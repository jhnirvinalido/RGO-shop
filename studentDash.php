<?php
session_start();
include 'db.php';

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/httpsms_otp.log');


$HTTPSMS_API_KEY = 'uk_ZLH-PGU8dibwWwLcMYEK18ijXOBvURsB6SnzHP-O7rBIQR31Gq-uNMomtfseAVtr';
$HTTPSMS_FROM = '+639664686314';

$STATIC_QR_IMAGES = [
    'qr-1.jpg',
    'qr-2.jpg',
    'qr-3.jpg',
];
try {
    // Items inside an order
    @$conn->query("CREATE TABLE IF NOT EXISTS order_items (
        order_item_id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        item_id INT NOT NULL,
        size_label VARCHAR(50) NOT NULL,
        quantity INT NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL,
        total_price DECIMAL(10,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (order_id),
       CONSTRAINT fk_order_items_order
    FOREIGN KEY (order_id) REFERENCES orders_info(id)
    ON DELETE CASCADE

    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    error_log('ORDER_ITEMS CREATE TABLE ERR: ' . $e->getMessage());
}
try {
    // Create chat_messages table if missing (safe to run repeatedly)
    @$conn->query("CREATE TABLE IF NOT EXISTS chat_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        sender ENUM('student','admin') NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (student_id),
        INDEX (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    error_log('CHAT CREATE TABLE ERR: ' . $e->getMessage());
}

// Send a chat message (from student)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'chat_send') {
    header('Content-Type: application/json');
    $sid = isset($_SESSION['student_id']) ? (int) $_SESSION['student_id'] : 0;
    if ($sid <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Not logged in.']);
        exit;
    }
    $msg = trim($_POST['message'] ?? '');
    if ($msg === '') {
        echo json_encode(['ok' => false, 'msg' => 'Message cannot be empty.']);
        exit;
    }
    try {
        $stmt = $conn->prepare("INSERT INTO chat_messages (student_id, sender, message) VALUES (?, 'student', ?)");
        $stmt->bind_param('is', $sid, $msg);
        $stmt->execute();
        $newId = $stmt->insert_id;
        $stmt->close();
        /* ⭐ AUTO OFFICE HOURS REPLY — ONLY IF IT'S THE FIRST MESSAGE ⭐ */

        $check = $conn->prepare("SELECT COUNT(*) FROM chat_messages WHERE student_id = ?");
        $check->bind_param("i", $sid);
        $check->execute();
        $check->bind_result($count);
        $check->fetch();
        $check->close();

        /* ⭐ AUTO OFFICE HOURS – ONLY ON FIRST STUDENT MESSAGE ⭐ */

        /* Count only STUDENT messages to detect REAL first message */
        $check = $conn->prepare("
    SELECT COUNT(*) 
    FROM chat_messages 
    WHERE student_id = ? AND sender = 'student'
");
        $check->bind_param("i", $sid);
        $check->execute();
        $check->bind_result($studentMsgCount);
        $check->fetch();
        $check->close();

        if ($studentMsgCount === 1) {

            $autoMsg =
                "Thank you for reaching out to us. We appreciate your message and we are here to assist you.\n" .
                "If there is no response within 30 minutes, it simply means the RGO is currently attending to other concerns.\n" .
                "Please leave your message, and we will get back to you as soon as possible.\n" .
                "Thank you for your patience and understanding.";


            $auto = $conn->prepare("
        INSERT INTO chat_messages (student_id, sender, message) 
        VALUES (?, 'admin', ?)
    ");
            $auto->bind_param("is", $sid, $autoMsg);
            $auto->execute();
            $auto->close();
        }



        echo json_encode(['ok' => true, 'message' => ['id' => $newId, 'sender' => 'student', 'message' => $msg]]);
    } catch (Throwable $e) {
        error_log('CHAT SEND ERR: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Failed to send.']);
    }
    exit;
}

// Fetch chat messages (for this student)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'chat_fetch') {
    header('Content-Type: application/json');
    $sid = isset($_SESSION['student_id']) ? (int) $_SESSION['student_id'] : 0;
    if ($sid <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Not logged in.']);
        exit;
    }
    $since_id = isset($_POST['since_id']) ? (int) $_POST['since_id'] : 0;
    try {
        if ($since_id > 0) {
            $stmt = $conn->prepare("SELECT id, sender, message, created_at
                                    FROM chat_messages
                                    WHERE student_id = ? AND id > ?
                                    ORDER BY id ASC
                                    LIMIT 200");
            $stmt->bind_param('ii', $sid, $since_id);
        } else {
            $stmt = $conn->prepare("SELECT id, sender, message, created_at
                                    FROM chat_messages
                                    WHERE student_id = ?
                                    ORDER BY id DESC
                                    LIMIT 50");
            $stmt->bind_param('i', $sid);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        $stmt->close();

        if ($since_id === 0) {
            $rows = array_reverse($rows);
        }
        echo json_encode(['ok' => true, 'messages' => $rows]);
    } catch (Throwable $e) {
        error_log('CHAT FETCH ERR: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Failed to fetch.']);
    }
    exit;
}
/* ============== END ADD ============== */


function sendHttpSms($apiKey, $from, $to, $message)
{
    $url = "https://api.httpsms.com/v1/messages/send";
    $payloadArr = [
        "from" => $from,     // must be the device's SIM number
        "to" => $to,       // recipient in E.164
        "content" => $message
    ];
    $payload = json_encode($payloadArr, JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);

    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $dbg = [
        'request_url' => $url,
        'request_from' => $from,
        'request_to' => $to,
        'http_code' => $code,
        'curl_error' => $err,
        'response_body' => $resp,
        'request_body' => $payloadArr
    ];
    error_log("HTTPSMS SEND DEBUG: " . json_encode($dbg));

    curl_close($ch);

    return ['http_code' => $code, 'error' => $err, 'body' => $resp];
}


function gen_digits($len)
{
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= (string) random_int(0, 9);
    }
    return $out;
}


function create_order_and_payment(mysqli $conn, int $student_id, ?int $item_id, float $amount, string $method = 'gcash', string $currency = 'PHP', ?string $notes = null)
{
    // Resolve login_id
    $login_id = isset($_SESSION['login_id']) ? (int) $_SESSION['login_id'] : 0;
    if ($login_id <= 0) {
        $q = $conn->prepare("
            SELECT sl.login_id
            FROM student_login sl
            INNER JOIN students s ON s.login_id = sl.login_id
            WHERE s.id = ?
            ORDER BY sl.login_id DESC
            LIMIT 1
        ");
        if ($q) {
            $q->bind_param("i", $student_id);
            $q->execute();
            $q->bind_result($found_login_id);
            if ($q->fetch()) {
                $login_id = (int)$found_login_id;
                $_SESSION['login_id'] = $login_id; // keep for future
            }
            $q->close();
        }
    }

    // Generate IDs
    $order_num = gen_digits(32);
    $transaction_num = gen_digits(64);
    $payment_no = gen_digits(32);

    // Begin transaction
    $conn->begin_transaction();
    try {
        // Insert into orders_info
        $sqlOrder = "INSERT INTO orders_info (student_id, login_id, order_num, transaction_num) VALUES (?,?,?,?)";
        $stmtOrder = $conn->prepare($sqlOrder);
        if (!$stmtOrder) {
            throw new Exception("Prepare order failed: " . $conn->error);
        }
        $stmtOrder->bind_param("iiss", $student_id, $login_id, $order_num, $transaction_num);
        if (!$stmtOrder->execute()) {
            throw new Exception("Execute order failed: " . $stmtOrder->error);
        }
        $order_id = (int) $stmtOrder->insert_id;
        $stmtOrder->close();

        // Insert into payment
        $sqlPay = "INSERT INTO payment (order_id, student_id, login_id, item_id, amount, currency, payment_method, payment_no, notes)
                   VALUES (?,?,?,?,?,?,?,?,?)";
        $stmtPay = $conn->prepare($sqlPay);
        if (!$stmtPay) {
            throw new Exception("Prepare payment failed: " . $conn->error);
        }
        // item_id can be NULL; amount must be >=0 per CHECK
        $item_id_param = $item_id ?: null;
        $notes_param = $notes ?: null;
        $stmtPay->bind_param(
            "iiiidssss",
            $order_id,
            $student_id,
            $login_id,
            $item_id_param,
            $amount,
            $currency,
            $method,
            $payment_no,
            $notes_param
        );
        if (!$stmtPay->execute()) {
            throw new Exception("Execute payment failed: " . $stmtPay->error);
        }
        $payment_id = (int) $stmtPay->insert_id;
        $stmtPay->close();

        $conn->commit();
        return [
            'ok' => true,
            'order_id' => $order_id,
            'payment_id' => $payment_id,
            'msg' => null,
            // Optional: return the generated numbers if you need them server-side
            'order_num' => $order_num,
            'transaction_num' => $transaction_num,
            'payment_no' => $payment_no
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("ORDER/PAYMENT TX ROLLBACK: " . $e->getMessage());
        return ['ok' => false, 'order_id' => null, 'payment_id' => null, 'msg' => $e->getMessage()];
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_otp') {
    header('Content-Type: application/json');
    $mobile = trim($_POST['mobile'] ?? '');
    $mobile = preg_replace('/\D+/', '', $mobile);
    if (strpos($mobile, '09') === 0) {
        $mobile = '+63' . substr($mobile, 1);
    } elseif (strpos($mobile, '639') === 0) {
        $mobile = '+' . $mobile;
    } elseif (strpos($mobile, '+639') !== 0) {
        $mobile = '+' . $mobile;
    }
    if (strlen($mobile) < 10) {
        echo json_encode(['ok' => false, 'msg' => 'Invalid mobile number.', 'debug' => ['normalized_to' => $mobile]]);
        exit;
    }

    $otp = random_int(100000, 999999);
    $_SESSION['gcash_otp'] = $otp;
    $_SESSION['gcash_otp_expiry'] = time() + (5 * 60);
    $_SESSION['gcash_otp_mobile'] = $mobile;

    $boldOtp = strtr((string) $otp, ['0' => '𝟬', '1' => '𝟭', '2' => '𝟮', '3' => '𝟯', '4' => '𝟰', '5' => '𝟱', '6' => '𝟲', '7' => '𝟳', '8' => '𝟴', '9' => '𝟵']);
    $msg = "Dear Student,
Thank you for trusting the Resources Generation Office.
We guarantee that your transactions are handled safely and securely.
    
Your verification code is: {$boldOtp}. It will expire in 5 minutes.";


    // Send via httpSMS
    global $HTTPSMS_API_KEY, $HTTPSMS_FROM;
    $resp = sendHttpSms($HTTPSMS_API_KEY, $HTTPSMS_FROM, $mobile, $msg);

    $parsed = null;
    $first_error = null;

    if (!empty($resp['body'])) {
        $parsed = json_decode($resp['body'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {

            $status = null;
            $errTxt = null;

            // If response includes something like {"success":true} or {"error":"..."}
            if (array_key_exists('success', $parsed)) {
                $status = $parsed['success'] ? 'SUCCESS' : 'FAILED';
            }
            if (isset($parsed['error'])) {
                $errTxt = is_string($parsed['error']) ? $parsed['error'] : json_encode($parsed['error']);
            }
            // If messages array exists, grab first message info
            if (isset($parsed['messages']) && is_array($parsed['messages']) && !empty($parsed['messages'])) {
                $m0 = $parsed['messages'][0];
                if (isset($m0['status']))
                    $status = strtoupper((string) $m0['status']);
                if (isset($m0['error']))
                    $errTxt = is_string($m0['error']) ? $m0['error'] : json_encode($m0['error']);
            }

            $first_error = [
                'status_groupId' => null,
                'status_groupName' => null,
                'status_id' => null,
                'status_name' => $status,
                'status_description' => $errTxt
            ];
        }
    }

    if (!empty($resp['error'])) {
        echo json_encode([
            'ok' => false,
            'msg' => 'Failed to send OTP. Network error. Please try again.',
            'debug' => [
                'http_code' => $resp['http_code'],
                'curl_error' => $resp['error'],
                'httpsms_body' => $parsed ?: $resp['body'],
                'first_message_status' => $first_error
            ]
        ]);
        exit;
    }

    if ($resp['http_code'] >= 200 && $resp['http_code'] < 300) {
        // If we couldn’t parse, assume success on 2xx
        $statusName = $first_error['status_name'] ?? 'SUCCESS';
        if (strtoupper((string) $statusName) !== 'SUCCESS') {
            echo json_encode([
                'ok' => false,
                'msg' => 'Failed to send OTP (httpSMS).',
                'debug' => [
                    'http_code' => $resp['http_code'],
                    'httpsms_body' => $parsed ?: $resp['body'],
                    'first_message_status' => $first_error,
                    'hint' => 'Check: API key, device online, Android app logged in, FROM is device SIM (+63…), and account limits.'
                ]
            ]);
        } else {
            echo json_encode([
                'ok' => true,
                'msg' => 'OTP sent',
                'debug' => [
                    'http_code' => $resp['http_code'],
                    'httpsms_body' => $parsed ?: $resp['body'],
                    'first_message_status' => $first_error
                ]
            ]);
        }
    } else {
        error_log("HTTPSMS SEND FAIL: HTTP {$resp['http_code']} BODY {$resp['body']}");
        echo json_encode([
            'ok' => false,
            'msg' => 'Failed to send OTP (httpSMS).',
            'debug' => [
                'http_code' => $resp['http_code'],
                'httpsms_body' => $parsed ?: $resp['body'],
                'first_message_status' => $first_error,
                'hint' => 'Check: API key, device online, permissions, and request payload.'
            ]
        ]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_otp') {
    header('Content-Type: application/json');
    $code = trim($_POST['code'] ?? '');

    if (!isset($_SESSION['gcash_otp'], $_SESSION['gcash_otp_expiry'], $_SESSION['gcash_otp_mobile'])) {
        echo json_encode(['ok' => false, 'msg' => 'No OTP session found. Please request a new code.']);
        exit;
    }

    if (time() > $_SESSION['gcash_otp_expiry']) {
        unset($_SESSION['gcash_otp'], $_SESSION['gcash_otp_expiry'], $_SESSION['gcash_otp_mobile']);
        echo json_encode(['ok' => false, 'msg' => 'OTP has expired. Please request a new code.']);
        exit;
    }

    if ($code !== strval($_SESSION['gcash_otp'])) {
        echo json_encode(['ok' => false, 'msg' => 'Incorrect OTP.']);
        exit;
    }

    // OTP correct → get the mobile used for verification
    $verified_mobile = $_SESSION['gcash_otp_mobile'];

    // Clear OTP data
    unset($_SESSION['gcash_otp'], $_SESSION['gcash_otp_expiry'], $_SESSION['gcash_otp_mobile']);

    $student_id = isset($_SESSION['student_id']) ? (int) $_SESSION['student_id'] : 0;
    if ($student_id <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Student not in session.']);
        exit;
    }

    // Save phone + mark verified in DB
    $stmt = $conn->prepare("UPDATE students SET student_phone_number = ?, phone_verified = 1 WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("si", $verified_mobile, $student_id);
        $stmt->execute();
        $stmt->close();
    }

    // Optional: update session as well if you use it later
    $_SESSION['student_phone_number'] = $verified_mobile;

    echo json_encode([
        'ok' => true,
        'msg' => 'Phone verified',
        'phone' => $verified_mobile
    ]);
    exit;
}
// PLACE ORDER (deduct stock + create order & payment)
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'place_order') {

    header('Content-Type: application/json');

    if (!isset($_SESSION['student_id'])) {
        echo json_encode(['ok' => false, 'msg' => 'Not logged in']);
        exit;
    }

    $student_id = (int)$_SESSION['student_id'];
    $method     = $_POST['method'] ?? 'GCash';
    $itemsJson  = $_POST['items'] ?? '[]';
    $items      = json_decode($itemsJson, true);

    if (!is_array($items) || empty($items)) {
        echo json_encode(['ok' => false, 'msg' => 'No items in order.']);
        exit;
    }
        // Resolve login_id like your helper
        $login_id = isset($_SESSION['login_id']) ? (int)$_SESSION['login_id'] : 0;
        if ($login_id <= 0) {
            $q = $conn->prepare("
                SELECT sl.login_id
                FROM student_login sl
                INNER JOIN students s ON s.login_id = sl.login_id
                WHERE s.id = ?
                ORDER BY sl.login_id DESC
                LIMIT 1
            ");
            if ($q) {
                $q->bind_param("i", $student_id);
                $q->execute();
                $q->bind_result($found_login_id);
                if ($q->fetch()) {
                    $login_id = (int)$found_login_id;
                    $_SESSION['login_id'] = $login_id;
                }
                $q->close();
            }
        }
        if ($login_id <= 0) {
            echo json_encode(['ok' => false, 'msg' => 'Missing login_id.']);
            exit;
        }
    
    $conn->begin_transaction();
    try {
        // Create order header
        $order_num       = gen_digits(32);
        $transaction_num = gen_digits(64);

        $sqlOrder = "INSERT INTO orders_info (student_id, login_id, order_num, transaction_num)
                     VALUES (?,?,?,?)";
        $stmtOrder = $conn->prepare($sqlOrder);
        if (!$stmtOrder) {
            throw new Exception("Prepare order failed: " . $conn->error);
        }
        $stmtOrder->bind_param("iiss", $student_id, $login_id, $order_num, $transaction_num);
        $stmtOrder->execute();
        $order_id = (int)$stmtOrder->insert_id;
        $stmtOrder->close();

        // Loop items: check + deduct stock + insert order_items
        $totalAmount = 0.0;

        foreach ($items as $it) {
            // 1) Read values from JSON
            $item_id = isset($it['item_id']) ? (int)$it['item_id'] : 0;
            $size    = isset($it['size']) ? trim($it['size']) : '';
            $qty     = isset($it['qty']) ? (int)$it['qty'] : (isset($it['quantity']) ? (int)$it['quantity'] : 0);
        
            // unitPrice can be named unitPrice OR unit_price OR price string
            $unitPrice = 0.0;
            if (isset($it['unitPrice'])) {
                $unitPrice = (float)$it['unitPrice'];
            } elseif (isset($it['unit_price'])) {
                $unitPrice = (float)$it['unit_price'];
            } elseif (!empty($it['price'])) {
                // price like "₱160.00" → 160.00
                $unitPrice = (float)preg_replace('/[^\d.]+/', '', $it['price']);
            }
        
            // Basic validation (we no longer require unitPrice > 0 here)
            if ($item_id <= 0 || $size === '' || $qty <= 0) {
                error_log('PLACE_ORDER ITEM DEBUG: ' . json_encode($it));
                throw new Exception("Invalid item data (item_id/size/qty missing).");
            }
        
            // 2) Lock row & read current stock + price from DB
            $stmt = $conn->prepare("
                SELECT stock, price 
                FROM item_sizes 
                WHERE item_id = ? AND label = ? 
                FOR UPDATE
            ");
            if (!$stmt) {
                throw new Exception("Prepare stock check failed: " . $conn->error);
            }
            $stmt->bind_param("is", $item_id, $size);
            $stmt->execute();
            $stmt->bind_result($stock, $dbPrice);
            if (!$stmt->fetch()) {
                $stmt->close();
                throw new Exception("Size not found for item_id={$item_id}, label='{$size}'.");
            }
            $stmt->close();
        
            if ($stock < $qty) {
                throw new Exception("Not enough stock for {$size}. Available: {$stock}, requested: {$qty}.");
            }
        
            // If front-end price is missing/0, fall back to DB price
            if ($unitPrice <= 0 && $dbPrice !== null) {
                $unitPrice = (float)$dbPrice;
            }
        
            // 3) Deduct stock
            $upd = $conn->prepare("UPDATE item_sizes SET stock = stock - ? WHERE item_id = ? AND label = ?");
            if (!$upd) {
                throw new Exception("Prepare stock update failed: " . $conn->error);
            }
            $upd->bind_param("iis", $qty, $item_id, $size);
            $upd->execute();
        
            if ($upd->affected_rows === 0) {
                $upd->close();
                throw new Exception("Stock update affected 0 rows for item_id={$item_id}, size='{$size}'.");
            }
            $upd->close();
        
            // (Optional) log new stock for debugging
            $check = $conn->prepare("SELECT stock FROM item_sizes WHERE item_id = ? AND label = ?");
            $check->bind_param("is", $item_id, $size);
            $check->execute();
            $check->bind_result($newStock);
            $check->fetch();
            $check->close();
            error_log("STOCK DEBUG: item_id={$item_id}, size='{$size}', old_stock={$stock}, qty={$qty}, new_stock={$newStock}");
        
            // 4) Insert line item
            $lineTotal = $unitPrice * $qty;
            $sqlItem = "INSERT INTO order_items (order_id, item_id, size_label, quantity, unit_price, total_price)
                        VALUES (?,?,?,?,?,?)";
            $stmtItem = $conn->prepare($sqlItem);
            if (!$stmtItem) {
                throw new Exception("Prepare order_items failed: " . $conn->error);
            }
            $stmtItem->bind_param("iisidd", $order_id, $item_id, $size, $qty, $unitPrice, $lineTotal);
            $stmtItem->execute();
            $stmtItem->close();
        
            $totalAmount += $lineTotal;
        }
        

        // Insert payment
        $payment_no = gen_digits(32);
        $currency   = 'PHP';

        $sqlPay = "INSERT INTO payment
            (order_id, student_id, login_id, item_id, amount, currency, payment_method, payment_no, notes)
            VALUES (?,?,?,?,?,?,?,?,?)";
        $stmtPay = $conn->prepare($sqlPay);
        if (!$stmtPay) {
            throw new Exception("Prepare payment failed: " . $conn->error);
        }
        $nullItemId = null; // multi-item order
        $notes = null;
        $stmtPay->bind_param(
            "iiiidssss",
            $order_id,
            $student_id,
            $login_id,
            $nullItemId,
            $totalAmount,
            $currency,
            $method,
            $payment_no,
            $notes
        );
        $stmtPay->execute();
        $payment_id = (int)$stmtPay->insert_id;
        $stmtPay->close();

        $conn->commit();

        echo json_encode([
            'ok'            => true,
            'order_id'      => $order_id,
            'order_num'     => $order_num,     // 👈 from orders_info
            'payment_id'    => $payment_id,
            'payment_no'    => $payment_no,    // 👈 this will be your SI
            'total'         => $totalAmount,
            'payment_method'=> $method
        ]);
        
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("PLACE_ORDER ERR: " . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}
// GET ORDERS for current student (used by "My Orders" modal)
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'get_orders') {

    header('Content-Type: application/json');

    if (!isset($_SESSION['student_id'])) {
        echo json_encode(['ok' => false, 'msg' => 'Not logged in']);
        exit;
    }

    $student_id = (int)$_SESSION['student_id'];

    $sql = "
        SELECT 
            o.orderid AS order_id,           -- PK from orders_info
            o.order_num,
            p.payment_method,
            p.payment_no,                    -- SI number
            p.amount,
            p.currency,
            p.payment_date AS created_at,    -- alias for JS
            oi.id AS order_item_id,          -- PK from order_items
            oi.item_id,
            oi.size_label,
            oi.quantity,
            oi.unit_price,
            oi.total_price,
            i.item_name,
            ii.image_data
        FROM orders_info o
        JOIN payment      p  ON p.order_id = o.orderid
        JOIN order_items  oi ON oi.order_id = o.orderid
        JOIN items        i  ON i.item_id = oi.item_id
        LEFT JOIN item_images ii ON ii.item_id = i.item_id
        WHERE o.student_id = ?
        ORDER BY p.payment_date DESC, o.orderid DESC, oi.id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['ok' => false, 'msg' => 'Failed to prepare.']);
        exit;
    }

    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $orders = [];
    while ($row = $res->fetch_assoc()) {
        $oid = (int)$row['order_id'];
        if (!isset($orders[$oid])) {
            $orders[$oid] = [
                'order_id'       => $oid,
                'order_num'      => $row['order_num'],
                'payment_method' => $row['payment_method'],
                'payment_no'     => $row['payment_no'],
                'amount'         => (float)$row['amount'],
                'currency'       => $row['currency'],
                'created_at'     => $row['created_at'],
                'items'          => []
            ];
        }

        $img = $row['image_data']
            ? 'data:image/jpeg;base64,' . base64_encode($row['image_data'])
            : 'logo.png';

        $orders[$oid]['items'][] = [
            'item_id'    => (int)$row['item_id'],
            'name'       => $row['item_name'],
            'size'       => $row['size_label'],
            'qty'        => (int)$row['quantity'],
            'unitPrice'  => (float)$row['unit_price'],
            'totalPrice' => (float)$row['total_price'],
            'image'      => $img
        ];
    }
    $stmt->close();

    echo json_encode([
        'ok'     => true,
        'orders' => array_values($orders)
    ]);
    exit;
}



if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit();
}

$student_id = $_SESSION['student_id'];

$stmt = $conn->prepare("
    SELECT fullname, sr_code, course,
           twofa_verified, twofa_secret,
           phone_verified, student_phone_number,
           account_status
    FROM students
    WHERE id=?
");

$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
// AUTO-COMPUTE VERIFICATION STATUS
// AUTO-COMPUTE VERIFICATION STATUS
$phone = (int) $student['phone_verified'];
$twofa = (int) $student['twofa_verified'];

// Decide what the new status should be
if ($phone === 1 && $twofa === 1) {
    $status = 'verified';          // FULLY VERIFIED
} elseif ($phone === 1 || $twofa === 1) {
    $status = 'semi_verified';     // SEMI VERIFIED
} else {
    $status = 'not_verified';      // NOT VERIFIED
}

// Safely read current account_status (might be NULL for new students)
$currentStatus = isset($student['account_status']) ? $student['account_status'] : null;

// Update DB only if status actually changed
if ($currentStatus !== $status) {
    $u = $conn->prepare("UPDATE students SET account_status=? WHERE id=?");
    $u->bind_param("si", $status, $student_id);
    $u->execute();
    $u->close();

    // Reflect change in local array
    $student['account_status'] = $status;
} else {
    // Make sure key is always set even if it was null before
    $student['account_status'] = $status;
}

// Save to session for later use (badge, etc.)
$_SESSION['account_status'] = $status;
$_SESSION['phone_verified'] = (int) $student['phone_verified'];
$_SESSION['student_phone_number'] = $student['student_phone_number'] ?? '';




$update_success = false;
$phone_error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {

    // SAFEST FIX:
    // Re-fetch the full student record BEFORE updating
    $stmtData = $conn->prepare("
        SELECT fullname, sr_code, gsuite_email, course, profile_pic, student_phone_number 
        FROM students WHERE id=?
    ");
    $stmtData->bind_param("i", $student_id);
    $stmtData->execute();
    $current = $stmtData->get_result()->fetch_assoc();
    $stmtData->close();

    // Load values from DB (fallback if fields are not changed)
    $current_fullname = $current['fullname'];
    $current_sr = $current['sr_code'];
    $current_gsuite = $current['gsuite_email'];
    $current_course = $current['course'];
    $current_pic = $current['profile_pic'];
    $current_phone = $current['student_phone_number'];

    // Updated values from the form
    $fullname = trim($_POST['fullName']) ?: $current_fullname;
    $sr_code = trim($_POST['srCode']) ?: $current_sr;
    $gsuite_email = trim($_POST['gsuite']) ?: $current_gsuite;
    $course = trim($_POST['course']) ?: $current_course;

    // Profile picture upload
    if (isset($_FILES['profilePicInput']) && $_FILES['profilePicInput']['error'] === UPLOAD_ERR_OK) {
        $imgData = file_get_contents($_FILES['profilePicInput']['tmp_name']);
        $profile_pic = $imgData;  // NEW PIC
    } else {
        $profile_pic = $current_pic; // KEEP OLD PIC
    }

    // ALWAYS preserve phone number
    $real_phone = $current_phone;

    // UPDATE query
    $stmt = $conn->prepare("
        UPDATE students 
        SET fullname=?, sr_code=?, gsuite_email=?, course=?, profile_pic=?, student_phone_number=?
        WHERE id=?
    ");

    $stmt->bind_param(
        "ssssssi",
        $fullname,
        $sr_code,
        $gsuite_email,
        $course,
        $profile_pic,
        $real_phone,
        $student_id
    );

    if ($stmt->execute()) {
        $update_success = true;

        // Update session safely
        $_SESSION['fullname'] = $fullname;
        $_SESSION['profile_pic'] = $profile_pic;
        $_SESSION['student_phone_number'] = $real_phone;
    }

    $stmt->close();
}


$stmt = $conn->prepare("
    SELECT fullname, sr_code, gsuite_email, course,
           profile_pic, student_phone_number,
           twofa_verified, twofa_secret,
           account_status
    FROM students
    WHERE id = ?
");

if (!$stmt) {
    die("Prepare failed: (" . $conn->errno . ") " . $conn->error);
}
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();
$student = $result->fetch_assoc();
$stmt->close();

$profilePicData = $student['profile_pic'];
$profilePic = $profilePicData ? 'data:image/jpeg;base64,' . base64_encode($profilePicData) : 'logo.png';
$fullname = $student['fullname'];
$sr_code = $student['sr_code'];
$gsuite = $student['gsuite_email'];
$course = $student['course'];
$student_phone = $student['student_phone_number'] ?? '';

$items_rs = $conn->query("
  SELECT i.item_id, i.category, i.item_name, i.description, i.base_price,
         COALESCE(MIN(s.price), NULL) AS min_price,
         COALESCE(MAX(s.price), NULL) AS max_price
  FROM items i
  LEFT JOIN item_sizes s ON s.item_id = i.item_id
  GROUP BY i.item_id
  ORDER BY i.item_id DESC
");

$items = [];
if ($items_rs) {
    while ($row = $items_rs->fetch_assoc()) {
        $img_rs = $conn->query("SELECT image_data FROM item_images WHERE item_id=" . (int) $row['item_id'] . " ORDER BY image_id ASC");
        $img_list = [];
        if ($img_rs) {
            while ($img = $img_rs->fetch_assoc()) {
                $img_list[] = 'data:image/jpeg;base64,' . base64_encode($img['image_data']);
            }
            $img_rs->close();
        }
        $sizes_rs = $conn->query("SELECT label, stock FROM item_sizes WHERE item_id=" . (int) $row['item_id'] . " ORDER BY size_id ASC");
        $sizePairs = [];
        if ($sizes_rs) {
            while ($sz = $sizes_rs->fetch_assoc()) {
                $label = trim($sz['label']);
                $stock = is_null($sz['stock']) ? 0 : (int) $sz['stock'];
                $sizePairs[] = "{$label}:{$stock}";
            }
            $sizes_rs->close();
        }
        $display_price = '';
        if (!is_null($row['min_price'])) {
            if ((float) $row['min_price'] == (float) $row['max_price']) {
                $display_price = '₱' . number_format((float) $row['min_price'], 2);
            } else {
                $display_price = '₱' . number_format((float) $row['min_price'], 2) . ' – ' . '₱' . number_format((float) $row['max_price'], 2);
            }
        } else {
            $display_price = '₱' . number_format((float) ($row['base_price'] ?? 0), 2);
        }
        $items[] = [
            'item_id' => (int) $row['item_id'],
            'category' => $row['category'],
            'name' => $row['item_name'],
            'desc' => $row['description'] ?: '',
            'price_display' => $display_price,
            'images' => $img_list,
            'sizes_attr' => implode(',', $sizePairs),
        ];
    }
    $items_rs->close();
}
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>RGO University Shop</title>

    <!-- Your existing stylesheet -->
    <link rel="stylesheet" href="style.css" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@latest/dist/css/bootstrap.min.css" rel="stylesheet">

  
    <style>
        html,
        body {
            margin: 0;
            padding: 0;
            font-family: "Arial", sans-serif;
            background: #fafafa;
        }

        input[readonly] {
            background-color: #f0f0f0;
            cursor: not-allowed;
        }

        .ulo {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            padding: 10px 40px;
            background-image: url('unif.jpg');
            background-position: center;
            background-size: 100% 160%;
            background-repeat: no-repeat;
            height: 250px;
            box-sizing: border-box;
            overflow: hidden;
        }

        .ulo::before {
            content: "";
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, .38);
            z-index: 0;
        }

        .ulo * {
            position: relative;
            z-index: 1;
        }

        header img {
            height: 150px;
            width: 160px;
            margin-right: 30px;
            margin-left: 10px;
        }

        .header-text {
            display: flex;
            flex-direction: column;
            color: #000;
        }

        .header-text .office {
            font-size: 1.9rem;
            font-weight: bold;
            margin: 0;
            color: #fff;
        }

        .header-text .subtitle {
            font-size: 1.5rem;
            font-weight: bold;
            margin: 1px 0;
            color: rgb(239, 96, 101);
        }

        .category-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: red;
            border-bottom: 1px solid #c93a1f;
            padding: 10px 15px;
            flex-wrap: wrap;
        }

        .left-controls {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .right-controls {
            display: flex;
            align-items: center;
        }

        .menu-btn {
            font-size: 22px;
            background: none;
            border: none;
            cursor: pointer;
            color: #fff;
        }

        .menu-btn:hover {
            opacity: .9;
        }

        .category {
            background: rgba(255, 255, 255, .15);
            border: 1px solid rgba(255, 255, 255, .25);
            border-radius: 20px;
            font-weight: 600;
            color: #fff;
            cursor: pointer;
            padding: 8px 14px;
            transition: .25s;
        }

        .category:hover {
            background: rgba(255, 255, 255, .28);
        }

        .search-box {
            padding: 8px 12px;
            border: 1px solid #ccc;
            border-radius: 20px;
            outline: none;
            width: 200px;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: -270px;
            width: 250px;
            height: 100%;
            background: #ffffffdc;
            box-shadow: 3px 0 10px rgba(0, 0, 0, .2);
            transition: left .3s ease;
            z-index: 1000;
            display: flex,
                flex-direction: column;
            justify-content: space-between;
        }

        .sidebar.open {
            left: 0;
        }

        .sidebar-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 30px 15px;
        }

        .profile-pic {
            width: 60%;
            height: 50%;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #ee4d2d;
        }

        .profile-name {
            font-weight: bold;
            margin-bottom: 2px;
            color: #333.
        }

        .course {
            font-size: 12px;
            font-weight: bold;
            color: #555;
            margin-bottom: 20px;
            text-align: center;
        }

        .sidebar-btn {
            width: 100%;
            background: reda;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 12px;
            margin: 6px 0;
            cursor: pointer;
            font-weight: bold.
        }

        .sidebar-btn:hover {
            background: #d63f22;
        }

        .signout-btn {
            background: #444;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 12px;
            margin: 15px;
            cursor: pointer;
            font-weight: bold;
        }

        .signout-btn:hover {
            background: #222;
        }

        .overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .4);
            z-index: 500;
        }

        .overlay.show {
            display: block;
        }

        .product-section {
            padding: 20px;
            background-color: rgba(255, 255, 255, 0.2);
            min-height: 100vh.
        }

        .product-grid {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 20px;
            width: 100%;
            padding: 10px;
        }

        .product-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, .08);
            text-align: center;
            padding: 16px;
            width: 300px;
            height: 400px;
            transition: transform .2s ease, box-shadow .2s ease;
            cursor: pointer;
        }

        .product-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, .15);
        }

        .product-img {
            width: 100%;
            height: 300px;
            object-fit: cover;
            border-radius: 10px;
        }

        .product-name {
            font-size: 16px;
            font-weight: bold;
            margin: 10px 0 6px;
            color: #333.
        }

        .product-price {
            color: #ee4d2d;
            font-weight: 800;
            margin: 0;
        }

        .pd-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .5);
            z-index: 3000;
            padding: 24px;
            overflow: auto.
        }

        .pd-modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .pd-panel {
            background: #fff;
            width: min(570px, 50vw);
            height: 85vh;
            border-radius: 14px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .25);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .pd-body {
            overflow: auto;
            padding-bottom: 16px;
        }

        .pd-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-bottom: 1px solid #eee;
        }

        .pd-close {
            font-size: 22px;
            border: none;
            background: none;
            cursor: pointer.
        }

        .pd-title {
            font-weight: 700;
            font-size: 16px;
            margin: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap.
        }

        .carousel {
            position: relative;
            width: 100%;
            max-width: 760px;
            margin: 0 auto.
        }

        .carousel-viewport {
            width: 100%;
            overflow: hidden;
            touch-action: pan-y;
        }

        .carousel-track {
            display: flex;
            transition: transform .35s ease.
        }

        .carousel-slide {
            min-width: 100%;
            user-select: none;
            display: grid;
            place-items: center;
            background: #f6f6f6;
            height: 550px;
        }

        .carousel-slide img {
            width: 85%;
            height: 100%;
            object-fit: cover;
            object-position: center center;
        }

        .carousel-arrow {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: rgba(0, 0, 0, .45);
            color: #fff;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center.
        }

        .carousel-arrow.left {
            left: 8px;
        }

        .carousel-arrow.right {
            right: 8px;
        }

        .carousel-dots {
            display: flex;
            gap: 6px;
            justify-content: center;
            padding: 10px 0;
        }

        .carousel-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #ddd;
        }

        .carousel-dot.active {
            background: #ee4d2d;
        }

        .pd-info {
            max-width: 760px;
            margin: 0 auto;
            padding: 12px 16px;
        }

        .pd-name {
            font-size: 18px;
            font-weight: 700;
            margin: 8px 0;
        }

        .pd-price {
            font-size: 20px;
            font-weight: 800;
            color: #ee4d2d;
            margin: 0 0 8px;
        }

        .pd-desc {
            font-size: 14px;
            line-height: 1.6;
            color: #444;
            margin: 8px 0 14px;
        }

        .pd-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding-top: 6px;
            flex-wrap: wrap.
        }

        .btn-primary {
            background: #ee4d2d;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px 14px;
            font-weight: 700;
        }

        .btn-ghost {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px 14px;
            font-weight: 700;
            cursor: pointer.
        }

        .btn-primary[disabled] {
            opacity: .5;
            cursor: not-allowed;
        }

        .btn-primary:hover:not([disabled]) {
            background: #d63f22;
        }

        .buymodal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .5);
            z-index: 3400;
            padding: 24px;
            overflow: auto.
        }

        .buymodal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .buy-panel {
            background: #fff;
            width: min(590px, 100vw);
            border-radius: 14px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .25);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            max-height: 100vh.
        }

        .buy-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            border-bottom: 1px solid #eee.
        }

        .buy-title {
            font-size: 16px;
            font-weight: 800;
            margin: 0.
        }

        .buy-body {
            padding: 14px 16px;
            overflow: auto;
        }

        .size-title {
            font-size: 13px;
            color: #555;
            margin: 4px 0 10px;
        }

        .size-list {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .size-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border: 1px solid #eaeaea;
            border-radius: 10px;
            padding: 10px 12px;
        }

        .size-left {
            display: flex;
            align-items: center;
            gap: 10px.
        }

        .size-radio {
            transform: scale(1.1);
        }

        .size-label {
            font-weight: 700;
        }

        .size-stock {
            font-size: 12px;
            color: #666;
        }

        .ck-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .5);
            z-index: 3500;
            padding: 24px;
            overflow: auto.
        }

        .ck-modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .ck-panel {
            background: #fff;
            width: min(560px, 92vw);
            border-radius: 14px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .25);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            max-height: 90vh.
        }

        .ck-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            border-bottom: 1px solid #eee;
        }

        .ck-title {
            font-size: 16px;
            font-weight: 800;
            margin: 0.
        }

        .ck-body {
            padding: 14px 16px;
            overflow: auto.
        }

        .ck-card {
            display: grid;
            grid-template-columns: 96px 1fr;
            gap: 12px;
            padding: 10px;
            border: 1px solid #f0f0f0;
            border-radius: 12px;
            background: #fff;
            margin-bottom: 14px.
        }

        .ck-card img {
            width: 96px;
            height: 96px;
            border-radius: 10px;
            object-fit: cover;
        }

        .ck-name {
            font-weight: 700;
            margin: 0 0 6px;
        }

        .ck-meta {
            color: #666;
            font-size: 13px;
            margin: 0.
        }

        .ck-price {
            color: #ee4d2d;
            font-weight: 800;
            margin-top: 6px.
        }

        .ck-pay {
            margin-top: 12px;
        }

        .ck-pay h4 {
            margin: 0 0 8px;
            font-size: 14px.
        }

        .ck-payopt {
            display: flex;
            gap: 10px;
            flex-wrap: wrap.
        }

        .ck-radio {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            cursor: pointer.
        }

        .ck-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 12px 16px;
            border-top: 1px solid #eee.
        }

        .ck-close {
            background: transparent;
            border: none;
            font-size: 22px;
            cursor: pointer.
        }
    </style>
    <style>
        .product-grid {
            justify-content: flex-start !important;
        }
    </style>
    <style>
        .product-card {
            padding: 24px !important;
        }

        .product-img {
            margin-bottom: 12px;
        }

        .product-name {
            margin: 12px 0 8px !important;
        }

        .product-price {
            margin-top: 6px !important;
        }
    </style>
    <style>
        .sidebar .profile-pic {
            width: 160px !important;
            height: 160px !important;
            border-radius: 50% !important;
            object-fit: cover !important;
            display: block;
            margin: 0 auto;
        }
    </style>
    <style>
        .simple-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .5);
            z-index: 3600;
            padding: 24px;
            overflow: auto;
        }

        .simple-modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .simple-panel {
            background: #fff;
            width: min(480px, 92vw);
            border-radius: 14px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .25);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            max-height: 85vh;
        }

        .simple-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            border-bottom: 1px solid #eee;
        }

        .simple-body {
            padding: 16px;
        }

        .simple-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 12px 16px;
            border-top: 1px solid #eee;
        }

        .text-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }

        .qr-wrap {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            justify-items: center;
        }

        .qr-wrap img {
            width: 100%;
            max-width: 400px;
            aspect-ratio: 1/1;
            object-fit: contain;
            border: 1px solid #eee;
            border-radius: 10px;
            background: #fff;
        }
    </style>
    <style>
        #qrModal .simple-panel {
            width: min(500px, 90vw);
        }

        #qrModal .qr-wrap {
            grid-template-columns: 1fr !important;
            justify-items: center !important;
        }

        #qrModal .qr-wrap img {
            width: 400px !important;
            height: 250px !important;
            object-fit: contain !important;
            aspect-ratio: 1 / 1 !important;
        }
    </style>
    <style>
        .orders-section {
            display: none;
            padding: 24px;
            min-height: 100vh;
        }

        .orders-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .orders-header {
            font-size: 28px;
            font-weight: 800;
            margin: 0;
        }

        .orders-sub {
            color: #555;
            margin-bottom: 14px;
        }

        .orders-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(420px, 1fr));
            gap: 16px;
            justify-content: flex-start;
        }

        .order-card {
            background: #fff;
            border: 1px solid #eee;
            border-radius: 12px;
            padding: 16px;
            display: grid;
            grid-template-columns: 100px 1fr;
            gap: 14px;
        }

        .order-card img {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 8px;
        }

        .order-status {
            font-weight: 800;
            margin: 0 0 4px;
        }

        .order-est {
            color: #2e7d32;
            font-size: 13px;
            margin: 0 0 6px;
        }

        .order-meta small {
            color: #666;
            display: block;
        }

        .order-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 8px;
        }

        .btn-line {
            background: #fff;
            border: 1px solid #ccc;
            border-radius: 8px;
            padding: 8px 12px;
            cursor: pointer;
            font-weight: 700;
        }

        .btn-back {
            background: #f6f6f6;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 8px 12px;
            cursor: pointer;
        }
    </style>
    <style>
        #ordersSection .orders-topbar {
            flex-direction: column !important;
            align-items: flex-start !important;
            gap: 8px !important;
        }

        #ordersSection #backToShop {
            order: -1;
            align-self: flex-start;

        }

        #notifCount {
            background: #ffc107;
        }

        .notif-item {
            padding: 10px;
            background: #f7f7f7;
            margin-bottom: 8px;
            border-radius: 6px;
            cursor: pointer;
            border-left: 4px solid #3b82f6;
            transition: 0.2s ease;
        }

        .notif-item:hover {
            background: #eaeaea;
        }

        .notif-message-box {
            padding: 10px;
            background: #fff;
            border-radius: 6px;
            margin-top: 10px;
        }
    </style>

    <!-- ✅ OVERRIDES (no removal of existing code): Make product cards fill the row and OTP into 6 boxes -->
    <style>
        .product-grid {
            display: grid !important;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)) !important;
            gap: 20px !important;
            align-items: stretch !important;
            justify-items: stretch !important;
        }

        .product-card {
            width: 100% !important;
            height: auto !important;
            display: flex !important;
            flex-direction: column !important;
        }

        .product-img {
            height: 260px !important;
        }

        .otp-boxes {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 8px;
            margin-bottom: 6px;
        }

        .otp-boxes input {
            width: 44px;
            height: 52px;
            text-align: center;
            font-size: 22px;
            border: 1px solid #ddd;
            border-radius: 8px;
            outline: none;
        }

        .otp-boxes input:focus {
            border-color: #ee4d2d;
            box-shadow: 0 0 0 2px rgba(238, 77, 45, 0.15);
        }

        #otpCode {
            position: absolute !important;
            opacity: 0 !important;
            pointer-events: none !important;
            height: 0 !important;
            width: 0 !important;
        }
    </style>

    <!-- ✅ NEW: Pure CSS polish (no HTML/JS changes) -->
    <style>
        .modal {
            position: fixed;
            inset: 0;
            display: none;
            background: rgba(17, 24, 39, 0.55);
            z-index: 4000;
            overflow: auto;
            backdrop-filter: blur(2px);
        }

        .modal .modal-content {
            width: min(720px, 92vw);
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 24px 60px rgba(0, 0, 0, .22);
            margin: 6vh auto 4vh;
            padding: 24px 26px 22px;
            position: relative;
            animation: pfFadeUp .22s ease-out;
        }

        @keyframes pfFadeUp {
            from {
                transform: translateY(8px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal .close-btn {
            position: absolute;
            right: 14px;
            top: 12px;
            font-size: 22px;
            line-height: 1;
            cursor: pointer;
            color: #6b7280;
            transition: color .15s ease;
        }

        .modal .close-btn:hover {
            color: #111827;
        }

        .modal h3 {
            margin: 0 0 14px;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: .2px;
            color: #111827;
        }

        .modal .profile-pic-container {
            display: grid;
            grid-template-columns: 112px 1fr;
            gap: 16px;
            align-items: center;
            margin-bottom: 6px;
        }

        .modal .modal-profile-pic {
            width: 112px;
            height: 112px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #f3f4f6;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .06);
        }

        .modal label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #374151;
            margin: 10px 0 6px;
        }

        .modal input[type="text"],
        .modal input[type="tel"],
        .modal input[type="email"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #fff;
            font-size: 14px;
            transition: border-color .15s, box-shadow .15s;
        }

        .modal input[type="text"]:focus,
        .modal input[type="tel"]:focus,
        .modal input[type="email"]:focus {
            border-color: #ee4d2d;
            box-shadow: 0 0 0 3px rgba(238, 77, 45, .12);
            outline: none;
        }

        .modal input[readonly] {
            background: #f9fafb;
            color: #6b7280;
        }

        .modal button[type="submit"][name="update_profile"] {
            margin-top: 14px;
            background: #ee4d2d;
            border: none;
            color: #fff;
            font-weight: 800;
            padding: 10px 16px;
            border-radius: 10px;
            cursor: pointer;
            transition: background .15s ease;
        }

        .modal button[type="submit"][name="update_profile"]:hover {
            background: #d63f22;
        }

        .category-bar {
            gap: 10px;
        }

        .left-controls {
            gap: 10px;
        }

        .category {
            padding: 9px 16px;
            border-radius: 999px;
            letter-spacing: .2px;
            backdrop-filter: saturate(1.2);
            box-shadow: 0 1px 0 rgba(255, 255, 255, .2) inset;
        }

        .category:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, .18);
        }

        .category:focus-visible {
            outline: 3px solid rgba(255, 255, 255, .65);
            outline-offset: 2px;
        }
    </style>

    <!-- ✅ NEW: CART STYLES -->
    <style>
        .cart-btn {
            margin-left: 8px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 50%;
            width: 38px;
            height: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            position: relative;
        }

        .cart-count {
            position: absolute;
            top: -4px;
            right: -4px;
            background: #ee4d2d;
            color: #fff;
            border-radius: 999px;
            font-size: 11px;
            padding: 1px 5px;
            min-width: 16px;
            text-align: center;
            display: none;
        }

        .cart-item {
            display: grid;
            grid-template-columns: 64px 1fr auto;
            gap: 10px;
            align-items: center;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .cart-item img {
            width: 64px;
            height: 64px;
            border-radius: 8px;
            object-fit: cover;
        }

        .cart-item-name {
            font-weight: 600;
        }

        .cart-item-meta {
            font-size: 13px;
            color: #666;
        }

        .cart-remove {
            background: transparent;
            border: none;
            color: #d63f22;
            cursor: pointer;
            font-size: 13px;
        }

        .cart-empty {
            color: #666;
            font-size: 13px;
        }

        /* Make 2FA modal always appear IN FRONT of Security Modal */
        #twoFAModal {
            z-index: 9999 !important;
        }

        /* Center the 2FA Modal */
        #twoFAModal .simple-panel {
            margin: auto;
            top: auto !important;
            left: auto !important;
            right: auto !important;
            bottom: auto !important;

            transform: none !important;
        }

        /* Make SMS phone + OTP modals appear above Security modal */
        #securityModal {
            z-index: 4000;
        }

        #gcashNumberModal,
        #otpModal {
            z-index: 5000;

            .order-card img {
                width: 80px;
                height: 80px;
                object-fit: cover;
                border-radius: 8px;
                margin-right: 12px;
                flex-shrink: 0;
            }

            .order-card {
                display: flex;
                gap: 12px;
                padding: 12px;
                background: white;
                border-radius: 12px;
                margin-bottom: 12px;
            }

            .order-card>div {
                flex: 1;
            }

        }
    </style>
