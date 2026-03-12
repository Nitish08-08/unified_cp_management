<?php
require 'db.php';

if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

/* ----------------- Fetch User ----------------- */
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

/* ----------------- Aggregated Stats ----------------- */
$stmt = $pdo->prepare("SELECT * FROM aggregated_stats WHERE user_id = ?");
$stmt->execute([$user_id]);
$stats = $stmt->fetch();

if (!$stats) {
    $stats = [
        'total_problems_solved' => 0,
        'total_contests'        => 0,
        'highest_platform_id'   => null,
        'composite_score'       => null,
        'last_updated'          => null,
    ];
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
$totalTaggedTopics = 0;

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

$strongTopics = [];
$weakTopics = [];
if (!empty($topicCounts)) {
    $sortedDesc = $topicCounts;
    arsort($sortedDesc);
    $strongTopics = array_slice($sortedDesc, 0, 5, true);

    $sortedAsc = $topicCounts;
    asort($sortedAsc);
    $weakTopics = array_slice($sortedAsc, 0, 5, true);
}

/* ------- Prepare data for charts ------- */

$chartLabels        = [];
$chartRatingChanges = [];
$chartRanks         = [];
$chartPlatforms     = [];
$chartColors        = [];

$platformPalette = [
    'Codeforces' => '#1f78d1',
    'LeetCode'   => '#ffa116',
    'CodeChef'   => '#5b2c6f',
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
    $chartColors[]        = $platformPalette[$row['platform_name']] ?? '#2563eb';
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
<section class="surface">
    <h2>Dashboard</h2>
    <p>Welcome back, <?= htmlspecialchars($user['name']) ?>. All of your competitive programming signals are synced in real-time.</p>

    <div class="summary-grid">
        <div class="summary-card">
            <h4>Performance Score</h4>
            <?php $scoreValue = isset($stats['composite_score']) ? (float)$stats['composite_score'] : null; ?>
            <div class="metric">
                <?= $scoreValue !== null ? number_format($scoreValue, 1) : '—' ?>
            </div>
            <div class="meta">Relative score (0-100) combining volume, rating and activity.</div>
        </div>
        <div class="summary-card">
            <h4>Total Problems Solved</h4>
            <div class="metric"><?= number_format((int)$stats['total_problems_solved']) ?></div>
            <div class="meta">Sum of solved problems across connected platforms.</div>
        </div>
        <div class="summary-card">
            <h4>Total Contests</h4>
            <div class="metric"><?= number_format((int)$stats['total_contests']) ?></div>
            <div class="meta">Only contests you actually participated in (rank recorded).</div>
        </div>
        <div class="summary-card">
            <h4>Last Synced</h4>
            <div class="metric" style="font-size:1.35rem;"><?= htmlspecialchars($stats['last_updated'] ?? 'N/A') ?></div>
            <div class="meta">Refresh any platform below to pull the latest data.</div>
        </div>
    </div>

    <div class="refresh-buttons">
        <a class="btn-refresh" href="refresh_cf.php">🔄 Refresh Codeforces</a>
        <a class="btn-refresh" href="refresh_lc.php">🔄 Refresh LeetCode</a>
        <a class="btn-refresh" href="refresh_cc.php">🔄 Refresh CodeChef</a>
    </div>
</section>

<section class="surface">
    <h3>Topic Strength Profile</h3>
    <?php if ($totalTaggedTopics === 0): ?>
        <p>We need more tagged problem solves to analyze your strengths. Sync Codeforces problems to populate topic metadata.</p>
    <?php else: ?>
        <p>Based on <?= number_format($totalTaggedTopics) ?> tagged problem solves across your connected platforms.</p>
        <div class="topic-grid">
            <div class="topic-panel">
                <h4>Strong Topics</h4>
                <p>Areas where you have solved the most tagged problems.</p>
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
                <h4>Weak Topics</h4>
                <p>Categories with the fewest tagged solves—good candidates for targeted practice.</p>
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

<section class="surface">
    <h3>Your Platform Profiles</h3>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Platform</th>
                    <th>Handle</th>
                    <th>Current Rating</th>
                    <th>Max Rating</th>
                    <th>Problems Solved</th>
                    <th>Rank / Title</th>
                    <th>Last Sync</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($profiles as $profile): ?>
                    <tr>
                        <td><?= htmlspecialchars($profile['platform_name']) ?></td>
                        <td><?= htmlspecialchars($profile['handle']) ?></td>
                        <td><?= htmlspecialchars($profile['current_rating'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($profile['max_rating'] ?? '—') ?></td>
                        <td><?= number_format((int)($profile['problems_solved'] ?? 0)) ?></td>
                        <td><?= htmlspecialchars($profile['rank_title'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($profile['last_sync_date'] ?? 'Never') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="surface">
    <h3>Rating Change History</h3>
    <p>Every bar reflects the rating delta from the corresponding contest. Hover for platform insights.</p>
    <canvas id="ratingChart" height="90"></canvas>
</section>

<section class="surface">
    <h3>Contest Rank History</h3>
    <p>Lower bars are better. Each entry captures only contests where a valid rank was available.</p>
    <canvas id="rankChart" height="90"></canvas>
</section>

<section class="surface">
    <h3>Contest Performance Log</h3>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Contest</th>
                    <th>Platform</th>
                    <th>Handle</th>
                    <th>Date / Time</th>
                    <th>Rank</th>
                    <th>Rating Change</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($contests as $contest): ?>
                    <tr>
                        <td><?= htmlspecialchars($contest['contest_name']) ?></td>
                        <td><?= htmlspecialchars($contest['platform_name']) ?></td>
                        <td><?= htmlspecialchars($contest['handle']) ?></td>
                        <td><?= htmlspecialchars($contest['contest_time']) ?></td>
                        <td><?= $contest['contest_rank'] !== null ? number_format((int)$contest['contest_rank']) : '—' ?></td>
                        <td><?= $contest['rating_change'] !== null ? (int)$contest['rating_change'] : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const chartData = <?= json_encode($chartData) ?>;

if (chartData.labels.length > 0) {
    const ratingCtx = document.getElementById('ratingChart').getContext('2d');
    new Chart(ratingCtx, {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [{
                label: 'Rating Change',
                data: chartData.ratingChanges,
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37, 99, 235, 0.25)',
                borderWidth: 3,
                pointRadius: 4,
                tension: 0.35
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `Δ ${ctx.formattedValue}`,
                        afterLabel: (ctx) => {
                            const idx = ctx.dataIndex;
                            return 'Platform: ' + chartData.platforms[idx];
                        }
                    }
                }
            },
            scales: {
                y: {
                    title: { display: true, text: 'Rating Change' },
                    grid: { color: 'rgba(148, 163, 184, 0.25)' }
                },
                x: {
                    title: { display: true, text: 'Contest' },
                    ticks: { maxRotation: 35, minRotation: 35 },
                    grid: { display: false }
                }
            }
        }
    });

    const rankCtx = document.getElementById('rankChart').getContext('2d');
    new Chart(rankCtx, {
        type: 'bar',
        data: {
            labels: chartData.labels,
            datasets: [{
                label: 'Contest Rank (Lower is better)',
                data: chartData.ranks,
                backgroundColor: chartData.colors,
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    title: { display: true, text: 'Rank' },
                    reverse: true,
                    grid: { color: 'rgba(148, 163, 184, 0.2)' }
                },
                x: {
                    title: { display: true, text: 'Contest' },
                    ticks: { maxRotation: 35, minRotation: 35 },
                    grid: { display: false }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `Rank: ${ctx.formattedValue}`,
                        afterLabel: (ctx) => {
                            const idx = ctx.dataIndex;
                            return 'Platform: ' + chartData.platforms[idx];
                        }
                    }
                }
            }
        }
    });
}
</script>

<?php include 'footer.php'; ?>
