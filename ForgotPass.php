<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';
include 'db.php';
session_start();

$step = 1;
$error = "";
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // STEP 1: Email verification
    if (isset($_POST['EmailForgotPasss']) && !isset($_POST['verifyCode']) && !isset($_POST['newPassword'])) {
        $email = trim($_POST['EmailForgotPasss']);
        $stmt = $conn->prepare("SELECT login_id FROM student_login WHERE email = ?");
        if ($stmt === false) {
            die("MySQL prepare failed: " . $conn->error);
        }
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $verification_code = rand(100000, 999999);

            $_SESSION['reset_email'] = $email;
            $_SESSION['verification_code'] = $verification_code;
            $_SESSION['code_time'] = time();

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
                $mail->Subject = 'RGO University Password Reset Verification Code';
                $mail->Body = "
                    <div style='font-family: Arial, sans-serif;'>
                        <h2 style='color:#d62828;'>RGO University</h2>
                        <p>Hello!</p>
                        <p>Your verification code for password reset is:</p>
                        <h2 style='color:#d62828;'>$verification_code</h2>
                        <p>This code will expire in 10 minutes.</p>
                        <p><b>RGO University Portal</b></p>
                    </div>
                ";

                $mail->send();
                echo "<script>alert('Verification code has been sent to your G-Suite email.');</script>";
                $step = 2;

            } catch (Exception $e) {
                $error = "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
            }
        } else {
            $error = "No account found with that G-Suite email.";
        }
        $stmt->close();
    }

    // STEP 2: Verify Code
    elseif (isset($_POST['verifyCode'])) {
        $entered_code = trim($_POST['verifyCode']);
        if (isset($_SESSION['verification_code']) && isset($_SESSION['code_time'])) {
            $time_diff = time() - $_SESSION['code_time'];
            if ($time_diff > 600) { // 10 minutes
                $error = "Verification code has expired. Please request a new one.";
                $step = 1;
            } elseif ($entered_code == $_SESSION['verification_code']) {
                echo "<script>alert('Verification successful! You may now reset your password.');</script>";
                $step = 3;
            } else {
                $error = "Invalid verification code.";
                $step = 2;
            }
        } else {
            $error = "Verification session expired. Please try again.";
            $step = 1;
        }
    }

    // STEP 3: Update Password
    elseif (isset($_POST['newPassword']) && isset($_POST['confirmPassword'])) {
        $new_pass = trim($_POST['newPassword']);
        $confirm_pass = trim($_POST['confirmPassword']);

        if ($new_pass === $confirm_pass) {
            if (isset($_SESSION['reset_email'])) {
                $email = $_SESSION['reset_email'];
                $hashed = password_hash($new_pass, PASSWORD_DEFAULT);

                $stmt = $conn->prepare("UPDATE student_login SET password = ? WHERE email = ?");
                $stmt->bind_param("ss", $hashed, $email);
                if ($stmt->execute()) {
                    echo "<script>alert('Password successfully updated! You may now log in.');
                        window.location.href = 'UserLogin.php';
                    </script>";
                    session_unset();
                    session_destroy();
                    $step = 1;
                } else {
                    $error = "Failed to update password. Try again.";
                    $step = 3;
                }
                $stmt->close();
            } else {
                $error = "Session expired. Please restart the process.";
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
    <link rel="stylesheet" href="style.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Arial", sans-serif;
        }

        body {
            min-height: 100vh;
            background-color: #f8f8f8;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        header {
            width: 100%;
            height: 230px;
            background: url('header.png') center/100% 100% no-repeat;
        }

        .Forgottpass-Container {
            background: white;
            width: 380px;
            padding: 40px 30px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.15);
            text-align: center;
            animation: fadeIn .5s ease;
            margin-top: 200px;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .Forgottpass-Container h2 {
            color: #d62828;
            font-size: 24px;
            margin-bottom: 10px;
        }

        .Forgottpass-Container p {
            color: #555;
            font-size: 14px;
            margin-bottom: 25px;
        }

        .Forgottpass-Container input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 15px;
            margin-bottom: 20px;
            transition: .3s;
        }

        .Forgottpass-Container input:focus {
            border-color: #d62828;
            outline: none;
        }

        .ConBut {
            width: 100%;
            background-color: #ffd633;
            color: black;
            font-weight: bold;
            border: none;
            border-radius: 8px;
            padding: 12px;
            cursor: pointer;
            transition: .3s;
        }

        .ConBut:hover {
            background-color: #ffe066;
        }

        .back-link {
            display: block;
            margin-top: 15px;
            font-size: 14px;
            color: #007b00;
            text-decoration: none;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .message {
            margin-bottom: 15px;
            font-size: 14px;
            color: red;
        }

        .success {
            color: green;
        }
    </style>
</head>

<body>
    <header></header>
    <div class="Forgottpass-Container">
        <form method="POST" action="">
            <h2>Forgot Password</h2>

            <?php if ($error): ?>
                <script>alert("<?php echo $error; ?>");</script>
            <?php endif; ?>

            <?php if ($step == 1): ?>
                <p>Enter your G-Suite account to reset your password</p>
                <input type="email" name="EmailForgotPasss" placeholder="Enter your G-Suite Account" required>
                <button class="ConBut" type="submit">Continue</button>

            <?php elseif ($step == 2): ?>
                <p>Enter the verification code sent to your email</p>
                <input type="email" name="EmailForgotPasss" value="<?php echo $_SESSION['reset_email']; ?>" readonly><br>
                <input type="text" name="verifyCode" placeholder="Enter verification code" required><br>
                <button class="ConBut" type="submit">Verify</button>

            <?php elseif ($step == 3): ?>
                <p>Enter your new password below</p>
                <input type="password" name="newPassword" placeholder="New Password" required><br>
                <input type="password" name="confirmPassword" placeholder="Re-enter Password" required><br>
                <button class="ConBut" type="submit">Update Password</button>
            <?php endif; ?>

            <a href="UserLogin.php" class="back-link">Back to Login</a>
        </form>
    </div>
</body>
</html>
