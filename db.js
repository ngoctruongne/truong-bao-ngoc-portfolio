const { DatabaseSync } = require('node:sqlite');
const path = require('path');
const fs = require('fs');
const crypto = require('crypto');

const DATA_DIR = path.join(__dirname, 'data');
if (!fs.existsSync(DATA_DIR)) {
  fs.mkdirSync(DATA_DIR, { recursive: true });
}

const DB_PATH = path.join(DATA_DIR, 'blog.db');
const db = new DatabaseSync(DB_PATH);

// Tối ưu hóa hiệu năng SQLite
db.exec('PRAGMA journal_mode = WAL;');
db.exec('PRAGMA synchronous = NORMAL;');
db.exec('PRAGMA cache_size = 10000;');
db.exec('PRAGMA temp_store = MEMORY;');
db.exec('PRAGMA busy_timeout = 5000;');
db.exec('PRAGMA foreign_keys = ON;');

// Khởi tạo bảng dữ liệu
db.exec(`
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
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
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

  -- CHỈ MỤC TỐI ƯU HÓA HIỆU NĂNG TRUY VẤN
  CREATE INDEX IF NOT EXISTS idx_posts_status_created ON posts(status, created_at DESC);
  CREATE INDEX IF NOT EXISTS idx_posts_category ON posts(category);
  CREATE INDEX IF NOT EXISTS idx_login_attempts_lookup ON login_attempts(ip, username);
  CREATE INDEX IF NOT EXISTS idx_login_attempts_time ON login_attempts(last_attempt);
  CREATE INDEX IF NOT EXISTS idx_admin_sessions_exp ON admin_sessions(expires_at);
  CREATE INDEX IF NOT EXISTS idx_two_factor_pending_exp ON two_factor_pending(expires_at);
`);

// Tự động nâng cấp bảng admins hỗ trợ 2FA
try {
  const cols = db.prepare("PRAGMA table_info(admins)").all().map(c => c.name);
  if (!cols.includes('two_factor_enabled')) {
    db.exec("ALTER TABLE admins ADD COLUMN two_factor_enabled INTEGER DEFAULT 0");
  }
  if (!cols.includes('two_factor_secret')) {
    db.exec("ALTER TABLE admins ADD COLUMN two_factor_secret TEXT DEFAULT NULL");
  }
  if (!cols.includes('two_factor_backup_codes')) {
    db.exec("ALTER TABLE admins ADD COLUMN two_factor_backup_codes TEXT DEFAULT NULL");
  }
  const pcols = db.prepare("PRAGMA table_info(two_factor_pending)").all().map(c => c.name);
  if (!pcols.includes('attempts')) {
    db.exec("ALTER TABLE two_factor_pending ADD COLUMN attempts INTEGER DEFAULT 0");
  }
} catch (err) {}

// Hàm tạo Slug chuẩn tiếng Việt
function generateSlug(text) {
  return text
    .toString()
    .toLowerCase()
    .trim()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '') // Xóa dấu tiếng Việt
    .replace(/đ/g, 'd')
    .replace(/Đ/g, 'd')
    .replace(/[^a-z0-9\s-]/g, '') // Xóa ký tự lạ
    .replace(/[\s_-]+/g, '-')     // Đổi khoảng trắng thành gạch nối
    .replace(/^-+|-+$/g, '');     // Xóa gạch nối đầu/cuối
}

// Hàm Hash mật khẩu với Salt sử dụng Scrypt (chuẩn mã hóa mạnh)
function hashPassword(password, salt) {
  return crypto.scryptSync(password, salt, 64).toString('hex');
}

