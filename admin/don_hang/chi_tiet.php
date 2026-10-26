<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../middleware/check_admin.php";
require_once "../../config/database.php";

/* IphoneStore ADMIN ORDER DETAIL */

/* =========================================================
   MÃ SẢN PHẨM + MÃ VẬN ĐƠN

   Database đã có sẵn:
   - san_pham.ma_san_pham
   - don_hang.ma_van_don

   don_hang.id vẫn chỉ dùng nội bộ để truy vấn và chuyển trạng thái.
========================================================= */

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
   HÀM HIỂN THỊ TRẠNG THÁI ĐƠN
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
                'da_dat',
                'Đã đặt',
                'cho_xac_nhan',
                'Chờ xác nhận'
            ],
            true
        )
    ) {
        return 'bg-warning text-dark';
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
        return 'bg-primary';
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
        return 'bg-info text-dark';
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
        return 'bg-success';
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
        return 'bg-danger';
    }

    return 'bg-secondary';
}

function paymentStatusClass(?string $status): string
{
    $status = trim((string)$status);

    if (
        in_array(
            $status,
            [
                'Thành công',
                'Đã thanh toán'
            ],
            true
        )
    ) {
        return 'bg-success';
    }

    if (
        in_array(
            $status,
            [
                'Chưa thanh toán',
                'Chờ xác nhận',
                'Chờ thanh toán',
                'Chờ xử lý'
            ],
            true
        )
    ) {
        return 'bg-warning text-dark';
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
        return 'bg-danger';
    }

    return 'bg-secondary';
}

function isPendingStatus(string $status): bool
{
    return in_array(
        trim($status),
        [
            'cho_xu_ly',
            'da_dat',
            'Đã đặt',
            'cho_xac_nhan',
            'Chờ xác nhận'
        ],
        true
    );
}

function isApprovedStatus(string $status): bool
{
    return in_array(
        trim($status),
        [
            'da_duyet',
            'dang_xu_ly',
            'Đang xử lý'
        ],
        true
    );
}

function isShippingStatus(string $status): bool
{
    return in_array(
        trim($status),
        [
            'dang_giao',
            'Đang giao'
        ],
        true
    );
}

function isCompletedStatus(string $status): bool
{
    return in_array(
        trim($status),
        [
            'hoan_thanh',
            'Hoàn thành',
            'da_giao',
            'Đã giao'
        ],
        true
    );
}

function isCancelledStatus(string $status): bool
{
    return in_array(
        trim($status),
        [
            'huy',
            'da_huy',
            'Hủy',
            'Đã hủy'
        ],
        true
    );
}

/* =========================================================
   HÀM NGHIỆP VỤ THANH TOÁN
========================================================= */

/* =========================================================
   ID ĐƠN HÀNG
========================================================= */
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    die('Mã đơn hàng không hợp lệ');
}

