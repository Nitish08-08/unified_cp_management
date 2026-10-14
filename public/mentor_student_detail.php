<?php
require_once __DIR__ . '/../config/db.php';
require_mentor();

$mentorId = (int)$_SESSION['user_id'];
$studentId = (int)($_GET['id'] ?? 0);

// IDOR Protection: strictly verify that this student is assigned to this mentor
if ($studentId <= 0 || !is_student_assigned_to_mentor($pdo, $mentorId, $studentId)) {
    http_response_code(403);
    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="card" style="max-width: 600px; margin: 60px auto; text-align: center; padding: 40px 24px;">
        <div style="font-size: 3.5rem; margin-bottom: 12px;">🛡️</div>
        <h2 style="font-size: 1.6rem; color: var(--clr-danger); margin-bottom: 10px;">Access Denied (403)</h2>
        <p style="color: var(--clr-text-muted); margin-bottom: 24px; line-height: 1.6;">
            You do not have permission to view or manage this student profile. Either this user is not part of your mentorship roster or their invitation is still pending.
        </p>
        <a href="mentor_dashboard.php" class="btn btn-primary">
            <span>&larr; Return to Mentor Dashboard</span>
        </a>
    </div>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

/* ==========================================================================
   Handle POST Actions (Notes & Goals)
   ========================================================================== */
$message = '';
$msgType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_note') {
        $noteText = trim($_POST['note_text'] ?? '');
        if ($noteText === '') {
            $message = 'Note content cannot be empty.';
            $msgType = 'error';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO mentor_notes (mentor_id, student_id, note_text, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$mentorId, $studentId, $noteText]);
                $message = 'Private mentor note saved.';
                $msgType = 'success';
            } catch (Exception $e) {
                $message = 'Error saving note: ' . $e->getMessage();
                $msgType = 'error';
            }
        }
    } elseif ($action === 'delete_note') {
        $noteId = (int)($_POST['note_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("DELETE FROM mentor_notes WHERE id = ? AND mentor_id = ? AND student_id = ?");
            $stmt->execute([$noteId, $mentorId, $studentId]);
            $message = 'Note deleted.';
            $msgType = 'info';
        } catch (Exception $e) {
            $message = 'Error deleting note: ' . $e->getMessage();
            $msgType = 'error';
        }
    } elseif ($action === 'add_goal') {
        $goalTitle = trim($_POST['goal_title'] ?? '');
        $targetProblems = !empty($_POST['target_problems']) ? (int)$_POST['target_problems'] : null;
        $targetRating = !empty($_POST['target_rating']) ? (int)$_POST['target_rating'] : null;
        $targetDate = !empty($_POST['target_date']) ? $_POST['target_date'] : null;

        if ($goalTitle === '') {
            $message = 'Please provide a title for the target milestone.';
            $msgType = 'error';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO mentor_goals (mentor_id, student_id, goal_title, target_problems, target_rating, target_date, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
                $stmt->execute([$mentorId, $studentId, $goalTitle, $targetProblems, $targetRating, $targetDate]);
                $message = 'Target goal assigned to junior.';
                $msgType = 'success';
            } catch (Exception $e) {
                $message = 'Error assigning goal: ' . $e->getMessage();
                $msgType = 'error';
            }
        }
    } elseif ($action === 'toggle_goal') {
        $goalId = (int)($_POST['goal_id'] ?? 0);
        $currentStatus = $_POST['current_status'] ?? 'pending';
        $newStatus = ($currentStatus === 'completed') ? 'pending' : 'completed';

        try {
            $stmt = $pdo->prepare("UPDATE mentor_goals SET status = ? WHERE id = ? AND mentor_id = ? AND student_id = ?");
            $stmt->execute([$newStatus, $goalId, $mentorId, $studentId]);
            $message = $newStatus === 'completed' ? 'Milestone marked as completed! 🎉' : 'Milestone reopened.';
            $msgType = 'success';
        } catch (Exception $e) {
            $message = 'Error updating goal: ' . $e->getMessage();
            $msgType = 'error';
        }
    } elseif ($action === 'delete_goal') {
        $goalId = (int)($_POST['goal_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("DELETE FROM mentor_goals WHERE id = ? AND mentor_id = ? AND student_id = ?");
            $stmt->execute([$goalId, $mentorId, $studentId]);
            $message = 'Target goal removed.';
            $msgType = 'info';
        } catch (Exception $e) {
            $message = 'Error removing goal: ' . $e->getMessage();
            $msgType = 'error';
        }
    }
}

/* ==========================================================================
   Fetch Comprehensive Student Data
   ========================================================================== */
// 1. Student User Profile
$stStmt = $pdo->prepare("SELECT id, name, email, join_date FROM users WHERE id = ?");
$stStmt->execute([$studentId]);
$student = $stStmt->fetch(PDO::FETCH_ASSOC);

// Refresh Aggregated Stats
recalculateAggregatedStats($pdo, $studentId);
$aggStmt = $pdo->prepare("SELECT * FROM aggregated_stats WHERE user_id = ?");
$aggStmt->execute([$studentId]);
$stats = $aggStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total_problems_solved' => 0,
    'total_contests'        => 0,
    'composite_score'       => 0,
    'consistency_score'     => 0,
    'problem_solving_score' => 0,
    'last_updated'          => null,
];

