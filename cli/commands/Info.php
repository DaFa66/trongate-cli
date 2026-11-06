<?php

/**
 * Trongate CLI — Project Information
 *
 * Usage:
 *   php trongate info
 */

final class Info
{
    public const CLI_VERSION = '0.6.0';
    private const LINE_WIDTH = 100;

    /**
     * Canonical Trongate core directories, with their descriptions.
     *
     * These are the four directories that every Trongate project must
     * have. They are reported separately so their role is clear.
     */
    private const CORE_DIRECTORIES = [
        'public' => 'Web entry point (index.php) and publicly accessible application assets.',
        'engine' => 'Core Trongate framework classes and internal components. Do not modify.',
        'modules' => 'Application modules. Each module contains its controller, views, and optional assets.',
        'config' => 'Application configuration files, including database settings.',
    ];

    /**
     * Well-known optional / project-specific directories, with descriptions.
     *
     * Any of these that exist will get a friendly description; anything
     * else found in the root is listed with a generic label.
     */
    private const KNOWN_OPTIONAL_DIRECTORIES = [
        'uploads'   => 'User-uploaded files (optional convention).',
        'assets'    => 'Shared static resources (optional; per-module assets are preferred).',
        'templates' => 'Reusable layouts, if used by the application (optional).',
        'logs'      => 'Application and diagnostic logs, if configured (optional).',
        'cli'       => 'Custom Trongate CLI implementation: commands, support utilities, and related files.',
        'vendor'    => 'Third-party dependencies, if Composer is used (optional).',
        'storage'   => 'Application-managed storage for cache or generated files (optional).',
        'tests'     => 'Automated tests, if present (optional).',
        'docs'      => 'Project documentation (optional).',
    ];

    /**
     * Display project information.
     */
    public static function run(): void
    {
        $projectRoot = dirname(__DIR__, 2);

        $scan = self::scanRoot($projectRoot);

        $extensions = [
            'PDO'        => extension_loaded('pdo'),
            'PDO MySQL'  => extension_loaded('pdo_mysql'),
            'PDO SQLite' => extension_loaded('pdo_sqlite'),
            'OpenSSL'    => extension_loaded('openssl'),
            'Mbstring'   => extension_loaded('mbstring'),
            'cURL'       => extension_loaded('curl'),
            'OPcache'    => extension_loaded('Zend OPcache'),
        ];

        $line = str_repeat('─', self::LINE_WIDTH);
        $separator = str_repeat('-', self::LINE_WIDTH);

        echo PHP_EOL;
        echo "  Trongate CLI — Project Information" . PHP_EOL;
        echo "  {$line}" . PHP_EOL;

        // CLI information.
        echo PHP_EOL . "  CLI" . PHP_EOL;
        echo "  {$separator}" . PHP_EOL;

        self::displayInfoRow('Version', self::CLI_VERSION);
        self::displayInfoRow('PHP version', PHP_VERSION);
        self::displayInfoRow('PHP binary', PHP_BINARY);
        self::displayInfoRow('Operating system', PHP_OS_FAMILY);
        self::displayInfoRow('Architecture', PHP_INT_SIZE * 8 . '-bit');

        // Project root.
        echo PHP_EOL . "  Project Root" . PHP_EOL;
        echo "  {$separator}" . PHP_EOL;

        self::displayInfoRow('Path', $projectRoot);
        self::displayInfoRow('Directories', (string) count($scan['all']));

        // Core Trongate directories.
        echo PHP_EOL . "  Trongate Core Structure" . PHP_EOL;
        echo "  {$separator}" . PHP_EOL;

        foreach (self::CORE_DIRECTORIES as $name => $description) {
            $exists = in_array($name, $scan['all'], true);
            self::renderDirectory($name, $projectRoot . '/' . $name, $description, $exists);
        }

        // Missing core directories warning.
        if (!empty($scan['missing_core'])) {
            echo PHP_EOL . "  [WARN] Missing core directories: "
                . implode(', ', $scan['missing_core']) . PHP_EOL;
        }

        // Optional / project-specific directories.
        echo PHP_EOL . "  Optional / Project-Specific Directories" . PHP_EOL;
        echo "  {$separator}" . PHP_EOL;

        if (empty($scan['optional'])) {
            echo PHP_EOL . "  (none found)" . PHP_EOL;
        } else {
            foreach ($scan['optional'] as $name) {
                $description = self::KNOWN_OPTIONAL_DIRECTORIES[$name]
                    ?? 'Project-specific directory (no description available).';
                self::renderDirectory($name, $projectRoot . '/' . $name, $description, true);
            }
        }

        // PHP extensions.
        echo PHP_EOL . "  PHP Extensions" . PHP_EOL;
        echo "  {$separator}" . PHP_EOL;

        foreach ($extensions as $name => $loaded) {
            self::displayInfoRow(
                $name,
                $loaded ? 'Available' : 'Not installed',
                $loaded
            );
        }

        // PHP configuration.
        echo PHP_EOL . "  Configuration" . PHP_EOL;
        echo "  {$separator}" . PHP_EOL;

        self::displayInfoRow('PHP SAPI', PHP_SAPI);

        self::displayInfoRow(
            'Loaded php.ini',
            php_ini_loaded_file() ?: 'None'
        );

        self::displayInfoRow(
            'Memory limit',
            (string) ini_get('memory_limit')
        );

        self::displayInfoRow(
            'Maximum execution time',
            (string) ini_get('max_execution_time') . ' seconds'
        );

        self::displayInfoRow(
            'OPcache enabled',
            filter_var(
                ini_get('opcache.enable'),
                FILTER_VALIDATE_BOOLEAN
            ) ? 'Yes' : 'No'
        );

        echo PHP_EOL . "  {$line}" . PHP_EOL;
        echo "  Run \"php trongate help\" to see available commands." . PHP_EOL;
        echo PHP_EOL;
    }

