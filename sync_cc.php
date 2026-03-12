<?php
if (!isset($pdo, $user_id, $handle, $platform_id, $profile_id)) {
    return;
}

if (!function_exists('codechefClassifyDifficulty')) {
    function codechefClassifyDifficulty(?int $rating, ?string $label = null): ?string
    {
        if ($label !== null && $label !== '') {
            $normalized = strtolower($label);
            if (in_array($normalized, ['easy', 'medium', 'hard'], true)) {
                return ucfirst($normalized);
            }
        }

        if ($rating === null) {
            return null;
        }

        if ($rating < 1400) {
            return 'Easy';
        }

        if ($rating < 2000) {
            return 'Medium';
        }

        return 'Hard';
    }
}

if (!function_exists('codechefFetchProblemDifficulty')) {
    function codechefFetchProblemDifficulty(string $problemCode): ?string
    {
        static $cache = [];

        $upperCode = strtoupper($problemCode);
        if (array_key_exists($upperCode, $cache)) {
            return $cache[$upperCode];
        }

        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 5,
                'header'  => "User-Agent: UnifiedCPBot/1.0\r\nAccept: application/json\r\n",
            ],
        ]);

        $apiUrl = "https://www.codechef.com/api/contests/PRACTICE/problems/" . urlencode($upperCode);
        $json   = @file_get_contents($apiUrl, false, $context);

        if (!$json) {
            $cache[$upperCode] = null;
            return null;
        }

        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            $cache[$upperCode] = null;
            return null;
        }

        $data = $payload['data'] ?? ($payload['result']['data'] ?? $payload);

        $label  = $data['difficulty'] ?? ($data['difficulty_label'] ?? null);
        $rating = null;

        if (isset($data['difficulty_rating'])) {
            $rating = (int)$data['difficulty_rating'];
        } elseif (isset($data['difficulty-details']['difficulty_rating'])) {
            $rating = (int)$data['difficulty-details']['difficulty_rating'];
        }

        $difficulty = codechefClassifyDifficulty($rating, is_string($label) ? $label : null);
        $cache[$upperCode] = $difficulty;

        return $difficulty;
    }
}

$userId = (int)$user_id;

$html = @file_get_contents("https://www.codechef.com/users/" . urlencode($handle));
if (!$html) {
    return;
}

// Parse the HTML once so we can extract problem stats reliably.
libxml_use_internal_errors(true);
$doc = new DOMDocument();
$loaded = $doc->loadHTML($html);
libxml_clear_errors();
$xpath = $loaded ? new DOMXPath($doc) : null;

// Grabs the rating block shown on the public profile.
preg_match('/class="rating-number">([0-9]+)/', $html, $currentMatch);
$currentRating = isset($currentMatch[1]) ? (int)$currentMatch[1] : 0;

preg_match('/title="Highest Rating">([0-9]+)/', $html, $maxMatch);
$maxRating = isset($maxMatch[1]) ? (int)$maxMatch[1] : $currentRating;

// CodeChef represents rank via star count; derive a readable badge for the dashboard.
if ($currentRating >= 2500) {
    $starCount = 7;
} elseif ($currentRating >= 2200) {
    $starCount = 6;
} elseif ($currentRating >= 2000) {
    $starCount = 5;
} elseif ($currentRating >= 1800) {
    $starCount = 4;
} elseif ($currentRating >= 1600) {
    $starCount = 3;
} elseif ($currentRating >= 1400) {
    $starCount = 2;
} elseif ($currentRating >= 1) {
    $starCount = 1;
} else {
    $starCount = 0;
}

$rankTitleBase = $starCount > 0 ? str_repeat('⭐', $starCount) : 'Unrated';

// Parse the problems solved summary (practice + contest fully solved entries).
$problemsSolved = 0;
$difficultyBreakdown = [
    'easy'   => 0,
    'medium' => 0,
    'hard'   => 0,
];
if ($xpath) {
    $countNodes = $xpath->query(
        "//section[contains(concat(' ', normalize-space(@class), ' '), ' problems-solved ')]//p[contains(translate(., 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'fully solved')]//span"
    );

    foreach ($countNodes as $node) {
        if (preg_match('/([0-9,]+)/', $node->textContent, $digits)) {
            $raw   = isset($digits[1]) ? $digits[1] : $digits[0];
            $value = (int)str_replace(',', '', $raw);
            $problemsSolved += $value;
        }
    }

    // Some layouts render the counts as badges instead of paragraphs.
    if ($problemsSolved === 0) {
        $badgeNodes = $xpath->query(
            "//section[contains(concat(' ', normalize-space(@class), ' '), ' problems-solved ')]//*[contains(translate(., 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'fully solved')]//span"
        );

        foreach ($badgeNodes as $node) {
            if (preg_match('/([0-9,]+)/', $node->textContent, $digits)) {
                $raw   = isset($digits[1]) ? $digits[1] : $digits[0];
                $value = (int)str_replace(',', '', $raw);
                $problemsSolved += $value;
            }
        }
    }
}

