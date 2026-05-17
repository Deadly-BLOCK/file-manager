<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ERROR | E_PARSE);

$request_method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? ''));
if (!in_array($request_method, ['GET', 'POST', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, POST, HEAD');
    exit;
}
$content_length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($content_length < 0 || $content_length > 65536) {
    http_response_code(413);
    exit;
}

require_once 'config.php';

define('GOOGLE_CLIENT_ID', 'YOUR-GOOGLE-CLIENT-ID.apps.googleusercontent.com');
$allowed_email = 'YOUR-GOOGLE-ALLOWED-EMAIL';

define('MAX_ATTEMPTS', 5);
define('COOLDOWN_SECONDS', 10 * 3600);
define('LOCKOUT_FILE',  __DIR__ . '/.lockout.dat');
define('ATTEMPTS_FILE', __DIR__ . '/.attempts.dat');

define('IP_LOG_DIR',       __DIR__ . '/attempts');
define('IP_LOG_FILE',      __DIR__ . '/attempts/log.json');
define('JWKS_CACHE_FILE',  __DIR__ . '/attempts/.jwks.cache');
define('JTI_CACHE_FILE',   __DIR__ . '/attempts/.jti.cache');
define('IP_THROTTLE_FILE', __DIR__ . '/attempts/.ip_throttle.dat');

define('MAX_USERNAME_LEN', 64);
define('MAX_PASSWORD_LEN', 256);
define('MAX_TOKEN_LEN',    8192);
define('MAX_UA_LEN',       256);
define('MAX_LOG_BYTES',    5 * 1024 * 1024);

define('JWKS_TTL',          3600);
define('JTI_TTL',           600);
define('IP_WINDOW',         600);
define('IP_SOFT_THRESHOLD', 3);
define('IP_BACKOFF_CAP',    60);
define('MIN_RESPONSE_TIME_US', 800000);
define('MAX_REQUEST_BYTES',  65536);

$correct_username = 'username';
$correct_hashes = [
    '<argon2id-version19-memory65536-time4-parallelism1_hash-1>',
    '<argon2id-version19-memory65536-time4-parallelism1_hash-2>',
    '<argon2id-version19-memory65536-time4-parallelism1_hash-3>',
    '<argon2id-version19-memory65536-time4-parallelism1_hash-4>'
];

$AUTH_SECRET = defined('AUTH_SECRET') && is_string(AUTH_SECRET) && strlen((string)AUTH_SECRET) >= 32
    ? (string)AUTH_SECRET
    : hash('sha256',
        GOOGLE_CLIENT_ID . '|' . $allowed_email . '|' .
        implode('|', $correct_hashes) . '|' . __FILE__,
        true);

$auth_start = microtime(true);

$csp_nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');

$is_https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (isset($_SERVER['HTTP_CF_VISITOR']) && stripos((string)$_SERVER['HTTP_CF_VISITOR'], 'https') !== false)
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-DNS-Prefetch-Control: off');
header('X-Permitted-Cross-Domain-Policies: none');
header('Permissions-Policy: accelerometer=(), ambient-light-sensor=(), autoplay=(), battery=(), bluetooth=(), browsing-topics=(), camera=(), display-capture=(), document-domain=(), encrypted-media=(), fullscreen=(), gamepad=(), geolocation=(), gyroscope=(), hid=(), idle-detection=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(), publickey-credentials-get=(), screen-wake-lock=(), serial=(), usb=(), web-share=(), xr-spatial-tracking=()');
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-{$csp_nonce}' 'strict-dynamic' https://accounts.google.com/gsi/client https://accounts.google.com; style-src 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self' https://accounts.google.com; frame-src https://accounts.google.com; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Cross-Origin-Opener-Policy: same-origin-allow-popups');
header('Cross-Origin-Resource-Policy: same-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
if ($is_https) {
    header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if ($is_https) ini_set('session.cookie_secure', '1');
    session_name('AUTHSID');
    session_start(['cookie_lifetime' => 0]);
}

if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function secure_compare(string $user_input, string $correct_value): bool {
    return hash_equals($correct_value, $user_input);
}

function csrf_valid(): bool {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) return false;
    $provided = $_POST['csrf_token'] ?? '';
    if (!is_string($provided) || $provided === '') return false;
    return hash_equals($_SESSION['csrf_token'], $provided);
}

function normalize_host(string $h): string {
    $h = trim($h);
    if ($h === '') return '';
    if ($h[0] === '[') {
        $end = strpos($h, ']');
        if ($end === false) return '';
        return strtolower(substr($h, 0, $end + 1));
    }
    $colon = strpos($h, ':');
    if ($colon !== false) $h = substr($h, 0, $colon);
    return strtolower($h);
}

function origin_valid(): bool {
    $host = normalize_host((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') return false;

    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && $origin !== 'null') {
        $p = @parse_url($origin);
        if (!is_array($p) || empty($p['host'])) return false;
        return normalize_host((string)$p['host']) === $host;
    }
    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($referer !== '') {
        $p = @parse_url($referer);
        if (!is_array($p) || empty($p['host'])) return false;
        return normalize_host((string)$p['host']) === $host;
    }
    return false;
}

function get_client_ip(): string {
    $candidates = [];
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) $candidates[] = (string)$_SERVER['HTTP_CF_CONNECTING_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
        $candidates[] = trim($parts[0]);
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) $candidates[] = (string)$_SERVER['HTTP_X_REAL_IP'];
    $candidates[] = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    foreach ($candidates as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return $ip;
    }
    foreach ($candidates as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return 'unknown';
}

function clean_text(string $s, int $max): string {
    $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s);
    if (!is_string($s)) $s = '';
    return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
}

function format_remaining(int $seconds): string {
    if ($seconds < 0) $seconds = 0;
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return sprintf('%02dh %02dm %02ds', $h, $m, $s);
}

function pad_response_time(float $start): void {
    $elapsed_us = (int)((microtime(true) - $start) * 1000000);
    $need = MIN_RESPONSE_TIME_US - $elapsed_us;
    if ($need > 0) {
        $jitter = random_int(0, 80000);
        usleep($need + $jitter);
    }
}

function ctype_form_ok(string $ctype): bool {
    $ctype = strtolower(trim($ctype));
    return strncmp($ctype, 'application/x-www-form-urlencoded', 33) === 0
        || strncmp($ctype, 'multipart/form-data', 19) === 0;
}

function sec_fetch_post_ok(): bool {
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
    if ($site === null) return true;
    if (!in_array($site, ['same-origin', 'same-site', 'none'], true)) return false;
    $mode = $_SERVER['HTTP_SEC_FETCH_MODE'] ?? null;
    if ($mode !== null && !in_array($mode, ['navigate', 'cors', 'same-origin'], true)) return false;
    $dest = $_SERVER['HTTP_SEC_FETCH_DEST'] ?? null;
    if ($dest !== null && $dest !== '' && !in_array($dest, ['document', 'empty', 'iframe'], true)) return false;
    return true;
}

function ensure_dir(): void {
    if (!is_dir(IP_LOG_DIR)) {
        @mkdir(IP_LOG_DIR, 0750, true);
        @file_put_contents(IP_LOG_DIR . '/.htaccess', "Require all denied\n");
    }
}

function hmac_sign(string $data, string $key): string {
    return hash_hmac('sha256', $data, $key, false);
}

function read_signed(string $path, string $key): ?string {
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') return null;
    $nl = strpos($raw, "\n");
    if ($nl === false || $nl < 32) return null;
    $sig  = substr($raw, 0, $nl);
    $body = substr($raw, $nl + 1);
    if (!hash_equals(hmac_sign($body, $key), $sig)) return null;
    return $body;
}

function write_signed(string $path, string $key, string $data): bool {
    ensure_dir();
    $payload = hmac_sign($data, $key) . "\n" . $data;
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $payload, LOCK_EX) === false) return false;
    @chmod($tmp, 0640);
    if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
    @chmod($path, 0640);
    return true;
}

