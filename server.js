const express = require('express');
const mysql = require('mysql2');
const cors = require('cors');
const bodyParser = require('body-parser');
require('dotenv').config();

const app = express();
const PORT = process.env.PORT || 3000;

// 中介層設定
app.use(cors());
app.use(bodyParser.json());
app.use(bodyParser.urlencoded({ extended: true }));
app.use(express.static('.')); // 提供靜態HTML檔案

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
// 通用：使用者查詢
// ============================================

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
        a.rank
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
        GROUP_CONCAT(u.name SEPARATOR ', ') AS organizations
      FROM Scholarship s
      LEFT JOIN Scholarship_Organization so ON s.name = so.scholarship_name
      LEFT JOIN Organization o ON so.organization_id = o.id
      LEFT JOIN User u ON o.id = u.id
      ${isAdminView ? '' : 'WHERE s.is_published = TRUE'}
      GROUP BY s.name, s.amount, s.description, s.identity_restriction, s.is_published, s.published_by, s.published_at
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
    const { name, amount, description, organization_id, identity_restriction, publish, admin_id } = req.body;

    if (!name || !amount) {
      return res.status(400).json({ success: false, message: 'name 與 amount 為必填欄位' });
    }

    await connection.beginTransaction();

    // 建立獎學金
    await connection.query(
      `INSERT INTO Scholarship (name, amount, description, identity_restriction, is_published, published_by, published_at)
       VALUES (?, ?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE amount = VALUES(amount), description = VALUES(description), identity_restriction = VALUES(identity_restriction)`,
      [
        name,
        amount,
        description || null,
        identity_restriction || null,
        publish ? 1 : 0,
        publish ? (admin_id || null) : null,
        publish ? new Date() : null
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

// 新增獎學金申請
app.post('/api/application', async (req, res) => {
  try {
    const { student_id, scholarship_name, apply_way, score, gpa, family_income } = req.body;

    const applicationId = 'APP' + Date.now();
    
    await promisePool.query(`
      INSERT INTO Application 
      (id, student_id, scholarship_name, apply_way, apply_state, score, gpa, family_income)
      VALUES (?, ?, ?, ?, 'Pending', ?, ?, ?)
    `, [applicationId, student_id, scholarship_name, apply_way, score, gpa, family_income]);

    res.json({
      success: true,
      message: '申請提交成功',
      applicationId
    });
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
      ORDER BY m.created_at DESC
    `, [viewerId, viewerId]);

    res.json({ success: true, data: rows });
  } catch (error) {
    console.error('取得留言列表錯誤:', error);
    res.status(500).json({ success: false, message: '伺服器錯誤', error: error.message });
  }
});

// POST /api/messages - 新增留言
app.post('/api/messages', async (req, res) => {
  try {
    const { user_id, title, content, visibility, target_user_id } = req.body;

    if (!user_id || !title || !content) {
      return res.status(400).json({ success: false, message: 'user_id、title 與 content 為必填欄位' });
    }
    const vis = visibility || 'Public';
    if (!['Public', 'Private'].includes(vis)) {
      return res.status(400).json({ success: false, message: "visibility 必須為 'Public' 或 'Private'" });
    }
    if (vis === 'Private' && !target_user_id) {
      return res.status(400).json({ success: false, message: '私密留言必須指定 target_user_id' });
    }

    // SystemAdministrator 不可發言
    const [userRows] = await promisePool.query('SELECT type FROM User WHERE id = ?', [user_id]);
    if (userRows.length === 0) {
      return res.status(404).json({ success: false, message: '找不到使用者' });
    }
    if (userRows[0].type === 'SystemAdministrator') {
      return res.status(403).json({ success: false, message: '系統管理員不可發表留言' });
    }

    const messageId = 'MSG' + Date.now();
    await promisePool.query(
      'INSERT INTO Message (id, user_id, title, content, visibility, target_user_id) VALUES (?, ?, ?, ?, ?, ?)',
      [messageId, user_id, title, content, vis, target_user_id || null]
    );

    res.json({ success: true, message: '留言發布成功', data: { id: messageId } });
  } catch (error) {
    console.error('新增留言錯誤:', error);
    res.status(500).json({ success: false, message: '新增留言失敗', error: error.message });
  }
});

// PUT /api/messages/:id/reply - 回覆留言（智慧回覆權限防禦）
app.put('/api/messages/:id/reply', async (req, res) => {
  try {
    const messageId = req.params.id;
    const { reply_user_id, reply_content } = req.body;

    if (!reply_user_id || !reply_content) {
      return res.status(400).json({ success: false, message: 'reply_user_id 與 reply_content 為必填欄位' });
    }

    const [msgRows] = await promisePool.query('SELECT user_id FROM Message WHERE id = ?', [messageId]);
    if (msgRows.length === 0) {
      return res.status(404).json({ success: false, message: '找不到指定留言' });
    }
    const messageOwnerId = msgRows[0].user_id;

    const [userRows] = await promisePool.query('SELECT type FROM User WHERE id = ?', [reply_user_id]);
    if (userRows.length === 0) {
      return res.status(404).json({ success: false, message: '找不到回覆者' });
    }
    const replyUserType = userRows[0].type;

    const isTeacherOrOrg = replyUserType === 'Teacher' || replyUserType === 'Organization';
    const isOriginalAuthor = reply_user_id === messageOwnerId;

    if (!isTeacherOrOrg && !isOriginalAuthor) {
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
    const finalStatus   = status   || '代辦';
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
// 啟動伺服器
// ============================================

app.listen(PORT, () => {
  console.log(`🚀 伺服器運行在 http://localhost:${PORT}`);
  console.log(`📄 學生個人資料頁面: http://localhost:${PORT}/student_profile.html`);
  console.log(`📄 學生首頁: http://localhost:${PORT}/student.html`);
});

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
