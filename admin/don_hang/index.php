<?php

if (session_status() === PHP_SESSION_NONE) {

    session_start();

}

require_once "../../middleware/check_admin.php";

require_once "../../config/database.php";

/* =========================================================
   IPHONESTORE - QUẢN LÝ ĐƠN HÀNG

   Trang này tập trung vào:
   - vòng đời đơn hàng;
   - hiển thị trạng thái thanh toán  ;
   - tìm kiếm, lọc và phân trang.

   Trạng thái thanh toán không chặn xử lý trạng thái đơn.
========================================================= */

/* =========================================================

   HÀM HIỂN THỊ

========================================================= */

function orderStatusLabel(string $status): string

{

    $status = trim($status);

    $labels = [

        'cho_xu_ly' => 'Chờ xử lý',

        'Đã đặt' => 'Chờ xử lý',

        'da_dat' => 'Chờ xử lý',

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

    return $labels[$status]

        ?? ($status !== '' ? $status : 'Chưa xác định');

}

function orderStatusClass(string $status): string

{

    $status = trim($status);

    if (

        in_array(

            $status,

            [

                'cho_xu_ly',

                'Đã đặt',

                'da_dat',

                'cho_xac_nhan',

                'Chờ xác nhận'

            ],

            true

        )

    ) {

        return 'status-pending';

    }

    if (

        in_array(

            $status,

            [

                'da_duyet',

                'dang_xu_ly',

                'Đang xử lý'

            ],

            true

        )

    ) {

        return 'status-approved';

    }

    if (

        in_array(

            $status,

            [

                'dang_giao',

                'Đang giao'

            ],

            true

        )

    ) {

        return 'status-shipping';

    }

    if (

        in_array(

            $status,

            [

                'hoan_thanh',

                'Hoàn thành',

                'da_giao',

                'Đã giao'

            ],

            true

        )

    ) {

        return 'status-completed';

    }

    if (

        in_array(

            $status,

            [

                'huy',

                'da_huy',

                'Hủy',

                'Đã hủy'

            ],

            true

        )

    ) {

        return 'status-cancelled';

    }

    return 'status-default';

}

function paymentStatusClass(?string $status): string

{

    $status = trim((string)$status);

    if (

        in_array(

            $status,

            [

                'Đã thanh toán',

                'Thành công'

            ],

            true

        )

    ) {

        return 'payment-paid';

    }

    if (

        in_array(

            $status,

            [

                'Chờ xác nhận',

                'Chờ thanh toán',

                'Chờ xử lý'

            ],

            true

        )

    ) {

        return 'payment-waiting';

    }

    if ($status === 'Chưa thanh toán') {

        return 'payment-unpaid';

    }

    if (

        in_array(

            $status,

            [

                'Thất bại',

                'Đã hủy'

            ],

            true

        )

    ) {

        return 'payment-failed';

    }

    return 'payment-default';

}

function pageUrl(

    int $page,

    string $q,

    string $status,

    string $paymentFilter

): string {

    $query = [

        'page' => max(1, $page)

    ];

    if ($q !== '') {

        $query['q'] = $q;

    }

    if ($status !== 'all') {

        $query['status'] = $status;

    }

    if ($paymentFilter !== 'all') {

        $query['payment'] = $paymentFilter;

    }

    return 'index.php?' . http_build_query($query);

}

/* =========================================================

   BỘ LỌC

========================================================= */

$q = trim(

        (string)(

            $_GET['q']

            ?? ''

        )

    );

$status = trim(

        (string)(

            $_GET['status']

            ?? 'all'

        )

    );

$paymentFilter = trim(

        (string)(

            $_GET['payment']

            ?? 'all'

        )

    );

$statusConfig = [

    'all' => [

        'label' => 'Tất cả',

        'icon' => '📦',

        'where' => ''

    ],

    'pending' => [

        'label' => 'Chờ xử lý',

        'icon' => '⏳',

        'where' => "

            AND dh.trang_thai IN (

                'cho_xu_ly',

                'Đã đặt',

                'da_dat',

                'cho_xac_nhan',

                'Chờ xác nhận'

            )

        "

    ],

    'approved' => [

        'label' => 'Đã duyệt',

        'icon' => '✓',

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

        'icon' => '🚚',

        'where' => "

            AND dh.trang_thai IN (

                'dang_giao',

                'Đang giao'

            )

        "

    ],

    'completed' => [

        'label' => 'Hoàn thành',

        'icon' => '✅',

        'where' => "

            AND dh.trang_thai IN (

                'hoan_thanh',

                'Hoàn thành',

                'da_giao',

                'Đã giao'

            )

        "

    ],

    'cancelled' => [

        'label' => 'Đã hủy',

        'icon' => '✕',

        'where' => "

            AND dh.trang_thai IN (

                'huy',

                'da_huy',

                'Hủy',

                'Đã hủy'

            )

        "

    ]

];

if (!isset($statusConfig[$status])) {

    $status = 'all';

}

$statusWhere =

    $statusConfig[$status]['where'];

$paymentConfig = [

    'all' => [

        'label' => 'Tất cả thanh toán',

        'where' => ''

    ],

    'unpaid' => [

        'label' => 'Chưa thanh toán',

        'where' => "

            AND EXISTS (

                SELECT 1

                FROM thanh_toan tt_payment

                WHERE tt_payment.id = (

                    SELECT tt_payment2.id

                    FROM thanh_toan tt_payment2

                    WHERE tt_payment2.don_hang_id = dh.id

                    ORDER BY tt_payment2.id DESC

                    LIMIT 1

                )

                AND tt_payment.trang_thai =

                    'Chưa thanh toán'

            )

        "

    ],

    'waiting' => [

        'label' => 'Chờ thanh toán / xác nhận',

        'where' => "

            AND EXISTS (

                SELECT 1

                FROM thanh_toan tt_payment

                WHERE tt_payment.id = (

                    SELECT tt_payment2.id

                    FROM thanh_toan tt_payment2

                    WHERE tt_payment2.don_hang_id = dh.id

                    ORDER BY tt_payment2.id DESC

                    LIMIT 1

                )

                AND tt_payment.trang_thai IN (

                    'Chờ xác nhận',

                    'Chờ thanh toán',

                    'Chờ xử lý'

                )

            )

        "

    ],

    'paid' => [

        'label' => 'Đã thanh toán',

        'where' => "

            AND EXISTS (

                SELECT 1

                FROM thanh_toan tt_payment

                WHERE tt_payment.id = (

                    SELECT tt_payment2.id

                    FROM thanh_toan tt_payment2

                    WHERE tt_payment2.don_hang_id = dh.id

                    ORDER BY tt_payment2.id DESC

                    LIMIT 1

                )

                AND tt_payment.trang_thai IN (

                    'Đã thanh toán',

                    'Thành công'

                )

            )

        "

    ],

    'failed' => [

        'label' => 'Thanh toán thất bại',

        'where' => "

            AND EXISTS (

                SELECT 1

                FROM thanh_toan tt_payment

                WHERE tt_payment.id = (

                    SELECT tt_payment2.id

                    FROM thanh_toan tt_payment2

                    WHERE tt_payment2.don_hang_id = dh.id

                    ORDER BY tt_payment2.id DESC

                    LIMIT 1

                )

                AND tt_payment.trang_thai IN (

                    'Thất bại',

                    'Đã hủy'

                )

            )

        "

    ]

];

if (!isset($paymentConfig[$paymentFilter])) {

    $paymentFilter = 'all';

}

$paymentWhere =

    $paymentConfig[$paymentFilter]['where'];

/* =========================================================

   THỐNG KÊ TRẠNG THÁI ĐƠN

   Chỉ giữ thống kê cần thiết cho module đơn hàng.

========================================================= */

$sqlStats = "

    SELECT

        COUNT(*) AS tong_don,

        COALESCE(

            SUM(

                CASE

                    WHEN trang_thai IN (

                        'cho_xu_ly',

                        'Đã đặt',

                        'da_dat',

                        'cho_xac_nhan',

                        'Chờ xác nhận'

                    )

                    THEN 1 ELSE 0

                END

            ),

            0

        ) AS cho_xu_ly,

        COALESCE(

            SUM(

                CASE

                    WHEN trang_thai IN (

                        'da_duyet',

                        'dang_xu_ly',

                        'Đang xử lý'

                    )

                    THEN 1 ELSE 0

                END

            ),

            0

        ) AS da_duyet,

        COALESCE(

            SUM(

                CASE

                    WHEN trang_thai IN (

                        'dang_giao',

                        'Đang giao'

                    )

                    THEN 1 ELSE 0

                END

            ),

            0

        ) AS dang_giao,

        COALESCE(

            SUM(

                CASE

                    WHEN trang_thai IN (

                        'hoan_thanh',

                        'Hoàn thành',

                        'da_giao',

                        'Đã giao'

                    )

                    THEN 1 ELSE 0

                END

            ),

            0

        ) AS hoan_thanh,

        COALESCE(

            SUM(

                CASE

                    WHEN trang_thai IN (

                        'huy',

                        'da_huy',

                        'Hủy',

                        'Đã hủy'

                    )

                    THEN 1 ELSE 0

                END

            ),

            0

        ) AS da_huy

    FROM don_hang

";

$statsResult =

    $conn->query($sqlStats);

$stats =

    $statsResult

        ? $statsResult->fetch_assoc()

        : [];

$statNumbers = [

    'all' =>

        (int)($stats['tong_don'] ?? 0),

    'pending' =>

        (int)($stats['cho_xu_ly'] ?? 0),

    'approved' =>

        (int)($stats['da_duyet'] ?? 0),

    'shipping' =>

        (int)($stats['dang_giao'] ?? 0),

    'completed' =>

        (int)($stats['hoan_thanh'] ?? 0),

    'cancelled' =>

        (int)($stats['da_huy'] ?? 0)

];

/* =========================================================

   WHERE DANH SÁCH

========================================================= */

$where = "

    WHERE 1 = 1

    $statusWhere

    $paymentWhere

";

$types = '';

$params = [];

if ($q !== '') {

    $where .= "

        AND (

            nd.ho_ten LIKE ?

            OR dh.ma_van_don LIKE ?

            OR EXISTS (

                SELECT 1

                FROM chi_tiet_don_hang ct_search

                JOIN san_pham sp_search

                    ON sp_search.id = ct_search.san_pham_id

                WHERE ct_search.don_hang_id = dh.id

                AND (

                    sp_search.ma_san_pham LIKE ?

                    OR sp_search.ten_san_pham LIKE ?

                )

            )

            OR EXISTS (

                SELECT 1

                FROM thanh_toan tt_search

                WHERE tt_search.don_hang_id = dh.id

                AND (

                    tt_search.phuong_thuc LIKE ?

                    OR tt_search.trang_thai LIKE ?

                )

            )

        )

    ";

    $keyword =

        '%' . $q . '%';

    $types = 'ssssss';

    $params = [

        $keyword,

        $keyword,

        $keyword,

        $keyword,

        $keyword,

        $keyword

    ];

}

/* =========================================================

   PHÂN TRANG

========================================================= */

$limit = 10;

$page =

    max(

        1,

        (int)(

            $_GET['page']

            ?? 1

        )

    );

$sqlCount = "

    SELECT COUNT(*) AS total

    FROM don_hang dh

    JOIN nguoi_dung nd

        ON nd.id = dh.nguoi_dung_id

    $where

";

$stmtCount =

    $conn->prepare($sqlCount);

if (!$stmtCount) {

    die(

        'Lỗi đếm đơn hàng: ' .

        htmlspecialchars(

            $conn->error

        )

    );

}

if ($types !== '') {

    $stmtCount->bind_param(

        $types,

        ...$params

    );

}

$stmtCount->execute();

$totalFiltered =

    (int)(

        $stmtCount

            ->get_result()

            ->fetch_assoc()['total']

        ?? 0

    );

$totalPages =

    max(

        1,

        (int)ceil(

            $totalFiltered / $limit

        )

    );

if ($page > $totalPages) {

    $page = $totalPages;

}

$offset = ($page - 1) * $limit;

/* =========================================================

   DANH SÁCH ĐƠN HÀNG

   Chỉ lấy dữ liệu cần cho danh sách.

========================================================= */

$sql = "

    SELECT

        dh.id,

        dh.ma_van_don,

        dh.nguoi_dung_id,

        dh.tong_tien,

        dh.trang_thai,

        dh.ngay_dat,

        dh.ly_do_huy,

        dh.nguoi_huy,

        nd.ho_ten,

        (

            SELECT COALESCE(

                SUM(ct.so_luong),

                0

            )

            FROM chi_tiet_don_hang ct

            WHERE ct.don_hang_id = dh.id

        ) AS tong_so_luong,

        tt.id AS thanh_toan_id,

        tt.phuong_thuc,

        tt.trang_thai AS trang_thai_thanh_toan

    FROM don_hang dh

    JOIN nguoi_dung nd

        ON nd.id = dh.nguoi_dung_id

    LEFT JOIN thanh_toan tt

        ON tt.id = (

            SELECT tt2.id

            FROM thanh_toan tt2

            WHERE tt2.don_hang_id = dh.id

            ORDER BY tt2.id DESC

            LIMIT 1

        )

    $where

    ORDER BY

        dh.ngay_dat DESC,

        dh.id DESC

    LIMIT ?

    OFFSET ?

";

$stmt =

    $conn->prepare($sql);

if (!$stmt) {

    die(

        'Lỗi truy vấn đơn hàng: ' .

        htmlspecialchars(

            $conn->error

        )

    );

}

$listTypes =

    $types . 'ii';

$listParams =

    array_merge(

        $params,

        [

            $limit,

            $offset

        ]

    );

$stmt->bind_param(

    $listTypes,

    ...$listParams

);

$stmt->execute();

$donHangList =

    $stmt

        ->get_result()

        ->fetch_all(MYSQLI_ASSOC);

$fromItem =

    $totalFiltered > 0

        ? $offset + 1

        : 0;

$toItem =

    min(

        $offset + $limit,

        $totalFiltered

    );

?>

<?php require_once "../../includes/header.php"; ?>

<?php require_once "../../includes/navbar.php"; ?>

<link

    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"

    rel="stylesheet"

>

<style>

:root {

    --order-primary: #d70018;

    --order-primary-soft: #fff1f3;

    --order-border: #e9ebef;

    --order-text: #20242b;

    --order-muted: #7a8392;

    --order-bg: #f6f7f9;

}

.order-admin-page {

    min-height: 72vh;

    padding: 28px 0 48px;

    background: var(--order-bg);

}

.order-page-head {

    display: flex;

    align-items: flex-start;

    justify-content: space-between;

    gap: 20px;

    margin-bottom: 20px;

}

.order-page-title {

    margin: 0;

    color: var(--order-text);

    font-size: 27px;

    font-weight: 900;

}

.order-page-subtitle {

    margin-top: 5px;

    color: var(--order-muted);

    font-size: 13px;

}

.order-top-actions {

    display: flex;

    flex-wrap: wrap;

    gap: 9px;

}

.order-module-link {

    display: inline-flex;

    align-items: center;

    gap: 7px;

    min-height: 40px;

    padding: 8px 13px;

    border: 1px solid var(--order-border);

    border-radius: 10px;

    background: #fff;

    color: #404754;

    text-decoration: none;

    font-size: 13px;

    font-weight: 750;

    transition: .18s ease;

}

.order-module-link:hover {

    transform: translateY(-1px);

    border-color: #cfd4dc;

    color: var(--order-primary);

    box-shadow: 0 5px 16px rgba(0, 0, 0, .05);

}

.order-status-tabs {

    display: grid;

    grid-template-columns: repeat(6, minmax(0, 1fr));

    gap: 10px;

    margin-bottom: 18px;

}

.order-status-tab {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 9px;

    min-height: 68px;

    padding: 12px 14px;

    border: 1px solid var(--order-border);

    border-radius: 13px;

    background: #fff;

    color: var(--order-text);

    text-decoration: none;

    transition: .18s ease;

}

.order-status-tab:hover {

    border-color: #f2b6bd;

    color: var(--order-text);

    transform: translateY(-1px);

}

.order-status-tab.active {

    border-color: #ef9ba5;

    background: var(--order-primary-soft);

    box-shadow: 0 0 0 1px rgba(215, 0, 24, .05);

}

.order-status-tab-info {

    min-width: 0;

}

.order-status-tab-label {

    color: var(--order-muted);

    font-size: 11px;

    font-weight: 750;

}

.order-status-tab.active

.order-status-tab-label {

    color: #a51425;

}

.order-status-tab-number {

    margin-top: 2px;

    color: var(--order-text);

    font-size: 22px;

    line-height: 1;

    font-weight: 900;

}

.order-status-tab-icon {

    font-size: 19px;

    opacity: .85;

}

.order-filter-card,

.order-list-card {

    border: 1px solid var(--order-border);

    border-radius: 15px;

    background: #fff;

    box-shadow: 0 6px 22px rgba(20, 27, 38, .045);

}

.order-filter-card {

    margin-bottom: 18px;

    padding: 15px;

}

.order-filter-grid {

    display: grid;

    grid-template-columns:

        minmax(260px, 1.4fr)

        minmax(170px, .7fr)

        minmax(190px, .8fr)

        auto;

    gap: 10px;

    align-items: center;

}

.order-search-wrap {

    position: relative;

}

.order-search-icon {

    position: absolute;

    top: 50%;

    left: 13px;

    transform: translateY(-50%);

    color: #9aa1ad;

    pointer-events: none;

}

.order-search-input {

    height: 44px;

    padding-left: 38px;

    border-radius: 10px;

}

.order-filter-card .form-select {

    height: 44px;

    border-radius: 10px;

}

.order-filter-actions {

    display: flex;

    gap: 7px;

}

.order-filter-actions .btn {

    min-height: 44px;

    border-radius: 10px;

    font-weight: 750;

}

.order-list-card {

    overflow: hidden;

}

.order-list-head {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 12px;

    padding: 15px 18px;

    border-bottom: 1px solid var(--order-border);

}

.order-list-title {

    color: var(--order-text);

    font-weight: 850;

}

.order-list-count {

    color: var(--order-muted);

    font-size: 12px;

}

.order-table {

    margin: 0;

}

.order-table thead th {

    padding: 13px 15px;

    border-bottom: 1px solid var(--order-border);

    background: #fafbfc;

    color: #697280;

    font-size: 11px;

    font-weight: 850;

    letter-spacing: .2px;

    white-space: nowrap;

}

.order-table tbody td {

    padding: 15px;

    border-color: #f0f1f3;

    vertical-align: middle;

}

.order-table tbody tr:hover > td {

    background: #fcfcfd;

}

.tracking-code {

    display: inline-flex;

    align-items: center;

    padding: 5px 9px;

    border-radius: 8px;

    background: #eef5ff;

    color: #2463b4;

    font-size: 12px;

    font-weight: 850;

    letter-spacing: .5px;

    white-space: nowrap;

}

.tracking-empty {

    color: #9ba1aa;

    font-size: 11px;

    font-weight: 650;

    white-space: nowrap;

}

.customer-name {

    color: var(--order-text);

    font-size: 13px;

    font-weight: 800;

}

.order-date {

    margin-top: 4px;

    color: #9299a4;

    font-size: 11px;

    white-space: nowrap;

}

.order-quantity {

    color: #7d8591;

    font-size: 11px;

}

.order-money {

    margin-top: 3px;

    color: var(--order-primary);

    font-size: 15px;

    font-weight: 900;

    white-space: nowrap;

}

.order-badge,

.payment-badge,


.status-pending {

    background: #fff4d8;

    color: #856404;

}

.status-approved {

    background: #eaf2ff;

    color: #265da8;

}

.status-shipping {

    background: #e7f7fb;

    color: #087990;

}

.status-completed {

    background: #e9f8ef;

    color: #18743a;

}

.status-cancelled {

    background: #fff0f2;

    color: #b42332;

}

.status-default {

    background: #f0f1f3;

    color: #626a76;

}

.payment-method {

    margin-bottom: 5px;

    color: #697280;

    font-size: 11px;

    font-weight: 700;

}

.payment-paid {

    background: #e9f8ef;

    color: #18743a;

}

.payment-waiting {

    background: #fff4d8;

    color: #856404;

}

.payment-unpaid,

.payment-default {

    background: #f0f1f3;

    color: #626a76;

}

.payment-failed {

    background: #fff0f2;

    color: #b42332;

}

.payment-note {

    margin-top: 5px;

    color: #8a9099;

    font-size: 10px;

    line-height: 1.4;

}

.cancel-note {

    max-width: 230px;

    margin-top: 7px;

    padding: 7px 9px;

    border-radius: 8px;

    font-size: 10px;

    line-height: 1.45;

    font-weight: 700;

}

.cancel-note.user-cancel {

    background: #fff1f3;

    color: #b42332;

}

.cancel-note.admin-cancel {

    background: #fff5e8;

    color: #9a4d00;

}

.cancel-note.unknown-cancel {

    background: #f3f4f6;

    color: #666d78;

}

.cancel-note-title {

    font-weight: 900;

}

.order-detail-btn {

    min-width: 76px;

    border-radius: 9px;

    font-size: 12px;

    font-weight: 800;

}

.order-empty {

    padding: 55px 20px;

    text-align: center;

}

.order-empty-icon {

    font-size: 35px;

}

.order-pagination {

    padding: 15px;

    border-top: 1px solid var(--order-border);

}

.page-link {

    color: #4b5360;

}

.page-item.active .page-link {

    border-color: var(--order-primary);

    background: var(--order-primary);

}

@media (max-width: 1199px) {

    .order-status-tabs {

        grid-template-columns:

            repeat(3, minmax(0, 1fr));

    }

    .order-filter-grid {

        grid-template-columns:

            repeat(2, minmax(0, 1fr));

    }

    .order-search-wrap {

        grid-column: 1 / -1;

    }

}

@media (max-width: 767px) {

    .order-admin-page {

        padding-top: 18px;

    }

    .order-page-head {

        flex-direction: column;

    }

    .order-status-tabs {

        grid-template-columns:

            repeat(2, minmax(0, 1fr));

    }

    .order-filter-grid {

        grid-template-columns: 1fr;

    }

    .order-filter-actions {

        width: 100%;

    }

    .order-filter-actions .btn {

        flex: 1;

    }

}

</style>

<div class="order-admin-page">

    <div class="container">

        <!-- HEADER -->

        <div class="order-page-head">

            <div>

                <h1 class="order-page-title">

                    Quản lý đơn hàng

                </h1>

                <div class="order-page-subtitle">

                    Theo dõi trạng thái và xử lý đơn hàng của khách.

                </div>

            </div>

            <div class="order-top-actions">

                <a

                    href="../thanh_toan/index.php"

                    class="order-module-link"

                >

                    💳 Thanh toán

                </a>

            </div>

        </div>

        <!-- TRẠNG THÁI NHANH -->

        <div class="order-status-tabs">

            <?php foreach (

                $statusConfig

                as $key => $config

            ): ?>

                <a

                    href="<?= $key === 'all'

                        ? 'index.php'

                        : 'index.php?status=' .

                            urlencode($key) ?>"

                    class="order-status-tab <?= $status === $key

                        ? 'active'

                        : '' ?>"

                >

                    <div class="order-status-tab-info">

                        <div class="order-status-tab-label">

                            <?= htmlspecialchars(

                                $config['label']

                            ) ?>

                        </div>

                        <div class="order-status-tab-number">

                            <?= $statNumbers[$key] ?? 0 ?>

                        </div>

                    </div>

                    <div class="order-status-tab-icon">

                        <?= $config['icon'] ?>

                    </div>

                </a>

            <?php endforeach; ?>

        </div>

        <!-- BỘ LỌC GỌN -->

        <form

            method="GET"

            class="order-filter-card"

        >

            <div class="order-filter-grid">

                <div class="order-search-wrap">

                    <span class="order-search-icon">

                        🔎

                    </span>

                    <input

                        type="text"

                        name="q"

                        class="form-control order-search-input"

                        value="<?= htmlspecialchars($q) ?>"

                        placeholder="Mã vận đơn, khách hàng, mã/tên sản phẩm..."

                    >

                </div>

                <!-- TRẠNG THÁI ĐƠN -->

                <select

                    name="status"

                    class="form-select"

                    title="Trạng thái đơn hàng"

                >

                    <?php foreach (

                        $statusConfig

                        as $key => $config

                    ): ?>

                        <option

                            value="<?= htmlspecialchars($key) ?>"

                            <?= $status === $key

                                ? 'selected'

                                : '' ?>

                        >

                            <?= htmlspecialchars(

                                $config['label']

                            ) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

                <!-- TRẠNG THÁI THANH TOÁN -->

                <select

                    name="payment"

                    class="form-select"

                    title="Trạng thái thanh toán"

                >

                    <?php foreach (

                        $paymentConfig

                        as $key => $config

                    ): ?>

                        <option

                            value="<?= htmlspecialchars($key) ?>"

                            <?= $paymentFilter === $key

                                ? 'selected'

                                : '' ?>

                        >

                            <?= htmlspecialchars(

                                $config['label']

                            ) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

                <div class="order-filter-actions">

                    <button

                        type="submit"

                        class="btn btn-dark"

                    >

                        Lọc

                    </button>

                    <?php if (

                        $q !== '' ||

                        $status !== 'all' ||

                        $paymentFilter !== 'all'

                    ): ?>

                        <a

                            href="index.php"

                            class="btn btn-outline-secondary"

                        >

                            Đặt lại

                        </a>

                    <?php endif; ?>

                </div>

            </div>

        </form>

        <!-- DANH SÁCH -->

        <div class="order-list-card">

            <div class="order-list-head">

                <div class="order-list-title">

                    Danh sách đơn hàng

                </div>

                <?php if ($totalFiltered > 0): ?>

                    <div class="order-list-count">

                        <?= $fromItem ?>

                        -

                        <?= $toItem ?>

                        /

                        <?= $totalFiltered ?>

                        đơn

                    </div>

                <?php endif; ?>

            </div>

            <?php if (empty($donHangList)): ?>

                <div class="order-empty">

                    <div class="order-empty-icon">

                        📭

                    </div>

                    <div class="fw-bold mt-2">

                        Không tìm thấy đơn hàng

                    </div>

                    <div class="text-muted small mt-1">

                        Hãy thử thay đổi từ khóa hoặc trạng thái.

                    </div>

                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table align-middle order-table">

                        <thead>

                            <tr>

                                <th>Mã vận đơn</th>

                                <th>Khách hàng</th>

                                <th>Đơn hàng</th>

                                <th>Trạng thái</th>

                                <th>Thanh toán</th>

                                <th class="text-end">

                                    Thao tác

                                </th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach (

                                $donHangList

                                as $row

                            ): ?>

                                <?php

                                $orderStatus =

                                    trim(

                                        (string)(

                                            $row['trang_thai']

                                            ?? ''

                                        )

                                    );

                                $paymentStatus =

                                    trim(

                                        (string)(

                                            $row[

                                                'trang_thai_thanh_toan'

                                            ]

                                            ?? ''

                                        )

                                    );

                                $paymentMethod =

                                    trim(

                                        (string)(

                                            $row['phuong_thuc']

                                            ?? ''

                                        )

                                    );

                                $laDonDaHuy =

                                    in_array(

                                        $orderStatus,

                                        [

                                            'huy',

                                            'da_huy',

                                            'Hủy',

                                            'Đã hủy'

                                        ],

                                        true

                                    );

                                $lyDoHuy =

                                    trim(

                                        (string)(

                                            $row['ly_do_huy']

                                            ?? ''

                                        )

                                    );

                                $nguoiHuy =

                                    trim(

                                        (string)(

                                            $row['nguoi_huy']

                                            ?? ''

                                        )

                                    );

                                $maVanDon =

                                    trim(

                                        (string)(

                                            $row['ma_van_don']

                                            ?? ''

                                        )

                                    );

                                ?>

                                <tr>

                                    <!-- MÃ VẬN ĐƠN -->

                                    <td>

                                        <?php if (

                                            $maVanDon !== ''

                                        ): ?>

                                            <span class="tracking-code">

                                                <?= htmlspecialchars(

                                                    $maVanDon

                                                ) ?>

                                            </span>

                                        <?php else: ?>

                                            <span class="tracking-empty">

                                                Chưa có mã vận đơn

                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <!-- KHÁCH + NGÀY -->

                                    <td>

                                        <div class="customer-name">

                                            <?= htmlspecialchars(

                                                (string)$row['ho_ten']

                                            ) ?>

                                        </div>

                                        <div class="order-date">

                                            <?= !empty(

                                                $row['ngay_dat']

                                            )

                                                ? date(

                                                    'd/m/Y H:i',

                                                    strtotime(

                                                        $row[

                                                            'ngay_dat'

                                                        ]

                                                    )

                                                )

                                                : '-' ?>

                                        </div>

                                    </td>

                                    <!-- SỐ LƯỢNG + TIỀN -->

                                    <td>

                                        <div class="order-quantity">

                                            <?= (int)$row[

                                                'tong_so_luong'

                                            ] ?>

                                            sản phẩm

                                        </div>

                                        <div class="order-money">

                                            <?= number_format(

                                                (float)$row[

                                                    'tong_tien'

                                                ],

                                                0,

                                                ',',

                                                '.'

                                            ) ?>đ

                                        </div>

                                    </td>

                                    <!-- TRẠNG THÁI -->

                                    <td>

                                        <span

                                            class="order-badge <?= orderStatusClass(

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

                                            <?php if (

                                                $nguoiHuy === 'user'

                                            ): ?>

                                                <div class="cancel-note user-cancel">

                                                    <span class="cancel-note-title">

                                                        Người mua hủy

                                                    </span>

                                                    <?php if (

                                                        $lyDoHuy !== ''

                                                    ): ?>

                                                        :

                                                        <?= htmlspecialchars(

                                                            mb_strimwidth(

                                                                $lyDoHuy,

                                                                0,

                                                                90,

                                                                '...',

                                                                'UTF-8'

                                                            )

                                                        ) ?>

                                                    <?php else: ?>

                                                        · Chưa ghi nhận lý do

                                                    <?php endif; ?>

                                                </div>

                                            <?php elseif (

                                                $nguoiHuy === 'admin'

                                            ): ?>

                                                <div class="cancel-note admin-cancel">

                                                    <span class="cancel-note-title">

                                                        Người bán hủy

                                                    </span>

                                                    <?php if (

                                                        $lyDoHuy !== ''

                                                    ): ?>

                                                        :

                                                        <?= htmlspecialchars(

                                                            mb_strimwidth(

                                                                $lyDoHuy,

                                                                0,

                                                                90,

                                                                '...',

                                                                'UTF-8'

                                                            )

                                                        ) ?>

                                                    <?php else: ?>

                                                        · Chưa ghi nhận lý do

                                                    <?php endif; ?>

                                                </div>

                                            <?php else: ?>

                                                <div class="cancel-note unknown-cancel">

                                                    <span class="cancel-note-title">

                                                        Đơn đã hủy

                                                    </span>

                                                    <?php if (

                                                        $lyDoHuy !== ''

                                                    ): ?>

                                                        :

                                                        <?= htmlspecialchars(

                                                            mb_strimwidth(

                                                                $lyDoHuy,

                                                                0,

                                                                90,

                                                                '...',

                                                                'UTF-8'

                                                            )

                                                        ) ?>

                                                    <?php else: ?>

                                                        · Chưa xác định người hủy

                                                    <?php endif; ?>

                                                </div>

                                            <?php endif; ?>

                                        <?php endif; ?>

                                    </td>

                                    <!-- THANH TOÁN GỌN -->

                                    <td>
                                        <?php if ($paymentMethod !== ''): ?>
                                            <div class="payment-method">
                                                <?= htmlspecialchars($paymentMethod) ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($paymentStatus !== ''): ?>
                                            <span
                                                class="payment-badge <?= paymentStatusClass(
                                                    $paymentStatus
                                                ) ?>"
                                            >
                                                <?= htmlspecialchars($paymentStatus) ?>
                                            </span>

                                            <!-- <div class="payment-note">
                                                Trạng thái  
                                            </div> -->
                                        <?php else: ?>
                                            <span class="payment-badge payment-default">
                                                Chưa có thông tin
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- THAO TÁC -->

                                    <td class="text-end">

                                        <a

                                            href="./chi_tiet.php?id=<?= (int)$row['id'] ?>"

                                            class="btn btn-sm btn-outline-dark order-detail-btn"

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

            <!-- PHÂN TRANG -->

            <?php if ($totalPages > 1): ?>

                <div class="order-pagination">

                    <nav>

                        <ul class="pagination justify-content-center mb-0">

                            <li

                                class="page-item <?= $page <= 1

                                    ? 'disabled'

                                    : '' ?>"

                            >

                                <a

                                    class="page-link"

                                    href="<?= htmlspecialchars(

                                        pageUrl($page - 1, $q, $status, $paymentFilter)

                                    ) ?>"

                                >

                                    ‹

                                </a>

                            </li>

                            <?php

                            $startPage =

                                max(

                                    1,

                                    $page - 2

                                );

                            $endPage =

                                min(

                                    $totalPages,

                                    $page + 2

                                );

                            ?>

                            <?php if (

                                $startPage > 1

                            ): ?>

                                <li class="page-item">

                                    <a

                                        class="page-link"

                                        href="<?= htmlspecialchars(

                                            pageUrl(1, $q, $status, $paymentFilter)

                                        ) ?>"

                                    >

                                        1

                                    </a>

                                </li>

                                <?php if (

                                    $startPage > 2

                                ): ?>

                                    <li class="page-item disabled">

                                        <span class="page-link">

                                            …

                                        </span>

                                    </li>

                                <?php endif; ?>

                            <?php endif; ?>

                            <?php for (

                                $p = $startPage;

                                $p <= $endPage;

                                $p++

                            ): ?>

                                <li

                                    class="page-item <?= $p === $page

                                        ? 'active'

                                        : '' ?>"

                                >

                                    <a

                                        class="page-link"

                                        href="<?= htmlspecialchars(

                                            pageUrl($p, $q, $status, $paymentFilter)

                                        ) ?>"

                                    >

                                        <?= $p ?>

                                    </a>

                                </li>

                            <?php endfor; ?>

                            <?php if (

                                $endPage < $totalPages

                            ): ?>

                                <?php if (

                                    $endPage <

                                    $totalPages - 1

                                ): ?>

                                    <li class="page-item disabled">

                                        <span class="page-link">

                                            …

                                        </span>

                                    </li>

                                <?php endif; ?>

                                <li class="page-item">

                                    <a

                                        class="page-link"

                                        href="<?= htmlspecialchars(

                                            pageUrl($totalPages, $q, $status, $paymentFilter)

                                        ) ?>"

                                    >

                                        <?= $totalPages ?>

                                    </a>

                                </li>

                            <?php endif; ?>

                            <li

                                class="page-item <?= $page >= $totalPages

                                    ? 'disabled'

                                    : '' ?>"

                            >

                                <a

                                    class="page-link"

                                    href="<?= htmlspecialchars(

                                        pageUrl($page + 1, $q, $status, $paymentFilter)

                                    ) ?>"

                                >

                                    ›

                                </a>

                            </li>

                        </ul>

                    </nav>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>

<?php require_once "../../includes/footer.php"; ?>
