<?php

if (session_status() === PHP_SESSION_NONE) {

    session_start();

}

require_once "../../middleware/check_user.php";

require_once "../../config/database.php";

/* =========================================================

   MÃ SẢN PHẨM + MÃ VẬN ĐƠN

   Database hiện đã có:

   - san_pham.ma_san_pham

   - don_hang.ma_van_don

   Mã vận đơn có thể NULL khi đơn chưa chuyển sang Đang giao.

========================================================= */

/* =========================================================

   KHÔNG CACHE

========================================================= */

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

header("Pragma: no-cache");

/* =========================================================

   HÀM BADGE TRẠNG THÁI ĐƠN

========================================================= */

function orderStatusClass(string $status): string

{

    $status = trim($status);

    switch ($status) {

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

        case 'da_huy':

        case 'Hủy':

        case 'huy':

            return 'bg-danger';

        default:

            return 'bg-secondary';

    }

}

/* =========================================================

   ĐỔI MÃ TRẠNG THÁI TRONG DATABASE THÀNH TÊN ĐẸP

   Admin của bạn đang lưu dạng:

   hoan_thanh, dang_giao, ...

   Trong khi trang user trước đó lại kiểm tra:

   "Hoàn thành", "Đang giao", ...

   Hàm này giúp trang user hỗ trợ cả hai kiểu dữ liệu.

========================================================= */

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

        'Đã hủy' => 'Đã hủy',

        'huy' => 'Đã hủy',

        'Hủy' => 'Đã hủy'

    ];

    return $labels[$status]

        ?? ($status !== '' ? $status : 'Chưa xác định');

}

/* =========================================================

   HÀM BADGE THANH TOÁN

========================================================= */

function paymentStatusClass(?string $status): string

