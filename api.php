<?php
/**
 * TBN Central CMS - Unified REST API Engine (PHP + SQLite)
 * Phục vụ backend cho Admin CMS, n8n Automation, Blog và Blog-Detail
 */

error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, x-api-key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 1. KẾT NỐI DATABASE SQLITE
$dbDir = __DIR__ . '/data';
if (!is_dir($dbDir)) {
    @mkdir($dbDir, 0777, true);
}
@chmod($dbDir, 0777);
$dbPath = $dbDir . '/blog.db';
if (file_exists($dbPath)) {
    @chmod($dbPath, 0666);
}

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Tối ưu hóa SQLite cho tốc độ cao và giảm I/O đĩa
    $db->exec('PRAGMA journal_mode = WAL;');
    $db->exec('PRAGMA synchronous = NORMAL;');
    $db->exec('PRAGMA cache_size = 10000;');
    $db->exec('PRAGMA temp_store = MEMORY;');
    $db->exec('PRAGMA busy_timeout = 5000;');
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Khởi tạo bảng dữ liệu nếu chưa có
$db->exec("
    CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        salt TEXT NOT NULL,
        name TEXT DEFAULT 'Trương Bảo Ngọc',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS admin_sessions (
        token TEXT PRIMARY KEY,
        admin_id INTEGER NOT NULL,
        username TEXT NOT NULL,
        expires_at INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        slug TEXT NOT NULL UNIQUE,
        excerpt TEXT,
        content TEXT NOT NULL,
        category TEXT DEFAULT 'Performance Ads',
        cover_image TEXT,
        author TEXT DEFAULT 'Trương Bảo Ngọc',
        read_time TEXT DEFAULT '5 phút đọc',
        tags TEXT,
        status TEXT DEFAULT 'published',
        source TEXT DEFAULT 'cms',
        views INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL,
        username TEXT,
        attempts INTEGER DEFAULT 1,
        last_attempt INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS two_factor_pending (
        token TEXT PRIMARY KEY,
        admin_id INTEGER NOT NULL,
        username TEXT NOT NULL,
        expires_at INTEGER NOT NULL
    );

    -- TỐI ƯU HÓA CHỈ MỤC (PERFORMANCE INDEXES) TĂNG TỐC TRUY VẤN
    CREATE INDEX IF NOT EXISTS idx_posts_status_created ON posts(status, created_at DESC);
    CREATE INDEX IF NOT EXISTS idx_posts_category ON posts(category);
    CREATE INDEX IF NOT EXISTS idx_login_attempts_lookup ON login_attempts(ip, username);
    CREATE INDEX IF NOT EXISTS idx_login_attempts_time ON login_attempts(last_attempt);
    CREATE INDEX IF NOT EXISTS idx_admin_sessions_exp ON admin_sessions(expires_at);
    CREATE INDEX IF NOT EXISTS idx_two_factor_pending_exp ON two_factor_pending(expires_at);
");

// Tự động nâng cấp bảng admins hỗ trợ Xác thực 2 bước (2FA)
try {
    $cols = $db->query("PRAGMA table_info(admins)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('two_factor_enabled', $cols)) {
        $db->exec("ALTER TABLE admins ADD COLUMN two_factor_enabled INTEGER DEFAULT 0");
    }
    if (!in_array('two_factor_secret', $cols)) {
        $db->exec("ALTER TABLE admins ADD COLUMN two_factor_secret TEXT DEFAULT NULL");
    }
    if (!in_array('two_factor_backup_codes', $cols)) {
        $db->exec("ALTER TABLE admins ADD COLUMN two_factor_backup_codes TEXT DEFAULT NULL");
    }
    $pcols = $db->query("PRAGMA table_info(two_factor_pending)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('attempts', $pcols)) {
        $db->exec("ALTER TABLE two_factor_pending ADD COLUMN attempts INTEGER DEFAULT 0");
    }
} catch (Exception $e) {}

// Khởi tạo cấu hình mặc định nếu rỗng
$stmt = $db->prepare('SELECT value FROM settings WHERE key = ?');
$stmt->execute(['n8n_api_key']);
if (!$stmt->fetch()) {
    $db->prepare('INSERT INTO settings (key, value) VALUES (?, ?)')->execute(['n8n_api_key', 'tbn_n8n_sec_8f93e1a7b4c2']);
}

// Tạo tài khoản admin nếu chưa có
$adminCount = $db->query('SELECT COUNT(*) as count FROM admins')->fetchColumn();
if ($adminCount == 0) {
    $salt = bin2hex(random_bytes(16));
    // Lưu mật khẩu sha256 + salt
    $hash = hash('sha256', 'admin123' . $salt);
    $db->prepare('INSERT INTO admins (username, password_hash, salt, name) VALUES (?, ?, ?, ?)')
       ->execute(['admin', $hash, $salt, 'Trương Bảo Ngọc']);
}

// 2. HELPER FUNCTIONS
function getJsonInput() {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?: [];
}

function getClientIp() {
    // Luôn ưu tiên REMOTE_ADDR (được thiết lập trực tiếp từ socket TCP của Web Server)
    // để ngăn chặn hoàn toàn tấn công IP Spoofing qua header X-Forwarded-For
    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    
    // Nếu có Cloudflare kết nối
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $cfIp = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        if (filter_var($cfIp, FILTER_VALIDATE_IP)) {
            return $cfIp;
        }
    }
    
    return filter_var($remoteIp, FILTER_VALIDATE_IP) ? $remoteIp : '127.0.0.1';
}

function base32Decode($b32) {
    $b32 = strtoupper(trim($b32));
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $binary = '';
    $buffer = 0;
    $bitsLeft = 0;
    for ($i = 0; $i < strlen($b32); $i++) {
        $char = $b32[$i];
        if ($char === '=' || $char === ' ' || $char === '-') continue;
        $val = strpos($alphabet, $char);
        if ($val === false) continue;
        $buffer = ($buffer << 5) | $val;
        $bitsLeft += 5;
        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $binary .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }
    return $binary;
}

function generateTOTPSecret($length = 16) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    $bytes = random_bytes($length);
    for ($i = 0; $i < $length; $i++) {
        $secret .= $alphabet[ord($bytes[$i]) % 32];
    }
    return $secret;
}

