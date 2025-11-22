<?php
session_start();
include 'db.php';
require __DIR__ . '/vendor/autoload.php';
use OTPHP\TOTP;

/* ==========================================================
RECAPTCHA FUNCTION
========================================================== */
if (!function_exists('verify_recaptcha')) {
    function verify_recaptcha($response, $remoteIp = null)
    {
        $secret = '6LfBJhAsAAAAAN98ocgvyxADhc9_yDZHl6MYQKp0';

        if (!$response)
            return false;

        $postFields = ['secret' => $secret, 'response' => $response];
        if (!empty($remoteIp))
            $postFields['remoteip'] = $remoteIp;

        $endpoint = 'https://www.google.com/recaptcha/api/siteverify';
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        $result = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($result, true);
        return !empty($data['success']);
    }
}

/* ==========================================================
GOOGLE AUTHENTICATOR LOGIN (REPLACES OLD EMAIL OTP)
========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp_flow'])) {

    $email = $_SESSION['email'] ?? '';
    $inputCode = trim($_POST['otpCode']);

    /* ⭐ ADDED FIX — IDENTIFY USER BY 2FA CODE */
    if (!$email) {
        $query = $conn->query("
            SELECT s.id, s.fullname, s.sr_code, s.course, 
                   sl.email, s.twofa_secret,
                   s.phone_verified, s.student_phone_number, s.account_status
            FROM students s
            INNER JOIN student_login sl ON sl.login_id = s.login_id
            WHERE s.twofa_secret IS NOT NULL AND s.twofa_secret != ''
        ");

        while ($u = $query->fetch_assoc()) {
            $totp = TOTP::create($u['twofa_secret']);
            if ($totp->verify($inputCode)) {
                // Found matching user
                $_SESSION['email'] = $u['email'];
                $email = $u['email'];
                break;
            }
        }

        if (!$email) {
            $_SESSION['otp_flash'] = "Invalid or expired One-Time Pass.";
            header("Location: index.php?show=otp");
            exit();
        }
    }
    /* ⭐ END FIX */

    // Fetch student + their 2FA secret
    $stmt = $conn->prepare("
    SELECT s.id, s.fullname, s.sr_code, s.course,
           s.phone_verified, s.student_phone_number, s.account_status,
           sl.password,
           sl.login_id
    FROM students s
    INNER JOIN student_login sl ON sl.login_id = s.login_id
    WHERE sl.email = ?
    LIMIT 1
");

    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 0) {
        $_SESSION['otp_flash'] = "No student account found.";
        header("Location: index.php?show=otp");
        exit();
    }

    $row = $res->fetch_assoc();

    if (empty($row['twofa_secret'])) {
        $_SESSION['otp_flash'] = "Google Authenticator is not set up.";
        header("Location: index.php?show=otp");
        exit();
    }

    $totp = TOTP::create($row['twofa_secret']);
    if (!$totp->verify($inputCode)) {
        echo "<script>
                alert('Invalid or expired 6-digit code.');
                window.location.href = 'index.php?show=otp';
              </script>";
        exit();
    }

// Success — login student
$_SESSION['student_id'] = $row['id'];
$_SESSION['login_id']  = $row['login_id'];   // 🔴 ADD THIS
$_SESSION['email'] = $email;
$_SESSION['fullname'] = $row['fullname'];
$_SESSION['sr_code'] = $row['sr_code'];
$_SESSION['course'] = $row['course'];

$_SESSION['phone_verified'] = (int)$row['phone_verified'];
$_SESSION['student_phone_number'] = $row['student_phone_number'];
$_SESSION['account_status'] = $row['account_status'];


    $conn->query("UPDATE students SET status='online', last_online=NULL WHERE id={$row['id']}");

    header("Location: StudentDash.php");
    exit();
}