// 2. Platform Profiles (CC, CF, LC)
$profStmt = $pdo->prepare("
    SELECT upp.*, p.platform_name, p.base_url
    FROM user_platform_profiles upp
    JOIN platforms p ON upp.platform_id = p.id
    WHERE upp.user_id = ?
    ORDER BY p.platform_name
");
$profStmt->execute([$studentId]);
$platformRows = $profStmt->fetchAll(PDO::FETCH_ASSOC);

$platforms = [];
foreach ($platformRows as $p) {
    $platforms[$p['platform_name']] = $p;
}

$cc = $platforms['CodeChef'] ?? null;
$cf = $platforms['Codeforces'] ?? null;
$lc = $platforms['LeetCode'] ?? null;

// Helper: CodeChef Stars
function get_cc_stars(?int $rating): string {
    if ($rating === null || $rating <= 0) return 'Unrated';
    if ($rating < 1400) return '1★';
    if ($rating < 1600) return '2★';
    if ($rating < 1800) return '3★';
    if ($rating < 2000) return '4★';
    if ($rating < 2200) return '5★';
    if ($rating < 2500) return '6★';
    return '7★';
}

// 3. Contest Participation History
$contestStmt = $pdo->prepare("
    SELECT 
        cp.contest_rank,
        cp.rating_change,
        cp.new_rating,
        c.name AS contest_name,
        c.start_time AS contest_time,
        p.platform_name,
        upp.handle
    FROM contest_performance cp
    JOIN contests c ON cp.contest_id = c.id
    JOIN user_platform_profiles upp ON cp.profile_id = upp.id
    JOIN platforms p ON upp.platform_id = p.id
    WHERE upp.user_id = ?
    ORDER BY c.start_time ASC
");
$contestStmt->execute([$studentId]);
$contests = $contestStmt->fetchAll(PDO::FETCH_ASSOC);

// Chart Data Preparation
$chartLabels = [];
$chartRatings = [];
$chartPlatforms = [];
$chartColors = [];

$palette = [
    'Codeforces' => '#1890ff',
    'LeetCode'   => '#f59e0b',
    'CodeChef'   => '#8b5cf6',
];

foreach ($contests as $ct) {
    if ($ct['contest_rank'] !== null) {
        $chartLabels[] = $ct['contest_name'];
        $chartRatings[] = (float)($ct['new_rating'] ?? ($ct['rating_change'] ?? 0));
        $chartPlatforms[] = $ct['platform_name'];
        $chartColors[] = $palette[$ct['platform_name']] ?? '#3b82f6';
    }
}

// 4. Solved Problems by Difficulty & Platform
$diffStmt = $pdo->prepare("
    SELECT p.platform_name, COALESCE(usp.difficulty_level, 'Unspecified') AS difficulty, COUNT(*) AS count
    FROM user_solved_problems usp
    JOIN platforms p ON usp.platform_id = p.id
    WHERE usp.user_id = ?
    GROUP BY p.platform_name, difficulty
");
$diffStmt->execute([$studentId]);
$diffDistribution = $diffStmt->fetchAll(PDO::FETCH_ASSOC);

$lcDifficulties = ['Easy' => 0, 'Medium' => 0, 'Hard' => 0];
foreach ($diffDistribution as $d) {
    if ($d['platform_name'] === 'LeetCode') {
        $key = ucfirst(strtolower($d['difficulty']));
        if (isset($lcDifficulties[$key])) {
            $lcDifficulties[$key] = (int)$d['count'];
        }
    }
}

// 5. Topic Aggregates
$topicStmt = $pdo->prepare("
    SELECT topic FROM user_solved_problems 
    WHERE user_id = ? AND topic IS NOT NULL AND topic <> ''
");
$topicStmt->execute([$studentId]);
$topicRows = $topicStmt->fetchAll(PDO::FETCH_COLUMN);

$topicCounts = [];
foreach ($topicRows as $tRow) {
    $topics = array_filter(array_map('trim', explode(',', $tRow)));
    foreach ($topics as $t) {
        if ($t !== '') {
            $topicCounts[$t] = ($topicCounts[$t] ?? 0) + 1;
        }
    }
}
arsort($topicCounts);
$topTopics = array_slice($topicCounts, 0, 8, true);

// 6. Recent Submissions / Solves
$recentStmt = $pdo->prepare("
    SELECT usp.*, p.platform_name
    FROM user_solved_problems usp
    JOIN platforms p ON usp.platform_id = p.id
    WHERE usp.user_id = ?
    ORDER BY usp.solved_at DESC
    LIMIT 12
");
$recentStmt->execute([$studentId]);
$recentSolves = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

// 7. Mentor Private Notes
$notesStmt = $pdo->prepare("
    SELECT * FROM mentor_notes 
    WHERE mentor_id = ? AND student_id = ? 
    ORDER BY created_at DESC
");
$notesStmt->execute([$mentorId, $studentId]);
$notes = $notesStmt->fetchAll(PDO::FETCH_ASSOC);

// 8. Mentor Assigned Goals
$goalsStmt = $pdo->prepare("
    SELECT * FROM mentor_goals 
    WHERE mentor_id = ? AND student_id = ? 
    ORDER BY status ASC, target_date ASC, created_at DESC
");
$goalsStmt->execute([$mentorId, $studentId]);
$goals = $goalsStmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../includes/header.php';
?>

<!-- ==========================================================================
     Top Breadcrumb & Student Banner
     ========================================================================== -->
<div style="margin-bottom: 24px;">
    <a href="mentor_dashboard.php" style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.88rem; color: var(--clr-primary); font-weight: 600; margin-bottom: 12px;">
        &larr; Back to Mentor Dashboard
    </a>

    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 20px;">
        <div style="display: flex; align-items: center; gap: 16px;">
            <div style="width: 56px; height: 56px; border-radius: 50%; background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%); color: white; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; font-weight: 700; box-shadow: 0 4px 14px rgba(99, 102, 241, 0.4);">
                <?= strtoupper(substr($student['name'], 0, 1)) ?>
            </div>
            <div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <h1 style="font-size: 2rem; margin: 0;"><?= htmlspecialchars($student['name']) ?></h1>
                    <span class="badge badge-emerald">Junior Student</span>
                </div>
                <div style="font-size: 0.9rem; color: var(--clr-text-muted); margin-top: 4px;">
                    <span><?= htmlspecialchars($student['email']) ?></span> •
                    <span>ID: #<?= (int)$student['id'] ?></span> •
                    <span>Joined: <?= $student['join_date'] ? date('M d, Y', strtotime($student['join_date'])) : 'Recently' ?></span>
                </div>
            </div>
        </div>

        <div style="display: flex; gap: 12px; align-items: center;">
            <a href="#notes-section" class="btn btn-secondary btn-sm">
                <span>📝 Mentor Notes (<?= count($notes) ?>)</span>
            </a>
            <a href="#goals-section" class="btn btn-primary btn-sm">
                <span>🎯 Target Goals (<?= count($goals) ?>)</span>
            </a>
        </div>
    </div>
</div>

<?php if ($message): ?>
    <div class="msg <?= $msgType ?>" style="margin-bottom: 24px;">
        <span><?= $msgType === 'success' ? '✅' : ($msgType === 'error' ? '⚠️' : 'ℹ️') ?></span>
        <span><?= htmlspecialchars($message) ?></span>
    </div>
<?php endif; ?>

<!-- ==========================================================================
     Key Performance Summary Cards
     ========================================================================== -->
<div class="summary-cards" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); margin-bottom: 30px;">
    <!-- Solved Problems -->
    <div class="summary-card">
        <div class="title-row">
            <span class="label">Total Problems Solved</span>
            <div class="icon-bubble">⚡</div>
        </div>
        <div class="metric"><?= number_format((int)$stats['total_problems_solved']) ?></div>
        <div class="meta">Across CC, CF, and LeetCode</div>
    </div>

    <!-- Consistency Score -->
    <div class="summary-card">
        <div class="title-row">
            <span class="label">Consistency Score</span>
            <div class="icon-bubble">📈</div>
        </div>
        <div class="metric" style="color: #34d399;">
            <?= number_format((float)$stats['consistency_score'], 1) ?><span style="font-size: 1.25rem;">%</span>
        </div>
        <div class="meta">Habit, Recency & Solve Momentum</div>
    </div>

    <!-- Problem Solving Skill Score -->
    <div class="summary-card">
        <div class="title-row">
            <span class="label">Problem Solving Skills</span>
            <div class="icon-bubble">🧠</div>
        </div>
        <div class="metric" style="color: #60a5fa;">
            <?= number_format((float)$stats['problem_solving_score'], 1) ?><span style="font-size: 1.25rem;">%</span>
        </div>
        <div class="meta">Rating depth, volume & topics</div>
    </div>

    <!-- Overall Composite Score -->
    <div class="summary-card">
        <div class="title-row">
            <span class="label">Overall Composite Score</span>
            <div class="icon-bubble">⭐</div>
        </div>
        <div class="metric" style="color: #c084fc;">
            <?= number_format((float)$stats['composite_score'], 1) ?><span style="font-size: 1.25rem;">%</span>
        </div>
        <div class="meta"><?= (int)$stats['total_contests'] ?> rated contests logged</div>
    </div>
</div>

<!-- ==========================================================================
     Platform Breakdowns (CodeChef, Codeforces, LeetCode)
     ========================================================================== -->
<h2 style="font-size: 1.4rem; margin-bottom: 16px;">Platform Deep-Dive</h2>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 36px;">

    <!-- 1. CodeChef Card -->
    <div class="card" style="border-top: 3px solid #8b5cf6;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(139, 92, 246, 0.15); display: flex; align-items: center; justify-content: center; font-weight: 700; color: #a78bfa;">
                    CC
                </div>
                <div>
                    <h3 style="font-size: 1.15rem; margin: 0;">CodeChef</h3>
                    <?php if ($cc && !empty($cc['handle'])): ?>
                        <a href="https://www.codechef.com/users/<?= urlencode($cc['handle']) ?>" target="_blank" rel="noopener" style="font-size: 0.85rem; color: #a78bfa;">
                            @<?= htmlspecialchars($cc['handle']) ?> ↗
                        </a>
                    <?php else: ?>
                        <span style="font-size: 0.82rem; color: var(--clr-text-subtle);">Handle not connected</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($cc && $cc['current_rating']): ?>
                <span class="badge badge-purple" style="font-size: 0.82rem;">
                    <?= get_cc_stars((int)$cc['current_rating']) ?>
                </span>
            <?php endif; ?>
        </div>

        <?php if ($cc && !empty($cc['handle'])): ?>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                <div style="padding: 10px; background: var(--clr-bg-subtle); border-radius: var(--radius-sm);">
                    <div style="font-size: 0.75rem; color: var(--clr-text-muted);">Current Rating</div>
                    <div style="font-size: 1.25rem; font-weight: 700; font-family: var(--font-mono); color: #a78bfa;">
                        <?= $cc['current_rating'] !== null ? number_format((int)$cc['current_rating']) : 'Unrated' ?>
                    </div>
                </div>
                <div style="padding: 10px; background: var(--clr-bg-subtle); border-radius: var(--radius-sm);">
                    <div style="font-size: 0.75rem; color: var(--clr-text-muted);">Peak Rating</div>
                    <div style="font-size: 1.25rem; font-weight: 700; font-family: var(--font-mono);">
                        <?= $cc['max_rating'] !== null ? number_format((int)$cc['max_rating']) : '—' ?>
                    </div>
                </div>
                <div style="padding: 10px; background: var(--clr-bg-subtle); border-radius: var(--radius-sm);">
                    <div style="font-size: 0.75rem; color: var(--clr-text-muted);">Problems Solved</div>
                    <div style="font-size: 1.25rem; font-weight: 700; font-family: var(--font-mono);">
                        <?= number_format((int)$cc['problems_solved']) ?>
                    </div>
                </div>
                <div style="padding: 10px; background: var(--clr-bg-subtle); border-radius: var(--radius-sm);">
                    <div style="font-size: 0.75rem; color: var(--clr-text-muted);">Global Division / Tier</div>
                    <div style="font-size: 1rem; font-weight: 600; margin-top: 2px;">
                        <?= !empty($cc['rank_title']) ? htmlspecialchars($cc['rank_title']) : (get_cc_stars((int)($cc['current_rating'] ?? 0))) ?>
                    </div>
                </div>
            </div>
            <div style="font-size: 0.78rem; color: var(--clr-text-subtle);">
                Last synchronized: <?= $cc['last_sync_date'] ? date('M d, Y H:i', strtotime($cc['last_sync_date'])) : 'Manual record' ?>
            </div>
        <?php else: ?>
            <div style="padding: 24px; text-align: center; color: var(--clr-text-muted); font-size: 0.88rem; background: var(--clr-bg-subtle); border-radius: var(--radius-sm);">
                Junior has not configured a CodeChef handle yet.
            </div>
        <?php endif; ?>
    </div>

    <!-- 2. Codeforces Card -->
    <div class="card" style="border-top: 3px solid #1890ff;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(24, 144, 255, 0.15); display: flex; align-items: center; justify-content: center; font-weight: 700; color: #60a5fa;">
                    CF
                </div>
                <div>
                    <h3 style="font-size: 1.15rem; margin: 0;">Codeforces</h3>
                    <?php if ($cf && !empty($cf['handle'])): ?>
                        <a href="https://codeforces.com/profile/<?= urlencode($cf['handle']) ?>" target="_blank" rel="noopener" style="font-size: 0.85rem; color: #60a5fa;">
                            @<?= htmlspecialchars($cf['handle']) ?> ↗
                        </a>
                    <?php else: ?>
                        <span style="font-size: 0.82rem; color: var(--clr-text-subtle);">Handle not connected</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($cf && !empty($cf['rank_title'])): ?>
                <span class="badge badge-blue" style="font-size: 0.82rem;">
                    <?= htmlspecialchars(ucwords($cf['rank_title'])) ?>
                </span>
            <?php endif; ?>
        </div>

        <?php if ($cf && !empty($cf['handle'])): ?>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                <div style="padding: 10px; background: var(--clr-bg-subtle); border-radius: var(--radius-sm);">
                    <div style="font-size: 0.75rem; color: var(--clr-text-muted);">Current Rating</div>
                    <div style="font-size: 1.25rem; font-weight: 700; font-family: var(--font-mono); color: #60a5fa;">
                        <?= $cf['current_rating'] !== null ? number_format((int)$cf['current_rating']) : 'Unrated' ?>
                    </div>
                </div>
                <div style="padding: 10px; background: var(--clr-bg-subtle); border-radius: var(--radius-sm);">
                    <div style="font-size: 0.75rem; color: var(--clr-text-muted);">Max Rating</div>
                    <div style="font-size: 1.25rem; font-weight: 700; font-family: var(--font-mono);">
                        <?= $cf['max_rating'] !== null ? number_format((int)$cf['max_rating']) : '—' ?>
                    </div>
                </div>
                <div style="padding: 10px; background: var(--clr-bg-subtle); border-radius: var(--radius-sm);">
                    <div style="font-size: 0.75rem; color: var(--clr-text-muted);">Problems Solved</div>
                    <div style="font-size: 1.25rem; font-weight: 700; font-family: var(--font-mono);">
                        <?= number_format((int)$cf['problems_solved']) ?>
                    </div>
                </div>
                <div style="padding: 10px; background: var(--clr-bg-subtle); border-radius: var(--radius-sm);">
                    <div style="font-size: 0.75rem; color: var(--clr-text-muted);">Rank Title</div>
                    <div style="font-size: 1rem; font-weight: 600; margin-top: 2px;">
                        <?= !empty($cf['rank_title']) ? htmlspecialchars(ucwords($cf['rank_title'])) : 'Unrated' ?>
                    </div>
                </div>
            </div>
            <div style="font-size: 0.78rem; color: var(--clr-text-subtle);">
                Last synchronized: <?= $cf['last_sync_date'] ? date('M d, Y H:i', strtotime($cf['last_sync_date'])) : 'Manual record' ?>
            </div>
        <?php else: ?>
            <div style="padding: 24px; text-align: center; color: var(--clr-text-muted); font-size: 0.88rem; background: var(--clr-bg-subtle); border-radius: var(--radius-sm);">
                Junior has not configured a Codeforces handle yet.
            </div>
        <?php endif; ?>
    </div>

    <!-- 3. LeetCode Card -->
    <div class="card" style="border-top: 3px solid #f59e0b;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(245, 158, 11, 0.15); display: flex; align-items: center; justify-content: center; font-weight: 700; color: #fbbf24;">
                    LC
                </div>
                <div>
                    <h3 style="font-size: 1.15rem; margin: 0;">LeetCode</h3>
                    <?php if ($lc && !empty($lc['handle'])): ?>
                        <a href="https://leetcode.com/<?= urlencode($lc['handle']) ?>" target="_blank" rel="noopener" style="font-size: 0.85rem; color: #fbbf24;">
                            @<?= htmlspecialchars($lc['handle']) ?> ↗
                        </a>
                    <?php else: ?>
                        <span style="font-size: 0.82rem; color: var(--clr-text-subtle);">Handle not connected</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($lc && (int)$lc['problems_solved'] > 0): ?>
                <span class="badge badge-amber" style="font-size: 0.82rem;">
                    <?= (int)$lc['problems_solved'] ?> Solved
                </span>
            <?php endif; ?>
        </div>

        <?php if ($lc && !empty($lc['handle'])): ?>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                <div style="padding: 10px; background: var(--clr-bg-subtle); border-radius: var(--radius-sm);">
                    <div style="font-size: 0.75rem; color: var(--clr-text-muted);">Total Solved</div>
                    <div style="font-size: 1.25rem; font-weight: 700; font-family: var(--font-mono); color: #fbbf24;">
                        <?= number_format((int)$lc['problems_solved']) ?>
                    </div>
                </div>
                <div style="padding: 10px; background: var(--clr-bg-subtle); border-radius: var(--radius-sm);">
                    <div style="font-size: 0.75rem; color: var(--clr-text-muted);">Contest Rating</div>
                    <div style="font-size: 1.25rem; font-weight: 700; font-family: var(--font-mono);">
                        <?= $lc['current_rating'] !== null ? number_format((int)$lc['current_rating']) : 'Unrated' ?>
                    </div>
                </div>
            </div>

            <!-- Difficulty breakdown -->
            <div style="padding: 12px; background: var(--clr-bg-subtle); border-radius: var(--radius-sm); margin-bottom: 12px;">
                <div style="font-size: 0.75rem; color: var(--clr-text-muted); margin-bottom: 8px;">Difficulty Breakdown:</div>
                <div style="display: flex; gap: 12px; justify-content: space-between;">
                    <div style="text-align: center;">
                        <span style="font-size: 0.75rem; color: #34d399; font-weight: 700;">Easy</span>
                        <div style="font-weight: 700; font-family: var(--font-mono);"><?= $lcDifficulties['Easy'] ?></div>
                    </div>
                    <div style="text-align: center;">
                        <span style="font-size: 0.75rem; color: #fbbf24; font-weight: 700;">Medium</span>
                        <div style="font-weight: 700; font-family: var(--font-mono);"><?= $lcDifficulties['Medium'] ?></div>
                    </div>
                    <div style="text-align: center;">
                        <span style="font-size: 0.75rem; color: #f87171; font-weight: 700;">Hard</span>
                        <div style="font-weight: 700; font-family: var(--font-mono);"><?= $lcDifficulties['Hard'] ?></div>
                    </div>
                </div>
            </div>

            <div style="font-size: 0.78rem; color: var(--clr-text-subtle);">
                Last synchronized: <?= $lc['last_sync_date'] ? date('M d, Y H:i', strtotime($lc['last_sync_date'])) : 'Manual record' ?>
            </div>
        <?php else: ?>
            <div style="padding: 24px; text-align: center; color: var(--clr-text-muted); font-size: 0.88rem; background: var(--clr-bg-subtle); border-radius: var(--radius-sm);">
                Junior has not configured a LeetCode handle yet.
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- ==========================================================================
     Rating Trends Chart & Topics
     ========================================================================== -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px; margin-bottom: 36px;">
    <!-- Rating Trends Chart -->
    <div class="card" style="grid-column: span 2;">
        <h3 style="font-size: 1.25rem; margin-bottom: 4px;">Contest Performance & Rating Trajectory</h3>
        <p style="font-size: 0.88rem; color: var(--clr-text-muted); margin-bottom: 16px;">
            Historical contest rating timeline recorded for <?= htmlspecialchars($student['name']) ?>.
        </p>

        <?php if (empty($chartLabels)): ?>
            <div style="text-align: center; padding: 40px; color: var(--clr-text-muted); font-size: 0.9rem; background: var(--clr-bg-subtle); border-radius: var(--radius-sm);">
                No rated contest entries recorded yet for this student.
            </div>
        <?php else: ?>
            <div style="height: 280px; position: relative;">
                <canvas id="studentRatingChart"></canvas>
            </div>
        <?php endif; ?>
    </div>

    <!-- Strong Topics / Tags -->
    <div class="card">
        <h3 style="font-size: 1.25rem; margin-bottom: 4px;">Algorithmic Topics</h3>
        <p style="font-size: 0.88rem; color: var(--clr-text-muted); margin-bottom: 16px;">
            Most frequently practiced topics & categories.
        </p>

        <?php if (empty($topTopics)): ?>
            <div style="text-align: center; padding: 30px; color: var(--clr-text-muted); font-size: 0.9rem; background: var(--clr-bg-subtle); border-radius: var(--radius-sm);">
                No tagged practice topics logged yet.
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <?php foreach ($topTopics as $topic => $count): 
                    $pct = min(100, round(($count / max(1, (int)$stats['total_problems_solved'])) * 100));
                ?>
                    <div>
                        <div style="display: flex; justify-content: space-between; font-size: 0.85rem; margin-bottom: 4px;">
                            <span style="font-weight: 600;"><?= htmlspecialchars($topic) ?></span>
                            <span style="color: var(--clr-text-muted); font-family: var(--font-mono);"><?= $count ?> solved</span>
                        </div>
                        <div style="height: 6px; background: var(--clr-border); border-radius: 3px; overflow: hidden;">
                            <div style="width: <?= max(8, $pct) ?>%; height: 100%; background: linear-gradient(90deg, #6366f1, #3b82f6);"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ==========================================================================
     Recent Submissions Table
     ========================================================================== -->
<div class="card" style="margin-bottom: 36px;">
    <h3 style="font-size: 1.25rem; margin-bottom: 4px;">Recent Practice Submissions</h3>
    <p style="font-size: 0.88rem; color: var(--clr-text-muted); margin-bottom: 16px;">
        Last 12 solved problems recorded for <?= htmlspecialchars($student['name']) ?>.
    </p>

    <?php if (empty($recentSolves)): ?>
        <div style="text-align: center; padding: 30px; color: var(--clr-text-muted); font-size: 0.9rem; background: var(--clr-bg-subtle); border-radius: var(--radius-sm);">
            No recent submissions logged.
        </div>
    <?php else: ?>
        <div class="table-wrapper" style="margin: 0;">
            <table>
                <thead>
                    <tr>
                        <th>Platform</th>
                        <th>Problem Title / Code</th>
                        <th>Difficulty</th>
                        <th>Topic</th>
                        <th style="text-align: right;">Solved Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentSolves as $slv): 
                        $badgeClass = match($slv['platform_name']) {
                            'Codeforces' => 'badge-blue',
                            'LeetCode'   => 'badge-amber',
                            'CodeChef'   => 'badge-purple',
                            default      => 'badge-gray'
                        };
                    ?>
                        <tr>
                            <td><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($slv['platform_name']) ?></span></td>
                            <td style="font-weight: 600;"><?= htmlspecialchars($slv['title'] ?: $slv['problem_code']) ?></td>
                            <td><?= htmlspecialchars($slv['difficulty_level'] ?: '—') ?></td>
                            <td style="color: var(--clr-text-muted); font-size: 0.85rem;"><?= htmlspecialchars($slv['topic'] ?: '—') ?></td>
                            <td style="text-align: right; font-family: var(--font-mono); font-size: 0.85rem; color: var(--clr-text-muted);">
                                <?= $slv['solved_at'] ? date('M d, Y', strtotime($slv['solved_at'])) : '—' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ==========================================================================
     Mentor Private Notes & Action Goals Section
     ========================================================================== -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px; margin-bottom: 40px;">

    <!-- 1. Private Mentor Notes -->
    <div class="card" id="notes-section">
        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
            <span class="chip" style="background: rgba(139, 92, 246, 0.15); color: #a78bfa; border: 1px solid rgba(139, 92, 246, 0.3);">Private Feedback</span>
        </div>
        <h3 style="font-size: 1.25rem; margin-bottom: 4px;">Mentor Notes & Observations</h3>
        <p style="font-size: 0.85rem; color: var(--clr-text-muted); margin-bottom: 16px;">
            Private remarks on student strengths, weaknesses, and 1-on-1 check-ins. Strictly confidential to you.
        </p>

        <!-- New Note Form -->
        <form method="post" action="mentor_student_detail.php?id=<?= $studentId ?>" style="margin-bottom: 20px;">
            <input type="hidden" name="action" value="add_note">
            <div class="form-group" style="margin-bottom: 12px;">
                <textarea name="note_text" rows="3" required placeholder="Add mentor feedback or observation (e.g. 'Needs more practice on Tree DP and BFS; recommended CF Div 2 B-C problems')..." style="width: 100%; border-radius: var(--radius-sm); border: 1px solid var(--clr-border); background: var(--clr-bg-subtle); color: var(--clr-text-main); padding: 10px; font-family: var(--font-main); font-size: 0.88rem;"></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">
                <span>Save Private Note</span>
            </button>
        </form>

        <!-- Notes List -->
        <div style="display: flex; flex-direction: column; gap: 12px;">
            <?php if (empty($notes)): ?>
                <div style="text-align: center; padding: 20px; color: var(--clr-text-muted); font-size: 0.85rem; background: var(--clr-bg-subtle); border-radius: var(--radius-sm);">
                    No mentor notes recorded yet.
                </div>
            <?php else: ?>
                <?php foreach ($notes as $n): ?>
                    <div style="padding: 12px; background: var(--clr-bg-subtle); border-radius: var(--radius-sm); border-left: 3px solid #8b5cf6;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 6px;">
                            <span style="font-size: 0.75rem; color: var(--clr-text-subtle); font-family: var(--font-mono);">
                                <?= date('M d, Y H:i', strtotime($n['created_at'])) ?>
                            </span>
                            <form method="post" action="mentor_student_detail.php?id=<?= $studentId ?>" style="display: inline;" onsubmit="return confirm('Delete this note?');">
                                <input type="hidden" name="action" value="delete_note">
                                <input type="hidden" name="note_id" value="<?= (int)$n['id'] ?>">
                                <button type="submit" style="background: transparent; border: none; color: var(--clr-danger); cursor: pointer; font-size: 0.78rem; padding: 0;" title="Delete note">
                                    Delete
                                </button>
                            </form>
                        </div>
                        <div style="font-size: 0.9rem; line-height: 1.5; color: var(--clr-text-main); white-space: pre-wrap;">
                            <?= htmlspecialchars($n['note_text']) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- 2. Target Goals & Milestones -->
    <div class="card" id="goals-section">
        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
            <span class="chip" style="background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3);">Milestones</span>
        </div>
        <h3 style="font-size: 1.25rem; margin-bottom: 4px;">Target Goals for Junior</h3>
        <p style="font-size: 0.85rem; color: var(--clr-text-muted); margin-bottom: 16px;">
            Assign actionable milestones for solved problem volumes, target rating tiers, and completion deadlines.
        </p>

        <!-- New Goal Form -->
        <form method="post" action="mentor_student_detail.php?id=<?= $studentId ?>" style="margin-bottom: 20px;">
            <input type="hidden" name="action" value="add_goal">
            <div class="form-group" style="margin-bottom: 10px;">
                <input type="text" name="goal_title" required placeholder="Goal Title (e.g. Reach 1400 on Codeforces & 300 Solves)" style="width: 100%; border-radius: var(--radius-sm); border: 1px solid var(--clr-border); background: var(--clr-bg-subtle); color: var(--clr-text-main); padding: 8px 12px; font-size: 0.88rem;">
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; margin-bottom: 12px;">
                <input type="number" name="target_problems" placeholder="Target Solves" min="1" style="border-radius: var(--radius-sm); border: 1px solid var(--clr-border); background: var(--clr-bg-subtle); color: var(--clr-text-main); padding: 6px 10px; font-size: 0.82rem;">
                <input type="number" name="target_rating" placeholder="Target Rating" min="1" style="border-radius: var(--radius-sm); border: 1px solid var(--clr-border); background: var(--clr-bg-subtle); color: var(--clr-text-main); padding: 6px 10px; font-size: 0.82rem;">
                <input type="date" name="target_date" style="border-radius: var(--radius-sm); border: 1px solid var(--clr-border); background: var(--clr-bg-subtle); color: var(--clr-text-main); padding: 6px 10px; font-size: 0.82rem;">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">
                <span>Assign Goal</span>
            </button>
        </form>

        <!-- Goals List -->
        <div style="display: flex; flex-direction: column; gap: 10px;">
            <?php if (empty($goals)): ?>
                <div style="text-align: center; padding: 20px; color: var(--clr-text-muted); font-size: 0.85rem; background: var(--clr-bg-subtle); border-radius: var(--radius-sm);">
                    No active goals assigned yet.
                </div>
            <?php else: ?>
                <?php foreach ($goals as $g): 
                    $isCompleted = ($g['status'] === 'completed');
                ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: var(--clr-bg-subtle); border-radius: var(--radius-sm); border-left: 3px solid <?= $isCompleted ? '#10b981' : '#f59e0b' ?>;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <form method="post" action="mentor_student_detail.php?id=<?= $studentId ?>" style="margin: 0;">
                                <input type="hidden" name="action" value="toggle_goal">
                                <input type="hidden" name="goal_id" value="<?= (int)$g['id'] ?>">
                                <input type="hidden" name="current_status" value="<?= htmlspecialchars($g['status']) ?>">
                                <button type="submit" style="background: none; border: none; cursor: pointer; font-size: 1.2rem; padding: 0;" title="<?= $isCompleted ? 'Mark Pending' : 'Mark Completed' ?>">
                                    <?= $isCompleted ? '✅' : '⚪' ?>
                                </button>
                            </form>
                            <div>
                                <div style="font-weight: 600; font-size: 0.9rem; <?= $isCompleted ? 'text-decoration: line-through; color: var(--clr-text-muted);' : 'color: var(--clr-text-main);' ?>">
                                    <?= htmlspecialchars($g['goal_title']) ?>
                                </div>
                                <div style="font-size: 0.78rem; color: var(--clr-text-muted); display: flex; gap: 8px; margin-top: 2px;">
                                    <?php if ($g['target_problems']): ?>
                                        <span>Target Solves: <strong><?= (int)$g['target_problems'] ?></strong></span>
                                    <?php endif; ?>
                                    <?php if ($g['target_rating']): ?>
                                        <span>Target Rating: <strong><?= (int)$g['target_rating'] ?></strong></span>
                                    <?php endif; ?>
                                    <?php if ($g['target_date']): ?>
                                        <span>Due: <strong><?= date('M d, Y', strtotime($g['target_date'])) ?></strong></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <form method="post" action="mentor_student_detail.php?id=<?= $studentId ?>" onsubmit="return confirm('Delete this goal?');" style="margin: 0;">
                            <input type="hidden" name="action" value="delete_goal">
                            <input type="hidden" name="goal_id" value="<?= (int)$g['id'] ?>">
                            <button type="submit" style="background: transparent; border: none; color: var(--clr-danger); cursor: pointer; font-size: 0.8rem; padding: 4px;" title="Delete goal">
                                ✕
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Chart.js Script -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const chartLabels = <?= json_encode($chartLabels) ?>;
const chartRatings = <?= json_encode($chartRatings) ?>;
const chartColors = <?= json_encode($chartColors) ?>;

if (chartLabels && chartLabels.length > 0) {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const gridColor = isDark ? 'rgba(255, 255, 255, 0.08)' : 'rgba(0, 0, 0, 0.06)';
    const textColor = isDark ? '#94a3b8' : '#64748b';

    const ctx = document.getElementById('studentRatingChart')?.getContext('2d');
    if (ctx) {
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartLabels,
                datasets: [{
                    label: 'Contest Rating / Performance',
                    data: chartRatings,
                    borderColor: '#8b5cf6',
                    backgroundColor: 'rgba(139, 92, 246, 0.12)',
                    fill: true,
                    borderWidth: 2.5,
                    pointBackgroundColor: chartColors,
                    pointBorderColor: '#ffffff',
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    tension: 0.3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        padding: 12,
                        cornerRadius: 8,
                        callbacks: {
                            label: (ctx) => ` Rating / Rank: ${ctx.parsed.y}`
                        }
                    }
                },
                scales: {
                    y: {
                        grid: { color: gridColor },
                        ticks: { color: textColor }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: textColor, maxRotation: 35, minRotation: 15 }
                    }
                }
            }
        });
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
