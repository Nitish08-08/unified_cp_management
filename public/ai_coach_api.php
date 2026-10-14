<?php
/**
 * ai_coach_api.php
 * Secure REST API for Student AI Competitive Programming Coach
 * Unified CP Management System
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../services/ai_coach_service.php';

// Authentication Guard & Session Init
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper to read JSON request body
function get_json_input(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return $_POST;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? array_merge($_POST, $decoded) : $_POST;
}

$input = get_json_input();
$action = $_GET['action'] ?? ($input['action'] ?? ($_POST['action'] ?? ''));

// Serverless fallback: verify signed HMAC token if session cookie was lost across Lambda workers
if (empty($_SESSION['user_id'])) {
    $token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? ($input['auth_token'] ?? ($_GET['auth_token'] ?? ''));
    if ($token && function_exists('verify_user_auth_token')) {
        $tokenUserId = verify_user_auth_token($token);
        if ($tokenUserId && $pdo !== null) {
            $uStmt = $pdo->prepare("SELECT id, name, role FROM users WHERE id = ?");
            $uStmt->execute([$tokenUserId]);
            $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
            if ($uRow) {
                $_SESSION['user_id'] = (int)$uRow['id'];
                $_SESSION['user_name'] = $uRow['name'];
                $_SESSION['user_role'] = $uRow['role'];
            }
        }
    }
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required. Please sign in.']);
    exit;
}

$userId = (int)$_SESSION['user_id'];

try {
    switch ($action) {
        /* ==========================================================
           1. Get all conversations for current authenticated student
           ========================================================== */
        case 'get_conversations':
            $stmt = $pdo->prepare("
                SELECT c.id, c.title, c.created_at, c.updated_at, COUNT(m.id) AS message_count
                FROM ai_conversations c
                LEFT JOIN ai_messages m ON c.id = m.conversation_id
                WHERE c.user_id = ?
                GROUP BY c.id
                ORDER BY c.updated_at DESC
            ");
            $stmt->execute([$userId]);
            $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success'       => true,
                'conversations' => $conversations,
            ]);
            break;

        /* ==========================================================
           2. Create a new conversation thread
           ========================================================== */
        case 'new_conversation':
            $stmt = $pdo->prepare("INSERT INTO ai_conversations (user_id, title, created_at, updated_at) VALUES (?, 'New Conversation', NOW(), NOW())");
            $stmt->execute([$userId]);
            $convId = (int)$pdo->lastInsertId();

            echo json_encode([
                'success'      => true,
                'conversation' => [
                    'id'            => $convId,
                    'title'         => 'New Conversation',
                    'message_count' => 0,
                    'created_at'    => date('Y-m-d H:i:s'),
                ]
            ]);
            break;

        /* ==========================================================
           3. Get messages for a specific conversation (IDOR Protected)
           ========================================================== */
        case 'get_messages':
            $convId = (int)($_GET['conversation_id'] ?? ($input['conversation_id'] ?? 0));

            // Verify ownership
            $check = $pdo->prepare("SELECT id, title FROM ai_conversations WHERE id = ? AND user_id = ?");
            $check->execute([$convId, $userId]);
            $conv = $check->fetch(PDO::FETCH_ASSOC);

            if (!$conv) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Conversation not found or access denied.']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT m.id, m.role, m.content, m.created_at, fb.rating AS user_feedback
                FROM ai_messages m
                LEFT JOIN ai_feedback fb ON m.id = fb.message_id AND fb.user_id = ?
                WHERE m.conversation_id = ?
                ORDER BY m.id ASC
            ");
            $stmt->execute([$userId, $convId]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success'      => true,
                'conversation' => $conv,
                'messages'     => $messages,
            ]);
            break;

        /* ==========================================================
           4. Send Message & Receive AI Coach Response
           ========================================================== */
        case 'send_message':
            $convId = !empty($input['conversation_id']) ? (int)$input['conversation_id'] : null;
            $userText = trim($input['message'] ?? '');

            if ($userText === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Message content cannot be empty.']);
                exit;
            }

            if (mb_strlen($userText) > 4000) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Message exceeds maximum limit of 4,000 characters.']);
                exit;
            }

            // If conversation ID is missing, create a new conversation thread
            if (!$convId) {
                // Generate a smart title from the user prompt
                $cleanTitle = preg_replace('/[^\w\s-]/', '', $userText);
                $titleWords = explode(' ', trim($cleanTitle));
                $title = implode(' ', array_slice($titleWords, 0, 5));
                if (strlen($title) > 45) {
                    $title = substr($title, 0, 42) . '...';
                }
                if ($title === '') $title = 'Practice Discussion';

                $cStmt = $pdo->prepare("INSERT INTO ai_conversations (user_id, title, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
                $cStmt->execute([$userId, $title]);
                $convId = (int)$pdo->lastInsertId();
            } else {
                // Verify ownership to prevent IDOR
                $check = $pdo->prepare("SELECT id, title FROM ai_conversations WHERE id = ? AND user_id = ?");
                $check->execute([$convId, $userId]);
                $existingConv = $check->fetch(PDO::FETCH_ASSOC);
                if (!$existingConv) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'Access denied to this conversation thread.']);
                    exit;
                }

                // If existing title is default, update it with first meaningful query
                if ($existingConv['title'] === 'New Conversation') {
                    $cleanTitle = preg_replace('/[^\w\s-]/', '', $userText);
                    $titleWords = explode(' ', trim($cleanTitle));
                    $title = implode(' ', array_slice($titleWords, 0, 5));
                    if (strlen($title) > 45) $title = substr($title, 0, 42) . '...';
                    if ($title !== '') {
                        $pdo->prepare("UPDATE ai_conversations SET title = ? WHERE id = ?")->execute([$title, $convId]);
                    }
                }
            }

            // Save user message
            $uMsgStmt = $pdo->prepare("INSERT INTO ai_messages (conversation_id, role, content, created_at) VALUES (?, 'user', ?, NOW())");
            $uMsgStmt->execute([$convId, $userText]);
            $userMsgId = (int)$pdo->lastInsertId();

            // Fetch recent conversation history for conversational context (last 6 messages)
            $histStmt = $pdo->prepare("
                SELECT role, content FROM ai_messages 
                WHERE conversation_id = ? AND id < ?
                ORDER BY id DESC LIMIT 6
            ");
            $histStmt->execute([$convId, $userMsgId]);
            $history = array_reverse($histStmt->fetchAll(PDO::FETCH_ASSOC));

            // Generate personalized AI coach response
            $coachOutput = generate_cp_coach_response($pdo, $userId, $userText, $history);
            $assistantContent = $coachOutput['content'];

            // Save assistant message
            $aMsgStmt = $pdo->prepare("
                INSERT INTO ai_messages (conversation_id, role, content, metadata, created_at)
                VALUES (?, 'assistant', ?, ?, NOW())
            ");
            $aMsgStmt->execute([
                $convId,
                $assistantContent,
                json_encode([
                    'engine'        => $coachOutput['engine'],
                    'saved_plan_id' => $coachOutput['saved_plan_id'] ?? null
                ])
            ]);
            $assistantMsgId = (int)$pdo->lastInsertId();

            // Touch conversation timestamp
            $pdo->prepare("UPDATE ai_conversations SET updated_at = NOW() WHERE id = ?")->execute([$convId]);

            // Fetch updated conversation title
            $titleStmt = $pdo->prepare("SELECT title FROM ai_conversations WHERE id = ?");
            $titleStmt->execute([$convId]);
            $currentTitle = $titleStmt->fetchColumn() ?: 'Conversation';

            echo json_encode([
                'success'           => true,
                'conversation_id'   => $convId,
                'title'             => $currentTitle,
                'engine'            => $coachOutput['engine'],
                'user_message'      => [
                    'id'         => $userMsgId,
                    'role'       => 'user',
                    'content'    => $userText,
                    'created_at' => date('Y-m-d H:i:s'),
                ],
                'assistant_message' => [
                    'id'         => $assistantMsgId,
                    'role'       => 'assistant',
                    'content'    => $assistantContent,
                    'created_at' => date('Y-m-d H:i:s'),
                ],
                'saved_plan_id'     => $coachOutput['saved_plan_id'],
                'context_summary'   => $coachOutput['context_summary'],
            ]);
            break;

        /* ==========================================================
           5. Delete conversation thread (IDOR Protected)
           ========================================================== */
        case 'delete_conversation':
            $convId = (int)($input['conversation_id'] ?? 0);

            $stmt = $pdo->prepare("DELETE FROM ai_conversations WHERE id = ? AND user_id = ?");
            $stmt->execute([$convId, $userId]);

            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Conversation deleted.']);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Conversation not found or unauthorized.']);
            }
            break;

        /* ==========================================================
           6. Get Active Practice Plan and Tasks
           ========================================================== */
        case 'get_active_plan':
            $planStmt = $pdo->prepare("
                SELECT id, title, duration_days, objective, status, created_at
                FROM ai_practice_plans
                WHERE user_id = ? AND status = 'active'
                ORDER BY id DESC LIMIT 1
            ");
            $planStmt->execute([$userId]);
            $plan = $planStmt->fetch(PDO::FETCH_ASSOC);

            $tasks = [];
            if ($plan) {
                $taskStmt = $pdo->prepare("
                    SELECT id, day_number, topic, task_description, is_completed, completed_at
                    FROM ai_plan_tasks
                    WHERE plan_id = ?
                    ORDER BY day_number ASC, id ASC
                ");
                $taskStmt->execute([$plan['id']]);
                $tasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode([
                'success' => true,
                'plan'    => $plan,
                'tasks'   => $tasks,
            ]);
            break;

        /* ==========================================================
           7. Toggle Practice Plan Task Completion (IDOR Protected)
           ========================================================== */
        case 'toggle_task':
            $taskId = (int)($input['task_id'] ?? 0);

            // Ensure task belongs to a plan owned by this student
            $tStmt = $pdo->prepare("
                SELECT apt.id, apt.is_completed, apt.plan_id
                FROM ai_plan_tasks apt
                JOIN ai_practice_plans app ON apt.plan_id = app.id
                WHERE apt.id = ? AND app.user_id = ?
            ");
            $tStmt->execute([$taskId, $userId]);
            $task = $tStmt->fetch(PDO::FETCH_ASSOC);

            if (!$task) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Task not found or access denied.']);
                exit;
            }

            $newStatus = $task['is_completed'] ? 0 : 1;
            $completedAt = $newStatus ? date('Y-m-d H:i:s') : null;

            $upd = $pdo->prepare("UPDATE ai_plan_tasks SET is_completed = ?, completed_at = ? WHERE id = ?");
            $upd->execute([$newStatus, $completedAt, $taskId]);

            echo json_encode([
                'success'      => true,
                'task_id'      => $taskId,
                'is_completed' => (bool)$newStatus,
            ]);
            break;

        /* ==========================================================
           8. Feedback on Message (Thumbs Up / Down)
           ========================================================== */
        case 'feedback':
            $msgId = (int)($input['message_id'] ?? 0);
            $rating = ($input['rating'] ?? '') === 'down' ? 'down' : 'up';

            // Verify message belongs to student's conversation
            $checkStmt = $pdo->prepare("
                SELECT m.id FROM ai_messages m
                JOIN ai_conversations c ON m.conversation_id = c.id
                WHERE m.id = ? AND c.user_id = ?
            ");
            $checkStmt->execute([$msgId, $userId]);
            if (!$checkStmt->fetch()) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Unauthorized message.']);
                exit;
            }

            // Insert or update feedback
            $fbStmt = $pdo->prepare("
                INSERT INTO ai_feedback (message_id, user_id, rating, created_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE rating = VALUES(rating)
            ");
            $fbStmt->execute([$msgId, $userId, $rating]);

            echo json_encode(['success' => true, 'rating' => $rating]);
            break;

        /* ==========================================================
           9. Default
           ========================================================== */
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error'   => 'Invalid API action specified.',
                'valid_actions' => [
                    'get_conversations', 'new_conversation', 'get_messages',
                    'send_message', 'delete_conversation', 'get_active_plan',
                    'toggle_task', 'feedback'
                ]
            ]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Server Error: ' . $e->getMessage()
    ]);
}
