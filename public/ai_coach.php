<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../services/ai_coach_service.php';

require_auth();

$userId = (int)$_SESSION['user_id'];
$userName = $_SESSION['user_name'] ?? 'Student';
$authToken = function_exists('generate_user_auth_token') ? generate_user_auth_token($userId) : '';

// Retrieve live context summary for verified badge
$studentContext = get_student_ai_context($pdo, $userId);
$stats = $studentContext['stats'];
$platforms = $studentContext['platforms'];

include __DIR__ . '/../includes/header.php';
?>

<div class="ai-coach-container">
    <!-- Top Welcome & Status Bar -->
    <div class="ai-coach-header">
        <div style="display: flex; align-items: center; gap: 12px;">
            <div class="ai-avatar-badge">🤖</div>
            <div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <h1 style="font-size: 1.5rem; margin: 0;">AI Competitive Programming Coach</h1>
                    <span class="badge badge-emerald" style="font-size: 0.72rem; padding: 2px 8px;">
                        ● Live Context Active
                    </span>
                </div>
                <p style="margin: 2px 0 0 0; font-size: 0.85rem; color: var(--clr-text-muted);">
                    Personalized mentoring, weak-topic diagnostics, and adaptive practice plans powered by your real-time stats.
                </p>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 10px;">
            <button type="button" class="btn btn-secondary btn-sm" onclick="toggleContextModal()">
                <span>📊 View Context Data</span>
            </button>
            <button type="button" class="btn btn-primary btn-sm mobile-only-btn" onclick="toggleSidebarDrawer()">
                <span>☰ History & Plans</span>
            </button>
        </div>
    </div>

    <!-- Main Workspace Grid (Sidebar + Chat Area) -->
    <div class="ai-coach-grid">
        
        <!-- Left Sidebar: Conversations & Active Practice Plan -->
        <aside class="ai-sidebar" id="aiSidebar">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                <button type="button" class="btn btn-primary" style="width: 100%; font-size: 0.88rem; padding: 8px 14px;" onclick="startNewConversation()">
                    <span>+ New Conversation</span>
                </button>
            </div>

            <!-- Conversations History List -->
            <div class="sidebar-section">
                <div class="sidebar-section-title">
                    <span>Recent Conversations</span>
                    <span id="convCountBadge" class="badge badge-gray" style="font-size: 0.7rem;">0</span>
                </div>
                <div class="conversations-list" id="conversationsList">
                    <div style="padding: 16px; text-align: center; color: var(--clr-text-muted); font-size: 0.82rem;">
                        Loading conversations...
                    </div>
                </div>
            </div>

            <!-- Active Practice Plan Checklist -->
            <div class="sidebar-section" id="activePlanWidget" style="display: none;">
                <div class="sidebar-section-title">
                    <span>🎯 Active Practice Plan</span>
                    <span id="planProgressBadge" class="badge badge-emerald" style="font-size: 0.7rem;">0%</span>
                </div>
                <div class="plan-card">
                    <div id="planTitle" style="font-weight: 700; font-size: 0.88rem; margin-bottom: 4px; color: var(--clr-text-main);">
                        CP Mastery Plan
                    </div>
                    <div id="planObjective" style="font-size: 0.76rem; color: var(--clr-text-muted); margin-bottom: 8px;">
                        Adaptive schedule
                    </div>
                    <div class="plan-progress-bar">
                        <div id="planProgressBarFill" style="width: 0%;"></div>
                    </div>
                    <div class="plan-tasks-list" id="planTasksList">
                        <!-- Tasks dynamically populated -->
                    </div>
                </div>
            </div>

            <!-- Student Snapshot Widget -->
            <div class="sidebar-section" style="margin-top: auto; padding-top: 14px; border-top: 1px solid var(--clr-border);">
                <div class="sidebar-section-title">
                    <span>Verified Snapshot</span>
                    <span style="font-size: 0.7rem; color: var(--clr-text-subtle);">User #<?= $userId ?></span>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 0.78rem;">
                    <div class="mini-stat-box">
                        <span class="label">Total Solves</span>
                        <span class="val"><?= number_format($stats['total_problems_solved']) ?></span>
                    </div>
                    <div class="mini-stat-box">
                        <span class="label">Consistency</span>
                        <span class="val" style="color: #34d399;"><?= number_format($stats['consistency_score'], 1) ?>%</span>
                    </div>
                    <div class="mini-stat-box">
                        <span class="label">Codeforces</span>
                        <span class="val" style="color: #60a5fa;"><?= $platforms['Codeforces'] ? $platforms['Codeforces']['current_rating'] : '—' ?></span>
                    </div>
                    <div class="mini-stat-box">
                        <span class="label">LeetCode</span>
                        <span class="val" style="color: #fbbf24;"><?= $platforms['LeetCode'] ? $platforms['LeetCode']['problems_solved'] : '—' ?></span>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Right Main Chat Panel -->
        <main class="ai-chat-main">
            <!-- Messages Scroll Area -->
            <div class="chat-messages" id="chatMessages">
                <!-- Welcome Screen (shown when conversation is new) -->
                <div class="welcome-screen" id="welcomeScreen">
                    <div class="welcome-icon">🎓</div>
                    <h2>Welcome, <?= htmlspecialchars($userName) ?>!</h2>
                    <p style="color: var(--clr-text-muted); max-width: 580px; margin: 0 auto 20px; font-size: 0.92rem;">
                        I am your dedicated Competitive Programming Coach. I have inspected your verified profile data across 
                        <strong>Codeforces</strong> (<?= $platforms['Codeforces'] ? 'Rating ' . $platforms['Codeforces']['current_rating'] : 'Unlinked' ?>), 
                        <strong>LeetCode</strong> (<?= $platforms['LeetCode'] ? $platforms['LeetCode']['problems_solved'] . ' solves' : 'Unlinked' ?>), and 
                        <strong>CodeChef</strong> (<?= $platforms['CodeChef'] ? 'Rating ' . $platforms['CodeChef']['current_rating'] : 'Unlinked' ?>).
                    </p>

                    <!-- Suggested Starter Questions -->
                    <div style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--clr-text-subtle); margin-bottom: 10px;">
                        Quick Starter Prompts
                    </div>
                    <div class="starter-prompts-grid">
                        <button type="button" class="prompt-chip" onclick="sendSuggestedQuestion('Analyze my competitive programming profile and current momentum.')">
                            <span class="icon">📊</span>
                            <span>Analyze my competitive programming profile</span>
                        </button>

                        <button type="button" class="prompt-chip" onclick="sendSuggestedQuestion('Identify my weakest topics and skill gaps based on my solves.')">
                            <span class="icon">🎯</span>
                            <span>Identify my weakest topics & skill gaps</span>
                        </button>

                        <button type="button" class="prompt-chip" onclick="sendSuggestedQuestion('Create a personalized 7-day practice plan for my current rating.')">
                            <span class="icon">📅</span>
                            <span>Create a personalized 7-day practice plan</span>
                        </button>

                        <button type="button" class="prompt-chip" onclick="sendSuggestedQuestion('How can I improve my Codeforces rating and break into the next tier?')">
                            <span class="icon">⚡</span>
                            <span>Help me improve my Codeforces rating</span>
                        </button>

                        <button type="button" class="prompt-chip" onclick="sendSuggestedQuestion('Recommend 3 practice problems based on my current level.')">
                            <span class="icon">💡</span>
                            <span>Recommend problems for my current level</span>
                        </button>

                        <button type="button" class="prompt-chip" onclick="sendSuggestedQuestion('Analyze my recent contest performance and rating deltas.')">
                            <span class="icon">🏆</span>
                            <span>Analyze my recent contest performance</span>
                        </button>

                        <button type="button" class="prompt-chip" onclick="sendSuggestedQuestion('Explain binary search on answer with an intuitive example step-by-step.')">
                            <span class="icon">🧩</span>
                            <span>Explain binary search on answer step by step</span>
                        </button>
                    </div>
                </div>

                <!-- Messages stream container -->
                <div id="messagesList"></div>

                <!-- Typing indicator -->
                <div class="typing-indicator" id="typingIndicator" style="display: none;">
                    <div class="ai-avatar-tiny">🤖</div>
                    <div class="typing-bubble">
                        <span class="dot"></span>
                        <span class="dot"></span>
                        <span class="dot"></span>
                    </div>
                </div>
            </div>

            <!-- Chat Input Bar -->
            <div class="chat-input-container">
                <form id="chatForm" onsubmit="handleChatSubmit(event)">
                    <div class="input-wrapper">
                        <textarea 
                            id="userInput" 
                            rows="1" 
                            placeholder="Ask your coach anything (e.g. 'How do I optimize Dijkstra?', 'Create a weekly plan', 'Analyze my weak topics')... (Enter to send, Shift+Enter for new line)"
                            onkeydown="handleKeyDown(event)"
                            oninput="autoResizeTextarea(this)"
                        ></textarea>
                        
                        <button type="submit" id="sendBtn" class="btn btn-primary send-btn" aria-label="Send message">
                            <span id="sendBtnText">➤</span>
                        </button>
                    </div>
                </form>
                <div class="chat-disclaimer">
                    🔒 AI Coach analyzes your verified CP statistics to formulate personalized advice. Hint-first tutoring enabled.
                </div>
            </div>
        </main>

    </div>
