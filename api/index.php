<?php
// 資料庫連接設定
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
 

// 載入資料庫配置
if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
    $host = DB_HOST;
    $dbname = DB_NAME;
    $username = DB_USER;
    $password = DB_PASS;
} else {
    // 預設配置（XAMPP）
    $host = 'localhost';
    $dbname = 'scholarship_system';
    $username = 'root';
    $password = '';
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // 遷移：添加 file_path 欄位（如果不存在）
    try {
        $pdo->exec("ALTER TABLE Recommendation ADD COLUMN file_path VARCHAR(255) NULL");
    } catch(PDOException $e) {
        // 欄位可能已存在，忽略錯誤
    }
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => '資料庫連接失敗: ' . $e->getMessage()
    ]);
    exit();
}

// 路由處理
$method = $_SERVER['REQUEST_METHOD'];
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);

// 移除基礎路徑，支援多種格式
$path = str_replace('/scholarship/api/index.php', '', $path);
$path = str_replace('/scholarship/api', '', $path);
$segments = array_values(array_filter(explode('/', trim($path, '/'))));

// 身分別正規化：將同義詞映射為一致的標籤
function normalize_identity($identity) {
    if ($identity === null) return null;
    $identity = trim($identity);
    $map = [
        '低收入戶' => '清寒',
        '低收' => '清寒',
        '清寒' => '清寒',
        '原住民' => '原住民',
        '僑生' => '僑生',
        '身心障礙' => '身心障礙',
    ];
    return $map[$identity] ?? $identity;
}

// ============================================================
// 郵件發信模組（Mock 模式 — 不連線 SMTP，直接寫入 Apache error log）
// ============================================================

function sendEmail($to, $subject, $html) {
    $ts       = date('Y-m-d H:i:s');
    $plain    = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
    $summary  = mb_strlen($plain) > 120 ? mb_substr($plain, 0, 120) . '…' : $plain;

    // 從 HTML 提取 6 位數驗證碼（若有）
    $otpLine = '';
    if (preg_match('/\b(\d{6})\b/', $html, $m)) {
        $otpLine = "\n  OTP     : {$m[1]}  ← 複製此碼貼到網頁";
    }

    error_log("┌──────────────────────────────────────────────────────");
    error_log("│  [MOCK EMAIL NOTIFICATION]  $ts");
    error_log("│  收件人 : $to");
    error_log("│  主旨   : $subject");
    error_log("│  摘要   : $summary$otpLine");
    error_log("└──────────────────────────────────────────────────────");
    return true;
}

function debugOTP(string $context, string $userId, string $otp): void {
    error_log("========== [OTP DEBUG LOG] ==========");
    error_log("  場景    : $context");
    error_log("  帳號 ID : $userId");
    error_log("  驗證碼  : $otp");
    error_log("=====================================");
}

function generateOTP() {
    return str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
}

// 通用 Email 格式正則（放寬為任意網域）
define('EMAIL_REGEX', '/^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/');

// ============================================================
// 路由: POST /login - 登入驗證（LoginManager C002）
// ============================================================
if ($method === 'POST' && isset($segments[0]) && $segments[0] === 'login') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id       = $data['id']       ?? null;
    $password = $data['password'] ?? null;

    if (!$id || !$password) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '請輸入帳號與密碼']);
        exit();
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT id, name, email, type, password AS pwd, email_verified FROM User WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        if (!$user) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => '帳號或密碼錯誤']);
            exit();
        }

        $passwordMatch = false;
        if (!empty($user['pwd'])) {
            // 標準路徑：bcrypt 比對
            $passwordMatch = password_verify($password, $user['pwd']);
        } else {
            // 遷移路徑：舊帳號接受 {id}2025，並自動升級為雜湊
            if ($password === $id . '2025') {
                $passwordMatch = true;
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $upd = $pdo->prepare('UPDATE User SET password = ? WHERE id = ?');
                $upd->execute([$hash, $id]);
            }
        }

        if (!$passwordMatch) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => '帳號或密碼錯誤']);
            exit();
        }

        // email_verified 攔截已移除（帳號密碼制，無需 Email 驗證）

        echo json_encode([
            'success' => true,
            'data'    => [
                'id'    => $user['id'],
                'name'  => $user['name'],
                'email' => $user['email'],
                'type'  => $user['type']
            ]
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '資料庫錯誤：' . $e->getMessage()]);
    }
    exit();
}

