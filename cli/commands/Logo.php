<?php

/**
 * Trongate CLI Logo
 *
 * Renders the Trongate wordmark neofetch-style: logo on the left,
 * system information on the right.
 */
final class Logo
{
    private const PURPLE = "\033[38;5;99m";
    private const WHITE  = "\033[97m";
    private const LABEL  = "\033[38;5;99m";   // key color (matches logo)
    private const VALUE  = "\033[97m";        // value color
    private const RESET  = "\033[0m";

    /**
     * Display the CLI logo with system info beside it.
     */
    public static function render(?bool $colors = null): void
    {
        $colors ??= self::supportsColors();
        
        $purple = $colors ? self::PURPLE : '';
        $white  = $colors ? self::WHITE  : '';
        $label  = $colors ? self::LABEL  : '';
        $value  = $colors ? self::VALUE  : '';
        $reset  = $colors ? self::RESET  : '';

        // Logo lines (left column) — "trongate" in ASCII letters
        $logo = [
            '  _                         _      ',
            ' | |_ _ __ ___  _ __   __ _| |_ ___ ',
            " | __| '__/ _ \\| '_ \\ / _` | __/ _ \\",
            ' | |_| | | (_) | | | | (_| | ||  __/',
            '  \\__|_|  \\___/|_| |_|\\__, |\\__\\___|',
            '                      |___/         ',
            '',
            '   TRONGATE PHP DEVELOPMENT CLI',
        ];

        // System info (right column)
        $info = [
            ['user',    self::getUser() . '@' . self::getHost()],
            ['',        str_repeat('-', 55)],
            ['OS',      PHP_OS_FAMILY . ' ' . php_uname('r')],
            ['PHP',     PHP_VERSION],
            ['Shell',   self::getShell()],
            ['Terminal',self::getTerminal()],
            ['CPU',     self::getCpu()],
            ['Memory',  self::getMemory()],
            ['Uptime',  self::getUptime()],
            ['Workers', (string) System::serverWorkers()]
        ];

        // Strip ANSI for width calc
        $strip = fn(string $s): string => preg_replace('/\033\[[0-9;]*m/', '', $s);

        // Pad logo column to a fixed width
        $logoWidth = 0;
        foreach ($logo as $line) {
            $logoWidth = max($logoWidth, strlen($strip($line))); 
        }
        $logoWidth += 3; // gap between columns

        // Render side-by-side
        $max = max(count($logo), count($info));
        for ($i = 0; $i < $max; $i++) {
            // Left: logo
            $left = $logo[$i] ?? '';
            $pad  = str_repeat(' ', max(0, $logoWidth - strlen($strip($left))));
            echo $purple . $left . $reset . $pad;

            // Right: info
            if (isset($info[$i])) {
                [$key, $val] = $info[$i];
                if ($key === '') {
                    echo $label . $val . $reset;
                } elseif ($val === '') {
                    echo $value . $key . $reset;
                } else {
                    echo $label . str_pad($key . ':', 9) . $reset
                       . ' ' . $value . $val . $reset;
                }
            }
            echo PHP_EOL;
        }
    }

    // ---- System info helpers -------------------------------------------

    private static function getUser(): string
    {
        return getenv('USER') ?: (getenv('USERNAME') ?: 'user');
    }

    private static function getHost(): string
    {
        return gethostname() ?: 'localhost';
    }

    private static function getShell(): string
    {
        return basename(getenv('SHELL') ?: (PHP_OS_FAMILY === 'Windows' ? 'cmd' : 'sh'));
    }

    private static function getTerminal(): string
    {
        return getenv('TERM_PROGRAM') ?: (getenv('TERM') ?: 'unknown');
    }

    private static function getCpu(): string
    {
        if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/cpuinfo')) {
            foreach (file('/proc/cpuinfo') as $line) {
                if (stripos($line, 'model name') === 0) {
                    return trim(explode(':', $line, 2)[1] ?? 'CPU');
                }
            }
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            $out = @shell_exec('sysctl -n machdep.cpu.brand_string 2>/dev/null');
            if ($out) {
                return trim($out);
            }
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $out = @shell_exec('wmic cpu get name 2>NUL');
            if ($out) {
                $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $out))));
                if (isset($lines[1])) {
                    return $lines[1];
                }
            }
        }
        return php_uname('m');
    }

    private static function getMemory(): string
    {
        if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/meminfo')) {
            $mem = [];
            foreach (file('/proc/meminfo') as $line) {
                if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
                    $mem[$m[1]] = (int) $m[2];
                }
            }
            if (isset($mem['MemTotal'], $mem['MemAvailable'])) {
                $used = ($mem['MemTotal'] - $mem['MemAvailable']) / 1024 / 1024;
                $tot  = $mem['MemTotal'] / 1024 / 1024;
                return sprintf('%.1f GiB / %.1f GiB', $used, $tot);
            }
        }
        return ini_get('memory_limit') . ' (limit)';
    }

    private static function getUptime(): string
    {
        if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/uptime')) {
            $secs = (int) explode(' ', file_get_contents('/proc/uptime'))[0];
            $d = intdiv($secs, 86400);
            $h = intdiv($secs % 86400, 3600);
            $m = intdiv($secs % 3600, 60);
            return ($d ? "{$d}d " : '') . "{$h}h {$m}m";
        }
        return 'n/a';
    }

    /**
     * Determine whether ANSI colors should be enabled.
     */
    private static function supportsColors(): bool
    {
        return System::supportsColor();
    }
}
