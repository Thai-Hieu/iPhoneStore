<?php

if (session_status() === PHP_SESSION_NONE) {

    session_start();

}

require_once "../../middleware/check_user.php";

require_once "../../config/database.php";

/* =========================================================

   MÃ SẢN PHẨM + MÃ VẬN ĐƠN

   Database đã có:

   - san_pham.ma_san_pham

   - don_hang.ma_van_don

   id đơn hàng vẫn dùng nội bộ để truy vấn.

========================================================= */

/* =========================================================

   KHÔNG CACHE

========================================================= */

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

header("Pragma: no-cache");

/* =========================================================

   ẢNH RIÊNG THEO BIẾN THỂ

   Hỗ trợ cả hai tên cột đã từng dùng:

   - hinh_anh

   - duong_dan_anh

========================================================= */

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

        if (

            $stmt

                ->get_result()

                ->fetch_assoc()

        ) {

            return $columnName;

        }

    }

    return null;

}

/* =========================================================

   HÀM BADGE TRẠNG THÁI ĐƠN

========================================================= */

function orderStatusClass(string $status): string

{

    switch (trim($status)) {

        case 'cho_xu_ly':

        case 'Đã đặt':

        case 'da_dat':

        case 'Chờ xác nhận':

        case 'cho_xac_nhan':

            return 'bg-warning text-dark';

        case 'da_duyet':

        case 'Đang xử lý':

        case 'dang_xu_ly':

            return 'bg-primary';

        case 'Đang giao':

        case 'dang_giao':

            return 'bg-info text-dark';

        case 'Đã giao':

        case 'da_giao':

        case 'Hoàn thành':

        case 'hoan_thanh':

            return 'bg-success';

        case 'Đã hủy':

        case 'Hủy':

        case 'da_huy':

        case 'huy':

            return 'bg-danger';

        default:

            return 'bg-secondary';

    }

}

function orderStatusLabel(string $status): string

{

    $status = trim($status);

    $labels = [

        'cho_xu_ly' => 'Chờ xử lý',

        'da_dat' => 'Chờ xử lý',

        'Đã đặt' => 'Chờ xử lý',

        'cho_xac_nhan' => 'Chờ xử lý',

        'Chờ xác nhận' => 'Chờ xử lý',

        'da_duyet' => 'Đã duyệt',

        'dang_xu_ly' => 'Đã duyệt',

        'Đang xử lý' => 'Đã duyệt',

        'dang_giao' => 'Đang giao',

        'Đang giao' => 'Đang giao',

        'da_giao' => 'Hoàn thành',

        'Đã giao' => 'Hoàn thành',

        'hoan_thanh' => 'Hoàn thành',

        'Hoàn thành' => 'Hoàn thành',

        'da_huy' => 'Đã hủy',

        'huy' => 'Đã hủy',

        'Đã hủy' => 'Đã hủy',

        'Hủy' => 'Đã hủy'

    ];

    return $labels[$status]

        ?? ($status !== '' ? $status : 'Chưa xác định');

}

/* =========================================================

   HÀM BADGE TRẠNG THÁI THANH TOÁN

========================================================= */

function paymentStatusClass(string $status): string

{

    switch (trim($status)) {

        case 'Thành công':

        case 'Đã thanh toán':

            return 'bg-success';

        case 'Chờ xác nhận':

            return 'bg-warning text-dark';

        case 'Chưa thanh toán':

        case 'Chờ thanh toán':

        case 'Chờ xử lý':

            return 'bg-secondary';

        case 'Thất bại':

        case 'Đã hủy':

            return 'bg-danger';

        default:

            return 'bg-secondary';

    }

}

/* =========================================================

   USER + ID ĐƠN HÀNG

========================================================= */

$nguoiDungId = (int)($_SESSION['user']['id'] ?? 0);

$donHangId = (int)($_GET['id'] ?? 0);

if ($nguoiDungId <= 0) {

    die("Bạn chưa đăng nhập");

}

if ($donHangId <= 0) {

    die("Thiếu hoặc sai mã đơn hàng");

}

/* =========================================================

   LẤY ĐƠN HÀNG

   Chỉ chủ sở hữu đơn mới xem được.

========================================================= */