// 路由: GET /student/{id}
else if ($method === 'GET' && $segments[0] === 'student' && isset($segments[1]) && !isset($segments[2])) {
    $studentId = $segments[1];
    
    try {
        // 查詢學生基本資料
        $stmt = $pdo->prepare("
            SELECT 
                u.id,
                u.name,
                u.email,
                s.identity,
                s.major,
                GROUP_CONCAT(up.phone SEPARATOR ',') as phones
            FROM User u
            JOIN Student s ON u.id = s.id
            LEFT JOIN User_Phone up ON u.id = up.user_id
            WHERE u.id = ?
            GROUP BY u.id, u.name, u.email, s.identity, s.major
        ");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();

        if (!$student) {
            echo json_encode(['success' => false, 'message' => '找不到學生資料']);
            exit();
        }

        // 查詢身分別額外資訊
        $additionalInfo = [];
        if ($student['identity'] === '僑生') {
            $stmt = $pdo->prepare("SELECT * FROM Overseas_Student WHERE id = ?");
            $stmt->execute([$studentId]);
            $additionalInfo = $stmt->fetch() ?: [];
        }

        $student['phones'] = $student['phones'] ? explode(',', $student['phones']) : [];
        $student['additionalInfo'] = $additionalInfo;

        echo json_encode(['success' => true, 'data' => $student]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// 路由: GET /student/{id}/applications
else if ($method === 'GET' && $segments[0] === 'student' && isset($segments[1]) && isset($segments[2]) && $segments[2] === 'applications') {
    $studentId = $segments[1];

    try {
        $stmt = $pdo->prepare("
            SELECT
                a.id,
                a.apply_date,
                a.scholarship_name,
                s.amount,
                a.apply_state,
                a.score,
                a.gpa,
                a.requires_recommendation,
                a.reject_reason
            FROM Application a
            JOIN Scholarship s ON a.scholarship_name = s.name
            WHERE a.student_id = ?
            ORDER BY a.apply_date DESC
        ");
        $stmt->execute([$studentId]);
        $applications = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'data' => $applications]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// 路由: PUT /student/{id}
else if ($method === 'PUT' && $segments[0] === 'student' && isset($segments[1])) {
    $studentId = $segments[1];
    $data = json_decode(file_get_contents('php://input'), true);
    
    try {
        $pdo->beginTransaction();

        // 更新 User 表
        $stmt = $pdo->prepare("UPDATE User SET name = ?, email = ? WHERE id = ?");
        $stmt->execute([$data['name'], $data['email'], $studentId]);

        // 更新 Student 表
        $stmt = $pdo->prepare("UPDATE Student SET identity = ?, major = ? WHERE id = ?");
        $stmt->execute([$data['identity'], $data['major'], $studentId]);

        // 更新電話號碼
        $stmt = $pdo->prepare("DELETE FROM User_Phone WHERE user_id = ?");
        $stmt->execute([$studentId]);
        
        if (isset($data['phones']) && is_array($data['phones'])) {
            $stmt = $pdo->prepare("INSERT INTO User_Phone (user_id, phone) VALUES (?, ?)");
            foreach ($data['phones'] as $phone) {
                if (trim($phone)) {
                    $stmt->execute([$studentId, trim($phone)]);
                }
            }
        }

        // 更新僑生資訊
        if ($data['identity'] === '僑生' && isset($data['additionalInfo'])) {
            $info = $data['additionalInfo'];
            
            $stmt = $pdo->prepare("SELECT id FROM Overseas_Student WHERE id = ?");
            $stmt->execute([$studentId]);
            
            if ($stmt->fetch()) {
                $stmt = $pdo->prepare("
                    UPDATE Overseas_Student 
                    SET overseas_id = ?, chinese_certify = ?, immigrate_date = ?, passport_number = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $info['overseas_id'],
                    $info['chinese_certify'],
                    $info['immigrate_date'],
                    $info['passport_number'],
                    $studentId
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO Overseas_Student (id, overseas_id, chinese_certify, immigrate_date, passport_number)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $studentId,
                    $info['overseas_id'],
                    $info['chinese_certify'],
                    $info['immigrate_date'],
                    $info['passport_number']
                ]);
            }
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => '學生資料更新成功']);
    } catch(PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// 路由: GET /scholarships (學生端 - 只顯示已發放的)
else if ($method === 'GET' && $segments[0] === 'scholarships' && !isset($segments[1])) {
    try {
        // 檢查是否為管理員模式
        $isAdmin = isset($_GET['admin']) && $_GET['admin'] === 'true';
        $studentId = $_GET['student_id'] ?? null; // 學生ID用於身份過濾
        
        $sql = "
            SELECT
                s.name,
                s.amount,
                s.description,
                s.is_published,
                s.published_at,
                s.identity_restriction,
                s.start_date,
                s.end_date,
                s.required_documents,
                GROUP_CONCAT(u.name SEPARATOR ', ') as organizations
            FROM Scholarship s
            LEFT JOIN Scholarship_Organization so ON s.name = so.scholarship_name
            LEFT JOIN Organization o ON so.organization_id = o.id
            LEFT JOIN User u ON o.id = u.id
        ";
        
        // 學生端需要過濾
        if (!$isAdmin) {
            $sql .= " WHERE s.is_published = TRUE";
            
            // 如果提供了學生ID，根據身份過濾獎學金
            if ($studentId) {
                // 查詢學生身份
                $identityStmt = $pdo->prepare("SELECT identity FROM Student WHERE id = ?");
                $identityStmt->execute([$studentId]);
                $studentIdentity = $identityStmt->fetchColumn();
                $studentIdentity = normalize_identity($studentIdentity);
                
                if ($studentIdentity) {
                    // 可以看到一般獎學金(identity_restriction IS NULL)或符合自己身份的獎學金
                    $sql .= " AND (s.identity_restriction IS NULL OR s.identity_restriction = " . $pdo->quote($studentIdentity) . ")";
                } else {
                    // 沒有身份信息，只顯示一般獎學金
                    $sql .= " AND s.identity_restriction IS NULL";
                }
            } else {
                // 未登入或未提供學生ID，只顯示一般獎學金
                $sql .= " AND s.identity_restriction IS NULL";
            }
        }
        
        $sql .= " GROUP BY s.name, s.amount, s.description, s.is_published, s.published_at, s.identity_restriction, s.start_date, s.end_date, s.required_documents";
        
        $stmt = $pdo->query($sql);
        $scholarships = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'data' => $scholarships]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// 路由: POST /recommendation - 提交推薦信（純文字，無檔案）
else if ($method === 'POST' && $segments[0] === 'recommendation' && !isset($segments[1])) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    try {
        $recommendationId = 'REC' . time();
        
        $stmt = $pdo->prepare("
            INSERT INTO Recommendation (id, content, teacher_id, update_date)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([
            $recommendationId,
            isset($data['content']) ? $data['content'] : '',
            $data['teacher_id']
        ]);
        
        // 更新申請的推薦信ID
        if (isset($data['application_id'])) {
            $stmt = $pdo->prepare("UPDATE Application SET recommendation_id = ? WHERE id = ?");
            $stmt->execute([$recommendationId, $data['application_id']]);
        }
        
        echo json_encode(['success' => true, 'data' => ['id' => $recommendationId]]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// 路由: POST /scholarships/{name}/publish (發放獎學金)
else if ($method === 'POST' && $segments[0] === 'scholarships' && isset($segments[1]) && isset($segments[2]) && $segments[2] === 'publish') {
    $scholarshipName = urldecode($segments[1]);
    $data = json_decode(file_get_contents('php://input'), true);
    
    try {
        $stmt = $pdo->prepare("
            UPDATE Scholarship 
            SET is_published = TRUE, 
                published_by = ?, 
                published_at = NOW() 
            WHERE name = ?
        ");
        $stmt->execute([$data['admin_id'] ?? 'A001', $scholarshipName]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => '獎學金已發放']);
        } else {
            echo json_encode(['success' => false, 'message' => '找不到該獎學金']);
        }
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
// 路由: POST /scholarships/{name}/unpublish (下架獎學金)
else if ($method === 'POST' && $segments[0] === 'scholarships' && isset($segments[1]) && isset($segments[2]) && $segments[2] === 'unpublish') {
    $scholarshipName = urldecode($segments[1]);
    
    try {
        $stmt = $pdo->prepare("
            UPDATE Scholarship 
            SET is_published = FALSE, published_by = NULL, published_at = NULL 
            WHERE name = ?
        ");
        $stmt->execute([$scholarshipName]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => '獎學金已下架']);
        } else {
            echo json_encode(['success' => false, 'message' => '找不到該獎學金']);
        }
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
// 路由: DELETE /scholarships/{name} (刪除獎學金)
else if ($method === 'DELETE' && $segments[0] === 'scholarships' && isset($segments[1]) && !isset($segments[2])) {
    $scholarshipName = urldecode($segments[1]);

    try {
        // 若已有申請紀錄則不允許刪除
        $check = $pdo->prepare("SELECT COUNT(*) FROM Application WHERE scholarship_name = ?");
        $check->execute([$scholarshipName]);
        $cnt = (int)$check->fetchColumn();
        if ($cnt > 0) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => '已有申請紀錄，無法刪除此獎學金']);
            exit();
        }

        // 刪除獎學金（相關的 Scholarship_Organization 會因外鍵級聯自動刪除）
        $stmt = $pdo->prepare("DELETE FROM Scholarship WHERE name = ?");
        $stmt->execute([$scholarshipName]);

        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => '刪除成功']);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '找不到該獎學金']);
        }
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
// 路由: GET /organization/{id}/applications - 獲取機構的申請列表
else if ($method === 'GET' && $segments[0] === 'organization' && isset($segments[1]) && isset($segments[2]) && $segments[2] === 'applications') {
    $organizationId = $segments[1];
    
    try {
        $stmt = $pdo->prepare("
            SELECT
                a.id,
                a.student_id,
                a.scholarship_name,
                a.apply_date,
                a.apply_state,
                a.score,
                a.gpa,
                a.family_income,
                s.amount,
                u.name AS student_name
            FROM Application a
            JOIN Scholarship s ON a.scholarship_name = s.name
            LEFT JOIN Student st ON a.student_id = st.id
            LEFT JOIN User u ON st.id = u.id
            WHERE a.apply_state IN ('pending_review', 'Under Review', 'Pending')
              AND s.name IN (
                SELECT scholarship_name FROM Scholarship_Organization WHERE organization_id = ?
              )
            ORDER BY a.apply_date DESC
        ");
        $stmt->execute([$organizationId]);
        $applications = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $applications]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// 路由: GET /organization/{id}/stats - 獲取機構統計數據
else if ($method === 'GET' && $segments[0] === 'organization' && isset($segments[1]) && isset($segments[2]) && $segments[2] === 'stats') {
    $organizationId = $segments[1];
    
    try {
        // 統計待審核、審查中、已通過的申請
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN a.apply_state = 'Pending' THEN 1 ELSE 0 END), 0) as pending_count,
                COALESCE(SUM(CASE WHEN a.apply_state = 'Under Review' THEN 1 ELSE 0 END), 0) as review_count,
                COALESCE(SUM(CASE WHEN a.apply_state = 'Approved' THEN 1 ELSE 0 END), 0) as approved_count,
                COALESCE(SUM(CASE WHEN a.apply_state = 'Approved' THEN s.amount ELSE 0 END), 0) as total_approved_amount
            FROM Application a
            JOIN Scholarship s ON a.scholarship_name = s.name
            WHERE s.name IN (
                SELECT scholarship_name 
                FROM Scholarship_Organization 
                WHERE organization_id = ?
            )
        ");
        $stmt->execute([$organizationId]);
        $stats = $stmt->fetch();
        
        echo json_encode(['success' => true, 'data' => $stats]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// 路由: GET /applications/{studentId}
else if ($method === 'GET' && $segments[0] === 'applications' && isset($segments[1])) {
    $studentId = $segments[1];

    try {
        $stmt = $pdo->prepare("
            SELECT
                a.id,
                a.apply_date,
                a.scholarship_name,
                s.amount,
                a.apply_state,
                a.score,
                a.gpa,
                a.requires_recommendation,
                a.reject_reason
            FROM Application a
            JOIN Scholarship s ON a.scholarship_name = s.name
            WHERE a.student_id = ?
            ORDER BY a.apply_date DESC
        ");
        $stmt->execute([$studentId]);
        $applications = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'data' => $applications]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// 路由: GET /application/{id} - 取得單一申請詳情（含推薦信）
else if ($method === 'GET' && $segments[0] === 'application' && isset($segments[1]) && !isset($segments[2])) {
    $applicationId = $segments[1];
    try {
        $stmt = $pdo->prepare("
            SELECT 
                a.id,
                a.student_id,
                u.name AS student_name,
                a.scholarship_name,
                s.amount,
                a.apply_date,
                a.apply_state,
                a.score,
                a.gpa,
                a.family_income,
                a.recommendation_id,
                r.content AS recommendation_content,
                r.update_date AS recommendation_date,
                t.name AS teacher_name
            FROM Application a
            JOIN Scholarship s ON a.scholarship_name = s.name
            LEFT JOIN Student st ON a.student_id = st.id
            LEFT JOIN User u ON st.id = u.id
            LEFT JOIN Recommendation r ON a.recommendation_id = r.id
            LEFT JOIN Teacher te ON r.teacher_id = te.id
            LEFT JOIN User t ON te.id = t.id
            WHERE a.id = ?
        ");
        $stmt->execute([$applicationId]);
        $app = $stmt->fetch();
        if (!$app) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '找不到申請']);
        } else {
            echo json_encode(['success' => true, 'data' => $app]);
        }
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// 路由: PUT/POST /application/{id}/review - 提交審查結果
else if (($method === 'PUT' || $method === 'POST') && $segments[0] === 'application' && isset($segments[1]) && isset($segments[2]) && $segments[2] === 'review') {
    $applicationId = $segments[1];
    $data = json_decode(file_get_contents('php://input'), true);
    
    try {
        // 驗證數據
        if (!isset($data['apply_state'])) {
            echo json_encode(['success' => false, 'message' => '缺少 apply_state 參數']);
            exit();
        }
        
        $applyState = $data['apply_state'];
        $reason     = $data['reason'] ?? null;

        $validStates = ['Pending','Under Review','Approved','Rejected','pending_review','tutor_rejected','review_rejected'];
        if (!in_array($applyState, $validStates)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '無效的審查狀態']);
            exit();
        }

        // 查詢申請詳情及學生 Email（用於後續通知）
        $infoStmt = $pdo->prepare("
            SELECT a.student_id, a.scholarship_name, u.email, u.name AS student_name
            FROM Application a
            JOIN User u ON a.student_id = u.id
            WHERE a.id = ? LIMIT 1
        ");
        $infoStmt->execute([$applicationId]);
        $appInfo = $infoStmt->fetch();

        $isRejection = in_array($applyState, ['tutor_rejected', 'review_rejected']);

        // 更新申請狀態（退件時一併寫入原因）
        $stmt = $pdo->prepare("UPDATE Application SET apply_state = ?, reject_reason = ? WHERE id = ?");
        $stmt->execute([$applyState, $isRejection ? ($reason ?? '') : null, $applicationId]);

        // 檢查是否有行被更新
        if ($stmt->rowCount() === 0) {
            echo json_encode(['success' => false, 'message' => '找不到指定的申請或狀態未變更', 'application_id' => $applicationId]);
            exit();
        }

        // 需通知的狀態
        $notifyMap = ['Approved'=>'審核通過','Rejected'=>'審核不通過','tutor_rejected'=>'導師退件','review_rejected'=>'機構退件'];
        if ($appInfo && isset($notifyMap[$applyState])) {
            $stateLabel = $notifyMap[$applyState];
            $stateColor = $applyState === 'Approved' ? '#22c55e' : '#ef4444';
            $reasonHtml = $reason ? "<p><strong>審查意見：</strong>{$reason}</p>" : '';
            $resubHtml  = $isRejection ? '<p>請登入系統查看退件說明並補件後重新提交。</p>' : '';
            $emailHtml  = "
              <div style='font-family:sans-serif;max-width:480px;margin:auto;padding:24px;border:1px solid #e5e7eb;border-radius:12px;'>
                <h2 style='color:#667eea;margin-top:0;'>ScholarLink 獎學金審查通知</h2>
                <p>您好 <strong>{$appInfo['student_name']}</strong>，</p>
                <p>您申請的 <strong>「{$appInfo['scholarship_name']}」</strong> 獎學金審查結果如下：</p>
                <div style='font-size:24px;font-weight:bold;color:{$stateColor};padding:16px;background:#f5f5f5;border-radius:8px;text-align:center;'>{$stateLabel}</div>
                {$reasonHtml}{$resubHtml}
                <p style='color:#9ca3af;font-size:12px;margin-top:16px;'>如有疑問請透過校內系統聯繫相關單位。</p>
              </div>
            ";
            sendEmail($appInfo['email'], "ScholarLink 獎學金審查通知：{$stateLabel}", $emailHtml);
        }

        echo json_encode(['success' => true, 'message' => '審查結果已保存', 'updated_state' => $applyState]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => '數據庫錯誤: ' . $e->getMessage()]);
    }
}

// 路由: POST /application/{id}/resubmit - 學生補件重新提交
else if ($method === 'POST' && $segments[0] === 'application' && isset($segments[1]) && isset($segments[2]) && $segments[2] === 'resubmit') {
    $applicationId = $segments[1];
    try {
        $stmt = $pdo->prepare("SELECT apply_state, requires_recommendation FROM Application WHERE id = ?");
        $stmt->execute([$applicationId]);
        $app = $stmt->fetch();
        if (!$app) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '找不到指定的申請']);
            exit();
        }
        if (!in_array($app['apply_state'], ['tutor_rejected', 'review_rejected'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '目前狀態不可重新提交']);
            exit();
        }
        $nextState = ($app['apply_state'] === 'tutor_rejected' || ($app['apply_state'] === 'review_rejected' && $app['requires_recommendation']))
            ? 'pending_tutor'
            : 'pending_review';

        $upd = $pdo->prepare("UPDATE Application SET apply_state = ?, reject_reason = NULL WHERE id = ?");
        $upd->execute([$nextState, $applicationId]);
        echo json_encode(['success' => true, 'message' => '已重新提交，等待審查', 'next_state' => $nextState]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// 路由: POST /applications
else if ($method === 'POST' && $segments[0] === 'applications') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    try {
        // 驗證學生身份是否符合獎學金限制
        $scholarshipStmt = $pdo->prepare("SELECT identity_restriction FROM Scholarship WHERE name = ?");
        $scholarshipStmt->execute([$data['scholarship_name']]);
        $scholarship = $scholarshipStmt->fetch();
        
        if (!$scholarship) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '找不到該獎學金']);
            exit;
        }
        
        $identityRestriction = $scholarship['identity_restriction'];
        
        // 如果獎學金有身份限制，檢查學生身份
        if ($identityRestriction !== null) {
            $studentStmt = $pdo->prepare("SELECT identity FROM Student WHERE id = ?");
            $studentStmt->execute([$data['student_id']]);
            $student = $studentStmt->fetch();
            
            $studentIdentity = isset($student['identity']) ? normalize_identity($student['identity']) : null;
            $requiredIdentity = normalize_identity($identityRestriction);
            
            if (!$studentIdentity || $studentIdentity !== $requiredIdentity) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => '您的身份不符合此獎學金的申請資格（需要：' . $requiredIdentity . '）']);
                exit;
            }
        }
        
        // 取得獎學金必備文件設定
        $schDocStmt = $pdo->prepare("SELECT required_documents FROM Scholarship WHERE name = ?");
        $schDocStmt->execute([$data['scholarship_name']]);
        $schDoc = $schDocStmt->fetch();

        // 若有必備文件，驗證學生已上傳
        if ($schDoc && !empty($schDoc['required_documents'])) {
            $required = array_filter(array_map('trim', explode(',', $schDoc['required_documents'])));
            if (!empty($required)) {
                $docStmt = $pdo->prepare("SELECT DISTINCT file_category FROM Identity_Proof WHERE student_id = ?");
                $docStmt->execute([$data['student_id']]);
                $uploaded = array_column($docStmt->fetchAll(), 'file_category');
                $missing = array_diff($required, $uploaded);
                if (!empty($missing)) {
                    $catLabels = ['identity_proof'=>'身份證明','transcript'=>'成績單','award_certificate'=>'參賽得獎'];
                    $missingLabels = array_map(function($c) use ($catLabels){ return $catLabels[$c] ?? $c; }, $missing);
                    http_response_code(400);
                    echo json_encode(['success'=>false,'message'=>'申請前請先上傳必備文件：'.implode('、',$missingLabels)]);
                    exit;
                }
            }
        }

        // 決定初始狀態
        $requiresRec = !empty($data['requires_recommendation']) ? 1 : 0;
        $initialState = $requiresRec ? 'pending_tutor' : 'pending_review';

        // 生成唯一的申請 ID
        $applicationId = 'APP' . round(microtime(true) * 1000);

        $stmt = $pdo->prepare("
            INSERT INTO Application (id, student_id, scholarship_name, apply_date, apply_state, score, gpa, requires_recommendation)
            VALUES (?, ?, ?, NOW(), ?, ?, ?, ?)
        ");
        $stmt->execute([
            $applicationId,
            $data['student_id'],
            $data['scholarship_name'],
            $initialState,
            $data['score'],
            $data['gpa'],
            $requiresRec
        ]);

        echo json_encode(['success' => true, 'message' => '申請提交成功', 'id' => $applicationId]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// 路由: GET /announcements
else if ($method === 'GET' && $segments[0] === 'announcements') {
    try {
        $stmt = $pdo->query("
            SELECT 
                a.announce_id AS id,
                a.title,
                a.content,
                a.publish_date,
                u.name as publisher_name
            FROM Announcement a
            JOIN System_Administrator sa ON a.admin_id = sa.id
            JOIN User u ON sa.id = u.id
            ORDER BY a.publish_date DESC
            LIMIT 10
        ");
        $announcements = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'data' => $announcements]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// 路由: POST /announcements (新增公告)
else if ($method === 'POST' && $segments[0] === 'announcements') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['title']) || !isset($data['content'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'title 與 content 為必填']);
        exit;
    }

    $id = 'ANN' . round(microtime(true) * 1000);
    $adminId = $data['admin_id'] ?? 'ADMIN001';

    try {
        $stmt = $pdo->prepare("
            INSERT INTO Announcement (announce_id, title, content, publish_date, admin_id)
            VALUES (?, ?, ?, NOW(), ?)
        ");
        $stmt->execute([$id, $data['title'], $data['content'], $adminId]);

        echo json_encode(['success' => true, 'message' => '公告已新增', 'data' => ['id' => $id]]);
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// 路由: GET /user/{id} - 取得使用者資訊
else if ($method === 'GET' && $segments[0] === 'user' && isset($segments[1])) {
    $userId = $segments[1];
    
    try {
        $stmt = $pdo->prepare("SELECT id, name, email, type FROM User WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if ($user) {
            echo json_encode(['success' => true, 'data' => $user]);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '找不到使用者']);
        }
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// 路由: POST /scholarships - 新增獎學金
else if ($method === 'POST' && $segments[0] === 'scholarships' && count($segments) === 1) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['name']) || !isset($data['amount'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'name 與 amount 為必填欄位']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // 建立獎學金
        $stmt = $pdo->prepare("
            INSERT INTO Scholarship (name, amount, description, is_published, published_by, published_at, identity_restriction, start_date, end_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE amount = VALUES(amount), description = VALUES(description), identity_restriction = VALUES(identity_restriction), start_date = VALUES(start_date), end_date = VALUES(end_date)
        ");
        $stmt->execute([
            $data['name'],
            $data['amount'],
            $data['description'] ?? null,
            isset($data['publish']) && $data['publish'] ? 1 : 0,
            isset($data['publish']) && $data['publish'] ? ($data['admin_id'] ?? null) : null,
            isset($data['publish']) && $data['publish'] ? date('Y-m-d H:i:s') : null,
            $data['identity_restriction'] ?? null,
            $data['start_date'] ?? null,
            $data['end_date'] ?? null
        ]);
        
        // 關聯機構
        if (isset($data['organization_id']) && $data['organization_id']) {
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO Scholarship_Organization (scholarship_name, organization_id)
                VALUES (?, ?)
            ");
            $stmt->execute([$data['name'], $data['organization_id']]);
        }
        
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => '獎學金已建立', 'data' => ['name' => $data['name']]]);
    } catch(PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// 路由: PUT /scholarships/{name} - 更新獎學金
else if ($method === 'PUT' && $segments[0] === 'scholarships' && isset($segments[1])) {
    $scholarshipName = urldecode($segments[1]);
    $data = json_decode(file_get_contents('php://input'), true);
    
    try {
        $pdo->beginTransaction();
        
        // 更新獎學金基本資料
        $updateFields = [];
        $params = [];
        
        if (isset($data['amount'])) {
            $updateFields[] = "amount = ?";
            $params[] = $data['amount'];
        }
        if (isset($data['description'])) {
            $updateFields[] = "description = ?";
            $params[] = $data['description'];
        }
        if (isset($data['start_date'])) {
            $updateFields[] = "start_date = ?";
            $params[] = $data['start_date'];
        }
        if (isset($data['end_date'])) {
            $updateFields[] = "end_date = ?";
            $params[] = $data['end_date'];
        }
        if (isset($data['identity_restriction'])) {
            $updateFields[] = "identity_restriction = ?";
            $params[] = $data['identity_restriction'] ?: null;
        }
        if (isset($data['required_documents'])) {
            $updateFields[] = "required_documents = ?";
            $params[] = $data['required_documents'] ?: null;
        }

        if (!empty($updateFields)) {
            $params[] = $scholarshipName;
            $sql = "UPDATE Scholarship SET " . implode(", ", $updateFields) . " WHERE name = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }
        
        // 更新機構關聯
        if (isset($data['organization_id'])) {
            // 先刪除舊的關聯
            $stmt = $pdo->prepare("DELETE FROM Scholarship_Organization WHERE scholarship_name = ?");
            $stmt->execute([$scholarshipName]);
            
            // 如果有新的機構ID，建立新關聯
            if ($data['organization_id']) {
                $stmt = $pdo->prepare("INSERT INTO Scholarship_Organization (scholarship_name, organization_id) VALUES (?, ?)");
                $stmt->execute([$scholarshipName, $data['organization_id']]);
            }
        }
        
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => '獎學金已更新']);
    } catch(PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// 路由: POST /scholarships/{name}/publish - 發放獎學金 (removed duplicate)

// 路由: POST /scholarships/{name}/unpublish - 下架獎學金 (removed duplicate)

// 路由: POST /recommendation - 提交推薦信
else if ($method === 'POST' && $segments[0] === 'recommendation' && !isset($segments[1])) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    try {
        $recommendationId = 'REC' . time();
        $filePath = null;
        
        // 處理檔案上傳（如果來自 multipart/form-data）
        if (!empty($_FILES['file'])) {
            $uploadDir = __DIR__ . '/../uploads/recommendations/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $file = $_FILES['file'];
            if ($file['error'] === UPLOAD_ERR_OK) {
                $allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                if (in_array($file['type'], $allowedTypes) && $file['size'] <= 5242880) { // 5MB
                    $fileName = $recommendationId . '_' . basename($file['name']);
                    $filePath = $uploadDir . $fileName;
                    move_uploaded_file($file['tmp_name'], $filePath);
                    $filePath = '/scholarship/uploads/recommendations/' . $fileName;
                }
            }
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO Recommendation (id, content, teacher_id, file_path, update_date)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $recommendationId,
            isset($data['content']) ? $data['content'] : '',
            $data['teacher_id'],
            $filePath
        ]);
        
        // 更新申請的推薦信ID，並將狀態從 pending_tutor 推進至 pending_review
        if (isset($data['application_id'])) {
            $stmt = $pdo->prepare("UPDATE Application SET recommendation_id = ?, apply_state = 'pending_review' WHERE id = ? AND apply_state = 'pending_tutor'");
            $stmt->execute([$recommendationId, $data['application_id']]);
        }

        echo json_encode(['success' => true, 'data' => ['id' => $recommendationId, 'file_path' => $filePath]]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// 路由: GET /teacher/{id}/applications - 取得教師「本科系」學生的申請列表（科系隔離）
else if ($method === 'GET' && $segments[0] === 'teacher' && isset($segments[1]) && isset($segments[2]) && $segments[2] === 'applications') {
    $teacherId = $segments[1];

    try {
        // 步驟一：查詢該導師的科系
        $deptStmt = $pdo->prepare("SELECT department FROM User WHERE id = ? AND type = 'Teacher'");
        $deptStmt->execute([$teacherId]);
        $deptRow = $deptStmt->fetch();

        $teacherDepartment = (!$deptRow || empty($deptRow['department'])) ? null : $deptRow['department'];

        if ($teacherDepartment) {
            $stmt = $pdo->prepare("
                SELECT
                    a.id,
                    a.student_id,
                    u.name       AS student_name,
                    u.department AS student_department,
                    a.scholarship_name,
                    a.apply_date,
                    a.apply_state,
                    a.gpa,
                    a.score,
                    a.reject_reason,
                    a.recommendation_id,
                    r.content    AS recommendation_content
                FROM Application a
                JOIN Student s ON a.student_id = s.id
                JOIN User u ON s.id = u.id
                LEFT JOIN Recommendation r ON a.recommendation_id = r.id
                WHERE a.apply_state = 'pending_tutor'
                  AND u.department = ?
                ORDER BY a.apply_date DESC
            ");
            $stmt->execute([$teacherDepartment]);
        } else {
            $stmt = $pdo->prepare("
                SELECT
                    a.id,
                    a.student_id,
                    u.name       AS student_name,
                    u.department AS student_department,
                    a.scholarship_name,
                    a.apply_date,
                    a.apply_state,
                    a.gpa,
                    a.score,
                    a.reject_reason,
                    a.recommendation_id,
                    r.content    AS recommendation_content
                FROM Application a
                JOIN Student s ON a.student_id = s.id
                JOIN User u ON s.id = u.id
                LEFT JOIN Recommendation r ON a.recommendation_id = r.id
                WHERE a.apply_state = 'pending_tutor'
                ORDER BY a.apply_date DESC
            ");
            $stmt->execute();
        }
        $applications = $stmt->fetchAll();
        echo json_encode(['success' => true, 'data' => $applications, 'department' => $teacherDepartment, 'departmentFiltered' => !!$teacherDepartment]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// 路由: POST /login
else if ($method === 'POST' && $segments[0] === 'login') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    try {
        $stmt = $pdo->prepare("SELECT id, name, email, type FROM User WHERE id = ?");
        $stmt->execute([$data['userId']]);
        $user = $stmt->fetch();
        
        if ($user) {
            echo json_encode(['success' => true, 'data' => $user]);
        } else {
            echo json_encode(['success' => false, 'message' => '找不到使用者']);
        }
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// 路由: POST /identity-proof - 學生上傳身分證明檔案
else if ($method === 'POST' && $segments[0] === 'identity-proof' && !isset($segments[1])) {
    $studentId = $_POST['student_id'] ?? null;
    
    if (!$studentId || empty($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少 student_id 或 file']);
        exit();
    }
    
    try {
        $file = $_FILES['file'];
        
        // 驗證檔案
        $allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];
        $maxSize = 5242880; // 5MB
        
        if (!in_array($file['type'], $allowedTypes) || $file['size'] > $maxSize) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '檔案格式或大小不符（限 JPG/PNG/PDF, 最大 5MB）']);
            exit();
        }
        
        // 建立上傳目錄
        $uploadDir = __DIR__ . '/../uploads/identity-proofs/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        // 生成唯一檔案名稱
        $proofId = 'PROOF' . round(microtime(true) * 1000);
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = $proofId . '.' . $ext;
        $filePath = $uploadDir . $fileName;
        
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => '檔案上傳失敗']);
            exit();
        }
        
        // 驗證並取得檔案類別（預設向後相容為 identity_proof）
        $validCategories = ['identity_proof', 'transcript', 'award_certificate'];
        $category = $_POST['category'] ?? 'identity_proof';
        if (!in_array($category, $validCategories)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '無效的檔案類別，限定：identity_proof / transcript / award_certificate']);
            exit();
        }
        $fileMime = $file['type'];

        // 記錄到資料庫
        $stmt = $pdo->prepare("
            INSERT INTO Identity_Proof (id, student_id, file_path, file_name, file_size, file_category, file_mime, uploaded_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $proofId,
            $studentId,
            '/scholarship/uploads/identity-proofs/' . $fileName,
            $file['name'],
            $file['size'],
            $category,
            $fileMime
        ]);

        echo json_encode(['success' => true, 'message' => '檔案上傳成功', 'id' => $proofId, 'file_category' => $category, 'file_mime' => $fileMime]);
    } catch(Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// 路由: GET /identity-proof - 取得身分證明檔案清單（支援 ?studentId 過濾）
else if ($method === 'GET' && $segments[0] === 'identity-proof' && !isset($segments[1])) {
    try {
        $conditions = [];
        $params     = [];
        if (!empty($_GET['studentId'])) {
            $conditions[] = 'p.student_id = ?';
            $params[]     = $_GET['studentId'];
        }
        $where = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $stmt = $pdo->prepare("
            SELECT
                p.id,
                p.student_id,
                u.name AS student_name,
                s.identity,
                p.file_name,
                p.file_size,
                p.file_path,
                COALESCE(p.file_category, 'identity_proof') AS file_category,
                p.file_mime,
                p.uploaded_at
            FROM Identity_Proof p
            JOIN Student s ON p.student_id = s.id
            JOIN User u ON s.id = u.id
            $where
            ORDER BY p.uploaded_at DESC
        ");
        $stmt->execute($params);
        $proofs = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $proofs]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// 路由: GET /identity-proof/:id/download - 下載身分證明檔案
else if ($method === 'GET' && $segments[0] === 'identity-proof' && isset($segments[1]) && isset($segments[2]) && $segments[2] === 'download') { 
    $proofId = $segments[1];
    
    try {
        $stmt = $pdo->prepare("SELECT file_path, file_name FROM Identity_Proof WHERE id = ?");
        $stmt->execute([$proofId]);
        $proof = $stmt->fetch();
        
        if (!$proof) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '找不到該檔案']);
            exit();
        }
        
        $filePath = __DIR__ . str_replace('/scholarship', '', $proof['file_path']);
        
        if (!file_exists($filePath)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '檔案不存在']);
            exit();
        }
        
        // 送出檔案下載
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($proof['file_name']) . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit();
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// 路由: DELETE /identity-proof/:id - 刪除身分證明檔案
else if ($method === 'DELETE' && $segments[0] === 'identity-proof' && isset($segments[1])) {
    $proofId = $segments[1];

    try {
        // 先取得檔案資訊
        $stmt = $pdo->prepare("SELECT file_path FROM Identity_Proof WHERE id = ?");
        $stmt->execute([$proofId]);
        $proof = $stmt->fetch();

        if (!$proof) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '找不到該證明文件']);
            exit();
        }

        // 刪除實體檔案（修正路徑，指向 /uploads/identity-proofs/）
        $relativePath = str_replace('/scholarship', '', $proof['file_path']);
        $filePath = realpath(__DIR__ . '/..' . $relativePath);
        if ($filePath && file_exists($filePath)) {
            unlink($filePath);
        }

        // 刪除資料庫記錄
        $stmt = $pdo->prepare("DELETE FROM Identity_Proof WHERE id = ?");
        $stmt->execute([$proofId]);

        echo json_encode(['success' => true, 'message' => '證明文件已刪除']);
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// 路由: GET /applications/:id/documents - 取得申請案佐證檔案（依分類打包）
else if ($method === 'GET' && ($segments[0] ?? '') === 'applications' && isset($segments[1]) && ($segments[2] ?? '') === 'documents') {
    $appId = $segments[1];
    try {
        $stmt = $pdo->prepare('SELECT student_id FROM Application WHERE id = ? LIMIT 1');
        $stmt->execute([$appId]);
        $app = $stmt->fetch();
        if (!$app) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '找不到申請案']);
            exit();
        }
        $studentId = $app['student_id'];

        $stmt = $pdo->prepare("
            SELECT id, student_id, file_path, file_name, file_size,
                   COALESCE(file_category, 'identity_proof') AS file_category,
                   file_mime, uploaded_at
            FROM Identity_Proof
            WHERE student_id = ?
            ORDER BY file_category, uploaded_at DESC
        ");
        $stmt->execute([$studentId]);
        $rows = $stmt->fetchAll();

        $validCategories = ['identity_proof', 'transcript', 'award_certificate'];
        $grouped = ['identity_proof' => [], 'transcript' => [], 'award_certificate' => []];
        foreach ($rows as $r) {
            $cat = in_array($r['file_category'], $validCategories) ? $r['file_category'] : 'identity_proof';
            $grouped[$cat][] = $r;
        }

        echo json_encode(['success' => true, 'student_id' => $studentId, 'data' => $grouped]);
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '資料庫錯誤：' . $e->getMessage()]);
    }
}

// 路由: GET /admin/documents - 管理員複合查詢佐證檔案
else if ($method === 'GET' && ($segments[0] ?? '') === 'admin' && ($segments[1] ?? '') === 'documents') {
    try {
        $validCategories = ['identity_proof', 'transcript', 'award_certificate'];
        $conditions = [];
        $params     = [];

        if (!empty($_GET['studentId'])) {
            $conditions[] = 'ip.student_id = ?';
            $params[]     = trim($_GET['studentId']);
        }

        if (!empty($_GET['categories'])) {
            $catList = array_values(array_filter(
                array_map('trim', explode(',', $_GET['categories'])),
                function($c) use ($validCategories) { return in_array($c, $validCategories); }
            ));
            if (!empty($catList)) {
                $placeholders = implode(',', array_fill(0, count($catList), '?'));
                $conditions[] = "ip.file_category IN ($placeholders)";
                $params = array_merge($params, $catList);
            }
        }

        $where = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $stmt = $pdo->prepare("
            SELECT ip.id, ip.student_id, u.name AS student_name,
                   ip.file_name, ip.file_size, ip.file_path,
                   COALESCE(ip.file_mime, '')                    AS file_mime,
                   COALESCE(ip.file_category, 'identity_proof') AS file_category,
                   ip.uploaded_at
            FROM Identity_Proof ip
            JOIN Student s ON ip.student_id = s.id
            JOIN User u ON s.id = u.id
            $where
            ORDER BY ip.uploaded_at DESC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $rows]);
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '資料庫錯誤：' . $e->getMessage()]);
    }
}

// 路由: POST /user - 新增帳號（管理員專用）
else if ($method === 'POST' && $segments[0] === 'user' && !isset($segments[1])) {
    try {
        // 解析 JSON 請求
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '請求體無效']);
            exit();
        }
        
        $id         = $input['id']         ?? null;
        $name       = $input['name']       ?? null;
        $email      = $input['email']      ?? null;
        $type       = $input['type']       ?? null;
        $department = $input['department'] ?? null;

        // 驗證必填欄位
        if (!$id || !$name || !$email || !$type) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '缺少必填欄位：id, name, email, type']);
            exit();
        }

        // 驗證角色
        $validTypes = ['Student', 'Teacher', 'Organization', 'SystemAdministrator'];
        if (!in_array($type, $validTypes)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '無效的角色']);
            exit();
        }

        // Email 格式基本驗證（任意網域）
        if (!preg_match(EMAIL_REGEX, $email)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Email 格式不正確']);
            exit();
        }

        // 學生與教師必須填寫科系
        if (in_array($type, ['Student', 'Teacher']) && empty($department)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '學生與教師帳號必須指定所屬科系']);
            exit();
        }

        // 管理員與審查單位不需科系，強制為 null
        if (in_array($type, ['SystemAdministrator', 'Organization'])) {
            $department = null;
        }

        // 檢查帳號是否已存在
        $stmt = $pdo->prepare("SELECT id FROM User WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => '帳號已存在']);
            exit();
        }

        // 建立密碼：使用傳入密碼或自動產生隨機預設密碼
        $rawPassword   = !empty($input['password']) ? $input['password']
                         : ($id . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4)) . '!');
        $hashedPassword = password_hash($rawPassword, PASSWORD_DEFAULT);

        // 新增使用者（直接設為已驗證，無需 OTP）
        $stmt = $pdo->prepare("
            INSERT INTO User (id, name, email, type, department, password, email_verified, verification_code)
            VALUES (?, ?, ?, ?, ?, ?, 1, NULL)
        ");
        $stmt->execute([$id, $name, $email, $type, $department, $hashedPassword]);

        // 如果是學生，建立 Student 記錄
        if ($type === 'Student') {
            try {
                $stmt = $pdo->prepare("INSERT INTO Student (id) VALUES (?)");
                $stmt->execute([$id]);
            } catch(PDOException $e) {
                // Student 記錄可能已存在，忽略
            }
        }

        $emailHtml = "
          <div style='font-family:sans-serif;max-width:480px;margin:auto;padding:24px;border:1px solid #e5e7eb;border-radius:12px;'>
            <h2 style='color:#667eea;margin-top:0;'>ScholarLink 帳號已建立</h2>
            <p>您好 <strong>{$name}</strong>，您的帳號已由管理員建立，可直接登入。</p>
            <p>帳號 ID：<code>{$id}</code><br>初始密碼：<code>{$rawPassword}</code></p>
          </div>
        ";
        sendEmail($email, 'ScholarLink 帳號建立通知', $emailHtml);

        echo json_encode([
            'success' => true,
            'message' => '帳號新增成功，驗證碼已寄送至 Email',
            'data' => [
                'id'          => $id,
                'name'        => $name,
                'email'       => $email,
                'type'        => $type,
                'department'  => $department,
                'tempPassword' => $rawPassword
            ]
        ]);
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '資料庫錯誤：' . $e->getMessage()]);
    }
}

