<?php
ob_start();
session_start();
include 'db.php';

$error = '';
$inputEmail = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $inputEmail = strtolower(trim($_POST['UserUsername']));
    $password = trim($_POST['UserPassword']);

    if ($inputEmail && $password) {
        $stmt = $conn->prepare("
            SELECT s.id, s.fullname, s.sr_code, s.course, sl.password
            FROM students s
            INNER JOIN student_login sl ON s.login_id = sl.login_id
            WHERE sl.email = ?
            LIMIT 1
        ");

        if (!$stmt) die("Prepare failed: (" . $conn->errno . ") " . $conn->error);

        $stmt->bind_param("s", $inputEmail);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows === 1) {
            $row = $result->fetch_assoc();

            if (password_verify($password, $row['password'])) {
                $_SESSION['student_id'] = $row['id'];
                $_SESSION['email'] = $inputEmail;
                $_SESSION['fullname'] = $row['fullname'];
                $_SESSION['sr_code'] = $row['sr_code'];
                $_SESSION['course'] = $row['course'];

                header("Location: StudentDash.php");
                exit();
            } else {
                $error = "Invalid password.";
            }
        } else {
            $error = "No account found with that email.";
        }

        $stmt->close();
    } else {
        $error = "Please fill in all required fields.";
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
</head>
<body>
<header style=" width: 100%;
    height: 270px;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: flex-start;
    padding: 10px 40px;
    box-sizing: border-box;
    overflow: hidden;
    background-image: url('ito.jpg'), url('headerbg.png'); =
    background-position: center;
    background-size: 100% 100%;  
    background-repeat: no-repeat; "></header>

<h2 class="page-title">Admin Portal Login</h2>

<div class="login-container">
    <form method="POST" action="">
        <h3>Please Login</h3>
        <input type="text" placeholder="Username" name="UserUsername" 
               value="<?php echo htmlspecialchars($inputEmail); ?>" required>
        <input type="password" placeholder="Password" name="UserPassword" required>
        <small class="note">* Password is case sensitive</small>
        <div class="captcha-box">
            <div class="g-recaptcha" data-sitekey="6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI"></div>
        </div>
        <button type="submit">Sign in</button>
        <?php if(!empty($error)) echo "<p style='color:red; margin-top:10px;'>$error</p>"; ?>
        <div class="links">
            <a href="ForgotPass.php">Forgot password? Click here</a> | 
            <a href="#">Contact Us</a>
        </div>
    </form>
</div>
</body>
</html>
