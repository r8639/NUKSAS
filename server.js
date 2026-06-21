const express = require('express');
const mysql = require('mysql2');
const cors = require('cors');
const bodyParser = require('body-parser');
const bcrypt = require('bcryptjs');
// nodemailer 已停用（Mock 模式）
const multer = require('multer');
const path = require('path');
const fs = require('fs');
require('dotenv').config();

const app = express();
const PORT = process.env.PORT || 3000;

// 中介層設定
app.use(cors());
app.use(bodyParser.json());
app.use(bodyParser.urlencoded({ extended: true }));
app.use(express.static('.')); // 提供靜態HTML檔案
app.use('/uploads', express.static(path.join(__dirname, 'uploads')));

// ============================================
// Multer 設定：佐證檔案上傳（DocumentUploadManager）
// ============================================
const VALID_CATEGORIES = ['identity_proof', 'transcript', 'award_certificate'];

const docStorage = multer.diskStorage({
  destination: (req, file, cb) => {
    const dir = path.join(__dirname, 'uploads', 'documents');
    fs.mkdirSync(dir, { recursive: true });
    cb(null, dir);
  },
  filename: (req, file, cb) => {
    const ext = path.extname(file.originalname);
    cb(null, `DOC${Date.now()}${ext}`);
  }
});

const uploadDoc = multer({
  storage: docStorage,
  limits: { fileSize: 5 * 1024 * 1024 },
  fileFilter: (req, file, cb) => {
    const allowed = ['image/jpeg', 'image/png', 'application/pdf'];
    cb(null, allowed.includes(file.mimetype));
  }
});

// MySQL 連接池
const pool = mysql.createPool({
  host: process.env.DB_HOST || 'localhost',
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASSWORD || '',
  database: process.env.DB_NAME || 'scholarship_system',
  port: process.env.DB_PORT || 3306,
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0
});

const promisePool = pool.promise();

// 測試資料庫連接
pool.getConnection((err, connection) => {
  if (err) {
    console.error('❌ 資料庫連接失敗:', err.message);
    return;
  }
  console.log('✅ 資料庫連接成功！');
  connection.release();
});

// ============================================
// Email 模組（Mock 模式 — 不連線 SMTP，直接印到終端機）
// ============================================

async function sendEmail(to, subject, html) {
  const plainText = html.replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();
  const summary   = plainText.length > 120 ? plainText.substring(0, 120) + '…' : plainText;
  const otpMatch  = html.match(/\b(\d{6})\b/);

  console.log('\n\x1b[46m\x1b[30m ┌──────────────────────────────────────────────────────┐ \x1b[0m');
  console.log('\x1b[46m\x1b[30m │           [MOCK EMAIL NOTIFICATION]                  │ \x1b[0m');
  console.log('\x1b[46m\x1b[30m └──────────────────────────────────────────────────────┘ \x1b[0m');
  console.log(`\x1b[36m  收件人 :\x1b[0m ${to}`);
  console.log(`\x1b[36m  主旨   :\x1b[0m ${subject}`);
  console.log(`\x1b[36m  摘要   :\x1b[0m ${summary}`);
  if (otpMatch) {
    console.log(`\x1b[33m\x1b[1m  OTP    : ${otpMatch[1]}  ← 複製此碼貼到網頁\x1b[0m`);
  }
  console.log('\x1b[46m\x1b[30m ─────────────────────────────────────────────────────── \x1b[0m\n');
  return true;
}

function generateOTP() {
  return String(Math.floor(100000 + Math.random() * 900000));
}

// ============================================
// 通知中心輔助函式
// ============================================
async function createNotification(userId, title, content, targetUrl) {
  try {
    await promisePool.query(
      'INSERT INTO Notification (user_id, title, content, target_url) VALUES (?, ?, ?, ?)',
      [userId, title, content, targetUrl || '']
    );
  } catch (e) {
    console.error('[通知] 建立失敗:', e.message);
  }
}

async function notifyAllByType(userType, title, content, targetUrl) {
  try {
    const [users] = await promisePool.query('SELECT id FROM User WHERE type = ?', [userType]);
    for (const u of users) {
      await createNotification(u.id, title, content, targetUrl);
    }
  } catch (e) {
    console.error('[通知] 批量建立失敗:', e.message);
  }
}

function debugOTP(context, userId, otp) {
  console.log('\n\x1b[43m\x1b[30m ========== [OTP DEBUG LOG] ========== \x1b[0m');
  console.log(`\x1b[36m  場景    : ${context}\x1b[0m`);
  console.log(`\x1b[36m  帳號 ID : ${userId}\x1b[0m`);
  console.log(`\x1b[33m\x1b[1m  驗證碼  : ${otp} \x1b[0m`);
  console.log('\x1b[43m\x1b[30m ====================================== \x1b[0m\n');
}

// 通用 Email 格式正則（放寬為任意網域）
const EMAIL_REGEX = /^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/;

// ============================================
// 通用：使用者查詢 & 帳號管理
// ============================================

// POST /api/login - 登入驗證（LoginManager C002）
// 驗證 id + password，支援 bcrypt 雜湊比對及舊帳號遷移
app.post('/api/login', async (req, res) => {
  try {
    const { id, password } = req.body;
    if (!id || !password) {
      return res.status(400).json({ success: false, message: '請輸入帳號與密碼' });
    }

    const [rows] = await promisePool.query(
      'SELECT id, name, email, type, password AS pwd, email_verified FROM User WHERE id = ? LIMIT 1',
      [id]
    );

    if (rows.length === 0) {
      return res.status(401).json({ success: false, message: '帳號或密碼錯誤' });
    }

    const user = rows[0];
    let passwordMatch = false;

    if (user.pwd) {
      // 標準路徑：bcrypt 比對
      passwordMatch = await bcrypt.compare(password, user.pwd);
    } else {
      // 遷移路徑：舊帳號尚未設定雜湊密碼，接受 {id}2025 預設值並自動升級
      if (password === id + '2025') {
        passwordMatch = true;
        const hash = await bcrypt.hash(password, 12);
        await promisePool.query('UPDATE User SET password = ? WHERE id = ?', [hash, id]);
      }
    }

    if (!passwordMatch) {
      return res.status(401).json({ success: false, message: '帳號或密碼錯誤' });
    }

    res.json({
      success: true,
      data: { id: user.id, name: user.name, email: user.email, type: user.type }
    });
  } catch (error) {
    console.error('登入錯誤:', error);
    res.status(500).json({ success: false, message: '伺服器錯誤', error: error.message });
  }
});

// POST /api/user - 新增帳號（管理員專用，含科系欄位）
app.post('/api/user', async (req, res) => {
  try {
    const { id, name, email, type, department, password } = req.body;

    if (!id || !name || !email || !type) {
      return res.status(400).json({ success: false, message: '缺少必填欄位：id, name, email, type' });
    }

    const validTypes = ['Student', 'Teacher', 'Organization', 'SystemAdministrator'];
    if (!validTypes.includes(type)) {
      return res.status(400).json({ success: false, message: '無效的角色' });
    }

    // Email 格式基本驗證（任意網域）
    if (!EMAIL_REGEX.test(email)) {
      return res.status(400).json({ success: false, message: 'Email 格式不正確' });
    }

    // 學生與教師必須填寫科系
    if (['Student', 'Teacher'].includes(type) && !department) {
      return res.status(400).json({ success: false, message: '學生與教師帳號必須指定所屬科系' });
    }

    // 管理員與審查單位不需科系，強制為 null
    const finalDepartment = ['Student', 'Teacher'].includes(type) ? department : null;

    const [existing] = await promisePool.query('SELECT id FROM User WHERE id = ?', [id]);
    if (existing.length > 0) {
      return res.status(409).json({ success: false, message: '帳號已存在' });
    }

    // 建立密碼：使用傳入密碼或自動產生隨機預設密碼
    const rawPassword = password || (id + Math.random().toString(36).slice(-4).toUpperCase() + '!');
    const hashedPassword = await bcrypt.hash(rawPassword, 12);

    await promisePool.query(
      'INSERT INTO User (id, name, email, type, department, password, email_verified, verification_code) VALUES (?, ?, ?, ?, ?, ?, 1, NULL)',
      [id, name, email, type, finalDepartment, hashedPassword]
    );

    if (type === 'Student') {
      try {
        await promisePool.query('INSERT INTO Student (id) VALUES (?)', [id]);
      } catch {
        // Student 記錄可能已存在，忽略
      }
    }

    sendEmail(email, 'ScholarLink 帳號建立通知', `
      <div style="font-family:sans-serif;max-width:480px;margin:auto;padding:24px;border:1px solid #e5e7eb;border-radius:12px;">
        <h2 style="color:#667eea;margin-top:0;">ScholarLink 帳號已建立</h2>
        <p>您好 <strong>${name}</strong>，您的帳號已由管理員建立，可直接登入。</p>
        <p>帳號 ID：<code>${id}</code><br>初始密碼：<code>${rawPassword}</code></p>
      </div>
    `).catch(() => {});

    res.json({
      success: true,
      message: '帳號新增成功',
      data: { id, name, email, type, department: finalDepartment, tempPassword: rawPassword }
    });
  } catch (error) {
    console.error('新增帳號錯誤:', error);
    res.status(500).json({ success: false, message: '伺服器錯誤', error: error.message });
  }
});

