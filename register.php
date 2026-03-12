<?php
require 'db.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($name === '' || $email === '' || $password === '') {
        $message = 'All fields are required.';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $message = 'Email already registered.';
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
    }
}

include 'header.php';
?>

<h2>Register</h2>

<?php if ($message): ?>
<div class="msg error"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<form method="post">
    <label>Name</label>
    <input type="text" name="name">

    <label>Email</label>
    <input type="email" name="email">

    <label>Password</label>
    <input type="password" name="password">

    <button type="submit">Register</button>
</form>

<?php include 'footer.php'; ?>