// Khởi tạo dữ liệu mẫu nếu bảng rỗng
function initDefaultData() {
  // 1. Kiểm tra API Key cho n8n
  const getApiKeyStmt = db.prepare('SELECT value FROM settings WHERE key = ?');
  const apiKeyRow = getApiKeyStmt.get('n8n_api_key');
  if (!apiKeyRow) {
    const defaultApiKey = 'tbn_n8n_sec_8f93e1a7b4c2';
    db.prepare('INSERT INTO settings (key, value) VALUES (?, ?)').run('n8n_api_key', defaultApiKey);
    console.log('[Database] Đã tạo n8n API Key mặc định: ' + defaultApiKey);
  }

  // 2. Khởi tạo tài khoản Admin đầu tiên nếu chưa có
  const adminCount = db.prepare('SELECT COUNT(*) as count FROM admins').get();
  if (adminCount.count === 0) {
    const salt = crypto.randomBytes(16).toString('hex');
    const defaultPassword = 'Admin@TBN2026!';
    const hash = hashPassword(defaultPassword, salt);
    db.prepare(`
      INSERT INTO admins (username, password_hash, salt, name)
      VALUES (?, ?, ?, ?)
    `).run('admin', hash, salt, 'Trương Bảo Ngọc');
    console.log('\n======================================================');
    console.log('[Database] 🛡️ ĐÃ TẠO TÀI KHOẢN ADMIN BẢO MẬT:');
    console.log('            👉 Username: admin');
    console.log('            👉 Password: ' + defaultPassword);
    console.log('======================================================\n');
  }

  // 3. Kiểm tra bài viết
  const countRow = db.prepare('SELECT COUNT(*) as count FROM posts').get();
  if (countRow.count === 0) {
    console.log('[Database] Đang khởi tạo bài viết mẫu ban đầu...');
    
    const samplePosts = [
      {
        title: 'Chiến Lược Tối Ưu ROAS Meta Ads & Google CBO Trong Năm 2026',
        slug: 'chien-luoc-toi-uu-roas-meta-ads-google-cbo-2026',
        excerpt: 'Phân tích chi tiết quy trình A/B testing sáng tạo đa biến, kỹ thuật phân bổ ngân sách thông minh và đo lường đa kênh để tăng trưởng doanh số 3.5x mà không bị gãy hiệu quả.',
        content: `
<h2>1. Bối cảnh thị trường quảng cáo số năm 2026</h2>
<p>Chi phí CPM liên tục tăng cao do sự cạnh tranh gay gắt trên các nền tảng Meta Ads, Google Ads và TikTok Shop. Các doanh nghiệp áp dụng mô hình phân bổ ngân sách thủ công truyền thống đang dần mất đi lợi thế cạnh tranh trước những thuật toán máy học phân tích hành vi người dùng thời gian thực.</p>

<h2>2. Cấu trúc chiến dịch chuẩn phễu O2O (Online to Offline)</h2>
<p>Một chiến dịch Performance Marketing hiệu quả không chỉ dừng lại ở tỷ lệ nhấp chuột (CTR) hay chi phí trên mỗi lượt nhấp (CPC). Trọng tâm là <b>Giá trị đơn hàng trung bình (AOV)</b> và <b>Tỷ lệ hoàn vốn chi tiêu quảng cáo (ROAS)</b> thực tế thu về.</p>
<div class="note-box">
  <strong>Ghi chú thực chiến:</strong> Luôn duy trì tỷ lệ phân bổ ngân sách 70% cho nhóm khách hàng lạnh (Prospecting) với định dạng Video ngắn chạm đúng nỗi đau, 20% cho nhóm cân nhắc (Retargeting) và 10% cho nhóm giữ chân (Retention).
</div>

<h2>3. Ma trận A/B Testing sáng tạo đa biến (Dynamic Creative Testing)</h2>
<p>Để duy trì ROAS ổn định trên mức 350%, hệ thống quảng cáo cần được cấp nguyên liệu sáng tạo liên tục:</p>
<ul>
  <li><b>3s Hook đầu tiên:</b> Thử nghiệm 5 góc tiếp cận (Đánh vào nỗi đau, con số giật mình, phản trực giác, phỏng vấn thực tế).</li>
  <li><b>Thân video (Body Narrative):</b> Trình diễn tính năng thực tế, feedback khách hàng và minh chứng kết quả Sell-out tại quầy kệ.</li>
  <li><b>Call to Action (CTA):</b> Khuyến mãi có giới hạn hoặc hướng dẫn nhận quà trực tiếp tại điểm bán đại lý gần nhất.</li>
</ul>

<h2>4. Kết luận & Hành động cụ thể</h2>
<p>Tối ưu hóa không phải là một công việc làm một lần rồi thôi. Đó là chu trình đo lường - phân tích - tinh chỉnh liên tục mỗi ngày dựa trên số liệu thực tế được tự động đồng bộ về Looker Studio.</p>
        `,
        category: 'Performance Ads',
        cover_image: 'assets/images/portfolio-banner.jpg',
        author: 'Trương Bảo Ngọc',
        read_time: '6 phút đọc',
        tags: 'Performance, Meta Ads, ROAS, Google CBO',
        status: 'published',
        source: 'cms',
        views: 142
      },
      {
        title: 'Xây Dựng Nhận Diện Thương Hiệu Đồng Bộ Từ Digital Đến 200+ Điểm Bán Lẻ',
        slug: 'xay-dung-nhan-dien-thuong-hieu-dong-bo-200-diem-ban-le',
        excerpt: 'Kinh nghiệm triển khai chiến dịch kích hoạt thương hiệu kết hợp bộ nhận diện POSM chuẩn mực, giúp tăng tỷ lệ nhận biết thương hiệu lên 94% và thúc đẩy doanh số tại chuỗi siêu thị.',
        content: `
<h2>1. Thách thức phân mảnh thông điệp giữa Online và Offline</h2>
<p>Nhiều nhãn hàng đầu tư hàng trăm triệu vào quảng cáo Facebook và TikTok nhưng khách hàng khi bước vào siêu thị hay đại lý lại không thể nhận diện được sản phẩm do thiết kế bao bì và quầy kệ POSM rời rạc.</p>

<h2>2. Bộ chuẩn Visual Key và kiến trúc POSM chuẩn mực</h2>
<p>Chúng tôi đồng bộ hóa toàn bộ màu sắc chủ đạo, typography và câu khẩu hiệu từ banner website đến standee, wobbler và backdrop tại hơn 200 điểm bán trên toàn quốc.</p>
        `,
        category: 'Brand Strategy',
        cover_image: 'assets/images/profile.jpg',
        author: 'Trương Bảo Ngọc',
        read_time: '5 phút đọc',
        tags: 'Branding, Trade Marketing, Retail, POSM',
        status: 'published',
        source: 'cms',
        views: 89
      },
      {
        title: 'Mô Hình Đo Lường & Báo Cáo Tự Động Hóa Với GA4 & Looker Studio',
        slug: 'mo-hinh-do-luong-bao-cao-tu-dong-hoa-ga4-looker-studio',
        excerpt: 'Cách xây dựng Dashboard theo thời gian thực giúp ban giám đốc nắm bắt chỉ số tài chính sống còn, đo lường chính xác đóng góp của từng kênh marketing.',
        content: `
<h2>1. Tại sao GA4 tiêu chuẩn là chưa đủ?</h2>
<p>Báo cáo mặc định của GA4 thường bị trễ dữ liệu và khó chia sẻ cho các bên liên quan không rành kỹ thuật. Việc kết nối BigQuery và Looker Studio mang lại một giao diện điều hành trực quan 100%.</p>
        `,
        category: 'Data Analytics',
        cover_image: 'assets/images/portfolio-banner.jpg',
        author: 'Trương Bảo Ngọc',
        read_time: '4 phút đọc',
        tags: 'GA4, Looker Studio, Analytics, Dashboard',
        status: 'published',
        source: 'cms',
        views: 115
      }
    ];

    const insertStmt = db.prepare(`
      INSERT INTO posts (title, slug, excerpt, content, category, cover_image, author, read_time, tags, status, source, views)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    `);

    samplePosts.forEach(post => {
      insertStmt.run(
        post.title, post.slug, post.excerpt, post.content,
        post.category, post.cover_image, post.author, post.read_time,
        post.tags, post.status, post.source, post.views
      );
    });
    console.log('[Database] Đã nạp thành công 3 bài viết mẫu vào SQLite!');
  }
}

