# Trongate CLI

A lightweight command-line toolkit for [Trongate](https://trongateframework.com/) PHP projects. Scaffold modules, generate CRUD from your database schema, explore a guided learning path, and run a tuned development server — all from your terminal.

## Commands

```bash
php trongate                    # Show available commands
php trongate info               # Inspect the current project
php trongate learn              # Interactive 20-chapter learning guide
php trongate create:crud blog   # Generate controller + model + views from the `blog` table
php trongate serve              # Start the development server
```

---

## `php trongate`

Print the list of available commands and their options.
<img width="713" height="889" alt="trongate" src="https://github.com/user-attachments/assets/17b2895c-d769-4b0f-8276-8517982b194c" />

---

## `php trongate info`

Display project metadata: framework version, PHP version, configured database, and detected modules.

<img width="1137" height="1045" alt="trongate_info" src="https://github.com/user-attachments/assets/4599827d-973e-4bef-95ca-8f4583467946" />

---

## `php trongate learn`

An interactive, chapter-by-chapter guide to the framework. Navigate with `[P]` `[N]` `[G]` `[L]` `[R]` `[Q]`.

<img width="819" height="579" alt="trongate_learn" src="https://github.com/user-attachments/assets/5b869838-c4cf-4a8c-936f-43280c72e266" />

---

## `php trongate create:crud`

Reads the table's real columns and generates a complete admin CRUD module — controller, model, validation, forms, list view, and delete confirmation.

```bash
php trongate create:crud members
php trongate create:crud members --public --force
php trongate create:crud members --dry-run
```

<img width="606" height="598" alt="trongate_create_crud" src="https://github.com/user-attachments/assets/fc056d8a-644a-43ef-bfef-14a0051a6687" />

---

## Requirements
```
PHP 8.0 or newer (CLI)
A Trongate v2 project (config/, engine/, modules/, public/)
ENV set to 'dev' in config/config.php. The CLI refuses to run in any other environment.
For create:crud: MySQL or MariaDB, and the pdo_mysql extension
```

## Installation

Copy the files into your project root, keeping this layout:
```
trongate                 entry script (run it with: php trongate ...)
cli/
  commands/              Cli.php, Create.php, Serve.php, Info.php, Logo.php
  Support/               Console.php, FileGenerator.php, System.php
```
On Linux and macOS you can also chmod +x trongate and run ./trongate.



## Trongate Project Documentation

- [Trongate PHP Framework](https://trongateframework.com/)
- [The Complete Trongate Reference Guide](https://trongateframework.com/documentation)
- [The Trongate Way](https://trongateframework.com/documentation)

## License

MIT
