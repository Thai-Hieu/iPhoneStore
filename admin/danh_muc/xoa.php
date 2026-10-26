<?php
session_start();
require_once "../../middleware/check_admin.php";
require_once "../../config/database.php";

// Kiểm tra id
if (!isset($_GET['id'])) {
    header("Location: index.php");
    exit;
}

$id = (int)$_GET['id'];

// 1️⃣ Kiểm tra danh mục có sản phẩm không
$check = $conn->prepare(
    "SELECT COUNT(*) AS tong FROM san_pham WHERE danh_muc_id = ?"
);
$check->bind_param("i", $id);
$check->execute();
$result = $check->get_result()->fetch_assoc();

if ($result['tong'] > 0) {
    // Có sản phẩm → không cho xóa
    $_SESSION['error'] = "❌ Khong the xoa danh muc vi dang co san pham!";
    header("Location: index.php");
    exit;
}

// 2️⃣ Xóa danh mục
$stmt = $conn->prepare("DELETE FROM danh_muc WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();

$_SESSION['success'] = "✅ Xoa danh muc thanh cong!";
header("Location: index.php");
