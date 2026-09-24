const http = require('http');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const db = require('./db');

const PORT = process.env.PORT || 3000;
const ROOT = __dirname;
const UPLOAD_DIR = path.join(ROOT, 'assets', 'uploads');

if (!fs.existsSync(UPLOAD_DIR)) {
  fs.mkdirSync(UPLOAD_DIR, { recursive: true });
}

const MIME_TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.webp': 'image/webp',
  '.gif': 'image/gif',
  '.svg': 'image/svg+xml',
  '.ico': 'image/x-icon',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.ttf': 'font/ttf'
};

// Bản đồ theo dõi Brute-force đăng nhập theo IP
const loginAttempts = new Map();

function getClientIp(req) {
  return req.headers['x-forwarded-for'] || req.socket.remoteAddress || 'unknown';
}

function checkRateLimit(ip) {
  const attempt = loginAttempts.get(ip);
  if (!attempt) return { allowed: true };
  if (attempt.lockedUntil && attempt.lockedUntil > Date.now()) {
    const remainingMins = Math.ceil((attempt.lockedUntil - Date.now()) / 60000);
    return { allowed: false, remainingMins };
  }
  if (attempt.lockedUntil && attempt.lockedUntil <= Date.now()) {
    loginAttempts.delete(ip);
    return { allowed: true };
  }
  return { allowed: true };
}

function recordFailedLogin(ip) {
  const attempt = loginAttempts.get(ip) || { count: 0 };
  attempt.count += 1;
  if (attempt.count >= 5) {
    attempt.lockedUntil = Date.now() + 15 * 60 * 1000; // Khóa 15 phút
  }
  loginAttempts.set(ip, attempt);
  return attempt;
}

function recordSuccessfulLogin(ip) {
  loginAttempts.delete(ip);
}

// Kiểm tra quyền: n8n (qua x-api-key) hoặc Admin (qua Session Token lưu trong SQLite)
function authenticateRequest(req) {
  // 1. Kiểm tra x-api-key cho n8n
  const currentApiKey = db.getSetting('n8n_api_key');
  const reqApiKey = req.headers['x-api-key'];
  if (reqApiKey && reqApiKey === currentApiKey) {
    return { authenticated: true, role: 'n8n' };
  }

  // 2. Kiểm tra Bearer Token cho Admin
  const authHeader = req.headers['authorization'];
  if (authHeader && authHeader.startsWith('Bearer ')) {
    const token = authHeader.substring(7).trim();
    
    // Kiểm tra session token trong SQLite
    const adminSession = db.validateSession(token);
    if (adminSession) {
      return { authenticated: true, role: 'admin', admin: adminSession, token };
    }

    // Nếu token trùng với n8n API Key
    if (token === currentApiKey) {
      return { authenticated: true, role: 'n8n' };
    }
  }

  return { authenticated: false };
}

// Hàm đọc body JSON từ request (xử lý an toàn UTF-8 đa byte)
function parseJsonBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let totalLength = 0;
    req.on('data', chunk => {
      chunks.push(chunk);
      totalLength += chunk.length;
      if (totalLength > 20 * 1024 * 1024) { // giới hạn 20MB
        reject(new Error('Payload too large'));
      }
    });
    req.on('end', () => {
      if (chunks.length === 0) return resolve({});
      try {
        const rawBody = Buffer.concat(chunks).toString('utf-8');
        if (!rawBody.trim()) return resolve({});
        resolve(JSON.parse(rawBody));
      } catch (err) {
        reject(new Error('Invalid JSON format: ' + err.message));
      }
    });
    req.on('error', reject);
  });
}

// Trả về JSON Response có CORS
function sendJson(res, statusCode, data) {
  res.writeHead(statusCode, {
    'Content-Type': 'application/json; charset=utf-8',
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE, OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type, Authorization, x-api-key'
  });
  res.end(JSON.stringify(data));
}

// Danh sách clients đang kết nối SSE để Live Reload
let sseClients = [];

const LIVE_RELOAD_SCRIPT = `
<!-- Live Reload Script -->
<script>
  (function() {
    const es = new EventSource('/__livereload');
    es.onmessage = function(e) {
      if (e.data === 'reload') {
        console.log('[LiveReload] Phát hiện thay đổi, đang làm mới trang...');
        location.reload();
      }
    };
  })();
</script>
`;