/* =========================================================
   THÔNG TIN ĐƠN HÀNG + KHÁCH HÀNG
========================================================= */
$stmtOrder = $conn->prepare("
    SELECT
        dh.id,
        dh.ma_van_don,
        dh.nguoi_dung_id,
        dh.tong_tien,
        dh.trang_thai,
        dh.ngay_dat,
        dh.ly_do_huy,
        dh.ngay_huy,
        dh.nguoi_huy,

        dh.ten_nguoi_nhan,
        dh.so_dien_thoai_nhan,
        dh.dia_chi_nhan,

        nd.ho_ten,
        nd.email

    FROM don_hang dh

    JOIN nguoi_dung nd
        ON nd.id = dh.nguoi_dung_id

    WHERE dh.id = ?

    LIMIT 1
");

if (!$stmtOrder) {
    die(
        'Lỗi truy vấn đơn hàng: ' .
        htmlspecialchars($conn->error)
    );
}

$stmtOrder->bind_param(
    "i",
    $id
);

$stmtOrder->execute();

$don = $stmtOrder
    ->get_result()
    ->fetch_assoc();

if (!$don) {
    die('Đơn hàng không tồn tại');
}

/* =========================================================
   THÔNG TIN NHẬN HÀNG ĐÃ LƯU THEO ĐƠN

   Đây là snapshot lúc khách đặt hàng.
   Không lấy lại từ hồ sơ hiện tại của nguoi_dung vì khách có
   thể thay đổi tên / số điện thoại / địa chỉ sau khi đặt.
========================================================= */
$tenNguoiNhan =
    trim(
        (string)(
            $don['ten_nguoi_nhan']
            ?? ''
        )
    );

$soDienThoaiNhan =
    trim(
        (string)(
            $don['so_dien_thoai_nhan']
            ?? ''
        )
    );

$diaChiNhan =
    trim(
        (string)(
            $don['dia_chi_nhan']
            ?? ''
        )
    );

$coThongTinNhanHang =
    $tenNguoiNhan !== '' ||
    $soDienThoaiNhan !== '' ||
    $diaChiNhan !== '';

/* =========================================================
   THANH TOÁN MỚI NHẤT
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
    $id
);

$stmtPayment->execute();

$thanhToan = $stmtPayment
    ->get_result()
    ->fetch_assoc();

/* =========================================================
   CHI TIẾT SẢN PHẨM

   ct.gia là giá đã lưu lúc khách đặt hàng.

   Ảnh hiển thị:
   - ưu tiên ảnh chính của biến thể đã mua;
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
    $id
);

$stmtDetail->execute();

$chiTietList = $stmtDetail
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

/*
    Chuẩn hóa ảnh từng dòng đơn:
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
   TỔNG KẾT GIÁ

   ct.gia = giá đã lưu tại thời điểm khách đặt hàng.

   Hiện tại chi_tiet_don_hang chưa lưu riêng giá gốc lúc mua,
   nên giá gốc được lấy từ sản phẩm / biến thể hiện tại
   để làm giá tham chiếu.

   Nếu giá tham chiếu thấp hơn giá đã lưu thì dùng giá đã lưu,
   tránh xuất hiện số tiền giảm âm.
========================================================= */
$tongSoLuong = 0;
$tongGiaGoc = 0;
$tongGiamGia = 0;
$tongDaMua = 0;

foreach ($chiTietList as &$item) {
    $soLuong = (int)$item['so_luong'];
    $giaDaMua = (float)$item['gia'];

    if (
        !empty($item['bien_the_id']) &&
        $item['gia_goc_bien_the'] !== null
    ) {
        $giaGoc = (float)$item['gia_goc_bien_the'];
    } else {
        $giaGoc = (float)($item['gia_goc_san_pham'] ?? 0);
    }

    if ($giaGoc < $giaDaMua) {
        $giaGoc = $giaDaMua;
    }

    $giamMoiSanPham = max(0, $giaGoc - $giaDaMua);

    $item['gia_goc_hien_thi'] = $giaGoc;
    $item['giam_moi_san_pham'] = $giamMoiSanPham;

    $tongSoLuong += $soLuong;
    $tongGiaGoc += $giaGoc * $soLuong;
    $tongGiamGia += $giamMoiSanPham * $soLuong;
    $tongDaMua += $giaDaMua * $soLuong;
}

unset($item);

$orderStatus =
    trim(
        (string)$don['trang_thai']
    );

$paymentMethod =
    trim(
        (string)(
            $thanhToan['phuong_thuc']
            ?? ''
        )
    );

$paymentStatus =
    trim(
        (string)(
            $thanhToan['trang_thai']
            ?? ''
        )
    );

$maVanDon = trim(
    (string)($don['ma_van_don'] ?? '')
);

$lyDoHuyHienTai = trim(
    (string)($don['ly_do_huy'] ?? '')
);

$nguoiHuyHienTai = trim(
    (string)($don['nguoi_huy'] ?? '')
);

/* =========================================================
   CSRF + DANH SÁCH LÝ DO HỦY
   Danh sách này cũng được kiểm tra lại trong huy_don.php.
========================================================= */
if (empty($_SESSION['csrf_admin_cancel_order'])) {
    $_SESSION['csrf_admin_cancel_order'] =
        bin2hex(random_bytes(32));
}

$cancelCsrfToken =
    (string)$_SESSION['csrf_admin_cancel_order'];

$cancelReasons = [
    'Khách hàng yêu cầu hủy đơn',
    'Không thể liên hệ được với khách hàng',
    'Khách hàng muốn đổi sang sản phẩm khác',
    'Khách hàng cung cấp sai thông tin đặt hàng',
    'Sản phẩm đã hết hàng',
    'Phiên bản hoặc màu khách chọn đã hết hàng',
    'Sản phẩm phát hiện lỗi trước khi giao',
    'Đơn hàng bị đặt trùng',
    'Đơn hàng có dấu hiệu bất thường hoặc gian lận',
    'Không thể giao hàng đến khu vực của khách',
    'Có sự cố vận chuyển và không thể tiếp tục giao',
    'Lý do vận hành khác từ cửa hàng',
    'Hiện tại đang là ngày lễ hoặc nghỉ, không thể giao hàng',
    'Khách hàng yêu cầu hủy đơn vì lý do cá nhân',
];

/* =========================================================
   THANH TOÁN  

   Trang chi tiết đơn chỉ hiển thị thông tin thanh toán.
   Việc cập nhật trạng thái thanh toán thực hiện tại
   admin/thanh_toan/chi_tiet.php.
========================================================= */
$paymentExists = !empty($thanhToan['id']);
?>

<?php require_once "../../includes/header.php"; ?>
<?php require_once "../../includes/navbar.php"; ?>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
>

<style>
.order-detail-admin {
    background: #f6f7f9;
    min-height: 70vh;
}

.detail-card {
    background: #fff;
    border: 1px solid #ececec;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 8px 28px rgba(0, 0, 0, .05);
}

.card-section-title {
    font-size: 18px;
    font-weight: 800;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px 26px;
}

.info-item {
    padding: 13px 15px;
    border: 1px solid #eee;
    border-radius: 12px;
    background: #fafafa;
}

.info-label {
    margin-bottom: 5px;
    color: #777;
    font-size: 12px;
}

.info-value {
    color: #222;
    font-weight: 700;
    word-break: break-word;
}

.product-image {
    width: 74px;
    height: 74px;
    flex-shrink: 0;
    object-fit: contain;
    border: 1px solid #eee;
    border-radius: 11px;
    background: #fff;
}

.no-image {
    width: 74px;
    height: 74px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #eee;
    border-radius: 11px;
    background: #fafafa;
    color: #aaa;
    font-size: 11px;
}

.product-name {
    font-weight: 800;
}

.product-meta {
    margin-top: 3px;
    color: #666;
    font-size: 12px;
}

.product-code-cell {
    min-width: 120px;
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
    font-size: 15px;
    font-weight: 800;
    letter-spacing: 1px;
}

.tracking-empty {
    color: #777;
    font-size: 13px;
    font-weight: 600;
}

.code-note {
    margin-top: 5px;
    color: #888;
    font-size: 11px;
}

.order-price {
    color: #ee4d2d;
    font-weight: 800;
    white-space: nowrap;
}

.order-original-price {
    color: #999;
    font-size: 13px;
    text-decoration: line-through;
    white-space: nowrap;
}

.order-discount {
    margin-top: 3px;
    color: #198754;
    font-size: 12px;
    font-weight: 700;
}

.order-price-formula {
    margin-top: 5px;
    color: #777;
    font-size: 12px;
    white-space: nowrap;
}

.summary-discount {
    color: #198754;
    font-weight: 800;
}

.summary-box {
    max-width: 430px;
    margin-left: auto;
    padding: 18px;
    border: 1px solid #f0e3de;
    border-radius: 14px;
    background: linear-gradient(
        180deg,
        #fffaf7 0%,
        #ffffff 100%
    );
}

.summary-row {
    display: flex;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 10px;
}

.summary-row:last-child {
    margin-bottom: 0;
}

.summary-total {
    padding-top: 14px;
    border-top: 1px dashed #e3d7d1;
    font-size: 19px;
    font-weight: 800;
}

.summary-total-value {
    color: #ee4d2d;
    font-size: 24px;
    font-weight: 800;
}

.action-panel {
    padding: 20px;
}

.action-title {
    margin-bottom: 10px;
    font-weight: 800;
}

.action-help {
    margin-bottom: 16px;
    color: #777;
    font-size: 13px;
}

.action-btn {
    min-width: 145px;
}

.status-action-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    flex-wrap: wrap;
    margin-top: 12px;
    margin-bottom: 18px;
}

