<?php
require_once __DIR__ . '/../config/db.php';

if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$user_id = $_SESSION['user_id'];
$message = '';
$messageType = 'success';

if ($pdo !== null) {
    /* Auto Sync Codeforces */
    if (isset($_POST['fetch_cf'])) {
        $input = trim($_POST['profile_url'] ?? '');
        $handle = '';

        if (preg_match("/codeforces\.com\/profile\/([^\/\s]+)/i", $input, $match)) {
            $handle = $match[1];
        } elseif (preg_match('/^[a-zA-Z0-9_.-]+$/', $input)) {
            $handle = $input;
        }

        if (!$handle) {
            $message = "Please enter a valid Codeforces handle or profile URL.";
            $messageType = 'error';
        } else {
            $pdo->exec("INSERT IGNORE INTO platforms (platform_name, base_url) VALUES ('Codeforces', 'https://codeforces.com')");
            $platform_id = $pdo->query("SELECT id FROM platforms WHERE platform_name='Codeforces'")->fetch()['id'];

            $pdo->prepare("INSERT INTO user_platform_profiles (user_id, platform_id, handle, last_sync_date)
                           VALUES (?, ?, ?, NOW())
                           ON DUPLICATE KEY UPDATE handle=VALUES(handle), last_sync_date=NOW()")->execute([$user_id, $platform_id, $handle]);

            $stmt = $pdo->prepare("SELECT id FROM user_platform_profiles WHERE user_id=? AND platform_id=?");
            $stmt->execute([$user_id, $platform_id]);
            $profile_id = $stmt->fetch()['id'];

            include __DIR__ . '/../services/sync_cf.php';
            $message = "Codeforces account (@$handle) synchronized successfully!";
            $messageType = 'success';
        }
    }

    /* Auto Sync LeetCode */
    if (isset($_POST['fetch_lc'])) {
        $input = trim($_POST['lc_url'] ?? '');
        $handle = '';

        if (preg_match("/leetcode\.com\/(?:u\/)?([^\/\s]+)/i", $input, $m)) {
            $handle = $m[1];
        } elseif (preg_match('/^[a-zA-Z0-9_.-]+$/', $input)) {
            $handle = $input;
        }

        if (!$handle) {
            $message = "Please enter a valid LeetCode username or profile URL.";
            $messageType = 'error';
        } else {
            $pdo->exec("INSERT IGNORE INTO platforms (platform_name, base_url) VALUES ('LeetCode', 'https://leetcode.com')");
            $platform_id = $pdo->query("SELECT id FROM platforms WHERE platform_name='LeetCode'")->fetch()['id'];

            $pdo->prepare("INSERT INTO user_platform_profiles (user_id, platform_id, handle, last_sync_date)
                           VALUES (?, ?, ?, NOW())
                           ON DUPLICATE KEY UPDATE handle=VALUES(handle), last_sync_date=NOW()")->execute([$user_id, $platform_id, $handle]);

            $stmt = $pdo->prepare("SELECT id FROM user_platform_profiles WHERE user_id=? AND platform_id=?");
            $stmt->execute([$user_id, $platform_id]);
            $profile_id = $stmt->fetch()['id'];

            include __DIR__ . '/../services/sync_lc.php';
            $message = "LeetCode account (@$handle) synchronized successfully!";
            $messageType = 'success';
        }
    }

    /* Auto Sync CodeChef */
    if (isset($_POST['fetch_cc'])) {
        $input = trim($_POST['cc_url'] ?? '');
        $handle = '';

        if (preg_match("/codechef\.com\/users\/([^\/\s]+)/i", $input, $m)) {
            $handle = $m[1];
        } elseif (preg_match('/^[a-zA-Z0-9_.-]+$/', $input)) {
            $handle = $input;
        }

        if (!$handle) {
            $message = "Please enter a valid CodeChef username or profile URL.";
            $messageType = 'error';
        } else {
            $pdo->exec("INSERT IGNORE INTO platforms (platform_name, base_url) VALUES ('CodeChef', 'https://www.codechef.com')");
            $platform_id = $pdo->query("SELECT id FROM platforms WHERE platform_name='CodeChef'")->fetch()['id'];

            $pdo->prepare("INSERT INTO user_platform_profiles (user_id, platform_id, handle, last_sync_date)
                           VALUES (?, ?, ?, NOW())
                           ON DUPLICATE KEY UPDATE handle=VALUES(handle), last_sync_date=NOW()")->execute([$user_id, $platform_id, $handle]);

            $stmt = $pdo->prepare("SELECT id FROM user_platform_profiles WHERE user_id=? AND platform_id=?");
            $stmt->execute([$user_id, $platform_id]);
            $profile_id = $stmt->fetch()['id'];

            include __DIR__ . '/../services/sync_cc.php';
            $message = "CodeChef account (@$handle) synchronized successfully!";
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
} else {
    $profiles = [];
}

$profileIndex = [];
foreach ($profiles as $profileRow) {
    $profileIndex[$profileRow['platform_name']] = $profileRow;
}

$cfHandle = $profileIndex['Codeforces']['handle'] ?? '';
$lcHandle = $profileIndex['LeetCode']['handle'] ?? '';
$ccHandle = $profileIndex['CodeChef']['handle'] ?? '';

include __DIR__ . '/../includes/header.php';
?>

<section class="surface">
    <div class="surface-header">
        <div>
            <h2>Manage & Sync Your Platforms</h2>
            <p>Simply enter your handle or profile URL below. Unified CP fetches your rating history, official ranks, and solved problems automatically!</p>
        </div>
        <a href="dashboard.php" class="btn btn-secondary btn-sm">
            <span>&larr; Back to Dashboard</span>
        </a>
    </div>

    <?php if ($message): ?>
        <div class="msg <?= $messageType === 'error' ? 'error' : 'success' ?>">
            <span><?= $messageType === 'error' ? '⚠️' : '✅' ?></span>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
    <?php endif; ?>

    <!-- Platforms Grid -->
    <div class="features-grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); margin-top: 20px;">
        <!-- Codeforces Card -->
        <div class="feature-card" style="display:flex; flex-direction:column; justify-content:space-between;">
            <div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <span class="badge-platform codeforces">Codeforces</span>
                    <span style="font-size:0.8rem; color:var(--clr-text-muted); font-family:var(--font-mono);">
                        <?= $cfHandle ? '@' . htmlspecialchars($cfHandle) : 'Not Linked' ?>
                    </span>
                </div>
                <h3>Codeforces Handle</h3>
                <p>Fetches verified rating deltas, title (Newbie, Expert, etc.), and contest history automatically.</p>
            </div>
            
            <form method="post" action="manage_platforms.php" style="margin-top:16px;">
                <div class="form-group">
                    <label for="cf-profile">Handle or Profile URL</label>
                    <input id="cf-profile" type="text" name="profile_url" placeholder="e.g. nitish__0708" value="<?= htmlspecialchars($cfHandle) ?>" required>
                </div>
                <button type="submit" name="fetch_cf" class="btn btn-primary" style="width:100%;">
                    <span>Sync Codeforces</span>
                </button>
            </form>
        </div>

        <!-- LeetCode Card -->
        <div class="feature-card" style="display:flex; flex-direction:column; justify-content:space-between;">
            <div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <span class="badge-platform leetcode">LeetCode</span>
                    <span style="font-size:0.8rem; color:var(--clr-text-muted); font-family:var(--font-mono);">
                        <?= $lcHandle ? '@' . htmlspecialchars($lcHandle) : 'Not Linked' ?>
                    </span>
                </div>
                <h3>LeetCode Username</h3>
                <p>Syncs contest rankings, ratings, and total problems solved (Easy, Medium, Hard).</p>
            </div>
            
            <form method="post" action="manage_platforms.php" style="margin-top:16px;">
                <div class="form-group">
                    <label for="lc-profile">Username or Profile URL</label>
                    <input id="lc-profile" type="text" name="lc_url" placeholder="e.g. nitish__0708" value="<?= htmlspecialchars($lcHandle) ?>" required>
                </div>
                <button type="submit" name="fetch_lc" class="btn btn-primary" style="width:100%;">
                    <span>Sync LeetCode</span>
                </button>
            </form>
        </div>

        <!-- CodeChef Card -->
        <div class="feature-card" style="display:flex; flex-direction:column; justify-content:space-between;">
            <div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <span class="badge-platform codechef">CodeChef</span>
                    <span style="font-size:0.8rem; color:var(--clr-text-muted); font-family:var(--font-mono);">
                        <?= $ccHandle ? '@' . htmlspecialchars($ccHandle) : 'Not Linked' ?>
                    </span>
                </div>
                <h3>CodeChef Handle</h3>
                <p>Scrapes star tier (1★ to 7★), division rating, and contest ranks in real-time.</p>
            </div>
            
            <form method="post" action="manage_platforms.php" style="margin-top:16px;">
                <div class="form-group">
                    <label for="cc-profile">Handle or Profile URL</label>
                    <input id="cc-profile" type="text" name="cc_url" placeholder="e.g. nitish_0_8" value="<?= htmlspecialchars($ccHandle) ?>" required>
                </div>
                <button type="submit" name="fetch_cc" class="btn btn-primary" style="width:100%;">
                    <span>Sync CodeChef</span>
                </button>
            </form>
        </div>
    </div>
</section>

<!-- Active Platforms Table -->
<section class="surface">
    <div class="surface-header">
        <div>
            <h3>Your Connected Profiles</h3>
            <p>Live snapshot of your platform standings.</p>
        </div>
    </div>
    
    <div class="table-wrapper">
        <table>
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
                        <td colspan="7" style="text-align:center; padding: 24px; color: var(--clr-text-muted);">
                            No platforms linked yet. Type your username above and click Sync to pull your stats automatically!
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
                            <td><span class="badge-platform <?= $badgeClass ?>"><?= htmlspecialchars($profile['platform_name']) ?></span></td>
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

<?php include __DIR__ . '/../includes/footer.php'; ?>
