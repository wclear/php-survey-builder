<?php

// Set include path to look for classes in the models directory, then in the controllers directory
$projectDir = dirname(dirname(__FILE__));
define('PROJECT_DIR', $projectDir);
define('SEP', DIRECTORY_SEPARATOR);
$classDirectories = ['models', 'controllers'];
$classPaths = [];
foreach ($classDirectories as $dir) {
    $classPaths[] = $projectDir . DIRECTORY_SEPARATOR . $dir;
}
$classPaths[] = get_include_path();
$includePath = implode(PATH_SEPARATOR, $classPaths);
set_include_path($includePath);

// Set up autoload function
spl_autoload_register(['Controller', 'autoload']);

function abs_path($projectPath) {
    return PROJECT_DIR . DIRECTORY_SEPARATOR . str_replace(
        ['/', '\\'], DIRECTORY_SEPARATOR, $projectPath
    );
}

/**
 * The Controller class is an abstract class used to handle user requests and make use of various
 * models and views.
 *
 * @author David Barnes
 * @copyright Copyright (c) 2013, David Barnes
 */
abstract class Controller
{
    const INITIAL_DATA_FILE = 'sql/initial_data.sql';
    const RUNTIME_EXCEPTION_VIEW = 'runtime_exception.php';

    protected $config;
    protected $dsn;
    protected $driver = 'sqlite';
    protected $pdo;
    protected $viewVariables = [];

    protected $dsnConfigFile = '';
    protected $databaseSchemaFile = '';
    protected $databaseSchemaFileMysql = '';
    protected $databaseSchemaFilePostgresql = '';
    protected $initialDataFile = '';
    protected $sessionName = '';
    protected $runtimeExceptionView = '';

    function __construct() {
        $this->dsnConfigFile = PROJECT_DIR . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'Database.ini';
        $this->databaseSchemaFile = PROJECT_DIR . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'schema.sql';
        $this->databaseSchemaFileMysql = PROJECT_DIR . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'schema_mysql.sql';
        $this->databaseSchemaFilePostgresql = PROJECT_DIR . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'schema_postgresql.sql';
        $this->initialDataFile = PROJECT_DIR . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'initial_data.sql';
        $this->sessionName = 'survey-session';
        $this->runtimeExceptionView = PROJECT_DIR . DIRECTORY_SEPARATOR . 'runtime_exception.php';
    }

    /**
     * Automatically load the necessary file for a given class.
     *
     * @param string $class the class name to autoload
     */
    public static function autoload($class)
    {
        require $class . '.php';

        return true;
    }

    /**
     * Display the page - open the database, execute controller code, and display the view.
     */
    public function display()
    {
        // Make sure requests and responses are utf-8
        header('Content-type: text/html; charset=utf-8');

        try {
            // Check to make sure required dependencies are installed
            $this->checkDependencies();

            // Open PDO database connection
            $this->openDatabase();

            // Handle the page request
            $this->handleRequest($_REQUEST);

            // Get view filename
            $viewFilename = $this->getViewFilename();

            // Display the view
            $this->displayView($viewFilename);
        } catch (RuntimeException $e) {
            $this->assign('statusMessage', $e->getMessage());
            $this->displayView($this->runtimeExceptionView);
        } catch (Exception $e) {
            // Handle exception
            $this->handleError($e);

            // Get view filename
            $viewFilename = $this->getViewFilename();

            // Display view
            if ($viewFilename) {
                $this->displayView($viewFilename);
            } else {
                die($e->getMessage());
            }
        }
    }

    /**
     * Get the filename of the view file containing HTML and PHP presentation logic.
     *
     * @return string returns the view filename
     */
    protected function getViewFilename()
    {
        return basename($_SERVER['SCRIPT_NAME']);
    }

    /**
     * Handle the page request.
     *
     * @param array $request the page parameters from a form post or query string
     */
    abstract protected function handleRequest(&$request);

    /**
     * Handle an exception and display the error to the user.
     *
     * @param Exception $e the Exception to be displayed
     */
    protected function handleError(Exception $e)
    {
        $this->assign('statusMessage', $e->getMessage());
    }

    /**
     * Assign a variable to be used in the view.
     *
     * @param string $name  the variable name
     * @param mixed  $value the variable value
     */
    protected function assign($name, $value)
    {
        $this->viewVariables[$name] = $value;
    }

    /**
     * Display the view associated with the controller.
     */
    protected function displayView($viewFilename)
    {
        $viewPath = realpath(dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . $viewFilename);

        if (! file_exists($viewPath)) {
            throw new RuntimeException("Path does not exist: $viewPath");
        }

        // Extract view variables into current scope
        extract($this->viewVariables);

        // Display the view
        require	$viewPath;
    }

    /**
     * Start a new session with the session name
     */
    protected function startSession()
    {
        session_name($this->sessionName);
        session_start();
    }

    /**
     * Get the current user sessions, or redirect to the login page.
     */
    protected function getUserSession()
    {
        $this->startSession();

        if (! isset($_SESSION['login'])) {
            $this->redirect('login.php');
        }

        return $_SESSION['login'];
    }

