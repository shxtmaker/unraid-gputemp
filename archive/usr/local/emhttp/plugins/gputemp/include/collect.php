<?PHP
/* gputemp - Unraid GPU 温度监控插件（版本 2026.08.10）
 * include/collect.php —— 外部命令超时执行、三厂商温度/显存采集、
 * 逐卡连续失败计数与降级状态（仅状态迁移时写 state.json）。
 *
 * 降级状态存放于 tmpfs（STATE_DIR/state.json），禁写 /boot（NR-6.1），
 * 重启后 tmpfs 清空自然复位。
 */

require_once(__DIR__.'/helper.php');
require_once(__DIR__.'/detect.php');

define('GPUTEMP_STATE_FILE', GPUTEMP_STATE_DIR.'/state.json');

/**
 * 以超时约束执行外部命令（proc_open + 非阻塞读 + stream_select 截止）。
 * 超时三段回收：SIGTERM → 500ms 等待 → SIGKILL → proc_close，保证零僵尸。
 *
 * @param string $cmd     完整命令行（调用方负责 escapeshellarg）
 * @param int    $timeout 超时秒数
 * @return string|null    成功返回 stdout；超时/启动失败/非零退出返回 null
 */
function run_with_timeout($cmd, $timeout) {
  $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
  if (!is_resource($proc)) return null;

  stream_set_blocking($pipes[1], false);
  stream_set_blocking($pipes[2], false);
  $deadline = microtime(true) + max(1, (int)$timeout);
  $out = '';
  $open = [1, 2];
  $timedOut = false;

  while ($open) {
    $read = [];
    foreach ($open as $i) $read[] = $pipes[$i];
    $write = null;
    $except = null;
    $remain = $deadline - microtime(true);
    if ($remain <= 0) { $timedOut = true; break; }
    $sec = (int)$remain;
    $usec = (int)(($remain - $sec) * 1000000);
    $n = @stream_select($read, $write, $except, $sec, $usec);
    if ($n === false) break;
    if (microtime(true) >= $deadline) { $timedOut = true; break; }
    if ($n > 0) {
      foreach ($read as $stream) {
        $chunk = fread($stream, 65536);
        if ($chunk === false || $chunk === '') {
          if (feof($stream)) {
            foreach ($open as $k => $i) if ($pipes[$i] === $stream) unset($open[$k]);
            $open = array_values($open);
          }
        } elseif ($stream === $pipes[1]) {
          $out .= $chunk;
        }
      }
    }
    $status = proc_get_status($proc);
    if (!$status['running']) break;
  }

  if ($timedOut) {
    @proc_terminate($proc, 15);             // SIGTERM
    usleep(500000);                          // 500ms 宽限
    $status = proc_get_status($proc);
    if ($status['running']) @proc_terminate($proc, 9); // SIGKILL
  }
  foreach ([1, 2] as $i) {
    if (is_resource($pipes[$i])) @fclose($pipes[$i]);
  }
  $code = null;
  if (!$timedOut) {
    // 管道先于进程关闭时短暂轮询等待退出（最多 500ms），取到真实退出码
    $status = proc_get_status($proc);
    $waited = 0;
    while ($status['running'] && $waited < 50) {
      usleep(10000);
      $status = proc_get_status($proc);
      $waited++;
    }
    if (!$status['running']) $code = $status['exitcode'];
  }
  proc_close($proc);
  if ($timedOut || $code === null || $code !== 0) return null;
  return $out;
}

/**
 * 全卡采集一次。
 * 返回：['gpus'=>[ {pci,vendor,name,temp:int|null,mem_used:int|null,mem_total:int|null,status:string}, ... ], 'ok'=>bool]
 * mem_used/mem_total 单位 MiB（整数）；无数据源或读取失败为 null（前端显示 N/A）。
 * status：ok | warn | crit | timeout | unavailable（warn/crit 由后端判定，前端只映射颜色）。
 * 失败卡输出 temp=null，绝不携带旧温度（AC-FUNC-08）。
 */
