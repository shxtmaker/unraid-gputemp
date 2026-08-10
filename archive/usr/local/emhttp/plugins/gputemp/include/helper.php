<?PHP
/* gputemp - Unraid GPU 温度监控插件（版本 2026.08.10）
 * include/helper.php —— 配置唯一读取入口、路径常量与日志工具。
 *
 * 配置读取一律经平台官方函数 parse_plugin_cfg('gputemp')（Wrappers.php），
 * 禁止对 cfg 文件使用 parse_ini_file / file_get_contents 自行解析。
 * 日志仅写 syslog（my_logger），绝不向浏览器输出。
 */

$docroot ??= ($_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp');

// 平台官方包装函数：parse_plugin_cfg / file_put_contents_atomic / my_logger
require_once("$docroot/plugins/dynamix/include/Wrappers.php");

// 运行时状态目录（tmpfs，重启自然清空；降级状态/设备缓存/采集缓存均落此处）
if (!defined('GPUTEMP_STATE_DIR')) define('GPUTEMP_STATE_DIR', '/var/local/emhttp/plugins/gputemp');

define('GPUTEMP_PLUGIN', 'gputemp');
define('GPUTEMP_VERSION', '2026.08.10');

// 默认值（与 default.cfg 逐项一致，作为 ?? 兜底与非法值回落值，避免"第三套默认值"）
define('GPUTEMP_DEF_REFRESH', 5);
define('GPUTEMP_DEF_WARN', 65);
define('GPUTEMP_DEF_CRIT', 85);
define('GPUTEMP_DEF_GPUS', '');
define('GPUTEMP_DEF_TIMEOUT', 5);
define('GPUTEMP_DEF_FAIL_LIMIT', 3);

/**
 * 插件日志：仅经 my_logger() 写 syslog，绝不输出到浏览器。
 */
function gputemp_log($msg) {
  my_logger($msg, 'gputemp');
}

/**
 * 配置唯一读取入口（FR-6.3 / FR-6.13）。
 * parse_plugin_cfg 已保证：用户 cfg 缺失/损坏/缺键时回退 default.cfg 默认值。
 * 本函数再做双保险：?? 兜底 + intval/trim + 非法值回落默认并记 syslog 一条。
 *
 * @return array{REFRESH_INTERVAL:int,TEMP_WARN:int,TEMP_CRIT:int,ENABLED_GPUS:string,COLLECT_TIMEOUT:int,FAIL_THRESHOLD:int}
 */
function gputemp_cfg() {
  static $cache = null;
  if ($cache !== null) return $cache;

  $raw = parse_plugin_cfg(GPUTEMP_PLUGIN);

  $refresh = intval($raw['REFRESH_INTERVAL'] ?? GPUTEMP_DEF_REFRESH);
  if (!in_array($refresh, [1, 2, 5, 10], true)) {
    gputemp_log("gputemp: invalid REFRESH_INTERVAL '$refresh', falling back to ".GPUTEMP_DEF_REFRESH);
    $refresh = GPUTEMP_DEF_REFRESH;
  }

  $warn = intval($raw['TEMP_WARN'] ?? GPUTEMP_DEF_WARN);
  $crit = intval($raw['TEMP_CRIT'] ?? GPUTEMP_DEF_CRIT);
  if ($warn < 1 || $crit <= $warn || $crit > 120) {
    gputemp_log("gputemp: invalid TEMP_WARN/TEMP_CRIT ($warn/$crit), falling back to ".GPUTEMP_DEF_WARN.'/'.GPUTEMP_DEF_CRIT);
    $warn = GPUTEMP_DEF_WARN;
    $crit = GPUTEMP_DEF_CRIT;
  }

  $gpus = trim((string)($raw['ENABLED_GPUS'] ?? GPUTEMP_DEF_GPUS));

  $timeout = intval($raw['COLLECT_TIMEOUT'] ?? GPUTEMP_DEF_TIMEOUT);
  if ($timeout < 1 || $timeout > 30) {
    gputemp_log("gputemp: invalid COLLECT_TIMEOUT '$timeout', falling back to ".GPUTEMP_DEF_TIMEOUT);
    $timeout = GPUTEMP_DEF_TIMEOUT;
  }

  $failLimit = intval($raw['FAIL_THRESHOLD'] ?? GPUTEMP_DEF_FAIL_LIMIT);
  if ($failLimit < 1) {
    gputemp_log("gputemp: invalid FAIL_THRESHOLD '$failLimit', falling back to ".GPUTEMP_DEF_FAIL_LIMIT);
    $failLimit = GPUTEMP_DEF_FAIL_LIMIT;
  }

  $cache = [
    'REFRESH_INTERVAL' => $refresh,
    'TEMP_WARN'        => $warn,
    'TEMP_CRIT'        => $crit,
    'ENABLED_GPUS'     => $gpus,
    'COLLECT_TIMEOUT'  => $timeout,
    'FAIL_THRESHOLD'   => $failLimit,
  ];
  return $cache;
}

/**
 * 确保 STATE_DIR 存在（tmpfs 重启后可能不存在）。
 */
function gputemp_ensure_state_dir() {
  if (!is_dir(GPUTEMP_STATE_DIR)) @mkdir(GPUTEMP_STATE_DIR, 0755, true);
}

/**
 * 原子写文件：先写同目录临时文件，成功后 rename 替换（tmpfs 上 rename 原子）。
 *
 * @return int|false 写入字节数，失败返回 false
 */
function gputemp_atomic_write($path, $data) {
  $dir = dirname($path);
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  $tmp = $path.'.'.getmypid().'.'.mt_rand();
  if (@file_put_contents($tmp, $data) !== strlen($data)) {
    @unlink($tmp);
    return false;
  }
  if (!@rename($tmp, $path)) {
    @unlink($tmp);
    return false;
  }
  return strlen($data);
}

/**
 * 原子写 JSON（带 JSON 编码失败保护）。
 */
function gputemp_write_json($path, $data) {
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) return false;
  return gputemp_atomic_write($path, $json);
}

/**
 * 读 JSON 文件；不存在或解析失败返回 null。
 */
function gputemp_read_json($path) {
  $raw = @file_get_contents($path);
  if ($raw === false || $raw === '') return null;
  $data = json_decode($raw, true);
  return is_array($data) ? $data : null;
}