function generateBackupCodes($count = 5) {
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $codes[] = strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));
    }
    return $codes;
}

function getTOTPCode($secret, $timeSlice = null) {
    if ($timeSlice === null) {
        $timeSlice = floor(time() / 30);
    }
    $secretKey = base32Decode($secret);
    $time = pack('N*', 0) . pack('N*', $timeSlice);
    $hash = hash_hmac('sha1', $time, $secretKey, true);
    $offset = ord($hash[19]) & 0x0f;
    $binary = ((ord($hash[$offset]) & 0x7f) << 24) |
              ((ord($hash[$offset + 1]) & 0xff) << 16) |
              ((ord($hash[$offset + 2]) & 0xff) << 8) |
              (ord($hash[$offset + 3]) & 0xff);
    $otp = $binary % 1000000;
    return str_pad((string)$otp, 6, '0', STR_PAD_LEFT);
}

function verifyTOTP($secret, $code, $discrepancy = 1) {
    $code = trim((string)$code);
    if (strlen($code) !== 6 || !ctype_digit($code)) return false;
    $currentTimeSlice = floor(time() / 30);
    for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
        $calc = getTOTPCode($secret, $currentTimeSlice + $i);
        if (hash_equals($calc, $code)) {
            return true;
        }
    }
    return false;
}

function generateSlug($str) {
    $str = mb_strtolower(trim($str), 'UTF-8');
    $vietMap = [
        'a'=>'á|à|ả|ã|ạ|ă|ắ|ặ|ằ|ẳ|ẵ|â|ấ|ầ|ẩ|ẫ|ậ',
        'd'=>'đ',
        'e'=>'é|è|ẻ|ẽ|ẹ|ê|ế|ề|ể|ễ|ệ',
        'i'=>'í|ì|ỉ|ĩ|ị',
        'o'=>'ó|ò|ỏ|õ|ọ|ô|ố|ồ|ổ|ỗ|ộ|ơ|ớ|ờ|ở|ỡ|ợ',
        'u'=>'ú|ù|ủ|ũ|ụ|ư|ứ|ừ|ử|ữ|ự',
        'y'=>'ý|ỳ|ỷ|ỹ|ỵ'
    ];
    foreach($vietMap as $nonMark => $marks) {
        $str = preg_replace("/($marks)/iu", $nonMark, $str);
    }
    $str = preg_replace('/[^a-z0-9\s-]/u', '', $str);
    $str = preg_replace('/[\s-]+/u', '-', $str);
    return trim($str, '-');
}