</div>

<!-- ==========================================================================
     Modal: Verified Context Summary
     ========================================================================== -->
<div class="modal-overlay" id="contextModal" style="display: none;" onclick="if(event.target===this) toggleContextModal()">
    <div class="modal-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="margin: 0; font-size: 1.25rem;">Verified Student CP Context</h3>
            <button type="button" class="btn-close" onclick="toggleContextModal()">✕</button>
        </div>
        <p style="font-size: 0.85rem; color: var(--clr-text-muted); margin-bottom: 16px;">
            This live snapshot is dynamically provided to the AI Coach to ensure all diagnostics, ratings, and plans reference your actual performance.
        </p>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
            <div class="context-detail-card">
                <span class="label">Total Solves</span>
                <span class="val"><?= number_format($stats['total_problems_solved']) ?> problems</span>
            </div>
            <div class="context-detail-card">
                <span class="label">Consistency Score</span>
                <span class="val"><?= number_format($stats['consistency_score'], 1) ?>%</span>
            </div>
            <div class="context-detail-card">
                <span class="label">Problem Solving Skills</span>
                <span class="val"><?= number_format($stats['problem_solving_score'], 1) ?>%</span>
            </div>
            <div class="context-detail-card">
                <span class="label">Composite Index</span>
                <span class="val"><?= number_format($stats['composite_score'], 1) ?>%</span>
            </div>
        </div>

        <div style="font-size: 0.85rem; font-weight: 700; margin-bottom: 8px;">Connected Platforms:</div>
        <div style="display: flex; flex-direction: column; gap: 8px; font-size: 0.85rem; margin-bottom: 16px;">
            <div style="padding: 8px 12px; background: var(--clr-bg-subtle); border-radius: var(--radius-sm); display: flex; justify-content: space-between;">
                <span>Codeforces: <strong><?= $platforms['Codeforces'] ? '@' . htmlspecialchars($platforms['Codeforces']['handle']) : 'Not linked' ?></strong></span>
                <span style="color: #60a5fa;">Rating: <?= $platforms['Codeforces'] ? $platforms['Codeforces']['current_rating'] : '—' ?></span>
            </div>
            <div style="padding: 8px 12px; background: var(--clr-bg-subtle); border-radius: var(--radius-sm); display: flex; justify-content: space-between;">
                <span>LeetCode: <strong><?= $platforms['LeetCode'] ? '@' . htmlspecialchars($platforms['LeetCode']['handle']) : 'Not linked' ?></strong></span>
                <span style="color: #fbbf24;">Solves: <?= $platforms['LeetCode'] ? $platforms['LeetCode']['problems_solved'] : '—' ?></span>
            </div>
            <div style="padding: 8px 12px; background: var(--clr-bg-subtle); border-radius: var(--radius-sm); display: flex; justify-content: space-between;">
                <span>CodeChef: <strong><?= $platforms['CodeChef'] ? '@' . htmlspecialchars($platforms['CodeChef']['handle']) : 'Not linked' ?></strong></span>
                <span style="color: #a78bfa;">Rating: <?= $platforms['CodeChef'] ? $platforms['CodeChef']['current_rating'] : '—' ?></span>
            </div>
        </div>

        <button type="button" class="btn btn-secondary btn-sm" style="width: 100%;" onclick="toggleContextModal()">
            Close
        </button>
    </div>
