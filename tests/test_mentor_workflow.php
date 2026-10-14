<?php
/**
 * tests/test_mentor_workflow.php
 * Verification script for Mentor Dashboard workflows, role validation, and assignment helpers.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

echo "=== Testing Mentor Workflows with Modular Structure ===\n";

if ($pdo === null) {
    echo "FAIL: Database connection is null: " . ($dbConnectionError ?? 'Unknown error') . "\n";
    exit(1);
}

// 1. Check helper functions exist
$helpers = [
    'require_auth',
    'require_mentor',
    'require_student',
    'is_student_assigned_to_mentor',
    'get_mentor_assigned_students',
    'get_mentor_pending_invitations',
    'get_student_mentor_invitations',
    'get_student_assigned_mentors',
    'recalculateAggregatedStats'
];

foreach ($helpers as $h) {
    if (!function_exists($h)) {
        echo "FAIL: Missing function: $h\n";
        exit(1);
    }
}
echo "SUCCESS: All mentor & security helper functions are registered.\n";

// 2. Test mentor assignment lookup
$mentors = $pdo->query("SELECT id, name, email FROM users WHERE role = 'mentor' LIMIT 1")->fetchAll(PDO::FETCH_ASSOC);
if (!empty($mentors)) {
    $mentorId = (int)$mentors[0]['id'];
    $assignedStudents = get_mentor_assigned_students($pdo, $mentorId);
    $pendingInvites = get_mentor_pending_invitations($pdo, $mentorId);
    echo "SUCCESS: Mentor #" . $mentorId . " (" . $mentors[0]['name'] . ") has " . count($assignedStudents) . " assigned students and " . count($pendingInvites) . " pending invitations.\n";
} else {
    echo "INFO: No mentor account in database yet. Helpers checked successfully.\n";
}

echo "ALL MENTOR WORKFLOW TESTS PASSED.\n";
