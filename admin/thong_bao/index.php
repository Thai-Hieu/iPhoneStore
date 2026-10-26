<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../middleware/check_admin.php";
require_once "../../config/database.php";
require_once "../../includes/notification_helper.php";

$adminId =
    (int)($_SESSION['user']['id'] ?? 0);

if ($adminId <= 0) {
    die("Bạn chưa đăng nhập.");
}

$limit = 15;

$page =
    max(
        1,
        (int)($_GET['page'] ?? 1)
    );

$stmtCount = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM thong_bao
    WHERE nguoi_dung_id = ?
");

$stmtCount->bind_param(
    "i",
    $adminId
);

$stmtCount->execute();

$total =
    (int)(
        $stmtCount
            ->get_result()
            ->fetch_assoc()['total']
        ?? 0
    );

$totalPages =
    max(
        1,
        (int)ceil($total / $limit)
    );

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset =
    ($page - 1) * $limit;

$stmt = $conn->prepare("
    SELECT
        id,
        don_hang_id,
        tieu_de,
        noi_dung,
        loai,
        lien_ket,
        da_doc,
        ngay_tao,
        ngay_doc
    FROM thong_bao
    WHERE nguoi_dung_id = ?
    ORDER BY
        ngay_tao DESC,
        id DESC
    LIMIT ?
    OFFSET ?
");

$stmt->bind_param(
    "iii",
    $adminId,
    $limit,
    $offset
);

$stmt->execute();

$notifications =
    $stmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

$unreadCount =
    demThongBaoChuaDoc(
        $conn,
        $adminId
    );

if (empty($_SESSION['csrf_notification'])) {
    $_SESSION['csrf_notification'] =
        bin2hex(random_bytes(32));
}

$csrfToken =
    (string)$_SESSION['csrf_notification'];

$icons = [
    'don_moi' => '🧾',
    'khach_huy_don' => '👤',
    'huy_don' => '❌',
    'he_thong' => '🔔'
];
?>

<?php require_once "../../includes/header.php"; ?>
<?php require_once "../../includes/navbar.php"; ?>

<style>
.admin-notification-page {
    min-height: 72vh;
    padding: 30px 0 45px;
    background: #f6f7f9;
}

.admin-notification-card {
    overflow: hidden;
    border: 1px solid #ececec;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 8px 28px rgba(0, 0, 0, .05);
}

.admin-notification-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 20px;
    border-bottom: 1px solid #eee;
}

.admin-notification-title {
    font-size: 22px;
    font-weight: 850;
}

.admin-notification-subtitle {
    margin-top: 4px;
    color: #777;
    font-size: 13px;
}

.admin-notification-item {
    display: grid;
    grid-template-columns: 46px minmax(0, 1fr) auto;
    gap: 14px;
    align-items: start;
    padding: 17px 20px;
    border-bottom: 1px solid #f0f0f0;
}

.admin-notification-item.unread {
    background: #fff7f8;
}

.admin-notification-icon {
    width: 44px;
    height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    background: #f4f5f7;
    font-size: 21px;
}

.admin-notification-item-title {
    color: #222;
    font-size: 14px;
    font-weight: 850;
}

.admin-notification-item-text {
    margin-top: 4px;
    color: #666;
    font-size: 13px;
    line-height: 1.55;
}

.admin-notification-time {
    margin-top: 7px;
    color: #999;
    font-size: 11px;
}

.admin-notification-empty {
    padding: 60px 20px;
    color: #777;
    text-align: center;
}

.admin-pagination {
    display: flex;
    justify-content: center;
    gap: 6px;
    padding: 18px;
}

.admin-pagination a,
.admin-pagination span {
    min-width: 38px;
    height: 38px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #ddd;
    border-radius: 9px;
    color: #444;
    text-decoration: none;
}

.admin-pagination .active {
    border-color: #d70018;
    background: #d70018;
    color: #fff;
}

@media (max-width: 767px) {
    .admin-notification-item {
        grid-template-columns: 42px minmax(0, 1fr);
    }

    .admin-notification-action {
        grid-column: 2;
    }
}
</style>

<div class="admin-notification-page">
    <div class="container">

        <div class="admin-notification-card">

            <div class="admin-notification-header">

                <div>
                    <div class="admin-notification-title">
                        🔔 Thông báo quản trị
                    </div>

                    <div class="admin-notification-subtitle">
                        Có
                        <strong><?= $unreadCount ?></strong>
                        thông báo chưa đọc.
                    </div>
                </div>

                <?php if ($unreadCount > 0): ?>
                    <form
                        method="POST"
                        action="danh_dau_tat_ca.php"
                    >
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars($csrfToken) ?>"
                        >

                        <button
                            type="submit"
                            class="btn btn-sm btn-outline-secondary"
                        >
                            Đánh dấu tất cả đã đọc
                        </button>
                    </form>
                <?php endif; ?>

            </div>

            <?php if (empty($notifications)): ?>

                <div class="admin-notification-empty">
                    <div class="fs-2 mb-2">🔕</div>
                    Chưa có thông báo quản trị nào.
                </div>

            <?php else: ?>

                <?php foreach ($notifications as $item): ?>

                    <?php
                    $isUnread =
                        (int)$item['da_doc'] === 0;

                    $icon =
                        $icons[$item['loai']]
                        ?? '🔔';
                    ?>

                    <div
                        class="admin-notification-item <?= $isUnread
                            ? 'unread'
                            : '' ?>"
                    >

                        <div class="admin-notification-icon">
                            <?= $icon ?>
                        </div>

                        <div>
                            <div class="admin-notification-item-title">
                                <?= htmlspecialchars($item['tieu_de']) ?>
                            </div>

                            <div class="admin-notification-item-text">
                                <?= htmlspecialchars($item['noi_dung']) ?>
                            </div>

                            <div class="admin-notification-time">
                                <?= !empty($item['ngay_tao'])
                                    ? date(
                                        'd/m/Y H:i',
                                        strtotime($item['ngay_tao'])
                                    )
                                    : '-' ?>
                            </div>
                        </div>

                        <div class="admin-notification-action">
                            <a
                                href="xem.php?id=<?= (int)$item['id'] ?>"
                                class="btn btn-sm btn-outline-danger"
                            >
                                Xem
                            </a>
                        </div>

                    </div>

                <?php endforeach; ?>

                <?php if ($totalPages > 1): ?>
                    <div class="admin-pagination">

                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>

                            <?php if ($p === $page): ?>
                                <span class="active">
                                    <?= $p ?>
                                </span>
                            <?php else: ?>
                                <a href="?page=<?= $p ?>">
                                    <?= $p ?>
                                </a>
                            <?php endif; ?>

                        <?php endfor; ?>

                    </div>
                <?php endif; ?>

            <?php endif; ?>

        </div>

    </div>
</div>

<?php require_once "../../includes/footer.php"; ?>