initDefaultData();

// ===== CÁC HÀM CRUD BÀI VIẾT =====

function getAllPosts({ category, search, status, limit = 50, offset = 0 } = {}) {
  let query = 'SELECT * FROM posts WHERE 1=1';
  const params = [];

  if (status) {
    query += ' AND status = ?';
    params.push(status);
  }

  if (category && category !== 'Tất cả') {
    query += ' AND category = ?';
    params.push(category);
  }

  if (search) {
    query += ' AND (title LIKE ? OR excerpt LIKE ? OR tags LIKE ?)';
    const term = `%${search}%`;
    params.push(term, term, term);
  }

  query += ' ORDER BY created_at DESC LIMIT ? OFFSET ?';
  params.push(Number(limit), Number(offset));

  const stmt = db.prepare(query);
  return stmt.all(...params);
}

function getPostBySlug(slug) {
  const stmt = db.prepare('SELECT * FROM posts WHERE slug = ?');
  return stmt.get(slug);
}

function getPostById(id) {
  const stmt = db.prepare('SELECT * FROM posts WHERE id = ?');
  return stmt.get(id);
}

function createPost(data) {
  let slug = data.slug ? generateSlug(data.slug) : generateSlug(data.title);
  
  // Tránh trùng lặp slug
  let uniqueSlug = slug;
  let counter = 1;
  while (getPostBySlug(uniqueSlug)) {
    uniqueSlug = `${slug}-${counter}`;
    counter++;
  }

  const stmt = db.prepare(`
    INSERT INTO posts (title, slug, excerpt, content, category, cover_image, author, read_time, tags, status, source, views, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
  `);

  const result = stmt.run(
    data.title,
    uniqueSlug,
    data.excerpt || '',
    data.content || '',
    data.category || 'Performance Ads',
    data.cover_image || 'assets/images/portfolio-banner.jpg',
    data.author || 'Trương Bảo Ngọc',
    data.read_time || '5 phút đọc',
    Array.isArray(data.tags) ? data.tags.join(', ') : (data.tags || ''),
    data.status || 'published',
    data.source || 'n8n'
  );

  return getPostById(result.lastInsertRowid);
}

