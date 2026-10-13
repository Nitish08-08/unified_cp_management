<?php
require 'db.php';

if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if (!empty($_SESSION['user_role']) && $_SESSION['user_role'] === 'mentor') {
    header("Location: mentor_dashboard.php");
    exit;
}

$user_id = $_SESSION['user_id'];

$mentorNotice = '';
$mentorNoticeType = 'info';

/* Handle Mentor Invitation Responses */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mentor_action'])) {
    $mAction = $_POST['mentor_action'];
    $assignmentId = (int)($_POST['assignment_id'] ?? 0);

    if ($mAction === 'accept_mentor') {
        try {
            $stmt = $pdo->prepare("UPDATE mentor_student_assignments SET status = 'accepted', responded_at = NOW() WHERE id = ? AND student_id = ? AND status = 'pending'");
            $stmt->execute([$assignmentId, $user_id]);
            $mentorNotice = 'Mentorship invitation accepted! Your mentor can now guide your competitive programming progress.';
            $mentorNoticeType = 'success';
        } catch (Exception $e) {
            $mentorNotice = 'Error accepting invitation: ' . $e->getMessage();
            $mentorNoticeType = 'error';
        }
    } elseif ($mAction === 'reject_mentor') {
        try {
            $stmt = $pdo->prepare("UPDATE mentor_student_assignments SET status = 'rejected', responded_at = NOW() WHERE id = ? AND student_id = ? AND status = 'pending'");
            $stmt->execute([$assignmentId, $user_id]);
            $mentorNotice = 'Mentorship invitation declined.';
            $mentorNoticeType = 'info';
        } catch (Exception $e) {
            $mentorNotice = 'Error declining invitation: ' . $e->getMessage();
            $mentorNoticeType = 'error';
        }
    } elseif ($mAction === 'leave_mentor') {
        $mentorIdToLeave = (int)($_POST['mentor_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("DELETE FROM mentor_student_assignments WHERE mentor_id = ? AND student_id = ?");
            $stmt->execute([$mentorIdToLeave, $user_id]);
            $mentorNotice = 'Mentorship connection removed.';
            $mentorNoticeType = 'info';
        } catch (Exception $e) {
            $mentorNotice = 'Error removing mentor: ' . $e->getMessage();
            $mentorNoticeType = 'error';
        }
    }
}

$user = ['name' => $_SESSION['user_name'] ?? 'User'];
$stats = [
    'total_problems_solved' => 0,
    'total_contests'        => 0,
    'highest_platform_id'   => null,
    'composite_score'       => null,
    'last_updated'          => null,
];
$profiles = [];
$contests = [];
$strongTopics = [];
$weakTopics = [];
$totalTaggedTopics = 0;
$pendingMentorInvitations = [];
$assignedMentors = [];

