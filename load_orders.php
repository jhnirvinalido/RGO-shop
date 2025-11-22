<?php
session_start();
require 'db.php';
$conn = $db->getConnection();

$student_id = $_SESSION['student_id'];

$q = $conn->prepare("
SELECT 
    o.orderid,
    o.order_num,
    o.transaction_num,
    o.order_date,
    p.payment_status,
    p.verify_status,
    p.amount
FROM orders_info o
LEFT JOIN payment p ON p.order_id = o.orderid
WHERE o.student_id = ?
ORDER BY o.orderid DESC
");
$q->bind_param("i", $student_id);
$q->execute();

$res = $q->get_result();
$data = [];
while ($row = $res->fetch_assoc()) $data[] = $row;

echo json_encode($data);
?>
