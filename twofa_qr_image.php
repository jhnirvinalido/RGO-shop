<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/vendor/autoload.php';

use Endroid\QrCode\QrCode;
use Endroid\QrCode\ErrorCorrectionLevel;

$uri = $_GET['uri'] ?? '';

if (!$uri) {
    http_response_code(400);
    exit("Missing QR data");
}

try {
    $qrCode = new QrCode($uri);
    $qrCode->setSize(300);
    $qrCode->setMargin(10);
    $qrCode->setEncoding('UTF-8');

    // Correct error correction for v3
    $qrCode->setErrorCorrectionLevel(
        new ErrorCorrectionLevel(ErrorCorrectionLevel::HIGH)
    );

    header('Content-Type: '.$qrCode->getContentType());
    echo $qrCode->writeString();

} catch (Exception $e) {
    http_response_code(500);
    echo "QR Generation Error: " . $e->getMessage();
}
