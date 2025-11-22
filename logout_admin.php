<?php
session_start();
include 'db.php';

if (isset($_SESSION['admin_login_id'])) {

    $login_id = $_SESSION['admin_login_id'];

    $conn->query("
        UPDATE admin
        SET status='offline', last_online=NOW()
        WHERE login_id = {$login_id}
    ");

    unset($_SESSION['admin_login_id'], $_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_position']);
}

header("Location: index.php");
exit();
?>
