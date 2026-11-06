<?php

require_once __DIR__ . '/../Support/Console.php';
require_once __DIR__ . '/../Support/FileGenerator.php';

/**
 * Code generator.
 *
 * Usage: php trongate create:<module|controller|model|view|crud> ... [--dry-run] [--force] [--yes]
 *        php trongate create <generator> ...   (same thing, space-separated)
 *
 *   create:module     <module>             Blog.php + views/index.php
 *   create:controller <module> <child>     child module: modules/<module>/<child>/<Child>.php
 *   create:model      <module> [<child>]   <Module>_model.php (or the child module's model)
 *   create:view       <module> <view>
 *   create:crud       <module>   (controller + model + views, driven by the table's columns)
 *
 * CRUD layout (admin-only by default, matching Trongate's own generator):
 *   ADMIN   (protected)  : index (redirects), manage, show, create, submit, delete_conf, submit_delete
 *   PUBLIC  (--public)   : index, show become read-only public routes
 *
 * Flags:
 *   --force             Overwrite generated files.
 *   --dry-run           Print what would happen; never touches the DB or disk.
 *   --yes | -y          Non-interactive; use the bare default table when missing.
 *   --no-interaction    Alias for --yes.
 *   --public            crud: generate public index/show instead of admin-only.
 *   --offline           crud: allow generating from default columns without a database.
 */
final class Create
{
    /**
     * Column names that would overwrite variables the generated views rely on
     * (templates->public()/admin() extracts $data into view variables).
     */
    private const RESERVED_VIEW_VARS = [
        'headline', 'rows', 'row', 'record', 'total',
        'cancel_url', 'form_location', 'view_module', 'view_file', 'update_id',
        'page', 'total_pages',
    ];

    private string $projectRoot = '';
    private bool $dryRun = false;
    private bool $interactive = true;
    private bool $public = false;
    private bool $offline = false;


    /**
     * Handle the create command and execute the requested file generator.
     *
     * Parses the generator command, processes CLI options, initializes the
     * file generator, and executes the requested generation task.
     *
     * Supported options:
     *   --dry-run          Preview changes without creating files.
     *   --force            Overwrite existing files.
     *   --public           Generate publicly accessible resources.
     *   --offline          Disable operations requiring an internet connection.
     *   --yes, -y          Skip interactive confirmation prompts.
     *   --no-interaction   Disable interactive mode.
     *
     * @param array<int, string> $arguments CLI arguments following the create command.
     *
     * @return int Exit code: 0 on success, non-zero on failure.
     *
     * @throws InvalidArgumentException If no generator command is supplied
     *                                  or an unsupported option is provided.
     */
    public function handle(array $arguments): int
    {
        if (empty($arguments)) {
            throw new InvalidArgumentException(
                "Missing generator command.\n"
                . "Usage: php trongate create: <module|controller|model|view|crud> ..."
            );
        }

        $command = array_shift($arguments);

        $options = [
            'dry-run'     => false,
            'force'       => false,
            'interactive' => true,
            'public'      => false,
            'offline'     => false,
        ];

        $positionalArguments = [];

        foreach ($arguments as $argument) {
            if ($argument === '--dry-run') {
                $options['dry-run'] = true;
            } elseif ($argument === '--force') {
                $options['force'] = true;
            } elseif ($argument === '--public') {
                $options['public'] = true;
            } elseif ($argument === '--offline') {
                $options['offline'] = true;
            } elseif ($argument === '--yes' || $argument === '-y' || $argument === '--no-interaction') {
                $options['interactive'] = false;
            } elseif (strpos($argument, '--') === 0) {
                throw new InvalidArgumentException("Unknown option: {$argument}");
            } else {
                $positionalArguments[] = $argument;
            }
        }

        $projectRoot = dirname(__DIR__, 2);

        $this->projectRoot = $projectRoot;
        $this->dryRun      = $options['dry-run'];
        $this->interactive = $options['interactive'];
        $this->public      = $options['public'];
        $this->offline     = $options['offline'];

        $generator = new FileGenerator(
            $projectRoot,
            $options['dry-run'],
            $options['force']
        );

        try {
            $this->runGenerator($command, $positionalArguments, $generator);
        } catch (RuntimeException $e) {
            // Always report what was done before the failure, then exit non-zero.
            Console::error($e->getMessage());
            $generator->recordError();
            $generator->summary();

            return 1;
        }

        return $generator->summary();
    }