function authenticate($db) {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    
    // 1. Kiểm tra x-api-key (n8n)
    $apiKey = $headers['x-api-key'] ?? $headers['X-Api-Key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;
    if ($apiKey) {
        $stmt = $db->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute(['n8n_api_key']);
        $saved = $stmt->fetchColumn();
        if ($saved && hash_equals($saved, $apiKey)) {
            return ['authenticated' => true, 'role' => 'n8n'];
        }
    }
    
    // 2. Kiểm tra Bearer token (Admin)
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
        $token = $matches[1];
        $stmt = $db->prepare('
            SELECT s.token, s.admin_id, s.username, s.expires_at, a.name
            FROM admin_sessions s
            JOIN admins a ON s.admin_id = a.id
            WHERE s.token = ?
        ');
        $stmt->execute([$token]);
        $session = $stmt->fetch();
        if ($session && $session['expires_at'] > time()) {
            return [
                'authenticated' => true,
                'role' => 'admin',
                'admin' => [
                    'id' => $session['admin_id'],
                    'username' => $session['username'],
                    'name' => $session['name']
                ],
                'token' => $token
            ];
        }
    }
    
    return ['authenticated' => false];
}

// 3. XÁC ĐỊNH ENDPOINT VÀ METHOD
$endpoint = $_GET['endpoint'] ?? '';
$endpoint = trim(parse_url($endpoint, PHP_URL_PATH), '/');
$method = $_SERVER['REQUEST_METHOD'];

// ==========================================
// 4. ROUTER XỬ LÝ CÁC API
// ==========================================

// 4.1. ĐĂNG NHẬP ADMIN: POST /api/auth/login
if ($endpoint === 'auth/login' && $method === 'POST') {
    $body = getJsonInput();
    $username = trim($body['username'] ?? '');
    $password = (string)($body['password'] ?? '');
    $honeypot = trim($body['website_trap'] ?? '');
    
    // 1. Chống Bot tự động: Honeypot trap
    if (!empty($honeypot)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Phát hiện hành vi bất thường từ bot tự động!']);
        exit;
    }

    if (!$username || !$password) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu!']);
        exit;
    }

    // 2. Chống Dò Mật Khẩu (Brute Force): Kiểm tra Rate Limit theo IP & Username
    $ip = getClientIp();
    $now = time();
    $lockDuration = 900; // Khóa 15 phút nếu sai quá 5 lần
    $maxAttempts = 5;

    $stmt = $db->prepare('SELECT id, attempts, last_attempt FROM login_attempts WHERE ip = ? OR (username = ? AND username != "") ORDER BY attempts DESC LIMIT 1');
    $stmt->execute([$ip, $username]);
    $attemptRecord = $stmt->fetch();
    if ($attemptRecord) {
        $elapsed = $now - $attemptRecord['last_attempt'];
        if ($attemptRecord['attempts'] >= $maxAttempts && $elapsed < $lockDuration) {
            $remainMin = ceil(($lockDuration - $elapsed) / 60);
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'error' => "Tài khoản hoặc IP của bạn đã bị tạm khóa 15 phút do thử đăng nhập sai quá {$maxAttempts} lần! Vui lòng thử lại sau {$remainMin} phút.",
                'locked' => true,
                'waitMinutes' => $remainMin
            ]);
            exit;
        }
        // Nếu đã hết hạn khóa 15 phút thì xóa bản ghi cũ
        if ($elapsed >= $lockDuration) {
            $db->prepare('DELETE FROM login_attempts WHERE id = ?')->execute([$attemptRecord['id']]);
            $attemptRecord = null;
        }
    }

    // 3. Kiểm tra thông tin đăng nhập
    $stmt = $db->prepare('SELECT * FROM admins WHERE username = ?');
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    $valid = false;
    if ($admin) {
        $calcHash = hash('sha256', $password . $admin['salt']);
        if (hash_equals($admin['password_hash'], $calcHash)) {
            $valid = true;
        }
    }

    if (!$valid) {
        // Ghi nhận số lần nhập sai
        $curAttempts = $attemptRecord ? ($attemptRecord['attempts'] + 1) : 1;
        if ($attemptRecord) {
            $db->prepare('UPDATE login_attempts SET attempts = ?, last_attempt = ? WHERE id = ?')
               ->execute([$curAttempts, $now, $attemptRecord['id']]);
        } else {
            $db->prepare('INSERT INTO login_attempts (ip, username, attempts, last_attempt) VALUES (?, ?, ?, ?)')
               ->execute([$ip, $username, 1, $now]);
        }

        $remaining = max(0, $maxAttempts - $curAttempts);
        $errorMsg = 'Tên đăng nhập hoặc mật khẩu không chính xác!';
        if ($remaining > 0) {
            $errorMsg .= " (Cảnh báo: Còn {$remaining} lần thử trước khi bị khóa tạm thời 15 phút)";
        } else {
            $errorMsg = 'Bạn đã nhập sai 5 lần! Tài khoản và IP đã bị khóa tạm thời trong 15 phút để bảo vệ hệ thống.';
        }

        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => $errorMsg,
            'remaining' => $remaining,
            'locked' => ($remaining === 0)
        ]);
        exit;
    }

    // Đăng nhập hợp lệ: Xóa lịch sử lần sai
    $db->prepare('DELETE FROM login_attempts WHERE ip = ? OR username = ?')->execute([$ip, $username]);

    // 4. Kiểm tra Xác thực 2 bước (2FA)
    if (!empty($admin['two_factor_enabled']) && !empty($admin['two_factor_secret'])) {
        // Tạo token tạm thời (5 phút) để xác minh bước 2
        $tempToken = bin2hex(random_bytes(32));
        $db->prepare('DELETE FROM two_factor_pending WHERE admin_id = ? OR expires_at < ?')
           ->execute([$admin['id'], $now]);
        $db->prepare('INSERT INTO two_factor_pending (token, admin_id, username, expires_at) VALUES (?, ?, ?, ?)')
           ->execute([$tempToken, $admin['id'], $admin['username'], $now + 300]);

        echo json_encode([
            'success' => true,
            'require2FA' => true,
            'tempToken' => $tempToken,
            'message' => 'Mật khẩu chính xác! Vui lòng nhập mã xác thực 2 lớp (TOTP) từ ứng dụng Google Authenticator hoặc mã dự phòng.'
        ]);
        exit;
    }

    // Nếu không bật 2FA -> Cấp session 7 ngày
    $token = bin2hex(random_bytes(32));
    $expiresAt = time() + (7 * 24 * 3600);
    $db->prepare('INSERT INTO admin_sessions (token, admin_id, username, expires_at) VALUES (?, ?, ?, ?)')
       ->execute([$token, $admin['id'], $admin['username'], $expiresAt]);

    $apiKeyStmt = $db->prepare('SELECT value FROM settings WHERE key = ?');
    $apiKeyStmt->execute(['n8n_api_key']);
    $apiKey = $apiKeyStmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'token' => $token,
        'admin' => [
            'id' => $admin['id'],
            'username' => $admin['username'],
            'name' => $admin['name'],
            'two_factor_enabled' => false
        ],
        'apiKey' => $apiKey,
        'message' => 'Đăng nhập Quản trị viên thành công!'
    ]);
    exit;
}