// 路由: GET /users/list - 取得非管理員使用者清單（供私密留言選取對象用）
else if ($method === 'GET' && ($segments[0] ?? '') === 'users' && ($segments[1] ?? '') === 'list') {
    try {
        $stmt = $pdo->prepare(
            "SELECT id, name FROM User WHERE type != 'SystemAdministrator' ORDER BY name ASC"
        );
        $stmt->execute();
        $users = $stmt->fetchAll();
        echo json_encode(['success' => true, 'data' => $users]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '資料庫錯誤：' . $e->getMessage()]);
    }
}

// 路由: GET /users - 列表所有帳號（管理員專用）
else if ($method === 'GET' && $segments[0] === 'users' && !isset($segments[1])) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, name, email, type, department
            FROM User
            ORDER BY id ASC
        ");
        $stmt->execute();
        $users = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'users' => $users
        ]);
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '資料庫錯誤：' . $e->getMessage()]);
    }
}

// 路由: POST /users/verify-otp - 核對 OTP，啟用帳號
else if ($method === 'POST' && ($segments[0] ?? '') === 'users' && ($segments[1] ?? '') === 'verify-otp') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id   = $data['id']   ?? null;
    $code = $data['code'] ?? null;

    if (!$id || !$code) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少 id 或 code 參數']);
        exit();
    }

    try {
        $stmt = $pdo->prepare('SELECT verification_code, email_verified FROM User WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        if (!$user) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '找不到使用者']);
            exit();
        }

        if ($user['email_verified']) {
            echo json_encode(['success' => true, 'message' => '帳號已驗證，無需重複驗證']);
            exit();
        }

        if (empty($user['verification_code']) || $user['verification_code'] !== trim((string)$code)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '驗證碼錯誤，請重新確認']);
            exit();
        }

        $upd = $pdo->prepare('UPDATE User SET email_verified = 1, verification_code = NULL WHERE id = ?');
        $upd->execute([$id]);

        echo json_encode(['success' => true, 'message' => '電子郵件驗證成功，帳號已啟用']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '資料庫錯誤：' . $e->getMessage()]);
    }
}

