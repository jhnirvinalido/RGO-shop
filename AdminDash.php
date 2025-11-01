<?php
session_start();
include 'db.php';

/* ------------------------------------------
   OPTIONAL: auth check for admin (add yours)
------------------------------------------- */
// if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
//   header('Location: UserLogin.php'); exit();
// }

/* ------------------------------------------
   Add Student (your original code - unchanged)
------------------------------------------- */
$error = '';
$success = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_student'])) {
  $fullname = trim($_POST['fullname'] ?? '');
  $sr_code = trim($_POST['sr_code'] ?? '');
  $gsuite_email = trim($_POST['gsuite_email'] ?? '');
  $course = trim($_POST['course'] ?? '');
  $password = trim($_POST['password'] ?? '');

  if ($fullname && $sr_code && $gsuite_email && $course && $password) {
    $profile_pic_data = null;
    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
      $profile_pic_data = file_get_contents($_FILES['profile_pic']['tmp_name']);
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    $stmt_login = $conn->prepare("INSERT INTO student_login (email, password) VALUES (?, ?)");
    if (!$stmt_login) {
      $error = "Login prepare failed: " . $conn->error;
    } else {
      $stmt_login->bind_param("ss", $gsuite_email, $hashed_password);
      if ($stmt_login->execute()) {
        $login_id = $conn->insert_id;
        $stmt_login->close();

        $stmt_student = $conn->prepare("INSERT INTO students (login_id, fullname, sr_code, gsuite_email, course, profile_pic) VALUES (?, ?, ?, ?, ?, ?)");
        if (!$stmt_student) {
          $error = "Student prepare failed: " . $conn->error;
        } else {
          $null = NULL;
          $stmt_student->bind_param("issssb", $login_id, $fullname, $sr_code, $gsuite_email, $course, $null);
          if ($profile_pic_data !== null) {
            $stmt_student->send_long_data(5, $profile_pic_data);
          }

          if ($stmt_student->execute()) {
            $success = "Student added successfully!";
          } else {
            $error = "Student insert failed [{$stmt_student->errno}]: " . $stmt_student->error;
          }
          $stmt_student->close();
        }
      } else {
        if (isset($stmt_login->errno) && (int) $stmt_login->errno === 1062) {
          $error = "That email is already registered in student_login.";
        } else {
          $error = "Login insert failed [{$stmt_login->errno}]: " . $stmt_login->error;
        }
      }
    }
  } else {
    $error = "Please fill in all required fields.";
  }
}

/* ------------------------------------------
   AJAX: Get one item (for Edit modal prefill)
------------------------------------------- */
if (isset($_GET['action']) && $_GET['action'] === 'get_item' && isset($_GET['id'])) {
  $item_id = (int) $_GET['id'];

  $item_stmt = $conn->prepare("SELECT item_id, category, item_name, description, base_price FROM items WHERE item_id=?");
  $item_stmt->bind_param("i", $item_id);
  $item_stmt->execute();
  $item = $item_stmt->get_result()->fetch_assoc();
  $item_stmt->close();

  $sizes = [];
  $sz = $conn->prepare("SELECT label, price, stock, notes FROM item_sizes WHERE item_id=? ORDER BY size_id ASC");
  $sz->bind_param("i", $item_id);
  $sz->execute();
  $rsz = $sz->get_result();
  while ($row = $rsz->fetch_assoc()) {
    $sizes[] = [
      'label' => $row['label'],
      'price' => is_null($row['price']) ? 0 : (float) $row['price'],
      'stock' => is_null($row['stock']) ? null : (int) $row['stock'],
      'notes' => $row['notes'] ?? ''
    ];
  }
  $sz->close();

  $images = [];
  $im = $conn->prepare("SELECT image_id, image_data FROM item_images WHERE item_id=? ORDER BY image_id ASC");
  $im->bind_param("i", $item_id);
  $im->execute();
  $rim = $im->get_result();
  while ($row = $rim->fetch_assoc()) {
    $images[] = [
      'image_id' => (int) $row['image_id'],
      'data_url' => 'data:image/jpeg;base64,' . base64_encode($row['image_data'])
    ];
  }
  $im->close();

  header('Content-Type: application/json');
  echo json_encode(['ok' => (bool) $item, 'item' => $item, 'sizes' => $sizes, 'images' => $images]);
  exit();
}

/* ------------------------------------------
   Add Item (existing)
------------------------------------------- */
$add_item_success = '';
$add_item_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
  // Basic fields
  $category = trim($_POST['category'] ?? '');
  $item_name = trim($_POST['item_name'] ?? '');
  $description = trim($_POST['description'] ?? '');
  $base_price = $_POST['base_price'] === '' ? null : (float) $_POST['base_price'];

  // Sizes JSON (serialized from modal)
  $sizes_json = $_POST['sizes_json'] ?? '[]';
  $sizes = json_decode($sizes_json, true);
  if (!is_array($sizes))
    $sizes = [];

  if (!$category || !$item_name) {
    $add_item_error = "Category and Item Name are required.";
  } else {
    $stmt_item = $conn->prepare("INSERT INTO items (category, item_name, description, base_price, created_at) VALUES (?, ?, ?, ?, NOW())");
    if (!$stmt_item) {
      $add_item_error = "Item prepare failed: " . $conn->error;
    } else {
      $stmt_item->bind_param("sssd", $category, $item_name, $description, $base_price);
      if ($stmt_item->execute()) {
        $item_id = $stmt_item->insert_id;
        $stmt_item->close();

        // Insert images
        if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
          $names = $_FILES['images']['name'];
          $tmps = $_FILES['images']['tmp_name'];
          $errs = $_FILES['images']['error'];

          for ($i = 0; $i < count($names); $i++) {
            if ($errs[$i] === UPLOAD_ERR_OK && is_uploaded_file($tmps[$i])) {
              $blob = file_get_contents($tmps[$i]);
              if ($blob !== false) {
                $stmt_img = $conn->prepare("INSERT INTO item_images (item_id, image_data) VALUES (?, ?)");
                if ($stmt_img) {
                  $null = NULL;
                  $stmt_img->bind_param("ib", $item_id, $null);
                  $stmt_img->send_long_data(1, $blob);
                  $stmt_img->execute();
                  $stmt_img->close();
                }
              }
            }
          }
        }

        // Insert sizes
        if (!empty($sizes)) {
          $stmt_size = $conn->prepare("INSERT INTO item_sizes (item_id, label, price, stock, notes) VALUES (?, ?, ?, ?, ?)");
          if ($stmt_size) {
            foreach ($sizes as $row) {
              $label = trim($row['label'] ?? '');
              if ($label === '')
                continue;
              $price = (float) ($row['price'] ?? 0);
              $stock = $row['stock'] === null || $row['stock'] === '' ? null : (int) $row['stock'];
              $notes = trim($row['notes'] ?? '');
              $s = $stock === null ? 0 : $stock;
              $stmt_size->bind_param("issis", $item_id, $label, $price, $s, $notes);
              $stmt_size->execute();
            }
            $stmt_size->close();
          }
        }

        $add_item_success = "Item saved!";
      } else {
        $add_item_error = "Item insert failed [{$stmt_item->errno}]: " . $stmt_item->error;
      }
    }
  }
}

