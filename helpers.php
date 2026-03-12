<?php
/**
 * Common helper utilities shared across the Unified Competitive Programming
 * Management System.
 */

if (!function_exists('recalculateAggregatedStats')) {
    /**
     * Rebuild the aggregated statistics snapshot for the supplied user.
     *
     * @param PDO $pdo     Active PDO connection
     * @param int $userId  Authenticated user identifier
     */
    function recalculateAggregatedStats(PDO $pdo, int $userId): void
    {
        // Total problems solved comes from the latest snapshot on each profile.
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(problems_solved), 0) FROM user_platform_profiles WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $totalProblems = (int)$stmt->fetchColumn();

        // Count the contests where we actually have a rank recorded.
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM contest_performance cp
             INNER JOIN user_platform_profiles upp ON cp.profile_id = upp.id
             WHERE upp.user_id = ? AND cp.contest_rank IS NOT NULL'
        );
        $stmt->execute([$userId]);
        $totalContests = (int)$stmt->fetchColumn();

        // Highest platform based on current rating (fallback to max rating).
        $stmt = $pdo->prepare(
            'SELECT platform_id
             FROM user_platform_profiles
             WHERE user_id = ?
             ORDER BY COALESCE(current_rating, 0) DESC, COALESCE(max_rating, 0) DESC
             LIMIT 1'
        );
        $stmt->execute([$userId]);
        $highestPlatformId = $stmt->fetchColumn();
        $highestPlatformId = $highestPlatformId !== false ? (int)$highestPlatformId : null;

        // Pull per-platform ratings so we can normalize each scale consistently.
        $profileStmt = $pdo->prepare(
            'SELECT upp.platform_id, p.platform_name, COALESCE(upp.current_rating, 0) AS current_rating, COALESCE(upp.max_rating, 0) AS max_rating
             FROM user_platform_profiles upp
             INNER JOIN platforms p ON p.id = upp.platform_id
             WHERE upp.user_id = ?'
        );
        $profileStmt->execute([$userId]);
        $profileRows = $profileStmt->fetchAll(PDO::FETCH_ASSOC);

        $ratingRanges = [
            'Codeforces' => ['min' => 0,    'max' => 3800],
            'LeetCode'   => ['min' => 0,    'max' => 3600],
            'CodeChef'   => ['min' => 1200, 'max' => 3600],
        ];

        $normalizedRatings = [];
        foreach ($profileRows as $profileRow) {
            $platformName = $profileRow['platform_name'];
            $range        = $ratingRanges[$platformName] ?? ['min' => 0, 'max' => 3600];
            $rangeWidth   = max(1, (float)$range['max'] - (float)$range['min']);

            $rawRating = max((float)$profileRow['current_rating'], (float)$profileRow['max_rating']);
            $normalized = ($rawRating - (float)$range['min']) / $rangeWidth;
            $normalized = max(0.0, min(1.0, $normalized));

            $normalizedRatings[] = $normalized;
        }

        // Last activity date from solved problems or contests.
        $stmt = $pdo->prepare(
            'SELECT MAX(solved_at) FROM user_solved_problems WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $lastSolveAt = $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT MAX(c.start_time)
             FROM contest_performance cp
             INNER JOIN user_platform_profiles upp ON cp.profile_id = upp.id
             INNER JOIN contests c ON cp.contest_id = c.id
             WHERE upp.user_id = ? AND cp.contest_rank IS NOT NULL'
        );
        $stmt->execute([$userId]);
        $lastContestAt = $stmt->fetchColumn();

        $lastActivityAt = null;
        if ($lastSolveAt && $lastContestAt) {
            $lastActivityAt = max($lastSolveAt, $lastContestAt);
        } elseif ($lastSolveAt) {
            $lastActivityAt = $lastSolveAt;
        } elseif ($lastContestAt) {
            $lastActivityAt = $lastContestAt;
        }

        $problemScore  = min($totalProblems / 1200, 1.0);
        $contestScore  = min($totalContests / 150, 1.0);
        $ratingScore   = !empty($normalizedRatings) ? max($normalizedRatings) : 0.0;
        $activityScore = 0.0;

        if ($lastActivityAt) {
            $activityTimestamp = strtotime($lastActivityAt);
            if ($activityTimestamp !== false) {
                $daysSinceActivity = max(0, (time() - $activityTimestamp) / 86400);
                $activityScore = 1.0 - min($daysSinceActivity, 365) / 365;
                if ($activityScore < 0) {
                    $activityScore = 0.0;
                }
            }
        }

        $compositeScore = (0.35 * $problemScore)
            + (0.25 * $contestScore)
            + (0.30 * $ratingScore)
            + (0.10 * $activityScore);

        $compositeScore = round(max(0.0, min(1.0, $compositeScore)) * 100, 2);

        // Upsert into aggregated_stats.
        $stmt = $pdo->prepare('SELECT id FROM aggregated_stats WHERE user_id = ?');
        $stmt->execute([$userId]);
        $existingId = $stmt->fetchColumn();

        if ($existingId === false) {
            $insert = $pdo->prepare(
                'INSERT INTO aggregated_stats (user_id, total_problems_solved, total_contests, highest_platform_id, composite_score, last_updated)
                 VALUES (?, ?, ?, ?, ?, NOW())'
            );
            $insert->execute([$userId, $totalProblems, $totalContests, $highestPlatformId, $compositeScore]);
        } else {
            $update = $pdo->prepare(
                'UPDATE aggregated_stats
                 SET total_problems_solved = ?, total_contests = ?, highest_platform_id = ?, composite_score = ?, last_updated = NOW()
                 WHERE user_id = ?'
            );
            $update->execute([$totalProblems, $totalContests, $highestPlatformId, $compositeScore, $userId]);
        }
    }
}
