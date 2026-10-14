<?php
/**
 * tests/test_auth_token.php
 * Verification script for HMAC token generation and API authentication
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

echo "=== Testing HMAC Auth Token Generation & Verification ===\n";

$testUid = 30001;
$token = generate_user_auth_token($testUid);

echo "Generated token: $token\n";

$verifiedUid = verify_user_auth_token($token);
if ($verifiedUid === $testUid) {
    echo "SUCCESS: Token verified correctly for user #$testUid.\n";
} else {
    echo "FAIL: Expected $testUid, got " . var_export($verifiedUid, true) . "\n";
    exit(1);
}

// Test tampering detection
$tampered = $token . 'tamper';
if (verify_user_auth_token($tampered) === null) {
    echo "SUCCESS: Tampered token correctly rejected.\n";
} else {
    echo "FAIL: Tampered token was accepted!\n";
    exit(1);
}

echo "ALL AUTH TOKEN TESTS PASSED.\n";