</head>

<body
    style="background: url('http://localhost/Group%203%20-%20SIA/rgoshop.jpeg') no-repeat center center fixed; background-size: cover;">
    <header class="ulo">
        <img src="logo.png" alt="School Logo" />
        <div class="header-text">
            <h1 class="office">RESOURCE GENERATION OFFICE</h1>
            <p class="subtitle">Batangas State University - Lipa Campus</p>
        </div>
    </header>

    <div id="sidebar" class="sidebar">
        <div class="sidebar-content">
            <img src="<?php echo $profilePic; ?>" alt="Profile Picture" class="profile-pic" id="sidebarProfilePic" />
            <p class="profile-name" id="sidebarName"><?php echo htmlspecialchars($fullname); ?></p>
            <p class="course" id="sidebarCourse"><?php echo htmlspecialchars($course); ?></p>
            <button class="sidebar-btn" id="profileBtn">Profile</button>
            <button class="sidebar-btn">My Orders</button>
            <button class="sidebar-btn">Contact RGO</button>
            <button id="openSecurityModal" class="sidebar-btn">Security</button>

        </div>
        <button class="signout-btn" id="signoutBtn">Sign Out</button>
    </div>
    <div id="overlay" class="overlay"></div>

    <nav class="category-bar">
        <div class="left-controls">
            <button id="sidebarToggle" class="menu-btn">☰</button>
            <button class="category" data-category="all">All</button>
            <button class="category" data-category="uniforms">Uniforms</button>
            <button class="category" data-category="textile">Textile</button>
            <button class="category" data-category="pants">Pants</button>
            <button class="category" data-category="accessories">Accessories</button>
            <button class="category" data-category="skirts">Skirts</button>
        </div>
        <div class="right-controls">
            <input type="text" id="searchInput" class="search-box" placeholder="Search products..." />

            <!-- 🔔 Notification icon -->
            <button type="button" id="notifToggleBtn" class="cart-btn" title="Notifications">
                🔔
                <span id="notifCount" class="cart-count">0</span>
            </button>

            <!-- 🛒 Cart icon button -->
            <button type="button" id="cartToggleBtn" class="cart-btn" title="View cart">
                🛒
                <span id="cartCount" class="cart-count">0</span>
            </button>
            <!-- 🔔 Notification Modal -->
            <div id="notifModal" class="simple-modal" aria-hidden="true">
                <div class="simple-panel">

                    <div class="simple-header">
                        <h3 class="buy-title">Notifications</h3>
                        <button id="notifClose" class="ck-close">×</button>
                    </div>

                    <div class="simple-body">
                        <p style="margin-top:0;">Scan this QR code for payment.</p>
                        <div id="qrWrap" class="qr-wrap"></div>

                        <!-- ✅ Proof of payment -->
                        <div id="qrProofSection" style="margin-top:16px;">
                            <label style="font-size:14px;font-weight:600;">
                                Upload Screenshot / Proof of Payment <span style="color:red;">*</span>
                            </label>
                            <input type="file" id="qrProofFile" accept="image/*" style="display:block;margin-top:6px;">
                            <small style="display:block;margin-top:4px;color:#666;font-size:12px;">
                                This is required to confirm your GCash payment.
                            </small>
                            <div id="qrProofError" style="margin-top:8px;font-size:13px;color:#d63f22;"></div>
                        </div>
                    </div>
                    <div class="simple-actions">
                        <button id="qrDone" class="btn-ghost" style="background-color:transparent;">Cancel</button>
                        <button id="qrConfirm" class="btn-primary">Confirm Payment</button>
                    </div>


                </div>
            </div>

        </div>

    </nav>

    <section class="product-section">
        <div class="product-grid">
            <?php if (!empty($items)):
                foreach ($items as $it):
                    $firstImg = $it['images'][0] ?? 'https://via.placeholder.com/600x800?text=No+Image';
                    $imagesAttr = implode('||', $it['images']);
                    $desc = trim($it['desc']) !== '' ? $it['desc'] : 'This item is presented with a formal description.';
                    ?>
                    <div class="product-card" data-category="<?= htmlspecialchars($it['category']) ?>"
                        data-name="<?= htmlspecialchars($it['name']) ?>"
                        data-price="<?= htmlspecialchars($it['price_display']) ?>"
                        data-images="<?= htmlspecialchars($imagesAttr) ?>" data-description="<?= htmlspecialchars($desc) ?>"
                        data-sizes="<?= htmlspecialchars($it['sizes_attr']) ?>"
                        data-item-id="<?= htmlspecialchars($it['item_id']) ?>">
                        <img src="<?= htmlspecialchars($firstImg) ?>" alt="<?= htmlspecialchars($it['name']) ?>"
                            class="product-img" />
                        <h3 class="product-name"><?= htmlspecialchars($it['name']) ?></h3>
                        <p class="product-price"><?= htmlspecialchars($it['price_display']) ?></p>
                    </div>
                <?php endforeach; else: ?>
                <div style="background:#fff;border-radius:12px;padding:20px;box-shadow:0 3px 10px rgba(0,0,0,.08);">No items
                    available yet.</div>
            <?php endif; ?>
        </div>
    </section>

    <section id="ordersSection" class="orders-section" aria-hidden="true">
        <div class="orders-topbar">
            <h2 class="orders-header">MY ORDERS</h2>
            <button id="backToShop" class="btn-back">← Back to shop</button>
        </div>
        <div id="ordersCountLabel" class="orders-sub">Displaying 0 of 0 orders</div>
        <div id="ordersList" class="orders-list"></div>
    </section>

    <div id="productDetailModal" class="pd-modal" aria-hidden="true">
        <div id="pdPanel" class="pd-panel" role="dialog" aria-modal="true" aria-labelledby="pdTitle">
            <div class="pd-header">
                <button id="pdClose" class="pd-close" aria-label="Close">×</button>
                <h2 id="pdTitle" class="pd-title">Product</h2>
            </div>
            <div class="pd-body">
                <div class="carousel" id="pdCarousel">
                    <button class="carousel-arrow left" id="cPrev" aria-label="Previous">‹</button>
                    <div class="carousel-viewport" id="cViewport">
                        <div class="carousel-track" id="cTrack"></div>
                    </div>
                    <button class="carousel-arrow right" id="cNext" aria-label="Next">›</button>
                    <div class="carousel-dots" id="cDots"></div>
                </div>
                <div class="pd-info">
                    <div id="pdName" class="pd-name">Product Name</div>
                    <div id="pdPrice" class="pd-price">₱0.00</div>
                    <p id="pdDesc" class="pd-desc">This item is presented with a formal description.</p>
                    <div class="pd-actions">
                        <!-- 🛒 Add to Cart icon -->
                        <button id="pdAddToCart" class="btn-ghost" title="Add to cart">🛒</button>
                        <button style="color: white;" id="pdBuyNow" class="btn-primary">Buy now</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="buyModal" class="buymodal" aria-hidden="true">
        <div class="buy-panel" role="dialog" aria-modal="true" aria-labelledby="buyTitle">
            <div class="buy-header">
                <h3 id="buyTitle" class="buy-title">Select Size</h3>
                <button id="buyClose" class="ck-close" aria-label="Close">×</button>
            </div>
            <div class="buy-body">
                <div class="size-title">Available Options</div>
                <div id="sizeList" class="size-list"></div>

                <!-- ✅ Quantity selector -->
                <div style="margin-top:16px; display:flex; align-items:center; justify-content:flex-end; gap:8px;">
                    <span style="font-size:14px;color:#555;">Quantity:</span>
                    <button type="button" id="qtyMinus" class="btn-ghost"
                        style="width:32px;height:32px;padding:0;">−</button>
                    <span id="qtyValue" style="min-width:24px;text-align:center;">1</span>
                    <button type="button" id="qtyPlus" class="btn-ghost"
                        style="width:32px;height:32px;padding:0;">+</button>
                </div>
            </div>

            <div class="buy-actions">
                <button style="background-color: transparent;" id="buyCancel" class="btn-ghost">Cancel</button>
                <button id="buyProceed" class="btn-primary" disabled>Checkout</button>
            </div>
        </div>
    </div>

    <div id="checkoutModal" class="ck-modal" aria-hidden="true">
        <div class="ck-panel" role="dialog" aria-modal="true" aria-labelledby="ckTitle">
            <div class="ck-header">
                <h3 id="ckTitle" class="ck-title">Checkout</h3>
                <button id="ckClose" class="ck-close" aria-label="Close">×</button>
            </div>
            <div class="ck-body">
                <!-- ✅ multiple checkout items -->
                <div id="ckItemsList"></div>

                <!-- ✅ total row -->
                <div id="ckTotalRow" style="margin-top:10px; text-align:right; font-weight:800;">
                    Total: <span id="ckTotal">₱0.00</span>
                </div>

                <div class="ck-pay">
                    <h4>Payment Method</h4>
                    <div class="ck-payopt">
                        <label class="ck-radio"><input type="radio" name="paymethod" value="GCash" /> GCash</label>
                        <label class="ck-radio"><input type="radio" name="paymethod" value="Cash" /> Cash</label>
                    </div>
                </div>
            </div>

            <div class="ck-actions">
                <button id="ckPlaceOrder" class="btn-primary">Place Order</button>
            </div>
        </div>
    </div>

    <div id="profileFormModal" class="modal">
        <div class="modal-content">
            <span id="closeProfileForm" class="close-btn">&times;</span>
            <h3>Personal Information</h3>
            <form id="profileForm" method="POST" enctype="multipart/form-data">
                <div class="profile-pic-container">
                    <img src="<?php echo $profilePic; ?>" class="modal-profile-pic" id="modalProfilePic" />
                    <label for="profilePicInput" class="add-photo-overlay"><span>+</span></label>
                    <input type="file" name="profilePicInput" id="profilePicInput" accept="image/*">
                </div>

                <!-- BADGE GOES HERE -->
                <div style="text-align:center; margin-top:10px;">
                    <?php
                    $status = $student['account_status'];

                    if ($status === 'verified') {
                        echo '<button type="button" id="verificationBadge"
        style="background:#28a745;color:white;border:none;padding:6px 14px;font-size:12px;border-radius:6px;font-weight:700;">
        ✔ FULLY VERIFIED
    </button>';

                    } elseif ($status === 'semi_verified') {
                        echo '<button type="button" id="verificationBadge"
        style="background:#ffc107;color:black;border:none;padding:6px 14px;font-size:12px;border-radius:6px;font-weight:700;">
        ⚠ SEMI-VERIFIED
    </button>';

                    } else {
                        echo '<button type="button" id="verificationBadge"
        style="background:#dc3545;color:white;border:none;padding:6px 14px;font-size:12px;border-radius:6px;font-weight:700;">
        ✖ NOT VERIFIED
    </button>';
                    }
                    ?>

                </div>

                <label for="fullName">Full Name</label>
                <input type="text" id="fullName" name="fullName" value="<?php echo htmlspecialchars($fullname); ?>" />
                <label for="student_phone">Phone Number</label>
                <div style="display:flex; align-items:center; gap:8px;">
                    <input type="text" id="student_phone"
                        value="<?= htmlspecialchars($_SESSION['student_phone_number'] ?? '') ?>" readonly>

                    <span id="phoneVerifiedIcon"
                        style="color:#28a745; font-size:18px; display: <?= ($_SESSION['phone_verified'] ?? 0) ? 'inline' : 'none' ?>">
                        ✔
                    </span>

                </div>

                <label for="srCode">SR-Code</label>
                <input type="text" id="srCode" name="srCode" value="<?php echo htmlspecialchars($sr_code); ?>"
                    readonly />
                <label for="gsuite">Gsuite Email</label>
                <input type="email" id="gsuite" name="gsuite" value="<?php echo htmlspecialchars($gsuite); ?>"
                    readonly />
                <label for="course">Course</label>
                <input type="text" id="course" name="course" value="<?php echo htmlspecialchars($course); ?>" />
                <button type="submit" name="update_profile">Done</button>
            </form>
        </div>
    </div>

    <div id="gcashNumberModal" class="simple-modal" aria-hidden="true">
        <div class="simple-panel" role="dialog" aria-modal="true" aria-labelledby="gcashTitle">
            <div class="simple-header">
                <h3 id="gcashTitle" class="buy-title">Phone number Verification</h3>
                <button id="gcashClose" class="ck-close" aria-label="Close">×</button>
            </div>
            <div class="simple-body">
                <p style="margin-top:0;">Enter your GCash mobile number to receive a one-time PIN (OTP).</p>
                <input type="tel" id="gcashMobile" class="text-input" placeholder="e.g. 09XXXXXXXXX or +639XXXXXXXXX" />
                <small id="gcashNote" style="display:block;margin-top:8px;color:#666;">We’ll send a 6-digit code via
                    SMS.</small>
                <div id="gcashSendStatus" style="margin-top:10px;font-size:13px;color:#d63f22;"></div>
            </div>
            <div class="simple-actions">
                <button id="gcashCancel" class="btn-ghost" style="background-color: transparent;">Cancel</button>
                <button id="gcashSendBtn" class="btn-primary">Send OTP</button>
            </div>
        </div>
    </div>

    <div id="otpModal" class="simple-modal" aria-hidden="true">
        <div class="simple-panel" role="dialog" aria-modal="true" aria-labelledby="otpTitle">
            <div class="simple-header">
                <h3 id="otpTitle" class="buy-title">Enter OTP</h3>
                <button id="otpClose" class="ck-close" aria-label="Close">×</button>
            </div>
            <div class="simple-body">
                <p id="otpSentMsg" style="margin-top:0;"></p>
                <input type="text" id="otpCode" maxlength="6" class="text-input" placeholder="------" />
                <div id="otpBoxesMount"></div>
                <div id="otpStatus" style="margin-top:10px;font-size:13px;color:#d63f22;"></div>
            </div>
            <div class="simple-actions">
                <button id="otpCancel" class="btn-ghost" style="background-color: transparent;">Cancel</button>
                <button id="otpVerifyBtn" class="btn-primary">Verify</button>
            </div>
        </div>
    </div>

    <div id="qrModal" class="simple-modal" aria-hidden="true">
        <div class="simple-panel" role="dialog" aria-modal="true" aria-labelledby="qrTitle">
            <div class="simple-header">
                <h3 id="qrTitle" class="buy-title">Your QR Codes</h3>
                <button id="qrClose" class="ck-close" aria-label="Close">×</button>
            </div>
            <div class="simple-body">
                <p style="margin-top:0;">Scan this QR code for payment.</p>
                <div id="qrWrap" class="qr-wrap"></div>
            </div>
            <div class="simple-actions">
                <button id="qrDone" class="btn-primary">Done</button>
            </div>
        </div>
    </div>

    <!-- 🛒 CART MODAL -->
    <div id="cartModal" class="simple-modal" aria-hidden="true">
        <div class="simple-panel" role="dialog" aria-modal="true" aria-labelledby="cartTitle">
            <div class="simple-header">
                <h3 id="cartTitle" class="buy-title">My Cart</h3>
                <button id="cartClose" class="ck-close" aria-label="Close">×</button>
            </div>
            <div class="simple-body">
                <div id="cartEmptyText" class="cart-empty">Your cart is empty.</div>
                <div id="cartItems"></div>
            </div>
            <div class="simple-actions">
                <button id="cartClear" class="btn-ghost" style="background-color: transparent;">Clear</button>
                <button id="cartCheckout" class="btn-primary">Checkout</button>
            </div>
        </div>
    </div>

    <div id="securityModal" class="modal" style="display:none;">
        <div class="modal-content"
            style=" color: white; max-width:900px; padding:30px; height:85vh; border-radius:14px; overflow-y:auto; background-color: rgba(0, 0, 0, 0.416); position:relative;">

            <span id="closeSecurityModal" style="
            position:absolute;
            top:18px;
            right:25px;
            font-size:28px;
            font-weight:700;
            cursor:pointer;
            transition:0.2s ease;
        " onmouseover="this.style.color='red'; this.style.transform='scale(1.2)';"
                onmouseout="this.style.color='white'; this.style.transform='scale(1)';">
                &times;
            </span>

            <h2 style="margin-bottom:25px; font-size:28px; font-weight:700; text-align: center;">
                Security Setup
            </h2>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:25px; ">

                <!-- PHONE NUMBER -->
                <div style="
                background: white;
                padding:28px;
                border-radius:14px;
                display:flex;
                flex-direction:column;
                justify-content:space-between;
                min-height:230px;
                box-shadow:0 0 10px rgba(0,0,0,0.2);
            ">

                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                        <div style="font-size:26px;">📱</div>
                        <div style="color:#ffcc00; font-size:22px;">⚠</div>
                    </div>

                    <div>
                        <h3 style="margin:0; font-size:20px; font-weight:700;">Phone Number Verification</h3>
                        <p style="margin:5px 0 0 0; color:#bdbdbd; font-size:14px;">
                            Ensure your phone number is valid and accessible.
                        </p>
                    </div>

                    <?php $isPhoneVerified = !empty($student['phone_verified']); ?>
                    <?php $isVerified = !empty($_SESSION['phone_verified']) && $_SESSION['phone_verified'] == 1; ?>

                    <button id="openPhoneVerify" <?php if (isset($_SESSION['phone_verified']) && $_SESSION['phone_verified'] == 1)
                        echo 'disabled style="background:#28a745;color:#fff;cursor:not-allowed;opacity:0.7;"'; ?>>
                        <?php echo (isset($_SESSION['phone_verified']) && $_SESSION['phone_verified'] == 1) ? "Verified" : "Verify Phone"; ?>
                    </button>



                </div>

                <!-- 2FA AUTH -->
                <div style="
                background: white;
                padding:28px;
                border-radius:14px;
                display:flex;
                flex-direction:column;
                justify-content:space-between;
                min-height:230px;
                box-shadow:0 0 10px rgba(0,0,0,0.2);
            ">

                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                        <div style="font-size:26px;">🛡️</div>
                        <div style="color:#ffcc00; font-size:22px;">⚠</div>
                    </div>

                    <div>
                        <h3 style="margin:0; font-size:20px; font-weight:700;">Two-Factor Authentication</h3>
                        <p style="margin:5px 0 0 0; color:#bdbdbd; font-size:14px;">
                            Strengthen your account by enabling Two-Factor Authentication.
                        </p>
                    </div>
                    <?php if ($student['twofa_verified'] == 1): ?>
                        <button id="open2FASettings" <?php if ($student['twofa_verified'] == 1)
                            echo 'disabled style="background:#4cd964;color:#fff;cursor:not-allowed;opacity:0.7;"'; ?>>
                            <?php echo ($student['twofa_verified'] == 1 ? "2FA Enabled" : "Set Up 2FA"); ?>
                        </button>
                    <?php else: ?>
                        <button id="open2FASettings" style="margin-top:20px; background:red; padding:12px; border:none; 
        font-weight:700; color:black; border-radius:8px; font-size:14px;">
                            Enable 2FA
                        </button>
                    <?php endif; ?>

                </div>

            </div>

            <!-- 🔥 NEW NOTICE CONTAINER AT THE BOTTOM -->
            <div style="
    margin-top:30px;
    border: solid red 1px;
    padding:25px 30px;
    border-radius:14px;
    box-shadow:0 0 12px rgba(0,0,0,0.25);
    color:#333;
