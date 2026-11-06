<?php

/**
 * Small shared helpers for the Trongate CLI (CPU detection, colour support).
 * Replaces the three duplicated CPU/worker implementations.
 */
final class System
{
    /** Logical CPU cores available to PHP (never less than 1). */
    public static function cpuCores(): int
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $cores = (int) trim((string) @shell_exec('nproc 2>/dev/null'));
            if ($cores > 0) {
                return $cores;
            }

            if (is_readable('/proc/cpuinfo')) {
                $info = file_get_contents('/proc/cpuinfo');
                if ($info !== false) {
                    // Anchor to line starts: ARM "Processor : ..." lines must not count.
                    $cores = (int) preg_match_all('/^processor\s*:/m', $info);
                    if ($cores > 0) {
                        return $cores;
                    }
                }
            }
        }

        if (PHP_OS_FAMILY === 'Darwin' || PHP_OS_FAMILY === 'BSD') {
            $cores = (int) trim((string) @shell_exec('sysctl -n hw.ncpu 2>/dev/null'));
            if ($cores > 0) {
                return $cores;
            }
        }

        return 2;
    }

    /** Worker count for PHP's built-in server (1 where unsupported). */
    public static function serverWorkers(): int
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return 1;
        }

        return min(8, max(2, self::cpuCores()));
    }

    /** Whether ANSI colour should be used on STDOUT. */
    public static function supportsColor(): bool
    {
        $noColor = getenv('NO_COLOR');
        if ($noColor !== false && $noColor !== '') {
            return false;
        }

        if (getenv('TERM') === 'dumb' || !defined('STDOUT')) {
            return false;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            return function_exists('sapi_windows_vt100_support')
                && @sapi_windows_vt100_support(STDOUT);
        }

        return function_exists('stream_isatty') && @stream_isatty(STDOUT);
    }
}
