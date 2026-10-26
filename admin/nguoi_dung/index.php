<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../middleware/check_admin.php";
require_once "../../config/database.php";

/* =========================================================
   BỘ LỌC
========================================================= */
$q = trim((string)($_GET['q'] ?? ''));
$roleFilter = trim((string)($_GET['role'] ?? 'all'));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$allowedRoles = ['all', 'user', 'admin'];
$allowedStatuses = ['all', 'active', 'locked'];

if (!in_array($roleFilter, $allowedRoles, true)) {
    $roleFilter = 'all';
}

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

/* =========================================================
   THỐNG KÊ NHANH
========================================================= */
$statsSql = "
    SELECT
        COUNT(*) AS tong,
        SUM(CASE WHEN nd.trang_thai = 1 THEN 1 ELSE 0 END) AS hoat_dong,
        SUM(CASE WHEN nd.trang_thai = 0 THEN 1 ELSE 0 END) AS bi_khoa,
        SUM(CASE WHEN vt.vai_tro = 'user' THEN 1 ELSE 0 END) AS user_count,
        SUM(CASE WHEN vt.vai_tro = 'admin' THEN 1 ELSE 0 END) AS admin_count
    FROM nguoi_dung nd
    JOIN vai_tro vt
        ON vt.id = nd.vai_tro_id
";

$statsResult = $conn->query($statsSql);
$stats = $statsResult
    ? $statsResult->fetch_assoc()
    : [];

$tongNguoiDung = (int)($stats['tong'] ?? 0);
$hoatDong = (int)($stats['hoat_dong'] ?? 0);
$biKhoa = (int)($stats['bi_khoa'] ?? 0);
$userCount = (int)($stats['user_count'] ?? 0);
$adminCount = (int)($stats['admin_count'] ?? 0);

/* =========================================================
   XÂY WHERE
========================================================= */
$where = " WHERE 1=1 ";
$types = '';
$params = [];

if ($q !== '') {
    $where .= "
        AND (
            nd.ho_ten LIKE ?
            OR nd.email LIKE ?
            OR nd.so_dien_thoai LIKE ?
            OR CAST(nd.id AS CHAR) LIKE ?
        )
    ";

    $keyword = '%' . $q . '%';

    $types .= 'ssss';
    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;
}

if ($roleFilter !== 'all') {
    $where .= " AND vt.vai_tro = ? ";
    $types .= 's';
    $params[] = $roleFilter;
}

if ($statusFilter === 'active') {
    $where .= " AND nd.trang_thai = 1 ";
} elseif ($statusFilter === 'locked') {
    $where .= " AND nd.trang_thai = 0 ";
}

/* =========================================================
   ĐẾM TỔNG DÒNG
========================================================= */
$countSql = "
    SELECT COUNT(*) AS total
    FROM nguoi_dung nd
    JOIN vai_tro vt
        ON vt.id = nd.vai_tro_id
    $where
";

$stmtCount = $conn->prepare($countSql);

if (!$stmtCount) {
    die("Không thể đếm người dùng.");
}

if ($types !== '') {
    $stmtCount->bind_param($types, ...$params);
}

$stmtCount->execute();

$totalRows = (int)(
    $stmtCount
        ->get_result()
        ->fetch_assoc()['total'] ?? 0
);

$totalPages = max(
    1,
    (int)ceil($totalRows / $perPage)
);

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

/* =========================================================
   DANH SÁCH NGƯỜI DÙNG
========================================================= */
$listSql = "
    SELECT
        nd.id,
        nd.ho_ten,
        nd.email,
        nd.so_dien_thoai,
        nd.trang_thai,
        nd.ngay_tao,
        vt.vai_tro,

        (
            SELECT COUNT(*)
            FROM don_hang dh
            WHERE dh.nguoi_dung_id = nd.id
        ) AS tong_don

    FROM nguoi_dung nd

    JOIN vai_tro vt
        ON vt.id = nd.vai_tro_id

    $where

    ORDER BY nd.id DESC

    LIMIT ?
    OFFSET ?
";

$stmtList = $conn->prepare($listSql);

if (!$stmtList) {
    die("Không thể tải danh sách người dùng.");
}

$listTypes = $types . 'ii';
$listParams = $params;
$listParams[] = $perPage;
$listParams[] = $offset;

$stmtList->bind_param(
    $listTypes,
    ...$listParams
);

$stmtList->execute();

$users = $stmtList
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

/* =========================================================
   HELPER URL
========================================================= */
function buildPageUrl(
    int $page,
    string $q,
    string $role,
    string $status
): string {
    $query = [
        'page' => $page
    ];

    if ($q !== '') {
        $query['q'] = $q;
    }

    if ($role !== 'all') {
        $query['role'] = $role;
    }

    if ($status !== 'all') {
        $query['status'] = $status;
    }

    return 'index.php?' . http_build_query($query);
}
?>

<?php require_once "../../includes/header.php"; ?>
<?php require_once "../../includes/navbar.php"; ?>

<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
>

