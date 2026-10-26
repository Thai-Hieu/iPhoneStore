<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';

if (
    (string)(
        $_SESSION['user']['vai_tro']
        ?? ''
    ) !== 'admin'
) {
    header(
        'Location: /iPhoneStore/auth/login.php'
    );
    exit;
}

require_once __DIR__ . '/_common.php';

$csrf = kmCsrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        !kmCsrfOk(
            $_POST['csrf_token']
            ?? null
        )
    ) {
        $_SESSION['km_error'] =
            'Phiên thao tác không hợp lệ.';

        kmGo(
            '/iPhoneStore/admin/khuyen_mai/index.php'
        );
    }

    $id =
        (int)(
            $_POST['id']
            ?? 0
        );

    $action =
        trim(
            (string)(
                $_POST['action']
                ?? ''
            )
        );

    if ($id <= 0) {
        $_SESSION['km_error'] =
            'Khuyến mãi không hợp lệ.';

        kmGo(
            '/iPhoneStore/admin/khuyen_mai/index.php'
        );
    }

    if ($action === 'delete') {
        $stmt =
            $conn->prepare("
                DELETE FROM khuyen_mai
                WHERE id = ?
                LIMIT 1
            ");

        $stmt->bind_param(
            'i',
            $id
        );

        $stmt->execute();

        $_SESSION['km_success'] =
            $stmt->affected_rows > 0
                ? 'Đã xóa khuyến mãi.'
                : 'Không tìm thấy khuyến mãi.';

        $stmt->close();
    } elseif ($action === 'toggle') {
        /*
            Chỉ chặn khi đang BẬT lại khuyến mãi.
            Nếu chương trình đang bật thì vẫn cho TẮT,
            kể cả sản phẩm/biến thể đã hết hàng.
        */
        $stmtCheck =
            $conn->prepare("
                SELECT
                    km.trang_thai,
                    km.san_pham_id,
                    km.bien_the_id,
                    km.loai_khuyen_mai,
                    km.ton_kho_toi_da,

                    CASE
                        WHEN EXISTS (
                            SELECT 1
                            FROM san_pham_bien_the bt_exists
                            WHERE bt_exists.san_pham_id = sp.id
                        )
                        THEN COALESCE((
                            SELECT SUM(bt_all.so_luong)
                            FROM san_pham_bien_the bt_all
                            WHERE bt_all.san_pham_id = sp.id
                        ), 0)
                        ELSE COALESCE(sp.so_luong, 0)
                    END AS tong_ton_san_pham,

                    COALESCE((
                        SELECT MAX(bt_max.so_luong)
                        FROM san_pham_bien_the bt_max
                        WHERE bt_max.san_pham_id = sp.id
                    ), 0) AS ton_bien_the_cao_nhat,

                    COALESCE(
                        bt.so_luong,
                        0
                    ) AS ton_bien_the

                FROM khuyen_mai km

                JOIN san_pham sp
                    ON sp.id = km.san_pham_id

                LEFT JOIN san_pham_bien_the bt
                    ON bt.id = km.bien_the_id

                WHERE km.id = ?
                LIMIT 1
            ");

        if (!$stmtCheck) {
            $_SESSION['km_error'] =
                'Không thể kiểm tra tồn kho khuyến mãi.';
        } else {
            $stmtCheck->bind_param(
                'i',
                $id
            );

            $stmtCheck->execute();

            $toggleRow =
                $stmtCheck
                    ->get_result()
                    ->fetch_assoc();

            $stmtCheck->close();

            if (!$toggleRow) {
                $_SESSION['km_error'] =
                    'Không tìm thấy khuyến mãi.';
            } else {
                $isCurrentlyOn =
                    (int)$toggleRow['trang_thai'] === 1;

                $hasVariant =
                    !empty($toggleRow['bien_the_id']);

                $currentStock =
                    $hasVariant
                        ? (int)$toggleRow['ton_bien_the']
                        : (int)$toggleRow['tong_ton_san_pham'];

                $isClearance =
                    (string)($toggleRow['loai_khuyen_mai'] ?? '') ===
                    'xa_kho';

                $clearanceThreshold =
                    max(
                        0,
                        (int)($toggleRow['ton_kho_toi_da'] ?? 0)
                    );

                $clearanceStock =
                    $hasVariant
                        ? (int)$toggleRow['ton_bien_the']
                        : (
                            (int)$toggleRow['ton_bien_the_cao_nhat'] > 0
                                ? (int)$toggleRow['ton_bien_the_cao_nhat']
                                : (int)$toggleRow['tong_ton_san_pham']
                        );

                if (
                    !$isCurrentlyOn &&
                    $currentStock <= 0
                ) {
                    $_SESSION['km_error'] =
                        $hasVariant
                            ? 'Biến thể đã hết hàng nên không thể bật khuyến mãi.'
                            : 'Sản phẩm đã hết hàng nên không thể bật khuyến mãi.';
                } elseif (
                    !$isCurrentlyOn &&
                    $isClearance &&
                    $clearanceThreshold > 0 &&
                    $clearanceStock < $clearanceThreshold
                ) {
                    $_SESSION['km_error'] =
                        $hasVariant
                            ? 'Biến thể chưa đạt ngưỡng tồn kho Xả kho nên không thể bật khuyến mãi.'
                            : 'Không có biến thể nào đạt ngưỡng tồn kho Xả kho nên không thể bật khuyến mãi toàn sản phẩm.';
                } else {
                    $stmt =
                        $conn->prepare("
                            UPDATE khuyen_mai
                            SET trang_thai =
                                IF(trang_thai = 1, 0, 1)
                            WHERE id = ?
                            LIMIT 1
                        ");

                    if ($stmt) {
                        $stmt->bind_param(
                            'i',
                            $id
                        );

                        $stmt->execute();
                        $stmt->close();

                        $_SESSION['km_success'] =
                            'Đã cập nhật trạng thái.';
                    } else {
                        $_SESSION['km_error'] =
                            'Không thể cập nhật trạng thái.';
                    }
                }
            }
        }
    } else {
        $_SESSION['km_error'] =
            'Thao tác không hợp lệ.';
    }

    kmGo(
        '/iPhoneStore/admin/khuyen_mai/index.php'
    );
}

$q =
    trim(
        (string)(
            $_GET['q']
            ?? ''
        )
    );

$schemaReady =
    kmSchemaReady($conn);

$promos = [];

if ($schemaReady) {
    $sql = "
        SELECT
            km.*,
            sp.ten_san_pham,
            bt.ram,
            bt.rom,
            bt.gia AS gia_bien_the,
            bt.so_luong AS ton_bien_the,
            ms.ten_mau,

            CASE
                WHEN EXISTS (
                    SELECT 1
                    FROM san_pham_bien_the bt_exists2
                    WHERE bt_exists2.san_pham_id = sp.id
                )
                THEN COALESCE((
                    SELECT SUM(bt_stock.so_luong)
                    FROM san_pham_bien_the bt_stock
                    WHERE bt_stock.san_pham_id = sp.id
                ), 0)
                ELSE COALESCE(sp.so_luong, 0)
            END AS tong_ton_san_pham,

            EXISTS (
                SELECT 1
                FROM san_pham_bien_the bt_kind
                WHERE bt_kind.san_pham_id = sp.id
            ) AS co_bien_the,

            COALESCE((
                SELECT MAX(bt_max_list.so_luong)
                FROM san_pham_bien_the bt_max_list
                WHERE bt_max_list.san_pham_id = sp.id
            ), 0) AS ton_bien_the_cao_nhat

        FROM khuyen_mai km
        JOIN san_pham sp
            ON sp.id = km.san_pham_id
        LEFT JOIN san_pham_bien_the bt
            ON bt.id = km.bien_the_id
        LEFT JOIN mau_sac ms
            ON ms.id = bt.mau_id
        WHERE 1 = 1
    ";

    $params = [];
    $types = '';

    if ($q !== '') {
        $sql .= "
            AND (
                km.ten_khuyen_mai LIKE ?
                OR sp.ten_san_pham LIKE ?
                OR km.loai_khuyen_mai LIKE ?
                OR km.ma_su_kien LIKE ?
            )
        ";

        $like =
            '%' . $q . '%';

        $params = [
            $like,
            $like,
            $like,
            $like,
        ];

        $types = 'ssss';
    }

    $sql .= "
        ORDER BY
            km.trang_thai DESC,
            km.uu_tien DESC,
            km.id DESC
    ";

    $stmt =
        $conn->prepare($sql);

    if ($stmt) {
        if ($types !== '') {
            $stmt->bind_param(
                $types,
                ...$params
            );
        }

        $stmt->execute();

        $promos =
            $stmt
                ->get_result()
                ->fetch_all(
                    MYSQLI_ASSOC
                );

        $stmt->close();
    }
}

$summary = [
    'total' => count($promos),
    'running' => 0,
    'waiting' => 0,
    'scheduled' => 0,
    'off' => 0,
    'expired' => 0,
];

foreach ($promos as $promoRow) {
    $hasVariant =
        !empty($promoRow['bien_the_id']);

    $currentStock =
        $hasVariant
            ? (int)($promoRow['ton_bien_the'] ?? 0)
            : (int)($promoRow['tong_ton_san_pham'] ?? 0);

    $isClearance =
        (string)($promoRow['loai_khuyen_mai'] ?? '') ===
        'xa_kho';

    $clearanceThreshold =
        max(
            0,
            (int)($promoRow['ton_kho_toi_da'] ?? 0)
        );

    $clearanceStock =
        $hasVariant
            ? (int)($promoRow['ton_bien_the'] ?? 0)
            : (
                !empty($promoRow['co_bien_the'])
                    ? (int)($promoRow['ton_bien_the_cao_nhat'] ?? 0)
                    : (int)($promoRow['tong_ton_san_pham'] ?? 0)
            );

    $clearanceBelowThreshold =
        $isClearance &&
        $clearanceThreshold > 0 &&
        $clearanceStock < $clearanceThreshold;

    if (
        $currentStock <= 0 ||
        $clearanceBelowThreshold
    ) {
        $summary['off']++;
        continue;
    }

    $state =
        kmEffectiveState($promoRow);

    if ($state['code'] === 'running') {
        $summary['running']++;
    } elseif ($state['code'] === 'waiting_time') {
        $summary['waiting']++;
    } elseif ($state['code'] === 'scheduled') {
        $summary['scheduled']++;
    } elseif ($state['code'] === 'expired') {
        $summary['expired']++;
    } else {
        $summary['off']++;
    }
}

$success =
    (string)(
        $_SESSION['km_success']
        ?? ''
    );

$error =
    (string)(
        $_SESSION['km_error']
        ?? ''
    );

unset(
    $_SESSION['km_success'],
    $_SESSION['km_error']
);

$typeLabels = kmLoai();
$eventLabels = kmSuKien();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
?>

<style>
.km-page {
    padding: 28px 0 48px;
    background: #f6f7f9;
    min-height: 75vh;
}

.km-card {
    background: #fff;
    border: 1px solid #e7e9ee;
    border-radius: 18px;
    box-shadow: 0 10px 30px rgba(15,23,42,.05);
    overflow: hidden;
}

.km-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    padding: 18px;
    border-bottom: 1px solid #eceef2;
}

.km-head h1 {
    margin: 0;
    font-size: 22px;
    font-weight: 900;
}

.km-sub {
    margin-top: 4px;
    color: #7c8490;
    font-size: 12px;
}

.km-tools {
    display: flex;
    gap: 8px;
    padding: 14px 18px;
}

.km-tools input {
    max-width: 420px;
}

.km-btn {
    min-height: 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 11px;
    border: 1px solid #dfe2e7;
    border-radius: 9px;
    background: #fff;
    color: #353c47;
    font-size: 11px;
    font-weight: 800;
    text-decoration: none;
    cursor: pointer;
}

.km-btn.primary {
    border-color: #d70018;
    background: #d70018;
    color: #fff;
}

.km-btn.danger {
    border-color: #efabb3;
    color: #d70018;
}

.km-table-wrap {
    overflow-x: auto;
}

.km-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}