if ($pdo !== null) {
    try {
        /* ----------------- Fetch User ----------------- */
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $fetchedUser = $stmt->fetch();
        if ($fetchedUser) {
            $user = $fetchedUser;
        }

        /* ----------------- Aggregated Stats ----------------- */
        $stmt = $pdo->prepare("SELECT * FROM aggregated_stats WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $fetchedStats = $stmt->fetch();
        if ($fetchedStats && isset($fetchedStats['consistency_score']) && $fetchedStats['consistency_score'] !== null) {
            $stats = $fetchedStats;
        } else {
            recalculateAggregatedStats($pdo, $user_id);
            $stmt->execute([$user_id]);
            $stats = $stmt->fetch() ?: $stats;
        }

        /* ----------------- Profiles (CF, LC, CC etc.) ----------------- */
        $stmt = $pdo->prepare("
            SELECT upp.*, p.platform_name
            FROM user_platform_profiles upp
            JOIN platforms p ON p.id = upp.platform_id
            WHERE upp.user_id = ?
            ORDER BY p.platform_name
        ");
        $stmt->execute([$user_id]);
        $profiles = $stmt->fetchAll();

        /* ----------------- Contest History (All Platforms) ----------------- */
        $stmt = $pdo->prepare("
            SELECT 
                cp.contest_rank,
                cp.rating_change,
                c.name        AS contest_name,
                c.start_time  AS contest_time,
                p.platform_name,
                upp.handle
            FROM contest_performance cp
            JOIN contests c              ON cp.contest_id = c.id
            JOIN user_platform_profiles upp ON cp.profile_id = upp.id
            JOIN platforms p             ON upp.platform_id = p.id
            WHERE upp.user_id = ?
            ORDER BY c.start_time ASC
        ");
        $stmt->execute([$user_id]);
        $contests = $stmt->fetchAll();

        /* ----------------- Topic Aggregates ----------------- */
        $topicStmt = $pdo->prepare(
            "SELECT topic FROM user_solved_problems WHERE user_id = ? AND topic IS NOT NULL AND topic <> ''"
        );
        $topicStmt->execute([$user_id]);
        $topicRows = $topicStmt->fetchAll(PDO::FETCH_COLUMN);

        $topicCounts = [];
        foreach ($topicRows as $topicRow) {
            $topics = array_filter(array_map('trim', explode(',', $topicRow)));
            if (empty($topics)) {
                continue;
            }

            foreach ($topics as $topicName) {
                if ($topicName === '') {
                    continue;
                }
                $topicCounts[$topicName] = ($topicCounts[$topicName] ?? 0) + 1;
                $totalTaggedTopics++;
            }
        }

        if (!empty($topicCounts)) {
            $sortedDesc = $topicCounts;
            arsort($sortedDesc);
            $strongTopics = array_slice($sortedDesc, 0, 5, true);

            $sortedAsc = $topicCounts;
            asort($sortedAsc);
            $weakTopics = array_slice($sortedAsc, 0, 5, true);
        }

        /* ----------------- Mentor Data ----------------- */
        $pendingMentorInvitations = get_student_mentor_invitations($pdo, $user_id);
        $assignedMentors = get_student_assigned_mentors($pdo, $user_id);
    } catch (Exception $e) {
        $dbError = $e->getMessage();
    }
}

/* ------- Prepare data for charts ------- */
$chartLabels        = [];
$chartRatingChanges = [];
$chartRanks         = [];
$chartPlatforms     = [];
$chartColors        = [];

$platformPalette = [
    'Codeforces' => '#1890ff',
    'LeetCode'   => '#f59e0b',
    'CodeChef'   => '#8b5cf6',
];

foreach ($contests as $row) {
    if ($row['contest_rank'] === null) {
        continue;
    }

    $label = $row['contest_name'] . ' (' . $row['platform_name'] . ')';
    $chartLabels[]        = $label;
    $chartRatingChanges[] = (float)($row['rating_change'] ?? 0);
    $chartRanks[]         = (int)$row['contest_rank'];
    $chartPlatforms[]     = $row['platform_name'];
    $chartColors[]        = $platformPalette[$row['platform_name']] ?? '#3b82f6';
}

$chartData = [
    'labels'        => $chartLabels,
    'ratingChanges' => $chartRatingChanges,
    'ranks'         => $chartRanks,
    'platforms'     => $chartPlatforms,
    'colors'        => $chartColors,
];

include 'header.php';
?>

<?php if ($mentorNotice): ?>
    <div class="msg <?= $mentorNoticeType ?>" style="margin-bottom: 20px;">
        <span><?= $mentorNoticeType === 'success' ? '✅' : 'ℹ️' ?></span>
        <span><?= htmlspecialchars($mentorNotice) ?></span>
    </div>
<?php endif; ?>

<?php if (!empty($pendingMentorInvitations)): ?>
    <?php foreach ($pendingMentorInvitations as $inv): ?>
        <div class="card" style="margin-bottom: 24px; border: 1px solid rgba(139, 92, 246, 0.4); background: linear-gradient(135deg, rgba(139, 92, 246, 0.1) 0%, rgba(59, 130, 246, 0.05) 100%);">
            <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 16px;">
                <div>
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                        <span class="badge badge-purple">🎓 Mentorship Invitation</span>
                        <span style="font-size: 0.78rem; color: var(--clr-text-subtle);"><?= date('M d, Y', strtotime($inv['invited_at'])) ?></span>
                    </div>
                    <h3 style="font-size: 1.15rem; margin-bottom: 4px;">
                        Mentor <strong><?= htmlspecialchars($inv['mentor_name']) ?></strong> (<?= htmlspecialchars($inv['mentor_email']) ?>) has invited you to their mentorship group
                    </h3>
                    <p style="font-size: 0.88rem; color: var(--clr-text-muted); margin: 0; max-width: 680px;">
                        By accepting, your mentor will be able to review your solved problems, platform ratings, and contest trends to guide your practice. Your private passwords and credentials are never shared.
                    </p>
                </div>
                <div style="display: flex; gap: 10px;">
                    <form method="post" action="dashboard.php" style="margin: 0;">
                        <input type="hidden" name="mentor_action" value="accept_mentor">
                        <input type="hidden" name="assignment_id" value="<?= (int)$inv['assignment_id'] ?>">
                        <button type="submit" class="btn btn-primary btn-sm" style="background: linear-gradient(135deg, #7c3aed 0%, #4f46e5 100%);">
                            Accept Invitation
                        </button>
                    </form>
                    <form method="post" action="dashboard.php" style="margin: 0;" onsubmit="return confirm('Decline this mentorship invitation?');">
                        <input type="hidden" name="mentor_action" value="reject_mentor">
                        <input type="hidden" name="assignment_id" value="<?= (int)$inv['assignment_id'] ?>">
                        <button type="submit" class="btn btn-secondary btn-sm" style="color: var(--clr-text-muted);">
                            Decline
                        </button>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (isset($dbError)): ?>
    <div class="msg error">
        <span>⚠️</span>
        <span>Database error: <?= htmlspecialchars($dbError) ?></span>
    </div>
<?php endif; ?>

<!-- Dashboard Welcome Header -->
<section class="surface">
    <div class="surface-header">
        <div>
            <h2>Welcome back, <?= htmlspecialchars($user['name']) ?>!</h2>
            <p>Unified real-time signals across your competitive programming accounts.</p>
        </div>
        <a href="manage_platforms.php" class="btn btn-secondary btn-sm">
            <span>⚙️ Manage Platforms</span>
        </a>
    </div>

    <?php
    $scoreValue       = isset($stats['composite_score']) && $stats['composite_score'] !== null ? (float)$stats['composite_score'] : 0.0;
    $consistencyValue = isset($stats['consistency_score']) && $stats['consistency_score'] !== null ? (float)$stats['consistency_score'] : 0.0;
    $skillValue       = isset($stats['problem_solving_score']) && $stats['problem_solving_score'] !== null ? (float)$stats['problem_solving_score'] : 0.0;

    // Consistency Badge
    if ($consistencyValue >= 80)     $cBadge = ['text' => '🔥 Streak Master',     'cls' => 'badge-emerald'];
    elseif ($consistencyValue >= 60) $cBadge = ['text' => '⚡ Highly Consistent', 'cls' => 'badge-blue'];
    elseif ($consistencyValue >= 40) $cBadge = ['text' => '📈 Steady Routine',   'cls' => 'badge-amber'];
    else                              $cBadge = ['text' => '🌱 Building Habit',    'cls' => 'badge-gray'];

    // Skill Tier Badge
    if ($skillValue >= 80)     $sBadge = ['text' => '👑 Elite Solver',       'cls' => 'badge-purple'];
    elseif ($skillValue >= 65) $sBadge = ['text' => '🚀 Advanced Solver',    'cls' => 'badge-blue'];
    elseif ($skillValue >= 45) $sBadge = ['text' => '🎯 Proficient Solver',  'cls' => 'badge-emerald'];
    elseif ($skillValue >= 25) $sBadge = ['text' => '💡 Intermediate',       'cls' => 'badge-amber'];
    else                        $sBadge = ['text' => '🌱 Foundational',       'cls' => 'badge-gray'];
    ?>

    <!-- Summary Metrics Grid -->
    <div class="summary-grid">
        <!-- 1. Overall Performance Score -->
        <div class="summary-card">
            <div class="card-top">
                <h4>Performance Score</h4>
                <span class="card-icon">⚡</span>
            </div>
            <div class="metric" data-count="<?= $scoreValue ?>">
                <?= number_format($scoreValue, 1) ?>
            </div>
            <div class="meta">
                <span class="badge badge-blue">Overall Index</span>
                Balanced synthesis of problem-solving depth and active consistency.
            </div>
        </div>

        <!-- 2. Consistency Score -->
        <div class="summary-card">
            <div class="card-top">
                <h4>Consistency Score</h4>
                <span class="card-icon">🔥</span>
            </div>
            <div class="metric" data-count="<?= $consistencyValue ?>">
                <?= number_format($consistencyValue, 1) ?>
            </div>
            <div class="meta">
                <span class="badge <?= $cBadge['cls'] ?>"><?= $cBadge['text'] ?></span>
                Recency decay, contest regularity, and multi-platform habit.
            </div>
        </div>

        <!-- 3. Problem Solving Skills Score -->
        <div class="summary-card">
            <div class="card-top">
                <h4>Solving Skills Score</h4>
                <span class="card-icon">🎯</span>
            </div>
            <div class="metric" data-count="<?= $skillValue ?>">
                <?= number_format($skillValue, 1) ?>
            </div>
            <div class="meta">
                <span class="badge <?= $sBadge['cls'] ?>"><?= $sBadge['text'] ?></span>
                Peak platform ratings, difficulty depth, and solve volume.
            </div>
        </div>

        <!-- 4. Problems Solved -->
        <div class="summary-card">
            <div class="card-top">
                <h4>Problems Solved</h4>
                <span class="card-icon">🧩</span>
            </div>
            <div class="metric" data-count="<?= (int)$stats['total_problems_solved'] ?>">
                <?= number_format((int)$stats['total_problems_solved']) ?>
            </div>
            <div class="meta">Cumulative verified solves across all connected platforms.</div>
        </div>

        <!-- 5. Total Contests -->
        <div class="summary-card">
            <div class="card-top">
                <h4>Total Contests</h4>
                <span class="card-icon">🏆</span>
            </div>
            <div class="metric" data-count="<?= (int)$stats['total_contests'] ?>">
                <?= number_format((int)$stats['total_contests']) ?>
            </div>
            <div class="meta">Official rounds with a recorded rank and participation record.</div>
        </div>

        <!-- 6. Last Synced -->
        <div class="summary-card">
            <div class="card-top">
                <h4>Last Synced</h4>
                <span class="card-icon">🕒</span>
            </div>
            <div class="metric" style="font-size: 1.2rem; font-family: var(--font-main);">
                <?= !empty($stats['last_updated']) ? htmlspecialchars($stats['last_updated']) : 'Never' ?>
            </div>
            <div class="meta">Click a platform button below to trigger live synchronization.</div>
        </div>
    </div>

    <!-- Mathematical Scoring Breakdown Card -->
    <div style="background: rgba(255, 255, 255, 0.03); border: 1px solid var(--clr-border); border-radius: var(--radius-md); padding: 18px 22px; margin: 20px 0;">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; cursor: pointer;" onclick="document.getElementById('math-breakdown').style.display = document.getElementById('math-breakdown').style.display === 'none' ? 'block' : 'none';">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 1.2rem;">📐</span>
                <span style="font-weight: 700; font-size: 0.95rem; color: var(--clr-text-main);">Mathematical Evaluation Model & Scoring Equations</span>
                <span class="badge badge-purple" style="font-size: 0.7rem;">Verified Algorithm</span>
            </div>
            <span style="font-size: 0.85rem; color: var(--clr-primary); font-weight: 600;">Click to View Formulas &darr;</span>
        </div>
        <div id="math-breakdown" style="display: none; margin-top: 16px; border-top: 1px solid var(--clr-border); padding-top: 16px; font-size: 0.88rem; line-height: 1.7; color: var(--clr-text-muted);">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 18px;">
                <div style="background: var(--clr-surface); padding: 14px; border-radius: var(--radius-sm); border: 1px solid var(--clr-border);">
                    <h5 style="color: #60a5fa; margin-bottom: 6px;">1. Overall Performance ($S_{\text{overall}}$)</h5>
                    <code style="display: block; background: rgba(0,0,0,0.25); padding: 6px 10px; border-radius: 4px; font-family: var(--font-mono); margin-bottom: 8px; color: var(--clr-text-main);">
                        S_overall = 0.50 × S_skill + 0.50 × S_consistency
                    </code>
                    <p style="font-size: 0.8rem; margin: 0;">Equally balances algorithmic problem-solving power with continuous practice habits.</p>
                </div>
                <div style="background: var(--clr-surface); padding: 14px; border-radius: var(--radius-sm); border: 1px solid var(--clr-border);">
                    <h5 style="color: #a78bfa; margin-bottom: 6px;">2. Problem Solving Skills ($S_{\text{skill}}$)</h5>
                    <code style="display: block; background: rgba(0,0,0,0.25); padding: 6px 10px; border-radius: 4px; font-family: var(--font-mono); margin-bottom: 8px; color: var(--clr-text-main);">
                        S_skill = 0.45·R_max + 0.35·V(N) + 0.20·D
                    </code>
                    <p style="font-size: 0.8rem; margin: 0;"><strong>R_max</strong>: Normalized peak platform rating; <strong>V(N)</strong>: Solve volume saturation; <strong>D</strong>: Weighted difficulty depth (Hard: 3.0×, Med: 1.8×, Easy: 0.8×).</p>
                </div>
                <div style="background: var(--clr-surface); padding: 14px; border-radius: var(--radius-sm); border: 1px solid var(--clr-border);">
                    <h5 style="color: #34d399; margin-bottom: 6px;">3. Consistency Score ($S_{\text{consistency}}$)</h5>
                    <code style="display: block; background: rgba(0,0,0,0.25); padding: 6px 10px; border-radius: 4px; font-family: var(--font-mono); margin-bottom: 8px; color: var(--clr-text-main);">
                        S_consistency = 0.35·A(Δt) + 0.35·C_freq + 0.15·M + 0.15·P
                    </code>
                    <p style="font-size: 0.8rem; margin: 0;"><strong>A(Δt)</strong>: Recency decay (days elapsed); <strong>C_freq</strong>: Contest participation habit; <strong>M</strong>: Solve momentum; <strong>P</strong>: Multi-platform engagement.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Refresh Actions -->
    <div style="margin-top: 18px;">
        <span style="font-size: 0.85rem; font-weight: 600; color: var(--clr-text-muted); display: block; margin-bottom: 8px;">Trigger Real-time Sync:</span>
        <div class="refresh-buttons">
            <a class="btn-refresh" href="refresh_cf.php">
                <span>🔄 Sync Codeforces</span>
            </a>
            <a class="btn-refresh" href="refresh_lc.php">
                <span>🔄 Sync LeetCode</span>
            </a>
            <a class="btn-refresh" href="refresh_cc.php">
                <span>🔄 Sync CodeChef</span>
            </a>
        </div>
    </div>
</section>

<!-- Topic Strength Profile -->
<section class="surface">
    <div class="surface-header">
        <div>
            <h3>Algorithmic Topic Intelligence</h3>
            <?php if ($totalTaggedTopics > 0): ?>
                <p>Based on <?= number_format($totalTaggedTopics) ?> tagged problem solves analyzed across platforms.</p>
            <?php else: ?>
                <p>Sync your Codeforces or LeetCode problems to unlock detailed topic intelligence.</p>
            <?php endif; ?>
        </div>
        <span class="chip">Auto-Categorized</span>
    </div>

    <?php if ($totalTaggedTopics === 0): ?>
        <p style="margin-bottom: 0;">Connect your platforms from the <a href="manage_platforms.php" style="color:var(--clr-primary);">Platforms</a> page and trigger a refresh to generate this report.</p>
    <?php else: ?>
        <div class="topic-grid">
            <div class="topic-panel">
                <h4><span>🟢</span> Strong Topics</h4>
                <p>Categories where you have achieved the highest volume of solved problems.</p>
                <ul class="topic-list">
                    <?php foreach ($strongTopics as $topic => $count): ?>
                        <?php
                            $topicLabel = ucwords(str_replace(['_', '-'], ' ', $topic));
                            $percent    = $totalTaggedTopics > 0 ? round(($count / $totalTaggedTopics) * 100) : 0;
                        ?>
                        <li>
                            <span><?= htmlspecialchars($topicLabel) ?></span>
                            <span><?= number_format($count) ?> solves<?= $percent > 0 ? ' (' . $percent . '%)' : '' ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="topic-panel">
                <h4><span>🔴</span> Growth Opportunities</h4>
                <p>Categories with low solve counts—great targets for focused practice sprints.</p>
                <ul class="topic-list">
                    <?php foreach ($weakTopics as $topic => $count): ?>
                        <?php
                            $topicLabel = ucwords(str_replace(['_', '-'], ' ', $topic));
                            $percent    = $totalTaggedTopics > 0 ? round(($count / $totalTaggedTopics) * 100) : 0;
                        ?>
                        <li>
                            <span><?= htmlspecialchars($topicLabel) ?></span>
                            <span><?= number_format($count) ?> solves<?= $percent > 0 ? ' (' . $percent . '%)' : '' ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>
</section>

<!-- Platform Profiles Table -->
<section class="surface">
    <div class="surface-header">
        <div>
            <h3>Connected Platform Profiles</h3>
            <p>Your handles and verified ratings fetched across competitive platforms.</p>
        </div>
        <input type="text" data-table-search="platformProfilesTable" placeholder="🔍 Filter profiles..." style="max-width: 240px; padding: 6px 12px; margin: 0; font-size: 0.85rem;">
    </div>

    <div class="table-wrapper">
        <table id="platformProfilesTable">
            <thead>
                <tr>
                    <th>Platform</th>
                    <th>Handle</th>
                    <th>Current Rating</th>
                    <th>Max Rating</th>
                    <th>Solves</th>
                    <th>Rank / Tier</th>
                    <th>Last Synchronized</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($profiles)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 24px; color: var(--clr-text-muted);">
                            No platforms connected yet. <a href="manage_platforms.php" style="color: var(--clr-primary); font-weight: 600;">Link your handles here &rarr;</a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($profiles as $profile): ?>
                        <?php
                            $pLower = strtolower($profile['platform_name']);
                            $badgeClass = 'codeforces';
                            if (strpos($pLower, 'leetcode') !== false) $badgeClass = 'leetcode';
                            elseif (strpos($pLower, 'codechef') !== false) $badgeClass = 'codechef';
                        ?>
                        <tr>
                            <td>
                                <span class="badge-platform <?= $badgeClass ?>"><?= htmlspecialchars($profile['platform_name']) ?></span>
                            </td>
                            <td><strong><?= htmlspecialchars($profile['handle']) ?></strong></td>
                            <td style="font-family: var(--font-mono); font-weight: 600;"><?= htmlspecialchars($profile['current_rating'] ?? '—') ?></td>
                            <td style="font-family: var(--font-mono); color: var(--clr-text-muted);"><?= htmlspecialchars($profile['max_rating'] ?? '—') ?></td>
                            <td style="font-family: var(--font-mono);"><?= number_format((int)($profile['problems_solved'] ?? 0)) ?></td>
                            <td><span class="chip" style="font-size: 0.72rem;"><?= htmlspecialchars($profile['rank_title'] ?? 'Member') ?></span></td>
                            <td style="font-size: 0.85rem; color: var(--clr-text-muted);"><?= htmlspecialchars($profile['last_sync_date'] ?? 'Never') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Visual Analytics: Rating Delta & Ranks -->
<section class="surface">
    <div class="surface-header">
        <div>
            <h3>Contest Rating Delta Trajectory</h3>
            <p>Net rating gains and losses per round over time.</p>
        </div>
    </div>
    <?php if (empty($chartData['labels'])): ?>
        <p style="padding: 24px; text-align: center;">No contest data available yet. Participate in contests or sync your platforms.</p>
    <?php else: ?>
        <canvas id="ratingChart" height="85"></canvas>
    <?php endif; ?>
</section>

<section class="surface">
    <div class="surface-header">
        <div>
            <h3>Contest Rank History</h3>
            <p>Overall standing in each contest (lower rank indicates better standing).</p>
        </div>
    </div>
    <?php if (empty($chartData['labels'])): ?>
        <p style="padding: 24px; text-align: center;">No contest rankings recorded yet.</p>
    <?php else: ?>
        <canvas id="rankChart" height="85"></canvas>
    <?php endif; ?>
</section>

<!-- Contest Performance Log -->
<section class="surface">
    <div class="surface-header">
        <div>
            <h3>Official Contest History Log</h3>
            <p>Comprehensive ledger of official contests and performance deltas.</p>
        </div>
        <input type="text" data-table-search="contestLogTable" placeholder="🔍 Search contest name or handle..." style="max-width: 280px; padding: 6px 12px; margin: 0; font-size: 0.85rem;">
    </div>

    <div class="table-wrapper">
        <table id="contestLogTable">
            <thead>
                <tr>
                    <th>Platform</th>
                    <th>Contest Name</th>
                    <th>Handle</th>
                    <th>Date / Time</th>
                    <th>Rank</th>
                    <th>Rating Change</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($contests)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 24px; color: var(--clr-text-muted);">
                            No contests recorded yet. Sync a platform with contest history.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($contests as $contest): ?>
                        <?php
                            $pLower = strtolower($contest['platform_name']);
                            $badgeClass = 'codeforces';
                            if (strpos($pLower, 'leetcode') !== false) $badgeClass = 'leetcode';
                            elseif (strpos($pLower, 'codechef') !== false) $badgeClass = 'codechef';
                            
                            $delta = $contest['rating_change'];
                        ?>
                        <tr>
                            <td><span class="badge-platform <?= $badgeClass ?>"><?= htmlspecialchars($contest['platform_name']) ?></span></td>
                            <td><strong><?= htmlspecialchars($contest['contest_name']) ?></strong></td>
                            <td><?= htmlspecialchars($contest['handle']) ?></td>
                            <td style="font-size: 0.85rem; color: var(--clr-text-muted);"><?= htmlspecialchars($contest['contest_time']) ?></td>
                            <td style="font-family: var(--font-mono); font-weight: 600;"><?= $contest['contest_rank'] !== null ? number_format((int)$contest['contest_rank']) : '—' ?></td>
                            <td>
                                <?php if ($delta !== null): ?>
                                    <?php if ($delta > 0): ?>
                                        <span class="delta-positive">+<?= (int)$delta ?></span>
                                    <?php elseif ($delta < 0): ?>
                                        <span class="delta-negative"><?= (int)$delta ?></span>
                                    <?php else: ?>
                                        <span style="color: var(--clr-text-muted);">0</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: var(--clr-text-muted);">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Connected Mentors Section -->
<section class="surface" style="margin-top: 30px;">
    <div class="surface-header">
        <div>
            <h3>Connected Mentors</h3>
            <p>Faculty & Mentors authorized to monitor your competitive programming path.</p>
        </div>
    </div>
    <?php if (empty($assignedMentors)): ?>
        <div style="padding: 24px; text-align: center; color: var(--clr-text-muted); font-size: 0.9rem; background: var(--clr-bg-subtle); border-radius: var(--radius-sm);">
            You are not currently linked with any mentors. If your teacher or coach sends you an invitation, it will appear at the top of your dashboard.
        </div>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px;">
            <?php foreach ($assignedMentors as $am): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; background: var(--clr-bg-subtle); border-radius: var(--radius-sm); border: 1px solid var(--clr-border);">
                    <div>
                        <div style="font-weight: 700; font-size: 0.95rem; color: var(--clr-text-main);">
                            <?= htmlspecialchars($am['mentor_name']) ?>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--clr-text-muted);">
                            <?= htmlspecialchars($am['mentor_email']) ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--clr-text-subtle); margin-top: 4px;">
                            Connected since: <?= $am['responded_at'] ? date('M d, Y', strtotime($am['responded_at'])) : 'Active' ?>
                        </div>
                    </div>
                    <form method="post" action="dashboard.php" onsubmit="return confirm('Disconnect from mentor <?= htmlspecialchars(addslashes($am['mentor_name'])) ?>?');">
                        <input type="hidden" name="mentor_action" value="leave_mentor">
                        <input type="hidden" name="mentor_id" value="<?= (int)$am['mentor_id'] ?>">
                        <button type="submit" class="btn btn-secondary btn-sm" style="color: var(--clr-danger); border-color: rgba(239,68,68,0.2);">
                            Disconnect
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        <div style="margin-top: 14px; font-size: 0.8rem; color: var(--clr-text-muted);">
            🔒 <strong>Privacy Assurance:</strong> Mentors can view your platform handles, rating trajectories, and solved problems. They cannot see your password, personal settings, or manage your platform connections.
        </div>
    <?php endif; ?>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const chartData = <?= json_encode($chartData) ?>;

if (chartData && chartData.labels && chartData.labels.length > 0) {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const gridColor = isDark ? 'rgba(255, 255, 255, 0.08)' : 'rgba(0, 0, 0, 0.06)';
    const textColor = isDark ? '#94a3b8' : '#64748b';

    const ratingCtx = document.getElementById('ratingChart')?.getContext('2d');
    if (ratingCtx) {
        new Chart(ratingCtx, {
            type: 'line',
            data: {
                labels: chartData.labels,
                datasets: [{
                    label: 'Rating Delta',
                    data: chartData.ratingChanges,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.15)',
                    fill: true,
                    borderWidth: 2.5,
                    pointBackgroundColor: '#3b82f6',
                    pointBorderColor: '#ffffff',
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    tension: 0.35
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        padding: 12,
                        cornerRadius: 8,
                        callbacks: {
                            label: (ctx) => ` Rating Delta: ${ctx.parsed.y > 0 ? '+' : ''}${ctx.parsed.y}`,
                            afterLabel: (ctx) => 'Platform: ' + chartData.platforms[ctx.dataIndex]
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
                        ticks: { color: textColor, maxRotation: 40, minRotation: 20 }
                    }
                }
            }
        });
    }

    const rankCtx = document.getElementById('rankChart')?.getContext('2d');
    if (rankCtx) {
        new Chart(rankCtx, {
            type: 'bar',
            data: {
                labels: chartData.labels,
                datasets: [{
                    label: 'Contest Rank',
                    data: chartData.ranks,
                    backgroundColor: chartData.colors,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        reverse: true,
                        grid: { color: gridColor },
                        ticks: { color: textColor }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: textColor, maxRotation: 40, minRotation: 20 }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        padding: 12,
                        cornerRadius: 8,
                        callbacks: {
                            label: (ctx) => ` Rank: #${ctx.parsed.y}`,
                            afterLabel: (ctx) => 'Platform: ' + chartData.platforms[ctx.dataIndex]
                        }
                    }
                }
            }
        });
    }
}
</script>

<?php include 'footer.php'; ?>
