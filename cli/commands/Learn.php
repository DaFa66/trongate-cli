<?php

/**
 * Trongate CLI — Learn
 *
 * An interactive, paginated chapter-by-chapter learning guide
 * for developers getting started with the Trongate framework.
 *
 * Usage:
 *   php trongate learn            (interactive, starts at chapter 1)
 *   php trongate learn <n>        (jump straight to chapter n)
 *   php trongate learn list       (print chapter list and exit)
 */

final class Learn
{
    private const LINE_WIDTH = 100;

    /** The current chapter index (1-based). */
    private static int $current = 1;

    /* ------------------------------------------------------------------ */
    /*  Entry point                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Run the interactive learning guide.
     *
     * @param string $arg Optional CLI argument: a chapter number, "list", or "".
     * @return void
     */
    public static function run(string $arg = ''): void
    {
        $chapters = self::chapters();
        $total    = count($chapters);

        // Non-interactive shortcut: list chapters.
        if ($arg === 'list') {
            self::renderChapterList($chapters);
            return;
        }

        // Jump-to-chapter shortcut.
        $n = (int) $arg;
        self::$current = ($n >= 1 && $n <= $total) ? $n : 1;

        // Interactive loop.
        while (true) {
            self::renderChapter($chapters[self::$current - 1], self::$current, $total);
            $choice = self::prompt();

            switch (strtoupper(trim($choice))) {
                case 'N':
                    if (self::$current < $total) {
                        self::$current++;
                    } else {
                        self::note('You are on the last chapter.');
                    }
                    break;

                case 'P':
                    if (self::$current > 1) {
                        self::$current--;
                    } else {
                        self::note('You are on the first chapter.');
                    }
                    break;

                case 'G':
                    $target = (int) self::prompt('Chapter number: ');
                    if ($target >= 1 && $target <= $total) {
                        self::$current = $target;
                    } else {
                        self::note("Invalid chapter. Choose 1–{$total}.");
                    }
                    break;

                case 'L':
                    self::renderChapterList($chapters);
                    self::prompt('Press ENTER to return...');
                    break;

                case 'R':
                    self::renderResources();
                    self::prompt('Press ENTER to return...');
                    break;

                case 'Q':
                    self::bye();
                    return;

                case '':
                    // ENTER defaults to Next.
                    if (self::$current < $total) {
                        self::$current++;
                    }
                    break;

                default:
                    self::note('Unknown choice. Use P, N, G, L, R or Q.');
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Rendering                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Render a single chapter page.
     *
     * @param array{title:string, short:string, objective:string, topics:string, practice:string, checkpoint:string} $chapter
     * @param int $index 1-based chapter index.
     * @param int $total Total number of chapters.
     * @return void
     */
    private static function renderChapter(array $chapter, int $index, int $total): void
    {
        // Resources is list-style, so delegate to its own renderer.
        if ($chapter['title'] === 'Resources') {
            self::renderResources($chapter, $index, $total);
            return;
        }

        $line = str_repeat('-', self::LINE_WIDTH);

        echo PHP_EOL;
        echo "  TRONGATE CLI — LEARNING GUIDE" . PHP_EOL;
        echo "  {$line}" . PHP_EOL . PHP_EOL;

        // Header: "Chapter 01 of 20                        INTRODUCTION"
        $left  = sprintf('Chapter %02d of %02d', $index, $total);
        $right = strtoupper($chapter['short']);
        printf("  %-50s%s\n", $left, $right);

        echo "  {$line}" . PHP_EOL . PHP_EOL;

        self::block('LEARNING OBJECTIVE', $chapter['objective']);
        self::block('WHAT TO STUDY',      $chapter['topics']);
        self::block('PRACTICAL EXERCISE', $chapter['practice']);
        self::block('CHECKPOINT',         $chapter['checkpoint']);

        echo PHP_EOL . "  {$line}" . PHP_EOL;
        echo "  [P] Previous   [N] Next   [G] Go to chapter   [L] List chapters   [R] Resources   [Q] Quit" . PHP_EOL . PHP_EOL;
    }

    /**
     * Print the compact list of all chapters.
     *
     * @param array<int, array{title:string, short:string, objective:string, topics:string, practice:string, checkpoint:string}> $chapters
     * @return void
     */
    private static function renderChapterList(array $chapters): void
    {
        $line = str_repeat('-', self::LINE_WIDTH);

        echo PHP_EOL;
        echo "  TRONGATE CLI — CHAPTER LIST" . PHP_EOL;
        echo "  {$line}" . PHP_EOL . PHP_EOL;

        foreach ($chapters as $i => $chapter) {
            $marker = ($i + 1 === self::$current) ? '>>' : '  ';
            printf("  %s %02d. %-45s\n", $marker, $i + 1, $chapter['title']);
        }

        echo PHP_EOL . "  {$line}" . PHP_EOL;
    }

    /**
     * Render the Resources page.
     *
     * Can be called either:
     *   - as chapter 20 (with args), or
     *   - via the [R] shortcut (with no args).
     *
     * @param array{short:string}|null $chapter Chapter data, or null for the [R] shortcut.
     * @param int $index 1-based index; defaults to the current chapter.
     * @param int $total Total chapter count; defaults to the real count.
     * @return void
     */
    private static function renderResources(
        ?array $chapter = null,
        int $index = 0,
        int $total = 0
    ): void {
        // Invoked via [R]: synthesize a consistent header.
        if ($chapter === null) {
            $chapter = ['short' => 'Resources'];
            $index   = self::$current;
            $total   = count(self::chapters());
        }

        $line = str_repeat('-', self::LINE_WIDTH);

        echo PHP_EOL;
        echo "  TRONGATE CLI — LEARNING GUIDE" . PHP_EOL;
        echo "  {$line}" . PHP_EOL . PHP_EOL;

        $left  = sprintf('Chapter %02d of %02d', $index, $total);
        $right = strtoupper($chapter['short']);
        printf("  %-50s%s\n", $left, $right);

        echo "  {$line}" . PHP_EOL . PHP_EOL;

        echo "  DOCUMENTATION HOMEPAGE" . PHP_EOL;
        echo "  https://trongateframework.com/documentation" . PHP_EOL . PHP_EOL;

        echo "  THE FIVE OFFICIAL BOOKS" . PHP_EOL;
        echo PHP_EOL;

        $books = [
            'The Trongate PHP Framework'            => 'Core framework guide — the starting point.',
            'The Complete Trongate Reference Guide' => 'Every module, class and helper function.',
            'Trongate CSS'                          => 'Style pure HTML without classes.',
            'Trongate MX'                           => 'Interactive front-end without JavaScript.',
            'The Trongate Way'                      => 'Blueprints and step-by-step tutorials.',
        ];

        foreach ($books as $name => $description) {
            echo "    - {$name}" . PHP_EOL;
            echo "      {$description}" . PHP_EOL;
        }

        echo PHP_EOL;
        echo "  CHECKPOINT" . PHP_EOL;
        echo "  You know which of the five books to consult for each type of question." . PHP_EOL;

        echo PHP_EOL . "  {$line}" . PHP_EOL;
        echo "  [P] Previous   [N] Next   [G] Go to chapter   [L] List chapters   [R] Resources   [Q] Quit" . PHP_EOL . PHP_EOL;
    }

    /**
     * Print a titled block of word-wrapped text.
     *
     * @param string $heading
     * @param string $body
     * @return void
     */
    private static function block(string $heading, string $body): void
    {
        echo "  {$heading}" . PHP_EOL;
        foreach (self::wrap($body, self::LINE_WIDTH - 2) as $line) {
            echo "  {$line}" . PHP_EOL;
        }
        echo PHP_EOL;
    }

    /**
     * Print an inline notice (used for invalid choices, boundaries, etc.).
     *
     * @param string $message
     * @return void
     */
    private static function note(string $message): void
    {
        echo "  ** {$message}" . PHP_EOL . PHP_EOL;
    }

    /**
     * Print the farewell message.
     *
     * @return void
     */
    private static function bye(): void
    {
        echo PHP_EOL . "  Happy coding with Trongate!" . PHP_EOL . PHP_EOL;
    }

    /* ------------------------------------------------------------------ */
    /*  Input handling                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Prompt the user and return one line of input.
     *
     * Returns an empty string when stdin is not interactive,
     * which prevents infinite loops in piped/CI runs.
     *
     * @param string $label Prompt label shown before reading input.
     * @return string
     */
    private static function prompt(string $label = 'Enter choice: '): string
    {
        if (!self::isInteractive()) {
            return '';
        }

        echo "  {$label}";
        $line = fgets(STDIN);
        return $line === false ? '' : trim($line);
    }

    /**
     * Determine whether STDIN is an interactive terminal.
     *
     * @return bool
     */
    private static function isInteractive(): bool
    {
        return function_exists('posix_isatty')
            ? @posix_isatty(STDIN)
            : (function_exists('stream_isatty') ? @stream_isatty(STDIN) : true);
    }

    /* ------------------------------------------------------------------ */
    /*  Text helpers                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Word-wrap a string to the given width.
     *
     * @param string $text
     * @param int $width Maximum characters per line.
     * @return array<int, string>
     */
    private static function wrap(string $text, int $width): array
    {
        return explode("\n", wordwrap($text, $width, "\n", false));
    }

    /* ------------------------------------------------------------------ */
    /*  Chapter data                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * All chapter definitions, in display order.
     *
     * @return array<int, array{title:string, short:string, objective:string,
     *                          topics:string, practice:string, checkpoint:string}>
     */
    private static function chapters(): array
    {
        return [
            [
                'title'      => 'Introduction',
                'short'      => 'Introduction',
                'objective'  => 'Understand what Trongate is and the problems it helps developers solve.',
                'topics'     => 'Framework philosophy, Native PHP focus, simplicity and modularity, the Trongate ecosystem (PHP Framework, MX, CSS, The Trongate Way).',
                'practice'   => 'Explore an existing Trongate application and identify its main directories. Note how small the engine/ folder is.',
                'checkpoint' => 'You can explain what Trongate does and why you would use it over a heavier framework.',
            ],
            [
                'title'      => 'Quick Start',
                'short'      => 'Quick Start',
                'objective'  => 'Set up a working Trongate development environment.',
                'topics'     => 'Requirements, installation, configuration, database setup, and the BASE_URL constant in config.php.',
                'practice'   => 'Install Trongate, configure your application and open it in a browser.',
                'checkpoint' => 'Your application runs successfully in your local environment.',
            ],
            [
                'title'      => 'Basic Concepts — Everything Is a Module',
                'short'      => 'Basic Concepts',
                'objective'  => 'Understand the fundamental conventions used when developing with Trongate, especially the "everything is a module" architecture.',
                'topics'     => 'PHP conventions; the module-as-folder concept; built-in modules (db, form, validation, file, image, templates, etc.); helpers as thin routing wrappers; engine size (~1,200 lines).',
                'practice'   => 'Open the modules/ directory and identify three built-in modules. Look at how a helper function like truncate_str() delegates to the string_service module.',
                'checkpoint' => 'You understand that database, forms, validation, and even helpers are all modules, and you know where they live.',
            ],
            [
                'title'      => 'Routing',
                'short'      => 'Routing',
                'objective'  => 'Understand how a browser request reaches the correct application code.',
                'topics'     => 'Automatic routing, homepage routing, custom routes, URL segments and redirects, BASE_URL configuration.',
                'practice'   => 'Create a simple page and access it through its URL.',
                'checkpoint' => 'You can explain how a URL maps to a controller and method.',
            ],
            [
                'title'      => 'Intercepting Requests',
                'short'      => 'Intercepting Requests',
                'objective'  => 'Understand how incoming requests can be checked and controlled.',
                'topics'     => 'Request interceptors, endpoint protection, direct URL access, CORS handling via the utilities module.',
                'practice'   => 'Study a request-protection scenario and identify where checks should happen.',
                'checkpoint' => 'You understand where request checks belong in an application.',
            ],
            [
                'title'      => 'Modules',
                'short'      => 'Modules',
                'objective'  => 'Learn how to organize application functionality into modules, and explore the built-in module inventory.',
                'topics'     => 'Controllers, optional model files, views, child modules, module communication; built-in modules: login, db, file, flashdata, form, image, language, pagination, templates, string_service, url, utilities, validation, trongate_tokens, trongate_security.',
                'practice'   => 'Create a simple blog module with a controller and a view. Then browse the modules/ folder and note what each built-in module does.',
                'checkpoint' => 'You can organize a feature into a working module and know which built-in modules exist.',
            ],
            [
                'title'      => 'Database Operations',
                'short'      => 'Database',
                'objective'  => 'Learn how to store, retrieve and manage application data using the db module.',
                'topics'     => 'Database connections via $this->db (not $this->model in v2), configuration, CRUD, raw SQL, debugging, multiple databases.',
                'practice'   => 'Create a blog table and implement record creation and retrieval using $this->db.',
                'checkpoint' => 'You can connect to a database and perform basic CRUD operations.',
            ],
            [
                'title'      => 'Templates',
                'short'      => 'Templates',
                'objective'  => 'Understand how to create consistent application layouts.',
                'topics'     => 'Public templates, admin templates, custom layouts, reusable partials, passing data to views.',
                'practice'   => 'Create a shared page layout and display multiple views inside it.',
                'checkpoint' => 'You can separate page content from the overall application layout.',
            ],
            [
                'title'      => 'Helpers',
                'short'      => 'Helpers',
                'objective'  => 'Learn how to reuse common functionality across your application.',
                'topics'     => 'Form, string, URL, flashdata and utility helpers; understanding that helpers are thin wrappers around modules.',
                'practice'   => 'Identify repetitive tasks in a module and explore the relevant helpers.',
                'checkpoint' => 'You know when a helper can simplify your application code.',
            ],
            [
                'title'      => 'Trongate MX — Interactive Front-End Without JavaScript',
                'short'      => 'Trongate MX',
                'objective'  => 'Learn how to build dynamic, AJAX-driven interfaces using HTML attributes only — no JavaScript required.',
                'topics'     => 'Including trongate-mx.js and trongate.css; the <base> tag; mx-get, mx-target, mx-post attributes; creating HTML-returning endpoints; using form helpers with mx attributes.',
                'practice'   => 'Build a button that fetches HTML from a controller endpoint and inserts it into a target div using mx-get and mx-target.',
                'checkpoint' => 'You can create interactive page elements without writing any JavaScript.',
            ],
            [
                'title'      => 'Form Handling',
                'short'      => 'Forms',
                'objective'  => 'Learn how applications receive and process user input.',
                'topics'     => 'HTML forms, input types, submitted data, form helpers (form_button, form_input, etc.), processing submissions.',
                'practice'   => 'Build a form that creates a new blog post.',
                'checkpoint' => 'You can receive form submissions and process the submitted values.',
            ],
            [
                'title'      => 'Validation and CSRF',
                'short'      => 'Validation',
                'objective'  => 'Learn how to validate input and protect form submissions.',
                'topics'     => 'Validation rules, custom validation callbacks, error messages, CSRF protection via trongate_security.',
                'practice'   => 'Add validation to your blog form and test invalid submissions.',
                'checkpoint' => 'You can reject invalid input and understand the purpose of CSRF protection.',
            ],
            [
                'title'      => 'File Handling',
                'short'      => 'Files',
                'objective'  => 'Understand how applications manage uploaded and stored files using the file module.',
                'topics'     => 'File uploads, downloads, paths, directory management, file security.',
                'practice'   => 'Add a file upload feature and validate the uploaded file.',
                'checkpoint' => 'You understand how to handle files without exposing your application to unnecessary risk.',
            ],
            [
                'title'      => 'Image Processing',
                'short'      => 'Images',
                'objective'  => 'Learn how images can be uploaded and prepared for application use.',
                'topics'     => 'Image configuration, uploading, resizing, cropping, watermarking, storage via the image module.',
                'practice'   => 'Add an image upload feature to your blog application.',
                'checkpoint' => 'You can process and display uploaded images.',
            ],
            [
                'title'      => 'Dates and Times',
                'short'      => 'Dates & Times',
                'objective'  => 'Learn how to collect, validate and work with dates and times.',
                'topics'     => 'HTML5 date/time inputs (form_date, form_time, form_datetime_local); validation rules: valid_date, valid_time, valid_datetime_local, valid_month, valid_week; custom callbacks for ranges and business logic.',
                'practice'   => 'Add publication dates to your blog posts using form_date and valid_date.',
                'checkpoint' => 'You can handle date and time values consistently, using built-in rules for format and callbacks for business logic.',
            ],
            [
                'title'      => 'Language Control',
                'short'      => 'Language',
                'objective'  => 'Understand how to prepare an application for multiple languages.',
                'topics'     => 'Language files, translations, loading language content, HTML language attributes.',
                'practice'   => 'Create a small set of translated interface labels.',
                'checkpoint' => 'You understand how language content can be separated from application logic.',
            ],
            [
                'title'      => 'Security and Authentication',
                'short'      => 'Security',
                'objective'  => 'Understand how to identify users and restrict access to protected resources.',
                'topics'     => 'Token authentication (trongate_tokens), user identity (login module), authorization, password security, trongate_security for permission checks and method-level access control.',
                'practice'   => 'Study a login workflow and identify which pages require authentication.',
                'checkpoint' => 'You understand the difference between authentication and authorization.',
            ],
            [
                'title'      => 'Trongate CSS — Beautiful HTML Without Classes',
                'short'      => 'Trongate CSS',
                'objective'  => 'Learn how to style your application instantly using Trongate CSS, which styles pure HTML automatically.',
                'topics'     => 'Including trongate.css; the <base> tag; automatic form styling; validation error containers (.validation-errors); field error classes (.form-field-validation-error); shake animation.',
                'practice'   => 'Add trongate.css to a template and build a simple form using pure HTML. Notice how it looks presentable without any classes.',
                'checkpoint' => 'You can make your application look decent without writing custom CSS.',
            ],
            [
                'title'      => 'Tips and Best Practices',
                'short'      => 'Best Practices',
                'objective'  => 'Learn how to organize, deploy and maintain a complete application.',
                'topics'     => 'Application data, constructors, utility modules, login systems, password resets, deployment; using The Trongate Way for standardised blueprints.',
                'practice'   => 'Review your blog application and improve its organization and security. Consult The Trongate Way for the login system blueprint.',
                'checkpoint' => 'You can identify areas that need improvement before deploying an application.',
            ],
            [
                'title'      => 'Resources',
                'short'      => 'Resources',
                'objective'  => 'Know where to find the official documentation and learning materials.',
                'topics'     => 'The five official books, the documentation homepage, and how each resource helps you.',
                'practice'   => 'Bookmark the documentation homepage and skim The Trongate Way to see which blueprint matches your next project.',
                'checkpoint' => 'You know which of the five books to consult for each type of question.',
            ],
        ];
    }
}