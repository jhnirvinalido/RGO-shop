<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
include 'db.php';

require __DIR__ . '/vendor/autoload.php';
use OTPHP\TOTP;

header("Content-Type: application/json");
ob_clean();

$student_id = $_SESSION['student_id'] ?? 0;
if (!$student_id) {
    echo json_encode(["ok" => false, "msg" => "Not logged in"]);
    exit;
}

$issuer = "RGO University Shop";
$label  = "Student-" . $student_id;

// Generate TOTP
$totp = TOTP::create();
$totp->setLabel($label);
$totp->setIssuer($issuer);

$secret = $totp->getSecret();
$uri = $totp->getProvisioningUri();

// URL that loads QR from the image script
$qr_url = "twofa_qr_image.php?uri=" . urlencode($uri);

// Save secret in DB
$stmt = $conn->prepare("UPDATE students SET twofa_secret=?, twofa_verified=0 WHERE id=?");
$stmt->bind_param("si", $secret, $student_id);
$stmt->execute();

echo json_encode([
    "ok" => true,
    "secret" => $secret,
    "qr"     => $qr_url,
    "uri"    => $uri
]);
exit;
