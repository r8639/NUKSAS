<?php
/**
 * 一次性初始化腳本：建立系統管理員測試帳號
 * 執行方式：瀏覽器開啟 http://localhost/scholarship/create_admin.php
 *           或 CLI: php create_admin.php
 * 執行完成後請刪除此檔案以避免安全疑慮。
 */
require_once __DIR__ . '/config.php';

$PLAIN_PASSWORD = 'Admin@2026';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $hashed = password_hash($PLAIN_PASSWORD, PASSWORD_DEFAULT);

    // 寫入 User 表（ON DUPLICATE KEY UPDATE 防止重複執行噴錯）
    $stmt = $pdo->prepare("
        INSERT INTO User (id, name, email, type, password, email_verified, verification_code)
        VALUES (:id, :name, :email, 'SystemAdministrator', :password, 1, NULL)
        ON DUPLICATE KEY UPDATE
            name               = VALUES(name),
            email              = VALUES(email),
            password           = VALUES(password),
            email_verified     = 1,
            verification_code  = NULL
    ");
    $stmt->execute([
        ':id'       => 'ADMIN002',
        ':name'     => '模擬管理員',
        ':email'    => 'mockadmin@nuk.edu.tw',
        ':password' => $hashed,
    ]);

    // 寫入 System_Administrator 擴充表
    $stmt2 = $pdo->prepare("
        INSERT INTO System_Administrator (id)
        VALUES (:id)
        ON DUPLICATE KEY UPDATE id = id
    ");
    $stmt2->execute([':id' => 'ADMIN002']);

    echo "<pre style='font-family:monospace;font-size:14px;padding:24px;'>";
    echo "✅ 管理員帳號建立成功！\n\n";
    echo "  帳號 ID  : ADMIN002\n";
    echo "  姓名     : 模擬管理員\n";
    echo "  Email    : mockadmin@nuk.edu.tw\n";
    echo "  密碼     : {$PLAIN_PASSWORD}\n";
    echo "  類型     : SystemAdministrator\n";
    echo "  已驗證   : 是\n\n";
    echo "現在可至 <a href='/scholarship/pages/login.html'>/scholarship/pages/login.html</a> 登入測試。\n";
    echo "\n⚠️  請在驗證完成後刪除此檔案（create_admin.php）。\n";
    echo "</pre>";

} catch (PDOException $e) {
    http_response_code(500);
    echo "<pre style='color:red;padding:24px;'>";
    echo "❌ 建立失敗：" . htmlspecialchars($e->getMessage()) . "\n";
    echo "</pre>";
}
