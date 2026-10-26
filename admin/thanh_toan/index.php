<?php

if (session_status() === PHP_SESSION_NONE) {

    session_start();

}

require_once "../../middleware/check_admin.php";

require_once "../../config/database.php";

mysqli_report(

    MYSQLI_REPORT_ERROR |

    MYSQLI_REPORT_STRICT

);

/* =========================================================

   HÀM HIỂN THỊ

========================================================= */

function paymentStatusClass(?string $status): string

{

    $status = trim((string)$status);

    if (

        in_array(

            $status,

            ['Đã thanh toán', 'Thành công'],

            true

        )

    ) {

        return 'bg-success';

    }

    if ($status === 'Chờ xác nhận') {

        return 'bg-warning text-dark';

    }

    if (

        in_array(

            $status,

            [

                'Chưa thanh toán',

                'Chờ thanh toán',

                'Chờ xử lý'

            ],

            true

        )

    ) {

        return 'bg-secondary';

    }

    if (

        in_array(

            $status,

            ['Thất bại', 'Đã hủy'],

            true

        )

    ) {

        return 'bg-danger';

    }

    return 'bg-dark';

}

function orderStatusLabel(?string $status): string

{

    $status = trim((string)$status);

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

    return $labels[$status]

        ?? ($status !== '' ? $status : 'Chưa xác định');

}

function orderStatusClass(?string $status): string

{

    $label = orderStatusLabel($status);

    return match ($label) {

        'Chờ xử lý' => 'bg-warning text-dark',

        'Đã duyệt' => 'bg-primary',

        'Đang giao' => 'bg-info text-dark',

        'Hoàn thành' => 'bg-success',

        'Đã hủy' => 'bg-danger',

        default => 'bg-secondary'

    };

}

/* =========================================================

   BỘ LỌC

========================================================= */

$q =

    trim(

        (string)($_GET['q'] ?? '')

    );

$filter =

    trim(

        (string)($_GET['trang_thai'] ?? 'tat_ca')

    );

$allowedFilters = [

    'tat_ca',

    'cho_xac_nhan',

    '',

    'chua_thanh_toan',

    'da_thanh_toan',

    'that_bai'

];

if (!in_array($filter, $allowedFilters, true)) {

    $filter = 'tat_ca';

}

$page =

    max(

        1,

        (int)($_GET['page'] ?? 1)

    );

$limit = 15;

$offset = ($page - 1) * $limit;

/* =========================================================

   WHERE ĐỘNG

   Chỉ lấy THANH TOÁN MỚI NHẤT của mỗi đơn.

========================================================= */

$where = [

    "tt.id = (

        SELECT MAX(tt2.id)

        FROM thanh_toan tt2

        WHERE tt2.don_hang_id = tt.don_hang_id

    )"

];

$params = [];

$types = '';

if ($q !== '') {

    $where[] = "(

        CAST(dh.id AS CHAR) LIKE ?

        OR COALESCE(dh.ma_van_don, '') LIKE ?

        OR nd.ho_ten LIKE ?

        OR nd.email LIKE ?

        OR tt.phuong_thuc LIKE ?

        OR tt.trang_thai LIKE ?

    )";

    $search = '%' . $q . '%';

    for ($i = 0; $i < 6; $i++) {

        $params[] = $search;

        $types .= 's';

    }

}

if ($filter === 'cho_xac_nhan') {

    $where[] = "tt.trang_thai IN (

        'Chờ xác nhận',

        'Chờ thanh toán',

        'Chờ xử lý'

    )";

    $where[] = "UPPER(TRIM(COALESCE(tt.phuong_thuc, ''))) <> 'COD'";

}

if ($filter === 'chua_thanh_toan') {

    $where[] = "tt.trang_thai IN (

        'Chưa thanh toán',

        'Chờ thanh toán',

        'Chờ xử lý'

    )";

    $where[] = "UPPER(TRIM(COALESCE(tt.phuong_thuc, ''))) = 'COD'";

}

if ($filter === 'da_thanh_toan') {

    $where[] = "tt.trang_thai IN (

        'Đã thanh toán',

        'Thành công'

    )";

}

if ($filter === 'that_bai') {

    $where[] = "tt.trang_thai IN (

        'Thất bại',

        'Đã hủy'

    )";

}