{

    switch (trim((string)$status)) {

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

   TẠO URL GIỮ BỘ LỌC KHI PHÂN TRANG

========================================================= */

function pageUrl(int $page, string $filter): string

{

    $query = [

        'page' => max(1, $page)

    ];

    if ($filter !== 'all') {

        $query['filter'] = $filter;

    }

    return 'don_hang_da_dat.php?' .

        http_build_query($query);

}

/* =========================================================

   TẠO DANH SÁCH SỐ TRANG

   Ví dụ:

   1 2 3 ... 8 9

========================================================= */

function paginationPages(

    int $currentPage,

    int $totalPages

): array {

    if ($totalPages <= 7) {

        return range(1, $totalPages);

    }

    $pages = [1];

    $start = max(

        2,

        $currentPage - 1

    );

    $end = min(

        $totalPages - 1,

        $currentPage + 1

    );

    if ($start > 2) {

        $pages[] = '...';

    }

    for ($page = $start; $page <= $end; $page++) {

        $pages[] = $page;

    }

    if ($end < $totalPages - 1) {

        $pages[] = '...';

    }

    $pages[] = $totalPages;

    return $pages;

}

/* =========================================================

   USER

========================================================= */

$nguoiDungId = (int)($_SESSION['user']['id'] ?? 0);

if ($nguoiDungId <= 0) {

    die("Bạn chưa đăng nhập");

}

/* =========================================================

   BỘ LỌC

   Dùng key cố định thay vì đưa trực tiếp trạng thái từ GET

   vào SQL. Như vậy dễ kiểm soát và an toàn hơn.

   "completed" gồm:

   - Đã giao

   - Hoàn thành

   "cancelled" gồm:

   - Hủy

   - Đã hủy

========================================================= */

$filter = trim($_GET['filter'] ?? 'all');

$filterConfig = [

    'all' => [

        'label' => 'Tất cả đơn hàng',

        'group' => 'Đơn hàng',

        'where' => ''

    ],

    'pending' => [

        'label' => 'Chờ xử lý',

        'group' => 'Đơn hàng',

        'where' => "

            AND dh.trang_thai IN (

                'cho_xu_ly',

                'da_dat',

                'Đã đặt',

                'cho_xac_nhan',

                'Chờ xác nhận'

            )

        "

    ],

    'processing' => [

        'label' => 'Đã duyệt',

        'group' => 'Đơn hàng',

        'where' => "

            AND dh.trang_thai IN (

                'da_duyet',

                'dang_xu_ly',

                'Đang xử lý'

            )

        "

    ],

    'shipping' => [

        'label' => 'Đang giao',

        'group' => 'Đơn hàng',

        'where' => "

            AND dh.trang_thai IN (

                'dang_giao',

                'Đang giao'

            )

        "

    ],

    'completed' => [

        'label' => 'Đã hoàn thành',

        'group' => 'Đơn hàng',

        'where' => "

            AND dh.trang_thai IN (

                'da_giao',

                'Đã giao',

                'hoan_thanh',

                'Hoàn thành'

            )

        "

    ],

    'cancelled' => [

        'label' => 'Đã hủy',

        'group' => 'Đơn hàng',

        'where' => "

            AND dh.trang_thai IN (

                'da_huy',

                'Đã hủy',

                'huy',

                'Hủy'

            )

        "

    ],

    'paid' => [

        'label' => 'Đã thanh toán',

        'group' => 'Thanh toán',

        'where' => "

            AND EXISTS (

                SELECT 1

                FROM thanh_toan tt_filter

                WHERE tt_filter.id = (

                    SELECT MAX(tt_filter2.id)

                    FROM thanh_toan tt_filter2

                    WHERE tt_filter2.don_hang_id = dh.id

                )

                AND tt_filter.trang_thai IN (

                    'Đã thanh toán',

                    'Thành công'

                )

            )

        "

    ]

];

if (!isset($filterConfig[$filter])) {

    $filter = 'all';

}

$filterLabel =

    $filterConfig[$filter]['label'];

$filterWhere =

    $filterConfig[$filter]['where'];

/* =========================================================

   THỐNG KÊ TOÀN BỘ ĐƠN HÀNG

   Các số này KHÔNG bị ảnh hưởng bởi phân trang.

========================================================= */

$stmtStats = $conn->prepare("
    SELECT
        COUNT(*) AS tong_don,

        COALESCE(
            SUM(
                CASE
                    WHEN dh.trang_thai IN (
                        'cho_xu_ly',
                        'da_dat',
                        'Đã đặt',
                        'cho_xac_nhan',
                        'Chờ xác nhận'
                    )
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS don_cho_xu_ly,

        COALESCE(
            SUM(
                CASE
                    WHEN dh.trang_thai IN (
                        'dang_giao',
                        'Đang giao'
                    )
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS don_dang_giao,

        COALESCE(
            SUM(
                CASE
                    WHEN dh.trang_thai IN (
                        'da_giao',
                        'Đã giao',
                        'hoan_thanh',
                        'Hoàn thành'
                    )
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS don_hoan_thanh,

        COALESCE(
            SUM(
                CASE
                    WHEN dh.trang_thai IN (
                        'da_huy',
                        'Đã hủy',
                        'huy',
                        'Hủy'
                    )
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS don_da_huy

    FROM don_hang dh
    WHERE dh.nguoi_dung_id = ?
");

$stmtStats->bind_param(

    "i",

    $nguoiDungId

);

$stmtStats->execute();

$stats = $stmtStats

    ->get_result()

    ->fetch_assoc();

$tongDon =

    (int)($stats['tong_don'] ?? 0);

$donChoXuLy =

    (int)($stats['don_cho_xu_ly'] ?? 0);

$donDangGiao =

    (int)($stats['don_dang_giao'] ?? 0);

$donHoanThanh =

    (int)($stats['don_hoan_thanh'] ?? 0);

$donDaHuy =

    (int)($stats['don_da_huy'] ?? 0);

/* =========================================================

   PHÂN TRANG

   - 10 đơn / trang

========================================================= */

$limit = 10;

$currentPage =

    max(

        1,

        (int)($_GET['page'] ?? 1)

    );

/* =========================================================

   ĐẾM SỐ ĐƠN SAU KHI LỌC

========================================================= */

$sqlCount = "

    SELECT COUNT(*) AS total

    FROM don_hang dh

    WHERE dh.nguoi_dung_id = ?

    $filterWhere

";

$stmtCount =

    $conn->prepare($sqlCount);

if (!$stmtCount) {

    die(

        "Lỗi đếm đơn hàng: " .

        htmlspecialchars($conn->error)

    );

}

$stmtCount->bind_param(

    "i",

    $nguoiDungId

);

$stmtCount->execute();

$countData = $stmtCount

    ->get_result()

    ->fetch_assoc();

$totalFiltered =

    (int)($countData['total'] ?? 0);

$totalPages =

    max(

        1,

        (int)ceil(

            $totalFiltered / $limit

        )

    );

/*

    Nếu user tự sửa URL page quá lớn,

    đưa về trang cuối.

*/

if ($currentPage > $totalPages) {

    $currentPage =

        $totalPages;

}

$offset =

    ($currentPage - 1) * $limit;

/* =========================================================

   LẤY 10 ĐƠN CỦA TRANG HIỆN TẠI

   Lấy thanh toán mới nhất của từng đơn để hiển thị
   trạng thái thanh toán  .

========================================================= */

$sqlOrders = "

    SELECT

        dh.id,

        dh.ma_van_don,

        dh.tong_tien,

        dh.trang_thai,

        dh.ngay_dat,

        dh.ly_do_huy,

        dh.ngay_huy,

        dh.nguoi_huy,

        tt.phuong_thuc,

        tt.trang_thai AS trang_thai_thanh_toan,

        tt.ngay_thanh_toan,

        (

            SELECT COALESCE(

                SUM(ct.so_luong),

                0

            )

            FROM chi_tiet_don_hang ct

            WHERE ct.don_hang_id = dh.id

        ) AS tong_so_luong

    FROM don_hang dh

    LEFT JOIN thanh_toan tt

        ON tt.id = (

            SELECT tt2.id

            FROM thanh_toan tt2

            WHERE tt2.don_hang_id = dh.id

            ORDER BY tt2.id DESC

            LIMIT 1

        )

    WHERE dh.nguoi_dung_id = ?

    $filterWhere

    ORDER BY

        dh.ngay_dat DESC,

        dh.id DESC

    LIMIT ?

    OFFSET ?

";

$stmtOrders =

    $conn->prepare($sqlOrders);

if (!$stmtOrders) {

    die(

        "Lỗi truy vấn đơn hàng: " .

        htmlspecialchars($conn->error)

    );

}

$stmtOrders->bind_param(

    "iii",

    $nguoiDungId,

    $limit,

    $offset

);

$stmtOrders->execute();

$orders = $stmtOrders

    ->get_result()

    ->fetch_all(MYSQLI_ASSOC);

/* =========================================================

   VỊ TRÍ ĐANG HIỂN THỊ

========================================================= */

$fromItem =

    $totalFiltered > 0

        ? $offset + 1

        : 0;

$toItem =

    min(

        $offset + $limit,

        $totalFiltered

    );

$paginationList =

    paginationPages(

        $currentPage,

        $totalPages

    );

?>

<?php include "../../includes/header.php"; ?>

<?php include "../../includes/navbar.php"; ?>

<link

    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"

    rel="stylesheet"

>

<style>

.order-history-page {

    background: #f6f7f9;

    min-height: 70vh;

}

/* =========================================================

   HEADER

========================================================= */

.page-title {

    font-weight: 800;

    margin-bottom: 4px;

}

.page-subtitle {

    color: #777;

    font-size: 14px;

}

/* =========================================================

   STAT CARDS

========================================================= */

.stat-card-link {

    display: block;

    height: 100%;

    color: inherit;

    text-decoration: none;

}

.stats-grid {

    --bs-gutter-x: 12px;

    --bs-gutter-y: 12px;

}

.stat-card {

    position: relative;

    height: 100%;

    min-height: 82px;

    padding: 10px 12px;

    overflow: hidden;

    background: #fff;

    border: 1px solid #ececec;

    border-radius: 12px;

    box-shadow: 0 4px 14px rgba(0, 0, 0, .03);

    transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;

}

.stat-card-link:hover .stat-card {

    transform: translateY(-2px);

    border-color: #ffb6a5;

    box-shadow: 0 9px 22px rgba(238, 77, 45, .09);

}

.stat-card.active {

    border-color: #ee4d2d;

    box-shadow: 0 0 0 2px rgba(238, 77, 45, .07), 0 8px 20px rgba(238, 77, 45, .08);

}

.stat-label {

    max-width: calc(100% - 28px);

    color: #666;

    font-size: 11.5px;

    line-height: 1.2;

    font-weight: 700;

}

.stat-number {

    margin-top: 4px;

    color: #ee4d2d;

    font-size: 22px;

    line-height: 1;

    font-weight: 800;

}

.stat-icon {

    position: absolute;

    right: 10px;

    bottom: 8px;

    font-size: 25px;

    opacity: .08;

}

.stat-hint {

    max-width: calc(100% - 24px);

    margin-top: 4px;

    color: #999;

    font-size: 9px;

    line-height: 1.25;

}

/* =========================================================

   FILTER

========================================================= */

.filter-box {

    background: #fff;

    border: 1px solid #eee;

    border-radius: 18px;

    padding: 20px;

    box-shadow: 0 8px 24px rgba(0, 0, 0, .04);

}

.filter-title {

    font-weight: 800;

    font-size: 17px;

}

.filter-result {

    color: #777;

    font-size: 13px;

}

.filter-box .form-select {

    min-height: 46px;

    border-radius: 10px;

}

.filter-box .btn {

    min-height: 46px;

    border-radius: 10px;

}

/* =========================================================

   TABLE

========================================================= */

.history-card {

    overflow: hidden;

    background: #fff;

    border: 1px solid #eee;

    border-radius: 18px;

    box-shadow: 0 8px 24px rgba(0, 0, 0, .04);

}

.history-header {

    padding: 18px 20px;

    border-bottom: 1px solid #eee;

}

.history-header-title {

    font-weight: 800;

    font-size: 17px;

}

.order-table {

    width: 100%;

    margin-bottom: 0;

    table-layout: fixed;

    font-size: 12px;

}

.order-table thead th {

    padding: 11px 10px;

    white-space: normal;

    color: #555;

    font-size: 11.5px;

    line-height: 1.3;

    font-weight: 750;

}

.order-table tbody td {

    padding: 11px 10px;

    vertical-align: middle;

    line-height: 1.45;

    word-break: break-word;

}

.order-table tbody tr {

    transition: *background* .15s ease;

}

.order-table tbody tr:hover {

    background: #fffaf8;

}

.order-table th:nth-child(1) { width: 150px; }

.order-table th:nth-child(2) { width: 120px; }

.order-table th:nth-child(3) { width: 90px; }

.order-table th:nth-child(4) { width: 125px; }

.order-table th:nth-child(5) { width: 145px; }

.order-table th:nth-child(6) { width: 180px; }

.order-table th:nth-child(7) { width: 100px; }

.tracking-code {

    display: inline-flex;

    align-items: center;

    padding: 5px 9px;

    border: 1px solid #cfe2ff;

    border-radius: 8px;

    background: #eef6ff;

    color: #0d6efd;

    font-size: 12px;

    font-weight: 800;

    letter-spacing: .8px;

    white-space: nowrap;

}

.tracking-empty {

    display: inline-flex;

    align-items: center;

    padding: 5px 9px;

    border: 1px solid #e3e5e8;

    border-radius: 8px;

    background: #f6f7f9;

    color: #8b8f97;

    font-size: 11px;

    font-weight: 600;

    white-space: nowrap;

}

.notice-card {

    border: 0;

    border-radius: 12px;

    box-shadow: 0 5px 18px rgba(0, 0, 0, .04);

}

.order-total {

    color: #ee4d2d;

    font-weight: 800;

    white-space: nowrap;

}

.payment-method-text {

    color: #666;

    font-size: 12.5px;

    white-space: nowrap;

}

.payment-inline {

    display: inline-flex;

    align-items: center;

    gap: 5px;

    white-space: nowrap;

    word-break: normal;

}

.payment-inline .badge {

    flex-shrink: 0;

}

.payment-separator {

    flex-shrink: 0;

    color: #8a8f98;

    font-size: 12px;

    font-weight: 600;

}

.payment-separator {

    color: #8a8f98;

    font-size: 12px;

    font-weight: 600;

}

.view-order-btn {

    border-radius: 9px;

}

.cancel-message {

    max-width: 270px;

    margin-top: 7px;

    font-size: 11px;

    line-height: 1.5;

    font-weight: 650;

}

.cancel-message.seller {

    color: #b42332;

}

.cancel-message.user {

    color: #7b5360;

}

.cancel-message.legacy {

    color: #888;

}

.cancel-message strong {

    font-weight: 850;

}

.empty-orders {

    padding: 55px 20px;

    text-align: center;

}

.empty-icon {

    font-size: 45px;

    margin-bottom: 12px;

}

/* =========================================================

   PAGINATION

========================================================= */

.pagination-wrap {

    padding: 18px 20px;

    border-top: 1px solid #eee;

    background: #fff;

}

.pagination-info {

    color: #777;

    font-size: 13px;

}

.custom-pagination {

    display: flex;

    flex-wrap: wrap;

    align-items: center;

    gap: 6px;

    margin: 0;

    padding: 0;

    list-style: none;

}

.page-btn {

    min-width: 40px;

    height: 40px;

    padding: 0 12px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    border: 1px solid #ddd;

    border-radius: 10px;

    background: #fff;

    color: #444;

    text-decoration: none;

    font-weight: 600;

    transition: .18s;

}

.page-btn:hover {

    border-color: #ee4d2d;

    color: #ee4d2d;

    background: #fff8f5;

}

.page-btn.active {

    border-color: #ee4d2d;

    background: #ee4d2d;

    color: #fff;

}

.page-btn.disabled {

    opacity: .45;

    pointer-events: none;

}

.page-dots {

    min-width: 35px;

    text-align: center;

    color: #999;

}

.pagination-arrow {

    font-size: 18px;

}

/* =========================================================

   MOBILE

========================================================= */

@media (max-width: 991.98px) {

    .order-table {

        min-width: 930px;

    }

}

@media (max-width: 767.98px) {

    .order-history-page {

        padding-top: 18px !important;

        padding-bottom: 28px !important;

    }

    .order-history-page > .container {

        padding-left: 12px;

        padding-right: 12px;

    }

    .history-topbar {

        align-items: flex-start !important;

        margin-bottom: 18px !important;

    }

    .history-topbar > div:first-child {

        width: 100%;

    }

    .history-topbar .btn {

        width: 100%;

        min-height: 42px;

    }

    .page-title {

        font-size: 21px;

    }

    .page-subtitle {

        font-size: 12.5px;

        line-height: 1.5;

    }

    .stats-grid {

        --bs-gutter-x: 10px;

        --bs-gutter-y: 10px;

        margin-bottom: 18px !important;

    }

    .stat-card {

        min-height: 70px;

        padding: 9px 10px;

        border-radius: 11px;

    }

    .stat-label {

        max-width: calc(100% - 24px);

        font-size: 10.5px;

        line-height: 1.15;

    }

    .stat-number {

        margin-top: 3px;

        font-size: 20px;

    }

    .stat-hint {

        display: none;

    }

    .stat-icon {

        right: 8px;

        bottom: 7px;

        font-size: 22px;

    }

    .filter-box {

        padding: 14px;

        border-radius: 14px;

    }

    .filter-box > .d-flex {

        display: block !important;

    }

    .filter-box > .d-flex > div:first-child {

        width: 100%;

        min-width: 0 !important;

        max-width: none !important;

    }

    .filter-box > .d-flex > .d-flex {

        width: 100%;

        margin-top: 12px;

    }

    .filter-box .btn {

        flex: 1;

        min-height: 42px;

        padding-left: 12px !important;

        padding-right: 12px !important;

    }

    .history-card {

        overflow: visible;

        border: 0;

        background: transparent;

        box-shadow: none;

    }

    .history-header {

        margin-bottom: 10px;

        padding: 12px 14px;

        border: 1px solid #eee;

        border-radius: 13px;

        background: #fff;

    }

    .table-responsive {

        overflow: visible;

    }

    .order-table {

        min-width: 0;

        border-collapse: separate;

        border-spacing: 0 12px;

    }

    .order-table thead {

        display: none;

    }

    .order-table,

    .order-table tbody,

    .order-table tr,

    .order-table td {

        display: block;

        width: 100%;

    }

    .order-table tbody tr {

        overflow: hidden;

        margin-bottom: 12px;

        border: 1px solid #e8e8e8;

        border-radius: 14px;

        background: #fff;

        box-shadow: 0 6px 18px rgba(0, 0, 0, .035);

    }

    .order-table tbody tr:hover {

        background: #fff;

    }

    .order-table tbody td {

        display: grid;

        grid-template-columns: 112px minmax(0, 1fr);

        gap: 12px;

        align-items: center;

        padding: 10px 13px;

        border-bottom: 1px solid #f0f0f0;

        text-align: left;

    }

    .order-table tbody td > * {

        justify-self: end;

        text-align: right;

    }

    .order-table tbody td[data-label="Ngày đặt"],

    .order-table tbody td[data-label="Số lượng"],

    .order-table tbody td[data-label="Tổng tiền"] {

        text-align: right;

    }

    .order-table tbody td[data-label="Mã vận đơn"],

    .order-table tbody td[data-label="Trạng thái đơn"],

    .order-table tbody td[data-label="Thanh toán"] {

        text-align: right;

    }

    .order-table tbody td[data-label] > * {

        justify-self: end;

        text-align: right;

    }

    .order-table tbody td[data-label="Thanh toán"] > * {

        max-width: 100%;

    }

    .order-table tbody td::before {

        content: attr(data-label);

        width: 100%;

        justify-self: start;

        align-self: center;

        color: #7b7f87;

        font-size: 10.5px;

        line-height: 1.45;

        font-weight: 800;

        text-align: left;

        text-transform: uppercase;

        letter-spacing: .2px;

    }

    .order-table tbody td:last-child {

        display: flex;

        justify-content: center;

        padding: 12px 13px;

        border-bottom: 0;

        background: #fafafa;

    }

    .order-table tbody td:last-child::before {

        display: none;

    }

    .view-order-btn {

        width: auto;

        min-width: 120px;

        min-height: 38px;

        display: inline-flex;

        align-items: center;

        justify-content: center;

    }

    .tracking-code,

    .tracking-empty {

        max-width: 100%;

        white-space: normal;

        word-break: break-word;

    }

    .cancel-message {

        max-width: none;

    }

    .pagination-wrap {

        justify-content: center !important;

        padding: 14px 10px;

        border: 1px solid #eee;

        border-radius: 13px;

    }

    .pagination-info {

        width: 100%;

        margin-bottom: 6px;

        text-align: center;

    }

    .custom-pagination {

        justify-content: center;

    }

    .page-btn {

        min-width: 36px;

        height: 36px;

        padding: 0 10px;

    }

    .order-table tbody .badge {

        padding: 0;

        border: 0;

        border-radius: 0;

        background: transparent !important;

        box-shadow: none !important;

        font-size: 11px;

        font-weight: 800;

    }

    .order-table tbody .badge.bg-success {

        color: #198754 !important;

    }

    .order-table tbody .badge.bg-primary {

        color: #0d6efd !important;

    }

    .order-table tbody .badge.bg-warning {

        color: #9a6700 !important;

    }

    .order-table tbody .badge.bg-info {

        color: #087990 !important;

    }

    .order-table tbody .badge.bg-danger {

        color: #dc3545 !important;

    }

    .order-table tbody .badge.bg-secondary {

        color: #6c757d !important;

    }

    .order-table tbody .payment-method-text {

        margin-bottom: 0 !important;

        font-size: 11px;

        white-space: nowrap;

    }

    .payment-inline {

        display: flex;

        justify-content: flex-end;

        align-items: center;

        gap: 4px;

        width: 100%;

        white-space: nowrap;

    }

.order-table tbody .cancel-message {

        font-size: 10px;

        line-height: 1.4;

    }

    .view-order-btn {

        min-width: 110px;

        justify-content: center;

        text-align: center;

    }

    .mobile-order-toggle-wrap {

        display: flex;

        justify-content: center;

        margin: 12px 0 4px;

    }

    .mobile-order-toggle {

        min-width: 150px;

        min-height: 38px;

        padding: 0 16px;

        border: 1px solid #ee4d2d;

        border-radius: 999px;

        background: #fff;

        color: #ee4d2d;

        font-size: 12px;

        font-weight: 800;

        transition: .18s ease;

    }

    .mobile-order-toggle:hover {

        background: #fff5f1;

    }

    .order-table tbody tr:nth-child(n+4) {

        display: none;

    }

    .order-table.mobile-show-all tbody tr:nth-child(n+4) {

        display: block;

    }

}

@media (max-width: 420px) {

    .stats-grid > .col {

        width: 50%;

    }

    .stat-card {

        min-height: 68px;

    }

    .order-table tbody td {

        grid-template-columns: 96px minmax(0, 1fr);

        gap: 8px;

        padding: 9px 11px;

    }

    .order-table tbody td::before {

        font-size: 10px;

    }

}

</style>

<div class="order-history-page py-4">

    <div class="container">

        <!-- =================================================

             TIÊU ĐỀ

        ================================================== -->

        <div

            class="history-topbar d-flex flex-wrap gap-3 align-items-center justify-content-between mb-4"

        >

            <div>

                <h4 class="page-title">

                    📜 Lịch sử đơn hàng

                </h4>

                <div class="page-subtitle">

                    Theo dõi trạng thái đơn hàng và thông tin thanh toán  .

                </div>

            </div>

            <a

                href="../index.php"

                class="btn btn-outline-danger"

            >

                🛍 Tiếp tục mua sắm

            </a>

        </div>

        <!-- =================================================

             THỐNG KÊ CÓ THỂ BẤM ĐỂ LỌC

        ================================================== -->

        <div class="row row-cols-2 row-cols-md-3 row-cols-xl-5 stats-grid mb-4">

            <div class="col">

                <a

                    href="<?= htmlspecialchars(

                        pageUrl(1, 'all')

                    ) ?>"

                    class="stat-card-link"

                >

                    <div

                        class="stat-card <?= $filter === 'all'

                            ? 'active'

                            : '' ?>"

                    >

                        <div class="stat-label">

                            Tổng đơn hàng

                        </div>

                        <div class="stat-number">

                            <?= $tongDon ?>

                        </div>

                        <div class="stat-hint">

                            Bấm để xem tất cả

                        </div>

                        <div class="stat-icon">

                            📦

                        </div>

                    </div>

                </a>

            </div>

            <div class="col">

                <a

                    href="<?= htmlspecialchars(

                        pageUrl(1, 'pending')

                    ) ?>"

                    class="stat-card-link"

                >

                    <div

                        class="stat-card <?= $filter === 'pending'

                            ? 'active'

                            : '' ?>"

                    >

                        <div class="stat-label">

                            Chờ xử lý

                        </div>

                        <div class="stat-number">

                            <?= $donChoXuLy ?>

                        </div>

                        <div class="stat-hint">

                            Đơn đang chờ cửa hàng xử lý

                        </div>

                        <div class="stat-icon">

                            ⏳

                        </div>

                    </div>

                </a>

            </div>

            <div class="col">

                <a

                    href="<?= htmlspecialchars(

                        pageUrl(1, 'shipping')

                    ) ?>"

                    class="stat-card-link"

                >

                    <div

                        class="stat-card <?= $filter === 'shipping'

                            ? 'active'

                            : '' ?>"

                    >

                        <div class="stat-label">

                            Đang giao

                        </div>

                        <div class="stat-number">

                            <?= $donDangGiao ?>

                        </div>

                        <div class="stat-hint">

                            Đơn đang trên đường giao

                        </div>

                        <div class="stat-icon">

                            🚚

                        </div>

                    </div>

                </a>

            </div>

            <div class="col">

                <a

                    href="<?= htmlspecialchars(

                        pageUrl(1, 'completed')

                    ) ?>"

                    class="stat-card-link"

                >

                    <div

                        class="stat-card <?= $filter === 'completed'

                            ? 'active'

                            : '' ?>"

                    >

                        <div class="stat-label">

                            Đã hoàn thành

                        </div>

                        <div class="stat-number">

                            <?= $donHoanThanh ?>

                        </div>

                        <div class="stat-hint">

                            Đơn đã giao hoàn tất

                        </div>

                        <div class="stat-icon">

                            ✅

                        </div>

                    </div>

                </a>

            </div>

            <div class="col">

                <a

                    href="<?= htmlspecialchars(

                        pageUrl(1, 'cancelled')

                    ) ?>"

                    class="stat-card-link"

                >

                    <div

                        class="stat-card <?= $filter === 'cancelled'

                            ? 'active'

                            : '' ?>"

                    >

                        <div class="stat-label">

                            Đã hủy

                        </div>

                        <div class="stat-number">

                            <?= $donDaHuy ?>

                        </div>

                        <div class="stat-hint">

                            Đơn đã bị hủy

                        </div>

                        <div class="stat-icon">

                            ❌

                        </div>

                    </div>

                </a>

            </div>

        </div>

        <!-- =================================================

             BỘ LỌC

        ================================================== -->

        <form

            method="GET"

            action="don_hang_da_dat.php"

            class="filter-box mb-4"

        >

            <div

                class="d-flex flex-wrap gap-3 align-items-end justify-content-between"

            >

                <div style="min-width:280px;flex:1;max-width:650px">

                    <div class="filter-title mb-2">

                        Lọc theo trạng thái

                    </div>

                    <div class="text-muted small mb-2">

                        Có thể lọc theo trạng thái đơn hàng hoặc trạng thái thanh toán  .

                    </div>

                    <select

                        name="filter"

                        class="form-select"

                    >

                        <?php

                            $filterGroups = [

                                'Đơn hàng',

                                'Thanh toán'

                            ];

                        ?>

                        <?php foreach ($filterGroups as $groupName): ?>

                            <optgroup

                                label="<?= htmlspecialchars(

                                    $groupName

                                ) ?>"

                            >

                                <?php foreach (

                                    $filterConfig

                                    as $key => $config

                                ): ?>

                                    <?php if (

                                        ($config['group'] ?? '') !== $groupName

                                    ): ?>

                                        <?php continue; ?>

                                    <?php endif; ?>

                                    <option

                                        value="<?= htmlspecialchars($key) ?>"

                                        <?= $filter === $key

                                            ? 'selected'

                                            : '' ?>

                                    >

                                        <?= htmlspecialchars(

                                            $config['label']

                                        ) ?>

                                    </option>

                                <?php endforeach; ?>

                            </optgroup>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="d-flex flex-wrap gap-2">

                    <button

                        type="submit"

                        class="btn btn-danger px-4"

                    >

                        🔎 Lọc

                    </button>

                    <a

                        href="don_hang_da_dat.php"

                        class="btn btn-outline-secondary px-4"

                    >

                        Xóa lọc

                    </a>

                </div>

            </div>

            <div class="filter-result mt-3">

                Đang xem:

                <strong>

                    <?= htmlspecialchars($filterLabel) ?>

                </strong>

                ·

                Tìm thấy

                <strong>

                    <?= $totalFiltered ?>

                </strong>

                đơn hàng

            </div>

        </form>

        <!-- =================================================

             DANH SÁCH ĐƠN HÀNG

        ================================================== -->

        <div class="history-card">

            <div

                class="history-header d-flex flex-wrap gap-2 align-items-center justify-content-between"

            >

                <div class="history-header-title">

                    Danh sách đơn hàng

                </div>

                <?php if ($totalFiltered > 0): ?>

                    <div class="text-muted small">

                        Hiển thị

                        <?= $fromItem ?>

                        -

                        <?= $toItem ?>

                        /

                        <?= $totalFiltered ?>

                        đơn

                    </div>

                <?php endif; ?>

            </div>

            <?php if (empty($orders)): ?>

                <div class="empty-orders">

                    <div class="empty-icon">

                        📦

                    </div>

                    <div class="fs-5 fw-bold mb-2">

                        Không có đơn hàng

                    </div>

                    <div class="text-muted mb-3">

                        Không tìm thấy đơn hàng phù hợp với bộ lọc này.

                    </div>

                    <?php if ($filter !== 'all'): ?>

                        <a

                            href="don_hang_da_dat.php"

                            class="btn btn-outline-danger me-2"

                        >

                            Xem tất cả đơn

                        </a>

                    <?php endif; ?>

                    <a

                        href="../san_pham/index.php"

                        class="btn btn-danger"

                    >

                        Mua sắm ngay

                    </a>

                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table align-middle order-table">

                        <thead class="table-light">

                            <tr>

                                <th>

                                    Mã vận đơn

                                </th>

                                <th>

                                    Ngày đặt

                                </th>

                                <th>

                                    Số lượng

                                </th>

                                <th>

                                    Tổng tiền

                                </th>

                                <th>

                                    Trạng thái đơn

                                </th>

                                <th>

                                    Thanh toán

                                </th>

                                <th style="width:100px"></th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($orders as $order): ?>

                                <?php

                                $orderStatus =

                                    trim(

                                        (string)$order['trang_thai']

                                    );

                                $paymentStatus =

                                    trim(

                                        (string)(

                                            $order['trang_thai_thanh_toan']

                                            ?? ''

                                        )

                                    );

                                $paymentMethod =

                                    trim(

                                        (string)(

                                            $order['phuong_thuc']

                                            ?? ''

                                        )

                                    );

                                $orderIsCancelled =

                                    in_array(

                                        $orderStatus,

                                        [

                                            'da_huy',

                                            'Đã hủy',

                                            'huy',

                                            'Hủy'

                                        ],

                                        true

                                    );

                                ?>

                                <tr>

                                    <!-- MÃ VẬN ĐƠN -->

                                    <td data-label="Mã vận đơn">

                                        <?php

                                        $maVanDon = trim(

                                            (string)($order['ma_van_don'] ?? '')

                                        );

                                        ?>

                                        <?php if ($maVanDon !== ''): ?>

                                            <span class="tracking-code">

                                                <?= htmlspecialchars($maVanDon) ?>

                                            </span>

                                        <?php else: ?>

                                            <span class="tracking-empty">

                                                Chưa có mã vận đơn

                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <!-- NGÀY ĐẶT -->

                                    <td data-label="Ngày đặt">

                                        <?= !empty($order['ngay_dat'])

                                            ? date(

                                                'd/m/Y H:i',

                                                strtotime(

                                                    $order['ngay_dat']

                                                )

                                            )

                                            : '-' ?>

                                    </td>

                                    <!-- TỔNG SỐ LƯỢNG -->

                                    <td data-label="Số lượng">

                                        <?= (int)$order['tong_so_luong'] ?>

                                        sản phẩm

                                    </td>

                                    <!-- TỔNG TIỀN -->

                                    <td class="order-total" data-label="Tổng tiền">

                                        <?= number_format(

                                            (float)$order['tong_tien'],

                                            0,

                                            ',',

                                            '.'

                                        ) ?>đ

                                    </td>

                                    <!-- TRẠNG THÁI ĐƠN -->

                                    <td data-label="Trạng thái đơn">

                                        <?php

                                        $laDonDaHuy =

                                            $orderIsCancelled;

                                        $lyDoHuy =

                                            trim(

                                                (string)(

                                                    $order['ly_do_huy']

                                                    ?? ''

                                                )

                                            );

                                        $nguoiHuy =

                                            trim(

                                                (string)(

                                                    $order['nguoi_huy']

                                                    ?? ''

                                                )

                                            );

                                        ?>

                                        <span

                                            class="badge <?= orderStatusClass(

                                                $orderStatus

                                            ) ?>"

                                        >

                                            <?= htmlspecialchars(

                                                orderStatusLabel(

                                                    $orderStatus

                                                )

                                            ) ?>

                                        </span>

                                        <?php if ($laDonDaHuy): ?>

                                            <?php if ($nguoiHuy === 'admin'): ?>

                                                <div class="cancel-message seller">

                                                    <strong>

                                                        Bị người bán hủy

                                                    </strong>

                                                    <?php if ($lyDoHuy !== ''): ?>

                                                        vì lý do:

                                                        <?= htmlspecialchars($lyDoHuy) ?>

                                                    <?php endif; ?>

                                                </div>

                                            <?php elseif ($nguoiHuy === 'user'): ?>

                                                <div class="cancel-message user">

                                                    <strong>

                                                        Bạn đã hủy đơn

                                                    </strong>

                                                    <?php if ($lyDoHuy !== ''): ?>

                                                        vì lý do:

                                                        <?= htmlspecialchars($lyDoHuy) ?>

                                                    <?php endif; ?>

                                                </div>

                                            <?php else: ?>

                                                <div class="cancel-message legacy">

                                                    Đơn hàng đã bị hủy.

                                                    <?php if ($lyDoHuy !== ''): ?>

                                                        Lý do:

                                                        <?= htmlspecialchars($lyDoHuy) ?>

                                                    <?php endif; ?>

                                                </div>

                                            <?php endif; ?>

                                        <?php endif; ?>

                                    </td>

                                    <!-- THANH TOÁN -->

                                    <td data-label="Thanh toán">

                                        <div class="payment-inline">

                                            <?php if ($paymentMethod !== ''): ?>

                                                <span class="payment-method-text">

                                                    <?= htmlspecialchars($paymentMethod) ?>

                                                </span>

                                                <span class="payment-separator">-</span>

                                            <?php endif; ?>

                                            <?php if ($paymentStatus !== ''): ?>

                                                <span class="badge <?= paymentStatusClass($paymentStatus) ?>">

                                                    <?= htmlspecialchars($paymentStatus) ?>

                                                </span>

                                                <!-- <span class="payment-separator">·  </span> -->

                                            <?php else: ?>

                                                <span class="badge bg-secondary">

                                                    Chưa có thông tin

                                                </span>

                                            <?php endif; ?>

                                        </div>

                                    </td>

                                    <!-- XEM CHI TIẾT -->

                                    <td data-label="Thao tác">

                                        <a

                                            href="chi_tiet.php?id=<?= (int)$order['id'] ?>"

                                            class="btn btn-sm btn-outline-primary view-order-btn"

                                        >

                                            Xem

                                        </a>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                    <?php if (count($orders) > 3): ?>

                        <div class="mobile-order-toggle-wrap d-md-none">

                            <button type="button" class="mobile-order-toggle" id="mobileOrderToggle">

                                Xem thêm đơn hàng

                            </button>

                        </div>

                    <?php endif; ?>

                </div>

                <!-- =================================================

                     PHÂN TRANG

                     Chỉ hiện nếu có hơn 1 trang.

                ================================================== -->

                <?php if ($totalPages > 1): ?>

                    <div

                        class="pagination-wrap d-flex flex-wrap gap-3 align-items-center justify-content-between"

                    >

                        <div class="pagination-info">

                            Trang

                            <strong>

                                <?= $currentPage ?>

                            </strong>

                            /

                            <?= $totalPages ?>

                            ·

                            <?= $totalFiltered ?>

                            đơn hàng

                        </div>

                        <nav aria-label="Phân trang đơn hàng">

                            <ul class="custom-pagination">

                                <!-- TRANG TRƯỚC -->

                                <li>

                                    <a

                                        href="<?= htmlspecialchars(

                                            pageUrl(

                                                $currentPage - 1,

                                                $filter

                                            )

                                        ) ?>"

                                        class="page-btn <?= $currentPage <= 1

                                            ? 'disabled'

                                            : '' ?>"

                                        title="Trang trước"

                                    >

                                        <span class="pagination-arrow">

                                            ‹

                                        </span>

                                    </a>

                                </li>

                                <!-- SỐ TRANG -->

                                <?php foreach (

                                    $paginationList

                                    as $pageItem

                                ): ?>

                                    <?php if ($pageItem === '...'): ?>

                                        <li>

                                            <span class="page-dots">

                                                ...

                                            </span>

                                        </li>

                                    <?php else: ?>

                                        <li>

                                            <a

                                                href="<?= htmlspecialchars(

                                                    pageUrl(

                                                        (int)$pageItem,

                                                        $filter

                                                    )

                                                ) ?>"

                                                class="page-btn <?= (int)$pageItem === $currentPage

                                                    ? 'active'

                                                    : '' ?>"

                                            >

                                                <?= (int)$pageItem ?>

                                            </a>

                                        </li>

                                    <?php endif; ?>

                                <?php endforeach; ?>

                                <!-- TRANG SAU -->

                                <li>

                                    <a

                                        href="<?= htmlspecialchars(

                                            pageUrl(

                                                $currentPage + 1,

                                                $filter

                                            )

                                        ) ?>"

                                        class="page-btn <?= $currentPage >= $totalPages

                                            ? 'disabled'

                                            : '' ?>"

                                        title="Trang sau"

                                    >

                                        <span class="pagination-arrow">

                                            ›

                                        </span>

                                    </a>

                                </li>

                            </ul>

                        </nav>

                    </div>

                <?php endif; ?>

            <?php endif; ?>

        </div>

    </div>

</div>

<script>

/* =========================================================

   BACK TRÌNH DUYỆT → CẬP NHẬT LẠI ĐƠN HÀNG

========================================================= */

const mobileOrderToggle = document.getElementById('mobileOrderToggle');

const mobileOrderTable = document.querySelector('.order-table');

if (mobileOrderToggle && mobileOrderTable) {

    mobileOrderToggle.addEventListener('click', function () {

        const expanded = mobileOrderTable.classList.toggle('mobile-show-all');

        mobileOrderToggle.textContent = expanded

            ? 'Thu gọn'

            : 'Xem thêm đơn hàng';

        if (!expanded) {

            const historyCard = document.querySelector('.history-card');

            if (historyCard) {

                const top = historyCard.getBoundingClientRect().top + window.scrollY - 90;

                window.scrollTo({

                    top: top,

                    behavior: 'smooth'

                });

            }

        }

    });

}

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
