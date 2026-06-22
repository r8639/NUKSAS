<?php
// 添加 file_path 欄位到 Recommendation 表
header('Content-Type: application/json; charset=utf-8');

// 檢查本機是否有設定檔，有的話用設定檔，沒有就用預設值
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
    $host = DB_HOST;
    $dbname = DB_NAME;
    $username = DB_USER;
    $password = DB_PASS;
} else {
    $host = '127.0.0.1';
    $dbname = 'scholarship_system';
    $username = 'root';
    $password = '';
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    $pdo->exec("ALTER TABLE Recommendation ADD COLUMN file_path VARCHAR(255) NULL");
    echo json_encode(['success' => true, 'message' => '已添加 file_path 欄位']);
} catch(PDOException $e) {
    if(strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo json_encode(['success' => true, 'message' => '欄位已存在']);
    } else {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
?>
