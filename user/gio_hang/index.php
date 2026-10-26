<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../middleware/check_user.php";
require_once "../../config/database.php";
require_once "../../includes/khuyen_mai_helper.php";

/* =========================================================
   HÌNH ẢNH BIẾN THỂ

   Tự nhận diện tên cột ảnh đang dùng trong DB:
   - hinh_anh
   - duong_dan_anh
========================================================= */
if (!function_exists('layCotAnhBienThe')) {
    function layCotAnhBienThe(
        mysqli $conn
    ): ?string {
        foreach (
            [
                'hinh_anh',
                'duong_dan_anh'
            ]
            as $columnName
        ) {
            $stmt = $conn->prepare("
                SELECT 1
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME =
                    'san_pham_bien_the_hinh_anh'
                AND COLUMN_NAME = ?
                LIMIT 1
            ");

            if (!$stmt) {
                continue;
            }

            $stmt->bind_param(
                's',
                $columnName
            );

            $stmt->execute();

            $exists =
                $stmt
                    ->get_result()
                    ->fetch_assoc();

            $stmt->close();

            if ($exists) {
                return $columnName;
            }
        }

        return null;
    }
}

/* =========================================================
   USER
========================================================= */
$nguoiDungId =
    (int)($_SESSION['user']['id'] ?? 0);

if ($nguoiDungId <= 0) {
    die("Bạn chưa đăng nhập");
}

/* =========================================================
   MUA NGAY → TỰ CHECK ĐÚNG DÒNG TRONG GIỎ

   Chỉ dùng để chọn sẵn checkbox khi đi từ trang chi tiết.
   Không thay đổi dữ liệu giỏ hàng, giá, tồn kho hay thanh toán.
========================================================= */
$buyNowMode =
    (string)($_GET['buy_now'] ?? '') === '1';

$buyNowSanPhamId =
    (int)($_GET['san_pham_id'] ?? 0);

$buyNowMauIdRaw =
    trim((string)($_GET['mau_id'] ?? ''));

$buyNowMauId =
    $buyNowMauIdRaw !== ''
        ? (int)$buyNowMauIdRaw
        : null;

$buyNowBienTheIdRaw =
    trim((string)($_GET['bien_the_id'] ?? ''));

$buyNowBienTheId =
    $buyNowBienTheIdRaw !== ''
        ? (int)$buyNowBienTheIdRaw
        : null;

/*
    Ảnh hiển thị trong giỏ:
    - ưu tiên ảnh chính của bien_the_id đã chọn;
    - nếu biến thể chưa có ảnh riêng thì dùng sp.hinh_anh.
*/
$cotAnhBienThe =
    layCotAnhBienThe($conn);

if ($cotAnhBienThe !== null) {
    $sqlAnhBienThe = "
        (
            SELECT
                ha.`{$cotAnhBienThe}`

            FROM san_pham_bien_the_hinh_anh ha

            WHERE ha.bien_the_id =
                gh.bien_the_id

            ORDER BY
                ha.la_anh_chinh DESC,
                ha.thu_tu ASC,
                ha.id ASC

            LIMIT 1
        ) AS hinh_anh_bien_the,
    ";
} else {
    $sqlAnhBienThe = "
        NULL AS hinh_anh_bien_the,
    ";
}

/* =========================================================
   LẤY TOÀN BỘ GIỎ HÀNG

   JOIN biến thể có thêm điều kiện mau_id để đảm bảo
   biến thể trong giỏ đúng với màu đã chọn.
========================================================= */
$stmtCart = $conn->prepare("
    SELECT
        gh.id AS gio_hang_id,
        gh.san_pham_id,
        gh.so_luong,
        gh.mau_id,
        gh.bien_the_id,
        gh.gia AS gia_luu_trong_gio,
        sp.ten_san_pham,

        {$sqlAnhBienThe}

        sp.hinh_anh AS hinh_anh_san_pham,
        sp.gia AS gia_san_pham,
        sp.so_luong AS ton_kho_san_pham,
        sp.trang_thai AS trang_thai_san_pham,

        ms.ten_mau,
        ms.ma_mau,

        spm.mau_id AS mau_san_pham_hop_le,

        bt.id AS bien_the_hop_le,
        bt.mau_id AS mau_id_bien_the,
        bt.ram,
        bt.rom,
        bt.gia AS gia_bien_the,
        bt.so_luong AS ton_kho_bien_the,

        (
            SELECT COUNT(*)
            FROM san_pham_bien_the bt2
            WHERE bt2.san_pham_id = sp.id
        ) AS tong_bien_the,

        (
            SELECT COUNT(*)
            FROM san_pham_mau spm2
            WHERE spm2.san_pham_id = sp.id
        ) AS tong_mau

    FROM gio_hang gh

    JOIN san_pham sp
        ON sp.id = gh.san_pham_id

    LEFT JOIN mau_sac ms
        ON ms.id = gh.mau_id

    LEFT JOIN san_pham_mau spm
        ON spm.san_pham_id = gh.san_pham_id
        AND spm.mau_id = gh.mau_id

    LEFT JOIN san_pham_bien_the bt
        ON bt.id = gh.bien_the_id
        AND bt.san_pham_id = gh.san_pham_id
        AND (bt.mau_id <=> gh.mau_id)

    WHERE gh.nguoi_dung_id = ?

    ORDER BY gh.id DESC
");

$stmtCart->bind_param(
    "i",
    $nguoiDungId
);

$stmtCart->execute();

$cartRows = $stmtCart
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

/* =========================================================
   TÍNH TỔNG SỐ LƯỢNG ĐANG CHIẾM TỒN KHO

   Có biến thể:
   mỗi bien_the_id là một kho riêng.

   Không biến thể:
   mọi màu của cùng sản phẩm dùng chung sp.so_luong.
========================================================= */
$variantCartTotals = [];
$productCartTotals = [];

foreach ($cartRows as $row) {
    $soLuong =
        (int)$row['so_luong'];

    $tongBienThe =
        (int)$row['tong_bien_the'];

    $bienTheId =
        $row['bien_the_id'] !== null
            ? (int)$row['bien_the_id']
            : null;

    if (
        $tongBienThe > 0 &&
        $bienTheId !== null
    ) {
        if (!isset($variantCartTotals[$bienTheId])) {
            $variantCartTotals[$bienTheId] = 0;
        }

        $variantCartTotals[$bienTheId] +=
            $soLuong;
    } else {
        $sanPhamId =
            (int)$row['san_pham_id'];

        if (!isset($productCartTotals[$sanPhamId])) {
            $productCartTotals[$sanPhamId] = 0;
        }

        $productCartTotals[$sanPhamId] +=
            $soLuong;
    }
}

/* =========================================================
   CHUẨN HÓA DỮ LIỆU HIỂN THỊ
========================================================= */
$tongGiaGocGioHang = 0;
foreach ($cartRows as $grossRow) {
    $grossPrice = $grossRow['gia_bien_the'] !== null ? (float)$grossRow['gia_bien_the'] : (float)$grossRow['gia_san_pham'];
    $tongGiaGocGioHang += $grossPrice * max(1,(int)$grossRow['so_luong']);
}
$items = [];

foreach ($cartRows as $row) {
    $sanPhamId =
        (int)$row['san_pham_id'];

    $gioHangId =
        (int)$row['gio_hang_id'];

    $soLuong =
        (int)$row['so_luong'];

    $mauId =
        $row['mau_id'] !== null
            ? (int)$row['mau_id']
            : null;

    $bienTheId =
        $row['bien_the_id'] !== null
            ? (int)$row['bien_the_id']
            : null;

    $tongBienThe =
        (int)$row['tong_bien_the'];

    $tongMau =
        (int)$row['tong_mau'];

    $coBienThe =
        $tongBienThe > 0;

    $coMau =
        $tongMau > 0;

    /* -----------------------------------------------------
       KIỂM TRA DỮ LIỆU GIỎ CÓ CÒN HỢP LỆ KHÔNG
    ----------------------------------------------------- */
    $bienTheKhongHopLe = false;
    $mauKhongHopLe = false;

    if ($coBienThe) {
        /*
            Sản phẩm có biến thể thì giỏ bắt buộc
            phải có bien_the_id và JOIN được đúng
            biến thể + màu.
        */
        if (
            $bienTheId === null ||
            empty($row['bien_the_hop_le'])
        ) {
            $bienTheKhongHopLe = true;
        }
    } else {
        /*
            Sản phẩm không có biến thể nhưng có màu:
            màu trong giỏ phải còn thuộc sản phẩm.
        */
        if (
            $coMau &&
            (
                $mauId === null ||
                $row['mau_san_pham_hop_le'] === null
            )
        ) {
            $mauKhongHopLe = true;
        }
    }

    /* -----------------------------------------------------
       GIÁ GỐC + TỒN KHO ĐÚNG
    ----------------------------------------------------- */
    if (
        $coBienThe &&
        !$bienTheKhongHopLe
    ) {
        $giaGoc =
            (float)$row['gia_bien_the'];

        $tonKho =
            (int)$row['ton_kho_bien_the'];

        $tongTrongGioCungKho =
            $variantCartTotals[$bienTheId] ?? $soLuong;
    } else {
        $giaGoc =
            (float)$row['gia_san_pham'];

        $tonKho =
            (int)$row['ton_kho_san_pham'];

        $tongTrongGioCungKho =
            $productCartTotals[$sanPhamId] ?? $soLuong;
    }

    /* -----------------------------------------------------
       KHUYẾN MÃI HIỆN TẠI
    ----------------------------------------------------- */
    $khuyenMai = null;

    if (
        !$bienTheKhongHopLe &&
        !$mauKhongHopLe
    ) {
        $khuyenMai =
            layKhuyenMaiHienTai(
                $conn,
                $sanPhamId,
                $coBienThe ? $bienTheId : null,
                [
                    'gia_goc' => $giaGoc,
                    'so_luong' => $soLuong,
                    'tong_don_goc' => $tongGiaGocGioHang,
                    'ton_kho' => $tonKho,
                    'nguoi_dung_id' => $nguoiDungId
                ]
            );
    }

    $giaHienTai =
        tinhGiaSauKhuyenMai(
            $giaGoc,
            $khuyenMai
        );

    $giaDaLuu =
        (float)$row['gia_luu_trong_gio'];

    $giaDaThayDoi =
        abs($giaDaLuu - $giaHienTai) >= 0.01;

    /* -----------------------------------------------------
       TRẠNG THÁI TỒN KHO
    ----------------------------------------------------- */
    $sanPhamDangBan =
        (int)$row['trang_thai_san_pham'] === 1;

    $hetHang =
        $tonKho <= 0;

    /*
        Với sản phẩm không biến thể có nhiều màu,
        phải so tổng các màu trong giỏ với kho chung.
    */
    $vuotTonKho =
        $tongTrongGioCungKho > $tonKho;

    $datMax =
        $tongTrongGioCungKho >= $tonKho;

    $sapHet =
        $tonKho > 0 &&
        $tonKho <= 2;

    $hopLeThanhToan =
        $sanPhamDangBan &&
        !$bienTheKhongHopLe &&
        !$mauKhongHopLe &&
        !$hetHang &&
        !$vuotTonKho;

    $thanhTien =
        $giaHienTai * $soLuong;

    $hinhAnhBienThe =
        trim(
            (string)(
                $row['hinh_anh_bien_the']
                ?? ''
            )
        );

    $hinhAnhSanPham =
        trim(
            (string)(
                $row['hinh_anh_san_pham']
                ?? ''
            )
        );

    /*
        Có ảnh riêng của biến thể thì dùng đúng ảnh đó.
        Nếu chưa có ảnh riêng thì fallback ảnh đại diện sản phẩm.
    */
    $hinhAnhHienThi =
        $hinhAnhBienThe !== ''
            ? $hinhAnhBienThe
            : $hinhAnhSanPham;

    /*
        Chỉ tự check khi đúng chính xác:
        - sản phẩm;
        - màu;
        - biến thể;
        - và dòng vẫn hợp lệ để thanh toán.
    */
    $buyNowSelected =
        $buyNowMode &&
        $buyNowSanPhamId > 0 &&
        $sanPhamId === $buyNowSanPhamId &&
        (
            (
                $mauId === null &&
                $buyNowMauId === null
            ) ||
            (
                $mauId !== null &&
                $buyNowMauId !== null &&
                $mauId === $buyNowMauId
            )
        ) &&
        (
            (
                $bienTheId === null &&
                $buyNowBienTheId === null
            ) ||
            (
                $bienTheId !== null &&
                $buyNowBienTheId !== null &&
                $bienTheId === $buyNowBienTheId
            )
        ) &&
        $hopLeThanhToan;

    $items[] = [
        'gio_hang_id' => $gioHangId,
        'san_pham_id' => $sanPhamId,
        'ten_san_pham' => $row['ten_san_pham'],
        'hinh_anh' => $hinhAnhHienThi,
        'hinh_anh_bien_the' => $hinhAnhBienThe,
        'hinh_anh_san_pham' => $hinhAnhSanPham,

        'mau_id' => $mauId,
        'ten_mau' => $row['ten_mau'],
        'ma_mau' => $row['ma_mau'],

        'bien_the_id' => $bienTheId,
        'ram' => $row['ram'],
        'rom' => $row['rom'],

        'so_luong' => $soLuong,
        'ton_kho' => $tonKho,
        'tong_trong_gio_cung_kho' => $tongTrongGioCungKho,

        'gia_goc' => $giaGoc,
        'gia_hien_tai' => $giaHienTai,
        'gia_da_luu' => $giaDaLuu,
        'gia_da_thay_doi' => $giaDaThayDoi,
        'thanh_tien' => $thanhTien,

        'khuyen_mai' => $khuyenMai,

        'san_pham_dang_ban' => $sanPhamDangBan,
        'bien_the_khong_hop_le' => $bienTheKhongHopLe,
        'mau_khong_hop_le' => $mauKhongHopLe,
        'het_hang' => $hetHang,
        'vuot_ton_kho' => $vuotTonKho,
        'dat_max' => $datMax,
        'sap_het' => $sapHet,
        'hop_le_thanh_toan' => $hopLeThanhToan,
        'buy_now_selected' => $buyNowSelected
    ];
}
?>

<?php include "../../includes/header.php"; ?>
<?php include "../../includes/navbar.php"; ?>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
>

<style>
.cart-wrapper {
    background: #f7f7f7;
    min-height: 65vh;
}

.cart-card {
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, .06);
    overflow: hidden;
}

.cart-table {
    margin-bottom: 0;
}

.cart-table th {
    white-space: nowrap;
}

.product-image {
    width: 76px;
    height: 76px;
    flex-shrink: 0;
    object-fit: contain;
    border: 1px solid #eee;
    border-radius: 10px;
    background: #fff;
}

.product-name {
    color: #222;
    font-weight: 700;
    text-decoration: none;
}

.product-name:hover {
    color: #ee4d2d;
}

.product-meta {
    font-size: 13px;
    color: #666;
}

.cart-notice {
    border: 0;
    border-radius: 12px;
    box-shadow: 0 5px 18px rgba(0, 0, 0, .04);
}

.color-dot {
    display: inline-block;
    margin-right: 4px;
}

.current-price {
    color: #ee4d2d;
    font-weight: 700;
    white-space: nowrap;
}

.original-price {
    color: #999;
    text-decoration: line-through;
    font-size: 13px;
    white-space: nowrap;
}

.promo-mini {
    margin-top: 6px;
    max-width: 280px;
    padding: 7px 9px;
    border: 1px dashed #ee4d2d;
    border-radius: 8px;
    background: #fff8f5;
    font-size: 12px;
    line-height: 1.45;
}

.promo-mini-title {
    color: #ee4d2d;
    font-weight: 700;
}

.price-changed {
    margin-top: 4px;
    color: #b26a00;
    font-size: 12px;
}

.stock-warning {
    color: #b26a00;
    font-weight: 600;
}

.stock-error {
    color: #dc3545;
    font-weight: 600;
}

.qty-control {
    display: flex;
    align-items: center;
    gap: 4px;
}

.qty-control .form-control {
    width: 60px;
}

.cart-summary {
    position: sticky;
    bottom: 0;
    z-index: 10;
    margin-top: 18px;
    padding: 16px 18px;
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 -4px 18px rgba(0, 0, 0, .06);
}

.selected-total {
    color: #ee4d2d;
    font-size: 24px;
    font-weight: 700;
}

.invalid-row {
    background: #fff8e7;
}

.empty-cart {
    background: #fff;
    border-radius: 14px;
    padding: 35px;
    text-align: center;
}

@media (max-width: 767px) {
    .product-image {
        width: 60px;
        height: 60px;
    }

    .selected-total {
        font-size: 20px;
    }
}

/* =========================================================
   CART UI 2026 - DESKTOP + MOBILE RESPONSIVE
   Chỉ thay giao diện, giữ nguyên logic PHP/JS.
========================================================= */
:root {
    --cart-red: #ee2c3c;
    --cart-red-dark: #d91f31;
    --cart-soft: #fff5f6;
    --cart-green: #14804a;
    --cart-text: #20242c;
    --cart-muted: #7b8491;
    --cart-line: #edf0f3;
}

.cart-wrapper {
    padding-top: 24px !important;
    background:
        linear-gradient(180deg, #f8f9fb 0%, #f4f6f8 100%);
}

.cart-page-head {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 18px;
    margin-bottom: 20px;
}

.cart-page-kicker {
    margin-bottom: 4px;
    color: #a0a7b2;
    font-size: 11px;
    line-height: 1;
    font-weight: 900;
    letter-spacing: .9px;
}

.cart-page-title {
    margin: 0;
    color: var(--cart-text);
    font-size: 28px;
    line-height: 1.15;
    font-weight: 900;
}

.cart-page-subtitle {
    margin-top: 6px;
    color: var(--cart-muted);
    font-size: 13px;
}

.cart-page-badge {
    flex: 0 0 auto;
    padding: 7px 12px;
    border: 1px solid #ffd5da;
    border-radius: 999px;
    background: #fff;
    color: #d7192d;
    font-size: 12px;
    font-weight: 800;
}

.cart-card {
    overflow: hidden;
    border: 1px solid var(--cart-line);
    border-radius: 20px;
    background: #fff;
    box-shadow: 0 12px 34px rgba(15, 23, 42, .055);
}

.cart-table-modern {
    --bs-table-bg: transparent;
    margin: 0;
}

.cart-table-modern thead th {
    padding: 17px 14px;
    border-bottom: 1px solid #eceff2;
    background: #fbfcfd !important;
    color: #242933;
    font-size: 13px;
    font-weight: 850;
    vertical-align: middle;
}

.cart-table-modern tbody td {
    padding: 18px 14px;
    border-bottom: 1px solid #f0f2f4;
    vertical-align: middle;
}

.cart-table-modern tbody tr:last-child td {
    border-bottom: 0;
}

.cart-item-row {
    transition: background .18s ease;
}

.cart-item-row:hover {
    background: #fffdfd;
}

.cart-select-cell {
    width: 54px;
    text-align: center;
}

.cart-select-cell .form-check-input,
#selectAll.form-check-input {
    width: 19px;
    height: 19px;
    cursor: pointer;
}

.cart-select-cell .form-check-input:checked,
#selectAll.form-check-input:checked {
    border-color: var(--cart-red);
    background-color: var(--cart-red);
}

