<?php
/**
 * sync_lc.php  (FIXED RANKING)
 *
 * Variables expected (set in manage_platforms.php):
 *   $pdo         - PDO connection
 *   $user_id     - current logged-in user id
 *   $handle      - LeetCode username (from URL)
 *   $platform_id - id from platforms table for LeetCode
 *   $profile_id  - id from user_platform_profiles row for this LC profile
 */

if (!isset($pdo, $user_id, $handle, $platform_id, $profile_id)) {
    return;
}

if (!function_exists('leetcodeFetchProblemMeta')) {
    function leetcodeFetchProblemMeta(string $slug): array
    {
        static $cache = [];

        $key = strtolower($slug);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $query = <<<'GRAPHQL'
query questionData($titleSlug: String!) {
  question(titleSlug: $titleSlug) {
    difficulty
    topicTags {
      name
    }
  }
}
GRAPHQL;

        $payload = json_encode([
            'query'     => $query,
            'variables' => ['titleSlug' => $slug],
        ]);

        $ch = curl_init('https://leetcode.com/graphql');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 8,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $meta = ['difficulty' => null, 'topics' => []];
        if ($response) {
            $json = json_decode($response, true);
            if (isset($json['data']['question'])) {
                $question = $json['data']['question'];
                if (!empty($question['difficulty'])) {
                    $meta['difficulty'] = $question['difficulty'];
                }
                if (!empty($question['topicTags']) && is_array($question['topicTags'])) {
                    foreach ($question['topicTags'] as $tag) {
                        if (!empty($tag['name'])) {
                            $meta['topics'][] = $tag['name'];
                        }
                    }
                }
            }
        }

        $cache[$key] = $meta;
        return $meta;
    }
}

$userId = (int)$user_id;

/* ======================================================
 * 1. PROBLEM STATS (total solved + global problem rank)
 *    -> matches numbers shown on LeetCode profile
 * ==================================================== */

$statsUrl  = "https://leetcode-stats-api.herokuapp.com/" . urlencode($handle);
$statsJson = @file_get_contents($statsUrl);
$statsData = $statsJson ? json_decode($statsJson, true) : null;

$totalSolved = 0;
$problemRank = null;
if ($statsData && isset($statsData['totalSolved'])) {
    $totalSolved = (int)$statsData['totalSolved'];
    // This 'ranking' is the global rank shown on LeetCode profile
    if (isset($statsData['ranking'])) {
        $problemRank = (int)$statsData['ranking'];
    }
}

/* ======================================================
 * 2. CONTEST RATING & HISTORY (OFFICIAL GRAPHQL API)
 *    -> real contest rating + rating history
 * ==================================================== */

$query = <<<'GRAPHQL'
query userContestData($username: String!) {
  userContestRanking(username: $username) {
    rating
    globalRanking
  }
  userContestRankingHistory(username: $username) {
    contest {
      title
      startTime
    }
    rating
    ranking
  }
    recentSubmissionList(username: $username) {
        title
        titleSlug
        statusDisplay
        timestamp
    }
}
GRAPHQL;

$payload = json_encode([
    "query"     => $query,
    "variables" => ["username" => $handle],
]);

$ch = curl_init("https://leetcode.com/graphql");
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
    CURLOPT_POSTFIELDS     => $payload,
]);
$response = curl_exec($ch);
curl_close($ch);

$graphql = $response ? json_decode($response, true) : null;
$data    = $graphql['data'] ?? null;

$currentRatingInfo = $data['userContestRanking'] ?? null;
$history           = $data['userContestRankingHistory'] ?? [];
$recentSubs        = $data['recentSubmissionList'] ?? [];

// contest rating + global contest rank (from last rated contest)
$contestRating = 0.0;
$contestGlobalRank = null;

if ($currentRatingInfo) {
    if (isset($currentRatingInfo['rating'])) {
        $contestRating = (float)$currentRatingInfo['rating'];
    }
    if (isset($currentRatingInfo['globalRanking'])) {
        $contestGlobalRank = (int)$currentRatingInfo['globalRanking'];
    }
}

// max contest rating from history
$maxContestRating = $contestRating;
if (is_array($history)) {
    foreach ($history as $row) {
        if (!isset($row['rating'])) continue;
        $r = (float)$row['rating'];
        if ($r > $maxContestRating) {
            $maxContestRating = $r;
        }
    }
}

/* ======================================================
 * 3. BUILD A CLEAR RANK TITLE
 *    (Problem rank + Contest global rank combined)
 * ==================================================== */

