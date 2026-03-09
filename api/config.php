<?php
// ============================================================
// ATTENDANCE APP – Configuration
// Edit DB_* and APP_URL before deploying
// ============================================================

// Buffer ALL output — prevents "headers already sent" from any
// stray whitespace in included files
ob_start();

// ── App settings ─────────────────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_NAME',    'attendance_db');
define('DB_USER',    'root');         // ← your DB username
define('DB_PASS',    '');             // ← your DB password
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'AttendTrack');
define('APP_URL',  'http://localhost/attendance-app'); // ← your domain, no trailing slash

define('UPLOAD_DIR', '/tmp/uploads/');
define('UPLOAD_URL', '/uploads/'); // Note: Vercel /tmp is ephemeral — use external storage (S3/Cloudinary) for production

define('SESSION_TIMEOUT', 3600);         // seconds (1 hour)
define('TIMEZONE',        'Asia/Jakarta'); // your timezone

date_default_timezone_set(TIMEZONE);

// ── Session ───────────────────────────────────────────────────
// Only call session_start() once; guard against double-include
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Database connection (PDO singleton) ───────────────────────
function getDB() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    try {
        $dsn  = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
    } catch (PDOException $e) {
        // Show a friendly error page instead of raw JSON output
        // (raw output would break header() calls on every page)
        http_response_code(500);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
              <title>Database Error</title>
              <style>body{font-family:sans-serif;max-width:600px;margin:80px auto;padding:20px}
              .box{background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:24px}
              h2{color:#991b1b;margin:0 0 12px}pre{font-size:.85rem;color:#7f1d1d;white-space:pre-wrap}</style>
              </head><body>
              <div class="box">
              <h2>⚠️ Database Connection Failed</h2>
              <p>The app cannot connect to the database. Please check your <code>config.php</code> settings.</p>
              <pre>' . htmlspecialchars($e->getMessage()) . '</pre>
              </div></body></html>';
        exit;
    }
    return $pdo;
}

// ── Auth helpers ──────────────────────────────────────────────
function isLoggedIn() {
    return isset($_SESSION['user_id'], $_SESSION['last_activity'])
        && (time() - $_SESSION['last_activity']) < SESSION_TIMEOUT;
}

function isAdmin() {
    return isLoggedIn() && ($_SESSION['role'] ?? '') === 'admin';
}

// All internal redirects use relative paths so the app works in
// any subdirectory without touching APP_URL
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /?msg=session_expired');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: /dashboard');
        exit;
    }
}

// Generic redirect helper (kept for backward compat)
function redirect($url) {
    header('Location: ' . $url);
    exit;
}

