<?php
require 'db.php';
require_mentor();

$mentorId = (int)$_SESSION['user_id'];
$message = '';
$msgType = 'info';

/* ==========================================================================
   Handle POST Actions (Invite, Cancel Invite, Remove Junior)
   ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'invite_student') {
        $identifier = trim($_POST['student_identifier'] ?? '');

        if ($identifier === '') {
            $message = 'Please provide a student email address or Student ID.';
            $msgType = 'error';
        } else {
            try {
                // Find user by email or numeric ID
                $stmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE email = ? OR id = ? LIMIT 1");
                $stmt->execute([$identifier, is_numeric($identifier) ? (int)$identifier : -1]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$student) {
                    $message = 'No account found with identifier: "' . htmlspecialchars($identifier) . '". Ensure your junior has registered a student account.';
                    $msgType = 'error';
                } elseif ((int)$student['id'] === $mentorId) {
                    $message = 'You cannot invite your own mentor account as a junior.';
                    $msgType = 'error';
                } elseif ($student['role'] === 'mentor') {
                    $message = 'The specified user (' . htmlspecialchars($student['email']) . ') is registered as a Mentor. Mentors cannot be added as juniors.';
                    $msgType = 'error';
                } else {
                    $studentId = (int)$student['id'];

                    // Check existing assignment
                    $checkStmt = $pdo->prepare("SELECT id, status FROM mentor_student_assignments WHERE mentor_id = ? AND student_id = ?");
                    $checkStmt->execute([$mentorId, $studentId]);
                    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

                    if ($existing) {
                        if ($existing['status'] === 'accepted') {
                            $message = htmlspecialchars($student['name']) . ' (' . htmlspecialchars($student['email']) . ') is already in your mentorship group.';
                            $msgType = 'error';
                        } elseif ($existing['status'] === 'pending') {
                            $message = 'An invitation has already been sent to ' . htmlspecialchars($student['name']) . ' and is awaiting their acceptance.';
                            $msgType = 'info';
                        } else {
                            // Re-open rejected invitation
                            $upd = $pdo->prepare("UPDATE mentor_student_assignments SET status = 'pending', invited_at = NOW(), responded_at = NULL WHERE id = ?");
                            $upd->execute([$existing['id']]);
                            $message = 'Invitation re-sent to ' . htmlspecialchars($student['name']) . ' (' . htmlspecialchars($student['email']) . ').';
                            $msgType = 'success';
                        }
                    } else {
                        // Create new pending invitation
                        $ins = $pdo->prepare("INSERT INTO mentor_student_assignments (mentor_id, student_id, status, invited_at) VALUES (?, ?, 'pending', NOW())");
                        $ins->execute([$mentorId, $studentId]);
                        $message = 'Invitation successfully sent to ' . htmlspecialchars($student['name']) . ' (' . htmlspecialchars($student['email']) . ')! They can accept it from their student dashboard.';
                        $msgType = 'success';
                    }
                }
            } catch (Exception $e) {
                $message = 'Failed to process invitation: ' . $e->getMessage();
                $msgType = 'error';
            }
        }
    } elseif ($action === 'cancel_invitation') {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        try {
            $del = $pdo->prepare("DELETE FROM mentor_student_assignments WHERE id = ? AND mentor_id = ? AND status = 'pending'");
            $del->execute([$assignmentId, $mentorId]);
            $message = 'Invitation cancelled.';
            $msgType = 'info';
        } catch (Exception $e) {
            $message = 'Error cancelling invitation: ' . $e->getMessage();
            $msgType = 'error';
        }
    } elseif ($action === 'remove_student') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        try {
            $del = $pdo->prepare("DELETE FROM mentor_student_assignments WHERE mentor_id = ? AND student_id = ?");
            $del->execute([$mentorId, $studentId]);
            $message = 'Student removed from your mentorship group.';
            $msgType = 'info';
        } catch (Exception $e) {
            $message = 'Error removing student: ' . $e->getMessage();
            $msgType = 'error';
        }
    }
}

/* ==========================================================================
   Fetch Assigned Students and Group Metrics
   ========================================================================== */
$assignedStudents = get_mentor_assigned_students($pdo, $mentorId);
$pendingInvitations = get_mentor_pending_invitations($pdo, $mentorId);

$totalJuniors = count($assignedStudents);
$groupTotalProblems = 0;
$groupTotalContests = 0;
$improvingCount = 0;
$attentionCount = 0;
$inactiveCount = 0;