<style>
.user-admin-page {
    min-height: 72vh;
    padding: 36px 0;
    background: #f6f7f9;
}

.page-card {
    border: 1px solid #ececec;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 10px 30px rgba(0, 0, 0, .05);
}

.page-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    margin-bottom: 22px;
}

.page-title {
    margin: 0;
    font-size: 27px;
    font-weight: 850;
}

.page-subtitle {
    margin-top: 5px;
    color: #777;
    font-size: 13px;
}

.stat-card {
    height: 100%;
    padding: 18px;
    border: 1px solid #ececec;
    border-radius: 15px;
    background: #fff;
    text-decoration: none;
    color: inherit;
    transition: .2s;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, .06);
}

.stat-label {
    color: #858585;
    font-size: 12px;
    font-weight: 700;
}

.stat-value {
    margin-top: 6px;
    font-size: 25px;
    font-weight: 850;
}

.filter-card {
    padding: 18px;
    margin-bottom: 20px;
}

.form-control,
.form-select {
    min-height: 44px;
    border-radius: 10px;
    box-shadow: none !important;
}

.form-control:focus,
.form-select:focus {
    border-color: #ee4d2d;
}

.table-card {
    overflow: hidden;
}

.table {
    margin-bottom: 0;
    vertical-align: middle;
}

.table thead th {
    padding: 14px 12px;
    background: #fafafa;
    color: #666;
    border-bottom: 1px solid #ededed;
    font-size: 12px;
    font-weight: 800;
    white-space: nowrap;
}

.table tbody td {
    padding: 14px 12px;
    border-color: #f0f0f0;
}

.user-name {
    color: #222;
    font-weight: 800;
}

.user-email {
    margin-top: 3px;
    color: #888;
    font-size: 12px;
}

.role-badge,
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
    white-space: nowrap;
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

.detail-btn {
    border-radius: 9px;
    font-weight: 700;
}

.empty-state {
    padding: 45px 20px;
    color: #888;
    text-align: center;
}

.pagination .page-link {
    color: #444;
}

.pagination .active .page-link {
    border-color: #ee4d2d;
    background: #ee4d2d;
}