.km-table th,
.km-table td {
    padding: 11px 12px;
    border-top: 1px solid #eef0f3;
    vertical-align: top;
}

.km-table th {
    background: #fafbfc;
    white-space: nowrap;
}

.km-type {
    font-weight: 900;
    color: #262d37;
}

.km-event {
    margin-top: 4px;
    color: #7b3ff2;
    font-size: 10px;
    font-weight: 800;
}

.km-scope {
    margin-top: 4px;
    color: #0d6efd;
    font-size: 10px;
    font-weight: 850;
}

.km-muted {
    margin-top: 3px;
    color: #878e98;
    font-size: 10px;
}

.km-tags {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}

.km-tag {
    padding: 3px 6px;
    border-radius: 999px;
    background: #f3f5f8;
    color: #59616d;
    font-size: 9px;
}

.km-status {
    display: inline-block;
    padding: 5px 8px;
    border-radius: 999px;
    background: #eef0f3;
    color: #676d77;
    font-size: 10px;
    font-weight: 800;
}

.km-status.on {
    background: #eaf8ef;
    color: #198754;
}

.km-actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.km-actions form {
    margin: 0;
}

.km-warn {
    margin: 16px 18px;
}

.km-summary {
    display: grid;
    grid-template-columns: repeat(4, minmax(0,1fr));
    gap: 10px;
    padding: 15px 18px 4px;
}

