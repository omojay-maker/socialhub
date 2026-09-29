<?php
class Logger {
    /** First line of every log file; makes direct HTTP access return 404. */
    public const GUARD = '<?php /* 1TechLink Social Hub log - direct web access is blocked */ http_response_code(404); exit; ?>';

    public static function info(string $msg, array $ctx=[]): void { self::write('INFO',$msg,$ctx);}
    public static function warn(string $msg, array $ctx=[]): void { self::write('WARN',$msg,$ctx);}
    public static function error(string $msg, array $ctx=[]): void { self::write('ERROR',$msg,$ctx);}

    /**
     * Path of a log file, created with the PHP guard header when missing.
     *
     * The project root sits inside the Apache document root, so a .log file
     * would be handed out as plain text. The .php extension makes Apache
     * execute the guard line, which 404s instead of leaking the log.
     */
    public static function path(string $name = 'app-log.php'): string {
        $dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $file = $dir . '/' . basename($name);
        if (!is_file($file)) {
            @file_put_contents($file, self::GUARD . "\n", LOCK_EX);
        }
        return $file;
    }

    private static function write(string $level,string $msg,array $ctx): void {
        $ctx = self::redact($ctx);
        $line = date('Y-m-d H:i:s')." [$level] $msg ".json_encode($ctx,JSON_UNESCAPED_SLASHES).PHP_EOL;
        @file_put_contents(self::path(), $line, FILE_APPEND | LOCK_EX);
    }

    /** Reads a log without the guard line, newest entries last. */
    public static function tail(string $name = 'app-log.php', int $lines = 200): array {
        $file = self::path($name);
        $raw = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $raw = array_values(array_filter($raw, static fn(string $l) => !str_starts_with($l, '<?php')));
        return array_slice($raw, -$lines);
    }

    /** Never write credentials, tokens or cookies to disk. */
    private static function redact(array $ctx): array {
        $secretish = '/(token|secret|password|passwd|authorization|cookie|code|sig|signature|assertion|api_key)/i';
        $out = [];
        foreach ($ctx as $k => $v) {
            if (preg_match($secretish, (string)$k)) { $out[$k] = '[redacted]'; continue; }
            if (is_array($v)) { $out[$k] = self::redact($v); continue; }
            $out[$k] = $v;
        }
        return $out;
    }
}