$stmtOrder = $conn->prepare("

    SELECT

        id,

        ma_van_don,

        nguoi_dung_id,

        tong_tien,

        trang_thai,

        ngay_dat,

        ly_do_huy,

        ngay_huy,

        nguoi_huy,

        ten_nguoi_nhan,

        so_dien_thoai_nhan,

        dia_chi_nhan

    FROM don_hang

    WHERE id = ?

    AND nguoi_dung_id = ?

    LIMIT 1

");

$stmtOrder->bind_param(

    "ii",

    $donHangId,

    $nguoiDungId

);

$stmtOrder->execute();

$donHang = $stmtOrder

    ->get_result()

    ->fetch_assoc();

if (!$donHang) {

    die("Đơn hàng không tồn tại");

}

/* =========================================================

   THÔNG TIN NHẬN HÀNG ĐÃ LƯU THEO ĐƠN

   Đây là snapshot tại thời điểm đặt hàng.

   Không lấy lại từ hồ sơ tài khoản để tránh đơn cũ bị thay đổi

   khi người dùng sửa họ tên / SĐT / địa chỉ sau này.

========================================================= */

$tenNguoiNhan =

    trim(

        (string)(

            $donHang['ten_nguoi_nhan']

            ?? ''

        )

    );

$soDienThoaiNhan =

    trim(

        (string)(

            $donHang['so_dien_thoai_nhan']

            ?? ''

        )

    );

$diaChiNhan =

    trim(

        (string)(

            $donHang['dia_chi_nhan']

            ?? ''

        )

    );

$coThongTinNhanHang =

    $tenNguoiNhan !== '' ||

    $soDienThoaiNhan !== '' ||

    $diaChiNhan !== '';

/* =========================================================

   LẤY THANH TOÁN MỚI NHẤT CỦA ĐƠN

========================================================= */

$stmtPayment = $conn->prepare("

    SELECT

        id,

        phuong_thuc,

        trang_thai,

        ngay_thanh_toan

    FROM thanh_toan

    WHERE don_hang_id = ?

    ORDER BY id DESC

    LIMIT 1

");

$stmtPayment->bind_param(

    "i",

    $donHangId

);

$stmtPayment->execute();

$thanhToan = $stmtPayment

    ->get_result()

    ->fetch_assoc();

/* =========================================================

   CHI TIẾT ĐƠN HÀNG

   ct.gia là GIÁ TẠI THỜI ĐIỂM ĐẶT HÀNG.

   Không tính lại khuyến mãi hiện tại.

   Ảnh:

   - ưu tiên ảnh chính của biến thể đã đặt;

   - nếu biến thể chưa có ảnh riêng thì dùng sp.hinh_anh.

========================================================= */

$cotAnhBienThe = layCotAnhBienThe($conn);

if ($cotAnhBienThe !== null) {

    $anhBienTheSelect = "

        (

            SELECT

                ha.`{$cotAnhBienThe}`

            FROM san_pham_bien_the_hinh_anh ha

            WHERE ha.bien_the_id =

                ct.bien_the_id

            ORDER BY

                ha.la_anh_chinh DESC,

                ha.thu_tu ASC,

                ha.id ASC

            LIMIT 1

        ) AS hinh_anh_bien_the,

    ";

} else {

    $anhBienTheSelect = "

        NULL AS hinh_anh_bien_the,

    ";

}

$stmtDetail = $conn->prepare("

    SELECT

        ct.id AS chi_tiet_id,

        ct.san_pham_id,

        ct.so_luong,

        ct.gia,

        ct.mau_id,

        ct.bien_the_id,

        sp.ma_san_pham,

        sp.ten_san_pham,

        {$anhBienTheSelect}

        sp.hinh_anh AS hinh_anh_san_pham,

        sp.gia AS gia_goc_san_pham,

        ms.ten_mau,

        ms.ma_mau,

        bt.ram,

        bt.rom,

        bt.gia AS gia_goc_bien_the

    FROM chi_tiet_don_hang ct

    LEFT JOIN san_pham sp

        ON sp.id = ct.san_pham_id

    LEFT JOIN mau_sac ms

        ON ms.id = ct.mau_id

    LEFT JOIN san_pham_bien_the bt

        ON bt.id = ct.bien_the_id

        AND bt.san_pham_id = ct.san_pham_id

    WHERE ct.don_hang_id = ?

    ORDER BY ct.id ASC

");

$stmtDetail->bind_param(

    "i",

    $donHangId

);

$stmtDetail->execute();

$chiTietList = $stmtDetail

    ->get_result()

    ->fetch_all(MYSQLI_ASSOC);

/*

    Chuẩn hóa ảnh hiển thị cho từng dòng đơn:

    ảnh biến thể > ảnh đại diện sản phẩm.

*/

foreach ($chiTietList as &$chiTietItem) {

    $hinhAnhBienThe =

        trim(

            (string)(

                $chiTietItem['hinh_anh_bien_the']

                ?? ''

            )

        );

    $hinhAnhSanPham =

        trim(

            (string)(

                $chiTietItem['hinh_anh_san_pham']

                ?? ''

            )

        );

    $chiTietItem['hinh_anh'] =

        $hinhAnhBienThe !== ''

            ? $hinhAnhBienThe

            : $hinhAnhSanPham;

}

unset($chiTietItem);

/* =========================================================

   TÍNH TÓM TẮT ĐƠN HÀNG

   ct.gia = giá đã lưu tại thời điểm khách đặt hàng.

   Database hiện chưa lưu riêng "giá gốc lúc mua",

   nên giá gốc bên dưới lấy từ giá sản phẩm / biến thể

   hiện tại để làm giá tham chiếu.

   Nếu sau này muốn lịch sử chính xác tuyệt đối,

   nên lưu thêm gia_goc_luc_mua trong chi_tiet_don_hang.

========================================================= */

$tongGiaGoc = 0;

$tongGiamGiaSanPham = 0;

$tongPhuSanPham = 0;

$tongSoLuong = 0;

foreach ($chiTietList as &$item) {

    $soLuong =

        (int)$item['so_luong'];

    $giaDaDat =

        (float)$item['gia'];

    if (

        !empty($item['bien_the_id']) &&

        $item['gia_goc_bien_the'] !== null

    ) {

        $giaGoc =

            (float)$item['gia_goc_bien_the'];

    } else {

        $giaGoc =

            (float)($item['gia_goc_san_pham'] ?? 0);

    }

    /*

        Không để xuất hiện giảm giá âm.

    */

    if ($giaGoc < $giaDaDat) {

        $giaGoc =

            $giaDaDat;

    }

    $giamMoiSanPham =

        max(

            0,

            $giaGoc - $giaDaDat

        );

    $tongGiaGoc +=

        $giaGoc * $soLuong;

    $tongGiamGiaSanPham +=

        $giamMoiSanPham * $soLuong;

    $tongPhuSanPham +=

        $giaDaDat * $soLuong;

    $tongSoLuong +=

        $soLuong;

    $item['gia_goc_hien_thi'] =

        $giaGoc;

    $item['giam_moi_san_pham'] =

        $giamMoiSanPham;

    $item['phan_tram_giam_hien_thi'] =
        $giaGoc > 0 && $giamMoiSanPham > 0
            ? round(
                ($giamMoiSanPham / $giaGoc) * 100,
                1
            )
            : 0;

}

unset($item);

/*

    Công thức:

    Tổng phụ sản phẩm = Giá gốc - Giảm giá sản phẩm

*/

$tongPhuSanPhamTinhLai =

    max(

        0,

        $tongGiaGoc -

        $tongGiamGiaSanPham

    );

/*

    Tổng cuối cùng của hệ thống vẫn ưu tiên

    don_hang.tong_tien đã lưu.

*/

$tongDonHang = (float)$donHang['tong_tien'];

/*
    Chỉ dùng để hiển thị lịch sử đơn hàng.
    Không tính lại chương trình khuyến mãi hiện tại.
*/
$tongSauUuDaiSanPham =
    max(
        0,
        $tongGiaGoc - $tongGiamGiaSanPham
    );

$chenhLechSauUuDai =
    $tongDonHang - $tongSauUuDaiSanPham;

$phanTramTietKiemDon =
    $tongGiaGoc > 0 && $tongGiamGiaSanPham > 0
        ? round(
            ($tongGiamGiaSanPham / $tongGiaGoc) * 100,
            1
        )
        : 0;

$trangThaiDon =

    trim(

        (string)$donHang['trang_thai']

    );

$phuongThuc =

    trim(

        (string)($thanhToan['phuong_thuc'] ?? '')

    );

$trangThaiThanhToan =

    trim(

        (string)($thanhToan['trang_thai'] ?? '')

    );

$maVanDon = trim(

    (string)($donHang['ma_van_don'] ?? '')

);

$lyDoHuy = trim(

    (string)($donHang['ly_do_huy'] ?? '')

);

$nguoiHuy = trim(

    (string)($donHang['nguoi_huy'] ?? '')

);

$ngayHuy = trim(

    (string)($donHang['ngay_huy'] ?? '')

);

$laDonChoXuLy =

    in_array(

        $trangThaiDon,

        [

            'cho_xu_ly',

            'da_dat',

            'Đã đặt',

            'cho_xac_nhan',

            'Chờ xác nhận'

        ],

        true

    );

$laDonDaHuy =

    in_array(

        $trangThaiDon,

        [

            'da_huy',

            'Đã hủy',

            'huy',

            'Hủy'

        ],

        true

    );

$coTheTuHuy = $laDonChoXuLy;

if (empty($_SESSION['csrf_user_cancel_order'])) {

    $_SESSION['csrf_user_cancel_order'] =

        bin2hex(random_bytes(32));

}

$userCancelCsrf =

    (string)$_SESSION['csrf_user_cancel_order'];

$userCancelReasons = [

    'Tôi không còn nhu cầu mua sản phẩm',

    'Tôi đặt nhầm sản phẩm',

    'Tôi muốn đổi sang sản phẩm khác',

    'Tôi muốn đổi màu hoặc phiên bản khác',

    'Tôi đặt trùng đơn hàng',

    'Tôi muốn thay đổi địa chỉ nhận hàng',

    'Tôi muốn thay đổi số điện thoại nhận hàng',

    'Tôi muốn thay đổi phương thức thanh toán',

    'Thời gian nhận hàng dự kiến không còn phù hợp',

    'Tôi tìm được lựa chọn phù hợp hơn',

    'Tôi nhập sai thông tin đặt hàng',

    'Lý do cá nhân khác'

];

?>

<?php include "../../includes/header.php"; ?>

<?php include "../../includes/navbar.php"; ?>

<link

    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"

    rel="stylesheet"

>

<style>

.order-detail-page {

    background: #f7f7f7;

    min-height: 65vh;

}

.detail-card {

    background: #fff;

    border-radius: 16px;

    box-shadow: 0 8px 24px rgba(0, 0, 0, .06);

    overflow: hidden;

}

.info-box {

    padding: 20px;

}

.info-row {

    display: flex;

    justify-content: space-between;

    gap: 20px;

    margin-bottom: 10px;

}

.info-row:last-child {

    margin-bottom: 0;

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

    font-weight: 700;

    color: #222;

}

.product-meta {

    color: #666;

    font-size: 13px;

}

.product-code-cell {

    min-width: 115px;

}

.product-code-badge {

    display: inline-flex;

    align-items: center;

    padding: 5px 9px;

    border: 1px solid #ffd9cc;

    border-radius: 8px;

    background: #fff5f1;

    color: #ee4d2d;

    font-size: 12px;

    font-weight: 800;

    letter-spacing: .6px;

    white-space: nowrap;

}

.product-code-empty {

    color: #999;

    font-size: 12px;

    font-weight: 600;

}

.tracking-code {

    display: inline-flex;

    align-items: center;

    padding: 6px 10px;

    border: 1px solid #cfe2ff;

    border-radius: 8px;

    background: #eef6ff;

    color: #0d6efd;

    font-size: 14px;

    font-weight: 800;

    letter-spacing: 1px;

}

.tracking-empty {

    color: #777;

    font-size: 13px;

    font-weight: 600;

}

.tracking-note {

    margin-top: 4px;

    color: #999;

    font-size: 11px;

}

.color-dot {

    display: inline-block;

    margin-right: 4px;

}

.order-price {

    color: #ee4d2d;

    font-weight: 700;

    white-space: nowrap;

}

.product-price-block {

    display: flex;

    flex-direction: column;

    gap: 3px;

}

.product-price-line {

    display: flex;

    flex-wrap: wrap;

    align-items: center;

    gap: 7px;

}

.product-old-price {

    color: #999;

    font-size: 12px;

    text-decoration: line-through;

    white-space: nowrap;

}

.product-saving {

    color: #198754;

    font-size: 11px;

    line-height: 1.35;

}

.history-note {

    padding: 12px 14px;

    border-radius: 10px;

    background: #fff8f5;

    color: #666;

    font-size: 13px;

}

.cancel-notice {

    margin-top: 14px;

    padding: 15px 16px;

    border: 1px solid #f2c7cd;

    border-radius: 12px;

    background: #fff5f6;

}

.cancel-notice-title {

    color: #b42332;

    font-weight: 850;

}

.cancel-notice-text {

    margin-top: 5px;

    color: #70565a;

    font-size: 13px;

    line-height: 1.6;

}

.cancel-notice-time {

    margin-top: 5px;

    color: #9b7c80;

    font-size: 11px;

}

.user-cancel-card {

    padding: 20px;

    border: 1px solid #f0d5d9;

    border-radius: 16px;

    background: linear-gradient(180deg, #fffafb, #ffffff);

}

.user-cancel-title {

    margin-bottom: 5px;

    color: #b42332;

    font-size: 17px;

    font-weight: 850;

}

.user-cancel-help {

    color: #777;

    font-size: 13px;

    line-height: 1.6;

}

.user-cancel-form {

    display: grid;

    grid-template-columns: minmax(0, 1fr) auto;

    gap: 10px;

    align-items: end;

    margin-top: 14px;

}

.user-cancel-form .form-select {

    min-height: 46px;

    border-radius: 10px;

    box-shadow: none !important;

}

.user-cancel-form .form-select:focus {

    border-color: #d70018;

}

.user-cancel-btn {

    min-height: 46px;

    min-width: 150px;

    border-radius: 10px;

    font-weight: 800;

}

.cancel-lock-note {

    padding: 13px 15px;

    border: 1px solid #ececec;

    border-radius: 12px;

    background: #fafafa;

    color: #777;

    font-size: 13px;

    line-height: 1.6;

}

.total-box {

    max-width: 430px;

    margin-left: auto;

}

.total-row {

    display: flex;

    justify-content: space-between;

    gap: 20px;

    margin-bottom: 8px;

}

.total-final {

    color: #111;

    font-size: 28px;

    font-weight: 800;

}

.order-finance {

    margin-top: 22px;

    padding: 18px 20px;

    border: 1px solid #f1e5df;

    border-radius: 14px;

    background: linear-gradient(

        180deg,

        #fffaf7 0%,

        #ffffff 100%

    );

}

.order-finance-title {

    display: flex;

    align-items: center;

    gap: 8px;

    margin-bottom: 16px;

    color: #333;

    font-size: 16px;

    font-weight: 800;

}

.finance-row {

    display: grid;

    grid-template-columns: 1fr auto;

    align-items: center;

    column-gap: 28px;

    margin-bottom: 12px;

}

.finance-row:last-child {

    margin-bottom: 0;

}

.finance-label {

    color: #666;

    font-size: 15px;

}

.finance-value {

    color: #333;

    font-weight: 700;

    white-space: nowrap;

    text-align: right;

}

.finance-discount {

    color: #ee4d2d;

}

.finance-divider {

    border-top: 1px dashed #e2d7d1;

    margin: 16px 0;

}

.finance-total-label {

    color: #222;

    font-size: 18px;

    font-weight: 800;

}

.finance-total-value {

    color: #ee4d2d;

    font-size: 24px;

    font-weight: 800;

}

.saving-chip {

    display: inline-flex;

    align-items: center;

    gap: 5px;

    margin-top: 12px;

    padding: 7px 11px;

    border-radius: 999px;

    background: #edf9f0;

    color: #198754;

    font-size: 13px;

    font-weight: 700;

}

@media (max-width: 767px) {

    .order-finance {

        padding: 15px;

    }

    .finance-row {

        column-gap: 14px;

    }

    .finance-total-label {

        font-size: 17px;

    }

    .finance-total-value {

        font-size: 21px;

    }

}

.empty-detail {

    padding: 35px;

    text-align: center;

}

/* =========================================================

   THÔNG TIN NHẬN HÀNG

========================================================= */

.receiver-card {

    padding: 20px;

}

.receiver-card-title {

    display: flex;

    align-items: center;

    gap: 9px;

    margin-bottom: 16px;

    color: #222;

    font-size: 18px;

    font-weight: 850;

}

.receiver-grid {

    display: grid;

    grid-template-columns: repeat(2, minmax(0, 1fr));

    gap: 14px;

}

.receiver-item {

    padding: 14px 15px;

    border: 1px solid #eceff3;

    border-radius: 12px;

    background: #fafbfc;

}

.receiver-item.address {

    grid-column: 1 / -1;

}

.receiver-label {

    margin-bottom: 5px;

    color: #7a7f87;

    font-size: 12px;

    font-weight: 700;

}

.receiver-value {

    color: #222;

    font-size: 14px;

    font-weight: 750;

    line-height: 1.6;

    word-break: break-word;

}

.receiver-empty {

    color: #999;

    font-weight: 600;

}

.receiver-legacy-note {

    margin-top: 13px;

    padding: 11px 13px;

    border: 1px solid #f1dfbf;

    border-radius: 10px;

    background: #fffaf0;

    color: #80652d;

    font-size: 12px;

    line-height: 1.55;

}

@media (max-width: 767px) {

    .receiver-card {

        padding: 16px 14px;

    }

    .receiver-card-title {

        margin-bottom: 12px;

        font-size: 17px;

    }

    .receiver-grid {

        grid-template-columns: 1fr;

        gap: 10px;

    }

    .receiver-item,

    .receiver-item.address {

        grid-column: auto;

        display: flex;

        align-items: flex-start;

        gap: 8px;

        padding: 11px 12px;

        border-radius: 10px;

    }

    .receiver-label {

        flex: 0 0 auto;

        margin-bottom: 0;

        color: #7a7f87;

        font-size: 11px;

        line-height: 1.5;

        font-weight: 700;

        white-space: nowrap;

    }

    .receiver-label::after {

        content: ":";

    }

    .receiver-value {

        min-width: 0;

        flex: 1 1 auto;

        color: #222;

        font-size: 12px;

        line-height: 1.5;

        font-weight: 750;

        word-break: break-word;

        overflow-wrap: anywhere;

    }

}

@media (max-width: 767px) {

    .info-box {

        padding: 16px 14px;

    }

    .info-box h5 {

        margin-bottom: 14px !important;

        font-size: 18px;

    }

    .info-row {

        display: grid;

        grid-template-columns: minmax(0, 1fr) auto;

        align-items: center;

        gap: 12px;

        margin-bottom: 12px;

    }

    .info-row > :first-child {

        min-width: 0;

        color: #777 !important;

        text-align: left;

    }

    .info-row > :last-child {

        min-width: 0;

        margin-top: 0;

        text-align: right !important;

        justify-self: end;

    }

    .info-row > b,

    .info-row > span:last-child,

    .info-row > div:last-child {

        text-align: right !important;

    }

    .tracking-code {

        padding: 5px 9px;

        font-size: 12px;

        letter-spacing: .7px;

    }

    .tracking-empty {

        font-size: 11px;

        text-align: right;

    }

    .tracking-note {

        max-width: 190px;

        margin-left: auto;

        font-size: 10px;

        line-height: 1.35;

        text-align: right;

    }

    .info-row .badge {

        padding: 0;

        border: 0;

        border-radius: 0;

        background: transparent !important;

        box-shadow: none !important;

        font-size: 12px;

        font-weight: 800;

    }

    .info-row .badge.bg-success {

        color: #198754 !important;

    }

    .info-row .badge.bg-primary {

        color: #0d6efd !important;

    }

    .info-row .badge.bg-warning {

        color: #9a6700 !important;

    }

    .info-row .badge.bg-info {

        color: #087990 !important;

    }

    .info-row .badge.bg-danger {

        color: #dc3545 !important;

    }

    .info-row .badge.bg-secondary {

        color: #6c757d !important;

    }

    .product-image {

        width: 62px;

        height: 62px;

    }

    .user-cancel-form {

        grid-template-columns: 1fr;

    }

    .user-cancel-btn {

        width: 100%;

    }

}

/* =========================================================

   RESPONSIVE DANH SÁCH SẢN PHẨM TRONG ĐƠN

========================================================= */

.product-order-table {

    table-layout: auto;

}

.product-order-table th,

.product-order-table td {

    vertical-align: middle;

}

.product-order-table th:first-child,

.product-order-table td:first-child {

    min-width: 300px;

}

@media (max-width: 991.98px) {

    .product-order-table th:first-child,

    .product-order-table td:first-child {

        min-width: 250px;

    }

    .product-image {

        width: 68px;

        height: 68px;

    }

}

@media (max-width: 767.98px) {

    .product-table-wrap {

        overflow: visible;

    }

    .product-order-table {

        display: block;

        width: 100%;

        margin: 0;

    }

    .product-order-table thead {

        display: none;

    }

    .product-order-table tbody {

        display: grid;

        gap: 12px;

        padding: 12px;

    }

    .product-order-table tbody tr {

        display: grid;

        grid-template-columns: 1fr 1fr;

        overflow: hidden;

        border: 1px solid #eceff3;

        border-radius: 14px;

        background: #fff;

        box-shadow: 0 5px 16px rgba(0, 0, 0, .035);

    }

    .product-order-table tbody td {

        min-width: 0 !important;

        padding: 10px 12px;

        border: 0;

        border-bottom: 1px solid #f1f2f4;

        font-size: 12px;

    }

    .product-order-table tbody td:first-child {

        grid-column: 1 / -1;

        padding: 12px;

        background: #fafbfc;

    }

    .product-order-table tbody td:nth-child(2),

    .product-order-table tbody td:nth-child(5) {

        grid-column: 1 / -1;

    }

    .product-order-table tbody td:nth-child(2),

    .product-order-table tbody td:nth-child(3),

    .product-order-table tbody td:nth-child(4),

    .product-order-table tbody td:nth-child(5) {

        display: grid;

        grid-template-columns: 92px minmax(0, 1fr);

        gap: 8px;

        align-items: center;

    }

    .product-order-table tbody td:nth-child(2)::before {

        content: "Mã sản phẩm";

    }

    .product-order-table tbody td:nth-child(3)::before {

        content: "Giá lúc đặt";

    }

    .product-order-table tbody td:nth-child(4)::before {

        content: "Số lượng";

    }

    .product-order-table tbody td:nth-child(5)::before {

        content: "Thành tiền";

    }

    .product-order-table tbody td:nth-child(2)::before,

    .product-order-table tbody td:nth-child(3)::before,

    .product-order-table tbody td:nth-child(4)::before,

    .product-order-table tbody td:nth-child(5)::before {

        justify-self: start;

        text-align: left;

    }

    .product-order-table tbody td::before {

        color: #8a9099;

        font-size: 10px;

        font-weight: 800;

        text-transform: uppercase;

        letter-spacing: .2px;

    }

    .product-order-table tbody td:last-child {

        border-bottom: 0;

    }

    .product-order-table .d-flex.gap-3 {

        align-items: center;

        gap: 10px !important;

    }

    .product-image {

        width: 64px;

        height: 64px;

        border-radius: 10px;

    }

    .product-name {

        font-size: 13px;

        line-height: 1.35;

    }

    .product-meta {

        margin-top: 3px !important;

        font-size: 11px;

        line-height: 1.4;

    }

    .product-code-cell {

        min-width: 0;

    }

    .product-code-badge {

        width: fit-content;

        max-width: 100%;

        padding: 4px 7px;

        border-radius: 7px;

        font-size: 10px;

        letter-spacing: .3px;

        white-space: normal;

        overflow-wrap: anywhere;

    }

    .order-price {

        font-size: 12px;

        white-space: normal;

        text-align: right;

    }

    .product-order-table tbody td:nth-child(2),

    .product-order-table tbody td:nth-child(3),

    .product-order-table tbody td:nth-child(4),

    .product-order-table tbody td:nth-child(5) {

        text-align: left;

    }

    .product-order-table tbody td:nth-child(2) > *,

    .product-order-table tbody td:nth-child(3) > *,

    .product-order-table tbody td:nth-child(5) > * {

        justify-self: end;

        text-align: right;

    }

    .product-order-table tbody td:nth-child(4) {

        text-align: left;

    }

    .product-order-table tbody td:nth-child(4) {

        justify-items: stretch;

    }

    .product-order-table tbody td:nth-child(4) > *,

    .product-order-table tbody td:nth-child(5) > * {

        justify-self: end;

        text-align: right;

    }

    .product-order-table .product-code-badge,

    .product-order-table .product-code-empty,

    .product-order-table .product-price-block,

    .product-order-table .order-price {

        text-align: right;

    }

    .product-quantity-value,

    .product-total-value {

        justify-self: end;

        text-align: right;

    }

    .product-price-block {

        min-width: 0;

        align-items: flex-end;

    }

    .product-price-line {

        justify-content: flex-end;

        gap: 6px;

    }

    .product-old-price {

        font-size: 10px;

    }

    .order-price {

        font-size: 12px;

        white-space: nowrap;

    }

    .product-saving {

        margin-top: 2px;

        font-size: 10px;

    }

}

@media (max-width: 420px) {

    .product-order-table tbody {

        gap: 10px;

        padding: 10px;

    }

    .product-order-table tbody tr {

        grid-template-columns: 1fr;

        border-radius: 12px;

    }

    .product-order-table tbody td,

    .product-order-table tbody td:nth-child(2),

    .product-order-table tbody td:nth-child(3),

    .product-order-table tbody td:nth-child(4),

    .product-order-table tbody td:nth-child(5) {

        grid-column: 1;

    }

    .product-order-table tbody td:nth-child(2),

    .product-order-table tbody td:nth-child(3),

    .product-order-table tbody td:nth-child(4),

    .product-order-table tbody td:nth-child(5) {

        grid-template-columns: 90px minmax(0, 1fr);

    }

    .product-image {

        width: 58px;

        height: 58px;

    }

    .product-name {

        font-size: 12px;

    }

    .product-meta {

        font-size: 10px;

    }

}

@media (max-width: 420px) {

    .info-row {

        grid-template-columns: minmax(0, 1fr) minmax(110px, auto);

        gap: 10px;

    }

    .info-row > :first-child {

        font-size: 12px;

    }

    .info-row > :last-child {

        font-size: 12px;

    }

}

@media (max-width: 420px) {

    .receiver-item,

    .receiver-item.address {

        flex-wrap: wrap;

        gap: 4px 6px;

    }

    .receiver-label {

        font-size: 10.5px;

    }

    .receiver-value {

        font-size: 11.5px;

    }

}

@media (max-width: 767px) {

    .order-finance .saving-chip {

        display: flex;

        width: fit-content;

        margin: 12px auto 0;

        justify-content: center;

        text-align: center;

    }

}

/* =========================================================
   ORDER DETAIL - PAYMENT STYLE SUMMARY
========================================================= */
.product-saving-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 4px;
}

.product-discount-chip {
    display: inline-flex;
    align-items: center;
    min-height: 23px;
    padding: 3px 7px;
    border-radius: 7px;
    background: #fff0f1;
    color: #e32237;
    font-size: 10px;
    line-height: 1;
    font-weight: 900;
}

.product-saving {
    margin: 0;
    color: #198754;
    font-size: 11px;
    line-height: 1.35;
    font-weight: 700;
}

.order-finance {
    margin-top: 24px;
    padding: 20px;
    border: 1px solid #f0e1dc;
    border-radius: 18px;
    background: linear-gradient(145deg, #fff9f7 0%, #ffffff 66%);
}

.order-finance-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 18px;
}

.order-finance-eyebrow {
    margin-bottom: 4px;
    color: #a28f87;
    font-size: 10px;
    line-height: 1;
    font-weight: 900;
    letter-spacing: .8px;
}

.order-finance-title {
    margin: 0;
    color: #25282d;
    font-size: 17px;
    line-height: 1.3;
    font-weight: 900;
}

.order-discount-badge {
    flex: 0 0 auto;
    padding: 6px 10px;
    border-radius: 999px;
    background: #eaf8ef;
    color: #118247;
    font-size: 11px;
    font-weight: 900;
}

.finance-after-discount {
    padding-top: 4px;
}

.finance-total-row {
    align-items: baseline;
}

.finance-total-value {
    font-size: 28px;
    line-height: 1;
}

.order-saving-box {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-top: 15px;
    padding: 12px 13px;
    border: 1px solid #d6efdf;
    border-radius: 12px;
    background: #f1fbf5;
}

.order-saving-icon {
    width: 27px;
    height: 27px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 27px;
    border-radius: 50%;
    background: #198754;
    color: #fff;
    font-size: 13px;
    font-weight: 900;
}

.order-saving-title {
    color: #127442;
    font-size: 13px;
    line-height: 1.35;
    font-weight: 850;
}

.order-saving-note {
    margin-top: 3px;
    color: #698475;
    font-size: 10.5px;
    line-height: 1.45;
}

@media (max-width: 767.98px) {
    .order-finance {
        margin-top: 18px;
        padding: 15px;
        border-radius: 14px;
    }

    .order-finance-head {
        align-items: center;
        margin-bottom: 14px;
    }

    .order-finance-title {
        font-size: 15px;
    }

    .order-discount-badge {
        padding: 5px 8px;
        font-size: 10px;
    }

    .finance-row {
        grid-template-columns: minmax(0, 1fr) auto;
        column-gap: 12px;
    }

    .finance-label,
    .finance-value {
        font-size: 12px;
    }

    .finance-total-label {
        font-size: 15px;
    }

    .finance-total-value {
        font-size: 21px;
    }

    .order-saving-box {
        padding: 10px 11px;
    }

    .order-saving-title {
        font-size: 12px;
    }

    .order-saving-note {
        font-size: 10px;
    }
}

</style>

<div class="order-detail-page py-4">

    <div class="container">

        <div

            class="d-flex flex-wrap gap-3 align-items-center justify-content-between mb-4"

        >

            <div>

                <h4 class="mb-1">

                    📦 Chi tiết đơn hàng

                </h4>

                <div class="text-muted small">

                    Thông tin và sản phẩm trong đơn hàng.

                </div>

            </div>

            <a

                href="don_hang_da_dat.php"

                class="btn btn-outline-secondary"

            >

                ⬅ Quay lại lịch sử

            </a>

        </div>

        <div class="detail-card mb-4">

            <div class="p-3 border-bottom">

                <h5 class="mb-0">

                    Sản phẩm đã mua

                </h5>

            </div>

            <?php if (empty($chiTietList)): ?>

                <div class="empty-detail text-muted">

                    Đơn hàng chưa có chi tiết sản phẩm.

                </div>

            <?php else: ?>

                <div class="table-responsive product-table-wrap">

                    <table class="table align-middle mb-0 product-order-table">

                        <thead class="table-light">

                            <tr>

                                <th>

                                    Sản phẩm

                                </th>

                                <th>

                                    Mã sản phẩm

                                </th>

                                <th>

                                    Giá lúc đặt

                                </th>

                                <th>

                                    Số lượng

                                </th>

                                <th>

                                    Thành tiền

                                </th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($chiTietList as $item): ?>

                                <?php

                                $gia =

                                    (float)$item['gia'];

                                $giaGocHienThi =

                                    (float)$item['gia_goc_hien_thi'];

                                $giamMoiSanPham =

                                    (float)$item['giam_moi_san_pham'];

                                $soLuong =

                                    (int)$item['so_luong'];

                                $thanhTien =

                                    $gia * $soLuong;

                                $tenSanPham =

                                    trim(

                                        (string)(

                                            $item['ten_san_pham']

                                            ?? ''

                                        )

                                    );

                                if ($tenSanPham === '') {

                                    $tenSanPham =

                                        'Sản phẩm #' .

                                        (int)$item['san_pham_id'];

                                }

                                ?>

                                <tr>

                                    <td>

                                        <div class="d-flex gap-3">

                                            <?php if (

                                                !empty(

                                                    $item['hinh_anh']

                                                )

                                            ): ?>

                                                <img

                                                    src="../../uploads/<?= htmlspecialchars(

                                                        $item['hinh_anh'],

                                                        ENT_QUOTES,

                                                        'UTF-8'

                                                    ) ?>"

                                                    class="product-image"

                                                    alt="<?= htmlspecialchars(

                                                        $tenSanPham,

                                                        ENT_QUOTES,

                                                        'UTF-8'

                                                    ) ?>"

                                                    title="<?= !empty(

                                                        $item['hinh_anh_bien_the']

                                                    )

                                                        ? 'Ảnh đúng theo biến thể đã đặt'

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

                                                <div class="product-name">

                                                    <?= htmlspecialchars(

                                                        $tenSanPham

                                                    ) ?>

                                                </div>

                                                <?php if (

                                                    !empty(

                                                        $item['ten_mau']

                                                    )

                                                ): ?>

                                                    <div class="product-meta mt-1">

                                                        Màu:

                                                        <b>

                                                            <span

                                                                class="color-dot"

                                                                style="color:<?= htmlspecialchars(

                                                                    $item['ma_mau']

                                                                    ?? '#777'

                                                                ) ?>"

                                                            >

                                                                ●

                                                            </span>

                                                            <?= htmlspecialchars(

                                                                $item['ten_mau']

                                                            ) ?>

                                                        </b>

                                                    </div>

                                                <?php endif; ?>

                                                <?php if (

                                                    !empty(

                                                        $item['bien_the_id']

                                                    )

                                                ): ?>

                                                    <div class="product-meta">

                                                        Phiên bản:

                                                        <?php if (

                                                            $item['ram'] !== null ||

                                                            $item['rom'] !== null

                                                        ): ?>

                                                            <b>

                                                                <?= htmlspecialchars(

                                                                    (string)(

                                                                        $item['ram']

                                                                        ?? ''

                                                                    )

                                                                ) ?>

                                                                /

                                                                <?= htmlspecialchars(

                                                                    (string)(

                                                                        $item['rom']

                                                                        ?? ''

                                                                    )

                                                                ) ?>

                                                            </b>

                                                        <?php else: ?>

                                                            <span class="text-muted">

                                                                Biến thể cũ không còn tồn tại

                                                            </span>

                                                        <?php endif; ?>

                                                    </div>

                                                <?php endif; ?>

                                            </div>

                                        </div>

                                    </td>

                                    <td class="product-code-cell">

                                        <?php

                                        $maSanPham = trim(

                                            (string)($item['ma_san_pham'] ?? '')

                                        );

                                        ?>

                                        <?php if ($maSanPham !== ''): ?>

                                            <span class="product-code-badge">

                                                <?= htmlspecialchars($maSanPham) ?>

                                            </span>

                                        <?php else: ?>

                                            <span class="product-code-empty">

                                                Chưa có mã

                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td>

                                        <div class="product-price-block">

                                            <div class="product-price-line">

                                                <?php if ($giaGocHienThi > $gia): ?>

                                                    <span class="product-old-price">

                                                        <?= number_format(

                                                            $giaGocHienThi,

                                                            0,

                                                            ',',

                                                            '.'

                                                        ) ?>đ

                                                    </span>

                                                <?php endif; ?>

                                                <span class="order-price">

                                                    <?= number_format(

                                                        $gia,

                                                        0,

                                                        ',',

                                                        '.'

                                                    ) ?>đ

                                                </span>

                                            </div>

                                            <?php if ($giamMoiSanPham > 0): ?>

                                                <div class="product-saving-row">

                                                    <span class="product-discount-chip">
                                                        -<?= rtrim(
                                                            rtrim(
                                                                number_format(
                                                                    (float)($item['phan_tram_giam_hien_thi'] ?? 0),
                                                                    1,
                                                                    '.',
                                                                    ''
                                                                ),
                                                                '0'
                                                            ),
                                                            '.'
                                                        ) ?>%
                                                    </span>

                                                    <span class="product-saving">
                                                        Tiết kiệm
                                                        <?= number_format(
                                                            $giamMoiSanPham,
                                                            0,
                                                            ',',
                                                            '.'
                                                        ) ?>đ / sản phẩm
                                                    </span>

                                                </div>

                                            <?php endif; ?>

                                        </div>

                                    </td>

                                    <td>

                                        <span class="product-quantity-value"><?= $soLuong ?></span>

                                    </td>

                                    <td>

                                        <span class="order-price product-total-value">

                                            <?= number_format(

                                                $thanhTien,

                                                0,

                                                ',',

                                                '.'

                                            ) ?>đ

                                        </span>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </div>

        <div class="detail-card mb-4">

            <div class="receiver-card">

                <div class="receiver-card-title">

                    <span>📍</span>

                    <span>Thông tin nhận hàng</span>

                </div>

                <div class="receiver-grid">

                    <div class="receiver-item">

                        <div class="receiver-label">

                            Người nhận

                        </div>

                        <div class="receiver-value">

                            <?php if ($tenNguoiNhan !== ''): ?>

                                <?= htmlspecialchars(

                                    $tenNguoiNhan,

                                    ENT_QUOTES,

                                    'UTF-8'

                                ) ?>

                            <?php else: ?>

                                <span class="receiver-empty">

                                    Chưa lưu thông tin

                                </span>

                            <?php endif; ?>

                        </div>

                    </div>

                    <div class="receiver-item">

                        <div class="receiver-label">

                            Số điện thoại

                        </div>

                        <div class="receiver-value">

                            <?php if ($soDienThoaiNhan !== ''): ?>

                                <?= htmlspecialchars(

                                    $soDienThoaiNhan,

                                    ENT_QUOTES,

                                    'UTF-8'

                                ) ?>

                            <?php else: ?>

                                <span class="receiver-empty">

                                    Chưa lưu thông tin

                                </span>

                            <?php endif; ?>

                        </div>

                    </div>

                    <div class="receiver-item address">

                        <div class="receiver-label">

                            Địa chỉ giao hàng

                        </div>

                        <div class="receiver-value">

                            <?php if ($diaChiNhan !== ''): ?>

                                <?= nl2br(

                                    htmlspecialchars(

                                        $diaChiNhan,

                                        ENT_QUOTES,

                                        'UTF-8'

                                    )

                                ) ?>

                            <?php else: ?>

                                <span class="receiver-empty">

                                    Chưa lưu thông tin

                                </span>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

                <?php if (!$coThongTinNhanHang): ?>

                    <div class="receiver-legacy-note">

                        Đơn hàng này được tạo trước khi hệ thống lưu

                        thông tin nhận hàng riêng theo từng đơn,

                        nên chưa có dữ liệu người nhận được lưu cố định.

                    </div>

                <?php endif; ?>

            </div>

        </div>

        <div class="detail-card mb-4">

            <div class="info-box">

                <h5 class="mb-3">

                    Thông tin đơn hàng

                </h5>

                <div class="info-row">

                    <span class="text-muted">

                        Mã vận đơn

                    </span>

                    <div class="text-end">

                        <?php if ($maVanDon !== ''): ?>

                            <span class="tracking-code">

                                <?= htmlspecialchars($maVanDon) ?>

                            </span>

                        <?php else: ?>

                            <div class="tracking-empty">

                                Chưa có mã vận đơn

                            </div>

                            <div class="tracking-note">

                                Mã sẽ được tạo khi đơn chuyển sang Đang giao.

                            </div>

                        <?php endif; ?>

                    </div>

                </div>

                <div class="info-row">

                    <span class="text-muted">

                        Ngày đặt

                    </span>

                    <b>

                        <?= !empty($donHang['ngay_dat'])

                            ? date(

                                'd/m/Y H:i',

                                strtotime(

                                    $donHang['ngay_dat']

                                )

                            )

                            : '-' ?>

                    </b>

                </div>

                <div class="info-row">

                    <span class="text-muted">

                        Trạng thái đơn hàng

                    </span>

                    <span>

                        <span

                            class="badge <?= orderStatusClass(

                                $trangThaiDon

                            ) ?>"

                        >

                            <?= htmlspecialchars(

                                orderStatusLabel(

                                    $trangThaiDon

                                )

                            ) ?>

                        </span>

                    </span>

                </div>

                <div class="info-row">

                    <span class="text-muted">

                        Phương thức thanh toán

                    </span>

                    <span>

                        <?= $phuongThuc !== ''

                            ? htmlspecialchars($phuongThuc)

                            : 'Chưa có thông tin' ?>

                    </span>

                </div>

                <div class="info-row">

                    <span class="text-muted">

                        Trạng thái thanh toán

                    </span>

                    <span>

                        <?php if ($trangThaiThanhToan !== ''): ?>

                            <span

                                class="badge <?= paymentStatusClass(

                                    $trangThaiThanhToan

                                ) ?>"

                            >

                                <?= htmlspecialchars(

                                    $trangThaiThanhToan

                                ) ?>

                            </span>

                        <?php else: ?>

                            <span class="text-muted">

                                Chưa có thông tin

                            </span>

                        <?php endif; ?>

                    </span>

                </div>

                <div class="order-finance">

                    <div class="order-finance-head">

                        <div>
                            <div class="order-finance-eyebrow">
                                CHI TIẾT THANH TOÁN
                            </div>

                            <div class="order-finance-title">
                                🧾 Tóm tắt giá trị đơn hàng
                            </div>
                        </div>

                        <?php if ($tongGiamGiaSanPham > 0): ?>

                            <span class="order-discount-badge">
                                Tiết kiệm
                                <?= rtrim(
                                    rtrim(
                                        number_format(
                                            $phanTramTietKiemDon,
                                            1,
                                            '.',
                                            ''
                                        ),
                                        '0'
                                    ),
                                    '.'
                                ) ?>%
                            </span>

                        <?php endif; ?>

                    </div>

                    <div class="finance-row">
                        <span class="finance-label">
                            Giá gốc sản phẩm
                        </span>

                        <span class="finance-value">
                            <?= number_format(
                                $tongGiaGoc,
                                0,
                                ',',
                                '.'
                            ) ?>đ
                        </span>
                    </div>

                    <div class="finance-row">
                        <span class="finance-label">
                            Ưu đãi / giảm giá sản phẩm
                        </span>

                        <span class="finance-value finance-discount">
                            <?php if ($tongGiamGiaSanPham > 0): ?>
                                -<?= number_format(
                                    $tongGiamGiaSanPham,
                                    0,
                                    ',',
                                    '.'
                                ) ?>đ
                            <?php else: ?>
                                0đ
                            <?php endif; ?>
                        </span>
                    </div>

                    <div class="finance-row finance-after-discount">
                        <span class="finance-label">
                            Tạm tính sau ưu đãi
                        </span>

                        <span class="finance-value">
                            <?= number_format(
                                $tongSauUuDaiSanPham,
                                0,
                                ',',
                                '.'
                            ) ?>đ
                        </span>
                    </div>

                    <?php if (abs($chenhLechSauUuDai) >= 0.01): ?>

                        <div class="finance-row">
                            <span class="finance-label">
                                Điều chỉnh khác
                            </span>

                            <span class="finance-value <?= $chenhLechSauUuDai < 0
                                ? 'finance-discount'
                                : '' ?>">
                                <?= $chenhLechSauUuDai > 0 ? '+' : '' ?>
                                <?= number_format(
                                    $chenhLechSauUuDai,
                                    0,
                                    ',',
                                    '.'
                                ) ?>đ
                            </span>
                        </div>

                    <?php endif; ?>

                    <div class="finance-divider"></div>

                    <div class="finance-row finance-total-row">
                        <span class="finance-total-label">
                            Tổng thanh toán
                        </span>

                        <span class="finance-total-value">
                            <?= number_format(
                                $tongDonHang,
                                0,
                                ',',
                                '.'
                            ) ?>đ
                        </span>
                    </div>

                    <?php if ($tongGiamGiaSanPham > 0): ?>

                        <div class="order-saving-box">

                            <div class="order-saving-icon">
                                ✓
                            </div>

                            <div>
                                <div class="order-saving-title">
                                    Bạn đã tiết kiệm
                                    <?= number_format(
                                        $tongGiamGiaSanPham,
                                        0,
                                        ',',
                                        '.'
                                    ) ?>đ
                                </div>

                                <div class="order-saving-note">
                                    Giá trong đơn là giá đã lưu tại thời điểm đặt hàng.
                                </div>
                            </div>

                        </div>

                    <?php endif; ?>

                </div>

                </div>

            </div>

        <?php if ($coTheTuHuy): ?>

            <div class="detail-card mb-4">

                <div class="user-cancel-card">

                    <div class="user-cancel-title">

                        Hủy đơn hàng

                    </div>

                    <div class="user-cancel-help">

                        Bạn có thể tự hủy khi đơn vẫn đang

                        <strong>Chờ xử lý</strong>,

                        tức là trước khi cửa hàng chuyển đơn sang

                        <strong>Đã duyệt</strong>.

                        Thì lúc đó đơn của bạn sẽ không được hủy nữa! Xin cảm ơn!

                    </div>

                    <form

                        method="POST"

                        action="huy_don.php"

                        class="user-cancel-form"

                        onsubmit="return confirm(

                            'Bạn chắc chắn muốn hủy đơn hàng này?'

                        );"

                    >

                        <input

                            type="hidden"

                            name="id"

                            value="<?= (int)$donHangId ?>"

                        >

                        <input

                            type="hidden"

                            name="csrf_token"

                            value="<?= htmlspecialchars(

                                $userCancelCsrf

                            ) ?>"

                        >

                        <div>

                            <label

                                for="userCancelReason"

                                class="form-label fw-bold mb-1"

                            >

                                Lý do hủy đơn

                            </label>

                            <select

                                name="ly_do_huy"

                                id="userCancelReason"

                                class="form-select"

                                required

                            >

                                <option value="">

                                    -- Chọn lý do hủy đơn --

                                </option>

                                <?php foreach ($userCancelReasons as $reason): ?>

                                    <option

                                        value="<?= htmlspecialchars($reason) ?>"

                                    >

                                        <?= htmlspecialchars($reason) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <button

                            type="submit"

                            class="btn btn-outline-danger user-cancel-btn"

                        >

                            Hủy đơn

                        </button>

                    </form>

                </div>

            </div>

        <?php endif; ?>

    </div>

</div>

<script>

/* =========================================================

   BACK TRÌNH DUYỆT → LOAD LẠI TRẠNG THÁI

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