.cart-product-cell {
    min-width: 380px;
}

.cart-product-main {
    align-items: center;
}

.product-image {
    width: 94px;
    height: 94px;
    padding: 4px;
    border: 1px solid #edf0f3;
    border-radius: 14px;
    background: #fff;
}

.product-name {
    display: inline-block;
    max-width: 360px;
    color: #20242c;
    font-size: 15px;
    line-height: 1.42;
    font-weight: 850;
}

.product-meta {
    margin-top: 4px;
    color: #707986;
    font-size: 12.5px;
}

.cart-price-cell {
    min-width: 250px;
}

.cart-price-cell .current-price {
    margin-top: 3px;
    color: var(--cart-red);
    font-size: 18px;
    font-weight: 900;
}

.original-price {
    color: #9ba2ad;
    font-size: 12px;
}

.promo-mini {
    max-width: 100%;
    margin-top: 9px;
    padding: 10px 11px;
    border: 1px dashed #ff9ca7;
    border-radius: 12px;
    background: linear-gradient(145deg, #fff 0%, #fff8f9 100%);
    color: #5f6670;
    font-size: 11px;
    line-height: 1.5;
}

.promo-mini-title {
    margin-bottom: 3px;
    color: #e72b3f;
    font-size: 11.5px;
    font-weight: 850;
}

.qty-control {
    justify-content: center;
    gap: 6px;
}

.qty-control .btn {
    width: 38px;
    height: 38px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    border-radius: 9px;
}

.qty-control .form-control {
    width: 58px;
    height: 38px;
    border-radius: 9px;
    font-weight: 800;
}

.cart-subtotal-cell {
    min-width: 150px;
    color: #161a20 !important;
    font-size: 16px;
    font-weight: 900;
    white-space: nowrap;
}

.cart-remove-cell {
    width: 70px;
    text-align: center;
}

.cart-remove-cell .btn {
    width: 40px;
    height: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
}

.cart-summary {
    position: sticky;
    bottom: 12px;
    z-index: 30;
    margin-top: 16px;
    padding: 15px 18px;
    border: 1px solid var(--cart-line);
    border-radius: 18px;
    background: rgba(255,255,255,.96);
    box-shadow: 0 14px 36px rgba(15,23,42,.10);
    backdrop-filter: blur(12px);
}

.cart-total-line {
    display: flex;
    align-items: baseline;
    gap: 10px;
    margin-top: 2px;
    color: #454b54;
    font-size: 13px;
    font-weight: 700;
}

.selected-total {
    color: var(--cart-red);
    font-size: 25px;
    line-height: 1;
    font-weight: 950;
}

#checkoutButton {
    min-width: 190px;
    min-height: 48px;
    border: 0;
    border-radius: 13px;
    background: linear-gradient(135deg, #f43f52, #e51f35);
    font-weight: 850;
    box-shadow: 0 9px 20px rgba(229,31,53,.20);
}

#checkoutButton:hover:not(:disabled) {
    transform: translateY(-1px);
    background: linear-gradient(135deg, #eb3146, #d9192f);
}

.invalid-row {
    background: #fffaf0 !important;
}

/* Tablet */
@media (max-width: 991.98px) {
    .cart-product-cell {
        min-width: 300px;
    }

    .product-image {
        width: 78px;
        height: 78px;
    }

    .cart-price-cell {
        min-width: 220px;
    }

    .cart-subtotal-cell {
        min-width: 130px;
    }
}

/* =========================================================
   MOBILE: bảng chuyển thành card dọc, không kéo ngang
========================================================= */
@media (max-width: 767.98px) {
    .cart-wrapper {
        padding-top: 14px !important;
        padding-bottom: 96px;
    }

    .cart-wrapper .container {
        padding-left: 10px;
        padding-right: 10px;
    }

    .cart-page-head {
        align-items: flex-start;
        margin-bottom: 14px;
    }

    .cart-page-title {
        font-size: 22px;
    }

    .cart-page-subtitle {
        max-width: 270px;
        font-size: 11.5px;
    }

    .cart-page-badge {
        padding: 6px 9px;
        font-size: 10px;
    }

    .cart-card {
        overflow: visible;
        border: 0;
        border-radius: 0;
        background: transparent;
        box-shadow: none;
    }

    .cart-card .table-responsive {
        overflow: visible;
    }

    .cart-table-modern,
    .cart-table-modern tbody {
        display: block;
        width: 100%;
    }

    .cart-table-modern thead {
        display: none;
    }

    .cart-table-modern tbody tr.cart-item-row {
        position: relative;
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 0 12px;
        width: 100%;
        margin-bottom: 12px;
        padding: 14px;
        border: 1px solid #e9edf1;
        border-radius: 16px;
        background: #fff;
        box-shadow: 0 7px 20px rgba(15,23,42,.045);
    }

    .cart-table-modern tbody td {
        display: block;
        width: auto !important;
        min-width: 0 !important;
        padding: 0;
        border: 0;
    }

    .cart-select-cell {
        position: absolute;
        top: 13px;
        left: 13px;
        z-index: 3;
    }

    .cart-select-cell .form-check-input {
        width: 20px;
        height: 20px;
        margin: 0;
        border: 2px solid #cdd3dc;
        background-color: rgba(255,255,255,.95);
    }

    .cart-product-cell {
        grid-column: 1 / -1;
        padding-left: 28px !important;
        padding-bottom: 11px !important;
    }

    .cart-product-main {
        align-items: flex-start;
        gap: 10px !important;
    }

    .product-image {
        width: 82px;
        height: 82px;
        flex: 0 0 82px;
        border-radius: 12px;
    }

    .product-name {
        max-width: none;
        padding-right: 2px;
        font-size: 14px;
        line-height: 1.35;
    }

    .product-meta {
        font-size: 11.5px;
    }

    .cart-price-cell,
    .cart-qty-cell,
    .cart-subtotal-cell {
        grid-column: 1 / -1;
        display: grid !important;
        grid-template-columns: 88px minmax(0, 1fr);
        align-items: center;
        gap: 8px;
        padding: 10px 0 !important;
        border-top: 1px dashed #eceff2 !important;
    }

    .cart-price-cell::before,
    .cart-qty-cell::before,
    .cart-subtotal-cell::before {
        content: attr(data-label);
        color: #8a929e;
        font-size: 11px;
        font-weight: 750;
    }

    .cart-price-cell .current-price {
        margin: 0;
        font-size: 17px;
    }

    .cart-price-cell .original-price {
        grid-column: 2;
        margin-bottom: 2px;
    }

    .cart-price-cell .promo-mini,
    .cart-price-cell .price-changed {
        grid-column: 1 / -1;
    }

    .promo-mini {
        margin-top: 7px;
    }

    .cart-qty-cell .qty-control {
        justify-content: flex-start;
    }

    .qty-control .btn {
        width: 36px;
        height: 36px;
    }

    .qty-control .form-control {
        width: 56px;
        height: 36px;
    }

    .cart-subtotal-cell {
        color: var(--cart-red) !important;
        font-size: 17px;
    }

    .cart-remove-cell {
        position: absolute;
        right: 12px;
        bottom: 12px;
        width: auto !important;
    }

    .cart-remove-cell .btn {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        background: #fff;
    }

    .cart-summary {
        position: fixed;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 60;
        margin: 0;
        padding: 10px 12px calc(10px + env(safe-area-inset-bottom));
        border: 0;
        border-top: 1px solid #e8ebef;
        border-radius: 0;
        background: rgba(255,255,255,.98);
        box-shadow: 0 -7px 24px rgba(15,23,42,.09);
    }

    .cart-summary > .d-flex {
        flex-wrap: nowrap !important;
        gap: 10px !important;
    }

    .cart-summary > .d-flex > div:first-child {
        min-width: 0;
        flex: 1;
    }

    .cart-summary .text-muted.small:last-child {
        display: none;
    }

    .cart-total-line {
        display: block;
        margin-top: 1px;
    }

    .cart-total-line > span:first-child {
        display: block;
        color: #8a929e;
        font-size: 10px;
        font-weight: 700;
    }

    .selected-total {
        display: block;
        margin-top: 2px;
        font-size: 20px;
    }

    #checkoutButton {
        flex: 0 0 auto;
        min-width: 132px;
        min-height: 45px;
        padding: 8px 15px !important;
        border-radius: 999px;
        font-size: 13px;
    }

    .empty-cart {
        padding: 26px 18px;
        border-radius: 16px;
    }
}

