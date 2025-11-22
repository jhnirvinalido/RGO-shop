<?php
session_start();
include 'db.php';
require 'vendor/autoload.php';

use OTPHP\TOTP;

header("Content-Type: application/json");

$student_id = $_SESSION['student_id'] ?? 0;
if (!$student_id) {
    echo json_encode(["ok" => false, "msg" => "Not logged in"]);
    exit;
}

$code = trim($_POST['code'] ?? '');

$stmt = $conn->prepare("SELECT twofa_secret FROM students WHERE id=? LIMIT 1");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$stmt->bind_result($secret);
$stmt->fetch();
$stmt->close();

if (!$secret) {
    echo json_encode(["ok" => false, "msg" => "No secret stored"]);
    exit;
}

$totp = TOTP::create($secret);

// 🔥 FIX: allow time drift (±2 steps)
if ($totp->verify($code, null, 2)) {

    $stmt = $conn->prepare("UPDATE students SET twofa_verified=1 WHERE id=?");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();

    echo json_encode(["ok" => true]);
} else {
    echo json_encode(["ok" => false, "msg" => "Invalid code (time mismatch)"]);
}
