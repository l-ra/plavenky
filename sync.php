<?php
declare(strict_types=1);
/**
 * Plavenky – encrypted file store (protocol v1).
 * Server intentionally never decrypts the application data or interprets the primary role.
 * Deployment: HTTPS, PHP 8+, one writable local filesystem, PATH_INFO routing.
 */
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function fail(int $status, string $code, string $message): never {
    respond($status, ['error' => $code, 'message' => $message]);
}
function envValue(string $name, string $fallback): string {
    $value = getenv($name);
    return $value === false || $value === '' ? $fallback : $value;
}
function b64urlDecode(string $input): string|false {
    if (!preg_match('/^[A-Za-z0-9_-]+$/D', $input)) return false;
    $data = base64_decode(strtr($input, '-_', '+/') . str_repeat('=', (4 - strlen($input) % 4) % 4), true);
    if ($data === false || rtrim(strtr(base64_encode($data), '+/', '-_'), '=') !== $input) return false;
    return $data;
}
function originPolicy(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = array_filter(array_map('trim', explode(',', envValue('PLAVENKY_SYNC_ALLOWED_ORIGINS', ''))));
    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, PUT, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, If-Match, If-None-Match');
        header('Access-Control-Expose-Headers: ETag');
        header('Access-Control-Max-Age: 600');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        if ($origin !== '' && !in_array($origin, $allowed, true)) fail(403, 'ORIGIN_DENIED', 'Origin is not permitted');
        http_response_code(204);
        exit;
    }
    if ($origin !== '' && !in_array($origin, $allowed, true)) fail(403, 'ORIGIN_DENIED', 'Origin is not permitted');
}
function requireTls(): void {
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTPS'] ?? '') === '1';
    if (envValue('PLAVENKY_SYNC_TRUST_PROXY', '0') === '1' &&
        strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') $https = true;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $local = in_array($ip, ['127.0.0.1', '::1'], true);
    if (!$https && !(envValue('PLAVENKY_SYNC_ALLOW_HTTP', '0') === '1' && $local)) {
        fail(403, 'HTTPS_REQUIRED', 'HTTPS is required');
    }
}
function canonicalGroup(): array {
    $domain = strtolower(envValue('PLAVENKY_SYNC_DOMAIN', (string) ($_SERVER['SERVER_NAME'] ?? '')));
    if (strlen($domain) > 253 || !preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $domain) ||
        str_contains($domain, '..')) fail(400, 'INVALID_DOMAIN', 'Invalid configured domain');
    // Check the raw path before a webserver or PHP normalizes/decode percent escapes.
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (!is_string($requestPath) || str_contains($requestPath, '%')) {
        fail(400, 'INVALID_PATH', 'Encoded group paths are not allowed');
    }
    $raw = $_SERVER['PATH_INFO'] ?? '';
    // Some SAPIs (notably PHP's built-in server) omit PATH_INFO.
    if ($raw === '') {
        $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '/sync.php';
        if (is_string($uriPath) && is_string($scriptPath) &&
            str_starts_with($uriPath, $scriptPath . '/')) {
            $raw = substr($uriPath, strlen($scriptPath));
        }
    }
    // Reject percent encoding entirely: no ambiguous double decoding, slash or dot segment aliases.
    if ($raw === '' || strlen($raw) > 400 || !str_starts_with($raw, '/') ||
        str_contains($raw, '%') || str_contains($raw, chr(92)) || str_contains($raw, chr(0))) {
        fail(400, 'INVALID_PATH', 'Invalid group path');
    }
    $parts = explode('/', substr($raw, 1));
    if (count($parts) > 12) fail(400, 'INVALID_PATH', 'Too many group segments');
    foreach ($parts as $part) {
        if ($part === '.' || $part === '..' || strlen($part) > 64 ||
            !preg_match('/^[A-Za-z0-9_.-]+$/D', $part)) fail(400, 'INVALID_PATH', 'Invalid group segment');
    }
    return [$domain, implode('/', $parts)];
}
function storagePath(string $domain, string $group): string {
    $template = envValue('PLAVENKY_SYNC_STORAGE_TEMPLATE', '/working/{domenaRequestu}/{cestaRequestu}/data');
    if (!str_starts_with($template, '/') || str_contains($template, chr(0)) ||
        str_contains($template, chr(92)) || !str_contains($template, '{domenaRequestu}') ||
        !str_contains($template, '{cestaRequestu}')) fail(500, 'INVALID_STORAGE_TEMPLATE', 'Invalid storage template');
    $path = str_replace(['{domenaRequestu}', '{cestaRequestu}'], [$domain, $group], $template);
    $segments = explode('/', ltrim($path, '/'));
    foreach ($segments as $part) {
        if ($part === '' || $part === '.' || $part === '..' || str_contains($part, '{') ||
            !preg_match('/^[A-Za-z0-9_.-]+$/D', $part)) {
            fail(500, 'INVALID_STORAGE_TEMPLATE', 'Invalid storage template');
        }
    }
    return '/' . implode('/', $segments);
}
function checkNoSymlinks(string $path): void {
    $current = '';
    foreach (explode('/', ltrim($path, '/')) as $part) {
        $current .= '/' . $part;
        if (is_link($current)) fail(400, 'UNSAFE_PATH', 'Symbolic links are forbidden');
        if (file_exists($current) && !is_dir($current) && $current !== $path) {
            fail(500, 'STORAGE_NOT_ACCESSIBLE', 'Storage parent is not a directory');
        }
    }
}
function ensureDirectory(string $path): void {
    checkNoSymlinks($path);
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        fail(500, 'STORAGE_NOT_WRITABLE', 'Cannot create storage directory');
    }
    checkNoSymlinks($path);
}
function nearestWritableParent(string $path): bool {
    checkNoSymlinks($path);
    $p = $path;
    while (!file_exists($p)) $p = dirname($p);
    return is_dir($p) && is_readable($p) && is_writable($p);
}
function initialized(string $dir): bool {
    return is_file($dir . '/config.json') && is_file($dir . '/auth.verifier.json') &&
           is_file($dir . '/current.enc');
}
function stateOf(string $dir): array {
    if (!is_dir($dir)) {
        $canCreate = nearestWritableParent($dir);
        return ['configured'=>$canCreate, 'initialized'=>false,
            'accessible'=>$canCreate, 'writable'=>$canCreate,
            'errors'=>$canCreate ? [] : [[
                'code'=>'STORAGE_NOT_WRITABLE',
                'message'=>'The target storage parent is missing or not writable'
            ]]];
    }
    checkNoSymlinks($dir);
    $access = is_readable($dir);
    $write = is_writable($dir);
    $config = is_file($dir . '/config.json');
    $verifier = is_file($dir . '/auth.verifier.json');
    $current = is_file($dir . '/current.enc');
    $complete = $config && $verifier && $current;
    $partial = ($config || $verifier || $current) && !$complete;
    $errors = [];
    if (!$access) $errors[] = ['code'=>'STORAGE_NOT_ACCESSIBLE','message'=>'Storage is not readable'];
    if (!$write) $errors[] = ['code'=>'STORAGE_NOT_WRITABLE','message'=>'Storage is not writable'];
    if ($partial) $errors[] = ['code'=>'CONFIG_CORRUPTED','message'=>'Incomplete initialization'];
    if ($complete) {
        $cfg = json_decode((string) @file_get_contents($dir . '/config.json'), true);
        if (!is_array($cfg) || ($cfg['protocolVersion'] ?? null) !== 1 ||
            !is_array($cfg['kdf'] ?? null)) $errors[] = ['code'=>'CONFIG_CORRUPTED','message'=>'Invalid configuration'];
        $auth = json_decode((string) @file_get_contents($dir . '/auth.verifier.json'), true);
        if (!is_array($auth) || !is_string($auth['authVerifier'] ?? null) ||
            !preg_match('/^[a-f0-9]{64}$/D', $auth['authVerifier'])) {
            $errors[] = ['code'=>'CONFIG_CORRUPTED','message'=>'Invalid authentication configuration'];
        }
        $encrypted = @file_get_contents($dir . '/current.enc');
        if (!is_string($encrypted) || !is_array(json_decode($encrypted, true))) {
            $errors[] = ['code'=>'CONFIG_CORRUPTED','message'=>'Invalid current snapshot'];
        }
    }
    return ['configured'=>$access && $write && !$partial && !$errors,
        'initialized'=>$complete && !$errors, 'accessible'=>$access, 'writable'=>$write,'errors'=>$errors];
}
function readJson(string $file): array {
    $data = @file_get_contents($file);
    $json = is_string($data) ? json_decode($data, true) : null;
    if (!is_array($json)) fail(500, 'CONFIG_CORRUPTED', 'Storage configuration is corrupt');
    return $json;
}
function configuration(string $dir): array {
    return readJson($dir . '/config.json');
}
function verifier(string $dir): string {
    $v = readJson($dir . '/auth.verifier.json');
    if (!is_string($v['authVerifier'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $v['authVerifier'])) {
        fail(500, 'CONFIG_CORRUPTED', 'Authentication configuration is corrupt');
    }
    return $v['authVerifier'];
}
function auth(string $dir): void {
    if (!initialized($dir)) fail(404, 'NOT_INITIALIZED', 'Group not initialized');
    $value = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer ([A-Za-z0-9_-]{43})$/D', $value, $m)) {
        header('WWW-Authenticate: Bearer realm="plavenky-sync"');
        fail(401, 'UNAUTHORIZED', 'Authentication required');
    }
    $bytes = b64urlDecode($m[1]);
    if ($bytes === false || strlen($bytes) !== 32 ||
        !hash_equals(verifier($dir), hash('sha256', $bytes))) {
        header('WWW-Authenticate: Bearer realm="plavenky-sync"');
        fail(401, 'UNAUTHORIZED', 'Authentication required');
    }
}
function body(int $limit): string {
    $raw = file_get_contents('php://input', false, null, 0, $limit + 1);
    if (!is_string($raw)) fail(400, 'INVALID_BODY', 'Could not read request body');
    if (strlen($raw) > $limit) fail(413, 'TOO_LARGE', 'File exceeds size limit');
    if (strlen($raw) === 0) fail(400, 'INVALID_BODY', 'Empty request');
    return $raw;
}
function etag(string $bytes): string { return '"sha256-' . hash('sha256', $bytes) . '"'; }
function locked(string $file, callable $callback): mixed {
    $handle = @fopen($file, 'c+b');
    if (!$handle || !flock($handle, LOCK_EX)) fail(500, 'LOCK_UNAVAILABLE', 'Cannot lock storage');
    try { return $callback(); }
    finally { flock($handle, LOCK_UN); fclose($handle); }
}
function atomicWrite(string $file, string $bytes): void {
    $tmp = dirname($file) . '/.tmp-' . bin2hex(random_bytes(16));
    $handle = @fopen($tmp, 'xb');
    if (!$handle) fail(500, 'STORAGE_NOT_WRITABLE', 'Cannot create temporary file');
    try {
        if (fwrite($handle, $bytes) !== strlen($bytes)) fail(500, 'WRITE_FAILED', 'Incomplete write');
        if (!fflush($handle)) fail(500, 'WRITE_FAILED', 'Flush failed');
        if (function_exists('fsync')) @fsync($handle);
    } finally { fclose($handle); }
    if (!@rename($tmp, $file)) { @unlink($tmp); fail(500, 'WRITE_FAILED', 'Atomic rename failed'); }
    @chmod($file, 0600);
}
function initGroup(string $dir): never {
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') !== '*') fail(428, 'PRECONDITION_REQUIRED', 'If-None-Match: * required');
    $data = json_decode(body(14000000), true);
    if (!is_array($data) || ($data['protocolVersion'] ?? null) !== 1 ||
        !is_array($data['kdf'] ?? null) || !is_string($data['authVerifier'] ?? null) ||
        !is_string($data['snapshot'] ?? null)) fail(400, 'INVALID_BODY', 'Invalid initialize request');
    $kdf = $data['kdf'];
    $salt = isset($kdf['salt']) && is_string($kdf['salt']) ? b64urlDecode($kdf['salt']) : false;
    if (($kdf['name'] ?? '') !== 'PBKDF2-SHA256' || ($kdf['iterations'] ?? 0) < 600000 ||
        ($kdf['iterations'] ?? 0) > 2000000 || $salt === false || strlen($salt) !== 32 ||
        !preg_match('/^[a-f0-9]{64}$/D', $data['authVerifier'])) {
        fail(400, 'INVALID_KDF', 'Invalid KDF/verifier');
    }
    $snapshot = b64urlDecode($data['snapshot']);
    if ($snapshot === false || strlen($snapshot) > 10 * 1024 * 1024 ||
        !is_array(json_decode($snapshot, true))) fail(400, 'INVALID_BODY', 'Invalid encrypted snapshot');
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer ([A-Za-z0-9_-]{43})$/D', $authHeader, $m)) fail(401, 'UNAUTHORIZED', 'Authentication required');
    $token = b64urlDecode($m[1]);
    if ($token === false || strlen($token) !== 32 ||
        !hash_equals($data['authVerifier'], hash('sha256', $token))) fail(401, 'UNAUTHORIZED', 'Authentication required');
    $parent = dirname($dir);
    ensureDirectory($parent);
    locked($parent . '/.plavenky-init.lock', function() use ($dir, $data, $snapshot) {
        // An administrator may have pre-created an empty data directory.
        if (is_dir($dir) && !is_link($dir)) {
            $entries = array_diff(scandir($dir) ?: [], ['.', '..']);
            if (!$entries && !@rmdir($dir)) fail(500, 'STORAGE_NOT_WRITABLE', 'Cannot replace empty group directory');
        }
        if (file_exists($dir)) fail(412, 'ALREADY_INITIALIZED', 'Group already exists');
        $stage = $dir . '.staging-' . bin2hex(random_bytes(12));
        if (!@mkdir($stage, 0700)) fail(500, 'STORAGE_NOT_WRITABLE', 'Cannot initialize storage');
        try {
            if (!mkdir($stage . '/history', 0700)) fail(500, 'WRITE_FAILED', 'Cannot initialize history');
            atomicWrite($stage . '/config.json', json_encode(['protocolVersion'=>1,'kdf'=>$data['kdf']], JSON_UNESCAPED_SLASHES));
            atomicWrite($stage . '/auth.verifier.json', json_encode(['authVerifier'=>$data['authVerifier']], JSON_UNESCAPED_SLASHES));
            atomicWrite($stage . '/current.enc', $snapshot);
            if (!@rename($stage, $dir)) fail(500, 'WRITE_FAILED', 'Cannot publish initialized group');
        } finally {
            if (is_dir($stage)) {
                foreach (glob($stage . '/*') ?: [] as $file) {
                    if (is_dir($file)) @rmdir($file);
                    else @unlink($file);
                }
                @rmdir($stage);
            }
        }
    });
    header('ETag: ' . etag($snapshot));
    respond(201, ['initialized'=>true]);
}
try {
    originPolicy();
    requireTls();
    [$domain, $group] = canonicalGroup();
    $dir = storagePath($domain, $group);
    checkNoSymlinks($dir);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $object = $_GET['object'] ?? null;
    if ($object !== null && !is_string($object)) fail(400, 'INVALID_OBJECT', 'Invalid object');
    if ($method === 'GET' && $object === null) {
        $status = stateOf($dir);
        $result = ['service'=>'plavenky-sync','configured'=>$status['configured'],
            'initialized'=>$status['initialized'],'group'=>$group,
            'storage'=>['accessible'=>$status['accessible'],'writable'=>$status['writable']],
            'errors'=>$status['errors']];
        if ($status['initialized']) $result['kdf'] = configuration($dir)['kdf'];
        respond(200, $result);
    }
    if ($method === 'POST' && $object === 'initialize') initGroup($dir);
    if (!in_array($method, ['GET','PUT'], true)) fail(405, 'METHOD_NOT_ALLOWED', 'Unsupported method');
    auth($dir);
    if ($object === 'current') {
        $file = $dir . '/current.enc';
        if ($method === 'GET') {
            $raw = @file_get_contents($file);
            if ($raw === false) fail(500, 'STORAGE_NOT_ACCESSIBLE', 'Cannot read snapshot');
            header('ETag: ' . etag($raw));
            header('Content-Type: application/octet-stream');
            echo $raw;
            exit;
        }
        $incoming = body(10 * 1024 * 1024);
        if (!is_array(json_decode($incoming, true))) fail(400, 'INVALID_BODY', 'Invalid encrypted envelope');
        $expected = $_SERVER['HTTP_IF_MATCH'] ?? '';
        if ($expected === '') fail(428, 'PRECONDITION_REQUIRED', 'If-Match is required');
        $newETag = locked($dir . '/.sync.lock', function() use ($file, $incoming, $expected) {
            $old = @file_get_contents($file);
            if ($old === false) fail(500, 'STORAGE_NOT_ACCESSIBLE', 'Cannot read current state');
            if (!hash_equals(etag($old), $expected)) fail(412, 'PRECONDITION_FAILED', 'Version has changed');
            atomicWrite($file, $incoming);
            return etag($incoming);
        });
        header('ETag: ' . $newETag);
        respond(200, ['saved'=>true]);
    }
    if ($object === 'history' && $method === 'GET') {
        $files = glob($dir . '/history/*.enc') ?: [];
        $names = [];
        foreach ($files as $file) {
            $name = basename($file, '.enc');
            if (preg_match('/^[a-f0-9-]{36}$/D', $name)) $names[] = $name;
        }
        sort($names, SORT_STRING);
        respond(200, ['versions'=>array_slice($names, 0, 500), 'truncated'=>count($names)>500]);
    }
    if (preg_match('~^history/([a-f0-9-]{36})$~D', $object, $m)) {
        $file = $dir . '/history/' . $m[1] . '.enc';
        if ($method === 'GET') {
            if (!is_file($file)) fail(404, 'NOT_FOUND', 'Historical version not found');
            $raw = @file_get_contents($file);
            if ($raw === false) fail(500, 'STORAGE_NOT_ACCESSIBLE', 'Cannot read history');
            header('ETag: ' . etag($raw));
            header('Content-Type: application/octet-stream');
            echo $raw;
            exit;
        }
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') !== '*') fail(428, 'PRECONDITION_REQUIRED', 'If-None-Match: * required');
        $incoming = body(10 * 1024 * 1024);
        if (!is_array(json_decode($incoming, true))) fail(400, 'INVALID_BODY', 'Invalid encrypted envelope');
        locked($dir . '/.sync.lock', function() use ($file, $incoming) {
            $f = @fopen($file, 'xb');
            if (!$f) fail(412, 'PRECONDITION_FAILED', 'Historical version already exists');
            try {
                if (fwrite($f, $incoming) !== strlen($incoming) || !fflush($f)) {
                    @unlink($file);
                    fail(500, 'WRITE_FAILED', 'Cannot store historical version');
                }
                if (function_exists('fsync')) @fsync($f);
            } finally { fclose($f); }
            @chmod($file, 0600);
        });
        respond(201, ['saved'=>true]);
    }
    fail(404, 'NOT_FOUND', 'Unknown object');
} catch (Throwable $e) {
    error_log('Plavenky sync: ' . $e->getMessage());
    fail(500, 'INTERNAL_ERROR', 'Unexpected storage error');
}