@media (max-width: 420px) {
    .cart-table-modern tbody tr.cart-item-row {
        padding: 12px;
    }

    .product-image {
        width: 74px;
        height: 74px;
        flex-basis: 74px;
    }

    .product-name {
        font-size: 13px;
    }

    .cart-price-cell,
    .cart-qty-cell,
    .cart-subtotal-cell {
        grid-template-columns: 78px minmax(0, 1fr);
    }

    #checkoutButton {
        min-width: 122px;
        font-size: 12px;
    }

    .selected-total {
        font-size: 18px;
    }
}

/* =========================================================
   FIX GIÁ + KHUYẾN MÃI ĐỒNG BỘ TRANG CHI TIẾT
========================================================= */
.cart-price-row {
    display: flex;
    align-items: baseline;
    flex-wrap: wrap;
    gap: 8px 10px;
    min-width: 0;
}

.cart-price-row .original-price {
    margin: 0;
    color: #9ca3af;
    font-size: 12px;
    line-height: 1.2;
    text-decoration: line-through;
    white-space: nowrap;
}

.cart-price-row .current-price {
    margin: 0;
    color: var(--cart-red);
    font-size: 18px;
    line-height: 1.15;
    font-weight: 900;
    white-space: nowrap;
}

.promo-mini {
    max-width: 360px;
}