function updatePost(id, data) {
  const existing = getPostById(id);
  if (!existing) return null;

  let slug = existing.slug;
  if (data.slug && data.slug !== existing.slug) {
    slug = generateSlug(data.slug);
    let uniqueSlug = slug;
    let counter = 1;
    while (true) {
      const check = getPostBySlug(uniqueSlug);
      if (!check || check.id === Number(id)) break;
      uniqueSlug = `${slug}-${counter}`;
      counter++;
    }
    slug = uniqueSlug;
  }

  const stmt = db.prepare(`
    UPDATE posts SET
      title = ?,
      slug = ?,
      excerpt = ?,
      content = ?,
      category = ?,
      cover_image = ?,
      author = ?,
      read_time = ?,
      tags = ?,
      status = ?,
      updated_at = CURRENT_TIMESTAMP
    WHERE id = ?
  `);

  stmt.run(
    data.title !== undefined ? data.title : existing.title,
    slug,
    data.excerpt !== undefined ? data.excerpt : existing.excerpt,
    data.content !== undefined ? data.content : existing.content,
    data.category !== undefined ? data.category : existing.category,
    data.cover_image !== undefined ? data.cover_image : existing.cover_image,
    data.author !== undefined ? data.author : existing.author,
    data.read_time !== undefined ? data.read_time : existing.read_time,
    Array.isArray(data.tags) ? data.tags.join(', ') : (data.tags !== undefined ? data.tags : existing.tags),
    data.status !== undefined ? data.status : existing.status,
    id
  );

  return getPostById(id);
}