.km-summary-card {
    padding: 13px 14px;
    border: 1px solid #e7e9ee;
    border-radius: 13px;
    background: #fff;
}

.km-summary-card.primary {
    border-color: #f0b6bd;
    background: #fff7f8;
}

.km-summary-card.warn {
    border-color: #f0ddac;
    background: #fffaf0;
}

.km-summary-card.info {
    border-color: #cfe0ff;
    background: #f7faff;
}

.km-summary-label {
    color: #7d8591;
    font-size: 10px;
    font-weight: 800;
}

.km-summary-value {
    margin-top: 3px;
    color: #222831;
    font-size: 23px;
    line-height: 1;
    font-weight: 950;
}

.km-summary-note {
    margin-top: 5px;
    color: #9097a2;
    font-size: 9.5px;
    line-height: 1.4;
}

.km-status.scheduled {
    background: #eef5ff;
    color: #0d6efd;
}

.km-status.waiting {
    background: #fff6dc;
    color: #9a6a00;
}

.km-status.expired,
.km-status.off {
    background: #f1f3f5;
    color: #6c757d;
}

.km-status.danger {
    background: #fff0f2;
    color: #d70018;
}

.km-status.out-of-stock {
    border: 1px solid #f0c36d;
    background: #fff6dc;
    color: #9a6500;
}

