# 🚀 Unified Competitive Programming Tracker & Mentor Command Center  
### *Track Codeforces, LeetCode & CodeChef progress in one place with AI Coaching & Mentor Analytics — built with PHP + MySQL / TiDB Cloud.*

<p align="center">
  <img src="https://img.shields.io/badge/Language-PHP%208.x-blue?style=for-the-badge">
  <img src="https://img.shields.io/badge/Database-TiDB%20Cloud%20%7C%20MySQL-orange?style=for-the-badge">
  <img src="https://img.shields.io/badge/Deployment-Vercel%20Serverless-black?style=for-the-badge">
  <img src="https://img.shields.io/badge/AI-Gemini%202.5%20Flash-purple?style=for-the-badge">
  <img src="https://img.shields.io/badge/License-MIT-green?style=for-the-badge">
</p>

---

## 📌 Overview

**Unified CP Management** aggregates competitive programming progress across **Codeforces**, **LeetCode**, and **CodeChef** into a unified, responsive developer command center. It features:

- 📊 **Unified Student Dashboard:** Live cross-platform rating tracking, solved problem breakdowns, consistency and problem-solving analytics, and offline contest tracking.
- 🤖 **AI Competitive Programming Coach:** Powered by Google Gemini 2.5 Flash, providing deeply personalized algorithmic practice plans, topic weakness diagnosis, and submission analysis using the student's real competitive programming stats.
- 👨‍🏫 **Mentor Command Center:** Allows coaches and senior mentors to monitor multiple assigned students/juniors from one centralized dashboard with search, filtering, student progress comparison, and IDOR-protected detailed student drilldowns.
- ☁️ **Production-Ready Serverless Architecture:** Optimized for Vercel deployment with TiDB Cloud Serverless MySQL and local development fallback.

---

## 🗂️ Clean Modular Project Structure

The project follows a clean separation of concerns:

```
unified_cp_management/
├── public/                     # Web root directory (publicly accessible)
│   ├── index.php               # Landing page & authenticated student router
│   ├── index.html              # Static landing page
│   ├── login.php               # Unified sign in (role-aware for students & mentors)
│   ├── register.php            # Student registration
│   ├── logout.php              # Secure session termination
│   ├── dashboard.php           # Student analytics & performance command center
│   ├── ai_coach.php            # Interactive AI Coach chat interface
│   ├── ai_coach_api.php        # AI Coach backend REST API
│   ├── mentor_dashboard.php    # Mentor Command Center for monitoring juniors
│   ├── mentor_register.php     # Mentor registration portal (passkey protected)
│   ├── mentor_student_detail.php # Detailed student profile for mentors
│   ├── manage_platforms.php    # Platform handles (CF, LC, CC) management & sync
│   ├── record_contest.php      # Contest participation history
│   ├── add_problem.php         # Solved problems log
│   ├── refresh_cc.php          # Quick CodeChef trigger
│   ├── refresh_cf.php          # Quick Codeforces trigger
│   ├── refresh_lc.php          # Quick LeetCode trigger
│   └── assets/                 # Static web assets
│       ├── css/style.css       # Unified design system & responsive styling
│       └── js/main.js          # Theme toggle, filters & interactive scripts
│
├── config/                     # Core configuration
│   └── db.php                  # TiDB Cloud & MySQL connection handler with SSL
│
├── includes/                   # Reusable UI components & shared helpers
│   ├── header.php              # Global navigation bar & theme header
│   ├── footer.php              # Global footer & scripts
│   └── helpers.php             # Scoring formulas, auth guards & mentor queries
│
├── services/                   # Business logic, platform APIs & AI services
│   ├── sync_cf.php             # Codeforces API synchronization
│   ├── sync_lc.php             # LeetCode GraphQL synchronization
│   ├── sync_cc.php             # CodeChef web scraping synchronization
│   └── ai_coach_service.php    # Gemini AI integration & student context builder
│
├── database/                   # Schema migrations & seed data
│   ├── clean_schema.sql        # Minimal, production-tested DDL schema
│   └── competitive_cp.sql      # Complete SQL database dump
│
├── tests/                      # Verification & test scripts
│   ├── test_db.php             # TiDB Cloud database connectivity test
│   ├── test_ai_coach.php       # AI Coach service & context retrieval test
│   └── test_mentor_workflow.php # Mentor workflow & IDOR verification test
│
├── certs/                      # SSL certificates
│   └── ca.pem                  # TiDB Cloud SSL root certificate authority
│
├── api/                        # Serverless entrypoint
│   └── index.php               # Vercel serverless front controller
│
├── index.php                   # Root delegator for local development
├── vercel.json                 # Vercel routing & serverless configuration
├── .env.example                # Safe environment variable template
├── .gitignore                  # Git ignore rules protecting secrets (.env)
└── README.md                   # Documentation
```

