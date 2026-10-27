<?php
/**
 * SmartClass - Database Connection Configuration
 * Google Classroom Clone - Teacher & Student System
 */

// =====================================================
// CONFIGURATION — which customer (instance) is this request for?
// =====================================================
// One code folder serves many customers. A customer has a file config/instances/<domain>.php
// (written by bin/create_customer.php) with its own database, login and settings. A request for any
// other domain is the original system and uses the values below.
$sc_host = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? (getenv('SC_HOST') ?: 'localhost'))));
$sc_host = preg_replace('/[^a-z0-9.-]/', '', $sc_host);
$sc_inst_file = __DIR__ . '/instances/' . $sc_host . '.php';
$sc_inst = ($sc_host !== '' && strpos($sc_host, '..') === false && is_file($sc_inst_file)) ? (require $sc_inst_file) : null;
if ($sc_inst !== null && !is_array($sc_inst)) $sc_inst = null;

// Only the hosts listed in config/provider.php › 'master_hosts' (when that list exists) open the ORIGINAL system; any other
// domain that reaches this server and is not a customer gets "Unknown site" (a deleted customer's domain, a stray DNS record ...)
if ($sc_inst === null && PHP_SAPI !== 'cli' && is_file(__DIR__ . '/provider.php')) {
    $sc_prov = @include __DIR__ . '/provider.php';
    if (is_array($sc_prov) && !empty($sc_prov['master_hosts']) && !in_array($sc_host, array_map('strtolower', (array) $sc_prov['master_hosts']), true)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Unknown site');
    }
}

// Staging copy: started with the environment variable SC_STAGING=1 (see bin/staging.sh). It uses the database smartclass_staging
// (made by bin/make_staging.php from the live data), never sends e-mail and never calls an AI provider.
define('SC_STAGING', getenv('SC_STAGING') === '1');
define('SC_INSTANCE', $sc_inst['slug'] ?? 'default');
define('SC_IS_MASTER', $sc_inst === null);                 // the original system (owns the Customers page)
// Folder that holds this customer's uploads/, backups/ and sessions/ (the original system keeps them in the project folder)
define('STORAGE_ROOT', $sc_inst ? dirname(__DIR__) . '/instances/' . SC_INSTANCE : dirname(__DIR__));