.promo-mini-title {
    color: #e52b3e;
    font-size: 12px;
    line-height: 1.35;
    font-weight: 850;
}

.promo-mini-reason {
    margin-top: 3px;
    color: #666f7b;
    font-size: 11px;
}

.promo-mini-time {
    margin-top: 4px;
    color: #737b86;
    font-size: 11px;
    line-height: 1.45;
}

.promo-mini-time strong {
    color: #5a616b;
    font-weight: 800;
}

@media (max-width: 767.98px) {
    .cart-price-cell .cart-price-row {
        grid-column: 2;
        display: flex;
        align-items: baseline;
        gap: 6px 8px;
    }

    .cart-price-row .current-price {
        font-size: 17px;
    }

    .cart-price-row .original-price {
        font-size: 10px;
    }

    .cart-price-cell .promo-mini {
        grid-column: 1 / -1;
        width: 100%;
        max-width: none;
    }
}

</style>

<div class="cart-wrapper py-4">
    <div class="container">

        <div class="cart-page-head">
            <div>
                <div class="cart-page-kicker">MUA SẮM</div>
                <h4 class="cart-page-title mb-1">Giỏ hàng của bạn</h4>
                <div class="cart-page-subtitle">Kiểm tra sản phẩm, ưu đãi và số lượng trước khi thanh toán.</div>
            </div>
            <div class="cart-page-badge">
                <?= count($items) ?> sản phẩm
            </div>
        </div>

        <?php if (($_GET['deleted'] ?? '') === '1'): ?>
            <div class="alert alert-success cart-notice">
                ✅ Đã xóa sản phẩm khỏi giỏ hàng.
            </div>
        <?php endif; ?>

        <?php if (($_GET['notice'] ?? '') === 'max_stock'): ?>
            <div class="alert alert-warning cart-notice">
                ⚠️ Số lượng trong giỏ đã đạt mức tồn kho tối đa của sản phẩm.
            </div>
        <?php endif; ?>

        <?php if (!empty($_GET['error'])): ?>
            <div class="alert alert-danger cart-notice">
                <?= htmlspecialchars(
                    (string)$_GET['error'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </div>
        <?php endif; ?>
<?php if (empty($items)): ?>

            <div class="empty-cart">
                <div class="fs-4 mb-2">
                    Giỏ hàng trống
                </div>

                <div class="text-muted mb-3">
                    Hãy chọn sản phẩm bạn muốn mua.
                </div>

                <a
                    href="../../index.php"
                    class="btn btn-danger"
                >
                    Tiếp tục mua sắm
                </a>
            </div>

        <?php else: ?>

            <form
                action="../thanh_toan/index.php"
                method="POST"
                id="checkoutForm"
            >

                <div class="cart-card">
                    <div class="table-responsive">

                        <table class="table align-middle cart-table cart-table-modern">

                            <thead class="table-light">
                                <tr>
                                    <th style="width:45px">
                                        <input
                                            type="checkbox"
                                            id="selectAll"
                                            class="form-check-input"
                                            aria-label="Chọn tất cả"
                                        >
                                    </th>

                                    <th>
                                        Sản phẩm
                                    </th>

                                    <th>
                                        Giá
                                    </th>

                                    <th style="width:180px">
                                        Số lượng
                                    </th>

                                    <th>
                                        Thành tiền
                                    </th>

                                    <th style="width:70px"></th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($items as $item): ?>

                                    <tr
                                        class="cart-item-row <?= !$item['hop_le_thanh_toan']
                                            ? 'invalid-row'
                                            : '' ?>"
                                    >

                                        <td class="cart-select-cell">
                                            <input
                                                type="checkbox"
                                                name="cart_ids[]"
                                                value="<?= (int)$item['gio_hang_id'] ?>"
                                                class="form-check-input cart-checkbox"
                                                data-total="<?= htmlspecialchars(
                                                    (string)$item['thanh_tien']
                                                ) ?>"
                                                <?= !empty($item['buy_now_selected'])
                                                    ? 'checked'
                                                    : '' ?>
                                                <?= !$item['hop_le_thanh_toan']
                                                    ? 'disabled'
                                                    : '' ?>
                                            >
                                        </td>

                                        <td class="cart-product-cell">
                                            <div class="cart-product-main d-flex gap-3">

                                                <?php if (!empty($item['hinh_anh'])): ?>

                                                    <img
                                                        src="../../uploads/<?= htmlspecialchars(
                                                            $item['hinh_anh'],
                                                            ENT_QUOTES,
                                                            'UTF-8'
                                                        ) ?>"
                                                        class="product-image"
                                                        alt="<?= htmlspecialchars(
                                                            $item['ten_san_pham'],
                                                            ENT_QUOTES,
                                                            'UTF-8'
                                                        ) ?>"
                                                        title="<?= !empty(
                                                            $item['hinh_anh_bien_the']
                                                        )
                                                            ? 'Ảnh đúng theo biến thể đã chọn'
                                                            : 'Ảnh đại diện sản phẩm' ?>"
                                                    >

                                                <?php else: ?>

                                                    <div
                                                        class="product-image d-flex align-items-center justify-content-center text-muted small"
                                                    >
                                                        No image
                                                    </div>

                                                <?php endif; ?>

                                                <div>

                                                    <a
                                                        href="../san_pham/chi_tiet.php?id=<?= (int)$item['san_pham_id'] ?>"
                                                        class="product-name"
                                                    >
                                                        <?= htmlspecialchars($item['ten_san_pham']) ?>
                                                    </a>