const server = http.createServer(async (req, res) => {
  // Bật CORS cho tất cả OPTIONS request (Preflight)
  if (req.method === 'OPTIONS') {
    res.writeHead(204, {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type, Authorization, x-api-key',
      'Access-Control-Max-Age': '86400'
    });
    res.end();
    return;
  }

  // SSE Live Reload Endpoint
  if (req.url === '/__livereload') {
    res.writeHead(200, {
      'Content-Type': 'text/event-stream',
      'Cache-Control': 'no-cache',
      'Connection': 'keep-alive'
    });
    res.write('\n');
    sseClients.push(res);
    req.on('close', () => {
      sseClients = sseClients.filter(c => c !== res);
    });
    return;
  }

  const parsedUrl = new URL(req.url, `http://${req.headers.host || 'localhost:3000'}`);
  const pathname = parsedUrl.pathname;

  // ==========================================
  // ===== HỆ THỐNG REST API /api/... =====
  // ==========================================

  // ==========================================
  // ===== HỆ THỐNG XÁC THỰC ADMIN /api/auth/ =====
  // ==========================================

  // 1.1. Đăng nhập Admin: POST /api/auth/login
  if (pathname === '/api/auth/login' && req.method === 'POST') {
    const ip = getClientIp(req);
    const rateCheck = checkRateLimit(ip);
    if (!rateCheck.allowed) {
      return sendJson(res, 429, {
        success: false,
        error: `Bạn đã nhập sai quá nhiều lần. Vui lòng thử lại sau ${rateCheck.remainingMins} phút để bảo vệ hệ thống!`
      });
    }

    try {
      const body = await parseJsonBody(req);
      const username = body.username ? String(body.username).trim() : '';
      const password = body.password ? String(body.password) : '';

      if (!username || !password) {
        return sendJson(res, 400, { success: false, error: 'Vui lòng nhập đầy đủ Tên đăng nhập và Mật khẩu!' });
      }

      const admin = db.verifyAdmin(username, password);
      if (admin) {
        recordSuccessfulLogin(ip);
        const session = db.createSession(admin.id, admin.username);
        const apiKey = db.getSetting('n8n_api_key');
        console.log(`[Auth] 🟢 Admin "${admin.username}" đã đăng nhập thành công từ IP: ${ip}`);
        return sendJson(res, 200, {
          success: true,
          token: session.token,
          admin: {
            id: admin.id,
            username: admin.username,
            name: admin.name
          },
          apiKey,
          message: 'Đăng nhập Quản trị viên thành công!'
        });
      } else {
        const attempt = recordFailedLogin(ip);
        const remaining = Math.max(0, 5 - attempt.count);
        console.warn(`[Auth] 🔴 Đăng nhập thất bại từ IP ${ip} (Thử sai lần ${attempt.count}/5)`);
        return sendJson(res, 401, {
          success: false,
          error: remaining > 0 
            ? `Tên đăng nhập hoặc mật khẩu không chính xác! (Còn ${remaining} lần thử trước khi bị khóa tạm thời)`
            : 'Tài khoản đã bị tạm khóa 15 phút do nhập sai quá 5 lần!'
        });
      }
    } catch (e) {
      return sendJson(res, 400, { success: false, error: e.message });
    }
  }

  // 1.2. Kiểm tra phiên đăng nhập hiện tại: GET /api/auth/me
  if (pathname === '/api/auth/me' && req.method === 'GET') {
    const auth = authenticateRequest(req);
    if (!auth.authenticated || auth.role !== 'admin') {
      return sendJson(res, 401, { success: false, error: 'Chưa đăng nhập hoặc phiên đã hết hạn' });
    }
    return sendJson(res, 200, {
      success: true,
      admin: auth.admin,
      apiKey: db.getSetting('n8n_api_key')
    });
  }

  // 1.3. Đăng xuất: POST /api/auth/logout
  if (pathname === '/api/auth/logout' && req.method === 'POST') {
    const auth = authenticateRequest(req);
    if (auth.authenticated && auth.token) {
      db.revokeSession(auth.token);
    }
    return sendJson(res, 200, { success: true, message: 'Đã đăng xuất an toàn' });
  }

  // 1.4. Đổi mật khẩu Admin: POST /api/auth/change-password
  if (pathname === '/api/auth/change-password' && req.method === 'POST') {
    const auth = authenticateRequest(req);
    if (!auth.authenticated || auth.role !== 'admin') {
      return sendJson(res, 401, { success: false, error: 'Chỉ Admin mới có quyền đổi mật khẩu!' });
    }

    try {
      const body = await parseJsonBody(req);
      const { oldPassword, newPassword } = body;
      const result = db.changeAdminPassword(auth.admin.id, oldPassword, newPassword);
      if (result.success) {
        // Tạo ngay session mới để admin không phải đăng nhập lại
        const newSession = db.createSession(auth.admin.id, auth.admin.username);
        return sendJson(res, 200, {
          success: true,
          token: newSession.token,
          message: 'Đổi mật khẩu thành công! Các thiết bị khác đã được đăng xuất.'
        });
      } else {
        return sendJson(res, 400, { success: false, error: result.error });
      }
    } catch (e) {
      return sendJson(res, 500, { success: false, error: e.message });
    }
  }

  // 1.5. Cập nhật hồ sơ Admin: POST /api/auth/profile
  if (pathname === '/api/auth/profile' && req.method === 'POST') {
    const auth = authenticateRequest(req);
    if (!auth.authenticated || auth.role !== 'admin') {
      return sendJson(res, 401, { success: false, error: 'Yêu cầu quyền Admin' });
    }

    try {
      const body = await parseJsonBody(req);
      const result = db.updateAdminProfile(auth.admin.id, {
        username: body.username || auth.admin.username,
        name: body.name || auth.admin.name
      });
      return sendJson(res, result.success ? 200 : 400, result);
    } catch (e) {
      return sendJson(res, 500, { success: false, error: e.message });
    }
  }

  // 2. Lấy & Quản lý Cấu hình API Key: /api/settings
  if (pathname === '/api/settings') {
    if (req.method === 'GET') {
      const auth = authenticateRequest(req);
      if (!auth.authenticated) {
        return sendJson(res, 401, { success: false, error: 'Yêu cầu quyền Quản trị hoặc x-api-key' });
      }
      return sendJson(res, 200, {
        success: true,
        apiKey: db.getSetting('n8n_api_key'),
        webhookUrl: `http://${req.headers.host || 'localhost:3000'}/api/posts`
      });
    }

    if (req.method === 'POST') {
      const auth = authenticateRequest(req);
      if (!auth.authenticated) {
        return sendJson(res, 401, { success: false, error: 'Yêu cầu quyền Quản trị' });
      }
      const newApiKey = 'tbn_n8n_' + crypto.randomBytes(12).toString('hex');
      db.setSetting('n8n_api_key', newApiKey);
      return sendJson(res, 200, {
        success: true,
        apiKey: newApiKey,
        message: 'Đã tạo API Key mới thành công'
      });
    }
  }

  // 3. Upload File / Ảnh (Hỗ trợ Base64 từ n8n hoặc Admin): POST /api/upload
  if (pathname === '/api/upload' && req.method === 'POST') {
    const auth = authenticateRequest(req);
    if (!auth.authenticated) {
      return sendJson(res, 401, { success: false, error: 'Cần Header x-api-key hoặc Bearer token để upload ảnh' });
    }
    try {
      const body = await parseJsonBody(req);
      if (!body.imageBase64) {
        return sendJson(res, 400, { success: false, error: 'Vui lòng truyền imageBase64' });
      }

      // Xử lý chuỗi Base64
      let base64Data = body.imageBase64;
      let ext = '.jpg';
      if (base64Data.startsWith('data:image/')) {
        const mime = base64Data.substring(5, base64Data.indexOf(';'));
        if (mime === 'image/png') ext = '.png';
        else if (mime === 'image/webp') ext = '.webp';
        else if (mime === 'image/gif') ext = '.gif';
        base64Data = base64Data.replace(/^data:image\/\w+;base64,/, '');
      }

      const filename = `img_${Date.now()}_${crypto.randomBytes(4).toString('hex')}${ext}`;
      const filePath = path.join(UPLOAD_DIR, filename);
      fs.writeFileSync(filePath, Buffer.from(base64Data, 'base64'));

      const fileUrl = `/assets/uploads/${filename}`;
      return sendJson(res, 201, {
        success: true,
        url: fileUrl,
        fullUrl: `http://${req.headers.host || 'localhost:3000'}${fileUrl}`
      });
    } catch (err) {
      return sendJson(res, 500, { success: false, error: err.message });
    }
  }

  // 3.5. TỰ ĐỘNG HÓA DEPLOY TỪ GITHUB WEBHOOK: /api/webhook/deploy
  if (pathname === '/api/webhook/deploy' && (req.method === 'POST' || req.method === 'GET')) {
    const { exec } = require('child_process');
    console.log('[Webhook] Nhận tín hiệu từ GitHub, đang tự động chạy git pull...');

    exec('git pull origin main', { cwd: ROOT }, (err, stdout, stderr) => {
      if (err) {
        console.error('[Webhook] Lỗi khi kéo code:', err.message);
        return sendJson(res, 500, { success: false, error: err.message, stderr });
      }
      console.log('[Webhook] Đã kéo code thành công:\n', stdout);

      // Báo cho Phusion Passenger trên cPanel restart nếu cần
      try {
        const tmpDir = path.join(ROOT, 'tmp');
        if (!fs.existsSync(tmpDir)) fs.mkdirSync(tmpDir, { recursive: true });
        fs.writeFileSync(path.join(tmpDir, 'restart.txt'), String(Date.now()));
      } catch (e) {}

      return sendJson(res, 200, {
        success: true,
        message: 'Hosting đã tự động cập nhật code mới nhất từ GitHub!',
        output: stdout
      });
    });
    return;
  }

  // 4. API BÀI VIẾT: /api/posts
  if (pathname === '/api/posts' || pathname.startsWith('/api/posts/')) {
    const subPath = pathname.replace('/api/posts', '');

    // 4.1. Lấy danh sách bài viết: GET /api/posts
    if (pathname === '/api/posts' && req.method === 'GET') {
      const category = parsedUrl.searchParams.get('category');
      const search = parsedUrl.searchParams.get('search');
      const status = parsedUrl.searchParams.get('status') || 'published';
      const limit = parsedUrl.searchParams.get('limit') || 50;
      const offset = parsedUrl.searchParams.get('offset') || 0;

      // Nếu có auth admin thì xem được cả draft
      const auth = authenticateRequest(req);
      const queryStatus = (auth.authenticated && parsedUrl.searchParams.get('all') === 'true') ? null : status;

      const posts = db.getAllPosts({ category, search, status: queryStatus, limit, offset });
      return sendJson(res, 200, {
        success: true,
        count: posts.length,
        posts
      });
    }

    // 4.2. ĐĂNG BÀI MỚI (TỪ N8N HOẶC CMS): POST /api/posts
    if (pathname === '/api/posts' && req.method === 'POST') {
      const auth = authenticateRequest(req);
      if (!auth.authenticated) {
        return sendJson(res, 401, {
          success: false,
          error: 'Xác thực thất bại! Hãy truyền Header x-api-key hoặc Authorization: Bearer <TOKEN>'
        });
      }

      try {
        const body = await parseJsonBody(req);
        if (!body.title || !body.content) {
          return sendJson(res, 400, {
            success: false,
            error: 'Thiếu trường bắt buộc: "title" và "content" là bắt buộc!'
          });
        }

        const source = auth.role === 'n8n' ? 'n8n' : (body.source || 'cms');
        const newPost = db.createPost({
          title: body.title,
          slug: body.slug,
          excerpt: body.excerpt || '',
          content: body.content,
          category: body.category || 'Performance Ads',
          cover_image: body.cover_image || body.coverImage || 'assets/images/portfolio-banner.jpg',
          author: body.author || 'Trương Bảo Ngọc',
          read_time: body.read_time || body.readTime || '5 phút đọc',
          tags: body.tags || '',
          status: body.status || 'published',
          source: source
        });

        console.log(`[API] Đã tạo bài viết mới: "${newPost.title}" (Nguồn: ${source})`);

        return sendJson(res, 201, {
          success: true,
          message: 'Tạo bài viết thành công!',
          post: newPost,
          postUrl: `/blog-detail.html?slug=${newPost.slug}`,
          fullPostUrl: `http://${req.headers.host || 'localhost:3000'}/blog-detail.html?slug=${newPost.slug}`
        });
      } catch (err) {
        return sendJson(res, 500, { success: false, error: err.message });
      }
    }

    // 4.3. Lấy chi tiết bài viết: GET /api/posts/:slug_or_id
    if (subPath.length > 1 && req.method === 'GET') {
      const param = decodeURIComponent(subPath.substring(1));
      let post = null;
      if (/^\d+$/.test(param)) {
        post = db.getPostById(Number(param));
      }
      if (!post) {
        post = db.getPostBySlug(param);
      }

      if (!post) {
        return sendJson(res, 404, { success: false, error: 'Không tìm thấy bài viết' });
      }

      // Tăng lượt xem nếu không phải admin request
      db.incrementViews(post.id);
      post.views += 1;

      return sendJson(res, 200, { success: true, post });
    }

    // 4.4. Cập nhật bài viết: PUT /api/posts/:id
    if (subPath.length > 1 && req.method === 'PUT') {
      const auth = authenticateRequest(req);
      if (!auth.authenticated) {
        return sendJson(res, 401, { success: false, error: 'Yêu cầu quyền xác thực để chỉnh sửa' });
      }

      const id = Number(subPath.substring(1));
      try {
        const body = await parseJsonBody(req);
        const updated = db.updatePost(id, body);
        if (!updated) {
          return sendJson(res, 404, { success: false, error: 'Không tìm thấy bài viết để cập nhật' });
        }
        return sendJson(res, 200, { success: true, message: 'Đã cập nhật bài viết', post: updated });
      } catch (err) {
        return sendJson(res, 500, { success: false, error: err.message });
      }
    }

    // 4.5. Xóa bài viết: DELETE /api/posts/:id
    if (subPath.length > 1 && req.method === 'DELETE') {
      const auth = authenticateRequest(req);
      if (!auth.authenticated) {
        return sendJson(res, 401, { success: false, error: 'Yêu cầu quyền xác thực để xóa bài' });
      }

      const id = Number(subPath.substring(1));
      const deleted = db.deletePost(id);
      if (!deleted) {
        return sendJson(res, 404, { success: false, error: 'Không tìm thấy bài viết' });
      }
      return sendJson(res, 200, { success: true, message: 'Đã xóa bài viết thành công' });
    }
  }

  // ==========================================
  // ===== PHỤC VỤ STATIC FILE & LIVE RELOAD =====
  // ==========================================
  let reqPath = decodeURI(pathname);
  if (reqPath === '/' || reqPath === '') reqPath = '/index.html';
  if (reqPath === '/admin' || reqPath === '/admin/') reqPath = '/admin.html';

  const filePath = path.join(ROOT, reqPath);

  // Bảo vệ không cho duyệt ra ngoài ROOT
  if (!filePath.startsWith(ROOT)) {
    res.writeHead(403);
    res.end('403 Forbidden');
    return;
  }

  fs.stat(filePath, (err, stats) => {
    if (err || !stats.isFile()) {
      res.writeHead(404, { 'Content-Type': 'text/html; charset=utf-8' });
      res.end('<h1>404 Not Found</h1><p><a href="/">Về trang chủ</a></p>');
      return;
    }

    const ext = path.extname(filePath).toLowerCase();
    const contentType = MIME_TYPES[ext] || 'application/octet-stream';

    fs.readFile(filePath, (err, data) => {
      if (err) {
        res.writeHead(500);
        res.end('500 Server Error');
        return;
      }

      // Tự động chèn script Live Reload cho các trang HTML
      if (ext === '.html') {
        let htmlStr = data.toString('utf-8');
        if (htmlStr.includes('</body>')) {
          htmlStr = htmlStr.replace('</body>', `${LIVE_RELOAD_SCRIPT}</body>`);
        } else {
          htmlStr += LIVE_RELOAD_SCRIPT;
        }
        res.writeHead(200, { 'Content-Type': contentType });
        res.end(htmlStr);
      } else {
        res.writeHead(200, { 'Content-Type': contentType });
        res.end(data);
      }
    });
  });
});

