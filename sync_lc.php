<?php
/**
 * sync_lc.php - Resilient LeetCode Synchronizer via Official GraphQL API
 *
 * Variables expected:
 *   $pdo         - PDO connection
 *   $user_id     - Current logged-in user id
 *   $handle      - LeetCode username
 *   $platform_id - LeetCode platform ID
 *   $profile_id  - Profile ID in user_platform_profiles
 */

if (!isset($pdo, $user_id, $handle, $platform_id, $profile_id)) {
    return;
}

$userId = (int)$user_id;

$query = <<<'GRAPHQL'
query getUserData($username: String!) {
  matchedUser(username: $username) {
    username
    profile {
      ranking
      userAvatar
      realName
    }
    submitStatsGlobal {
      acSubmissionNum {
        difficulty
        count
      }
    }
  }
  userContestRanking(username: $username) {
    attendedContestsCount
    rating
    globalRanking
    topPercentage
    badge {
      name
    }
  }
  userContestRankingHistory(username: $username) {
    attended
    rating
    ranking
    trendDirection
    problemsSolved
    totalProblems
    finishTimeInSeconds
    contest {
      title
      startTime
    }
  }
  recentAcSubmissionList(username: $username, limit: 25) {
    id
    title
    titleSlug
    timestamp
  }
}
GRAPHQL;

$ch = curl_init('https://leetcode.com/graphql');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Referer: https://leetcode.com',
        'Origin: https://leetcode.com',
    ],
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    CURLOPT_POSTFIELDS     => json_encode(['query' => $query, 'variables' => ['username' => $handle]]),
    CURLOPT_TIMEOUT        => 12,
]);

$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true)['data'] ?? null;
if (!$data || empty($data['matchedUser'])) {
    return;
}

$matchedUser = $data['matchedUser'];
$contestInfo = $data['userContestRanking'] ?? null;
$history     = $data['userContestRankingHistory'] ?? [];
$recentSubs  = $data['recentAcSubmissionList'] ?? [];

// 1. Solved Counts
$totalSolved  = 0;
$easySolved   = 0;
$mediumSolved = 0;
$hardSolved   = 0;

if (!empty($matchedUser['submitStatsGlobal']['acSubmissionNum'])) {
    foreach ($matchedUser['submitStatsGlobal']['acSubmissionNum'] as $item) {
        $diff = strtolower($item['difficulty']);
        $cnt  = (int)$item['count'];
        if ($diff === 'all')       $totalSolved  = $cnt;
        elseif ($diff === 'easy')   $easySolved   = $cnt;
        elseif ($diff === 'medium') $mediumSolved = $cnt;
        elseif ($diff === 'hard')   $hardSolved   = $cnt;
    }
}
if ($totalSolved === 0) {
    $totalSolved = $easySolved + $mediumSolved + $hardSolved;
}

// 2. Contest Rating
$contestRating     = 0;
$contestGlobalRank = null;
if ($contestInfo) {
    $contestRating     = (int)round($contestInfo['rating'] ?? 0);
    $contestGlobalRank = $contestInfo['globalRanking'] ?? null;
}

$maxContestRating = $contestRating;
if (is_array($history)) {
    foreach ($history as $h) {
        if (!empty($h['attended']) && isset($h['rating'])) {
            $r = (int)round($h['rating']);
            if ($r > $maxContestRating) {
                $maxContestRating = $r;
            }
        }
    }
}

$problemGlobalRank = $matchedUser['profile']['ranking'] ?? null;

// Build clear rank title
$rankParts = [];
if ($contestRating > 0) {
    $tier = 'Knight';
    if ($contestRating >= 2150)     $tier = 'Guardian';
    elseif ($contestRating < 1600)  $tier = 'Contestant';
    $rankParts[] = "$tier ($contestRating)";
}
if ($contestGlobalRank) {
    $rankParts[] = "Contest #" . number_format($contestGlobalRank);
}
if ($problemGlobalRank) {
    $rankParts[] = "Global #" . number_format($problemGlobalRank);
}
$rankTitle = $rankParts ? implode(' | ', $rankParts) : "Solved $totalSolved";

