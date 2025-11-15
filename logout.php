<?php
session_start();
include 'db.php';

if (isset($_SESSION['student_id'])) {

    $id = $_SESSION['student_id'];

    $conn->query("
        UPDATE students
        SET status='offline', last_online=NOW()
        WHERE id = {$id}
    ");

    unset($_SESSION['student_id'], $_SESSION['fullname'], $_SESSION['sr_code'], $_SESSION['course']);
}

header("Location: UserLogin.php");
exit();
?>