// 4.1.2. XÁC MINH BƯỚC 2 (2FA): POST /api/auth/verify-2fa
if ($endpoint === 'auth/verify-2fa' && $method === 'POST') {
    $body = getJsonInput();
    $tempToken = trim($body['tempToken'] ?? '');
    $code = strtoupper(trim($body['code'] ?? ''));

    if (!$tempToken || !$code) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Vui lòng cung cấp mã xác minh 2 lớp!']);
        exit;
    }

    $now = time();
    $stmt = $db->prepare('SELECT * FROM two_factor_pending WHERE token = ? AND expires_at > ?');
    $stmt->execute([$tempToken, $now]);
    $pending = $stmt->fetch();
    if (!$pending) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Phiên xác thực đã hết hạn (quá 5 phút). Vui lòng đăng nhập lại từ đầu!']);
        exit;
    }

    $stmt = $db->prepare('SELECT * FROM admins WHERE id = ?');
    $stmt->execute([$pending['admin_id']]);
    $admin = $stmt->fetch();
    if (!$admin) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Không tìm thấy tài khoản quản trị!']);
        exit;
    }

    $isValid = false;
    $isBackupCode = false;

    // 1. Kiểm tra mã TOTP 6 số
    if (strlen($code) === 6 && ctype_digit($code)) {
        if (verifyTOTP($admin['two_factor_secret'], $code)) {
            $isValid = true;
        }
    }

    // 2. Nếu không khớp TOTP, kiểm tra xem có phải Mã dự phòng (Backup code) không
    if (!$isValid && !empty($admin['two_factor_backup_codes'])) {
        $backupCodes = json_decode($admin['two_factor_backup_codes'], true) ?: [];
        $cleanInputCode = str_replace(' ', '-', $code);
        $foundIndex = array_search($cleanInputCode, $backupCodes);
        if ($foundIndex !== false) {
            $isValid = true;
            $isBackupCode = true;
            // Tiêu hủy mã dự phòng đã sử dụng
            array_splice($backupCodes, $foundIndex, 1);
            $db->prepare('UPDATE admins SET two_factor_backup_codes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
               ->execute([json_encode($backupCodes), $admin['id']]);
        }
    }

    if (!$isValid) {
        $curAttempts = ((int)($pending['attempts'] ?? 0)) + 1;
        if ($curAttempts >= 5) {
            $db->prepare('DELETE FROM two_factor_pending WHERE token = ?')->execute([$tempToken]);
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'error' => 'Bạn đã nhập sai mã xác thực 2 lớp quá 5 lần! Phiên xác thực đã bị hủy để bảo vệ tài khoản. Vui lòng đăng nhập lại từ đầu.',
                'locked' => true
            ]);
            exit;
        }
        $db->prepare('UPDATE two_factor_pending SET attempts = ? WHERE token = ?')->execute([$curAttempts, $tempToken]);
        $remaining = 5 - $curAttempts;

        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => "Mã xác thực 2 lớp hoặc mã dự phòng không chính xác! (Còn {$remaining} lần thử trước khi phiên bị hủy)"
        ]);
        exit;
    }

    // Tiêu hủy pending token
    $db->prepare('DELETE FROM two_factor_pending WHERE token = ?')->execute([$tempToken]);

    // Tạo phiên đăng nhập chính thức 7 ngày
    $token = bin2hex(random_bytes(32));
    $expiresAt = time() + (7 * 24 * 3600);
    $db->prepare('INSERT INTO admin_sessions (token, admin_id, username, expires_at) VALUES (?, ?, ?, ?)')
       ->execute([$token, $admin['id'], $admin['username'], $expiresAt]);

    $apiKeyStmt = $db->prepare('SELECT value FROM settings WHERE key = ?');
    $apiKeyStmt->execute(['n8n_api_key']);
    $apiKey = $apiKeyStmt->fetchColumn();

    $backupWarning = $isBackupCode ? ' (Bạn vừa sử dụng một mã dự phòng khôi phục)' : '';

    echo json_encode([
        'success' => true,
        'token' => $token,
        'admin' => [
            'id' => $admin['id'],
            'username' => $admin['username'],
            'name' => $admin['name'],
            'two_factor_enabled' => true
        ],
        'apiKey' => $apiKey,
        'message' => 'Xác thực 2 lớp thành công! Chào mừng Quản trị viên.' . $backupWarning
    ]);
    exit;
}