<?php if (!empty($item['ten_mau'])): ?>

                                                        <div class="product-meta mt-1">
                                                            Màu:
                                                            <b>
                                                                <span
                                                                    class="color-dot"
                                                                    style="color:<?= htmlspecialchars($item['ma_mau'] ?? '#777') ?>"
                                                                >
                                                                    ●
                                                                </span>

                                                                <?= htmlspecialchars($item['ten_mau']) ?>
                                                            </b>
                                                        </div>

                                                    <?php endif; ?>

                                                    <?php if (!empty($item['bien_the_id'])): ?>

                                                        <div class="product-meta">
                                                            Phiên bản:
                                                            <b>
                                                                <?= htmlspecialchars($item['ram'] ?? '') ?>
                                                                /
                                                                <?= htmlspecialchars($item['rom'] ?? '') ?>
                                                            </b>
                                                        </div>

                                                    <?php endif; ?>

                                                    <?php if (!$item['san_pham_dang_ban']): ?>

                                                        <div class="stock-error small mt-1">
                                                            ❌ Sản phẩm hiện đã ngừng bán
                                                        </div>

                                                    <?php elseif ($item['bien_the_khong_hop_le']): ?>

                                                        <div class="stock-error small mt-1">
                                                            ❌ Phiên bản hoặc màu trong giỏ không còn hợp lệ
                                                        </div>

                                                    <?php elseif ($item['mau_khong_hop_le']): ?>

                                                        <div class="stock-error small mt-1">
                                                            ❌ Màu trong giỏ không còn được bán
                                                        </div>

                                                    <?php elseif ($item['het_hang']): ?>

                                                        <div class="stock-error small mt-1">
                                                            ❌ Hết hàng
                                                        </div>

                                                    <?php elseif ($item['vuot_ton_kho']): ?>

                                                        <div class="stock-error small mt-1">
                                                            ⚠️ Giỏ đang có tổng
                                                            <?= (int)$item['tong_trong_gio_cung_kho'] ?>
                                                            sản phẩm nhưng kho chỉ còn
                                                            <?= (int)$item['ton_kho'] ?>
                                                        </div>

                                                    <?php elseif ($item['sap_het']): ?>

                                                        <div class="stock-warning small mt-1">
                                                            ⚠️ Sắp hết hàng, còn
                                                            <?= (int)$item['ton_kho'] ?>
                                                            sản phẩm
                                                        </div>

                                                    <?php else: ?>

                                                        <div class="product-meta mt-1">
                                                            Còn
                                                            <?= (int)$item['ton_kho'] ?>
                                                            sản phẩm
                                                        </div>

                                                    <?php endif; ?>

                                                </div>
                                            </div>
                                        </td>

                                        <td class="cart-price-cell" data-label="Giá">

                                            <div class="cart-price-row">
                                                <?php if (
                                                    $item['gia_hien_tai'] <
                                                    $item['gia_goc']
                                                ): ?>
                                                    <span class="original-price">
                                                        <?= number_format(
                                                            $item['gia_goc'],
                                                            0,
                                                            ',',
                                                            '.'
                                                        ) ?>đ
                                                    </span>
                                                <?php endif; ?>

                                                <span class="current-price">
                                                    <?= number_format(
                                                        $item['gia_hien_tai'],
                                                        0,
                                                        ',',
                                                        '.'
                                                    ) ?>đ
                                                </span>
                                            </div>

                                            <?php if ($item['khuyen_mai']): ?>

                                                <?php
                                                $promo =
                                                    $item['khuyen_mai'];
                                                ?>

                                                <div class="promo-mini">

                                                    <div class="promo-mini-title">
                                                        🏷
                                                        <?= htmlspecialchars(
                                                            $promo['ten_khuyen_mai']
                                                            ?: tenLoaiKhuyenMai(
                                                                $promo['loai_khuyen_mai']
                                                            )
                                                        ) ?>
                                                    </div>

                                                    <?php if (!empty($promo['ly_do'])): ?>
                                                        <div class="promo-mini-reason">
                                                            <?= htmlspecialchars(
                                                                $promo['ly_do']
                                                            ) ?>
                                                        </div>
                                                    <?php endif; ?>

                                                    <div class="promo-mini-time">
                                                        <?php if (
                                                            ($promo['loai_khuyen_mai'] ?? '') === 'flash_sale' &&
                                                            !empty($promo['gio_bat_dau']) &&
                                                            !empty($promo['gio_ket_thuc'])
                                                        ): ?>
                                                            Mỗi ngày
                                                            <strong>
                                                                <?= htmlspecialchars(
                                                                    substr(
                                                                        (string)$promo['gio_bat_dau'],
                                                                        0,
                                                                        5
                                                                    )
                                                                ) ?>
                                                                →
                                                                <?= htmlspecialchars(
                                                                    substr(
                                                                        (string)$promo['gio_ket_thuc'],
                                                                        0,
                                                                        5
                                                                    )
                                                                ) ?>
                                                            </strong>
                                                            · đến ngày
                                                            <strong>
                                                                <?= date(
                                                                    'd/m/Y',
                                                                    strtotime(
                                                                        $promo['ngay_ket_thuc']
                                                                    )
                                                                ) ?>
                                                            </strong>
                                                        <?php else: ?>
                                                            Áp dụng đến
                                                            <strong>
                                                                <?= date(
                                                                    'd/m/Y H:i',
                                                                    strtotime(
                                                                        $promo['ngay_ket_thuc']
                                                                    )
                                                                ) ?>
                                                            </strong>
                                                        <?php endif; ?>
                                                    </div>

                                                </div>

                                            <?php endif; ?>

                                            <?php if ($item['gia_da_thay_doi']): ?>

                                                <div class="price-changed">
                                                    Giá hiện tại đã thay đổi so với lúc thêm vào giỏ.
                                                </div>

                                            <?php endif; ?>

                                        </td>

                                        <td class="cart-qty-cell" data-label="Số lượng">

                                            <div class="qty-control">

                                                <a
                                                    href="cap_nhat.php?id=<?= (int)$item['gio_hang_id'] ?>&type=minus"
                                                    class="btn btn-sm btn-outline-secondary <?= $item['so_luong'] <= 1
                                                        ? 'disabled'
                                                        : '' ?>"
                                                >
                                                    −
                                                </a>

                                                <input
                                                    type="text"
                                                    class="form-control text-center"
                                                    value="<?= (int)$item['so_luong'] ?>"
                                                    readonly
                                                >

                                                <a
                                                    href="cap_nhat.php?id=<?= (int)$item['gio_hang_id'] ?>&type=plus"
                                                    class="btn btn-sm btn-outline-secondary <?= (
                                                        $item['dat_max'] ||
                                                        $item['het_hang'] ||
                                                        !$item['san_pham_dang_ban'] ||
                                                        $item['bien_the_khong_hop_le'] ||
                                                        $item['mau_khong_hop_le']
                                                    ) ? 'disabled' : '' ?>"
                                                >
                                                    +
                                                </a>

                                            </div>

                                        </td>

                                        <td class="cart-subtotal-cell current-price" data-label="Thành tiền">
                                            <?= number_format(
                                                $item['thanh_tien'],
                                                0,
                                                ',',
                                                '.'
                                            ) ?>đ
                                        </td>

                                        <td class="cart-remove-cell">
                                            <a
                                                href="xoa.php?id=<?= (int)$item['gio_hang_id'] ?>"
                                                class="btn btn-sm btn-outline-danger"
                                                onclick="return confirm('Xóa sản phẩm này khỏi giỏ hàng?')"
                                                title="Xóa"
                                            >
                                                🗑
                                            </a>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="cart-summary">

                    <div
                        class="d-flex flex-wrap gap-3 align-items-center justify-content-between"
                    >

                        <div>
                            <div class="text-muted small">
                                Đã chọn
                                <strong id="selectedCount">0</strong>
                                sản phẩm
                            </div>

                            <div class="cart-total-line">
                                <span>Tổng thanh toán</span>
                                <span
                                    id="selectedTotal"
                                    class="selected-total"
                                >
                                    0đ
                                </span>
                            </div>

                            <div class="text-muted small">
                                Giá và tồn kho sẽ được kiểm tra lại khi thanh toán.
                            </div>
                        </div>

                        <button
                            type="submit"
                            id="checkoutButton"
                            class="btn btn-danger px-5"
                            disabled
                        >
                            💳 Thanh toán
                        </button>

                    </div>

                </div>

            </form>

        <?php endif; ?>

    </div>