</div>

<!-- ==========================================================================
     Styles for AI Coach
     ========================================================================== -->
<style>
.ai-coach-container {
    max-width: 1400px;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
    height: calc(100vh - 120px);
    min-height: 600px;
}

.ai-coach-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 18px;
    background: var(--clr-surface-card);
    border: 1px solid var(--clr-border);
    border-radius: var(--radius-md);
    margin-bottom: 14px;
    box-shadow: var(--shadow-xs);
}

.ai-avatar-badge {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.ai-coach-grid {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 16px;
    flex: 1;
    min-height: 0;
}

/* Left Sidebar */
.ai-sidebar {
    background: var(--clr-surface-card);
    border: 1px solid var(--clr-border);
    border-radius: var(--radius-md);
    padding: 16px;
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    box-shadow: var(--shadow-xs);
}

.sidebar-section {
    margin-bottom: 18px;
}

.sidebar-section-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.76rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--clr-text-muted);
    margin-bottom: 8px;
}

.conversations-list {
    display: flex;
    flex-direction: column;
    gap: 4px;
    max-height: 220px;
    overflow-y: auto;
}

.conv-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 10px;
    border-radius: var(--radius-sm);
    font-size: 0.85rem;
    color: var(--clr-text-main);
    cursor: pointer;
    transition: background var(--transition-fast);
}

.conv-item:hover {
    background: var(--clr-surface-hover);
}

