<?php

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp_flow'])) {
    session_start();
    include 'db.php';

    // ===== ADDED: reCAPTCHA verification function (can be reused anywhere) =====
    if (!function_exists('verify_recaptcha')) {
        /**
         * Verifies Google reCAPTCHA v2 checkbox using server-side request.
         * @param string $response g-recaptcha-response token from client
         * @param string|null $remoteIp Optional user IP
         * @return bool true if verification succeeds
         */
        function verify_recaptcha($response, $remoteIp = null) {
            // Use Google's official TEST SECRET for the test site key in this page.
            // Replace with your real secret in production.
            $secret = '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe';

            if (!$response) return false;

            $postFields = [
                'secret'   => $secret,
                'response' => $response,
            ];
            if (!empty($remoteIp)) {
                $postFields['remoteip'] = $remoteIp;
            }

            // Prefer cURL; fall back to file_get_contents if needed
            $endpoint = 'https://www.google.com/recaptcha/api/siteverify';

            if (function_exists('curl_init')) {
                $ch = curl_init($endpoint);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                $result = curl_exec($ch);
                $curlErr = curl_error($ch);
                curl_close($ch);
                if ($result === false) {
                    // If cURL failed, be conservative: fail closed
                    return false;
                }
            } else {
                $opts = [
                    'http' => [
                        'method'  => 'POST',
                        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                        'content' => http_build_query($postFields),
                        'timeout' => 10,
                    ]
                ];
                $context = stream_context_create($opts);
                $result = @file_get_contents($endpoint, false, $context);
                if ($result === false) {
                    // Fail closed on error
                    return false;
                }
            }

            $data = json_decode($result, true);
            return is_array($data) && !empty($data['success']);
        }
    }
    // ===== END ADDED FUNCTION =====

    function redirect_back($extra = '') {
        $target = strtok($_SERVER['REQUEST_URI'], '?');
        header("Location: {$target}" . ($extra ? "?{$extra}" : ""));
        exit();
    }

    if (!isset($_SESSION['otp_flash'])) $_SESSION['otp_flash'] = '';

    $email = isset($_POST['otpEmail']) ? strtolower(trim($_POST['otpEmail'])) : '';
    $inputCode = isset($_POST['otpCode']) ? trim($_POST['otpCode']) : '';
    $setFlash = function($msg) { $_SESSION['otp_flash'] = $msg; };

    if (!isset($_POST['verify_otp'])) {
        $setFlash("Unsupported action.");
        redirect_back("show=otp");
    }

    if (!$email || !$inputCode) {
        $setFlash("Please enter both your email and the 6-digit code.");
        redirect_back("show=otp");
    }

    $savedCode  = isset($_SESSION['OTP_CODE'])    ? $_SESSION['OTP_CODE']    : null;
    $savedEmail = isset($_SESSION['OTP_EMAIL'])   ? $_SESSION['OTP_EMAIL']   : null;
    $expires    = isset($_SESSION['OTP_EXPIRES']) ? (int)$_SESSION['OTP_EXPIRES'] : 0;

    if (!$savedCode || !$savedEmail) {
        $setFlash("No active code found for verification. Please request a code first, then come back to verify.");
        redirect_back("show=otp");
    }
    if (time() > $expires) {
        $setFlash("Your code has expired. Please request a new code and try again.");
        redirect_back("show=otp");
    }
    if (strcasecmp($email, $savedEmail) !== 0 || $inputCode !== $savedCode) {
        $setFlash("Invalid code or email. Double-check and try again.");
        redirect_back("show=otp");
    }

    $stmt = $conn->prepare("
        SELECT s.id, s.fullname, s.sr_code, s.course, sl.email
        FROM students s
        INNER JOIN student_login sl ON s.login_id = sl.login_id
        WHERE sl.email = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $res->num_rows === 1) {
            $row = $res->fetch_assoc();
            $_SESSION['student_id'] = $row['id'];
            $_SESSION['email']      = $row['email'];
            $_SESSION['fullname']   = $row['fullname'];
            $_SESSION['sr_code']    = $row['sr_code'];
            $_SESSION['course']     = $row['course'];

            // Invalidate OTP immediately
            unset($_SESSION['OTP_CODE'], $_SESSION['OTP_EMAIL'], $_SESSION['OTP_EXPIRES'], $_SESSION['otp_flash']);

            header("Location: StudentDash.php");
            exit();
        } else {
            $setFlash("No account found for that email.");
            redirect_back("show=otp");
        }
    } else {
        $setFlash("A server error occurred. Please try again later.");
        redirect_back("show=otp");
    }
}


ob_start();
session_start();
include 'db.php';

