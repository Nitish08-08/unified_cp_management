<?php
require_once __DIR__ . '/../config/db.php';

$isAuthenticated = !empty($_SESSION['user_id']);

include __DIR__ . '/../includes/header.php';
?>

<!-- Hero Section -->
<section class="hero-section">
    <div class="hero-content">
        <span class="hero-pill">
            <span>✨</span> Next-Gen Competitive Programming Hub
        </span>
        <h1 class="hero-title">Stay in sync with every platform you grind.</h1>
        <p class="hero-desc">
            Unified CP aggregates your contests, rating trajectories, solved problems, and topic weaknesses across <strong>Codeforces</strong>, <strong>LeetCode</strong>, and <strong>CodeChef</strong> into one unified developer dashboard.
        </p>
        
        <div class="hero-actions">
            <?php if ($isAuthenticated): ?>
                <a class="btn btn-primary" href="dashboard.php">
                    <span>⚡ Launch Dashboard</span>
                </a>
                <a class="btn btn-secondary" href="manage_platforms.php">
                    <span>🔗 Manage Platforms</span>
                </a>
            <?php else: ?>
                <a class="btn btn-primary" href="register.php">
                    <span>🚀 Get Started Free</span>
                </a>
                <a class="btn btn-secondary" href="login.php">
                    <span>🔑 Sign In</span>
                </a>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Live Platform Highlights -->
<section style="margin-bottom: 36px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <h3>Supported Platforms</h3>
        <span style="font-size: 0.85rem; color: var(--clr-text-muted);">Real-time API & GraphQL synchronization</span>
    </div>
    
    <div class="summary-grid" style="margin: 0;">
        <div class="summary-card">
            <div class="card-top">
                <span class="badge-platform codeforces">Codeforces</span>
                <span class="card-icon">⚡</span>
            </div>
            <div class="metric" style="font-size: 1.5rem;">API Connected</div>
            <div class="meta">Syncs official rating, rank title, submission history, and topic tags.</div>
        </div>

        <div class="summary-card">
            <div class="card-top">
                <span class="badge-platform leetcode">LeetCode</span>
                <span class="card-icon">🧩</span>
            </div>
            <div class="metric" style="font-size: 1.5rem;">GraphQL Sync</div>
            <div class="meta">Pulls total solved counts (Easy / Medium / Hard) and global ranking.</div>
        </div>

        <div class="summary-card">
            <div class="card-top">
                <span class="badge-platform codechef">CodeChef</span>
                <span class="card-icon">👨‍🍳</span>
            </div>
            <div class="metric" style="font-size: 1.5rem;">Profile Scraper</div>
            <div class="meta">Captures stars, current division rating, and contest ranks.</div>
        </div>
    </div>
</section>

<!-- Feature Showcase -->
<section class="surface">
    <div class="surface-header">
        <div>
            <h2>Everything You Need To Elevate Your CP Rating</h2>
            <p>Designed for competitive coders who want actionable analytics instead of manual spreadsheets.</p>
        </div>
    </div>

    <div class="features-grid" style="margin: 12px 0 0;">
        <div class="feature-card">
            <div class="feature-icon">📊</div>
            <h3>Composite Performance Score</h3>
            <p>A smart normalized index (0–100) combining your contest frequency, solve volume, and peak ratings across all platforms.</p>
        </div>

        <div class="feature-card">
            <div class="feature-icon">🎯</div>
            <h3>Topic Weakness Radar</h3>
            <p>Identifies your algorithmic blind spots (e.g. DP, Segment Trees, Graph Theory) based on recent contest solve patterns.</p>
        </div>

        <div class="feature-card">
            <div class="feature-icon">📈</div>
            <h3>Rating & Delta Trajectory</h3>
            <p>Visualize rating swings and contest ranks chronologically to spot momentum shifts and practice trends.</p>
        </div>
    </div>
</section>

<!-- Steps Guide -->
<section class="surface">
    <h2>Get Started in 3 Simple Steps</h2>
    <div class="features-grid" style="margin-top: 16px;">
        <div class="feature-card" style="border-top: 3px solid var(--clr-primary);">
            <span class="chip" style="margin-bottom: 12px;">Step 1</span>
            <h3>Create Account</h3>
            <p>Sign up in seconds to access your customized dashboard with automatic dark mode support.</p>
        </div>

        <div class="feature-card" style="border-top: 3px solid var(--clr-success);">
            <span class="chip" style="margin-bottom: 12px;">Step 2</span>
            <h3>Paste Profile Handles</h3>
            <p>Enter your handles for Codeforces, LeetCode, or CodeChef. We pull your entire stats history right away.</p>
        </div>

        <div class="feature-card" style="border-top: 3px solid var(--clr-warning);">
            <span class="chip" style="margin-bottom: 12px;">Step 3</span>
            <h3>Analyze & Dominate</h3>
            <p>Review your composite score, track delta swings, and target your weakest algorithmic topics.</p>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
