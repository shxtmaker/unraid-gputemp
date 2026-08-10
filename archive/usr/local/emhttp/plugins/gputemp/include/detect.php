<?PHP
/* gputemp - Unraid GPU 温度监控插件（版本 2026.08.10）
 * include/detect.php —— PCI GPU 枚举、独显/核显拓扑判定、is_apu、
 * hwmon 绑定（零硬编码 hwmon 数字）、NVIDIA 索引映射与设备缓存。
 *
 * 显示规则（SRS 表 3-1）：所有独显（任意厂商）SHOW；
 * 核显仅 AMD APU SHOW；Intel 核显与非 APU AMD 核显 HIDE。
 * ENABLED_GPUS 白名单为第二层过滤（空串=全显；白名单项不因设备不可用而剔除）。
 */

require_once(__DIR__.'/helper.php');
require_once(__DIR__.'/collect.php'); // run_with_timeout（lspci / nvidia-smi 枚举也受超时保护）

define('GPUTEMP_DEVICES_FILE', GPUTEMP_STATE_DIR.'/devices.json');
define('GPUTEMP_DEVICES_TTL', 300); // 枚举缓存 TTL（秒）

/**
 * lspci -nn -D 枚举显示控制器候选集（PCI 类 0x03 全子类：0300 VGA /
 * 0301 XGA / 0302 3D（Tesla 等数据中心卡）/ 0380 Other），按 vendor 分类。
 * 类码过滤采用 class 前缀 03 通配而非逐值枚举，避免遗漏子类。
 * 返回：[ pci => ['vendor'=>'NVIDIA|AMD|Intel|Other', 'vendor_id'=>'10de', 'name'=>'...'] ]
 */
function gputemp_scan_lspci() {
  $out = run_with_timeout('/usr/bin/lspci -nn -D', 5);
  $devices = [];
  if ($out === null) return $devices;
  foreach (explode("\n", $out) as $line) {
    // 行格式：<domain:bus:dev.fn> 类名 [03xx]: 厂商 型号 [10de:2206] (rev a1)
    // [03xx] 即类码前缀 0x03（显示控制器）；lspci -nn 仅输出后 4 位类码
    if (!preg_match('/^([0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7])\s[^:]*\[(03[0-9a-f]{2})\]:\s(.*?)\s*\[([0-9a-f]{4}):[0-9a-f]{4}\]/', trim($line), $m)) continue;
    $vendorId = $m[4];
    switch ($vendorId) {
      case '10de': $vendor = 'NVIDIA'; break;
      case '1002': $vendor = 'AMD';    break;
      case '8086': $vendor = 'Intel';  break;
      default:     $vendor = 'Other';  break;
    }
    $name = trim($m[3]);
    $devices[$m[1]] = ['vendor' => $vendor, 'vendor_id' => $vendorId, 'name' => $name];
  }
  return $devices;
}

/**
 * 独显判定（FR-3.1.3 拓扑判据，不采用 VGA 启动标志属性作为判据）：
 * 沿 sysfs 父链向上读取各级 class，出现 0x0604（PCI-to-PCI/PCIe 桥）即为独显。
 * 注：/sys/bus/pci/devices/<pci> 是指向 /sys/devices/... 的符号链接，
 * 必须先经 realpath() 解析后再做父链推导，否则 dirname() 作用于字符串
 * 只会得到 /sys/bus/pci/devices，永远不满足 /sys/devices 前缀条件。
 */
function gputemp_is_discrete($pci) {
  $real = @realpath("/sys/bus/pci/devices/$pci");
  if ($real === false) return false;
  $cur = dirname($real);
  $root = '/sys/devices';
  while (strlen($cur) > strlen($root) && strpos($cur, $root) === 0) {
    $class = trim((string)@file_get_contents("$cur/class"));
    if (strpos($class, '0x0604') === 0) return true;
    $cur = dirname($cur);
  }
  return false;
}