.conv-item.active {
    background: var(--clr-primary-muted);
    color: var(--clr-primary);
    font-weight: 600;
}

.conv-title {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    flex: 1;
}

.conv-del-btn {
    opacity: 0;
    background: none;
    border: none;
    color: var(--clr-danger);
    cursor: pointer;
    padding: 2px 4px;
    font-size: 0.8rem;
    transition: opacity var(--transition-fast);
}

.conv-item:hover .conv-del-btn {
    opacity: 1;
}

/* Plan Widget */
.plan-card {
    background: var(--clr-bg-subtle);
    border: 1px solid var(--clr-border-subtle);
    border-radius: var(--radius-sm);
    padding: 12px;
}

.plan-progress-bar {
    height: 5px;
    background: var(--clr-border);
    border-radius: 3px;
    overflow: hidden;
    margin-bottom: 10px;
}

.plan-progress-bar div {
    height: 100%;
    background: linear-gradient(90deg, #3b82f6, #10b981);
    transition: width 0.3s ease;
}

.plan-tasks-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
    max-height: 140px;
    overflow-y: auto;
}

.plan-task-item {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    font-size: 0.78rem;
    line-height: 1.3;
}

.plan-task-item.completed {
    text-decoration: line-through;
    color: var(--clr-text-muted);
}

.mini-stat-box {
    background: var(--clr-bg-subtle);
    padding: 6px 8px;
    border-radius: 6px;
    display: flex;
    flex-direction: column;
}

.mini-stat-box .label {
    font-size: 0.68rem;
    color: var(--clr-text-muted);
}

.mini-stat-box .val {
    font-weight: 700;
    font-family: var(--font-mono);
}

/* Right Chat Area */
.ai-chat-main {
    background: var(--clr-surface-card);
    border: 1px solid var(--clr-border);
    border-radius: var(--radius-md);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    box-shadow: var(--shadow-xs);
}

.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 24px 20px;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

/* Welcome Screen */
.welcome-screen {
    text-align: center;
    margin: auto 0;
    padding: 30px 16px;
}

.welcome-icon {
    font-size: 3rem;
    margin-bottom: 12px;
}

.starter-prompts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 10px;
    max-width: 800px;
    margin: 0 auto;
}

.prompt-chip {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    background: var(--clr-bg-subtle);
    border: 1px solid var(--clr-border);
    border-radius: var(--radius-sm);
    color: var(--clr-text-main);
    font-size: 0.85rem;
    text-align: left;
    cursor: pointer;
    transition: all var(--transition-fast);
}

.prompt-chip:hover {
    background: var(--clr-surface-hover);
    border-color: var(--clr-primary);
    transform: translateY(-1px);
    box-shadow: var(--shadow-xs);
}

.prompt-chip .icon {
    font-size: 1.1rem;
}

/* Message Bubbles */
.message-row {
    display: flex;
    gap: 12px;
    max-width: 85%;
}

.message-row.user {
    align-self: flex-end;
    flex-direction: row-reverse;
}

.message-row.assistant {
    align-self: flex-start;
}

.msg-avatar {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.95rem;
    flex-shrink: 0;
}