// 路由: POST /users/forgot-password - 驗證帳號 ID 與 Email（無 OTP）
else if ($method === 'POST' && ($segments[0] ?? '') === 'users' && ($segments[1] ?? '') === 'forgot-password') {
    $data  = json_decode(file_get_contents('php://input'), true);
    $id    = $data['id']    ?? null;
    $email = $data['email'] ?? null;

    if (!$id || !$email) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '請輸入帳號 ID 與 Email']);
        exit();
    }

    try {
        $stmt = $pdo->prepare('SELECT id FROM User WHERE id = ? AND email = ? LIMIT 1');
        $stmt->execute([$id, trim($email)]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '帳號 ID 與 Email 不吻合，請確認後再試']);
            exit();
        }

        echo json_encode(['success' => true, 'message' => '身份驗證成功，請輸入新密碼']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '資料庫錯誤：' . $e->getMessage()]);
    }
}

// 路由: POST /users/reset-password - 驗證 ID+Email 後直接更新密碼（無 OTP）
else if ($method === 'POST' && ($segments[0] ?? '') === 'users' && ($segments[1] ?? '') === 'reset-password') {
    $data        = json_decode(file_get_contents('php://input'), true);
    $id          = $data['id']          ?? null;
    $email       = $data['email']       ?? null;
    $newPassword = $data['newPassword'] ?? null;

    if (!$id || !$email || !$newPassword) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少必要參數']);
        exit();
    }
    if (strlen($newPassword) < 6) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '新密碼至少需要 6 個字元']);
        exit();
    }

    try {
        $stmt = $pdo->prepare('SELECT id FROM User WHERE id = ? AND email = ? LIMIT 1');
        $stmt->execute([$id, trim($email)]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '帳號 ID 與 Email 不吻合']);
            exit();
        }

        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
        $upd    = $pdo->prepare('UPDATE User SET password = ? WHERE id = ?');
        $upd->execute([$hashed, $id]);

        echo json_encode(['success' => true, 'message' => '密碼重設成功，請使用新密碼登入']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '資料庫錯誤：' . $e->getMessage()]);
    }
}