$cfSolvedTotal = 0;
$lcSolvedTotal = 0;
$ccSolvedTotal = 0;

$studentIds = [];

foreach ($assignedStudents as &$st) {
    $studentIds[] = (int)$st['id'];
    $groupTotalProblems += (int)$st['total_problems_solved'];
    $groupTotalContests += (int)$st['total_contests'];

    // Platform solves
    if (isset($st['platforms']['Codeforces'])) {
        $cfSolvedTotal += (int)$st['platforms']['Codeforces']['problems_solved'];
    }
    if (isset($st['platforms']['LeetCode'])) {
        $lcSolvedTotal += (int)$st['platforms']['LeetCode']['problems_solved'];
    }
    if (isset($st['platforms']['CodeChef'])) {
        $ccSolvedTotal += (int)$st['platforms']['CodeChef']['problems_solved'];
    }

    // Engagement status determination
    $consistency = (float)$st['consistency_score'];
    $lastUpdated = $st['last_updated'] ? strtotime($st['last_updated']) : null;
    $daysSinceUpdate = $lastUpdated ? max(0, (time() - $lastUpdated) / 86400) : 999;

    if ($consistency >= 60 || $daysSinceUpdate <= 7) {
        $st['status_tag'] = 'improving';
        $st['status_label'] = 'Improving / Active';
        $st['status_badge'] = 'badge-emerald';
        $improvingCount++;
    } elseif ($consistency >= 35 || $daysSinceUpdate <= 21) {
        $st['status_tag'] = 'attention';
        $st['status_label'] = 'Needs Attention';
        $st['status_badge'] = 'badge-amber';
        $attentionCount++;
    } else {
        $st['status_tag'] = 'inactive';
        $st['status_label'] = 'Inactive';
        $st['status_badge'] = 'badge-gray';
        $inactiveCount++;
    }
}
unset($st);

// Recent activity across assigned students
$recentSolves = [];
if (!empty($studentIds)) {
    $inPlaceholders = implode(',', array_fill(0, count($studentIds), '?'));
    $actStmt = $pdo->prepare("
        SELECT usp.title, usp.problem_code, usp.difficulty_level, usp.solved_at, u.name AS student_name, p.platform_name
        FROM user_solved_problems usp
        JOIN users u ON usp.user_id = u.id
        JOIN platforms p ON usp.platform_id = p.id
        WHERE usp.user_id IN ($inPlaceholders)
        ORDER BY usp.solved_at DESC
        LIMIT 6
    ");
    $actStmt->execute($studentIds);
    $recentSolves = $actStmt->fetchAll(PDO::FETCH_ASSOC);
}

include 'header.php';
?>

<div style="margin-bottom: 24px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 16px;">
    <div>
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
            <span class="badge badge-purple" style="font-size: 0.8rem; padding: 4px 10px;">Mentor Command Center</span>
            <span style="font-size: 0.82rem; color: var(--clr-text-muted);">Session ID: #<?= htmlspecialchars($mentorId) ?></span>
        </div>
        <h1 style="font-size: 2rem; margin: 0;">Junior Monitoring & Analytics</h1>
        <p style="color: var(--clr-text-muted); font-size: 0.95rem; margin-top: 4px;">
            Track, guide, and benchmark your juniors across Codeforces, LeetCode, and CodeChef.
        </p>
    </div>
    <div style="display: flex; gap: 10px;">
        <button type="button" class="btn btn-primary" onclick="document.getElementById('invite-section').scrollIntoView({behavior: 'smooth'}); document.getElementById('student_identifier').focus();">
            <span>+ Invite Junior</span>
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="msg <?= $msgType ?>" style="margin-bottom: 24px;">
        <span><?= $msgType === 'success' ? '✅' : ($msgType === 'error' ? '⚠️' : 'ℹ️') ?></span>
        <span><?= $message ?></span>
    </div>
<?php endif; ?>

<!-- ==========================================================================
     Top Overview Cards
     ========================================================================== -->
<div class="summary-cards" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); margin-bottom: 30px;">
    <!-- Total Juniors -->
    <div class="summary-card">
        <div class="title-row">
            <span class="label">Assigned Juniors</span>
            <div class="icon-bubble">👥</div>
        </div>
        <div class="metric"><?= number_format($totalJuniors) ?></div>
        <div class="meta">
            <?= count($pendingInvitations) ?> pending invitation<?= count($pendingInvitations) === 1 ? '' : 's' ?>
        </div>
    </div>

    <!-- Total Problems Solved -->
    <div class="summary-card">
        <div class="title-row">
            <span class="label">Total Solves (Group)</span>
            <div class="icon-bubble">⚡</div>
        </div>
        <div class="metric"><?= number_format($groupTotalProblems) ?></div>
        <div class="meta" style="display: flex; gap: 8px; flex-wrap: wrap;">
            <span style="color: #60a5fa;">CF: <?= number_format($cfSolvedTotal) ?></span> •
            <span style="color: #fbbf24;">LC: <?= number_format($lcSolvedTotal) ?></span> •
            <span style="color: #a78bfa;">CC: <?= number_format($ccSolvedTotal) ?></span>
        </div>
    </div>

    <!-- Group Contests -->
    <div class="summary-card">
        <div class="title-row">
            <span class="label">Contest Participations</span>
            <div class="icon-bubble">🏆</div>
        </div>
        <div class="metric"><?= number_format($groupTotalContests) ?></div>
        <div class="meta">
            Across rated platforms
        </div>
    </div>

    <!-- Engagement Status -->
    <div class="summary-card">
        <div class="title-row">
            <span class="label">Engagement Overview</span>
            <div class="icon-bubble">🎯</div>
        </div>
        <div class="metric" style="font-size: 1.5rem; display: flex; align-items: center; gap: 8px; padding-top: 4px;">
            <span style="color: #34d399;"><?= $improvingCount ?> Active</span>
            <span style="font-size: 1rem; color: var(--clr-text-muted);">/</span>
            <span style="color: #fbbf24;"><?= $attentionCount ?> Alert</span>
            <span style="font-size: 1rem; color: var(--clr-text-muted);">/</span>
            <span style="color: #94a3b8;"><?= $inactiveCount ?> Inactive</span>
        </div>
        <div class="meta">
            <?= $attentionCount ?> junior<?= $attentionCount === 1 ? '' : 's' ?> need mentoring check-in
        </div>
    </div>