// 依 ID 取得使用者基本資訊（含身分別）
app.get('/api/user/:id', async (req, res) => {
  try {
    const userId = req.params.id;
    const [rows] = await promisePool.query(
      'SELECT id, name, email, type FROM User WHERE id = ? LIMIT 1',
      [userId]
    );

    if (rows.length === 0) {
      return res.status(404).json({ success: false, message: '找不到使用者' });
    }

    res.json({
      success: true,
      data: rows[0]
    });
  } catch (error) {
    console.error('取得使用者錯誤:', error);
    res.status(500).json({ success: false, message: '伺服器錯誤', error: error.message });
  }
});

// ============================================
// 學生 API 路由
// ============================================

// 取得學生基本資料
app.get('/api/student/:id', async (req, res) => {
  try {
    const studentId = req.params.id;
    
    const [rows] = await promisePool.query(`
      SELECT 
        u.id,
        u.name,
        u.email,
        s.identity,
        s.major,
        GROUP_CONCAT(up.phone SEPARATOR ',') as phones
      FROM User u
      JOIN Student s ON u.id = s.id
      LEFT JOIN User_Phone up ON u.id = up.user_id
      WHERE u.id = ?
      GROUP BY u.id, u.name, u.email, s.identity, s.major
    `, [studentId]);

    if (rows.length === 0) {
      return res.status(404).json({ success: false, message: '找不到學生資料' });
    }

    const student = rows[0];
    
    // 根據身分別查詢額外資料
    let additionalInfo = {};
    if (student.identity === '僑生') {
      const [overseasInfo] = await promisePool.query(
        'SELECT * FROM Overseas_Student WHERE id = ?',
        [studentId]
      );
      if (overseasInfo.length > 0) {
        additionalInfo = overseasInfo[0];
      }
    } else if (student.identity === '原住民') {
      const [aboriginalInfo] = await promisePool.query(
        'SELECT * FROM Aboriginal_Student WHERE id = ?',
        [studentId]
      );
      if (aboriginalInfo.length > 0) {
        additionalInfo = aboriginalInfo[0];
      }
    } else if (student.identity === '低收入戶') {
      const [lowIncomeInfo] = await promisePool.query(
        'SELECT * FROM Low_Income_Student WHERE id = ?',
        [studentId]
      );
      if (lowIncomeInfo.length > 0) {
        additionalInfo = lowIncomeInfo[0];
      }
    } else if (student.identity === '身心障礙') {
      const [disabledInfo] = await promisePool.query(
        'SELECT * FROM Disabled_Student WHERE id = ?',
        [studentId]
      );
      if (disabledInfo.length > 0) {
        additionalInfo = disabledInfo[0];
      }
    }

    res.json({
      success: true,
      data: {
        ...student,
        phones: student.phones ? student.phones.split(',') : [],
        additionalInfo
      }
    });
  } catch (error) {
    console.error('取得學生資料錯誤:', error);
    res.status(500).json({ success: false, message: '伺服器錯誤', error: error.message });
  }
});

// 更新學生基本資料
app.put('/api/student/:id', async (req, res) => {
  const connection = await promisePool.getConnection();
  
  try {
    await connection.beginTransaction();
    
    const studentId = req.params.id;
    const { name, email, identity, major, phones, additionalInfo } = req.body;

    // 更新 User 表
    await connection.query(
      'UPDATE User SET name = ?, email = ? WHERE id = ?',
      [name, email, studentId]
    );

    // 更新 Student 表
    await connection.query(
      'UPDATE Student SET identity = ?, major = ? WHERE id = ?',
      [identity, major, studentId]
    );

    // 更新電話號碼（先刪除舊的，再插入新的）
    await connection.query('DELETE FROM User_Phone WHERE user_id = ?', [studentId]);
    
    if (phones && phones.length > 0) {
      for (const phone of phones) {
        if (phone.trim()) {
          await connection.query(
            'INSERT INTO User_Phone (user_id, phone) VALUES (?, ?)',
            [studentId, phone.trim()]
          );
        }
      }
    }

    // 根據身分別更新對應表格
    if (identity === '僑生' && additionalInfo) {
      // 先檢查是否存在
      const [existing] = await connection.query(
        'SELECT id FROM Overseas_Student WHERE id = ?',
        [studentId]
      );
      
      if (existing.length > 0) {
        await connection.query(`
          UPDATE Overseas_Student 
          SET overseas_id = ?, chinese_certify = ?, immigrate_date = ?, passport_number = ?
          WHERE id = ?
        `, [
          additionalInfo.overseas_id,
          additionalInfo.chinese_certify,
          additionalInfo.immigrate_date,
          additionalInfo.passport_number,
          studentId
        ]);
      } else {
        await connection.query(`
          INSERT INTO Overseas_Student (id, overseas_id, chinese_certify, immigrate_date, passport_number)
          VALUES (?, ?, ?, ?, ?)
        `, [
          studentId,
          additionalInfo.overseas_id,
          additionalInfo.chinese_certify,
          additionalInfo.immigrate_date,
          additionalInfo.passport_number
        ]);
      }
    }

    await connection.commit();
    
    res.json({
      success: true,
      message: '學生資料更新成功'
    });
  } catch (error) {
    await connection.rollback();
    console.error('更新學生資料錯誤:', error);
    res.status(500).json({ success: false, message: '更新失敗', error: error.message });
  } finally {
    connection.release();
  }
});

// 取得學生申請紀錄
app.get('/api/student/:id/applications', async (req, res) => {
  try {
    const studentId = req.params.id;
    
    const [rows] = await promisePool.query(`
      SELECT
        a.id,
        a.apply_date,
        a.scholarship_name,
        s.amount,
        a.apply_state,
        a.score,
        a.gpa,
        a.rank,
        a.requires_recommendation,
        a.reject_reason
      FROM Application a
      JOIN Scholarship s ON a.scholarship_name = s.name
      WHERE a.student_id = ?
      ORDER BY a.apply_date DESC
    `, [studentId]);

    res.json({
      success: true,
      data: rows
    });
  } catch (error) {
    console.error('取得申請紀錄錯誤:', error);
    res.status(500).json({ success: false, message: '伺服器錯誤', error: error.message });
  }
});

// 取得所有可申請的獎學金
app.get('/api/scholarships', async (req, res) => {
  try {
    const isAdminView = req.query.admin === 'true' || req.query.admin === '1';

    const [rows] = await promisePool.query(
      `SELECT
        s.name,
        s.amount,
        s.description,
        s.identity_restriction,
        s.is_published,
        s.published_by,
        s.published_at,
        s.start_date,
        s.end_date,
        s.required_documents,
        GROUP_CONCAT(u.name SEPARATOR ', ') AS organizations
      FROM Scholarship s
      LEFT JOIN Scholarship_Organization so ON s.name = so.scholarship_name
      LEFT JOIN Organization o ON so.organization_id = o.id
      LEFT JOIN User u ON o.id = u.id
      ${isAdminView ? '' : 'WHERE s.is_published = TRUE'}
      GROUP BY s.name, s.amount, s.description, s.identity_restriction, s.is_published, s.published_by, s.published_at, s.start_date, s.end_date, s.required_documents
      ORDER BY (s.published_at IS NULL), s.published_at DESC, s.name ASC`
    );

    res.json({
      success: true,
      data: rows
    });
  } catch (error) {
    console.error('取得獎學金列表錯誤:', error);
    res.status(500).json({ success: false, message: '伺服器錯誤', error: error.message });
  }
});

