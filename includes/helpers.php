<?php
/**
 * Common helper utilities shared across the Unified Competitive Programming
 * Management System.
 */

if (!function_exists('recalculateAggregatedStats')) {
    /**
     * Rebuild the aggregated statistics snapshot for the supplied user.
     * Computes Composite Performance, Consistency Score, and Problem Solving Skills Score.
     *
     * @param PDO $pdo     Active PDO connection
     * @param int $userId  Authenticated user identifier
     */
    function recalculateAggregatedStats(PDO $pdo, int $userId): void
    {
        // 1. Total problems solved from profile snapshots
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(problems_solved), 0) FROM user_platform_profiles WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $totalProblems = (int)$stmt->fetchColumn();

        // 2. Count contests with a recorded rank
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM contest_performance cp
             INNER JOIN user_platform_profiles upp ON cp.profile_id = upp.id
             WHERE upp.user_id = ? AND cp.contest_rank IS NOT NULL'
        );
        $stmt->execute([$userId]);
        $totalContests = (int)$stmt->fetchColumn();

        // 3. Highest platform by current/max rating
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

        // 4. Per-platform ratings
        $profileStmt = $pdo->prepare(
            'SELECT upp.platform_id, p.platform_name, COALESCE(upp.current_rating, 0) AS current_rating, COALESCE(upp.max_rating, 0) AS max_rating
             FROM user_platform_profiles upp
             INNER JOIN platforms p ON p.id = upp.platform_id
             WHERE upp.user_id = ?'
        );
        $profileStmt->execute([$userId]);
        $profileRows = $profileStmt->fetchAll(PDO::FETCH_ASSOC);

        $ratingBenchmarks = [
            'Codeforces' => ['min' => 800,  'target' => 2000],
            'LeetCode'   => ['min' => 1200, 'target' => 2200],
            'CodeChef'   => ['min' => 1200, 'target' => 2000],
        ];

        $normalizedRatings = [];
        foreach ($profileRows as $row) {
            $plat = $row['platform_name'];
            $bench = $ratingBenchmarks[$plat] ?? ['min' => 800, 'target' => 2000];
            $peak = max((float)$row['current_rating'], (float)$row['max_rating']);
            if ($peak > 0) {
                $norm = ($peak - (float)$bench['min']) / max(1.0, (float)$bench['target'] - (float)$bench['min']);
                $normalizedRatings[] = max(0.0, min(1.0, $norm));
            }
        }
        $maxNormalizedRating = !empty($normalizedRatings) ? max($normalizedRatings) : 0.0;

        // 5. Last activity timestamp (solves or contests)
        $stmt = $pdo->prepare('SELECT MAX(solved_at) FROM user_solved_problems WHERE user_id = ?');
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

        // ==========================================
        // A. CONSISTENCY SCORE (0 - 100)
        // ==========================================
        $recencyScore = 20.0;
        if ($lastActivityAt) {
            $actTs = strtotime($lastActivityAt);
            if ($actTs !== false) {
                $days = max(0, (time() - $actTs) / 86400);
                if ($days <= 1.0) {
                    $recencyScore = 100.0;
                } elseif ($days <= 3.0) {
                    $recencyScore = 95.0;
                } elseif ($days <= 7.0) {
                    $recencyScore = 85.0;
                } elseif ($days <= 14.0) {
                    $recencyScore = 70.0;
                } elseif ($days <= 30.0) {
                    $recencyScore = 55.0;
                } elseif ($days <= 60.0) {
                    $recencyScore = 35.0;
                } else {
                    $recencyScore = max(5.0, 35.0 - ($days - 60.0) * 0.3);
                }
            }
        }

        // Contest participation frequency (target 15 contests = 100%)
        $contestHabitScore = min(1.0, $totalContests / 15.0) * 100.0;

        // Solve volume momentum (target 400 problems = 100%)
        $volumeMomentum = min(1.0, $totalProblems / 400.0) * 100.0;

        // Multi-platform commitment
        $numPlatforms = count($profileRows);
        $platformCommitment = $numPlatforms >= 3 ? 100.0 : ($numPlatforms === 2 ? 85.0 : ($numPlatforms === 1 ? 65.0 : 0.0));

        $consistencyScore = round(
            (0.35 * $recencyScore) +
            (0.35 * $contestHabitScore) +
            (0.15 * $volumeMomentum) +
            (0.15 * $platformCommitment),
            1
        );
        $consistencyScore = max(0.0, min(100.0, $consistencyScore));

        // ==========================================
        // B. PROBLEM SOLVING SKILLS SCORE (0 - 100)
        // ==========================================
        // 1. Rating skill depth (45%)
        $ratingDepthScore = $maxNormalizedRating * 100.0;

        // 2. Verified solved quantity (30% - target 500 problems = 100%)
        $solvedQuantityScore = min(1.0, $totalProblems / 500.0) * 100.0;

        // 3. Problem difficulty weighting from user_solved_problems (25%)
        $diffStmt = $pdo->prepare(
            'SELECT difficulty_level, COUNT(*) AS cnt
             FROM user_solved_problems
             WHERE user_id = ?
             GROUP BY difficulty_level'
        );
        $diffStmt->execute([$userId]);
        $diffBreakdown = $diffStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        $easyCount   = (int)($diffBreakdown['Easy'] ?? 0);
        $mediumCount = (int)($diffBreakdown['Medium'] ?? 0);
        $hardCount   = (int)($diffBreakdown['Hard'] ?? 0);
        $totalTagged = $easyCount + $mediumCount + $hardCount;

        if ($totalTagged > 0) {
            $weightedDiff = ($hardCount * 3.0) + ($mediumCount * 1.8) + ($easyCount * 0.8);
            $idealRatio = $weightedDiff / ($totalTagged * 1.8);
            $difficultyScore = min(1.0, $idealRatio) * 100.0;
        } else {
            // Default based on overall problems and rating
            $difficultyScore = ($ratingDepthScore * 0.6) + ($solvedQuantityScore * 0.4);
        }

        $problemSolvingScore = round(
            (0.45 * $ratingDepthScore) +
            (0.35 * $solvedQuantityScore) +
            (0.20 * $difficultyScore),
            1
        );
        $problemSolvingScore = max(0.0, min(100.0, $problemSolvingScore));

        // ==========================================
        // C. COMPOSITE PERFORMANCE SCORE (0 - 100)
        // ==========================================
        $compositeScore = round(
            (0.50 * $problemSolvingScore) + (0.50 * $consistencyScore),
            1
        );

        // ==========================================
        // D. PERSIST TO aggregated_stats TABLE
        // ==========================================
        // Check if consistency_score & problem_solving_score columns exist
        try {
            $checkCols = $pdo->query("SHOW COLUMNS FROM aggregated_stats LIKE 'consistency_score'")->fetch();
            if (!$checkCols) {
                $pdo->exec("ALTER TABLE aggregated_stats ADD COLUMN consistency_score DECIMAL(6,2) DEFAULT 0.00");
                $pdo->exec("ALTER TABLE aggregated_stats ADD COLUMN problem_solving_score DECIMAL(6,2) DEFAULT 0.00");
            }
        } catch (Exception $e) {
            // Ignore if already present
        }

        $stmt = $pdo->prepare('SELECT id FROM aggregated_stats WHERE user_id = ?');
        $stmt->execute([$userId]);
        $existingId = $stmt->fetchColumn();

        if ($existingId === false) {
            try {
                $insert = $pdo->prepare(
                    'INSERT INTO aggregated_stats (user_id, total_problems_solved, total_contests, highest_platform_id, consistency_score, problem_solving_score, composite_score, last_updated)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
                );
                $insert->execute([$userId, $totalProblems, $totalContests, $highestPlatformId, $consistencyScore, $problemSolvingScore, $compositeScore]);
            } catch (Exception $e) {
                $insertFallback = $pdo->prepare(
                    'INSERT INTO aggregated_stats (user_id, total_problems_solved, total_contests, highest_platform_id, composite_score, last_updated)
                     VALUES (?, ?, ?, ?, ?, NOW())'
                );
                $insertFallback->execute([$userId, $totalProblems, $totalContests, $highestPlatformId, $compositeScore]);
            }
        } else {
            try {
                $update = $pdo->prepare(
                    'UPDATE aggregated_stats
                     SET total_problems_solved = ?,
                         total_contests = ?,
                         highest_platform_id = ?,
                         consistency_score = ?,
                         problem_solving_score = ?,
                         composite_score = ?,
                         last_updated = NOW()
                     WHERE user_id = ?'
                );
                $update->execute([$totalProblems, $totalContests, $highestPlatformId, $consistencyScore, $problemSolvingScore, $compositeScore, $userId]);
            } catch (Exception $e) {
                $updateFallback = $pdo->prepare(
                    'UPDATE aggregated_stats
                     SET total_problems_solved = ?,
                         total_contests = ?,
                         highest_platform_id = ?,
                         composite_score = ?,
                         last_updated = NOW()
                     WHERE user_id = ?'
                );
                $updateFallback->execute([$totalProblems, $totalContests, $highestPlatformId, $compositeScore, $userId]);
            }
        }
    }
}

