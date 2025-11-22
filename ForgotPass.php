<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php'; // COMPOSER MAILER

include 'db.php';
session_start();

// Initialize cooldown timer
if (!isset($_SESSION['resend_wait_until'])) {
    $_SESSION['resend_wait_until'] = 0;
}

$step = 1;
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* =======================================================
       STEP 1 — REQUEST RESET CODE (Send Email)
    ======================================================== */
    if (isset($_POST['EmailForgotPasss']) && !isset($_POST['verifyCode']) && !isset($_POST['newPassword'])) {

        $email = trim($_POST['EmailForgotPasss']);
        $current_time = time();

        // Cooldown check
        if ($current_time < $_SESSION['resend_wait_until']) {
            $remaining = $_SESSION['resend_wait_until'] - $current_time;
            $error = "Please wait $remaining seconds before requesting a new code.";
            $step = 2;
        } else {

            $stmt = $conn->prepare("SELECT login_id FROM student_login WHERE email = ?");
            if (!$stmt) die("MySQL prepare failed: " . $conn->error);

            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {

                $verification_code = rand(100000, 999999);
                $_SESSION['reset_email'] = $email;
                $_SESSION['verification_code'] = $verification_code;
                $_SESSION['code_time'] = time();
                $_SESSION['resend_wait_until'] = time() + 300; // 300s cooldown

                // SEND EMAIL
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host = 'smtp.gmail.com';
                    $mail->SMTPAuth = true;

                    $mail->Username = 'brionescarvey0903@gmail.com';
                    $mail->Password = 'pcex rism vlsq tjvm';

                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;

                    $mail->setFrom('yourgsuite@gmail.com', 'RGO University');
                    $mail->addAddress($email);

                    $mail->isHTML(true);
                    $mail->Subject = 'RGO University Password Reset Code';

                    $mail->Body = "
                        <div style='font-family: Arial;'>
                            <h2 style='color:#d62828;'>RGO University</h2>
                            <p>Your verification code is:</p>
                            <h1 style='color:#d62828;'>$verification_code</h1>
                            <p>This code expires in <b>10 minutes</b>.</p>
                        </div>
                    ";

                    $mail->send();

                    echo "<script>alert('Verification code sent. Check your G-Suite email.');</script>";
                    $step = 2;

                } catch (Exception $e) {
                    $error = "Mailer Error: {$mail->ErrorInfo}";
                }
            } else {
                $error = "No account found with that G-Suite email.";
            }
            $stmt->close();
        }
    }

    /* =======================================================
       STEP 2 — VERIFY CODE
    ======================================================== */
    elseif (isset($_POST['verifyCode'])) {

        $entered = trim($_POST['verifyCode']);

        if (isset($_SESSION['verification_code']) && isset($_SESSION['code_time'])) {

            $time_passed = time() - $_SESSION['code_time'];

            if ($time_passed > 600) { // 10 minutes
                $error = "Verification code expired. Request a new one.";
                $step = 1;
            } elseif ($entered == $_SESSION['verification_code']) {
                echo "<script>alert('Verification successful! Set your new password.');</script>";
                $step = 3;
            } else {
                $error = "Invalid verification code.";
                $step = 2;
            }

        } else {
            $error = "Session expired. Try again.";
            $step = 1;
        }
    }

    /* =======================================================
       STEP 3 — UPDATE PASSWORD
    ======================================================== */
    elseif (isset($_POST['newPassword']) && isset($_POST['confirmPassword'])) {

        $new = trim($_POST['newPassword']);
        $confirm = trim($_POST['confirmPassword']);

        if ($new === $confirm) {

            if (isset($_SESSION['reset_email'])) {

                $email = $_SESSION['reset_email'];
                $hash = password_hash($new, PASSWORD_DEFAULT);

                $stmt = $conn->prepare("UPDATE student_login SET password = ? WHERE email = ?");
                $stmt->bind_param("ss", $hash, $email);

                if ($stmt->execute()) {
                    echo "<script>alert('Password updated! Login now.'); window.location='UserLogin.php';</script>";
                    session_unset();
                    session_destroy();
                } else {
                    $error = "Failed to update password. Try again.";
                }

                $stmt->close();

            } else {
                $error = "Session expired.";
                $step = 1;
            }

        } else {
            $error = "Passwords do not match.";
            $step = 3;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password</title>

<style>
    body {
        background: #f5f5f5;
        font-family: Arial, sans-serif;
        text-align: center;
    }
    header {
        width: 100%;
        height: 230px;
        background: url('header.png') center/100% 100% no-repeat;
    }
    .Forgottpass-Container {
        background: white;
        width: 380px;
        margin: 200px auto 0;
        padding: 40px;
        border-radius: 15px;
        box-shadow: 0px 5px 15px #0003;
    }
    input {
        width: 90%;
        padding: 12px;
        margin: 12px 0;
        border-radius: 8px;
        border: 1px solid #aaa;
    }
    .ConBut {
        width: 100%;
        background: #ffd633;
        padding: 12px;
        border-radius: 8px;
        border: none;
        font-weight: bold;
        cursor: pointer;
    }
    .ConBut:hover {
        background: #ffe066;
    }
    .back-link {
        margin-top: 15px;
        display: block;
        text-decoration: none;
        color: #007b00;
    }
    #timerText {
        color:#d62828; 
        font-size:14px; 
        margin-top:10px;
    }
</style>

</head>
<body>

<header></header>

<div class="Forgottpass-Container">
<form method="POST">

<h2 style="color:#d62828;">Forgot Password</h2>

<?php if ($error): ?>
<script>alert("<?php echo $error; ?>");</script>
<?php endif; ?>

<?php if ($step == 1): ?>
    <p>Enter your G-Suite account</p>
    <input type="email" name="EmailForgotPasss" placeholder="Enter your G-Suite Account" required>
    <button class="ConBut">Continue</button>

    <?php elseif ($step == 2): ?>
    <p>Enter the verification code sent to your email</p>

    <input type="email" value="<?php echo $_SESSION['reset_email']; ?>" readonly>
    <input type="text" name="verifyCode" placeholder="Enter verification code" required>
    <button class="ConBut">Verify</button>

    <br><br>

    <!-- RESEND CODE LINK -->
    <p id="timerText" style="color:#d62828; font-size:14px;"></p>

    <a id="resendLink" 
       href="ForgotPass.php?resend=1" 
       style="color:#0066cc; text-decoration:underline; font-size:14px;">
       Resend Code
    </a>

    <script>
        let waitUntil = <?php echo $_SESSION['resend_wait_until']; ?>;
        let now = Math.floor(Date.now() / 1000);
        let diff = waitUntil - now;

        let link = document.getElementById("resendLink");
        let timerText = document.getElementById("timerText");

        if (diff > 0) {
            link.style.pointerEvents = "none";
            link.style.opacity = "0.4";

            let countdown = setInterval(() => {
                diff--;

                let min = Math.floor(diff / 60);
                let sec = diff % 60;

                timerText.textContent = `Please wait ${min}:${sec.toString().padStart(2, '0')} before resending. `;

                if (diff <= 0) {
                    clearInterval(countdown);
                    timerText.textContent = "";
                    link.style.pointerEvents = "auto";
                    link.style.opacity = "1";
                }
            }, 1000);
        }
    </script>

<?php elseif ($step == 3): ?>
    <p>Set your new password</p>
    <input type="password" name="newPassword" placeholder="New Password" required>
    <input type="password" name="confirmPassword" placeholder="Re-enter Password" required>
    <button class="ConBut">Update Password</button>
<?php endif; ?>

<a class="back-link" href="index.php">Back to Login</a>

</form>
</div>

</body>
</html>
