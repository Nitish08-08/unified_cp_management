<?php
require 'db.php';
if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

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
     LIMIT 5'
);

$latestProblems = [];
foreach ($platforms as $platform) {
    $activityStmt->execute([$_SESSION['user_id'], $platform['id']]);
    $latestProblems[$platform['id']] = $activityStmt->fetchAll();
}

include 'header.php';
?>

<section class="surface">
    <h2>Recently Solved Problems</h2>
    <p>Auto-synced snapshots from each connected platform. Refresh a platform from the dashboard to pull updated activity, then use these lists for quick review or bookmarking.</p>

    <?php if (empty($platforms)): ?>
        <p>You haven't linked any competitive programming profiles yet. Connect a platform from <a href="manage_platforms.php">Manage Platforms</a> to start tracking solved problems.</p>
    <?php else: ?>
        <?php foreach ($platforms as $platform): ?>
            <?php $problems = $latestProblems[$platform['id']] ?? []; ?>
            <div class="surface" style="margin-top: 1.5rem;">
                <h3 style="margin-bottom: 0.75rem;"><?= htmlspecialchars($platform['platform_name']) ?></h3>
                <?php if (empty($problems)): ?>
                    <p>No recent solved problems yet. Trigger a sync to populate this list.</p>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Problem Code</th>
                                    <th>Title</th>
                                    <th>Difficulty</th>
                                    <th>Topic / Tags</th>
                                    <th>Solved At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($problems as $problem): ?>
                                    <?php $solvedAt = $problem['solved_at'] ? date('M j, Y H:i', strtotime($problem['solved_at'])) : '—'; ?>
                                    <tr>
                                        <td><?= htmlspecialchars($problem['problem_code']) ?></td>
                                        <td><?= htmlspecialchars($problem['title']) ?></td>
                                        <td><?= htmlspecialchars($problem['difficulty_level']) ?></td>
                                        <td><?= htmlspecialchars($problem['topic'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($solvedAt) ?></td>
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

<?php include 'footer.php'; ?>
