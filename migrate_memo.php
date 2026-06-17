<?php
// 管理員備忘錄資料庫遷移腳本
// 執行方式：瀏覽器開啟 http://localhost/scholarship/migrate_memo.php
//           或命令列執行 php migrate_memo.php
header('Content-Type: text/html; charset=utf-8');

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
    $host = DB_HOST; $dbname = DB_NAME; $username = DB_USER; $password = DB_PASS;
} else {
    $host = 'localhost'; $dbname = 'scholarship_system'; $username = 'root'; $password = '';
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('<p style="color:red">❌ 資料庫連接失敗：' . $e->getMessage() . '</p>');
}

$sql = "
CREATE TABLE IF NOT EXISTS Admin_Memo (
  id            VARCHAR(50)  NOT NULL                    COMMENT '備忘錄ID（MEMO+時間戳記）',
  admin_id      VARCHAR(50)  NOT NULL                    COMMENT '管理員ID',
  title         VARCHAR(255) NOT NULL                    COMMENT '標題',
  content       TEXT         NULL                        COMMENT '內容',
  priority      VARCHAR(20)  NOT NULL DEFAULT '重要'     COMMENT '優先級：緊急/重要/沒那麼重要',
  status        VARCHAR(20)  NOT NULL DEFAULT '代辦'     COMMENT '狀態：代辦/完成',
  reminder_date DATE         NULL                        COMMENT '提醒日期',
  created_time  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
  PRIMARY KEY (id),
  CONSTRAINT fk_admin_memo_admin
    FOREIGN KEY (admin_id) REFERENCES User(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理員備忘錄'
";

try {
    $pdo->exec($sql);
    echo '<p style="color:green; font-family:sans-serif;">✅ Admin_Memo 資料表建立成功（或已存在）。</p>';
    echo '<p style="font-family:sans-serif;"><a href="/scholarship/pages/admin_memos.html">← 前往備忘錄頁面</a></p>';
} catch (PDOException $e) {
    echo '<p style="color:red; font-family:sans-serif;">❌ 建立失敗：' . $e->getMessage() . '</p>';
}
?>
