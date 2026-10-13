<?php
require 'db.php';

$message = '';
$isError = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $passkey  = trim($_POST['passkey'] ?? '');

    $expectedPasskey = getenv('MENTOR_SECRET_KEY') ?: (getenv('MENTOR_ACCESS_KEY') ?: 'MENTOR2026');

    if ($name === '' || $email === '' || $password === '' || $passkey === '') {
        $message = 'All fields, including the Mentor Authorization Passkey, are required.';
    } elseif ($passkey !== $expectedPasskey) {
        $message = 'Invalid Mentor Authorization Passkey. Contact your institution or administrator to obtain valid mentor credentials.';
    } elseif ($pdo === null) {
        $message = 'Database connection error: ' . ($dbConnectionError ?? 'Please check database credentials.');
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $message = 'An account with this email address already exists. Please sign in or use a different email.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'mentor')");
                $stmt->execute([$name, $email, $hash]);

                $mentor_id = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO aggregated_stats (user_id, last_updated) VALUES (?, NOW())")
                    ->execute([$mentor_id]);

                header("Location: login.php?registered=mentor");
                exit;
            }
        } catch (Exception $e) {
            $message = 'Error creating mentor account: ' . $e->getMessage();
        }
    }
}

include 'header.php';
?>

<div class="form-card" style="max-width: 480px; margin: 40px auto; border-top: 3px solid #8b5cf6;">
    <div style="text-align: center; margin-bottom: 24px;">
        <span class="chip" style="background: rgba(139, 92, 246, 0.15); color: #a78bfa; border: 1px solid rgba(139, 92, 246, 0.3); margin-bottom: 8px;">
            🎓 Mentor Access Portal
        </span>
        <h2 style="font-size: 1.75rem; margin-bottom: 6px;">Register as Mentor</h2>
        <p style="color: var(--clr-text-muted); font-size: 0.92rem;">
            Monitor junior progress across CodeChef, Codeforces, and LeetCode from an administrative dashboard.
        </p>
    </div>

    <?php if ($message): ?>
        <div class="msg <?= $isError ? 'error' : 'success' ?>" style="margin-bottom: 20px;">
            <span><?= $isError ? '⚠️' : '✅' ?></span>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
    <?php endif; ?>

    <form method="post" action="mentor_register.php">
        <div class="form-group">
            <label for="name">Full Name or Title</label>
            <input type="text" id="name" name="name" required placeholder="e.g. Prof. Alan Turing or Mentor Alex" autocomplete="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label for="email">Institutional or Work Email</label>
            <input type="email" id="email" name="email" required placeholder="mentor@institution.edu" autocomplete="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label for="password">Account Password</label>
            <input type="password" id="password" name="password" required minlength="6" placeholder="Choose a secure password" autocomplete="new-password">
        </div>

        <div class="form-group">
            <label for="passkey" style="display: flex; justify-content: space-between; align-items: center;">
                <span>Mentor Authorization Passkey</span>
                <span style="font-size: 0.75rem; color: #a78bfa; font-weight: normal;">Required for Mentor Role</span>
            </label>
            <input type="password" id="passkey" name="passkey" required placeholder="Enter mentor authorization code" autocomplete="off">
            <p style="font-size: 0.78rem; color: var(--clr-text-subtle); margin-top: 6px;">
                Note: Standard code is <code>MENTOR2026</code> unless customized in environment settings.
            </p>
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px; background: linear-gradient(135deg, #7c3aed 0%, #4f46e5 100%);">
            <span>Create Authorized Mentor Account</span>
        </button>
    </form>

    <div style="margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--clr-border); text-align: center; font-size: 0.9rem; color: var(--clr-text-muted);">
        Already have a mentor or student account? <a href="login.php" style="color: var(--clr-primary); font-weight: 600;">Sign in here</a>
        <div style="margin-top: 8px;">
            Are you a student? <a href="register.php" style="color: var(--clr-primary); font-weight: 600;">Standard Student Registration &rarr;</a>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
