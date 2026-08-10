<?PHP
/* gputemp - Unraid GPU 温度监控插件（版本 2026.08.10）
 * save.php —— 配置保存 / 彻底清除端点。
 *
 * 严格时序（SR-6.1/6.2、FR-6.10）：
 * ① 非 POST → 405
 * ② CSRF 显式校验（对照 /var/local/emhttp/var.ini 的 csrf_token），
 *    失败 403 + syslog 一条不含令牌明文的拒绝记录 + die；此前零文件写调用
 * ③ 7 项取值校验（全部在写操作之前完成）
 * ④ 组装 KEY="value"\n（LF）⑤ file_put_contents_atomic() 原子写入
 * ⑥ 成功后删除 devices.json 枚举缓存（新配置立即生效）
 * 全程仅使用 $_POST / $_GET 具体超全局变量（禁用泛化请求变量）；令牌不走 GET、不外泄。
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once(__DIR__.'/include/helper.php'); // Wrappers.php + gputemp_log

define('GPUTEMP_BOOT_DIR', '/boot/config/plugins/gputemp');
define('GPUTEMP_CFG_FILE', GPUTEMP_BOOT_DIR.'/gputemp.cfg');

/* ① 仅 POST（状态变更只经 POST；GET 端点不产生状态变更） */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  header('Allow: POST');
  header('Content-Type: text/plain; charset=UTF-8');
  die('Method Not Allowed');
}

/* ② CSRF 显式校验（校验前零文件写调用） */
$var = @parse_ini_file('/var/local/emhttp/var.ini');
$submitted = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
if (!is_array($var) || $submitted === '' || !hash_equals((string)($var['csrf_token'] ?? ''), $submitted)) {
  // 拒绝记录不含令牌明文（SR-6.3 令牌不外泄）
  gputemp_log('gputemp: CSRF token validation failed, configuration change rejected');
  http_response_code(403);
  header('Content-Type: text/plain; charset=UTF-8');
  die('Security token validation failed');
}

/* 清除动作：action=purge —— 显式删除 /boot/config/plugins/gputemp/（FR-6.2） */
if ((isset($_POST['action']) ? (string)$_POST['action'] : (isset($_GET['action']) ? (string)$_GET['action'] : '')) === 'purge') {
  gputemp_rrmdir(GPUTEMP_BOOT_DIR);
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode(['ok' => !is_dir(GPUTEMP_BOOT_DIR), 'action' => 'purge']);
  exit;
}

/**
 * 递归删除目录（仅用于 purge 的白名单固定路径，不接受任何用户可控路径）。
 */
function gputemp_rrmdir($dir) {
  if (!is_dir($dir)) return;
  foreach ((array)scandir($dir) as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $path = "$dir/$entry";
    if (is_dir($path)) {
      gputemp_rrmdir($path);
    } else {
      @unlink($path);
    }
  }
  @rmdir($dir);
}

/* ③ 7 项校验（FR-6.10；任一失败拒绝保存，配置文件内容与 mtime 均不变） */
$keys = ['REFRESH_INTERVAL', 'TEMP_WARN', 'TEMP_CRIT', 'ENABLED_GPUS', 'COLLECT_TIMEOUT', 'FAIL_THRESHOLD'];
$values = [];
$errors = [];
foreach ($keys as $key) {
  $values[$key] = isset($_POST[$key]) ? (string)$_POST[$key] : '';
}

// 通用字符校验先行：全部值不得含 < >（FR-6.4 第 4 款）与换行符（KEY="value" 单行格式）
foreach ($values as $key => $v) {
  if (strpos($v, '<') !== false || strpos($v, '>') !== false) {
    $errors[] = "$key contains forbidden characters";
  }
  if (strpos($v, "\n") !== false || strpos($v, "\r") !== false) {
    $errors[] = "$key contains line break";
  }
}

