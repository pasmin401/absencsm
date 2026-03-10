<?php
// ============================================================
// ATTENDANCE APP – Configuration (Vercel Postgres / Neon)
// ============================================================

ob_start();

// ── App settings ─────────────────────────────────────────────
define('APP_NAME', 'AttendTrack');
define('APP_URL',  'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

define('UPLOAD_DIR', '/tmp/uploads/');
define('UPLOAD_URL', '/uploads/');

define('SESSION_TIMEOUT', 3600);
define('TIMEZONE', 'Asia/Jakarta');

date_default_timezone_set(TIMEZONE);

// ── Session ───────────────────────────────────────────────────
// Vercel serverless: sessions must use /tmp, set secure cookie settings
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.save_path', '/tmp');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

// ── Database connection (PDO PostgreSQL via Vercel Postgres) ──
function getDB() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    try {
        // Vercel injects POSTGRES_* env vars automatically
        $host     = $_ENV['PGHOST']     ?? getenv('PGHOST');
        $dbname   = $_ENV['PGDATABASE'] ?? getenv('PGDATABASE');
        $user     = $_ENV['PGUSER']     ?? getenv('PGUSER');
        $password = $_ENV['PGPASSWORD'] ?? getenv('PGPASSWORD');
        $port     = '5432';

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require";

        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $pdo = new PDO($dsn, $user, $password, $opts);

    } catch (PDOException $e) {
        http_response_code(500);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
              <title>Database Error</title>
              <style>body{font-family:sans-serif;max-width:600px;margin:80px auto;padding:20px}
              .box{background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:24px}
              h2{color:#991b1b;margin:0 0 12px}pre{font-size:.85rem;color:#7f1d1d;white-space:pre-wrap}</style>
              </head><body><div class="box">
              <h2>⚠️ Database Connection Failed</h2>
              <p>Check your Vercel Postgres environment variables (PGHOST, PGDATABASE, PGUSER, PGPASSWORD).</p>
              <pre>' . htmlspecialchars($e->getMessage()) . '</pre>
              </div></body></html>';
        exit;
    }
    return $pdo;
}

// ── Auth helpers ──────────────────────────────────────────────
function isLoggedIn() {
    if (!isset($_SESSION['user_id'], $_SESSION['last_activity'], $_SESSION['role'])) {
        return false;
    }
    if ((time() - $_SESSION['last_activity']) >= SESSION_TIMEOUT) {
        // Session expired — destroy it so it can't cause redirect loops
        session_unset();
        session_destroy();
        return false;
    }
    return true;
}

function isAdmin() {
    return isLoggedIn() && ($_SESSION['role'] ?? '') === 'admin';
}

function requireLogin() {
    if (!isLoggedIn()) {
        // Clear any stale session data before redirecting
        session_unset();
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

function redirect($url) {
    header('Location: ' . $url);
    exit;
}

// ── Helper: escape HTML ───────────────────────────────────────
function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}