">
                <h3 style="margin:0 0 10px 0; color:red; font-size:20px; font-weight:700;">Security Notices</h3>

                <p style="margin:10px 0; color:white; font-size:14px; line-height:1.5; font-style:italic;">
                    <strong>Phone Number Verification</strong><br>
                    Verify that your phone number is accurate and reachable to confirm your identity,
                    protect your account, and ensure secure transactions.
                    Phone verification is <strong>required before any uniform purchases</strong> to prevent unauthorized
                    or mistaken orders.
                </p>

                <p style="margin:10px 0; color:white; font-size:14px; line-height:1.5; font-style:italic;">
                    <strong>Two-Factor Authentication (2FA)</strong><br>
                    Two-Factor Authentication gives you the option to log in using a one-time verification code
                    from Google Authenticator. This optional feature provides an additional layer of security and
                    a more secure alternative to password-only login.
                </p>
            </div>

        </div>
    </div>
    <!-- 🧾 ORDERS HISTORY MODAL -->
    <div id="ordersModal" class="simple-modal" aria-hidden="true">
        <div class="simple-panel" role="dialog" aria-modal="true">
            <div class="simple-header">
                <h3 class="buy-title">My Orders</h3>
                <button id="ordersClose" class="ck-close">×</button>
            </div>

            <div class="simple-body" style="max-height:70vh; overflow:auto;">
                <div id="ordersModalList"></div>
            </div>

            <div class="simple-actions">
                <button id="ordersDone" class="btn-primary">Done</button>
            </div>
        </div>
    </div>

    <!-- 2FA SETUP MODAL -->
    <div id="twoFAModal" class="simple-modal" style="display:none;">
        <div class="simple-panel">
            <div class="simple-header">
                <h3 class="buy-title">Two-Factor Authentication</h3>
                <button id="twoFAClose" class="ck-close">×</button>
            </div>

            <div class="simple-body" style="text-align:center;">
                <p style="margin-bottom:20px;">Set up Google Authenticator for stronger account security.</p>

                <!-- Initial Google Auth Setup Button -->
                <button id="setupGoogle2FA" class="btn-primary" style="width:100%;">
                    Set up Google Authenticator
                </button>

                <!-- Store Download Buttons (Hidden until clicked) -->
                <div id="appDownloadButtons" style="display:none; margin-top:20px; text-align:center;">

                    <div style="
        display:flex; 
        justify-content:center; 
        align-items:center; 
        gap:15px; 
        flex-wrap:wrap;
    ">

                        <!-- App Store -->
                        <button id="downloadAppStore" style="border:none; background:none; padding:0; display:flex;">
                            <img src="https://developer.apple.com/assets/elements/badges/download-on-the-app-store.svg"
                                alt="Download on the App Store" style="width:160px; cursor:pointer;">
                        </button>

                        <!-- Google Play -->
                        <button id="downloadGooglePlay" style="border:none; background:none; padding:0; display:flex;">
                            <img src="https://upload.wikimedia.org/wikipedia/commons/7/78/Google_Play_Store_badge_EN.svg"
                                alt="Get it on Google Play" style="width:180px; cursor:pointer;">
                        </button>

                    </div>
                </div>


                <!-- GOOGLE AUTH QR -->
                <div id="googleAuthSection" style="display:none; margin-top:20px; text-align:center;">
                    <h4 style="margin:0;">Scan this QR Code</h4>
                    <img id="googleQR" src="" style="width:220px; margin-top:10px;">
                    <input type="text" id="google2FACode" class="text-input" placeholder="Enter 6-digit code"
                        style="margin-top:15px;">
                    <button id="verifyGoogle2FA" class="btn-primary" style="margin-top:10px;">Verify</button>
                    <p id="googleAuthStatus" style="color:red; margin-top:10px;"></p>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="receiptModal">
        <div class="modal-dialog modal-lg">
            <div class="modal-content p-4" id="receiptContent"></div>
        </div>
    </div>



    <script>