.status-flow {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 0;
    flex: 1 1 auto;
}

.primary-order-action {
    flex: 0 0 auto;
}

.primary-order-action .action-btn {
    min-width: 150px;
}

.flow-item {
    padding: 7px 10px;
    border-radius: 999px;
    background: #f1f3f5;
    color: #777;
    font-size: 12px;
    font-weight: 700;
}

.flow-item.active {
    background: #e8f2ff;
    color: #0d6efd;
}

.flow-item.done {
    background: #edf8f0;
    color: #198754;
}

/* =========================================================
   THÔNG TIN SẢN PHẨM TRONG KHỐI THÔNG TIN ĐƠN
========================================================= */
.info-item-full {
    grid-column: 1 / -1;
}

.order-info-product-list {
    display: grid;
    gap: 9px;
}

.order-info-product-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 16px;
    align-items: center;
    padding: 9px 0;
    border-bottom: 1px dashed #e6e6e6;
}

.order-info-product-row:last-child {
    border-bottom: 0;
    padding-bottom: 0;
}

.order-info-product-row:first-child {
    padding-top: 0;
}

.order-info-product-name {
    color: #222;
    font-size: 13px;
    font-weight: 800;
}

.order-info-product-meta {
    margin-top: 3px;
    color: #777;
    font-size: 11px;
    font-weight: 500;
}

.order-info-product-price {
    color: #d70018;
    font-size: 13px;
    font-weight: 850;
    white-space: nowrap;
}

/* =========================================================
   THÔNG TIN NHẬN HÀNG THEO ĐƠN
========================================================= */
.receiver-admin-card {
    border: 1px solid #dce6f5;
    background: linear-gradient(
        180deg,
        #f9fbff 0%,
        #ffffff 100%
    );
}

.receiver-admin-body {
    padding: 20px;
}