if ($xpath) {
    $sectionNodes = $xpath->query("//section[contains(concat(' ', normalize-space(@class), ' '), ' problems-solved ')]");
    foreach ($sectionNodes as $sectionNode) {
        $sectionText = preg_replace('/\s+/', ' ', $sectionNode->textContent);
        if (!$sectionText) {
            continue;
        }

        if (preg_match_all('/\b(Easy|Medium|Hard)\b[^0-9]*([0-9,]+)/i', $sectionText, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $bucket = strtolower($match[1]);
                $value  = (int)str_replace(',', '', $match[2]);
                if (isset($difficultyBreakdown[$bucket])) {
                    $difficultyBreakdown[$bucket] = max($difficultyBreakdown[$bucket], $value);
                }
            }
        }
    }
}

// Fallback to legacy regex if DOM parsing failed to locate counts.
if ($problemsSolved === 0 && preg_match_all('/Fully\s+Solved\s*\([^)]*\)\s*<span>([0-9]+)/i', $html, $fullyMatches)) {
    foreach ($fullyMatches[1] as $value) {
        $problemsSolved += (int)str_replace(',', '', $value);
    }
}

// Try a plain-text fallback in case markup changed substantially.
if ($problemsSolved === 0) {
    $textOnly = preg_replace('/\s+/', ' ', strip_tags($html));
    if ($textOnly && preg_match_all('/Fully\s+Solved[^0-9]*([0-9,]+)/i', $textOnly, $textMatches)) {
        foreach ($textMatches[1] as $value) {
            $problemsSolved += (int)str_replace(',', '', $value);
        }
    }
}

// As a final fallback, inspect embedded JSON blocks that expose fully solved stats.
if (preg_match('/"fullySolved"\s*:\s*\{([^}]*)\}/i', $html, $fullyJsonMatch)) {
    $pairs = explode(',', $fullyJsonMatch[1]);
    foreach ($pairs as $pair) {
        if (preg_match('/"(Easy|Medium|Hard|Practice|Contest)"\s*:\s*([0-9,]+)/i', $pair, $valueMatch)) {
            $value = (int)str_replace(',', '', $valueMatch[2]);
            $label = strtolower($valueMatch[1]);

            if ($label === 'practice' || $label === 'contest') {
                if ($problemsSolved === 0) {
                    $problemsSolved += $value;
                }
                continue;
            }

            if (isset($difficultyBreakdown[$label])) {
                $difficultyBreakdown[$label] = max($difficultyBreakdown[$label], $value);
            }
        } elseif ($problemsSolved === 0 && preg_match('/:\s*([0-9,]+)/', $pair, $totalMatch)) {
            $problemsSolved += (int)str_replace(',', '', $totalMatch[1]);
        }
    }
}

// Capture up to the latest 5 problems from the Fully Solved section for activity view.
$recentProblems = [];
$allSolvedCodes = [];
if ($xpath) {
    $linkNodes = $xpath->query("//section[contains(concat(' ', normalize-space(@class), ' '), ' problems-solved ')]//a[contains(@href, '/problems/')]");
    $seenCodes = [];
    foreach ($linkNodes as $linkNode) {
        $href = $linkNode->getAttribute('href');
        $label = trim($linkNode->nodeValue);
        if ($href === '') {
            continue;
        }

        if (!preg_match('#/problems/([^/]+)#i', $href, $matches)) {
            continue;
        }

        $code = strtoupper($matches[1]);

        $allSolvedCodes[$code] = true;

        if (isset($seenCodes[$code]) || count($recentProblems) >= 5) {
            continue;
        }

        $seenCodes[$code] = true;
        $title = $label !== '' ? $label : $code;

        $difficultyLabel = codechefFetchProblemDifficulty($code) ?? null;
        if ($difficultyLabel === null) {
            $difficultyLabel = 'Medium';
        }

        $recentProblems[] = [
            'code'       => $code,
            'title'      => $title,
            'difficulty' => $difficultyLabel,
            'topic'      => null,
            'solved_at'  => date('Y-m-d H:i:s', time() - count($recentProblems)),
        ];

    }
}