/* ---------- PHP → JS GLOBALS ---------- */
const STUDENT_PHONE_E164 = <?php echo json_encode($student_phone); ?>;
const PHONE_VERIFIED     = <?php echo (int)($student['phone_verified'] ?? 0); ?>;
const TWOFA_VERIFIED     = <?php echo (int)($student['twofa_verified'] ?? 0); ?>;
const LOGIN_ID           = <?php echo json_encode($_SESSION['login_id'] ?? 0); ?>;

/* ---------- UTILITIES ---------- */
function parsePriceToNumber(str) {
    if (typeof str === 'number') return str;
    return parseFloat(String(str).replace(/[^\d.]/g, '')) || 0;
}
function show(el) {
    if (!el) return;
    el.classList.add('show');
    el.setAttribute('aria-hidden', 'false');
}
function hide(el) {
    if (!el) return;
    el.classList.remove('show');
    el.setAttribute('aria-hidden', 'true');
}
function escapeHtml(str) {
    return String(str || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/* ---------- GLOBAL STATE ---------- */
let cart = [];
let checkoutItems = [];
let orders = [];
let unreadNotifCount = 0;
let lastPlacedOrderMeta = null;

/* ---------- NOTIFICATION BADGE (BELL) ---------- */
const notifCountSpan = document.getElementById('notifCount');
function updateNotifBadge() {
    if (!notifCountSpan) return;
    notifCountSpan.textContent = unreadNotifCount;
    notifCountSpan.style.display = unreadNotifCount > 0 ? 'inline-block' : 'none';
}

/* ---------- SIDEBAR & OVERLAY ---------- */
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('overlay');
const sidebarToggle = document.getElementById('sidebarToggle');

if (sidebarToggle) {
    sidebarToggle.addEventListener('click', () => {
        sidebar.classList.toggle('open');
        overlay.classList.toggle('show');
    });
}
if (overlay) {
    overlay.addEventListener('click', () => {
        sidebar.classList.remove('open');
        overlay.classList.remove('show');
    });
}

/* ---------- CATEGORY FILTER + SEARCH ---------- */
const categories    = document.querySelectorAll('.category');
const productCards  = document.querySelectorAll('.product-card');
const productGrid   = document.querySelector('.product-grid');
const searchInput   = document.getElementById('searchInput');

categories.forEach(btn => {
    btn.addEventListener('click', () => {
        const category = btn.dataset.category;
        productCards.forEach(card => {
            const ok = (category === 'all' || card.dataset.category === category);
            card.style.display = ok ? 'block' : 'none';
        });
        if (productGrid) productGrid.style.justifyContent = 'flex-start';
    });
});

if (searchInput) {
    searchInput.addEventListener('input', () => {
        const q = searchInput.value.toLowerCase();
        productCards.forEach(card => {
            const n = (card.dataset.name || '').toLowerCase();
            card.style.display = n.includes(q) ? 'block' : 'none';
        });
        if (productGrid) productGrid.style.justifyContent = 'flex-start';
    });
}

/* ---------- PROFILE MODAL ---------- */
const profileBtn       = document.getElementById('profileBtn');
const profileModal     = document.getElementById('profileFormModal');
const closeProfileForm = document.getElementById('closeProfileForm');
const profilePicInput  = document.getElementById('profilePicInput');
const modalProfilePic  = document.getElementById('modalProfilePic');
const sidebarProfilePic= document.getElementById('sidebarProfilePic');

if (profileBtn && profileModal) {
    profileBtn.addEventListener('click', () => {
        profileModal.style.display = 'block';
    });
}
if (closeProfileForm && profileModal) {
    closeProfileForm.addEventListener('click', () => {
        profileModal.style.display = 'none';
    });
}
window.addEventListener('click', e => {
    if (e.target === profileModal) profileModal.style.display = 'none';
});

if (profilePicInput && modalProfilePic && sidebarProfilePic) {
    profilePicInput.addEventListener('change', e => {
        const f = e.target.files[0];
        if (!f) return;
        const r = new FileReader();
        r.onload = ev => {
            modalProfilePic.src   = ev.target.result;
            sidebarProfilePic.src = ev.target.result;
        };
        r.readAsDataURL(f);
    });
}

/* ---------- SIGN OUT ---------- */
const signoutBtn = document.getElementById('signoutBtn');
if (signoutBtn) {
    signoutBtn.addEventListener('click', () => {
        if (confirm('Are you sure you want to sign out?')) {
            window.location.href = 'logout.php';
        }
    });
}

/* ---------- PRODUCT DETAIL MODAL + CAROUSEL ---------- */
const pdModal      = document.getElementById('productDetailModal');
const pdClose      = document.getElementById('pdClose');
const pdTitle      = document.getElementById('pdTitle');
const pdName       = document.getElementById('pdName');
const pdPrice      = document.getElementById('pdPrice');
const pdDesc       = document.getElementById('pdDesc');
const pdBuyNow     = document.getElementById('pdBuyNow');
const pdAddToCart  = document.getElementById('pdAddToCart');
const cTrack       = document.getElementById('cTrack');
const cDots        = document.getElementById('cDots');
const cPrev        = document.getElementById('cPrev');
const cNext        = document.getElementById('cNext');

let cIndex = 0;
let cImages = [];
let currentProductName = '';
let currentProductPrice = '';
let currentProductImage = '';
let currentDescription = '';
let currentSizes = [];
let currentItemId = null;
let currentBuyMode = 'buynow';
let selectedSize  = '';
let selectedStock = 0;
let selectedQty   = 1;

function updateCarousel(jump = false) {
    if (!cTrack) return;
    const pct = -cIndex * 100;
    cTrack.style.transition = jump ? 'none' : 'transform .35s ease';
    cTrack.style.transform  = `translateX(${pct}%)`;
    if (cDots) {
        [...cDots.children].forEach((dot, i) => {
            dot.classList.toggle('active', i === cIndex);
        });
    }
}
function nextSlide() {
    if (!cImages.length) return;
    cIndex = (cIndex + 1) % cImages.length;
    updateCarousel();
}
function prevSlide() {
    if (!cImages.length) return;
    cIndex = (cIndex - 1 + cImages.length) % cImages.length;
    updateCarousel();
}
if (cNext) cNext.addEventListener('click', nextSlide);
if (cPrev) cPrev.addEventListener('click', prevSlide);

function parseSizes(attr) {
    if (!attr) return [];
    return attr
        .split(',')
        .map(s => s.trim())
        .filter(Boolean)
        .map(p => {
            const [labelRaw, countRaw] = p.split(':');
            const label = (labelRaw || '').trim();
            const count = parseInt((countRaw || '0').replace(/\D/g, ''), 10) || 0;
            return { label, count };
        });
}

function openProductDetail(opts) {
    if (!pdModal || !cTrack || !cDots) return;
    const { id, name, price, images, description, sizes } = opts;

    currentItemId        = id;
    currentProductName   = name;
    currentProductPrice  = price;
    currentDescription   = description && description.trim()
        ? description.trim()
        : 'This item is presented with a formal description.';
    currentSizes         = sizes || [];
    selectedSize         = '';
    selectedStock        = 0;
    selectedQty          = 1;

    pdTitle.textContent  = name;
    pdName.textContent   = name;
    pdPrice.textContent  = price;
    pdDesc.textContent   = currentDescription;

    cImages = images && images.length ? images : [];
    currentProductImage = cImages[0] || '';

    cTrack.innerHTML = '';
    cDots.innerHTML  = '';
    cIndex           = 0;

    cImages.forEach((src, i) => {
        const slide = document.createElement('div');
        slide.className = 'carousel-slide';
        const img = document.createElement('img');
        img.src = src.trim();
        img.alt = `${name} ${i + 1}`;
        img.draggable = false;
        slide.appendChild(img);
        cTrack.appendChild(slide);

        const dot = document.createElement('span');
        dot.className = 'carousel-dot' + (i === 0 ? ' active' : '');
        dot.dataset.index = i;
        dot.addEventListener('click', () => {
            cIndex = i;
            updateCarousel();
        });
        cDots.appendChild(dot);
    });

    updateCarousel(true);
    pdModal.classList.add('show');
    pdModal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}
function closeProductDetail() {
    if (!pdModal) return;
    pdModal.classList.remove('show');
    pdModal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

if (pdClose) {
    pdClose.addEventListener('click', closeProductDetail);
}
if (pdModal) {
    pdModal.addEventListener('click', e => {
        if (e.target === pdModal) closeProductDetail();
    });
}
window.addEventListener('keydown', e => {
    if (!pdModal || !pdModal.classList.contains('show')) return;
    if (e.key === 'Escape') closeProductDetail();
    if (e.key === 'ArrowRight') nextSlide();
    if (e.key === 'ArrowLeft')  prevSlide();
});

/* Product card → open detail */
document.querySelectorAll('.product-card').forEach(card => {
    card.addEventListener('click', () => {
        const name  = card.dataset.name  || card.querySelector('.product-name')?.textContent?.trim()  || 'Product';
        const price = card.dataset.price || card.querySelector('.product-price')?.textContent?.trim() || '';
        const desc  = card.dataset.description || '';
        const imagesAttr = card.dataset.images || '';
        const sizesAttr  = card.dataset.sizes  || '';
        const itemId     = card.dataset.itemId || card.getAttribute('data-item-id') || '';

        let imgs = [];
        if (imagesAttr) {
            imgs = imagesAttr
                .split('||')
                .map(s => s.trim())
                .filter(Boolean);
        }
        if (!imgs.length) {
            const single = card.querySelector('.product-img')?.getAttribute('src') || '';
            if (single) imgs = [single, single, single];
        }

        const sizes = parseSizes(sizesAttr);

        openProductDetail({
            id: itemId,
            name,
            price,
            description: desc,
            images: imgs,
            sizes
        });
    });
});

/* ---------- BUY MODAL ---------- */
const buyModal    = document.getElementById('buyModal');
const buyClose    = document.getElementById('buyClose');
const buyCancel   = document.getElementById('buyCancel');
const buyProceed  = document.getElementById('buyProceed');
const sizeList    = document.getElementById('sizeList');
const qtyMinus    = document.getElementById('qtyMinus');
const qtyPlus     = document.getElementById('qtyPlus');
const qtyValue    = document.getElementById('qtyValue');

function updateQtyDisplay() {
    if (qtyValue) qtyValue.textContent = selectedQty;
}
if (qtyMinus && qtyPlus) {
    qtyMinus.addEventListener('click', () => {
        if (!selectedStock) return;
        if (selectedQty > 1) {
            selectedQty--;
            updateQtyDisplay();
        }
    });
    qtyPlus.addEventListener('click', () => {
        if (!selectedStock) return;
        if (selectedQty < selectedStock) {
            selectedQty++;
            updateQtyDisplay();
        }
    });
}

function openBuyModal(mode) {
    if (!buyModal || !sizeList) return;
    currentBuyMode = mode || 'buynow';

    sizeList.innerHTML = '';
    selectedSize  = '';
    selectedStock = 0;
    selectedQty   = 1;
    updateQtyDisplay();
    if (buyProceed) buyProceed.disabled = true;

    currentSizes.forEach(opt => {
        const row = document.createElement('div');
        row.className = 'size-item';

        const left = document.createElement('div');
        left.className = 'size-left';

        const radio = document.createElement('input');
        radio.type = 'radio';
        radio.className = 'size-radio';
        radio.name = 'sizeopt';
        radio.value = opt.label;
        radio.id    = `size-${opt.label}`;
        radio.disabled = opt.count <= 0;

        const lbl = document.createElement('label');
        lbl.className = 'size-label';
        lbl.setAttribute('for', `size-${opt.label}`);
        lbl.textContent = opt.label;

        left.appendChild(radio);
        left.appendChild(lbl);

        const stk = document.createElement('div');
        stk.className = 'size-stock';
        stk.textContent = `${opt.count} pcs`;

        row.appendChild(left);
        row.appendChild(stk);

        radio.addEventListener('change', () => {
            selectedSize  = opt.label;
            selectedStock = opt.count;
            selectedQty   = 1;
            updateQtyDisplay();
            if (buyProceed) buyProceed.disabled = false;
        });

        sizeList.appendChild(row);
    });

    show(buyModal);
}
function closeBuyModal() {
    hide(buyModal);
}
if (buyClose)  buyClose.addEventListener('click', closeBuyModal);
if (buyCancel) buyCancel.addEventListener('click', closeBuyModal);

if (pdBuyNow) {
    pdBuyNow.addEventListener('click', e => {
        e.stopPropagation();
        openBuyModal('buynow');
    });
}
if (pdAddToCart) {
    pdAddToCart.addEventListener('click', e => {
        e.stopPropagation();
        openBuyModal('cart');
    });
}

/* ---------- CART ---------- */
const cartModal     = document.getElementById('cartModal');
const cartToggleBtn = document.getElementById('cartToggleBtn');
const cartClose     = document.getElementById('cartClose');
const cartClear     = document.getElementById('cartClear');
const cartCheckout  = document.getElementById('cartCheckout');
const cartItemsEl   = document.getElementById('cartItems');
const cartEmptyText = document.getElementById('cartEmptyText');
const cartCountSpan = document.getElementById('cartCount');

function updateCartBadge() {
    if (!cartCountSpan) return;
    const count = cart.length;
    cartCountSpan.textContent = count;
    cartCountSpan.style.display = count > 0 ? 'inline-block' : 'none';
}
function renderCart() {
    if (!cartItemsEl) return;
    cartItemsEl.innerHTML = '';
    if (!cart.length) {
        if (cartEmptyText) cartEmptyText.style.display = 'block';
        return;
    }
    if (cartEmptyText) cartEmptyText.style.display = 'none';

    cart.forEach((item, index) => {
        const row = document.createElement('div');
        row.className = 'cart-item';
        row.dataset.index = index;

        const chk = document.createElement('input');
        chk.type = 'checkbox';
        chk.className = 'cart-select';

        const img = document.createElement('img');
        img.src = item.image || 'logo.png';
        img.alt = item.name || 'Item';

        const mid = document.createElement('div');
        const name = document.createElement('div');
        name.className = 'cart-item-name';
        name.textContent = item.name || '';

        const meta = document.createElement('div');
        meta.className = 'cart-item-meta';
        meta.textContent =
            (item.size ? `Size: ${item.size} · ` : '') +
            (item.price || `₱${(item.unit_price || 0).toFixed(2)}`);

        const qtyInfo = document.createElement('div');
        qtyInfo.style.fontSize = '13px';
        qtyInfo.style.color = '#666';
        qtyInfo.textContent = `Quantity: ${item.qty || 1}`;

        mid.appendChild(name);
        mid.appendChild(meta);
        mid.appendChild(qtyInfo);

        const rm = document.createElement('button');
        rm.className = 'cart-remove';
        rm.textContent = 'Remove';
        rm.addEventListener('click', () => {
            cart.splice(index, 1);
            renderCart();
            updateCartBadge();
        });

        row.appendChild(chk);
        row.appendChild(img);
        row.appendChild(mid);
        row.appendChild(rm);
        cartItemsEl.appendChild(row);
    });
}

function addCurrentSelectionToCart() {
    if (!currentProductName || !selectedSize) return;
    const unit = parsePriceToNumber(currentProductPrice);
    cart.push({
        item_id: currentItemId,
        name: currentProductName,
        size: selectedSize,
        qty: selectedQty,
        unit_price: unit,
        price: '₱' + unit.toFixed(2),
        image: currentProductImage
    });
    updateCartBadge();
    renderCart();
}

/* buyProceed → add to cart or open checkout */
if (buyProceed) {
    buyProceed.addEventListener('click', () => {
        if (!selectedSize) return;
        if (!selectedStock || selectedQty < 1 || selectedQty > selectedStock) {
            alert('Invalid quantity selected.');
            return;
        }

        const unit = parsePriceToNumber(currentProductPrice);

        if (currentBuyMode === 'cart') {
            addCurrentSelectionToCart();
            closeBuyModal();
            alert('Added to cart.');
            return;
        }

        checkoutItems = [{
            source: 'single',
            cart_id: null,
            item_id: currentItemId,
            name: currentProductName,
            size: selectedSize,
            qty: selectedQty,
            unitPrice: unit,
            image: currentProductImage
        }];

        closeBuyModal();
        closeProductDetail();
        openCheckoutModalWithItems();
    });
}

/* cart modal controls */
if (cartToggleBtn && cartModal) {
    cartToggleBtn.addEventListener('click', () => {
        renderCart();
        show(cartModal);
    });
}
if (cartClose) {
    cartClose.addEventListener('click', () => hide(cartModal));
}
if (cartModal) {
    cartModal.addEventListener('click', e => {
        if (e.target === cartModal) hide(cartModal);
    });
}
if (cartClear) {
    cartClear.addEventListener('click', () => {
        cart = [];
        renderCart();
        updateCartBadge();
    });
}
if (cartCheckout) {
    cartCheckout.addEventListener('click', () => {
        if (!cart.length) return;
        const rows = Array.from(cartItemsEl.querySelectorAll('.cart-item'));
        const selectedIndexes = [];
        rows.forEach(row => {
            const chk = row.querySelector('.cart-select');
            if (chk && chk.checked) selectedIndexes.push(parseInt(row.dataset.index, 10));
        });
        if (!selectedIndexes.length) {
            alert('Please select at least one item to checkout.');
            return;
        }

        checkoutItems = selectedIndexes.map(i => {
            const it   = cart[i];
            const unit = parsePriceToNumber(it.price || it.unit_price || 0);
            return {
                source: 'cart',
                cart_id: it.cart_id || null,
                item_id: it.item_id || null,
                name: it.name,
                size: it.size,
                qty: it.qty || 1,
                unitPrice: unit,
                image: it.image
            };
        });

        hide(cartModal);
        openCheckoutModalWithItems();
    });
}

/* ---------- CHECKOUT MODAL ---------- */
const ckModal         = document.getElementById('checkoutModal');
const ckClose         = document.getElementById('ckClose');
const ckPlaceOrderBtn = document.getElementById('ckPlaceOrder');
const ckPayRadios     = document.querySelectorAll('input[name="paymethod"]');

function openCheckoutModalWithItems() {
    const list    = document.getElementById('ckItemsList');
    const totalEl = document.getElementById('ckTotal');
    if (!list || !totalEl) return;

    list.innerHTML = '';
    let total = 0;

    checkoutItems.forEach(item => {
        const unit = item.unitPrice || item.unit_price || parsePriceToNumber(item.price || 0);
        const qty  = item.qty || 1;
        const line = unit * qty;
        total += line;

        const card = document.createElement('div');
        card.className = 'ck-card';

        const img = document.createElement('img');
        img.src = item.image || 'logo.png';
        img.alt = item.name || 'Item';

        const right = document.createElement('div');
        const nm = document.createElement('p');
        nm.className = 'ck-name';
        nm.textContent = item.name + (item.size ? ` • ${item.size}` : '');

        const meta = document.createElement('p');
        meta.className = 'ck-meta';
        meta.textContent = `${qty}x @ ₱${unit.toFixed(2)}`;

        const pr = document.createElement('div');
        pr.className = 'ck-price';
        pr.textContent = '₱' + line.toFixed(2);

        right.appendChild(nm);
        right.appendChild(meta);
        right.appendChild(pr);

        card.appendChild(img);
        card.appendChild(right);
        list.appendChild(card);
    });

    totalEl.textContent = '₱' + total.toFixed(2);
    show(ckModal);
}
function closeCheckout() {
    hide(ckModal);
}
if (ckClose) ckClose.addEventListener('click', closeCheckout);
if (ckModal) {
    ckModal.addEventListener('click', e => {
        if (e.target === ckModal) closeCheckout();
    });
}

/* ---------- RECEIPT MODAL (BOOTSTRAP) ---------- */
function showReceiptModalFromItems(orderMeta, itemsSource) {
    const rc = document.getElementById('receiptContent');
    if (!rc) return;

    const items = itemsSource || checkoutItems || [];
    let rowsHtml = '';
    let total = 0;

    items.forEach(it => {
        const name = it.name || '';
        const size = it.size || '';
        const qty  = it.qty  || 1;
        const unit = it.unitPrice || it.unit_price ||
                     parsePriceToNumber(it.price || it.totalPrice || 0) / (it.qty || 1);
        const line = unit * qty;
        total += line;

        rowsHtml += `
            <tr>
                <td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">${escapeHtml(name)}</td>
                <td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:center;">${escapeHtml(size)}</td>
                <td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:center;">${qty}x</td>
                <td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:right;">₱${unit.toFixed(2)}</td>
                <td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:right;">₱${line.toFixed(2)}</td>
            </tr>
        `;
    });

    const nowStr        = new Date().toLocaleString();
    const statusLabel   = (orderMeta && orderMeta.status ? orderMeta.status : 'pending').toUpperCase();
    const paymentMethod = (orderMeta && (orderMeta.method || orderMeta.payment_method)) || 'GCash';
    const orderRef      = (orderMeta && (orderMeta.order_num || orderMeta.id))
        ? String(orderMeta.order_num || orderMeta.id)
        : 'N/A';
    const siRef         = (orderMeta && (orderMeta.si || orderMeta.payment_no))
        ? String(orderMeta.si || orderMeta.payment_no)
        : 'N/A';

    rc.innerHTML = `
        <div style="text-align:center;margin-bottom:10px;">
            <img src="logo.png" style="height:60px;margin-bottom:6px;">
            <h4 style="margin:0;font-weight:800;">RESOURCE GENERATION OFFICE</h4>
            <div style="font-size:13px;color:#6b7280;">Batangas State University - Lipa Campus</div>
        </div>
        <hr>
        <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:14px;">
            <div>
                <div><strong>Order No:</strong> ${escapeHtml(orderRef)}</div>
                <div><strong>SI No:</strong> ${escapeHtml(siRef)}</div>
                <div><strong>Payment Method:</strong> ${escapeHtml(paymentMethod)}</div>
                <div><strong>Status:</strong> ${escapeHtml(statusLabel)}</div>
            </div>
            <div>
                <div><strong>Date:</strong> ${escapeHtml(nowStr)}</div>
            </div>
        </div>
        <table style="width:100%;border-collapse:collapse;font-size:14px;margin-top:8px;">
            <thead>
                <tr>
                    <th style="text-align:left;padding:6px 8px;border-bottom:1px solid #d1d5db;">Item</th>
                    <th style="text-align:center;padding:6px 8px;border-bottom:1px solid #d1d5db;">Size</th>
                    <th style="text-align:center;padding:6px 8px;border-bottom:1px solid #d1d5db;">Qty</th>
                    <th style="text-align:right;padding:6px 8px;border-bottom:1px solid #d1d5db;">Unit</th>
                    <th style="text-align:right;padding:6px 8px;border-bottom:1px solid #d1d5db;">Total</th>
                </tr>
            </thead>
            <tbody>${rowsHtml}</tbody>
            <tfoot>
                <tr>
                    <td colspan="4" style="padding:8px 8px;text-align:right;font-weight:800;">TOTAL</td>
                    <td style="padding:8px 8px;text-align:right;font-weight:800;">₱${total.toFixed(2)}</td>
                </tr>
            </tfoot>
        </table>
        <div style="margin-top:12px;font-size:12px;color:#6b7280;">
            This receipt is system-generated and marked as <strong>${escapeHtml(statusLabel)}</strong>.
            Final confirmation will be set by the RGO admin.
        </div>
    `;

    const modalEl = document.getElementById('receiptModal');
    if (modalEl && window.bootstrap && window.bootstrap.Modal) {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
    }
}

/* ---------- GCash QR PAYMENT (notifModal) ---------- */
const qrPaymentModal  = document.getElementById('notifModal');
const qrPaymentClose  = document.getElementById('notifClose');
const qrPaymentCancel = document.getElementById('qrDone');
const qrWrapPayment   = qrPaymentModal ? qrPaymentModal.querySelector('#qrWrap') : null;
const qrProofFile     = document.getElementById('qrProofFile');
const qrProofError    = document.getElementById('qrProofError');
const qrConfirmBtn    = document.getElementById('qrConfirm');

function showQrForOrder() {
    if (!qrPaymentModal || !qrWrapPayment) return;
    qrWrapPayment.innerHTML = '';

    const qrImages = ['qr-1.jpg', 'qr-2.jpg', 'qr-3.jpg'];
    const picked   = qrImages[Math.floor(Math.random() * qrImages.length)];

    const img = document.createElement('img');
    img.src = picked;
    img.alt = 'Payment QR';
    qrWrapPayment.appendChild(img);

    if (qrProofFile)  qrProofFile.value = '';
    if (qrProofError) qrProofError.textContent = '';

    show(qrPaymentModal);
}
if (qrPaymentClose)  qrPaymentClose.addEventListener('click', () => hide(qrPaymentModal));
if (qrPaymentCancel) qrPaymentCancel.addEventListener('click', () => hide(qrPaymentModal));

if (qrConfirmBtn) {
    qrConfirmBtn.addEventListener('click', () => {
        if (!qrProofFile || !qrProofFile.files.length) {
            if (qrProofError) qrProofError.textContent = 'Please attach a screenshot/image of your GCash payment.';
            return;
        }
        if (qrProofError) qrProofError.textContent = '';

        const meta = lastPlacedOrderMeta || {
            id: 'N/A',
            order_num: '',
            payment_no: '',
            method: 'GCash',
            status: 'pending'
        };

        hide(qrPaymentModal);
        showReceiptModalFromItems(meta, checkoutItems);
    });
}

/* bell currently just clears badge */
const notifToggleBtn = document.getElementById('notifToggleBtn');
if (notifToggleBtn) {
    notifToggleBtn.addEventListener('click', () => {
        alert('Notifications will appear here soon.');
        unreadNotifCount = 0;
        updateNotifBadge();
    });
}

/* ---------- PLACE ORDER (AJAX) ---------- */
if (ckPlaceOrderBtn) {
    ckPlaceOrderBtn.addEventListener('click', async () => {
        const method = [...ckPayRadios].find(r => r.checked)?.value || '';
        if (!method) {
            alert('Please select a payment method.');
            return;
        }
        if (!checkoutItems.length) {
            alert('No items to checkout.');
            return;
        }

        ckPlaceOrderBtn.disabled = true;

        try {
            const fd = new FormData();
            fd.append('action', 'place_order');
            fd.append('method', method);
            fd.append('items', JSON.stringify(checkoutItems));

            const res  = await fetch(window.location.href, { method: 'POST', body: fd });
            const data = await res.json();

            if (!data.ok) {
                alert(data.msg || 'Failed to place order.');
                ckPlaceOrderBtn.disabled = false;
                return;
            }

            const orderId   = data.order_id;
            const orderNum  = data.order_num;
            const paymentNo = data.payment_no;

            lastPlacedOrderMeta = {
                id: orderId,
                order_num: orderNum,
                payment_no: paymentNo,
                method,
                status: 'pending'
            };

            // reload orders from server so My Orders & Orders page sync
            await loadOrdersFromServer();

            closeCheckout();

            if (method === 'GCash') {
                showQrForOrder();
            } else {
                showReceiptModalFromItems(lastPlacedOrderMeta, checkoutItems);
            }

            // clear cart items that came from the cart
            if (checkoutItems.some(it => it.source === 'cart')) {
                const remaining = [];
                cart.forEach(cItem => {
                    const used = checkoutItems.find(ci =>
                        ci.source === 'cart' &&
                        ci.item_id === cItem.item_id &&
                        ci.size    === cItem.size &&
                        ci.qty     === cItem.qty
                    );
                    if (!used) remaining.push(cItem);
                });
                cart = remaining;
                renderCart();
                updateCartBadge();
            }

            checkoutItems = [];
        } catch (err) {
            console.error('place_order error', err);
            alert('Unexpected error while placing order.');
        } finally {
            ckPlaceOrderBtn.disabled = false;
        }
    });
}

/* ---------- ORDERS PAGE SECTION ---------- */
const ordersSection     = document.getElementById('ordersSection');
const ordersList        = document.getElementById('ordersList');
const ordersCountLabel  = document.getElementById('ordersCountLabel');
const backToShop        = document.getElementById('backToShop');
const categoryBar       = document.querySelector('.category-bar');
const productSection    = document.querySelector('.product-section');

function fmtShortDate(d) {
    return d.toLocaleDateString(undefined, {
        day: '2-digit',
        month: 'short',
        year: 'numeric'
    });
}

async function loadOrdersFromServer() {
    try {
        const fd = new FormData();
        fd.append('action', 'get_orders');

        const res  = await fetch(window.location.href, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) {
            console.error('get_orders failed:', data.msg);
            return [];
        }

        orders = (data.orders || []).map(o => {
            const first   = (o.items && o.items[0]) || {};
            const created = new Date(o.created_at);
            const eta     = new Date(created.getTime() + 3 * 24 * 60 * 60 * 1000);
            const sumAmt  = o.amount != null ? o.amount : 0;

            return {
                id: o.order_id,
                order_num: o.order_num,
                payment_no: o.payment_no,
                name: first.name || '',
                size: first.size || '',
                price: '₱' + sumAmt.toFixed(2),
                method: o.payment_method || 'GCash',
                status: "IT'S ORDERED!",
                date: created,
                eta: eta,
                image: first.image || 'logo.png',
                items: o.items || []
            };
        });

        renderOrders();
        return orders;
    } catch (err) {
        console.error('loadOrdersFromServer error', err);
        return [];
    }
}

function renderOrders() {
    if (!ordersList || !ordersCountLabel) return;
    ordersList.innerHTML = '';
    ordersCountLabel.textContent = `Displaying ${orders.length} of ${orders.length} orders`;

    orders.forEach(o => {
        const card = document.createElement('div');
        card.className = 'order-card';

        const im = document.createElement('img');
        im.src   = o.image || 'logo.png';
        im.alt   = o.name || 'Item';

        const right = document.createElement('div');

        const st = document.createElement('div');
        st.className = 'order-status';
        st.textContent = 'ORDER STATUS: ' + (o.status || "IT'S ORDERED!");

        const est = document.createElement('div');
        est.className = 'order-est';
        est.textContent = 'Estimated delivery ' +
            (o.eta ? o.eta.toLocaleDateString(undefined, {
                weekday: 'long',
                day: 'numeric',
                month: 'long',
                year: 'numeric'
            }) : '');

        const nm = document.createElement('div');
        nm.textContent = (o.name || '') + (o.size ? ` • ${o.size}` : '');

        const pr = document.createElement('div');
        pr.style.fontWeight = '800';
        pr.style.color      = '#ee4d2d';
        pr.textContent      = o.price || '';

        const meta = document.createElement('div');
        meta.className = 'order-meta';
        meta.innerHTML = `
            <small>ORDER NO.: ${escapeHtml(o.order_num || String(o.id))}</small>
            <small>SI NO.: ${escapeHtml(o.payment_no || '')}</small>
            <small>ORDER DATE: ${fmtShortDate(o.date)}</small>
            <small>PAYMENT: ${escapeHtml(o.method || '')}</small>
        `;

        const actions = document.createElement('div');
        actions.className = 'order-actions';

        const viewReceiptBtn = document.createElement('button');
        viewReceiptBtn.className = 'btn-line';
        viewReceiptBtn.textContent = 'View Receipt';
        viewReceiptBtn.addEventListener('click', () => {
            const mappedItems = o.items && o.items.length
                ? o.items.map(it => ({
                    name: it.name,
                    size: it.size,
                    qty:  it.qty,
                    unitPrice: it.unitPrice || it.unit_price ||
                        parsePriceToNumber(it.totalPrice || 0) / (it.qty || 1),
                    image: it.image
                }))
                : [{
                    name: o.name,
                    size: o.size,
                    qty:  1,
                    unitPrice: parsePriceToNumber(o.price || 0),
                    image: o.image
                }];

            showReceiptModalFromItems(
                {
                    id: o.id,
                    order_num: o.order_num,
                    payment_no: o.payment_no,
                    status: o.status || 'pending',
                    method: o.method
                },
                mappedItems
            );
        });

        const cancelOrderBtn = document.createElement('button');
        cancelOrderBtn.className = 'btn-line';
        cancelOrderBtn.textContent = 'Cancel Order';
        cancelOrderBtn.addEventListener('click', () => {
            if (o.status === 'Canceled' || o.status === 'ORDER CANCELLED') {
                alert('This order is already canceled.');
                return;
            }
            if (!confirm('Are you sure you want to cancel this order?')) return;
            o.status = 'ORDER CANCELLED';
            alert('Your order has been cancelled.');
            renderOrders();
        });

        actions.appendChild(viewReceiptBtn);
        actions.appendChild(cancelOrderBtn);

        right.appendChild(st);
        right.appendChild(est);
        right.appendChild(nm);
        right.appendChild(pr);
        right.appendChild(meta);
        right.appendChild(actions);

        card.appendChild(im);
        card.appendChild(right);
        ordersList.appendChild(card);
    });
}

function showOrdersPage() {
    if (productSection) productSection.style.display = 'none';
    if (ordersSection) {
        ordersSection.style.display = 'block';
        ordersSection.setAttribute('aria-hidden', 'false');
    }
    if (categoryBar) categoryBar.style.display = 'none';
}
function hideOrdersPage() {
    if (ordersSection) {
        ordersSection.style.display = 'none';
        ordersSection.setAttribute('aria-hidden', 'true');
    }
    if (productSection) productSection.style.display = 'block';
    if (categoryBar) categoryBar.style.display = 'flex';
}
if (backToShop) backToShop.addEventListener('click', hideOrdersPage);

/* ---------- ORDERS HISTORY MODAL ---------- */
const ordersModal     = document.getElementById('ordersModal');
const ordersModalList = document.getElementById('ordersModalList');
const ordersClose     = document.getElementById('ordersClose');
const ordersDone      = document.getElementById('ordersDone');

async function showOrdersModal() {
    if (!ordersModal || !ordersModalList) return;

    ordersModalList.innerHTML = '<p>Loading orders...</p>';

    const latestOrders = await loadOrdersFromServer();

    ordersModalList.innerHTML = '';

    if (!latestOrders || !latestOrders.length) {
        ordersModalList.innerHTML = '<p>No previous orders found.</p>';
    } else {
        latestOrders.forEach(o => {
            const card = document.createElement('div');
            card.style.cssText = `
                background:#fff;
                padding:12px;
                border-radius:10px;
                margin-bottom:12px;
                border:1px solid #ddd;
            `;
            card.innerHTML = `
                <img src="${o.image || 'logo.png'}"
                     style="width:120px;height:120px;object-fit:cover;border-radius:10px;margin-bottom:15px;">
                <strong>${escapeHtml(o.name)}</strong> (${escapeHtml(o.size || '')})<br>
                <span style="color:#ee4d2d;font-weight:bold;">${escapeHtml(o.price || '')}</span><br>
                <small>Order No: ${escapeHtml(o.order_num || String(o.id))}</small><br>
                <small>SI No: ${escapeHtml(o.payment_no || '')}</small><br>
                <small>Status: <span id="status-${escapeHtml(String(o.id))}">${escapeHtml(o.status || '')}</span></small><br>
                <small>Order Date: ${o.date.toLocaleDateString()}</small><br><br>

                <button class="btn-primary view-receipt" data-id="${escapeHtml(String(o.id))}"
                    style="width:100%;margin-bottom:8px;">
                    View Receipt
                </button>

                <button class="btn-secondary cancel-order" data-id="${escapeHtml(String(o.id))}"
                    style="width:100%;background:white;color:red;border:1px solid black;">
                    Cancel Order
                </button>
            `;
            ordersModalList.appendChild(card);
        });
    }

    show(ordersModal);
}
function hideOrdersModal() {
    hide(ordersModal);
}
if (ordersClose) ordersClose.addEventListener('click', hideOrdersModal);
if (ordersDone)  ordersDone.addEventListener('click', hideOrdersModal);
if (ordersModal) {
    ordersModal.addEventListener('click', e => {
        if (e.target === ordersModal) hideOrdersModal();
    });
}

/* Sidebar "My Orders" + "Contact RGO" */
if (sidebar) {
    sidebar.addEventListener('click', e => {
        const t = e.target;
        if (!t.classList.contains('sidebar-btn')) return;
        const text = (t.textContent || '').toLowerCase();

        if (text.includes('my orders')) {
            showOrdersModal();
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
        }
        if (text.includes('contact rgo')) {
            openChatWidget();
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
        }
    });
}

/* orders modal buttons (delegated) */
document.addEventListener('click', e => {
    if (e.target.classList.contains('view-receipt')) {
        const orderId = e.target.getAttribute('data-id');
        const order   = orders.find(o => String(o.id) === String(orderId));
        if (!order) return;

        const mappedItems = order.items && order.items.length
            ? order.items.map(it => ({
                name: it.name,
                size: it.size,
                qty:  it.qty,
                unitPrice: it.unitPrice || it.unit_price ||
                    parsePriceToNumber(it.totalPrice || 0) / (it.qty || 1),
                image: it.image
            }))
            : [{
                name: order.name,
                size: order.size,
                qty:  1,
                unitPrice: parsePriceToNumber(order.price || 0),
                image: order.image
            }];

        showReceiptModalFromItems(
            {
                id: order.id,
                order_num: order.order_num,
                payment_no: order.payment_no,
                status: order.status || 'pending',
                method: order.method
            },
            mappedItems
        );
    }

    if (e.target.classList.contains('cancel-order')) {
        const orderId = e.target.getAttribute('data-id');
        const order   = orders.find(o => String(o.id) === String(orderId));
        if (!order) return;

        if (order.status === 'Canceled' || order.status === 'ORDER CANCELLED') {
            alert('This order is already canceled.');
            return;
        }
        if (!confirm('Are you sure you want to cancel this order?')) return;

        order.status = 'Canceled';
        const statusElement = document.getElementById(`status-${orderId}`);
        if (statusElement) statusElement.textContent = 'Canceled';
        e.target.disabled  = true;
        e.target.textContent = 'Canceled';
        alert('Order successfully canceled.');
    }
});

/* ---------- SECURITY MODAL + PHONE OTP ---------- */
const securityModal        = document.getElementById('securityModal');
const openSecurityModalBtn = document.getElementById('openSecurityModal');
const closeSecurityModalBtn= document.getElementById('closeSecurityModal');

if (openSecurityModalBtn && securityModal) {
    openSecurityModalBtn.addEventListener('click', () => {
        securityModal.style.display = 'block';
    });
}
if (closeSecurityModalBtn && securityModal) {
    closeSecurityModalBtn.addEventListener('click', () => {
        securityModal.style.display = 'none';
    });
}
window.addEventListener('click', e => {
    if (e.target === securityModal) securityModal.style.display = 'none';
});

/* phone verify & OTP */
const openPhoneVerify = document.getElementById('openPhoneVerify');
const gcashModal      = document.getElementById('gcashNumberModal');
const gcashMobile     = document.getElementById('gcashMobile');
const gcashSendStatus = document.getElementById('gcashSendStatus');
const gcashClose      = document.getElementById('gcashClose');
const gcashCancel     = document.getElementById('gcashCancel');
const gcashSendBtn    = document.getElementById('gcashSendBtn');

function restoreSecurityOpacity() {
    if (securityModal) securityModal.style.opacity = '1';
}

if (openPhoneVerify && gcashModal) {
    openPhoneVerify.addEventListener('click', () => {
        gcashMobile.value = STUDENT_PHONE_E164 || '';
        gcashSendStatus.textContent = '';
        if (securityModal) securityModal.style.opacity = '0.4';
        gcashModal.style.zIndex = '5000';
        show(gcashModal);
    });
}
if (gcashClose)  gcashClose.addEventListener('click', () => { hide(gcashModal); restoreSecurityOpacity(); });
if (gcashCancel) gcashCancel.addEventListener('click', () => { hide(gcashModal); restoreSecurityOpacity(); });

const otpModal     = document.getElementById('otpModal');
const otpClose     = document.getElementById('otpClose');
const otpCancel    = document.getElementById('otpCancel');
const otpVerifyBtn = document.getElementById('otpVerifyBtn');
const otpCode      = document.getElementById('otpCode');
const otpStatus    = document.getElementById('otpStatus');
const otpSentMsg   = document.getElementById('otpSentMsg');

if (otpClose)  otpClose.addEventListener('click', () => { hide(otpModal); restoreSecurityOpacity(); });
if (otpCancel) otpCancel.addEventListener('click', () => { hide(otpModal); restoreSecurityOpacity(); });

if (gcashSendBtn) {
    gcashSendBtn.addEventListener('click', async () => {
        const mobile = gcashMobile.value.trim();
        if (!mobile) {
            gcashSendStatus.textContent = 'Please enter your mobile number.';
            return;
        }
        gcashSendBtn.disabled = true;
        gcashSendStatus.style.color = '#000';
        gcashSendStatus.textContent = 'Sending OTP...';

        try {
            const fd = new FormData();
            fd.append('action', 'send_otp');
            fd.append('mobile', mobile);

            const res  = await fetch(window.location.href, { method: 'POST', body: fd });
            const data = await res.json();

            if (data.ok) {
                gcashSendStatus.style.color = '#2e7d32';
                gcashSendStatus.textContent = 'OTP sent. Please check your SMS.';
                hide(gcashModal);

                otpCode.value = '';
                otpStatus.textContent = '';
                otpSentMsg.textContent =
                    'We sent the verification code to your phone number (' + mobile + ').';

                show(otpModal);
                if (window.__otpInputs) window.__otpInputs.forEach(i => i.value = '');
                if (window.__otpInputs && window.__otpInputs[0]) window.__otpInputs[0].focus();
            } else {
                gcashSendStatus.style.color = '#d63f22';
                gcashSendStatus.textContent =
                    (data.debug && data.debug.first_message_status && data.debug.first_message_status.status_description)
                        ? data.debug.first_message_status.status_description
                        : (data.msg || 'Failed to send OTP.');
            }
        } catch (err) {
            console.error('OTP send error', err);
            gcashSendStatus.style.color = '#d63f22';
            gcashSendStatus.textContent = 'Unexpected error while sending OTP.';
        } finally {
            gcashSendBtn.disabled = false;
        }
    });
}

if (otpVerifyBtn) {
    otpVerifyBtn.addEventListener('click', async () => {
        const code = otpCode.value.trim();
        if (!/^\d{6}$/.test(code)) {
            otpStatus.textContent = 'Please enter the 6-digit code.';
            return;
        }
        otpVerifyBtn.disabled = true;
        otpStatus.style.color = '#000';
        otpStatus.textContent = 'Verifying...';

        try {
            const fd = new FormData();
            fd.append('action', 'verify_otp');
            fd.append('code', code);

            const res  = await fetch(window.location.href, { method: 'POST', body: fd });
            const data = await res.json();

            if (data.ok) {
                otpStatus.style.color = '#2e7d2e';
                otpStatus.textContent = 'Phone number verified!';
                hide(otpModal);
                restoreSecurityOpacity();

                const btn = document.getElementById('openPhoneVerify');
                if (btn) {
                    btn.style.background = '#28a745';
                    btn.style.color      = '#fff';
                    btn.textContent      = 'Verified';
                    btn.disabled         = true;
                    btn.style.cursor     = 'not-allowed';
                    btn.style.opacity    = '0.7';
                }

                const newPhone   = data.phone || gcashMobile.value;
                const profilePhone = document.getElementById('student_phone');
                if (profilePhone) profilePhone.value = newPhone;

                const phoneIcon = document.getElementById('phoneVerifiedIcon');
                if (phoneIcon) phoneIcon.style.display = 'inline';

                const badge = document.getElementById('verificationBadge');
                if (badge) {
                    if (TWOFA_VERIFIED) {
                        badge.style.background = '#28a745';
                        badge.style.color      = 'white';
                        badge.textContent      = '✔ FULLY VERIFIED';
                    } else {
                        badge.style.background = '#ffc107';
                        badge.style.color      = 'black';
                        badge.textContent      = '⚠ SEMI-VERIFIED';
                    }
                }

                alert('Your phone number has been successfully verified.');
            } else {
                otpStatus.style.color = '#d63f22';
                otpStatus.textContent = data.msg || 'OTP verification failed.';
            }
        } catch (err) {
            console.error('OTP verify error', err);
            otpStatus.style.color = '#d63f22';
            otpStatus.textContent = 'Unexpected error while verifying OTP.';
        } finally {
            otpVerifyBtn.disabled = false;
        }
    });
}

/* ---------- OTP BOXES UI ---------- */
(function initOtpBoxes() {
    const mount  = document.getElementById('otpBoxesMount');
    const hidden = document.getElementById('otpCode');
    if (!mount || !hidden) return;

    const wrap = document.createElement('div');
    wrap.className = 'otp-boxes';
    const inputs = [];

    for (let i = 0; i < 6; i++) {
        const inp = document.createElement('input');
        inp.type = 'text';
        inp.inputMode = 'numeric';
        inp.maxLength = 1;
        inp.autocomplete = 'one-time-code';
        inp.setAttribute('aria-label', `OTP digit ${i + 1}`);
        wrap.appendChild(inp);
        inputs.push(inp);
    }
    mount.appendChild(wrap);
    window.__otpInputs = inputs;

    function syncHidden() {
        hidden.value = inputs.map(x => (x.value || '').replace(/\D/g, '')).join('').slice(0, 6);
    }
    function focusNext(idx) {
        if (idx < inputs.length - 1) inputs[idx + 1].focus();
    }
    function focusPrev(idx) {
        if (idx > 0) inputs[idx - 1].focus();
    }

    inputs.forEach((inp, idx) => {
        inp.addEventListener('input', e => {
            const v = e.target.value.replace(/\D/g, '');
            e.target.value = v.slice(-1);
            syncHidden();
            if (v) focusNext(idx);
        });
        inp.addEventListener('keydown', e => {
            if (e.key === 'Backspace' && !inp.value) {
                e.preventDefault();
                focusPrev(idx);
            }
            if (e.key === 'ArrowLeft') {
                e.preventDefault();
                focusPrev(idx);
            }
            if (e.key === 'ArrowRight') {
                e.preventDefault();
                focusNext(idx);
            }
        });
        inp.addEventListener('paste', e => {
            const text = (e.clipboardData?.getData('text') || '').replace(/\D/g, '').slice(0, 6);
            if (!text) return;
            e.preventDefault();
            for (let i = 0; i < inputs.length; i++) {
                inputs[i].value = text[i] || '';
            }
            syncHidden();
            const firstEmpty = inputs.find(i => !i.value);
            (firstEmpty || inputs[inputs.length - 1]).focus();
        });
    });

    if (otpModal) {
        const obs = new MutationObserver(() => {
            const shown = otpModal.classList.contains('show');
            if (shown) setTimeout(() => inputs[0].focus(), 50);
        });
        obs.observe(otpModal, { attributes: true, attributeFilter: ['class'] });
    }
})();

/* ---------- 2FA (GOOGLE AUTH) ---------- */
const twoFAModal        = document.getElementById('twoFAModal');
const twoFAClose        = document.getElementById('twoFAClose');
const open2FASettings   = document.getElementById('open2FASettings');
const enable2FAButton   = document.getElementById('enable2FAButton');
const setupGoogle2FA    = document.getElementById('setupGoogle2FA');
const googleAuthSection = document.getElementById('googleAuthSection');
const googleQR          = document.getElementById('googleQR');
const google2FACode     = document.getElementById('google2FACode');
const verifyGoogle2FA   = document.getElementById('verifyGoogle2FA');
const googleAuthStatus  = document.getElementById('googleAuthStatus');
const appDownloadButtons= document.getElementById('appDownloadButtons');
const downloadAppStore  = document.getElementById('downloadAppStore');
const downloadGooglePlay= document.getElementById('downloadGooglePlay');

function openTwoFA() {
    if (!twoFAModal) return;
    twoFAModal.style.display = 'block';
    twoFAModal.style.zIndex  = '999999';
    if (securityModal) securityModal.style.opacity = '0.4';

    if (googleAuthSection) googleAuthSection.style.display = 'none';
    if (googleQR)          googleQR.src = '';
    if (google2FACode)     google2FACode.value = '';
    if (googleAuthStatus)  googleAuthStatus.textContent = '';
    if (appDownloadButtons)appDownloadButtons.style.display = 'none';
    if (setupGoogle2FA)    setupGoogle2FA.style.display = 'block';
}
function closeTwoFA() {
    if (!twoFAModal) return;
    twoFAModal.style.display = 'none';
    if (securityModal) securityModal.style.opacity = '1';
}
if (open2FASettings)  open2FASettings.addEventListener('click', openTwoFA);
if (enable2FAButton)  enable2FAButton.addEventListener('click', openTwoFA);
if (twoFAClose)       twoFAClose.addEventListener('click', closeTwoFA);
window.addEventListener('click', e => {
    if (e.target === twoFAModal) closeTwoFA();
});

if (setupGoogle2FA) {
    setupGoogle2FA.addEventListener('click', async () => {
        setupGoogle2FA.style.display = 'none';
        if (appDownloadButtons) appDownloadButtons.style.display = 'block';

        try {
            const res  = await fetch('twofa_generate.php');
            const data = await res.json();
            if (data.ok) {
                if (googleAuthSection) googleAuthSection.style.display = 'block';
                if (googleQR) googleQR.src = './' + data.qr;
                window.__TOTP_SECRET = data.secret;
            } else {
                alert('Failed to load QR.');
            }
        } catch (err) {
            console.error(err);
            alert('Failed to load QR.');
        }
    });
}
if (downloadAppStore) {
    downloadAppStore.addEventListener('click', () => {
        window.open('https://apps.apple.com/app/google-authenticator/id388497605', '_blank');
    });
}
if (downloadGooglePlay) {
    downloadGooglePlay.addEventListener('click', () => {
        window.open('https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2', '_blank');
    });
}
if (verifyGoogle2FA) {
    verifyGoogle2FA.addEventListener('click', async () => {
        const code = google2FACode.value.trim();
        if (code.length !== 6) {
            googleAuthStatus.style.color = 'red';
            googleAuthStatus.textContent = 'Enter 6 digits';
            return;
        }

        const fd = new FormData();
        fd.append('code', code);
        try {
            const res  = await fetch('twofa_verify.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.ok) {
                googleAuthStatus.style.color = 'green';
                googleAuthStatus.textContent = '2FA Verified!';

                const badge = document.getElementById('verificationBadge');
                if (badge) {
                    const phoneDone = PHONE_VERIFIED;
                    if (phoneDone) {
                        badge.style.background = '#28a745';
                        badge.style.color      = 'white';
                        badge.textContent      = '✔ FULLY VERIFIED';
                    } else {
                        badge.style.background = '#ffc107';
                        badge.style.color      = 'black';
                        badge.textContent      = '⚠ SEMI-VERIFIED';
                    }
                }

                if (enable2FAButton) {
                    enable2FAButton.style.background = '#28a745';
                    enable2FAButton.style.color      = 'white';
                    enable2FAButton.textContent      = '✔ VERIFIED';
                }

                if (open2FASettings) {
                    open2FASettings.style.background = '#4cd964';
                    open2FASettings.textContent      = '2FA Enabled';
                    open2FASettings.disabled         = true;
                    open2FASettings.style.cursor     = 'not-allowed';
                    open2FASettings.style.opacity    = '0.7';
                }

                setTimeout(() => {
                    closeTwoFA();
                    if (securityModal) {
                        securityModal.style.display = 'block';
                        securityModal.style.opacity = '1';
                    }
                    alert('Your Two-Factor Authentication has been enabled.');
                }, 500);
            } else {
                googleAuthStatus.style.color = 'red';
                googleAuthStatus.textContent = data.msg || 'Invalid code.';
            }
        } catch (err) {
            console.error(err);
            googleAuthStatus.style.color = 'red';
            googleAuthStatus.textContent = 'Error verifying code.';
        }
    });
}

/* ---------- CHAT WIDGET ---------- */
function openChatWidget() {
    const box = document.querySelector('.rgochat-wrap');
    if (!box) return;
    box.style.display = 'block';
}
(function initChatWidget() {
    const css = `
        .rgochat-wrap{position:fixed;right:18px;bottom:18px;width:340px;max-width:calc(100vw - 32px);height:480px;max-height:calc(100vh - 32px);background:#fff;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 18px 40px rgba(0,0,0,.18);display:none;z-index:5000;overflow:hidden}
        .rgochat-hdr{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:#ee4d2d;color:#fff}
        .rgochat-title{font-weight:800;margin:0;font-size:15px}
        .rgochat-x{border:none;background:rgba(255,255,255,.18);color:#fff;font-size:18px;line-height:1;width:28px;height:28px;border-radius:8px;cursor:pointer}
        .rgochat-body{background:#fafafa;height:calc(100% - 52px - 62px);overflow:auto;padding:12px}
        .rgochat-empty{text-align:center;color:#666;font-size:13px;margin-top:20px}
        .rgochat-msg{max-width:80%;padding:8px 10px;border-radius:12px;margin:6px 0;font-size:14px;line-height:1.35;word-wrap:break-word;white-space:pre-wrap}
        .rgochat-me{background:#e6f2ff;color:#0b5394;margin-left:auto;border-top-right-radius:4px}
        .rgochat-admin{background:#fff;border:1px solid #eee;color:#111827;border-top-left-radius:4px}
        .rgochat-ftr{display:grid;grid-template-columns:1fr 72px;gap:8px;padding:10px;background:#fff;border-top:1px solid #eee}
        .rgochat-in{border:1px solid #e5e7eb;border-radius:10px;padding:10px 12px;font-size:14px;outline:none}
        .rgochat-send{background:#ee4d2d;color:#fff;border:none;border-radius:10px;font-weight:800;cursor:pointer}
        .rgochat-send:disabled{opacity:.5;cursor:not-allowed}
    `;
    const st = document.createElement('style');
    st.textContent = css;
    document.head.appendChild(st);

    const box = document.createElement('div');
    box.className = 'rgochat-wrap';
    box.innerHTML = `
        <div class="rgochat-hdr">
            <div class="rgochat-title">Chat with RGO</div>
            <button class="rgochat-x" title="Close">×</button>
        </div>
        <div class="rgochat-body" id="rgochat-body">
            <div class="rgochat-empty" id="rgochat-empty">Start a conversation with the RGO admin.</div>
        </div>
        <div class="rgochat-ftr">
            <input type="text" id="rgochat-in" class="rgochat-in" placeholder="Type your message..." maxlength="1000">
            <button id="rgochat-send" class="rgochat-send">Send</button>
        </div>
    `;
    document.body.appendChild(box);

    const body   = box.querySelector('#rgochat-body');
    const empty  = box.querySelector('#rgochat-empty');
    const input  = box.querySelector('#rgochat-in');
    const sendBtn= box.querySelector('#rgochat-send');
    const closeBtn = box.querySelector('.rgochat-x');

    let lastId = 0;
    let timer  = null;
    const POLL = 3500;

    function addBubble(sender, message) {
        if (empty) empty.style.display = 'none';
        const b = document.createElement('div');
        b.className = 'rgochat-msg ' + (sender === 'student' ? 'rgochat-me' : 'rgochat-admin');
        b.textContent = message;
        body.appendChild(b);
        body.scrollTop = body.scrollHeight;
    }

    async function fetchMsgs(initial = false) {
        try {
            const fd = new FormData();
            fd.append('action', 'chat_fetch');
            fd.append('since_id', initial ? 0 : (lastId || 0));

            const res  = await fetch(window.location.href, { method: 'POST', body: fd });
            const data = await res.json();
            if (!data.ok) return;

            (data.messages || []).forEach(m => {
                addBubble(m.sender, m.message);
                if (!lastId || m.id > lastId) lastId = m.id;
            });
        } catch (err) {
            console.error('chat_fetch', err);
        }
    }

    async function sendMsg() {
        const text = (input.value || '').trim();
        if (!text) return;
        sendBtn.disabled = true;

        try {
            const fd = new FormData();
            fd.append('action', 'chat_send');
            fd.append('message', text);

            const res  = await fetch(window.location.href, { method: 'POST', body: fd });
            const data = await res.json();

            if (data.ok) {
                addBubble('student', text);
                input.value = '';
                if (data.message && data.message.id) {
                    lastId = Math.max(lastId, data.message.id);
                }
            } else {
                alert(data.msg || 'Failed to send.');
            }
        } catch (err) {
            console.error('chat_send', err);
            alert('Network error.');
        } finally {
            sendBtn.disabled = false;
            input.focus();
        }
    }

    function openChat() {
        box.style.display = 'block';
        if (!timer) {
            fetchMsgs(true);
            timer = setInterval(fetchMsgs, POLL);
        }
        setTimeout(() => input.focus(), 50);
    }
    function closeChat() {
        box.style.display = 'none';
        if (timer) { clearInterval(timer); timer = null; }
    }

    closeBtn.addEventListener('click', closeChat);
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
            e.preventDefault();
            sendMsg();
        }
    });
    sendBtn.addEventListener('click', sendMsg);

    window.openChatWidget = openChat;
})();