// 路由: DELETE /user/{id} - 刪除帳號
else if ($method === 'DELETE' && $segments[0] === 'user' && isset($segments[1]) && !isset($segments[2])) {
    try {
        $userId = $segments[1];
        
        // 檢查是否存在
        $stmt = $pdo->prepare("SELECT id FROM User WHERE id = ?");
        $stmt->execute([$userId]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '帳號不存在']);
            exit();
        }
        
        // 刪除使用者
        $stmt = $pdo->prepare("DELETE FROM User WHERE id = ?");
        $stmt->execute([$userId]);
        
        echo json_encode([
            'success' => true,
            'message' => '帳號已刪除'
        ]);
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '資料庫錯誤：' . $e->getMessage()]);
    }
}

// ============================================================
// 師生留言板路由
// ============================================================

// 路由: GET /messages - 取得留言列表（智慧隱私安全篩選）
else if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'messages' && count($segments) === 1) {
    $viewerId = $_GET['viewer_id'] ?? null;
    if (!$viewerId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少 viewer_id 參數']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT
                m.id,
                m.user_id,
                m.title,
                m.content,
                m.visibility,
                m.target_user_id,
                m.reply_content,
                m.reply_user_id,
                m.created_at,
                m.replied_at,
                u.name  AS author_name,
                ru.name AS reply_user_name,
                tu.name AS target_user_name
            FROM Message m
            JOIN User u ON m.user_id = u.id
            LEFT JOIN User ru ON m.reply_user_id = ru.id
            LEFT JOIN User tu ON m.target_user_id = tu.id
            WHERE m.visibility = 'Public'
               OR m.user_id = ?
               OR m.target_user_id = ?
            ORDER BY m.created_at DESC
        ");
        $stmt->execute([$viewerId, $viewerId]);
        $messages = $stmt->fetchAll();
        echo json_encode(['success' => true, 'data' => $messages]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// 路由: POST /messages - 新增留言
else if ($method === 'POST' && isset($segments[0]) && $segments[0] === 'messages' && count($segments) === 1) {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['user_id']) || !isset($data['title']) || !isset($data['content'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'user_id、title 與 content 為必填欄位']);
        exit;
    }
    $visibility = $data['visibility'] ?? 'Public';
    if (!in_array($visibility, ['Public', 'Private'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "visibility 必須為 'Public' 或 'Private'"]);
        exit;
    }
    if ($visibility === 'Private' && empty($data['target_user_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '私密留言必須指定 target_user_id']);
        exit;
    }
    try {
        // SystemAdministrator 不可發言
        $stmt = $pdo->prepare("SELECT type FROM User WHERE id = ?");
        $stmt->execute([$data['user_id']]);
        $userRow = $stmt->fetch();
        if (!$userRow) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '找不到使用者']);
            exit;
        }
        if ($userRow['type'] === 'SystemAdministrator') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => '系統管理員不可發表留言']);
            exit;
        }
        $messageId = 'MSG' . round(microtime(true) * 1000);
        $stmt = $pdo->prepare("
            INSERT INTO Message (id, user_id, title, content, visibility, target_user_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $messageId,
            $data['user_id'],
            $data['title'],
            $data['content'],
            $visibility,
            $data['target_user_id'] ?? null
        ]);
        echo json_encode(['success' => true, 'message' => '留言發布成功', 'data' => ['id' => $messageId]]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// 路由: PUT /messages/{id}/reply - 回覆留言（支援單則及批量回覆）
// 批量模式：body 傳入 message_ids: [...] 且 {id} 填任意佔位字串（如 'bulk'）
else if ($method === 'PUT' && isset($segments[0]) && $segments[0] === 'messages' && isset($segments[1]) && ($segments[2] ?? '') === 'reply') {
    $messageId = $segments[1];
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['reply_user_id']) || !isset($data['reply_content'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'reply_user_id 與 reply_content 為必填欄位']);
        exit;
    }
    try {
        // 驗證回覆者身份
        $stmt = $pdo->prepare("SELECT type FROM User WHERE id = ?");
        $stmt->execute([$data['reply_user_id']]);
        $userRow = $stmt->fetch();
        if (!$userRow) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '找不到回覆者']);
            exit;
        }
        $replyUserType  = $userRow['type'];
        $isAdmin        = $replyUserType === 'SystemAdministrator';
        $isTeacherOrOrg = $replyUserType === 'Teacher' || $replyUserType === 'Organization';

        // ── 批量回覆模式（管理員專用）────────────────────────
        if (isset($data['message_ids']) && is_array($data['message_ids']) && count($data['message_ids']) > 0) {
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => '批量回覆僅限系統管理員操作']);
                exit;
            }
            $successCount = 0;
            $updateStmt = $pdo->prepare(
                "UPDATE Message SET reply_content = ?, reply_user_id = ?, replied_at = NOW() WHERE id = ?"
            );
            foreach ($data['message_ids'] as $msgId) {
                $checkStmt = $pdo->prepare("SELECT id FROM Message WHERE id = ?");
                $checkStmt->execute([$msgId]);
                if (!$checkStmt->fetch()) continue;
                $updateStmt->execute([$data['reply_content'], $data['reply_user_id'], $msgId]);
                $successCount++;
            }
            echo json_encode(['success' => true, 'message' => "已成功回覆 {$successCount} 則留言", 'count' => $successCount]);
            exit;
        }

        // ── 單則回覆模式 ──────────────────────────────────────
        $stmt = $pdo->prepare("SELECT user_id FROM Message WHERE id = ?");
        $stmt->execute([$messageId]);
        $msgRow = $stmt->fetch();
        if (!$msgRow) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '找不到指定留言']);
            exit;
        }
        $messageOwnerId  = $msgRow['user_id'];
        $isOriginalAuthor = $data['reply_user_id'] === $messageOwnerId;

        if (!$isAdmin && !$isTeacherOrOrg && !$isOriginalAuthor) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => '您沒有回覆此留言的權限']);
            exit;
        }
        $stmt = $pdo->prepare(
            "UPDATE Message SET reply_content = ?, reply_user_id = ?, replied_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$data['reply_content'], $data['reply_user_id'], $messageId]);
        echo json_encode(['success' => true, 'message' => '回覆成功']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// 路由: DELETE /messages/{id} - 刪除留言（智慧刪除權限防禦）
else if ($method === 'DELETE' && isset($segments[0]) && $segments[0] === 'messages' && isset($segments[1]) && count($segments) === 2) {
    $messageId = $segments[1];
    $data = json_decode(file_get_contents('php://input'), true);
    $operatorId = $data['operator_id'] ?? null;

    if (!$operatorId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少 operator_id 參數']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("SELECT user_id, visibility FROM Message WHERE id = ?");
        $stmt->execute([$messageId]);
        $msgRow = $stmt->fetch();
        if (!$msgRow) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '找不到指定留言']);
            exit;
        }
        $authorId   = $msgRow['user_id'];
        $visibility = $msgRow['visibility'];

        $stmt = $pdo->prepare("SELECT type FROM User WHERE id = ?");
        $stmt->execute([$operatorId]);
        $operatorRow = $stmt->fetch();
        if (!$operatorRow) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '找不到操作者資訊']);
            exit;
        }
        $operatorType = $operatorRow['type'];

        $isAdmin  = $operatorType === 'SystemAdministrator';
        $isAuthor = $operatorId === $authorId;

        if ($isAdmin && $visibility !== 'Public') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => '管理員只能刪除公開留言']);
            exit;
        }
        if (!$isAdmin && !$isAuthor) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => '您沒有刪除此留言的權限']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM Message WHERE id = ?");
        $stmt->execute([$messageId]);
        echo json_encode(['success' => true, 'message' => '留言已刪除']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ============================================================
// 管理員備忘錄路由
// ============================================================

// 路由: GET /memos?admin_id=xxx - 取得指定管理員的所有備忘錄
else if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'memos' && count($segments) === 1) {
    $adminId = $_GET['admin_id'] ?? null;
    if (!$adminId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少 admin_id 參數']);
        exit;
    }

    // 防禦性檢查：確認是 SystemAdministrator
    $stmt = $pdo->prepare("SELECT type FROM User WHERE id = ? LIMIT 1");
    $stmt->execute([$adminId]);
    $userRow = $stmt->fetch();
    if (!$userRow || $userRow['type'] !== 'SystemAdministrator') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '權限不足，僅限系統管理員存取']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id, admin_id, title, content, priority, status, reminder_date, created_time
            FROM Admin_Memo
            WHERE admin_id = ?
            ORDER BY
                CASE priority
                    WHEN '緊急'      THEN 1
                    WHEN '重要'      THEN 2
                    WHEN '沒那麼重要' THEN 3
                    ELSE 4
                END,
                created_time DESC
        ");
        $stmt->execute([$adminId]);
        $memos = $stmt->fetchAll();
        echo json_encode(['success' => true, 'data' => $memos]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '資料庫錯誤：' . $e->getMessage()]);
    }
}