function read_signed_json(string $path, string $key): ?array {
    $body = read_signed($path, $key);
    if ($body === null) return null;
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

function write_signed_json(string $path, string $key, array $data): bool {
    $flags = JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    $encoded = json_encode($data, $flags);
    if ($encoded === false) return false;
    return write_signed($path, $key, $encoded);
}

function get_lockout_remaining(string $key) {
    $body = read_signed(LOCKOUT_FILE, $key);
    if ($body === null) {
        if (is_file(LOCKOUT_FILE)) @unlink(LOCKOUT_FILE);
        return false;
    }
    $lockout_time = (int)trim($body);
    if ($lockout_time <= 0) { @unlink(LOCKOUT_FILE); return false; }
    $elapsed = time() - $lockout_time;
    if ($elapsed >= COOLDOWN_SECONDS) {
        @unlink(LOCKOUT_FILE);
        @unlink(ATTEMPTS_FILE);
        return false;
    }
    return COOLDOWN_SECONDS - $elapsed;
}

function record_failed_attempt(string $key): int {
    ensure_dir();
    $body = read_signed(ATTEMPTS_FILE, $key);
    $count = $body === null ? 0 : (int)trim($body);
    $count++;
    write_signed(ATTEMPTS_FILE, $key, (string)$count);
    if ($count >= MAX_ATTEMPTS && !is_file(LOCKOUT_FILE)) {
        write_signed(LOCKOUT_FILE, $key, (string)time());
    }
    return $count;
}

function reset_attempts(): void {
    @unlink(ATTEMPTS_FILE);
    @unlink(LOCKOUT_FILE);
}

function ip_throttle_check(string $ip, string $key): array {
    $data = read_signed_json(IP_THROTTLE_FILE, $key) ?? [];
    $now = time();
    foreach ($data as $k => $v) {
        if (!is_array($v) || ($v['last'] ?? 0) < $now - IP_WINDOW) unset($data[$k]);
    }
    $entry = $data[$ip] ?? ['count' => 0, 'last' => 0];
    $excess = max(0, ((int)$entry['count']) - IP_SOFT_THRESHOLD + 1);
    $delay = $excess <= 0 ? 0 : min(IP_BACKOFF_CAP, (int)pow(2, $excess));
    $since = $now - (int)$entry['last'];
    $wait = max(0, $delay - $since);
    write_signed_json(IP_THROTTLE_FILE, $key, $data);
    return ['blocked' => $wait > 0, 'wait' => $wait];
}

function ip_throttle_record(string $ip, string $key): void {
    $data = read_signed_json(IP_THROTTLE_FILE, $key) ?? [];
    $now = time();
    foreach ($data as $k => $v) {
        if (!is_array($v) || ($v['last'] ?? 0) < $now - IP_WINDOW) unset($data[$k]);
    }
    $entry = $data[$ip] ?? ['count' => 0, 'last' => 0];
    $entry['count'] = ((int)$entry['count']) + 1;
    $entry['last']  = $now;
    $data[$ip] = $entry;
    write_signed_json(IP_THROTTLE_FILE, $key, $data);
}

function ip_throttle_reset(string $ip, string $key): void {
    $data = read_signed_json(IP_THROTTLE_FILE, $key) ?? [];
    unset($data[$ip]);
    write_signed_json(IP_THROTTLE_FILE, $key, $data);
}

function log_attempt(string $status, string $method): void {
    ensure_dir();
    $ip     = get_client_ip();
    $now    = time();
    $ua     = clean_text((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), MAX_UA_LEN);
    $status = clean_text($status, 16);
    $method = clean_text($method, 16);
    $fp = @fopen(IP_LOG_FILE, 'c+');
    if (!$fp) return;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return; }
    $raw  = stream_get_contents($fp);
    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($data)) $data = [];
    if (!isset($data[$ip]) || !is_array($data[$ip])) {
        $data[$ip] = [
            'attempts' => 0, 'failures' => 0, 'successes' => 0,
            'first_seen' => date('c', $now), 'last_attempt' => date('c', $now),
            'last_status' => $status, 'last_method' => $method, 'last_user_agent' => $ua,
        ];
    }
    $data[$ip]['attempts'] = ((int)($data[$ip]['attempts'] ?? 0)) + 1;
    if ($status === 'success') $data[$ip]['successes'] = ((int)($data[$ip]['successes'] ?? 0)) + 1;
    if ($status === 'failure') $data[$ip]['failures']  = ((int)($data[$ip]['failures']  ?? 0)) + 1;
    $data[$ip]['last_attempt']    = date('c', $now);
    $data[$ip]['last_status']     = $status;
    $data[$ip]['last_method']     = $method;
    $data[$ip]['last_user_agent'] = $ua;
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    $encoded = json_encode($data, $flags);
    if ($encoded === false) { flock($fp, LOCK_UN); fclose($fp); return; }
    if (strlen($encoded) > MAX_LOG_BYTES) {
        $data = [$ip => $data[$ip]];
        $encoded = json_encode($data, $flags);
        if ($encoded === false) { flock($fp, LOCK_UN); fclose($fp); return; }
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $encoded);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    @chmod(IP_LOG_FILE, 0640);
}

