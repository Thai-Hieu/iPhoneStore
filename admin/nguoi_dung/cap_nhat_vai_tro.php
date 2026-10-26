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

$vaiTroMoi =
    trim((string)($_POST['vai_tro'] ?? ''));

$csrfToken =
    (string)($_POST['csrf_token'] ?? '');

if (
    empty($_SESSION['csrf_user_role_update']) ||
    $csrfToken === '' ||
    !hash_equals(
        (string)$_SESSION['csrf_user_role_update'],
        $csrfToken
    )
) {
    die("Phiên làm việc không hợp lệ.");
}

if ($targetId <= 0) {
    die("Người dùng không hợp lệ.");
}

if (!in_array($vaiTroMoi, ['user', 'admin'], true)) {
    die("Vai trò không hợp lệ.");
}

if ($targetId === $currentAdminId) {
    die("Bạn không thể tự thay đổi vai trò của chính tài khoản đang đăng nhập.");
}

$conn->begin_transaction();

try {
    /* =====================================================
       KHÓA TÀI KHOẢN ĐÍCH
    ===================================================== */
    $stmtUser = $conn->prepare("
        SELECT
            nd.id,
            vt.vai_tro
        FROM nguoi_dung nd
        JOIN vai_tro vt
            ON vt.id = nd.vai_tro_id
        WHERE nd.id = ?
        LIMIT 1
        FOR UPDATE
    ");

    if (!$stmtUser) {
        throw new Exception(
            "Không thể đọc tài khoản."
        );
    }

    $stmtUser->bind_param(
        "i",
        $targetId
    );

    $stmtUser->execute();

    $user = $stmtUser
        ->get_result()
        ->fetch_assoc();

    if (!$user) {
        throw new Exception(
            "Tài khoản không tồn tại."
        );
    }

    $vaiTroHienTai =
        (string)$user['vai_tro'];

    /* =====================================================
       KHÔNG HẠ QUYỀN ADMIN CUỐI CÙNG
    ===================================================== */
    if (
        $vaiTroHienTai === 'admin' &&
        $vaiTroMoi === 'user'
    ) {
        $countAdminResult = $conn->query("
            SELECT COUNT(*) AS total
            FROM nguoi_dung nd
            JOIN vai_tro vt
                ON vt.id = nd.vai_tro_id
            WHERE vt.vai_tro = 'admin'
        ");

        if (!$countAdminResult) {
            throw new Exception(
                "Không thể kiểm tra số lượng admin."
            );
        }

        $adminCount = (int)(
            $countAdminResult
                ->fetch_assoc()['total'] ?? 0
        );

        if ($adminCount <= 1) {
            throw new Exception(
                "Không thể hạ quyền admin cuối cùng của hệ thống."
            );
        }
    }

    /* =====================================================
       LẤY ID ROLE MỚI
    ===================================================== */
    $stmtRole = $conn->prepare("
        SELECT id
        FROM vai_tro
        WHERE vai_tro = ?
        LIMIT 1
    ");

    if (!$stmtRole) {
        throw new Exception(
            "Không thể đọc vai trò."
        );
    }

    $stmtRole->bind_param(
        "s",
        $vaiTroMoi
    );

    $stmtRole->execute();

    $role = $stmtRole
        ->get_result()
        ->fetch_assoc();

    if (!$role) {
        throw new Exception(
            "Vai trò không tồn tại trong bảng vai_tro."
        );
    }

    $vaiTroIdMoi =
        (int)$role['id'];

    /* =====================================================
       CẬP NHẬT
    ===================================================== */
    $stmtUpdate = $conn->prepare("
        UPDATE nguoi_dung
        SET vai_tro_id = ?
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmtUpdate) {
        throw new Exception(
            "Không thể cập nhật vai trò."
        );
    }

    $stmtUpdate->bind_param(
        "ii",
        $vaiTroIdMoi,
        $targetId
    );

    $stmtUpdate->execute();

    $conn->commit();

    $_SESSION['csrf_user_role_update'] =
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
        "Không thể cập nhật vai trò: " .
        htmlspecialchars(
            $e->getMessage(),
            ENT_QUOTES,
            "UTF-8"
        )
    );
}
