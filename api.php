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
    @mkdir($dbDir, 0755, true);
}
$dbPath = $dbDir . '/blog.db';

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA journal_mode = WAL;');
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
");

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
    // Lưu mật khẩu kép
    $hash = hash('sha256', 'admin123' . $salt);
    $db->prepare('INSERT INTO admins (username, password_hash, salt, name) VALUES (?, ?, ?, ?)')
       ->execute(['admin', $hash, $salt, 'Trương Bảo Ngọc']);
}

// 2. HELPER FUNCTIONS
function getJsonInput() {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?: [];
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
        $str = preg_replace("/($marks)/i", $nonMark, $str);
    }
    $str = preg_replace('/[^a-z0-9\s-]/', '', $str);
    $str = preg_replace('/[\s-]+/', '-', $str);
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
    
    if (!$username || !$password) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu!']);
        exit;
    }
    
    $stmt = $db->prepare('SELECT * FROM admins WHERE username = ?');
    $stmt->execute([$username]);
    $admin = $stmt->fetch();
    
    $valid = false;
    if ($admin) {
        // Kiểm tra mật khẩu (hỗ trợ cả hash sha256 và mật khẩu mặc định admin123 / Admin@TBN2026!)
        $calcHash = hash('sha256', $password . $admin['salt']);
        if ($calcHash === $admin['password_hash'] || $password === 'admin123' || $password === 'Admin@TBN2026!') {
            $valid = true;
        }
    }
    
    if ($valid) {
        // Tạo session 7 ngày
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
                'name' => $admin['name']
            ],
            'apiKey' => $apiKey,
            'message' => 'Đăng nhập Quản trị viên thành công!'
        ]);
        exit;
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Tên đăng nhập hoặc mật khẩu không chính xác!']);
        exit;
    }
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
    $newPass = (string)($body['newPassword'] ?? '');
    if (strlen($newPass) < 6) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Mật khẩu mới phải có ít nhất 6 ký tự']);
        exit;
    }
    $salt = bin2hex(random_bytes(16));
    $hash = hash('sha256', $newPass . $salt);
    $db->prepare('UPDATE admins SET password_hash = ?, salt = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
       ->execute([$hash, $salt, $auth['admin']['id']]);
    
    $token = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO admin_sessions (token, admin_id, username, expires_at) VALUES (?, ?, ?, ?)')
       ->execute([$token, $auth['admin']['id'], $auth['admin']['username'], time() + (7 * 24 * 3600)]);
    
    echo json_encode(['success' => true, 'token' => $token, 'message' => 'Đổi mật khẩu thành công!']);
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
    $ext = '.jpg';
    if (strpos($b64, 'image/png') !== false) $ext = '.png';
    elseif (strpos($b64, 'image/webp') !== false) $ext = '.webp';
    $b64 = preg_replace('#^data:image/\w+;base64,#i', '', $b64);
    $data = base64_decode($b64);
    
    $uploadDir = __DIR__ . '/assets/uploads';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
    
    $filename = 'img_' . time() . '_' . bin2hex(random_bytes(4)) . $ext;
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
            'postUrl' => "/blog-detail.html?slug={$uniqueSlug}"
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
        $db->prepare('UPDATE posts SET views = views + 1 WHERE id = ?')->execute([$post['id']]);
        $post['views']++;
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
        $db->prepare($sql)->execute($params);
        echo json_encode(['success' => true, 'message' => 'Đã cập nhật bài viết']);
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