// Private settings of this server (never committed): config/local.php returns ['db_host', 'db_name', 'db_user', 'db_pass', 'secret_key'].
// Written by  php bin/init_local_config.php  — keeps the database password and the key for stored secrets out of the code.
$sc_local = (!$sc_inst && is_file(__DIR__ . '/local.php')) ? (@include __DIR__ . '/local.php') : [];
if (!is_array($sc_local)) $sc_local = [];
if ($sc_inst && !empty($sc_inst['secret_key'])) $sc_local['secret_key'] = $sc_inst['secret_key'];
define('SC_SECRET_KEY', (string) ($sc_local['secret_key'] ?? (getenv('SC_SECRET_KEY') ?: '')));   // the environment variable is for tests and containers
// Behind a reverse proxy / CDN that ends HTTPS (Cloudflare, nginx in front, a hosting panel): the proxy tells us in X-Forwarded-Proto. Only believed when this
// server is told to trust its proxy ('trust_proxy' => true in config/local.php or the customer file, or SC_TRUST_PROXY=1) — otherwise a visitor could fake the header.
if ((!empty($sc_local['trust_proxy']) || !empty($sc_inst['trust_proxy']) || getenv('SC_TRUST_PROXY') === '1') && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') $_SERVER['HTTPS'] = 'on';

// Database
define('DB_HOST', $sc_inst['db_host'] ?? $sc_local['db_host'] ?? 'localhost');
// Automated tests (tests/run.php) point the whole app at a throw-away database called sc_test_<random>
$sc_test_db = (string) getenv('SC_TEST_DB');
define('DB_NAME', preg_match('/^sc_test_[a-z0-9]{6,16}$/', $sc_test_db) ? $sc_test_db : (SC_STAGING ? 'smartclass_staging' : ($sc_inst['db_name'] ?? $sc_local['db_name'] ?? 'smartclass_db')));
define('DB_USER', $sc_inst['db_user'] ?? $sc_local['db_user'] ?? 'root');
define('DB_PASS', $sc_inst['db_pass'] ?? $sc_local['db_pass'] ?? '');
define('DB_CHARSET', 'utf8mb4');

// URL path where the app lives on the server. Examples:
//   http://localhost/myProject/SmartClass  ->  '/myProject/SmartClass'
//   https://yourdomain.com                 ->  ''   (app at domain root)
//   https://yourdomain.com/smartclass      ->  '/smartclass'
define('BASE_PATH', $sc_inst['base_path'] ?? $sc_local['base_path'] ?? '/myProject/SmartClass');   // set 'base_path' in config/local.php; '' = the site is at the domain root

// =====================================================

// Full app URL, built from the actual request host + BASE_PATH.
// Works the same on localhost, 127.0.0.1, a LAN IP, or a real domain.
$app_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$app_host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('APP_URL', $app_scheme . '://' . $app_host . BASE_PATH);

// Session Configuration
define('SESSION_LIFETIME', 86400); // 24 hours
define('SESSION_NAME', 'SmartClassSession' . ($sc_inst ? '_' . preg_replace('/[^A-Za-z0-9]/', '', SC_INSTANCE) : '') . (SC_STAGING ? '_staging' : ''));

// Error Reporting: log everything, never print warnings onto pages
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
// Errors go to logs/php-error.log (web access denied by logs/.htaccess).
// If the folder is not writable, PHP falls back to the server's error log.
$app_log_dir = dirname(__DIR__) . '/logs';
if (is_dir($app_log_dir) && is_writable($app_log_dir)) {
    ini_set('error_log', $app_log_dir . '/php-error.log');
}

// Timezone
date_default_timezone_set('Asia/Karachi');

/**
 * Database Connection Class
 */
class Database {
    private $pdo;
    private $stmt;
    
    public function __construct() {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        
        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
        } catch (PDOException $e) {
            die("Database Connection Failed: " . $e->getMessage());
        }
    }
    
    /** The PDO connection (backup / restore need raw statements). */
    public function getConnection() {
        return $this->pdo;
    }

    /**
     * Execute a query with parameters
     */
    public function query($sql, $params = []) {
        $this->stmt = $this->pdo->prepare($sql);
        $this->stmt->execute($params);
        return $this->stmt;
    }
    
    /**
     * Fetch all results
     */
    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }
    
    /**
     * Fetch single row
     */
    public function fetchOne($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }
    
    /**
     * Insert and return last insert ID
     */
    public function insert($table, $data) {
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $this->query($sql, $data);
        
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Update records
     */
    public function update($table, $data, $where, $whereParams = []) {
        $set = [];
        foreach (array_keys($data) as $column) {
            $set[] = "{$column} = ?";
        }

        // Positional placeholders throughout: SET values first, then WHERE params
        $sql = "UPDATE {$table} SET " . implode(', ', $set) . " WHERE {$where}";
        $this->query($sql, array_merge(array_values($data), array_values($whereParams)));

        return $this->stmt->rowCount();
    }
    
    /**
     * Delete records
     */
    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        $this->query($sql, $params);
        
        return $this->stmt->rowCount();
    }
    
    /**
     * Count records
     */
    public function count($table, $where = '1=1', $params = []) {
        $sql = "SELECT COUNT(*) as total FROM {$table} WHERE {$where}";
        return $this->fetchOne($sql, $params)['total'];
    }
    
    /**
     * Check if record exists
     */
    public function exists($table, $column, $value) {
        $sql = "SELECT COUNT(*) as count FROM {$table} WHERE {$column} = :value LIMIT 1";
        $result = $this->fetchOne($sql, ['value' => $value]);
        return $result['count'] > 0;
    }
}

/**
 * Session Management Class
 */