@media (max-width: 767px) {
    .page-heading {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>

<div class="user-admin-page">
    <div class="container-fluid px-3 px-lg-4">

        <div class="page-heading">
            <div>
                <h1 class="page-title">
                    Quản lý người dùng
                </h1>

                <div class="page-subtitle">
                    Theo dõi tài khoản, vai trò và trạng thái người dùng trong hệ thống.
                </div>
            </div>

            <a
                href="../index.php"
                class="btn btn-outline-secondary"
            >
                <i class="bi bi-arrow-left me-1"></i>
                Trang quản trị
            </a>
        </div>

        <!-- THỐNG KÊ -->
        <div class="row g-3 mb-4">

            <div class="col-xl col-md-4 col-6">
                <a
                    href="index.php"
                    class="stat-card d-block"
                >
                    <div class="stat-label">
                        Tổng tài khoản
                    </div>

                    <div class="stat-value">
                        <?= $tongNguoiDung ?>
                    </div>
                </a>
            </div>

            <div class="col-xl col-md-4 col-6">
                <a
                    href="index.php?status=active"
                    class="stat-card d-block"
                >
                    <div class="stat-label">
                        Đang hoạt động
                    </div>

                    <div class="stat-value">
                        <?= $hoatDong ?>
                    </div>
                </a>
            </div>

            <div class="col-xl col-md-4 col-6">
                <a
                    href="index.php?status=locked"
                    class="stat-card d-block"
                >
                    <div class="stat-label">
                        Bị khóa
                    </div>

                    <div class="stat-value">
                        <?= $biKhoa ?>
                    </div>
                </a>
            </div>

            <div class="col-xl col-md-4 col-6">
                <a
                    href="index.php?role=user"
                    class="stat-card d-block"
                >
                    <div class="stat-label">
                        Người mua
                    </div>

                    <div class="stat-value">
                        <?= $userCount ?>
                    </div>
                </a>
            </div>

            <div class="col-xl col-md-4 col-6">
                <a
                    href="index.php?role=admin"
                    class="stat-card d-block"
                >
                    <div class="stat-label">
                        Admin
                    </div>

                    <div class="stat-value">
                        <?= $adminCount ?>
                    </div>
                </a>
            </div>

        </div>

        <!-- BỘ LỌC -->
        <div class="page-card filter-card">
            <form
                method="GET"
                class="row g-2 align-items-end"
            >

                <div class="col-lg-5">
                    <label class="form-label small fw-bold">
                        Tìm kiếm
                    </label>

                    <input
                        type="text"
                        name="q"
                        class="form-control"
                        value="<?= htmlspecialchars($q) ?>"
                        placeholder="Họ tên, email, số điện thoại hoặc ID..."
                    >
                </div>

                <div class="col-lg-2 col-md-4">
                    <label class="form-label small fw-bold">
                        Vai trò
                    </label>

                    <select
                        name="role"
                        class="form-select"
                    >
                        <option value="all" <?= $roleFilter === 'all' ? 'selected' : '' ?>>
                            Tất cả
                        </option>

                        <option value="user" <?= $roleFilter === 'user' ? 'selected' : '' ?>>
                            User
                        </option>

                        <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>
                            Admin
                        </option>
                    </select>
                </div>

                <div class="col-lg-2 col-md-4">
                    <label class="form-label small fw-bold">
                        Trạng thái
                    </label>

                    <select
                        name="status"
                        class="form-select"
                    >
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>
                            Tất cả
                        </option>

                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>
                            Hoạt động
                        </option>

                        <option value="locked" <?= $statusFilter === 'locked' ? 'selected' : '' ?>>
                            Bị khóa
                        </option>
                    </select>
                </div>

                <div class="col-lg-3 col-md-4">
                    <div class="d-flex gap-2">
                        <button
                            type="submit"
                            class="btn btn-danger flex-grow-1"
                        >
                            <i class="bi bi-search me-1"></i>
                            Lọc
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
        </div>

        <!-- DANH SÁCH -->
        <div class="page-card table-card">
            <div class="table-responsive">

                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Người dùng</th>
                            <th>Số điện thoại</th>
                            <th>Vai trò</th>
                            <th>Trạng thái</th>
                            <th>Đơn hàng</th>
                            <th>Ngày tham gia</th>
                            <th class="text-end">Thao tác</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (empty($users)): ?>

                            <tr>
                                <td colspan="8">
                                    <div class="empty-state">
                                        <i class="bi bi-people fs-2 d-block mb-2"></i>
                                        Không tìm thấy người dùng phù hợp.
                                    </div>
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($users as $row): ?>

                                <tr>
                                    <td>
                                        #<?= (int)$row['id'] ?>
                                    </td>

                                    <td>
                                        <div class="user-name">
                                            <?= htmlspecialchars(
                                                (string)$row['ho_ten']
                                            ) ?>
                                        </div>

                                        <div class="user-email">
                                            <?= htmlspecialchars(
                                                (string)$row['email']
                                            ) ?>
                                        </div>
                                    </td>

                                    <td>
                                        <?= !empty($row['so_dien_thoai'])
                                            ? htmlspecialchars(
                                                (string)$row['so_dien_thoai']
                                            )
                                            : '—' ?>
                                    </td>

                                    <td>
                                        <?php if ($row['vai_tro'] === 'admin'): ?>
                                            <span class="role-badge role-admin">
                                                <i class="bi bi-shield-check"></i>
                                                Admin
                                            </span>
                                        <?php else: ?>
                                            <span class="role-badge role-user">
                                                <i class="bi bi-person"></i>
                                                User
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if ((int)$row['trang_thai'] === 1): ?>
                                            <span class="status-badge status-active">
                                                <i class="bi bi-check-circle"></i>
                                                Hoạt động
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge status-locked">
                                                <i class="bi bi-lock"></i>
                                                Bị khóa
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?= (int)$row['tong_don'] ?>
                                    </td>

                                    <td>
                                        <?= !empty($row['ngay_tao'])
                                            ? date(
                                                'd/m/Y',
                                                strtotime(
                                                    $row['ngay_tao']
                                                )
                                            )
                                            : '—' ?>
                                    </td>

                                    <td class="text-end">
                                        <a
                                            href="chi_tiet.php?id=<?= (int)$row['id'] ?>"
                                            class="btn btn-sm btn-outline-primary detail-btn"
                                        >
                                            <i class="bi bi-eye me-1"></i>
                                            Chi tiết
                                        </a>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>
                </table>

            </div>
        </div>

        <!-- PHÂN TRANG -->
        <?php if ($totalPages > 1): ?>

            <nav class="mt-4">
                <ul class="pagination justify-content-center">

                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a
                            class="page-link"
                            href="<?= htmlspecialchars(
                                buildPageUrl(
                                    max(1, $page - 1),
                                    $q,
                                    $roleFilter,
                                    $statusFilter
                                )
                            ) ?>"
                        >
                            Trước
                        </a>
                    </li>

                    <?php
                    $from = max(1, $page - 2);
                    $to = min($totalPages, $page + 2);
                    ?>

                    <?php for ($i = $from; $i <= $to; $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a
                                class="page-link"
                                href="<?= htmlspecialchars(
                                    buildPageUrl(
                                        $i,
                                        $q,
                                        $roleFilter,
                                        $statusFilter
                                    )
                                ) ?>"
                            >
                                <?= $i ?>
                            </a>
                        </li>
                    <?php endfor; ?>

                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a
                            class="page-link"
                            href="<?= htmlspecialchars(
                                buildPageUrl(
                                    min($totalPages, $page + 1),
                                    $q,
                                    $roleFilter,
                                    $statusFilter
                                )
                            ) ?>"
                        >
                            Sau
                        </a>
                    </li>

                </ul>
            </nav>

        <?php endif; ?>

    </div>
</div>

<?php require_once "../../includes/footer.php"; ?>
