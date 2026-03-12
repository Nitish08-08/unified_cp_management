<?php
// header.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Unified Competitive Programming Management System</title>
    <style>
        :root {
            --clr-bg: #f5f7fb;
            --clr-surface: #ffffff;
            --clr-border: #e2e8f0;
            --clr-text: #1a202c;
            --clr-muted: #4a5568;
            --clr-accent: #2563eb;
            --clr-accent-muted: #dbeafe;
            --clr-success: #16a34a;
            --clr-danger: #dc2626;
            --shadow-sm: 0 6px 18px rgba(15, 23, 42, 0.08);
            --radius-sm: 10px;
            --radius-md: 18px;
            --transition: 0.3s ease;
        }

        html[data-theme='dark'] {
            --clr-bg: #0f172a;
            --clr-surface: #111a2f;
            --clr-border: #1f2937;
            --clr-text: #e2e8f0;
            --clr-muted: #94a3b8;
            --clr-accent: #60a5fa;
            --clr-accent-muted: rgba(96, 165, 250, 0.18);
            --clr-success: #34d399;
            --clr-danger: #f87171;
            --shadow-sm: 0 8px 30px rgba(15, 23, 42, 0.65);
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            background: var(--clr-bg);
            color: var(--clr-text);
            transition: background var(--transition), color var(--transition);
        }

        a {
            color: inherit;
            text-decoration: none;
            transition: opacity var(--transition);
        }

        a:hover { opacity: 0.75; }

        header.navbar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(17, 24, 39, 0.85);
            backdrop-filter: blur(12px);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 24px;
        }

        header.navbar .nav-left { display: flex; align-items: center; gap: 16px; }

        header.navbar .logo {
            font-size: 1.05rem;
            font-weight: 600;
            letter-spacing: 0.04em;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .nav-links a {
            font-size: 0.95rem;
            color: inherit;
        }

        .theme-toggle {
            border: 1px solid rgba(255, 255, 255, 0.35);
            background: transparent;
            color: inherit;
            border-radius: 20px;
            padding: 6px 12px;
            cursor: pointer;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background var(--transition), border var(--transition);
        }

        .theme-toggle:hover {
            background: rgba(255, 255, 255, 0.15);
            border-color: rgba(255, 255, 255, 0.6);
        }

        .page-container {
            max-width: 1180px;
            margin: 28px auto 48px;
            padding: 0 22px;
        }

        .surface {
            background: var(--clr-surface);
            border: 1px solid var(--clr-border);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            padding: 24px;
            margin-bottom: 28px;
        }

        h1, h2, h3, h4 {
            margin: 0 0 12px;
            font-weight: 600;
        }

        h2 { font-size: 1.4rem; }
        h3 { font-size: 1.2rem; color: var(--clr-muted); font-weight: 500; }

        p { margin: 0 0 12px; color: var(--clr-muted); }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }

        .summary-card {
            background: var(--clr-surface);
            border: 1px solid var(--clr-border);
            border-radius: var(--radius-md);
            padding: 18px;
            box-shadow: var(--shadow-sm);
            transition: transform var(--transition), box-shadow var(--transition);
        }

        .summary-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 32px rgba(37, 99, 235, 0.18);
        }

        .summary-card h4 {
            margin-bottom: 4px;
            font-size: 0.9rem;
            text-transform: uppercase;
            color: var(--clr-muted);
            letter-spacing: 0.06em;
        }

        .summary-card .metric {
            font-size: 2rem;
            font-weight: 600;
        }

        .summary-card .meta {
            font-size: 0.85rem;
            color: var(--clr-muted);
        }

        .table-wrapper {
            overflow-x: auto;
            border-radius: var(--radius-sm);
            border: 1px solid var(--clr-border);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 640px;
        }

        th, td {
            padding: 12px 14px;
            text-align: left;
            border-bottom: 1px solid var(--clr-border);
            font-size: 0.95rem;
        }

        th {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            background: var(--clr-accent-muted);
            color: var(--clr-text);
        }

        tr:hover td { background: rgba(37, 99, 235, 0.06); }

        input, select, textarea {
            width: 100%;
            padding: 10px 12px;
            margin: 6px 0 14px;
            border-radius: 12px;
            border: 1px solid var(--clr-border);
            background: var(--clr-surface);
            color: var(--clr-text);
            transition: border var(--transition), box-shadow var(--transition);
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--clr-accent);
            box-shadow: 0 0 0 3px var(--clr-accent-muted);
        }

        button, .btn-refresh {
            border: none;
            border-radius: 12px;
            padding: 10px 18px;
            font-weight: 600;
            cursor: pointer;
            background: var(--clr-accent);
            color: #fff;
            transition: transform var(--transition), box-shadow var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        button:hover, .btn-refresh:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(37, 99, 235, 0.35);
        }

        .btn-refresh { text-decoration: none; }

        .refresh-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin: 16px 0 24px;
        }

        .topic-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 18px;
        }

        .topic-panel {
            border: 1px solid var(--clr-border);
            border-radius: var(--radius-md);
            padding: 18px;
            background: var(--clr-bg);
        }

        .topic-panel h4 {
            margin-bottom: 8px;
            font-size: 1rem;
        }

        .topic-panel p {
            font-size: 0.85rem;
            margin-bottom: 12px;
        }

        .topic-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .topic-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--clr-border);
            background: var(--clr-surface);
            font-size: 0.9rem;
        }

        .topic-list li span:last-child {
            color: var(--clr-muted);
            font-size: 0.85rem;
        }

        .chip {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 999px;
            background: var(--clr-accent-muted);
            color: var(--clr-accent);
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .msg {
            padding: 10px 14px;
            border-radius: 12px;
            margin-bottom: 18px;
            font-size: 0.92rem;
        }

        .msg.error {
            background: rgba(248, 113, 113, 0.12);
            border: 1px solid rgba(248, 113, 113, 0.4);
            color: var(--clr-danger);
        }

        .msg.success {
            background: rgba(34, 197, 94, 0.12);
            border: 1px solid rgba(34, 197, 94, 0.35);
            color: var(--clr-success);
        }

        .form-card {
            background: var(--clr-surface);
            border: 1px solid var(--clr-border);
            border-radius: var(--radius-md);
            padding: 22px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
        }

        .form-card h3 { margin-bottom: 8px; }
        .form-card p { margin-bottom: 18px; }

        canvas { background: var(--clr-surface); border-radius: var(--radius-md); padding: 16px; }

        @media (max-width: 768px) {
            header.navbar {
                flex-wrap: wrap;
                gap: 12px;
            }

            .nav-links { width: 100%; justify-content: space-between; }
            .nav-links a { font-size: 0.85rem; }
            .page-container { padding: 0 16px; }
            table { min-width: 100%; }
        }

        @media (max-width: 520px) {
            .nav-links { flex-wrap: wrap; gap: 12px; }
            .theme-toggle { width: 100%; justify-content: center; }
            .summary-grid { grid-template-columns: 1fr; }
        }
    </style>
    <script>
        (function () {
            try {
                const storedTheme = window.localStorage.getItem('ucp-theme');
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                const theme = storedTheme || (prefersDark ? 'dark' : 'light');
                document.documentElement.setAttribute('data-theme', theme);
            } catch (err) {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>
</head>
<body>
<header class="navbar">
    <div class="nav-left">
        <div class="logo">Unified CP</div>
    </div>
    <div class="nav-links">
        <a href="index.php">Home</a>
        <?php if (!empty($_SESSION['user_id'])): ?>
            <a href="dashboard.php">Dashboard</a>
            <a href="manage_platforms.php">Platforms</a>
            <a href="add_problem.php">Problems</a>
            <a href="record_contest.php">Contests</a>
            <a href="logout.php">Logout</a>
        <?php else: ?>
            <a href="register.php">Register</a>
            <a href="login.php">Login</a>
        <?php endif; ?>
        <button type="button" class="theme-toggle" id="theme-toggle" aria-label="Toggle color mode">
            <span class="icon-sun">☀️</span>
            <span class="icon-moon">🌙</span>
            <span class="toggle-label">Theme</span>
        </button>
    </div>
</header>
<main class="page-container">