function gputemp_collect_all() {
  $cfg = gputemp_cfg();
  $devices = gputemp_get_devices();
  $state = gputemp_state_read();
  $threshold = max(1, (int)$cfg['FAIL_THRESHOLD']);
  $timeout = max(1, (int)$cfg['COLLECT_TIMEOUT']);

  if (!$devices) {
    return ['gpus' => [], 'ok' => true];
  }

  // 本轮参与采集的卡（未达降级阈值者）；已达阈值者停采（FR-3.7.2）
  $targets = [];
  foreach ($devices as $dev) {
    if ((int)($state[$dev['pci']]['fails'] ?? 0) < $threshold) $targets[] = $dev;
  }

  $readings = gputemp_read_batch($targets, $timeout);

  // 逐卡记成功/失败，仅状态迁移时写盘（flock 读改写 + tmp+rename）；
  // 返回更新后的状态，使本轮即可反映新达阈值的卡
  $state = gputemp_state_update($devices, $readings);

  $gpus = [];
  foreach ($devices as $dev) {
    $pci = $dev['pci'];
    $fails = (int)($state[$pci]['fails'] ?? 0);
    if ($fails >= $threshold) {
      $gpus[] = ['pci' => $pci, 'vendor' => $dev['vendor'], 'name' => $dev['name'], 'temp' => null, 'mem_used' => null, 'mem_total' => null, 'status' => 'unavailable'];
      continue;
    }
    $r = $readings[$pci] ?? ['temp' => null, 'mem_used' => null, 'mem_total' => null];
    if ($r['temp'] === null) {
      $gpus[] = ['pci' => $pci, 'vendor' => $dev['vendor'], 'name' => $dev['name'], 'temp' => null, 'mem_used' => null, 'mem_total' => null, 'status' => 'timeout'];
      continue;
    }
    $temp = (int)$r['temp'];
    $status = $temp >= $cfg['TEMP_CRIT'] ? 'crit' : ($temp >= $cfg['TEMP_WARN'] ? 'warn' : 'ok');
    $gpus[] = ['pci' => $pci, 'vendor' => $dev['vendor'], 'name' => $dev['name'], 'temp' => $temp, 'mem_used' => $r['mem_used'], 'mem_total' => $r['mem_total'], 'status' => $status];
  }
  return ['gpus' => $gpus, 'ok' => true];
}

/**
 * 批量读取：NVIDIA 单条命令查全部卡（PR-4.1）；AMD/Intel 纯 sysfs 直读。
 * 返回：[ pci => ['temp'=>int|null, 'mem_used'=>int|null, 'mem_total'=>int|null] ]
 */
function gputemp_read_batch($devices, $timeout) {
  $readings = [];
  $nvidia = [];
  foreach ($devices as $dev) {
    if ($dev['vendor'] === 'NVIDIA') $nvidia[] = $dev;
  }
  if ($nvidia) {
    $nv = gputemp_read_nvidia($timeout);
    foreach ($nvidia as $dev) {
      $readings[$dev['pci']] = $nv[$dev['pci']] ?? ['temp' => null, 'mem_used' => null, 'mem_total' => null];
    }
  }
  foreach ($devices as $dev) {
    if ($dev['vendor'] === 'NVIDIA') continue;
    $temp = gputemp_read_sysfs_temp($dev);
    $mem = gputemp_read_sysfs_mem($dev);
    $readings[$dev['pci']] = ['temp' => $temp, 'mem_used' => $mem[0], 'mem_total' => $mem[1]];
  }
  return $readings;
}

/**
 * NVIDIA：单条命令查全部卡（一次进程启动）；温度 + 显存一并返回。
 * nounits 模式下 memory.used/memory.total 单位为 MiB。
 * 返回：[ pci(小写) => ['temp'=>int|null,'mem_used'=>int|null,'mem_total'=>int|null] ]
 */
function gputemp_read_nvidia($timeout) {
  $result = [];
  $cmd = '/usr/bin/nvidia-smi --query-gpu=index,pci.bus_id,name,temperature.gpu,memory.used,memory.total --format=csv,noheader,nounits';
  $out = run_with_timeout($cmd, $timeout);
  if ($out === null) return $result;
  foreach (explode("\n", $out) as $line) {
    $parts = array_map('trim', explode(',', $line));
    if (count($parts) < 6) continue;
    $busId = strtolower($parts[1]);
    if (!preg_match('/^(?:0{0,4})?([0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7])$/i', $busId, $m)) continue;
    $temp = (is_numeric($parts[3])) ? (int)$parts[3] : null;
    $memUsed = (is_numeric($parts[4])) ? (int)$parts[4] : null;
    $memTotal = (is_numeric($parts[5])) ? (int)$parts[5] : null;
    $result[$m[1]] = ['temp' => $temp, 'mem_used' => $memUsed, 'mem_total' => $memTotal];
  }
  return $result;
}

