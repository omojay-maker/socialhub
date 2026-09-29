<?php
require_once __DIR__ . '/Logger.php';

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES|ENT_HTML5,'UTF-8'); }
function json_response(array $data, int $code=200): void {
    http_response_code($code); header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES); exit;
}

/**
 * Authenticated JSON must never sit in a shared or browser cache.
 * Call once at the top of every api/*.php entry point.
 */
function json_no_store(): void {
    if (headers_sent()) return;
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}
/** BASE_URL is defined per entry point; fall back so helpers stay usable alone. */
function base_url(string $path=''): string {
    $base = defined('BASE_URL') ? (string)BASE_URL : rtrim((string)env_get('APP_URL', base_url_public()), '/');
    return $base . '/' . ltrim($path,'/');
}
function activity_log(?int $uid, string $action, string $desc, ?array $meta=null): void {
    try{
        $pdo=db(); $stmt=$pdo->prepare("INSERT INTO activity_logs (user_id,action,description,ip_address,meta) VALUES (?,?,?,?,?)");
        $ip=$_SERVER['REMOTE_ADDR']??'cli';
        $stmt->execute([$uid,$action,$desc,$ip, $meta?json_encode($meta):null]);
    }catch(Throwable $e){ Logger::error('activity_log failed',['error'=>$e->getMessage()]);}
}

/* ---------------------------------------------------------------- media --- */

/** HMAC that makes a media URL usable without a session (platform fetches). */
function media_signature(string $filename): string {
    return hash_hmac('sha256', $filename, app_key());
}

function media_signature_valid(string $filename, string $sig): bool {
    return hash_equals(media_signature($filename), $sig);
}

/** Absolute, publicly reachable URL for a stored upload. */
function media_url(string $filename, bool $signed = true): string {
    $base = rtrim((string)env_get('PUBLIC_MEDIA_BASE', base_url_public()), '/');
    $url  = $base . '/media.php?f=' . rawurlencode($filename);
    return $signed ? $url . '&sig=' . media_signature($filename) : $url;
}

function base_url_public(): string {
    $app = (string)env_get('APP_URL', '');
    if ($app !== '') return $app;
    $scheme = request_is_https() ? 'https' : 'http';
    $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir    = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return $scheme . '://' . $host . $dir;
}

/**
 * The exact URL that must be registered as the OAuth redirect URI in each
 * platform's developer portal. Providers derive their redirect from APP_URL
 * so the registered value and the value sent in the request cannot drift.
 * An explicit *_REDIRECT_URI env var still wins when set.
 */
function oauth_callback_url(string $slug): string {
    return rtrim((string)env_get('APP_URL', base_url_public()), '/')
        . '/public/api/oauth.php?action=callback&platform=' . rawurlencode($slug);
}

function media_mime(string $filename): string {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif',  'webp' => 'image/webp',  'mp4' => 'video/mp4',
        'mov' => 'video/quicktime', 'webm' => 'video/webm', 'avi' => 'video/x-msvideo',
    ][$ext] ?? 'application/octet-stream';
}

function media_storage_path(string $filename): string {
    return dirname(__DIR__, 2) . '/storage/uploads/' . basename($filename);
}

/**
 * Validates and stores an uploaded file under a random name.
 *
 * Both media.php and the create-post endpoint used to implement this
 * separately and drifted: one produced unguessable names, the other produced
 * uniqid('media_') names that media.php refuses to serve. There is one
 * implementation now.
 *
 * @param  array $file one entry from $_FILES
 * @return array{filename:string,original_name:string,file_path:string,file_type:string,file_size:int,width:?int,height:?int}|null
 *         null when the file is rejected; the reason is in $GLOBALS['media_upload_error'].
 */