// 新增獎學金
app.post('/api/scholarships', async (req, res) => {
  const connection = await promisePool.getConnection();
  try {
    const { name, amount, description, organization_id, identity_restriction, publish, admin_id, start_date, end_date, required_documents } = req.body;

    if (!name || !amount) {
      return res.status(400).json({ success: false, message: 'name 與 amount 為必填欄位' });
    }

    await connection.beginTransaction();

    // 建立獎學金
    await connection.query(
      `INSERT INTO Scholarship (name, amount, description, identity_restriction, is_published, published_by, published_at, start_date, end_date, required_documents)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE amount = VALUES(amount), description = VALUES(description), identity_restriction = VALUES(identity_restriction), start_date = VALUES(start_date), end_date = VALUES(end_date), required_documents = VALUES(required_documents)`,
      [
        name,
        amount,
        description || null,
        identity_restriction || null,
        publish ? 1 : 0,
        publish ? (admin_id || null) : null,
        publish ? new Date() : null,
        start_date || null,
        end_date || null,
        required_documents || null
      ]
    );

    // 關聯機構
    if (organization_id) {
      await connection.query(
        `INSERT IGNORE INTO Scholarship_Organization (scholarship_name, organization_id) VALUES (?, ?)`,
        [name, organization_id]
      );
    }

    await connection.commit();
    res.json({ success: true, message: '獎學金已建立', data: { name } });
  } catch (error) {
    await connection.rollback();
    console.error('新增獎學金錯誤:', error);
    res.status(500).json({ success: false, message: '新增失敗', error: error.message });
  } finally {
    connection.release();
  }
});

// 刪除獎學金
app.delete('/api/scholarships/:name', async (req, res) => {
  try {
    const scholarshipName = req.params.name;

    // 檢查是否存在申請紀錄
    const [apps] = await promisePool.query(
      'SELECT COUNT(*) AS cnt FROM Application WHERE scholarship_name = ?',
      [scholarshipName]
    );
    if (apps[0].cnt > 0) {
      return res.status(409).json({ success: false, message: '已有申請紀錄，無法刪除此獎學金' });
    }

    const [result] = await promisePool.query(
      'DELETE FROM Scholarship WHERE name = ?',
      [scholarshipName]
    );

    if (result.affectedRows === 0) {
      return res.status(404).json({ success: false, message: '找不到指定的獎學金' });
    }

    // Scholarship_Organization 有 ON DELETE CASCADE，無須手動清理
    res.json({ success: true, message: '刪除成功' });
  } catch (error) {
    console.error('刪除獎學金錯誤:', error);
    res.status(500).json({ success: false, message: '刪除失敗', error: error.message });
  }
});

// 發放獎學金
app.post('/api/scholarships/:name/publish', async (req, res) => {
  try {
    const scholarshipName = req.params.name;
    const adminId = req.body.admin_id || null;

    const [result] = await promisePool.query(
      `UPDATE Scholarship SET is_published = TRUE, published_by = ?, published_at = NOW() WHERE name = ?`,
      [adminId, scholarshipName]
    );

    if (result.affectedRows === 0) {
      return res.status(404).json({ success: false, message: '找不到指定的獎學金' });
    }

    // 通知所有學生
    notifyAllByType(
      'Student',
      `📢 新獎學金開放申請：${scholarshipName}`,
      '符合資格的同學請盡快至系統申請',
      '/scholarship/pages/student_dashboard.html'
    ).catch(() => {});

    res.json({ success: true, message: '獎學金已發放' });
  } catch (error) {
    console.error('發放獎學金錯誤:', error);
    res.status(500).json({ success: false, message: '發放失敗', error: error.message });
  }
});

// 下架獎學金
app.post('/api/scholarships/:name/unpublish', async (req, res) => {
  try {
    const scholarshipName = req.params.name;
    const [result] = await promisePool.query(
      `UPDATE Scholarship SET is_published = FALSE, published_by = NULL, published_at = NULL WHERE name = ?`,
      [scholarshipName]
    );

    if (result.affectedRows === 0) {
      return res.status(404).json({ success: false, message: '找不到指定的獎學金' });
    }

    res.json({ success: true, message: '獎學金已下架' });
  } catch (error) {
    console.error('下架獎學金錯誤:', error);
    res.status(500).json({ success: false, message: '下架失敗', error: error.message });
  }
});

// 更新獎學金
app.put('/api/scholarships/:name', async (req, res) => {
  const connection = await promisePool.getConnection();
  try {
    const scholarshipName = decodeURIComponent(req.params.name);
    const { amount, description, organization_id, identity_restriction, start_date, end_date, required_documents } = req.body;

    await connection.beginTransaction();

    const fields = [];
    const params = [];
    if (amount !== undefined)              { fields.push('amount = ?');               params.push(amount); }
    if (description !== undefined)         { fields.push('description = ?');           params.push(description || null); }
    if (identity_restriction !== undefined){ fields.push('identity_restriction = ?');  params.push(identity_restriction || null); }
    if (start_date !== undefined)          { fields.push('start_date = ?');            params.push(start_date || null); }
    if (end_date !== undefined)            { fields.push('end_date = ?');              params.push(end_date || null); }
    if (required_documents !== undefined)  { fields.push('required_documents = ?');    params.push(required_documents || null); }

    if (fields.length > 0) {
      params.push(scholarshipName);
      await connection.query(`UPDATE Scholarship SET ${fields.join(', ')} WHERE name = ?`, params);
    }

    if (organization_id !== undefined) {
      await connection.query('DELETE FROM Scholarship_Organization WHERE scholarship_name = ?', [scholarshipName]);
      if (organization_id) {
        await connection.query('INSERT INTO Scholarship_Organization (scholarship_name, organization_id) VALUES (?, ?)', [scholarshipName, organization_id]);
      }
    }

    await connection.commit();
    res.json({ success: true, message: '獎學金已更新' });
  } catch (error) {
    await connection.rollback();
    console.error('更新獎學金錯誤:', error);
    res.status(500).json({ success: false, message: '更新失敗', error: error.message });
  } finally {
    connection.release();
  }
});

// 新增獎學金申請（Express 版，plural alias）
app.post('/api/applications', async (req, res) => {
  req.url = '/api/application';
  return app._router.handle(req, res, () => {});
});