</div>

<!-- ==========================================================================
     Pending Invitations Section (if any)
     ========================================================================== -->
<?php if (!empty($pendingInvitations)): ?>
    <div class="card" style="margin-bottom: 28px; border-left: 4px solid var(--clr-warning);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
            <h3 style="font-size: 1.1rem; display: flex; align-items: center; gap: 8px;">
                <span>⏳ Pending Student Invitations</span>
                <span class="badge badge-amber"><?= count($pendingInvitations) ?></span>
            </h3>
        </div>
        <p style="font-size: 0.88rem; color: var(--clr-text-muted); margin-bottom: 14px;">
            These students have been invited. Once they log in and accept the invitation on their dashboard, their statistics will populate your monitoring table.
        </p>
        <div class="table-wrapper" style="margin: 0;">
            <table>
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Email</th>
                        <th>Invited Date</th>
                        <th>Status</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingInvitations as $pInv): ?>
                        <tr>
                            <td style="font-weight: 600;"><?= htmlspecialchars($pInv['student_name']) ?></td>
                            <td style="color: var(--clr-text-muted);"><?= htmlspecialchars($pInv['student_email']) ?></td>
                            <td style="font-size: 0.85rem;"><?= htmlspecialchars(date('M d, Y H:i', strtotime($pInv['invited_at']))) ?></td>
                            <td><span class="badge badge-amber">Awaiting Student Acceptance</span></td>
                            <td style="text-align: right;">
                                <form method="post" action="mentor_dashboard.php" style="display: inline;" onsubmit="return confirm('Cancel this invitation?');">
                                    <input type="hidden" name="action" value="cancel_invitation">
                                    <input type="hidden" name="assignment_id" value="<?= (int)$pInv['assignment_id'] ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" style="color: var(--clr-danger);">
                                        Cancel
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- ==========================================================================
     Assigned Juniors Directory & Analytics Table
     ========================================================================== -->