$whereSql = implode(

    "\nAND ",

    $where

);

/* =========================================================

   THỐNG KÊ NHANH

========================================================= */

$stats = [

    'tat_ca' => 0,

    'cho_xac_nhan' => 0,

    'chua_thanh_toan' => 0,

    'da_thanh_toan' => 0,

    'that_bai' => 0,

    'tong_da_ghi_nhan' => 0

];

$sqlStats = "

    SELECT

        COUNT(*) AS tat_ca,

        SUM(

            CASE

                WHEN x.trang_thai IN (

                    'Chờ xác nhận',

                    'Chờ thanh toán',

                    'Chờ xử lý'

                )

                AND UPPER(TRIM(COALESCE(x.phuong_thuc, ''))) <> 'COD'

                THEN 1 ELSE 0

            END

        ) AS cho_xac_nhan,

        SUM(

            CASE

                WHEN x.trang_thai IN (

                    'Chưa thanh toán',

                    'Chờ thanh toán',

                    'Chờ xử lý'

                )

                AND UPPER(TRIM(COALESCE(x.phuong_thuc, ''))) = 'COD'

                THEN 1 ELSE 0

            END

        ) AS chua_thanh_toan,

        SUM(

            CASE

                WHEN x.trang_thai IN (

                    'Đã thanh toán',

                    'Thành công'

                )

                THEN 1 ELSE 0

            END

        ) AS da_thanh_toan,

        SUM(

            CASE

                WHEN x.trang_thai IN (

                    'Thất bại',

                    'Đã hủy'

                )

                THEN 1 ELSE 0

            END

        ) AS that_bai,

        SUM(

            CASE

                WHEN x.trang_thai IN (

                    'Đã thanh toán',

                    'Thành công'

                )

                THEN x.tong_tien

                ELSE 0

            END

        ) AS tong_da_ghi_nhan

    FROM (

        SELECT

            tt.trang_thai,

            tt.phuong_thuc,

            dh.tong_tien

        FROM thanh_toan tt

        JOIN don_hang dh

            ON dh.id = tt.don_hang_id

        WHERE tt.id = (

            SELECT MAX(tt2.id)

            FROM thanh_toan tt2

            WHERE tt2.don_hang_id = tt.don_hang_id

        )

    ) x

";

$statRow = $conn

    ->query($sqlStats)

    ->fetch_assoc();

if ($statRow) {

    foreach (

        [

            'tat_ca',

            'cho_xac_nhan',

            '',

            'chua_thanh_toan',

            'da_thanh_toan',

            'that_bai'

        ] as $key

    ) {

        $stats[$key] = (int)($statRow[$key] ?? 0);

    }

    $stats['tong_da_ghi_nhan'] =

        (float)($statRow['tong_da_ghi_nhan'] ?? 0);

}

/* =========================================================

   ĐẾM KẾT QUẢ

========================================================= */

$sqlCount = "

    SELECT COUNT(*) AS total

    FROM thanh_toan tt

    JOIN don_hang dh

        ON dh.id = tt.don_hang_id

    JOIN nguoi_dung nd

        ON nd.id = dh.nguoi_dung_id

    WHERE $whereSql

";

$stmtCount =

    $conn->prepare($sqlCount);

if ($types !== '') {

    $stmtCount->bind_param(

        $types,

        ...$params

    );

}

$stmtCount->execute();

$totalRows =

    (int)(

        $stmtCount

            ->get_result()

            ->fetch_assoc()['total']

        ?? 0

    );

$totalPages =

    max(

        1,

        (int)ceil($totalRows / $limit)

    );

if ($page > $totalPages) {

    $page = $totalPages;

    $offset = ($page - 1) * $limit;

}

/* =========================================================

   DANH SÁCH

========================================================= */