.km-stock-warning {
    display: inline-flex;
    align-items: center;
    margin-top: 7px;
    padding: 4px 8px;
    border-radius: 999px;
    background: #fff6dc;
    color: #9a6500;
    font-size: 10.5px;
    font-weight: 900;
}

.km-btn:disabled {
    border-color: #d9dde3;
    background: #eef0f3;
    color: #8a919b;
    cursor: not-allowed;
    opacity: 1;
}

.km-time-box {
    min-width: 150px;
}

.km-time-window {
    display: inline-flex;
    margin-top: 5px;
    padding: 4px 7px;
    border-radius: 8px;
    background: #fff6dc;
    color: #865c00;
    font-size: 9.5px;
    font-weight: 850;
}

.km-guide-box {
    margin: 13px 18px 0;
    padding: 12px 14px;
    border: 1px solid #dfe7f5;
    border-radius: 12px;
    background: #f8fbff;
    color: #5f6875;
    font-size: 11px;
    line-height: 1.6;
}

.km-guide-box strong {
    color: #27364a;
}

@media (max-width: 767px) {
    .km-head {
        align-items: flex-start;
        flex-direction: column;
    }

    .km-tools {
        flex-direction: column;
    }

    .km-summary {
        grid-template-columns: repeat(2, minmax(0,1fr));
    }
}

@media (max-width: 480px) {
    .km-summary {
        grid-template-columns: 1fr;
    }
}

.km-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 13.5px;
}

.km-table th,
.km-table td {
    padding: 14px 14px;
    border-top: 1px solid #e9edf2;
    vertical-align: top;
}

.km-table th {
    background: #f7f8fa;
    color: #394150;
    font-size: 12.5px;
    font-weight: 900;
    white-space: nowrap;
}

.km-table tbody tr {
    transition: .18s ease;
}

.km-table tbody tr:hover {
    background: #fffafa;
}

.km-row-active {
    background: #fffafb;
}

.km-row-active td:first-child {
    box-shadow: inset 4px 0 0 #d70018;
}

.km-type {
    font-size: 14px;
    font-weight: 950;
    color: #1f2937;
}

.km-event {
    display: inline-flex;
    align-items: center;
    margin-top: 7px;
    padding: 4px 8px;
    border-radius: 999px;
    background: #f3edff;
    color: #6f2bd9;
    font-size: 11px;
    font-weight: 900;
}

