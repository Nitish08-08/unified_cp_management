<?php
// header.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentScript = basename($_SERVER['PHP_SELF'] ?? 'index.php');
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Unified CP — Competitive Programming Tracker</title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Stylesheet -->
    <link rel="stylesheet" href="css/style.css">

    <!-- Anti-FOUC Theme Switcher -->
    <script>
        (function () {
            try {
                const storedTheme = window.localStorage.getItem('ucp-theme');
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                const theme = storedTheme || (prefersDark ? 'dark' : 'light');
                document.documentElement.setAttribute('data-theme', theme);
            } catch (err) {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>
</head>
<body>
<header class="navbar">
    <div class="nav-left">
        <a href="index.php" class="nav-brand">
            <span class="brand-badge">{CP}</span>
            <span class="brand-title">
                Unified CP
                <span class="brand-tag">PRO</span>
            </span>
        </a>
    </div>

    <nav class="nav-menu">
        <a href="index.php" class="nav-link <?= $currentScript === 'index.php' ? 'active' : '' ?>">Home</a>
        <?php if (!empty($_SESSION['user_id'])): ?>
            <?php if (($_SESSION['user_role'] ?? 'student') === 'mentor'): ?>
                <a href="mentor_dashboard.php" class="nav-link <?= in_array($currentScript, ['mentor_dashboard.php', 'mentor_student_detail.php']) ? 'active' : '' ?>">Mentor Dashboard</a>
            <?php else: ?>
                <a href="dashboard.php" class="nav-link <?= $currentScript === 'dashboard.php' ? 'active' : '' ?>">Dashboard</a>
                <a href="manage_platforms.php" class="nav-link <?= $currentScript === 'manage_platforms.php' ? 'active' : '' ?>">Platforms</a>
                <a href="add_problem.php" class="nav-link <?= $currentScript === 'add_problem.php' ? 'active' : '' ?>">Problems</a>
                <a href="record_contest.php" class="nav-link <?= $currentScript === 'record_contest.php' ? 'active' : '' ?>">Contests</a>
            <?php endif; ?>
        <?php else: ?>
            <a href="register.php" class="nav-link <?= $currentScript === 'register.php' ? 'active' : '' ?>">Register</a>
            <a href="login.php" class="nav-link <?= $currentScript === 'login.php' ? 'active' : '' ?>">Sign In</a>
            <a href="mentor_register.php" class="nav-link <?= $currentScript === 'mentor_register.php' ? 'active' : '' ?>" style="color: #a78bfa;">Mentor Portal</a>
        <?php endif; ?>
    </nav>

    <div class="nav-actions">
        <?php if (!empty($_SESSION['user_name'])): ?>
            <div class="user-pill">
                <?php if (($_SESSION['user_role'] ?? 'student') === 'mentor'): ?>
                    <span class="badge badge-purple" style="font-size: 0.7rem; padding: 2px 7px;">Mentor</span>
                <?php else: ?>
                    <span class="user-avatar-dot"></span>
                <?php endif; ?>
                <span><?= htmlspecialchars($_SESSION['user_name']) ?></span>
            </div>
            <a href="logout.php" class="nav-link" style="color: var(--clr-danger);" title="Log out">Logout</a>
        <?php endif; ?>

        <button type="button" class="theme-toggle" id="theme-toggle" aria-label="Toggle theme">
            <span class="icon-sun" style="display:none;">☀️</span>
            <span class="icon-moon">🌙</span>
            <span class="toggle-label">Dark</span>
        </button>
    </div>
</header>
<main class="page-container">
