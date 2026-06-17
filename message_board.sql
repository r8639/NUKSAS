-- 師生留言板資料表建立腳本
-- 執行方式：透過 phpMyAdmin 或 MySQL CLI 執行
-- mysql -u root scholarship_system < message_board.sql

USE scholarship_system;

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
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='師生留言板';
