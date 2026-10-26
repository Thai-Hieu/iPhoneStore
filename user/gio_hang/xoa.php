<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../middleware/check_user.php";
require_once "../../config/database.php";

/* =========================================================
   DỮ LIỆU ĐẦU VÀO
========================================================= */
$gioHangId = (int)($_GET['id'] ?? 0);
$nguoiDungId = (int)($_SESSION['user']['id'] ?? 0);

if ($nguoiDungId <= 0) {
    header("Location: index.php?error=" . urlencode("Bạn chưa đăng nhập"));
    exit;
}

if ($gioHangId <= 0) {
    header("Location: index.php?error=" . urlencode("Sản phẩm trong giỏ không hợp lệ"));
    exit;
}

/* =========================================================
   XÓA AN TOÀN

   Quan trọng:
   - Chỉ được xóa dòng giỏ hàng thuộc chính user đang đăng nhập.
   - Không cần xử lý ma_san_pham vì gio_hang liên kết bằng
     san_pham_id.
========================================================= */
$stmt = $conn->prepare("
    DELETE FROM gio_hang
    WHERE id = ?
    AND nguoi_dung_id = ?
");

if (!$stmt) {
    header(
        "Location: index.php?error=" .
        urlencode("Không thể chuẩn bị thao tác xóa giỏ hàng")
    );
    exit;
}

$stmt->bind_param(
    "ii",
    $gioHangId,
    $nguoiDungId
);

$stmt->execute();

if ($stmt->affected_rows > 0) {
    header("Location: index.php?deleted=1");
    exit;
}

/*
    Không tìm thấy dòng tương ứng hoặc dòng đó không thuộc user.
    Không tiết lộ chi tiết quyền sở hữu để tránh lộ thông tin.
*/
header(
    "Location: index.php?error=" .
    urlencode("Không tìm thấy sản phẩm cần xóa trong giỏ hàng")
);

exit;
?>