// Lắng nghe thay đổi file tĩnh (ngoại trừ data và uploads) để reload realtime
let reloadTimer = null;
fs.watch(ROOT, { recursive: true }, (eventType, filename) => {
  if (filename &&
      !filename.includes('node_modules') &&
      !filename.includes('.git') &&
      !filename.includes('data') &&
      !filename.includes('uploads') &&
      filename !== 'server.js') {
    clearTimeout(reloadTimer);
    reloadTimer = setTimeout(() => {
      console.log(`[File Changed] ${filename} -> Gửi lệnh reload tới trình duyệt...`);
      sseClients.forEach(client => client.write('data: reload\n\n'));
    }, 150);
  }
});

server.listen(PORT, () => {
  console.log(`\n======================================================`);
  console.log(`🚀 TBN CMS & API SERVER ĐÃ SẴN SÀNG! (CỔNG: ${PORT})`);
  console.log(`📍 Trang chủ:      http://localhost:${PORT}`);
  console.log(`📍 Thư viện Blog:  http://localhost:${PORT}/blog.html`);
  console.log(`📍 Quản trị CMS:   http://localhost:${PORT}/admin`);
  console.log(`🤖 Endpoint n8n:   http://localhost:${PORT}/api/posts`);
  console.log(`🔑 n8n API Key:    ${db.getSetting('n8n_api_key')}`);
  console.log(`⚡ Hỗ trợ LIVE RELOAD & REST API đầy đủ!`);
  console.log(`======================================================\n`);
});
