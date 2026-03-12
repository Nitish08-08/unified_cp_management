<?php
require 'db.php';

if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$user_id = $_SESSION['user_id'];
$message = '';
$messageType = 'success';

/* Auto Sync Codeforces */
if (isset($_POST['fetch_cf'])) {
    $profileUrl = trim($_POST['profile_url']);

    if (!preg_match("/codeforces\.com\/profile\/([^\/]+)/", $profileUrl, $match)) {
        $message = "Invalid Codeforces profile URL.";
        $messageType = 'error';
    } else {
        $handle = $match[1];

        $pdo->exec("INSERT IGNORE INTO platforms (platform_name, base_url)
                    VALUES ('Codeforces', 'https://codeforces.com')");
        $platform_id = $pdo->query("SELECT id FROM platforms WHERE platform_name='Codeforces'")
                           ->fetch()['id'];

        $pdo->prepare("INSERT IGNORE INTO user_platform_profiles
            (user_id, platform_id, handle, last_sync_date)
            VALUES (?, ?, ?, NOW())")->execute([$user_id, $platform_id, $handle]);

        $profile_id = $pdo->query("
            SELECT id FROM user_platform_profiles WHERE user_id=$user_id AND platform_id=$platform_id")
            ->fetch()['id'];

        include 'sync_cf.php';
        $message = "Codeforces synced successfully.";
        $messageType = 'success';
    }
}

/* Auto Sync LeetCode */
if (isset($_POST['fetch_lc'])) {
    $lcUrl = trim($_POST['lc_url']);

    if (!preg_match("/leetcode\.com\/u\/([^\/]+)/", $lcUrl, $m)) {
        $message = "Invalid LeetCode profile URL.";
        $messageType = 'error';
    } else {
        $handle = $m[1];

        $pdo->exec("INSERT IGNORE INTO platforms (platform_name, base_url)
                    VALUES ('LeetCode', 'https://leetcode.com')");
        $platform_id = $pdo->query("SELECT id FROM platforms WHERE platform_name='LeetCode'")
                           ->fetch()['id'];

        $pdo->prepare("INSERT IGNORE INTO user_platform_profiles
            (user_id, platform_id, handle, last_sync_date)
            VALUES (?, ?, ?, NOW())")->execute([$user_id, $platform_id, $handle]);

        $profile_id = $pdo->query("
            SELECT id FROM user_platform_profiles 
            WHERE user_id=$user_id AND platform_id=$platform_id")->fetch()['id'];

        include 'sync_lc.php';
        $message = "LeetCode synced successfully.";
        $messageType = 'success';
    }
}

/* Auto Sync CodeChef */
if (isset($_POST['fetch_cc'])) {
    $ccUrl = trim($_POST['cc_url']);

    if (!preg_match("/codechef\.com\/users\/([^\/]+)/", $ccUrl, $m)) {
        $message = "Invalid CodeChef profile URL.";
        $messageType = 'error';
    } else {
        $handle = $m[1];

        $pdo->exec("INSERT IGNORE INTO platforms (platform_name, base_url)
                    VALUES ('CodeChef', 'https://www.codechef.com')");
        $platform_id = $pdo->query("SELECT id FROM platforms WHERE platform_name='CodeChef'")
                           ->fetch()['id'];

        $pdo->prepare("INSERT IGNORE INTO user_platform_profiles
            (user_id, platform_id, handle, last_sync_date)
            VALUES (?, ?, ?, NOW())")->execute([$user_id, $platform_id, $handle]);

        $profile_id = $pdo->query("
            SELECT id FROM user_platform_profiles 
            WHERE user_id=$user_id AND platform_id=$platform_id")->fetch()['id'];

        include 'sync_cc.php';
        $message = "CodeChef synced successfully.";
        $messageType = 'success';
    }
}

$stmt = $pdo->prepare("
SELECT upp.*, p.platform_name
FROM user_platform_profiles upp
JOIN platforms p ON p.id=upp.platform_id
WHERE upp.user_id=?
");
$stmt->execute([$user_id]);
$profiles = $stmt->fetchAll();

$profileIndex = [];
foreach ($profiles as $profileRow) {
    $profileIndex[$profileRow['platform_name']] = $profileRow;
}

$cfHandle = $profileIndex['Codeforces']['handle'] ?? '';
$lcHandle = $profileIndex['LeetCode']['handle'] ?? '';
$ccHandle = $profileIndex['CodeChef']['handle'] ?? '';

include 'header.php';
?>

<section class="surface">
    <h2>Manage Platforms</h2>
    <p>Connect each profile once — we automatically capture handles, rating trajectory, contest history, and solved counts on every refresh.</p>

    <?php if ($message): ?>
        <div class="msg <?= $messageType === 'error' ? 'error' : 'success' ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="form-card">
        <h3>Codeforces</h3>
        <p>Paste your public Codeforces profile URL. We will pull ratings, submissions, and contest deltas using the official API.</p>
        <form method="post">
            <label for="cf-profile" class="chip">Profile URL</label>
            <input id="cf-profile" type="url" name="profile_url" placeholder="https://codeforces.com/profile/YOUR_HANDLE" value="<?= $cfHandle ? htmlspecialchars('https://codeforces.com/profile/' . $cfHandle) : '' ?>" required>
            <button type="submit" name="fetch_cf">Sync Codeforces</button>
        </form>
    </div>

    <div class="form-card">
        <h3>LeetCode</h3>
        <p>We combine GraphQL contest history with detailed solved counts to keep your analytics aligned with the official profile.</p>
        <form method="post">
            <label for="lc-profile" class="chip">Profile URL</label>
            <input id="lc-profile" type="url" name="lc_url" placeholder="https://leetcode.com/u/YOUR_HANDLE/" value="<?= $lcHandle ? htmlspecialchars('https://leetcode.com/u/' . $lcHandle . '/') : '' ?>" required>
            <button type="submit" name="fetch_lc">Sync LeetCode</button>
        </form>
    </div>

    <div class="form-card">
        <h3>CodeChef</h3>
        <p>Ratings, star level, ranked contests, and problems solved are scraped securely from your CodeChef profile.</p>
        <form method="post">
            <label for="cc-profile" class="chip">Profile URL</label>
            <input id="cc-profile" type="url" name="cc_url" placeholder="https://www.codechef.com/users/YOUR_HANDLE" value="<?= $ccHandle ? htmlspecialchars('https://www.codechef.com/users/' . $ccHandle) : '' ?>" required>
            <button type="submit" name="fetch_cc">Sync CodeChef</button>
        </form>
    </div>
</section>

<section class="surface">
    <h3>Your Connected Profiles</h3>
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

<?php include 'footer.php'; ?>