// 新增獎學金申請
app.post('/api/application', async (req, res) => {
  try {
    const { student_id, scholarship_name, apply_way, score, gpa, family_income, requires_recommendation } = req.body;
    console.log(`[DEBUG POST /application] student_id=${student_id} scholarship=${scholarship_name} requires_rec=${requires_recommendation}`);

    if (!student_id || !scholarship_name) {
      return res.status(400).json({ success: false, message: '缺少必填欄位' });
    }

    // 取得獎學金資訊（必備文件 + 身份限制）
    const [schRows] = await promisePool.query(
      'SELECT identity_restriction, required_documents FROM Scholarship WHERE name = ?',
      [scholarship_name]
    );
    if (schRows.length === 0) {
      return res.status(404).json({ success: false, message: '找不到該獎學金' });
    }
    const sch = schRows[0];

    // 若有必備文件，驗證學生已上傳
    if (sch.required_documents) {
      const required = sch.required_documents.split(',').map(s => s.trim()).filter(Boolean);
      if (required.length > 0) {
        const [docRows] = await promisePool.query(
          'SELECT DISTINCT file_category FROM Identity_Proof WHERE student_id = ?',
          [student_id]
        );
        const uploaded = docRows.map(r => r.file_category);
        const missing = required.filter(cat => !uploaded.includes(cat));
        if (missing.length > 0) {
          const LABELS = { identity_proof: '身份證明', transcript: '成績單', award_certificate: '參賽得獎' };
          return res.status(400).json({
            success: false,
            message: `申請前請先上傳必備文件：${missing.map(c => LABELS[c] || c).join('、')}`
          });
        }
      }
    }

    // 防重複申請
    const [existing] = await promisePool.query(
      'SELECT id FROM Application WHERE student_id = ? AND scholarship_name = ?',
      [student_id, scholarship_name]
    );
    if (existing.length > 0) {
      return res.status(400).json({ success: false, message: '您已經申請過此項獎學金，無法重複申請！' });
    }

    // 強制 parseInt 確保不受 '0'/'1'/true/false 型別差異影響
    const needsTutor = parseInt(requires_recommendation, 10) === 1 ? 1 : 0;
    let db_state;
    if (needsTutor === 1) {
      db_state = 'pending_tutor';   // 需要導師推薦
    } else {
      db_state = 'pending_review';  // 直接進入審查
    }
    const applicationId = 'APP' + Date.now();
    console.log(`[DEBUG POST /application] needsTutor=${needsTutor} db_state=${db_state} applicationId=${applicationId}`);

    await promisePool.query(`
      INSERT INTO Application
      (id, student_id, scholarship_name, apply_way, apply_state, score, gpa, family_income, requires_recommendation)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    `, [applicationId, student_id, scholarship_name, apply_way || null, db_state, score, gpa, family_income || null, needsTutor]);

    // 通知相關人員
    try {
      const [[stuRow]] = await promisePool.query('SELECT name, department FROM User WHERE id = ?', [student_id]);
      const stuName = stuRow?.name || student_id;
      const dept    = stuRow?.department;
      if (needsTutor && dept) {
        const [teachers] = await promisePool.query(
          "SELECT id FROM User WHERE type = 'Teacher' AND department = ?", [dept]
        );
        for (const t of teachers) {
          createNotification(t.id,
            `📝 學生申請需要推薦信`,
            `${stuName} 申請「${scholarship_name}」，需要您的推薦信`,
            '/scholarship/pages/teacher_students.html'
          ).catch(() => {});
        }
      } else if (!needsTutor) {
        notifyAllByType('Organization',
          `📋 新申請待審查`,
          `學生 ${stuName} 申請「${scholarship_name}」，請進行審查`,
          '/scholarship/pages/organization_applications.html'
        ).catch(() => {});
      }
    } catch { /* 通知失敗不影響主流程 */ }

    res.json({ success: true, message: '申請提交成功', applicationId });
  } catch (error) {
    console.error('提交申請錯誤:', error);
    res.status(500).json({ success: false, message: '申請失敗', error: error.message });
  }
});

// ============================================
// 師生留言板 API 路由
// ============================================

// GET /api/messages - 取得留言列表（智慧隱私安全篩選）
app.get('/api/messages', async (req, res) => {
  try {
    const viewerId = req.query.viewer_id;
    if (!viewerId) {
      return res.status(400).json({ success: false, message: '缺少 viewer_id 參數' });
    }

    const [rows] = await promisePool.query(`
      SELECT
        m.id,
        m.parent_id,
        m.user_id,
        m.title,
        m.content,
        m.visibility,
        m.target_user_id,
        m.reply_content,
        m.reply_user_id,
        m.created_at,
        m.replied_at,
        u.name  AS author_name,
        ru.name AS reply_user_name,
        tu.name AS target_user_name
      FROM Message m
      JOIN User u ON m.user_id = u.id
      LEFT JOIN User ru ON m.reply_user_id = ru.id
      LEFT JOIN User tu ON m.target_user_id = tu.id
      WHERE m.visibility = 'Public'
         OR m.user_id = ?
         OR m.target_user_id = ?
      ORDER BY m.created_at ASC
    `, [viewerId, viewerId]);

    // 建立樹狀結構：根留言掛 replies 陣列
    const byId = {};
    rows.forEach(r => { r.replies = []; byId[r.id] = r; });
    const roots = [];
    rows.forEach(r => {
      if (r.parent_id && byId[r.parent_id]) {
        byId[r.parent_id].replies.push(r);
      } else if (!r.parent_id) {
        roots.push(r);
      }
    });
    roots.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));

    res.json({ success: true, data: roots });
  } catch (error) {
    console.error('取得留言列表錯誤:', error);
    res.status(500).json({ success: false, message: '伺服器錯誤', error: error.message });
  }
});

// POST /api/messages - 新增留言
app.post('/api/messages', async (req, res) => {
  try {
    const { user_id, title, content, visibility, target_user_id, parent_id } = req.body;

    if (!user_id || !content) {
      return res.status(400).json({ success: false, message: 'user_id 與 content 為必填欄位' });
    }
    if (!parent_id && !title) {
      return res.status(400).json({ success: false, message: '新留言必須填寫標題' });
    }
    const vis = visibility || 'Public';
    if (!['Public', 'Private'].includes(vis)) {
      return res.status(400).json({ success: false, message: "visibility 必須為 'Public' 或 'Private'" });
    }
    if (!parent_id && vis === 'Private' && !target_user_id) {
      return res.status(400).json({ success: false, message: '私密留言必須指定 target_user_id' });
    }

    // SystemAdministrator 不可發起根留言，但可回覆
    const [userRows] = await promisePool.query('SELECT type, name FROM User WHERE id = ?', [user_id]);
    if (userRows.length === 0) {
      return res.status(404).json({ success: false, message: '找不到使用者' });
    }
    if (!parent_id && userRows[0].type === 'SystemAdministrator') {
      return res.status(403).json({ success: false, message: '系統管理員不可發表新留言' });
    }

    const messageId = 'MSG' + Date.now();
    await promisePool.query(
      'INSERT INTO Message (id, parent_id, user_id, title, content, visibility, target_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
      [messageId, parent_id || null, user_id, title || '', content, vis, target_user_id || null]
    );

    // 私密根留言：通知收件人
    if (!parent_id && vis === 'Private' && target_user_id) {
      const senderName = userRows[0]?.name || user_id;
      createNotification(target_user_id,
        `💬 您收到 ${senderName} 的私人留言`,
        `「${title}」`,
        '/scholarship/pages/message_board.html'
      ).catch(() => {});
    }

    res.json({ success: true, message: parent_id ? '回覆成功' : '留言發布成功', data: { id: messageId } });
  } catch (error) {
    console.error('新增留言錯誤:', error);
    res.status(500).json({ success: false, message: '新增留言失敗', error: error.message });
  }
});

// PUT /api/messages/:id/reply - 回覆留言（支援單則及批量回覆）
// 批量模式：body 傳入 message_ids: [...] 且 :id 填任意佔位字串（如 'bulk'）
app.put('/api/messages/:id/reply', async (req, res) => {
  try {
    const { reply_user_id, reply_content, message_ids } = req.body;

    if (!reply_user_id || !reply_content) {
      return res.status(400).json({ success: false, message: 'reply_user_id 與 reply_content 為必填欄位' });
    }

    // 驗證回覆者身份
    const [userRows] = await promisePool.query('SELECT type FROM User WHERE id = ?', [reply_user_id]);
    if (userRows.length === 0) {
      return res.status(404).json({ success: false, message: '找不到回覆者' });
    }
    const replyUserType = userRows[0].type;
    const isAdmin       = replyUserType === 'SystemAdministrator';
    const isTeacherOrOrg = replyUserType === 'Teacher' || replyUserType === 'Organization';

    // ── 批量回覆模式（管理員專用）────────────────────────────
    if (Array.isArray(message_ids) && message_ids.length > 0) {
      if (!isAdmin) {
        return res.status(403).json({ success: false, message: '批量回覆僅限系統管理員操作' });
      }
      let successCount = 0;
      for (const msgId of message_ids) {
        const [check] = await promisePool.query('SELECT id FROM Message WHERE id = ?', [msgId]);
        if (check.length === 0) continue;
        await promisePool.query(
          'UPDATE Message SET reply_content = ?, reply_user_id = ?, replied_at = NOW() WHERE id = ?',
          [reply_content, reply_user_id, msgId]
        );
        successCount++;
      }
      return res.json({ success: true, message: `已成功回覆 ${successCount} 則留言`, count: successCount });
    }

    // ── 單則回覆模式 ────────────────────────────────────────
    const messageId = req.params.id;
    const [msgRows] = await promisePool.query('SELECT user_id FROM Message WHERE id = ?', [messageId]);
    if (msgRows.length === 0) {
      return res.status(404).json({ success: false, message: '找不到指定留言' });
    }
    const messageOwnerId  = msgRows[0].user_id;
    const isOriginalAuthor = reply_user_id === messageOwnerId;

    if (!isAdmin && !isTeacherOrOrg && !isOriginalAuthor) {
      return res.status(403).json({ success: false, message: '您沒有回覆此留言的權限' });
    }

    await promisePool.query(
      'UPDATE Message SET reply_content = ?, reply_user_id = ?, replied_at = NOW() WHERE id = ?',
      [reply_content, reply_user_id, messageId]
    );
    res.json({ success: true, message: '回覆成功' });
  } catch (error) {
    console.error('回覆留言錯誤:', error);
    res.status(500).json({ success: false, message: '回覆失敗', error: error.message });
  }
});