<div class="card" style="margin-bottom: 30px;">
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 20px;">
        <div>
            <h2 style="font-size: 1.4rem; margin-bottom: 4px;">Assigned Juniors Directory</h2>
            <p style="font-size: 0.88rem; color: var(--clr-text-muted);">
                Detailed competitive programming profiles, platform ratings, and consistency scores.
            </p>
        </div>

        <!-- Filter & Search Controls -->
        <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
            <input type="text" id="studentSearch" placeholder="🔍 Search student, email, handle..." style="padding: 8px 14px; font-size: 0.88rem; border-radius: var(--radius-sm); border: 1px solid var(--clr-border); background: var(--clr-bg-subtle); color: var(--clr-text-main); min-width: 240px;" onkeyup="filterStudentsTable()">
            
            <select id="statusFilter" style="padding: 8px 14px; font-size: 0.88rem; border-radius: var(--radius-sm); border: 1px solid var(--clr-border); background: var(--clr-bg-subtle); color: var(--clr-text-main);" onchange="filterStudentsTable()">
                <option value="all">All Statuses (<?= $totalJuniors ?>)</option>
                <option value="improving">Active / Improving (<?= $improvingCount ?>)</option>
                <option value="attention">Needs Attention (<?= $attentionCount ?>)</option>
                <option value="inactive">Inactive (<?= $inactiveCount ?>)</option>
            </select>
        </div>
    </div>

    <?php if (empty($assignedStudents)): ?>
        <div style="text-align: center; padding: 48px 20px; background: var(--clr-bg-subtle); border-radius: var(--radius-md); border: 1px dashed var(--clr-border);">
            <div style="font-size: 3rem; margin-bottom: 12px;">🎓</div>
            <h3 style="font-size: 1.25rem; margin-bottom: 6px;">No Juniors Assigned Yet</h3>
            <p style="color: var(--clr-text-muted); max-width: 500px; margin: 0 auto 20px; font-size: 0.92rem;">
                Build your mentorship roster! Invite your students using their registered email address or Student ID below. Once accepted, their performance will appear here.
            </p>
            <button type="button" class="btn btn-primary" onclick="document.getElementById('student_identifier').focus();">
                <span>Send First Invitation</span>
            </button>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table id="studentsTable">
                <thead>
                    <tr>
                        <th onclick="sortTable(0)" style="cursor: pointer;">Student Profile ⇕</th>
                        <th>CodeChef</th>
                        <th>Codeforces</th>
                        <th>LeetCode</th>
                        <th onclick="sortTable(4)" style="cursor: pointer;">Total Solved ⇕</th>
                        <th onclick="sortTable(5)" style="cursor: pointer;">Consistency ⇕</th>
                        <th>Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assignedStudents as $st): 
                        $initials = strtoupper(substr($st['name'], 0, 1));
                        $cc = $st['platforms']['CodeChef'] ?? null;
                        $cf = $st['platforms']['Codeforces'] ?? null;
                        $lc = $st['platforms']['LeetCode'] ?? null;
                    ?>
                        <tr class="student-row" data-name="<?= htmlspecialchars(strtolower($st['name'])) ?>" data-email="<?= htmlspecialchars(strtolower($st['email'])) ?>" data-handles="<?= htmlspecialchars(strtolower(($cc['handle'] ?? '') . ' ' . ($cf['handle'] ?? '') . ' ' . ($lc['handle'] ?? ''))) ?>" data-status="<?= $st['status_tag'] ?>">
                            <!-- Student -->
                            <td>
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <div style="width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.9rem;">
                                        <?= $initials ?>
                                    </div>
                                    <div>
                                        <a href="mentor_student_detail.php?id=<?= $st['id'] ?>" style="font-weight: 700; color: var(--clr-text-main); font-size: 0.95rem;" class="hover-underline">
                                            <?= htmlspecialchars($st['name']) ?>
                                        </a>
                                        <div style="font-size: 0.78rem; color: var(--clr-text-muted);">
                                            <?= htmlspecialchars($st['email']) ?>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <!-- CodeChef -->
                            <td>
                                <?php if ($cc && !empty($cc['handle'])): ?>
                                    <div style="font-weight: 600; font-size: 0.88rem;">
                                        <a href="https://www.codechef.com/users/<?= urlencode($cc['handle']) ?>" target="_blank" rel="noopener" style="color: #a78bfa;">
                                            <?= htmlspecialchars($cc['handle']) ?> ↗
                                        </a>
                                    </div>
                                    <div style="font-size: 0.8rem; color: var(--clr-text-muted);">
                                        Rating: <strong><?= $cc['current_rating'] ? htmlspecialchars($cc['current_rating']) : '—' ?></strong>
                                        <?php if (!empty($cc['rank_title'])): ?>
                                            (<?= htmlspecialchars($cc['rank_title']) ?>)
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: var(--clr-text-subtle); font-size: 0.82rem;">Not connected</span>
                                <?php endif; ?>
                            </td>

                            <!-- Codeforces -->
                            <td>
                                <?php if ($cf && !empty($cf['handle'])): ?>
                                    <div style="font-weight: 600; font-size: 0.88rem;">
                                        <a href="https://codeforces.com/profile/<?= urlencode($cf['handle']) ?>" target="_blank" rel="noopener" style="color: #60a5fa;">
                                            <?= htmlspecialchars($cf['handle']) ?> ↗
                                        </a>
                                    </div>
                                    <div style="font-size: 0.8rem; color: var(--clr-text-muted);">
                                        Rating: <strong><?= $cf['current_rating'] ? htmlspecialchars($cf['current_rating']) : '—' ?></strong>
                                        <?php if (!empty($cf['rank_title'])): ?>
                                            (<?= htmlspecialchars($cf['rank_title']) ?>)
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: var(--clr-text-subtle); font-size: 0.82rem;">Not connected</span>
                                <?php endif; ?>
                            </td>

                            <!-- LeetCode -->
                            <td>
                                <?php if ($lc && !empty($lc['handle'])): ?>
                                    <div style="font-weight: 600; font-size: 0.88rem;">
                                        <a href="https://leetcode.com/<?= urlencode($lc['handle']) ?>" target="_blank" rel="noopener" style="color: #fbbf24;">
                                            <?= htmlspecialchars($lc['handle']) ?> ↗
                                        </a>
                                    </div>
                                    <div style="font-size: 0.8rem; color: var(--clr-text-muted);">
                                        Solved: <strong><?= (int)($lc['problems_solved'] ?? 0) ?></strong>
                                    </div>
                                <?php else: ?>
                                    <span style="color: var(--clr-text-subtle); font-size: 0.82rem;">Not connected</span>
                                <?php endif; ?>
                            </td>

                            <!-- Total Solved -->
                            <td>
                                <div style="font-family: var(--font-mono); font-weight: 700; font-size: 1.1rem; color: var(--clr-text-main);">
                                    <?= number_format($st['total_problems_solved']) ?>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--clr-text-muted);">
                                    <?= (int)$st['total_contests'] ?> contest<?= (int)$st['total_contests'] === 1 ? '' : 's' ?>
                                </div>
                            </td>

                            <!-- Consistency Score -->
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <div style="font-weight: 700; font-size: 0.95rem; font-family: var(--font-mono);">
                                        <?= number_format($st['consistency_score'], 1) ?>%
                                    </div>
                                </div>
                                <div style="width: 80px; height: 6px; background: var(--clr-border); border-radius: 4px; overflow: hidden; margin-top: 4px;">
                                    <div style="width: <?= min(100, max(5, (float)$st['consistency_score'])) ?>%; height: 100%; background: linear-gradient(90deg, #3b82f6, #10b981);"></div>
                                </div>
                            </td>

                            <!-- Status -->
                            <td>
                                <span class="badge <?= $st['status_badge'] ?>">
                                    <?= $st['status_label'] ?>
                                </span>
                            </td>

                            <!-- Actions -->
                            <td style="text-align: right; white-space: nowrap;">
                                <a href="mentor_student_detail.php?id=<?= $st['id'] ?>" class="btn btn-secondary btn-sm" style="margin-right: 6px;">
                                    <span>Analytics &rarr;</span>
                                </a>
                                <form method="post" action="mentor_dashboard.php" style="display: inline;" onsubmit="return confirm('Remove <?= htmlspecialchars(addslashes($st['name'])) ?> from your mentorship group?');">
                                    <input type="hidden" name="action" value="remove_student">
                                    <input type="hidden" name="student_id" value="<?= (int)$st['id'] ?>">
                                    <button type="submit" class="btn btn-sm" style="background: transparent; color: var(--clr-danger); border: 1px solid rgba(239, 68, 68, 0.2); padding: 5px 8px;" title="Remove student">
                                        ✕
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ==========================================================================
     Bottom Grid: Invite Junior & Recent Solves
     ========================================================================== -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px; margin-bottom: 40px;">
    <!-- Invite Junior Card -->
    <div class="card" id="invite-section">
        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
            <span class="chip" style="background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3);">Assignment System</span>
        </div>
        <h3 style="font-size: 1.25rem; margin-bottom: 6px;">Invite Junior / Student</h3>
        <p style="font-size: 0.88rem; color: var(--clr-text-muted); margin-bottom: 18px;">
            Enter your junior's registered email address or their Student ID. An invitation will be dispatched immediately for their approval.
        </p>

        <form method="post" action="mentor_dashboard.php">
            <input type="hidden" name="action" value="invite_student">
            
            <div class="form-group" style="margin-bottom: 16px;">
                <label for="student_identifier">Student Email or ID</label>
                <input type="text" id="student_identifier" name="student_identifier" required placeholder="e.g. student@college.edu or 30001" style="background: var(--clr-bg-subtle);">
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%;">
                <span>Send Group Invitation</span>
            </button>
        </form>

        <div style="margin-top: 16px; padding: 12px; border-radius: var(--radius-sm); background: var(--clr-bg-subtle); border: 1px solid var(--clr-border-subtle); font-size: 0.8rem; color: var(--clr-text-muted);">
            🔒 <strong>Strict Privacy:</strong> Mentors can only view public platform metrics, handles, contest ratings, and practice solves. Passwords, auth keys, and personal credentials are never disclosed.
        </div>
    </div>

    <!-- Recent Solves Stream -->
    <div class="card">
        <h3 style="font-size: 1.25rem; margin-bottom: 6px;">Recent Junior Practice Stream</h3>
        <p style="font-size: 0.88rem; color: var(--clr-text-muted); margin-bottom: 18px;">
            Live feed of solved problems across your active mentorship roster.
        </p>

        <?php if (empty($recentSolves)): ?>
            <div style="text-align: center; padding: 32px 16px; color: var(--clr-text-muted); font-size: 0.9rem;">
                No recent problem submissions recorded yet. Once your juniors sync their platforms or log solves, they will stream here.
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <?php foreach ($recentSolves as $solv): 
                    $platColor = match($solv['platform_name']) {
                        'Codeforces' => '#60a5fa',
                        'LeetCode'   => '#fbbf24',
                        'CodeChef'   => '#a78bfa',
                        default      => 'var(--clr-primary)'
                    };
                ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: var(--clr-bg-subtle); border-radius: var(--radius-sm); border: 1px solid var(--clr-border-subtle);">
                        <div>
                            <div style="font-weight: 600; font-size: 0.9rem; color: var(--clr-text-main);">
                                <?= htmlspecialchars($solv['title'] ?: $solv['problem_code']) ?>
                            </div>
                            <div style="font-size: 0.78rem; color: var(--clr-text-muted); display: flex; gap: 8px; align-items: center; margin-top: 2px;">
                                <span style="font-weight: 600; color: <?= $platColor ?>;"><?= htmlspecialchars($solv['platform_name']) ?></span>
                                <span>•</span>
                                <span>by <?= htmlspecialchars($solv['student_name']) ?></span>
                                <?php if (!empty($solv['difficulty_level'])): ?>
                                    <span>•</span>
                                    <span><?= htmlspecialchars($solv['difficulty_level']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--clr-text-subtle); white-space: nowrap; font-family: var(--font-mono);">
                            <?= $solv['solved_at'] ? date('M d, H:i', strtotime($solv['solved_at'])) : 'Recent' ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function filterStudentsTable() {
    const searchVal = document.getElementById('studentSearch').value.toLowerCase().trim();
    const statusVal = document.getElementById('statusFilter').value;
    const rows = document.querySelectorAll('#studentsTable tbody tr.student-row');

    rows.forEach(row => {
        const name = row.getAttribute('data-name') || '';
        const email = row.getAttribute('data-email') || '';
        const handles = row.getAttribute('data-handles') || '';
        const status = row.getAttribute('data-status') || '';

        const matchesSearch = !searchVal || name.includes(searchVal) || email.includes(searchVal) || handles.includes(searchVal);
        const matchesStatus = statusVal === 'all' || status === statusVal;

        if (matchesSearch && matchesStatus) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

let sortAsc = false;
function sortTable(colIndex) {
    const table = document.getElementById('studentsTable');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr.student-row'));

    sortAsc = !sortAsc;

    rows.sort((a, b) => {
        let valA = a.children[colIndex].innerText.trim();
        let valB = b.children[colIndex].innerText.trim();

        let numA = parseFloat(valA.replace(/[^0-9.-]+/g, ""));
        let numB = parseFloat(valB.replace(/[^0-9.-]+/g, ""));

        if (!isNaN(numA) && !isNaN(numB)) {
            return sortAsc ? numA - numB : numB - numA;
        }
        return sortAsc ? valA.localeCompare(valB) : valB.localeCompare(valA);
    });

    rows.forEach(r => tbody.appendChild(r));
}
</script>

<?php include 'footer.php'; ?>
