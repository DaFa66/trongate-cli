<?php

/**
 * Trongate CLI Application
 *
 * Handles command routing, logo rendering, help output,
 * and generator command execution.
 */

require_once __DIR__ . '/../Support/System.php';
require_once __DIR__ . '/Logo.php';
require_once __DIR__ . '/Serve.php';
require_once __DIR__ . '/Info.php';
require_once __DIR__ . '/Create.php';
require_once __DIR__ . '/Learn.php';

final class Cli
{
    /**
     * Start the CLI application.
     *
     * @param array<int, string> $argv Raw command-line arguments.
     * @return int Process exit code.
     */
    public static function run(array $argv): int
    {
        $command   = $argv[1] ?? 'help';
        $arguments = array_slice($argv, 2);

        switch ($command) {
            case 'serve':
                (new Serve())->handle($arguments); // exits with the server's code
                return 0;

            case 'info':
                Logo::render();
                Info::run();
                return 0;
                
            case 'learn':
                $sub = $_SERVER['argv'][2] ?? '';
                Learn::run($sub);
                return 0;

            case 'logo':
                Logo::render();
                return 0;

            case 'version':
            case '--version':
            case '-V':
                echo 'Trongate CLI ' . Info::CLI_VERSION . PHP_EOL;
                return 0;

            case 'help':
            case '--help':
            case '-h':
                Logo::render();
                self::help();
                return 0;
        }

        // "php trongate create module blog" / "php trongate make module blog"
        if (in_array($command, ['create', 'make'], true) && $arguments !== []) {
            $generator = array_shift($arguments);

            return (new Create())->handle(
                array_merge([$generator], $arguments)
            );
        }

        // "php trongate create:module blog" / "php trongate make:module blog"
        if (strpos($command, 'create:') === 0 || strpos($command, 'make:') === 0) {
            $generator = substr($command, strpos($command, ':') + 1);

            return (new Create())->handle(
                array_merge([$generator], $arguments)
            );
        }

        throw new InvalidArgumentException(
            "Unknown command: {$command}. Run 'php trongate help' to see available commands."
        );
    }

    /**
     * Display available commands.
     */
    private static function help(): void
    {
        echo <<<TXT

Trongate PHP Development CLI

Usage:
  php trongate <command> [arguments] [options]

Development:
  serve [host] [port]         Start the development server
  info                        Display project information
  version                     Show the CLI version

Generators (create <generator> ... or create:<generator> ...; make is an alias):
  create:module <module>              Module controller + views/index
  create:controller <module> <child>  Child module (modules/<module>/<child>/)
  create:model <module> [<child>]     Model for a module or child module
  create:view <module> <view>         View in a module (nested paths allowed)
  create:crud <module>                CRUD scaffolding (admin-only by default)

Options:
  --dry-run                   Preview changes without writing files
  --force                     Replace existing generated files
  --public                    crud: also generate public index/show routes
  --offline                   crud: allow generating without a database
  --yes, -y                   Non-interactive (no prompts)

General:
  help, --help, -h            Show this help message

Examples:
  php trongate serve 127.0.0.1 8000
  php trongate create:module blog
  php trongate create:controller blog post     # -> modules/blog/post/Post.php
  php trongate create:model blog post
  php trongate create:view blog posts/index
  php trongate create:crud blog
  php trongate create:crud blog --public
  php trongate create:module blog --dry-run

TXT;
    }
}