---

## 🌟 Key Features

### 1. 🔐 Role-Based Access Control (RBAC)
- **Student Role:** Can view their own profile, track performance, sync handles, interact with the AI Coach, and accept/decline mentor invitations.
- **Mentor Role:** Can access the Mentor Command Center, search and filter juniors, view detailed cross-platform profiles, add feedback notes, and assign milestones.
- **IDOR Protection:** Mentors can only view students who have accepted their mentorship invitation. Unauthorized access attempts trigger HTTP 403.
- **Passkey Protection:** Mentor registration requires a secure institutional passkey (`MENTOR_SECRET_KEY`).

### 2. 🤖 AI Competitive Programming Coach
- **Engine:** Google Gemini 2.5 Flash API (`gemini-2.5-flash`).
- **Real Student Context:** Automatically loads live ratings, contest count, problem volume, difficulty distribution (Easy / Medium / Hard), topic strengths, and weaknesses before generating recommendations.
- **Features:** 7-day personalized roadmaps, topic weakness identification, contest retrospective analysis, code debugging, and step-by-step problem explanations.
- **Chat History:** Persistent conversation threads stored per student.

### 3. 🔄 Platform Synchronizations
- **Codeforces:** Official REST API (`user.info`, `user.status`, `user.rating`).
- **LeetCode:** Official GraphQL API (`matchedUser`, `submitStats`, `userContestRanking`).
- **CodeChef:** Profile parsing and scraper with rating tracking.

---

## ⚙️ Local Development Setup

### Option 1: PHP Built-in Server (Recommended)

1. Clone the repository:
   ```bash
   git clone https://github.com/Nitish08-08/unified_cp_management.git
   cd unified_cp_management
   ```

2. Copy `.env.example` to `.env` and fill in your database & API keys:
   ```bash
   cp .env.example .env
   ```

3. Start the PHP server:
   ```bash
   php -S localhost:8000 -t public
   ```
   Or run directly from root:
   ```bash
   php -S localhost:8000
   ```

4. Open `http://localhost:8000` in your browser.

---

## ☁️ Vercel Deployment

This project is configured for deployment on Vercel using `vercel-php@0.9.0`:

1. Push your repository to GitHub.
2. Import the project in Vercel.
3. Configure Environment Variables in Vercel Project Settings:
   - `DB_HOST`: TiDB Cloud host (e.g. `gateway01.ap-northeast-1.prod.aws.tidbcloud.com`)
   - `DB_PORT`: `4000`
   - `DB_NAME`: `competitive_cp`
   - `DB_USER`: Your TiDB Cloud username
   - `DB_PASS`: Your TiDB Cloud password
   - `GEMINI_API_KEY`: Your Google Gemini API key
   - `MENTOR_SECRET_KEY`: Passkey required for mentor signups (e.g. `MENTOR2026`)
4. Deploy! All routes are automatically handled by `api/index.php` and `vercel.json`.

---

## 🧪 Testing

Run test verification scripts from the root directory:

```bash
# Test TiDB Cloud connection and table structure
php tests/test_db.php

# Test AI Coach service & student context extraction
php tests/test_ai_coach.php

# Test Mentor workflows & permission helpers
php tests/test_mentor_workflow.php
```

---

## 📄 License

MIT License &copy; 2026 Unified CP Management. Built for competitive programmers worldwide.