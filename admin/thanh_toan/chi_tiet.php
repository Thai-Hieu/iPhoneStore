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

    return match (orderStatusLabel($status)) {

        'Chờ xử lý' => 'bg-warning text-dark',

        'Đã duyệt' => 'bg-primary',

        'Đang giao' => 'bg-info text-dark',

        'Hoàn thành' => 'bg-success',

        'Đã hủy' => 'bg-danger',

        default => 'bg-secondary'

    };

}

function isCancelledOrder(?string $status): bool

{

    return orderStatusLabel($status) === 'Đã hủy';

}

function layCotAnhBienThe(mysqli $conn): ?string

{

    foreach (['hinh_anh', 'duong_dan_anh'] as $columnName) {

        $stmt = $conn->prepare("

            SELECT 1

            FROM INFORMATION_SCHEMA.COLUMNS

            WHERE TABLE_SCHEMA = DATABASE()

              AND TABLE_NAME = 'san_pham_bien_the_hinh_anh'

              AND COLUMN_NAME = ?

            LIMIT 1

        ");

        $stmt->bind_param('s', $columnName);

        $stmt->execute();

        if ($stmt->get_result()->fetch_assoc()) {

            return $columnName;

        }

    }

    return null;

}

$id =

    (int)($_GET['id'] ?? 0);

if ($id <= 0) {

    die('Thanh toán không hợp lệ.');

}

/* =========================================================

   THANH TOÁN + ĐƠN + KHÁCH

========================================================= */

$stmt = $conn->prepare("

    SELECT

        tt.id AS thanh_toan_id,

        tt.don_hang_id,

        tt.phuong_thuc,

        tt.trang_thai AS trang_thai_thanh_toan,

        tt.ngay_thanh_toan,

        dh.ma_van_don,

        dh.nguoi_dung_id,

        dh.tong_tien,

        dh.trang_thai AS trang_thai_don,

        dh.ngay_dat,

        dh.ly_do_huy,

        dh.ngay_huy,

        dh.nguoi_huy,

        dh.ten_nguoi_nhan,

        dh.so_dien_thoai_nhan,

        dh.dia_chi_nhan,

        nd.ho_ten,

        nd.email,

        nd.so_dien_thoai,

        nd.dia_chi

    FROM thanh_toan tt

    JOIN don_hang dh

        ON dh.id = tt.don_hang_id

    JOIN nguoi_dung nd

        ON nd.id = dh.nguoi_dung_id

    WHERE tt.id = ?

    LIMIT 1

");

$stmt->bind_param(

    "i",

    $id

);

$stmt->execute();

$payment =

    $stmt

        ->get_result()

        ->fetch_assoc();

if (!$payment) {

    die('Không tìm thấy thanh toán.');

}

$donHangId = (int)$payment['don_hang_id'];

/* =========================================================
   SẢN PHẨM TRONG ĐƠN
========================================================= */

$cotAnhBienThe = layCotAnhBienThe($conn);

/*
    Lấy dòng chi tiết đơn trước.
    Không phụ thuộc JOIN để tránh mất dòng sản phẩm.
*/
$donHangId = (int)$donHangId;

$sqlItems = "
    SELECT
        id,
        san_pham_id,
        gia,
        so_luong,
        mau_id,
        bien_the_id
    FROM chi_tiet_don_hang
    WHERE don_hang_id = {$donHangId}
    ORDER BY id ASC
";

$resultItems = $conn->query($sqlItems);

$orderItems = $resultItems
    ? $resultItems->fetch_all(MYSQLI_ASSOC)
    : [];

/*
    Fallback theo mã vận đơn nếu dữ liệu liên kết cũ có vấn đề.
*/
if (
    empty($orderItems) &&
    !empty($payment['ma_van_don'])
) {
    $maVanDonSql = $conn->real_escape_string(
        (string)$payment['ma_van_don']
    );

    $sqlFallbackOrder = "
        SELECT id
        FROM don_hang
        WHERE ma_van_don = '{$maVanDonSql}'
        LIMIT 1
    ";

    $fallbackOrder = $conn
        ->query($sqlFallbackOrder)
        ->fetch_assoc();

    $fallbackDonHangId =
        (int)($fallbackOrder['id'] ?? 0);

    if ($fallbackDonHangId > 0) {
        $sqlFallbackItems = "
            SELECT
                id,
                san_pham_id,
                gia,
                so_luong,
                mau_id,
                bien_the_id
            FROM chi_tiet_don_hang
            WHERE don_hang_id = {$fallbackDonHangId}
            ORDER BY id ASC
        ";

        $fallbackResult = $conn->query(
            $sqlFallbackItems
        );

        $orderItems = $fallbackResult
            ? $fallbackResult->fetch_all(MYSQLI_ASSOC)
            : [];

        if (!empty($orderItems)) {
            $donHangId = $fallbackDonHangId;
        }
    }
}

if (empty($orderItems)) {
    $dbRow = $conn->query(
        "SELECT DATABASE() AS db_name"
    )->fetch_assoc();

    error_log(
        '[iPhoneStore payment detail] ' .
        'payment_id=' . $id .
        '; don_hang_id=' . $donHangId .
        '; ma_van_don=' . (string)($payment['ma_van_don'] ?? '') .
        '; database=' . (string)($dbRow['db_name'] ?? '')
    );
}

/*
    Bổ sung thông tin hiển thị cho từng dòng.
*/
$stmtProductInfo = $conn->prepare("
    SELECT
        sp.ma_san_pham,
        sp.ten_san_pham,
        sp.hinh_anh AS hinh_anh_san_pham,
        ms.ten_mau,
        bt.ram,
        bt.rom
    FROM san_pham sp
    LEFT JOIN mau_sac ms
        ON ms.id = ?
    LEFT JOIN san_pham_bien_the bt
        ON bt.id = ?
        AND bt.san_pham_id = sp.id
    WHERE sp.id = ?
    LIMIT 1
");

$stmtVariantImage = null;

if ($cotAnhBienThe !== null) {
    $sqlVariantImage = "
        SELECT `{$cotAnhBienThe}` AS hinh_anh_bien_the
        FROM san_pham_bien_the_hinh_anh
        WHERE bien_the_id = ?
        ORDER BY
            la_anh_chinh DESC,
            thu_tu ASC,
            id ASC
        LIMIT 1
    ";

    $stmtVariantImage = $conn->prepare(
        $sqlVariantImage
    );
}

foreach ($orderItems as &$item) {
    $mauId =
        !empty($item['mau_id'])
            ? (int)$item['mau_id']
            : 0;

    $bienTheId =
        !empty($item['bien_the_id'])
            ? (int)$item['bien_the_id']
            : 0;

    $sanPhamId =
        (int)$item['san_pham_id'];

    $stmtProductInfo->bind_param(
        "iii",
        $mauId,
        $bienTheId,
        $sanPhamId
    );

    $stmtProductInfo->execute();

    $productInfo = $stmtProductInfo
        ->get_result()
        ->fetch_assoc()
        ?: [];

    $item = array_merge(
        $item,
        $productInfo
    );

    $hinhAnhBienThe = '';

    if (
        $stmtVariantImage !== null &&
        $bienTheId > 0
    ) {
        $stmtVariantImage->bind_param(
            "i",
            $bienTheId
        );

        $stmtVariantImage->execute();

        $variantImageRow = $stmtVariantImage
            ->get_result()
            ->fetch_assoc();

        $hinhAnhBienThe = trim(
            (string)(
                $variantImageRow['hinh_anh_bien_the']
                ?? ''
            )
        );
    }

    $hinhAnhSanPham = trim(
        (string)(
            $item['hinh_anh_san_pham']
            ?? ''
        )
    );

    $item['hinh_anh_bien_the'] =
        $hinhAnhBienThe;

    $item['hinh_anh'] =
        $hinhAnhBienThe !== ''
            ? $hinhAnhBienThe
            : $hinhAnhSanPham;
}

unset($item);

/* =========================================================

   NGHIỆP VỤ THANH TOÁN GIẢ LẬP

   Thanh toán chỉ dùng để mô phỏng và được cập nhật

   độc lập với trạng thái đơn hàng.

========================================================= */

$tenNguoiNhan = trim((string)($payment['ten_nguoi_nhan'] ?? ''));

if ($tenNguoiNhan === '') {

    $tenNguoiNhan = trim((string)($payment['ho_ten'] ?? ''));

}

$soDienThoaiNhan = trim((string)($payment['so_dien_thoai_nhan'] ?? ''));

if ($soDienThoaiNhan === '') {

    $soDienThoaiNhan = trim((string)($payment['so_dien_thoai'] ?? ''));

}

$diaChiNhan = trim((string)($payment['dia_chi_nhan'] ?? ''));

if ($diaChiNhan === '') {

    $diaChiNhan = trim((string)($payment['dia_chi'] ?? ''));

}

$paymentMethod =

    trim(

        (string)$payment['phuong_thuc']

    );

$paymentStatus =

    trim(

        (string)$payment['trang_thai_thanh_toan']

    );

$orderStatus =

    trim(

        (string)$payment['trang_thai_don']

    );

$isCOD =

    strtoupper($paymentMethod) === 'COD';

$isPaid =

    in_array(

        $paymentStatus,

        ['Đã thanh toán', 'Thành công'],

        true

    );

$isAwaitingOnline =

    !$isCOD &&

    in_array(

        $paymentStatus,

        [

            'Chờ xác nhận',

            'Chờ thanh toán',

            'Chờ xử lý'

        ],

        true

    );

$canConfirmOnline =

    $isAwaitingOnline;

$canConfirmCOD =

    $isCOD &&

    !$isPaid &&

    in_array(

        $paymentStatus,

        [

            'Chưa thanh toán',

            'Chờ thanh toán'

        ],

        true

    );

if (empty($_SESSION['csrf_admin_payment'])) {

    $_SESSION['csrf_admin_payment'] =

        bin2hex(random_bytes(32));

}

$paymentCsrfToken =

    (string)$_SESSION['csrf_admin_payment'];

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

?>

<?php require_once "../../includes/header.php"; ?>

<?php require_once "../../includes/navbar.php"; ?>

<link

    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"

    rel="stylesheet"

>

<style>

.payment-detail-page {

    min-height: 70vh;

    background: #f6f7f9;

    font-size: 15px;

}

.page-title {

    font-size: 30px;

    font-weight: 850;

}

.detail-card {

    background: #fff;

    border: 1px solid #ececec;

    border-radius: 18px;

    overflow: hidden;

    box-shadow: 0 8px 28px rgba(0, 0, 0, .05);

}

.section-title {

    font-size: 20px;

    font-weight: 850;

}

.info-grid {

    display: grid;

    grid-template-columns: repeat(2, minmax(0, 1fr));

    gap: 14px;

}

.info-item {

    padding: 14px 15px;

    border: 1px solid #eee;

    border-radius: 12px;

    background: #fafafa;

}

.info-label {

    color: #777;

    font-size: 13px;

    font-weight: 700;

}

.info-value {

    margin-top: 5px;

    color: #222;

    font-size: 16px;

    font-weight: 750;

    word-break: break-word;

}

.money {

    color: #d70018;

    font-size: 22px;

    font-weight: 850;

}

.product-image {

    width: 66px;

    height: 66px;

    object-fit: contain;

    border: 1px solid #eee;

    border-radius: 10px;

    background: #fff;

}

.product-name {

    font-size: 15px;

    font-weight: 800;

}

.product-meta {

    margin-top: 3px;

    color: #777;

    font-size: 13px;

}

.product-code {

    color: #ee4d2d;

    font-size: 12px;

    font-weight: 800;

}

.payment-action {

    padding: 18px;

    border-radius: 14px;

}

.payment-action.online {

    border: 1px solid #ffe0a6;

    background: #fff9ed;

}

.payment-action.cod {

    border: 1px solid #cfe8d7;

    background: #f2fbf5;

}

.payment-action.online {

    border: 1px solid #f0c36d;

    background: #fff9e8;

}

.reconcile-actions {

    display: grid;

    grid-template-columns: 1fr 1fr;

    gap: 10px;

    margin-top: 12px;

}

.reconcile-actions form {

    margin: 0;

}

.reconcile-actions .btn {

    width: 100%;

    margin-top: 0;

}

@media (max-width: 575px) {

    .reconcile-actions {

        grid-template-columns: 1fr;

    }

}

.payment-action-title {

    font-size: 17px;

    font-weight: 850;

}

.payment-action-note {

    margin-top: 6px;

    color: #666;

    font-size: 14px;

    line-height: 1.6;

}

.payment-action .btn {

    margin-top: 12px;

    min-height: 44px;

    font-size: 15px;

    font-weight: 800;

}

@media (max-width: 767px) {

    .page-title {

        font-size: 25px;

    }

    .info-grid {

        grid-template-columns: 1fr;

    }

}

</style>

<div class="payment-detail-page py-4">

    <div class="container">

        <div

            class="d-flex flex-wrap gap-3 align-items-center justify-content-between mb-4"

        >

            <div>

                <div class="page-title">

                    💳 Chi tiết thanh toán

                </div>

                <div class="text-muted mt-1">

                    <?php if (!empty($payment['ma_van_don'])): ?>

                        Mã vận đơn:

                        <strong>

                            <?= htmlspecialchars($payment['ma_van_don']) ?>

                        </strong>

                    <?php else: ?>

                        Chưa có mã vận đơn

                    <?php endif; ?>

                </div>

            </div>

            <div class="d-flex gap-2">

                <a

                    href="index.php"

                    class="btn btn-outline-secondary"

                >

                    ⬅ Thanh toán

                </a>

                <a

                    href="../don_hang/chi_tiet.php?id=<?= $donHangId ?>"

                    class="btn btn-outline-primary"

                >

                    🧾 Xem đơn hàng

                </a>

            </div>

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

        <div class="row g-4">

            <div class="col-lg-7">

                <div class="detail-card mb-4">

                    <div class="p-3 border-bottom">

                        <div class="section-title">

                            📦 Đơn hàng

                        </div>

                    </div>

                    <div class="p-3">

                        <div class="info-grid">

                            <div class="info-item">

                                <div class="info-label">

                                    Mã vận đơn

                                </div>

                                <div class="info-value">

                                    <?= !empty($payment['ma_van_don'])

                                        ? htmlspecialchars(

                                            $payment['ma_van_don']

                                        )

                                        : 'Chưa có mã vận đơn' ?>

                                </div>

                            </div>

                            <div class="info-item">

                                <div class="info-label">

                                    Trạng thái đơn

                                </div>

                                <div class="info-value">

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

                                </div>

                            </div>

                            <div class="info-item">

                                <div class="info-label">

                                    Ngày đặt

                                </div>

                                <div class="info-value">

                                    <?= !empty($payment['ngay_dat'])

                                        ? date(

                                            'd/m/Y H:i',

                                            strtotime(

                                                $payment['ngay_dat']

                                            )

                                        )

                                        : '—' ?>

                                </div>

                            </div>

                        </div>

                    </div>

                    <div class="border-top">

                        <?php if (empty($orderItems)): ?>

                            <div class="text-center text-muted py-4">

                                Chưa có dữ liệu sản phẩm.

                            </div>

                        <?php else: ?>

                            <?php foreach ($orderItems as $item): ?>

                                <div class="p-3 border-bottom">

                                    <div class="d-flex gap-3 align-items-center">

                                        <?php if (!empty($item['hinh_anh'])): ?>

                                            <img

                                                src="../../uploads/<?= htmlspecialchars(

                                                    $item['hinh_anh']

                                                ) ?>"

                                                class="product-image"

                                                alt=""

                                            >

                                        <?php else: ?>

                                            <div

                                                class="product-image d-flex align-items-center justify-content-center text-muted small"

                                            >

                                                No image

                                            </div>

                                        <?php endif; ?>

                                        <div class="flex-grow-1">

                                            <div class="product-name">

                                                <?= htmlspecialchars(

                                                    $item['ten_san_pham']

                                                    ?: (

                                                        'Sản phẩm #' .

                                                        (int)$item['san_pham_id']

                                                    )

                                                ) ?>

                                            </div>

                                            <?php if (!empty($item['ma_san_pham'])): ?>

                                                <div class="product-code">

                                                    Mã SP:

                                                    <?= htmlspecialchars(

                                                        $item['ma_san_pham']

                                                    ) ?>

                                                </div>

                                            <?php endif; ?>

                                            <div class="product-meta">

                                                <?php if (!empty($item['ten_mau'])): ?>

                                                    Màu:

                                                    <strong>

                                                        <?= htmlspecialchars(

                                                            $item['ten_mau']

                                                        ) ?>

                                                    </strong>

                                                <?php endif; ?>

                                                <?php if (

                                                    !empty($item['ram']) ||

                                                    !empty($item['rom'])

                                                ): ?>

                                                    ·

                                                    Phiên bản:

                                                    <strong>

                                                        <?= htmlspecialchars(

                                                            trim(

                                                                (string)($item['ram'] ?? '') .

                                                                (

                                                                    !empty($item['ram']) &&

                                                                    !empty($item['rom'])

                                                                        ? ' / '

                                                                        : ''

                                                                ) .

                                                                (string)($item['rom'] ?? '')

                                                            )

                                                        ) ?>

                                                    </strong>

                                                <?php endif; ?>

                                                · SL:

                                                <strong>

                                                    <?= (int)$item['so_luong'] ?>

                                                </strong>

                                            </div>

                                        </div>

                                        <div class="money">

                                            <?= number_format(

                                                (float)$item['gia'] *

                                                (int)$item['so_luong'],

                                                0,

                                                ',',

                                                '.'

                                            ) ?>đ

                                        </div>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>

                </div>

                <div class="detail-card">

                    <div class="p-3 border-bottom">

                        <div class="section-title">

                            👤 Khách hàng

                        </div>

                    </div>

                    <div class="p-3">

                        <div class="info-grid">

                            <div class="info-item">

                                <div class="info-label">

                                    Người nhận

                                </div>

                                <div class="info-value">

                                    <?= htmlspecialchars($tenNguoiNhan) ?>

                                </div>

                            </div>

                            <div class="info-item">

                                <div class="info-label">

                                    Email

                                </div>

                                <div class="info-value">

                                    <?= htmlspecialchars(

                                        $payment['email']

                                    ) ?>

                                </div>

                            </div>

                            <div class="info-item">

                                <div class="info-label">

                                    Số điện thoại

                                </div>

                                <div class="info-value">

                                    <?= $soDienThoaiNhan !== '' ? htmlspecialchars($soDienThoaiNhan) : 'Chưa có' ?>

                                </div>

                            </div>

                            <div class="info-item">

                                <div class="info-label">

                                    Địa chỉ

                                </div>

                                <div class="info-value">

                                    <?= $diaChiNhan !== '' ? htmlspecialchars($diaChiNhan) : 'Chưa có' ?>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

            <div class="col-lg-5">

                <div class="detail-card mb-4">

                    <div class="p-3 border-bottom">

                        <div class="section-title">

                            💰 Thanh toán

                        </div>

                    </div>

                    <div class="p-3">

                        <div class="info-grid">

                            <div class="info-item">

                                <div class="info-label">

                                    Tổng tiền

                                </div>

                                <div class="info-value money">

                                    <?= number_format(

                                        (float)$payment['tong_tien'],

                                        0,

                                        ',',

                                        '.'

                                    ) ?>đ

                                </div>

                            </div>

                            <div class="info-item">

                                <div class="info-label">

                                    Phương thức

                                </div>

                                <div class="info-value">

                                    <?= htmlspecialchars(

                                        $paymentMethod

                                    ) ?>

                                </div>

                            </div>

                            <div class="info-item">

                                <div class="info-label">

                                    Trạng thái thanh toán

                                </div>

                                <div class="info-value">

                                    <span

                                        class="badge <?= paymentStatusClass(

                                            $paymentStatus

                                        ) ?>"

                                    >

                                        <?= htmlspecialchars(

                                            $paymentStatus

                                        ) ?>

                                    </span>

                                </div>

                            </div>

                            <div class="info-item">

                                <div class="info-label">

                                    Thời gian thanh toán

                                </div>

                                <div class="info-value">

                                    <?php if (

                                        !empty(

                                            $payment['ngay_thanh_toan']

                                        )

                                    ): ?>

                                        <?= date(

                                            'd/m/Y H:i',

                                            strtotime(

                                                $payment['ngay_thanh_toan']

                                            )

                                        ) ?>

                                    <?php else: ?>

                                        <span class="text-muted">

                                            Chưa ghi nhận

                                        </span>

                                    <?php endif; ?>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <?php if ($canConfirmOnline): ?>

                    <div class="payment-action online mb-4">

                        <div class="payment-action-title">

                            💳 Cập nhật thanh toán

                        </div>

                        <!-- <div class="payment-action-note">

                            Chọn trạng thái thanh toán bằng

                            <strong><?= htmlspecialchars($paymentMethod) ?></strong>.

                            Thao tác này chỉ cập nhật thanh toán,

                            không tự thay đổi trạng thái đơn hàng.

                        </div> -->

                        <div class="reconcile-actions">

                            <form

                                method="POST"

                                action="xac_nhan.php"

                                onsubmit="return confirm(

                                    'Đã thanh toán?'

                                );"

                            >

                                <input type="hidden" name="payment_id" value="<?= (int)$payment['thanh_toan_id'] ?>">

                                <input type="hidden" name="action" value="confirm_online">

                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($paymentCsrfToken) ?>">

                                <input type="hidden" name="return_to" value="/iPhoneStore/admin/thanh_toan/chi_tiet.php?id=<?= (int)$payment['thanh_toan_id'] ?>">

                                <button type="submit" class="btn btn-success">

                                    ✅ Đánh dấu Đã thanh toán

                                </button>

                            </form>

                            <form

                                method="POST"

                                action="xac_nhan.php"

                                onsubmit="return confirm(

                                    'Chuyển trạng thái thanh toán sang Thất bại?'

                                );"

                            >

                                <input type="hidden" name="payment_id" value="<?= (int)$payment['thanh_toan_id'] ?>">

                                <input type="hidden" name="action" value="reconcile_online_no_money">

                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($paymentCsrfToken) ?>">

                                <input type="hidden" name="return_to" value="/iPhoneStore/admin/thanh_toan/chi_tiet.php?id=<?= (int)$payment['thanh_toan_id'] ?>">

                                <button type="submit" class="btn btn-outline-secondary">

                                    ✖ Đánh dấu Thất bại

                                </button>

                            </form>

                        </div>

                    </div>

                <?php elseif ($canConfirmCOD): ?>

                    <div class="payment-action cod mb-4">

                        <div class="payment-action-title">

                            💵 Cập nhật COD

                        </div>

                        <form

                            method="POST"

                            action="xac_nhan.php"

                            onsubmit="return confirm(

                                'Chuyển trạng thái COD sang Đã thanh toán?'

                            );"

                        >

                            <input type="hidden" name="payment_id" value="<?= (int)$payment['thanh_toan_id'] ?>">

                            <input type="hidden" name="action" value="confirm_cod_complete">

                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($paymentCsrfToken) ?>">

                            <input type="hidden" name="return_to" value="/iPhoneStore/admin/thanh_toan/chi_tiet.php?id=<?= (int)$payment['thanh_toan_id'] ?>">

                            <button type="submit" class="btn btn-success w-100">

                                ✅ Đánh dấu Đã thanh toán

                            </button>

                        </form>

                    </div>

                <?php endif; ?>

                <?php if (isCancelledOrder($orderStatus)): ?>

                    <div class="alert alert-danger mt-4">

                        <div class="fw-bold">

                            Đơn hàng đã hủy

                        </div>

                        <?php if (!empty($payment['ly_do_huy'])): ?>

                            <div class="mt-1">

                                Lý do:

                                <?= htmlspecialchars(

                                    $payment['ly_do_huy']

                                ) ?>

                            </div>

                        <?php endif; ?>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </div>

</div>

<?php require_once "../../includes/footer.php"; ?>