function jti_seen_and_record(string $jti, string $key): bool {
    if ($jti === '') return false;
    $data = read_signed_json(JTI_CACHE_FILE, $key) ?? [];
    $now = time();
    foreach ($data as $k => $exp) {
        if (!is_int($exp) || $exp < $now) unset($data[$k]);
    }
    if (isset($data[$jti])) return true;
    $data[$jti] = $now + JTI_TTL;
    if (count($data) > 256) {
        asort($data);
        $data = array_slice($data, -256, null, true);
    }
    write_signed_json(JTI_CACHE_FILE, $key, $data);
    return false;
}

function b64url_decode(string $s) {
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    return base64_decode($s, true);
}

function der_len(int $len): string {
    if ($len < 0x80) return chr($len);
    $hex = dechex($len);
    if (strlen($hex) % 2) $hex = '0' . $hex;
    $bytes = hex2bin($hex);
    return chr(0x80 | strlen($bytes)) . $bytes;
}

function der_int(string $v): string {
    if ($v === '') return "\x02\x01\x00";
    if (ord($v[0]) >= 0x80) $v = "\x00" . $v;
    return "\x02" . der_len(strlen($v)) . $v;
}

function der_seq(string $v): string { return "\x30" . der_len(strlen($v)) . $v; }
function der_bitstr(string $v): string { return "\x03" . der_len(strlen($v) + 1) . "\x00" . $v; }