// 1) REFRESH_INTERVAL ∈ {1, 2, 5, 10}
if (!in_array(trim($values['REFRESH_INTERVAL']), ['1', '2', '5', '10'], true)) {
  $errors[] = 'REFRESH_INTERVAL must be one of 1, 2, 5, 10';
}
// 2) 0 < TEMP_WARN < TEMP_CRIT ≤ 120 整数
if (!ctype_digit(trim($values['TEMP_WARN']))) {
  $errors[] = 'TEMP_WARN must be a positive integer';
}
if (!ctype_digit(trim($values['TEMP_CRIT']))) {
  $errors[] = 'TEMP_CRIT must be a positive integer';
}
if (!$errors) {
  $warn = intval(trim($values['TEMP_WARN']));
  $crit = intval(trim($values['TEMP_CRIT']));
  if (!($warn > 0 && $warn < $crit && $crit <= 120)) {
    $errors[] = 'thresholds must satisfy 0 < TEMP_WARN < TEMP_CRIT <= 120';
  }
}
// 3) ENABLED_GPUS 逐项 strtolower 后整串匹配 PCI 正则（空串合法）
$gpus = [];
if (trim($values['ENABLED_GPUS']) !== '') {
  foreach (explode(',', $values['ENABLED_GPUS']) as $item) {
    $item = strtolower(trim($item));
    if (!preg_match('/^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$/', $item)) {
      $errors[] = 'ENABLED_GPUS contains an invalid PCI address';
      break;
    }
    $gpus[] = $item;
  }
}
// 4) COLLECT_TIMEOUT ∈ [1, 30]
if (!ctype_digit(trim($values['COLLECT_TIMEOUT']))) {
  $errors[] = 'COLLECT_TIMEOUT must be a positive integer';
} elseif (intval(trim($values['COLLECT_TIMEOUT'])) < 1 || intval(trim($values['COLLECT_TIMEOUT'])) > 30) {
  $errors[] = 'COLLECT_TIMEOUT must be between 1 and 30';
}
// 5) FAIL_THRESHOLD ≥ 1
if (!ctype_digit(trim($values['FAIL_THRESHOLD']))) {
  $errors[] = 'FAIL_THRESHOLD must be a positive integer';
} elseif (intval(trim($values['FAIL_THRESHOLD'])) < 1) {
  $errors[] = 'FAIL_THRESHOLD must be >= 1';
}

if ($errors) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=UTF-8');
  die(implode('; ', $errors));
}

/* ④ 组装 KEY="value"\n（LF，剥离 \r；值已经校验不含引号以外的特殊字符） */
$normalized = [
  'REFRESH_INTERVAL' => trim($values['REFRESH_INTERVAL']),
  'TEMP_WARN'        => trim($values['TEMP_WARN']),
  'TEMP_CRIT'        => trim($values['TEMP_CRIT']),
  'ENABLED_GPUS'     => implode(',', $gpus),
  'COLLECT_TIMEOUT'  => trim($values['COLLECT_TIMEOUT']),
  'FAIL_THRESHOLD'   => trim($values['FAIL_THRESHOLD']),
];
$content = '';
foreach ($normalized as $key => $value) {
  $content .= $key.'="'.$value.'"'."\n";
}
$content = str_replace("\r", '', $content);

/* ⑤ 平台官方原子写入（Wrappers.php：rand() 后缀 + 全字节校验 + rename） */
if (!is_dir(GPUTEMP_BOOT_DIR)) @mkdir(GPUTEMP_BOOT_DIR, 0755, true);
if (file_put_contents_atomic(GPUTEMP_CFG_FILE, $content) === false) {
  gputemp_log('gputemp: failed to save configuration (atomic write returned false)');
  http_response_code(500);
  header('Content-Type: text/plain; charset=UTF-8');
  die('Failed to save configuration');
}

/* ⑥ 成功后删除枚举缓存，使新白名单等配置立即生效 */
@unlink(GPUTEMP_STATE_DIR.'/devices.json');

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['ok' => true, 'action' => 'save']);
