/**
 * 一次性初始化腳本：建立系統管理員測試帳號
 * 執行方式：node create_admin.js
 * 執行完成後請刪除此檔案以避免安全疑慮。
 */
require('dotenv').config();
const mysql  = require('mysql2/promise');
const bcrypt = require('bcryptjs');

const PLAIN_PASSWORD = 'Admin@2026';

async function main() {
  const pool = await mysql.createPool({
    host:     process.env.DB_HOST     || 'localhost',
    user:     process.env.DB_USER     || 'root',
    password: process.env.DB_PASSWORD || '',
    database: process.env.DB_NAME     || 'scholarship_system',
    charset:  'utf8mb4',
  });

  try {
    const hashed = bcrypt.hashSync(PLAIN_PASSWORD, 12);

    // 寫入 User 表
    await pool.execute(`
      INSERT INTO User (id, name, email, type, password, email_verified, verification_code)
      VALUES (?, ?, ?, 'SystemAdministrator', ?, 1, NULL)
      ON DUPLICATE KEY UPDATE
        name              = VALUES(name),
        email             = VALUES(email),
        password          = VALUES(password),
        email_verified    = 1,
        verification_code = NULL
    `, ['ADMIN002', '模擬管理員', 'mockadmin@nuk.edu.tw', hashed]);

    // 寫入 System_Administrator 擴充表
    await pool.execute(`
      INSERT INTO System_Administrator (id)
      VALUES (?)
      ON DUPLICATE KEY UPDATE id = id
    `, ['ADMIN002']);

    console.log('✅ 管理員帳號建立成功！\n');
    console.log('  帳號 ID  : ADMIN002');
    console.log('  姓名     : 系統管理員');
    console.log('  Email    : mockadmin@nuk.edu.tw');
    console.log(`  密碼     : ${PLAIN_PASSWORD}`);
    console.log('  類型     : SystemAdministrator');
    console.log('  已驗證   : 是\n');
    console.log('⚠️  請在驗證完成後刪除此檔案（create_admin.js）。');
  } finally {
    await pool.end();
  }
}

main().catch(err => {
  console.error('❌ 建立失敗：', err.message);
  process.exit(1);
});
