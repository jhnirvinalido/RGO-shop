<?php
session_start();
include 'db.php';

if (isset($_SESSION['student_id'])) {
    $id = $_SESSION['student_id'];
    $conn->query("UPDATE students SET status='online' WHERE id=$id");
}

if (isset($_SESSION['admin_id'])) {
    $id = $_SESSION['admin_id'];
    $conn->query("UPDATE admin SET status='online' WHERE admin_id=$id");
}
?>