// DELETE /api/messages/:id - 刪除留言（智慧刪除權限防禦）
app.delete('/api/messages/:id', async (req, res) => {
  try {
    const messageId = req.params.id;
    const operatorId = req.body.operator_id;

    if (!operatorId) {
      return res.status(400).json({ success: false, message: '缺少 operator_id 參數' });
    }

    const [msgRows] = await promisePool.query('SELECT user_id, visibility FROM Message WHERE id = ?', [messageId]);
    if (msgRows.length === 0) {
      return res.status(404).json({ success: false, message: '找不到指定留言' });
    }
    const { user_id: authorId, visibility } = msgRows[0];

    const [operatorRows] = await promisePool.query('SELECT type FROM User WHERE id = ?', [operatorId]);
    if (operatorRows.length === 0) {
      return res.status(404).json({ success: false, message: '找不到操作者資訊' });
    }
    const operatorType = operatorRows[0].type;

    const isAdmin = operatorType === 'SystemAdministrator';
    const isAuthor = operatorId === authorId;

    if (isAdmin && visibility !== 'Public') {
      return res.status(403).json({ success: false, message: '管理員只能刪除公開留言' });
    }
    if (!isAdmin && !isAuthor) {
      return res.status(403).json({ success: false, message: '您沒有刪除此留言的權限' });
    }

    await promisePool.query('DELETE FROM Message WHERE id = ?', [messageId]);
    res.json({ success: true, message: '留言已刪除' });
  } catch (error) {
    console.error('刪除留言錯誤:', error);
    res.status(500).json({ success: false, message: '刪除失敗', error: error.message });
  }
});

// GET /api/users/list - 取得非管理員使用者清單（供私密留言選取對象用）
app.get('/api/users/list', async (req, res) => {
  try {
    const [rows] = await promisePool.query(
      "SELECT id, name FROM User WHERE type != 'SystemAdministrator' ORDER BY name ASC"
    );
    res.json({ success: true, data: rows });
  } catch (error) {
    console.error('取得使用者清單錯誤:', error);
    res.status(500).json({ success: false, message: '伺服器錯誤', error: error.message });
  }
});

// POST /api/users/verify-otp - 核對 OTP 驗證碼，啟用帳號
app.post('/api/users/verify-otp', async (req, res) => {
  try {
    const { id, code } = req.body;
    if (!id || !code) {
      return res.status(400).json({ success: false, message: '缺少 id 或 code 參數' });
    }

    const [rows] = await promisePool.query(
      'SELECT verification_code, email_verified FROM User WHERE id = ? LIMIT 1',
      [id]
    );

    if (rows.length === 0) {
      return res.status(404).json({ success: false, message: '找不到使用者' });
    }

    const user = rows[0];

    if (user.email_verified) {
      return res.json({ success: true, message: '帳號已驗證，無需重複驗證' });
    }

    if (!user.verification_code || user.verification_code !== String(code).trim()) {
      return res.status(400).json({ success: false, message: '驗證碼錯誤，請重新確認' });
    }

    await promisePool.query(
      'UPDATE User SET email_verified = 1, verification_code = NULL WHERE id = ?',
      [id]
    );

    res.json({ success: true, message: '電子郵件驗證成功，帳號已啟用' });
  } catch (error) {
    console.error('OTP 驗證錯誤:', error);
    res.status(500).json({ success: false, message: '伺服器錯誤', error: error.message });
  }
});

// ============================================
// 佐證檔案 API 路由（DocumentUploadManager）
// ============================================

// POST /api/documents/upload - 學生佐證檔案上傳
app.post('/api/documents/upload', uploadDoc.single('file'), async (req, res) => {
  try {
    const studentId = req.body.student_id;
    const category  = req.body.category;

    if (!studentId || !req.file) {
      return res.status(400).json({ success: false, message: '缺少 student_id 或 file' });
    }
    if (!VALID_CATEGORIES.includes(category)) {
      return res.status(400).json({ success: false, message: '無效的檔案類別，限定：identity_proof / transcript / award_certificate' });
    }

    const docId    = 'DOC' + Date.now();
    const filePath = '/scholarship/uploads/documents/' + req.file.filename;
    const fileMime = req.file.mimetype;

    await promisePool.query(
      'INSERT INTO Identity_Proof (id, student_id, file_path, file_name, file_size, file_category, file_mime, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
      [docId, studentId, filePath, req.file.originalname, req.file.size, category, fileMime]
    );

    res.json({ success: true, message: '檔案上傳成功', data: { id: docId, file_path: filePath, file_mime: fileMime, file_category: category } });
  } catch (error) {
    console.error('佐證檔案上傳錯誤:', error);
    res.status(500).json({ success: false, message: '伺服器錯誤', error: error.message });
  }
});

// GET /api/applications/:id/documents - 取得申請案佐證檔案（依分類打包）
app.get('/api/applications/:id/documents', async (req, res) => {
  try {
    const appId = req.params.id;

    const [appRows] = await promisePool.query(
      'SELECT student_id FROM Application WHERE id = ? LIMIT 1',
      [appId]
    );
    if (appRows.length === 0) {
      return res.status(404).json({ success: false, message: '找不到申請案' });
    }
    const studentId = appRows[0].student_id;

    const [rows] = await promisePool.query(
      `SELECT id, student_id, file_path, file_name, file_size,
              COALESCE(file_category, 'identity_proof') AS file_category,
              file_mime, uploaded_at
       FROM Identity_Proof
       WHERE student_id = ?
       ORDER BY file_category, uploaded_at DESC`,
      [studentId]
    );

    const grouped = { identity_proof: [], transcript: [], award_certificate: [] };
    for (const r of rows) {
      const cat = VALID_CATEGORIES.includes(r.file_category) ? r.file_category : 'identity_proof';
      grouped[cat].push(r);
    }

    res.json({ success: true, student_id: studentId, data: grouped });
  } catch (error) {
    console.error('取得申請案文件錯誤:', error);
    res.status(500).json({ success: false, message: '伺服器錯誤', error: error.message });
  }
});

// GET /api/admin/documents - 管理員複合查詢佐證檔案
app.get('/api/admin/documents', async (req, res) => {
  try {
    const { studentId, categories } = req.query;
    const conditions = [];
    const params     = [];

    if (studentId && studentId.trim()) {
      conditions.push('ip.student_id = ?');
      params.push(studentId.trim());
    }

    if (categories && categories.trim()) {
      const catList = categories.split(',')
        .map(c => c.trim())
        .filter(c => VALID_CATEGORIES.includes(c));
      if (catList.length > 0) {
        conditions.push(`ip.file_category IN (${catList.map(() => '?').join(',')})`);
        params.push(...catList);
      }
    }

    const where = conditions.length > 0 ? 'WHERE ' + conditions.join(' AND ') : '';

    const [rows] = await promisePool.query(
      `SELECT ip.id, ip.student_id, u.name AS student_name,
              ip.file_name, ip.file_size, ip.file_path,
              COALESCE(ip.file_mime, '') AS file_mime,
              COALESCE(ip.file_category, 'identity_proof') AS file_category,
              ip.uploaded_at
       FROM Identity_Proof ip
       JOIN Student s ON ip.student_id = s.id
       JOIN User u ON s.id = u.id
       ${where}
       ORDER BY ip.uploaded_at DESC`,
      params
    );

    res.json({ success: true, data: rows });
  } catch (error) {
    console.error('管理員查詢文件錯誤:', error);
    res.status(500).json({ success: false, message: '伺服器錯誤', error: error.message });
  }
});