    /**
     * Scan the project root for directories.
     *
     * @return array{
     *     all: string[],
     *     core: string[],
     *     optional: string[],
     *     missing_core: string[]
     * }
     */
    private static function scanRoot(string $projectRoot): array
    {
        $entries = @scandir($projectRoot);

        if ($entries === false) {
            return [
                'all' => [],
                'core' => [],
                'optional' => [],
                'missing_core' => array_keys(self::CORE_DIRECTORIES),
            ];
        }

        $directories = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
                continue; // skip hidden directories such as .git and .idea
            }

            $fullPath = $projectRoot . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($fullPath)) {
                $directories[] = $entry;
            }
        }

        sort($directories, SORT_NATURAL | SORT_FLAG_CASE);

        $coreNames = array_keys(self::CORE_DIRECTORIES);

        $core = array_values(array_intersect($coreNames, $directories));
        $optional = array_values(array_diff($directories, $coreNames));
        $missingCore = array_values(array_diff($coreNames, $directories));

        return [
            'all' => $directories,
            'core' => $core,
            'optional' => $optional,
            'missing_core' => $missingCore,
        ];
    }

    /**
     * Render a single directory entry (header, path, purpose).
     */
    private static function renderDirectory(
        string $label,
        string $path,
        string $description,
        bool $exists
    ): void {
        echo PHP_EOL;
        self::displayInfoRow(
            ucfirst($label),
            $exists ? 'Directory' : 'Not found',
            $exists
        );

        self::displayInfoRow('Path', $path);
        self::displayInfoRow('Purpose', $description);
    }

    /**
     * Print a consistently formatted information row.
     */
    private static function displayInfoRow(
        string $label,
        string $value,
        ?bool $available = null
    ): void {
        $status = '';

        if ($available !== null) {
            $status = $available ? '[OK]' : '[MISSING]';
        }

        printf(
            "  %-24s %-12s %s\n",
            $label,
            $status,
            $value
        );
    } 
}