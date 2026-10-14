<?php
/**
 * tests/test_ai_coach.php
 * Verification script for AI Coach service and student context retrieval
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../services/ai_coach_service.php';

echo "=== Testing AI Coach Service with Modular Structure ===\n";

if ($pdo === null) {
    echo "FAIL: Database connection is null: " . ($dbConnectionError ?? 'Unknown error') . "\n";
    exit(1);
}

// Find a student account (e.g. 30001 Nitish Sahani or first student)
$stmt = $pdo->query("SELECT id, name, email FROM users WHERE role = 'student' LIMIT 1");
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    echo "INFO: No student found. Creating temporary test student...\n";
    $email = 'test_student_' . time() . '@example.com';
    $pdo->prepare("INSERT INTO users (name, email, password_hash, role) VALUES ('Test Student', ?, 'hash', 'student')")
        ->execute([$email]);
    $studentId = (int)$pdo->lastInsertId();
} else {
    $studentId = (int)$student['id'];
}

echo "Testing student context for ID: $studentId (" . ($student['name'] ?? 'Test Student') . ")...\n";

try {
    $context = get_student_ai_context($pdo, $studentId);
    echo "SUCCESS: Retrieved AI context:\n";
    echo " - Student Name: " . ($context['student']['name'] ?? 'N/A') . "\n";
    echo " - Total Problems Solved: " . $context['stats']['total_problems_solved'] . "\n";
    echo " - Total Contests: " . $context['stats']['total_contests'] . "\n";
    echo " - Composite Score: " . $context['stats']['composite_score'] . "\n";
    echo " - Consistency Score: " . $context['stats']['consistency_score'] . "\n";
    echo " - Problem Solving Score: " . $context['stats']['problem_solving_score'] . "\n";
    echo " - Platforms Attached: " . implode(', ', array_keys(array_filter($context['platforms']))) . "\n";

    echo "ALL AI COACH SERVICE TESTS PASSED.\n";
} catch (Exception $e) {
    echo "FAIL: Exception in AI coach service: " . $e->getMessage() . "\n";
    exit(1);
}