// POST /api/users/forgot-password - 驗證帳號 ID 與 Email 是否吻合（無 OTP）
app.post('/api/users/forgot-password', async (req, res) => {
  try {
    const { id, email } = req.body;
    if (!id || !email) {
      return res.status(400).json({ success: false, message: '請輸入帳號 ID 與 Email' });
    }

    const [rows] = await promisePool.query(
      'SELECT id FROM User WHERE id = ? AND email = ? LIMIT 1',
      [id, email.trim()]
    );

    if (rows.length === 0) {
      return res.status(404).json({ success: false, message: '帳號 ID 與 Email 不吻合，請確認後再試' });
    }

    res.json({ success: true, message: '身份驗證成功，請輸入新密碼' });
  } catch (error) {
    console.error('忘記密碼錯誤:', error);
    res.status(500).json({ success: false, message: '伺服器錯誤', error: error.message });
  }
});

// POST /api/users/reset-password - 驗證 ID+Email 後直接更新密碼（無 OTP）
app.post('/api/users/reset-password', async (req, res) => {
  try {
    const { id, email, newPassword } = req.body;
    if (!id || !email || !newPassword) {
      return res.status(400).json({ success: false, message: '缺少必要參數' });
    }
    if (String(newPassword).length < 6) {
      return res.status(400).json({ success: false, message: '新密碼至少需要 6 個字元' });
    }

    const [rows] = await promisePool.query(
      'SELECT id FROM User WHERE id = ? AND email = ? LIMIT 1',
      [id, email.trim()]
    );

    if (rows.length === 0) {
      return res.status(404).json({ success: false, message: '帳號 ID 與 Email 不吻合' });
    }

    const hashed = await bcrypt.hash(String(newPassword), 12);
    await promisePool.query('UPDATE User SET password = ? WHERE id = ?', [hashed, id]);

    res.json({ success: true, message: '密碼重設成功，請使用新密碼登入' });
  } catch (error) {
    console.error('重設密碼錯誤:', error);
    res.status(500).json({ success: false, message: '伺服器錯誤', error: error.message });
  }
});

// ============================================
// 管理員備忘錄 API 路由
// ============================================

// GET /api/memos?admin_id=xxx - 取得指定管理員的所有備忘錄
app.get('/api/memos', async (req, res) => {
  try {
    const { admin_id } = req.query;
    if (!admin_id) {
      return res.status(400).json({ success: false, message: '缺少 admin_id 參數' });
    }

    // 防禦性檢查：確認是 SystemAdministrator
    const [userRows] = await promisePool.query(
      'SELECT type FROM User WHERE id = ? LIMIT 1',
      [admin_id]
    );
    if (userRows.length === 0 || userRows[0].type !== 'SystemAdministrator') {
      return res.status(403).json({ success: false, message: '權限不足，僅限系統管理員存取' });
    }

    const [rows] = await promisePool.query(
      `SELECT id, admin_id, title, content, priority, status, reminder_date, created_time
       FROM Admin_Memo
       WHERE admin_id = ?
       ORDER BY
         CASE priority
           WHEN '緊急'     THEN 1
           WHEN '重要'     THEN 2
           WHEN '沒那麼重要' THEN 3
           ELSE 4
         END,
         created_time DESC`,
      [admin_id]
    );

    res.json({ success: true, data: rows });
  } catch (error) {
    console.error('取得備忘錄錯誤:', error);
    res.status(500).json({ success: false, message: '伺服器錯誤', error: error.message });
  }
});

// POST /api/memos - 新增備忘錄
app.post('/api/memos', async (req, res) => {
  try {
    const { admin_id, title, content, priority, status, reminder_date } = req.body;

    if (!admin_id || !title) {
      return res.status(400).json({ success: false, message: '缺少必填欄位：admin_id、title' });
    }

    // 防禦性檢查：確認是 SystemAdministrator
    const [userRows] = await promisePool.query(
      'SELECT type FROM User WHERE id = ? LIMIT 1',
      [admin_id]
    );
    if (userRows.length === 0 || userRows[0].type !== 'SystemAdministrator') {
      return res.status(403).json({ success: false, message: '權限不足，僅限系統管理員存取' });
    }

    const id = 'MEMO' + Date.now();
    const finalPriority = priority || '重要';
    const finalStatus   = '代辦'; // 新增備忘錄一律強制為代辦，不採用前端傳入值
    const finalReminder = reminder_date || null;

    await promisePool.query(
      `INSERT INTO Admin_Memo (id, admin_id, title, content, priority, status, reminder_date)
       VALUES (?, ?, ?, ?, ?, ?, ?)`,
      [id, admin_id, title, content || null, finalPriority, finalStatus, finalReminder]
    );

    res.status(201).json({ success: true, message: '備忘錄新增成功', data: { id } });
  } catch (error) {
    console.error('新增備忘錄錯誤:', error);
    res.status(500).json({ success: false, message: '伺服器錯誤', error: error.message });
  }
});

// PUT /api/memos/:id - 編輯備忘錄
app.put('/api/memos/:id', async (req, res) => {
  try {
    const memoId = req.params.id;
    const { admin_id, title, content, priority, status, reminder_date } = req.body;

    if (!admin_id) {
      return res.status(400).json({ success: false, message: '缺少 admin_id 參數' });
    }

    // 防禦性檢查：確認是 SystemAdministrator
    const [userRows] = await promisePool.query(
      'SELECT type FROM User WHERE id = ? LIMIT 1',
      [admin_id]
    );
    if (userRows.length === 0 || userRows[0].type !== 'SystemAdministrator') {
      return res.status(403).json({ success: false, message: '權限不足，僅限系統管理員存取' });
    }

    // 確認備忘錄存在且屬於此管理員
    const [memoRows] = await promisePool.query(
      'SELECT admin_id FROM Admin_Memo WHERE id = ? LIMIT 1',
      [memoId]
    );
    if (memoRows.length === 0) {
      return res.status(404).json({ success: false, message: '找不到指定備忘錄' });
    }
    if (memoRows[0].admin_id !== admin_id) {
      return res.status(403).json({ success: false, message: '您沒有權限修改此備忘錄' });
    }

    const fields = [];
    const values = [];
    if (title         !== undefined) { fields.push('title = ?');         values.push(title); }
    if (content       !== undefined) { fields.push('content = ?');       values.push(content); }
    if (priority      !== undefined) { fields.push('priority = ?');      values.push(priority); }
    if (status        !== undefined) { fields.push('status = ?');        values.push(status); }
    if (reminder_date !== undefined) { fields.push('reminder_date = ?'); values.push(reminder_date || null); }

    if (fields.length === 0) {
      return res.status(400).json({ success: false, message: '沒有可更新的欄位' });
    }

    values.push(memoId);
    await promisePool.query(
      `UPDATE Admin_Memo SET ${fields.join(', ')} WHERE id = ?`,
      values
    );

    res.json({ success: true, message: '備忘錄更新成功' });
  } catch (error) {
    console.error('編輯備忘錄錯誤:', error);
    res.status(500).json({ success: false, message: '伺服器錯誤', error: error.message });
  }
});