// 路由: POST /memos - 新增備忘錄
else if ($method === 'POST' && isset($segments[0]) && $segments[0] === 'memos' && count($segments) === 1) {
    $data = json_decode(file_get_contents('php://input'), true);
    $adminId      = $data['admin_id']      ?? null;
    $title        = $data['title']         ?? null;
    $content      = $data['content']       ?? null;
    $priority     = $data['priority']      ?? '重要';
    $status       = '代辦'; // 新增備忘錄一律強制為代辦，不採用前端傳入值
    $reminderDate = $data['reminder_date'] ?? null;

    if (!$adminId || !$title) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少必填欄位：admin_id、title']);
        exit;
    }

    // 防禦性檢查：確認是 SystemAdministrator
    $stmt = $pdo->prepare("SELECT type FROM User WHERE id = ? LIMIT 1");
    $stmt->execute([$adminId]);
    $userRow = $stmt->fetch();
    if (!$userRow || $userRow['type'] !== 'SystemAdministrator') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '權限不足，僅限系統管理員存取']);
        exit;
    }

    try {
        $id = 'MEMO' . round(microtime(true) * 1000);
        $stmt = $pdo->prepare("
            INSERT INTO Admin_Memo (id, admin_id, title, content, priority, status, reminder_date)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$id, $adminId, $title, $content, $priority, $status, $reminderDate ?: null]);
        http_response_code(201);
        echo json_encode(['success' => true, 'message' => '備忘錄新增成功', 'data' => ['id' => $id]]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '資料庫錯誤：' . $e->getMessage()]);
    }
}