$sqlList = "

    SELECT

        tt.id AS thanh_toan_id,

        tt.don_hang_id,

        tt.phuong_thuc,

        tt.trang_thai AS trang_thai_thanh_toan,

        tt.ngay_thanh_toan,

        dh.ma_van_don,

        dh.tong_tien,

        dh.trang_thai AS trang_thai_don,

        dh.ngay_dat,

        nd.ho_ten,

        nd.email

    FROM thanh_toan tt

    JOIN don_hang dh

        ON dh.id = tt.don_hang_id

    JOIN nguoi_dung nd

        ON nd.id = dh.nguoi_dung_id

    WHERE $whereSql

    ORDER BY

        CASE

            WHEN dh.trang_thai IN (

                'huy',

                'da_huy',

                'Hủy',

                'Đã hủy'

            )

            AND UPPER(TRIM(COALESCE(tt.phuong_thuc, ''))) <> 'COD'

            AND tt.trang_thai IN (

                'Chờ xác nhận',

                'Chờ thanh toán',

                'Chờ xử lý'

            )

                THEN 0

            WHEN dh.trang_thai NOT IN (

                'huy',

                'da_huy',

                'Hủy',

                'Đã hủy'

            )

            AND UPPER(TRIM(COALESCE(tt.phuong_thuc, ''))) <> 'COD'

            AND tt.trang_thai IN (

                'Chờ xác nhận',

                'Chờ thanh toán',

                'Chờ xử lý'

            )

                THEN 1

            WHEN UPPER(TRIM(COALESCE(tt.phuong_thuc, ''))) = 'COD'

            AND tt.trang_thai IN (

                'Chưa thanh toán',

                'Chờ thanh toán',

                'Chờ xử lý'

            )

                THEN 2

            ELSE 3

        END,

        tt.id DESC

    LIMIT ?

    OFFSET ?

";

$stmtList =

    $conn->prepare($sqlList);

$listParams = $params;

$listTypes = $types . 'ii';

$listParams[] = $limit;

$listParams[] = $offset;

$stmtList->bind_param(

    $listTypes,

    ...$listParams

);

$stmtList->execute();

$payments =

    $stmtList

        ->get_result()

        ->fetch_all(MYSQLI_ASSOC);

/* =========================================================

   FLASH

========================================================= */

$successFlash =

    $_SESSION['admin_payment_success']

    ?? '';

$errorFlash =

    $_SESSION['admin_payment_error']

    ?? '';

unset(

    $_SESSION['admin_payment_success'],

    $_SESSION['admin_payment_error']

);

$queryBase = [

    'q' => $q,

    'trang_thai' => $filter

];

function buildPaymentUrl(

    array $base,

    int $page

): string {

    $base['page'] = $page;

    return '?' . http_build_query($base);

}

?>

<?php require_once "../../includes/header.php"; ?>

<?php require_once "../../includes/navbar.php"; ?>

<link

    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"

    rel="stylesheet"

>

<style>

.payment-admin-page {

    min-height: 70vh;

    background: #f6f7f9;

    font-size: 15px;

}

.payment-card {

    background: #fff;

    border: 1px solid #ececec;

    border-radius: 18px;

    box-shadow: 0 8px 28px rgba(0, 0, 0, .05);

}

.page-title {

    font-size: 30px;

    font-weight: 850;

}

.stats-grid {

    display: grid;

    grid-template-columns: repeat(4, minmax(0, 1fr));

    gap: 12px;

}

.stat-card {

    padding: 15px;

    border: 1px solid #ececec;

    border-radius: 14px;

    background: #fff;

}

.stat-label {

    color: #777;

    font-size: 13px;

    font-weight: 700;

}

.stat-value {

    margin-top: 4px;

    color: #222;

    font-size: 23px;

    font-weight: 850;

}

.stat-money {

    color: #198754;

    font-size: 15px;

}

.filter-chip {

    text-decoration: none;

    border-radius: 999px;

    padding: 8px 13px;

    border: 1px solid #ddd;

    color: #555;

    background: #fff;

    font-size: 13px;

    font-weight: 750;

}

.filter-chip:hover {

    color: #d70018;

    border-color: #d70018;

}

.filter-chip.active {

    color: #fff;

    border-color: #d70018;

    background: #d70018;

}

.payment-table {

    font-size: 14px;

}

.payment-table th {

    white-space: nowrap;

    font-size: 14px;

}

.customer-name {

    font-weight: 800;

}

.customer-email {

    color: #777;

    font-size: 12px;

}

.money {

    color: #d70018;

    font-weight: 850;

    white-space: nowrap;

}

.method-badge {

    display: inline-flex;

    align-items: center;

    padding: 6px 9px;

    border-radius: 8px;

    background: #f3f4f6;

    color: #333;

    font-weight: 750;

}

