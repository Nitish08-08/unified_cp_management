<?php
require 'db.php';

$isAuthenticated = !empty($_SESSION['user_id']);

include 'header.php';
?>
<section class="surface" style="overflow:hidden; position:relative;">
    <div style="position:absolute; inset:0; background:linear-gradient(135deg, rgba(37,99,235,0.08), rgba(14,165,233,0.08)); z-index:0;"></div>
    <div style="position:relative; z-index:1; display:flex; flex-direction:column; gap:18px;">
        <span class="chip">Unified Competitive Programming</span>
        <h1 style="font-size:2.5rem; font-weight:700; margin:0; color:var(--clr-text);">Stay in sync with every platform you grind.</h1>
        <p style="max-width:620px; font-size:1.05rem; color:var(--clr-muted);">
            Unified CP pulls contests, ratings, solved problems, and topic analytics from Codeforces, LeetCode, and CodeChef. Compare progress, spot weak topics, and refresh data in seconds.
        </p>
        <div style="display:flex; flex-wrap:wrap; gap:12px; align-items:center;">
            <?php if ($isAuthenticated): ?>
                <a class="btn-refresh" href="dashboard.php">Go to Dashboard</a>
                <a class="btn-refresh" style="background:var(--clr-accent-muted); color:var(--clr-accent);" href="manage_platforms.php">Manage Platforms</a>
            <?php else: ?>
                <a class="btn-refresh" href="register.php">Create Account</a>
                <a class="btn-refresh" style="background:var(--clr-accent-muted); color:var(--clr-accent);" href="login.php">Sign In</a>
            <?php endif; ?>
            <span style="font-size:0.9rem; color:var(--clr-muted);">No manual spreadsheets. Your stats refresh automatically.</span>
        </div>
    </div>
</section>

<section class="surface" style="display:grid; gap:24px;">
    <h2 style="margin-bottom:0;">What You Get</h2>
    <div class="summary-grid" style="margin-bottom:0;">
        <div class="summary-card">
            <h4>Unified Dashboard</h4>
            <p style="margin:0;">Track contests, ratings, and performance score side-by-side for every connected platform.</p>
        </div>
        <div class="summary-card">
            <h4>Topic Intelligence</h4>
            <p style="margin:0;">Identify strong and weak topics instantly using recent solves and historical data.</p>
        </div>
        <div class="summary-card">
            <h4>Automatic Sync</h4>
            <p style="margin:0;">One click refresh keeps ratings, contest logs, and solved problems up to date.</p>
        </div>
    </div>
</section>

<section class="surface" style="display:grid; gap:18px;">
    <h2 style="margin-bottom:0;">Get Started in 3 Steps</h2>
    <div class="summary-grid" style="margin-bottom:0; grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
        <div class="summary-card" style="border-left:4px solid var(--clr-accent);">
            <h4>1. Create Profile</h4>
            <p style="margin:0;">
                Register or sign in. Every account gets instant access to the dashboard and dark mode UI.
            </p>
        </div>
        <div class="summary-card" style="border-left:4px solid #10b981;">
            <h4>2. Link Platforms</h4>
            <p style="margin:0;">
                Paste your Codeforces, LeetCode, and CodeChef URLs. We fetch handles, ratings, and history right away.
            </p>
        </div>
        <div class="summary-card" style="border-left:4px solid #f59e0b;">
            <h4>3. Analyze & Improve</h4>
            <p style="margin:0;">
                Review performance score, topic strengths, and contest deltas to plan your next practice sprint.
            </p>
        </div>
    </div>
</section>

<section class="surface" style="display:grid; gap:20px;">
    <h2 style="margin-bottom:0;">Need Inspiration?</h2>
    <div class="summary-grid" style="margin-bottom:0; grid-template-columns:repeat(auto-fit,minmax(240px,1fr));">
        <div class="summary-card">
            <h4>Recent Solves</h4>
            <p style="margin:0;">Browse your latest accepted submissions and jump straight to the problems from the Problems page.</p>
        </div>
        <div class="summary-card">
            <h4>Contest Planner</h4>
            <p style="margin:0;">Record practice or virtual contests to keep every performance in one place.</p>
        </div>
        <div class="summary-card">
            <h4>Performance Score</h4>
            <p style="margin:0;">Compare friends with a unified 0-100 score blending volume, rating, contests, and recency.</p>
        </div>
    </div>
</section>

<?php include 'footer.php'; ?>