function media_store_upload(array $file): ?array {
    global $media_upload_error;
    $media_upload_error = null;

    $max = (int) env_get('MAX_UPLOAD_SIZE', 10485760);
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $media_upload_error = 'Upload error';
        return null;
    }
    if (($file['size'] ?? 0) > $max) {
        $media_upload_error = 'File too large';
        return null;
    }
    if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        $media_upload_error = 'Invalid upload';
        return null;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = $finfo ? finfo_file($finfo, (string) $file['tmp_name']) : false;
    if ($finfo) finfo_close($finfo);

    $allowed = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
        'image/webp' => 'webp', 'video/mp4' => 'mp4', 'video/quicktime' => 'mov',
        'video/x-msvideo' => 'avi', 'video/webm' => 'webm',
    ];
    if (!is_string($mime) || !isset($allowed[$mime])) {
        $media_upload_error = 'Invalid file type: ' . (is_string($mime) ? $mime : 'unknown');
        return null;
    }

    // 128 bits of entropy, and the name must satisfy the pattern media.php
    // serves from, otherwise the file exists but is unreachable.
    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $dest     = media_storage_path($filename);
    if (!is_dir(dirname($dest))) @mkdir(dirname($dest), 0775, true);
    if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
        $media_upload_error = 'Move failed';
        return null;
    }
    @chmod($dest, 0644);

    // Image dimensions let the UI reserve layout space before the file loads.
    $w = $h = null;
    if (str_starts_with($mime, 'image/') && function_exists('getimagesize')) {
        $size = @getimagesize($dest);
        if ($size) { $w = (int) $size[0]; $h = (int) $size[1]; }
    }

    return [
        'filename'      => $filename,
        'original_name' => (string) ($file['name'] ?? $filename),
        // Relative, matching what the rest of the codebase expects.
        'file_path'     => 'uploads/' . $filename,
        'file_type'     => $mime,
        'file_size'     => (int) $file['size'],
        'width'         => $w,
        'height'        => $h,
    ];
}

/* ----------------------------------------------------------------- keys --- */

function app_key(): string {
    static $key = null;
    if ($key !== null) return $key;
    $key = (string)env_get('APP_KEY', '');
    if ($key === '') {
        // Development fallback so encryption still works without configuration.
        $key = hash('sha256', 'social-hub-dev-key|' . (string)env_get('DB_PASSWORD', ''));
    }
    return $key;
}

function app_key_is_configured(): bool {
    return (string)env_get('APP_KEY', '') !== '';
}

/* -------------------------------------------------------------- secrets --- */

/** AES-256-GCM. Returns "v1:<iv>:<tag>:<ciphertext>" (all base64url). */
function encrypt_secret(string $plain): string {
    $key = hash('sha256', app_key(), true);
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) throw new RuntimeException('Encryption failed');
    return 'v1:' . b64u($iv) . ':' . b64u($tag) . ':' . b64u($ct);
}

function decrypt_secret(?string $payload): ?string {
    if (!$payload) return null;
    if (!str_starts_with($payload, 'v1:')) return $payload; // legacy plaintext
    $parts = explode(':', $payload);
    if (count($parts) !== 4) return null;
    $key = hash('sha256', app_key(), true);
    $ct  = openssl_decrypt(b64u_decode($parts[3]), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, b64u_decode($parts[1]), b64u_decode($parts[2]));
    return $ct === false ? null : $ct;
}

