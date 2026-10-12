<?php
require 'db.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($name === '' || $email === '' || $password === '') {
        $message = 'All fields are required.';
    } elseif ($pdo === null) {
        $message = 'Database connection error: ' . ($dbConnectionError ?? 'Please check your database credentials in Vercel settings.');
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $message = 'An account with this email already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)");
                $stmt->execute([$name, $email, $hash]);

                $user_id = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO aggregated_stats (user_id, last_updated) VALUES (?, NOW())")
                    ->execute([$user_id]);

                header("Location: login.php?registered=1");
                exit;
            }
        } catch (Exception $e) {
            $message = 'Error creating account: ' . $e->getMessage();
        }
    }
}

include 'header.php';
?>

<div class="form-card">
    <div style="text-align: center; margin-bottom: 24px;">
        <span class="chip" style="margin-bottom: 8px;">Get Started</span>
        <h2 style="font-size: 1.75rem; margin-bottom: 6px;">Create Account</h2>
        <p>Start tracking Codeforces, LeetCode, and CodeChef in one place.</p>
    </div>

    <?php if ($message): ?>
        <div class="msg error">
            <span>⚠️</span>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
    <?php endif; ?>

    <form method="post" action="register.php">
        <div class="form-group">
            <label for="name">Full Name or Handle</label>
            <input type="text" id="name" name="name" required placeholder="e.g. Tourist or Jane Doe" autocomplete="name">
        </div>

        <div class="form-group">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" required placeholder="you@example.com" autocomplete="email">
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required minlength="6" placeholder="Choose a secure password" autocomplete="new-password">
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 8px;">
            <span>Create Free Account</span>
        </button>
    </form>

    <div style="margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--clr-border); text-align: center; font-size: 0.9rem; color: var(--clr-text-muted);">
        Already have an account? <a href="login.php" style="color: var(--clr-primary); font-weight: 600;">Sign in here</a>
    </div>
</div>

<?php include 'footer.php'; ?>