/* ==========================================================
PASSWORD LOGIN (ADMIN + STUDENT)
========================================================== */
$error = "";
$inputEmail = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['otp_flow'])) {

    $inputEmail = strtolower(trim($_POST['UserUsername']));
    $password = trim($_POST['UserPassword']);

    if (!verify_recaptcha($_POST['g-recaptcha-response'] ?? '', $_SERVER['REMOTE_ADDR'])) {
        $error = "CAPTCHA verification failed.";
    }

    if (!$inputEmail || !$password) {
        $error = "Please enter email and password.";
    }

    if ($error === "") {

        /* =======================================
        1. TRY ADMIN LOGIN
        ======================================= */
        $adminQ = $conn->prepare("
                SELECT a.admin_id, a.admin_name, a.admin_position,
                    sl.password, sl.login_id
                FROM admin a
                INNER JOIN student_login sl ON sl.login_id = a.login_id
                WHERE sl.email = ?
                LIMIT 1
            ");
        $adminQ->bind_param("s", $inputEmail);
        $adminQ->execute();
        $adminRes = $adminQ->get_result();

        if ($adminRes->num_rows === 1) {
            $admin = $adminRes->fetch_assoc();

            if (password_verify($password, $admin['password'])) {

                $conn->query("UPDATE admin SET status='online', last_online=NULL WHERE admin_id={$admin['admin_id']}");

                $_SESSION['admin_id'] = $admin['admin_id'];
                $_SESSION['admin_login_id'] = $admin['login_id'];
                $_SESSION['admin_name'] = $admin['admin_name'];
                $_SESSION['admin_position'] = $admin['admin_position'];
                $_SESSION['email'] = $inputEmail;

                header("Location: AdminDash.php");
                exit();
            } else {
                $error = "Invalid password.";
            }
        }

        /* =======================================
        2. TRY STUDENT LOGIN
        ======================================= */
        $stmt = $conn->prepare("
                SELECT s.id, s.fullname, s.sr_code, s.course,
                       s.phone_verified, s.student_phone_number, s.account_status,
                       sl.password
                FROM students s
                INNER JOIN student_login sl ON sl.login_id = s.login_id
                WHERE sl.email = ?
                LIMIT 1
            ");
        $stmt->bind_param("s", $inputEmail);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $row = $result->fetch_assoc();

            if (password_verify($password, $row['password'])) {

                $conn->query("UPDATE students SET status='online', last_online=NULL WHERE id={$row['id']}");
            
                unset($_SESSION['admin_id'], $_SESSION['admin_login_id'], $_SESSION['admin_name'], $_SESSION['admin_position']);
            
                $_SESSION['student_id'] = $row['id'];
                $_SESSION['login_id']   = $row['login_id'];   // 🔴 ADD THIS
                $_SESSION['email'] = $inputEmail;
                $_SESSION['fullname'] = $row['fullname'];
                $_SESSION['sr_code'] = $row['sr_code'];
                $_SESSION['course'] = $row['course'];
            
                $_SESSION['phone_verified'] = (int)$row['phone_verified'];
                $_SESSION['student_phone_number'] = $row['student_phone_number'];
                $_SESSION['account_status'] = $row['account_status'];
            
                header("Location: StudentDash.php");
                exit();
            }
            else {
                $error = "Invalid password.";
            }
        }

        if ($error === "") {
            $error = "No account found.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RGO Portal</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <style>
        /* ===== ADDED: simple toggle styles (OTP panel only) ===== */
        .switcher {
            display: flex;
            gap: 8px;
            align-items: center;
            justify-content: center;
            margin: 16px auto 8px;
            max-width: 520px;
        }

        .switcher button {
            padding: 10px 14px;
            border: 1px solid #ccc;
            background: #f7f7f7;
            cursor: pointer;
            border-radius: 8px;
        }

        .switcher button.active {
            background: #222;
            color: #fff;
        }

        .panel {
            display: none;
        }

        .panel.active {
            display: block;
        }

        .note {
            display: block;
            margin-top: 6px;
            color: #666;
            font-size: 12px;
        }

        .otp-flash {
            color: #0a7;
            margin-top: 10px;
            font-size: 14px;
        }

        .otp-error {
            color: #c00;
            margin-top: 10px;
            font-size: 14px;
        }

        .login-container {
            max-width: 520px;
            margin: 0 auto;
        }

        .links {
            margin-top: 10px;
        }

        .notice-box {
            margin-top: 5px;
            margin-bottom: 10px;
            padding: 12px;
            border-left: 4px solid #4285F4;
            background: #f1f5ff;
            color: #333;
            border-radius: 4px;
            font-size: 13px;
            line-height: 1.4;
        }

        .notice-box p {
            margin: 4px 0;
        }
    </style>

</head>

<body>
    <header
        style=" width: 100%; height: 270px; position: relative; display: flex; align-items: center; justify-content: flex-start; padding: 10px 40px; box-sizing: border-box; overflow: hidden; background-image: url('ito.jpg'), url('headerbg.png'); = background-position: center; background-size: 100% 100%;  background-repeat: no-repeat; ">
    </header>

    <h2 class="page-title">Welcome, Users! </h2>

    <div class="switcher">
        <button type="button" id="btnPwd" class="active">Password Login</button>
        <button type="button" id="btnOtp">One-Time Pass</button>
    </div>

    <div class="login-container panel active" id="panelPwd">
        <form method="POST" action="">
            <h3>Please Login</h3>
            <input type="text" placeholder="Username" name="UserUsername"
                value="<?php echo htmlspecialchars($inputEmail); ?>" required>
            <input type="password" placeholder="Password" name="UserPassword" required>
            <small class="note">* Password is case sensitive</small>
            <div class="captcha-box">
                <div class="g-recaptcha" data-sitekey="6LfBJhAsAAAAAC4DWOrxmTyg2kSMxuZOb1UHo-4B"></div>
            </div>
            <div class="notice-box">
                <p><strong>Notice:</strong> <i>Login requires a valid GSuite account.</i></p>
                <p><i>If you do not have an account, please contact the Admin or RGO.</i></p>
            </div>
            <button type="submit">Log in</button>
            <?php if (!empty($error))
                echo "<p style='color:red; margin-top:10px;'>$error</p>"; ?>
            <div class="links">
                <a href="ForgotPass.php">Forgot password? Click here</a>
            </div>
        </form>
    </div>

    <div class="login-container panel" id="panelOtp">
        <?php
        $otpFlash = $_SESSION['otp_flash'] ?? '';
        if ($otpFlash) {
            echo "<p class='otp-flash'>" . htmlspecialchars($otpFlash) . "</p>";
            unset($_SESSION['otp_flash']);
        } else {
            echo "<small class='note'>Enter your 6-digit Google Authenticator code.</small>";
        }
        ?>
        <form method="POST" action="">
            <h3>Verify One-Time Pass</h3>
            <!-- Removed email input -->
            <div style="height:5px;"></div>

            <input type="hidden" name="otp_flow" value="1">
            <input type="text" placeholder="Enter 6-digit code" name="otpCode" pattern="\d{6}" inputmode="numeric"
                autocomplete="one-time-code" required>
            <div class="notice-box">
                <p><strong>Notice:</strong> <i>The One-Time Pass login is an optional method. After accessing your
                        account via
                        the default email-and-password login, you may enable Two-Factor Authentication (2FA) to further
                        strengthen
                        security and smoother login experience.</i></p>
                <p><i>If you have not completed verification, please navigate to the Security page on the website to
                        enable 2FA.</i></p>
            </div>
            <button type="submit" name="verify_otp">Verify &amp; Sign in</button>

            <div class="links" style="margin-top:10px;">
                <a href="#" id="backToPwd">Back to password login</a>
            </div>
        </form>
    </div>

    <script>
        const btnPwd = document.getElementById('btnPwd');
        const btnOtp = document.getElementById('btnOtp');
        const panelPwd = document.getElementById('panelPwd');
        const panelOtp = document.getElementById('panelOtp');
        const backToPwd = document.getElementById('backToPwd');

        function showPwd() {
            btnPwd.classList.add('active'); btnOtp.classList.remove('active');
            panelPwd.classList.add('active'); panelOtp.classList.remove('active');
            history.replaceState(null, '', window.location.pathname);
        }
        function showOtp() {
            btnOtp.classList.add('active'); btnPwd.classList.remove('active');
            panelOtp.classList.add('active'); panelPwd.classList.remove('active');
            const url = new URL(window.location);
            url.searchParams.set('show', 'otp');
            history.replaceState(null, '', url);
        }

        btnPwd.addEventListener('click', showPwd);
        btnOtp.addEventListener('click', showOtp);
        if (backToPwd) backToPwd.addEventListener('click', function (e) { e.preventDefault(); showPwd(); });

        (function () {
            const params = new URLSearchParams(window.location.search);
            if (params.get('show') === 'otp') showOtp();
        })();
    </script>
</body>

</html>