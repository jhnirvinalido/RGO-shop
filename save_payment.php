<?php
session_start();
require 'db.php';

$conn = $db->getConnection();

$order_id   = $_POST['order_id'] ?? null;
$reference  = $_POST['reference'] ?? '';
$amount     = $_POST['amount'] ?? 0;

$student_id = $_SESSION['student_id'] ?? null;
$login_id   = $_SESSION['login_id'] ?? null;

if (!$order_id || !$student_id || !$login_id) {
    echo "ERR_SESSION";
    exit;
}

// ------------ GENERATE PAYMENT NO ------------
$payment_no = "PM-" . time() . "-" . rand(1000,9999);

// ------------ HANDLE IMAGE ------------
$proofBlob = null;
if (isset($_FILES['proof']) && !empty($_FILES['proof']['tmp_name'])) {
    $proofBlob = file_get_contents($_FILES['proof']['tmp_name']);
}

// --------------------------------------------------
// 1. CHECK IF PAYMENT ROW EXISTS
// --------------------------------------------------
$chk = $conn->prepare("SELECT payment_id FROM payment WHERE order_id=?");
$chk->bind_param("i", $order_id);
$chk->execute();
$chk->store_result();

if ($chk->num_rows == 0) {
    // --------------------------------------------------
    // INSERT PAYMENT ROW FIRST
    // --------------------------------------------------
    $stmt = $conn->prepare("
        INSERT INTO payment (
            order_id, student_id, login_id, amount,
            payment_method, payment_no, reference_no, proof_image,
            payment_status, verify_status
        ) VALUES (?, ?, ?, ?, 'gcash', ?, ?, ?, 'PAID', 'UNREVIEWED')
    ");

    $stmt->bind_param(
        "iiisssb",
        $order_id,
        $student_id,
        $login_id,
        $amount,
        $payment_no,
        $reference,
        $proofBlob
    );

} else {
    // --------------------------------------------------
    // UPDATE EXISTING PAYMENT ROW
    // --------------------------------------------------
    $stmt = $conn->prepare("
        UPDATE payment SET 
            amount=?,
            reference_no=?,
            proof_image=?,
            payment_method='gcash',
            payment_no=?,
            payment_status='PAID',
            verify_status='UNREVIEWED'
        WHERE order_id=? AND student_id=? AND login_id=?
    ");

    $stmt->bind_param(
        "dsssiii",
        $amount,
        $reference,
        $proofBlob,
        $payment_no,
        $order_id,
        $student_id,
        $login_id
    );
}

if ($stmt->execute()) {
    echo "OK";
} else {
    echo "ERR_SQL";
}

$stmt->close();
?>
