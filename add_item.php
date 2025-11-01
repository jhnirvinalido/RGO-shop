<?php
// add_item.php
session_start();
require_once 'db.php';

// Optional: admin gate
// if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'Invalid method']);
  exit;
}

function respond($ok, $extra = []) {
  echo json_encode(['ok'=>$ok] + $extra);
  exit;
}

$category    = trim($_POST['category']    ?? '');
$item_name   = trim($_POST['item_name']   ?? '');
$description = trim($_POST['description'] ?? '');
$base_price  = ($_POST['base_price'] ?? '') !== '' ? floatval($_POST['base_price']) : null;
$sizes_json  = $_POST['sizes_json'] ?? '[]';
$cover_index = isset($_POST['cover_index']) ? intval($_POST['cover_index']) : 0;

if ($category === '' || $item_name === '') {
  respond(false, ['error' => 'Category and Item Name are required.']);
}

$sizes = json_decode($sizes_json, true);
if (!is_array($sizes)) $sizes = [];

$conn->begin_transaction();

try {
  // Insert item
  if ($base_price === null) {
    $stmt = $conn->prepare("INSERT INTO items (category, item_name, description, base_price) VALUES (?, ?, ?, NULL)");
    if (!$stmt) throw new Exception($conn->error);
    $stmt->bind_param("sss", $category, $item_name, $description);
  } else {
    $stmt = $conn->prepare("INSERT INTO items (category, item_name, description, base_price) VALUES (?, ?, ?, ?)");
    if (!$stmt) throw new Exception($conn->error);
    $stmt->bind_param("sssd", $category, $item_name, $description, $base_price);
  }
  if (!$stmt->execute()) throw new Exception($stmt->error);
  $item_id = $conn->insert_id;
  $stmt->close();

  // Insert sizes
  if (!empty($sizes)) {
    $stmtS = $conn->prepare("INSERT INTO item_sizes (item_id, size_label, price, stock, notes) VALUES (?, ?, ?, ?, ?)");
    if (!$stmtS) throw new Exception($conn->error);
    foreach ($sizes as $s) {
      $label = trim($s['label'] ?? '');
      if ($label === '') continue;
      $price = isset($s['price']) ? floatval($s['price']) : 0;
      $stock = ($s['stock'] === null || $s['stock'] === '') ? null : (int)$s['stock'];
      $notes = trim($s['notes'] ?? '');
      $stmtS->bind_param("isdss", $item_id, $label, $price, $stock, $notes);
      if (!$stmtS->execute()) throw new Exception($stmtS->error);
    }
    $stmtS->close();
  }

  // Insert images
  if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
    $names = $_FILES['images']['name'];
    $tmps  = $_FILES['images']['tmp_name'];
    $errs  = $_FILES['images']['error'];

    for ($i = 0; $i < count($names); $i++) {
      if ($errs[$i] !== UPLOAD_ERR_OK) continue;
      $data = file_get_contents($tmps[$i]);
      if ($data === false) continue;

      $is_cover = ($i === $cover_index) ? 1 : 0;

      $stmtI = $conn->prepare("INSERT INTO item_images (item_id, image_data, is_cover) VALUES (?, ?, ?)");
      if (!$stmtI) throw new Exception($conn->error);
      $null = NULL;
      $stmtI->bind_param("ibi", $item_id, $null, $is_cover);
      $stmtI->send_long_data(1, $data);
      if (!$stmtI->execute()) throw new Exception($stmtI->error);
      $stmtI->close();
    }
  }

  $conn->commit();
  respond(true, ['item_id' => $item_id]);

} catch (Exception $e) {
  $conn->rollback();
  respond(false, ['error' => 'Save failed: ' . $e->getMessage()]);
}