.km-scope {
    display: inline-flex;
    margin-top: 7px;
    padding: 4px 8px;
    border-radius: 999px;
    background: #eef5ff;
    color: #0d6efd;
    font-size: 11px;
    font-weight: 900;
}

.km-muted {
    margin-top: 5px;
    color: #68707c;
    font-size: 11.5px;
    line-height: 1.45;
}

.km-discount-box {
    min-width: 105px;
    display: inline-flex;
    flex-direction: column;
    gap: 4px;
    padding: 9px 11px;
    border: 1px solid #ffd3d9;
    border-radius: 11px;
    background: #fff5f6;
}

.km-discount-value {
    color: #d70018;
    font-size: 18px;
    line-height: 1;
    font-weight: 950;
}

.km-discount-cap {
    color: #7a4b51;
    font-size: 10.5px;
    font-weight: 800;
}

.km-tags {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    max-width: 260px;
}

.km-tag {
    padding: 5px 8px;
    border: 1px solid #e1e6ed;
    border-radius: 9px;
    background: #f8fafc;
    color: #3f4753;
    font-size: 11px;
    line-height: 1.35;
    font-weight: 800;
}

.km-condition-important .km-tag {
    border-color: #f2cf8f;
    background: #fff9eb;
    color: #795300;
}

.km-time-box {
    min-width: 180px;
    padding: 9px 10px;
    border: 1px solid #d8e4f5;
    border-radius: 11px;
    background: #f7faff;
}

.km-time-main {
    color: #1f4f87;
    font-size: 12.5px;
    line-height: 1.45;
    font-weight: 900;
}

.km-time-end {
    margin-top: 4px;
    color: #546171;
    font-size: 11.5px;
    font-weight: 800;
}

.km-time-window {
    display: inline-flex;
    margin-top: 7px;
    padding: 5px 8px;
    border: 1px solid #f1d38a;
    border-radius: 8px;
    background: #fff6dc;
    color: #865c00;
    font-size: 11px;
    font-weight: 900;
}

.km-status {
    display: inline-flex;
    align-items: center;
    padding: 6px 9px;
    border-radius: 999px;
    background: #eef0f3;
    color: #676d77;
    font-size: 11px;
    font-weight: 900;
}

.km-status.on {
    border: 1px solid #bfe7cc;
    background: #eaf8ef;
    color: #146c43;
    box-shadow: 0 0 0 3px rgba(25,135,84,.05);
}

.km-status-note {
    max-width: 180px;
    margin-top: 7px;
    color: #5d6672;
    font-size: 11px;
    line-height: 1.4;
    font-weight: 700;
}

.km-actions .km-btn {
    min-height: 36px;
    font-size: 11.5px;
}

.km-summary-label {
    color: #687180;
    font-size: 11.5px;
    font-weight: 900;
}

.km-summary-note {
    margin-top: 5px;
    color: #747d89;
    font-size: 10.5px;
    line-height: 1.4;
}

@media (max-width: 991px) {
    .km-table {
        font-size: 13px;
    }

    .km-table th,
    .km-table td {
        padding: 12px 10px;
    }
}

</style>

<div class="km-page">
    <div class="container">
        <section class="km-card">

            <div class="km-head">
                <div>
                    <h1>
                        🎯 Khuyến mãi
                    </h1>

                    <div class="km-sub">
                        Theo dõi chương trình đang chạy, sắp chạy và Flash sale theo khung giờ. Trạng thái thực tế được tính tự động theo lịch đã cấu hình.
                    </div>
                </div>

                <div style="display:flex;gap:8px;flex-wrap:wrap">
<a
                        class="km-btn primary"
                        href="/iPhoneStore/admin/khuyen_mai/them.php"
                    >
                        + Thêm khuyến mãi
                    </a>
                </div>
            </div>

            <div class="km-summary">
                <div class="km-summary-card">
                    <div class="km-summary-label">Tổng chương trình</div>
                    <div class="km-summary-value"><?= (int)$summary['total'] ?></div>
                    <div class="km-summary-note">Tất cả khuyến mãi trong hệ thống</div>
                </div>

                <div class="km-summary-card primary">
                    <div class="km-summary-label">Đang áp dụng</div>
                    <div class="km-summary-value"><?= (int)$summary['running'] ?></div>
                    <div class="km-summary-note">Đúng ngày và đúng khung giờ hiện tại</div>
                </div>

                <div class="km-summary-card warn">
                    <div class="km-summary-label">Chờ khung giờ</div>
                    <div class="km-summary-value"><?= (int)$summary['waiting'] ?></div>
                    <div class="km-summary-note">Đã tới ngày nhưng Flash sale chưa tới giờ</div>
                </div>

                <div class="km-summary-card info">
                    <div class="km-summary-label">Sắp diễn ra</div>
                    <div class="km-summary-value"><?= (int)$summary['scheduled'] ?></div>
                    <div class="km-summary-note">Đã bật lịch nhưng chưa tới ngày bắt đầu</div>
                </div>
            </div>