// 路由: PUT /memos/{id} - 編輯備忘錄
else if ($method === 'PUT' && isset($segments[0]) && $segments[0] === 'memos' && isset($segments[1]) && count($segments) === 2) {
    $memoId = $segments[1];
    $data   = json_decode(file_get_contents('php://input'), true);
    $adminId = $data['admin_id'] ?? null;

    if (!$adminId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少 admin_id 參數']);
        exit;
    }

    // 防禦性檢查：確認是 SystemAdministrator
    $stmt = $pdo->prepare("SELECT type FROM User WHERE id = ? LIMIT 1");
    $stmt->execute([$adminId]);
    $userRow = $stmt->fetch();
    if (!$userRow || $userRow['type'] !== 'SystemAdministrator') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '權限不足，僅限系統管理員存取']);
        exit;
    }

    // 確認備忘錄存在且屬於此管理員
    $stmt = $pdo->prepare("SELECT admin_id FROM Admin_Memo WHERE id = ? LIMIT 1");
    $stmt->execute([$memoId]);
    $memoRow = $stmt->fetch();
    if (!$memoRow) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '找不到指定備忘錄']);
        exit;
    }
    if ($memoRow['admin_id'] !== $adminId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '您沒有權限修改此備忘錄']);
        exit;
    }

    try {
        $fields = []; $values = [];
        if (array_key_exists('title',         $data)) { $fields[] = 'title = ?';         $values[] = $data['title']; }
        if (array_key_exists('content',       $data)) { $fields[] = 'content = ?';       $values[] = $data['content']; }
        if (array_key_exists('priority',      $data)) { $fields[] = 'priority = ?';      $values[] = $data['priority']; }
        if (array_key_exists('status',        $data)) { $fields[] = 'status = ?';        $values[] = $data['status']; }
        if (array_key_exists('reminder_date', $data)) { $fields[] = 'reminder_date = ?'; $values[] = $data['reminder_date'] ?: null; }

        if (empty($fields)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '沒有可更新的欄位']);
            exit;
        }

        $values[] = $memoId;
        $stmt = $pdo->prepare("UPDATE Admin_Memo SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($values);
        echo json_encode(['success' => true, 'message' => '備忘錄更新成功']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '資料庫錯誤：' . $e->getMessage()]);
    }
}