function deletePost(id) {
  const stmt = db.prepare('DELETE FROM posts WHERE id = ?');
  const result = stmt.run(id);
  return result.changes > 0;
}

function incrementViews(id) {
  const stmt = db.prepare('UPDATE posts SET views = views + 1 WHERE id = ?');
  stmt.run(id);
}

function getSetting(key, defaultValue = null) {
  const stmt = db.prepare('SELECT value FROM settings WHERE key = ?');
  const row = stmt.get(key);
  return row ? row.value : defaultValue;
}

function setSetting(key, value) {
  const stmt = db.prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
  stmt.run(key, String(value));
  return true;
}

// ===== HỆ THỐNG XÁC THỰC ADMIN BẢO MẬT =====

function verifyAdmin(username, password) {
  if (!username || !password) return null;
  const stmt = db.prepare('SELECT * FROM admins WHERE username = ?');
  const admin = stmt.get(username.trim());
  if (!admin) return null;

  try {
    let inputHash;
    if (admin.password_hash.length === 64) {
      inputHash = crypto.createHash('sha256').update(password + admin.salt).digest('hex');
    } else {
      inputHash = hashPassword(password, admin.salt);
    }
    const match = crypto.timingSafeEqual(Buffer.from(inputHash, 'hex'), Buffer.from(admin.password_hash, 'hex'));
    if (!match) return null;

    return {
      id: admin.id,
      username: admin.username,
      name: admin.name,
      two_factor_enabled: Boolean(admin.two_factor_enabled),
      two_factor_secret: admin.two_factor_secret,
      two_factor_backup_codes: admin.two_factor_backup_codes
    };
  } catch (err) {
    return null;
  }
}

function createSession(adminId, username) {
  const token = crypto.randomBytes(32).toString('hex');
  const expiresAt = Date.now() + 7 * 24 * 3600 * 1000; // Phiên đăng nhập 7 ngày
  const stmt = db.prepare(`
    INSERT INTO admin_sessions (token, admin_id, username, expires_at)
    VALUES (?, ?, ?, ?)
  `);
  stmt.run(token, adminId, username, expiresAt);
  return { token, expiresAt };
}

function validateSession(token) {
  if (!token) return null;
  const stmt = db.prepare(`
    SELECT s.token, s.admin_id, s.username, s.expires_at, a.name
    FROM admin_sessions s
    JOIN admins a ON s.admin_id = a.id
    WHERE s.token = ?
  `);
  const session = stmt.get(token);
  if (!session) return null;

  if (session.expires_at < Date.now()) {
    revokeSession(token);
    return null;
  }

  return {
    id: session.admin_id,
    username: session.username,
    name: session.name
  };
}

function revokeSession(token) {
  if (!token) return;
  const stmt = db.prepare('DELETE FROM admin_sessions WHERE token = ?');
  stmt.run(token);
}

function changeAdminPassword(adminId, oldPassword, newPassword) {
  const stmt = db.prepare('SELECT * FROM admins WHERE id = ?');
  const admin = stmt.get(adminId);
  if (!admin) return { success: false, error: 'Không tìm thấy tài khoản quản trị' };

  try {
    const oldHash = hashPassword(oldPassword, admin.salt);
    const match = crypto.timingSafeEqual(Buffer.from(oldHash, 'hex'), Buffer.from(admin.password_hash, 'hex'));
    if (!match) {
      return { success: false, error: 'Mật khẩu cũ không chính xác!' };
    }
  } catch (e) {
    return { success: false, error: 'Xác thực mật khẩu thất bại' };
  }

  if (!newPassword || newPassword.length < 6) {
    return { success: false, error: 'Mật khẩu mới phải có ít nhất 6 ký tự!' };
  }

  const newSalt = crypto.randomBytes(16).toString('hex');
  const newHash = hashPassword(newPassword, newSalt);

  const updateStmt = db.prepare(`
    UPDATE admins SET password_hash = ?, salt = ?, updated_at = CURRENT_TIMESTAMP
    WHERE id = ?
  `);
  updateStmt.run(newHash, newSalt, adminId);

  // Thu hồi các session cũ ngoại trừ session hiện tại (bắt buộc các máy khác đăng nhập lại)
  db.prepare('DELETE FROM admin_sessions WHERE admin_id = ?').run(adminId);

  return { success: true, message: 'Đổi mật khẩu quản trị thành công!' };
}

