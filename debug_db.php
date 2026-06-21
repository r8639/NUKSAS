<?php
/**
 * 資料庫診斷頁面
 * 用法：http://localhost/scholarship/debug_db.php
 * ⚠️ 測試完成後請刪除此檔案
 */
require_once __DIR__ . '/config.php';

header('Content-Type: text/html; charset=utf-8');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die('<pre style="color:red">❌ DB 連線失敗：' . $e->getMessage() . '</pre>');
}

$style = '
<style>
  body { font-family: monospace; background:#0f172a; color:#e5e7eb; padding:24px; }
  h2 { color:#22d3ee; margin-top:32px; }
  table { border-collapse:collapse; width:100%; margin-top:8px; font-size:13px; }
  th { background:#1f2937; color:#7dd3fc; padding:8px; text-align:left; }
  td { padding:7px 8px; border-bottom:1px solid #1f2937; }
  tr:hover { background:rgba(34,211,238,0.05); }
  .ok { color:#86efac; } .warn { color:#fcd34d; } .err { color:#f87171; }
  pre { background:#111827; padding:12px; border-radius:8px; overflow-x:auto; }
</style>';
echo $style;
echo '<h1 style="color:#7dd3fc">🔍 ScholarLink 資料庫診斷</h1>';

// ── 1. Application 表結構 ──────────────────────────────────
echo '<h2>1. Application 表結構（DESCRIBE）</h2>';
try {
    $cols = $pdo->query('DESCRIBE Application')->fetchAll();
    echo '<table><tr><th>欄位</th><th>型別</th><th>Null</th><th>預設值</th><th>Key</th></tr>';
    $hasApplyState = false;
    foreach ($cols as $c) {
        if ($c['Field'] === 'apply_state') $hasApplyState = true;
        $cls = ($c['Field'] === 'apply_state') ? ' class="ok"' : '';
        echo "<tr{$cls}><td>{$c['Field']}</td><td>{$c['Type']}</td><td>{$c['Null']}</td><td>" . ($c['Default'] ?? 'NULL') . "</td><td>{$c['Key']}</td></tr>";
    }
    echo '</table>';
    if (!$hasApplyState) {
        echo '<p class="err">❌ <strong>apply_state 欄位不存在！</strong>請先執行 run_migration.php</p>';
    } else {
        echo '<p class="ok">✅ apply_state 欄位存在</p>';
    }
} catch (PDOException $e) {
    echo '<p class="err">❌ ' . $e->getMessage() . '</p>';
}

// ── 2. 全部申請件（最近 20 筆）──────────────────────────────
echo '<h2>2. Application 最近 20 筆（全部狀態）</h2>';
try {
    $rows = $pdo->query('SELECT id, student_id, scholarship_name, apply_state, apply_date, requires_recommendation FROM Application ORDER BY apply_date DESC LIMIT 20')->fetchAll();
    if (!$rows) {
        echo '<p class="warn">⚠️ Application 表目前完全沒有資料</p>';
    } else {
        echo '<table><tr><th>id</th><th>student_id</th><th>scholarship_name</th><th>apply_state</th><th>requires_rec</th><th>apply_date</th></tr>';
        foreach ($rows as $r) {
            $stateClass = match(true) {
                $r['apply_state'] === 'pending_tutor' => ' style="color:#fcd34d"',
                $r['apply_state'] === 'pending_review' => ' style="color:#86efac"',
                str_contains($r['apply_state'] ?? '', 'reject') => ' style="color:#f87171"',
                default => ''
            };
            echo "<tr><td>{$r['id']}</td><td>{$r['student_id']}</td><td>{$r['scholarship_name']}</td><td{$stateClass}>{$r['apply_state']}</td><td>{$r['requires_recommendation']}</td><td>{$r['apply_date']}</td></tr>";
        }
        echo '</table>';
    }
} catch (PDOException $e) {
    echo '<p class="err">❌ ' . $e->getMessage() . '</p>';
}

// ── 3. 導師視角 ─────────────────────────────────────────────
echo '<h2>3. 導師視角（apply_state = pending_tutor）</h2>';
try {
    $pending_tutor = $pdo->query("
        SELECT a.id, a.student_id, u.name AS student_name, u.department,
               a.scholarship_name, a.apply_state, a.apply_date
        FROM Application a
        JOIN User u ON a.student_id = u.id
        WHERE a.apply_state = 'pending_tutor'
        ORDER BY a.apply_date DESC
    ")->fetchAll();
    if (!$pending_tutor) {
        echo '<p class="warn">⚠️ 目前沒有 pending_tutor 的申請</p>';
    } else {
        echo '<table><tr><th>id</th><th>student_id</th><th>student_name</th><th>department</th><th>scholarship_name</th><th>apply_date</th></tr>';
        foreach ($pending_tutor as $r) {
            echo "<tr><td>{$r['id']}</td><td>{$r['student_id']}</td><td>{$r['student_name']}</td><td>{$r['department']}</td><td>{$r['scholarship_name']}</td><td>{$r['apply_date']}</td></tr>";
        }
        echo '</table>';
    }
} catch (PDOException $e) {
    echo '<p class="err">❌ ' . $e->getMessage() . '</p>';
}

// ── 4. 審查單位視角 ──────────────────────────────────────────
echo '<h2>4. 審查單位視角（apply_state IN pending_review / Under Review / Pending）</h2>';
try {
    $pending_review = $pdo->query("
        SELECT a.id, a.student_id, u.name AS student_name, u.department,
               a.scholarship_name, a.apply_state, a.apply_date
        FROM Application a
        JOIN User u ON a.student_id = u.id
        WHERE a.apply_state IN ('pending_review', 'Under Review', 'Pending')
        ORDER BY a.apply_date DESC
    ")->fetchAll();
    if (!$pending_review) {
        echo '<p class="warn">⚠️ 目前沒有 pending_review / Under Review / Pending 的申請</p>';
    } else {
        echo '<table><tr><th>id</th><th>student_id</th><th>student_name</th><th>department</th><th>scholarship_name</th><th>apply_state</th><th>apply_date</th></tr>';
        foreach ($pending_review as $r) {
            echo "<tr><td>{$r['id']}</td><td>{$r['student_id']}</td><td>{$r['student_name']}</td><td>{$r['department']}</td><td>{$r['scholarship_name']}</td><td style='color:#86efac'>{$r['apply_state']}</td><td>{$r['apply_date']}</td></tr>";
        }
        echo '</table>';
    }
} catch (PDOException $e) {
    echo '<p class="err">❌ ' . $e->getMessage() . '</p>';
}

// ── 5. 全體使用者（科系欄位）──────────────────────────────────
echo '<h2>5. User 表科系分布（Student / Teacher）</h2>';
try {
    $users = $pdo->query("
        SELECT id, name, type, department
        FROM User
        WHERE type IN ('Student','Teacher')
        ORDER BY type, department
    ")->fetchAll();
    echo '<table><tr><th>id</th><th>name</th><th>type</th><th>department</th></tr>';
    foreach ($users as $u) {
        $deptDisplay = $u['department'] ? htmlspecialchars($u['department']) : '<span class="err">（NULL）</span>';
        $hasSpace = ($u['department'] !== trim($u['department'] ?? ''));
        echo "<tr><td>{$u['id']}</td><td>{$u['name']}</td><td>{$u['type']}</td><td>{$deptDisplay}" . ($hasSpace ? ' <span class="err">⚠️前後有空格</span>' : '') . "</td></tr>";
    }
    echo '</table>';
} catch (PDOException $e) {
    echo '<p class="err">❌ ' . $e->getMessage() . '</p>';
}

echo '<hr style="border-color:#1f2937;margin-top:32px;"><p style="color:#4b5563;font-size:12px;">⚠️ 診斷完成後請刪除 debug_db.php</p>';