function b64u(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function b64u_decode(string $s): string { return base64_decode(strtr($s, '-_', '+/')); }

/* ------------------------------------------------------------------ http -- */

/**
 * JSON HTTP client for platform APIs.
 * Returns ['ok'=>bool,'status'=>int,'body'=>array|string,'error'=>?string].
 */
function http_json(string $method, string $url, array $opts = []): array {
    if (!empty($opts['query']) && is_array($opts['query'])) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($opts['query']);
    }
    $ch = curl_init();
    $headers = ['Accept: application/json', 'User-Agent: 1TechLink-SocialHub/1.0'];
    if (isset($opts['json'])) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opts['json']));
    } elseif (isset($opts['form'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opts['form']));
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    } elseif (isset($opts['body'])) {
        // Raw bytes (media chunks). Content-Type is left to the caller.
        curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$opts['body']);
        if (!empty($opts['content_type'])) $headers[] = 'Content-Type: ' . $opts['content_type'];
    }
    if (isset($opts['multipart'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_multipart($opts['multipart']));
        $headers[] = 'Content-Type: multipart/form-data; boundary=' . http_multipart_boundary();
    }
    if (isset($opts['headers'])) {
        foreach ($opts['headers'] as $h) $headers[] = $h;
    }
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => (int)($opts['timeout'] ?? 20),
        CURLOPT_CONNECTTIMEOUT => (int)($opts['connect_timeout'] ?? 8),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    // `auth` wins when a provider needs a non-Bearer scheme (OAuth 1.0a).
    if (!empty($opts['auth'])) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ['Authorization: ' . $opts['auth']]));
    } elseif (!empty($opts['access_token'])) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, [
            'Authorization: Bearer ' . $opts['access_token'],
        ]));
    }
    $body    = curl_exec($ch);
    $errno   = curl_errno($ch);
    $status  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $rawHead = (string)curl_getinfo($ch, CURLINFO_HEADER_OUT);
    curl_close($ch);

    if ($errno) {
        return ['ok'=>false,'status'=>0,'body'=>null,'headers'=>[], 'error'=>"network: ".curl_strerror($errno)];
    }
    $json = json_decode((string)$body, true);
    $error = null;
    if (is_array($json)) {
        // Only surface a real message; a body with no error shape means nothing useful.
        $described = describe_api_error($json['error'] ?? $json);
        if ($described !== 'Unknown API error') $error = $described;
    }
    return [
        'ok'      => $status >= 200 && $status < 300,
        'status'  => $status,
        'body'    => $json ?? (string)$body,
        'headers' => http_parse_headers($rawHead),
        'error'   => $error,
    ];
}

/**
 * Builds a multipart/form-data body.
 * Each part is ['name'=>..,'contents'=>string|resource,'filename'=>?,'type'=>?].
 */
function http_build_multipart(array $parts): string {
    $boundary = http_multipart_boundary();
    $body = '';
    foreach ($parts as $p) {
        $body .= "--$boundary\r\n";
        $body .= 'Content-Disposition: form-data; name="' . $p['name'] . '"';
        if (!empty($p['filename'])) $body .= '; filename="' . $p['filename'] . '"';
        $body .= "\r\n";
        if (!empty($p['type'])) $body .= 'Content-Type: ' . $p['type'] . "\r\n";
        $body .= "\r\n";
        $body .= is_resource($p['contents']) ? (string)stream_get_contents($p['contents']) : (string)$p['contents'];
        $body .= "\r\n";
    }
    return $body . "--$boundary--\r\n";
}

function http_multipart_boundary(): string {
    static $b = null;
    return $b ??= 'SHH' . bin2hex(random_bytes(16));
}

/* -------------------------------------------------------------- oauth 1.0a */

/**
 * Builds an OAuth 1.0a (HMAC-SHA1) Authorization header value.
 * Used by X, whose write endpoints still expect OAuth 1.0a user context.
 */
/**
 * Splits a query string into a key/value map for OAuth 1.0a signing.
 * parse_str() is unusable here because it rewrites keys containing dots or
 * spaces, which would make the signature disagree with what is actually sent.
 */
function http_query_params(string $queryString): array {
    // strstr() returns false when there is no '?', which used to silently
    // discard the whole string and produce an empty signature.
    $withMarker = strstr($queryString, '?');
    if ($withMarker !== false) $queryString = $withMarker;
    $queryString = ltrim($queryString, '?');
    if ($queryString === '') return [];
    $out = [];
    foreach (explode('&', $queryString) as $pair) {
        if ($pair === '') continue;
        $eq = strpos($pair, '=');
        if ($eq === false) {
            $out[urldecode($pair)] = '';
            continue;
        }
        // urldecode, not rawurldecode: in a query string '+' means a space
        // (http_build_query encodes it that way), and OAuth 1.0a re-encodes a
        // decoded space as %20. A literal plus arrives as %2B and survives.
        $out[urldecode(substr($pair, 0, $eq))] = urldecode(substr($pair, $eq + 1));
    }
    return $out;
}