function jwk_rsa_to_pem(string $n_b64, string $e_b64): ?string {
    $n = b64url_decode($n_b64);
    $e = b64url_decode($e_b64);
    if ($n === false || $e === false || $n === '' || $e === '') return null;
    $rsa_pubkey = der_seq(der_int($n) . der_int($e));
    $oid = hex2bin('06092a864886f70d010101');
    $null = "\x05\x00";
    $alg_id = der_seq($oid . $null);
    $spki = der_seq($alg_id . der_bitstr($rsa_pubkey));
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function fetch_google_jwks(bool $force_refresh = false): ?array {
    ensure_dir();
    if (!$force_refresh && is_file(JWKS_CACHE_FILE) && (time() - filemtime(JWKS_CACHE_FILE)) < JWKS_TTL) {
        $cached = @file_get_contents(JWKS_CACHE_FILE);
        if (is_string($cached)) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded) && isset($decoded['keys']) && is_array($decoded['keys'])) {
                return $decoded['keys'];
            }
        }
    }
    $ch = curl_init();
    if (!$ch) return null;
    $opts = [
        CURLOPT_URL            => 'https://www.googleapis.com/oauth2/v3/certs',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_MAXREDIRS      => 0,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'auth-verifier/1.0',
    ];
    if (defined('CURLPROTO_HTTPS')) {
        $opts[CURLOPT_PROTOCOLS]       = CURLPROTO_HTTPS;
        $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
    }
    curl_setopt_array($ch, $opts);
    $response  = curl_exec($ch);
    $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno     = curl_errno($ch);
    curl_close($ch);
    if ($errno !== 0 || $http_code !== 200 || !is_string($response)) {
        if (is_file(JWKS_CACHE_FILE)) {
            $cached = @file_get_contents(JWKS_CACHE_FILE);
            if (is_string($cached)) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded) && isset($decoded['keys']) && is_array($decoded['keys'])) {
                    return $decoded['keys'];
                }
            }
        }
        return null;
    }
    $decoded = json_decode($response, true);
    if (!is_array($decoded) || !isset($decoded['keys']) || !is_array($decoded['keys'])) return null;
    @file_put_contents(JWKS_CACHE_FILE, $response, LOCK_EX);
    @chmod(JWKS_CACHE_FILE, 0640);
    return $decoded['keys'];
}

function find_google_jwk(string $kid): ?array {
    $keys = fetch_google_jwks(false);
    if (is_array($keys)) {
        foreach ($keys as $k) {
            if (is_array($k) && ($k['kid'] ?? '') === $kid && ($k['kty'] ?? '') === 'RSA') return $k;
        }
    }
    $keys = fetch_google_jwks(true);
    if (is_array($keys)) {
        foreach ($keys as $k) {
            if (is_array($k) && ($k['kid'] ?? '') === $kid && ($k['kty'] ?? '') === 'RSA') return $k;
        }
    }
    return null;
}

function looks_like_jwt(string $s): bool {
    return strlen($s) > 0
        && strlen($s) <= MAX_TOKEN_LEN
        && (bool)preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $s);
}

function verify_google_id_token_local(string $jwt, string $expected_aud, string $secret_key): ?array {
    if (!looks_like_jwt($jwt)) return null;
    if (!function_exists('openssl_verify')) return null;
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return null;
    list($h_b64, $p_b64, $s_b64) = $parts;
    $header_raw  = b64url_decode($h_b64);
    $payload_raw = b64url_decode($p_b64);
    $sig         = b64url_decode($s_b64);
    if ($header_raw === false || $payload_raw === false || $sig === false) return null;
    if (strlen($header_raw) > 1024 || strlen($payload_raw) > 8192) return null;
    $header  = json_decode($header_raw, true);
    $payload = json_decode($payload_raw, true);
    if (!is_array($header) || !is_array($payload)) return null;
    if (($header['alg'] ?? '') !== 'RS256') return null;
    $kid = $header['kid'] ?? '';
    if (!is_string($kid) || $kid === '' || strlen($kid) > 128) return null;
    $jwk = find_google_jwk($kid);
    if (!$jwk || !isset($jwk['n'], $jwk['e'])) return null;
    $pem = jwk_rsa_to_pem((string)$jwk['n'], (string)$jwk['e']);
    if ($pem === null) return null;
    $pkey = @openssl_pkey_get_public($pem);
    if (!$pkey) return null;
    $signed_data = $h_b64 . '.' . $p_b64;
    $ok = @openssl_verify($signed_data, $sig, $pkey, OPENSSL_ALGO_SHA256);
    if ($ok !== 1) return null;
    $iss = $payload['iss'] ?? '';
    if ($iss !== 'https://accounts.google.com' && $iss !== 'accounts.google.com') return null;
    $aud = $payload['aud'] ?? '';
    if (!is_string($aud) || !hash_equals($expected_aud, $aud)) return null;
    $azp = $payload['azp'] ?? null;
    if ($azp !== null && (!is_string($azp) || !hash_equals($expected_aud, $azp))) return null;
    $now = time();
    $exp = isset($payload['exp']) ? (int)$payload['exp'] : 0;
    if ($exp <= 0 || $exp < $now - 5) return null;
    if (isset($payload['iat']) && (int)$payload['iat'] > $now + 60) return null;
    if (isset($payload['nbf']) && (int)$payload['nbf'] > $now + 5) return null;
    $v = $payload['email_verified'] ?? null;
    if ($v !== true && $v !== 'true' && $v !== 1 && $v !== '1') return null;
    if (empty($payload['email']) || !is_string($payload['email'])) return null;
    $jti = (string)($payload['jti'] ?? '');
    if ($jti === '') $jti = 'h:' . hash('sha256', $jwt);
    if (jti_seen_and_record($jti, $secret_key)) return null;
    return $payload;
}

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    session_write_close();
    header('Location: index.php');
    exit;
}

