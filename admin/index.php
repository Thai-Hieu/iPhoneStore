<?php

if (session_status() === PHP_SESSION_NONE) {

    session_start();

}

require_once "../middleware/check_admin.php";

require_once "../config/database.php";
require_once "../includes/khuyen_mai_helper.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

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

        'hoan_thanh' => 'Hoàn thành',

        'Hoàn thành' => 'Hoàn thành',

        'da_giao' => 'Hoàn thành',

        'Đã giao' => 'Hoàn thành',

        'huy' => 'Đã hủy',

        'da_huy' => 'Đã hủy',

        'Hủy' => 'Đã hủy',

        'Đã hủy' => 'Đã hủy'

    ];

    return $labels[$status] ?? ($status !== '' ? $status : 'Chưa xác định');

}

function orderStatusClass(string $status): string

{

    $status = trim($status);

    if (in_array($status, ['cho_xu_ly', 'da_dat', 'Đã đặt', 'cho_xac_nhan', 'Chờ xác nhận'], true)) {

        return 'warning';

    }

    if (in_array($status, ['da_duyet', 'dang_xu_ly', 'Đang xử lý'], true)) {

        return 'primary';

    }

    if (in_array($status, ['dang_giao', 'Đang giao'], true)) {

        return 'info';

    }

    if (in_array($status, ['hoan_thanh', 'Hoàn thành', 'da_giao', 'Đã giao'], true)) {

        return 'success';

    }

    if (in_array($status, ['huy', 'da_huy', 'Hủy', 'Đã hủy'], true)) {

        return 'danger';

    }

    return 'secondary';

}

function paymentStatusClass(string $status): string

{

    $status = trim($status);

    if (in_array($status, ['Đã thanh toán', 'Thành công'], true)) {

        return 'success';

    }

    if (in_array($status, ['Chờ xác nhận', 'Chờ thanh toán', 'Chờ xử lý'], true)) {

        return 'warning';

    }

    if ($status === 'Chưa thanh toán') {

        return 'secondary';

    }

    if (in_array($status, ['Thất bại', 'Đã hủy'], true)) {

        return 'danger';

    }

    return 'secondary';

}

function getScalar(mysqli $conn, string $sql, string $column = 'tong'): float|int

{

    $result = $conn->query($sql);

    $row = $result ? $result->fetch_assoc() : null;

    return $row[$column] ?? 0;

}

/* =========================================================
   THỐNG KÊ KHUYẾN MÃI CHO DASHBOARD

   Dashboard hiện dùng trực tiếp bảng khuyen_mai.
   Không còn phụ thuộc:
   - chuong_trinh_sale
   - chuong_trinh_sale_san_pham

   Một chương trình được tính là đang hoạt động khi:
   - trang_thai = 1;
   - hiện tại nằm trong ngay_bat_dau -> ngay_ket_thuc;
   - nếu có gio_bat_dau / gio_ket_thuc thì phải nằm trong khung giờ;
   - khuyến mãi phải còn hàng đúng phạm vi áp dụng.
========================================================= */
function adminPromotionIsActive(
    array $promo,
    ?DateTimeImmutable $now = null
): bool {
    if ((int)($promo['trang_thai'] ?? 0) !== 1) {
        return false;
    }

    $tz = new DateTimeZone('Asia/Ho_Chi_Minh');

    $now ??= new DateTimeImmutable(
        'now',
        $tz
    );

    $startText = trim((string)($promo['ngay_bat_dau'] ?? ''));
    $endText = trim((string)($promo['ngay_ket_thuc'] ?? ''));

    if ($startText === '' || $endText === '') {
        return false;
    }

    try {
        $start = new DateTimeImmutable(
            $startText,
            $tz
        );

        $end = new DateTimeImmutable(
            $endText,
            $tz
        );
    } catch (Throwable $e) {
        return false;
    }

    if ($now < $start || $now > $end) {
        return false;
    }

    $timeStart = trim((string)($promo['gio_bat_dau'] ?? ''));
    $timeEnd = trim((string)($promo['gio_ket_thuc'] ?? ''));

    if ($timeStart !== '' || $timeEnd !== '') {
        if ($timeStart === '' || $timeEnd === '') {
            return false;
        }

        $currentTime = $now->format('H:i:s');
        $timeStart = substr($timeStart, 0, 8);
        $timeEnd = substr($timeEnd, 0, 8);

        /*
            Cùng ngày:
            12:00 -> 14:00
        */
        if ($timeStart <= $timeEnd) {
            if (
                $currentTime < $timeStart ||
                $currentTime > $timeEnd
            ) {
                return false;
            }
        } else {
            /*
                Qua đêm:
                22:00 -> 02:00
            */
            if (
                $currentTime < $timeStart &&
                $currentTime > $timeEnd
            ) {
                return false;
            }
        }
    }

    return true;
}

