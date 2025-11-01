<?php
// image.php
require_once 'db.php';

$img_id = isset($_GET['img']) ? intval($_GET['img']) : 0;
if ($img_id <= 0) {
  http_response_code(404);
  exit;
}

$stmt = $conn->prepare("SELECT image_data FROM item_images WHERE image_id = ?");
$stmt->bind_param("i", $img_id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

if (!$row) { http_response_code(404); exit; }

$data = $row['image_data'];
// Try to sniff MIME (default to jpeg)
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->buffer($data);
if (!in_array($mime, ['image/jpeg','image/png','image/webp','image/gif'])) {
  $mime = 'image/jpeg';
}

header("Content-Type: $mime");
header("Cache-Control: public, max-age=31536000, immutable");
echo $data;