/**
 * APU 核显判定（FR-3.7.3）：vendor=0x1002（AMD）且位于 root complex 直接下游
 * （上级路径不经过任何 0x0604 桥）。
 */
function gputemp_is_apu($pci, $vendorId) {
  return $vendorId === '1002' && !gputemp_is_discrete($pci);
}

/**
 * hwmon → PCI 地址绑定（FR-3.3）：遍历 /sys/class/hwmon/hwmon* /name，
 * 匹配驱动名 amdgpu/i915/xe，再经 device 符号链接解析归属 PCI 地址。
 * 全程 glob + 符号链接解析，源码零硬编码 hwmon 数字。
 *
 * @return array pci => hwmon 设备目录路径
 */
function gputemp_hwmon_map() {
  static $map = null;
  if ($map !== null) return $map;
  $map = [];
  foreach ((array)glob('/sys/class/hwmon/hwmon*/name') as $nameFile) {
    $driver = trim((string)@file_get_contents($nameFile));
    if ($driver !== 'amdgpu' && $driver !== 'i915' && $driver !== 'xe') continue;
    $devLink = dirname($nameFile).'/device';
    $pci = gputemp_resolve_pci_addr($devLink);
    if ($pci !== null) $map[$pci] = $devLink;
  }
  return $map;
}

/**
 * 解析 sysfs device 符号链接归属的 PCI 地址（兼容 realpath 受限环境）。
 */
function gputemp_resolve_pci_addr($devicePath) {
  $real = @realpath($devicePath);
  if ($real !== false && preg_match('/([0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7])$/', $real, $m)) {
    return $m[1];
  }
  // 兜底：沿父目录链向上匹配 PCI 地址命名的目录（sysfs 中即符号链接目标）
  $cur = $devicePath;
  for ($i = 0; $i < 8; $i++) {
    $base = basename($cur);
    if (preg_match('/^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$/', $base)) return $base;
    $parent = dirname($cur);
    if ($parent === $cur || $parent === '.' || $parent === '/') break;
    $cur = $parent;
  }
  return null;
}

/**
 * 定位某 PCI 设备 hwmon 下的 temp1_input 路径（不硬编码 hwmon 编号）。
 */
function gputemp_hwmon_temp_path($deviceDir) {
  $direct = "$deviceDir/hwmon";
  if (is_dir($direct)) {
    $found = glob("$direct/hwmon*/temp1_input");
    if ($found) return $found[0];
  }
  // i915/xe 部分版本 hwmon 嵌套于 gt 子目录下
  $nested = glob("$deviceDir/hwmon/hwmon*/gt/hwmon*/temp1_input");
  if ($nested) return $nested[0];
  return null;
}

/**
 * NVIDIA 索引 ↔ PCI 映射：单条命令查询全部卡（FR-3.3 补充说明）。
 * 返回：[ pci(小写) => index(int) ]
 */
function gputemp_nvidia_index_map() {
  $map = [];
  $cmd = '/usr/bin/nvidia-smi --query-gpu=index,pci.bus_id --format=csv,noheader';
  $out = run_with_timeout($cmd, 5);
  if ($out === null) return $map;
  foreach (explode("\n", $out) as $line) {
    $parts = array_map('trim', explode(',', $line));
    if (count($parts) < 2 || !is_numeric($parts[0])) continue;
    // nvidia-smi 输出形如 00000000:01:00.0，规范为 lspci 风格 0000:01:00.0
    $busId = strtolower($parts[1]);
    if (preg_match('/^(?:0{0,4})?([0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7])$/i', $busId, $m)) {
      $map[$m[1]] = intval($parts[0]);
    }
  }
  return $map;
}

/**
 * 完整枚举：lspci 候选 → 表 3-1 显示规则 → ENABLED_GPUS 白名单二次过滤 →
 * hwmon / nvidia-smi 数据源绑定。返回待显示 GPU 列表（数组，发现顺序）。
 */