// 3. Update user_platform_profiles
$updateProfile = $pdo->prepare(
    'UPDATE user_platform_profiles
     SET rank_title = ?,
         current_rating = ?,
         max_rating = ?,
         problems_solved = ?,
         last_sync_date = NOW()
     WHERE id = ?'
);
$updateProfile->execute([
    $rankTitle,
    $contestRating,
    $maxContestRating,
    $totalSolved,
    $profile_id
]);

// 4. Update Contests & Contest Performance
if (is_array($history) && !empty($history)) {
    $prevRating = null;
    $selectContest = $pdo->prepare('SELECT id FROM contests WHERE platform_id = ? AND name = ? LIMIT 1');
    $insertContest = $pdo->prepare('INSERT INTO contests (platform_id, name, start_time) VALUES (?, ?, ?)');
    $insertPerf    = $pdo->prepare(
        'INSERT INTO contest_performance (profile_id, contest_id, contest_rank, rating_change)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE contest_rank = VALUES(contest_rank), rating_change = VALUES(rating_change)'
    );

    foreach ($history as $row) {
        if (empty($row['attended']) || empty($row['contest']['title']) || empty($row['contest']['startTime'])) {
            continue;
        }

        $contestName = $row['contest']['title'];
        $startTimeTS = (int)$row['contest']['startTime'];
        $contestDate = date('Y-m-d H:i:s', $startTimeTS);

        $rating  = isset($row['rating']) ? (float)$row['rating'] : 0.0;
        $ranking = isset($row['ranking']) ? (int)$row['ranking'] : null;

        if ($ranking === null || $ranking <= 0) {
            continue;
        }

        $ratingChange = ($prevRating === null) ? 0.0 : ($rating - $prevRating);
        $prevRating   = $rating;

        $selectContest->execute([$platform_id, $contestName]);
        $contestRow = $selectContest->fetch();
        if ($contestRow) {
            $contestId = $contestRow['id'];
        } else {
            $insertContest->execute([$platform_id, $contestName, $contestDate]);
            $contestId = $pdo->lastInsertId();
        }

        $insertPerf->execute([$profile_id, $contestId, $ranking, (int)round($ratingChange)]);
    }
}

// 5. Track Solved Problems
if (is_array($recentSubs) && !empty($recentSubs)) {
    $problemUpsert = $pdo->prepare(
        'INSERT INTO problems (platform_id, problem_code, title, difficulty_level, topic)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE title = VALUES(title), difficulty_level = VALUES(difficulty_level)'
    );

    $userSolvedUpsert = $pdo->prepare(
        'INSERT INTO user_solved_problems (user_id, platform_id, problem_code, title, difficulty_level, topic, solved_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            difficulty_level = VALUES(difficulty_level),
            solved_at = CASE
                WHEN solved_at IS NULL OR VALUES(solved_at) > solved_at THEN VALUES(solved_at)
                ELSE solved_at
            END'
    );

    foreach ($recentSubs as $sub) {
        $title     = trim($sub['title'] ?? '');
        $slug      = trim($sub['titleSlug'] ?? '');
        $timestamp = isset($sub['timestamp']) ? (int)$sub['timestamp'] : 0;
        if (!$title || !$slug) {
            continue;
        }

        $code = 'LC-' . strtoupper(str_replace('-', '_', $slug));
        $solvedAt = $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : date('Y-m-d H:i:s');
        $difficulty = 'Medium'; // Default baseline difficulty

        $problemUpsert->execute([$platform_id, $code, $title, $difficulty, 'Algorithms']);
        $userSolvedUpsert->execute([$userId, $platform_id, $code, $title, $difficulty, 'Algorithms', $solvedAt]);
    }
}

recalculateAggregatedStats($pdo, $userId);
