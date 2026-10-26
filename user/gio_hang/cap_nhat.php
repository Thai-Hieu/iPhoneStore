<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../middleware/check_user.php";
require_once "../../config/database.php";
require_once "../../includes/khuyen_mai_helper.php";

/* =========================================================
   DỮ LIỆU ĐẦU VÀO
========================================================= */
$gioHangId = (int)($_GET['id'] ?? 0);
$type = trim((string)($_GET['type'] ?? ''));
$nguoiDungId = (int)($_SESSION['user']['id'] ?? 0);

if (
    $nguoiDungId <= 0 ||
    $gioHangId <= 0 ||
    !in_array(
        $type,
        ['plus', 'minus'],
        true
    )
) {
    header("Location: index.php");
    exit;
}

/* =========================================================
   TRANSACTION
========================================================= */
$conn->begin_transaction();

try {
    /* =====================================================
       1. LẤY DÒNG GIỎ HÀNG VÀ KHÓA

       Giỏ hàng vẫn liên kết bằng san_pham_id.
       Không cần lưu ma_san_pham lặp lại trong gio_hang.
    ===================================================== */
    $stmtCart = $conn->prepare("
        SELECT
            id,
            san_pham_id,
            so_luong,
            mau_id,
            bien_the_id,
            gia AS gia_luu_trong_gio
        FROM gio_hang
        WHERE id = ?
        AND nguoi_dung_id = ?
        FOR UPDATE
    ");

    if (!$stmtCart) {
        throw new Exception(
            "Không thể đọc giỏ hàng: " .
            $conn->error
        );
    }

    $stmtCart->bind_param(
        "ii",
        $gioHangId,
        $nguoiDungId
    );

    $stmtCart->execute();

    $cartItem = $stmtCart
        ->get_result()
        ->fetch_assoc();

    if (!$cartItem) {
        throw new Exception(
            "Sản phẩm trong giỏ không tồn tại"
        );
    }

    $sanPhamId = (int)$cartItem['san_pham_id'];
    $soLuongHienTai = (int)$cartItem['so_luong'];

    $mauId =
        $cartItem['mau_id'] !== null
            ? (int)$cartItem['mau_id']
            : null;

    $bienTheId =
        $cartItem['bien_the_id'] !== null
            ? (int)$cartItem['bien_the_id']
            : null;

    $giaDaLuu =
        (float)($cartItem['gia_luu_trong_gio'] ?? 0);

    /* =====================================================
       2. LẤY SẢN PHẨM VÀ KHÓA

       ma_san_pham không cần dùng để UPDATE giỏ hàng.
       san_pham_id vẫn là khóa liên kết chính xác.
    ===================================================== */
    $stmtProduct = $conn->prepare("
        SELECT
            id,
            trang_thai
        FROM san_pham
        WHERE id = ?
        FOR UPDATE
    ");

    if (!$stmtProduct) {
        throw new Exception(
            "Không thể đọc sản phẩm: " .
            $conn->error
        );
    }

    $stmtProduct->bind_param(
        "i",
        $sanPhamId
    );

    $stmtProduct->execute();

    $sanPham = $stmtProduct
        ->get_result()
        ->fetch_assoc();

    if (!$sanPham) {
        throw new Exception(
            "Sản phẩm không tồn tại"
        );
    }

    /* =====================================================
       3. KIỂM TRA ĐÚNG BIẾN THỂ TRONG GIỎ

       iPhoneStore hiện dùng sản phẩm bắt buộc có biến thể.
       Mỗi dòng giỏ phải có:
       - mau_id
       - bien_the_id
       và biến thể phải thuộc đúng sản phẩm + đúng màu.

       Nếu dữ liệu cũ không còn hợp lệ:
       - vẫn cho phép bấm "minus" để giảm số lượng;
       - không cho phép bấm "plus".
    ===================================================== */
    $duLieuHopLe = true;
    $tonKho = 0;
    $tongTrongGioCungKho = 0;

    /*
        Nếu biến thể đã bị xóa / dữ liệu giỏ legacy bị thiếu,
        giữ giá đã lưu trong giỏ để thao tác giảm số lượng
        không làm sai giá lịch sử của dòng giỏ.
    */
    $giaGoc = $giaDaLuu;
    $giaBan = $giaDaLuu;
    $khuyenMai = null;

    if (
        $bienTheId === null ||
        $bienTheId <= 0 ||
        $mauId === null ||
        $mauId <= 0
    ) {
        $duLieuHopLe = false;
    } else {
        $stmtVariant = $conn->prepare("
            SELECT
                id,
                mau_id,
                gia,
                so_luong
            FROM san_pham_bien_the
            WHERE id = ?
            AND san_pham_id = ?
            AND mau_id = ?
            FOR UPDATE
        ");

        if (!$stmtVariant) {
            throw new Exception(
                "Không thể đọc biến thể: " .
                $conn->error
            );
        }

        $stmtVariant->bind_param(
            "iii",
            $bienTheId,
            $sanPhamId,
            $mauId
        );

        $stmtVariant->execute();

        $variant = $stmtVariant
            ->get_result()
            ->fetch_assoc();

        if (!$variant) {
            $duLieuHopLe = false;
        } else {
            $giaGoc =
                (float)$variant['gia'];

            $tonKho =
                (int)$variant['so_luong'];

            /*
                Một bien_the_id là một kho riêng.
                Cộng toàn bộ số lượng của chính biến thể đó
                trong giỏ của user.
            */
            $stmtVariantCartTotal = $conn->prepare("
                SELECT
                    COALESCE(
                        SUM(so_luong),
                        0
                    ) AS total
                FROM gio_hang
                WHERE nguoi_dung_id = ?
                AND san_pham_id = ?
                AND bien_the_id = ?
            ");

            if (!$stmtVariantCartTotal) {
                throw new Exception(
                    "Không thể kiểm tra số lượng trong giỏ: " .
                    $conn->error
                );
            }

            $stmtVariantCartTotal->bind_param(
                "iii",
                $nguoiDungId,
                $sanPhamId,
                $bienTheId
            );

            $stmtVariantCartTotal->execute();

            $variantCartData =
                $stmtVariantCartTotal
                    ->get_result()
                    ->fetch_assoc();

            $tongTrongGioCungKho =
                (int)($variantCartData['total'] ?? 0);

            /* =============================================
               4. GIÁ KHUYẾN MÃI
               Tính theo số lượng mục tiêu ở từng thao tác
               plus / minus bên dưới.
            ============================================= */
            $khuyenMai = null;
            $giaBan = $giaGoc;
        }
    }

    /* =====================================================
       5. GIẢM SỐ LƯỢNG

       Cho phép giảm kể cả khi:
       - sản phẩm hết hàng;
       - sản phẩm ngừng bán;
       - biến thể cũ không còn hợp lệ.

       Nếu số lượng đang là 1 thì giữ nguyên 1.
       Xóa sản phẩm dùng file xoa.php riêng.
    ===================================================== */
    if ($type === 'minus') {
        if ($soLuongHienTai > 1) {
            $soLuongMoi = $soLuongHienTai - 1;

            if ($duLieuHopLe) {
                $khuyenMai =
                    layKhuyenMaiHienTai(
                        $conn,
                        $sanPhamId,
                        $bienTheId,
                        [
                            'gia_goc' =>
                                $giaGoc,
                            'so_luong' =>
                                $soLuongMoi,
                            'ton_kho' =>
                                $tonKho,
                            'nguoi_dung_id' =>
                                $nguoiDungId
                        ]
                    );

                $giaBan =
                    tinhGiaSauKhuyenMai(
                        $giaGoc,
                        $khuyenMai
                    );
            }

            $stmtUpdate = $conn->prepare("
                UPDATE gio_hang
                SET
                    so_luong = ?,
                    gia = ?
                WHERE id = ?
                AND nguoi_dung_id = ?
            ");

            if (!$stmtUpdate) {
                throw new Exception(
                    "Không thể cập nhật giỏ hàng: " .
                    $conn->error
                );
            }

            $stmtUpdate->bind_param(
                "idii",
                $soLuongMoi,
                $giaBan,
                $gioHangId,
                $nguoiDungId
            );

            $stmtUpdate->execute();
        } else {
            /*
                Dù không giảm dưới 1, vẫn làm mới giá hiện tại.
            */
            if ($duLieuHopLe) {
                $khuyenMai =
                    layKhuyenMaiHienTai(
                        $conn,
                        $sanPhamId,
                        $bienTheId,
                        [
                            'gia_goc' =>
                                $giaGoc,
                            'so_luong' =>
                                1,
                            'ton_kho' =>
                                $tonKho,
                            'nguoi_dung_id' =>
                                $nguoiDungId
                        ]
                    );

                $giaBan =
                    tinhGiaSauKhuyenMai(
                        $giaGoc,
                        $khuyenMai
                    );
            }

            $stmtRefreshPrice = $conn->prepare("
                UPDATE gio_hang
                SET gia = ?
                WHERE id = ?
                AND nguoi_dung_id = ?
            ");

            if (!$stmtRefreshPrice) {
                throw new Exception(
                    "Không thể làm mới giá giỏ hàng: " .
                    $conn->error
                );
            }

            $stmtRefreshPrice->bind_param(
                "dii",
                $giaBan,
                $gioHangId,
                $nguoiDungId
            );

            $stmtRefreshPrice->execute();
        }

        $conn->commit();

        header("Location: index.php");
        exit;
    }

    /* =====================================================
       6. TĂNG SỐ LƯỢNG

       Chỉ cho tăng khi:
       - sản phẩm còn bán;
       - màu/biến thể hợp lệ;
       - còn tồn;
       - tổng trong giỏ chưa vượt tồn kho.
    ===================================================== */
    if ((int)$sanPham['trang_thai'] !== 1) {
        throw new Exception(
            "Sản phẩm hiện không được bán"
        );
    }

    if (!$duLieuHopLe) {
        throw new Exception(
            "Màu hoặc phiên bản trong giỏ không còn hợp lệ"
        );
    }

    if ($tonKho <= 0) {
        throw new Exception(
            "Phiên bản này đã hết hàng"
        );
    }

    if ($tongTrongGioCungKho >= $tonKho) {
        /*
            Đã chạm tồn kho:
            không tăng số lượng nhưng vẫn làm mới giá hiện tại.
        */
        $khuyenMai =
            layKhuyenMaiHienTai(
                $conn,
                $sanPhamId,
                $bienTheId,
                [
                    'gia_goc' =>
                        $giaGoc,
                    'so_luong' =>
                        $soLuongHienTai,
                    'ton_kho' =>
                        $tonKho,
                    'nguoi_dung_id' =>
                        $nguoiDungId
                ]
            );

        $giaBan =
            tinhGiaSauKhuyenMai(
                $giaGoc,
                $khuyenMai
            );

        $stmtRefreshPrice = $conn->prepare("
            UPDATE gio_hang
            SET gia = ?
            WHERE id = ?
            AND nguoi_dung_id = ?
        ");

        if (!$stmtRefreshPrice) {
            throw new Exception(
                "Không thể làm mới giá giỏ hàng: " .
                $conn->error
            );
        }

        $stmtRefreshPrice->bind_param(
            "dii",
            $giaBan,
            $gioHangId,
            $nguoiDungId
        );

        $stmtRefreshPrice->execute();

        $conn->commit();

        header(
            "Location: index.php?notice=max_stock"
        );

        exit;
    }

    $soLuongMoi =
        $soLuongHienTai + 1;

    $khuyenMai =
        layKhuyenMaiHienTai(
            $conn,
            $sanPhamId,
            $bienTheId,
            [
                'gia_goc' =>
                    $giaGoc,
                'so_luong' =>
                    $soLuongMoi,
                'ton_kho' =>
                    $tonKho,
                'nguoi_dung_id' =>
                    $nguoiDungId
            ]
        );

    $giaBan =
        tinhGiaSauKhuyenMai(
            $giaGoc,
            $khuyenMai
        );

    $stmtUpdate = $conn->prepare("
        UPDATE gio_hang
        SET
            so_luong = ?,
            gia = ?
        WHERE id = ?
        AND nguoi_dung_id = ?
    ");

    if (!$stmtUpdate) {
        throw new Exception(
            "Không thể cập nhật giỏ hàng: " .
            $conn->error
        );
    }

    $stmtUpdate->bind_param(
        "idii",
        $soLuongMoi,
        $giaBan,
        $gioHangId,
        $nguoiDungId
    );

    $stmtUpdate->execute();

    $conn->commit();

    header("Location: index.php");
    exit;

} catch (Throwable $e) {
    $conn->rollback();

    /*
        File cap_nhat.php dùng link +/- nên redirect về index
        thay vì in lỗi thẳng ra màn hình.
    */
    $message = urlencode(
        $e->getMessage()
    );

    header(
        "Location: index.php?error=" .
        $message
    );

    exit;
}
?>