$rankParts = [];
if ($problemRank !== null) {
    $rankParts[] = "Problem Rank #" . $problemRank;
}
if ($contestGlobalRank !== null) {
    $rankParts[] = "Contest Rank #" . $contestGlobalRank;
}
$rankTitle = $rankParts ? implode(" | ", $rankParts) : "LeetCode";

/* ======================================================
 * 4. UPDATE user_platform_profiles ENTRY
 * ==================================================== */

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

/* ======================================================
 * 5. UPDATE / INSERT CONTEST
 * ==================================================== */

if (is_array($history) && !empty($history)) {

    $prevRating = null;
    $selectContest = $pdo->prepare("
        SELECT id FROM contests
        WHERE platform_id = ? AND name = ?
        LIMIT 1
    ");
    $insertContest = $pdo->prepare("
        INSERT INTO contests (platform_id, name, start_time)
        VALUES (?, ?, ?)
    ");
    $insertPerf = $pdo->prepare("
        INSERT INTO contest_performance (profile_id, contest_id, contest_rank, rating_change)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE contest_rank = VALUES(contest_rank), rating_change = VALUES(rating_change)
    ");

    $contestCount = 0;

    foreach ($history as $row) {
        if (!isset($row['contest']['title'], $row['contest']['startTime'])) {
            continue;
        }

        $contestName = $row['contest']['title'];
        $startTimeTS = (int)$row['contest']['startTime'];
        $contestDate = date('Y-m-d H:i:s', $startTimeTS);

        $rating  = isset($row['rating']) ? (float)$row['rating']   : 0.0;
        $ranking = isset($row['ranking']) ? (int)$row['ranking']   : null;

        if ($ranking === null || $ranking <= 0) {
            continue; // skip unrated appearances
        }

        // rating change w.r.t previous contest
        $ratingChange = ($prevRating === null) ? 0.0 : ($rating - $prevRating);
        $prevRating   = $rating;

        // ensure contest exists
        $selectContest->execute([$platform_id, $contestName]);
        $contestRow = $selectContest->fetch();
        if ($contestRow) {
            $contestId = $contestRow['id'];
        } else {
            $insertContest->execute([$platform_id, $contestName, $contestDate]);
            $contestId = $pdo->lastInsertId();
        }

        // insert performance row
        $insertPerf->execute([$profile_id, $contestId, $ranking, $ratingChange]);
        $contestCount++;
    }

}

/* ======================================================
 * 6. TRACK RECENT PROBLEM SOLVES FOR DASHBOARD VIEW
 * ==================================================== */

$recentProblems = [];
if (is_array($recentSubs)) {
    foreach ($recentSubs as $submission) {
        if (($submission['statusDisplay'] ?? '') !== 'Accepted') {
            continue;
        }
        $title     = $submission['title'] ?? null;
        $slug      = $submission['titleSlug'] ?? null;
        $timestamp = isset($submission['timestamp']) ? (int)$submission['timestamp'] : 0;

        if (!$title || !$slug || isset($recentProblems[$slug])) {
            continue;
        }

        $meta = leetcodeFetchProblemMeta($slug);
        $difficulty = !empty($meta['difficulty']) ? $meta['difficulty'] : 'Medium';
        $topics = !empty($meta['topics']) ? implode(', ', $meta['topics']) : null;

        $recentProblems[$slug] = [
            'code'       => strtoupper(str_replace('-', '_', $slug)),
            'title'      => $title,
            'difficulty' => $difficulty,
            'topic'      => $topics,
            'solved_at'  => $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : date('Y-m-d H:i:s'),
        ];

        if (count($recentProblems) >= 5) {
            break;
        }
    }
}

if (!empty($recentProblems)) {
    $userSolvedUpsert = $pdo->prepare(
        'INSERT INTO user_solved_problems (user_id, platform_id, problem_code, title, difficulty_level, topic, solved_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            difficulty_level = VALUES(difficulty_level),
            topic = VALUES(topic),
            solved_at = CASE
                WHEN solved_at IS NULL OR VALUES(solved_at) > solved_at
                    THEN VALUES(solved_at)
                ELSE solved_at
            END'
    );

    foreach ($recentProblems as $problem) {
        $userSolvedUpsert->execute([
            $userId,
            $platform_id,
            $problem['code'],
            $problem['title'],
            $problem['difficulty'],
            $problem['topic'],
            $problem['solved_at'],
        ]);
    }
}

recalculateAggregatedStats($pdo, $userId);