// 4.1.3. LẤY TRẠNG THÁI 2FA: GET /api/auth/2fa/status
if ($endpoint === 'auth/2fa/status' && $method === 'GET') {
    $auth = authenticate($db);
    if (!$auth['authenticated'] || $auth['role'] !== 'admin') {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Chưa đăng nhập']);
        exit;
    }
    $stmt = $db->prepare('SELECT two_factor_enabled, two_factor_backup_codes FROM admins WHERE id = ?');
    $stmt->execute([$auth['admin']['id']]);
    $admin = $stmt->fetch();
    $backupCodes = !empty($admin['two_factor_backup_codes']) ? json_decode($admin['two_factor_backup_codes'], true) : [];
    
    echo json_encode([
        'success' => true,
        'enabled' => (bool)($admin['two_factor_enabled'] ?? false),
        'backupCodesCount' => count($backupCodes)
    ]);
    exit;
}

// 4.1.4. KHỞI TẠO CẤU HÌNH 2FA: POST /api/auth/2fa/setup
if ($endpoint === 'auth/2fa/setup' && $method === 'POST') {
    $auth = authenticate($db);
    if (!$auth['authenticated'] || $auth['role'] !== 'admin') {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Chưa đăng nhập']);
        exit;
    }
    $secret = generateTOTPSecret(16);
    $backupCodes = generateBackupCodes(5);
    $username = $auth['admin']['username'];
    $otpauthUrl = "otpauth://totp/TBN%20CMS:" . urlencode($username) . "?secret=" . $secret . "&issuer=" . urlencode("TBN Central CMS");

    echo json_encode([
        'success' => true,
        'secret' => $secret,
        'otpauthUrl' => $otpauthUrl,
        'backupCodes' => $backupCodes
    ]);
    exit;
}

// 4.1.5. KÍCH HOẠT 2FA: POST /api/auth/2fa/enable
if ($endpoint === 'auth/2fa/enable' && $method === 'POST') {
    $auth = authenticate($db);
    if (!$auth['authenticated'] || $auth['role'] !== 'admin') {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Chưa đăng nhập']);
        exit;
    }
    $body = getJsonInput();
    $secret = trim($body['secret'] ?? '');
    $code = trim($body['code'] ?? '');
    $backupCodes = $body['backupCodes'] ?? [];

    if (!$secret || !$code) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Vui lòng cung cấp Secret và mã xác thực 6 số!']);
        exit;
    }

    if (!verifyTOTP($secret, $code)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Mã 6 chữ số từ ứng dụng Authenticator không chính xác hoặc đã hết hạn! Vui lòng kiểm tra lại.']);
        exit;
    }

    // Lưu vào database
    $db->prepare('UPDATE admins SET two_factor_enabled = 1, two_factor_secret = ?, two_factor_backup_codes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
       ->execute([$secret, json_encode($backupCodes), $auth['admin']['id']]);

    echo json_encode([
        'success' => true,
        'message' => 'Kích hoạt Xác thực 2 bước (2FA) thành công! Tài khoản của bạn hiện đã được bảo vệ tối đa.'
    ]);
    exit;
}