.order-code {

    color: #0d6efd;

    font-weight: 800;

}

.pagination .page-link {

    color: #d70018;

}

.pagination .active .page-link {

    border-color: #d70018;

    background: #d70018;

}

@media (max-width: 992px) {

    .stats-grid {

        grid-template-columns: repeat(2, minmax(0, 1fr));

    }

}

@media (max-width: 576px) {

    .page-title {

        font-size: 25px;

    }

    .stats-grid {

        grid-template-columns: 1fr;

    }

}

/* ===== Khối thống kê thanh toán ===== */
.payment-summary-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 16px;
}

.payment-summary-card {
    min-width: 0;
    min-height: 128px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 18px 20px;
    border: 1px solid #e6e9ee;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 7px 22px rgba(0, 0, 0, .04);
}

.payment-summary-label {
    color: #6b7280;
    font-size: 13px;
    line-height: 1.4;
    font-weight: 750;
}

.payment-summary-value {
    margin-top: 14px;
    color: #111827;
    font-size: 30px;
    line-height: 1.05;
    font-weight: 850;
    overflow-wrap: anywhere;
}

.payment-summary-card.is-waiting .payment-summary-value {
    color: #f59e0b;
}

.payment-summary-card.is-unpaid .payment-summary-value {
    color: #6b7280;
}

.payment-summary-card.is-paid .payment-summary-value,
.payment-summary-card.is-total .payment-summary-value {
    color: #198754;
}

.payment-summary-money {
    font-size: 23px;
    line-height: 1.2;
}

.payment-filter-card {
    padding: 18px;
    border: 1px solid #e6e9ee;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 7px 22px rgba(0, 0, 0, .04);
}

@media (max-width: 1199.98px) {
    .payment-summary-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .payment-summary-card.is-total {
        grid-column: span 2;
    }
}

@media (max-width: 767.98px) {
    .payment-summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .payment-summary-card {
        min-height: 108px;
        padding: 15px 16px;
    }

    .payment-summary-card.is-total {
        grid-column: 1 / -1;
    }

    .payment-summary-value {
        font-size: 25px;
    }

    .payment-summary-money {
        font-size: 21px;
    }

    .payment-filter-card {
        padding: 15px;
    }
}

@media (max-width: 420px) {
    .payment-summary-grid {
        grid-template-columns: 1fr;
    }

    .payment-summary-card.is-total {
        grid-column: auto;
    }
}
</style>