    /**
     * Dispatch the requested generator command.
     *
     * Determines which resource should be generated and delegates the
     * operation to the appropriate generator method.
     *
     * @param string             $command   Generator command (module, controller,
     *                                      model, view, or crud).
     * @param array<int, string> $arguments Positional arguments supplied to the
     *                                      generator command.
     * @param FileGenerator      $generator File generator responsible for creating
     *                                      and managing generated files.
     *
     * @return void
     *
     * @throws InvalidArgumentException If the generator command is unsupported
     *                                  or required arguments are missing.
     * @throws RuntimeException If the generation process fails.
     */
    private function runGenerator(
        string $command,
        array $arguments,
        FileGenerator $generator
    ): void {
        $requireArguments = static function (int $count) use ($arguments, $command): void {
            if (count($arguments) < $count) {
                throw new InvalidArgumentException(
                    "Insufficient arguments for {$command}.\n"
                    . "Run 'php trongate help' for usage."
                );
            }
        };

        switch ($command) {
            case 'module':
                $requireArguments(1);

                $module = FileGenerator::moduleName($arguments[0]);
                $class  = $this->capitalise(FileGenerator::className($module));

                $generator->ensureModule($module);

                // v2 loads a module's controller from modules/<module>/<Module>.php
                $generator->generate(
                    "modules/{$module}/{$class}.php",
                    $this->fill($this->controllerSource(), [
                        '__CLASS__'       => $class,
                        '__VIEW_MODULE__' => $module,
                    ])
                );

                $generator->generate(
                    "modules/{$module}/views/index.php",
                    $this->fill($this->viewSource(), [
                        '__PAGE_TITLE__' => var_export($class, true),
                    ])
                );

                Console::success("Module ready: {$module}  (URL: {$module})");
                break;

            case 'controller':
            
                // Require module name and child controller name
                $requireArguments(2);
            
                $module = FileGenerator::moduleName($arguments[0]);
                $child  = FileGenerator::moduleName($arguments[1]);
                $class  = $this->capitalise(
                    FileGenerator::className($child)
                );
            
                // The module must already exist (this generator never creates it).
                $moduleDirectory = "modules/{$module}";
                $this->requireDirectory(
                    $moduleDirectory,
                    "Create it first with: php trongate create:module {$module}"
                );
            
                // Controller source template
                $source = <<<'PHP'
            <?php
            
            class __CLASS__ extends Trongate
            {
                public function index(): void
                {
                    $data['headline']    = '__CLASS__';
                    $data['view_module'] = '__MODULE__';
                    $data['view_file']   = 'index'; // Create it with: php trongate create:view __MODULE__ index
            
                    $this->templates->public($data);
                }
            }

            PHP;
            
                // Generate controller file only
                $generator->generate(
                    "{$moduleDirectory}/{$class}.php",
                    $this->fill($source, [
                        '__CLASS__'  => $class,
                        '__MODULE__' => $module,
                    ])
                );            
                break;

            case 'model':
            
                $requireArguments(2);
            
                $module = FileGenerator::moduleName($arguments[0]);
                $class  = $this->modelName(
                    FileGenerator::className($arguments[1])
                );
            
                $moduleDirectory = "modules/{$module}";
            
                // The module must already exist (this generator never creates it).
                $this->requireDirectory(
                    $moduleDirectory,
                    "Create it first with: php trongate create:module {$module}"
                );
            
                $source = <<<'PHP'
            <?php
            
            class __CLASS__ extends Model
            {
            
                // Add model methods here, e.g.:
                // private $table_name = "table_name";
                // public function find_by_id(int $update_id): object|bool
                // {
                //     return $this->db->get_where($update_id, 'table_name');
                // }
            }
            
            PHP;
            
                $generator->generate(
                    "{$moduleDirectory}/{$class}.php",
                    $this->fill($source, [
                        '__CLASS__' => $class
                    ])
                );
            
                break;
 
            case 'view':
            
                // Require module name and view name
                $requireArguments(2);
            
                $module = FileGenerator::moduleName($arguments[0]);
                $view   = FileGenerator::viewName($arguments[1]);
            
                // Expected views directory
                $viewsDirectory = "modules/{$module}/views";
            
                // Do not create missing directories.
                $this->requireDirectory(
                    $viewsDirectory,
                    "Create the module first with: php trongate create:module {$module}"
                );
            
                // Generate view file only
                $generator->generate(
                    "{$viewsDirectory}/{$view}.php",
                    $this->fill($this->viewSource(), [
                        // Safely quoted PHP string literal
                        '__PAGE_TITLE__' => var_export(basename($view), true),
                    ])
                );
            
                break;

            case 'crud':
                $requireArguments(1);

                // Controller, model and table are all named after the module:
                //   module "members" => Members, Members_model, table `members`
                $module     = FileGenerator::moduleName($arguments[0]);
                $class      = $this->capitalise(FileGenerator::className($module));
                $modelClass = $this->modelName($class);
                $table      = $module;
                $viewBase   = "modules/{$module}/views";

                $generator->ensureModule($module);

                // 1. Connect. 2. Create the table (interactively if missing). 3. Read its real columns.
                $pdo = $this->connect();

                if ($pdo === null && !$this->dryRun && !$this->offline) {
                    throw new RuntimeException(
                        "Could not connect to the database, so the table's columns cannot be read.\n"
                        . "Fix config/database.php, or re-run with --offline to generate from default columns."
                    );
                }
                $this->createTable($table, $pdo);
                $columns = $this->loadColumns($table, $pdo);
                $fields  = $this->buildFields($columns);

                // Trongate's Db::get_where()/update()/delete() all address rows by `id`.
                if (!in_array('id', array_column($fields, 'name'), true)) {
                    throw new RuntimeException(
                        "Table `{$table}` has no `id` column. Trongate's Db helpers require one."
                    );
                }

                if ($pdo === null) {
                    $this->warn('No database connection: generating from default columns (id, created_at) only.');
                }

                if ($pdo !== null) {
                    $names = implode(', ', array_column($fields, 'name'));
                    Console::success("Columns detected: {$names}");
                }

                $vars = [
                    '__VALIDATION__'        => $this->validationCode($fields),
                    '__DATA_ARRAY__'        => $this->dataArrayCode($fields),
                    '__DB_ARRAY__'          => $this->dbArrayCode($fields),
                    '__PASSWORD_HANDLING__' => $this->passwordHandlingCode($fields),
                    '__AUTO_INSERT__'       => $this->autoColumnsCode($fields, true),
                    '__AUTO_UPDATE__'       => $this->autoColumnsCode($fields, false),
                    '__FORM_FIELDS__'       => $this->formFieldsHtml($fields),
                    '__INDEX_HEAD__'        => $this->indexHeadHtml($fields),
                    '__INDEX_CELLS__'       => $this->indexCellsHtml($fields),
                    '__SHOW_ROWS__'         => $this->showRowsHtml($fields),
                    '__READ_ROUTES__'       => $this->fill($this->readRoutes(), [
                        '__MODULE__' => $module,
                        '__CLASS__'  => $class,
                    ]),
                    '__BACK_URL__'          => $this->public ? $module : "{$module}/manage",
                    '__MODULE__'            => $module,
                    '__CLASS__'             => $class,
                    '__MODEL__'             => $modelClass,
                    '__TABLE__'             => $table,
                ];

                $generator->generate(
                    "modules/{$module}/{$class}.php",
                    $this->fill($this->crudController(), $vars)
                );

                $generator->generate(
                    "modules/{$module}/{$modelClass}.php",
                    $this->fill($this->crudModel(), $vars)
                );

                $types = ['manage', 'show', 'create', 'delete_conf'];

                if ($this->public) {
                    array_unshift($types, 'index'); // only needed for the public list
                }

                foreach ($types as $type) {
                    $generator->generate(
                        "{$viewBase}/{$type}.php",
                        $this->fill($this->crudView($type), $vars)
                    );
                }
                break;

            default:
                throw new InvalidArgumentException(
                    "Unknown generator: {$command}. "
                    . "Use module, controller, model, view, or crud."
                );
        }

    }

    /**
     * Fail with one consistent message when a prerequisite directory is missing.
     * Resolved against the project root, so it works from any working directory.
     * The RuntimeException is reported by handle() as "[ERROR] ...", counted in the
     * summary, and makes the command exit with status 1.
     */
    private function requireDirectory(string $relativePath, string $hint): void
    {
        $absolute = $this->projectRoot . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        if (!is_dir($absolute)) {
            throw new RuntimeException(
                "Directory does not exist: {$relativePath}\n{$hint}"
            );
        }
    }

    /** Minimal controller used by create:module and create:controller. */
    private function controllerSource(): string
    {
        return <<<'PHP'
<?php

class __CLASS__ extends Trongate
{
    public function index(): void
    {
        $data['headline']    = '__CLASS__';
        $data['view_module'] = '__VIEW_MODULE__';
        $data['view_file']   = 'index';
        $this->templates->public($data);
    }
}

PHP;
    }