class Session {
    public static function start() {
        // Command-line runs (tests, scripts) never need web sessions and must
        // not create session files the web server cannot read.
        if (PHP_SAPI === 'cli') {
            if (!isset($_SESSION)) {
                $_SESSION = [];
            }
            return;
        }

        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        // Own session folder for this app (isolated from other localhost apps)
        $sessionPath = STORAGE_ROOT . '/sessions';
        if (is_dir($sessionPath) && is_writable($sessionPath)) {
            session_save_path($sessionPath);
        }

        ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
        // Reject unknown/broken session ids from old cookies: PHP then simply
        // issues a fresh id instead of failing forever on a bad cookie.
        ini_set('session.use_strict_mode', 1);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',   // only over https (plain http on a local test server)
            'samesite' => 'Lax',
        ]);
        session_name(SESSION_NAME);

        // Self-healing: if the browser's cookie still cannot be used,
        // force a brand-new session id and try once more.
        if (!@session_start()) {
            session_id(bin2hex(random_bytes(16)));
            @session_start();
        }
        // A session belongs to one customer: never accept one made for another instance
        if (($_SESSION['_inst'] ?? SC_INSTANCE) !== SC_INSTANCE) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['_inst'] = SC_INSTANCE;
        // Browser protections for every page (a page that needs to be framed or sniffed can override them)
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: same-origin');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        }
    }
    
    public static function set($key, $value) {
        $_SESSION[$key] = $value;
    }
    
    public static function get($key, $default = null) {
        return $_SESSION[$key] ?? $default;
    }
    
    public static function has($key) {
        return isset($_SESSION[$key]);
    }
    
    public static function remove($key) {
        unset($_SESSION[$key]);
    }
    
    public static function destroy() {
        session_destroy();
        $_SESSION = [];
    }
    
    public static function flash($key, $value = null) {
        if ($value !== null) {
            $_SESSION['_flash'][$key] = $value;
        } else {
            $flash = $_SESSION['_flash'][$key] ?? null;
            unset($_SESSION['_flash'][$key]);
            return $flash;
        }
    }
    
    public static function isLoggedIn() {
        return self::has('user_id');
    }
    
    public static function getUser() {
        if (self::isLoggedIn()) {
            $db = new Database();
            return $db->fetchOne("SELECT * FROM users WHERE id = ?", [self::get('user_id')]);
        }
        return null;
    }
}

/**
 * Authentication Class
 */
class Auth {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    /**
     * Attempt to login user
     */
    public function login($email, $password) {
        $user = $this->db->fetchOne("SELECT * FROM users WHERE email = ? AND is_active = 1", [$email]);
        
        if ($user && password_verify($password, $user['password'])) {
            Session::set('user_id', $user['id']);
            Session::set('user_role', $user['role']);
            Session::set('user_name', $user['name']);
            
            // Update last login
            $this->db->update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Register new user
     */
    public function register($data) {
        $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        $data['role'] = $data['role'] ?? 'student';
        $data['is_active'] = 1;
        
        return $this->db->insert('users', $data);
    }
    
    /**
     * Logout user
     */
    public function logout() {
        Session::destroy();
    }
    
    /**
     * Check if user has specific role
     */
    public function hasRole($role) {
        return Session::get('user_role') === $role;
    }
    
    /**
     * Check if user is admin
     */
    public function isAdmin() {
        return $this->hasRole('admin');
    }
    
    /**
     * Check if user is teacher
     */
    public function isTeacher() {
        return $this->hasRole('teacher') || $this->hasRole('admin');
    }
    
    /**
     * Check if user is student
     */
    public function isStudent() {
        return $this->hasRole('student');
    }
    
    /**
     * Require authentication
     */
    public function requireLogin() {
        if (!Session::isLoggedIn()) {
            header('Location: ' . APP_URL . '/pages/auth/login.php');
            exit;
        }
    }
    
    /**
     * Require specific role
     */
    public function requireRole($role) {
        $this->requireLogin();
        if (!$this->hasRole($role)) {
            header('Location: ' . APP_URL . '/pages/dashboard.php?error=unauthorized');
            exit;
        }
    }
}

/**
 * Utility Functions
 */
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function redirect($url) {
    header("Location: $url");
    exit;
}

function dd($var) {
    echo "<pre>";
    print_r($var);
    echo "</pre>";
    exit;
}

function formatDate($date, $format = 'Y-m-d') {
    return date($format, strtotime($date));
}

function formatDateTime($date) {
    return date('Y-m-d H:i:s', strtotime($date));
}

function getStatusBadge($status) {
    $badges = [
        'present' => '<span class="badge badge-success">Present</span>',
        'absent' => '<span class="badge badge-danger">Absent</span>',
        'official' => '<span class="badge badge-warning">Official Leave</span>'
    ];
    return $badges[$status] ?? '<span class="badge badge-secondary">Unknown</span>';
}

// Initialize
Session::start();
$db = new Database();
$auth = new Auth();

// Shared helpers (notifications + activity log) — available everywhere
require_once dirname(__DIR__) . '/includes/functions.php';

enforceHttps();   // when the school switched on "Force HTTPS"

// Provider control: maintenance / suspended pages for customer installations (never for the original system)
enforceCustomerAccess();