.message-row.user .msg-avatar {
    background: linear-gradient(135deg, #6366f1, #3b82f6);
    color: white;
    font-weight: 700;
}

.message-row.assistant .msg-avatar {
    background: linear-gradient(135deg, #3b82f6, #8b5cf6);
    color: white;
}

.msg-bubble {
    padding: 14px 18px;
    border-radius: var(--radius-md);
    font-size: 0.92rem;
    line-height: 1.6;
    word-break: break-word;
}

.message-row.user .msg-bubble {
    background: var(--clr-primary);
    color: white;
    border-bottom-right-radius: 4px;
}

.message-row.assistant .msg-bubble {
    background: var(--clr-bg-subtle);
    border: 1px solid var(--clr-border);
    color: var(--clr-text-main);
    border-bottom-left-radius: 4px;
}

.msg-footer {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 8px;
    margin-top: 8px;
    font-size: 0.72rem;
    color: var(--clr-text-subtle);
}

.feedback-btn {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 0.85rem;
    padding: 2px 4px;
    border-radius: 4px;
    transition: background var(--transition-fast);
}

.feedback-btn:hover {
    background: var(--clr-border);
}

.feedback-btn.active {
    color: var(--clr-primary);
}

/* Markdown Formatted Content Inside Assistant */
.msg-bubble h1, .msg-bubble h2, .msg-bubble h3, .msg-bubble h4 {
    margin: 12px 0 6px 0;
    line-height: 1.3;
}
.msg-bubble h3 { font-size: 1.15rem; }
.msg-bubble h4 { font-size: 1rem; }
.msg-bubble p { margin-bottom: 8px; }
.msg-bubble p:last-child { margin-bottom: 0; }
.msg-bubble ul, .msg-bubble ol {
    margin: 6px 0 10px 22px;
}
.msg-bubble li { margin-bottom: 4px; }

.msg-bubble table {
    width: 100%;
    border-collapse: collapse;
    margin: 10px 0;
    font-size: 0.85rem;
}
.msg-bubble th, .msg-bubble td {
    padding: 6px 10px;
    border: 1px solid var(--clr-border);
}
.msg-bubble th {
    background: var(--clr-surface-card);
    font-weight: 700;
}

/* Code Snippet Blocks */
.code-block-wrapper {
    margin: 10px 0;
    border-radius: var(--radius-sm);
    overflow: hidden;
    border: 1px solid var(--clr-border);
    background: #0d1117;
}

.code-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 12px;
    background: #161b22;
    color: #8b949e;
    font-size: 0.75rem;
    font-family: var(--font-mono);
}

.btn-copy-code {
    background: transparent;
    border: 1px solid #30363d;
    color: #c9d1d9;
    border-radius: 4px;
    padding: 2px 8px;
    font-size: 0.72rem;
    cursor: pointer;
    transition: all var(--transition-fast);
}

.btn-copy-code:hover {
    background: #21262d;
    border-color: #8b949e;
}

.code-block-wrapper pre {
    margin: 0;
    padding: 12px 14px;
    overflow-x: auto;
    font-family: var(--font-mono);
    font-size: 0.84rem;
    color: #e6edf3;
    line-height: 1.45;
}

/* Input Area */
.chat-input-container {
    padding: 14px 20px 10px;
    border-top: 1px solid var(--clr-border);
    background: var(--clr-surface-card);
}

.input-wrapper {
    display: flex;
    gap: 10px;
    align-items: flex-end;
    background: var(--clr-bg-subtle);
    border: 1px solid var(--clr-border);
    border-radius: var(--radius-md);
    padding: 6px 12px;
    transition: border-color var(--transition-fast);
}

.input-wrapper:focus-within {
    border-color: var(--clr-primary);
    box-shadow: 0 0 0 2px var(--clr-primary-muted);
}

.input-wrapper textarea {
    flex: 1;
    border: none;
    background: transparent;
    color: var(--clr-text-main);
    font-family: var(--font-main);
    font-size: 0.92rem;
    line-height: 1.45;
    padding: 6px 0;
    resize: none;
    outline: none;
    max-height: 160px;
}

.send-btn {
    border-radius: 50%;
    width: 38px;
    height: 38px;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    margin-bottom: 2px;
}

.chat-disclaimer {
    font-size: 0.74rem;
    color: var(--clr-text-subtle);
    text-align: center;
    margin-top: 6px;
}

/* Typing Indicator Animation */
.typing-indicator {
    display: flex;
    align-items: center;
    gap: 10px;
}

.typing-bubble {
    background: var(--clr-bg-subtle);
    border: 1px solid var(--clr-border);
    border-radius: var(--radius-md);
    padding: 10px 16px;
    display: flex;
    gap: 4px;
    align-items: center;
}

.typing-bubble .dot {
    width: 6px;
    height: 6px;
    background: var(--clr-text-muted);
    border-radius: 50%;
    animation: typingPulse 1.4s infinite ease-in-out both;
}
.typing-bubble .dot:nth-child(1) { animation-delay: -0.32s; }
.typing-bubble .dot:nth-child(2) { animation-delay: -0.16s; }

@keyframes typingPulse {
    0%, 80%, 100% { transform: scale(0); opacity: 0.4; }
    40% { transform: scale(1); opacity: 1; }
}

/* Modal */
.modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.modal-card {
    background: var(--clr-surface-card);
    border: 1px solid var(--clr-border);
    border-radius: var(--radius-md);
    padding: 24px;
    max-width: 520px;
    width: 90%;
    box-shadow: var(--shadow-lg);
}

.btn-close {
    background: none;
    border: none;
    font-size: 1.2rem;
    cursor: pointer;
    color: var(--clr-text-muted);
}

.context-detail-card {
    padding: 10px;
    background: var(--clr-bg-subtle);
    border-radius: var(--radius-sm);
    display: flex;
    flex-direction: column;
}
.context-detail-card .label {
    font-size: 0.72rem;
    color: var(--clr-text-muted);
}
.context-detail-card .val {
    font-size: 1.15rem;
    font-weight: 700;
    font-family: var(--font-mono);
}

.mobile-only-btn {
    display: none;
}

@media (max-width: 900px) {
    .ai-coach-grid {
        grid-template-columns: 1fr;
    }
    .ai-sidebar {
        display: none;
        position: fixed;
        top: 70px;
        left: 10px;
        right: 10px;
        bottom: 20px;
        z-index: 90;
        box-shadow: var(--shadow-lg);
    }
    .ai-sidebar.open {
        display: flex;
    }
    .mobile-only-btn {
        display: inline-flex;
    }
}
</style>

<!-- ==========================================================================
     Frontend Client Controller
     ========================================================================== -->
<script>
const USER_AUTH_TOKEN = <?= json_encode($authToken) ?>;
let currentConversationId = null;
let isGenerating = false;

async function apiFetch(url, options = {}) {
    options.headers = options.headers || {};
    if (USER_AUTH_TOKEN) {
        options.headers['X-Auth-Token'] = USER_AUTH_TOKEN;
    }
    options.credentials = 'same-origin';

    let finalUrl = url;
    if (USER_AUTH_TOKEN) {
        const separator = url.includes('?') ? '&' : '?';
        finalUrl = `${url}${separator}auth_token=${encodeURIComponent(USER_AUTH_TOKEN)}`;
    }

    if (options.body && typeof options.body === 'string' && options.headers['Content-Type'] === 'application/json') {
        try {
            const parsed = JSON.parse(options.body);
            if (USER_AUTH_TOKEN && !parsed.auth_token) {
                parsed.auth_token = USER_AUTH_TOKEN;
            }
            options.body = JSON.stringify(parsed);
        } catch(e) {}
    }

    return fetch(finalUrl, options);
}

document.addEventListener('DOMContentLoaded', () => {
    loadConversations();
    loadActivePlan();
});

function toggleContextModal() {
    const modal = document.getElementById('contextModal');
    modal.style.display = (modal.style.display === 'none' || !modal.style.display) ? 'flex' : 'none';
}

function toggleSidebarDrawer() {
    const sb = document.getElementById('aiSidebar');
    sb.classList.toggle('open');
}

function autoResizeTextarea(textarea) {
    textarea.style.height = 'auto';
    textarea.style.height = Math.min(textarea.scrollHeight, 160) + 'px';
}

function handleKeyDown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        handleChatSubmit(e);
    }
}