function updateAdminProfile(adminId, { username, name }) {
  const checkStmt = db.prepare('SELECT id FROM admins WHERE username = ? AND id != ?');
  const existing = checkStmt.get(username.trim(), adminId);
  if (existing) {
    return { success: false, error: 'Tên đăng nhập này đã được sử dụng!' };
  }

  const stmt = db.prepare('UPDATE admins SET username = ?, name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
  stmt.run(username.trim(), name.trim(), adminId);
  return { success: true, message: 'Cập nhật thông tin thành công!' };
}

function getAdminById(id) {
  const stmt = db.prepare('SELECT id, username, name, two_factor_enabled, two_factor_backup_codes, created_at FROM admins WHERE id = ?');
  return stmt.get(id);
}

// ===== HỖ TRỢ XÁC THỰC 2 BƯỚC (2FA - TOTP RFC 6238) =====

function base32Decode(str) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let buffer = 0, bitsLeft = 0;
  const bytes = [];
  for (let c of str.toUpperCase()) {
    if (c === '=' || c === ' ' || c === '-') continue;
    let v = alphabet.indexOf(c);
    if (v === -1) continue;
    buffer = (buffer << 5) | v;
    bitsLeft += 5;
    if (bitsLeft >= 8) {
      bitsLeft -= 8;
      bytes.push((buffer >> bitsLeft) & 0xff);
    }
  }
  return Buffer.from(bytes);
}

function generateTOTPSecret(length = 16) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const bytes = crypto.randomBytes(length);
  let secret = '';
  for (let i = 0; i < length; i++) {
    secret += alphabet[bytes[i] % 32];
  }
  return secret;
}

function generateBackupCodes(count = 5) {
  const codes = [];
  for (let i = 0; i < count; i++) {
    const p1 = crypto.randomBytes(2).toString('hex').toUpperCase();
    const p2 = crypto.randomBytes(2).toString('hex').toUpperCase();
    codes.push(`${p1}-${p2}`);
  }
  return codes;
}

function getTOTP(secret, slice) {
  if (slice === undefined) {
    slice = Math.floor(Date.now() / 30000);
  }
  const key = base32Decode(secret);
  const buf = Buffer.alloc(8);
  buf.writeBigUInt64BE(BigInt(slice));
  const hmac = crypto.createHmac('sha1', key).update(buf).digest();
  const offset = hmac[19] & 0x0f;
  const val = ((hmac[offset] & 0x7f) << 24) |
              ((hmac[offset + 1] & 0xff) << 16) |
              ((hmac[offset + 2] & 0xff) << 8) |
              (hmac[offset + 3] & 0xff);
  return String(val % 1000000).padStart(6, '0');
}

function verifyTOTP(secret, code, discrepancy = 1) {
  const cleanCode = String(code).trim();
  if (cleanCode.length !== 6 || !/^\d{6}$/.test(cleanCode)) return false;
  const currentSlice = Math.floor(Date.now() / 30000);
  for (let i = -discrepancy; i <= discrepancy; i++) {
    const calc = getTOTP(secret, currentSlice + i);
    if (crypto.timingSafeEqual(Buffer.from(calc), Buffer.from(cleanCode))) {
      return true;
    }
  }
  return false;
}

function createPending2FA(adminId, username) {
  const token = crypto.randomBytes(32).toString('hex');
  const expiresAt = Date.now() + 5 * 60 * 1000;
  db.prepare('DELETE FROM two_factor_pending WHERE admin_id = ? OR expires_at < ?').run(adminId, Date.now());
  db.prepare('INSERT INTO two_factor_pending (token, admin_id, username, expires_at) VALUES (?, ?, ?, ?)').run(token, adminId, username, expiresAt);
  return token;
}