// 路由: DELETE /memos/{id}?admin_id=xxx - 刪除備忘錄
else if ($method === 'DELETE' && isset($segments[0]) && $segments[0] === 'memos' && isset($segments[1]) && count($segments) === 2) {
    $memoId  = $segments[1];
    $adminId = $_GET['admin_id'] ?? null;

    if (!$adminId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少 admin_id 參數']);
        exit;
    }

    // 防禦性檢查：確認是 SystemAdministrator
    $stmt = $pdo->prepare("SELECT type FROM User WHERE id = ? LIMIT 1");
    $stmt->execute([$adminId]);
    $userRow = $stmt->fetch();
    if (!$userRow || $userRow['type'] !== 'SystemAdministrator') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '權限不足，僅限系統管理員存取']);
        exit;
    }

    // 確認備忘錄存在且屬於此管理員
    $stmt = $pdo->prepare("SELECT admin_id FROM Admin_Memo WHERE id = ? LIMIT 1");
    $stmt->execute([$memoId]);
    $memoRow = $stmt->fetch();
    if (!$memoRow) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '找不到指定備忘錄']);
        exit;
    }
    if ($memoRow['admin_id'] !== $adminId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '您沒有權限刪除此備忘錄']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM Admin_Memo WHERE id = ?");
        $stmt->execute([$memoId]);
        echo json_encode(['success' => true, 'message' => '備忘錄已刪除']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '資料庫錯誤：' . $e->getMessage()]);
    }
}

// 路由不匹配
else {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'API 路由不存在', 'path' => implode('/', $segments)]);
}
?>