async function loadConversations() {
    try {
        const res = await apiFetch('ai_coach_api.php?action=get_conversations');
        const data = await res.json();
        if (data.success) {
            renderConversationsList(data.conversations);
        }
    } catch (err) {
        console.error('Failed to load conversations:', err);
    }
}

function renderConversationsList(convs) {
    const list = document.getElementById('conversationsList');
    const badge = document.getElementById('convCountBadge');
    badge.innerText = convs.length;

    if (convs.length === 0) {
        list.innerHTML = '<div style="padding: 12px; text-align: center; color: var(--clr-text-muted); font-size: 0.8rem;">No conversations yet.</div>';
        return;
    }

    list.innerHTML = '';
    convs.forEach(c => {
        const div = document.createElement('div');
        div.className = `conv-item ${c.id === currentConversationId ? 'active' : ''}`;
        div.id = `conv-item-${c.id}`;
        div.onclick = (e) => {
            if (!e.target.classList.contains('conv-del-btn')) {
                loadConversationMessages(c.id);
            }
        };

        div.innerHTML = `
            <div class="conv-title" title="${escapeHtml(c.title)}">💬 ${escapeHtml(c.title)}</div>
            <button type="button" class="conv-del-btn" title="Delete conversation" onclick="deleteConversation(${c.id}, event)">✕</button>
        `;
        list.appendChild(div);
    });
}

async function loadActivePlan() {
    try {
        const res = await apiFetch('ai_coach_api.php?action=get_active_plan');
        const data = await res.json();
        if (data.success && data.plan) {
            renderActivePlan(data.plan, data.tasks || []);
        } else {
            document.getElementById('activePlanWidget').style.display = 'none';
        }
    } catch (err) {
        console.error('Failed to load active plan:', err);
    }
}

function renderActivePlan(plan, tasks) {
    const widget = document.getElementById('activePlanWidget');
    widget.style.display = 'block';

    document.getElementById('planTitle').innerText = plan.title || 'CP Practice Plan';
    document.getElementById('planObjective').innerText = plan.objective || '7-day structured targets';

    const tasksList = document.getElementById('planTasksList');
    tasksList.innerHTML = '';

    let completed = 0;
    tasks.forEach(t => {
        if (t.is_completed) completed++;
        const item = document.createElement('div');
        item.className = `plan-task-item ${t.is_completed ? 'completed' : ''}`;
        item.innerHTML = `
            <input type="checkbox" ${t.is_completed ? 'checked' : ''} onchange="togglePlanTask(${t.id}, this)">
            <span><strong>D${t.day_number}:</strong> ${escapeHtml(t.task_description)}</span>
        `;
        tasksList.appendChild(item);
    });

    const pct = tasks.length > 0 ? Math.round((completed / tasks.length) * 100) : 0;
    document.getElementById('planProgressBadge').innerText = `${pct}%`;
    document.getElementById('planProgressBarFill').style.width = `${pct}%`;
}

