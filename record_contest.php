<?php
require 'db.php';
if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$user_id = $_SESSION['user_id'];

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

include 'header.php';
?>

<section class="surface">
    <h2>Contest History</h2>
    <p>Every contest here was fetched automatically from your connected profiles. We only display records where you received an official rank.</p>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Platform</th>
                    <th>Handle</th>
                    <th>Contest</th>
                    <th>Start Time</th>
                    <th>Rank</th>
                    <th>Rating Change</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($contests as $contest): ?>
                    <tr>
                        <td><?= htmlspecialchars($contest['platform_name']) ?></td>
                        <td><?= htmlspecialchars($contest['handle']) ?></td>
                        <td><?= htmlspecialchars($contest['contest_name']) ?></td>
                        <td><?= htmlspecialchars($contest['start_time']) ?></td>
                        <td><?= $contest['contest_rank'] !== null ? number_format((int)$contest['contest_rank']) : '—' ?></td>
                        <td><?= $contest['rating_change'] !== null ? (int)$contest['rating_change'] : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include 'footer.php'; ?>