$error_message = '';
$remaining = get_lockout_remaining($AUTH_SECRET);
$is_locked = ($remaining !== false);

$is_post        = $_SERVER['REQUEST_METHOD'] === 'POST';
$is_google_post = $is_post && isset($_POST['google_token']);
$is_manual_post = $is_post && isset($_POST['username']) && !$is_google_post;

if ($is_google_post) {
    header('Content-Type: application/json; charset=utf-8');
}

$client_ip = get_client_ip();

if ($is_locked && $is_post) {
    $method = $is_google_post ? 'google' : 'manual';
    log_attempt('locked', $method);
    pad_response_time($auth_start);
    if ($is_google_post) {
        echo json_encode([
            'success' => false,
            'error'   => 'System locked. Try again in ' . format_remaining((int)$remaining)
        ]);
        exit;
    }
    $error_message = 'System locked. Try again in ' . format_remaining((int)$remaining);
    $is_manual_post = false;
    $is_google_post = false;
}

if (!$is_locked && $is_post && !sec_fetch_post_ok()) {
    log_attempt('failure', $is_google_post ? 'google' : 'manual');
    pad_response_time($auth_start);
    if ($is_google_post) {
        echo json_encode(['success' => false, 'error' => 'Invalid request context.']);
        exit;
    }
    $error_message = 'Invalid request context. Please reload the page.';
    $is_manual_post = false;
    $is_google_post = false;
}

if (!$is_locked && $is_post && !ctype_form_ok((string)($_SERVER['CONTENT_TYPE'] ?? ''))) {
    log_attempt('failure', $is_google_post ? 'google' : 'manual');
    pad_response_time($auth_start);
    if ($is_google_post) {
        echo json_encode(['success' => false, 'error' => 'Invalid content type.']);
        exit;
    }
    $error_message = 'Invalid content type.';
    $is_manual_post = false;
    $is_google_post = false;
}

if (!$is_locked && $is_post) {
    $ua_raw = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (strlen($ua_raw) < 5 || strlen($ua_raw) > MAX_UA_LEN) {
        log_attempt('failure', $is_google_post ? 'google' : 'manual');
        pad_response_time($auth_start);
        if ($is_google_post) {
            echo json_encode(['success' => false, 'error' => 'Invalid client.']);
            exit;
        }
        $error_message = 'Invalid client.';
        $is_manual_post = false;
        $is_google_post = false;
    }
}

if (!$is_locked && $is_post && !origin_valid()) {
    log_attempt('failure', $is_google_post ? 'google' : 'manual');
    pad_response_time($auth_start);
    if ($is_google_post) {
        echo json_encode(['success' => false, 'error' => 'Invalid origin.']);
        exit;
    }
    $error_message = 'Invalid origin. Please reload the page and try again.';
    $is_manual_post = false;
    $is_google_post = false;
}

if (!$is_locked && $is_post && !csrf_valid()) {
    log_attempt('failure', $is_google_post ? 'google' : 'manual');
    pad_response_time($auth_start);
    if ($is_google_post) {
        echo json_encode(['success' => false, 'error' => 'Invalid request. Reload the page.']);
        exit;
    }
    $error_message = 'Invalid request. Please reload the page and try again.';
    $is_manual_post = false;
    $is_google_post = false;
}

if (!$is_locked && ($is_manual_post || $is_google_post)) {
    $throttle = ip_throttle_check($client_ip, $AUTH_SECRET);
    if ($throttle['blocked']) {
        log_attempt('throttled', $is_google_post ? 'google' : 'manual');
        pad_response_time($auth_start);
        if ($is_google_post) {
            echo json_encode([
                'success' => false,
                'error'   => 'Too many requests. Wait ' . (int)$throttle['wait'] . 's.'
            ]);
            exit;
        }
        $error_message = 'Too many requests from this IP. Wait ' . (int)$throttle['wait'] . ' second(s).';
        $is_manual_post = false;
        $is_google_post = false;
    }
}

