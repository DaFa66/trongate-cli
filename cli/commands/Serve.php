<?php

/**
 * Trongate CLI — Development Server Command
 *
 * Usage:
 *   php trongate serve
 *   php trongate serve 127.0.0.1 8000
 *   php trongate serve 0.0.0.0 8080
 *   php trongate serve ::1 8000
 */

require_once __DIR__ . '/../Support/System.php';

class Serve
{
    private string $projectRoot;
    private string $publicDir;

    private string $host = '127.0.0.1';
    private int $port = 8000;
    private int $workers = 1;

    public function __construct()
    {
        $this->projectRoot = dirname(__DIR__, 2);
        $this->publicDir = $this->projectRoot
            . DIRECTORY_SEPARATOR . 'public';
    }

    /**
     * Execute the development server command.
     *
     * @param array<int, string> $args CLI arguments after "serve".
     */
    public function handle(array $args = []): void
    {
        $this->validateProject();
        $this->parseArguments($args);
        $this->configureWorkers();
        $this->displayInformation();

        $exitCode = $this->startServer();

        // Normal shutdown paths:
        //   -1  : proc_close returned -1 (unsupported platform)
        //   130 : SIGINT  (Ctrl+C on Linux/macOS)
        //   143 : SIGTERM (kill on Linux/macOS)
        //   255 : generic termination reported by some Windows shells
        if (in_array($exitCode, [-1, 130, 143, 255], true)) {
            $exitCode = 0;
        }

        if ($exitCode !== 0) {
            fwrite(
                STDERR,
                "Development server exited with code {$exitCode}.\n"
            );
        }

        exit($exitCode);
    }

    /**
     * Ensure the command runs inside a Trongate project.
     */
    private function validateProject(): void
    {
        if (!is_dir($this->publicDir)) {
            $this->error(
                "public directory not found.\n"
                . "Run this command from a Trongate project."
            );
        }
    }

    /**
     * Parse and validate host and port arguments.
     *
     * @param array<int, string> $args
     */
    private function parseArguments(array $args): void
    {
        $host = $args[0] ?? '127.0.0.1';
        $port = $args[1] ?? '8000';

        // Normalise "localhost" to an IPv4 loopback address so the
        // built-in server binds predictably.
        if (is_string($host) && strtolower(trim($host)) === 'localhost') {
            $host = '127.0.0.1';
        }

        if (!is_string($host) || trim($host) === '') {
            $this->error('host cannot be empty.');
        }

        $host = trim($host);

        // Accept IPv4/IPv6 literals or plain hostnames; reject anything else.
        $validHost = filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match(
                '/^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/',
                $host
            ) === 1;

        if (!$validHost) {
            $this->error("invalid host: {$host}");
        }

        // Trim before ctype_digit so " 8000" is not rejected.
        $port = is_string($port) ? trim($port) : '';

        if (
            $port === ''
            || !ctype_digit($port)
            || (int) $port < 1
            || (int) $port > 65535
        ) {
            $this->error('port must be between 1 and 65535.');
        }

        $this->host = $host;
        $this->port = (int) $port;
    }

    /**
     * Configure PHP CLI server workers (PHP 8+, not supported on Windows).
     */
    private function configureWorkers(): void
    {
        // PHP_CLI_SERVER_WORKERS is ignored by the built-in server on Windows.
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->workers = 1;
            return;
        }

        $workers = System::serverWorkers();
        $this->workers = max(1, (int) $workers);

        if ($this->workers > 1) {
            // Note: putenv() is inherited by proc_open() children on
            // mainstream PHP builds. See startServer() for the explicit
            // env pass-through as a belt-and-braces measure.
            putenv("PHP_CLI_SERVER_WORKERS={$this->workers}");
        }
    }

    /**
     * Return development-only PHP configuration.
     *
     * @return array<string, string>
     */
    private function getPhpIni(): array
    {
        return [
            'opcache.enable'                => '1',
            'opcache.enable_cli'            => '1',
            'opcache.validate_timestamps'   => '1',
            'opcache.revalidate_freq'       => '1',
            'opcache.memory_consumption'    => '128',
            'opcache.max_accelerated_files' => '10000',
            'realpath_cache_size'           => '4096K',
            'realpath_cache_ttl'            => '600',
        ];
    }

    /**
     * Build the "host:port" bind string, bracketing IPv6 addresses
     * so they are not confused with the port separator.
     */
    private function getBindAddress(): string
    {
        $isIpv6 = str_contains($this->host, ':');

        return $isIpv6
            ? "[{$this->host}]:{$this->port}"
            : "{$this->host}:{$this->port}";
    }

    /**
     * Build the PHP server process command.
     *
     * @return array<int, string>
     */
    private function buildCommand(): array
    {
        $command = [PHP_BINARY];

        foreach ($this->getPhpIni() as $key => $value) {
            $command[] = '-d';
            $command[] = "{$key}={$value}";
        }

        $command[] = '-S';
        $command[] = $this->getBindAddress();
        $command[] = '-t';
        $command[] = $this->publicDir;

        return $command;
    }

    /**
     * Whether the chosen host only accepts connections from this machine.
     */
    private function isLoopback(): bool
    {
        return in_array($this->host, ['127.0.0.1', '::1', 'localhost'], true)
            || strpos($this->host, '127.') === 0;
    }

    /**
     * Display server startup information.
     */
    private function displayInformation(): void
    {
        $isIpv6 = str_contains($this->host, ':');
        $urlHost = $isIpv6 ? "[{$this->host}]" : $this->host;

        echo "Trongate Development Server\n";
        echo str_repeat('-', 90) . "\n";
        echo "URL:     http://{$urlHost}:{$this->port}\n";
        echo "Root:    {$this->projectRoot}\n";
        echo "Workers: {$this->workers}\n";
        echo "PHP:     " . PHP_VERSION . "\n\n";

        if (!$this->isLoopback()) {
            echo "WARNING: {$this->host} is reachable from other machines.\n"
                . "         This is a development server running with ENV=dev; do not expose it.\n\n";
        }

        echo "Press Ctrl+C to stop the server.\n\n";
    }

    /**
     * Start PHP's built-in development server.
     *
     * @return int Process exit code.
     */
    private function startServer(): int
    {
        // An empty descriptor array tells proc_open() to let the child
        // inherit the parent's STDIN/STDOUT/STDERR. This is required for
        // PHP_CLI_SERVER_WORKERS to behave correctly under Ctrl+C.
        $descriptors = [];

        // Explicitly pass the environment when using workers so the
        // child reliably sees PHP_CLI_SERVER_WORKERS regardless of
        // putenv() propagation quirks across platforms.
        $env = null;
        if ($this->workers > 1) {
            $env = array_merge(
                $_ENV,
                ['PHP_CLI_SERVER_WORKERS' => (string) $this->workers]
            );
        }

        $process = proc_open(
            $this->buildCommand(),
            $descriptors,
            $pipes,
            $this->projectRoot,
            $env
        );

        if (!is_resource($process)) {
            $this->error('could not start the development server.');
        }

        return proc_close($process);
    }

    /**
     * Display an error and terminate execution.
     */
    private function error(string $message): void
    {
        fwrite(STDERR, "Error: {$message}\n");
        exit(1);
    }
}