// DELETE /api/memos/:id?admin_id=xxx - 刪除備忘錄
app.delete('/api/memos/:id', async (req, res) => {
  try {
    const memoId  = req.params.id;
    const admin_id = req.query.admin_id;

    if (!admin_id) {
      return res.status(400).json({ success: false, message: '缺少 admin_id 參數' });
    }

    // 防禦性檢查：確認是 SystemAdministrator
    const [userRows] = await promisePool.query(
      'SELECT type FROM User WHERE id = ? LIMIT 1',
      [admin_id]
    );
    if (userRows.length === 0 || userRows[0].type !== 'SystemAdministrator') {
      return res.status(403).json({ success: false, message: '權限不足，僅限系統管理員存取' });
    }

    // 確認備忘錄存在且屬於此管理員
    const [memoRows] = await promisePool.query(
      'SELECT admin_id FROM Admin_Memo WHERE id = ? LIMIT 1',
      [memoId]
    );
    if (memoRows.length === 0) {
      return res.status(404).json({ success: false, message: '找不到指定備忘錄' });
    }
    if (memoRows[0].admin_id !== admin_id) {
      return res.status(403).json({ success: false, message: '您沒有權限刪除此備忘錄' });
    }

    await promisePool.query('DELETE FROM Admin_Memo WHERE id = ?', [memoId]);
    res.json({ success: true, message: '備忘錄已刪除' });
  } catch (error) {
    console.error('刪除備忘錄錯誤:', error);
    res.status(500).json({ success: false, message: '伺服器錯誤', error: error.message });
  }
});

// ============================================
// 教師 API 路由
// ============================================

// GET /api/organization/:id/applications - 取得機構所屬獎學金的待審申請列表
app.get('/api/organization/:id/applications', async (req, res) => {
  try {
    const organizationId = req.params.id;
    console.log(`[DEBUG GET /organization/${organizationId}/applications]`);
    const [rows] = await promisePool.query(`
      SELECT
        a.id,
        a.student_id,
        u.name        AS student_name,
        u.department  AS student_department,
        a.scholarship_name,
        a.apply_date,
        a.apply_state,
        a.score,
        a.gpa,
        a.family_income,
        a.reject_reason,
        a.recommendation_id,
        s.amount
      FROM Application a
      JOIN Scholarship s ON a.scholarship_name = s.name
      JOIN User u ON a.student_id = u.id
      WHERE a.apply_state IN ('pending_review', 'Under Review', 'Pending')
      ORDER BY a.apply_date DESC
    `);
    console.log(`[DEBUG GET /organization] 查到 ${rows.length} 筆`);
    res.json({ success: true, data: rows });
  } catch (error) {
    console.error('取得機構申請列表錯誤:', error);
    res.status(500).json({ success: false, message: '伺服器錯誤', error: error.message });
  }
});

// GET /api/teacher/:id/applications - 取得導師「本科系」學生的 pending_tutor 申請列表
app.get('/api/teacher/:id/applications', async (req, res) => {
  try {
    const teacherId = req.params.id;
    console.log(`[DEBUG GET /teacher/${teacherId}/applications]`);

    const [teacherRows] = await promisePool.query(
      "SELECT department FROM User WHERE id = ? AND type = 'Teacher'",
      [teacherId]
    );
    if (teacherRows.length === 0) {
      return res.status(403).json({ success: false, message: '找不到導師帳號' });
    }
    const teacherDepartment = teacherRows[0].department || null;

    let rows;
    if (teacherDepartment) {
      [rows] = await promisePool.query(`
        SELECT
          a.id,
          a.student_id,
          u.name       AS student_name,
          u.department AS student_department,
          a.scholarship_name,
          a.apply_date,
          a.apply_state,
          a.gpa,
          a.score,
          a.reject_reason,
          a.recommendation_id,
          r.content    AS recommendation_content
        FROM Application a
        JOIN User u ON a.student_id = u.id
        LEFT JOIN Recommendation r ON a.recommendation_id = r.id
        WHERE a.apply_state = 'pending_tutor'
          AND u.department = ?
        ORDER BY a.apply_date DESC
      `, [teacherDepartment]);
    } else {
      [rows] = await promisePool.query(`
        SELECT
          a.id,
          a.student_id,
          u.name       AS student_name,
          u.department AS student_department,
          a.scholarship_name,
          a.apply_date,
          a.apply_state,
          a.gpa,
          a.score,
          a.reject_reason,
          a.recommendation_id,
          r.content    AS recommendation_content
        FROM Application a
        JOIN User u ON a.student_id = u.id
        LEFT JOIN Recommendation r ON a.recommendation_id = r.id
        WHERE a.apply_state = 'pending_tutor'
        ORDER BY a.apply_date DESC
      `);
    }

    console.log(`[DEBUG GET /teacher] dept="${teacherDepartment}" 查到 ${rows.length} 筆`);
    res.json({ success: true, data: rows, department: teacherDepartment, departmentFiltered: !!teacherDepartment });
  } catch (error) {
    console.error('取得教師申請列表錯誤:', error);
    res.status(500).json({ success: false, message: '伺服器錯誤', error: error.message });
  }
});

// ============================================
// 獎學金審查 API 路由（ApplicationReviewManager C403）
// ============================================

// POST /api/recommendation - 提交推薦信，同步將申請從 pending_tutor 推進至 pending_review
app.post('/api/recommendation', async (req, res) => {
  try {
    const teacher_id    = (req.body.teacher_id    || '').trim();
    const application_id = (req.body.application_id || '').trim();
    const content       = req.body.content || '';
    if (!teacher_id || !application_id || !content) {
      return res.status(400).json({ success: false, message: '缺少必填欄位' });
    }

    const recommendationId = 'REC' + Date.now();
    await promisePool.query(
      'INSERT INTO Recommendation (id, content, teacher_id, update_date) VALUES (?, ?, ?, NOW())',
      [recommendationId, content, teacher_id]
    );

    await promisePool.query(
      `UPDATE Application SET recommendation_id = ?, apply_state = 'pending_review'
       WHERE id = ? AND apply_state = 'pending_tutor'`,
      [recommendationId, application_id]
    );

    // 通知所有機構：申請案推薦信已完成，可進行審查
    if (application_id) {
      const [[appRow]] = await promisePool.query(
        'SELECT scholarship_name FROM Application WHERE id = ?', [application_id]
      ).catch(() => [[]]);
      const schName = appRow?.scholarship_name || application_id;
      notifyAllByType('Organization',
        `✅ 推薦信已完成，申請可審查`,
        `申請案「${schName}」的導師推薦信已提交，請進行審查`,
        '/scholarship/pages/organization_applications.html'
      ).catch(() => {});
    }

    res.json({ success: true, data: { id: recommendationId } });
  } catch (error) {
    console.error('提交推薦信錯誤:', error);
    res.status(500).json({ success: false, message: '伺服器錯誤', error: error.message });
  }
});

// POST /api/application/:id/resubmit - 學生補件重新提交
app.post('/api/application/:id/resubmit', async (req, res) => {
  try {
    const applicationId = req.params.id;
    const [rows] = await promisePool.query(
      'SELECT apply_state, requires_recommendation FROM Application WHERE id = ?',
      [applicationId]
    );
    if (rows.length === 0) {
      return res.status(404).json({ success: false, message: '找不到指定的申請' });
    }
    const { apply_state, requires_recommendation } = rows[0];
    if (!['tutor_rejected', 'review_rejected'].includes(apply_state)) {
      return res.status(400).json({ success: false, message: '目前狀態不可重新提交' });
    }

    const nextState = (apply_state === 'tutor_rejected' || (apply_state === 'review_rejected' && requires_recommendation))
      ? 'pending_tutor'
      : 'pending_review';

    await promisePool.query(
      'UPDATE Application SET apply_state = ?, reject_reason = NULL WHERE id = ?',
      [nextState, applicationId]
    );

    res.json({ success: true, message: '已重新提交，等待審查', next_state: nextState });
  } catch (error) {
    console.error('重新提交錯誤:', error);
    res.status(500).json({ success: false, message: '伺服器錯誤', error: error.message });
  }
});

