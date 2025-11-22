<?php

session_start();

// If not logged in, go back to login screen
if (!isset($_SESSION['admin_login_id'])) {
  header("Location: index.php");
  exit();
}
include 'db.php';

// ===== Fetch logged-in admin data =====
$admin_login_id = $_SESSION['admin_login_id'];

$adminInfo = $conn->prepare("
    SELECT 
        a.admin_name,
        a.admin_position,
        a.profile_p
    FROM admin a
    WHERE a.login_id = ?
");
$adminInfo->bind_param("i", $admin_login_id);
$adminInfo->execute();
$adminData = $adminInfo->get_result()->fetch_assoc();
$adminInfo->close();

// Default fallback if something missing
$sidebar_name = $adminData['admin_name'] ?? "Unknown Admin";
$sidebar_position = $adminData['admin_position'] ?? "Administrator";
$sidebar_profile = $adminData['profile_p'] ?? null;

/* =======================================================
   LOW STOCKS QUERY FOR POPUP (stock <= 5)
   ======================================================= */

$lowStocks = [];
$lowRes = $conn->query("
    SELECT 
        i.category,
        i.item_name,
        s.label,
        s.stock
    FROM items i
    JOIN item_sizes s ON s.item_id = i.item_id
    WHERE s.stock IS NOT NULL AND s.stock <= 5
");
if ($lowRes) {
  while ($row = $lowRes->fetch_assoc()) {
    $lowStocks[] = [
      'category' => $row['category'],
      'item_name' => $row['item_name'],
      'label' => $row['label'],
      'stock' => (int) $row['stock']
    ];
  }
}

/* =======================================================
   ADD ADMIN ACCOUNT
   ======================================================= */

$add_admin_success = '';
$add_admin_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_admin'])) {

  $admin_name = trim($_POST['admin_name'] ?? '');
  $admin_position = trim($_POST['admin_position'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $password = trim($_POST['password'] ?? '');

  if (!$admin_name || !$admin_position || !$email || !$password) {
    $add_admin_error = "All fields are required.";
  } else {

    // Handle profile picture
    $profile_pic_data = null;
    if (isset($_FILES['profile_p']) && $_FILES['profile_p']['error'] === UPLOAD_ERR_OK) {
      $profile_pic_data = file_get_contents($_FILES['profile_p']['tmp_name']);
    }

    // Check duplicate email in student_login
    $check = $conn->prepare("SELECT 1 FROM student_login WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    $check_res = $check->get_result();

    if ($check_res->num_rows > 0) {
      $add_admin_error = "This email is already registered.";
    } else {

      // Step 1: Insert login account
      $hashed = password_hash($password, PASSWORD_DEFAULT);

      $stmt_login = $conn->prepare("
                   INSERT INTO student_login (email, password) 
                   VALUES (?, ?)
               ");
      if (!$stmt_login) {
        $add_admin_error = "Login prepare failed: " . $conn->error;
      } else {
        $stmt_login->bind_param("ss", $email, $hashed);

        if ($stmt_login->execute()) {

          $login_id = $stmt_login->insert_id;
          $stmt_login->close();

          // Step 2: Insert admin profile
          $stmt_admin = $conn->prepare("
                           INSERT INTO admin 
                           (login_id, admin_name, admin_position, profile_p)
                           VALUES (?, ?, ?, ?)
                       ");

          if (!$stmt_admin) {
            $add_admin_error = "Admin prepare failed: " . $conn->error;

          } else {

            $null = NULL;
            $stmt_admin->bind_param(
              "issb",
              $login_id,
              $admin_name,
              $admin_position,
              $null
            );

            if ($profile_pic_data !== null) {
              $stmt_admin->send_long_data(3, $profile_pic_data);
            }

            if ($stmt_admin->execute()) {
              $add_admin_success = "Admin successfully added!";
            } else {
              $add_admin_error = "Admin insert failed: " . $stmt_admin->error;
            }

            $stmt_admin->close();
          }

        } else {
          $add_admin_error = "Login insert failed: " . $stmt_login->error;
        }
      }
    }
  }
}

/* Student sends a message (sender='student') */
if (
  $_SERVER['REQUEST_METHOD'] === 'POST'
  && isset($_POST['action']) && $_POST['action'] === 'chat_send_student'
) {
  header('Content-Type: application/json');
  $sid = isset($_POST['student_id']) ? (int) $_POST['student_id'] : 0;
  $msg = trim($_POST['message'] ?? '');
  if ($sid <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Missing student_id']);
    exit;
  }
  if ($msg === '') {
    echo json_encode(['ok' => false, 'msg' => 'Message cannot be empty.']);
    exit;
  }
  try {
    $stmt = $conn->prepare("INSERT INTO chat_messages (student_id, sender, message) VALUES (?, 'student', ?)");
    $stmt->bind_param('is', $sid, $msg);
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();
    echo json_encode(['ok' => true, 'message' => ['id' => $newId, 'sender' => 'student', 'message' => $msg]]);
  } catch (Throwable $e) {
    error_log('chat_send_student ERR: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Failed to send.']);
  }
  exit;
}

/* Student fetches conversation (both sides), same as admin but by self */
if (isset($_GET['action']) && $_GET['action'] === 'chat_fetch_student') {
  header('Content-Type: application/json');
  $sid = isset($_GET['student_id']) ? (int) $_GET['student_id'] : 0;
  $since_id = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;
  if ($sid <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Missing student_id']);
    exit;
  }
  try {
    if ($since_id > 0) {
      $stmt = $conn->prepare("SELECT id, sender, message, created_at
                              FROM chat_messages
                              WHERE student_id = ? AND id > ?
                              ORDER BY id ASC
                              LIMIT 500");
      $stmt->bind_param('ii', $sid, $since_id);
    } else {
      $stmt = $conn->prepare("SELECT id, sender, message, created_at
                              FROM chat_messages
                              WHERE student_id = ?
                              ORDER BY id ASC
                              LIMIT 500");
      $stmt->bind_param('i', $sid);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc())
      $rows[] = $r;
    $stmt->close();
    echo json_encode(['ok' => true, 'messages' => $rows]);
  } catch (Throwable $e) {
    error_log('chat_fetch_student ERR: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Failed to fetch.']);
  }
  exit;
}

try {
  @$conn->query("CREATE TABLE IF NOT EXISTS chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    sender ENUM('student','admin') NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (student_id),
    INDEX (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
  error_log('CHAT TABLE ENSURE (admin): ' . $e->getMessage());
}

/* Threads list: latest per student, with unread count for admin */
if (isset($_GET['action']) && $_GET['action'] === 'chat_threads') {
  header('Content-Type: application/json');
  try {
    // Latest message per student
    $sql = "
      SELECT cm.student_id,
             s.fullname,
             MAX(cm.id) AS last_id
      FROM chat_messages cm
      LEFT JOIN students s ON s.id = cm.student_id
      GROUP BY cm.student_id, s.fullname
      ORDER BY last_id DESC
      LIMIT 500
    ";
    $rs = $conn->query($sql);
    $threads = [];
    $lastIds = [];
    while ($row = $rs->fetch_assoc()) {
      $lastIds[(int) $row['student_id']] = (int) $row['last_id'];
      $threads[] = [
        'student_id' => (int) $row['student_id'],
        'fullname' => $row['fullname'] ?: ('Student #' . (int) $row['student_id']),
        'last_id' => (int) $row['last_id'],
      ];
    }
    // Fetch last message and unread count
    foreach ($threads as &$t) {
      $lid = $t['last_id'];
      // last message details
      $q = $conn->prepare("SELECT sender, message, created_at FROM chat_messages WHERE id=?");
      $q->bind_param('i', $lid);
      $q->execute();
      $res = $q->get_result()->fetch_assoc();
      $q->close();
      $t['last_sender'] = $res['sender'] ?? null;
      $t['last_message'] = $res['message'] ?? '';
      $t['last_time'] = $res['created_at'] ?? null;

      // unread for admin = messages from student newer than last admin message id
      $q2 = $conn->prepare("
        SELECT COUNT(*) AS unread
        FROM chat_messages
        WHERE student_id = ? AND sender='student' AND id >
              COALESCE((
                SELECT MAX(id) FROM chat_messages
                WHERE student_id = ? AND sender='admin'
              ), 0)
      ");
      $q2->bind_param('ii', $t['student_id'], $t['student_id']);
      $q2->execute();
      $res2 = $q2->get_result()->fetch_assoc();
      $q2->close();
      $t['unread'] = (int) ($res2['unread'] ?? 0);
    }
    echo json_encode(['ok' => true, 'threads' => $threads]);
  } catch (Throwable $e) {
    error_log('chat_threads ERR: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Failed to load threads.']);
  }
  exit;
}

/* Fetch messages for a student (admin view) */
if (isset($_GET['action']) && $_GET['action'] === 'chat_fetch_admin') {
  header('Content-Type: application/json');
  $sid = isset($_GET['student_id']) ? (int) $_GET['student_id'] : 0;
  $since_id = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;
  if ($sid <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Missing student_id']);
    exit;
  }
  try {
    if ($since_id > 0) {
      $stmt = $conn->prepare("SELECT id, sender, message, created_at
                              FROM chat_messages
                              WHERE student_id = ? AND id > ?
                              ORDER BY id ASC
                              LIMIT 500");
      $stmt->bind_param('ii', $sid, $since_id);
    } else {
      $stmt = $conn->prepare("SELECT id, sender, message, created_at
                              FROM chat_messages
                              WHERE student_id = ?
                              ORDER BY id DESC
                              LIMIT 200");
      $stmt->bind_param('i', $sid);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc())
      $rows[] = $r;
    $stmt->close();
    if ($since_id === 0)
      $rows = array_reverse($rows);
    echo json_encode(['ok' => true, 'messages' => $rows]);
  } catch (Throwable $e) {
    error_log('chat_fetch_admin ERR: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Failed to fetch messages.']);
  }
  exit;
}

/* Send message from admin to a student */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'chat_send_admin') {
  header('Content-Type: application/json');
  $sid = isset($_POST['student_id']) ? (int) $_POST['student_id'] : 0;
  $msg = trim($_POST['message'] ?? '');
  if ($sid <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Missing student_id']);
    exit;
  }
  if ($msg === '') {
    echo json_encode(['ok' => false, 'msg' => 'Message cannot be empty.']);
    exit;
  }
  try {
    $stmt = $conn->prepare("INSERT INTO chat_messages (student_id, sender, message) VALUES (?, 'admin', ?)");
    $stmt->bind_param('is', $sid, $msg);
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();
    echo json_encode(['ok' => true, 'message' => ['id' => $newId, 'sender' => 'admin', 'message' => $msg]]);
  } catch (Throwable $e) {
    error_log('chat_send_admin ERR: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Failed to send.']);
  }
  exit;
}
/* ========== END of added admin chat server APIs ========== */

/* ------------------------------------------
   Add Student (original)
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
   AJAX: Get item (by id OR by category+item_name)
------------------------------------------- */
if (isset($_GET['action']) && $_GET['action'] === 'get_item') {

  header('Content-Type: application/json');

  // By ID (original behavior)
  if (isset($_GET['id'])) {
    $item_id = (int) $_GET['id'];

    $item_stmt = $conn->prepare("SELECT item_id, category, item_name, description, base_price FROM items WHERE item_id=?");
    $item_stmt->bind_param("i", $item_id);
    $item_stmt->execute();
    $item = $item_stmt->get_result()->fetch_assoc();
    $item_stmt->close();

    $sizes = [];
    if ($item) {
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

      echo json_encode(['ok' => true, 'item' => $item, 'sizes' => $sizes, 'images' => $images]);
      exit();
    } else {
      echo json_encode(['ok' => false, 'item' => null]);
      exit();
    }
  }

  // New: by category + item_name
  if (isset($_GET['category']) && isset($_GET['item_name'])) {
    $category = trim($_GET['category']);
    $iname = trim($_GET['item_name']);

    $item_stmt = $conn->prepare("SELECT item_id, category, item_name, description, base_price FROM items WHERE category = ? AND item_name = ? LIMIT 1");
    $item_stmt->bind_param("ss", $category, $iname);
    $item_stmt->execute();
    $item = $item_stmt->get_result()->fetch_assoc();
    $item_stmt->close();

    if (!$item) {
      echo json_encode(['ok' => false, 'item' => null]);
      exit();
    }

    $item_id = (int) $item['item_id'];

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

    echo json_encode(['ok' => true, 'item' => $item, 'sizes' => $sizes, 'images' => $images]);
    exit();
  }

  echo json_encode(['ok' => false, 'item' => null]);
  exit();
}

/* ------------------------------------------
   Add Item
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
   UPDATE Item
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
   DELETE Item
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
      width: 100px;
      height: 100px;
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
      width: min(900px, 95vw) !important;
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
    }

    /* ===== Panels (Add Student / Add Admin) ===== */

    #panelAddStudent,
    #panelAddAdmin {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .5);
      z-index: 5000;
      padding: 20px;
      overflow: auto;
    }

    #panelAddStudent.show,
    #panelAddAdmin.show {
      display: flex;
      align-items: center;
      justify-content: center;
    }

    /* Panel body */
    .additem-panel {
      width: min(420px, 95%);
      background: #ffffff;
      border-radius: 14px;
      box-shadow: 0 8px 30px rgba(0, 0, 0, .25);
      overflow: hidden;
      animation: scaleIn .25s ease;
    }

    @keyframes scaleIn {
      from {
        transform: scale(.85);
        opacity: 0;
      }

      to {
        transform: scale(1);
        opacity: 1;
      }
    }

    /* Header */
    .additem-header {
      background: #111827;
      padding: 14px 18px;
      color: #fff;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .additem-title {
      margin: 0;
      font-size: 17px;
      font-weight: 700;
    }

    .additem-header .ck-close {
      background: rgba(255, 255, 255, .2);
      color: #fff;
      border: none;
      padding: 6px 10px;
      border-radius: 8px;
      cursor: pointer;
      transition: .2s;
    }

    .additem-header .ck-close:hover {
      background: rgba(255, 255, 255, .35);
    }

    /* Body */
    .additem-body {
      padding: 20px 22px;
    }

    /* Form styling */
    #panelAddStudent form,
    #panelAddAdmin form {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    #panelAddStudent label,
    #panelAddAdmin label {
      font-weight: 600;
      font-size: 14px;
      color: #111827;
    }

    #panelAddStudent input,
    #panelAddAdmin input {
      padding: 10px 12px;
      font-size: 14px;
      border-radius: 8px;
      border: 1px solid #cbd5e1;
      outline: none;
      transition: border-color .2s ease;
    }

    #panelAddStudent input:focus,
    #panelAddAdmin input:focus {
      border-color: #2563eb;
    }

    /* Save buttons */
    #panelAddStudent button.btn-chip.primary,
    #panelAddAdmin button.btn-chip.primary {
      background: #2563eb;
      border-color: #2563eb;
      color: #fff;
      font-weight: 700;
      border-radius: 8px;
      padding: 10px;
      cursor: pointer;
      transition: background .25s ease;
    }

    #panelAddStudent button.btn-chip.primary:hover,
    #panelAddAdmin button.btn-chip.primary:hover {
      background: #1d4ed8;
    }

    /* Open panel buttons */
    #openAddStudent,
    #openAddAdmin {
      background: #2563eb;
      padding: 10px 16px;
      border-radius: 8px;
      border: none;
      color: #fff;
      font-weight: bold;
      cursor: pointer;
      transition: background .25s ease;
    }

    #openAddStudent:hover,
    #openAddAdmin:hover {
      background: #1d4ed8;
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
      <img
        src="<?php echo $sidebar_profile ? 'data:image/jpeg;base64,' . base64_encode($sidebar_profile) : 'logo.png'; ?>"
        alt="Profile Picture" class="profile-pic" id="sidebarProfilePic">

      <p class="profile-name" id="sidebarName">
        <?php echo htmlspecialchars($sidebar_name); ?>
      </p>

      <p class="course" id="sidebarCourse">
        <?php echo htmlspecialchars($sidebar_position); ?>
      </p>

      <button class="sidebar-btn" id="homeBut">Home</button>
      <button class="sidebar-btn" id="ordersBtn">Orders</button>
      <button class="sidebar-btn" id="stocksBtn">Stocks</button>
      <button class="sidebar-btn" id="manualAddBtn">Account Management</button>
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
    // flash notices
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
          SUM(COALESCE(s.stock,0)) AS total_stock,
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
              <td><?= (int) ($row['total_stock'] ?? 0) ?></td>
              <td>₱<?= $price_display ?></td>
              <td>
                <!-- Edit button removed as requested -->
                <button class="delete-btn btn-del-item" data-id="<?= (int) $row['item_id'] ?>">Delete</button>
              </td>
            </tr>
          <?php endwhile; endif; ?>
      </tbody>
    </table>
  </section>

  <!-- ===== Manual Add / Users Table ===== -->
  <section class="manual-section" id="manualAddSection">
    <?php
    if (!empty($add_admin_success)) {
      echo "<p style='color:green;margin:10px 0;'>$add_admin_success</p>";
    }
    if (!empty($add_admin_error)) {
      echo "<p style='color:red;margin:10px 0;'>$add_admin_error</p>";
    }
    ?>

    <h1 class="dashboard-title">Account Management</h1>

    <div style="display:flex; justify-content:flex-end; gap:10px; margin-bottom:20px;">
      <button id="openAddStudent" class="sidebar-btn" style="width:auto;">Add Student</button>
      <button id="openAddAdmin" class="sidebar-btn" style="width:auto;">Add Admin</button>
    </div>

    <!-- USERS TABLE -->
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>FULL NAME</th>
          <th>EMAIL</th>
          <th>USER TYPE</th>
          <th>STATUS</th>
          <th>LAST ONLINE</th>
        </tr>
      </thead>

      <tbody>

        <?php
        /* FETCH STUDENTS */
        $students = $conn->query("
    SELECT 
        id AS uid,
        fullname,
        gsuite_email AS email,
        status,
        last_online
    FROM students
    ORDER BY id DESC
");

        /* FETCH ADMINS */
        $admins = $conn->query("
    SELECT 
        a.admin_id AS uid,
        a.admin_name AS fullname,
        sl.email,
        a.status,
        a.last_online
    FROM admin a
    LEFT JOIN student_login sl ON sl.login_id = a.login_id
    ORDER BY a.admin_id DESC
");

        /* DISPLAY STUDENTS */
        if ($students) {
          while ($s = $students->fetch_assoc()) {

            if ($s['status'] === 'online') {
              $status_badge = "<span style='color:green;font-weight:bold;'>● Online</span>";
              $last_online = "—";
            } else {
              $status_badge = "<span style='color:red;font-weight:bold;'>● Offline</span>";
              $last_online = $s['last_online'] ?: "—";
            }

            echo "
        <tr>
            <td>{$s['uid']}</td>
            <td>" . htmlspecialchars($s['fullname']) . "</td>
            <td>" . htmlspecialchars($s['email']) . "</td>
            <td style='color:#2563eb;font-weight:bold;'>Student</td>
            <td>{$status_badge}</td>
            <td>{$last_online}</td>
        </tr>";
          }
        }

        /* DISPLAY ADMINS */
        if ($admins) {
          while ($a = $admins->fetch_assoc()) {

            if ($a['status'] === 'online') {
              $status_badge = "<span style='color:green;font-weight:bold;'>● Online</span>";
              $last_online = "—";
            } else {
              $status_badge = "<span style='color:red;font-weight:bold;'>● Offline</span>";
              $last_online = $a['last_online'] ?: "—";
            }

            echo "
        <tr>
            <td>{$a['uid']}</td>
            <td>" . htmlspecialchars($a['fullname']) . "</td>
            <td>" . htmlspecialchars($a['email']) . "</td>
            <td style='color:#dc2626;font-weight:bold;'>Admin</td>
            <td>{$status_badge}</td>
            <td>{$last_online}</td>
        </tr>";
          }
        }
        ?>

      </tbody>

    </table>


  </section>
  <!-- Add Student Panel -->
  <div id="panelAddStudent" class="additem-modal">
    <div class="additem-panel">
      <div class="additem-header">
        <h3 class="additem-title">Add Student</h3>
        <button class="ck-close"
          onclick="document.getElementById('panelAddStudent').classList.remove('show')">×</button>
      </div>

      <div class="additem-body">
        <form method="POST" enctype="multipart/form-data">
          <label>Full Name</label><input type="text" name="fullname" required>
          <label>SR-Code</label><input type="text" name="sr_code" required>
          <label>Gsuite Email</label><input type="email" name="gsuite_email" required>
          <label>Course</label><input type="text" name="course" required>
          <label>Profile Picture</label><input type="file" name="profile_pic" accept="image/*">
          <label>Password</label><input type="password" name="password" required>
          <button type="submit" name="add_student" class="btn-chip primary" style="margin-top:15px;">Add
            Student</button>
        </form>
      </div>
    </div>
  </div>
  <!-- Add Admin Panel -->
  <div id="panelAddAdmin" class="additem-modal">
    <div class="additem-panel">
      <div class="additem-header">
        <h3 class="additem-title">Add Admin</h3>
        <button class="ck-close" onclick="document.getElementById('panelAddAdmin').classList.remove('show')">×</button>
      </div>

      <div class="additem-body">
        <form method="POST" enctype="multipart/form-data">
          <label>Admin Name</label>
          <input type="text" name="admin_name" required>

          <label>Admin Position</label>
          <input type="text" name="admin_position" required>

          <label>Profile Picture</label>
          <input type="file" name="admin_p" accept="image/*">
          <label>Email</label>
          <input type="text" name="email" required>
          <label>Password</label>
          <input type="password" name="password" required>

          <button type="submit" name="add_admin" class="btn-chip primary" style="margin-top:15px;">Add Admin</button>
        </form>
      </div>
    </div>
  </div>


  <!-- ===== Add/Edit Item Modal ===== -->
  <div id="addItemModalFE" class="additem-modal" aria-hidden="true">
    <div class="additem-panel" role="dialog" aria-modal="true" aria-labelledby="addItemTitleFE">
      <div class="additem-header">
        <h3 id="addItemTitleFE" class="additem-title">Add Item</h3>
        <button id="addItemCloseFE" class="ck-close" aria-label="Close">×</button>
      </div>

      <div class="additem-body">
        <!-- IMPORTANT: real form -->
        <form id="addItemFormFE" method="POST" enctype="multipart/form-data">
          <!-- Mode toggles: Add vs Update -->
          <input type="hidden" name="add_item" id="modeAddField" value="1">
          <input type="hidden" name="" id="modeUpdateField" value="1" disabled style="display:none;">
          <input type="hidden" name="edit_item_id" id="editItemIdFE" value="0">
          <input type="hidden" name="sizes_json" id="sizesJsonFE" value="[]">

          <div class="grid2">
            <div>
              <label>Category</label>
              <select id="feCategory" name="category"
                style="width:100%;padding:10px;border:1px solid #ccc;border-radius:8px">
                <option value="">Select Category</option>
                <option value="textile">TEXTILE</option>
                <option value="uniforms">UNIFORM</option>
                <option value="accessories">ACCESSORIES</option>
              </select>
            </div>
            <div>
              <label>Item Name</label>
              <!-- original input for backend -->
              <input id="feName" name="item_name" placeholder="e.g., PE Uniform"
                style="width:100%;padding:10px;border:1px solid:#ccc;border-radius:8px">
              <!-- dropdown driven by category -->
              <select id="feNameSelect"
                style="width:100%;padding:10px;border:1px solid #ccc;border-radius:8px;margin-top:6px;display:none;"></select>
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

          <label>Sizes & Stocks</label>
          <table class="slim" id="sizesTableFE">
            <thead>
              <tr>
                <th>Size</th>
                <th>Price (₱)</th>
                <th>Current Stock</th>
                <th>Add Stock</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
          <!-- Add size buttons hidden as requested -->
          <div style="display:none;gap:8px;justify-content:flex-end;margin-top:10px">
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

      // ===== Add/Edit Item Modal =====
      (function () {
        const CATEGORY_ITEMS = {
          textile: [
            {
              name: 'WHITE FABRIC EMBEDDED',
              desc: 'Bright, crisp, and long-lasting white fabric crafted for school uniforms.'
            },
            {
              name: 'CHECKERED (FOR COLLEGE SKIRTS)',
              desc: 'Classic checkered fabric tailored for sleek and stylish college skirts.'
            },
            {
              name: 'REMINGTON (FOR COLLEGE PANTS)',
              desc: 'High-quality Remington fabric made for strong, comfortable college pants.'
            }
          ],
          uniforms: [
            { name: 'UNIFORM', desc: 'Standard university uniform.' },
            { name: 'COLLEGE BLOUSE', desc: 'Formal blouse for college uniforms.' },
            { name: 'COLLEGE BARONG', desc: 'Classic barong for college events and uniforms.' },
            { name: 'COLLEGE PANTS', desc: 'Tailored pants for college uniforms.' },
            { name: 'COLLEGE SKIRT', desc: 'Formal skirt for college uniforms.' }
          ],
          accessories: [
            { name: 'ID LACES', desc: 'Durable ID laces for daily campus use.' },
            { name: 'COLLAR PINS', desc: 'Metal collar pins featuring school insignia.' }
          ]
        };

        const CATEGORY_SIZES = {
          textile: ['1 YARD', '2 YARDS', '3 YARDS'],
          uniforms: ['XS', 'S', 'M', 'L', 'XL', 'XXL'],
          skirts: ['24', '25', '26', '27', '28', '29', '30', '31', '32'],
          blouse: ['XS', 'S', 'M', 'L', 'XL', 'XXL'],
          barong: ['XS', 'S', 'M', 'L', 'XL', 'XXL'],
          pants: ['28', '29', '30', '31', '32', '33', '34'],
          accessories: ['ONE SIZE']
        };

          // === Default prices per ITEM NAME + SIZE LABEL ===
  // NOTE: keys are UPPERCASE, so we uppercase when we match.
  const ITEM_SIZE_PRICES = {
    // ── TEXTILE (per yard) ─────────────────────────────
    'WHITE FABRIC EMBEDDED': {
      '1 YARD': 150.00,
      '2 YARDS': 300.00, // assumed x2
      '3 YARDS': 450.00  // assumed x3
    },
    'CHECKERED (FOR COLLEGE SKIRTS)': {
      '1 YARD': 150.00,
      '2 YARDS': 300.00,
      '3 YARDS': 450.00
    },
    'REMINGTON (FOR COLLEGE PANTS)': {
      '1 YARD': 150.00,
      '2 YARDS': 300.00,
      '3 YARDS': 450.00
    },

    // ── COLLEGE SKIRT ──────────────────────────────────
    // Small–Large – 320.00 , XL–5XL – 355.00 , Special Sizes – 450.00
    'COLLEGE SKIRT': {
      // letter sizes (if you use uniforms category)
      'XS': 320.00,
      'S': 320.00,
      'M': 320.00,
      'L': 320.00,
      'XL': 355.00,
      'XXL': 355.00,
      '3XL': 355.00,
      '4XL': 355.00,
      '5XL': 355.00,
      'SPECIAL': 450.00,

      // numeric waist sizes (if you use skirts category)
      '24': 320.00,
      '25': 320.00,
      '26': 320.00,
      '27': 320.00,
      '28': 320.00,
      '29': 320.00,
      '30': 320.00,
      '31': 355.00,
      '32': 355.00
    },

    // ── COLLEGE BLOUSE ─────────────────────────────────
    // Small–Large 350.00, Medium–3XL – 380.00, 4XL–5XL – 395.00, Special Size 450.00
    'COLLEGE BLOUSE': {
      'XS': 350.00,
      'S': 350.00,
      'M': 350.00,
      'L': 350.00,

      'XL': 380.00,
      'XXL': 380.00,
      '3XL': 380.00,

      '4XL': 395.00,
      '5XL': 395.00,

      'SPECIAL': 450.00
    },

    // ── COLLEGE BARONG ─────────────────────────────────
    // 18–Small – 350.00 , Medium–Large 370.00 , XL–2XL – 400.00 , 3XL–5XL – 440.00 , Special – 450.00
    'COLLEGE BARONG': {
      '18': 350.00,
      'XS': 350.00,
      'S': 350.00,

      'M': 370.00,
      'L': 370.00,

      'XL': 400.00,
      'XXL': 400.00,

      '3XL': 440.00,
      '4XL': 440.00,
      '5XL': 440.00,

      'SPECIAL': 450.00
    },

    // ── COLLEGE PANTS ──────────────────────────────────
    // 16–40 (waistline) 380.00, 42–46 – 390.00
    'COLLEGE PANTS': {
      // generic letter sizes
      'XS': 380.00,
      'S': 380.00,
      'M': 380.00,
      'L': 380.00,
      'XL': 380.00,
      'XXL': 380.00,

      // numeric (if you use pants category; adjust as needed)
      '16': 380.00, '18': 380.00, '20': 380.00, '22': 380.00, '24': 380.00,
      '26': 380.00, '28': 380.00, '30': 380.00, '32': 380.00, '34': 380.00,
      '36': 380.00, '38': 380.00, '40': 380.00,
      '42': 390.00, '44': 390.00, '46': 390.00
    },

    // ── ACCESSORIES ────────────────────────────────────
    'ID LACES': {
      'ONE SIZE': 60.00
    },
    'COLLAR PINS': {
      'ONE SIZE': 0.00 // TODO: put the real price here
    }
  };

        // ==== NEW HELPERS FOR AUTO-PRICE BY ITEM + SIZE ====

        function getCurrentItemName() {
          const rawFromSelect = (typeof feNameSelect !== 'undefined' && feNameSelect && feNameSelect.style.display !== 'none')
            ? (feNameSelect.value || '')
            : '';
          const rawFromInput = feName ? (feName.value || '') : '';
          const raw = rawFromSelect || rawFromInput;
          return raw.trim().toUpperCase();
        }

        function applyDefaultPricesForCurrentItem() {
          const itemName = getCurrentItemName();
          if (!itemName || !ITEM_SIZE_PRICES[itemName]) return;
          if (!sizesTableFE) return;

          const priceMap = ITEM_SIZE_PRICES[itemName];
          const rows = sizesTableFE.querySelectorAll('tr');

          rows.forEach(tr => {
            const labelInput = tr.querySelector('.sz-label');
            const priceInput = tr.querySelector('.sz-price');
            if (!labelInput || !priceInput) return;

            const currentVal = (priceInput.value || '').trim();
            // do not override if user or DB already has a non-zero price
            if (currentVal !== '' && currentVal !== '0' && currentVal !== '0.00') return;

            const lbl = (labelInput.value || '').trim().toUpperCase();
            if (priceMap[lbl] != null) {
              priceInput.value = priceMap[lbl].toFixed(2);
            }
          });
        }


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
        const feNameSelect = document.getElementById('feNameSelect');
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
          feCategory.value = '';
          feName.value = '';
          feNameSelect.innerHTML = '';
          feNameSelect.style.display = 'none';
          feName.style.display = 'block';
          feDesc.value = '';
          feBasePrice.value = '';
          imgs = [];
          imgPreviewFE.innerHTML = '';
          existingPreviewFE.innerHTML = '';
          sizesTableFE.innerHTML = '';
          addSizeRow(); // one row to start
          updateAllPreviews();
          updateCategoryDependentState();
        }

        function openForAdd() {
          switchToAddMode();
          clearForm();
          openModal();
        }

        openBtn?.addEventListener('click', openForAdd);
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
            d.addEventListener('drop', ev => {
              ev.preventDefault();
              const from = parseInt(ev.dataTransfer.getData('text/plain'), 10);
              const to = i;
              if (from === to) return;
              const [m] = imgs.splice(from, 1);
              imgs.splice(to, 0, m);
              renderThumbs();
              updateAllPreviews();
            });
            d.appendChild(im); d.appendChild(tg); d.appendChild(rm); imgPreviewFE.appendChild(d);
          });
        }

        function addSizeRow(val = { label: '', price: '', stock: null }) {
          const existing = (val.stock === null || val.stock === undefined || isNaN(val.stock)) ? 0 : Number(val.stock);
          const initialAdd = val.add || 0;

          const tr = document.createElement('tr');
          tr.dataset.existingStock = String(existing);

          tr.innerHTML = `
          <td><input class="sz-label" placeholder="e.g., S" style="width:110px;padding:8px;border:1px solid #ccc;border-radius:6px" value="${val.label ?? ''}"></td>
          <td><input class="sz-price" type="number" step="0.01" min="0" style="width:120px;padding:8px;border:1px solid #ccc;border-radius:6px" value="${val.price ?? ''}"></td>
        <td>
  <input
    class="sz-stock-current"
    type="number"
    value="${existing}"
    disabled
    readonly
    style="width:100px;padding:8px;border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb;pointer-events:none"
  >
</td>

          <td>
            <input class="sz-stock-add" type="number" min="0" placeholder="0"
              style="width:100px;padding:8px;border:1px solid #ccc;border-radius:6px"
              value="${initialAdd || ''}">
          </td>
        `;

          ['input', 'change'].forEach(ev => tr.addEventListener(ev, () => {
            updateAllPreviews();
          }));

          sizesTableFE.appendChild(tr);
        }

        // (buttons for add/preset exist but are hidden in HTML)
        addSizeRowFE?.addEventListener('click', () => addSizeRow());
        addPresetFE?.addEventListener('click', () => {
          sizesTableFE.innerHTML = '';
          ['XS', 'S', 'M', 'L', 'XL'].forEach(s => addSizeRow({ label: s, price: '0', stock: 0 }));
          updateAllPreviews();
        });

        function updateItemNameOptions(isEditPrefill) {
          const cat = feCategory.value;
          const items = CATEGORY_ITEMS[cat] || [];
          feNameSelect.innerHTML = '';

          if (!cat) {
            feNameSelect.style.display = 'none';
            feName.style.display = 'block';
            return;
          }

          if (items.length) {
            feNameSelect.style.display = 'block';
            feName.style.display = 'none';

            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.disabled = true;
            placeholder.selected = true;
            placeholder.textContent = 'Select item name...';
            feNameSelect.appendChild(placeholder);

            items.forEach(it => {
              const opt = document.createElement('option');
              opt.value = it.name;
              opt.textContent = it.name;
              feNameSelect.appendChild(opt);
            });

            if (isEditPrefill && feName.value) {
              const match = items.find(i => i.name === feName.value);
              if (match) {
                feNameSelect.value = match.name;
                if (!feDesc.value || !feDesc.value.trim()) {
                  feDesc.value = match.desc;
                }
              }
            }
          } else {
            feNameSelect.style.display = 'none';
            feName.style.display = 'block';
          }
        }

        function updateSizesForCategory(forceReplace = false) {
          const cat = feCategory.value;
          const defs = CATEGORY_SIZES[cat];
          if (!defs || !defs.length) return;

          // As requested: no validation / confirm popup when changing category
          sizesTableFE.innerHTML = '';
          defs.forEach(lbl => addSizeRow({ label: lbl, price: '', stock: 0 }));

          // NEW: auto-apply default prices for this item (if mapped)
          applyDefaultPricesForCurrentItem();

          updateAllPreviews();
        }

        function updateCategoryDependentState() {
          const hasCat = !!feCategory.value;

          const fields = [feName, feDesc, feBasePrice, feImages, doneFE];
          fields.forEach(f => { if (f) f.disabled = !hasCat; });
          if (feNameSelect) feNameSelect.disabled = !hasCat;

          const sizeInputs = sizesTableFE.querySelectorAll('input, select, button');
          sizeInputs.forEach(inp => {
            if (!inp.closest('.non-category')) {
              inp.disabled = !hasCat && inp.type !== 'hidden';
            }
          });
        }

        feNameSelect.addEventListener('change', () => {
          const cat = feCategory.value;
          const items = CATEGORY_ITEMS[cat] || [];
          const selectedName = feNameSelect.value;
          feName.value = selectedName || '';
          const selectedMeta = items.find(i => i.name === selectedName);
          if (selectedMeta && (!feDesc.value || !feDesc.value.trim())) {
            feDesc.value = selectedMeta.desc;
          }

          // NEW: apply default prices when a predefined item is chosen
          applyDefaultPricesForCurrentItem();

          updateAllPreviews();
          tryLoadItemByCategoryName(); // load from DB when category+item selected
        });

        feCategory.addEventListener('change', () => {
          updateItemNameOptions(false);
          updateSizesForCategory(true);
          updateCategoryDependentState();
          updateAllPreviews();
        });

        [feName, feDesc, feBasePrice].forEach(el => el.addEventListener('input', updateAllPreviews));

        // NEW: when typing item name manually, attempt to auto-fill default prices
        feName.addEventListener('input', () => {
          applyDefaultPricesForCurrentItem();
          updateAllPreviews();
        });

        feName.addEventListener('change', () => {
          // NEW: also apply default prices on blur/change
          applyDefaultPricesForCurrentItem();
          updateAllPreviews();
          tryLoadItemByCategoryName(); // also trigger DB load on manual name change
        });

        function collectSizes() {
          const rows = [...sizesTableFE.querySelectorAll('tr')];
          return rows.map(r => {
            const label = r.querySelector('.sz-label').value.trim();
            const priceVal = r.querySelector('.sz-price').value;
            const addVal = r.querySelector('.sz-stock-add').value.trim();
            const currentInput = r.querySelector('.sz-stock-current');

            const existing = currentInput ? Number(currentInput.value || '0') : Number(r.dataset.existingStock || '0');
            const addNum = addVal === '' ? 0 : (parseInt(addVal, 10) || 0);
            const finalStock = existing + addNum;

            if (!label) return null;
            const price = parseFloat(priceVal || '0');
            return {
              label,
              price: isNaN(price) ? 0 : price,
              stock: finalStock
            };
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

        // Load an item from DB by category+name (used when selecting Category + Item Name)
        async function tryLoadItemByCategoryName() {
          const cat = feCategory.value;
          const nm = feName.value.trim();
          if (!cat || !nm) return;

          try {
            const res = await fetch(`<?php echo basename(__FILE__); ?>?action=get_item&category=${encodeURIComponent(cat)}&item_name=${encodeURIComponent(nm)}`);
            const data = await res.json();
            if (!data.ok || !data.item) {
              // If no item exists for this combination, stay in ADD mode
              switchToAddMode();
              // keep selected category and name, reset sizes to defaults for category
              feCategory.value = cat;
              feName.value = nm;
              updateItemNameOptions(true);
              sizesTableFE.innerHTML = '';
              updateSizesForCategory(true);
              updateCategoryDependentState();
              updateAllPreviews();
              return;
            }

            const item = data.item;
            switchToEditMode(item.item_id);

            // Fill fields from DB
            feCategory.value = item.category || cat;
            feName.value = item.item_name || nm;
            feDesc.value = item.description || '';
            feBasePrice.value = (item.base_price !== null && item.base_price !== undefined) ? item.base_price : '';

            updateItemNameOptions(true);

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

            // Sizes from DB: Current Stock is loaded from DB, not editable
            sizesTableFE.innerHTML = '';
            if (Array.isArray(data.sizes) && data.sizes.length) {
              data.sizes.forEach(s => addSizeRow({
                label: s.label || '',
                price: s.price || 0,
                stock: (s.stock === null ? 0 : s.stock)
              }));
            } else {
              sizesTableFE.innerHTML = '';
              updateSizesForCategory(true);
            }

            // Reset images selection if any previous files
            feImages.value = '';
            imgs = [];
            imgPreviewFE.innerHTML = '';

            updateCategoryDependentState();
            updateAllPreviews();
            openModal();
          } catch (e) {
            console.error(e);
          }
        }

        // init
        addSizeRow();
        updateAllPreviews();
        updateCategoryDependentState();

        // Serialize sizes to hidden json before submit
        form.addEventListener('submit', () => {
          const payload = collectSizes();
          sizesJsonField.value = JSON.stringify(payload);
        });

        // DELETE from list
        document.querySelectorAll('.btn-del-item').forEach(btn => {
          btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            if (!confirm('Delete this item? This cannot be undone.')) return;
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

  <!-- POPUP FOR STOCKS <= 5 -->
  <script>
    const lowStocksData = <?php echo json_encode($lowStocks); ?>;
    if (Array.isArray(lowStocksData) && lowStocksData.length) {
      let msg = 'The following item sizes have stock of 5 or below:\n\n';
      msg += lowStocksData.map(it =>
        `${it.category} - ${it.item_name} (${it.label}): ${it.stock} pcs`
      ).join('\n');
      alert(msg);
    }
  </script>

  <!-- =========================
     APPEND-ONLY: RGO Inbox (Admin)
     ========================= -->
  <style>
    .rgo-inbox {
      position: fixed;
      right: 18px;
      bottom: 18px;
      width: 920px;
      max-width: calc(100vw - 36px);
      height: 560px;
      max-height: calc(100vh - 36px);
      background: #fff;
      border: 1px solid #e5e7eb;
      border-radius: 16px;
      box-shadow: 0 18px 40px rgba(0, 0, 0, .18);
      display: none;
      z-index: 6000;
      overflow: hidden
    }

    .rgo-inbox .hdr {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 10px 12px;
      background: #111827;
      color: #fff
    }

    .rgo-inbox .title {
      font-weight: 800;
      margin: 0;
      font-size: 15px
    }

    .rgo-inbox .x {
      border: none;
      background: rgba(255, 255, 255, .18);
      color: #fff;
      font-size: 18px;
      line-height: 1;
      width: 28px;
      height: 28px;
      border-radius: 8px;
      cursor: pointer
    }

    .rgo-inbox .body {
      display: grid;
      grid-template-columns: 300px 1fr;
      height: calc(100% - 52px)
    }

    .rgo-threads {
      border-right: 1px solid #eee;
      overflow: auto;
      background: #fafafa
    }

    .rgo-thread {
      padding: 10px 12px;
      border-bottom: 1px solid #eee;
      display: flex;
      gap: 10px;
      cursor: pointer
    }

    .rgo-thread:hover {
      background: #f1f5f9
    }

    .rgo-thread .av {
      width: 36px;
      height: 36px;
      border-radius: 999px;
      display: grid;
      place-items: center;
      background: #111827;
      color: #fff;
      font-weight: 800
    }

    .rgo-thread .tx {
      flex: 1;
      min-width: 0
    }

    .rgo-thread .nm {
      font-weight: 700;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis
    }

    .rgo-thread .lm {
      font-size: 12px;
      color: #475569;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis
    }

    .rgo-thread .time {
      font-size: 12px;
      color: #64748b
    }

    .rgo-thread .badge {
      background: #ef4444;
      color: #fff;
      border-radius: 999px;
      font-size: 11px;
      padding: 2px 8px;
      margin-left: auto;
      height: fit-content
    }

    .rgo-msgs {
      display: flex;
      flex-direction: column;
      height: 100%
    }

    .rgo-msg-list {
      flex: 1;
      overflow: auto;
      padding: 16px;
      background: #fff
    }

    .rgo-empty {
      color: #64748b;
      text-align: center;
      margin-top: 16px
    }

    .rgo-bubble {
      max-width: 72%;
      padding: 10px 12px;
      border-radius: 12px;
      margin: 6px 0;
      font-size: 14px;
      line-height: 1.35;
      white-space: pre-wrap;
      word-wrap: break-word
    }

    .rgo-me {
      background: #e6f2ff;
      color: #0b5394;
      margin-left: auto;
      border-top-right-radius: 4px
    }

    .rgo-st {
      background: #fff;
      border: 1px solid #e5e7eb;
      color: #111827;
      border-top-left-radius: 4px
    }

    .rgo-input {
      display: grid;
      grid-template-columns: 1fr 96px;
      gap: 8px;
      padding: 10px;
      background: #fff;
      border-top: 1px solid #eee
    }

    .rgo-input input {
      border: 1px solid #e5e7eb;
      border-radius: 10px;
      padding: 10px 12px;
      font-size: 14px;
      outline: none
    }

    .rgo-input button {
      background: #111827;
      color: #fff;
      border: none;
      border-radius: 10px;
      font-weight: 800;
      cursor: pointer
    }

    .rgo-msgs {
      min-height: 0 !important;
    }

    .rgo-msg-list {
      overflow-y: auto !important;
      overflow-x: hidden;
      max-height: 100% !important;
      -webkit-overflow-scrolling: touch;
      overscroll-behavior: contain;
    }

    .rgo-msg-list::-webkit-scrollbar {
      width: 8px;
    }

    .rgo-msg-list::-webkit-scrollbar-track {
      background: #f3f4f6;
      border-radius: 8px;
    }

    .rgo-msg-list::-webkit-scrollbar-thumb {
      background: #cbd5e1;
      border-radius: 8px;
    }

    .rgo-msg-list:hover::-webkit-scrollbar-thumb {
      background: #94a3b8;
    }

    .rgo-inbox {
      height: 560px;
      max-height: calc(100vh - 36px);
    }

    .rgo-inbox .body {
      height: calc(100% - 52px);
    }

    .sidebar .sidebar-btn[data-rgo-notifs="1"] {
      display: none !important;
    }

    .rgo-threads {
      background: #fff !important;
      border-right: 1px solid #e5e7eb;
    }

    .rgo-thread {
      padding: 12px 14px !important;
      align-items: center;
      border-bottom: 1px solid #f1f5f9 !important;
      border-radius: 0 !important;
    }

    .rgo-thread:hover {
      background: #f8fafc !important;
    }

    .rgo-thread .av {
      width: 40px;
      height: 40px;
      border-radius: 999px;
      font-weight: 800;
      background: #2563eb !important;
    }

    .rgo-thread .tx {
      display: flex;
      flex-direction: column;
      gap: 2px
    }

    .rgo-thread .nm {
      font-weight: 600 !important;
      color: #0f172a;
    }

    .rgo-thread .lm {
      color: #475569 !important;
      font-size: 13px;
      max-width: 260px;
    }

    .rgo-thread .time {
      color: #94a3b8 !important;
      font-size: 12px;
      margin-left: 8px;
      white-space: nowrap;
    }

    .rgo-thread.unread .nm {
      font-weight: 800 !important;
    }

    .rgo-thread.unread .lm {
      color: #0f172a !important;
      font-weight: 600;
    }

    .rgo-thread .dot {
      width: 10px;
      height: 10px;
      border-radius: 999px;
      background: #2563eb;
      margin-left: 8px;
    }

    .rgo-recents-head {
      position: sticky;
      top: 0;
      z-index: 1;
      background: #fff;
      border-bottom: 1px solid #e5e7eb;
      padding: 10px;
    }

    .rgo-search {
      display: flex;
      align-items: center;
      gap: 8px;
      border: 1px solid #e5e7eb;
      border-radius: 999px;
      padding: 8px 12px;
      background: #f8fafc;
    }

    .rgo-search input {
      flex: 1;
      border: none;
      background: transparent;
      outline: none;
      font-size: 14px;
    }

    .rgo-fab {
      position: fixed;
      right: 18px;
      bottom: 18px;
      z-index: 6500;
      width: 58px;
      height: 58px;
      border-radius: 999px;
      border: none;
      cursor: pointer;
      background: #2563eb;
      color: #fff;
      box-shadow: 0 10px 24px rgba(2, 6, 23, .24);
      display: grid;
      place-items: center;
      font-size: 24px;
      line-height: 1;
    }

    .rgo-fab:hover {
      filter: brightness(1.05);
    }

    .rgo-inbox .body {
      grid-template-columns: 340px 1fr !important;
    }
  </style>

  <script>
    (function () {
      // UI shell
      const inbox = document.createElement('div');
      inbox.className = 'rgo-inbox';
      inbox.innerHTML = `
    <div class="hdr">
      <div class="title">RGO Inbox</div>
      <button class="x" aria-label="Close">×</button>
    </div>
    <div class="body">
      <div class="rgo-threads" id="rgoThreads"></div>
      <div class="rgo-msgs">
        <div class="rgo-msg-list" id="rgoMsgList"><div class="rgo-empty" id="rgoEmpty">Select a conversation.</div></div>
        <div class="rgo-input">
          <input type="text" id="rgoInput" placeholder="Type a reply..." maxlength="1000"/>
          <button id="rgoSend">Send</button>
        </div>
      </div>
    </div>
  `;
      document.body.appendChild(inbox);

      const threadsEl = inbox.querySelector('#rgoThreads');
      const msgList = inbox.querySelector('#rgoMsgList');
      const input = inbox.querySelector('#rgoInput');
      const sendBtn = inbox.querySelector('#rgoSend');
      const empty = inbox.querySelector('#rgoEmpty');
      const closeBtn = inbox.querySelector('.x');

      let activeSid = null;
      let lastMsgId = 0;
      let pollThreads = null;
      let pollMsgs = null;
      const POLL = 3500;

      function openInbox() {
        inbox.style.display = 'block';
        refreshThreads();
        if (!pollThreads) pollThreads = setInterval(refreshThreads, POLL);
        if (activeSid && !pollMsgs) pollMsgs = setInterval(fetchMsgs, POLL);
        setTimeout(() => input.focus(), 80);
      }
      function closeInbox() {
        inbox.style.display = 'none';
        if (pollThreads) { clearInterval(pollThreads); pollThreads = null; }
        if (pollMsgs) { clearInterval(pollMsgs); pollMsgs = null; }
      }
      closeBtn.addEventListener('click', closeInbox);

      // Hook Notifications button
      document.querySelector('.sidebar')?.addEventListener('click', (e) => {
        const t = e.target;
        if (t.classList.contains('sidebar-btn') && /notifications/i.test(t.textContent || '')) {
          const sb = document.getElementById('sidebar'); const ov = document.getElementById('overlay');
          sb?.classList.remove('open'); ov?.classList.remove('show');
          openInbox();
        }
      });

      function el(tag, cls, txt) { const d = document.createElement(tag); if (cls) d.className = cls; if (txt !== undefined) d.textContent = txt; return d; }
      function fmtTime(iso) { if (!iso) return ''; try { const d = new Date(iso.replace(' ', 'T')); return d.toLocaleString(); } catch { return iso; } }
      function scrollToBottom() { msgList.scrollTop = msgList.scrollHeight; }

      function addBubble(sender, message) {
        if (empty) empty.style.display = 'none';
        const b = el('div', 'rgo-bubble ' + (sender === 'admin' ? 'rgo-me' : 'rgo-st'));
        b.textContent = message;
        msgList.appendChild(b);
      }

      async function refreshThreads() {
        try {
          const res = await fetch(`${location.pathname.split('/').pop()}?action=chat_threads`);
          const data = await res.json();
          if (!data.ok) return;
          const list = Array.isArray(data.threads) ? data.threads : [];
          threadsEl.innerHTML = '';
          list.forEach(th => {
            const row = el('div', 'rgo-thread'); row.dataset.sid = th.student_id;
            const av = el('div', 'av', (th.fullname || 'S')[0]?.toUpperCase() || 'S');
            const tx = el('div', 'tx');
            const nm = el('div', 'nm', th.fullname || ('Student #' + th.student_id));
            const lm = el('div', 'lm', (th.last_sender === 'admin' ? 'You: ' : '') + (th.last_message || ''));
            const rt = el('div'); rt.style.marginLeft = 'auto'; rt.style.textAlign = 'right';
            const tm = el('div', 'time', fmtTime(th.last_time)); rt.appendChild(tm);
            if ((th.unread || 0) > 0) rt.appendChild(el('div', 'badge', String(th.unread)));
            tx.appendChild(nm); tx.appendChild(lm);
            row.appendChild(av); row.appendChild(tx); row.appendChild(rt);
            row.addEventListener('click', () => openThread(th.student_id, th.fullname));
            threadsEl.appendChild(row);
          });
          [...threadsEl.children].forEach(ch => { ch.style.background = (Number(ch.dataset.sid) === Number(activeSid)) ? '#e2e8f0' : ''; });
        } catch (e) { console.error('threads err', e); }
      }

      async function openThread(studentId, fullname) {
        activeSid = studentId;
        lastMsgId = 0;
        msgList.innerHTML = '';
        const hdr = inbox.querySelector('.title');
        if (hdr) hdr.textContent = `RGO Inbox — ${fullname || ('Student #' + studentId)}`;
        if (pollMsgs) { clearInterval(pollMsgs); pollMsgs = null; }
        await fetchMsgs(true);
        pollMsgs = setInterval(fetchMsgs, POLL);
        [...threadsEl.children].forEach(ch => { ch.style.background = (Number(ch.dataset.sid) === Number(activeSid)) ? '#e2e8f0' : ''; });
      }

      async function fetchMsgs(initial = false) {
        if (!activeSid) return;
        try {
          const url = new URL(location.href);
          url.searchParams.set('action', 'chat_fetch_admin');
          url.searchParams.set('student_id', String(activeSid));
          url.searchParams.set('since_id', initial ? '0' : String(lastMsgId || 0));
          const res = await fetch(url.toString());
          const data = await res.json();
          if (!data.ok) return;

          (data.messages || []).forEach(m => {
            const sender = (m.sender === 'admin') ? 'admin' : 'student';
            addBubble(sender, m.message);
            if (!lastMsgId || m.id > lastMsgId) lastMsgId = m.id;
          });

          if (initial) scrollToBottom(); else if ((data.messages || []).length) scrollToBottom();
        } catch (e) { console.error('fetchMsgs err', e); }
      }

      async function sendMsg() {
        const text = (input.value || '').trim();
        if (!text || !activeSid) return;
        sendBtn.disabled = true;
        try {
          const fd = new FormData();
          fd.append('action', 'chat_send_admin');
          fd.append('student_id', String(activeSid));
          fd.append('message', text);
          const res = await fetch(location.pathname.split('/').pop(), { method: 'POST', body: fd });
          const data = await res.json();
          if (data.ok) {
            addBubble('admin', text);
            input.value = '';
            lastMsgId = Math.max(lastMsgId, data.message?.id || lastMsgId);
            scrollToBottom();
            refreshThreads();
          } else {
            alert(data.msg || 'Failed to send.');
          }
        } catch (e) {
          console.error('send err', e);
          alert('Network error.');
        } finally {
          sendBtn.disabled = false;
          input.focus();
        }
      }

      input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); sendMsg(); } });
      sendBtn.addEventListener('click', sendMsg);

      try { const q = new URLSearchParams(location.search); if (q.get('open') === 'inbox') openInbox(); } catch { }
    })();

    (function () {
      const inbox = document.querySelector('.rgo-inbox');
      const msgWrap = document.getElementById('rgoMsgList');
      if (!inbox || !msgWrap) return;

      function sizePane() {
        const msgs = msgWrap.closest('.rgo-msgs');
        if (!msgs) return;
        const rect = msgs.getBoundingClientRect();
        const input = msgs.querySelector('.rgo-input');
        const h = rect.height - (input ? input.getBoundingClientRect().height : 70);
        if (h > 120) msgWrap.style.maxHeight = h + 'px';
      }

      function scrollToBottom(force = false) {
        if (!msgWrap) return;
        const nearBottom = (msgWrap.scrollHeight - (msgWrap.scrollTop + msgWrap.clientHeight)) < 80;
        if (force || nearBottom) {
          msgWrap.scrollTo({ top: msgWrap.scrollHeight, behavior: 'smooth' });
        }
      }

      const obs = new MutationObserver(() => {
        if (getComputedStyle(inbox).display !== 'none') {
          sizePane();
          setTimeout(() => scrollToBottom(true), 60);
        }
      });
      obs.observe(inbox, { attributes: true, attributeFilter: ['style', 'class'] });

      window.addEventListener('resize', sizePane);

      const childObs = new MutationObserver(() => { sizePane(); scrollToBottom(false); });
      childObs.observe(msgWrap, { childList: true, subtree: false });

      msgWrap.addEventListener('wheel', (e) => {
        const atTop = msgWrap.scrollTop === 0 && e.deltaY < 0;
        const atBottom = Math.ceil(msgWrap.scrollTop + msgWrap.clientHeight) >= msgWrap.scrollHeight && e.deltaY > 0;
        if (atTop || atBottom) e.stopPropagation();
      }, { passive: true });

      sizePane();
      setTimeout(() => scrollToBottom(true), 100);
    })();

    (function () {
      const notifBtn = Array.from(document.querySelectorAll('.sidebar .sidebar-btn'))
        .find(b => /notifications/i.test(b?.textContent || ''));
      if (notifBtn) {
        notifBtn.dataset.rgoNotifs = '1';
        notifBtn.style.display = 'none';
      }

      const fab = document.createElement('button');
      fab.className = 'rgo-fab';
      fab.setAttribute('aria-label', 'Open chats');
      fab.innerHTML = '💬';
      fab.addEventListener('click', () => {
        if (notifBtn) notifBtn.click();
        else {
          const box = document.querySelector('.rgo-inbox');
          if (box) { box.style.display = 'block'; }
        }
      });
      document.body.appendChild(fab);

      const threads = document.getElementById('rgoThreads');

      function decorateThreads() {
        if (!threads) return;

        let head = threads.querySelector('.rgo-recents-head');
        if (!head) {
          head = document.createElement('div');
          head.className = 'rgo-recents-head';
          head.innerHTML = `
        <div class="rgo-search">
          <span style="font-size:14px;opacity:.7">🔎</span>
          <input id="rgoSearchInput" type="text" placeholder="Search chats">
        </div>
      `;
          threads.prepend(head);
        }

        let list = threads.querySelector('.rgo-thread-list');
        if (!list) {
          list = document.createElement('div');
          list.className = 'rgo-thread-list';
          Array.from(threads.querySelectorAll('.rgo-thread')).forEach(r => list.appendChild(r));
          head.insertAdjacentElement('afterend', list);
        } else {
          Array.from(threads.children).forEach(ch => {
            if (ch !== head && ch !== list) {
              if (ch.classList?.contains('rgo-thread')) list.appendChild(ch);
            }
          });
        }

        list.querySelectorAll('.rgo-thread').forEach(row => {
          const hasUnread = !!row.querySelector('.badge') && Number(row.querySelector('.badge')?.textContent || 0) > 0;
          row.classList.toggle('unread', hasUnread);
          if (hasUnread && !row.querySelector('.dot')) {
            const dot = document.createElement('div'); dot.className = 'dot';
            const rightCell = row.querySelector('.time')?.parentElement || row;
            rightCell.appendChild(dot);
          }
        });

        const input = head.querySelector('#rgoSearchInput');
        if (input && !input._wired) {
          input._wired = true;
          input.addEventListener('input', () => {
            const q = (input.value || '').toLowerCase().trim();
            list.querySelectorAll('.rgo-thread').forEach(row => {
              const text = row.textContent.toLowerCase();
              row.style.display = text.includes(q) ? '' : 'none';
            });
          });
        }
      }

      const obs = new MutationObserver(() => {
        requestAnimationFrame(decorateThreads);
      });
      if (threads) obs.observe(threads, { childList: true, subtree: false });

      const bodyObs = new MutationObserver(() => {
        const t = document.getElementById('rgoThreads');
        if (t && t !== threads) {
          obs.disconnect();
          setTimeout(() => {
            decorateThreads();
            obs.observe(t, { childList: true, subtree: false });
          }, 50);
          bodyObs.disconnect();
        }
      });
      bodyObs.observe(document.body, { childList: true, subtree: true });

      setTimeout(decorateThreads, 200);
    })();


    // Open Add Student/Admin Panels
    document.getElementById("openAddStudent").onclick = () => {
      document.getElementById("panelAddStudent").classList.add("show");
    };
    document.getElementById("openAddAdmin").onclick = () => {
      document.getElementById("panelAddAdmin").classList.add("show");
    };

    setInterval(() => {
      fetch("heartbeat.php");
    }, 30000);

    const signoutBtn = document.getElementById("signoutBtn");
    signoutBtn.addEventListener("click", () => {
      const c = confirm("Are you sure you want to sign out?");
      if (c) window.location.href = "logout_admin.php";
    });
  </script>


</body>

</html>
<?php $conn->close(); ?>
