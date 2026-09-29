<?php
class Validator {
    public static function email(string $e): bool { return filter_var($e, FILTER_VALIDATE_EMAIL) !== false; }
    public static function required(?string $v): bool { return isset($v) && trim($v) !== ''; }
    public static function sanitize(string $v): string { return trim(htmlspecialchars($v, ENT_QUOTES, 'UTF-8')); }
    public static function esc(string $v): string { return htmlspecialchars($v, ENT_QUOTES|ENT_HTML5, 'UTF-8'); }
}
