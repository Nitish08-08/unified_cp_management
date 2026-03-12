<?php
require 'db.php';

$message = '';

if (isset($_GET['registered'])) {
    $message = 'Registration successful. Please login.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT id, name, password_hash FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        header("Location: dashboard.php");
        exit;
    } else {
        $message = 'Invalid email or password.';
    }
}

include 'header.php';
?>

<h2>Login</h2>

<?php if ($message): ?>
<div class="msg <?= isset($_GET['registered']) ? 'success' : 'error' ?>">
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<form method="post">
    <label>Email</label>
    <input type="email" name="email">

    <label>Password</label>
    <input type="password" name="password">

    <button type="submit">Login</button>
</form>

<?php include 'footer.php'; ?>
