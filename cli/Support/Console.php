<?php

require_once __DIR__ . '/System.php';

final class Console {
    private static ?bool $color = null;
    public static function setColor(bool $enabled): void {
        self::$color = $enabled;
    }
    public static function line(string $s = ''): void {
        echo $s . PHP_EOL;
    }
    public static function title(string $s): void {
        self::line(self::paint($s, '1;35'));
        self::line(self::paint(str_repeat('=', min(strlen($s), 72)), '97'));
    }
    public static function info(string $s): void {
        self::line(self::paint('[INFO] ', '36') . $s);
    }
    public static function success(string $s): void {
        self::line(self::paint('[OK] ', '32') . $s);
    }
    public static function warning(string $s): void {
        self::line(self::paint('[WARN] ', '33') . $s);
    }
    public static function error(string $s): void {
        fwrite(STDERR, self::paint('[ERROR] ', '31') . $s . PHP_EOL);
    }
    private static function paint(string $s, string $c): string {
        self::$color ??= System::supportsColor();
        return self::$color ? "\033[{$c}m{$s}\033[0m" : $s;
    }
}


