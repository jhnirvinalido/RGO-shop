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

/* =======================
   ADDED: secure numeric ID helpers + atomic insert
   ======================= */

/** Generate a cryptographically secure numeric string of exact length $len. */
function gen_digits($len) {
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= (string) random_int(0, 9);
    }
    return $out;
}

/**
 * Create order + payment ATOMICALLY.
 * - order_num: 32 digits
 * - transaction_num: 64 digits
 * - payment_no: 32 digits
 * Falls back to latest login_id for the student if not in session.
 *
 * Returns array: ['ok'=>bool, 'order_id'=>int|null, 'payment_id'=>int|null, 'msg'=>string|null]
 */
function create_order_and_payment(mysqli $conn, int $student_id, ?int $item_id, float $amount, string $method = 'gcash', string $currency = 'PHP', ?string $notes = null) {
    // Resolve login_id
    $login_id = isset($_SESSION['login_id']) ? (int)$_SESSION['login_id'] : 0;
    if ($login_id <= 0) {
        $q = $conn->prepare("SELECT login_id FROM student_login WHERE student_id = ? ORDER BY login_id DESC LIMIT 1");
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
    if ($login_id <= 0) {
        return ['ok'=>false, 'order_id'=>null, 'payment_id'=>null, 'msg'=>'Missing login_id in session and fallback failed.'];
    }

    // Generate IDs
    $order_num       = gen_digits(32);
    $transaction_num = gen_digits(64);
    $payment_no      = gen_digits(32);

    // Begin transaction
    $conn->begin_transaction();
    try {
        // Insert into orders_info
        $sqlOrder = "INSERT INTO orders_info (student_id, login_id, order_num, transaction_num) VALUES (?,?,?,?)";
        $stmtOrder = $conn->prepare($sqlOrder);
        if (!$stmtOrder) {
            throw new Exception("Prepare order failed: ".$conn->error);
        }
        $stmtOrder->bind_param("iiss", $student_id, $login_id, $order_num, $transaction_num);
        if (!$stmtOrder->execute()) {
            throw new Exception("Execute order failed: ".$stmtOrder->error);
        }
        $order_id = (int)$stmtOrder->insert_id;
        $stmtOrder->close();

        // Insert into payment
        $sqlPay = "INSERT INTO payment (order_id, student_id, login_id, item_id, amount, currency, payment_method, payment_no, notes)
                   VALUES (?,?,?,?,?,?,?,?,?)";
        $stmtPay = $conn->prepare($sqlPay);
        if (!$stmtPay) {
            throw new Exception("Prepare payment failed: ".$conn->error);
        }
        // item_id can be NULL; amount must be >=0 per CHECK
        $item_id_param = $item_id ?: null;
        $notes_param   = $notes ?: null;
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
            throw new Exception("Execute payment failed: ".$stmtPay->error);
        }
        $payment_id = (int)$stmtPay->insert_id;
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
        error_log("ORDER/PAYMENT TX ROLLBACK: ".$e->getMessage());
        return ['ok'=>false, 'order_id'=>null, 'payment_id'=>null, 'msg'=>$e->getMessage()];
    }
}
/* =======================
   END additions
   ======================= */

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

    $boldOtp = strtr((string)$otp, ['0'=>'𝟬','1'=>'𝟭','2'=>'𝟮','3'=>'𝟯','4'=>'𝟰','5'=>'𝟱','6'=>'𝟲','7'=>'𝟳','8'=>'𝟴','9'=>'𝟵']);
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
            'msg' => 'Failed to send OTP. Network error.',
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
    if (!isset($_SESSION['gcash_otp'], $_SESSION['gcash_otp_expiry'])) {
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

    // OTP correct — clear OTP values
    unset($_SESSION['gcash_otp'], $_SESSION['gcash_otp_expiry']);

    /* ===========================================
       ADDED: Create the order + payment AT THIS POINT (atomic)
       We’ll accept optional POST values if you wire them later (item_id, amount, notes).
       Otherwise defaults are safe (NULL item, 0.00 amount).
       =========================================== */
    $student_id_for_tx = isset($_SESSION['student_id']) ? (int)$_SESSION['student_id'] : 0;

    // Best-effort: ensure we have a login_id in session (fallback to latest for this student)
    if (empty($_SESSION['login_id'])) {
        $fallbackLogin = $conn->prepare("SELECT login_id FROM student_login WHERE student_id = ? ORDER BY login_id DESC LIMIT 1");
        if ($fallbackLogin) {
            $fallbackLogin->bind_param("i", $student_id_for_tx);
            $fallbackLogin->execute();
            $fallbackLogin->bind_result($found_login_id);
            if ($fallbackLogin->fetch() && $found_login_id) {
                $_SESSION['login_id'] = (int)$found_login_id;
            }
            $fallbackLogin->close();
        }
    }

    // Pull optional item/amount/notes if you later include them in the request
    $item_id = isset($_POST['item_id']) && $_POST['item_id'] !== '' ? (int)$_POST['item_id'] : null;
    $amount  = isset($_POST['amount'])  ? (float)$_POST['amount'] : 0.00;
    $notes   = isset($_POST['notes'])   ? trim($_POST['notes'])   : null;

    $insertResult = ['ok'=>false, 'msg'=>'student not in session'];
    if ($student_id_for_tx > 0) {
        $insertResult = create_order_and_payment($conn, $student_id_for_tx, $item_id, $amount, 'gcash', 'PHP', $notes);
    }

    // If you prefer to block QR on DB failure, you can check $insertResult['ok'] first.
    // Here we keep your existing behavior (always show QR on successful OTP), but we include debug info.
    if (!$insertResult['ok']) {
        error_log("CREATE ORDER+PAYMENT FAILED: ".$insertResult['msg']);
    }

    if (is_array($STATIC_QR_IMAGES) && count($STATIC_QR_IMAGES) > 0) {
        $idx = random_int(0, count($STATIC_QR_IMAGES) - 1);
        $selectedQr = $STATIC_QR_IMAGES[$idx];
        echo json_encode([
            'ok' => true,
            'qr' => [$selectedQr],
            // Optional info for debugging; frontend can ignore
            'order_payment' => $insertResult
        ]);
        exit;
    }
    $qrPayloads = [];
    for ($i = 0; $i < 3; $i++) {
        $rnd = bin2hex(random_bytes(8)) . '-' . time();
        $qrPayloads[] = $rnd;
    }
    $qrUrls = array_map(function ($payload) {
        $chl = urlencode($payload);
        return "https://chart.googleapis.com/chart?chs=240x240&cht=qr&chl={$chl}&choe=UTF-8";
    }, $qrPayloads);
    echo json_encode([
        'ok' => true,
        'qr' => $qrUrls,
        // Optional info for debugging; frontend can ignore
        'order_payment' => $insertResult
    ]);
    exit;
}

