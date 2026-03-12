<?php
require 'db.php';

if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT upp.*, p.id AS pid
    FROM user_platform_profiles upp
    JOIN platforms p ON upp.platform_id = p.id
    WHERE upp.user_id=? AND p.platform_name='LeetCode'
");
$stmt->execute([$user_id]);
$profile = $stmt->fetch();

if (!$profile) {
    header("Location: dashboard.php"); exit;
}

$handle      = $profile['handle'];
$platform_id = $profile['pid'];
$profile_id  = $profile['id'];

include 'sync_lc.php';

echo "<script>alert('LeetCode synced successfully!');</script>";
echo "<meta http-equiv='refresh' content='0; URL=dashboard.php'>";