/* ------------------------------------------
   UPDATE Item (NEW)
------------------------------------------- */
$update_item_success = '';
$update_item_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_item'])) {
  $item_id = (int) ($_POST['edit_item_id'] ?? 0);
  $category = trim($_POST['category'] ?? '');
  $item_name = trim($_POST['item_name'] ?? '');
  $description = trim($_POST['description'] ?? '');
  $base_price = $_POST['base_price'] === '' ? null : (float) $_POST['base_price'];
  $sizes_json = $_POST['sizes_json'] ?? '[]';
  $sizes = json_decode($sizes_json, true);
  if (!is_array($sizes))
    $sizes = [];

  if ($item_id <= 0) {
    $update_item_error = "Invalid item id.";
  } elseif (!$category || !$item_name) {
    $update_item_error = "Category and Item Name are required.";
  } else {
    $conn->begin_transaction();
    try {
      $st = $conn->prepare("UPDATE items SET category=?, item_name=?, description=?, base_price=? WHERE item_id=?");
      $st->bind_param("sssdi", $category, $item_name, $description, $base_price, $item_id);
      $st->execute();
      $st->close();

      // Replace sizes
      $conn->query("DELETE FROM item_sizes WHERE item_id=" . (int) $item_id);
      if (!empty($sizes)) {
        $ins = $conn->prepare("INSERT INTO item_sizes (item_id, label, price, stock, notes) VALUES (?, ?, ?, ?, ?)");
        foreach ($sizes as $row) {
          $label = trim($row['label'] ?? '');
          if ($label === '')
            continue;
          $price = (float) ($row['price'] ?? 0);
          $stock = $row['stock'] === null || $row['stock'] === '' ? null : (int) $row['stock'];
          $notes = trim($row['notes'] ?? '');
          $s = $stock === null ? 0 : $stock;
          $ins->bind_param("issis", $item_id, $label, $price, $s, $notes);
          $ins->execute();
        }
        $ins->close();
      }

      // If user uploaded *any* new images, replace all existing images
      if (isset($_FILES['images']) && is_array($_FILES['images']['name']) && $_FILES['images']['name'][0] !== '') {
        $conn->query("DELETE FROM item_images WHERE item_id=" . (int) $item_id);
        $names = $_FILES['images']['name'];
        $tmps = $_FILES['images']['tmp_name'];
        $errs = $_FILES['images']['error'];
        for ($i = 0; $i < count($names); $i++) {
          if ($errs[$i] === UPLOAD_ERR_OK && is_uploaded_file($tmps[$i])) {
            $blob = file_get_contents($tmps[$i]);
            if ($blob !== false) {
              $stmt_img = $conn->prepare("INSERT INTO item_images (item_id, image_data) VALUES (?, ?)");
              $null = NULL;
              $stmt_img->bind_param("ib", $item_id, $null);
              $stmt_img->send_long_data(1, $blob);
              $stmt_img->execute();
              $stmt_img->close();
            }
          }
        }
      }

      $conn->commit();
      $update_item_success = "Item updated!";
    } catch (Exception $e) {
      $conn->rollback();
      $update_item_error = "Update failed: " . $e->getMessage();
    }
  }
}