async function togglePlanTask(taskId, checkbox) {
    try {
        const res = await apiFetch('ai_coach_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'toggle_task', task_id: taskId })
        });
        const data = await res.json();
        if (data.success) {
            checkbox.closest('.plan-task-item').classList.toggle('completed', data.is_completed);
            loadActivePlan();
        }
    } catch (err) {
        console.error('Error toggling task:', err);
    }
}

function startNewConversation() {
    currentConversationId = null;
    document.querySelectorAll('.conv-item').forEach(el => el.classList.remove('active'));
    document.getElementById('welcomeScreen').style.display = 'block';
    document.getElementById('messagesList').innerHTML = '';
    const sb = document.getElementById('aiSidebar');
    sb.classList.remove('open');
    document.getElementById('userInput').focus();
}

async function loadConversationMessages(convId) {
    currentConversationId = convId;
    document.querySelectorAll('.conv-item').forEach(el => {
        el.classList.toggle('active', el.id === `conv-item-${convId}`);
    });

    const sb = document.getElementById('aiSidebar');
    sb.classList.remove('open');

    document.getElementById('welcomeScreen').style.display = 'none';
    const list = document.getElementById('messagesList');
    list.innerHTML = '<div style="padding: 24px; text-align: center; color: var(--clr-text-muted);">Loading conversation...</div>';

    try {
        const res = await apiFetch(`ai_coach_api.php?action=get_messages&conversation_id=${convId}`);
        const data = await res.json();
        list.innerHTML = '';

        if (data.success && data.messages) {
            if (data.messages.length === 0) {
                document.getElementById('welcomeScreen').style.display = 'block';
            } else {
                data.messages.forEach(m => appendMessageToUI(m.role, m.content, m.id, m.user_feedback));
            }
        }
        scrollChatToBottom();
    } catch (err) {
        list.innerHTML = `<div style="padding: 24px; text-align: center; color: var(--clr-danger);">Error loading messages: ${err.message}</div>`;
    }
}

async function deleteConversation(convId, event) {
    event.stopPropagation();
    if (!confirm('Are you sure you want to delete this conversation?')) return;

    try {
        const res = await apiFetch('ai_coach_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete_conversation', conversation_id: convId })
        });
        const data = await res.json();
        if (data.success) {
            if (currentConversationId === convId) {
                startNewConversation();
            }
            loadConversations();
        }
    } catch (err) {
        alert('Failed to delete conversation.');
    }
}

function sendSuggestedQuestion(questionText) {
    document.getElementById('userInput').value = questionText;
    handleChatSubmit(new Event('submit'));
}

async function handleChatSubmit(e) {
    if (e) e.preventDefault();
    if (isGenerating) return;

    const input = document.getElementById('userInput');
    const message = input.value.trim();
    if (!message) return;

    // Reset input
    input.value = '';
    input.style.height = 'auto';

    // Hide welcome screen
    document.getElementById('welcomeScreen').style.display = 'none';

    // Append user message immediately
    appendMessageToUI('user', message);
    scrollChatToBottom();

    // Show typing indicator
    isGenerating = true;
    document.getElementById('typingIndicator').style.display = 'flex';
    document.getElementById('sendBtn').disabled = true;
    scrollChatToBottom();

    try {
        const res = await apiFetch('ai_coach_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'send_message',
                conversation_id: currentConversationId,
                message: message
            })
        });

        const data = await res.json();

        // Hide typing indicator
        document.getElementById('typingIndicator').style.display = 'none';

        if (data.success) {
            currentConversationId = data.conversation_id;
            appendMessageToUI('assistant', data.assistant_message.content, data.assistant_message.id);
            scrollChatToBottom();
            loadConversations();
            if (data.saved_plan_id) {
                loadActivePlan();
            }
        } else {
            appendMessageToUI('assistant', `⚠️ **Error**: ${data.error || 'Failed to generate response. Please try again.'}`);
        }
    } catch (err) {
        document.getElementById('typingIndicator').style.display = 'none';
        appendMessageToUI('assistant', `⚠️ **Network / Server Error**: ${err.message}. Please check your connection and retry.`);
    } finally {
        isGenerating = false;
        document.getElementById('sendBtn').disabled = false;
        scrollChatToBottom();
    }
}

