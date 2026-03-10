<?php
require_once __DIR__ . '/config.php';

// ============================================================
// USER FUNCTIONS
// ============================================================

function getUserById($id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function getUserByEmail($email) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    return $stmt->fetch();
}

function getUserByUsername($username) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    return $stmt->fetch();
}

function createUser($username, $email, $password, $role = 'user') {
    $db = getDB();
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $db->prepare("INSERT INTO users (username, email, password, role, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$username, $email, $hash, $role]);

    // Try lastInsertId() first, fall back to querying the row directly
    // (lastInsertId() can return 0 on some shared hosting PDO configurations)
    $uid = (int)$db->lastInsertId();
    if ($uid < 1) {
        $chk = $db->prepare("SELECT id FROM users WHERE email = ? ORDER BY id DESC LIMIT 1");
        $chk->execute([$email]);
        $row = $chk->fetch();
        $uid = $row ? (int)$row['id'] : 0;
    }
    if ($uid < 1) {
        throw new RuntimeException('Could not retrieve new user ID. Please check that the users table id column has AUTO_INCREMENT set.');
    }
    return $uid;
}

function updateUser($id, $data) {
    $db = getDB();
    $sets = [];
    $vals = [];
    foreach ($data as $k => $v) {
        $sets[] = "`$k` = ?";
        $vals[] = $v;
    }
    $vals[] = $id;
    $sql = "UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?";
    $stmt = $db->prepare($sql);
    return $stmt->execute($vals);
}

function getAllUsers($search = '', $limit = 50, $offset = 0) {
    $db = getDB();
    if ($search) {
        $s = "%$search%";
        $stmt = $db->prepare("SELECT * FROM users WHERE username LIKE ? OR email LIKE ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->execute([$s, $s, $limit, $offset]);
    } else {
        $stmt = $db->prepare("SELECT * FROM users ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
    }
    return $stmt->fetchAll();
}

function countUsers($search = '') {
    $db = getDB();
    if ($search) {
        $s = "%$search%";
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username LIKE ? OR email LIKE ?");
        $stmt->execute([$s, $s]);
    } else {
        $stmt = $db->prepare("SELECT COUNT(*) FROM users");
        $stmt->execute();
    }
    return $stmt->fetchColumn();
}

function deleteUser($id) {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'");
    return $stmt->execute([$id]);
}

// ============================================================
// ATTENDANCE FUNCTIONS
// ============================================================

function getTodayAttendance($userId) {
    $db = getDB();
    $today = date('Y-m-d');
    $stmt = $db->prepare("SELECT * FROM attendance WHERE user_id = ? AND work_date = ?");
    $stmt->execute([$userId, $today]);
    return $stmt->fetch();
}

function getAttendanceById($id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM attendance WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function getUserAttendance($userId, $from = null, $to = null, $limit = 30) {
    $db = getDB();
    $params = [$userId];
    $where = "WHERE a.user_id = ?";
    if ($from) { $where .= " AND a.work_date >= ?"; $params[] = $from; }
    if ($to)   { $where .= " AND a.work_date <= ?"; $params[] = $to; }
    $params[] = $limit;
    $stmt = $db->prepare("SELECT a.*, u.username, u.email FROM attendance a JOIN users u ON a.user_id = u.id $where ORDER BY a.work_date DESC LIMIT ?");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getAllAttendance($from = null, $to = null, $userId = null, $limit = 500) {
    $db = getDB();
    $params = [];
    $where = "WHERE 1=1";
    if ($from)   { $where .= " AND a.work_date >= ?"; $params[] = $from; }
    if ($to)     { $where .= " AND a.work_date <= ?"; $params[] = $to; }
    if ($userId) { $where .= " AND a.user_id = ?";    $params[] = $userId; }
    $params[] = $limit;
    $stmt = $db->prepare("SELECT a.*, u.username, u.email FROM attendance a JOIN users u ON a.user_id = u.id $where ORDER BY a.work_date DESC, u.username ASC LIMIT ?");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function savePhoto($base64Data, $prefix = 'photo') {
    if (empty($base64Data)) return null;

    // Strip data URL prefix e.g. "data:image/jpeg;base64,..."
    if (preg_match('/^data:image\/(\w+);base64,/i', $base64Data, $m)) {
        $ext  = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
        $data = substr($base64Data, strpos($base64Data, ',') + 1);
    } else {
        $ext  = 'jpg';
        $data = $base64Data;
    }

    // Sanitize: remove whitespace that breaks base64_decode on some servers
    $data = preg_replace('/\s+/', '', $data);

    // Strict decode — returns false on bad input
    $decoded = base64_decode($data, true);
    if ($decoded === false || strlen($decoded) < 100) return null;

    // Ensure upload directory exists and is writable
    if (!is_dir(UPLOAD_DIR)) {
        if (!mkdir(UPLOAD_DIR, 0755, true)) return null;
    }
    if (!is_writable(UPLOAD_DIR)) {
        chmod(UPLOAD_DIR, 0755);
        if (!is_writable(UPLOAD_DIR)) return null;
    }

    $filename = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $path     = UPLOAD_DIR . $filename;

    // Check write actually succeeded
    if (file_put_contents($path, $decoded) === false) return null;

    return $filename;
}

function checkIn($userId, $lat, $lng, $photoBase64) {
    $db = getDB();
    $today = date('Y-m-d');
    $now   = date('H:i:s');
    $existing = getTodayAttendance($userId);
    $photo = savePhoto($photoBase64, 'checkin');
    if ($photo === null) return false;
    if ($existing) {
        $stmt = $db->prepare("UPDATE attendance SET checkin_time=?, checkin_lat=?, checkin_lng=?, checkin_photo=? WHERE id=?");
        $stmt->execute([$now, $lat, $lng, $photo, $existing['id']]);
        return $existing['id'];
    } else {
        $stmt = $db->prepare("INSERT INTO attendance (user_id,work_date,checkin_time,checkin_lat,checkin_lng,checkin_photo,status) VALUES (?,?,?,?,?,?,'present') RETURNING id");
        $stmt->execute([$userId, $today, $now, $lat, $lng, $photo]);
        $row = $stmt->fetch();
        $id = $row ? (int)$row['id'] : 0;
        return $id ?: false;
    }
}

function checkOut($userId, $lat, $lng, $photoBase64) {
    $db = getDB();
    $now = date('H:i:s');
    $record = getTodayAttendance($userId);
    if (!$record) return false;
    $photo = savePhoto($photoBase64, 'checkout');
    if ($photo === null) return false;
    $stmt = $db->prepare("UPDATE attendance SET checkout_time=?, checkout_lat=?, checkout_lng=?, checkout_photo=? WHERE id=?");
    $stmt->execute([$now, $lat, $lng, $photo, $record['id']]);
    return (int)$record['id'] ?: true; // true fallback if id is 0 but update succeeded
}

function otCheckIn($userId, $lat, $lng, $photoBase64) {
    $db = getDB();
    $now = date('H:i:s');
    $record = getTodayAttendance($userId);
    if (!$record) return false;
    $photo = savePhoto($photoBase64, 'ot_checkin');
    if ($photo === null) return false;
    $stmt = $db->prepare("UPDATE attendance SET ot_checkin_time=?, ot_checkin_lat=?, ot_checkin_lng=?, ot_checkin_photo=? WHERE id=?");
    $stmt->execute([$now, $lat, $lng, $photo, $record['id']]);
    return (int)$record['id'] ?: true;
}

function otCheckOut($userId, $lat, $lng, $photoBase64) {
    $db = getDB();
    $now = date('H:i:s');
    $record = getTodayAttendance($userId);
    if (!$record) return false;
    $photo = savePhoto($photoBase64, 'ot_checkout');
    if ($photo === null) return false;
    $stmt = $db->prepare("UPDATE attendance SET ot_checkout_time=?, ot_checkout_lat=?, ot_checkout_lng=?, ot_checkout_photo=? WHERE id=?");
    $stmt->execute([$now, $lat, $lng, $photo, $record['id']]);
    return (int)$record['id'] ?: true;
}

function getDashboardStats() {
    $db = getDB();
    $today = date('Y-m-d');
    $month = date('Y-m');
    $stats = [];
    $stats['total_users']   = $db->query("SELECT COUNT(*) FROM users WHERE role='user'")->fetchColumn();
    $stats['present_today'] = $db->query("SELECT COUNT(*) FROM attendance WHERE work_date='$today' AND checkin_time IS NOT NULL")->fetchColumn();
    $stats['absent_today']  = $stats['total_users'] - $stats['present_today'];
    $stats['ot_today']      = $db->query("SELECT COUNT(*) FROM attendance WHERE work_date='$today' AND ot_checkin_time IS NOT NULL")->fetchColumn();
    $stats['month_records'] = $db->query("SELECT COUNT(*) FROM attendance WHERE work_date LIKE '$month%'")->fetchColumn();
    return $stats;
}

function computeWorkHours($checkin, $checkout) {
    if (!$checkin || !$checkout) return null;
    $ci = strtotime($checkin);
    $co = strtotime($checkout);
    if ($co < $ci) return null;
    $diff = $co - $ci;
    $h = floor($diff / 3600);
    $m = floor(($diff % 3600) / 60);
    return sprintf('%dh %02dm', $h, $m);
}

// ============================================================
// PASSWORD RESET
// ============================================================

function createResetToken($email) {
    $db = getDB();
    $user = getUserByEmail($email);
    if (!$user) return false;
    $token = bin2hex(random_bytes(32));
    $db->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);
    $stmt = $db->prepare("INSERT INTO password_resets (email, token, created_at) VALUES (?, ?, NOW()) RETURNING id");
    $stmt->execute([$email, $token]);
    return $token;
}

function validateResetToken($token) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM password_resets WHERE token = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->execute([$token]);
    return $stmt->fetch();
}

function resetPassword($token, $newPassword) {
    $db = getDB();
    $reset = validateResetToken($token);
    if (!$reset) return false;
    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
    $db->prepare("UPDATE users SET password = ? WHERE email = ?")->execute([$hash, $reset['email']]);
    $db->prepare("DELETE FROM password_resets WHERE token = ?")->execute([$token]);
    return true;
}

// ============================================================
// UTILITIES
// ============================================================


function flash($msg, $type = 'success') {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function formatTime($t) {
    return $t ? date('h:i A', strtotime($t)) : '—';
}

function formatDate($d) {
    return $d ? date('D, d M Y', strtotime($d)) : '—';
}