<div class="payment-admin-page py-4">

    <div class="container">

        <div

            class="d-flex flex-wrap gap-3 align-items-center justify-content-between mb-4"

        >

            <div>

                <div class="page-title">

                    💳 Quản lý thanh toán

                </div>

                <div class="text-muted mt-1">

                    Theo dõi và cập nhật trạng thái thanh toán cho COD, QR và ví điện tử.

                </div>

            </div>

            <a

                href="/iPhoneStore/admin/index.php"

                class="btn btn-outline-secondary"

            >

                ⬅ Dashboard

            </a>

        </div>

        <?php if ($successFlash !== ''): ?>

            <div class="alert alert-success">

                ✅ <?= htmlspecialchars($successFlash) ?>

            </div>

        <?php endif; ?>

        <?php if ($errorFlash !== ''): ?>

            <div class="alert alert-danger">

                ❌ <?= htmlspecialchars($errorFlash) ?>

            </div>

        <?php endif; ?>

        <div class="payment-summary-grid mb-4">
            <div class="payment-summary-card">
                <div class="payment-summary-label">Tất cả</div>
                <div class="payment-summary-value">
                    <?= number_format((int)$stats['tat_ca']) ?>
                </div>
            </div>

            <div class="payment-summary-card is-waiting">
                <div class="payment-summary-label">Chờ xác nhận</div>
                <div class="payment-summary-value">
                    <?= number_format((int)$stats['cho_xac_nhan']) ?>
                </div>
            </div>

            <div class="payment-summary-card is-unpaid">
                <div class="payment-summary-label">Chưa thanh toán</div>
                <div class="payment-summary-value">
                    <?= number_format((int)$stats['chua_thanh_toan']) ?>
                </div>
            </div>

            <div class="payment-summary-card is-paid">
                <div class="payment-summary-label">Đã thanh toán</div>
                <div class="payment-summary-value">
                    <?= number_format((int)$stats['da_thanh_toan']) ?>
                </div>
            </div>

            <div class="payment-summary-card is-total">
                <div class="payment-summary-label">
                    Tổng tiền đã thanh toán
                </div>
                <div class="payment-summary-value payment-summary-money">
                    <?= number_format(
                        (float)$stats['tong_da_ghi_nhan'],
                        0,
                        ',',
                        '.'
                    ) ?>đ
                </div>
            </div>
        </div>

        <div class="payment-filter-card mb-4">
            <form
                method="GET"
                class="row g-3 align-items-end"
            >
                <div class="col-lg-6">
                    <label class="form-label fw-bold">
                        Tìm kiếm
                    </label>

                    <input
                        type="text"
                        name="q"
                        class="form-control"
                        value="<?= htmlspecialchars($q) ?>"
                        placeholder="Mã vận đơn, khách hàng, email, phương thức, trạng thái..."
                    >
                </div>

                <div class="col-lg-3">
                    <label class="form-label fw-bold">
                        Trạng thái thanh toán
                    </label>

                    <select
                        name="trang_thai"
                        class="form-select"
                    >
                        <option value="tat_ca" <?= $filter === 'tat_ca' ? 'selected' : '' ?>>
                            Tất cả
                        </option>
                        <option value="cho_xac_nhan" <?= $filter === 'cho_xac_nhan' ? 'selected' : '' ?>>
                            Chờ xác nhận
                        </option>
                        <option value="chua_thanh_toan" <?= $filter === 'chua_thanh_toan' ? 'selected' : '' ?>>
                            Chưa thanh toán
                        </option>
                        <option value="da_thanh_toan" <?= $filter === 'da_thanh_toan' ? 'selected' : '' ?>>
                            Đã thanh toán
                        </option>
                        <option value="that_bai" <?= $filter === 'that_bai' ? 'selected' : '' ?>>
                            Thất bại
                        </option>
                    </select>
                </div>

                <div class="col-lg-3">
                    <div class="d-flex gap-2">
                        <button
                            type="submit"
                            class="btn btn-danger flex-grow-1"
                        >
                            🔎 Lọc
                        </button>

                        <a
                            href="index.php"
                            class="btn btn-outline-secondary"
                        >
                            Xóa lọc
                        </a>
                    </div>
                </div>
            </form>

            <div class="d-flex flex-wrap gap-2 mt-3">
                <?php
                $chips = [
                    'tat_ca' => 'Tất cả',
                    'cho_xac_nhan' => 'Chờ xác nhận',
                    'chua_thanh_toan' => 'Chưa thanh toán',
                    'da_thanh_toan' => 'Đã thanh toán',
                    'that_bai' => 'Thất bại'
                ];
                ?>

                <?php foreach ($chips as $key => $label): ?>
                    <a
                        class="filter-chip <?= $filter === $key ? 'active' : '' ?>"
                        href="?<?= http_build_query([
                            'q' => $q,
                            'trang_thai' => $key
                        ]) ?>"
                    >
                        <?= htmlspecialchars($label) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="payment-card overflow-hidden">

            <?php if (empty($payments)): ?>

                <div class="text-center text-muted py-5">

                    Chưa có thanh toán phù hợp bộ lọc.

                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table table-hover align-middle mb-0 payment-table">

                        <thead class="table-light">

                            <tr>

                                <th>Mã vận đơn</th>

                                <th>Khách hàng</th>

                                <th>Số tiền</th>

                                <th>Phương thức</th>

                                <th>Thanh toán</th>

                                <th>Đơn hàng</th>

                                <th>Ngày thanh toán</th>

                                <th class="text-end">Thao tác</th>

                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach ($payments as $row): ?>

                            <?php

                                $rowOrderStatus =

                                    trim(

                                        (string)(

                                            $row['trang_thai_don']

                                            ?? ''

                                        )

                                    );

                                $rowPaymentStatus =

                                    trim(

                                        (string)(

                                            $row['trang_thai_thanh_toan']

                                            ?? ''

                                        )

                                    );

                                $rowPaymentMethod =

                                    trim(

                                        (string)(

                                            $row['phuong_thuc']

                                            ?? ''

                                        )

                                    );

                            ?>

                            <tr>

                                <td>

                                    <?php

                                        $maVanDonHienThi =

                                            trim(

                                                (string)(

                                                    $row['ma_van_don']

                                                    ?? ''

                                                )

                                            );

                                    ?>

                                    <?php if ($maVanDonHienThi !== ''): ?>

                                        <div class="order-code">

                                            <?= htmlspecialchars(

                                                $maVanDonHienThi

                                            ) ?>

                                        </div>

                                    <?php else: ?>

                                        <span class="text-muted">

                                            Chưa có mã vận đơn

                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <div class="customer-name">

                                        <?= htmlspecialchars($row['ho_ten']) ?>

                                    </div>

                                    <div class="customer-email">

                                        <?= htmlspecialchars($row['email']) ?>

                                    </div>

                                </td>

                                <td class="money">

                                    <?= number_format(

                                        (float)$row['tong_tien'],

                                        0,

                                        ',',

                                        '.'

                                    ) ?>đ

                                </td>

                                <td>

                                    <span class="method-badge">

                                        <?= htmlspecialchars(

                                            $row['phuong_thuc']

                                        ) ?>

                                    </span>

                                </td>

                                <td>

                                    <span

                                            class="badge <?= paymentStatusClass(

                                                $rowPaymentStatus

                                            ) ?>"

                                        >

                                            <?= htmlspecialchars(

                                                $rowPaymentStatus

                                            ) ?>

                                        </span>

                                        <!-- <div class="text-muted small mt-1">

                                            Trạng thái  

                                        </div> -->

                                </td>

                                <td>

                                    <span

                                        class="badge <?= orderStatusClass(

                                            $row['trang_thai_don']

                                        ) ?>"

                                    >

                                        <?= htmlspecialchars(

                                            orderStatusLabel(

                                                $row['trang_thai_don']

                                            )

                                        ) ?>

                                    </span>

                                </td>

                                <td>

                                    <?php if (

                                        !empty($row['ngay_thanh_toan']) &&

                                        in_array(

                                            $row['trang_thai_thanh_toan'],

                                            [

                                                'Đã thanh toán',

                                                'Thành công'

                                            ],

                                            true

                                        )

                                    ): ?>

                                        <?= date(

                                            'd/m/Y H:i',

                                            strtotime(

                                                $row['ngay_thanh_toan']

                                            )

                                        ) ?>

                                    <?php else: ?>

                                        <span class="text-muted">—</span>

                                    <?php endif; ?>

                                </td>

                                <td class="text-end">

                                    <a

                                        href="chi_tiet.php?id=<?= (int)$row['thanh_toan_id'] ?>"

                                        class="btn btn-sm btn-outline-primary"

                                    >

                                        Xem

                                    </a>

                                    <a

                                        href="../don_hang/chi_tiet.php?id=<?= (int)$row['don_hang_id'] ?>"

                                        class="btn btn-sm btn-outline-secondary"

                                    >

                                        Đơn hàng

                                    </a>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

            <?php if ($totalPages > 1): ?>

                <div class="p-3 border-top">

                    <nav>

                        <ul class="pagination justify-content-center mb-0">

                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">

                                <a

                                    class="page-link"

                                    href="<?= htmlspecialchars(

                                        buildPaymentUrl(

                                            $queryBase,

                                            max(1, $page - 1)

                                        )

                                    ) ?>"

                                >

                                    ‹

                                </a>

                            </li>

                            <?php

                            $startPage = max(1, $page - 2);

                            $endPage = min($totalPages, $page + 2);

                            ?>

                            <?php for ($p = $startPage; $p <= $endPage; $p++): ?>

                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">

                                    <a

                                        class="page-link"

                                        href="<?= htmlspecialchars(

                                            buildPaymentUrl(

                                                $queryBase,

                                                $p

                                            )

                                        ) ?>"

                                    >

                                        <?= $p ?>

                                    </a>

                                </li>

                            <?php endfor; ?>

                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">

                                <a

                                    class="page-link"

                                    href="<?= htmlspecialchars(

                                        buildPaymentUrl(

                                            $queryBase,

                                            min(

                                                $totalPages,

                                                $page + 1

                                            )

                                        )

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
