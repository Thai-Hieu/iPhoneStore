<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../middleware/check_user.php";
require_once "../../config/database.php";
require_once __DIR__ . "/ham_ho_tro.php";

$nguoiDungId =
    (int)($_SESSION['user']['id'] ?? 0);

$sanPhamId =
    (int)($_POST['san_pham_id'] ?? 0);

$danhGiaId =
    (int)($_POST['danh_gia_id'] ?? 0);

$redirectUrl =
    '/iPhoneStore/user/index.php';

if ($sanPhamId > 0) {
    $redirectUrl =
        '/iPhoneStore/user/san_pham/chi_tiet.php?id=' .
        $sanPhamId .
        '#reviews';
}

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    $nguoiDungId <= 0 ||
    $sanPhamId <= 0 ||
    $danhGiaId <= 0
) {
    header(
        'Location: ' .
        $redirectUrl
    );
    exit;
}

try {
    $csrf =
        (string)($_POST['csrf_token'] ?? '');

    $sessionCsrf =
        (string)(
            $_SESSION['review_csrf']
            ?? ''
        );

    if (
        $sessionCsrf === '' ||
        !hash_equals(
            $sessionCsrf,
            $csrf
        )
    ) {
        throw new Exception(
            'Phiên xóa đánh giá không hợp lệ. Vui lòng tải lại trang.'
        );
    }

    $meta =
        dgMeta($conn);

    $userColumn =
        $meta['user'];

    $idColumn =
        $meta['id'];

    if (
        $userColumn === null ||
        $idColumn === null
    ) {
        throw new Exception(
            'Bảng đánh giá chưa có cấu trúc phù hợp để xóa.'
        );
    }

    /*
        Chỉ cho phép xóa khi đồng thời đúng:
        - ID đánh giá
        - sản phẩm
        - user đang đăng nhập
    */
    $stmtReview =
        $conn->prepare("
            SELECT
                `$idColumn` AS review_id
            FROM danh_gia
            WHERE
                `$idColumn` = ?
                AND san_pham_id = ?
                AND `$userColumn` = ?
            LIMIT 1
        ");

    if (!$stmtReview) {
        throw new Exception(
            'Không thể kiểm tra quyền sở hữu đánh giá.'
        );
    }

    $stmtReview->bind_param(
        "iii",
        $danhGiaId,
        $sanPhamId,
        $nguoiDungId
    );

    $stmtReview->execute();

    $ownedReview =
        $stmtReview
            ->get_result()
            ->fetch_assoc();

    $stmtReview->close();

    if (!$ownedReview) {
        throw new Exception(
            'Không tìm thấy đánh giá hoặc bạn không có quyền xóa đánh giá này.'
        );
    }

    /*
        Lấy tên file ảnh trước khi xóa DB.
        Các record ảnh / phản hồi admin đã có FK ON DELETE CASCADE.
    */
    $images = [];

    if (dgCoBangHinhAnhDanhGia($conn)) {
        $stmtImages =
            $conn->prepare("
                SELECT hinh_anh
                FROM danh_gia_hinh_anh
                WHERE danh_gia_id = ?
            ");

        if ($stmtImages) {
            $stmtImages->bind_param(
                "i",
                $danhGiaId
            );

            $stmtImages->execute();

            $images =
                $stmtImages
                    ->get_result()
                    ->fetch_all(
                        MYSQLI_ASSOC
                    );

            $stmtImages->close();
        }
    }

    $conn->begin_transaction();

    try {
        $stmtDelete =
            $conn->prepare("
                DELETE FROM danh_gia
                WHERE
                    `$idColumn` = ?
                    AND san_pham_id = ?
                    AND `$userColumn` = ?
                LIMIT 1
            ");

        if (!$stmtDelete) {
            throw new Exception(
                'Không thể chuẩn bị xóa đánh giá.'
            );
        }

        $stmtDelete->bind_param(
            "iii",
            $danhGiaId,
            $sanPhamId,
            $nguoiDungId
        );

        $stmtDelete->execute();

        if ($stmtDelete->affected_rows !== 1) {
            $stmtDelete->close();

            throw new Exception(
                'Đánh giá không còn tồn tại hoặc đã được xóa trước đó.'
            );
        }

        $stmtDelete->close();
        $conn->commit();

    } catch (Throwable $transactionError) {
        $conn->rollback();
        throw $transactionError;
    }

    /*
        Xóa file ảnh vật lý sau khi DB đã commit.
        File không tồn tại thì bỏ qua.
    */
    $uploadDir =
        dirname(
            __DIR__,
            2
        ) .
        '/uploads/danh_gia';

    foreach ($images as $image) {
        $fileName =
            basename(
                (string)(
                    $image['hinh_anh']
                    ?? ''
                )
            );

        if ($fileName === '') {
            continue;
        }

        $fullPath =
            $uploadDir .
            DIRECTORY_SEPARATOR .
            $fileName;

        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    unset(
        $_SESSION['review_error']
    );

    header(
        'Location: /iPhoneStore/user/san_pham/chi_tiet.php?id=' .
        $sanPhamId .
        '&review=deleted#reviews'
    );
    exit;

} catch (Throwable $e) {
    $_SESSION['review_error'] =
        $e->getMessage();

    header(
        'Location: ' .
        $redirectUrl
    );
    exit;
}
