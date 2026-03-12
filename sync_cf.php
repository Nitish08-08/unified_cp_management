<?php
if (!isset($pdo, $user_id, $handle, $platform_id, $profile_id)) {
    return;
}

$userId = (int)$user_id;

$infoResponse = @file_get_contents("https://codeforces.com/api/user.info?handles=" . urlencode($handle));
if (!$infoResponse) {
    return;
}

$decodedInfo = json_decode($infoResponse, true);
if (!is_array($decodedInfo) || ($decodedInfo['status'] ?? '') !== 'OK') {
    return;
}

$userInfo = $decodedInfo['result'][0] ?? [];

$rankTitle = isset($userInfo['rank']) ? ucwords(str_replace('_', ' ', $userInfo['rank'])) : 'Unrated';
$currentRating = (int)($userInfo['rating'] ?? 0);
$maxRating = (int)($userInfo['maxRating'] ?? $currentRating);

// Pull accepted submissions to compute solved count and keep the problem catalogue updated.
$statusResponse = @file_get_contents("https://codeforces.com/api/user.status?handle=" . urlencode($handle));
$solvedProblems = [];

if ($statusResponse) {
    $statusPayload = json_decode($statusResponse, true);
    if (is_array($statusPayload) && ($statusPayload['status'] ?? '') === 'OK') {
        foreach ($statusPayload['result'] as $submission) {
            if (($submission['verdict'] ?? '') !== 'OK') {
                continue;
            }
            if (empty($submission['problem']['contestId']) || empty($submission['problem']['index'])) {
                continue;
            }

            $problemKey = $submission['problem']['contestId'] . '-' . $submission['problem']['index'];
            if (isset($solvedProblems[$problemKey])) {
                continue;
            }

            $problemName   = $submission['problem']['name'] ?? 'Untitled';
            $problemCode   = $submission['problem']['contestId'] . $submission['problem']['index'];
            $problemRating = isset($submission['problem']['rating']) ? (int)$submission['problem']['rating'] : null;
            $tags          = $submission['problem']['tags'] ?? [];
            $solvedAt      = isset($submission['creationTimeSeconds'])
                ? date('Y-m-d H:i:s', (int)$submission['creationTimeSeconds'])
                : date('Y-m-d H:i:s');

            $difficulty = 'Easy';
            if ($problemRating !== null) {
                if ($problemRating >= 1900) {
                    $difficulty = 'Hard';
                } elseif ($problemRating >= 1300) {
                    $difficulty = 'Medium';
                }
            }

            $solvedProblems[$problemKey] = [
                'code'       => $problemCode,
                'title'      => $problemName,
                'difficulty' => $difficulty,
                'topics'     => implode(',', $tags),
                'solved_at'  => $solvedAt,
            ];
        }
    }
}

$totalSolved = count($solvedProblems);

// Upsert problem metadata so the Problems view stays fresh.
if ($totalSolved > 0) {
    $problemUpsert = $pdo->prepare(
        'INSERT INTO problems (platform_id, problem_code, title, difficulty_level, topic)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE title = VALUES(title), difficulty_level = VALUES(difficulty_level), topic = VALUES(topic)'
    );

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

    foreach ($solvedProblems as $problem) {
        $problemUpsert->execute([
            $platform_id,
            $problem['code'],
            $problem['title'],
            $problem['difficulty'],
            $problem['topics'],
        ]);

        $userSolvedUpsert->execute([
            $userId,
            $platform_id,
            $problem['code'],
            $problem['title'],
            $problem['difficulty'],
            $problem['topics'],
            $problem['solved_at'],
        ]);
    }
}

// Process contest rating history for ranked contests.
$ratingHistoryResponse = @file_get_contents("https://codeforces.com/api/user.rating?handle=" . urlencode($handle));
if ($ratingHistoryResponse) {
    $historyPayload = json_decode($ratingHistoryResponse, true);
    if (is_array($historyPayload) && ($historyPayload['status'] ?? '') === 'OK') {
        $contestLookup = $pdo->prepare('SELECT id FROM contests WHERE platform_id = ? AND name = ? LIMIT 1');
        $contestInsert = $pdo->prepare('INSERT INTO contests (platform_id, name, start_time) VALUES (?, ?, ?)');
        $performanceUpsert = $pdo->prepare(
            'INSERT INTO contest_performance (profile_id, contest_id, contest_rank, rating_change)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE contest_rank = VALUES(contest_rank), rating_change = VALUES(rating_change)'
        );

        foreach ($historyPayload['result'] as $row) {
            $rank = isset($row['rank']) ? (int)$row['rank'] : null;
            if ($rank === null || $rank <= 0) {
                continue;
            }

            $contestName = $row['contestName'] ?? null;
            if (!$contestName) {
                continue;
            }

            $ratingChange = (int)($row['newRating'] ?? 0) - (int)($row['oldRating'] ?? 0);
            $startTime = isset($row['ratingUpdateTimeSeconds'])
                ? date('Y-m-d H:i:s', (int)$row['ratingUpdateTimeSeconds'])
                : null;

            $contestLookup->execute([$platform_id, $contestName]);
            $contestId = $contestLookup->fetchColumn();

            if ($contestId === false) {
                $contestInsert->execute([$platform_id, $contestName, $startTime]);
                $contestId = (int)$pdo->lastInsertId();
            } else {
                $contestId = (int)$contestId;
            }

            $performanceUpsert->execute([$profile_id, $contestId, $rank, $ratingChange]);
        }
    }
}

// Snapshot the profile information alongside solved totals.
$pdo->prepare(
    'UPDATE user_platform_profiles
     SET rank_title = ?, current_rating = ?, max_rating = ?, problems_solved = ?, last_sync_date = NOW()
     WHERE id = ?'
)->execute([
    $rankTitle,
    $currentRating,
    $maxRating,
    $totalSolved,
    $profile_id,
]);

recalculateAggregatedStats($pdo, $userId);
