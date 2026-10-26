<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../middleware/check_user.php";
require_once "../../config/database.php";

$userId =
    (int)($_SESSION['user']['id'] ?? 0);

$notificationId =
    (int)($_GET['id'] ?? 0);

if (
    $userId <= 0 ||
    $notificationId <= 0
) {
    header(
        "Location: /iPhoneStore/user/thong_bao/index.php"
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
        $userId
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
        $userId
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

    /*
        Chỉ cho redirect nội bộ trong project,
        tránh open redirect.
    */
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
        "Location: /iPhoneStore/user/thong_bao/index.php"
    );
    exit;

} catch (Throwable $e) {
    $conn->rollback();

    header(
        "Location: /iPhoneStore/user/thong_bao/index.php"
    );
    exit;
}
