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
    // Application 工作流程欄位
    "ALTER TABLE Application ADD COLUMN requires_recommendation TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否需要導師推薦'" ,
    "ALTER TABLE Application ADD COLUMN reject_reason TEXT NULL COMMENT '退件原因'",
    "ALTER TABLE Application ADD COLUMN result_notified TINYINT(1) NOT NULL DEFAULT 0 COMMENT '截止結果是否已寄通知'",
    // Scholarship 必備文件欄位
    "ALTER TABLE Scholarship ADD COLUMN required_documents VARCHAR(255) NULL COMMENT '申請必備檔案類別，逗號分隔'",
    // Scholarship 申請日期欄位（若尚未存在）
    "ALTER TABLE Scholarship ADD COLUMN start_date DATE NULL COMMENT '申請開始日期'",
    "ALTER TABLE Scholarship ADD COLUMN end_date DATE NULL COMMENT '申請截止日期'",
];

echo "<pre style='font-family:monospace;font-size:14px;padding:24px;'>";
echo "ScholarLink Migration\n";
echo str_repeat('─', 60) . "\n\n";

$ok = 0; $skip = 0;
foreach ($migrations as $sql) {
    // 擷取欄位名稱用於顯示
    preg_match('/ADD COLUMN (\w+)/', $sql, $m);
    $col = $m[1] ?? $sql;
    try {
        $pdo->exec($sql);
        echo "  ✅ 新增欄位：$col\n";
        $ok++;
    } catch (PDOException $e) {
        if ($e->getCode() === '42S21') {        // Duplicate column — 已存在，跳過
            echo "  ⏭  已存在（跳過）：$col\n";
            $skip++;
        } else {
            echo "  ❌ 失敗：$col — " . $e->getMessage() . "\n";
        }
    }
}

echo "\n" . str_repeat('─', 60) . "\n";
echo "完成：新增 {$ok} 個欄位，跳過 {$skip} 個（已存在）。\n\n";
echo "現在可至 <a href='/scholarship/pages/login.html'>登入頁</a> 測試。\n";
echo "⚠️  完成後請刪除此檔案（run_migration.php）。\n";
echo "</pre>";
