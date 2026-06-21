<?php
/**
 * 一次性資料庫 Migration 腳本
 * 執行方式：瀏覽器開啟 http://localhost/scholarship/run_migration.php
 * 執行完成後可刪除此檔案。
 */
require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("<pre style='color:red'>❌ 資料庫連線失敗：" . $e->getMessage() . "</pre>");
}

$migrations = [
    // ── 申請狀態欄位（若欄位不存在則建立，MODIFY 步驟會修正型別）
    "ALTER TABLE Application ADD COLUMN apply_state VARCHAR(30) NOT NULL DEFAULT 'pending_review' COMMENT '申請狀態'",
    // ── 將 apply_state 改為完整 ENUM，涵蓋新舊所有狀態碼（最關鍵！）
    "ALTER TABLE Application MODIFY COLUMN apply_state ENUM('Pending','Approved','Rejected','Under Review','pending_tutor','pending_review','tutor_rejected','review_rejected') NOT NULL DEFAULT 'Pending'",
    // ── 修補舊的空字串記錄：凡 apply_state 為空，一律補為 pending_review
    "UPDATE Application SET apply_state = 'pending_review' WHERE apply_state = '' OR apply_state IS NULL",
    // Application 工作流程欄位
    "ALTER TABLE Application ADD COLUMN requires_recommendation TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否需要導師推薦'",
    "ALTER TABLE Application ADD COLUMN reject_reason TEXT NULL COMMENT '退件原因'",
    "ALTER TABLE Application ADD COLUMN result_notified TINYINT(1) NOT NULL DEFAULT 0 COMMENT '截止結果是否已寄通知'",
    // Scholarship 必備文件欄位
    "ALTER TABLE Scholarship ADD COLUMN required_documents VARCHAR(255) NULL COMMENT '申請必備檔案類別，逗號分隔'",
    // Scholarship 申請日期欄位（若尚未存在）
    "ALTER TABLE Scholarship ADD COLUMN start_date DATE NULL COMMENT '申請開始日期'",
    "ALTER TABLE Scholarship ADD COLUMN end_date DATE NULL COMMENT '申請截止日期'",
    // ── 防重複申請前置清理：刪除重複申請，每個（學生 + 獎學金）只保留 apply_date 最新一筆
    "DELETE FROM Application WHERE id NOT IN (
        SELECT id FROM (
            SELECT MAX(id) AS id FROM Application GROUP BY student_id, scholarship_name
        ) AS _keep
    )",
    // ── 防重複申請唯一索引（清理重複後才能建立）
    "ALTER TABLE Application ADD UNIQUE KEY unique_student_scholarship (student_id, scholarship_name)",
    // Identity_Proof 補充欄位（file_name / file_size / uploaded_at）
    "ALTER TABLE Identity_Proof ADD COLUMN file_name VARCHAR(255) NULL COMMENT '原始檔案名稱'",
    "ALTER TABLE Identity_Proof ADD COLUMN file_size INT NULL COMMENT '檔案大小（bytes）'",
    "ALTER TABLE Identity_Proof ADD COLUMN uploaded_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP COMMENT '上傳時間'",
    // 留言板串接回覆
    "ALTER TABLE Message ADD COLUMN parent_id VARCHAR(50) NULL DEFAULT NULL COMMENT '父留言 ID，NULL 代表根留言'",
    // 通知中心資料表
    "CREATE TABLE IF NOT EXISTS Notification (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    VARCHAR(50)  NOT NULL,
        title      VARCHAR(100) NOT NULL,
        content    TEXT         NOT NULL,
        target_url VARCHAR(255) NOT NULL DEFAULT '',
        is_read    TINYINT(1)   NOT NULL DEFAULT 0,
        created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_read (user_id, is_read)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

echo "<pre style='font-family:monospace;font-size:14px;padding:24px;background:#0f172a;color:#e5e7eb;'>";
echo "ScholarLink Migration\n";
echo str_repeat('─', 60) . "\n\n";

$ok = 0; $skip = 0; $errors = 0;
foreach ($migrations as $sql) {
    // 擷取欄位名稱或表名用於顯示
    $sqlTrim = trim(preg_replace('/\s+/', ' ', $sql));
    if (preg_match('/ADD COLUMN (\w+)/', $sql, $m)) {
        $label = '欄位：' . $m[1];
    } elseif (preg_match('/MODIFY COLUMN (\w+)/', $sql, $m)) {
        $label = 'ENUM 修正：' . $m[1];
    } elseif (preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $sql, $m)) {
        $label = '資料表：' . $m[1];
    } elseif (preg_match('/ADD UNIQUE KEY (\w+)/', $sql, $m)) {
        $label = '唯一索引：' . $m[1];
    } elseif (preg_match('/^DELETE FROM (\w+)/i', $sqlTrim, $m)) {
        $label = '清理重複：' . $m[1];
    } elseif (preg_match('/^UPDATE (\w+)/i', $sqlTrim, $m)) {
        $label = '資料修補：' . $m[1];
    } else {
        $label = substr($sqlTrim, 0, 60) . '…';
    }
    try {
        $affected = $pdo->exec($sql);
        $head = strtoupper(substr(trim($sql), 0, 6));
        if ($head === 'DELETE') {
            echo "  ✅ {$label}（刪除 {$affected} 筆重複資料）\n";
        } elseif ($head === 'UPDATE') {
            echo "  ✅ {$label}（修補 {$affected} 筆空狀態記錄）\n";
        } else {
            echo "  ✅ 建立 {$label}\n";
        }
        $ok++;
    } catch (PDOException $e) {
        $code = $e->getCode();
        // 42S21 = Duplicate column name（欄位已存在）
        // 42000 + errorInfo[1]=1061 = Duplicate key name（索引已存在）
        // 23000 = 仍有重複資料，無法建立唯一索引
        $info = $e->errorInfo ?? [];
        $mysqlCode = $info[1] ?? 0;

        if ($code === '42S21') {
            echo "  ⏭  已存在（跳過）：{$label}\n";
            $skip++;
        } elseif ($code === '42000' && $mysqlCode == 1061) {
            echo "  ⏭  索引已存在（跳過）：{$label}\n";
            $skip++;
        } elseif ($code === '23000') {
            echo "  ❌ 仍有重複資料，無法建立唯一索引：{$label}\n";
            echo "     → " . $e->getMessage() . "\n";
            echo "     → 請先至 debug_db.php 確認重複資料後手動清理\n";
            $errors++;
        } else {
            echo "  ❌ 失敗：{$label} — {$e->getMessage()}\n";
            $errors++;
        }
    }
}

echo "\n" . str_repeat('─', 60) . "\n";
echo "完成：✅ {$ok} 項，⏭ 跳過 {$skip} 項，❌ 失敗 {$errors} 項。\n\n";
if ($errors === 0) {
    echo "🎉 所有 Migration 執行成功！\n";
    echo "現在可至 <a href='/scholarship/pages/login.html'>登入頁</a> 測試。\n";
} else {
    echo "⚠️  有 {$errors} 項失敗，請依上述提示排查。\n";
    echo "診斷頁面：<a href='/scholarship/debug_db.php'>debug_db.php</a>\n";
}
echo "\n⚠️  完成後請刪除此檔案（run_migration.php）。\n";
echo "</pre>";
