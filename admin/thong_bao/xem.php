<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../middleware/check_admin.php";
require_once "../../config/database.php";

$adminId =
    (int)($_SESSION['user']['id'] ?? 0);

$notificationId =
    (int)($_GET['id'] ?? 0);

if (
    $adminId <= 0 ||
    $notificationId <= 0
) {
    header(
        "Location: /iPhoneStore/admin/thong_bao/index.php"
    );
    exit;
}

$conn->begin_transaction();

try {
    $stmt = $conn->prepare("
        SELECT
            id,
            lien_ket
        FROM thong_bao
        WHERE id = ?
        AND nguoi_dung_id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->bind_param(
        "ii",
        $notificationId,
        $adminId
    );

    $stmt->execute();

    $notification =
        $stmt
            ->get_result()
            ->fetch_assoc();

    if (!$notification) {
        throw new Exception(
            "Thông báo không tồn tại."
        );
    }

    $stmtUpdate = $conn->prepare("
        UPDATE thong_bao
        SET
            da_doc = 1,
            ngay_doc = COALESCE(
                ngay_doc,
                NOW()
            )
        WHERE id = ?
        AND nguoi_dung_id = ?
        LIMIT 1
    ");

    $stmtUpdate->bind_param(
        "ii",
        $notificationId,
        $adminId
    );

    $stmtUpdate->execute();

    $conn->commit();

    $link =
        trim(
            (string)(
                $notification['lien_ket']
                ?? ''
            )
        );

    if (
        $link !== '' &&
        str_starts_with(
            $link,
            '/iPhoneStore/'
        )
    ) {
        header(
            "Location: " . $link
        );
        exit;
    }

    header(
        "Location: /iPhoneStore/admin/thong_bao/index.php"
    );
    exit;

} catch (Throwable $e) {
    $conn->rollback();

    header(
        "Location: /iPhoneStore/admin/thong_bao/index.php"
    );
    exit;
}