</div>

<script>
/* =========================================================
   FORMAT TIỀN
========================================================= */
function formatMoney(number) {
    return Number(number)
        .toLocaleString("vi-VN") + "đ";
}

/* =========================================================
   CẬP NHẬT TỔNG CÁC SẢN PHẨM ĐƯỢC CHỌN
========================================================= */
function updateSelectedSummary() {
    const checkboxes =
        document.querySelectorAll(
            ".cart-checkbox"
        );

    let count = 0;
    let total = 0;

    checkboxes.forEach(function (checkbox) {
        if (
            checkbox.checked &&
            !checkbox.disabled
        ) {
            count++;

            total +=
                Number(
                    checkbox.dataset.total
                ) || 0;
        }
    });

    const countBox =
        document.getElementById(
            "selectedCount"
        );

    const totalBox =
        document.getElementById(
            "selectedTotal"
        );

    const checkoutButton =
        document.getElementById(
            "checkoutButton"
        );

    if (countBox) {
        countBox.innerText =
            count;
    }

    if (totalBox) {
        totalBox.innerText =
            formatMoney(total);
    }

    if (checkoutButton) {
        checkoutButton.disabled =
            count <= 0;
    }

    updateSelectAllState();
}

/* =========================================================
   TRẠNG THÁI CHECKBOX CHỌN TẤT CẢ
========================================================= */
function updateSelectAllState() {
    const selectAll =
        document.getElementById(
            "selectAll"
        );

    if (!selectAll) {
        return;
    }

    const selectable =
        Array.from(
            document.querySelectorAll(
                ".cart-checkbox:not(:disabled)"
            )
        );

    if (selectable.length === 0) {
        selectAll.checked = false;
        selectAll.indeterminate = false;
        selectAll.disabled = true;

        return;
    }

    selectAll.disabled = false;

    const checkedCount =
        selectable.filter(
            checkbox => checkbox.checked
        ).length;

    selectAll.checked =
        checkedCount ===
        selectable.length;

    selectAll.indeterminate =
        checkedCount > 0 &&
        checkedCount <
        selectable.length;
}