.receiver-admin-grid {
    display: grid;
    grid-template-columns:
        repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.receiver-admin-item {
    padding: 14px 15px;
    border: 1px solid #e6ebf2;
    border-radius: 12px;
    background: #fff;
}

.receiver-admin-item.address {
    grid-column: 1 / -1;
}

.receiver-admin-label {
    margin-bottom: 5px;
    color: #737b87;
    font-size: 13px;
    font-weight: 700;
}

.receiver-admin-value {
    color: #222;
    font-size: 15px;
    font-weight: 800;
    line-height: 1.6;
    word-break: break-word;
}

.receiver-admin-empty {
    color: #999;
    font-weight: 600;
}

.receiver-admin-note {
    margin-top: 14px;
    padding: 11px 13px;
    border: 1px solid #f0ddb5;
    border-radius: 10px;
    background: #fffaf0;
    color: #80652d;
    font-size: 12px;
    line-height: 1.55;
}

.receiver-admin-account-note {
    margin-top: 14px;
    padding: 11px 13px;
    border-radius: 10px;
    background: #f6f7f9;
    color: #737983;
    font-size: 12px;
    line-height: 1.55;
}

@media (max-width: 767px) {
    .receiver-admin-grid {
        grid-template-columns: 1fr;
    }

    .receiver-admin-item.address {
        grid-column: auto;
    }
}

/* =========================================================
   HỦY ĐƠN + LÝ DO
========================================================= */
.cancel-order-box {
    margin-top: 18px;
    padding: 17px;
    border: 1px solid #ffd7dc;
    border-radius: 14px;
    background: #fff8f9;
}

.cancel-order-title {
    color: #b42332;
    font-size: 14px;
    font-weight: 850;
}

.cancel-order-note {
    margin-top: 4px;
    color: #7c6669;
    font-size: 12px;
    line-height: 1.55;
}

.cancel-order-form {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 10px;
    align-items: end;
    margin-top: 13px;
}

.cancel-reason-select {
    min-height: 45px;
    border-color: #efc2c8;
    border-radius: 10px;
    box-shadow: none !important;
}

.cancel-reason-select:focus {
    border-color: #d70018;
}

.cancel-submit-btn {
    min-height: 45px;
    min-width: 145px;
    border-radius: 10px;
    font-weight: 800;
}

.cancel-business-note {
    margin-top: 12px;
    padding: 11px 13px;
    border-radius: 10px;
    font-size: 13px;
    line-height: 1.6;
}

.cancel-business-note.safe {
    border: 1px solid #d7e7dc;
    background: #f3faf5;
    color: #356447;
}

.cancel-business-note.blocked {
    border: 1px solid #ffc8cf;
    background: #fff4f5;
    color: #9f2d3b;
}

.cancelled-reason-box {
    grid-column: 1 / -1;
    padding: 14px 15px;
    border: 1px solid #f2c5ca;
    border-radius: 12px;
    background: #fff5f6;
}

.cancelled-reason-label {
    margin-bottom: 5px;
    color: #9a3c47;
    font-size: 12px;
    font-weight: 750;
}

.cancelled-by-badge {
    display: inline-flex;
    align-items: center;
    margin-bottom: 7px;
    padding: 5px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
}

.cancelled-by-badge.user {
    background: #fff1f3;
    color: #c92a3b;
}

.cancelled-by-badge.admin {
    background: #fff4e8;
    color: #b45309;
}

.cancelled-by-badge.unknown {
    background: #f1f3f5;
    color: #777;
}

.cancelled-reason-value {
    color: #b42332;
    font-weight: 800;
}

.cancelled-reason-time {
    margin-top: 5px;
    color: #8a7477;
    font-size: 11px;
}

@media (max-width: 767px) {
    .info-grid {
        grid-template-columns: 1fr;
    }

    .summary-box {
        max-width: none;
    }

    .action-btn {
        width: 100%;
    }

    .status-action-row {
        align-items: stretch;
        flex-direction: column;
    }

    .primary-order-action {
        width: 100%;
    }

    .primary-order-action .action-btn {
        width: 100%;
    }

    .cancel-order-form {
        grid-template-columns: 1fr;
    }

    .cancel-submit-btn {
        width: 100%;
    }

    .order-info-product-row {
        grid-template-columns: 1fr;
        gap: 5px;
    }

    .order-info-product-price {
        text-align: left;
    }
}

/* =========================================================
   TĂNG CỠ CHỮ TRANG CHI TIẾT ĐƠN HÀNG ADMIN
   Thanh toán chỉ hiển thị  ; trạng thái đơn xử lý độc lập.
========================================================= */
.order-detail-admin {
    font-size: 15px;
}

.order-detail-admin h3 {
    font-size: 30px;
}

.card-section-title {
    font-size: 20px;
}

.table {
    font-size: 15px;
}

.table thead th {
    font-size: 15px;
    font-weight: 800;
}

.product-name {
    font-size: 16px;
}

.product-meta {
    font-size: 14px;
}

.product-code-badge {
    font-size: 13px;
}

.product-code-empty {
    font-size: 13px;
}

.order-price {
    font-size: 16px;
}

.order-original-price {
    font-size: 14px;
}

.order-discount {
    font-size: 13px;
}

.order-price-formula {
    font-size: 13px;
}

.summary-row {
    font-size: 15px;
}

.summary-total {
    font-size: 20px;
}

.summary-total-value {
    font-size: 26px;
}

.info-label {
    font-size: 14px;
    font-weight: 700;
}

.info-value {
    font-size: 16px;
}

.tracking-code {
    font-size: 16px;
}

.tracking-empty {
    font-size: 14px;
}

.code-note {
    font-size: 13px;
}

.order-info-product-name {
    font-size: 15px;
}

.order-info-product-meta {
    font-size: 13px;
}

.order-info-product-price {
    font-size: 15px;
}

.badge {
    font-size: 13px;
    padding: 7px 10px;
}

.action-title {
    font-size: 19px;
}

.action-help {
    font-size: 14px;
}

.flow-item {
    font-size: 13px;
    padding: 8px 12px;
}

.action-btn {
    font-size: 15px;
    font-weight: 700;
}

.cancel-order-title {
    font-size: 16px;
}

.cancel-order-note {
    font-size: 13px;
}

.cancel-reason-select {
    font-size: 15px;
}

.cancel-submit-btn {
    font-size: 15px;
}

.cancelled-reason-label {
    font-size: 13px;
}

.cancelled-by-badge {
    font-size: 12px;
}

.cancelled-reason-value {
    font-size: 15px;
}

.cancelled-reason-time {
    font-size: 13px;
}

/* Các khối/nút thanh toán được tăng chữ cùng tông giao diện */
@media (max-width: 767px) {
    .order-detail-admin {
        font-size: 14px;
    }

    .order-detail-admin h3 {
        font-size: 25px;
    }

    .card-section-title {
        font-size: 18px;
    }

    .info-value {
        font-size: 15px;
    }

    .summary-total-value {
        font-size: 23px;
    }
}

</style>

<!-- IphoneStore ADMIN ORDER DETAIL MASP COLUMN V2 -->
<div class="order-detail-admin py-4">
    <div class="container">

        <!-- =================================================
             HEADER
        ================================================== -->
        <div
            class="d-flex flex-wrap gap-3 align-items-center justify-content-between mb-4"
        >
            <div>
                <h3 class="fw-bold mb-1">
                    🧾 Chi tiết đơn hàng
                </h3>

                <div class="text-muted small">
                    Xem sản phẩm, thông tin khách hàng,
                    thanh toán và xử lý trạng thái đơn.
                </div>
            </div>

            <a
                href="index.php"
                class="btn btn-outline-secondary"
            >
                ⬅ Quay lại
            </a>
        </div>

        <!-- =================================================
             SẢN PHẨM TRONG ĐƠN
        ================================================== -->
        <div class="detail-card mb-4">

            <div class="p-3 border-bottom">
                <div class="card-section-title">
                    📦 Sản phẩm trong đơn
                </div>
            </div>

            <?php if (empty($chiTietList)): ?>

                <div class="text-center text-muted py-5">
                    Đơn hàng chưa có sản phẩm.
                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table align-middle mb-0">

                        <thead class="table-light">
                            <tr>
                                <th>
                                    Sản phẩm
                                </th>

                                <th>
                                    Mã sản phẩm
                                </th>

                                <th>
                                    Giá sản phẩm
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
                                $giaDaMua = (float)$item['gia'];
                                $giaGoc = (float)$item['gia_goc_hien_thi'];
                                $giamMoiSanPham = (float)$item['giam_moi_san_pham'];
                                $soLuong = (int)$item['so_luong'];

                                $tongGiaGocItem = $giaGoc * $soLuong;
                                $tongGiamItem = $giamMoiSanPham * $soLuong;
                                $thanhTien = $giaDaMua * $soLuong;

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

                                        <div class="d-flex gap-3 align-items-center">

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
                                                        ? 'Ảnh đúng theo biến thể khách đã mua'
                                                        : 'Ảnh đại diện sản phẩm' ?>"
                                                >

                                            <?php else: ?>

                                                <div class="no-image">
                                                    No image
                                                </div>

                                            <?php endif; ?>

                                            <div>

                                                <div class="product-name">
                                                    <?= htmlspecialchars($tenSanPham) ?>
                                                </div>

                                                <?php if (!empty($item['ten_mau'])): ?>

                                                    <div class="product-meta">
                                                        Màu:
                                                        <strong>
                                                            <?= htmlspecialchars($item['ten_mau']) ?>
                                                        </strong>
                                                    </div>

                                                <?php endif; ?>

                                                <?php if (
                                                    !empty($item['bien_the_id'])
                                                ): ?>

                                                    <div class="product-meta">
                                                        Phiên bản:

                                                        <?php if (
                                                            $item['ram'] !== null ||
                                                            $item['rom'] !== null
                                                        ): ?>

                                                            <strong>
                                                                <?= htmlspecialchars(
                                                                    (string)($item['ram'] ?? '')
                                                                ) ?>

                                                                /

                                                                <?= htmlspecialchars(
                                                                    (string)($item['rom'] ?? '')
                                                                ) ?>
                                                            </strong>

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

                                    <!-- MÃ SẢN PHẨM -->
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
                                                Chưa có mã SP
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td>

                                        <?php if ($giamMoiSanPham > 0): ?>

                                            <div class="order-original-price">
                                                <?= number_format(
                                                    $giaGoc,
                                                    0,
                                                    ',',
                                                    '.'
                                                ) ?>đ
                                            </div>

                                        <?php endif; ?>

                                        <div class="order-price">
                                            <?= number_format(
                                                $giaDaMua,
                                                0,
                                                ',',
                                                '.'
                                            ) ?>đ
                                        </div>

                                        <?php if ($giamMoiSanPham > 0): ?>

                                            <div class="order-discount">
                                                Giảm
                                                <?= number_format(
                                                    $giamMoiSanPham,
                                                    0,
                                                    ',',
                                                    '.'
                                                ) ?>đ / sản phẩm
                                            </div>

                                        <?php endif; ?>

                                    </td>

                                    <td>
                                        <?= $soLuong ?>
                                    </td>

                                    <td>

                                        <?php if ($tongGiamItem > 0): ?>

                                            <div class="order-original-price">
                                                <?= number_format(
                                                    $tongGiaGocItem,
                                                    0,
                                                    ',',
                                                    '.'
                                                ) ?>đ
                                            </div>

                                        <?php endif; ?>

                                        <div class="order-price">
                                            <?= number_format(
                                                $thanhTien,
                                                0,
                                                ',',
                                                '.'
                                            ) ?>đ
                                        </div>

                                        <?php if ($tongGiamItem > 0): ?>

                                            <div class="order-price-formula">
                                                <?= number_format(
                                                    $tongGiaGocItem,
                                                    0,
                                                    ',',
                                                    '.'
                                                ) ?>đ
                                                -
                                                <?= number_format(
                                                    $tongGiamItem,
                                                    0,
                                                    ',',
                                                    '.'
                                                ) ?>đ
                                            </div>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>
                    </table>

                </div>

            <?php endif; ?>

            <div class="p-3 border-top">

                <div class="summary-box">

                    <div class="summary-row">
                        <span class="text-muted">
                            Tổng số lượng
                        </span>

                        <strong>
                            <?= $tongSoLuong ?> sản phẩm
                        </strong>
                    </div>

                    <div class="summary-row">
                        <span class="text-muted">
                            Tổng giá gốc
                        </span>

                        <strong>
                            <?= number_format(
                                $tongGiaGoc,
                                0,
                                ',',
                                '.'
                            ) ?>đ
                        </strong>
                    </div>

                    <div class="summary-row">
                        <span class="text-muted">
                            Tổng giảm giá
                        </span>

                        <span class="summary-discount">
                            <?php if ($tongGiamGia > 0): ?>
                                -<?= number_format(
                                    $tongGiamGia,
                                    0,
                                    ',',
                                    '.'
                                ) ?>đ
                            <?php else: ?>
                                0đ
                            <?php endif; ?>
                        </span>
                    </div>

                    <div class="summary-row">
                        <span class="text-muted">
                            Giá sau giảm
                        </span>

                        <strong>
                            <?= number_format(
                                $tongDaMua,
                                0,
                                ',',
                                '.'
                            ) ?>đ
                        </strong>
                    </div>

                    <div class="summary-row summary-total">
                        <span>
                            Tổng đơn hàng
                        </span>

                        <span class="summary-total-value">
                            <?= number_format(
                                (float)$don['tong_tien'],
                                0,
                                ',',
                                '.'
                            ) ?>đ
                        </span>
                    </div>

                    <?php if ($tongGiamGia > 0): ?>

                        <div class="order-price-formula text-end">
                            <?= number_format(
                                $tongGiaGoc,
                                0,
                                ',',
                                '.'
                            ) ?>đ
                            -
                            <?= number_format(
                                $tongGiamGia,
                                0,
                                ',',
                                '.'
                            ) ?>đ
                            =
                            <?= number_format(
                                $tongDaMua,
                                0,
                                ',',
                                '.'
                            ) ?>đ
                        </div>

                    <?php endif; ?>

                </div>

            </div>
        </div>

        <!-- =================================================
             THÔNG TIN NHẬN HÀNG
        ================================================== -->
        <div class="detail-card receiver-admin-card mb-4">

            <div class="p-3 border-bottom">
                <div class="card-section-title">
                    📍 Thông tin nhận hàng
                </div>
            </div>

            <div class="receiver-admin-body">

                <div class="receiver-admin-grid">

                    <div class="receiver-admin-item">
                        <div class="receiver-admin-label">
                            Người nhận
                        </div>

                        <div class="receiver-admin-value">
                            <?php if ($tenNguoiNhan !== ''): ?>

                                <?= htmlspecialchars(
                                    $tenNguoiNhan,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>

                            <?php else: ?>

                                <span class="receiver-admin-empty">
                                    Chưa lưu thông tin
                                </span>

                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="receiver-admin-item">
                        <div class="receiver-admin-label">
                            Số điện thoại nhận hàng
                        </div>

                        <div class="receiver-admin-value">
                            <?php if ($soDienThoaiNhan !== ''): ?>

                                <?= htmlspecialchars(
                                    $soDienThoaiNhan,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>

                            <?php else: ?>

                                <span class="receiver-admin-empty">
                                    Chưa lưu thông tin
                                </span>

                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="receiver-admin-item address">
                        <div class="receiver-admin-label">
                            Địa chỉ giao hàng
                        </div>

                        <div class="receiver-admin-value">
                            <?php if ($diaChiNhan !== ''): ?>

                                <?= nl2br(
                                    htmlspecialchars(
                                        $diaChiNhan,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    )
                                ) ?>

                            <?php else: ?>

                                <span class="receiver-admin-empty">
                                    Chưa lưu thông tin
                                </span>

                            <?php endif; ?>
                        </div>
                    </div>

                </div>

                <?php if (!$coThongTinNhanHang): ?>

                    <div class="receiver-admin-note">
                        Đây là đơn hàng cũ chưa có thông tin nhận hàng
                        được lưu riêng theo đơn. Không tự lấy địa chỉ
                        hiện tại trong hồ sơ để tránh làm sai lịch sử
                        giao nhận của đơn.
                    </div>

                <?php endif; ?>

                <div class="receiver-admin-account-note">
                    Email tài khoản:
                    <strong>
                        <?= !empty($don['email'])
                            ? htmlspecialchars(
                                $don['email'],
                                ENT_QUOTES,
                                'UTF-8'
                            )
                            : 'Chưa có email' ?>
                    </strong>
                </div>

            </div>
        </div>

        <!-- =================================================
             THÔNG TIN ĐƠN + KHÁCH HÀNG
        ================================================== -->
        <div class="detail-card mb-4">

            <div class="p-3 border-bottom">
                <div class="card-section-title">
                    👤 Thông tin đơn hàng
                </div>
            </div>

            <div class="p-3">

                <div class="info-grid">

                    <div class="info-item">
                        <div class="info-label">
                            Mã vận đơn
                        </div>

                        <div class="info-value">

                            <?php if ($maVanDon !== ''): ?>

                                <span class="tracking-code">
                                    <?= htmlspecialchars($maVanDon) ?>
                                </span>

                            <?php else: ?>

                                <span class="tracking-empty">
                                    Chưa có mã vận đơn
                                </span>

                                <div class="code-note">
                                    Mã vận đơn chỉ được tạo khi admin chuyển đơn sang
                                    trạng thái Đang giao và sau đó được giữ nguyên.
                                </div>

                            <?php endif; ?>

                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">
                            Ngày đặt
                        </div>

                        <div class="info-value">
                            <?= !empty($don['ngay_dat'])
                                ? date(
                                    'd/m/Y H:i',
                                    strtotime(
                                        $don['ngay_dat']
                                    )
                                )
                                : '-' ?>
                        </div>
                    </div>

                    <!-- TÊN SẢN PHẨM + GIÁ MUA -->
                    <div class="info-item info-item-full">
                        <div class="info-label">
                            Sản phẩm trong đơn
                        </div>

                        <div class="info-value">

                            <?php if (empty($chiTietList)): ?>

                                <span class="text-muted">
                                    Chưa có sản phẩm.
                                </span>

                            <?php else: ?>

                                <div class="order-info-product-list">

                                    <?php foreach ($chiTietList as $infoProduct): ?>

                                        <?php
                                        $infoProductName =
                                            trim(
                                                (string)(
                                                    $infoProduct['ten_san_pham']
                                                    ?? ''
                                                )
                                            );

                                        if ($infoProductName === '') {
                                            $infoProductName =
                                                'Sản phẩm #' .
                                                (int)$infoProduct['san_pham_id'];
                                        }

                                        $infoVariantParts = [];

                                        if (!empty($infoProduct['ten_mau'])) {
                                            $infoVariantParts[] =
                                                'Màu ' .
                                                $infoProduct['ten_mau'];
                                        }

                                        if (
                                            !empty($infoProduct['ram']) ||
                                            !empty($infoProduct['rom'])
                                        ) {
                                            $infoVariantParts[] =
                                                trim(
                                                    (string)(
                                                        $infoProduct['ram']
                                                        ?? ''
                                                    )
                                                ) .
                                                (
                                                    !empty($infoProduct['ram']) &&
                                                    !empty($infoProduct['rom'])
                                                        ? ' / '
                                                        : ''
                                                ) .
                                                trim(
                                                    (string)(
                                                        $infoProduct['rom']
                                                        ?? ''
                                                    )
                                                );
                                        }

                                        $infoVariantText =
                                            implode(
                                                ' · ',
                                                array_filter(
                                                    $infoVariantParts
                                                )
                                            );
                                        ?>

                                        <div class="order-info-product-row">

                                            <div>
                                                <div class="order-info-product-name">
                                                    <?= htmlspecialchars(
                                                        $infoProductName
                                                    ) ?>
                                                </div>

                                                <div class="order-info-product-meta">
                                                    Số lượng:
                                                    <strong>
                                                        <?= (int)$infoProduct['so_luong'] ?>
                                                    </strong>

                                                    <?php if ($infoVariantText !== ''): ?>
                                                        ·
                                                        <?= htmlspecialchars(
                                                            $infoVariantText
                                                        ) ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <div class="order-info-product-price">
                                                <?= number_format(
                                                    (float)$infoProduct['gia'],
                                                    0,
                                                    ',',
                                                    '.'
                                                ) ?>đ / sản phẩm
                                            </div>

                                        </div>

                                    <?php endforeach; ?>

                                </div>

                            <?php endif; ?>

                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">
                            Tài khoản đặt hàng
                        </div>

                        <div class="info-value">
                            <?= htmlspecialchars(
                                $don['ho_ten'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">
                            Email
                        </div>

                        <div class="info-value">
                            <?= htmlspecialchars($don['email']) ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">
                            Trạng thái đơn hàng
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

                    <?php if (isCancelledStatus($orderStatus)): ?>

                        <div class="cancelled-reason-box">

                            <?php if ($nguoiHuyHienTai === 'user'): ?>

                                <div class="cancelled-by-badge user">
                                    👤 Người mua đã hủy đơn
                                </div>

                                <div class="cancelled-reason-value">
                                    Người mua hủy vì lý do:
                                    <?= $lyDoHuyHienTai !== ''
                                        ? htmlspecialchars($lyDoHuyHienTai)
                                        : 'Chưa ghi nhận lý do hủy' ?>
                                </div>

                            <?php elseif ($nguoiHuyHienTai === 'admin'): ?>

                                <div class="cancelled-by-badge admin">
                                    🏪 Người bán đã hủy đơn
                                </div>

                                <div class="cancelled-reason-value">
                                    Người bán hủy vì lý do:
                                    <?= $lyDoHuyHienTai !== ''
                                        ? htmlspecialchars($lyDoHuyHienTai)
                                        : 'Chưa ghi nhận lý do hủy' ?>
                                </div>

                            <?php else: ?>

                                <div class="cancelled-by-badge unknown">
                                    ❌ Đơn hàng đã bị hủy
                                </div>

                                <div class="cancelled-reason-value">
                                    Lý do:
                                    <?= $lyDoHuyHienTai !== ''
                                        ? htmlspecialchars($lyDoHuyHienTai)
                                        : 'Chưa ghi nhận lý do hủy' ?>
                                </div>

                            <?php endif; ?>

                            <?php if (!empty($don['ngay_huy'])): ?>
                                <div class="cancelled-reason-time">
                                    Thời gian hủy:
                                    <?= date(
                                        'd/m/Y H:i',
                                        strtotime(
                                            $don['ngay_huy']
                                        )
                                    ) ?>
                                </div>
                            <?php endif; ?>

                        </div>

                    <?php endif; ?>

                    <div class="info-item">
                        <div class="info-label">
                            Phương thức thanh toán
                        </div>

                        <div class="info-value">
                            <?= $paymentMethod !== ''
                                ? htmlspecialchars($paymentMethod)
                                : 'Chưa có thông tin' ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">
                            Trạng thái thanh toán
                        </div>

                        <div class="info-value">

                            <?php if ($paymentStatus !== ''): ?>

                                <span
                                    class="badge <?= paymentStatusClass(
                                        $paymentStatus
                                    ) ?>"
                                >
                                    <?= htmlspecialchars(
                                        $paymentStatus
                                    ) ?>
                                </span>

                                <!-- <div class="code-note">
                                    Trạng thái  
                                </div> -->

                            <?php else: ?>

                                <span class="badge bg-secondary">
                                    Chưa có thông tin
                                </span>

                            <?php endif; ?>

                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">
                            Thời gian thanh toán
                        </div>

                        <div class="info-value">

                            <?php if (
                                !empty($thanhToan['ngay_thanh_toan'])
                            ): ?>

                                <?= date(
                                    'd/m/Y H:i',
                                    strtotime(
                                        $thanhToan['ngay_thanh_toan']
                                    )
                                ) ?>

                            <?php else: ?>

                                <span class="text-muted">
                                    Chưa ghi nhận thời gian
                                </span>

                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="info-item info-item-full">
                        <div class="info-label">
                            Cập nhật thanh toán
                        </div>

                        <div class="info-value">
                            <span class="text-muted">
                                Thanh toán là dữ liệu và được cập nhật riêng tại
                                <a href="../thanh_toan/chi_tiet.php?id=<?= (int)($thanhToan['id'] ?? 0) ?>">
                                    chi tiết thanh toán
                                </a>.
                            </span>
                        </div>
                    </div>

                </div>

            </div>
        </div>

        <!-- =================================================
             XỬ LÝ TRẠNG THÁI
        ================================================== -->
        <div class="detail-card">

            <div class="action-panel">

                <div class="action-title">
                    ⚙️ Xử lý đơn hàng
                </div>

                <div class="action-help">
                    Chỉ chuyển đơn theo đúng tiến trình.
                    Đơn đã hoàn thành hoặc đã hủy sẽ không hiện nút xử lý tiếp.
                </div>

                <!-- =================================================
                     TIẾN TRÌNH + NÚT XỬ LÝ CHÍNH CÙNG HÀNG
                ================================================== -->
                <div class="status-action-row">

                    <div class="status-flow">

                        <span
                            class="flow-item <?= isPendingStatus($orderStatus)
                                ? 'active'
                                : (
                                    !isCancelledStatus($orderStatus)
                                        ? 'done'
                                        : ''
                                ) ?>"
                        >
                            1. Chờ xử lý
                        </span>

                        <span
                            class="flow-item <?= isApprovedStatus($orderStatus)
                                ? 'active'
                                : (
                                    isShippingStatus($orderStatus) ||
                                    isCompletedStatus($orderStatus)
                                        ? 'done'
                                        : ''
                                ) ?>"
                        >
                            2. Đã duyệt
                        </span>

                        <span
                            class="flow-item <?= isShippingStatus($orderStatus)
                                ? 'active'
                                : (
                                    isCompletedStatus($orderStatus)
                                        ? 'done'
                                        : ''
                                ) ?>"
                        >
                            3. Đang giao
                        </span>

                        <span
                            class="flow-item <?= isCompletedStatus($orderStatus)
                                ? 'active done'
                                : '' ?>"
                        >
                            4. Hoàn thành
                        </span>

                    </div>

                    <div class="primary-order-action">

                        <?php if (isPendingStatus($orderStatus)): ?>

                            <a
                                href="cap_nhat_trang_thai.php?id=<?= $id ?>&tt=da_duyet"
                                class="btn btn-primary action-btn"
                                onclick="return confirm(
                                    'Xác nhận duyệt đơn hàng này?'
                                )"
                            >
                                ✔ Duyệt đơn
                            </a>

                        <?php elseif (isApprovedStatus($orderStatus)): ?>

                            <a
                                href="cap_nhat_trang_thai.php?id=<?= $id ?>&tt=dang_giao"
                                class="btn btn-info action-btn"
                                onclick="return confirm(
                                    'Chuyển đơn sang trạng thái đang giao?\n\n' +
                                    'Hệ thống sẽ tạo mã vận đơn dạng 8686xxxx nếu đơn chưa có mã.'
                                )"
                            >
                                🚚 Đang giao
                            </a>

                        <?php elseif (isShippingStatus($orderStatus)): ?>

                            <a
                                href="cap_nhat_trang_thai.php?id=<?= $id ?>&tt=hoan_thanh"
                                class="btn btn-success action-btn"
                                onclick="return confirm(
                                    'Xác nhận đơn hàng đã hoàn thành?'
                                )"
                            >
                                ✅ Hoàn thành
                            </a>

                        <?php elseif (isCompletedStatus($orderStatus)): ?>

                            <span class="alert alert-success mb-0 py-2 px-3">
                                ✅ Đơn hàng đã hoàn thành.
                            </span>

                        <?php elseif (isCancelledStatus($orderStatus)): ?>

                            <span class="alert alert-danger mb-0 py-2 px-3">
                                <?php if ($nguoiHuyHienTai === 'user'): ?>
                                    👤 Đơn hàng đã được người mua hủy.
                                <?php elseif ($nguoiHuyHienTai === 'admin'): ?>
                                    🏪 Đơn hàng đã được người bán hủy.
                                <?php else: ?>
                                    ❌ Đơn hàng đã bị hủy.
                                <?php endif; ?>
                            </span>

                        <?php endif; ?>

                    </div>

                </div>

                <?php if (isPendingStatus($orderStatus)): ?>

                    <div class="cancel-order-box">

                        <div class="cancel-order-title">
                            ❌ Hủy đơn hàng
                        </div>

                        <div class="cancel-order-note">
                            Chỉ được hủy khi đơn đang ở trạng thái Chờ xử lý.
                        </div>

                        <div class="cancel-business-note safe">
                            <strong>ℹ️ Có thể hủy đơn.</strong><br>
                            Chỉ đơn Chờ xử lý mới được hủy. Trạng thái thanh toán là dữ liệu và không chặn thao tác hủy.
                        </div>

                            <form
                                method="POST"
                                action="huy_don.php"
                                class="cancel-order-form"
                                onsubmit="return confirm(
                                    'Bạn chắc chắn muốn hủy đơn hàng này với lý do đã chọn?'
                                );"
                            >

                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?= (int)$id ?>"
                                >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= htmlspecialchars(
                                        $cancelCsrfToken
                                    ) ?>"
                                >

                                <div>
                                    <label
                                        for="lyDoHuy"
                                        class="form-label small fw-bold mb-1"
                                    >
                                        Lý do hủy đơn
                                    </label>

                                    <select
                                        name="ly_do_huy"
                                        id="lyDoHuy"
                                        class="form-select cancel-reason-select"
                                        required
                                    >
                                        <option value="">
                                            -- Chọn lý do hủy đơn --
                                        </option>

                                        <?php foreach ($cancelReasons as $reason): ?>
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
                                    class="btn btn-outline-danger cancel-submit-btn"
                                >
                                    ❌ Hủy đơn
                                </button>

                            </form>

                    </div>

                <?php endif; ?>

            </div>
        </div>

    </div>
</div>

<?php require_once "../../includes/footer.php"; ?>