    /** Minimal view used by create:view / create:module / create:controller. */
    private function viewSource(): string
    {
        return <<<'PHP'
<?php $pageTitle = $headline ?? __PAGE_TITLE__; ?>
<section>
    <h1><?= htmlspecialchars((string) $pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
    <p>Your view is ready.</p>
</section>

PHP;
    }

    /**
     * Read-only routes: admin-only by default, public with --public.
     * Both variants paginate; the model never returns the whole table.
     */
    private function readRoutes(): string
    {
        if ($this->public) {
            return <<<'PHP'
    // ------------------------------------------------------------------
    // PUBLIC (read only) - generated with --public. Review which columns
    // are shown in views/index.php and views/show.php before deploying.
    // ------------------------------------------------------------------

    /**
     * Public, read-only list.  URL: __MODULE__
     */
    public function index(): void
    {
        $data = $this->list_data();
        $data['headline'] = '__CLASS__';
        $this->render('index', $data);
    }

    /**
     * Public, read-only detail.  URL: __MODULE__/show/{id}
     */
    public function show(): void
    {
        $update_id = segment(3, 'int');
        $record    = $this->model->find_by_id($update_id);

        if ($record === false) {
            redirect('__MODULE__');
            return;
        }

        $data['headline'] = 'View __CLASS__';
        $data['record']   = $record;
        $this->render('show', $data);
    }
PHP;
        }

        return <<<'PHP'
    // ------------------------------------------------------------------
    // ENTRY + DETAIL (admin only; re-generate with --public for public routes)
    // ------------------------------------------------------------------

    /**
     * Entry point: send visitors to the admin list.  URL: __MODULE__
     */
    public function index(): void
    {
        redirect('__MODULE__/manage');
    }

    /**
     * Admin detail view.  URL: __MODULE__/show/{id}
     */
    public function show(): void
    {
        $this->trongate_security->make_sure_allowed();

        $update_id = segment(3, 'int');
        $record    = $this->model->find_by_id($update_id);

        if ($record === false) {
            redirect('__MODULE__/manage');
            return;
        }

        $data['headline'] = 'View __CLASS__';
        $data['record']   = $record;
        $this->render('show', $data, 'admin');
    }
PHP;
    }

    /** Upper-case the first letter only: "blog_posts" => "Blog_posts". */
    private function capitalise(string $name): string
    {
        return ucfirst($name);
    }

    /**
     * Capitalised model class name with a single "_model" suffix:
     * "members" / "Members_model" / "members_MODEL" => "Members_model".
     */
    private function modelName(string $name): string
    {
        if (strtolower(substr($name, -6)) === '_model') {
            $name = substr($name, 0, -6);
        }
        return $this->capitalise($name) . '_model';
    }

    // ------------------------------------------------------------------
    // Database
    // ------------------------------------------------------------------

    /**
     * Connect using the 'default' group in config/database.php.
     * Returns null (never throws) in dry-run mode or on any problem.
     */ 

    private function connect(): ?PDO
    {
        if ($this->dryRun) {
            return null;
        }
    
        $configFile = $this->projectRoot . '/config/database.php';
    
        if (!is_file($configFile)) {
            $this->warn("Config file not found: {$configFile}");
            return null;
        }
    
        try {
            $databases = (static function (string $file): array {
                $databases = [];
    
                require $file;
    
                return $databases;
            })($configFile);
    
            $cfg = $databases['default'] ?? null;
    
            if (!is_array($cfg)) {
                $this->warn(
                    "No \$databases['default'] found in config/database.php."
                );
    
                return null;
            }
    
            if (trim((string) ($cfg['database'] ?? '')) === '') {
                $this->warn("'database' is empty in config/database.php. Set your database name first.");

                return null;
            }

            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $cfg['host'] ?? '127.0.0.1',
                $cfg['port'] ?? '3306',
                $cfg['database'] ?? ''
            );
    
            return new PDO(
                $dsn,
                $cfg['user'] ?? 'root',
                $cfg['password'] ?? '',
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
    
        } catch (Throwable $e) {
            $this->warn(
                "Could not connect to the database: " . $e->getMessage()
            );
    
            return null;
        }
    }
    

    private function assertTableName(string $table): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new InvalidArgumentException("Invalid table name: {$table}");
        }
    }

    /**
     * Create the table if it does not exist yet. When a DB is available and the
     * table is missing, prompt the user (interactively) to define its columns.
     * Existing tables are never touched.
     */
    private function createTable(string $table, ?PDO $pdo): void
    {
        $this->assertTableName($table);

        // Already exists?  Leave it alone. (Exact match; LIKE would treat "_" as a wildcard.)
        if ($pdo !== null) {
            try {
                $stmt = $pdo->prepare(
                    'SELECT 1 FROM information_schema.tables
                     WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
                );
                $stmt->execute([$table]);
                if ($stmt->fetchColumn() !== false) {
                    Console::success("Table already exists, left untouched: {$table}");
                    return;
                }
            } catch (PDOException $e) {
                $this->warn("Could not check for table: {$e->getMessage()}");
            }
        }

        // No DB or dry-run: don't prompt, print the fallback SQL.
        if ($pdo === null) {
            $sql = $this->buildCreateSql($table, $this->defaultColumns());
            if ($this->dryRun) {
                echo "[dry-run] Would create table (if missing):\n{$sql}\n";
            } else {
                $this->warn("Table not created. Run this SQL yourself:\n{$sql}");
            }
            return;
        }

        $columns = $this->interactive ? $this->promptColumns($table) : [];

        if (empty($columns)) {
            $columns = $this->defaultColumns();
        }

        $sql = $this->buildCreateSql($table, $columns);

        try {
            $pdo->exec($sql);
            Console::success("Table created: {$table}");
        } catch (PDOException $e) {
            $this->warn("Could not create table: {$e->getMessage()}\nRun this SQL yourself:\n{$sql}");
        }
    }

    /**
     * Bare default columns used when the user skips the prompt.
     *
     * @return array<int, array{name:string, definition:string, primary?:bool}>
     */
    private function defaultColumns(): array
    {
        return [
            ['name' => 'id',         'definition' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT', 'primary' => true],
            ['name' => 'created_at', 'definition' => 'INT UNSIGNED NOT NULL DEFAULT 0'],
        ];
    }

    /**
     * Ask the user to define columns, one per line.
     * An empty answer means "use the bare default table".
     *
     * @return array<int, array{name:string, definition:string, primary?:bool}>
     */
    private function promptColumns(string $table): array
    {
        $this->out("Table `{$table}` does not exist. Define its columns.");
        $this->out("Format: name type [flags]   (blank line to finish)");
        $this->out("Examples:");
        $this->out("    name        varchar(100) not null");
        $this->out("    email       varchar(255) not null");
        $this->out("    price       decimal(10,2) null default=0");
        $this->out("    is_active   tinyint(1) not null default=1");
        $this->out("    body        text null");
        $this->out("    status      enum('draft','live') not null default='draft'");
        $this->out("");

        $columns = [];
        $seen    = [];

        while (true) {
            $line = $this->ask('column (or blank to finish): ');

            if ($line === '') {
                break;
            }

            $parsed = $this->parseColumnLine($line);

            if ($parsed === null) {
                $this->warn("Could not parse: {$line}. Try again.");
                continue;
            }

            if (isset($seen[$parsed['name']])) {
                $this->warn("Duplicate column: {$parsed['name']}. Skipped.");
                continue;
            }

            $seen[$parsed['name']] = true;
            $columns[] = $parsed;
            $this->out("  + {$parsed['name']} {$parsed['definition']}");
        }

        if (empty($columns)) {
            $this->out("No columns entered - using bare default table (id, created_at).");
            return [];
        }

        // Always ensure an id PK exists; created_at is only added if absent.
        if (!isset($seen['id'])) {
            array_unshift($columns, [
                'name'       => 'id',
                'definition' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
                'primary'    => true,
            ]);
        }
        if (!isset($seen['created_at'])) {
            $columns[] = [
                'name'       => 'created_at',
                'definition' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            ];
        }

        return $columns;
    }

    /**
     * Parse one "name type [flags]" line into ['name','definition','primary'].
     *
     * Everything that ends up in the SQL is whitelisted: type, args, flags and
     * DEFAULT values. Anything unrecognised makes the line unparseable (null),
     * so user input can never be spliced into the CREATE TABLE statement.
     *
     * @return array{name:string, definition:string, primary:bool}|null
     */
    private function parseColumnLine(string $line): ?array
    {
        $line = trim($line);

        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s+(.+)$/', $line, $m)) {
            return null;
        }

        $name = $m[1];
        $rest = trim($m[2]);

        if (!preg_match('/^([A-Za-z]+)(\([^)]*\))?(.*)$/', $rest, $t)) {
            return null;
        }

        $type = strtolower($t[1]);
        $args = $t[2] ?? '';
        $tail = trim($t[3] ?? '');

        $allowed = [
            'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint',
            'decimal', 'numeric', 'float', 'double', 'real',
            'char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext',
            'date', 'datetime', 'timestamp', 'time', 'year',
            'bool', 'boolean', 'json', 'enum',
        ];

        if (!in_array($type, $allowed, true)) {
            return null;
        }

        // Validate type arguments.
        if ($type === 'enum') {
            // Values may not contain quotes or backslashes.
            if (!preg_match('/^\(\s*\'[^\'\\\\]*\'(\s*,\s*\'[^\'\\\\]*\')*\s*\)$/', $args)) {
                return null;
            }
        } elseif ($args !== '') {
            if (!preg_match('/^\([0-9]+(\s*,\s*[0-9]+)?\)$/', $args)) {
                return null;
            }
        }

        // DEFAULT: only a plain quoted string (no quotes/backslashes inside), a number,
        // or NULL / CURRENT_TIMESTAMP / TRUE / FALSE.
        $default      = null;
        $defaultValid = true;

        $tail = preg_replace_callback(
            '/default\s*=\s*(\'[^\'\\\\]*\'|-?[0-9]+(?:\.[0-9]+)?|[A-Za-z_]+(?:\(\))?)/i',
            function (array $mm) use (&$default, &$defaultValid): string {
                $v = $mm[1];
                if (
                    $v[0] !== "'"
                    && !is_numeric($v)
                    && !in_array(strtoupper($v), ['NULL', 'CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP()', 'TRUE', 'FALSE'], true)
                ) {
                    $defaultValid = false;
                }
                $default = 'DEFAULT ' . $v;
                return ' ';
            },
            $tail
        );

        if (!$defaultValid) {
            return null;
        }

        $words = preg_split('/\s+/', strtolower(trim((string) $tail)), -1, PREG_SPLIT_NO_EMPTY);

        $unsigned     = false;
        $notNull      = false;
        $explicitNull = false;
        $primary      = false;
        $auto         = false;
        $unique       = false;

        for ($i = 0, $n = count($words); $i < $n; $i++) {
            switch ($words[$i]) {
                case 'unsigned':
                    $unsigned = true;
                    break;
                case 'not':
                    if (($words[$i + 1] ?? '') !== 'null') {
                        return null;
                    }
                    $notNull = true;
                    $i++;
                    break;
                case 'null':
                    $explicitNull = true;
                    break;
                case 'auto_increment':
                    $auto = true;
                    break;
                case 'primary':
                case 'primary_key':
                    $primary = true;
                    break;
                case 'key':
                    break;
                case 'unique':
                    $unique = true;
                    break;
                default:
                    return null; // unknown token: refuse rather than pass it into SQL
            }
        }

        if ($notNull && $explicitNull) {
            return null;
        }

        // MySQL requires AUTO_INCREMENT columns to be keys and not null.
        if ($auto) {
            $primary = true;
            $notNull = true;
        }
        if ($primary) {
            $notNull = true;
        }

        $definition = strtoupper($type) . $args;
        if ($unsigned) {
            $definition .= ' UNSIGNED';
        }
        $definition .= $notNull ? ' NOT NULL' : ' NULL';
        if ($auto) {
            $definition .= ' AUTO_INCREMENT';
        }
        if ($default !== null) {
            $definition .= ' ' . $default;
        }
        if ($unique && !$primary) {
            $definition .= ' UNIQUE';
        }

        return [
            'name'       => $name,
            'definition' => $definition,
            'primary'    => $primary,
        ];
    }

    /**
     * Build the CREATE TABLE statement from the prompted columns.
     *
     * @param array<int, array{name:string, definition:string, primary?:bool}> $columns
     */
    private function buildCreateSql(string $table, array $columns): string
    {
        $lines = [];

        foreach ($columns as $col) {
            $line = "    `{$col['name']}` {$col['definition']}";
            if (!empty($col['primary'])) {
                $line .= ' PRIMARY KEY';
            }
            $lines[] = $line;
        }

        return "CREATE TABLE IF NOT EXISTS `{$table}` (\n"
             . implode(",\n", $lines)
             . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    /**
     * Read the table's columns. Falls back to id + created_at when the DB is unavailable.
     *
     * @return array<int, array<string, mixed>>  rows of SHOW COLUMNS
     */
    private function loadColumns(string $table, ?PDO $pdo): array
    {
        $fallback = [
            ['Field' => 'id', 'Type' => 'int unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => 'auto_increment'],
            ['Field' => 'created_at', 'Type' => 'int unsigned', 'Null' => 'NO', 'Key' => '', 'Default' => '0', 'Extra' => ''],
        ];

        if ($pdo === null) {
            return $fallback;
        }

        $this->assertTableName($table);

        try {
            $rows = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            return $rows ?: $fallback;
        } catch (PDOException $e) {
            $this->warn("Could not read columns: {$e->getMessage()}");
            return $fallback;
        }
    }

    private function warn(string $message): void
    {
        fwrite(STDERR, "Warning: {$message}\n");
    }

    /** Echo to STDOUT (prefixed in dry-run mode). */
    private function out(string $message): void
    {
        if ($this->dryRun) {
            echo "[dry-run] {$message}\n";
            return;
        }
        fwrite(STDOUT, $message . "\n");
    }

    /** Read one line from STDIN; empty string when not interactive/TTY. */
    private function ask(string $prompt): string
    {
        if ($this->dryRun || !self::isTty()) {
            return '';
        }
        fwrite(STDOUT, $prompt);
        $line = fgets(STDIN);
        return $line === false ? '' : trim($line);
    }

    private static function isTty(): bool
    {
        return function_exists('stream_isatty') && @stream_isatty(STDIN);
    }

    // ------------------------------------------------------------------
    // Column => field metadata
    // ------------------------------------------------------------------
    /**
     * Build form field descriptors from database column metadata.
     *
     * Converts rows returned by SHOW COLUMNS into structured field
     * definitions, identifying field types and assigning appropriate
     * roles for form generation.
     *
     * Supported field kinds:
     * text, email, password, url, textarea, integer, decimal,
     * checkbox, date, datetime, time, select.
     *
     * Supported field roles:
     * pk       - Primary key or auto-increment column.
     * auto     - Automatically managed timestamp column.
     * reserved - Column excluded from form generation due to naming conflicts.
     * input    - Regular user-editable form field.
     *
     * @param array<int, array<string, mixed>> $columns
     *     Column metadata rows returned by the database.
     *
     * @return array<int, array<string, mixed>>
     *     Normalized field descriptors for form and CRUD generation.
     */
    private function buildFields(array $columns): array
    {
        $fields = [];

        foreach ($columns as $col) {
            $name = (string) $col['Field'];

            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                $this->warn("Skipping column with unsupported name: {$name}");
                continue;
            }

            $rawType  = strtolower((string) $col['Type']);
            $unsigned = str_contains($rawType, 'unsigned');
            preg_match('/^([a-z]+)(?:\((.*)\))?/', $rawType, $m);
            $base = $m[1] ?? 'varchar';
            $arg  = $m[2] ?? '';

            $nullable   = strtoupper((string) $col['Null']) === 'YES';
            $hasDefault = $col['Default'] !== null;
            $isPk       = ($col['Key'] ?? '') === 'PRI'
                || str_contains(strtolower((string) ($col['Extra'] ?? '')), 'auto_increment');

            $kind    = 'text';
            $options = [];
            $max     = null;

            switch ($base) {
                case 'tinyint':
                    $kind = ($arg === '1') ? 'checkbox' : 'integer';
                    break;
                case 'bool':
                case 'boolean':
                    $kind = 'checkbox';
                    break;
                case 'smallint':
                case 'mediumint':
                case 'int':
                case 'integer':
                case 'bigint':
                case 'year':
                    $kind = 'integer';
                    break;
                case 'decimal':
                case 'numeric':
                case 'float':
                case 'double':
                case 'real':
                    $kind = 'decimal';
                    break;
                case 'varchar':
                case 'char':
                    $kind = 'text';
                    $max  = ctype_digit($arg) ? (int) $arg : null;
                    if (str_contains($name, 'email')) {
                        $kind = 'email';
                    } elseif (str_contains($name, 'password')) {
                        $kind = 'password';
                        if ($max !== null && $max < 60) {
                            $this->warn("Column `{$name}` is varchar({$max}); password_hash() needs at least 60 (use 255).");
                        }
                    } elseif ($name === 'url' || str_ends_with($name, '_url') || str_contains($name, 'website')) {
                        $kind = 'url';
                    }
                    break;
                case 'tinytext':
                case 'text':
                case 'mediumtext':
                case 'longtext':
                case 'json':
                    $kind = 'textarea';
                    break;
                case 'date':
                    $kind = 'date';
                    break;
                case 'datetime':
                case 'timestamp':
                    $kind = 'datetime';
                    break;
                case 'time':
                    $kind = 'time';
                    break;
                case 'enum':
                    $kind    = 'select';
                    $options = array_map('strval', str_getcsv($arg, ',', "'"));
                    break;
            }

            $isAutoStamp = in_array($name, ['created_at', 'updated_at'], true)
                && in_array($kind, ['integer', 'datetime'], true);

            if ($isPk) {
                $role = 'pk';
            } elseif ($isAutoStamp) {
                $role = 'auto';
            } elseif (in_array($name, self::RESERVED_VIEW_VARS, true)) {
                $role = 'reserved';
                $this->warn("Column `{$name}` clashes with a generated view variable and was left out of the form. Rename it.");
            } else {
                $role = 'input';
            }

            $fields[] = [
                'name'     => $name,
                'label'    => ucwords(str_replace('_', ' ', $name)),
                'kind'     => $kind,
                'role'     => $role,
                'unsigned' => $unsigned,
                'nullable' => $nullable,
                'required' => !$nullable && !$hasDefault && $kind !== 'checkbox',
                'max'      => $max,
                'options'  => $options,
                'step'     => ($kind === 'decimal') ? 'any' : null,
            ];
        }

        return $fields;
    }


    /**
     * Filter field definitions to identify fields that should be rendered
     * as input controls in create and update forms.
     *
     * Excludes fields that should not be manually supplied by users,
     * such as primary keys and automatically managed database columns.
     *
     * @param array<int, array<string, mixed>> $fields Database field definitions.
     *
     * @return array<int, array<string, mixed>> Filtered field definitions for form inputs.
     */
    private function inputFields(array $fields): array
    {
        return array_values(array_filter($fields, static fn ($f) => $f['role'] === 'input'));
    }

    // ------------------------------------------------------------------
    // Controller code generated from fields
    // ------------------------------------------------------------------

    /**
     * Generate PHP validation code for the form submission handler.
     *
     * Produces validation rules for the supplied field definitions using
     * Trongate's validation conventions. The generated code is intended
     * to be inserted inside the submit() method with 8-space indentation.
     *
     * Assumes that $update_id has already been defined in the surrounding
     * method, allowing validation logic to distinguish between create
     * and update operations where necessary.
     *
     * @param array<int, array<string, mixed>> $fields Database field definitions.
     *
     * @return string Generated PHP validation code.
     */
    private function validationCode(array $fields): string
    {
        $lines = [];

        foreach ($this->inputFields($fields) as $f) {
            $n = $f['name'];

            // Passwords: required on create only; blank on update means "keep current".
            if ($f['kind'] === 'password') {
                if ($f['required']) {
                    $lines[] = "        if (\$update_id < 1 || (string) post('{$n}') !== '') {";
                    $lines[] = "            \$this->validation->set_rules('{$n}', '{$f['label']}', 'required|min_length[8]');";
                } else {
                    $lines[] = "        if ((string) post('{$n}') !== '') {";
                    $lines[] = "            \$this->validation->set_rules('{$n}', '{$f['label']}', 'min_length[8]');";
                }
                $lines[] = "        }";
                continue;
            }

            $rules = [];

            if ($f['required']) {
                $rules[] = 'required';
            }
            if ($f['kind'] === 'integer') {
                $rules[] = 'integer';
            }
            if ($f['kind'] === 'decimal') {
                $rules[] = 'numeric';
            }
            if ($f['kind'] === 'email') {
                $rules[] = 'valid_email';
            }
            if ($f['max'] !== null && in_array($f['kind'], ['text', 'email', 'url'], true)) {
                $rules[] = 'max_length[' . $f['max'] . ']';
            }

            if (!empty($rules)) {
                $lines[] = sprintf(
                    "        \$this->validation->set_rules('%s', '%s', '%s');",
                    $f['name'],
                    $f['label'],
                    implode('|', $rules)
                );
            }
        }

        return empty($lines)
            ? '        // No validation rules could be derived from the table.'
            : implode("\n", $lines);
    }



    /**
     * Generate PHP code for building a whitelist of fields allowed to be saved.
     *
     * Restricts database write operations to fields identified as input columns.
     * Only whitelisted fields are extracted from POST data, preventing
     * unauthorized or unintended fields from being written to the database.
     *
     * @param array<int, array<string, mixed>> $fields Database field definitions.
     *
     * @return string Generated PHP code for constructing the validated data array.
     */
    private function dataArrayCode(array $fields): string
    {
        $lines = [];

        foreach ($this->inputFields($fields) as $f) {
            $n    = $f['name'];
            $post = "post('{$n}', true)";

            switch ($f['kind']) {
                case 'checkbox':
                    $expr = "post('{$n}') ? 1 : 0";
                    break;
                case 'integer':
                    $expr = $f['nullable']
                        ? "post('{$n}') === '' ? null : (int) post('{$n}')"
                        : "(int) post('{$n}')";
                    break;
                case 'decimal':
                    $expr = $f['nullable']
                        ? "post('{$n}') === '' ? null : post('{$n}')"
                        : "post('{$n}')";
                    break;
                case 'datetime':
                    $expr = $f['nullable']
                        ? "{$post} === '' ? null : str_replace('T', ' ', {$post})"
                        : "str_replace('T', ' ', {$post})";
                    break;
                case 'textarea':
                    // No clean_up here: it collapses whitespace and would flatten line breaks.
                    $raw  = "trim((string) post('{$n}'))";
                    $expr = $f['nullable'] ? "{$raw} === '' ? null : {$raw}" : $raw;
                    break;
                case 'password':
                    // Raw value; hashed (or dropped when blank) in the controller.
                    $expr = "(string) post('{$n}')";
                    break;
                case 'select':
                    // Only accept values that really belong to the ENUM.
                    $list = '[' . implode(', ', array_map(
                        static fn ($o) => var_export($o, true),
                        $f['options']
                    )) . ']';
                    $fallback = $f['nullable'] ? 'null' : var_export($f['options'][0] ?? '', true);
                    $expr = "in_array(post('{$n}'), {$list}, true) ? post('{$n}') : {$fallback}";
                    break;
                default:
                    $expr = $f['nullable']
                        ? "{$post} === '' ? null : {$post}"
                        : $post;
            }

            $lines[] = sprintf("            '%s' => %s,", $n, $expr);
        }

        return empty($lines)
            ? "            // No editable columns found. Add columns to the table and re-run crud."
            : implode("\n", $lines);
    }


    /**
     * Generate PHP code for mapping a fetched database record to the view data array.
     *
     * Assigns the retrieved record's field values to the corresponding
     * top-level variables in the $data array consumed by the form view.
     * This ensures database values are available to the form helpers
     * when displaying or editing existing records.
     *
     * @param array<int, array<string, mixed>> $fields Database field definitions.
     *
     * @return string Generated PHP code for populating the view data array.
     */
    private function dbArrayCode(array $fields): string
    {
        $lines = [];

        foreach ($this->inputFields($fields) as $f) {
            $n = $f['name'];

            switch ($f['kind']) {
                case 'checkbox':
                    $expr = "!empty(\$record->{$n}) ? 1 : 0";
                    break;
                case 'integer':
                    $expr = "isset(\$record->{$n}) ? (int) \$record->{$n} : null";
                    break;
                case 'decimal':
                    $expr = "\$record->{$n} ?? null";
                    break;
                case 'datetime':
                    $expr = "isset(\$record->{$n}) ? str_replace(' ', 'T', substr((string) \$record->{$n}, 0, 16)) : ''";
                    break;
                case 'password':
                    $expr = "''"; // never send a stored hash back to the form
                    break;
                default:
                    $expr = "\$record->{$n} ?? ''";
            }

            $lines[] = sprintf("            '%s' => %s,", $n, $expr);
        }

        return empty($lines)
            ? "            // No editable columns found."
            : implode("\n", $lines);
    }


    /**
     * Generate PHP code for handling password fields securely.
     *
     * Hashes newly supplied passwords before storing them. During update
     * operations, blank password values are ignored to ensure existing
     * password hashes are never overwritten with empty values.
     *
     * @param array<int, array<string, mixed>> $fields Database field definitions.
     *
     * @return string Generated PHP code for password handling.
     */
    private function passwordHandlingCode(array $fields): string
    {
        $lines = [];

        foreach ($this->inputFields($fields) as $f) {
            if ($f['kind'] !== 'password') {
                continue;
            }
            $n = $f['name'];
            $lines[] = "        if ((string) (\$data['{$n}'] ?? '') !== '') {";
            $lines[] = "            \$data['{$n}'] = password_hash((string) \$data['{$n}'], PASSWORD_DEFAULT);";
            $lines[] = "        } else {";
            $lines[] = "            unset(\$data['{$n}']);";
            $lines[] = "        }";
        }

        return implode("\n", $lines);
    }

    /**
     * Generate PHP code for automatically managed timestamp columns.
     *
     * Handles created_at and updated_at fields by generating the appropriate
     * timestamp assignments for insert or update operations. The generated
     * code uses 12-space indentation to maintain consistent formatting with
     * the surrounding generated code.
     *
     * @param array<int, array<string, mixed>> $fields Database field definitions.
     * @param bool $isInsert Whether the generated code is for an insert operation.
     *
     * @return string Generated PHP code for automatic timestamp assignments.
     */
    private function autoColumnsCode(array $fields, bool $isInsert): string
    {
        $lines = [];

        foreach ($fields as $f) {
            if ($f['role'] !== 'auto') {
                continue;
            }
            if ($f['name'] === 'created_at' && !$isInsert) {
                continue;
            }
            $value   = $f['kind'] === 'integer' ? 'time()' : "date('Y-m-d H:i:s')";
            $lines[] = sprintf("            \$data['%s'] = %s;", $f['name'], $value);
        }

        return implode("\n", $lines);
    }

    // ------------------------------------------------------------------
    // View HTML generated from fields
    // ------------------------------------------------------------------

    /**
     * Generate the HTML form body using Trongate's built-in form helpers.
     *
     * Builds the appropriate form controls from the supplied field definitions.
     * Field values are accessed as top-level view variables (e.g. $title),
     * because the controller passes each database column directly into the
     * view data array.
     *
     * @param array<int, array<string, mixed>> $fields Field definitions used to generate the form.
     *
     * @return string Generated HTML markup for the form fields.
     */
    private function formFieldsHtml(array $fields): string
    {
        $out = [];

        foreach ($this->inputFields($fields) as $f) {
            $n     = $f['name'];
            $label = $f['label'];
            $req   = $f['required'];

            switch ($f['kind']) {
                case 'checkbox':
                    $out[] = "    <?php";
                    $out[] = "    echo form_checkbox('{$n}', '1', !empty(\${$n}));";
                    $out[] = "    echo '{$label}';";
                    $out[] = "    ?>";
                    break;

                case 'textarea':
                    $out[] = "    <?php";
                    $out[] = "    echo form_label('{$label}');";
                    $out[] = "    \${$n}_attr = [";
                    $out[] = "        'placeholder' => 'Enter {$label}',";
                    if ($req) {
                        $out[] = "        'required'  => true,";
                    }
                    $out[] = "        'rows'      => 5";
                    $out[] = "    ];";
                    $out[] = "    echo form_textarea('{$n}', \${$n}, \${$n}_attr);";
                    $out[] = "    ?>";
                    break;

                case 'select':
                    $out[] = "    <?php";
                    $out[] = "    echo form_label('{$label}');";
                    $out[] = "    \${$n}_options = [";
                    $out[] = "        '' => '-- Select --',";
                    foreach ($f['options'] as $opt) {
                        $out[] = "        " . var_export($opt, true) . " => " . var_export($opt, true) . ",";
                    }
                    $out[] = "    ];";
                    $out[] = "    echo form_dropdown('{$n}', \${$n}_options, \${$n});";
                    $out[] = "    ?>";
                    break;

                case 'password':
                    // Never echo a stored hash back into the form.
                    $out[] = "    <?php";
                    $out[] = "    echo form_label('{$label}');";
                    $out[] = "    \${$n}_attr = [";
                    $out[] = "        'placeholder' => 'Enter {$label}',";
                    $out[] = "        'autocomplete' => 'new-password'";
                    $out[] = "    ];";
                    $out[] = "    echo form_password('{$n}', '', \${$n}_attr);";
                    $out[] = "    ?>";
                    break;

                case 'date':
                case 'datetime':
                    // Date picker: Trongate's form_date()/form_datetime_local() helpers render a
                    // native <input type="date|datetime-local">. The onclick opens the browser's
                    // calendar from anywhere in the field (not just the small icon); showPicker()
                    // is feature-detected, so older browsers simply keep the default behaviour.
                    $helper = ($f['kind'] === 'date') ? 'form_date' : 'form_datetime_local';

                    $out[] = "    <?php";
                    $out[] = "    echo form_label('{$label}');";
                    $out[] = "    \${$n}_attr = [";
                    if ($req) {
                        $out[] = "        'required'     => true,";
                    }
                    $out[] = "        'class'        => 'datepicker',";
                    $out[] = "        'autocomplete' => 'off',";
                    $out[] = "        'onclick'      => 'if (this.showPicker) { try { this.showPicker(); } catch (e) {} }'";
                    $out[] = "    ];";
                    $out[] = "    echo {$helper}('{$n}', isset(\${$n}) ? (string) \${$n} : null, \${$n}_attr);";
                    $out[] = "    ?>";
                    break;

                default:
                    // text, email, url, integer, decimal, date, datetime, time
                    $typeMap = [
                        'integer'  => 'number',
                        'decimal'  => 'number',
                        'email'    => 'email',
                        'url'      => 'url',
                        'date'     => 'date',
                        'datetime' => 'datetime-local',
                        'time'     => 'time',
                        'text'     => 'text',
                    ];
                    $type = $typeMap[$f['kind']] ?? 'text';

                    $out[] = "    <?php";
                    $out[] = "    echo form_label('{$label}');";
                    $out[] = "    \${$n}_attr = [";
                    $out[] = "        'placeholder' => 'Enter {$label}',";
                    if ($req) {
                        $out[] = "        'required'  => true,";
                    }
                    if ($f['max'] !== null && in_array($f['kind'], ['text', 'email', 'url'], true)) {
                        $out[] = "        'maxlength' => {$f['max']},";
                    }
                    if ($f['kind'] === 'integer') {
                        $out[] = "        'step'      => 1,";
                    }
                    if ($f['kind'] === 'decimal') {
                        $out[] = "        'step'      => 'any',";
                    }
                    $out[] = "        'type'      => '{$type}'";
                    $out[] = "    ];";
                    $out[] = "    echo form_input('{$n}', \${$n}, \${$n}_attr);";
                    $out[] = "    ?>";
            }

            $out[] = '';
        }

        return empty($out)
            ? '    <!-- No editable columns found. Add columns to the table and re-run crud. -->'
            : rtrim(implode("\n", $out));
    }

    /**
     * Generate an HTML-safe display expression for a field belonging to an object.
     *
     * Builds the appropriate PHP expression using the supplied object variable
     * and field definition. The resulting expression always applies HTML escaping
     * to prevent cross-site scripting (XSS) when displaying database values.
     *
     * Optionally applies truncation to long field values to improve readability
     * in list views and other constrained display areas.
     *
     * @param array<string, mixed> $f Field definition containing the field name and display metadata.
     * @param string $var Object variable used to access the field value (e.g. '$row' or '$record').
     * @param bool $truncate Whether to truncate long field values before displaying them.
     *
     * @return string Generated PHP expression for safely displaying the field value.
     */
    private function displayExpr(array $f, string $var, bool $truncate): string
    {
        $n = $f['name'];

        if ($f['kind'] === 'checkbox') {
            return "<?= !empty({$var}->{$n}) ? 'Yes' : 'No' ?>";
        }
        if ($f['role'] === 'auto' && $f['kind'] === 'integer') {
            return "<?= !empty({$var}->{$n}) ? date('Y-m-d H:i', (int) {$var}->{$n}) : '' ?>";
        }
        if ($f['kind'] === 'password' || $this->isSensitive($f['name'])) {
            return '••••••••';
        }

        $value = "(string) ({$var}->{$n} ?? '')";
        if ($truncate && $f['kind'] === 'textarea') {
            $value = "mb_strimwidth({$value}, 0, 60, '…')";
        }

        return "<?= htmlspecialchars({$value}, ENT_QUOTES, 'UTF-8') ?>";
    }

    /**
     * Filter field definitions to determine which fields are displayed in index views.
     *
     * Excludes primary keys, textarea fields, password fields, and sensitive
     * fields to prevent inappropriate data exposure. Limits the resulting
     * field list to a maximum of eight entries to maintain readable index cards.
     *
     * Re-indexes the filtered array before applying the limit.
     *
     * @param array<int, array<string, mixed>> $fields Available field definitions.
     *
     * @return array<int, array<string, mixed>> Filtered field definitions for index views.
     */
    private function indexFields(array $fields): array
    {
        $shown = array_filter(
            $fields,
            fn ($f) => $f['role'] !== 'pk'
                && $f['kind'] !== 'textarea'
                && $f['kind'] !== 'password'
                && !$this->isSensitive($f['name'])
        );
    
        return array_slice(array_values($shown), 0, 8);
    }

    /** Columns that should never be echoed in list/detail views (still editable in the form). */
    private function isSensitive(string $name): bool
    {
        return (bool) preg_match('/(secret|token|api_?key|private_?key|salt|hash|pin|otp|ssn)/i', $name);
    }

    /**
     * Generate the HTML expression for the index card heading.
     *
     * Uses the value of the first available field returned by indexFields()
     * as the card heading. The field value is rendered using displayExpr()
     * with HTML escaping and truncation enabled.
     *
     * Returns an empty string when no eligible fields are available.
     *
     * @param array<int, array<string, mixed>> $fields Database field definitions.
     *
     * @return string Generated HTML-safe PHP expression for the card heading.
     */
    private function indexHeadHtml(array $fields): string
    {
        $first = $this->indexFields($fields)[0] ?? null;
    
        return $first ? $this->displayExpr($first, '$row', true) : '';
    }

    /**
     * Generate the HTML for additional fields displayed in an index row.
     *
     * Excludes the first field because it is already displayed in the
     * heading. Each remaining field is rendered as a paragraph containing
     * its escaped label and corresponding display expression.
     *
     * Returns an HTML comment when there are no additional fields to display.
     *
     * @param array<int, array<string, mixed>> $fields Field definitions.
     *
     * @return string Generated HTML markup for the additional index fields.
     */
    private function indexCellsHtml(array $fields): string
    {
        $rows = [];
        foreach (array_slice($this->indexFields($fields), 1) as $f) {
            $rows[] = '                        <p class="mb-1">'
                    . '<strong>' . htmlspecialchars($f['label'], ENT_QUOTES, 'UTF-8') . ':</strong> '
                    . $this->displayExpr($f, '$row', true)
                    . '</p>';
        }
        return empty($rows)
            ? '                        <!-- No additional fields. -->'
            : implode("\n", $rows);
    }

    
    /**
     * Generate HTML definition-list entries for displaying record fields.
     *
     * Iterates through the supplied field definitions, skipping password
     * fields to prevent password hashes from being exposed. Each remaining
     * field is rendered as a <dt>/<dd> pair using its escaped label and
     * corresponding display expression.
     *
     * @param array<int, array<string, mixed>> $fields Field definitions.
     *
     * @return string Generated HTML markup for the record fields.
     */
    private function showRowsHtml(array $fields): string
    {
        $rows = [];
    
        foreach ($fields as $f) {
            if ($f['kind'] === 'password') {
                continue; // never display password hashes
            }
    
            $rows[] = '                <dt>' . htmlspecialchars($f['label'], ENT_QUOTES, 'UTF-8') . '</dt>'
                    . '<dd>' . $this->displayExpr($f, '$record', false) . '</dd>';
        }
    
        return implode("\n", $rows);
    }

    // ------------------------------------------------------------------
    // Templates
    // ------------------------------------------------------------------

    /**
     * Replace template placeholders with their corresponding values in a single pass.
     *
     * Uses {@see strtr()} to prevent substituted content from being scanned
     * or replaced again. This ensures generated values, such as ENUM values
     * that resemble placeholders, remain unchanged.
     *
     * Templates are defined using nowdoc syntax to avoid unintended PHP
     * variable interpolation.
     *
     * @param string $template The template containing placeholders.
     * @param array<string, string> $vars Associative array of placeholder replacements.
     *
     * @return string The template with placeholders replaced.
     */
    private function fill(string $template, array $vars): string
    {
        return strtr($template, $vars);
    }

    private function crudController(): string
    {
        return <<<'PHP'
<?php

class __CLASS__ extends Trongate
{
__READ_ROUTES__

    // ------------------------------------------------------------------
    // ADMIN (every method below requires an allowed user)
    // ------------------------------------------------------------------

    /**
     * Admin list with Edit/Delete.  URL: __MODULE__/manage
     */
    public function manage(): void
    {
        $this->trongate_security->make_sure_allowed();

        $data = $this->list_data();
        $data['headline'] = '__CLASS__';
        $this->render('manage', $data, 'admin');
    }

    /**
     * Combined create/update form.  URL: __MODULE__/create  (or /create/{id})
     */
    public function create(): void
    {
        $this->trongate_security->make_sure_allowed();

        $update_id = segment(3, 'int');

        if ($update_id > 0 && $this->model->find_by_id($update_id) === false) {
            redirect('__MODULE__/manage');
            return;
        }

        if ((REQUEST_TYPE === 'GET') && ($update_id > 0)) {
            $data = $this->model->get_data_from_db($update_id);
        } else {
            $data = $this->model->get_data_from_post();
        }

        $data['headline']      = ($update_id > 0) ? 'Update __CLASS__ Record' : 'Create New __CLASS__ Record';
        $data['cancel_url']    = '__MODULE__/manage';
        $data['form_location'] = '__MODULE__/submit/' . $update_id;
        $data['update_id']     = $update_id;
        $this->render('create', $data, 'admin');
    }

    /**
     * Handle the create/update form (POST only).  URL: __MODULE__/submit[/{id}]
     * Validation rules were derived from the table's columns.
     */
    public function submit(): void
    {
        $this->trongate_security->make_sure_allowed();

        if (REQUEST_TYPE !== 'POST' || post('submit', true) !== 'Submit') {
            redirect('__MODULE__/manage');
            return;
        }

        $update_id = segment(3, 'int');

        if ($update_id > 0 && $this->model->find_by_id($update_id) === false) {
            redirect('__MODULE__/manage');
            return;
        }

__VALIDATION__

        if (!$this->validation->run()) {
            $this->create();
            return;
        }

        $data = $this->model->get_data_from_post();
__PASSWORD_HANDLING__

        if ($update_id > 0) {
__AUTO_UPDATE__
            $this->model->update_record($update_id, $data);
            $flash_msg = 'The record was successfully updated.';
        } else {
__AUTO_INSERT__
            $update_id = $this->model->save($data);
            $flash_msg = 'The record was successfully created.';
        }

        set_flashdata($flash_msg);
        redirect('__MODULE__/manage');
    }

    /**
     * Confirmation screen.  URL: __MODULE__/delete_conf/{id}
     */
    public function delete_conf(): void
    {
        $this->trongate_security->make_sure_allowed();

        $update_id = segment(3, 'int');

        if ($this->model->find_by_id($update_id) === false) {
            redirect('__MODULE__/manage');
            return;
        }

        $data['update_id']     = $update_id;
        $data['headline']      = 'Delete __MODULE__ Record';
        $data['cancel_url']    = '__MODULE__/manage';
        $data['form_location'] = '__MODULE__/submit_delete/' . $update_id;
        $this->render('delete_conf', $data, 'admin');
    }

    /**
     * Perform the delete (POST only).  URL: __MODULE__/submit_delete/{id}
     */
    public function submit_delete(): void
    {
        $this->trongate_security->make_sure_allowed();

        if (REQUEST_TYPE !== 'POST' || post('submit', true) !== 'Yes - Delete Now') {
            redirect('__MODULE__/manage');
            return;
        }

        // validation->run() verifies the CSRF token before anything is deleted.
        $this->validation->set_rules('submit', 'Submit', 'required');

        if (!$this->validation->run()) {
            redirect('__MODULE__/manage');
            return;
        }

        $update_id = segment(3, 'int');

        if ($update_id > 0 && $this->model->find_by_id($update_id) !== false) {
            $this->model->delete_record($update_id);
            set_flashdata('The record was successfully deleted.');
        }

        redirect('__MODULE__/manage');
    }

    /**
     * Rows for the current page plus pagination values for the list views.
     * Private, so it can never be reached through a URL.
     */
    private function list_data(): array
    {
        $per_page    = 20;
        $total       = $this->model->count_all();
        $total_pages = max(1, (int) ceil($total / $per_page));
        $page        = min(max(1, (int) ($_GET['page'] ?? 1)), $total_pages);

        return [
            'rows'        => $this->model->fetch_page($per_page, ($page - 1) * $per_page),
            'total'       => $total,
            'page'        => $page,
            'total_pages' => $total_pages,
        ];
    }

    /**
     * Private, so it can never be reached through a URL.
     * $template is 'public' or 'admin' (a method on the templates module).
     */
    private function render(string $view_file, array $data, string $template = 'public'): void
    {
        $data['view_module'] = '__MODULE__';
        $data['view_file']   = $view_file;

        if ($template === 'admin') {
            $this->templates->admin($data);
        } else {
            $this->templates->public($data);
        }
    }
}

PHP;
    }

    private function crudModel(): string
    {
        return <<<'PHP'
<?php

class __MODEL__ extends Model
{
    private string $table_name = '__TABLE__';

    public function count_all(): int
    {
        return $this->db->count($this->table_name);
    }

    /**
     * One page of records, newest first. $limit/$offset are cast to ints;
     * the table name is a generator-validated literal.
     */
    public function fetch_page(int $limit, int $offset): array
    {
        $limit  = max(1, $limit);
        $offset = max(0, $offset);

        return $this->db->query(
            "SELECT * FROM `{$this->table_name}` ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
            'object'
        );
    }

    public function find_by_id(int $id): object|bool
    {
        if ($id < 1) {
            return false;
        }
        return $this->db->get_where($id, $this->table_name);
    }

    public function save(array $data): int
    {
        return $this->db->insert($data, $this->table_name);
    }

    public function update_record(int $id, array $data): void
    {
        $this->db->update($id, $data, $this->table_name);
    }

    public function delete_record(int $id): void
    {
        $this->db->delete($id, $this->table_name);
    }

    /**
     * Get __MODULE__ form data from POST (whitelisted columns only).
     *
     * @return array Form data with proper types
     */
    public function get_data_from_post(): array
    {
        return [
__DATA_ARRAY__
        ];
    }

    /**
     * Load an existing record from the DB and prepare it for the form.
     *
     * @return array Form data
     */
    public function get_data_from_db(int $id): array
    {
        $record = $this->find_by_id($id);

        if ($record === false) {
            return $this->get_data_from_post();
        }

        return [
__DB_ARRAY__
        ];
    }
}

PHP;
    }

    private function crudView(string $type): string
    {
        switch ($type) {
            case 'index':
                // Public, read-only: no create/edit/delete links.
                return <<<'PHP'
<?= flashdata() ?>
<section>
    <h1 class="xl"><?= htmlspecialchars($headline, ENT_QUOTES, 'UTF-8') ?></h1>

    <?php if ($total === 0): ?>
        <div class="card">
            <div class="card-heading text-left">No records yet</div>
            <div class="card-body text-left">
                <p>There is nothing here yet.</p>
            </div>
        </div>
    <?php else: ?>
        <p class="tg-showing-statement text-left">
            Showing <?= $total ?> record<?= $total === 1 ? '' : 's' ?>
        </p>

        <div class="flex-column mb-2">
            <?php foreach ($rows as $row): ?>
                <div class="card">
                    <div class="card-heading text-left">
                        __INDEX_HEAD__
                    </div>
                    <div class="card-body text-left">
__INDEX_CELLS__
                        <div class="flex-row justify-start flex-wrap">
                            <a class="button" href="<?= BASE_URL ?>__MODULE__/show/<?= (int) $row->id ?>">View</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($total_pages > 1): ?>
            <div class="flex-row justify-between flex-wrap mb-2">
                <?php if ($page > 1): ?>
                    <a class="button alt" href="<?= BASE_URL ?>__MODULE__?page=<?= $page - 1 ?>">&larr; Previous</a>
                <?php else: ?>
                    <span></span>
                <?php endif; ?>
                <span>Page <?= $page ?> of <?= $total_pages ?></span>
                <?php if ($page < $total_pages): ?>
                    <a class="button alt" href="<?= BASE_URL ?>__MODULE__?page=<?= $page + 1 ?>">Next &rarr;</a>
                <?php else: ?>
                    <span></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

PHP;

            case 'manage':
                // Admin only (controller calls make_sure_allowed()).
                return <<<'PHP'
<?= flashdata() ?>
<section>
    <div class="flex-row justify-between align-center flex-wrap mb-1">
        <h1 class="xl"><?= htmlspecialchars($headline, ENT_QUOTES, 'UTF-8') ?> &ndash; Manage</h1>
        <a class="button success" href="<?= BASE_URL ?>__MODULE__/create">+ Create New</a>
    </div>

    <?php if ($total === 0): ?>
        <div class="card">
            <div class="card-heading text-left">No records yet</div>
            <div class="card-body text-left">
                <p>There is nothing here yet. Get started by creating your first record.</p>
                <a class="button" href="<?= BASE_URL ?>__MODULE__/create">Create New</a>
            </div>
        </div>
    <?php else: ?>
        <p class="tg-showing-statement text-left">
            Showing <?= $total ?> record<?= $total === 1 ? '' : 's' ?>
        </p>

        <div class="flex-column mb-2">
            <?php foreach ($rows as $row): ?>
                <div class="card">
                    <div class="card-heading text-left">
                        __INDEX_HEAD__
                        <span class="sm">#<?= htmlspecialchars((string) ($row->id ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div class="card-body text-left">
__INDEX_CELLS__
                        <div class="flex-row justify-start flex-wrap">
                            <a class="button" href="<?= BASE_URL ?>__MODULE__/show/<?= (int) $row->id ?>">View</a>
                            <a class="button alt" href="<?= BASE_URL ?>__MODULE__/create/<?= (int) $row->id ?>">Edit</a>
                            <a class="button danger" href="<?= BASE_URL ?>__MODULE__/delete_conf/<?= (int) $row->id ?>">Delete</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($total_pages > 1): ?>
            <div class="flex-row justify-between flex-wrap mb-2">
                <?php if ($page > 1): ?>
                    <a class="button alt" href="<?= BASE_URL ?>__MODULE__/manage?page=<?= $page - 1 ?>">&larr; Previous</a>
                <?php else: ?>
                    <span></span>
                <?php endif; ?>
                <span>Page <?= $page ?> of <?= $total_pages ?></span>
                <?php if ($page < $total_pages): ?>
                    <a class="button alt" href="<?= BASE_URL ?>__MODULE__/manage?page=<?= $page + 1 ?>">Next &rarr;</a>
                <?php else: ?>
                    <span></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

PHP;

            case 'show':
                // Public, read-only: no update/delete buttons.
                return <<<'PHP'
<style>
    .tg-dl {
        display: grid;
        grid-template-columns: minmax(120px, 30%) 1fr;
        margin: 0;
    }
    .tg-dl dt,
    .tg-dl dd {
        margin: 0;
        padding: 0.8em 0;
        border-bottom: 1px solid var(--border);
    }
    .tg-dl dt { font-weight: bold; color: #333; }
    .tg-dl dd { white-space: pre-line; word-break: break-word; }
    @media screen and (max-width: 550px) {
        .tg-dl { grid-template-columns: 1fr; }
        .tg-dl dt { border-bottom: 0; padding-bottom: 0; }
    }
</style>

<section>
    <h1 class="xl"><?= htmlspecialchars($headline, ENT_QUOTES, 'UTF-8') ?></h1>

    <div class="card">
        <div class="card-heading">Record Details</div>
        <div class="card-body">
            <dl class="tg-dl">
__SHOW_ROWS__
            </dl>
        </div>
    </div>

    <div class="flex-row justify-between flex-wrap">
        <a class="button alt" href="<?= BASE_URL ?>__BACK_URL__">&larr; Back to list</a>
    </div>
</section>

PHP;

            case 'create':
                return <<<'PHP'
<section>
    <h1 class="xl"><?= htmlspecialchars($headline, ENT_QUOTES, 'UTF-8') ?></h1>

    <div class="card">
        <div class="card-heading">Record Form</div>
        <div class="card-body">
            <?php echo validation_errors(); ?>

            <?php echo form_open($form_location); ?>

__FORM_FIELDS__

            <hr>

            <div class="flex-row justify-end flex-wrap">
                <?php echo anchor($cancel_url, 'Cancel', ['class' => 'button alt']); ?>
                <?php echo form_submit('submit', 'Submit', ['class' => 'button']); ?>
            </div>

            <?php echo form_close(); ?>
        </div>
    </div>
</section>

PHP;

            case 'delete_conf':
                return <<<'PHP'
<section class="container-xs">
    <h1 class="xl"><?= htmlspecialchars($headline, ENT_QUOTES, 'UTF-8') ?></h1>

    <div class="card">
        <div class="card-heading danger">Confirmation Required</div>
        <div class="card-body text-center">
            <p class="lg"><strong>Are you sure?</strong></p>
            <p>You are about to delete a __MODULE__ record. This cannot be undone. Do you really want to do this?</p>

            <?php echo form_open($form_location); ?>
                <?php echo anchor($cancel_url, 'Cancel', ['class' => 'button alt']); ?>
                <?php echo form_submit('submit', 'Yes - Delete Now', ['class' => 'button danger']); ?>
            <?php echo form_close(); ?>
        </div>
    </div>
</section>

PHP;
        }

        throw new InvalidArgumentException("Unknown CRUD view type: {$type}");
    }
}