/* =========================================================
   EVENT CHECKBOX
========================================================= */
document
    .querySelectorAll(
        ".cart-checkbox"
    )
    .forEach(function (checkbox) {
        checkbox.addEventListener(
            "change",
            updateSelectedSummary
        );
    });

const selectAll =
    document.getElementById(
        "selectAll"
    );

if (selectAll) {
    selectAll.addEventListener(
        "change",
        function () {
            document
                .querySelectorAll(
                    ".cart-checkbox:not(:disabled)"
                )
                .forEach(function (checkbox) {
                    checkbox.checked =
                        selectAll.checked;
                });

            updateSelectedSummary();
        }
    );
}

/* =========================================================
   KHÔNG CHO SUBMIT KHI CHƯA CHỌN
========================================================= */
const checkoutForm =
    document.getElementById(
        "checkoutForm"
    );

if (checkoutForm) {
    checkoutForm.addEventListener(
        "submit",
        function (event) {
            const checked =
                document.querySelector(
                    ".cart-checkbox:checked:not(:disabled)"
                );

            if (!checked) {
                event.preventDefault();

                alert(
                    "Vui lòng chọn ít nhất một sản phẩm để thanh toán"
                );
            }
        }
    );
}

updateSelectedSummary();

/* =========================================================
   BACK TRÌNH DUYỆT → LOAD LẠI GIÁ + TỒN KHO
========================================================= */
window.addEventListener(
    "pageshow",
    function (event) {
        if (event.persisted) {
            window.location.reload();
        }
    }
);
</script>

<?php include "../../includes/footer.php"; ?>
