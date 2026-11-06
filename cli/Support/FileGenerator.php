
<?php


final class FileGenerator
{
    private string $root;
    private bool $dryRun;
    private bool $force;

    private int $planned = 0;
    private int $created = 0;
    private int $skipped = 0;
    private int $errors = 0;

    public function __construct(
        string $root,
        bool $dryRun = false,
        bool $force = false
    ) {
        $resolvedRoot = realpath($root);

        if ($resolvedRoot === false || !is_dir($resolvedRoot)) {
            throw new RuntimeException(
                "Project root does not exist: {$root}"
            );
        }

        $this->root = rtrim($resolvedRoot, DIRECTORY_SEPARATOR);
        $this->dryRun = $dryRun;
        $this->force = $force;
    }

    /**
     * Prepare the standard Trongate module directories.
     */
    public function ensureModule(string $module): void
    {
        $module = self::moduleName($module);

        foreach (['views'] as $directory) {
            $this->directory("modules/{$module}/{$directory}");
        }
    }

    /**
     * Prepare a v2 child module: modules/<module>/<child>/views
     * (the parent module directory is created on the way).
     */
    public function ensureChildModule(string $module, string $child): void
    {
        $module = self::moduleName($module);
        $child  = self::moduleName($child);

        $this->directory("modules/{$module}/{$child}/views");
    }