if (!$is_locked && $is_manual_post) {
    $username = is_string($_POST['username'] ?? null) ? (string)$_POST['username'] : '';
    $p1 = is_string($_POST['password1'] ?? null) ? (string)$_POST['password1'] : '';
    $p2 = is_string($_POST['password2'] ?? null) ? (string)$_POST['password2'] : '';
    $p3 = is_string($_POST['password3'] ?? null) ? (string)$_POST['password3'] : '';
    $p4 = is_string($_POST['password4'] ?? null) ? (string)$_POST['password4'] : '';
    $honeypot = is_string($_POST['website_url'] ?? null) ? (string)$_POST['website_url'] : '';
    $passwords = [$p1, $p2, $p3, $p4];

    $lengths_ok = strlen($username) > 0 && strlen($username) <= MAX_USERNAME_LEN
        && strlen($p1) > 0 && strlen($p1) <= MAX_PASSWORD_LEN
        && strlen($p2) > 0 && strlen($p2) <= MAX_PASSWORD_LEN
        && strlen($p3) > 0 && strlen($p3) <= MAX_PASSWORD_LEN
        && strlen($p4) > 0 && strlen($p4) <= MAX_PASSWORD_LEN
        && $honeypot === '';

    $name_candidate = $lengths_ok ? $username : 'placeholder_____';
    $name_ok = hash_equals($correct_username, $name_candidate);
    $is_valid = $lengths_ok && $name_ok;

    foreach ($passwords as $index => $pass) {
        $candidate = $lengths_ok ? $pass : 'placeholder';
        $key_check = password_verify($candidate, $correct_hashes[$index]);
        $is_valid = $is_valid && $key_check;
    }

    if ($is_valid) {
        log_attempt('success', 'manual');
        reset_attempts();
        ip_throttle_reset($client_ip, $AUTH_SECRET);
        session_regenerate_id(true);
        $_SESSION = [];
        $_SESSION['logged_in']  = true;
        $_SESSION['login_time'] = time();
        $_SESSION['login_ip']   = $client_ip;
        $_SESSION['login_ua']   = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (function_exists('sodium_memzero')) {
            @sodium_memzero($p1); @sodium_memzero($p2);
            @sodium_memzero($p3); @sodium_memzero($p4);
            @sodium_memzero($username);
        }
        pad_response_time($auth_start);
        session_write_close();
        header('Location: index.php');
        exit;
    } else {
        log_attempt('failure', 'manual');
        ip_throttle_record($client_ip, $AUTH_SECRET);
        $count = record_failed_attempt($AUTH_SECRET);
        $remaining = get_lockout_remaining($AUTH_SECRET);
        if (function_exists('sodium_memzero')) {
            @sodium_memzero($p1); @sodium_memzero($p2);
            @sodium_memzero($p3); @sodium_memzero($p4);
            @sodium_memzero($username);
        }
        pad_response_time($auth_start);
        if ($remaining !== false) {
            $is_locked = true;
            $error_message = 'Too many failed attempts. Locked for ' . format_remaining((int)$remaining);
        } else {
            $left = MAX_ATTEMPTS - $count;
            if ($left < 0) $left = 0;
            $error_message = 'Invalid credentials. ' . $left . ' attempt(s) remaining.';
        }
    }
}