// ===== ADDED: ensure verify_recaptcha() exists in this scope as well =====
if (!function_exists('verify_recaptcha')) {
    /**
     * Verifies Google reCAPTCHA v2 checkbox using server-side request.
     * (Duplicate-safe definition in case the top block didn't run.)
     */
    function verify_recaptcha($response, $remoteIp = null) {
        $secret = '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe'; // Replace in production
        if (!$response) return false;
        $postFields = ['secret'=>$secret,'response'=>$response];
        if (!empty($remoteIp)) $postFields['remoteip'] = $remoteIp;

        $endpoint = 'https://www.google.com/recaptcha/api/siteverify';
        if (function_exists('curl_init')) {
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $result = curl_exec($ch);
            curl_close($ch);
            if ($result === false) return false;
        } else {
            $opts = ['http'=>[
                'method'=>'POST',
                'header'=>"Content-type: application/x-www-form-urlencoded\r\n",
                'content'=>http_build_query($postFields),
                'timeout'=>10
            ]];
            $context = stream_context_create($opts);
            $result = @file_get_contents($endpoint, false, $context);
            if ($result === false) return false;
        }
        $data = json_decode($result, true);
        return is_array($data) && !empty($data['success']);
    }
}
// ===== END ADDED =====

$error = '';
$inputEmail = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['otp_flow'])) {
    $inputEmail = strtolower(trim($_POST['UserUsername']));
    $password = trim($_POST['UserPassword']);

    // ===== ADDED: Gate login behind successful CAPTCHA =====
    $recaptchaResponse = isset($_POST['g-recaptcha-response']) ? trim($_POST['g-recaptcha-response']) : '';
    $remoteIp = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;

    if (!$recaptchaResponse) {
        $error = "Please complete the CAPTCHA challenge.";
    } elseif (!verify_recaptcha($recaptchaResponse, $remoteIp)) {
        $error = "CAPTCHA verification failed. Please try again.";
    } else {
        // Only proceed to password check if CAPTCHA is valid
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
    // ===== END ADDED =====
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
  display:flex; gap:8px; align-items:center; justify-content:center;
  margin: 16px auto 8px; max-width: 520px;
}
.switcher button {
  padding:10px 14px; border:1px solid #ccc; background:#f7f7f7; cursor:pointer; border-radius:8px;
}
.switcher button.active { background:#222; color:#fff; }
.panel { display:none; }
.panel.active { display:block; }
.note { display:block; margin-top:6px; color:#666; font-size:12px; }
.otp-flash { color:#0a7; margin-top:10px; font-size:14px; }
.otp-error { color:#c00; margin-top:10px; font-size:14px; }
.login-container { max-width:520px; margin: 0 auto; }
.links { margin-top: 10px; }
</style>
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

<h2 class="page-title">Student Portal Login</h2>

<!-- ===== ADDED: Switch buttons ===== -->
<div class="switcher">
  <button type="button" id="btnPwd" class="active">Password Login</button>
  <button type="button" id="btnOtp">One-Time Pass</button>
</div>

<!-- ===== ORIGINAL PANEL (Password Login) — unchanged ===== -->
<div class="login-container panel active" id="panelPwd">
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


<div class="login-container panel" id="panelOtp">
    <?php

      $otpFlash = $_SESSION['otp_flash'] ?? '';
      if ($otpFlash) {
          echo "<p class='otp-flash'>".htmlspecialchars($otpFlash)."</p>";
          unset($_SESSION['otp_flash']);
      } else {
          echo "<small class='note'>Enter the 6-digit code that was sent to your email. If you don’t have one, request a code first.</small>";
      }
    ?>
    <form method="POST" action="">
        <h3>Verify One-Time Pass</h3>
       
        <input type="hidden" name="otp_flow" value="1">

        <input type="email" placeholder="Email address" name="otpEmail"
               value="<?php echo htmlspecialchars($inputEmail); ?>" required>

        <input type="text" placeholder="Enter 6-digit code" name="otpCode"
               pattern="\d{6}" inputmode="numeric" autocomplete="one-time-code" required>

        <button type="submit" name="verify_otp">Verify &amp; Sign in</button>

        <div class="links" style="margin-top:10px;">
            <a href="#" id="backToPwd">Back to password login</a>
        </div>
    </form>
</div>

<script>
  // ===== ADDED: Panel toggler =====
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
  if (backToPwd) backToPwd.addEventListener('click', function(e){ e.preventDefault(); showPwd(); });

  // Auto-open OTP panel if server indicated (e.g., after failed verify)
  (function(){
    const params = new URLSearchParams(window.location.search);
    if (params.get('show') === 'otp') showOtp();
  })();
</script>
</body>
</html>