// 4.1.6. TẮT 2FA: POST /api/auth/2fa/disable
if ($endpoint === 'auth/2fa/disable' && $method === 'POST') {
    $auth = authenticate($db);
    if (!$auth['authenticated'] || $auth['role'] !== 'admin') {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Chưa đăng nhập']);
        exit;
    }
    $body = getJsonInput();
    $password = (string)($body['password'] ?? '');

    // Xác thực mật khẩu admin trước khi tắt 2FA
    $stmt = $db->prepare('SELECT * FROM admins WHERE id = ?');
    $stmt->execute([$auth['admin']['id']]);
    $admin = $stmt->fetch();

    $calcHash = hash('sha256', $password . $admin['salt']);
    if (!hash_equals($admin['password_hash'], $calcHash)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Mật khẩu xác nhận không chính xác!']);
        exit;
    }

    $db->prepare('UPDATE admins SET two_factor_enabled = 0, two_factor_secret = NULL, two_factor_backup_codes = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
       ->execute([$auth['admin']['id']]);

    echo json_encode([
        'success' => true,
        'message' => 'Đã tắt Xác thực 2 bước (2FA) thành công.'
    ]);
    exit;
}

// 4.1.7. XEM MÃ DỰ PHÒNG: GET /api/auth/2fa/backup-codes
if ($endpoint === 'auth/2fa/backup-codes' && $method === 'GET') {
    $auth = authenticate($db);
    if (!$auth['authenticated'] || $auth['role'] !== 'admin') {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Chưa đăng nhập']);
        exit;
    }
    $stmt = $db->prepare('SELECT two_factor_backup_codes, two_factor_enabled FROM admins WHERE id = ?');
    $stmt->execute([$auth['admin']['id']]);
    $admin = $stmt->fetch();
    $codes = !empty($admin['two_factor_backup_codes']) ? json_decode($admin['two_factor_backup_codes'], true) : [];
    
    echo json_encode([
        'success' => true,
        'enabled' => (bool)$admin['two_factor_enabled'],
        'backupCodes' => $codes
    ]);
    exit;
}

// 4.2. KIỂM TRA PHIÊN: GET /api/auth/me
if ($endpoint === 'auth/me' && $method === 'GET') {
    $auth = authenticate($db);
    if (!$auth['authenticated'] || $auth['role'] !== 'admin') {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Chưa đăng nhập hoặc phiên đã hết hạn']);
        exit;
    }
    $apiKeyStmt = $db->prepare('SELECT value FROM settings WHERE key = ?');
    $apiKeyStmt->execute(['n8n_api_key']);
    $apiKey = $apiKeyStmt->fetchColumn();

    $stmt = $db->prepare('SELECT two_factor_enabled FROM admins WHERE id = ?');
    $stmt->execute([$auth['admin']['id']]);
    $twoFactor = (bool)$stmt->fetchColumn();
    $auth['admin']['two_factor_enabled'] = $twoFactor;

    echo json_encode([
        'success' => true,
        'admin' => $auth['admin'],
        'apiKey' => $apiKey
    ]);
    exit;
}

// 4.3. ĐĂNG XUẤT: POST /api/auth/logout
if ($endpoint === 'auth/logout' && $method === 'POST') {
    $auth = authenticate($db);
    if (!empty($auth['token'])) {
        $db->prepare('DELETE FROM admin_sessions WHERE token = ?')->execute([$auth['token']]);
    }
    echo json_encode(['success' => true, 'message' => 'Đã đăng xuất']);
    exit;
}

// 4.4. ĐỔI MẬT KHẨU: POST /api/auth/change-password
if ($endpoint === 'auth/change-password' && $method === 'POST') {
    $auth = authenticate($db);
    if (!$auth['authenticated'] || $auth['role'] !== 'admin') {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Chỉ Admin mới có quyền đổi mật khẩu!']);
        exit;
    }
    $body = getJsonInput();
    $oldPass = (string)($body['oldPassword'] ?? '');
    $newPass = (string)($body['newPassword'] ?? '');

    // Kiểm tra tài khoản admin hiện tại
    $stmt = $db->prepare('SELECT * FROM admins WHERE id = ?');
    $stmt->execute([$auth['admin']['id']]);
    $admin = $stmt->fetch();
    if (!$admin) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Không tìm thấy tài khoản quản trị!']);
        exit;
    }

    // Xác thực mật khẩu cũ
    $oldHash = hash('sha256', $oldPass . $admin['salt']);
    if (!hash_equals($admin['password_hash'], $oldHash)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Mật khẩu hiện tại không chính xác!']);
        exit;
    }

    if (strlen($newPass) < 6) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Mật khẩu mới phải có ít nhất 6 ký tự']);
        exit;
    }

    $salt = bin2hex(random_bytes(16));
    $hash = hash('sha256', $newPass . $salt);
    $db->prepare('UPDATE admins SET password_hash = ?, salt = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
       ->execute([$hash, $salt, $auth['admin']['id']]);
    
    // Thu hồi toàn bộ session cũ của admin này (buộc các thiết bị khác phải đăng nhập lại với mật khẩu mới)
    $db->prepare('DELETE FROM admin_sessions WHERE admin_id = ?')->execute([$auth['admin']['id']]);

    // Cấp session mới cho thiết bị hiện tại
    $token = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO admin_sessions (token, admin_id, username, expires_at) VALUES (?, ?, ?, ?)')
       ->execute([$token, $auth['admin']['id'], $auth['admin']['username'], time() + (7 * 24 * 3600)]);
    
    echo json_encode(['success' => true, 'token' => $token, 'message' => 'Đổi mật khẩu thành công! Toàn bộ thiết bị khác đã được đăng xuất an toàn.']);
    exit;
}

