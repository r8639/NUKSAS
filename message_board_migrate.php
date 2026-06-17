<?php
// 師生留言板資料庫遷移腳本
// 執行方式：瀏覽器開啟 http://localhost/scholarship/message_board_migrate.php
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
CREATE TABLE IF NOT EXISTS Message (
  id             VARCHAR(50)  NOT NULL,
  user_id        VARCHAR(50)  NOT NULL        COMMENT '發起留言者ID',
  title          VARCHAR(255) NOT NULL        COMMENT '標題',
  content        TEXT         NOT NULL        COMMENT '留言內容',
  visibility     VARCHAR(10)  NOT NULL DEFAULT 'Public' COMMENT '可見度: Public 或 Private',
  target_user_id VARCHAR(50)  NULL            COMMENT '指定查看者ID（visibility=Private 時使用）',
  reply_content  TEXT         NULL            COMMENT '回覆內容',
  reply_user_id  VARCHAR(50)  NULL            COMMENT '回覆者ID',
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '留言建立時間',
  replied_at     TIMESTAMP    NULL            COMMENT '回覆時間',
  PRIMARY KEY (id),
  CONSTRAINT fk_message_user
    FOREIGN KEY (user_id)        REFERENCES User(id) ON DELETE CASCADE,
  CONSTRAINT fk_message_target
    FOREIGN KEY (target_user_id) REFERENCES User(id) ON DELETE SET NULL,
  CONSTRAINT fk_message_reply_user
    FOREIGN KEY (reply_user_id)  REFERENCES User(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='師生留言板'
";

try {
    $pdo->exec($sql);
    echo '<p style="color:green; font-family:sans-serif;">✅ Message 資料表建立成功（或已存在）。</p>';
    echo '<p style="font-family:sans-serif;"><a href="/scholarship/pages/login.html">← 返回登入頁</a></p>';
} catch (PDOException $e) {
    echo '<p style="color:red; font-family:sans-serif;">❌ 建立失敗：' . $e->getMessage() . '</p>';
}
?>
