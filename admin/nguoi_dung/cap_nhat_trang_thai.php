<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../middleware/check_admin.php";
require_once "../../config/database.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("Phương thức không hợp lệ.");
}

$currentAdminId =
    (int)($_SESSION['user']['id'] ?? 0);

$targetId =
    (int)($_POST['id'] ?? 0);

$trangThaiMoi =
    (int)($_POST['trang_thai'] ?? -1);

$csrfToken =
    (string)($_POST['csrf_token'] ?? '');

if (
    empty($_SESSION['csrf_user_status_update']) ||
    $csrfToken === '' ||
    !hash_equals(
        (string)$_SESSION['csrf_user_status_update'],
        $csrfToken
    )
) {
    die("Phiên làm việc không hợp lệ.");
}

if ($targetId <= 0) {
    die("Người dùng không hợp lệ.");
}

if (!in_array($trangThaiMoi, [0, 1], true)) {
    die("Trạng thái không hợp lệ.");
}

if ($targetId === $currentAdminId) {
    die("Bạn không thể tự khóa hoặc mở khóa chính tài khoản đang đăng nhập.");
}

$conn->begin_transaction();

try {
    $stmt = $conn->prepare("
        SELECT
            id,
            trang_thai
        FROM nguoi_dung
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");

    if (!$stmt) {
        throw new Exception(
            "Không thể đọc tài khoản."
        );
    }

    $stmt->bind_param(
        "i",
        $targetId
    );

    $stmt->execute();

    $user = $stmt
        ->get_result()
        ->fetch_assoc();

    if (!$user) {
        throw new Exception(
            "Tài khoản không tồn tại."
        );
    }

    $stmtUpdate = $conn->prepare("
        UPDATE nguoi_dung
        SET trang_thai = ?
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmtUpdate) {
        throw new Exception(
            "Không thể cập nhật trạng thái."
        );
    }

    $stmtUpdate->bind_param(
        "ii",
        $trangThaiMoi,
        $targetId
    );

    $stmtUpdate->execute();

    $conn->commit();

    $_SESSION['csrf_user_status_update'] =
        bin2hex(random_bytes(32));

    header(
        "Location: chi_tiet.php?id=" .
        $targetId .
        "&updated=1"
    );

    exit;

} catch (Throwable $e) {
    $conn->rollback();

    die(
        "Không thể cập nhật trạng thái: " .
        htmlspecialchars(
            $e->getMessage(),
            ENT_QUOTES,
            "UTF-8"
        )
    );
}
