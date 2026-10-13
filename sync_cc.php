<?php
/**
 * sync_cc.php - Resilient CodeChef Synchronizer
 *
 * Variables expected:
 *   $pdo         - PDO connection
 *   $user_id     - Current logged-in user id
 *   $handle      - CodeChef username
 *   $platform_id - CodeChef platform ID
 *   $profile_id  - Profile ID in user_platform_profiles
 */

if (!isset($pdo, $user_id, $handle, $platform_id, $profile_id)) {
    return;
}

$userId = (int)$user_id;

$url = "https://www.codechef.com/users/" . urlencode($handle);
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 12,
    CURLOPT_HTTPHEADER     => [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
    ],
]);
$html = curl_exec($ch);
curl_close($ch);

if (!$html) {
    return;
}

// 1. Current & Max Rating
$currentRating = 0;
if (preg_match('/class="[^"]*rating-number[^"]*">\s*([0-9]+)/s', $html, $m)) {
    $currentRating = (int)$m[1];
}

$maxRating = $currentRating;
if (preg_match('/Highest Rating\s*([0-9]+)/i', $html, $m)) {
    $maxRating = (int)$m[1];
}

// 2. Contest History from JavaScript block (var all_rating = [...])
$contestsHistory = [];
if (preg_match('/var all_rating = (\[.*?\]);/s', $html, $m)) {
    $contestsHistory = json_decode($m[1], true) ?: [];
}

if ($currentRating === 0 && !empty($contestsHistory)) {
    $lastEntry = end($contestsHistory);
    if (!empty($lastEntry['rating'])) {
        $currentRating = (int)$lastEntry['rating'];
    }
}

if ($maxRating === 0 && !empty($contestsHistory)) {
    foreach ($contestsHistory as $entry) {
        if (!empty($entry['rating'])) {
            $maxRating = max($maxRating, (int)$entry['rating']);
        }
    }
}

// 3. Stars & Div
$starCount = 0;
if ($currentRating >= 2500)     $starCount = 7;
elseif ($currentRating >= 2200) $starCount = 6;
elseif ($currentRating >= 2000) $starCount = 5;
elseif ($currentRating >= 1800) $starCount = 4;
elseif ($currentRating >= 1600) $starCount = 3;
elseif ($currentRating >= 1400) $starCount = 2;
elseif ($currentRating >= 1)    $starCount = 1;

$starBadge = $starCount > 0 ? str_repeat('★', $starCount) . " ({$currentRating})" : "Unrated";
$divNumber = $currentRating >= 2000 ? '1' : ($currentRating >= 1600 ? '2' : ($currentRating >= 1400 ? '3' : '4'));
$rankTitle = "$starBadge | Div $divNumber";

// 4. Solved Problems Extraction
$solvedProblems = [];
if (preg_match('/<section[^>]*class="[^"]*problems-solved[^"]*"[^>]*>(.*?)<\/section>/s', $html, $sectionMatch)) {
    $sectionHtml = $sectionMatch[1];
    if (preg_match_all('/<span style="font-size: 12px";?>([^<]+)<\/span>/i', $sectionHtml, $probs)) {
        foreach ($probs[1] as $pName) {
            $pName = trim(html_entity_decode($pName));
            if ($pName !== '' && !in_array($pName, $solvedProblems, true)) {
                $solvedProblems[] = $pName;
            }
        }
    }
}

$problemsSolved = count($solvedProblems);
if (preg_match('/Fully Solved\s*\(?([0-9]+)\)?/i', $html, $cntMatch)) {
    $problemsSolved = max($problemsSolved, (int)$cntMatch[1]);
}

// 5. Update user_platform_profiles
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
    $currentRating,
    $maxRating,
    $problemsSolved,
    $profile_id
]);

// 6. Upsert Contest History
if (!empty($contestsHistory)) {
    $prevRating = null;
    $selectContest = $pdo->prepare('SELECT id FROM contests WHERE platform_id = ? AND name = ? LIMIT 1');
    $insertContest = $pdo->prepare('INSERT INTO contests (platform_id, name, start_time) VALUES (?, ?, ?)');
    $insertPerf    = $pdo->prepare(
        'INSERT INTO contest_performance (profile_id, contest_id, contest_rank, rating_change)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE contest_rank = VALUES(contest_rank), rating_change = VALUES(rating_change)'
    );

    foreach ($contestsHistory as $entry) {
        $contestName = $entry['name'] ?? ($entry['code'] ?? '');
        if ($contestName === '') {
            continue;
        }

        $cDate = $entry['end_date'] ?? date('Y-m-d H:i:s');
        $rating = isset($entry['rating']) ? (int)$entry['rating'] : 0;
        $rank   = isset($entry['rank']) ? (int)$entry['rank'] : 0;

        $ratingChange = ($prevRating === null) ? 0 : ($rating - $prevRating);
        $prevRating   = $rating;

        $selectContest->execute([$platform_id, $contestName]);
        $contestRow = $selectContest->fetch();
        if ($contestRow) {
            $contestId = $contestRow['id'];
        } else {
            $insertContest->execute([$platform_id, $contestName, $cDate]);
            $contestId = $pdo->lastInsertId();
        }

        if ($rank > 0) {
            $insertPerf->execute([$profile_id, $contestId, $rank, $ratingChange]);
        }
    }
}

// 7. Upsert Solved Problems
if (!empty($solvedProblems)) {
    $problemUpsert = $pdo->prepare(
        'INSERT INTO problems (platform_id, problem_code, title, difficulty_level, topic)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE title = VALUES(title)'
    );

    $userSolvedUpsert = $pdo->prepare(
        'INSERT INTO user_solved_problems (user_id, platform_id, problem_code, title, difficulty_level, topic, solved_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE title = VALUES(title)'
    );

    foreach ($solvedProblems as $pName) {
        $pCode = 'CC-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $pName), 0, 20));
        $problemUpsert->execute([$platform_id, $pCode, $pName, 'Medium', 'Practice']);
        $userSolvedUpsert->execute([$userId, $platform_id, $pCode, $pName, 'Medium', 'Practice']);
    }
}

recalculateAggregatedStats($pdo, $userId);