// 4.5. CẬP NHẬT HỒ SƠ: POST /api/auth/profile
if ($endpoint === 'auth/profile' && $method === 'POST') {
    $auth = authenticate($db);
    if (!$auth['authenticated'] || $auth['role'] !== 'admin') {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Chưa đăng nhập']);
        exit;
    }
    $body = getJsonInput();
    $name = trim($body['name'] ?? $auth['admin']['name']);
    $username = trim($body['username'] ?? $auth['admin']['username']);
    $db->prepare('UPDATE admins SET name = ?, username = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
       ->execute([$name, $username, $auth['admin']['id']]);
    echo json_encode(['success' => true, 'message' => 'Đã cập nhật hồ sơ Admin']);
    exit;
}

// 4.6. CẤU HÌNH API SETTINGS: /api/settings
if ($endpoint === 'settings') {
    $auth = authenticate($db);
    if (!$auth['authenticated']) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Yêu cầu quyền Quản trị']);
        exit;
    }
    if ($method === 'GET') {
        $stmt = $db->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute(['n8n_api_key']);
        $key = $stmt->fetchColumn();
        echo json_encode([
            'success' => true,
            'apiKey' => $key,
            'webhookUrl' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . '/api/posts'
        ]);
        exit;
    }
    if ($method === 'POST') {
        $newKey = 'tbn_n8n_' . bin2hex(random_bytes(12));
        $db->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
           ->execute(['n8n_api_key', $newKey]);
        echo json_encode(['success' => true, 'apiKey' => $newKey, 'message' => 'Đã tạo API Key mới']);
        exit;
    }
}

// 4.7. UPLOAD FILE / ẢNH BASE64: POST /api/upload
if ($endpoint === 'upload' && $method === 'POST') {
    $auth = authenticate($db);
    if (!$auth['authenticated']) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Cần quyền xác thực để upload']);
        exit;
    }
    $body = getJsonInput();
    $b64 = $body['imageBase64'] ?? '';
    if (!$b64) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Thiếu dữ liệu imageBase64']);
        exit;
    }

    // Giới hạn dung lượng chuỗi Base64 tối đa 7MB (~5MB file nhị phân)
    if (strlen($b64) > 7 * 1024 * 1024) {
        http_response_code(413);
        echo json_encode(['success' => false, 'error' => 'Dung lượng ảnh vượt quá giới hạn cho phép (tối đa 5MB)']);
        exit;
    }

    $b64Clean = preg_replace('#^data:image/\w+;base64,#i', '', $b64);
    $data = base64_decode($b64Clean, true);
    if ($data === false || empty($data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Dữ liệu ảnh base64 không hợp lệ']);
        exit;
    }

    // Kiểm tra cấu trúc nhị phân thực tế của ảnh (Magic Bytes)
    $imageInfo = @getimagesizefromstring($data);
    if ($imageInfo === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Tệp tải lên không phải là định dạng hình ảnh hợp lệ']);
        exit;
    }

    $mime = $imageInfo['mime'] ?? '';
    $allowedMimes = [
        'image/jpeg' => '.jpg',
        'image/png'  => '.png',
        'image/webp' => '.webp',
        'image/gif'  => '.gif'
    ];

    if (!isset($allowedMimes[$mime])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Định dạng ảnh không được hỗ trợ. Chỉ chấp nhận JPEG, PNG, WEBP, GIF.']);
        exit;
    }

    $ext = $allowedMimes[$mime];
    $uploadDir = __DIR__ . '/assets/uploads';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
    
    $filename = 'img_' . time() . '_' . bin2hex(random_bytes(6)) . $ext;
    file_put_contents($uploadDir . '/' . $filename, $data);
    $fileUrl = '/assets/uploads/' . $filename;
    
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'url' => $fileUrl,
        'fullUrl' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $fileUrl
    ]);
    exit;
}

