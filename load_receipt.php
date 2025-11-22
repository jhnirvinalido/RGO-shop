<?php
require 'db.php';
$conn = $db->getConnection();

$order_id = (int)$_GET['order_id'];

$q = $conn->prepare("
SELECT 
    o.order_num,
    o.transaction_num,
    p.amount,
    p.payment_no,
    p.reference_no,
    p.payment_method,
    p.payment_date,
    p.payment_status,
    p.verify_status,
    p.proof_image
FROM orders_info o
JOIN payment p ON p.order_id = o.orderid
WHERE o.orderid = ?
");
$q->bind_param("i", $order_id);
$q->execute();
$r = $q->get_result()->fetch_assoc();
?>

<div>
    <h3 class="text-center mb-2">Payment Receipt</h3>
    <p><b>Order #:</b> <?= $r['order_num'] ?></p>
    <p><b>Transaction #:</b> <?= $r['transaction_num'] ?></p>
    <p><b>Payment No:</b> <?= $r['payment_no'] ?></p>
    <p><b>Amount:</b> ₱<?= number_format($r['amount'],2) ?></p>
    <p><b>Reference #:</b> <?= $r['reference_no'] ?></p>
    <p><b>Method:</b> <?= strtoupper($r['payment_method']) ?></p>
    <p><b>Status:</b> <?= $r['payment_status'] ?> (<?= $r['verify_status'] ?>)</p>

    <h5 class="mt-3">Proof of Payment:</h5>
    <?php if ($r['proof_image']): ?>
        <img src="data:image/jpeg;base64,<?= base64_encode($r['proof_image']) ?>" 
             class="img-fluid rounded">
    <?php else: ?>
        <p class="text-danger">No image uploaded.</p>
    <?php endif; ?>
</div>