function gputemp_detect_devices() {
  $cfg = gputemp_cfg();
  $candidates = gputemp_scan_lspci();

  // 白名单解析（空串 = 全显）
  $whitelist = null;
  if ($cfg['ENABLED_GPUS'] !== '') {
    $whitelist = [];
    foreach (explode(',', $cfg['ENABLED_GPUS']) as $item) {
      $item = strtolower(trim($item));
      if ($item !== '') $whitelist[$item] = true;
    }
  }

  $hwmon = gputemp_hwmon_map();
  $nvmap = null;

  $devices = [];
  foreach ($candidates as $pci => $info) {
    $discrete = gputemp_is_discrete($pci);
    $apu = gputemp_is_apu($pci, $info['vendor_id']);
    // 表 3-1：独显全显；核显仅 AMD APU 显示；其余 HIDE
    if (!$discrete && !$apu) continue;
    // 白名单二次过滤（白名单中被 HIDE 的设备不会进入此处；不可用项不自动剔除）
    if ($whitelist !== null && !isset($whitelist[$pci])) continue;

    $dev = [
      'pci'          => $pci,
      'vendor'       => $info['vendor'],
      'name'         => $info['name'] !== '' ? $info['name'] : $info['vendor'].' GPU',
      'discrete'     => $discrete,
      'apu'          => $apu,
      'sysfs'        => "/sys/bus/pci/devices/$pci",
      'hwmon_temp'   => null,
      'nvidia_index' => null,
    ];
    if ($info['vendor'] === 'NVIDIA') {
      if ($nvmap === null) $nvmap = gputemp_nvidia_index_map();
      if (isset($nvmap[$pci])) $dev['nvidia_index'] = $nvmap[$pci];
    } else {
      if (isset($hwmon[$pci])) $dev['hwmon_temp'] = gputemp_hwmon_temp_path($hwmon[$pci]);
    }
    $devices[] = $dev;
  }
  return $devices;
}

/**
 * 获取待显示 GPU 列表（带缓存）：STATE_DIR/devices.json，TTL 300s，
 * sysfs 存在性与候选集不一致即重建（FR-3.6）。
 */
function gputemp_get_devices() {
  $cache = gputemp_read_json(GPUTEMP_DEVICES_FILE);
  if (is_array($cache)
      && isset($cache['ts']) && (microtime(true) - (float)$cache['ts']) < GPUTEMP_DEVICES_TTL
      && isset($cache['devices']) && is_array($cache['devices'])
      && gputemp_devices_consistent($cache['devices'])) {
    return $cache['devices'];
  }
  $devices = gputemp_detect_devices();
  gputemp_write_json(GPUTEMP_DEVICES_FILE, ['ts' => microtime(true), 'devices' => $devices]);
  return $devices;
}

/**
 * 缓存一致性校验：
 * 1) 候选 PCI 集合与缓存一致（新增/移除 GPU 立即反映）；
 * 2) 每个缓存设备的 sysfs 节点仍存在；
 * 3) AMD/Intel 设备已绑定的温度节点仍存在（hwmon 编号漂移即重建；
 *    枚举时未绑定成功的设备允许保留，待 TTL 到期后重扫）。
 */
function gputemp_devices_consistent($cached) {
  $cachedPci = [];
  foreach ($cached as $dev) {
    if (!isset($dev['pci']) || !isset($dev['sysfs']) || !is_dir($dev['sysfs'])) return false;
    if ($dev['vendor'] !== 'NVIDIA' && isset($dev['hwmon_temp']) && $dev['hwmon_temp'] !== null && !is_file($dev['hwmon_temp'])) return false;
    $cachedPci[] = $dev['pci'];
  }
  $currentPci = array_keys(gputemp_scan_lspci());
  sort($cachedPci);
  sort($currentPci);
  return $cachedPci === $currentPci;
}
