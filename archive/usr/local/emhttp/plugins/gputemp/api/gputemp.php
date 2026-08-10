<?PHP
/* gputemp - Unraid GPU 温度监控插件（版本 2026.08.10）
 * api/gputemp.php —— 只读 GET JSON 端点（SR-6.3：GET 不产生状态变更，免 CSRF）。
 *
 * 流程：版本门禁 → 读 devices.json（检测缓存）→ 读 cache.json：
 *   now-ts < TTL（TTL = max(1, REFRESH_INTERVAL-1)）直接返回（多标签页复用同一结果）；
 *   未命中经 flock(LOCK_EX|LOCK_NB) 竞争：抢到则双检后采集并原子写回；
 *   没抢到立即返回现有缓存 + collecting:true（自动跳过本周期，不堆积）。
 * 对 /boot 零写；错误收敛（无绝对路径/堆栈泄漏）。
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once(dirname(__DIR__).'/include/helper.php');
require_once(dirname(__DIR__).'/include/detect.php');
require_once(dirname(__DIR__).'/include/collect.php');

define('GPUTEMP_CACHE_FILE', GPUTEMP_STATE_DIR.'/cache.json');
define('GPUTEMP_COLLECT_LOCK', GPUTEMP_STATE_DIR.'/collect.lock');

/**
 * 统一 JSON 输出出口。
 */
function gputemp_json_out($code, $payload) {
  http_response_code($code);
  header('Content-Type: application/json; charset=UTF-8');
  header('Cache-Control: no-store');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

/**
 * 错误收敛：细节仅记 syslog，客户端只见通用消息（SR-4.4）。
 */
function gputemp_fail($msg) {
  gputemp_log("gputemp api error: $msg");
  gputemp_json_out(500, ['error' => 'internal error']);
}

set_exception_handler(function($e) { gputemp_fail(get_class($e)); });

// GET-only（SR-6.3：状态变更仅经 POST；本端点只读）
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  header('Allow: GET');
  gputemp_json_out(405, ['error' => 'method not allowed']);
}

// 版本门禁（CR-4.1）：最低支持 7.2.0-beta
$urVersion = @parse_ini_file('/etc/unraid-version');
if (!is_array($urVersion)
    || !version_compare($urVersion['version'] ?? '', '7.2.0-beta', '>=')) {
  gputemp_json_out(503, ['error' => 'unsupported Unraid version']);
}

try {
  $cfg = gputemp_cfg();

  // 读 devices.json（gputemp_get_devices 内部负责枚举缓存与一致性校验）
  $devices = gputemp_get_devices();

  // 读采集缓存：TTL 内直接返回（多标签页复用同一结果）
  $ttl = max(1, (int)$cfg['REFRESH_INTERVAL'] - 1);
  $cached = gputemp_read_json(GPUTEMP_CACHE_FILE);
  if (is_array($cached) && isset($cached['ts'], $cached['gpus']) && is_array($cached['gpus'])) {
    if ((microtime(true) - (float)$cached['ts']) < $ttl) {
      gputemp_json_out(200, ['ts' => (float)$cached['ts'], 'collecting' => false, 'gpus' => $cached['gpus']]);
    }
  }

  // 缓存未命中：非阻塞锁竞争采集权
  gputemp_ensure_state_dir();
  $lock = @fopen(GPUTEMP_COLLECT_LOCK, 'c');
  if ($lock === false) {
    gputemp_fail('open collect lock');
  }

  if (!@flock($lock, LOCK_EX | LOCK_NB)) {
    // 没抢到锁：另一进程正在采集，立即返回现有缓存 + collecting:true（不堆积）
    fclose($lock);
    if (is_array($cached) && isset($cached['ts'], $cached['gpus']) && is_array($cached['gpus'])) {
      gputemp_json_out(200, ['ts' => (float)$cached['ts'], 'collecting' => true, 'gpus' => $cached['gpus']]);
    }
    gputemp_json_out(200, ['ts' => 0.0, 'collecting' => true, 'gpus' => []]);
  }

  // 抢到锁：双检——锁等待期间可能已有新鲜缓存，避免重复采集
  $cached = gputemp_read_json(GPUTEMP_CACHE_FILE);
  if (is_array($cached) && isset($cached['ts'], $cached['gpus']) && is_array($cached['gpus'])
      && (microtime(true) - (float)$cached['ts']) < $ttl) {
    @flock($lock, LOCK_UN);
    fclose($lock);
    gputemp_json_out(200, ['ts' => (float)$cached['ts'], 'collecting' => false, 'gpus' => $cached['gpus']]);
  }

  // 采集并原子写回缓存（cache.json 只存 ts+gpus；collecting 为传输期字段）
  $result = gputemp_collect_all();
  $payload = ['ts' => microtime(true), 'gpus' => $result['gpus']];
  gputemp_write_json(GPUTEMP_CACHE_FILE, $payload);

  @flock($lock, LOCK_UN);
  fclose($lock);
  gputemp_json_out(200, ['ts' => (float)$payload['ts'], 'collecting' => false, 'gpus' => $payload['gpus']]);
} catch (Throwable $e) {
  gputemp_fail(get_class($e));
}
