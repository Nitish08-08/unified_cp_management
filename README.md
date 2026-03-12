# 🚀 Unified Competitive Programming Tracker  
### *Track Codeforces, LeetCode & CodingNinjas progress in one place — built with PHP + MySQL.*

<p align="center">
  <img src="https://img.shields.io/badge/Language-PHP-blue?style=for-the-badge">
  <img src="https://img.shields.io/badge/Database-MySQL-orange?style=for-the-badge">
  <img src="https://img.shields.io/badge/Frontend-HTML%2FCSS%2FJS-yellow?style=for-the-badge">
  <img src="https://img.shields.io/badge/Status-Active-success?style=for-the-badge">
  <img src="https://img.shields.io/badge/License-MIT-green?style=for-the-badge">
</p>

---

## 📌 Overview

**Unified CP Tracker** is a lightweight web app that lets competitive programmers track their performance across:

- **Codeforces**
- **LeetCode**
- **CodeChef**

It provides a unified dashboard, automatic syncing, manual contest/problem tracking, and a simple login system — built entirely using **PHP, MySQL, HTML, CSS, and JS**.

---

## 🌟 Features

### 🔐 Authentication
- Secure registration & login  
- Password hashing  
- Session-based access control  

### 📊 Dashboard
- Combined rating overview  
- Contest analysis  
- Profile sync for each platform  
- Manual problem & contest entries  

### 🔄 Platform Sync Support
- **Codeforces API**
- **LeetCode API / GraphQL**
- **CodeChef API**


🗂️ Project Structure
unified_cp/
│── add_problem.php
│── competitive_cp.sql
│── dashboard.php
│── db.php
│── footer.php
│── header.php
│── helpers.php
│── index.html
│── index.php
│── login.php
│── logout.php
│── manage_platforms.php
│── record_contest.php
│── refresh_cc.php
│── refresh_cf.php
│── refresh_lc.php
│── register.php
│── sync_cc.php
│── sync_cf.php
│── sync_lc.php


Scripts:
sync_cf.php, sync_lc.php, sync_cc.php
refresh_cf.php, refresh_lc.php, refresh_cc.php


### 📝 Manual Entries
- Add your own problems  
- Record offline contests  

---
# ⚙️ Installation & Running Guide (XAMPP)

## ✅ 1. Install XAMPP  
Download: https://www.apachefriends.org  
Make sure Apache + MySQL are included.

---

## ✅ 2. Extract Project into `htdocs`
Move the folder to:
    C:/xampp/htdocs/unified_cp/
## ✅ 3. Start Apache & MySQL

Open XAMPP → Start:
- ✔ Apache  
- ✔ MySQL  

---

## ✅ 4. Create Database in phpMyAdmin
Visit:
http://localhost/phpmyadmin/

Steps:  
1. Click **New**  
2. Create database named: competitive_cp
---

## ✅ 5. Import SQL File

In phpMyAdmin:
- Select **competitive_cp**
- Click **Import**
- Upload:

This generates all necessary tables.

---

## ✅ 6. Configure Database Connection

Open:unified_cp/db.php
Use default XAMPP credentials:

```php
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "competitive_cp";


✅ 7. Run the App

Open browser:

http://localhost/unified_cp/

➕ Add Platform Handles
Go to:
manage_platforms.php






🚀 Future Enhancements->

📈 Visual rating graphs
🔄 Auto-sync via cron
🧭 Responsive modern UI
🏆 Leaderboards
🤖 Add CodeChef&AtCoder support