/* ---------- HEARTBEAT ---------- */
setInterval(() => {
    fetch('heartbeat.php').catch(() => {});
}, 30000);

/* ---------- PROFILE FORM CHANGE DETECTION ---------- */
(function initProfileFormChangeDetection() {
    const form = document.getElementById('profileForm');
    if (!form) return;
    const submitBtn = form.querySelector("button[name='update_profile']");
    const inputs    = form.querySelectorAll('input:not([type="file"])');
    if (!submitBtn) return;

    const originalValues = {};
    inputs.forEach(i => originalValues[i.id] = i.value);

    function checkChanges() {
        let changed = false;
        inputs.forEach(i => {
            if (i.value !== originalValues[i.id]) changed = true;
        });
        submitBtn.disabled = !changed;
    }
    inputs.forEach(i => i.addEventListener('input', checkChanges));

    const picInput = document.getElementById('profilePicInput');
    if (picInput) {
        picInput.addEventListener('change', () => {
            submitBtn.disabled = false;
        });
    }

    submitBtn.disabled = true;
})();

/* ---------- PHP FLASH ALERTS ---------- */
<?php if ($update_success): ?>
alert('Your personal information has been updated successfully!');
<?php endif; ?>
<?php if (!empty($phone_error_msg)): ?>
alert('<?php echo htmlspecialchars($phone_error_msg); ?>');
<?php endif; ?>

/* ---------- INITIAL LOAD ---------- */
loadOrdersFromServer();
</script>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
crossorigin="anonymous"></script>

</body>

</html>
<?php $conn->close(); ?>