/**
 * Builds the OAuth 1.0a signature base string (RFC 5849 section 3.4.1).
 *
 * Exposed separately because it is the part that is easy to get subtly wrong
 * and the only thing worth eyeballing when a write call returns 401 in
 * production.
 */
function oauth1_base_string(string $method, string $url, array $params): string {
    ksort($params);
    $pairs = [];
    foreach ($params as $k => $v) {
        $pairs[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
    }
    // The query string is not part of the base URI; its parameters belong in
    // $params instead, otherwise the signature disagrees with the request.
    $bareUrl = preg_replace('#\?.*#', '', $url) ?: $url;
    return strtoupper($method) . '&' . rawurlencode($bareUrl) . '&' . rawurlencode(implode('&', $pairs));
}

function oauth1_header(
    string $method,
    string $url,
    array $params,
    string $consumerKey,
    string $consumerSecret,
    string $token,
    string $tokenSecret,
    ?string $nonce = null,
    ?int $timestamp = null
): string {
    // The nonce and timestamp are injectable so the signature can be checked
    // against the published Twitter/X reference vector in tests/.
    $oauth = [
        'oauth_consumer_key'     => $consumerKey,
        'oauth_nonce'            => $nonce ?? bin2hex(random_bytes(16)),
        'oauth_signature_method' => 'HMAC-SHA1',
        'oauth_timestamp'        => (string)($timestamp ?? time()),
        'oauth_token'            => $token,
        'oauth_version'          => '1.0',
    ];

    $all = array_merge($params, $oauth);
    ksort($all);

    $key   = rawurlencode($consumerSecret) . '&' . rawurlencode($tokenSecret);
    $oauth['oauth_signature'] = base64_encode(
        hash_hmac('sha1', oauth1_base_string($method, $url, $all), $key, true)
    );
    ksort($oauth);

    $out = [];
    foreach ($oauth as $k => $v) $out[] = rawurlencode($k) . '="' . rawurlencode((string)$v) . '"';
    return 'OAuth ' . implode(', ', $out);
}

function http_parse_headers(string $raw): array {
    $out = [];
    foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
        if (!str_contains($line, ':')) continue;
        [$k, $v] = explode(':', $line, 2);
        $out[strtolower(trim($k))] = trim($v);
    }
    return $out;
}

/**
 * Turns a platform error payload into one readable sentence.
 *
 * The providers disagree on shape: X returns {"error":{"message","code"}},
 * Meta returns {"error":{"message","type","error_subcode"}},
 * LinkedIn returns {"error":{"message","status"}} or {"serviceErrorCode":...},
 * and TikTok returns a flat {"error_code","description"}. All of them are
 * unwrapped here so the UI never has to show "Unknown API error" for a
 * failure it could have named.
 */
function describe_api_error($error): string {
    if (is_string($error)) return $error !== '' ? $error : 'Unknown API error';
    if (!is_array($error)) return 'Unknown API error';

    // Unwrap the {"error": {...}} envelope used by every provider here.
    if (isset($error['error']) && is_array($error['error'])) {
        $error = array_merge($error, $error['error']);
    }
    // X nests the real text one level deeper under errors[].
    if (isset($error['errors'][0]['message'])) {
        $error['message'] = $error['errors'][0]['message'];
    }

    $msg = $error['message']        // X, Meta, LinkedIn
        ?? $error['error_user_msg']  // Meta's user-safe text
        ?? $error['description']     // TikTok
        ?? $error['status']          // LinkedIn
        ?? $error['error']           // LinkedIn serviceError
        ?? null;

    $type    = $error['type'] ?? null;
    $code    = $error['code'] ?? $error['error_code'] ?? $error['serviceErrorCode'] ?? null;
    $subcode = $error['error_subcode'] ?? null;

    if ($msg === null) {
        return $code !== null ? 'Platform error ' . $code : 'Unknown API error';
    }
    $parts = array_filter([
        $msg,
        $type,
        $code !== null ? 'code:' . $code : null,
        $subcode ? 'subcode:' . $subcode : null,
    ]);
    return implode(' — ', array_map('strval', $parts));
}