    /**
     * Redirect to another URL.
     *
     * @param string $url the URL to redirect to
     */
    protected function redirect($url)
    {
        // Close session information
        if (session_id() != '') {
            session_write_close();
        }

        header("Location: $url");
        exit;
    }

    /**
     * Check for required dependencies and throw an exception if not all dependencies are found.
     *
     * @throws RuntimeException if a required dependency is not found
     */
    protected function checkDependencies()
    {
        $missing = [];

        if (! extension_loaded('openssl')) {
            $missing[] = 'openssl';
        }

        if (! extension_loaded('pdo')) {
            $missing[] = 'PDO';
        }

        if (! extension_loaded('pdo_sqlite')) {
            $missing[] = 'pdo_sqlite';
        } else {
            // Version 3.6.19 is required for foreign key support and cascade support
            // Check version here
            if (extension_loaded('sqlite3')) {
                $versionArray = sqlite3::version();
                $versionString = $versionArray['versionString'];
            } else {
                // If we don't have the sqlite extension, but we have the pdo_sqlite extension,
                // Use an alternate method to check the sqlite version.
                $pdo = new PDO('sqlite::memory:');
                $versionString = $pdo->query('select sqlite_version()')->fetch()[0];
            }

            if (version_compare($versionString, '3.6.19', '<')) {
                $missing[] = 'sqlite3 version 3.6.19 or higher';
            }
        }

        if (! empty($missing)) {
            throw new RuntimeException("The following PHP extensions are required:\n\n" . implode("\n", $missing));
        }
    }

    /**
     * Open a PDO connection to the database and assign it to instance variable $pdo.
     *
     * @throws RuntimeException if the database could not be opened
     */
    protected function openDatabase()
    {
        if (! file_exists($this->dsnConfigFile)) {
            throw new RuntimeException('Database config file not found: ' . $this->dsnConfigFile);
        }
        $databaseConfig = parse_ini_file($this->dsnConfigFile);
        if (! isset($databaseConfig['dsn'])) {
            throw new RuntimeException("Database config parameter 'dsn' not found in config file: " . $this->dsnConfigFile);
        }

        $username = null;
        $password = null;

        if (preg_match('/^(mysql|pgsql)/', $databaseConfig['dsn'], $matches)) {
            $this->driver = $matches[1];
            if (! isset($databaseConfig['username'])) {
                throw new RuntimeException("Database config parameter 'username' not found in config file: " . $this->dsnConfigFile);
            }
            if (! isset($databaseConfig['password'])) {
                throw new RuntimeException("Database config parameter 'password' not found in config file: " . $this->dsnConfigFile);
            }
            $username = $databaseConfig['username'];
            $password = $databaseConfig['password'];
        } else {
            if (! isset($databaseConfig['filename'])) {
                throw new RuntimeException("Database config parameter 'filename' not found in config file: " . $this->dsnConfigFile);
            }
            if (! is_writable(dirname($databaseConfig['filename']))) {
                throw new RuntimeException('Data directory not writable by web server: ' . dirname($databaseConfig['filename']) . '/');
            }
            if (! is_writable(dirname($databaseConfig['filename'])) || (file_exists($databaseConfig['filename']) && ! is_writable($databaseConfig['filename']))) {
                throw new RuntimeException('Database file not writable by web server: ' . $databaseConfig['filename']);
            }
        }
        try {
            $this->pdo = new PDO($databaseConfig['dsn'], $username, $password);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            if ($this->driver == 'sqlite') {
                $this->pdo->exec('PRAGMA foreign_keys = ON;');
            }

            if (! $this->databaseTablesCreated()) {
                $this->createDatabaseTables();
            }
        } catch (PDOException $e) {
            throw new RuntimeException('PDOException: ' . $e->getMessage());
        }
    }

    /**
     * Create database tables from SQL schema file.
     */
    protected function createDatabaseTables()
    {
        $schemaFile = $this->databaseSchemaFile;;
        if ($this->driver == 'mysql') {
            $schemaFile = $this->databaseSchemaFileMysql;
        } elseif ($this->driver == 'pgsql') {
            $schemaFile = $this->databaseSchemaFilePostgresql;
        }

        if (! file_exists($schemaFile)) {
            throw new RuntimeException('Database schema file not found: ' . $schemaFile);
        }
        // Create tables
        $sql = file_get_contents($schemaFile);
        $this->pdo->exec($sql);

        // Load initial data
        $sql = file_get_contents($this->initialDataFile);
        $this->pdo->exec($sql);
    }

    /**
     * Determine if database tables have already been created.
     */
    protected function databaseTablesCreated()
    {
        if ($this->driver == 'mysql') {
            $sql = "show tables like 'login'";
        } elseif ($this->driver == 'pgsql') {
            $sql = "select table_name from information_schema.tables where table_schema='public' and table_name='login'";
        } else {
            $sql = "select count(*) from sqlite_master where type='table' and name='login'";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $stmt->setFetchMode(PDO::FETCH_NUM);
        if ($row = $stmt->fetch()) {
            if ($this->driver == 'mysql') {
                // mysql
                if ($row[0] == 'login') {
                    return true;
                }
            } elseif ($this->driver == 'pgsql') {
                // pgsql
                if ($row[0] == 'login') {
                    return true;
                }
            } else {
                // sqlite3
                if ($row[0] == 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