/**
 * AMD/Intel：纯 sysfs 直读 temp1_input（毫摄氏度 ÷1000），无子进程。
 */
function gputemp_read_sysfs_temp($dev) {
  if (empty($dev['hwmon_temp']) || !is_file($dev['hwmon_temp'])) return null;
  $raw = @file_get_contents($dev['hwmon_temp']);
  if ($raw === false || !is_numeric(trim($raw))) return null;
  return intval(intval(trim($raw)) / 1000);
}

/**
 * AMD/Intel 显存占用（纯 sysfs 直读，无子进程，单位字节 → MiB）。
 * AMD（amdgpu）：PCI 设备目录下 mem_info_vram_used / mem_info_vram_total
 *   （内核 amdgpu_vram_mgr.c 注册的只读属性）；APU 无本地 VRAM，节点不存在。
 * Intel 独显（i915/xe）：主线内核未提供统一的本地显存占用 sysfs 节点，
 *   按社区工具惯例探测 lmem_used_bytes / lmem_total_bytes；节点不存在
 *   （主线内核 / xe 驱动 / 核显）即返回 null，前端显示 N/A。
 * 返回：[mem_used:int|null, mem_total:int|null]（MiB）
 */
function gputemp_read_sysfs_mem($dev) {
  if ($dev['vendor'] === 'AMD') {
    return gputemp_read_mem_pair($dev['sysfs'].'/mem_info_vram_used', $dev['sysfs'].'/mem_info_vram_total');
  }
  if ($dev['vendor'] === 'Intel') {
    return gputemp_read_mem_pair($dev['sysfs'].'/lmem_used_bytes', $dev['sysfs'].'/lmem_total_bytes');
  }
  return [null, null];
}

/**
 * 读取字节单位的 used/total 节点对并换算为 MiB；
 * 任一节点缺失或内容非数值即返回 [null, null]。
 */
function gputemp_read_mem_pair($usedPath, $totalPath) {
  if (!is_file($usedPath) || !is_file($totalPath)) return [null, null];
  $used = @file_get_contents($usedPath);
  $total = @file_get_contents($totalPath);
  if ($used === false || $total === false) return [null, null];
  $used = trim($used);
  $total = trim($total);
  if (!is_numeric($used) || !is_numeric($total)) return [null, null];
  return [(int)round(((float)$used) / 1048576), (int)round(((float)$total) / 1048576)];
}

/**
 * 读取降级状态（[ pci => ['fails'=>int] ]）。
 */
function gputemp_state_read() {
  $data = gputemp_read_json(GPUTEMP_STATE_FILE);
  return is_array($data) ? $data : [];
}

/**
 * 更新降级状态：成功清零，失败 +1；仅状态迁移时才写盘。
 * 写盘路径：flock(LOCK_EX) 读改写 + tmp+rename 原子替换。
 *
 * @return array 更新后的状态数组
 */
function gputemp_state_update($devices, $readings) {
  gputemp_ensure_state_dir();
  $lockPath = GPUTEMP_STATE_DIR.'/state.lock';
  $lock = @fopen($lockPath, 'c');
  if ($lock === false) return gputemp_state_read();
  if (!@flock($lock, LOCK_EX)) { fclose($lock); return gputemp_state_read(); }

  $state = gputemp_state_read();
  $new = [];
  foreach ($devices as $dev) {
    $pci = $dev['pci'];
    $fails = (int)($state[$pci]['fails'] ?? 0);
    if (isset($readings[$pci])) {
      $fails = ($readings[$pci]['temp'] !== null) ? 0 : $fails + 1;
    }
    $new[$pci] = ['fails' => $fails];
  }
  if ($new !== $state) {
    gputemp_write_json(GPUTEMP_STATE_FILE, $new);
  }
  @flock($lock, LOCK_UN);
  fclose($lock);
  return $new;
}