if (!isset($_SESSION['student_id'])) {
    header("Location: UserLogin.php");
    exit();
}

$student_id = $_SESSION['student_id'];
$update_success = false;
$phone_error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $fullname = trim($_POST['fullName']);
    $sr_code = trim($_POST['srCode']);
    $gsuite_email = trim($_POST['gsuite']);
    $course = trim($_POST['course']);
    $student_phone_raw = trim($_POST['student_phone'] ?? '');
    if (!preg_match('/^(09\d{9}|\+639\d{9})$/', $student_phone_raw)) {
        $phone_error_msg = 'Please enter a valid PH mobile number (09 or +639).';
    }
    $student_phone_e164 = $student_phone_raw;
    if (strpos($student_phone_raw, '09') === 0) {
        $student_phone_e164 = '+63' . substr($student_phone_raw, 1);
    }
    $profile_pic = $_SESSION['profile_pic'] ?? null;
    if (isset($_FILES['profilePicInput']) && $_FILES['profilePicInput']['error'] === UPLOAD_ERR_OK) {
        $imgData = file_get_contents($_FILES['profilePicInput']['tmp_name']);
        $profile_pic = $imgData;
    }
    if ($phone_error_msg === '') {
        $stmt = $conn->prepare("UPDATE students SET fullname=?, sr_code=?, gsuite_email=?, course=?, profile_pic=?, student_phone_number=? WHERE id=?");
        if (!$stmt) {
            die("Prepare failed: (" . $conn->errno . ") " . $conn->error);
        }
        $stmt->bind_param("ssssssi", $fullname, $sr_code, $gsuite_email, $course, $profile_pic, $student_phone_e164, $student_id);
        if ($stmt->execute()) {
            $update_success = true;
            $_SESSION['fullname'] = $fullname;
            $_SESSION['profile_pic'] = $profile_pic;
        }
        $stmt->close();
    }
}