if (!$is_locked && $is_google_post) {
    $id_token_raw = is_string($_POST['google_token'] ?? null) ? (string)$_POST['google_token'] : '';
    $payload = looks_like_jwt($id_token_raw)
        ? verify_google_id_token_local($id_token_raw, GOOGLE_CLIENT_ID, $AUTH_SECRET)
        : null;

    $authorized = false;
    if ($payload !== null) {
        $email = strtolower((string)($payload['email'] ?? ''));
        $expected = strtolower($allowed_email);
        if (strlen($email) > 0 && strlen($email) === strlen($expected) && hash_equals($expected, $email)) {
            $authorized = true;
        }
    }

    if ($authorized) {
        log_attempt('success', 'google');
        reset_attempts();
        ip_throttle_reset($client_ip, $AUTH_SECRET);
        session_regenerate_id(true);
        $_SESSION = [];
        $_SESSION['logged_in']  = true;
        $_SESSION['login_time'] = time();
        $_SESSION['login_ip']   = $client_ip;
        $_SESSION['login_ua']   = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        pad_response_time($auth_start);
        session_write_close();
        echo json_encode(['success' => true]);
        exit;
    }

    log_attempt('failure', 'google');
    ip_throttle_record($client_ip, $AUTH_SECRET);
    record_failed_attempt($AUTH_SECRET);
    $remaining_after = get_lockout_remaining($AUTH_SECRET);
    pad_response_time($auth_start);
    if ($remaining_after !== false) {
        echo json_encode([
            'success' => false,
            'error'   => 'Too many failed attempts. Locked for ' . format_remaining((int)$remaining_after)
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access</title>
    <script src="https://accounts.google.com/gsi/client" async defer nonce="<?= htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8') ?>"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0b0b0d;
            --text: #ededee;
            --muted: #82828a;
            --dim: #44444a;
            --line: #1f1f23;
            --line-strong: #2c2c32;
            --field: #131316;
            --field-focus: #18181c;
            --accent: #f3f3f5;
            --ok: #5ddc9a;
            --danger: #f08585;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { color-scheme: dark; }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 24px;
            -webkit-font-smoothing: antialiased;
            line-height: 1.5;
        }

        .page {
            width: 100%;
            max-width: 360px;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-family: 'JetBrains Mono', monospace;
            font-size: 10px;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            color: var(--muted);
            padding-bottom: 14px;
            border-bottom: 1px solid var(--line);
            margin-bottom: 32px;
        }

        .topbar .mark {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .mark-dot {
            width: 6px;
            height: 6px;
            background: var(--muted);
            border-radius: 1px;
            transform: rotate(45deg);
        }

        .topbar .state {
            display: flex;
            align-items: center;
            gap: 7px;
            color: var(--ok);
        }

        .topbar .state .pulse {
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: var(--ok);
            position: relative;
        }


        .locked .topbar .state { color: var(--danger); }
        .locked .topbar .state .pulse { background: var(--danger); }
        .locked .topbar .state .pulse::after { border-color: var(--danger); }

        h1 {
            font-size: 28px;
            font-weight: 600;
            letter-spacing: -0.7px;
            line-height: 1.15;
            margin-bottom: 8px;
        }

        .lede {
            font-size: 13.5px;
            color: var(--muted);
            margin-bottom: 36px;
            max-width: 320px;
        }

        .section {
            margin-bottom: 28px;
        }

        .section-head {
            display: flex;
            align-items: baseline;
            gap: 10px;
            margin-bottom: 14px;
        }

        .section-num {
            font-family: 'JetBrains Mono', monospace;
            font-size: 10px;
            color: var(--dim);
            letter-spacing: 1px;
        }

        .section-label {
            font-size: 12px;
            color: var(--muted);
            font-weight: 500;
            letter-spacing: 0.2px;
        }

        .google-btn {
            width: 100%;
            background: transparent;
            border: 1px solid var(--line-strong);
            color: var(--text);
            padding: 11px 16px;
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
            font-size: 13.5px;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: border-color 0.15s ease, background 0.15s ease;
        }

        .google-btn:hover {
            border-color: #3a3a42;
            background: rgba(255,255,255,0.02);
        }

        .google-btn svg {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
        }

        .gsi-hidden {
            position: absolute;
            top: -9999px;
            left: -9999px;
            opacity: 0;
            pointer-events: none;
        }

        .error {
            background: rgba(240, 133, 133, 0.06);
            border: 1px solid rgba(240, 133, 133, 0.18);
            color: var(--danger);
            padding: 9px 12px;
            border-radius: 6px;
            font-size: 12px;
            margin-bottom: 18px;
            line-height: 1.45;
        }

        .field {
            margin-bottom: 14px;
        }

        .field-label {
            font-family: 'JetBrains Mono', monospace;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--dim);
            margin-bottom: 6px;
            display: block;
        }

        input {
            width: 100%;
            background: var(--field);
            border: 1px solid var(--line);
            color: var(--text);
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
            padding: 10px 12px;
            border-radius: 6px;
            transition: border-color 0.15s ease, background 0.15s ease;
            letter-spacing: 0.3px;
        }

        input::placeholder { color: var(--dim); }
        input:hover { border-color: var(--line-strong); }
        input:focus {
            outline: none;
            border-color: #45454d;
            background: var(--field-focus);
        }

        .keys {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .key {
            position: relative;
        }

        .key-num {
            position: absolute;
            left: 11px;
            top: 50%;
            transform: translateY(-50%);
            font-family: 'JetBrains Mono', monospace;
            font-size: 10px;
            color: var(--dim);
            pointer-events: none;
        }

        .key input {
            padding-left: 32px;
        }

        .submit {
            width: 100%;
            background: var(--accent);
            color: #0b0b0d;
            border: none;
            border-radius: 6px;
            padding: 12px;
            font-family: 'Inter', sans-serif;
            font-weight: 600;
            font-size: 13.5px;
            cursor: pointer;
            transition: opacity 0.15s ease, transform 0.05s ease;
            margin-top: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            letter-spacing: -0.1px;
        }

        .submit:hover { opacity: 0.92; }
        .submit:active { transform: translateY(1px); }
        .submit svg { width: 13px; height: 13px; }

        .footer {
            margin-top: 36px;
            padding-top: 14px;
            border-top: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            font-family: 'JetBrains Mono', monospace;
            font-size: 10px;
            letter-spacing: 1px;
            color: var(--dim);
            text-transform: uppercase;
        }

        .lockout {
            margin: 8px 0 28px;
        }

        .lockout-frame {
            border: 1px solid rgba(240, 133, 133, 0.18);
            background: rgba(240, 133, 133, 0.03);
            border-radius: 8px;
            padding: 22px 18px;
        }

        .lockout-tag {
            font-family: 'JetBrains Mono', monospace;
            font-size: 10px;
            letter-spacing: 1.5px;
            color: var(--danger);
            text-transform: uppercase;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .lockout-tag::before {
            content: '';
            width: 5px;
            height: 5px;
            background: var(--danger);
            border-radius: 50%;
        }

        .lockout-timer {
            font-family: 'JetBrains Mono', monospace;
            font-size: 30px;
            font-weight: 500;
            color: var(--text);
            letter-spacing: 0;
            margin-bottom: 10px;
            line-height: 1.1;
        }

        .lockout-note {
            font-size: 12.5px;
            color: var(--muted);
            line-height: 1.5;
        }

        @media (max-width: 420px) {
            body { padding: 32px 20px; }
        }

        .hp {
            position: absolute;
            left: -9999px;
            top: -9999px;
            width: 1px;
            height: 1px;
            opacity: 0;
            pointer-events: none;
        }
    </style>
</head>
<body>
    <div class="page <?= $is_locked ? 'locked' : '' ?>">
        <div class="topbar">
            
            <div class="state">
                <span class="pulse"></span>
                <span><?= $is_locked ? 'Locked' : 'Ready' ?></span>
            </div>
        </div>

        <?php if ($is_locked): ?>
            <h1>Locked out.</h1>
            <p class="lede">Sign-in is paused after repeated failed attempts. Wait for the cooldown to expire.</p>

            <div class="lockout">
                <div class="lockout-frame">
                    <div class="lockout-tag">Cooldown active</div>
                    <div class="lockout-timer" id="countdown" data-remaining="<?= (int)$remaining ?>"><?= htmlspecialchars(format_remaining((int)$remaining), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="lockout-note">All authentication methods are disabled until the timer expires.</div>
                </div>
            </div>
        <?php else: ?>
            <h1>Sign in.</h1>
            <p class="lede">Restricted area. Credentials required.</p>

            <div class="section">
                <div class="section-head">
                    <span class="section-label">Log in with Google</span>
                </div>
                <button type="button" class="google-btn" id="googleBtn">
                    <svg viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
                    <span>Continue with Google</span>
                </button>
                <div class="gsi-hidden">
                    <div id="g_id_onload"
                         data-client_id="<?= htmlspecialchars(GOOGLE_CLIENT_ID, ENT_QUOTES, 'UTF-8') ?>"
                         data-callback="onGoogleLogin"
                         data-auto_prompt="false"
                         data-itp_support="true">
                    </div>
                    <div class="g_id_signin" data-type="standard"></div>
                </div>
            </div>

            <div class="section">
                <div class="section-head">
                    <span class="section-num">or, enter</span>
                    <span class="section-label">Credentials</span>
                </div>

                <?php if ($error_message): ?>
                    <div class="error"><?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>

                <form method="post" autocomplete="off" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="hp" aria-hidden="true">
                        <label>Website<input type="text" name="website_url" tabindex="-1" autocomplete="off" value=""></label>
                    </div>
                    <div class="field">
                        <label class="field-label" for="username">Username</label>
                        <input type="text" id="username" name="username" required spellcheck="false" autocapitalize="off" autocomplete="off" maxlength="<?= (int)MAX_USERNAME_LEN ?>">
                    </div>

                    <label class="field-label">Passwords</label>
                    <div class="keys">
                        <div class="key"><span class="key-num">01</span><input type="password" name="password1" required maxlength="<?= (int)MAX_PASSWORD_LEN ?>" autocomplete="new-password"></div>
                        <div class="key"><span class="key-num">02</span><input type="password" name="password2" required maxlength="<?= (int)MAX_PASSWORD_LEN ?>" autocomplete="new-password"></div>
                        <div class="key"><span class="key-num">03</span><input type="password" name="password3" required maxlength="<?= (int)MAX_PASSWORD_LEN ?>" autocomplete="new-password"></div>
                        <div class="key"><span class="key-num">04</span><input type="password" name="password4" required maxlength="<?= (int)MAX_PASSWORD_LEN ?>" autocomplete="new-password"></div>
                    </div>

                    <button type="submit" class="submit">
                        <span>Authenticate</span>
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 8h10M9 4l4 4-4 4"/></svg>
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <div class="footer">
        </div>
    </div>

    <script nonce="<?= htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8') ?>">
        window.__CSRF = <?= json_encode($csrf_token, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        function onGoogleLogin(response) {
            if (!response || !response.credential) { alert('Unauthorized.'); return; }
            const data = new FormData();
            data.append('google_token', response.credential);
            data.append('csrf_token', window.__CSRF);
            fetch(window.location.href, {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'error',
                referrerPolicy: 'no-referrer-when-downgrade'
            })
                .then(res => res.json().catch(() => ({ success: false, error: 'Unauthorized.' })))
                .then(res => {
                    if (res && res.success) window.location.replace('index.php');
                    else alert((res && res.error) ? String(res.error).slice(0, 200) : 'Unauthorized.');
                })
                .catch(() => alert('Network error.'));
        }

        document.addEventListener('click', (e) => {
            const trigger = e.target.closest('#googleBtn');
            if (!trigger) return;
            const hidden = document.querySelector('.gsi-hidden [role="button"], .gsi-hidden div[tabindex]');
            if (hidden) hidden.click();
            else if (window.google && google.accounts && google.accounts.id) {
                google.accounts.id.prompt();
            }
        });

        (function() {
            const el = document.getElementById('countdown');
            if (!el) return;
            let remaining = parseInt(el.dataset.remaining, 10);
            if (!isFinite(remaining) || remaining < 0) remaining = 0;
            const fmt = (s) => {
                const h = Math.floor(s / 3600);
                const m = Math.floor((s % 3600) / 60);
                const sec = s % 60;
                return String(h).padStart(2, '0') + 'h ' +
                       String(m).padStart(2, '0') + 'm ' +
                       String(sec).padStart(2, '0') + 's';
            };
            const timer = setInterval(() => {
                remaining--;
                if (remaining <= 0) {
                    clearInterval(timer);
                    window.location.reload();
                    return;
                }
                el.textContent = fmt(remaining);
            }, 1000);
        })();
    </script>
</body>
</html>
