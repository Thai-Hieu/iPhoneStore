<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../middleware/check_user.php";
require_once "../../config/database.php";
require_once "../../includes/khuyen_mai_helper.php";

/* =========================================================
   KIỂM TRA CỘT MÃ SẢN PHẨM

   Giỏ hàng vẫn liên kết sản phẩm bằng san_pham_id.
   Không cần thêm ma_san_pham vào bảng gio_hang.

   Nếu san_pham.ma_san_pham đã tồn tại thì lấy mã để:
   - đồng bộ dữ liệu phản hồi;
   - thuận tiện kiểm tra/debug;
   - không làm thay đổi cấu trúc giỏ hàng hiện tại.
========================================================= */
$resultMaSanPhamColumn = $conn->query("
    SHOW COLUMNS
    FROM san_pham
    LIKE 'ma_san_pham'
");

$coCotMaSanPham =
    $resultMaSanPhamColumn &&
    $resultMaSanPhamColumn->num_rows > 0;

$maSanPhamSelect = $coCotMaSanPham
    ? 'ma_san_pham,'
    : '';

header('Content-Type: application/json; charset=utf-8');

/* =========================================================
   XỬ LÝ THÊM VÀO GIỎ
========================================================= */
try {
    $nguoiDungId =
        (int)($_SESSION['user']['id'] ?? 0);

    $sanPhamId =
        (int)($_POST['san_pham_id'] ?? 0);

    $soLuong =
        (int)($_POST['so_luong'] ?? 1);

    $mauId =
        !empty($_POST['mau_id'])
            ? (int)$_POST['mau_id']
            : null;

    $bienTheId =
        !empty($_POST['bien_the_id'])
            ? (int)$_POST['bien_the_id']
            : null;

    /* =====================================================
       1. KIỂM TRA DỮ LIỆU ĐẦU VÀO
    ====================================================== */
    if ($nguoiDungId <= 0) {
        throw new Exception(
            "Bạn chưa đăng nhập"
        );
    }

    if ($sanPhamId <= 0) {
        throw new Exception(
            "ID sản phẩm không hợp lệ"
        );
    }

    if ($soLuong <= 0) {
        throw new Exception(
            "Số lượng không hợp lệ"
        );
    }

    if ($mauId === null || $mauId <= 0) {
        throw new Exception(
            "Vui lòng chọn màu sắc"
        );
    }

    if ($bienTheId === null || $bienTheId <= 0) {
        throw new Exception(
            "Vui lòng chọn phiên bản"
        );
    }

    /* =====================================================
       2. TRANSACTION
    ====================================================== */
    $conn->begin_transaction();

    try {
        /* =================================================
           3. LẤY VÀ KHÓA SẢN PHẨM
        ================================================= */
        $stmtSP = $conn->prepare("
            SELECT
                id,
                $maSanPhamSelect
                gia,
                so_luong,
                trang_thai
            FROM san_pham
            WHERE id = ?
            FOR UPDATE
        ");

        $stmtSP->bind_param(
            "i",
            $sanPhamId
        );

        $stmtSP->execute();

        $sanPham = $stmtSP
            ->get_result()
            ->fetch_assoc();

        if (!$sanPham) {
            throw new Exception(
                "Sản phẩm không tồn tại"
            );
        }

        if ((int)$sanPham['trang_thai'] !== 1) {
            throw new Exception(
                "Sản phẩm hiện không được bán"
            );
        }

        /*
            Mã sản phẩm chỉ để nhận diện/hiển thị.
            Giỏ hàng vẫn lưu san_pham_id như thiết kế ban đầu.
        */
        $maSanPham = trim(
            (string)($sanPham['ma_san_pham'] ?? '')
        );

        /* =================================================
           4. KIỂM TRA SẢN PHẨM CÓ BIẾN THỂ
        ================================================= */
        $stmtCountVariant = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM san_pham_bien_the
            WHERE san_pham_id = ?
        ");

        $stmtCountVariant->bind_param(
            "i",
            $sanPhamId
        );

        $stmtCountVariant->execute();

        $countData = $stmtCountVariant
            ->get_result()
            ->fetch_assoc();

        $tongBienThe =
            (int)($countData['total'] ?? 0);

        if ($tongBienThe <= 0) {
            throw new Exception(
                "Sản phẩm này chưa có biến thể để thêm vào giỏ"
            );
        }

        /* =================================================
           5. LẤY ĐÚNG BIẾN THỂ KHÁCH ĐÃ CHỌN

           BẮT BUỘC:
           - bien_the_id thuộc đúng sản phẩm;
           - mau_id trùng đúng màu của biến thể.

           Nhờ vậy không thể gửi:
           Màu Cam + bien_the_id của màu Trắng.
        ================================================= */
        $stmtBienThe = $conn->prepare("
            SELECT
                id,
                san_pham_id,
                mau_id,
                ram,
                rom,
                gia,
                so_luong
            FROM san_pham_bien_the
            WHERE id = ?
            AND san_pham_id = ?
            AND mau_id = ?
            FOR UPDATE
        ");

        $stmtBienThe->bind_param(
            "iii",
            $bienTheId,
            $sanPhamId,
            $mauId
        );

        $stmtBienThe->execute();

        $bienThe = $stmtBienThe
            ->get_result()
            ->fetch_assoc();

        if (!$bienThe) {
            throw new Exception(
                "Màu sắc và phiên bản không khớp"
            );
        }

        $giaGoc =
            (float)$bienThe['gia'];

        $tonKho =
            (int)$bienThe['so_luong'];

        /* =================================================
           6. TỒN KHO BIẾN THỂ
        ================================================= */
        /* =================================================
           8. TỒN KHO
        ================================================= */
        if ($tonKho <= 0) {
            throw new Exception(
                "Phiên bản này đã hết hàng"
            );
        }

        if ($soLuong > $tonKho) {
            throw new Exception(
                "Chỉ còn " .
                $tonKho .
                " sản phẩm"
            );
        }

        /* =================================================
           7. GIÁ KHUYẾN MÃI
           Sẽ tính sau khi biết tổng số lượng của biến thể
           đang có trong giỏ.
        ================================================= */
        $khuyenMai = null;
        $giaBan = $giaGoc;

        /* =================================================
           8. KHÓA CÁC DÒNG GIỎ CỦA SẢN PHẨM
        ================================================= */
        $stmtCartRows = $conn->prepare("
            SELECT
                id,
                so_luong,
                mau_id,
                bien_the_id
            FROM gio_hang
            WHERE nguoi_dung_id = ?
            AND san_pham_id = ?
            FOR UPDATE
        ");

        $stmtCartRows->bind_param(
            "ii",
            $nguoiDungId,
            $sanPhamId
        );

        $stmtCartRows->execute();

        $cartRows = $stmtCartRows
            ->get_result()
            ->fetch_all(MYSQLI_ASSOC);

        $cartRowExact = null;
        $tongTrongGioTheoTonKho = 0;

        foreach ($cartRows as $row) {
            $rowMauId =
                $row['mau_id'] !== null
                    ? (int)$row['mau_id']
                    : null;

            $rowBienTheId =
                $row['bien_the_id'] !== null
                    ? (int)$row['bien_the_id']
                    : null;

            /*
                Mỗi bien_the_id là một kho riêng.
                Chỉ cộng số lượng của đúng biến thể đó
                đang có trong giỏ.
            */
            if ($rowBienTheId === $bienTheId) {
                $tongTrongGioTheoTonKho +=
                    (int)$row['so_luong'];
            }

            $sameColor =
                $rowMauId === $mauId;

            $sameVariant =
                $rowBienTheId === $bienTheId;

            if ($sameColor && $sameVariant) {
                $cartRowExact = $row;
            }
        }

        /* =================================================
           9. KIỂM TRA TỔNG SỐ LƯỢNG TRONG GIỎ

           Ví dụ tồn kho biến thể = 10:
           trong giỏ đã có 8,
           thêm tiếp 3 -> từ chối.
        ================================================= */
        $tongSauKhiThem =
            $tongTrongGioTheoTonKho +
            $soLuong;

        if ($tongSauKhiThem > $tonKho) {
            throw new Exception(
                "Trong giỏ đã có " .
                $tongTrongGioTheoTonKho .
                " sản phẩm. Chỉ còn tối đa " .
                $tonKho .
                " sản phẩm cho lựa chọn này"
            );
        }

        /*
            Tính KM bằng tổng số lượng của đúng biến thể sau khi thêm.
            Nhờ vậy "mua từ 2 sản phẩm" cũng kích hoạt đúng khi:
            trong giỏ đã có 1 + thêm tiếp 1.
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
                        $tongSauKhiThem,
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

        /* =================================================
           10. ĐÃ CÓ ĐÚNG DÒNG TRONG GIỎ
        ================================================= */
        if ($cartRowExact) {
            $newQty =
                (int)$cartRowExact['so_luong'] +
                $soLuong;

            $cartId =
                (int)$cartRowExact['id'];

            $stmtUpdateCart = $conn->prepare("
                UPDATE gio_hang
                SET
                    so_luong = ?,
                    gia = ?
                WHERE id = ?
                AND nguoi_dung_id = ?
            ");

            $stmtUpdateCart->bind_param(
                "idii",
                $newQty,
                $giaBan,
                $cartId,
                $nguoiDungId
            );

            $stmtUpdateCart->execute();

        } else {
            /* =============================================
               11. THÊM DÒNG MỚI VÀO GIỎ
            ============================================= */
            $stmtInsertCart = $conn->prepare("
                INSERT INTO gio_hang (
                    nguoi_dung_id,
                    san_pham_id,
                    so_luong,
                    mau_id,
                    bien_the_id,
                    gia
                )
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $stmtInsertCart->bind_param(
                "iiiiid",
                $nguoiDungId,
                $sanPhamId,
                $soLuong,
                $mauId,
                $bienTheId,
                $giaBan
            );

            $stmtInsertCart->execute();
        }

        /* =================================================
           12. COMMIT
        ================================================= */
        $conn->commit();

        echo json_encode(
            [
                "success" => true,
                "message" => "Đã thêm vào giỏ hàng",

                /*
                    Mã sản phẩm được lấy từ bảng san_pham.
                    Không lưu lặp lại vào bảng gio_hang.
                */
                "ma_san_pham" =>
                    $maSanPham !== ''
                        ? $maSanPham
                        : null,

                /*
                    Các trường này không bắt buộc cho giao diện,
                    nhưng hữu ích khi test đồ án.
                */
                "gia_goc" => $giaGoc,
                "gia_ban" => $giaBan,
                "khuyen_mai" => $khuyenMai
                    ? [
                        "id" => (int)$khuyenMai['id'],
                        "ten" => $khuyenMai['ten_khuyen_mai'],
                        "ly_do" => $khuyenMai['ly_do'],
                        "kieu_giam" => $khuyenMai['kieu_giam'],
                        "gia_tri" => (float)$khuyenMai['gia_tri']
                    ]
                    : null
            ],
            JSON_UNESCAPED_UNICODE
        );

    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Throwable $e) {
    echo json_encode(
        [
            "success" => false,
            "error" => $e->getMessage()
        ],
        JSON_UNESCAPED_UNICODE
    );
}