function verify2FA(tempToken, code) {
  const pending = db.prepare('SELECT * FROM two_factor_pending WHERE token = ? AND expires_at > ?').get(tempToken, Date.now());
  if (!pending) return { success: false, error: 'Phiên xác thực đã hết hạn hoặc không hợp lệ!' };

  const admin = db.prepare('SELECT * FROM admins WHERE id = ?').get(pending.admin_id);
  if (!admin) return { success: false, error: 'Không tìm thấy tài khoản quản trị' };

  let isValid = false;
  let isBackup = false;

  // Kiểm tra TOTP 6 số
  if (verifyTOTP(admin.two_factor_secret, code)) {
    isValid = true;
  }

  // Kiểm tra mã dự phòng
  if (!isValid && admin.two_factor_backup_codes) {
    try {
      const backupCodes = JSON.parse(admin.two_factor_backup_codes) || [];
      const cleanCode = code.toUpperCase().replace(/\s+/g, '-');
      const idx = backupCodes.indexOf(cleanCode);
      if (idx !== -1) {
        isValid = true;
        isBackup = true;
        backupCodes.splice(idx, 1);
        db.prepare('UPDATE admins SET two_factor_backup_codes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?').run(JSON.stringify(backupCodes), admin.id);
      }
    } catch (e) {}
  }

  if (!isValid) {
    return { success: false, error: 'Mã xác thực 2 lớp hoặc mã dự phòng không chính xác!' };
  }

  db.prepare('DELETE FROM two_factor_pending WHERE token = ?').run(tempToken);
  const session = createSession(admin.id, admin.username);
  return {
    success: true,
    token: session.token,
    admin: {
      id: admin.id,
      username: admin.username,
      name: admin.name,
      two_factor_enabled: true
    },
    isBackup
  };
}

function enable2FA(adminId, secret, code, backupCodes) {
  if (!verifyTOTP(secret, code)) {
    return { success: false, error: 'Mã 6 chữ số không khớp hoặc đã hết hạn!' };
  }
  db.prepare('UPDATE admins SET two_factor_enabled = 1, two_factor_secret = ?, two_factor_backup_codes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
    .run(secret, JSON.stringify(backupCodes), adminId);
  return { success: true, message: 'Đã kích hoạt Xác thực 2 bước (2FA) thành công!' };
}

function disable2FA(adminId, password) {
  const admin = db.prepare('SELECT * FROM admins WHERE id = ?').get(adminId);
  if (!admin) return { success: false, error: 'Không tìm thấy tài khoản' };

  let inputHash;
  if (admin.password_hash.length === 64) {
    inputHash = crypto.createHash('sha256').update(password + admin.salt).digest('hex');
  } else {
    inputHash = hashPassword(password, admin.salt);
  }
  const match = crypto.timingSafeEqual(Buffer.from(inputHash, 'hex'), Buffer.from(admin.password_hash, 'hex'));
  if (!match) return { success: false, error: 'Mật khẩu xác nhận không chính xác!' };

  db.prepare('UPDATE admins SET two_factor_enabled = 0, two_factor_secret = NULL, two_factor_backup_codes = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
    .run(adminId);
  return { success: true, message: 'Đã tắt Xác thực 2 bước.' };
}

module.exports = {
  db,
  generateSlug,
  getAllPosts,
  getPostBySlug,
  getPostById,
  createPost,
  updatePost,
  deletePost,
  incrementViews,
  getSetting,
  setSetting,
  // Admin Auth
  verifyAdmin,
  createSession,
  validateSession,
  revokeSession,
  changeAdminPassword,
  updateAdminProfile,
  getAdminById,
  // 2FA
  generateTOTPSecret,
  generateBackupCodes,
  getTOTP,
  verifyTOTP,
  createPending2FA,
  verify2FA,
  enable2FA,
  disable2FA
};