/**
 * Ensures user is authenticated.
 */
if (!function_exists('require_auth')) {
    function require_auth(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['user_id'])) {
            header("Location: login.php");
            exit;
        }
    }
}

/**
 * Ensures authenticated user has 'mentor' role.
 */
if (!function_exists('require_mentor')) {
    function require_mentor(): void {
        require_auth();
        $role = $_SESSION['user_role'] ?? 'student';
        if ($role !== 'mentor') {
            http_response_code(403);
            header("Location: dashboard.php?error=unauthorized_mentor");
            exit;
        }
    }
}

/**
 * Ensures authenticated user has 'student' role. Mentors are redirected to mentor dashboard.
 */
if (!function_exists('require_student')) {
    function require_student(): void {
        require_auth();
        $role = $_SESSION['user_role'] ?? 'student';
        if ($role === 'mentor') {
            header("Location: mentor_dashboard.php");
            exit;
        }
    }
}

/**
 * Validates mentor-to-student assignment ownership to prevent IDOR vulnerabilities.
 */
if (!function_exists('is_student_assigned_to_mentor')) {
    function is_student_assigned_to_mentor(PDO $pdo, int $mentorId, int $studentId): bool {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM mentor_student_assignments 
            WHERE mentor_id = ? AND student_id = ? AND status = 'accepted'
        ");
        $stmt->execute([$mentorId, $studentId]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

/**
 * Fetches all accepted students assigned to a mentor with summary statistics.
 */
if (!function_exists('get_mentor_assigned_students')) {
    function get_mentor_assigned_students(PDO $pdo, int $mentorId): array {
        $stmt = $pdo->prepare("
            SELECT 
                u.id, 
                u.name, 
                u.email, 
                u.join_date,
                msa.invited_at,
                msa.responded_at,
                COALESCE(ast.total_problems_solved, 0) AS total_problems_solved,
                COALESCE(ast.total_contests, 0) AS total_contests,
                COALESCE(ast.composite_score, 0.0) AS composite_score,
                COALESCE(ast.consistency_score, 0.0) AS consistency_score,
                COALESCE(ast.problem_solving_score, 0.0) AS problem_solving_score,
                ast.last_updated
            FROM mentor_student_assignments msa
            JOIN users u ON msa.student_id = u.id
            LEFT JOIN aggregated_stats ast ON u.id = ast.user_id
            WHERE msa.mentor_id = ? AND msa.status = 'accepted'
            ORDER BY u.name ASC
        ");
        $stmt->execute([$mentorId]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Attach platform profiles for each student
        $profileStmt = $pdo->prepare("
            SELECT upp.*, p.platform_name 
            FROM user_platform_profiles upp
            JOIN platforms p ON upp.platform_id = p.id
            WHERE upp.user_id = ?
        ");

        foreach ($students as &$student) {
            $profileStmt->execute([$student['id']]);
            $profiles = $profileStmt->fetchAll(PDO::FETCH_ASSOC);
            $indexed = [];
            foreach ($profiles as $prof) {
                $indexed[$prof['platform_name']] = $prof;
            }
            $student['platforms'] = $indexed;
        }
        unset($student);

        return $students;
    }
}

/**
 * Fetches pending invitations sent by this mentor.
 */
if (!function_exists('get_mentor_pending_invitations')) {
    function get_mentor_pending_invitations(PDO $pdo, int $mentorId): array {
        $stmt = $pdo->prepare("
            SELECT 
                msa.id AS assignment_id,
                msa.status,
                msa.invited_at,
                u.id AS student_id,
                u.name AS student_name,
                u.email AS student_email
            FROM mentor_student_assignments msa
            JOIN users u ON msa.student_id = u.id
            WHERE msa.mentor_id = ? AND msa.status = 'pending'
            ORDER BY msa.invited_at DESC
        ");
        $stmt->execute([$mentorId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/**
 * Fetches pending invitations received by a student.
 */
if (!function_exists('get_student_mentor_invitations')) {
    function get_student_mentor_invitations(PDO $pdo, int $studentId): array {
        $stmt = $pdo->prepare("
            SELECT 
                msa.id AS assignment_id,
                msa.invited_at,
                m.id AS mentor_id,
                m.name AS mentor_name,
                m.email AS mentor_email
            FROM mentor_student_assignments msa
            JOIN users m ON msa.mentor_id = m.id
            WHERE msa.student_id = ? AND msa.status = 'pending'
            ORDER BY msa.invited_at DESC
        ");
        $stmt->execute([$studentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/**
 * Fetches assigned mentors for a student.
 */
if (!function_exists('get_student_assigned_mentors')) {
    function get_student_assigned_mentors(PDO $pdo, int $studentId): array {
        $stmt = $pdo->prepare("
            SELECT 
                msa.id AS assignment_id,
                msa.responded_at,
                m.id AS mentor_id,
                m.name AS mentor_name,
                m.email AS mentor_email
            FROM mentor_student_assignments msa
            JOIN users m ON msa.mentor_id = m.id
            WHERE msa.student_id = ? AND msa.status = 'accepted'
            ORDER BY m.name ASC
        ");
        $stmt->execute([$studentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/**
 * Generates a tamper-proof HMAC-SHA256 authenticated token for serverless API calls.
 */
if (!function_exists('generate_user_auth_token')) {
    function generate_user_auth_token(int $userId): string {
        $secret = function_exists('env_val') ? env_val('APP_SECRET', 'unified_cp_hmac_secret_2026_tidb_key') : 'unified_cp_hmac_secret_2026_tidb_key';
        $payload = base64_encode(json_encode([
            'uid' => $userId,
            'ts'  => time(),
        ]));
        $sig = hash_hmac('sha256', $payload, $secret);
        return $payload . '.' . $sig;
    }
}

/**
 * Validates an HMAC-SHA256 authenticated token and returns the verified user ID or null.
 */
if (!function_exists('verify_user_auth_token')) {
    function verify_user_auth_token(string $token): ?int {
        if ($token === '') return null;
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) return null;
        list($payload, $sig) = $parts;
        $secret = function_exists('env_val') ? env_val('APP_SECRET', 'unified_cp_hmac_secret_2026_tidb_key') : 'unified_cp_hmac_secret_2026_tidb_key';
        $expectedSig = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expectedSig, $sig)) return null;

        $decoded = base64_decode($payload, true);
        if ($decoded === false) return null;
        $data = json_decode($decoded, true);
        if (!is_array($data) || empty($data['uid']) || empty($data['ts'])) return null;

        // Valid for 14 days
        if ((time() - (int)$data['ts']) > 86400 * 14) return null;

        return (int)$data['uid'];
    }
}
