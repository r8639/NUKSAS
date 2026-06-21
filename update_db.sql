-- 更新 Scholarship 表添加發放狀態欄位
USE scholarship_system;

-- =============================================
-- 科系隔離：為 User 表新增 department 欄位
-- 執行一次即可；若欄位已存在會收到 1060 錯誤，可忽略
-- =============================================
ALTER TABLE User ADD COLUMN department VARCHAR(100) NULL COMMENT '所屬科系（Student/Teacher 必填）';


-- 添加 is_published 欄位
ALTER TABLE Scholarship ADD COLUMN is_published TINYINT(1) DEFAULT 0 COMMENT '是否已發放';

-- 添加 published_by 欄位  
ALTER TABLE Scholarship ADD COLUMN published_by VARCHAR(50) NULL COMMENT '發放機構ID';

-- 添加 published_at 欄位
ALTER TABLE Scholarship ADD COLUMN published_at TIMESTAMP NULL DEFAULT NULL COMMENT '發放時間';

-- 將現有獎學金設為已發放
UPDATE Scholarship SET is_published = 1, published_at = NOW();

-- 查看更新後的表結構
DESCRIBE Scholarship;

-- =============================================
-- 步驟一：密碼、Email 驗證與 OTP 欄位
-- 執行一次即可；若欄位已存在會收到 1060 錯誤，可忽略
-- =============================================

-- 1. 儲存 bcrypt 加鹽雜湊密碼
ALTER TABLE User ADD COLUMN password VARCHAR(255) NULL COMMENT 'bcrypt 加鹽雜湊密碼';

-- 2. 記錄 Gmail 是否通過實體收信驗證（0=未驗證, 1=已驗證）
ALTER TABLE User ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Email 驗證狀態';

-- 3. 暫存 6 位數 OTP 驗證碼（核對後清除）
ALTER TABLE User ADD COLUMN verification_code VARCHAR(6) NULL COMMENT '6位數 Email OTP 驗證碼';

-- =============================================
-- 遷移現有帳號：設為已驗證（保留既有帳號可正常使用）
-- 新帳號一律從 email_verified = 0 開始，需完成 OTP 驗證
-- =============================================
UPDATE User SET email_verified = 1 WHERE email_verified = 0 AND password IS NULL;

DESCRIBE User;

-- =============================================
-- 佐證檔案分類：為 Identity_Proof 表新增欄位
-- 執行一次即可；若欄位已存在會收到 1060 錯誤，可忽略
-- =============================================

-- 檔案類別：限定 identity_proof / transcript / award_certificate
ALTER TABLE Identity_Proof
  ADD COLUMN file_category VARCHAR(50) NOT NULL DEFAULT 'identity_proof'
  COMMENT '檔案類別：identity_proof(身份證明) / transcript(成績單) / award_certificate(參賽得獎)';

-- MIME 類型：供前端判斷預覽方式（image/* → img 標籤；application/pdf → iframe）
ALTER TABLE Identity_Proof
  ADD COLUMN file_mime VARCHAR(100) NULL
  COMMENT '檔案 MIME 類型，例：image/jpeg、application/pdf';

-- 原始檔案名稱（供前端顯示）
ALTER TABLE Identity_Proof
  ADD COLUMN file_name VARCHAR(255) NULL
  COMMENT '上傳時的原始檔案名稱';

-- 檔案大小（bytes）
ALTER TABLE Identity_Proof
  ADD COLUMN file_size INT NULL
  COMMENT '檔案大小（bytes）';

-- 上傳時間
ALTER TABLE Identity_Proof
  ADD COLUMN uploaded_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
  COMMENT '上傳時間';

DESCRIBE Identity_Proof;

-- =============================================
-- 工作流程重構：申請狀態機、退件補件、必要文件
-- 執行一次即可；若欄位已存在會收到 1060 錯誤，可忽略
-- =============================================

-- Application 表：是否需要導師推薦
ALTER TABLE Application
  ADD COLUMN requires_recommendation TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '是否需要導師推薦信（1=需要，進入 pending_tutor 流程；0=不需要，直接進入 pending_review）';

-- Application 表：退件原因（導師或機構退件時填寫）
ALTER TABLE Application
  ADD COLUMN reject_reason TEXT NULL
  COMMENT '退件原因（tutor_rejected / review_rejected 時由審查方填寫）';

-- Scholarship 表：申請所需文件清單（逗號分隔）
ALTER TABLE Scholarship
  ADD COLUMN required_documents VARCHAR(255) NULL
  COMMENT '申請必備檔案類別，逗號分隔，如 identity_proof,transcript,award_certificate';

-- Application 表：截止通知追蹤（避免重複寄信）
ALTER TABLE Application
  ADD COLUMN result_notified TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '截止結果是否已寄出通知 Email（1=已寄，0=尚未）';

DESCRIBE Application;
DESCRIBE Scholarship;