/* ------------------------------------------
   DELETE Item (NEW)
------------------------------------------- */
$delete_item_success = '';
$delete_item_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item']) && isset($_POST['item_id'])) {
  $del_id = (int) $_POST['item_id'];
  if ($del_id <= 0) {
    $delete_item_error = "Invalid delete id.";
  } else {
    $conn->begin_transaction();
    try {
      $conn->query("DELETE FROM item_images WHERE item_id=" . (int) $del_id);
      $conn->query("DELETE FROM item_sizes  WHERE item_id=" . (int) $del_id);
      $conn->query("DELETE FROM items      WHERE item_id=" . (int) $del_id);
      $conn->commit();
      $delete_item_success = "Item deleted.";
    } catch (Exception $e) {
      $conn->rollback();
      $delete_item_error = "Delete failed: " . $e->getMessage();
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>RGO University Shop – Admin</title>
  <link rel="stylesheet" href="style.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    /* ====== Your original design (unchanged) ====== */

    body {
      margin: 0;
      font-family: Arial, sans-serif;
      background: #f5f5f5;
    }

    header {
      position: relative;
      display: flex;
      align-items: center;
      justify-content: flex-start;
      padding: 10px 40px;
      background-image: url('headerbg.png');
      background-position: center;
      background-size: 100% 160%;
      background-repeat: no-repeat;
      height: 250px;
      box-sizing: border-box;
      overflow: hidden;
    }

    header::before {
      content: "";
      position: absolute;
      inset: 0;
      background: rgba(0, 0, 0, .38);
    }

    header * {
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
      color: white;
    }

    .header-text .highlight {
      font-size: 1.1rem;
      font-weight: bold;
      color: black;
    }

    nav.category-r {
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: #fff;
      padding: 10px 15px;
    }

    .menu-btn {
      background: transparent;
      color: black;
      border: none;
      border-radius: 8px;
      padding: 10px 14px;
      cursor: pointer;
    }

    .menu-btn:hover {
      background: wheat;
    }

    .sidebar {
      position: fixed;
      top: 0;
      left: -280px;
      width: 280px;
      height: 100vh;
      background: #111827;
      color: #fff;
      box-shadow: 4px 0 20px rgba(0, 0, 0, .25);
      transition: left .25s ease;
      z-index: 3000;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }

    .sidebar.open {
      left: 0;
    }

    .sidebar-content {
      padding: 20px;
    }

    .profile-pic {
      width: 90px;
      height: 90px;
      border-radius: 999px;
      object-fit: cover;
      border: 2px solid #374151;
    }

    .profile-name {
      margin: 10px 0 0;
      font-weight: 700;
      font-size: 1.1rem;
      color: white;
    }

    .course {
      margin: 0 0 14px;
      color: red;
      font-size: .9rem;
    }

    .sidebar-btn {
      width: 100%;
      text-align: left;
      background: #1f2937;
      border: 1px solid #374151;
      color: #fff;
      padding: 10px 12px;
      border-radius: 8px;
      cursor: pointer;
      margin: 6px 0;
      transition: background .2s;
    }

    .sidebar-btn:hover {
      background: #374151;
    }

    .signout-btn {
      background: #ef4444;
      border: none;
      color: #fff;
      padding: 12px;
      margin: 14px;
      border-radius: 8px;
      cursor: pointer;
    }

    .signout-btn:hover {
      background: #b91c1c;
    }

    .overlay {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .4);
      display: none;
      z-index: 2000;
    }

    .overlay.show {
      display: block;
    }

    .home-section,
    .orders-section,
    .stocks-section,
    .manual-section {
      display: none;
      padding: 40px;
      background: #f5f5f5;
      min-height: calc(100vh - 250px - 55px);
    }

    .home-section {
      display: block;
    }

    .dashboard-title {
      text-align: center;
      font-size: 1.8rem;
      color: #333;
      margin-bottom: 20px;
    }

    .stats {
      display: flex;
      gap: 30px;
      justify-content: center;
      margin-bottom: 40px;
    }

    .stat-card {
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 4px 6px rgba(0, 0, 0, .1);
      padding: 20px;
      text-align: center;
      width: 200px;
      transition: transform .2s;
    }

    .stat-card:hover {
      transform: scale(1.05);
    }

    .stat-card h3 {
      color: #666;
      font-size: 1rem;
      margin: 0 0 10px;
    }

    .stat-card h2 {
      color: #d62828;
      margin: 10px 0;
      font-size: 2.4rem;
    }

    .chart-container {
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 4px 6px rgba(0, 0, 0, .1);
      padding: 20px;
      width: min(600px, 95vw);
      height: 400px;
      margin: 0 auto;
    }

    .search-bar {
      float: right;
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 20px;
    }

    .search-bar input {
      padding: 10px 14px;
      border: 1px solid #ccc;
      border-radius: 8px;
      font-size: 1rem;
      width: 250px;
    }

    .search-bar button,
    #addItemBtn {
      background: #dc2626;
      color: #fff;
      padding: 10px 16px;
      border: none;
      border-radius: 8px;
      cursor: pointer;
    }

    .search-bar button:hover,
    #addItemBtn:hover {
      background: #b91c1c;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      background: #fff;
      border-radius: 12px;
      overflow: hidden;
    }

    th,
    td {
      padding: 12px 15px;
      text-align: left;
      border: 1px solid #eee;
      border-bottom: 1px solid #eee;
    }

    thead th {
      background: #fafafa;
      font-weight: bold;
    }

    .view-btn {
      background: #2563eb;
      color: #fff;
      border: none;
      padding: 6px 12px;
      border-radius: 8px;
      cursor: pointer;
      margin-right: 5px;
    }

    .view-btn:hover {
      background: #1d4ed8;
    }

    .done-btn {
      background: #16a34a;
      color: #fff;
      border: none;
      padding: 6px 12px;
      border-radius: 8px;
      cursor: pointer;
    }

    .done-btn:hover {
      background: #15803d;
    }

    .delete-btn {
      background: #dc2626;
      color: #fff;
      border: none;
      padding: 6px 12px;
      border-radius: 8px;
      cursor: pointer;
      margin-left: 6px;
    }

    .delete-btn:hover {
      background: #b91c1c;
    }

    .status-pending {
      color: #1d4ed8;
      background: #dbeafe;
      padding: 5px 10px;
      border-radius: 8px;
    }

    .status-complete {
      color: #065f46;
      background: #d1fae5;
      padding: 5px 10px;
      border-radius: 8px;
    }

    .status-cancelled {
      color: #991b1b;
      background: #fee2e2;
      padding: 5px 10px;
      border-radius: 8px;
    }

    .manual-form {
      max-width: 500px;
      margin: 0 auto;
      background: #fff;
      padding: 25px;
      border-radius: 12px;
      box-shadow: 0 4px 6px rgba(0, 0, 0, .1);
    }

    .manual-form label {
      display: block;
      margin-top: 10px;
      font-weight: bold;
    }

    .manual-form input {
      width: 95%;
      padding: 10px;
      border: 1px solid #ccc;
      border-radius: 8px;
      margin-top: 5px;
    }

    .manual-form button {
      background: #dc2626;
      color: #fff;
      padding: 10px 16px;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      margin-top: 15px;
      width: 100%;
    }

    .manual-form button:hover {
      background: #b91c1c;
    }

    /* Add Item Modal (unchanged look; small additions) */
    .additem-modal {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .5);
      z-index: 4000;
      padding: 24px;
      overflow: auto;
    }

    .additem-modal.show {
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .additem-panel {
      background: #fff;
      width: min(980px, 95vw);
      max-height: 96vh;
      overflow: hidden;
      border-radius: 14px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, .25);
      display: flex;
      flex-direction: column;
    }

    .additem-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 12px 16px;
      border-bottom: 1px solid #eee;
    }

    .additem-title {
      font-size: 16px;
      font-weight: 800;
      margin: 0;
    }

    .additem-body {
      padding: 16px;
      overflow: auto;
    }

    .ck-close {
      background: #1f2937;
      color: #fff;
      border: none;
      border-radius: 8px;
      padding: 6px 10px;
      cursor: pointer;
    }

    .dropzone {
      border: 2px dashed #cbd5e1;
      border-radius: 12px;
      height: 170px;
      background: #fafafa;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      position: relative;
    }

    .thumbs {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      margin-top: 10px;
    }

    .thumb {
      width: 90px;
      height: 90px;
      border-radius: 8px;
      border: 1px solid #eee;
      overflow: hidden;
      position: relative;
    }

    .thumb img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .thumb .tag {
      position: absolute;
      bottom: 4px;
      left: 4px;
      background: rgba(0, 0, 0, .6);
      color: #fff;
      font-size: 11px;
      padding: 2px 6px;
      border-radius: 6px;
    }

    .thumb .remove {
      position: absolute;
      top: 4px;
      right: 4px;
      width: 20px;
      height: 20px;
      display: grid;
      place-items: center;
      border-radius: 50%;
      color: #fff;
      font-size: 14px;
      background: rgba(0, 0, 0, .55);
      cursor: pointer;
    }

    .slim {
      width: 100%;
      border-collapse: collapse;
      background: #fff;
      border-radius: 12px;
      overflow: hidden;
      margin-top: 8px;
    }

    .slim th,
    .slim td {
      padding: 8px 10px;
      border-bottom: 1px solid #f1f1f1;
      text-align: left;
    }

    .slim thead th {
      background: #fafafa;
      font-weight: bold;
    }

    .btn-chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      border: 1px solid #ddd;
      background: #fff;
      color: #333;
      border-radius: 8px;
      padding: 8px 12px;
      cursor: pointer;
    }

    .btn-chip.primary {
      background: #ee4d2d;
      color: #fff;
      border-color: #ee4d2d;
    }

    .pill {
      display: inline-block;
      border: 1px dashed #ddd;
      padding: 4px 8px;
      border-radius: 999px;
      font-size: 12px;
      margin-right: 6px;
      margin-bottom: 6px;
    }

    .grid2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }

    .previewCard {
      background: #fff;
      border: 1px solid #eee;
      border-radius: 12px;
      padding: 12px;
    }

    .product-card img.product-img {
      width: 100%;
      height: auto;
      border-radius: 12px;
      border: 1px solid #eee;
    }

    .product-card .product-name {
      margin: 10px 0 0;
      font-size: 16px;
      font-weight: 700;
    }

    .product-card .product-price {
      margin: 4px 0 0;
      font-size: 18px;
      font-weight: 800;
      color: #ee4d2d;
    }

    .pp-box {
      border: 1px solid #eee;
      border-radius: 12px;
      padding: 0;
      overflow: hidden;
      background: #fff;
    }

    .pp-header {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 12px;
      border-bottom: 1px solid #eee;
    }

    .pp-title {
      font-weight: 700;
      font-size: 16px;
      margin: 0;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .pp-carousel {
      position: relative;
      width: 100%;
      max-width: 760px;
      margin: 0 auto;
    }

    .pp-viewport {
      width: 100%;
      overflow: hidden;
      touch-action: pan-y;
    }

    .pp-track {
      display: flex;
      transition: transform .35s ease;
    }

    .pp-slide {
      min-width: 100%;
      display: grid;
      place-items: center;
      background: #f6f6f6;
      height: 320px;
    }

    .pp-slide img {
      width: 90%;
      height: 100%;
      object-fit: cover;
      object-position: center;
    }

    .pp-arrow {
      position: absolute;
      top: 50%;
      transform: translateY(-50%);
      border: none;
      background: rgba(0, 0, 0, .45);
      color: #fff;
      width: 32px;
      height: 32px;
      border-radius: 50%;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .pp-arrow.left {
      left: 8px;
    }

    .pp-arrow.right {
      right: 8px;
    }

    .pp-dots {
      display: flex;
      gap: 6px;
      justify-content: center;
      padding: 10px 0;
    }

    .pp-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #ddd;
      cursor: pointer;
    }

    .pp-dot.active {
      background: #ee4d2d;
    }

    .pp-info {
      max-width: 760px;
      margin: 0 auto;
      padding: 12px 16px;
    }

    .pp-name {
      font-size: 18px;
      font-weight: 700;
      margin: 8px 0;
    }

    .pp-price {
      font-size: 20px;
      font-weight: 800;
      color: #ee4d2d;
      margin: 0 0 8px;
    }

    .pp-desc {
      font-size: 14px;
      line-height: 1.6;
      color: #444;
      margin: 8px 0 14px;
    }

    .pp-actions {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      padding-top: 6px;
      flex-wrap: wrap;
    }

    .pp-btn {
      background: #ee4d2d;
      color: #fff;
      border: none;
      border-radius: 8px;
      padding: 10px 14px;
      font-weight: 700;
      cursor: not-allowed;
      opacity: .6;
    }

    .pp-sizes {
      max-width: 760px;
      margin: 0 auto;
      padding: 0 16px 16px;
    }

    .pp-sizehead {
      font-size: 13px;
      color: #555;
      margin: 0 0 6px;
    }

    .pp-sizelist {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 8px;
    }

    .pp-sizeitem {
      display: flex;
      align-items: center;
      justify-content: space-between;
      border: 1px solid #eaeaea;
      border-radius: 10px;
      padding: 8px 10px;
      font-size: 13px;
    }

    .pp-sizeleft {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .pp-sizelabel {
      font-weight: 700;
    }

    .pp-sizestock {
      font-size: 12px;
      color: #666;
    }

    .note {
      color: #6b7280;
      font-size: 12px;
      margin-top: 6px;
    }
  </style>
</head>

<body>
  <!-- ===== Header ===== -->
  <header>
    <img src="logo.png" alt="School Logo">
    <div class="header-text">
      <h1 class="office">RESOURCE GENERATION OFFICE</h1>
      <p class="subtitle">Batangas State University - Lipa Campus</p>
      <h3 class="highlight">University Shop System</h3>
    </div>
  </header>

  <!-- ===== Sidebar ===== -->
  <div id="sidebar" class="sidebar">
    <div class="sidebar-content">
      <img src="admin.jpeg" alt="Profile Picture" class="profile-pic" id="sidebarProfilePic">
      <p class="profile-name" id="sidebarName">Rizal, Jose</p>
      <p class="course" id="sidebarCourse">Admin 1</p>
      <button class="sidebar-btn" id="homeBut">Home</button>
      <button class="sidebar-btn" id="ordersBtn">Orders</button>
      <button class="sidebar-btn" id="stocksBtn">Stocks</button>
      <button class="sidebar-btn" id="manualAddBtn">Manual Add</button>
      <button class="sidebar-btn">Notifications</button>
    </div>
    <button class="signout-btn" id="signoutBtn">Sign Out</button>
  </div>
  <div id="overlay" class="overlay"></div>

  <!-- ===== Top Nav ===== -->
  <nav class="category-r">
    <div class="left-controls">
      <button id="sidebarToggle" class="menu-btn">☰</button>
    </div>
  </nav>

  <!-- ===== Dashboard ===== -->
  <section class="home-section" id="dashboard" style="display:block;">
    <h1 class="dashboard-title">Dashboard Overview</h1>
    <div class="stats">
      <div class="stat-card">
        <h3>Today</h3>
        <h2>12</h2>
        <p>Orders</p>
      </div>
      <div class="stat-card">
        <h3>This Week</h3>
        <h2>58</h2>
        <p>Orders</p>
      </div>
      <div class="stat-card">
        <h3>This Month</h3>
        <h2>233</h2>
        <p>Orders</p>
      </div>
    </div>
    <div class="chart-container"><canvas id="ordersChart"></canvas></div>
  </section>

  <!-- ===== Orders ===== -->
  <section class="orders-section" id="ordersPage">
    <h1 class="dashboard-title">Orders</h1>
    <div class="search-bar">
      <input type="text" id="searchInput" placeholder="Search orders...">
      <button id="searchBtn">Search</button>
    </div>
    <table>
      <thead>
        <tr>
          <th>ORDER ID</th>
          <th>ORDER NUMBER</th>
          <th>STATUS</th>
          <th>ITEM</th>
          <th>CUSTOMER NAME</th>
          <th>PAYMENT METHOD</th>
          <th>TRACKING CODE</th>
          <th>ACTION</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>59217</td>
          <td>59217342</td>
          <td><span class="status-pending">Pending</span></td>
          <td>1</td>
          <td>Jhon Irvin Alido</td>
          <td>GCASH</td>
          <td>940010010936113003113</td>
          <td><button class="view-btn">View</button><button class="done-btn">Done</button></td>
        </tr>
      </tbody>
    </table>
  </section>

  <!-- ===== Stocks ===== -->
  <section class="stocks-section" id="stocksPage">
    <h1 class="dashboard-title">Stocks</h1>

    <div class="search-bar">
      <button id="addItemBtn">Add Item</button>
    </div>

    <?php
    // flash notices after updates/deletes
    if (!empty($update_item_success))
      echo '<p style="color:green;margin:10px 0;">' . htmlspecialchars($update_item_success) . '</p>';
    if (!empty($update_item_error))
      echo '<p style="color:red;margin:10px 0;">' . htmlspecialchars($update_item_error) . '</p>';
    if (!empty($delete_item_success))
      echo '<p style="color:green;margin:10px 0;">' . htmlspecialchars($delete_item_success) . '</p>';
    if (!empty($delete_item_error))
      echo '<p style="color:red;margin:10px 0;">' . htmlspecialchars($delete_item_error) . '</p>';
    if (!empty($add_item_success))
      echo '<p style="color:green;margin:10px 0;">' . htmlspecialchars($add_item_success) . '</p>';
    if (!empty($add_item_error))
      echo '<p style="color:red;margin:10px 0;">' . htmlspecialchars($add_item_error) . '</p>';
    ?>

    <table>
      <thead>
        <tr>
          <th>ITEM ID</th>
          <th>CATEGORY</th>
          <th>ITEM NAME</th>
          <th>TOTAL STOCK</th>
          <th>PRICE</th>
          <th>ACTION</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $items = $conn->query("
        SELECT
          i.item_id, i.category, i.item_name,
          MIN(s.price) AS min_price, MAX(s.price) AS max_price,
          COUNT(s.size_id) AS size_count,
          (SELECT image_data FROM item_images im WHERE im.item_id = i.item_id ORDER BY im.image_id ASC LIMIT 1) AS img_blob
        FROM items i
        LEFT JOIN item_sizes s ON s.item_id = i.item_id
        GROUP BY i.item_id, i.category, i.item_name
        ORDER BY i.item_id DESC
      ");
        if ($items):
          while ($row = $items->fetch_assoc()):
            $hasPrices = !is_null($row['min_price']);
            $price_display = $hasPrices
              ? ((float) $row['min_price'] == (float) $row['max_price']
                ? number_format((float) $row['min_price'], 2)
                : number_format((float) $row['min_price'], 2) . " – " . number_format((float) $row['max_price'], 2))
              : '0.00';
            $thumb = '';
            if (!is_null($row['img_blob'])) {
              $thumb = 'data:image/jpeg;base64,' . base64_encode($row['img_blob']);
            }
            ?>
            <tr>
              <td><?= (int) $row['item_id'] ?></td>
              <td><?= htmlspecialchars($row['category']) ?></td>
              <td>
                <div style="display:flex;align-items:center;gap:10px;">
                  <div
                    style="width:48px;height:48px;border-radius:6px;border:1px solid #eee;display:flex;align-items:center;justify-content:center;overflow:hidden;background:#fafafa">
                    <?php if ($thumb): ?><img src="<?= $thumb ?>" alt=""
                        style="width:100%;height:100%;object-fit:cover;"><?php else: ?><span
                        style="font-size:20px;color:#9ca3af;">＋</span><?php endif; ?>
                  </div>
                  <span><?= htmlspecialchars($row['item_name']) ?></span>
                </div>
              </td>
              <td><?= (int) ($row['size_count'] ?? 0) ?></td>
              <td>₱<?= $price_display ?></td>
              <td>
                <button class="view-btn btn-edit-item" data-id="<?= (int) $row['item_id'] ?>">Edit</button>
                <button class="delete-btn btn-del-item" data-id="<?= (int) $row['item_id'] ?>">Delete</button>
              </td>
            </tr>
          <?php endwhile; endif; ?>
      </tbody>
    </table>
  </section>

  <!-- ===== Manual Add Student (unchanged) ===== -->
  <section class="manual-section" id="manualAddSection">
    <h1 class="dashboard-title">Add Student</h1>
    <div class="manual-form">
      <?php if ($error): ?>
        <p style="color:red;text-align:center;"><?= htmlspecialchars($error) ?></p><?php endif; ?>
      <?php if ($success): ?>
        <p style="color:green;text-align:center;"><?= htmlspecialchars($success) ?></p><?php endif; ?>
      <form method="POST" enctype="multipart/form-data">
        <label>Full Name</label><input type="text" name="fullname" required>
        <label>SR-Code</label><input type="text" name="sr_code" required>
        <label>Gsuite Email</label><input type="email" name="gsuite_email" required>
        <label>Course</label><input type="text" name="course" required>
        <label>Profile Picture</label><input type="file" name="profile_pic" accept="image/*">
        <label>Password</label><input type="password" name="password" required>
        <button type="submit" name="add_student">Add Student</button>
      </form>
    </div>
  </section>

  <!-- ===== Add/Edit Item Modal (same modal; now supports edit) ===== -->
  <div id="addItemModalFE" class="additem-modal" aria-hidden="true">
    <div class="additem-panel" role="dialog" aria-modal="true" aria-labelledby="addItemTitleFE">
      <div class="additem-header">
        <h3 id="addItemTitleFE" class="additem-title">Add Item</h3>
        <button id="addItemCloseFE" class="ck-close" aria-label="Close">×</button>
      </div>

      <div class="additem-body">
        <!-- IMPORTANT: now a real form -->
        <form id="addItemFormFE" method="POST" enctype="multipart/form-data">
          <!-- Mode toggles: Add vs Update (JS will switch which hidden field is present) -->
          <input type="hidden" name="add_item" id="modeAddField" value="1">
          <input type="hidden" name="update_item" id="modeUpdateField" value="1" disabled style="display:none;">
          <input type="hidden" name="edit_item_id" id="editItemIdFE" value="0">
          <input type="hidden" name="sizes_json" id="sizesJsonFE" value="[]">

          <div class="grid2">
            <div>
              <label>Category</label>
              <select id="feCategory" name="category"
                style="width:100%;padding:10px;border:1px solid #ccc;border-radius:8px">
                <option value="uniforms">Uniforms</option>
                <option value="textile">Textile</option>
                <option value="pants">Pants</option>
                <option value="accessories">Accessories</option>
                <option value="skirts">Skirts</option>
              </select>
            </div>
            <div>
              <label>Item Name</label>
              <input id="feName" name="item_name" placeholder="e.g., PE Uniform" required
                style="width:100%;padding:10px;border:1px solid #ccc;border-radius:8px">
            </div>

            <div style="grid-column:1/-1">
              <label>Description</label>
              <input id="feDesc" name="description" placeholder="Short description"
                style="width:100%;padding:10px;border:1px solid #ccc;border-radius:8px">
            </div>

            <div>
              <label>Images (4–5 recommended)</label>
              <div id="imgDropFE" class="dropzone">
                <span style="font-size:44px;color:#9ca3af;user-select:none">＋</span>
                <input id="feImages" name="images[]" type="file" accept="image/*" multiple
                  style="position:absolute;inset:0;opacity:0;cursor:pointer">
              </div>
              <div class="note" id="editImagesNote" style="display:none">Editing: leave this empty to keep current
                images. Uploading any images will <b>replace</b> all existing images.</div>
              <div class="thumbs" id="imgPreviewFE"></div>
              <div class="thumbs" id="existingPreviewFE" style="margin-top:6px;"></div>
            </div>

            <div>
              <label>Base Price (optional)</label>
              <input id="feBasePrice" name="base_price" type="number" min="0" step="0.01"
                placeholder="Shown if no size prices"
                style="width:100%;padding:10px;border:1px solid #ccc;border-radius:8px">
              <div style="color:#6b7280;font-size:12px;margin-top:6px">
                If sizes have prices, a min–max range shows on the card.
              </div>
            </div>
          </div>

          <div style="height:1px;background:#eee;margin:14px 0"></div>

          <label>Sizes & Prices</label>
          <table class="slim" id="sizesTableFE">
            <thead>
              <tr>
                <th>Size</th>
                <th>Price (₱)</th>
                <th>Stock (opt)</th>
                <th>Notes (opt)</th>
                <th></th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
          <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px">
            <button type="button" id="addSizeRowFE" class="btn-chip">Add Size</button>
            <button type="button" id="addPresetFE" class="btn-chip">Preset: XS–XL</button>
          </div>

          <div style="height:1px;background:#eee;margin:14px 0"></div>

          <div class="grid2">
            <div class="previewCard">
              <div style="font-weight:bold;margin-bottom:8px">Card Preview</div>
              <div class="product-card" style="width:100%;height:auto">
                <img id="prevImgFE" class="product-img" src="https://via.placeholder.com/600x800?text=Thumbnail" alt="">
                <h3 id="prevNameFE" class="product-name">Item Name</h3>
                <p id="prevPriceFE" class="product-price">₱0.00</p>
              </div>
            </div>

            <div class="previewCard">
              <div style="font-weight:bold;margin-bottom:8px">Student Product Modal Preview</div>
              <div class="pp-box">
                <div class="pp-header">
                  <h4 id="ppTitle" class="pp-title">Product</h4>
                </div>
                <div class="pp-carousel">
                  <button class="pp-arrow left" id="ppPrev" aria-label="Previous">‹</button>
                  <div class="pp-viewport" id="ppViewport">
                    <div class="pp-track" id="ppTrack"></div>
                  </div>
                  <button class="pp-arrow right" id="ppNext" aria-label="Next">›</button>
                  <div class="pp-dots" id="ppDots"></div>
                </div>
                <div class="pp-info">
                  <div id="ppName" class="pp-name">Item Name</div>
                  <div id="ppPrice" class="pp-price">₱0.00</div>
                  <p id="ppDesc" class="pp-desc">This item is presented with a formal description.</p>
                  <div class="pp-actions">
                    <button class="pp-btn" title="Preview only">Buy now</button>
                  </div>
                </div>
                <div class="pp-sizes">
                  <div class="pp-sizehead">Available Options</div>
                  <div class="pp-sizelist" id="ppSizeList"></div>
                </div>
              </div>
            </div>
          </div>

          <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
            <button type="button" id="closeFE" class="btn-chip">Close</button>
            <button type="submit" id="doneFE" class="btn-chip primary">Save Item</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ===== Scripts ===== -->
  <script>
    // Sidebar / navigation
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('overlay');
    const dashboard = document.getElementById('dashboard');
    const ordersPage = document.getElementById('ordersPage');
    const stocksPage = document.getElementById('stocksPage');
    const manualAddSection = document.getElementById('manualAddSection');

    document.getElementById('sidebarToggle').onclick = () => {
      sidebar.classList.toggle('open'); overlay.classList.toggle('show');
    };
    overlay.onclick = () => { sidebar.classList.remove('open'); overlay.classList.remove('show'); };
    function show(s) {
      [dashboard, ordersPage, stocksPage, manualAddSection].forEach(el => el.style.display = 'none');
      s.style.display = 'block';
      sidebar.classList.remove('open'); overlay.classList.remove('show');
    }
    document.getElementById('homeBut').onclick = () => show(dashboard);
    document.getElementById('ordersBtn').onclick = () => show(ordersPage);
    document.getElementById('stocksBtn').onclick = () => show(stocksPage);
    document.getElementById('manualAddBtn').onclick = () => show(manualAddSection);

    // Orders search
    const searchInput = document.getElementById("searchInput");
    const searchBtn = document.getElementById("searchBtn");
    const tableRows = document.querySelectorAll("#ordersPage tbody tr");
    searchBtn?.addEventListener("click", () => {
      const q = (searchInput.value || '').toLowerCase();
      tableRows.forEach(row => row.style.display = row.textContent.toLowerCase().includes(q) ? "" : "none");
    });

    // Chart
    const ctx = document.getElementById('ordersChart')?.getContext('2d');
    if (ctx) {
      new Chart(ctx, {
        type: 'bar',
        data: { labels: ['Today', 'This Week', 'This Month'], datasets: [{ label: 'Orders', data: [12, 58, 233], borderRadius: 8 }] },
        options: { responsive: true, plugins: { legend: { display: false }, title: { display: true, text: 'Order Summary', font: { size: 18 } } }, scales: { y: { beginAtZero: true } } }
      });
    }

    // Keep sections after POSTs
    <?php if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_student'])): ?>
      show(manualAddSection);
        <?php if (!empty($success)): ?> alert("<?= addslashes($success); ?>"); <?php endif; ?>
        <?php if (!empty($error)): ?> alert("<?= addslashes($error); ?>"); <?php endif; ?>
    <?php endif; ?>

    <?php if ($_SERVER["REQUEST_METHOD"] == "POST" && (isset($_POST['add_item']) || isset($_POST['update_item']) || isset($_POST['delete_item']))): ?>
      show(stocksPage);
        <?php if (!empty($add_item_success)): ?> alert("<?= addslashes($add_item_success); ?>"); <?php endif; ?>
        <?php if (!empty($add_item_error)): ?> alert("<?= addslashes($add_item_error); ?>"); <?php endif; ?>
        <?php if (!empty($update_item_success)): ?> alert("<?= addslashes($update_item_success); ?>"); <?php endif; ?>
        <?php if (!empty($update_item_error)): ?> alert("<?= addslashes($update_item_error); ?>"); <?php endif; ?>
        <?php if (!empty($delete_item_success)): ?> alert("<?= addslashes($delete_item_success); ?>"); <?php endif; ?>
        <?php if (!empty($delete_item_error)): ?> alert("<?= addslashes($delete_item_error); ?>"); <?php endif; ?>
    <?php endif; ?>

      // ===== Add/Edit Item Modal (wired to backend; stays a modal) =====
      (function () {
        const modal = document.getElementById('addItemModalFE');
        const openBtn = document.getElementById('addItemBtn');
        const closeX = document.getElementById('addItemCloseFE');
        const closeBtn = document.getElementById('closeFE');
        const form = document.getElementById('addItemFormFE');

        const modeAddField = document.getElementById('modeAddField');
        const modeUpdateField = document.getElementById('modeUpdateField');
        const editItemIdFE = document.getElementById('editItemIdFE');
        const addItemTitleFE = document.getElementById('addItemTitleFE');
        const doneFE = document.getElementById('doneFE');

        const feCategory = document.getElementById('feCategory');
        const feName = document.getElementById('feName');
        const feDesc = document.getElementById('feDesc');
        const feBasePrice = document.getElementById('feBasePrice');
        const feImages = document.getElementById('feImages');
        const imgDropFE = document.getElementById('imgDropFE');
        const imgPreviewFE = document.getElementById('imgPreviewFE');
        const existingPreviewFE = document.getElementById('existingPreviewFE');
        const editImagesNote = document.getElementById('editImagesNote');

        const sizesTableFE = document.querySelector('#sizesTableFE tbody');
        const addSizeRowFE = document.getElementById('addSizeRowFE');
        const addPresetFE = document.getElementById('addPresetFE');

        const prevImg = document.getElementById('prevImgFE');
        const prevName = document.getElementById('prevNameFE');
        const prevPrice = document.getElementById('prevPriceFE');

        const ppTitle = document.getElementById('ppTitle');
        const ppName = document.getElementById('ppName');
        const ppPrice = document.getElementById('ppPrice');
        const ppDesc = document.getElementById('ppDesc');
        const ppTrack = document.getElementById('ppTrack');
        const ppDots = document.getElementById('ppDots');
        const ppPrev = document.getElementById('ppPrev');
        const ppNext = document.getElementById('ppNext');
        const ppSizeList = document.getElementById('ppSizeList');

        const sizesJsonField = document.getElementById('sizesJsonFE');

        let imgs = []; // {src}
        let ppIndex = 0;

        function switchToAddMode() {
          addItemTitleFE.textContent = 'Add Item';
          doneFE.textContent = 'Save Item';
          editItemIdFE.value = '0';
          modeAddField.disabled = false; modeAddField.name = 'add_item';
          modeUpdateField.disabled = true; modeUpdateField.name = '';
          editImagesNote.style.display = 'none';
          existingPreviewFE.innerHTML = '';
        }
        function switchToEditMode(itemId) {
          addItemTitleFE.textContent = 'Edit Item';
          doneFE.textContent = 'Save Changes';
          editItemIdFE.value = String(itemId);
          modeAddField.disabled = true; modeAddField.name = '';
          modeUpdateField.disabled = false; modeUpdateField.name = 'update_item';
          editImagesNote.style.display = 'block';
        }

        function openModal() { modal.classList.add('show'); modal.setAttribute('aria-hidden', 'false'); }
        function closeModal() { modal.classList.remove('show'); modal.setAttribute('aria-hidden', 'true'); }

        function clearForm() {
          feCategory.value = 'uniforms';
          feName.value = '';
          feDesc.value = '';
          feBasePrice.value = '';
          imgs = [];
          imgPreviewFE.innerHTML = '';
          existingPreviewFE.innerHTML = '';
          sizesTableFE.innerHTML = '';
          addSizeRow(); // one row to start
          updateAllPreviews();
        }

        openBtn?.addEventListener('click', () => { switchToAddMode(); clearForm(); openModal(); });
        closeX?.addEventListener('click', closeModal);
        closeBtn?.addEventListener('click', closeModal);
        modal?.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

        imgDropFE?.addEventListener('click', () => feImages.click());
        feImages?.addEventListener('change', () => {
          imgs = [];
          imgPreviewFE.innerHTML = '';
          [...feImages.files].forEach((f, i) => {
            const rd = new FileReader();
            rd.onload = e => { imgs.push({ src: e.target.result }); renderThumbs(); updateAllPreviews(); };
            rd.readAsDataURL(f);
          });
        });

        function renderThumbs() {
          imgPreviewFE.innerHTML = '';
          imgs.forEach((o, i) => {
            const d = document.createElement('div'); d.className = 'thumb';
            const im = document.createElement('img'); im.src = o.src;
            const tg = document.createElement('span'); tg.className = 'tag'; tg.textContent = i === 0 ? 'Cover' : '#' + (i + 1);
            const rm = document.createElement('div'); rm.className = 'remove'; rm.textContent = '×';
            rm.onclick = () => { imgs.splice(i, 1); renderThumbs(); updateAllPreviews(); };
            d.draggable = true;
            d.addEventListener('dragstart', ev => { ev.dataTransfer.setData('text/plain', i.toString()); });
            d.addEventListener('dragover', ev => ev.preventDefault());
            d.addEventListener('drop', ev => { ev.preventDefault(); const from = parseInt(ev.dataTransfer.getData('text/plain'), 10); const to = i; if (from === to) return; const [m] = imgs.splice(from, 1); imgs.splice(to, 0, m); renderThumbs(); updateAllPreviews(); });
            d.appendChild(im); d.appendChild(tg); d.appendChild(rm); imgPreviewFE.appendChild(d);
          });
        }

        function addSizeRow(val = { label: '', price: '', stock: '', notes: '' }) {
          const tr = document.createElement('tr');
          tr.innerHTML = `
          <td><input class="sz-label" placeholder="e.g., S" style="width:110px;padding:8px;border:1px solid #ccc;border-radius:6px" value="${val.label}"></td>
          <td><input class="sz-price" type="number" step="0.01" min="0" style="width:120px;padding:8px;border:1px solid #ccc;border-radius:6px" value="${val.price}"></td>
          <td><input class="sz-stock" type="number" min="0" placeholder="optional" style="width:110px;padding:8px;border:1px solid #ccc;border-radius:6px" value="${val.stock ?? ''}"></td>
          <td><input class="sz-notes" placeholder="optional" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:6px" value="${val.notes ?? ''}"></td>
          <td><button type="button" class="delete-btn rm" style="padding:6px 10px">Remove</button></td>
        `;
          tr.querySelector('.rm').onclick = () => { tr.remove(); updateAllPreviews(); };
          ['input', 'change'].forEach(ev => tr.addEventListener(ev, updateAllPreviews));
          sizesTableFE.appendChild(tr);
        }
        addSizeRowFE?.addEventListener('click', () => addSizeRow());
        addPresetFE?.addEventListener('click', () => {
          sizesTableFE.innerHTML = '';
          ['XS', 'S', 'M', 'L', 'XL'].forEach(s => addSizeRow({ label: s, price: '0', stock: '', notes: '' }));
          updateAllPreviews();
        });

        [feCategory, feName, feDesc, feBasePrice].forEach(el => el.addEventListener('input', updateAllPreviews));

        function collectSizes() {
          const rows = [...sizesTableFE.querySelectorAll('tr')];
          return rows.map(r => {
            const label = r.querySelector('.sz-label').value.trim();
            const price = parseFloat(r.querySelector('.sz-price').value || '0');
            const stock = r.querySelector('.sz-stock').value.trim();
            const notes = r.querySelector('.sz-notes').value.trim();
            if (!label) return null;
            return { label, price: isNaN(price) ? 0 : price, stock: (stock === '' ? null : parseInt(stock, 10) || 0), notes };
          }).filter(Boolean);
        }
        function formatPrice(n) { try { return '₱' + (Number(n).toFixed(2)); } catch { return '₱0.00'; } }
        function deriveDisplayPrice() {
          const s = collectSizes();
          let basePrice = feBasePrice.value ? Number(feBasePrice.value) : null;
          if (s.length) {
            const prices = s.map(x => x.price).filter(x => !isNaN(x));
            if (prices.length) {
              const min = Math.min(...prices), max = Math.max(...prices);
              return (min === max) ? formatPrice(min) : (formatPrice(min) + ' – ' + formatPrice(max));
            }
          }
          return basePrice !== null ? formatPrice(basePrice) : '₱0.00';
        }

        function updateCardPreview() {
          prevName.textContent = feName.value.trim() || 'Item Name';
          prevPrice.textContent = deriveDisplayPrice();
          prevImg.src = imgs[0]?.src || document.querySelector('#existingPreviewFE img')?.src || 'https://via.placeholder.com/600x800?text=Thumbnail';
        }

        function buildPanelCarousel() {
          ppTrack.innerHTML = '';
          ppDots.innerHTML = '';
          ppIndex = 0;
          const extImgs = [...existingPreviewFE.querySelectorAll('img')].map(im => ({ src: im.src }));
          const list = imgs.length ? imgs : (extImgs.length ? extImgs : [{ src: 'https://via.placeholder.com/900x600?text=No+Image' }]);
          list.forEach((o, i) => {
            const slide = document.createElement('div');
            slide.className = 'pp-slide';
            const img = document.createElement('img');
            img.src = o.src;
            img.alt = (feName.value || 'Product') + ' ' + (i + 1);
            slide.appendChild(img);
            ppTrack.appendChild(slide);

            const dot = document.createElement('span');
            dot.className = 'pp-dot' + (i === 0 ? ' active' : '');
            dot.dataset.index = i;
            dot.addEventListener('click', () => { ppIndex = i; updateCarousel(false); });
            ppDots.appendChild(dot);
          });
          updateCarousel(true);
        }
        function updateCarousel(jump) {
          const pct = -ppIndex * 100;
          ppTrack.style.transition = jump ? 'none' : 'transform .35s ease';
          ppTrack.style.transform = `translateX(${pct}%)`;
          [...ppDots.children].forEach((d, i) => d.classList.toggle('active', i === ppIndex));
        }
        ppPrev?.addEventListener('click', () => {
          const n = (imgs.length || existingPreviewFE.querySelectorAll('img').length || 1);
          ppIndex = (ppIndex > 0 ? ppIndex - 1 : n - 1); updateCarousel(false);
        });
        ppNext?.addEventListener('click', () => {
          const n = (imgs.length || existingPreviewFE.querySelectorAll('img').length || 1);
          ppIndex = (ppIndex < n - 1 ? ppIndex + 1 : 0); updateCarousel(false);
        });

        function buildPanelSizes() {
          ppSizeList.innerHTML = '';
          const s = collectSizes();
          if (!s.length) {
            const empty = document.createElement('div');
            empty.textContent = 'No sizes configured';
            empty.style.color = '#6b7280';
            empty.style.fontSize = '12px';
            ppSizeList.appendChild(empty);
            return;
          }
          s.forEach(opt => {
            const row = document.createElement('div');
            row.className = 'pp-sizeitem';
            const left = document.createElement('div'); left.className = 'pp-sizeleft';
            const radio = document.createElement('input'); radio.type = 'radio'; radio.name = 'ppSize'; radio.disabled = (opt.stock !== null && opt.stock <= 0); radio.style.transform = 'scale(1.05)';
            const lbl = document.createElement('div'); lbl.className = 'pp-sizelabel'; lbl.textContent = opt.label;
            left.appendChild(radio); left.appendChild(lbl);
            const stk = document.createElement('div'); stk.className = 'pp-sizestock';
            const pcs = (opt.stock === null ? '—' : Math.max(0, opt.stock)) + (opt.stock === null ? '' : ' pcs');
            const price = ' • ' + formatPrice(opt.price);
            stk.textContent = pcs + price;
            row.appendChild(left); row.appendChild(stk);
            ppSizeList.appendChild(row);
          });
        }

        function updatePanelPreview() {
          ppTitle.textContent = feName.value.trim() || 'Product';
          ppName.textContent = feName.value.trim() || 'Item Name';
          ppPrice.textContent = deriveDisplayPrice();
          ppDesc.textContent = (feDesc.value && feDesc.value.trim()) ? feDesc.value.trim() : 'This item is presented with a formal description.';
          buildPanelCarousel();
          buildPanelSizes();
        }

        function updateAllPreviews() { updateCardPreview(); updatePanelPreview(); }

        // init
        addSizeRow();
        updateAllPreviews();

        // Serialize sizes to hidden json before submit
        form.addEventListener('submit', () => {
          const payload = collectSizes();
          sizesJsonField.value = JSON.stringify(payload);
        });

        // ========== EDIT & DELETE HOOKS ==========
        // Edit
        document.querySelectorAll('.btn-edit-item').forEach(btn => {
          btn.addEventListener('click', async () => {
            const id = btn.dataset.id;
            try {
              const res = await fetch(`<?php echo basename(__FILE__); ?>?action=get_item&id=${encodeURIComponent(id)}`);
              const data = await res.json();
              if (!data.ok) { alert('Failed to load item.'); return; }

              switchToEditMode(id);
              clearForm();

              // Fill fields
              feCategory.value = data.item.category || 'uniforms';
              feName.value = data.item.item_name || '';
              feDesc.value = data.item.description || '';
              feBasePrice.value = (data.item.base_price !== null && data.item.base_price !== undefined) ? data.item.base_price : '';

              // Existing images preview (for reference)
              existingPreviewFE.innerHTML = '';
              if (Array.isArray(data.images) && data.images.length) {
                data.images.forEach((im, i) => {
                  const d = document.createElement('div'); d.className = 'thumb';
                  const ig = document.createElement('img'); ig.src = im.data_url;
                  const tg = document.createElement('span'); tg.className = 'tag'; tg.textContent = i === 0 ? 'Current Cover' : 'Current #' + (i + 1);
                  d.appendChild(ig); d.appendChild(tg); existingPreviewFE.appendChild(d);
                });
              }

              // Sizes
              sizesTableFE.innerHTML = '';
              if (Array.isArray(data.sizes) && data.sizes.length) {
                data.sizes.forEach(s => addSizeRow({ label: s.label || '', price: s.price || 0, stock: (s.stock === null ? '' : s.stock), notes: s.notes || '' }));
              } else {
                addSizeRow();
              }

              // Reset images selection in case previous edit had files
              feImages.value = '';
              imgs = [];
              imgPreviewFE.innerHTML = '';

              updateAllPreviews();
              openModal();
            } catch (e) {
              alert('Error: ' + e.message);
            }
          });
        });

        // Delete
        document.querySelectorAll('.btn-del-item').forEach(btn => {
          btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            if (!confirm('Delete this item? This cannot be undone.')) return;
            // Create and submit a form (POST) to delete
            const f = document.createElement('form');
            f.method = 'POST';
            f.action = '';
            const a = document.createElement('input'); a.type = 'hidden'; a.name = 'delete_item'; a.value = '1';
            const b = document.createElement('input'); b.type = 'hidden'; b.name = 'item_id'; b.value = id;
            f.appendChild(a); f.appendChild(b);
            document.body.appendChild(f);
            f.submit();
          });
        });

      })();
  </script>
</body>

</html>
<?php $conn->close(); ?>