$stmt = $conn->prepare("SELECT fullname, sr_code, gsuite_email, course, profile_pic, student_phone_number FROM students WHERE id = ?");
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

    <!-- ✅ Added: Latest Bootstrap CSS (CDN) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@latest/dist/css/bootstrap.min.css" rel="stylesheet"
        crossorigin="anonymous" />

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
            background: red;
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

        /* >>> CHANGED THIS BLOCK ONLY: make QR image small <<< */
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
    </style>

    <!-- ✅ OVERRIDES (no removal of existing code): Make product cards fill the row and OTP into 6 boxes -->
    <style>
        /* Make the product grid consume full row width with responsive columns that fill each line */
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

        /* OTP 6-box UI */
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

        /* Hide original single OTP input, we’ll sync its value */
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
        /* PERSONAL INFORMATION MODAL — formal look */
        .modal {
            position: fixed;
            inset: 0;
            display: none;
            /* JS sets to block */
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
            /* centers horizontally; comfortable top space */
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

        /* CATEGORY FILTER — more refined look (no JS needed) */
        .category-bar {
            gap: 10px;
        }

        .left-controls {
            gap: 10px;
        }

        .category {
            padding: 9px 16px;
            /* slightly larger click target */
            border-radius: 999px;
            /* pill */
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
            <button class="sidebar-btn">Notifications</button>
            <button class="sidebar-btn">Contact RGO</button>
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
        </div>
    </nav>

    <section class="product-section">
        <div class="product-grid">
            <?php if (!empty($items)):
                foreach ($items as $it):
                    $firstImg = $it['images'][0] ?? 'https://via.placeholder.com/600x800?text=No+Image';
                    $imagesAttr = implode(', ', $it['images']);
                    $desc = trim($it['desc']) !== '' ? $it['desc'] : 'This item is presented with a formal description.';
                    ?>
                    <div class="product-card" data-category="<?= htmlspecialchars($it['category']) ?>"
                        data-name="<?= htmlspecialchars($it['name']) ?>"
                        data-price="<?= htmlspecialchars($it['price_display']) ?>"
                        data-images="<?= htmlspecialchars($imagesAttr) ?>" data-description="<?= htmlspecialchars($desc) ?>"
                        data-sizes="<?= htmlspecialchars($it['sizes_attr']) ?>">
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
                        <button style="color: white;" id="pdBuyNow" class="btn-ghost">Buy now</button>
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
            </div>
            <div class="buy-actions">
                <button style="background-color: transparent;" id="buyCancel" class="btn-ghost">Cancel</button>
                <button id="buyProceed" class="btn-primary" disabled>Proceed to Checkout</button>
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
                <div class="ck-card">
                    <img id="ckImage" src="" alt="Item" />
                    <div>
                        <p id="ckName" class="ck-name"></p>
                        <p id="ckMeta" class="ck-meta"></p>
                        <div id="ckPrice" class="ck-price"></div>
                    </div>
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
                    <img src="<?php echo $profilePic; ?>" alt="Profile Picture" class="modal-profile-pic"
                        id="modalProfilePic" />
                    <label for="profilePicInput" class="add-photo-overlay"><span>+</span></label>
                    <input type="file" name="profilePicInput" id="profilePicInput" accept="image/*" />
                </div>
                <label for="fullName">Full Name</label>
                <input type="text" id="fullName" name="fullName" value="<?php echo htmlspecialchars($fullname); ?>" />
                <label for="student_phone">Phone Number</label>
                <input type="tel" id="student_phone" name="student_phone"
                    value="<?php echo htmlspecialchars($student_phone); ?>" required pattern="^(09\d{9}|\+639\d{9})$"
                    title="Enter PH mobile: 09XXXXXXXXX or +639XXXXXXXXX" placeholder="09 or +639" />
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
                <h3 id="gcashTitle" class="buy-title">GCash Verification</h3>
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

                <!-- Original single input (kept, hidden by CSS); value will be synced from 6 boxes -->
                <input type="text" id="otpCode" maxlength="6" class="text-input" placeholder="------" />

                <!-- Container for the 6 OTP boxes (created by JS if not present) -->
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

    <script>
        const STUDENT_PHONE_E164 = <?php echo json_encode($student_phone); ?>;
        const sidebar = document.getElementById("sidebar");
        const overlay = document.getElementById("overlay");
        const toggleBtn = document.getElementById("sidebarToggle");
        toggleBtn.addEventListener("click", () => {
            sidebar.classList.toggle("open"); overlay.classList.toggle("show");
        });
        overlay.addEventListener("click", () => { sidebar.classList.remove("open"); overlay.classList.remove("show"); });

        const categories = document.querySelectorAll(".category");
        const products = document.querySelectorAll(".product-card");
        const grid = document.querySelector(".product-grid");
        categories.forEach(btn => {
            btn.addEventListener("click", () => {
                const category = btn.dataset.category;
                products.forEach(product => {
                    product.style.display = (category === "all" || product.dataset.category === category) ? "block" : "none";
                });
                grid.style.justifyContent = "start";
            });
        });

        const searchInput = document.getElementById("searchInput");
        searchInput.addEventListener("input", () => {
            const q = searchInput.value.toLowerCase();
            products.forEach(p => {
                const n = p.dataset.name.toLowerCase();
                p.style.display = n.includes(q) ? "block" : "none";
            });
            grid.style.justifyContent = "start";
        });

        const profileBtn = document.getElementById("profileBtn");
        const profileModal = document.getElementById("profileFormModal");
        const closeProfileForm = document.getElementById("closeProfileForm");
        profileBtn.addEventListener("click", () => { profileModal.style.display = "block"; });
        closeProfileForm.addEventListener("click", () => { profileModal.style.display = "none"; });
        window.addEventListener("click", (e) => { if (e.target === profileModal) profileModal.style.display = 'none'; });

        const profilePicInput = document.getElementById("profilePicInput");
        const modalProfilePic = document.getElementById("modalProfilePic");
        const sidebarProfilePic = document.getElementById("sidebarProfilePic");
        profilePicInput.addEventListener("change", (e) => {
            const f = e.target.files[0];
            if (f) { const r = new FileReader(); r.onload = (evt) => { modalProfilePic.src = evt.target.result; sidebarProfilePic.src = evt.target.result; }; r.readAsDataURL(f); }
        });

        const pdModal = document.getElementById('productDetailModal');
        const pdClose = document.getElementById('pdClose');
        const pdTitle = document.getElementById('pdTitle');
        const pdName = document.getElementById('pdName');
        const pdPrice = document.getElementById('pdPrice');
        const pdDesc = document.getElementById('pdDesc');
        const pdBuyNow = document.getElementById('pdBuyNow');

        const cTrack = document.getElementById('cTrack');
        theDots = document.getElementById('cDots');
        const cPrev = document.getElementById('cPrev');
        const cNext = document.getElementById('cNext');

        let cIndex = 0, cImages = [];
        let currentProductName = '', currentProductPrice = '', currentProductImage = '', currentDescription = '';
        let currentSizes = [], selectedSize = '', selectedStock = 0;

        function openProductDetail({ name, price, images, description, sizes }) {
            pdTitle.textContent = name;
            pdName.textContent = name;
            pdPrice.textContent = price;
            pdDesc.textContent = description && description.trim() ? description.trim() : 'This item is presented with a formal description.';
            currentProductName = name;
            currentProductPrice = price;
            currentDescription = pdDesc.textContent;
            cImages = images && images.length ? images : [];
            currentProductImage = cImages[0] || '';
            currentSizes = sizes || [];
            selectedSize = ''; selectedStock = 0;
            cTrack.innerHTML = '';
            cDots.innerHTML = '';
            cIndex = 0;
            cImages.forEach((src, i) => {
                const slide = document.createElement('div');
                slide.className = 'carousel-slide';
                const img = document.createElement('img');
                img.src = src.trim();
                img.alt = name + ' ' + (i + 1);
                img.draggable = false;
                slide.appendChild(img);
                cTrack.appendChild(slide);
                const dot = document.createElement('span');
                dot.className = 'carousel-dot' + (i === 0 ? ' active' : '');
                dot.dataset.index = i;
                dot.addEventListener('click', () => { cIndex = i; updateCarousel(); });
                cDots.appendChild(dot);
            });
            updateCarousel(true);
            pdModal.classList.add('show');
            pdModal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }

        function closeProductDetail() {
            pdModal.classList.remove('show'); pdModal.setAttribute('aria-hidden', 'true'); document.body.style.overflow = '';
        }
        function updateCarousel(jump = false) {
            const pct = -cIndex * 100;
            cTrack.style.transition = jump ? 'none' : 'transform .35s ease';
            cTrack.style.transform = `translateX(${pct}%)`;
            [...cDots.children].forEach((d, i) => d.classList.toggle('active', i === cIndex));
        }
        function nextSlide() { cIndex = (cIndex < cImages.length - 1) ? cIndex + 1 : 0; updateCarousel(); }
        function prevSlide() { cIndex = (cIndex > 0) ? cIndex - 1 : cImages.length - 1; updateCarousel(); }
        cNext.addEventListener('click', nextSlide);
        cPrev.addEventListener('click', prevSlide);
        pdClose.addEventListener('click', closeProductDetail);
        pdModal.addEventListener('click', (e) => { if (e.target === pdModal) closeProductDetail(); });
        window.addEventListener('keydown', (e) => {
            if (pdModal.classList.contains('show')) {
                if (e.key === 'Escape') closeProductDetail();
                if (e.key === 'ArrowRight') nextSlide();
                if (e.key === 'ArrowLeft') prevSlide();
            }
        });

        const buyModal = document.getElementById('buyModal');
        const buyClose = document.getElementById('buyClose');
        const buyCancel = document.getElementById('buyCancel');
        const buyProceed = document.getElementById('buyProceed');
        const sizeList = document.getElementById('sizeList');

        function parseSizes(attr) {
            if (!attr) return [];
            return attr.split(',').map(s => s.trim()).filter(Boolean).map(pair => {
                const [label, countRaw] = pair.split(':');
                const count = parseInt((countRaw || '0').replace(/\D/g, ''), 10) || 0;
                return { label: label.trim(), count };
            });
        }

        function openBuyModal() {
            sizeList.innerHTML = '';
            selectedSize = ''; selectedStock = 0;
            buyProceed.disabled = true;
            currentSizes.forEach(opt => {
                const row = document.createElement('div'); row.className = 'size-item';
                const left = document.createElement('div'); left.className = 'size-left';
                const radio = document.createElement('input'); radio.type = 'radio'; radio.name = 'sizeopt'; radio.className = 'size-radio'; radio.value = opt.label; radio.id = `size-${opt.label}`; radio.disabled = opt.count <= 0;
                const lbl = document.createElement('label'); lbl.setAttribute('for', `size-${opt.label}`); lbl.className = 'size-label'; lbl.textContent = opt.label;
                left.appendChild(radio); left.appendChild(lbl);
                const stk = document.createElement('div'); stk.className = 'size-stock'; stk.textContent = `${opt.count > 0 ? opt.count : 0} pcs`;
                row.appendChild(left); row.appendChild(stk);
                radio.addEventListener('change', () => { selectedSize = opt.label; selectedStock = opt.count; buyProceed.disabled = false; });
                sizeList.appendChild(row);
            });
            buyModal.classList.add('show'); buyModal.setAttribute('aria-hidden', 'false');
        }
        function closeBuyModal() { buyModal.classList.remove('show'); buyModal.setAttribute('aria-hidden', 'true'); }

        pdBuyNow.addEventListener('click', openBuyModal);
        buyClose.addEventListener('click', closeBuyModal);
        buyCancel.addEventListener('click', closeBuyModal);
        buyModal.addEventListener('click', (e) => { if (e.target === buyModal) closeBuyModal(); });

        const ckModal = document.getElementById('checkoutModal');
        const ckClose = document.getElementById('ckClose');
        const ckImage = document.getElementById('ckImage');
        const ckName = document.getElementById('ckName');
        const ckMeta = document.getElementById('ckMeta');
        const ckPrice = document.getElementById('ckPrice');
        const ckPlaceOrder = document.getElementById('ckPlaceOrder');

        function openCheckout() {
            ckImage.src = currentProductImage || '';
            ckName.textContent = currentProductName;
            ckMeta.textContent = selectedSize ? `Selected Size: ${selectedSize}` : '';
            ckPrice.textContent = currentProductPrice;
            ckModal.classList.add('show'); ckModal.setAttribute('aria-hidden', 'false');
        }
        function closeCheckout() { ckModal.classList.remove('show'); ckModal.setAttribute('aria-hidden', 'true'); }
        ckClose.addEventListener('click', closeCheckout);
        ckModal.addEventListener('click', (e) => { if (e.target === ckModal) closeCheckout(); });

        buyProceed.addEventListener('click', () => {
            if (!selectedSize) return;
            closeBuyModal(); closeProductDetail(); openCheckout();
        });

        const gcashModal = document.getElementById('gcashNumberModal');
        const gcashClose = document.getElementById('gcashClose');
        const gcashCancel = document.getElementById('gcashCancel');
        const gcashSendBtn = document.getElementById('gcashSendBtn');
        const gcashMobile = document.getElementById('gcashMobile');
        const gcashSendStatus = document.getElementById('gcashSendStatus');

        const otpModal = document.getElementById('otpModal');
        const otpClose = document.getElementById('otpClose');
        const otpCancel = document.getElementById('otpCancel');
        const otpVerifyBtn = document.getElementById('otpVerifyBtn');
        const otpCode = document.getElementById('otpCode');
        const otpStatus = document.getElementById('otpStatus');
        const otpSentMsg = document.getElementById('otpSentMsg');

        const qrModal = document.getElementById('qrModal');
        const qrClose = document.getElementById('qrClose');
        const qrDone = document.getElementById('qrDone');
        const qrWrap = document.getElementById('qrWrap');

        function show(el) { el.classList.add('show'); el.setAttribute('aria-hidden', 'false'); }
        function hide(el) { el.classList.remove('show'); el.setAttribute('aria-hidden', 'true'); }

        gcashClose.addEventListener('click', () => hide(gcashModal));
        gcashCancel.addEventListener('click', () => hide(gcashModal));

        otpClose.addEventListener('click', () => hide(otpModal));
        otpCancel.addEventListener('click', () => hide(otpModal));

        qrClose.addEventListener('click', () => hide(qrModal));
        qrDone.addEventListener('click', () => hide(qrModal));

        const ckPayRadios = document.querySelectorAll('input[name="paymethod"]');
        const ckPlaceOrderBtn = document.getElementById('ckPlaceOrder');
        ckPlaceOrderBtn.addEventListener('click', async () => {
            const method = [...ckPayRadios].find(r => r.checked)?.value || '';
            if (!method) { alert('Please select a payment method.'); return; }
            if (method === 'Cash') {
                alert(`Order placed:\n${currentProductName}\n${currentProductPrice}\nSize: ${selectedSize}\nPayment: ${method}`);
                closeCheckout();
                return;
            }
            if (method === 'GCash') {
                if (!STUDENT_PHONE_E164 || !/^\+639\d{9}$/.test(STUDENT_PHONE_E164)) {
                    alert('Please add a valid phone number (09XXXXXXXXX or +639XXXXXXXXX) in your Personal Information first.');
                    document.getElementById('profileFormModal').style.display = 'block';
                    return;
                }
                try {
                    const form = new FormData();
                    form.append('action', 'send_otp');
                    form.append('mobile', STUDENT_PHONE_E164);
                    const res = await fetch(window.location.href, { method: 'POST', body: form });
                    const data = await res.json();
                    if (data.ok) {
                        otpCode.value = '';
                        otpStatus.textContent = '';
                        otpSentMsg.textContent = 'Verification sent to your Phone Number ';
                        const strong = document.createElement('strong');
                        strong.textContent = '(' + STUDENT_PHONE_E164 + ')';
                        otpSentMsg.appendChild(strong);
                        show(otpModal);
                        // Clear 6-box UI if present
                        if (window.__otpInputs) window.__otpInputs.forEach(i => i.value = '');
                        if (window.__otpInputs && window.__otpInputs[0]) window.__otpInputs[0].focus();
                    } else {
                        alert((data.msg || 'Failed to send OTP.') + (data.debug?.first_message_status?.status_description ? ('\n' + data.debug.first_message_status.status_description) : ''));
                    }
                } catch (e) {
                    alert('Unexpected error while sending OTP.');
                    console.error(e);
                }
                return;
            }
        });

        gcashSendBtn.addEventListener('click', async () => {
            const mobile = gcashMobile.value.trim();
            if (!mobile) {
                gcashSendStatus.textContent = 'Please enter your mobile number.';
                return;
            }
            gcashSendBtn.disabled = true;
            gcashSendStatus.textContent = 'Sending OTP...';
            try {
                const form = new FormData();
                form.append('action', 'send_otp');
                form.append('mobile', mobile);
                const res = await fetch(window.location.href, { method: 'POST', body: form });
                const data = await res.json();
                if (data.ok) {
                    gcashSendStatus.style.color = '#2e7d32';
                    gcashSendStatus.textContent = 'OTP sent. Please check your SMS.';
                    hide(gcashModal);
                    otpCode.value = '';
                    otpStatus.textContent = '';
                    otpSentMsg.textContent = 'we send the verification to your Phone Number +639xxxxxxxxx(' + mobile + ')';
                    show(otpModal);
                    if (window.__otpInputs) window.__otpInputs.forEach(i => i.value = '');
                    if (window.__otpInputs && window.__otpInputs[0]) window.__otpInputs[0].focus();
                } else {
                    gcashSendStatus.style.color = '#d63f22';
                    gcashSendStatus.textContent = (data.debug && data.debug.first_message_status && data.debug.first_message_status.status_description)
                        ? data.debug.first_message_status.status_description
                        : (data.msg || 'Failed to send OTP.');
                }
                console.log('OTP DEBUG →', data);
            } catch (e) {
                gcashSendStatus.style.color = '#d63f22';
                gcashSendStatus.textContent = 'Unexpected error while sending OTP.';
                console.error('OTP SEND exception', e);
            } finally {
                gcashSendBtn.disabled = false;
            }
        });

        otpVerifyBtn.addEventListener('click', async () => {
            const code = otpCode.value.trim();
            if (!/^\d{6}$/.test(code)) {
                otpStatus.textContent = 'Please enter the 6-digit code.';
                return;
            }
            otpVerifyBtn.disabled = true;
            otpStatus.textContent = 'Verifying...';
            try {
                const form = new FormData();
                form.append('action', 'verify_otp');
                form.append('code', code);
                // Optional: later you can also send item_id/amount/notes here
                const res = await fetch(window.location.href, { method: 'POST', body: form });
                const data = await res.json();
                if (data.ok) {
                    otpStatus.style.color = '#2e7d2e';
                    otpStatus.textContent = 'Verified!';
                    hide(otpModal);
                    const qrWrap = document.getElementById('qrWrap');
                    qrWrap.innerHTML = '';
                    (data.qr || []).forEach(url => {
                        const img = document.createElement('img');
                        img.src = url;
                        img.alt = 'QR Code';
                        qrWrap.appendChild(img);
                    });
                    const qrModal = document.getElementById('qrModal');
                    qrModal.classList.add('show'); qrModal.setAttribute('aria-hidden', 'false');
                    const ckModal = document.getElementById('checkoutModal');
                    ckModal.classList.remove('show'); ckModal.setAttribute('aria-hidden', 'true');
                } else {
                    otpStatus.style.color = '#d63f22';
                    otpStatus.textContent = data.msg || 'OTP verification failed.';
                }
            } catch (e) {
                otpStatus.style.color = '#d63f22';
                otpStatus.textContent = 'Unexpected error while verifying OTP.';
                console.error('OTP VERIFY exception', e);
            } finally {
                otpVerifyBtn.disabled = false;
            }
        });

        document.querySelectorAll('.product-card').forEach(card => {
            card.addEventListener('click', () => {
                const name = card.dataset.name || card.querySelector('.product-name')?.textContent?.trim() || 'Product';
                const price = card.dataset.price || card.querySelector('.product-price')?.textContent?.trim() || '';
                const attr = card.getAttribute('data-images') || '';
                const desc = card.getAttribute('data-description') || '';
                const sizesA = card.getAttribute('data-sizes') || '';
                let images = attr.split(', ').map(s => s.trim()).filter(Boolean);
                if (images.length === 0) {
                    const single = card.querySelector('.product-img')?.getAttribute('src') || '';
                    if (single) images = [single, single, single, single];
                }
                const sizes = parseSizes(sizesA);
                openProductDetail({ name, price, images, description: desc, sizes });
            });
        });

        const signoutBtn = document.getElementById("signoutBtn");
        signoutBtn.addEventListener("click", () => {
            const c = confirm("Are you sure you want to sign out?");
            if (c) window.location.href = "logout.php";
        });

        <?php if ($update_success): ?> alert("Your personal information has been updated successfully!"); <?php endif; ?>
        <?php if (!empty($phone_error_msg)): ?> alert("<?php echo htmlspecialchars($phone_error_msg); ?>"); <?php endif; ?>

        const orders = [];
        const ordersSection = document.getElementById('ordersSection');
        const ordersList = document.getElementById('ordersList');
        const ordersCountLabel = document.getElementById('ordersCountLabel');
        const backToShop = document.getElementById('backToShop');
        const categoryBar = document.querySelector('.category-bar');

        function randOrderNo() { return String(Math.floor(100000000 + Math.random() * 900000000)); }
        function fmtShortDate(d) { return d.toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' }); }

        function addOrder(o) {
            orders.unshift(o);
        }

        function renderOrders() {
            ordersList.innerHTML = '';
            ordersCountLabel.textContent = `Displaying ${orders.length} of ${orders.length} orders`;
            orders.forEach(o => {
                const card = document.createElement('div');
                card.className = 'order-card';

                const im = document.createElement('img');
                im.src = o.image || 'logo.png';
                im.alt = o.name || 'Item';

                const right = document.createElement('div');

                const st = document.createElement('div');
                st.className = 'order-status';
                st.textContent = 'ORDER STATUS: ' + (o.status || "IT'S ORDERED!");

                const est = document.createElement('div');
                est.className = 'order-est';
                est.textContent = 'Estimated delivery ' + (o.eta ? o.eta.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) : '');

                const nm = document.createElement('div');
                nm.textContent = (o.name || '') + (o.size ? ` • ${o.size}` : '');

                const pr = document.createElement('div');
                pr.style.fontWeight = '800';
                pr.style.color = '#ee4d2d';
                pr.textContent = o.price || '';

                const meta = document.createElement('div');
                meta.className = 'order-meta';
                meta.innerHTML = `<small>ORDER NO.: ${o.id}</small><small>ORDER DATE: ${fmtShortDate(o.date)}</small><small>PAYMENT: ${o.method}</small>`;

                const actions = document.createElement('div');
                actions.className = 'order-actions';
                const v = document.createElement('button'); v.className = 'btn-line'; v.textContent = 'VIEW ORDER';
                const c = document.createElement('button'); c.className = 'btn-line'; c.textContent = 'CANCEL ORDER';
                actions.appendChild(v); actions.appendChild(c);

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

        function showOrders() {
            document.querySelector('.product-section').style.display = 'none';
            ordersSection.style.display = 'block';
            ordersSection.setAttribute('aria-hidden', 'false');
            if (categoryBar) categoryBar.style.display = 'none';
        }

        function hideOrders() {
            ordersSection.style.display = 'none';
            ordersSection.setAttribute('aria-hidden', 'true');
            document.querySelector('.product-section').style.display = 'block';
            if (categoryBar) categoryBar.style.display = 'flex';
        }

        (function seedOrders() {
            const now = new Date();
            const day = 24 * 60 * 60 * 1000;
            addOrder({
                id: randOrderNo(),
                name: 'University PE Shirt',
                size: 'M',
                price: '₱350.00',
                method: 'Cash',
                status: "IT'S ORDERED!",
                date: new Date(now.getTime() - 2 * day),
                eta: new Date(now.getTime() + 3 * day),
                image: 'logo.png'
            });
            addOrder({
                id: randOrderNo(),
                name: 'College Uniform Polo',
                size: 'L',
                price: '₱550.00',
                method: 'GCash',
                status: "IT'S ORDERED!",
                date: new Date(now.getTime() - 1 * day),
                eta: new Date(now.getTime() + 4 * day),
                image: 'logo.png'
            });
            renderOrders();
        })();

        document.querySelector('.sidebar').addEventListener('click', (e) => {
            const t = e.target;
            if (t.classList.contains('sidebar-btn') && /my orders/i.test(t.textContent || '')) {
                showOrders();
                sidebar.classList.remove('open'); overlay.classList.remove('show');
            }
        });

        backToShop.addEventListener('click', hideOrders);

        /* ===== OTP 6-BOX ENHANCEMENT (non-breaking) ===== */
        (function initOtpBoxes() {
            const mount = document.getElementById('otpBoxesMount');
            const hidden = document.getElementById('otpCode');
            if (!mount || !hidden) return;

            // Build 6 inputs
            const wrap = document.createElement('div');
            wrap.className = 'otp-boxes';
            const inputs = [];
            for (let i = 0; i < 6; i++) {
                const inp = document.createElement('input');
                inp.type = 'text';
                inp.inputMode = 'numeric';
                inp.autocomplete = 'one-time-code';
                inp.maxLength = 1;
                inp.setAttribute('aria-label', `OTP digit ${i + 1}`);
                wrap.appendChild(inp);
                inputs.push(inp);
            }
            mount.appendChild(wrap);
            window.__otpInputs = inputs;

            // Helpers
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
                inp.addEventListener('input', (e) => {
                    const v = e.target.value.replace(/\D/g, '');
                    e.target.value = v.slice(-1);
                    syncHidden();
                    if (v) focusNext(idx);
                });
                inp.addEventListener('keydown', (e) => {
                    if (e.key === 'Backspace' && !inp.value) {
                        e.preventDefault();
                        focusPrev(idx);
                    }
                    if (e.key === 'ArrowLeft') { e.preventDefault(); focusPrev(idx); }
                    if (e.key === 'ArrowRight') { e.preventDefault(); focusNext(idx); }
                });
                inp.addEventListener('paste', (e) => {
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

            // Keep boxes in sync if code set programmatically
            const observer = new MutationObserver(() => {
                const v = (hidden.value || '').replace(/\D/g, '').slice(0, 6);
                for (let i = 0; i < inputs.length; i++) {
                    inputs[i].value = v[i] || '';
                }
            });
            observer.observe(hidden, { attributes: true, attributeFilter: ['value'] });

            // Autofocus first box when modal opens
            const otpModal = document.getElementById('otpModal');
            const obs = new MutationObserver(() => {
                const shown = otpModal.classList.contains('show');
                if (shown) setTimeout(() => inputs[0].focus(), 50);
            });
            obs.observe(otpModal, { attributes: true, attributeFilter: ['class'] });
        })();
        /* ===== END OTP 6-BOX ENHANCEMENT ===== */
    </script>

    <!-- ✅ Added: Latest Bootstrap JS Bundle (includes Popper) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@latest/dist/js/bootstrap.bundle.min.js"
        crossorigin="anonymous"></script>
</body>

</html>
<?php $conn->close(); ?>