<?php if (!$schemaReady): ?>
                <div class="alert alert-warning km-warn">
                    <strong>Chưa nâng cấp database.</strong>
                    Hãy chạy migration khuyến mãi nâng cao.
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success m-3 mb-0">
                    <?= kmE($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger m-3 mb-0">
                    <?= kmE($error) ?>
                </div>
            <?php endif; ?>

            <form
                class="km-tools"
                method="get"
            >
                <input
                    class="form-control"
                    type="text"
                    name="q"
                    value="<?= kmE($q) ?>"
                    placeholder="Tìm sản phẩm, loại khuyến mãi, sự kiện..."
                >

                <button
                    class="km-btn"
                    type="submit"
                >
                    Tìm
                </button>

                <a
                    class="km-btn"
                    href="/iPhoneStore/admin/khuyen_mai/index.php"
                >
                    Xóa lọc
                </a>
            </form>

            <?php if ($schemaReady && !$promos): ?>

                <div class="p-5 text-center text-muted">
                    Chưa có khuyến mãi.
                </div>

            <?php elseif ($schemaReady): ?>

                <div class="km-table-wrap">
                    <table class="km-table">
                        <thead>
                            <tr>
                                <th>Loại / sự kiện</th>
                                <th>Sản phẩm / phạm vi</th>
                                <th>Mức giảm</th>
                                <th>Điều kiện</th>
                                <th>Thời gian</th>
                                <th>Trạng thái</th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($promos as $km): ?>
                                <?php
                                $hasVariant =
                                    !empty($km['bien_the_id']);

                                $currentStock =
                                    $hasVariant
                                        ? (int)($km['ton_bien_the'] ?? 0)
                                        : (int)($km['tong_ton_san_pham'] ?? 0);

                                $outOfStock =
                                    $currentStock <= 0;

                                $isClearance =
                                    (string)($km['loai_khuyen_mai'] ?? '') ===
                                    'xa_kho';

                                $clearanceThreshold =
                                    max(
                                        0,
                                        (int)($km['ton_kho_toi_da'] ?? 0)
                                    );

                                $clearanceStock =
                                    $hasVariant
                                        ? (int)($km['ton_bien_the'] ?? 0)
                                        : (
                                            !empty($km['co_bien_the'])
                                                ? (int)($km['ton_bien_the_cao_nhat'] ?? 0)
                                                : (int)($km['tong_ton_san_pham'] ?? 0)
                                        );

                                $clearanceBelowThreshold =
                                    $isClearance &&
                                    $clearanceThreshold > 0 &&
                                    $clearanceStock < $clearanceThreshold;

                                $state =
                                    kmEffectiveState($km);

                                if ($outOfStock) {
                                    $state = [
                                        'code' => 'out_of_stock',
                                        'active' => false,
                                        'class' => 'out-of-stock',
                                        'label' => 'Hết hàng',
                                        'note' =>
                                            $hasVariant
                                                ? 'Biến thể áp dụng đã hết hàng. Khuyến mãi tạm không có hiệu lực.'
                                                : 'Sản phẩm đã hết hàng. Khuyến mãi tạm không có hiệu lực.',
                                    ];
                                } elseif ($clearanceBelowThreshold) {
                                    $state = [
                                        'code' => 'below_clearance_threshold',
                                        'active' => false,
                                        'class' => 'waiting',
                                        'label' => 'Chưa đạt ngưỡng',
                                        'note' =>
                                            $hasVariant
                                                ? 'Biến thể hiện có ' .
                                                    $clearanceStock .
                                                    ', cần từ ' .
                                                    $clearanceThreshold .
                                                    ' để Xả kho.'
                                                : (
                                                    !empty($km['co_bien_the'])
                                                        ? 'Không có biến thể nào đạt ngưỡng Xả kho. Biến thể cao nhất hiện có ' .
                                                            $clearanceStock .
                                                            ', cần từ ' .
                                                            $clearanceThreshold .
                                                            '.'
                                                        : 'Tồn kho hiện có ' .
                                                            $clearanceStock .
                                                            ', cần từ ' .
                                                            $clearanceThreshold .
                                                            ' để Xả kho.'
                                                ),
                                    ];
                                }

                                $running =
                                    (bool)$state['active'];

                                $discount =
                                    $km['kieu_giam'] === 'phan_tram'
                                        ? (
                                            rtrim(
                                                rtrim(
                                                    number_format(
                                                        (float)$km['gia_tri'],
                                                        2,
                                                        '.',
                                                        ''
                                                    ),
                                                    '0'
                                                ),
                                                '.'
                                            ) .
                                            '%'
                                        )
                                        : (
                                            number_format(
                                                (float)$km['gia_tri'],
                                                0,
                                                ',',
                                                '.'
                                            ) .
                                            'đ'
                                        );

                                $conditions =
                                    kmConditionSummary($km);

                                $eventCode =
                                    (string)(
                                        $km['ma_su_kien']
                                        ?? ''
                                    );

                                $eventLabel =
                                    kmLoaiChoPhepSuKien(
                                        (string)$km['loai_khuyen_mai']
                                    ) &&
                                    $eventCode !== ''
                                        ? (
                                            $eventLabels[$eventCode]
                                            ?? $eventCode
                                        )
                                        : '';
                                ?>

                                <tr class="<?= $running ? 'km-row-active' : '' ?>">
                                    <td>
                                        <div class="km-type">
                                            <?= kmE(
                                                $typeLabels[
                                                    $km['loai_khuyen_mai']
                                                ]
                                                ?? $km['loai_khuyen_mai']
                                            ) ?>
                                        </div>

                                        <?php if ($running): ?>
                                            <div class="km-event" style="background:#eaf8ef;color:#146c43;">
                                                ● ĐANG CHẠY
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($eventLabel !== ''): ?>
                                            <div class="km-event">
                                                📅 <?= kmE($eventLabel) ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="km-muted">
                                                Không gắn sự kiện
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <strong>
                                            <?= kmE($km['ten_san_pham']) ?>
                                        </strong>

                                        <div class="km-scope">
                                            <?= empty($km['bien_the_id'])
                                                ? '📦 Toàn bộ sản phẩm'
                                                : '📱 Một biến thể' ?>
                                        </div>

                                        <?php if (!empty($km['bien_the_id'])): ?>
                                            <div class="km-muted">
                                                <?= kmE($km['rom'] ?? '') ?>
                                                <?= ($km['ram'] ?? '')
                                                    ? ' · ' . kmE($km['ram'])
                                                    : '' ?>
                                                <?= ($km['ten_mau'] ?? '')
                                                    ? ' · ' . kmE($km['ten_mau'])
                                                    : '' ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($outOfStock): ?>
                                            <div class="km-stock-warning">
                                                ⚠ Hết hàng
                                            </div>
                                        <?php elseif ($isClearance): ?>
                                            <div class="km-muted">
                                                Tồn xét Xả kho:
                                                <strong><?= $clearanceStock ?></strong>
                                                / ngưỡng <?= $clearanceThreshold ?>
                                            </div>

                                            <?php if (
                                                !$hasVariant &&
                                                !empty($km['co_bien_the'])
                                            ): ?>
                                                <div class="km-muted">
                                                    Xét biến thể có tồn cao nhất, không cộng tổng.
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div class="km-muted">
                                                Tồn kho: <?= $currentStock ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <div class="km-discount-box">
                                            <div class="km-discount-value">
                                                <?= kmE($discount) ?>
                                            </div>

                                            <?php if (
                                                (float)($km['gioi_han_giam'] ?? 0) > 0
                                            ): ?>
                                                <div class="km-discount-cap">
                                                    Tối đa <?= number_format(
                                                        (float)$km['gioi_han_giam'],
                                                        0,
                                                        ',',
                                                        '.'
                                                    ) ?>đ
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <td>
                                        <div class="km-tags <?= $conditions ? 'km-condition-important' : '' ?>">
                                            <?php if (!$conditions): ?>
                                                <span class="km-tag">
                                                    Không điều kiện thêm
                                                </span>
                                            <?php else: ?>
                                                <?php foreach ($conditions as $condition): ?>
                                                    <span class="km-tag">
                                                        <?= kmE($condition) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (!empty($km['ghi_chu_dieu_kien'])): ?>
                                            <div class="km-muted">
                                                <?= kmE($km['ghi_chu_dieu_kien']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <div class="km-time-box">
                                            <?php if (
                                                (string)$km['loai_khuyen_mai'] === 'flash_sale'
                                            ): ?>
                                                <div class="km-time-main">
                                                    <?= date(
                                                        'd/m/Y',
                                                        strtotime((string)$km['ngay_bat_dau'])
                                                    ) ?>
                                                    →
                                                    <?= date(
                                                        'd/m/Y',
                                                        strtotime((string)$km['ngay_ket_thuc'])
                                                    ) ?>
                                                </div>

                                                <?php if (
                                                    !empty($km['gio_bat_dau']) &&
                                                    !empty($km['gio_ket_thuc'])
                                                ): ?>
                                                    <div class="km-time-window">
                                                        ⚡ Mỗi ngày
                                                        <?= kmE(substr((string)$km['gio_bat_dau'], 0, 5)) ?>
                                                        →
                                                        <?= kmE(substr((string)$km['gio_ket_thuc'], 0, 5)) ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <div class="km-time-main">
                                                    Bắt đầu:
                                                    <?= date(
                                                        'd/m/Y H:i',
                                                        strtotime((string)$km['ngay_bat_dau'])
                                                    ) ?>
                                                </div>

                                                <div class="km-time-end">
                                                    Kết thúc:
                                                    <?= date(
                                                        'd/m/Y H:i',
                                                        strtotime((string)$km['ngay_ket_thuc'])
                                                    ) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <td>
                                        <span class="km-status <?= kmE($state['class']) ?>">
                                            <?= kmE($state['label']) ?>
                                        </span>

                                        <div class="km-status-note">
                                            <?= kmE($state['note']) ?>
                                        </div>

                                        <?php if ((int)$km['uu_tien'] > 0): ?>
                                            <div class="km-muted">
                                                Ưu tiên <?= (int)$km['uu_tien'] ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <div class="km-actions">
                                            <a
                                                class="km-btn"
                                                href="/iPhoneStore/admin/khuyen_mai/sua.php?id=<?= (int)$km['id'] ?>"
                                            >
                                                Sửa
                                            </a>

                                            <form method="post">
                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= kmE($csrf) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value="<?= (int)$km['id'] ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="toggle"
                                                >

                                                <button
                                                    class="km-btn"
                                                    type="submit"
                                                    <?= (
                                                        (
                                                            $outOfStock ||
                                                            $clearanceBelowThreshold
                                                        ) &&
                                                        (int)$km['trang_thai'] !== 1
                                                    )
                                                        ? 'disabled'
                                                        : '' ?>
                                                    title="<?= (
                                                        (
                                                            $outOfStock ||
                                                            $clearanceBelowThreshold
                                                        ) &&
                                                        (int)$km['trang_thai'] !== 1
                                                    )
                                                        ? (
                                                            $outOfStock
                                                                ? 'Hết hàng nên không thể bật khuyến mãi'
                                                                : 'Chưa đạt ngưỡng tồn kho Xả kho'
                                                        )
                                                        : '' ?>"
                                                >
                                                    <?php if (
                                                        (
                                                            $outOfStock ||
                                                            $clearanceBelowThreshold
                                                        ) &&
                                                        (int)$km['trang_thai'] !== 1
                                                    ): ?>
                                                        <?= $outOfStock
                                                            ? 'Hết hàng'
                                                            : 'Chưa đạt ngưỡng' ?>
                                                    <?php else: ?>
                                                        <?= (int)$km['trang_thai'] === 1
                                                            ? 'Tắt'
                                                            : 'Bật' ?>
                                                    <?php endif; ?>
                                                </button>
                                            </form>

                                            <form
                                                method="post"
                                                onsubmit="return confirm('Xóa khuyến mãi này?');"
                                            >
                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= kmE($csrf) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value="<?= (int)$km['id'] ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="delete"
                                                >

                                                <button
                                                    class="km-btn danger"
                                                    type="submit"
                                                >
                                                    Xóa
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>

                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php endif; ?>

        </section>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