// 4.8. API BÀI VIẾT: /api/posts VÀ /api/posts/:id_or_slug
if ($endpoint === 'posts' || strpos($endpoint, 'posts/') === 0) {
    $sub = substr($endpoint, 5); // phần sau 'posts'
    $sub = trim($sub, '/');
    
    // GET /api/posts: Danh sách bài viết
    if ($endpoint === 'posts' && $method === 'GET') {
        $category = $_GET['category'] ?? null;
        $search = $_GET['search'] ?? null;
        $status = $_GET['status'] ?? 'published';
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        
        $auth = authenticate($db);
        $queryStatus = ($auth['authenticated'] && isset($_GET['all']) && $_GET['all'] === 'true') ? null : $status;
        
        $sql = 'SELECT * FROM posts WHERE 1=1';
        $params = [];
        if ($queryStatus) {
            $sql .= ' AND status = ?';
            $params[] = $queryStatus;
        }
        if ($category && $category !== 'all') {
            $sql .= ' AND category = ?';
            $params[] = $category;
        }
        if ($search) {
            $sql .= ' AND (title LIKE ? OR excerpt LIKE ? OR tags LIKE ?)';
            $term = "%{$search}%";
            $params[] = $term; $params[] = $term; $params[] = $term;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $posts = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'count' => count($posts),
            'posts' => $posts
        ]);
        exit;
    }
    
    // POST /api/posts: Tạo bài viết mới (từ CMS hoặc n8n)
    if ($endpoint === 'posts' && $method === 'POST') {
        $auth = authenticate($db);
        if (!$auth['authenticated']) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Xác thực thất bại! Hãy truyền Header x-api-key hoặc Authorization: Bearer <TOKEN>']);
            exit;
        }
        $body = getJsonInput();
        $title = trim($body['title'] ?? '');
        $content = trim($body['content'] ?? '');
        if (!$title || !$content) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Thiếu trường "title" và "content"']);
            exit;
        }
        $slug = !empty($body['slug']) ? generateSlug($body['slug']) : generateSlug($title);
        // Tránh trùng slug
        $uniqueSlug = $slug;
        $counter = 1;
        while (true) {
            $chk = $db->prepare('SELECT id FROM posts WHERE slug = ?');
            $chk->execute([$uniqueSlug]);
            if (!$chk->fetch()) break;
            $uniqueSlug = "{$slug}-{$counter}";
            $counter++;
        }
        
        $source = ($auth['role'] === 'n8n') ? 'n8n' : ($body['source'] ?? 'cms');
        $stmt = $db->prepare('
            INSERT INTO posts (title, slug, excerpt, content, category, cover_image, author, read_time, tags, status, source, views, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ');
        $stmt->execute([
            $title,
            $uniqueSlug,
            $body['excerpt'] ?? '',
            $content,
            $body['category'] ?? 'Performance Ads',
            $body['cover_image'] ?? $body['coverImage'] ?? 'assets/images/portfolio-banner.jpg',
            $body['author'] ?? 'Trương Bảo Ngọc',
            $body['read_time'] ?? $body['readTime'] ?? '5 phút đọc',
            $body['tags'] ?? '',
            $body['status'] ?? 'published',
            $source
        ]);
        $newId = $db->lastInsertId();
        
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Tạo bài viết thành công!',
            'post' => [
                'id' => $newId,
                'title' => $title,
                'slug' => $uniqueSlug
            ],
            'postUrl' => "/blog/{$uniqueSlug}"
        ]);
        exit;
    }
    
    // GET /api/posts/:slug_or_id
    if ($sub && $method === 'GET') {
        $post = null;
        if (is_numeric($sub)) {
            $stmt = $db->prepare('SELECT * FROM posts WHERE id = ?');
            $stmt->execute([(int)$sub]);
            $post = $stmt->fetch();
        }
        if (!$post) {
            $stmt = $db->prepare('SELECT * FROM posts WHERE slug = ?');
            $stmt->execute([$sub]);
            $post = $stmt->fetch();
        }
        if (!$post) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Không tìm thấy bài viết']);
            exit;
        }
        try {
            $db->prepare('UPDATE posts SET views = views + 1 WHERE id = ?')->execute([$post['id']]);
            $post['views']++;
        } catch (Exception $e) {
            // Tiếp tục trả bài viết bình thường nếu không thể ghi tăng view
        }
        echo json_encode(['success' => true, 'post' => $post]);
        exit;
    }
    
    // PUT /api/posts/:id
    if ($sub && is_numeric($sub) && $method === 'PUT') {
        $auth = authenticate($db);
        if (!$auth['authenticated']) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Cần quyền xác thực để chỉnh sửa']);
            exit;
        }
        $id = (int)$sub;
        $body = getJsonInput();
        $fields = [];
        $params = [];
        foreach (['title', 'slug', 'excerpt', 'content', 'category', 'cover_image', 'author', 'read_time', 'tags', 'status'] as $f) {
            if (isset($body[$f])) {
                $fields[] = "{$f} = ?";
                $params[] = $body[$f];
            }
        }
        if (empty($fields)) {
            echo json_encode(['success' => true, 'message' => 'Không có thay đổi']);
            exit;
        }
        $fields[] = 'updated_at = CURRENT_TIMESTAMP';
        $params[] = $id;
        $sql = 'UPDATE posts SET ' . implode(', ', $fields) . ' WHERE id = ?';
        try {
            $db->prepare($sql)->execute($params);
            echo json_encode(['success' => true, 'message' => 'Đã cập nhật bài viết thành công']);
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => 'Đường dẫn (slug) này đã tồn tại ở một bài viết khác! Vui lòng chọn slug khác.']);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Lỗi khi cập nhật cơ sở dữ liệu.']);
            }
        }
        exit;
    }
    
    // DELETE /api/posts/:id
    if ($sub && is_numeric($sub) && $method === 'DELETE') {
        $auth = authenticate($db);
        if (!$auth['authenticated']) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Cần quyền xác thực để xóa']);
            exit;
        }
        $id = (int)$sub;
        $db->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Đã xóa bài viết thành công']);
        exit;
    }
}

// Fallback 404
http_response_code(404);
echo json_encode(['success' => false, 'error' => 'API endpoint not found: ' . $endpoint]);
