<?php
session_start();
require 'vendor/autoload.php';

use OTPHP\TOTP;

// generate secret if missing
if (!$_SESSION['gauth_secret']) {
    $totp = TOTP::generate();
    $_SESSION['gauth_secret'] = $totp->getSecret();
}

$secret = $_SESSION['gauth_secret'];
$totp = TOTP::create($secret);
$totp->setLabel("RGO User");
$totp->setIssuer("RGO University Shop");

$qrUrl = 'https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' . urlencode($totp->getProvisioningUri());

header("Content-Type: image/png");
echo file_get_contents($qrUrl);
