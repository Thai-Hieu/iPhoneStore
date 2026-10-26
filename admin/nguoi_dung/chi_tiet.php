<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../middleware/check_admin.php";
require_once "../../config/database.php";

$targetId = (int)($_GET['id'] ?? 0);
$currentAdminId = (int)($_SESSION['user']['id'] ?? 0);

if ($targetId <= 0) {
    die("Người dùng không hợp lệ.");
}

/* =========================================================
   CSRF TOKEN CHO 2 LOẠI THAO TÁC
========================================================= */
if (empty($_SESSION['csrf_user_status_update'])) {
    $_SESSION['csrf_user_status_update'] =
        bin2hex(random_bytes(32));
}

if (empty($_SESSION['csrf_user_role_update'])) {
    $_SESSION['csrf_user_role_update'] =
        bin2hex(random_bytes(32));
}

$statusCsrf =
    (string)$_SESSION['csrf_user_status_update'];

$roleCsrf =
    (string)$_SESSION['csrf_user_role_update'];

/* =========================================================
   THÔNG TIN NGƯỜI DÙNG
========================================================= */
$stmtUser = $conn->prepare("
    SELECT
        nd.id,
        nd.ho_ten,
        nd.email,
        nd.so_dien_thoai,
        nd.dia_chi,
        nd.trang_thai,
        nd.ngay_tao,
        vt.vai_tro
    FROM nguoi_dung nd
    JOIN vai_tro vt
        ON vt.id = nd.vai_tro_id
    WHERE nd.id = ?
    LIMIT 1
");

if (!$stmtUser) {
    die("Không thể tải thông tin người dùng.");
}

$stmtUser->bind_param("i", $targetId);
$stmtUser->execute();

$targetUser = $stmtUser
    ->get_result()
    ->fetch_assoc();

/*
    Dùng biến $targetUser thay vì $user.
    Lý do: includes/navbar.php có thể dùng lại biến $user
    cho tài khoản đang đăng nhập và làm ghi đè dữ liệu
    người dùng đang được admin xem.
*/
if (!$targetUser) {
    die("Không tìm thấy người dùng.");
}

/* =========================================================
   THỐNG KÊ ĐƠN HÀNG CỦA NGƯỜI DÙNG
========================================================= */
$stmtStats = $conn->prepare("
    SELECT
        COUNT(*) AS tong_don,

        SUM(
            CASE
                WHEN trang_thai IN (
                    'hoan_thanh',
                    'Hoàn thành',
                    'da_giao',
                    'Đã giao'
                )
                THEN 1
                ELSE 0
            END
        ) AS don_hoan_thanh,

        SUM(
            CASE
                WHEN trang_thai IN (
                    'huy',
                    'da_huy',
                    'Hủy',
                    'Đã hủy'
                )
                THEN 1
                ELSE 0
            END
        ) AS don_huy,

        SUM(
            CASE
                WHEN trang_thai IN (
                    'hoan_thanh',
                    'Hoàn thành',
                    'da_giao',
                    'Đã giao'
                )
                THEN tong_tien
                ELSE 0
            END
        ) AS tong_da_mua

    FROM don_hang
    WHERE nguoi_dung_id = ?
");

$stmtStats->bind_param("i", $targetId);
$stmtStats->execute();

$orderStats = $stmtStats
    ->get_result()
    ->fetch_assoc();

$tongDon = (int)($orderStats['tong_don'] ?? 0);
$donHoanThanh = (int)($orderStats['don_hoan_thanh'] ?? 0);
$donHuy = (int)($orderStats['don_huy'] ?? 0);
$tongDaMua = (float)($orderStats['tong_da_mua'] ?? 0);

/* =========================================================
   ĐƠN HÀNG GẦN NHẤT
========================================================= */
$stmtOrders = $conn->prepare("
    SELECT
        dh.id,
        dh.ma_van_don,
        dh.tong_tien,
        dh.trang_thai,
        dh.ngay_dat,

        (
            SELECT GROUP_CONCAT(
                DISTINCT sp.ma_san_pham
                ORDER BY sp.ma_san_pham
                SEPARATOR '||'
            )
            FROM chi_tiet_don_hang ct
            JOIN san_pham sp
                ON sp.id = ct.san_pham_id
            WHERE ct.don_hang_id = dh.id
        ) AS ma_san_pham_list

    FROM don_hang dh
    WHERE dh.nguoi_dung_id = ?
    ORDER BY dh.id DESC
    LIMIT 8
");

$stmtOrders->bind_param("i", $targetId);
$stmtOrders->execute();

$recentOrders = $stmtOrders
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

/* =========================================================
   HELPERS
========================================================= */
function statusLabel(string $status): string
{
    $map = [
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

    return $map[$status] ?? $status;
}

function money(float $value): string
{
    return number_format(
        $value,
        0,
        ',',
        '.'
    ) . 'đ';
}

$updated = ($_GET['updated'] ?? '') === '1';
?>

<?php require_once "../../includes/header.php"; ?>
<?php require_once "../../includes/navbar.php"; ?>

<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
>

<style>
.user-detail-page {
    min-height: 72vh;
    padding: 38px 0;
    background: #f6f7f9;
}

.detail-card {
    border: 1px solid #ececec;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 10px 30px rgba(0, 0, 0, .05);
}

.profile-head {
    padding: 28px;
    background:
        radial-gradient(circle at 90% 10%, rgba(238,77,45,.30), transparent 28%),
        linear-gradient(135deg,#151515,#262626);
    color: #fff;
}

.avatar {
    width: 72px;
    height: 72px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 20px;
    background: rgba(255,255,255,.10);
    font-size: 28px;
}

.profile-name {
    margin-top: 13px;
    font-size: 25px;
    font-weight: 850;
}

.profile-email {
    margin-top: 4px;
    color: rgba(255,255,255,.7);
    font-size: 13px;
}

.profile-body {
    padding: 26px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0,1fr));
    gap: 13px;
}

.info-item {
    padding: 14px;
    border: 1px solid #ededed;
    border-radius: 12px;
    background: #fafafa;
}

.info-label {
    margin-bottom: 5px;
    color: #8a8a8a;
    font-size: 11px;
    font-weight: 750;
}

.info-value {
    color: #252525;
    font-size: 14px;
    font-weight: 750;
    word-break: break-word;
}

.role-badge,
.status-badge {
    display: inline-flex;
    padding: 5px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
}

.role-admin {
    background: #fff2ec;
    color: #ee4d2d;
}

.role-user {
    background: #eef6ff;
    color: #0d6efd;
}

.status-active {
    background: #eaf8ef;
    color: #198754;
}

.status-locked {
    background: #fdecec;
    color: #dc3545;
}

.stat-box {
    height: 100%;
    padding: 18px;
    border: 1px solid #ececec;
    border-radius: 14px;
    background: #fff;
}

.stat-label {
    color: #888;
    font-size: 12px;
    font-weight: 700;
}

.stat-value {
    margin-top: 5px;
    font-size: 23px;
    font-weight: 850;
}

.action-card {
    padding: 23px;
}

.action-title {
    margin-bottom: 14px;
    font-size: 16px;
    font-weight: 850;
}

.warning-note {
    padding: 12px 14px;
    border-radius: 11px;
    background: #fff8f4;
    color: #765a50;
    font-size: 12px;
    line-height: 1.6;
}

.product-code-list {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    min-width: 115px;
}

.product-code-badge {
    display: inline-flex;
    align-items: center;
    padding: 5px 8px;
    border: 1px solid #ffd9cc;
    border-radius: 8px;
    background: #fff5f1;
    color: #ee4d2d;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .5px;
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
    padding: 5px 8px;
    border: 1px solid #cfe2ff;
    border-radius: 8px;
    background: #eef6ff;
    color: #0d6efd;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .5px;
    white-space: nowrap;
}

.tracking-empty {
    color: #999;
    font-size: 12px;
    font-weight: 600;
}

.table thead th {
    background: #fafafa;
    color: #666;
    font-size: 12px;
    font-weight: 800;
}

.table td,
.table th {
    padding: 12px;
    vertical-align: middle;
}

@media (max-width: 767px) {
    .info-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="user-detail-page">
    <div class="container">

        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
            <div>
                <h2 class="fw-bold mb-1">
                    Chi tiết người dùng
                </h2>

                <div class="text-muted small">
                    Quản lý hồ sơ, trạng thái và quyền tài khoản.
                </div>
            </div>

            <a
                href="index.php"
                class="btn btn-outline-secondary"
            >
                <i class="bi bi-arrow-left me-1"></i>
                Danh sách người dùng
            </a>
        </div>

        <?php if ($updated): ?>
            <div class="alert alert-success border-0 rounded-3">
                Cập nhật tài khoản thành công.
            </div>
        <?php endif; ?>

        <div class="row g-4">

            <!-- HỒ SƠ -->
            <div class="col-lg-5">

                <div class="detail-card overflow-hidden">

                    <div class="profile-head">
                        <div class="avatar">
                            <i class="bi bi-person"></i>
                        </div>

                        <div class="profile-name">
                            <?= htmlspecialchars(
                                (string)$targetUser['ho_ten']
                            ) ?>
                        </div>

                        <div class="profile-email">
                            <?= htmlspecialchars(
                                (string)$targetUser['email']
                            ) ?>
                        </div>
                    </div>

                    <div class="profile-body">

                        <div class="info-grid">

                            <div class="info-item">
                                <div class="info-label">
                                    ID nội bộ
                                </div>

                                <div class="info-value">
                                    #<?= (int)$targetUser['id'] ?>
                                </div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">
                                    Vai trò
                                </div>

                                <div class="info-value">
                                    <?php if ($targetUser['vai_tro'] === 'admin'): ?>
                                        <span class="role-badge role-admin">
                                            Admin
                                        </span>
                                    <?php else: ?>
                                        <span class="role-badge role-user">
                                            User
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">
                                    Số điện thoại
                                </div>

                                <div class="info-value">
                                    <?= !empty($targetUser['so_dien_thoai'])
                                        ? htmlspecialchars(
                                            (string)$targetUser['so_dien_thoai']
                                        )
                                        : 'Chưa cập nhật' ?>
                                </div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">
                                    Trạng thái
                                </div>

                                <div class="info-value">
                                    <?php if ((int)$targetUser['trang_thai'] === 1): ?>
                                        <span class="status-badge status-active">
                                            Hoạt động
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge status-locked">
                                            Bị khóa
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">
                                    Ngày tham gia
                                </div>

                                <div class="info-value">
                                    <?= !empty($targetUser['ngay_tao'])
                                        ? date(
                                            'd/m/Y H:i',
                                            strtotime(
                                                $targetUser['ngay_tao']
                                            )
                                        )
                                        : '—' ?>
                                </div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">
                                    Địa chỉ
                                </div>

                                <div class="info-value">
                                    <?= !empty($targetUser['dia_chi'])
                                        ? nl2br(
                                            htmlspecialchars(
                                                (string)$targetUser['dia_chi']
                                            )
                                        )
                                        : 'Chưa cập nhật' ?>
                                </div>
                            </div>

                        </div>

                    </div>
                </div>

            </div>

            <!-- THAO TÁC -->
            <div class="col-lg-7">

                <div class="detail-card action-card mb-4">

                    <div class="action-title">
                        Quản lý trạng thái tài khoản
                    </div>

                    <?php if ($targetId === $currentAdminId): ?>

                        <div class="warning-note">
                            Bạn đang xem chính tài khoản admin đang đăng nhập.
                            Hệ thống không cho phép tự khóa chính mình.
                        </div>

                    <?php else: ?>

                        <form
                            method="POST"
                            action="cap_nhat_trang_thai.php"
                            class="d-flex flex-wrap align-items-center gap-2"
                        >
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= htmlspecialchars($statusCsrf) ?>"
                            >

                            <input
                                type="hidden"
                                name="id"
                                value="<?= (int)$targetId ?>"
                            >

                            <input
                                type="hidden"
                                name="trang_thai"
                                value="<?= (int)$targetUser['trang_thai'] === 1 ? 0 : 1 ?>"
                            >

                            <?php if ((int)$targetUser['trang_thai'] === 1): ?>
                                <button
                                    type="submit"
                                    class="btn btn-outline-danger"
                                    onclick="return confirm('Khóa tài khoản này? Người dùng sẽ không thể đăng nhập.');"
                                >
                                    <i class="bi bi-lock me-1"></i>
                                    Khóa tài khoản
                                </button>
                            <?php else: ?>
                                <button
                                    type="submit"
                                    class="btn btn-success"
                                    onclick="return confirm('Mở khóa tài khoản này?');"
                                >
                                    <i class="bi bi-unlock me-1"></i>
                                    Mở khóa
                                </button>
                            <?php endif; ?>
                        </form>

                    <?php endif; ?>

                </div>

                <div class="detail-card action-card">

                    <div class="action-title">
                        Quản lý vai trò
                    </div>

                    <?php if ($targetId === $currentAdminId): ?>

                        <div class="warning-note">
                            Không thể tự thay đổi vai trò của chính tài khoản đang đăng nhập.
                        </div>

                    <?php else: ?>

                        <form
                            method="POST"
                            action="cap_nhat_vai_tro.php"
                            class="row g-2 align-items-end"
                        >

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= htmlspecialchars($roleCsrf) ?>"
                            >

                            <input
                                type="hidden"
                                name="id"
                                value="<?= (int)$targetId ?>"
                            >

                            <div class="col-md-7">
                                <label class="form-label fw-bold">
                                    Vai trò mới
                                </label>

                                <select
                                    name="vai_tro"
                                    class="form-select"
                                    required
                                >
                                    <option
                                        value="user"
                                        <?= $targetUser['vai_tro'] === 'user' ? 'selected' : '' ?>
                                    >
                                        User
                                    </option>

                                    <option
                                        value="admin"
                                        <?= $targetUser['vai_tro'] === 'admin' ? 'selected' : '' ?>
                                    >
                                        Admin
                                    </option>
                                </select>
                            </div>

                            <div class="col-md-5">
                                <button
                                    type="submit"
                                    class="btn btn-danger w-100"
                                    onclick="return confirm('Xác nhận thay đổi vai trò tài khoản này?');"
                                >
                                    Cập nhật vai trò
                                </button>
                            </div>

                        </form>

                        <div class="warning-note mt-3">
                            Hệ thống không cho phép hạ quyền admin cuối cùng của hệ thống.
                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>

        <!-- THỐNG KÊ -->
        <div class="row g-3 mt-1">

            <div class="col-lg-3 col-md-6">
                <div class="stat-box">
                    <div class="stat-label">
                        Tổng đơn hàng
                    </div>

                    <div class="stat-value">
                        <?= $tongDon ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <div class="stat-box">
                    <div class="stat-label">
                        Đơn hoàn thành
                    </div>

                    <div class="stat-value">
                        <?= $donHoanThanh ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <div class="stat-box">
                    <div class="stat-label">
                        Đơn đã hủy
                    </div>

                    <div class="stat-value">
                        <?= $donHuy ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <div class="stat-box">
                    <div class="stat-label">
                        Tổng đã mua
                    </div>

                    <div class="stat-value fs-5">
                        <?= money($tongDaMua) ?>
                    </div>
                </div>
            </div>

        </div>

        <!-- ĐƠN GẦN NHẤT -->
        <div class="detail-card mt-4 overflow-hidden">

            <div class="p-4 border-bottom">
                <h5 class="fw-bold mb-1">
                    Đơn hàng gần nhất
                </h5>

                <div class="text-muted small">
                    Tối đa 8 đơn gần nhất của tài khoản này.
                </div>
            </div>

            <div class="table-responsive">
                <table class="table mb-0">

                    <thead>
                        <tr>
                            <th>Mã sản phẩm</th>
                            <th>Mã vận đơn</th>
                            <th>Trạng thái</th>
                            <th>Ngày đặt</th>
                            <th>Tổng tiền</th>
                            <th class="text-end">Chi tiết</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (empty($recentOrders)): ?>

                            <tr>
                                <td
                                    colspan="6"
                                    class="text-center text-muted py-4"
                                >
                                    Người dùng chưa có đơn hàng.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($recentOrders as $order): ?>
                                <tr>
                                    <td>
                                        <?php
                                        $maSanPhamList = [];

                                        if (!empty($order['ma_san_pham_list'])) {
                                            foreach (
                                                explode(
                                                    '||',
                                                    (string)$order['ma_san_pham_list']
                                                )
                                                as $maSanPham
                                            ) {
                                                $maSanPham = trim($maSanPham);

                                                if ($maSanPham !== '') {
                                                    $maSanPhamList[] = $maSanPham;
                                                }
                                            }

                                            $maSanPhamList = array_values(
                                                array_unique($maSanPhamList)
                                            );
                                        }
                                        ?>

                                        <?php if (!empty($maSanPhamList)): ?>
                                            <div class="product-code-list">
                                                <?php foreach ($maSanPhamList as $maSanPham): ?>
                                                    <span class="product-code-badge">
                                                        <?= htmlspecialchars($maSanPham) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="product-code-empty">
                                                Chưa có mã SP
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if (!empty($order['ma_van_don'])): ?>
                                            <span class="tracking-code">
                                                <?= htmlspecialchars(
                                                    (string)$order['ma_van_don']
                                                ) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="tracking-empty">
                                                Chưa có mã
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            statusLabel(
                                                (string)$order['trang_thai']
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= !empty($order['ngay_dat'])
                                            ? date(
                                                'd/m/Y H:i',
                                                strtotime(
                                                    $order['ngay_dat']
                                                )
                                            )
                                            : '—' ?>
                                    </td>

                                    <td>
                                        <strong>
                                            <?= money(
                                                (float)$order['tong_tien']
                                            ) ?>
                                        </strong>
                                    </td>

                                    <td class="text-end">
                                        <a
                                            href="../don_hang/chi_tiet.php?id=<?= (int)$order['id'] ?>"
                                            class="btn btn-sm btn-outline-primary"
                                        >
                                            Xem đơn
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>
                </table>
            </div>

        </div>

    </div>
</div>

<?php require_once "../../includes/footer.php"; ?>