    /**
     * Generate a file inside the project root.
     */
    public function generate(string $relativePath, string $content): void
    {
        $relativePath = str_replace('\\', '/', $relativePath);

        if (
            $relativePath === ''
            || $relativePath[0] === '/'
            || preg_match('~(^|/)\.{1,2}(/|$)~', $relativePath)
        ) {
            throw new RuntimeException(
                "Unsafe output path: {$relativePath}"
            );
        }

        $target = $this->root
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        $parent = dirname($target);

        $this->directoryAbsolute($parent);

        // A symlink must never be used as the output file.
        if (is_link($target)) {
            throw new RuntimeException(
                "Refusing to write through symlink: {$relativePath}"
            );
        }

        if (file_exists($target) && !$this->force) {
            Console::warning("Skipped existing file: {$relativePath}");
            $this->skipped++;
            return;
        }

        if ($this->dryRun) {
            Console::info("[DRY RUN] Would write: {$relativePath}");
            $this->planned++;
            return;
        }

        $temporaryFile = tempnam($parent, '.trongate-');

        if ($temporaryFile === false) {
            $this->errors++;

            throw new RuntimeException(
                "Cannot create temporary file in {$parent}"
            );
        }

        try {
            if (file_put_contents(
                $temporaryFile,
                $content,
                LOCK_EX
            ) === false) {
                throw new RuntimeException(
                    "Cannot write generated file: {$relativePath}"
                );
            }

            // Recheck immediately before installing the file.
            if (is_link($target)) {
                throw new RuntimeException(
                    "Refusing to replace symlink: {$relativePath}"
                );
            }

            if (file_exists($target) && !$this->force) {
                Console::warning(
                    "Skipped existing file: {$relativePath}"
                );

                $this->skipped++;
                return;
            }

            // tempnam() creates files as 0600. Use the normal umask-based mode (or the
            // mode of the file being replaced) so the web server user can read them.
            $mode = is_file($target)
                ? (fileperms($target) & 0777)
                : (0666 & ~umask());
            @chmod($temporaryFile, $mode);

            if (!@rename($temporaryFile, $target)) {
                throw new RuntimeException(
                    "Cannot install generated file: {$relativePath}"
                );
            }

            $this->created++;

            Console::success("Created: {$relativePath}");
        } catch (\Throwable $exception) {
            $this->errors++;
            throw $exception;
        } finally {
            if (is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
    }

    /**
     * Resolve a project-relative directory.
     */
    private function directory(string $relativePath): void
    {
        $this->directoryAbsolute(
            $this->root
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath)
        );
    }

    /**
     * Create directories one component at a time.
     *
     * Existing symlink components are rejected to help prevent
     * generated files from escaping the project root.
     */
    private function directoryAbsolute(string $path): void
    {
        $prefix = $this->root . DIRECTORY_SEPARATOR;

        if ($path === $this->root) {
            return;
        }

        if (strpos($path, $prefix) !== 0) {
            throw new RuntimeException(
                "Directory resolves outside project root: {$path}"
            );
        }

        $relative = substr($path, strlen($prefix));
        $parts = explode(DIRECTORY_SEPARATOR, $relative);
        $current = $this->root;

        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RuntimeException(
                    "Unsafe directory component: {$part}"
                );
            }

            $current .= DIRECTORY_SEPARATOR . $part;

            if (is_link($current)) {
                throw new RuntimeException(
                    "Refusing symlink directory: {$current}"
                );
            }

            if (is_dir($current)) {
                continue;
            }

            if (file_exists($current)) {
                throw new RuntimeException(
                    "Path exists but is not a directory: {$current}"
                );
            }

            if ($this->dryRun) {
                Console::info(
                    '[DRY RUN] Would create directory: ' . $current
                );

                // Do not pretend missing parent directories exist.
                return;
            }

            if (!@mkdir($current, 0755)) {
                // Another process may have created the directory.
                if (is_link($current) || !is_dir($current)) {
                    $this->errors++;

                    throw new RuntimeException(
                        "Cannot create directory: {$current}"
                    );
                }
            }

            Console::info('Directory ready: ' . $current);
        }
    }

    /** Record a failure that happened outside generate() (e.g. a refused directory). */
    public function recordError(): void
    {
        $this->errors++;
    }

    /**
     * Display the generation summary and return an exit code.
     */
    public function summary(): int
    {
        Console::line('');

        $created = $this->dryRun
            ? "Would create: {$this->planned}"
            : "Created: {$this->created}";

        Console::line(
            $created
            . " | Skipped: {$this->skipped}"
            . " | Errors: {$this->errors}"
        );

        return $this->errors > 0 ? 1 : 0;
    }

    /**
     * Validate and normalize a module directory name.
     */
    public static function moduleName(string $name): string
    {
        $name = strtolower(trim($name));

        if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            throw new RuntimeException(
                "Invalid module name: {$name}"
            );
        }

        // Core Trongate modules: generating into these (especially with --force)
        // would overwrite framework code.
        $core = [
            'db', 'file', 'flashdata', 'form', 'image', 'image_uploader', 'language',
            'login', 'module_relations', 'pagination', 'setup', 'string_service',
            'templates', 'trongate_administrators', 'trongate_control',
            'trongate_email', 'trongate_security', 'trongate_tokens', 'url',
            'utilities', 'validation', 'welcome',
        ];

        if (in_array($name, $core, true)) {
            throw new RuntimeException(
                "'{$name}' is a core Trongate module name. Choose another."
            );
        }

        return $name;
    }

    /**
     * Validate a generated class name.
     */
    public static function className(string $name): string
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name)) {
            throw new RuntimeException(
                "Invalid class name: {$name}"
            );
        }

        $reserved = [
            'model', 'trongate', 'modules', 'db', 'validation', 'templates',
            'form', 'url', 'file', 'image', 'exception', 'stdclass', 'closure',
            'self', 'parent', 'static', 'class', 'list', 'array', 'object',
            'string', 'int', 'float', 'bool', 'mixed', 'void', 'null', 'true', 'false',
        ];

        if (in_array(strtolower($name), $reserved, true)) {
            throw new RuntimeException(
                "Reserved or conflicting class name: {$name}"
            );
        }

        return $name;
    }

    /**
     * Validate a view path, including nested view directories.
     */
    public static function viewName(string $name): string
    {
        $name = str_replace('\\', '/', trim($name));

        if (
            $name === ''
            || $name[0] === '/'
            || !preg_match(
                '~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~',
                $name
            )
        ) {
            throw new RuntimeException(
                "Invalid view path: {$name}"
            );
        }

        return $name;
    }
}
