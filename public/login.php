<?php
require_once __DIR__ . '/../config/db.php';

$message = '';

if (isset($_GET['registered'])) {
    if ($_GET['registered'] === 'mentor') {
        $message = 'Mentor registration successful! Please sign in to access your Mentor Dashboard.';
    } else {
        $message = 'Registration successful! You can now sign in.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($pdo === null) {
        $message = 'Database connection error: ' . ($dbConnectionError ?? 'Please verify your database credentials.');
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, name, password_hash, role FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_role'] = $user['role'] ?? 'student';

                if ($_SESSION['user_role'] === 'mentor') {
                    header("Location: mentor_dashboard.php");
                } else {
                    header("Location: dashboard.php");
                }
                exit;
            } else {
                $message = 'Invalid email or password.';
            }
        } catch (Exception $e) {
            $message = 'Error during sign in: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="form-card">
    <div style="text-align: center; margin-bottom: 24px;">
        <span class="chip" style="margin-bottom: 8px;">Authentication</span>
        <h2 style="font-size: 1.75rem; margin-bottom: 6px;">Welcome Back</h2>
        <p>Sign in to sync your ratings and competitive profiles.</p>
    </div>

    <?php if ($message): ?>
        <div class="msg <?= isset($_GET['registered']) ? 'success' : 'error' ?>">
            <span><?= isset($_GET['registered']) ? '✅' : '⚠️' ?></span>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
    <?php endif; ?>

    <form method="post" action="login.php">
        <div class="form-group">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" required placeholder="you@example.com" autocomplete="email">
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required placeholder="••••••••" autocomplete="current-password">
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 8px;">
            <span>Sign In to Dashboard</span>
        </button>
    </form>

    <div style="margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--clr-border); text-align: center; font-size: 0.9rem; color: var(--clr-text-muted);">
        Don't have an account? <a href="register.php" style="color: var(--clr-primary); font-weight: 600;">Create Student Account</a>
        <div style="margin-top: 8px;">
            Are you a Mentor? <a href="mentor_register.php" style="color: #a78bfa; font-weight: 600;">Mentor Access Portal &rarr;</a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
