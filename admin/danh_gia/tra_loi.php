<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../middleware/check_admin.php";
require_once "../../config/database.php";
require_once "../../includes/notification_helper.php";
require_once __DIR__ . "/ham_ho_tro.php";

$adminId =
    (int)($_SESSION['user']['id'] ?? 0);

$danhGiaId =
    (int)($_POST['danh_gia_id'] ?? 0);

$noiDung =
    trim(
        (string)(
            $_POST['noi_dung']
            ?? ''
        )
    );

$redirect =
    '/iPhoneStore/admin/danh_gia/index.php';

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    $adminId <= 0 ||
    $danhGiaId <= 0
) {
    header(
        'Location: ' .
        $redirect
    );
    exit;
}

try {
    if (!adgCoBangPhanHoiDanhGia($conn)) {
        throw new Exception(
            'Chưa có bảng phan_hoi_danh_gia. Hãy chạy file migration trước.'
        );
    }

    if ($noiDung === '') {
        throw new Exception(
            'Vui lòng nhập nội dung phản hồi.'
        );
    }

    if (
        mb_strlen(
            $noiDung,
            'UTF-8'
        ) > 2000
    ) {
        throw new Exception(
            'Phản hồi tối đa 2000 ký tự.'
        );
    }

    $meta =
        adgMeta($conn);

    $idColumn =
        $meta['id'];

    $userColumn =
        $meta['user'];

    if (
        $idColumn === null ||
        $userColumn === null
    ) {
        throw new Exception(
            'Bảng danh_gia chưa đủ cột để phản hồi.'
        );
    }

    $conn->begin_transaction();

    $stmtReview = $conn->prepare("
        SELECT
            dg.`$idColumn` AS id,
            dg.`$userColumn` AS nguoi_dung_id,
            dg.san_pham_id,
            sp.ten_san_pham

        FROM danh_gia dg

        JOIN san_pham sp
            ON sp.id = dg.san_pham_id

        WHERE dg.`$idColumn` = ?

        LIMIT 1

        FOR UPDATE
    ");

    if (!$stmtReview) {
        throw new Exception(
            'Không thể kiểm tra đánh giá.'
        );
    }

    $stmtReview->bind_param(
        "i",
        $danhGiaId
    );

    $stmtReview->execute();

    $review =
        $stmtReview
            ->get_result()
            ->fetch_assoc();

    if (!$review) {
        throw new Exception(
            'Đánh giá không tồn tại.'
        );
    }

    $stmtExisting = $conn->prepare("
        SELECT id
        FROM phan_hoi_danh_gia
        WHERE danh_gia_id = ?
        LIMIT 1
        FOR UPDATE
    ");

    if (!$stmtExisting) {
        throw new Exception(
            'Không thể kiểm tra phản hồi hiện tại.'
        );
    }

    $stmtExisting->bind_param(
        "i",
        $danhGiaId
    );

    $stmtExisting->execute();

    $existing =
        $stmtExisting
            ->get_result()
            ->fetch_assoc();

    $laCapNhat =
        (bool)$existing;

    if ($existing) {
        $stmtSave = $conn->prepare("
            UPDATE phan_hoi_danh_gia
            SET
                admin_id = ?,
                noi_dung = ?,
                ngay_cap_nhat = NOW()

            WHERE danh_gia_id = ?

            LIMIT 1
        ");

        if (!$stmtSave) {
            throw new Exception(
                'Không thể chuẩn bị cập nhật phản hồi.'
            );
        }

        $stmtSave->bind_param(
            "isi",
            $adminId,
            $noiDung,
            $danhGiaId
        );

        $stmtSave->execute();

    } else {
        $stmtSave = $conn->prepare("
            INSERT INTO phan_hoi_danh_gia (
                danh_gia_id,
                admin_id,
                noi_dung,
                ngay_tao,
                ngay_cap_nhat
            )
            VALUES (
                ?,
                ?,
                ?,
                NOW(),
                NULL
            )
        ");

        if (!$stmtSave) {
            throw new Exception(
                'Không thể chuẩn bị lưu phản hồi.'
            );
        }

        $stmtSave->bind_param(
            "iis",
            $danhGiaId,
            $adminId,
            $noiDung
        );

        $stmtSave->execute();
    }

    $conn->commit();

    /*
        Thông báo cho chính khách hàng đã đánh giá.
        Nếu thông báo lỗi thì phản hồi vẫn được lưu.
    */
    try {
        $nguoiDungId =
            (int)$review[
                'nguoi_dung_id'
            ];

        $sanPhamId =
            (int)$review[
                'san_pham_id'
            ];

        $tenSanPham =
            trim(
                (string)$review[
                    'ten_san_pham'
                ]
            );

        if (
            $nguoiDungId > 0 &&
            function_exists('taoThongBao')
        ) {
            taoThongBao(
                $conn,
                $nguoiDungId,
                $laCapNhat
                    ? 'Cửa hàng đã cập nhật phản hồi đánh giá'
                    : 'Cửa hàng đã phản hồi đánh giá của bạn',
                'iPhoneStore đã ' .
                    (
                        $laCapNhat
                            ? 'cập nhật phản hồi'
                            : 'phản hồi'
                    ) .
                    ' cho đánh giá của bạn về ' .
                    (
                        $tenSanPham !== ''
                            ? $tenSanPham
                            : 'sản phẩm'
                    ) .
                    '.',
                'phan_hoi_danh_gia',
                '/iPhoneStore/user/san_pham/chi_tiet.php?id=' .
                    $sanPhamId .
                    '#reviews',
                null
            );
        }
    } catch (Throwable $notificationError) {
        error_log(
            'iPhoneStore review reply notification error: ' .
            $notificationError->getMessage()
        );
    }

    header(
        'Location: ' .
        $redirect .
        '?reply_status=success'
    );
    exit;

} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
        // Không làm gì.
    }

    $_SESSION[
        'admin_review_reply_error'
    ] =
        $e->getMessage();

    header(
        'Location: ' .
        $redirect
    );
    exit;
}