// Fallback to regex parsing if DOM path returned nothing.
if (empty($recentProblems) && preg_match('/Fully\s+Solved.*?<article[^>]*>(.*?)<\/article>/is', $html, $solvedSection)) {
    if (preg_match_all('/<a[^>]*href="\/problems\/([^"\/#]+)[^>]*>([^<]+)<\/a>/i', $solvedSection[1], $problemLinks)) {
        $count = count($problemLinks[1]);
        for ($i = 0; $i < $count; $i++) {
            if (count($recentProblems) >= 5) {
                break;
            }
            $code = strtoupper(trim($problemLinks[2][$i] ?: $problemLinks[1][$i]));
            $difficultyLabel = codechefFetchProblemDifficulty($code) ?? 'Medium';
            $recentProblems[] = [
                'code'       => $code,
                'title'      => $code,
                'difficulty' => $difficultyLabel,
                'topic'      => null,
                'solved_at'  => date('Y-m-d H:i:s', time() - $i),
            ];
            $allSolvedCodes[$code] = true;
        }
    }
}

if ($problemsSolved === 0 && !empty($allSolvedCodes)) {
    $problemsSolved = count($allSolvedCodes);
}

if (array_sum($difficultyBreakdown) === 0 && !empty($recentProblems)) {
    foreach ($recentProblems as $problem) {
        $bucket = strtolower($problem['difficulty']);
        if (isset($difficultyBreakdown[$bucket])) {
            $difficultyBreakdown[$bucket]++;
        }
    }
}

$rankTitle = $rankTitleBase;
if (array_sum($difficultyBreakdown) > 0) {
    $rankTitle .= sprintf(' | E:%d M:%d H:%d',
        $difficultyBreakdown['easy'],
        $difficultyBreakdown['medium'],
        $difficultyBreakdown['hard']
    );
}

// Update the profile snapshot with the latest ratings and problem totals.
$pdo->prepare(
    'UPDATE user_platform_profiles
     SET rank_title = ?, current_rating = ?, max_rating = ?, problems_solved = ?, last_sync_date = NOW()
     WHERE id = ?'
)->execute([
    $rankTitle,
    $currentRating,
    $maxRating,
    $problemsSolved,
    $profile_id,
]);

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

// Extract rated contest history rows and upsert contest metadata + performance.
if (preg_match_all('/<tr[^>]*class="rating-data-row[^>]*">(.*?)<\/tr>/s', $html, $rowMatches)) {
    $contestLookup = $pdo->prepare('SELECT id FROM contests WHERE platform_id = ? AND name = ? LIMIT 1');
    $contestInsert = $pdo->prepare('INSERT INTO contests (platform_id, name, start_time) VALUES (?, ?, ?)');
    $performanceUpsert = $pdo->prepare(
        'INSERT INTO contest_performance (profile_id, contest_id, contest_rank, rating_change)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE contest_rank = VALUES(contest_rank), rating_change = VALUES(rating_change)'
    );

    foreach ($rowMatches[1] as $rowHtml) {
        if (!preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $rowHtml, $columns) || count($columns[1]) < 3) {
            continue;
        }

        $cells = array_map(static function ($fragment) {
            return trim(strip_tags(html_entity_decode($fragment, ENT_QUOTES | ENT_HTML5)));
        }, $columns[1]);

        $contestName = $cells[0] ?? '';
        $contestDateRaw = $cells[1] ?? '';
        $rankValue = isset($cells[2]) ? (int)preg_replace('/[^0-9]/', '', $cells[2]) : 0;
        $ratingChangeRaw = $cells[4] ?? '0';
        $ratingChange = (int)preg_replace('/[^0-9\-]/', '', $ratingChangeRaw);

        if ($contestName === '' || $rankValue <= 0) {
            continue;
        }

        $timestamp = strtotime($contestDateRaw);
        $startTime = $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;

        $contestLookup->execute([$platform_id, $contestName]);
        $contestId = $contestLookup->fetchColumn();

        if ($contestId === false) {
            $contestInsert->execute([$platform_id, $contestName, $startTime]);
            $contestId = (int)$pdo->lastInsertId();
        } else {
            $contestId = (int)$contestId;
        }

        if ($contestId <= 0) {
            continue;
        }

        $performanceUpsert->execute([$profile_id, $contestId, $rankValue, $ratingChange]);
    }
}

recalculateAggregatedStats($pdo, $userId);
