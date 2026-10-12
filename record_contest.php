<?php
require 'db.php';
if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$user_id = $_SESSION['user_id'];
$contests = [];

if ($pdo !== null) {
    try {
        $stmt = $pdo->prepare("
            SELECT cp.*, c.name AS contest_name, c.start_time, p.platform_name, upp.handle
            FROM contest_performance cp
            JOIN contests c ON cp.contest_id = c.id
            JOIN user_platform_profiles upp ON cp.profile_id = upp.id
            JOIN platforms p ON upp.platform_id = p.id
            WHERE upp.user_id = ?
            ORDER BY c.start_time DESC
        ");
        $stmt->execute([$user_id]);
        $contests = $stmt->fetchAll();
    } catch (Exception $e) {
        // Log/handle error
    }
}

include 'header.php';
?>

<section class="surface">
    <div class="surface-header">
        <div>
            <h2>Contest Participation History</h2>
            <p>Every contest here was fetched automatically from your connected platform profiles. Only official ranked performances are displayed.</p>
        </div>
        <div style="display:flex; gap:12px; align-items:center;">
            <input type="text" data-table-search="contestsHistoryTable" placeholder="🔍 Filter contests..." style="max-width: 220px; padding: 6px 12px; margin: 0; font-size: 0.85rem;">
            <a href="dashboard.php" class="btn btn-secondary btn-sm">&larr; Dashboard</a>
        </div>
    </div>

    <div class="table-wrapper">
        <table id="contestsHistoryTable">
            <thead>
                <tr>
                    <th>Platform</th>
                    <th>Handle</th>
                    <th>Contest Name</th>
                    <th>Start Time</th>
                    <th>Rank Standing</th>
                    <th>Rating Change</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($contests)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 32px; color: var(--clr-text-muted);">
                            No contest performances found. Connect your profiles from <a href="manage_platforms.php" style="color: var(--clr-primary); font-weight: 600;">Manage Platforms</a> and run a sync.
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
                            <td><strong><?= htmlspecialchars($contest['handle']) ?></strong></td>
                            <td><?= htmlspecialchars($contest['contest_name']) ?></td>
                            <td style="font-size: 0.85rem; color: var(--clr-text-muted);"><?= htmlspecialchars($contest['start_time']) ?></td>
                            <td style="font-family: var(--font-mono); font-weight: 600;"><?= $contest['contest_rank'] !== null ? '#' . number_format((int)$contest['contest_rank']) : '—' ?></td>
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

<?php include 'footer.php'; ?>
