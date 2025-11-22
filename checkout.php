<?php
session_start();
require "db.php";

$conn = $db->getConnection();

// SESSION CHECK
$student_id = $_SESSION['student_id'] ?? null;
$login_id   = $_SESSION['login_id'] ?? null;

if (!$student_id || !$login_id) {
    echo json_encode(["status" => "ERR_SESSION"]);
    exit;
}

// ------------------------------------------
// FETCH STUDENT INFO (FIXED COLUMN NAMES)
// ------------------------------------------
$s = $conn->prepare("SELECT fullname, sr_code, student_phone_number FROM students WHERE id = ?");
$s->bind_param("i", $student_id);
$s->execute();
$s->bind_result($stud_name, $stud_srcode, $stud_phone);
$s->fetch();
$s->close();

// ------------------------------------------
// RECEIVE CART DATA
// ------------------------------------------
$cart  = json_decode($_POST['cart'] ?? "[]", true);
$total = floatval($_POST['total'] ?? 0);

if (!$cart) {
    echo json_encode(["status" => "ERR_EMPTY_CART"]);
    exit;
}

// ------------------------------------------
// GENERATE ORDER NUMBERS
// ------------------------------------------
$order_num       = "ORD-" . strtoupper(uniqid());
$transaction_num = "TX-" . strtoupper(uniqid());

// ------------------------------------------
// INSERT ORDER
// ------------------------------------------
$q = $conn->prepare("
INSERT INTO orders_info (student_id, login_id, order_num, transaction_num)
VALUES (?, ?, ?, ?)
");
$q->bind_param("iiss", $student_id, $login_id, $order_num, $transaction_num);
$q->execute();
$order_id = $q->insert_id;
$q->close();

// ------------------------------------------
// CREATE order_items TABLE IF NOT EXISTS
// ------------------------------------------
$conn->query("
CREATE TABLE IF NOT EXISTS order_items (
    item_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    quantity INT NOT NULL,
    price DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders_info(orderid)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ------------------------------------------
// INSERT CART ITEMS
// ------------------------------------------
foreach ($cart as $c) {
    $qi = $conn->prepare("
        INSERT INTO order_items (order_id, product_name, quantity, price)
        VALUES (?, ?, ?, ?)
    ");
    $qi->bind_param("isid", $order_id, $c['name'], $c['qty'], $c['price']);
    $qi->execute();
    $qi->close();
}

// ------------------------------------------
// PAYMENT PLACEHOLDER (REQUIRED FOR UPDATE)
// ------------------------------------------
$placeholder = $conn->prepare("
INSERT INTO payment (order_id, student_id, login_id, amount, payment_status, verify_status)
VALUES (?, ?, ?, ?, 'PENDING', 'UNREVIEWED')
");
$placeholder->bind_param("iiid", $order_id, $student_id, $login_id, $total);
$placeholder->execute();
$placeholder->close();

// ------------------------------------------
// SEND RESPONSE TO JAVASCRIPT
// ------------------------------------------
echo json_encode([
    "status"          => "ok",
    "order_id"        => $order_id,
    "order_num"       => $order_num,
    "transaction_num" => $transaction_num,
    "total"           => $total,
    "stud_name"       => $stud_name,
    "stud_srcode"     => $stud_srcode,
    "stud_phone"      => $stud_phone
]);

?>