function appendMessageToUI(role, content, msgId = null, feedback = null) {
    const list = document.getElementById('messagesList');
    const row = document.createElement('div');
    row.className = `message-row ${role}`;

    const avatar = document.createElement('div');
    avatar.className = 'msg-avatar';
    avatar.innerText = (role === 'user') ? '<?= strtoupper(substr($userName, 0, 1)) ?>' : '🤖';

    const bubble = document.createElement('div');
    bubble.className = 'msg-bubble';

    if (role === 'user') {
        bubble.innerText = content;
    } else {
        bubble.innerHTML = renderMarkdown(content);

        if (msgId) {
            const footer = document.createElement('div');
            footer.className = 'msg-footer';
            footer.innerHTML = `
                <span>AI Coach</span>
                <span>•</span>
                <button type="button" class="feedback-btn ${feedback === 'up' ? 'active' : ''}" onclick="submitFeedback(${msgId}, 'up', this)" title="Helpful">👍</button>
                <button type="button" class="feedback-btn ${feedback === 'down' ? 'active' : ''}" onclick="submitFeedback(${msgId}, 'down', this)" title="Needs Improvement">👎</button>
            `;
            bubble.appendChild(footer);
        }
    }

    row.appendChild(avatar);
    row.appendChild(bubble);
    list.appendChild(row);
}

async function submitFeedback(msgId, rating, btn) {
    try {
        const res = await apiFetch('ai_coach_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'feedback', message_id: msgId, rating: rating })
        });
        const data = await res.json();
        if (data.success) {
            btn.parentElement.querySelectorAll('.feedback-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
        }
    } catch (err) {
        console.error('Feedback error:', err);
    }
}

function scrollChatToBottom() {
    const el = document.getElementById('chatMessages');
    el.scrollTop = el.scrollHeight;
}

function copyCodeSnippet(btn) {
    const pre = btn.closest('.code-block-wrapper').querySelector('pre');
    if (!pre) return;
    navigator.clipboard.writeText(pre.innerText).then(() => {
        const orig = btn.innerText;
        btn.innerText = '✅ Copied!';
        setTimeout(() => { btn.innerText = orig; }, 2000);
    });
}

function escapeHtml(text) {
    return text.replace(/&/g, '&amp;')
               .replace(/</g, '&lt;')
               .replace(/>/g, '&gt;')
               .replace(/"/g, '&quot;')
               .replace(/'/g, '&#039;');
}

/**
 * Lightweight client-side Markdown to Safe HTML renderer with code copy
 */
function renderMarkdown(md) {
    let html = escapeHtml(md);

    // Code blocks with syntax highlighting wrapper
    html = html.replace(/```([a-zA-Z0-9_\-\+]*)\n([\s\S]*?)```/g, (match, lang, code) => {
        const displayLang = lang || 'code';
        return `
            <div class="code-block-wrapper">
                <div class="code-header">
                    <span>${displayLang.toUpperCase()}</span>
                    <button type="button" class="btn-copy-code" onclick="copyCodeSnippet(this)">📋 Copy Code</button>
                </div>
                <pre><code>${code.trim()}</code></pre>
            </div>
        `;
    });

    // Inline code
    html = html.replace(/`([^`]+)`/g, '<code style="background: var(--clr-border); padding: 2px 6px; border-radius: 4px; font-family: var(--font-mono); font-size: 0.85em;">$1</code>');

    // Headers
    html = html.replace(/^#### (.*$)/gim, '<h4>$1</h4>');
    html = html.replace(/^### (.*$)/gim, '<h3>$1</h3>');
    html = html.replace(/^## (.*$)/gim, '<h2>$1</h2>');

    // Bold & Italics
    html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/\*(.*?)\*/g, '<em>$1</em>');

    // Markdown Tables
    html = html.replace(/((?:\|[^\n]+\|\r?\n)+)/g, (match) => {
        const rows = match.trim().split('\n');
        let tableHtml = '<table>';
        rows.forEach((r, idx) => {
            if (r.includes('---')) return; // separator
            const cells = r.split('|').filter((c, i, a) => i > 0 && i < a.length - 1);
            const tag = (idx === 0) ? 'th' : 'td';
            tableHtml += '<tr>' + cells.map(c => `<${tag}>${c.trim()}</${tag}>`).join('') + '</tr>';
        });
        tableHtml += '</table>';
        return tableHtml;
    });

    // Unordered lists
    html = html.replace(/^\s*-\s+(.*$)/gim, '<li>$1</li>');
    html = html.replace(/(<li>.*<\/li>)/gims, '<ul>$1</ul>');
    // Deduplicate nested uls
    html = html.replace(/<\/ul>\s*<ul>/g, '');

    // Links [text](url)
    html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener" style="color: var(--clr-primary); text-decoration: underline;">$1 ↗</a>');

    // Line breaks (non-code)
    html = html.replace(/\n\n+/g, '</p><p>');
    html = '<p>' + html + '</p>';
    html = html.replace(/<p><\/p>/g, '');

    return html;
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