/* =========================================================
   THỐNG KÊ TỔNG QUAN
========================================================= */

$tongSanPham = (int)getScalar(
    $conn,
    "SELECT COUNT(*) AS tong FROM san_pham"
);

$tongBienThe = (int)getScalar(
    $conn,
    "SELECT COUNT(*) AS tong
     FROM san_pham_bien_the bt
     JOIN san_pham sp ON sp.id = bt.san_pham_id
     WHERE sp.trang_thai = 1"
);

$tongDonHang = (int)getScalar(
    $conn,
    "SELECT COUNT(*) AS tong FROM don_hang"
);

$tongNguoiDung = (int)getScalar(
    $conn,
    "SELECT COUNT(*) AS tong
     FROM nguoi_dung nd
     JOIN vai_tro vt ON vt.id = nd.vai_tro_id
     WHERE vt.vai_tro = 'user'"
);

/*
   Tổng số bản ghi khuyến mãi đang quản lý.
   Dùng bảng khuyen_mai - hệ thống sale thực tế của website.
*/
$tongChuongTrinhSale = (int)getScalar(
    $conn,
    "SELECT COUNT(*) AS tong
     FROM khuyen_mai"
);

$saleDangHoatDong = 0;

$rowsSale = $conn->query("
    SELECT
        km.id,
        km.trang_thai,
        km.ngay_bat_dau,
        km.ngay_ket_thuc,
        km.gio_bat_dau,
        km.gio_ket_thuc,
        km.san_pham_id,
        km.bien_the_id,
        COALESCE(bt.so_luong, 0) AS ton_bien_the,
        CASE
            WHEN EXISTS (
                SELECT 1
                FROM san_pham_bien_the bt_exists
                WHERE bt_exists.san_pham_id = sp.id
            )
            THEN COALESCE((
                SELECT SUM(bt_stock.so_luong)
                FROM san_pham_bien_the bt_stock
                WHERE bt_stock.san_pham_id = sp.id
            ), 0)
            ELSE COALESCE(sp.so_luong, 0)
        END AS tong_ton_san_pham
    FROM khuyen_mai km
    JOIN san_pham sp ON sp.id = km.san_pham_id
    LEFT JOIN san_pham_bien_the bt ON bt.id = km.bien_the_id
    ORDER BY km.id DESC
");

if ($rowsSale) {
    $saleNow = new DateTimeImmutable(
        'now',
        new DateTimeZone('Asia/Ho_Chi_Minh')
    );

    while ($saleRow = $rowsSale->fetch_assoc()) {
        $hasVariant = !empty($saleRow['bien_the_id']);
        $currentStock = $hasVariant
            ? (int)($saleRow['ton_bien_the'] ?? 0)
            : (int)($saleRow['tong_ton_san_pham'] ?? 0);

        if ($currentStock > 0 && adminPromotionIsActive($saleRow, $saleNow)) {
            $saleDangHoatDong++;
        }
    }
}

/*
   Doanh thu   trên dashboard:
   - Chỉ tính các đơn đã Hoàn thành.
   - Không phụ thuộc trạng thái thanh toán.
*/

$revenueSql = "
    FROM don_hang dh
    WHERE dh.trang_thai IN (
        'hoan_thanh',
        'Hoàn thành',
        'da_giao',
        'Đã giao'
    )
";

$tongDoanhThu = (float)getScalar(

    $conn,

    "SELECT COALESCE(SUM(dh.tong_tien), 0) AS tong " . $revenueSql

);

$doanhThuThang = (float)getScalar(
    $conn,
    "SELECT COALESCE(SUM(dh.tong_tien), 0) AS tong " .
    $revenueSql .
    " AND YEAR(dh.ngay_dat) = YEAR(CURDATE())
      AND MONTH(dh.ngay_dat) = MONTH(CURDATE())"
);

$donChoXuLy = (int)getScalar(

    $conn,

    "SELECT COUNT(*) AS tong

     FROM don_hang

     WHERE trang_thai IN ('cho_xu_ly', 'da_dat', 'Đã đặt', 'cho_xac_nhan', 'Chờ xác nhận')"

);

$bienTheSapHet = (int)getScalar(

    $conn,

    "SELECT COUNT(*) AS tong

     FROM san_pham_bien_the bt

     JOIN san_pham sp ON sp.id = bt.san_pham_id

     WHERE sp.trang_thai = 1

       AND bt.so_luong BETWEEN 1 AND 5"

);

$bienTheHetHang = (int)getScalar(

    $conn,

    "SELECT COUNT(*) AS tong

     FROM san_pham_bien_the bt

     JOIN san_pham sp ON sp.id = bt.san_pham_id

     WHERE sp.trang_thai = 1

       AND bt.so_luong <= 0"

);

/* =========================================================

   5 ĐƠN HÀNG MỚI NHẤT

========================================================= */

$recentOrders = [];

$resultRecentOrders = $conn->query("

    SELECT

        dh.id,

        dh.ma_van_don,

        dh.ten_nguoi_nhan,

        dh.so_dien_thoai_nhan,

        dh.tong_tien,

        dh.trang_thai,

        dh.ly_do_huy,

        dh.nguoi_huy,

        dh.ngay_dat,

        nd.ho_ten AS ten_tai_khoan,

        tt.phuong_thuc,

        tt.trang_thai AS trang_thai_thanh_toan

    FROM don_hang dh

    JOIN nguoi_dung nd ON nd.id = dh.nguoi_dung_id

    LEFT JOIN thanh_toan tt

        ON tt.id = (

            SELECT MAX(t2.id)

            FROM thanh_toan t2

            WHERE t2.don_hang_id = dh.id

        )

    ORDER BY dh.ngay_dat DESC, dh.id DESC

    LIMIT 5

");

while ($row = $resultRecentOrders->fetch_assoc()) {

    $recentOrders[] = $row;

}

/* =========================================================

   BIẾN THỂ CẦN CHÚ Ý

========================================================= */

$lowStockVariants = [];

$resultLowStockVariants = $conn->query("

    SELECT

        bt.id AS bien_the_id,

        bt.san_pham_id,

        bt.ram,

        bt.rom,

        bt.gia,

        bt.so_luong,

        sp.ma_san_pham,

        sp.ten_san_pham,

        sp.hinh_anh

    FROM san_pham_bien_the bt

    JOIN san_pham sp ON sp.id = bt.san_pham_id

    WHERE sp.trang_thai = 1

      AND bt.so_luong <= 5

    ORDER BY bt.so_luong ASC, bt.id DESC

    LIMIT 6

");

while ($row = $resultLowStockVariants->fetch_assoc()) {

    $lowStockVariants[] = $row;

}

/* =========================================================

   DOANH THU 6 THÁNG GẦN NHẤT

========================================================= */

$monthlyRevenue = [];

$revenueByMonth = [];

$resultMonthlyRevenue = $conn->query("
    SELECT
        DATE_FORMAT(dh.ngay_dat, '%Y-%m') AS thang,
        COALESCE(SUM(dh.tong_tien), 0) AS doanh_thu
    FROM don_hang dh
    WHERE dh.trang_thai IN (
        'hoan_thanh',
        'Hoàn thành',
        'da_giao',
        'Đã giao'
    )
      AND dh.ngay_dat >= DATE_SUB(
          DATE_FORMAT(CURDATE(), '%Y-%m-01'),
          INTERVAL 5 MONTH
      )
    GROUP BY DATE_FORMAT(dh.ngay_dat, '%Y-%m')
    ORDER BY thang ASC
");

while ($row = $resultMonthlyRevenue->fetch_assoc()) {

    $revenueByMonth[$row['thang']] = (float)$row['doanh_thu'];

}

for ($i = 5; $i >= 0; $i--) {

    $monthKey = date('Y-m', strtotime("-$i month"));

    $monthlyRevenue[] = [

        'label' => date('m/Y', strtotime($monthKey . '-01')),

        'value' => $revenueByMonth[$monthKey] ?? 0

    ];

}

$maxRevenue = 0;

foreach ($monthlyRevenue as $item) {

    if ($item['value'] > $maxRevenue) {

        $maxRevenue = $item['value'];

    }

}

?>

<?php require_once "../includes/header.php"; ?>

<?php require_once "../includes/navbar.php"; ?>

<style>

:root {

    --admin-primary: #ee4d2d;

    --admin-primary-dark: #d93f20;

    --admin-bg: #f5f6f8;

    --admin-card: #ffffff;

    --admin-text: #1f2937;

    --admin-muted: #6b7280;

    --admin-border: #eceff3;

}

.admin-dashboard {

    min-height: 75vh;

    padding: 28px 0 45px;

    background:

        radial-gradient(circle at top right, rgba(238, 77, 45, .08), transparent 28%),

        var(--admin-bg);

}

.dashboard-header {

    display: flex;

    flex-wrap: wrap;

    align-items: center;

    justify-content: space-between;

    gap: 18px;

    margin-bottom: 26px;

}

.dashboard-title {

    margin: 0;

    color: var(--admin-text);

    font-size: 30px;

    font-weight: 800;

}

.dashboard-subtitle {

    margin-top: 5px;

    color: var(--admin-muted);

    font-size: 14px;

}

.dashboard-time {

    padding: 10px 14px;

    border: 1px solid var(--admin-border);

    border-radius: 12px;

    background: #fff;

    color: #555;

    font-size: 13px;

    box-shadow: 0 5px 18px rgba(0, 0, 0, .03);

}

.stat-card {

    height: 100%;

    min-height: 108px;

    display: grid;

    grid-template-columns: 42px minmax(0, 1fr);

    grid-template-rows: auto auto auto;

    column-gap: 14px;

    align-content: center;

    padding: 15px 18px;

    border: 1px solid var(--admin-border);

    border-radius: 15px;

    background: var(--admin-card);

    box-shadow: 0 6px 20px rgba(0, 0, 0, .035);

    transition: .2s;

}

.stat-card:hover {

    transform: translateY(-3px);

    box-shadow: 0 13px 34px rgba(0, 0, 0, .08);

}

.stat-icon {

    width: 42px;

    height: 42px;

    grid-row: 1 / 4;

    align-self: center;

    display: flex;

    align-items: center;

    justify-content: center;

    margin: 0;

    border-radius: 12px;

    font-size: 20px;

}

.stat-icon.product { background: #fff1ed; }

.stat-icon.order { background: #eef5ff; }

.stat-icon.user { background: #edf9f1; }

.stat-icon.revenue { background: #fff8e7; }

.stat-label {

    min-width: 0;

    color: var(--admin-muted);

    font-size: 12.5px;

    line-height: 1.25;

    font-weight: 600;

    white-space: nowrap;

}

.stat-value {

    min-width: 0;

    margin-top: 2px;

    color: var(--admin-text);

    font-size: 26px;

    line-height: 1.1;

    font-weight: 800;

}

.stat-value.money {

    color: var(--admin-primary);

    font-size: 21px;

    white-space: nowrap;

}

.stat-note {

    min-width: 0;

    margin-top: 3px;

    color: #9ca3af;

    font-size: 11px;

    line-height: 1.3;

}

.mini-status-card {

    height: 100%;

    padding: 17px 18px;

    border: 1px solid var(--admin-border);

    border-radius: 15px;

    background: #fff;

    transition: .2s;

}

.mini-status-card:hover {

    transform: translateY(-2px);

    box-shadow: 0 8px 20px rgba(0,0,0,.05);

}

.mini-status-label {

    color: var(--admin-muted);

    font-size: 13px;

}

.mini-status-number {

    margin-top: 5px;

    font-size: 24px;

    font-weight: 800;

}

.text-orange { color: var(--admin-primary); }

.dashboard-card {

    height: 100%;

    overflow: hidden;

    border: 1px solid var(--admin-border);

    border-radius: 18px;

    background: #fff;

    box-shadow: 0 8px 26px rgba(0, 0, 0, .04);

}

.dashboard-card-header {

    display: flex;

    flex-wrap: wrap;

    align-items: center;

    justify-content: space-between;

    gap: 12px;

    padding: 18px 20px;

    border-bottom: 1px solid var(--admin-border);

}

.dashboard-card-title {

    margin: 0;

    color: var(--admin-text);

    font-size: 17px;

    font-weight: 800;

}

.dashboard-card-body {

    padding: 20px;

}

.quick-action {

    display: flex;

    align-items: center;

    gap: 10px;

    height: 100%;

    min-height: 78px;

    padding: 13px 14px;

    border: 1px solid var(--admin-border);

    border-radius: 14px;

    background: #fff;

    color: inherit;

    text-decoration: none;

    transition: .2s;

}

.quick-action:hover {

    transform: translateY(-2px);

    border-color: #ffc1b3;

    background: #fffaf8;

    color: inherit;

    box-shadow: 0 8px 20px rgba(238, 77, 45, .07);

}

.quick-action-icon {

    width: 42px;

    height: 42px;

    flex: 0 0 42px;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 12px;

    background: #f5f6f8;

    font-size: 20px;

}

.quick-action-content {

    min-width: 0;

    flex: 1;

}

.quick-action-title {

    color: var(--admin-text);

    font-size: 15px;

    line-height: 1.25;

    font-weight: 800;

    white-space: nowrap;

}

.quick-action-desc {

    margin-top: 3px;

    overflow: hidden;

    color: var(--admin-muted);

    font-size: 11.5px;

    line-height: 1.35;

    text-overflow: ellipsis;

    white-space: nowrap;

}

.quick-actions-row {

    --bs-gutter-x: 12px;

    --bs-gutter-y: 12px;

}

@media (max-width: 1199.98px) {

    .quick-action-title,

    .quick-action-desc {

        white-space: normal;

    }

}

.revenue-chart {

    height: 240px;

    display: flex;

    align-items: end;

    gap: 14px;

    padding-top: 20px;

}

.revenue-column {

    flex: 1;

    min-width: 0;

    text-align: center;

}

.revenue-bar-wrap {

    height: 165px;

    display: flex;

    align-items: end;

    justify-content: center;

}

.revenue-bar {

    width: min(44px, 78%);

    min-height: 4px;

    border-radius: 9px 9px 4px 4px;

    background: linear-gradient(180deg, #ff7b5d, var(--admin-primary));

    transition: .25s;

}

.revenue-column:hover .revenue-bar {

    transform: translateY(-3px);

}

.revenue-month {

    margin-top: 10px;

    color: #777;

    font-size: 11px;

}

.revenue-value {

    min-height: 18px;

    margin-top: 4px;

    color: #444;

    font-size: 10px;

    font-weight: 700;

}

.variant-item {

    display: flex;

    align-items: center;

    gap: 12px;

    padding: 12px 0;

    border-bottom: 1px solid var(--admin-border);

}

.variant-item:first-child { padding-top: 0; }

.variant-item:last-child { padding-bottom: 0; border-bottom: 0; }

.variant-image {

    width: 48px;

    height: 48px;

    flex-shrink: 0;

    object-fit: contain;

    border: 1px solid #eee;

    border-radius: 10px;

    background: #fff;

}

.variant-no-image {

    width: 48px;

    height: 48px;

    flex-shrink: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    border: 1px solid #eee;

    border-radius: 10px;

    background: #fafafa;

    color: #aaa;

    font-size: 9px;

}

.variant-name {

    overflow: hidden;

    color: var(--admin-text);

    font-size: 13px;

    font-weight: 700;

    text-overflow: ellipsis;

    white-space: nowrap;

}

.variant-spec {

    margin-top: 2px;

    color: #6b7280;

    font-size: 11px;

    font-weight: 700;

}

.variant-stock {

    margin-top: 3px;

    font-size: 12px;

}

.variant-stock-scroll {
    max-height: 360px;
    overflow-y: auto;
    overflow-x: hidden;
    padding-right: 8px;
    scrollbar-width: thin;
    overscroll-behavior: contain;
}

.variant-stock-scroll::-webkit-scrollbar {
    width: 7px;
}

.variant-stock-scroll::-webkit-scrollbar-track {
    background: #f1f3f5;
    border-radius: 999px;
}

.variant-stock-scroll::-webkit-scrollbar-thumb {
    background: #c9ced6;
    border-radius: 999px;
}

.variant-stock-scroll::-webkit-scrollbar-thumb:hover {
    background: #aeb5bf;
}

.variant-stock-scroll .variant-item {
    flex-shrink: 0;
}

.recent-order-table {

    margin: 0;

}

.recent-order-table th {

    padding: 12px 14px;

    color: #777;

    font-size: 12px;

    white-space: nowrap;

}

.recent-order-table td {

    padding: 13px 14px;

    vertical-align: middle;

}

.product-code-badge,

.tracking-code {

    display: inline-flex;

    align-items: center;

    padding: 5px 8px;

    border-radius: 8px;

    font-size: 11px;

    font-weight: 800;

    letter-spacing: .4px;

    white-space: nowrap;

}

.tracking-code {

    border: 1px solid #cfe2ff;

    background: #eef6ff;

    color: #0d6efd;

}

.muted-small {

    color: #999;

    font-size: 11px;

}

.order-money {

    color: var(--admin-primary);

    font-weight: 800;

    white-space: nowrap;

}

.receiver-name {

    color: var(--admin-text);

    font-weight: 700;

}

.receiver-account {

    margin-top: 2px;

    color: #999;

    font-size: 10px;

}

.payment-method {

    margin-bottom: 4px;

    color: #555;

    font-size: 11px;

    font-weight: 700;

}

.cancel-reason {

    max-width: 260px;

    margin-top: 5px;

    color: #8b4a00;

    font-size: 10px;

    line-height: 1.4;

}

@media (max-width: 767px) {

    .admin-dashboard { padding-top: 20px; }

    .dashboard-title { font-size: 25px; }

    .stat-card {

        min-height: 102px;

        grid-template-columns: 38px minmax(0, 1fr);

        column-gap: 11px;

        padding: 14px 15px;

    }

    .stat-icon {

        width: 38px;

        height: 38px;

        font-size: 18px;

    }

    .stat-label {

        white-space: normal;

    }

    .stat-value { font-size: 24px; }

    .stat-value.money {

        font-size: 19px;

        white-space: normal;

    }

    .revenue-chart { gap: 6px; }

    .revenue-value { display: none; }

}

</style>

<div class="admin-dashboard">

    <div class="container-fluid px-3 px-lg-4">

        <div class="dashboard-header">

            <div>

                <h1 class="dashboard-title">Bảng điều khiển quản trị</h1>

                <div class="dashboard-subtitle">

                    Tổng quan iPhoneStore theo sản phẩm, đơn hàng, tồn kho và vận đơn.

                </div>

            </div>

            <div class="dashboard-time">

                🕒 <span id="dashboardTime"></span>

            </div>

        </div>

        <div class="row g-3 g-xl-4 mb-4">

            <div class="col-xl-3 col-md-6">

                <div class="stat-card">

                    <div class="stat-icon product">📱</div>

                    <div class="stat-label">Tổng sản phẩm</div>

                    <div class="stat-value"><?= number_format($tongSanPham) ?></div>

                    <div class="stat-note">

                        <?= number_format($tongBienThe) ?> biến thể của sản phẩm đang hoạt động

                    </div>

                </div>

            </div>

            <div class="col-xl-3 col-md-6">

                <div class="stat-card">

                    <div class="stat-icon order">🧾</div>

                    <div class="stat-label">Tổng đơn hàng</div>

                    <div class="stat-value"><?= number_format($tongDonHang) ?></div>

                    <div class="stat-note">

                        <?= number_format($donChoXuLy) ?> đơn đang chờ xử lý

                    </div>

                </div>

            </div>

            <div class="col-xl-3 col-md-6">

                <div class="stat-card">

                    <div class="stat-icon user">👥</div>

                    <div class="stat-label">Khách hàng</div>

                    <div class="stat-value"><?= number_format($tongNguoiDung) ?></div>

                    <div class="stat-note">Tài khoản có vai trò user</div>

                </div>

            </div>

            <div class="col-xl-3 col-md-6">

                <div class="stat-card">

                    <div class="stat-icon revenue">💰</div>

                    <div class="stat-label">Doanh thu đơn hoàn thành</div>

                    <div class="stat-value money">

                        <?= number_format($tongDoanhThu, 0, ',', '.') ?>đ

                    </div>

                    <div class="stat-note">

                        Tháng này: <?= number_format($doanhThuThang, 0, ',', '.') ?>đ<br>

                        Chỉ tính các đơn đã hoàn thành

                    </div>

                </div>

            </div>

        </div>

        <div class="row g-3 mb-4">

            <div class="col-xl-3 col-md-6">

                <a href="./don_hang/index.php" class="text-decoration-none text-reset">

                    <div class="mini-status-card">

                        <div class="mini-status-label">Đơn chờ xử lý</div>

                        <div class="mini-status-number text-warning">

                            <?= number_format($donChoXuLy) ?>

                        </div>

                    </div>

                </a>

            </div>

            <div class="col-xl-3 col-md-6">

                <a href="./san_pham/index.php" class="text-decoration-none text-reset">

                    <div class="mini-status-card">

                        <div class="mini-status-label">Biến thể sắp hết hàng</div>

                        <div class="mini-status-number text-orange">

                            <?= number_format($bienTheSapHet) ?>

                        </div>

                    </div>

                </a>

            </div>

            <div class="col-xl-3 col-md-6">

                <a href="./san_pham/index.php" class="text-decoration-none text-reset">

                    <div class="mini-status-card">

                        <div class="mini-status-label">Biến thể hết hàng</div>

                        <div class="mini-status-number text-danger">

                            <?= number_format($bienTheHetHang) ?>

                        </div>

                    </div>

                </a>

            </div>

        </div>

        <div class="dashboard-card mb-4">

            <div class="dashboard-card-header">

                <h2 class="dashboard-card-title">Truy cập nhanh</h2>

            </div>

            <div class="dashboard-card-body">

                <div class="row quick-actions-row">

                    <div class="col-xl col-md-4 col-sm-6">

                        <a href="./san_pham/index.php" class="quick-action">

                            <div class="quick-action-icon">📱</div>

                            <div class="quick-action-content">

                                <div class="quick-action-title">Sản phẩm</div>

                                <div class="quick-action-desc">Sản phẩm & biến thể</div>

                            </div>

                        </a>

                    </div>

                    <div class="col-xl col-md-4 col-sm-6">

                        <a href="./don_hang/index.php" class="quick-action">

                            <div class="quick-action-icon">🧾</div>

                            <div class="quick-action-content">

                                <div class="quick-action-title">Đơn hàng</div>

                                <div class="quick-action-desc">Duyệt & theo dõi đơn</div>

                            </div>

                        </a>

                    </div>

                    <div class="col-xl col-md-4 col-sm-6">

                        <a href="./nguoi_dung/index.php" class="quick-action">

                            <div class="quick-action-icon">👥</div>

                            <div class="quick-action-content">

                                <div class="quick-action-title">Người dùng</div>

                                <div class="quick-action-desc">Tài khoản & phân quyền</div>

                            </div>

                        </a>

                    </div>

                    <div class="col-xl col-md-4 col-sm-6">

                        <a href="./khuyen_mai/index.php" class="quick-action">

                            <div class="quick-action-icon">🏷️</div>

                            <div class="quick-action-content">

                                <div class="quick-action-title">Chương trình sale</div>

                                <div class="quick-action-desc"><?= number_format($saleDangHoatDong) ?> đang hoạt động / <?= number_format($tongChuongTrinhSale) ?> chương trình</div>

                            </div>

                        </a>

                    </div>

                    <div class="col-xl col-md-4 col-sm-6">

                        <a href="./thong_bao/index.php" class="quick-action">

                            <div class="quick-action-icon">🔔</div>

                            <div class="quick-action-content">

                                <div class="quick-action-title">Thông báo</div>

                                <div class="quick-action-desc">Theo dõi thông báo</div>

                            </div>

                        </a>

                    </div>

                    <div class="col-xl col-md-4 col-sm-6">

                        <a href="./thong_ke/index.php" class="quick-action">

                            <div class="quick-action-icon">📊</div>

                            <div class="quick-action-content">

                                <div class="quick-action-title">Thống kê</div>

                                <div class="quick-action-desc">Báo cáo & doanh thu</div>

                            </div>

                        </a>

                    </div>

                </div>

            </div>

        </div>

        <div class="row g-4 mb-4">

            <div class="col-xl-8">

                <div class="dashboard-card">

                    <div class="dashboard-card-header">

                        <div>

                            <h2 class="dashboard-card-title">Doanh thu đơn hoàn thành 6 tháng gần nhất</h2>

                            <div class="text-muted small mt-1">

                                Tính theo ngày đặt của các đơn đã hoàn thành.

                            </div>

                        </div>

                        <a href="./thong_ke/index.php" class="btn btn-sm btn-outline-danger">

                            Xem thống kê

                        </a>

                    </div>

                    <div class="dashboard-card-body">

                        <div class="revenue-chart">

                            <?php foreach ($monthlyRevenue as $item): ?>

                                <?php

                                $height = $maxRevenue > 0

                                    ? max(4, round($item['value'] / $maxRevenue * 100))

                                    : 4;

                                ?>

                                <div class="revenue-column">

                                    <div class="revenue-bar-wrap">

                                        <div

                                            class="revenue-bar"

                                            style="height:<?= $height ?>%"

                                            title="<?= number_format($item['value'], 0, ',', '.') ?>đ"

                                        ></div>

                                    </div>

                                    <div class="revenue-month">

                                        <?= htmlspecialchars($item['label']) ?>

                                    </div>

                                    <div class="revenue-value">

                                        <?= $item['value'] > 0

                                            ? number_format($item['value'], 0, ',', '.') . 'đ'

                                            : '0đ' ?>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        </div>

                    </div>

                </div>

            </div>

            <div class="col-xl-4">

                <div class="dashboard-card">

                    <div class="dashboard-card-header">

                        <div>

                            <h2 class="dashboard-card-title">Tồn kho biến thể cần chú ý</h2>

                            <div class="text-muted small mt-1">

                                Kiểm tra trực tiếp tồn kho từng RAM/ROM, không dùng tồn kho chung để xử lý bán hàng.

                            </div>

                        </div>

                    </div>

                    <div class="dashboard-card-body">

                        <?php if (empty($lowStockVariants)): ?>

                            <div class="text-center text-muted py-4">

                                ✅ Tồn kho biến thể hiện đang ổn định.

                            </div>

                        <?php else: ?>

                            <div class="variant-stock-scroll">

                            <?php foreach ($lowStockVariants as $variant): ?>

                                <div class="variant-item">

                                    <?php if (!empty($variant['hinh_anh'])): ?>

                                        <img

                                            src="../uploads/<?= htmlspecialchars($variant['hinh_anh']) ?>"

                                            class="variant-image"

                                            alt=""

                                        >

                                    <?php else: ?>

                                        <div class="variant-no-image">No image</div>

                                    <?php endif; ?>

                                    <div class="flex-grow-1" style="min-width:0;">

                                        <div class="variant-name">

                                            <?= htmlspecialchars($variant['ten_san_pham']) ?>

                                        </div>

                                        <div class="variant-spec">

                                            <?= htmlspecialchars((string)$variant['ram']) ?> RAM

                                            ·

                                            <?= htmlspecialchars((string)$variant['rom']) ?> ROM

                                        </div>

                                        <div class="variant-stock">

                                            <?php if ((int)$variant['so_luong'] <= 0): ?>

                                                <span class="text-danger fw-bold">Hết hàng</span>

                                            <?php else: ?>

                                                <span class="text-warning fw-bold">

                                                    Còn <?= (int)$variant['so_luong'] ?> sản phẩm

                                                </span>

                                            <?php endif; ?>

                                        </div>

                                    </div>

                                    <a

                                        href="./san_pham/sua.php?id=<?= (int)$variant['san_pham_id'] ?>"

                                        class="btn btn-sm btn-outline-secondary"

                                    >

                                        Sửa

                                    </a>

                                </div>

                            <?php endforeach; ?>

                            </div>

                        <?php endif; ?>

                    </div>

                </div>

            </div>

        </div>

        <div class="dashboard-card">

            <div class="dashboard-card-header">

                <div>

                    <h2 class="dashboard-card-title">Đơn hàng mới nhất</h2>

                    <div class="text-muted small mt-1">

                        Hiển thị trạng thái đơn, thanh toán   và mã vận đơn.

                    </div>

                </div>

                <a href="./don_hang/index.php" class="btn btn-sm btn-outline-dark">

                    Xem tất cả

                </a>

            </div>

            <?php if (empty($recentOrders)): ?>

                <div class="dashboard-card-body text-center text-muted">

                    Chưa có đơn hàng nào.

                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table table-hover recent-order-table">

                        <thead class="table-light">

                            <tr>

                                <th>Mã vận đơn</th>

                                <th>Người nhận</th>

                                <th>Tổng tiền</th>

                                <th>Thanh toán</th>

                                <th>Trạng thái đơn</th>

                                <th>Ngày đặt</th>

                                <th class="text-end">Thao tác</th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($recentOrders as $order): ?>

                                <?php
$orderStatus = trim((string)$order['trang_thai']);

                                $paymentStatus = trim((string)($order['trang_thai_thanh_toan'] ?? ''));

                                $receiverName = trim((string)($order['ten_nguoi_nhan'] ?? ''));

                                $cancelReason = trim((string)($order['ly_do_huy'] ?? ''));

                                $cancelledBy = trim((string)($order['nguoi_huy'] ?? ''));

                                ?>

                                <tr>

                                    <td>

                                        <?php if (!empty($order['ma_van_don'])): ?>

                                            <span class="tracking-code">

                                                <?= htmlspecialchars((string)$order['ma_van_don']) ?>

                                            </span>

                                        <?php else: ?>

                                            <span class="muted-small">Chưa có mã</span>

                                        <?php endif; ?>

                                    </td>

                                    <td>

                                        <?php if ($receiverName !== ''): ?>

                                            <div class="receiver-name">

                                                <?= htmlspecialchars($receiverName) ?>

                                            </div>

                                        <?php else: ?>

                                            <div class="receiver-name text-muted">Chưa lưu người nhận</div>

                                        <?php endif; ?>

                                        <div class="receiver-account">

                                            Tài khoản: <?= htmlspecialchars((string)$order['ten_tai_khoan']) ?>

                                        </div>

                                    </td>

                                    <td class="order-money">

                                        <?= number_format((float)$order['tong_tien'], 0, ',', '.') ?>đ

                                    </td>

                                    <td>

                                        <div class="payment-method">

                                            <?= htmlspecialchars((string)($order['phuong_thuc'] ?? 'Chưa xác định')) ?>

                                        </div>

                                        <span class="badge bg-<?= paymentStatusClass($paymentStatus) ?>">

                                            <?= htmlspecialchars($paymentStatus !== '' ? $paymentStatus : 'Chưa có thanh toán') ?>

                                        </span>

                                    </td>

                                    <td>

                                        <span class="badge bg-<?= orderStatusClass($orderStatus) ?>">

                                            <?= htmlspecialchars(orderStatusLabel($orderStatus)) ?>

                                        </span>

                                        <?php if (

                                            in_array($orderStatus, ['huy', 'da_huy', 'Hủy', 'Đã hủy'], true) &&

                                            $cancelReason !== ''

                                        ): ?>

                                            <div class="cancel-reason">

                                                <?= $cancelledBy === 'user' ? 'Người mua hủy: ' : ($cancelledBy === 'admin' ? 'Người bán hủy: ' : 'Lý do: ') ?>

                                                <?= htmlspecialchars($cancelReason) ?>

                                            </div>

                                        <?php endif; ?>

                                    </td>

                                    <td>

                                        <?= !empty($order['ngay_dat'])

                                            ? date('d/m/Y H:i', strtotime($order['ngay_dat']))

                                            : '-' ?>

                                    </td>

                                    <td class="text-end">

                                        <a

                                            href="./don_hang/chi_tiet.php?id=<?= (int)$order['id'] ?>"

                                            class="btn btn-sm btn-outline-primary"

                                        >

                                            Chi tiết

                                        </a>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>

<script>

function updateDashboardTime() {

    const element = document.getElementById("dashboardTime");

    if (!element) {

        return;

    }

    const now = new Date();

    element.innerText =

        now.toLocaleDateString("vi-VN") +

        " · " +

        now.toLocaleTimeString("vi-VN");

}

updateDashboardTime();

setInterval(updateDashboardTime, 1000);

</script>

<?php require_once "../includes/footer.php"; ?>
