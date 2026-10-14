<?php
require_once __DIR__ . '/../config/db.php';
if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$platforms = [];
$latestProblems = [];

if ($pdo !== null) {
    try {
        $platformStmt = $pdo->prepare(
            'SELECT p.id, p.platform_name
             FROM user_platform_profiles upp
             INNER JOIN platforms p ON upp.platform_id = p.id
             WHERE upp.user_id = ?
             ORDER BY p.platform_name'
        );
        $platformStmt->execute([$_SESSION['user_id']]);
        $platforms = $platformStmt->fetchAll();

        $activityStmt = $pdo->prepare(
            'SELECT problem_code, title, difficulty_level, topic, solved_at
             FROM user_solved_problems
             WHERE user_id = ? AND platform_id = ?
             ORDER BY solved_at DESC
             LIMIT 15'
        );

        foreach ($platforms as $platform) {
            $activityStmt->execute([$_SESSION['user_id'], $platform['id']]);
            $latestProblems[$platform['id']] = $activityStmt->fetchAll();
        }
    } catch (Exception $e) {
        // Log/handle error
    }
}

include __DIR__ . '/../includes/header.php';
?>

<section class="surface">
    <div class="surface-header">
        <div>
            <h2>Recently Solved Problems</h2>
            <p>Auto-synced snapshot of solved algorithmic challenges from your connected platforms. Refresh from the dashboard to pull the latest submissions.</p>
        </div>
        <a href="dashboard.php" class="btn btn-secondary btn-sm">
            <span>&larr; Back to Dashboard</span>
        </a>
    </div>

    <?php if (empty($platforms)): ?>
        <div style="text-align: center; padding: 36px 20px;">
            <p style="font-size: 1.05rem; margin-bottom: 16px;">You haven't linked any competitive programming profiles yet.</p>
            <a href="manage_platforms.php" class="btn btn-primary">
                <span>Connect a Platform Now</span>
            </a>
        </div>
    <?php else: ?>
        <?php foreach ($platforms as $platform): ?>
            <?php 
                $problems = $latestProblems[$platform['id']] ?? [];
                $pLower = strtolower($platform['platform_name']);
                $badgeClass = 'codeforces';
                if (strpos($pLower, 'leetcode') !== false) $badgeClass = 'leetcode';
                elseif (strpos($pLower, 'codechef') !== false) $badgeClass = 'codechef';
                $tableId = 'problemsTable_' . $platform['id'];
            ?>
            <div class="surface" style="margin-top: 1.5rem; background: var(--clr-surface);">
                <div class="surface-header">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span class="badge-platform <?= $badgeClass ?>"><?= htmlspecialchars($platform['platform_name']) ?></span>
                        <h3 style="margin: 0; font-size: 1.15rem;">Recent Submissions</h3>
                    </div>
                    <input type="text" data-table-search="<?= $tableId ?>" placeholder="🔍 Filter by code or topic..." style="max-width: 220px; padding: 6px 12px; margin: 0; font-size: 0.85rem;">
                </div>

                <?php if (empty($problems)): ?>
                    <p style="padding: 16px 0 8px; margin: 0; color: var(--clr-text-muted);">No recent solved problems recorded yet. Trigger a sync from the dashboard to populate this list.</p>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table id="<?= $tableId ?>">
                            <thead>
                                <tr>
                                    <th>Problem Code</th>
                                    <th>Title</th>
                                    <th>Difficulty</th>
                                    <th>Topic / Algorithmic Tags</th>
                                    <th>Solved Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($problems as $problem): ?>
                                    <?php 
                                        $solvedAt = $problem['solved_at'] ? date('M j, Y H:i', strtotime($problem['solved_at'])) : '—';
                                        $diff = strtolower($problem['difficulty_level'] ?? '');
                                        $diffClass = 'difficulty-medium';
                                        if (in_array($diff, ['easy', '800', '900', '1000', '1100'])) $diffClass = 'difficulty-easy';
                                        elseif (in_array($diff, ['hard', 'very hard', '1700', '1800', '1900', '2000', '2100', '2200'])) $diffClass = 'difficulty-hard';
                                    ?>
                                    <tr>
                                        <td style="font-family: var(--font-mono); font-weight: 600; color: var(--clr-primary);">
                                            <?= htmlspecialchars($problem['problem_code']) ?>
                                        </td>
                                        <td><strong><?= htmlspecialchars($problem['title']) ?></strong></td>
                                        <td>
                                            <span class="<?= $diffClass ?>"><?= htmlspecialchars($problem['difficulty_level'] ?? 'Normal') ?></span>
                                        </td>
                                        <td>
                                            <?php if (!empty($problem['topic'])): ?>
                                                <?php foreach (explode(',', $problem['topic']) as $tag): ?>
                                                    <span class="chip" style="font-size: 0.7rem; margin-right: 4px;"><?= htmlspecialchars(trim($tag)) ?></span>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span style="color: var(--clr-text-muted); font-size: 0.85rem;">Untagged</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size: 0.85rem; color: var(--clr-text-muted);"><?= htmlspecialchars($solvedAt) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