// PUT /api/application/:id/review - 提交審查結果（導師退件 / 機構退件補件 / 核准 / 不通過）
app.put('/api/application/:id/review', async (req, res) => {
  try {
    const applicationId = req.params.id;
    const { apply_state, reason } = req.body;

    if (!apply_state) {
      return res.status(400).json({ success: false, message: '缺少 apply_state 參數' });
    }

    const validStates = ['Pending', 'Under Review', 'Approved', 'Rejected', 'pending_review', 'tutor_rejected', 'review_rejected'];
    if (!validStates.includes(apply_state)) {
      return res.status(400).json({ success: false, message: '無效的審查狀態' });
    }

    const [appRows] = await promisePool.query(`
      SELECT a.id, a.student_id, a.scholarship_name, u.email, u.name AS student_name
      FROM Application a
      JOIN User u ON a.student_id = u.id
      WHERE a.id = ? LIMIT 1
    `, [applicationId]);

    if (appRows.length === 0) {
      return res.status(404).json({ success: false, message: '找不到指定的申請' });
    }

    const appInfo = appRows[0];
    const isRejection = ['tutor_rejected', 'review_rejected'].includes(apply_state);

    const [result] = await promisePool.query(
      'UPDATE Application SET apply_state = ?, reject_reason = ? WHERE id = ?',
      [apply_state, isRejection ? (reason || '') : null, applicationId]
    );

    if (result.affectedRows === 0) {
      return res.status(404).json({ success: false, message: '狀態未變更' });
    }

    // 系統內通知學生
    const notifyStates = { Approved: '審核通過', Rejected: '審核不通過', tutor_rejected: '導師退件', review_rejected: '機構退件' };
    if (notifyStates[apply_state] && appInfo) {
      const stateLabel = notifyStates[apply_state];
      const icon = apply_state === 'Approved' ? '✅' : (isRejection ? '↩️' : '❌');
      const detail = isRejection
        ? `您申請的「${appInfo.scholarship_name}」被退件${reason ? '，原因：' + reason : ''}，請補件後重新提交。`
        : apply_state === 'Approved'
          ? `您申請的「${appInfo.scholarship_name}」已通過審查，恭喜！`
          : `您申請的「${appInfo.scholarship_name}」審查未通過${reason ? '，原因：' + reason : ''}。`;
      createNotification(appInfo.student_id,
        `${icon} ${stateLabel}：${appInfo.scholarship_name}`,
        detail,
        '/scholarship/pages/student_results.html'
      ).catch(() => {});
    }

    // 最終結果（核准/不通過/退件）：非同步發送 Email 通知學生
    if (notifyStates[apply_state]) {
      const stateLabel = notifyStates[apply_state];
      const stateColor = apply_state === 'Approved' ? '#22c55e' : '#ef4444';
      const emailHtml = `
        <div style="font-family:sans-serif;max-width:480px;margin:auto;padding:24px;border:1px solid #e5e7eb;border-radius:12px;">
          <h2 style="color:#667eea;margin-top:0;">ScholarLink 獎學金審查結果通知</h2>
          <p>您好 <strong>${appInfo.student_name}</strong>，</p>
          <p>您申請的 <strong>「${appInfo.scholarship_name}」</strong> 獎學金審查結果如下：</p>
          <div style="font-size:24px;font-weight:bold;color:${stateColor};padding:16px;background:#f5f5f5;border-radius:8px;text-align:center;">${stateLabel}</div>
          ${reason ? `<p style="margin-top:16px;"><strong>審查意見：</strong>${reason}</p>` : ''}
          ${['tutor_rejected', 'review_rejected'].includes(apply_state) ? '<p>請登入系統查看退件說明並補件後重新提交。</p>' : ''}
          <p style="color:#9ca3af;font-size:12px;margin-top:16px;">如有疑問請透過校內系統聯繫相關單位。</p>
        </div>
      `;
      sendEmail(appInfo.email, `ScholarLink 獎學金審查通知：${stateLabel}`, emailHtml).catch(() => {});
    }

    res.json({ success: true, message: '審查結果已保存', updated_state: apply_state });
  } catch (error) {
    console.error('審查錯誤:', error);
    res.status(500).json({ success: false, message: '伺服器錯誤', error: error.message });
  }
});

// ============================================
// 通知中心 API 路由
// ============================================

// GET /api/notifications/unread-count?user_id=XXX（靜態路徑優先於 :id）
app.get('/api/notifications/unread-count', async (req, res) => {
  const { user_id } = req.query;
  if (!user_id) return res.status(400).json({ success: false, message: '缺少 user_id 參數' });
  try {
    const [[row]] = await promisePool.query(
      'SELECT COUNT(*) AS count FROM Notification WHERE user_id = ? AND is_read = 0',
      [user_id]
    );
    res.json({ success: true, count: row.count });
  } catch (e) {
    res.status(500).json({ success: false, message: e.message });
  }
});

// PUT /api/notifications/read-all?user_id=XXX（靜態路徑優先於 :id）
app.put('/api/notifications/read-all', async (req, res) => {
  const { user_id } = req.query;
  if (!user_id) return res.status(400).json({ success: false, message: '缺少 user_id 參數' });
  try {
    await promisePool.query('UPDATE Notification SET is_read = 1 WHERE user_id = ?', [user_id]);
    res.json({ success: true });
  } catch (e) {
    res.status(500).json({ success: false, message: e.message });
  }
});

// GET /api/notifications?user_id=XXX
app.get('/api/notifications', async (req, res) => {
  const { user_id } = req.query;
  if (!user_id) return res.status(400).json({ success: false, message: '缺少 user_id 參數' });
  try {
    const [rows] = await promisePool.query(
      'SELECT * FROM Notification WHERE user_id = ? ORDER BY created_at DESC LIMIT 50',
      [user_id]
    );
    res.json({ success: true, data: rows });
  } catch (e) {
    res.status(500).json({ success: false, message: e.message });
  }
});

// PUT /api/notifications/:id/read
app.put('/api/notifications/:id/read', async (req, res) => {
  try {
    await promisePool.query('UPDATE Notification SET is_read = 1 WHERE id = ?', [req.params.id]);
    res.json({ success: true });
  } catch (e) {
    res.status(500).json({ success: false, message: e.message });
  }
});

// ============================================
// 啟動伺服器
// ============================================

app.listen(PORT, () => {
  console.log(`🚀 伺服器運行在 http://localhost:${PORT}`);
  console.log(`📄 學生個人資料頁面: http://localhost:${PORT}/student_profile.html`);
  console.log(`📄 學生首頁: http://localhost:${PORT}/student.html`);
});

// 每小時檢查截止日期，對已結案獎學金的申請者發送結果通知
setInterval(async () => {
  try {
    const [expired] = await promisePool.query(`
      SELECT a.id, a.student_id, a.scholarship_name, a.apply_state,
             u.email, u.name AS student_name
      FROM Application a
      JOIN Scholarship s ON a.scholarship_name = s.name
      JOIN User u ON a.student_id = u.id
      WHERE s.end_date < CURDATE()
        AND a.apply_state IN ('Approved', 'Rejected')
        AND (a.result_notified IS NULL OR a.result_notified = 0)
    `);
    for (const app of expired) {
      const label = app.apply_state === 'Approved' ? '審核通過' : '審核不通過';
      const color = app.apply_state === 'Approved' ? '#22c55e' : '#ef4444';
      const html = `
        <div style="font-family:sans-serif;max-width:480px;margin:auto;padding:24px;border:1px solid #e5e7eb;border-radius:12px;">
          <h2 style="color:#667eea;margin-top:0;">ScholarLink 獎學金結果通知</h2>
          <p>您好 <strong>${app.student_name}</strong>，</p>
          <p>「<strong>${app.scholarship_name}</strong>」獎學金已截止，您的申請結果：</p>
          <div style="font-size:24px;font-weight:bold;color:${color};padding:16px;background:#f5f5f5;border-radius:8px;text-align:center;">${label}</div>
          <p style="color:#9ca3af;font-size:12px;margin-top:16px;">如有疑問請透過校內系統聯繫相關單位。</p>
        </div>
      `;
      const sent = await sendEmail(app.email, `ScholarLink 獎學金截止通知：${label}`, html).catch(() => false);
      if (sent !== false) {
        await promisePool.query('UPDATE Application SET result_notified = 1 WHERE id = ?', [app.id]).catch(() => {});
      }
    }
    if (expired.length > 0) {
      console.log(`[截止通知] 已處理 ${expired.length} 筆截止申請通知`);
    }
  } catch (e) {
    console.error('[截止通知] 檢查失敗:', e.message);
  }
}, 60 * 60 * 1000);

// 優雅關閉
process.on('SIGINT', () => {
  console.log('\n⏳ 正在關閉伺服器...');
  pool.end((err) => {
    if (err) {
      console.error('關閉資料庫連接池時發生錯誤:', err);
    } else {
      console.log('✅ 資料庫連接池已關閉');
    }
    process.exit(0);
  });
});
