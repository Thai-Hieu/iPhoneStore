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

$mode = 'edit';
$csrf = kmCsrf();
$schemaReady = kmSchemaReady($conn);

$id =
    $mode === 'edit'
        ? (int)(
            $_GET['id']
            ?? $_POST['id']
            ?? 0
        )
        : 0;

$products = [];

$rs = $conn->query("
        SELECT
            sp.id,
            sp.ten_san_pham,
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
            END AS tong_ton_kho,

            COALESCE((
                SELECT MAX(bt_max.so_luong)
                FROM san_pham_bien_the bt_max
                WHERE bt_max.san_pham_id = sp.id
            ), 0) AS ton_bien_the_cao_nhat,

            EXISTS (
                SELECT 1
                FROM san_pham_bien_the bt_kind
                WHERE bt_kind.san_pham_id = sp.id
            ) AS co_bien_the

        FROM san_pham sp
        WHERE sp.trang_thai = 1
        ORDER BY
            CASE WHEN (CASE
                WHEN EXISTS (
                    SELECT 1
                    FROM san_pham_bien_the bt_exists
                    WHERE bt_exists.san_pham_id = sp.id
                )
                THEN COALESCE((
                    SELECT SUM(bt_sort.so_luong)
                    FROM san_pham_bien_the bt_sort
                    WHERE bt_sort.san_pham_id = sp.id
                ), 0)
                ELSE COALESCE(sp.so_luong, 0)
            END) <= 0 THEN 1 ELSE 0 END,
            sp.ten_san_pham
    ");

if ($rs) {
    $products =
        $rs->fetch_all(
            MYSQLI_ASSOC
        );
}

$defaults = [
    'san_pham_id' => 0,
    'bien_the_id' => 0,
    'pham_vi' => 'toan_san_pham',
    'loai_khuyen_mai' => 'giam_truc_tiep',
    'kieu_giam' => 'phan_tram',
    'gia_tri' => '',
    'ngay_bat_dau' =>
        date('Y-m-d\TH:i'),
    'ngay_ket_thuc' =>
        date(
            'Y-m-d\TH:i',
            strtotime('+7 days')
        ),
    'trang_thai' => 1,
    'so_luong_mua_toi_thieu' => 1,
    'ton_kho_toi_da' => '',
    'doi_tuong_khach' => 'tat_ca',
    'don_hang_toi_thieu' => 0,
    'chi_tieu_toi_thieu' => 0,
    'gio_bat_dau' => '',
    'gio_ket_thuc' => '',
    'gioi_han_giam' => '',
    'uu_tien' => 0,
    'ma_su_kien' => '',
    'ghi_chu_dieu_kien' => '',
];

if ($mode === 'edit') {
    if ($id <= 0) {
        kmGo(
            '/iPhoneStore/admin/khuyen_mai/index.php'
        );
    }

    $stmt =
        $conn->prepare("
            SELECT *
            FROM khuyen_mai
            WHERE id = ?
            LIMIT 1
        ");

    $stmt->bind_param(
        'i',
        $id
    );

    $stmt->execute();

    $existing =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();

    if (!$existing) {
        $_SESSION['km_error'] =
            'Không tìm thấy khuyến mãi.';

        kmGo(
            '/iPhoneStore/admin/khuyen_mai/index.php'
        );
    }

    $form =
        array_merge(
            $defaults,
            $existing
        );

    $form['pham_vi'] =
        !empty($form['bien_the_id'])
            ? 'bien_the'
            : 'toan_san_pham';
} else {
    $form = $defaults;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form =
        array_merge(
            $form,
            $_POST
        );

    if (
        !kmCsrfOk(
            $_POST['csrf_token']
            ?? null
        )
    ) {
        $errors[] =
            'Phiên thao tác không hợp lệ.';
    } else {
        [
            $errors,
            $d,
        ] =
            kmValidate(
                $conn,
                $_POST
            );

        if (!$errors) {
            $variantId =
                $d['bien_the_id'] > 0
                    ? $d['bien_the_id']
                    : null;

            $stockThreshold =
                $d['ton_kho_toi_da'];

            $cap =
                $d['gioi_han_giam'];

            $startTime =
                $d['gio_bat_dau'] !== ''
                    ? $d['gio_bat_dau'] . ':00'
                    : null;

            $endTime =
                $d['gio_ket_thuc'] !== ''
                    ? $d['gio_ket_thuc'] . ':00'
                    : null;

            $event =
                $d['ma_su_kien'] !== ''
                    ? $d['ma_su_kien']
                    : null;

            $note =
                $d['ghi_chu_dieu_kien'] !== ''
                    ? $d['ghi_chu_dieu_kien']
                    : null;

            if ($mode === 'edit') {
                $stmt =
                    $conn->prepare("
                        UPDATE khuyen_mai
                        SET
                            san_pham_id = ?,
                            bien_the_id = ?,
                            ten_khuyen_mai = ?,
                            ly_do = ?,
                            loai_khuyen_mai = ?,
                            kieu_giam = ?,
                            gia_tri = ?,
                            ngay_bat_dau = ?,
                            ngay_ket_thuc = ?,
                            trang_thai = ?,
                            so_luong_mua_toi_thieu = ?,
                            ton_kho_toi_da = ?,
                            doi_tuong_khach = ?,
                            don_hang_toi_thieu = ?,
                            chi_tieu_toi_thieu = ?,
                            gio_bat_dau = ?,
                            gio_ket_thuc = ?,
                            gioi_han_giam = ?,
                            uu_tien = ?,
                            ma_su_kien = ?,
                            ghi_chu_dieu_kien = ?
                        WHERE id = ?
                        LIMIT 1
                    ");

                $stmt->bind_param(
                    'iissssdssiiisidssdissi',
                    $d['san_pham_id'],
                    $variantId,
                    $d['ten_khuyen_mai'],
                    $d['ly_do'],
                    $d['loai_khuyen_mai'],
                    $d['kieu_giam'],
                    $d['gia_tri'],
                    $d['ngay_bat_dau'],
                    $d['ngay_ket_thuc'],
                    $d['trang_thai'],
                    $d['so_luong_mua_toi_thieu'],
                    $stockThreshold,
                    $d['doi_tuong_khach'],
                    $d['don_hang_toi_thieu'],
                    $d['chi_tieu_toi_thieu'],
                    $startTime,
                    $endTime,
                    $cap,
                    $d['uu_tien'],
                    $event,
                    $note,
                    $id
                );

                $stmt->execute();
                $stmt->close();

                $_SESSION['km_success'] =
                    'Cập nhật khuyến mãi thành công.';
            } else {
                $stmt =
                    $conn->prepare("
                        INSERT INTO khuyen_mai (
                            san_pham_id,
                            bien_the_id,
                            ten_khuyen_mai,
                            ly_do,
                            loai_khuyen_mai,
                            kieu_giam,
                            gia_tri,
                            ngay_bat_dau,
                            ngay_ket_thuc,
                            trang_thai,
                            so_luong_mua_toi_thieu,
                            ton_kho_toi_da,
                            doi_tuong_khach,
                            don_hang_toi_thieu,
                            chi_tieu_toi_thieu,
                            gio_bat_dau,
                            gio_ket_thuc,
                            gioi_han_giam,
                            uu_tien,
                            ma_su_kien,
                            ghi_chu_dieu_kien
                        )
                        VALUES (
                            ?,?,?,?,?,?,?,?,?,?,
                            ?,?,?,?,?,?,?,?,?,?,?
                        )
                    ");

                $stmt->bind_param(
                    'iissssdssiiisidssdiss',
                    $d['san_pham_id'],
                    $variantId,
                    $d['ten_khuyen_mai'],
                    $d['ly_do'],
                    $d['loai_khuyen_mai'],
                    $d['kieu_giam'],
                    $d['gia_tri'],
                    $d['ngay_bat_dau'],
                    $d['ngay_ket_thuc'],
                    $d['trang_thai'],
                    $d['so_luong_mua_toi_thieu'],
                    $stockThreshold,
                    $d['doi_tuong_khach'],
                    $d['don_hang_toi_thieu'],
                    $d['chi_tieu_toi_thieu'],
                    $startTime,
                    $endTime,
                    $cap,
                    $d['uu_tien'],
                    $event,
                    $note
                );

                $stmt->execute();
                $stmt->close();

                $_SESSION['km_success'] =
                    'Thêm khuyến mãi thành công.';
            }

            kmGo(
                '/iPhoneStore/admin/khuyen_mai/index.php'
            );
        }
    }
}

$loaiLabels = kmLoai();
$audienceLabels = kmDoiTuong();
$eventGroups = kmSuKienNhom();
$fixedEventDates = kmNgaySuKienCoDinh();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
?>

<style>
.kmf-page {
    padding: 28px 0 48px;
    background: #f6f7f9;
    min-height: 75vh;
}

.kmf-card {
    width: min(1040px, 100%);
    margin: 0 auto;
    background: #fff;
    border: 1px solid #e7e9ee;
    border-radius: 18px;
    box-shadow: 0 10px 30px rgba(15,23,42,.05);
    overflow: hidden;
}

.kmf-head {
    padding: 20px 22px;
    border-bottom: 1px solid #eceef2;
}

.kmf-head h1 {
    margin: 0;
    font-size: 21px;
    font-weight: 900;
}

.kmf-head p {
    margin: 5px 0 0;
    color: #7d8591;
    font-size: 12px;
}

.kmf-body {
    padding: 22px;
}

.kmf-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0,1fr));
    gap: 16px;
}

.kmf-full {
    grid-column: 1 / -1;
}

.kmf-section {
    grid-column: 1 / -1;
    margin-top: 8px;
    padding: 14px 15px;
    border: 1px solid #e4e8ee;
    border-left: 4px solid #d70018;
    border-radius: 12px;
    background: linear-gradient(180deg,#fff,#fbfcfd);
}

.kmf-section-title {
    font-size: 15px;
    font-weight: 900;
    color: #262c35;
}

.kmf-label {
    display: block;
    margin-bottom: 7px;
    color: #414852;
    font-size: 12px;
    font-weight: 850;
}

.scope-options {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 9px;
}

.scope-option {
    position: relative;
}

.scope-option input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.scope-option label {
    min-height: 66px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 12px;
    border: 1px solid #dde2e8;
    border-radius: 12px;
    cursor: pointer;
    transition: .15s ease;
}

.scope-option input:checked + label {
    border-color: #d70018;
    background: #fff7f8;
    box-shadow: 0 0 0 3px rgba(215,0,24,.05);
}

.scope-icon {
    width: 34px;
    height: 34px;
    display: grid;
    place-items: center;
    flex: 0 0 34px;
    border-radius: 10px;
    background: #f4f6f8;
}

.scope-option strong {
    display: block;
    font-size: 11px;
}

.scope-option small {
    display: block;
    margin-top: 2px;
    color: #858c97;
    font-size: 9.5px;
}

.kmf-flash-guide {
    grid-column: 1 / -1;
    padding: 13px 15px;
    border: 1px solid #ffe09a;
    border-radius: 12px;
    background: #fffaf0;
    color: #5f5848;
    font-size: 11px;
    line-height: 1.65;
}
.kmf-flash-guide strong { color:#a15c00; }
.kmf-schedule-preview {
    margin-top: 7px;
    padding: 8px 10px;
    border-radius: 9px;
    background: #fff;
    border: 1px dashed #efc76f;
    font-weight: 800;
    color: #7a4d00;
}

.kmf-info {
    padding: 11px 13px;
    border: 1px solid #dfe8f6;
    border-radius: 11px;
    background: #f7faff;
    color: #586271;
    font-size: 11px;
    line-height: 1.55;
}

.event-help {
    margin-top: 6px;
    color: #8b929d;
    font-size: 10px;
}

.kmf-advanced {
    grid-column: 1 / -1;
    border: 1px solid #e7e9ee;
    border-radius: 13px;
    background: #fbfcfd;
}

.kmf-advanced summary {
    padding: 12px 14px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 850;
}

.kmf-advanced-body {
    display: grid;
    grid-template-columns: repeat(2, minmax(0,1fr));
    gap: 15px;
    padding: 4px 14px 15px;
}

[data-condition] {
    display: none;
}

[data-condition].show {
    display: block;
}

.kmf-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 22px;
    padding-top: 18px;
    border-top: 1px solid #eceef2;
}

.kmf-btn {
    min-height: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 15px;
    border: 1px solid #dde1e7;
    border-radius: 10px;
    background: #fff;
    color: #39414c;
    font-size: 12px;
    font-weight: 850;
    text-decoration: none;
    cursor: pointer;
}

.kmf-btn.primary {
    border-color: #d70018;
    background: #d70018;
    color: #fff;
}

.kmf-btn.primary:disabled {
    border-color: #cbd0d8;
    background: #cbd0d8;
    color: #68707c;
    cursor: not-allowed;
    opacity: 1;
}

@media (max-width: 767px) {
    .kmf-grid,
    .kmf-advanced-body,
    .scope-options {
        grid-template-columns: 1fr;
    }

    .kmf-full,
    .kmf-section,
    .kmf-advanced {
        grid-column: auto;
    }

    .kmf-body {
        padding: 16px;
    }
}
</style>

<div class="kmf-page">
    <div class="container">
        <section class="kmf-card">

            <div class="kmf-head">
                <h1>
                    <?= $mode === 'edit'
                        ? '✏️ Sửa khuyến mãi'
                        : '➕ Thêm khuyến mãi' ?>
                </h1>

                <p>
                    Tạo chương trình theo 5 bước: chọn sản phẩm → chọn kiểu khuyến mãi → nhập mức giảm → đặt điều kiện → đặt lịch chạy.
                    Với Flash sale, hệ thống tự áp dụng đúng khung giờ mỗi ngày và tự ngừng ngoài khung giờ.
                </p>
            </div>
<div class="kmf-body">

                <?php if (!$schemaReady): ?>
                    <div class="alert alert-warning">
                        <strong>Chưa nâng cấp database.</strong>
                        Hãy chạy migration khuyến mãi nâng cao trước.
                    </div>
                <?php endif; ?>

                <?php if ($errors): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($errors as $message): ?>
                            <div>
                                <?= kmE($message) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php
                $selectedProductStock = null;

                foreach ($products as $productRow) {
                    if (
                        (int)$productRow['id']
                        === (int)($form['san_pham_id'] ?? 0)
                    ) {
                        $selectedProductStock =
                            (int)($productRow['tong_ton_kho'] ?? 0);

                        break;
                    }
                }

                $selectedProductOutOfStock =
                    $selectedProductStock !== null &&
                    $selectedProductStock <= 0;
                ?>

                <?php if ($selectedProductOutOfStock): ?>
                    <div class="alert alert-warning">
                        <strong>⚠️ Sản phẩm của khuyến mãi này đã hết hàng.</strong>
                        Bạn có thể xem thông tin chương trình hiện tại,
                        nhưng hệ thống sẽ không cho lưu/kích hoạt lại cho đến khi sản phẩm có hàng.
                        Hãy nhập thêm tồn kho tại
                        <a href="/iPhoneStore/admin/san_pham/index.php">
                            Quản lý sản phẩm
                        </a>
                        trước.
                    </div>
                <?php endif; ?>

                <form method="post">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= kmE($csrf) ?>"
                    >

                    <?php if ($mode === 'edit'): ?>
                        <input
                            type="hidden"
                            name="id"
                            value="<?= $id ?>"
                        >
                    <?php endif; ?>

                    <div class="kmf-grid">

                        <div class="kmf-section">
                            <div class="kmf-section-title">
                                1. Sản phẩm áp dụng
                            </div>
</div>

                        <div>
                            <label class="kmf-label">
                                Sản phẩm
                            </label>

                            <div class="event-help" style="margin-bottom:7px">
                                Sản phẩm hết hàng không thể tiếp tục áp dụng khuyến mãi.
                            </div>

                            <select
                                id="san_pham_id"
                                name="san_pham_id"
                                class="form-select"
                                required
                            >
                                <option value="">
                                    -- Chọn sản phẩm --
                                </option>

                                <?php foreach ($products as $product): ?>
                                    <?php
                                    $productStock =
                                        (int)($product['tong_ton_kho'] ?? 0);

                                    $productOutOfStock =
                                        $productStock <= 0;

                                    $productSelected =
                                        (int)($form['san_pham_id'] ?? 0)
                                        === (int)$product['id'];
                                    ?>

                                    <option
                                        value="<?= (int)$product['id'] ?>"
                                        data-total-stock="<?= (int)($product['tong_ton_kho'] ?? 0) ?>"
                                        data-max-variant-stock="<?= (int)($product['ton_bien_the_cao_nhat'] ?? 0) ?>"
                                        data-has-variants="<?= !empty($product['co_bien_the']) ? '1' : '0' ?>"
                                        <?= $productSelected
                                            ? 'selected'
                                            : '' ?>
                                        <?= (
                                            $productOutOfStock &&
                                            !$productSelected
                                        )
                                            ? 'disabled'
                                            : '' ?>
                                    >
                                        <?= kmE($product['ten_san_pham']) ?>
                                        <?= $productOutOfStock
                                            ? ' — HẾT HÀNG'
                                            : ' — Còn ' . $productStock ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="kmf-label">
                                Áp dụng cho
                            </label>

                            <div class="scope-options">
                                <div class="scope-option">
                                    <input
                                        id="scopeAll"
                                        type="radio"
                                        name="pham_vi"
                                        value="toan_san_pham"
                                        <?= ($form['pham_vi'] ?? 'toan_san_pham') === 'toan_san_pham'
                                            ? 'checked'
                                            : '' ?>
                                    >

                                    <label for="scopeAll">
                                        <span class="scope-icon">📦</span>

                                        <span>
                                            <strong>Toàn sản phẩm</strong>
                                            <small>Mọi biến thể đều được áp dụng</small>
                                        </span>
                                    </label>
                                </div>

                                <div class="scope-option">
                                    <input
                                        id="scopeVariant"
                                        type="radio"
                                        name="pham_vi"
                                        value="bien_the"
                                        <?= ($form['pham_vi'] ?? '') === 'bien_the'
                                            ? 'checked'
                                            : '' ?>
                                    >

                                    <label for="scopeVariant">
                                        <span class="scope-icon">📱</span>

                                        <span>
                                            <strong>Một biến thể</strong>
                                            <small>Chỉ ROM / RAM / màu đã chọn</small>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div
                            id="variantBox"
                            class="kmf-full"
                        >
                            <label class="kmf-label">
                                Biến thể được áp dụng
                            </label>

                            <select
                                id="bien_the_id"
                                name="bien_the_id"
                                class="form-select"
                            >
                                <option value="">
                                    -- Chọn biến thể --
                                </option>
                            </select>
</div>

                        <div class="kmf-section">
                            <div class="kmf-section-title">
                                2. Loại khuyến mãi
                            </div>
</div>

                        <div>
                            <label class="kmf-label">
                                Loại khuyến mãi
                            </label>

                            <select
                                id="loai_khuyen_mai"
                                class="form-select"
                                name="loai_khuyen_mai"
                                required
                            >
                                <?php foreach ($loaiLabels as $value => $label): ?>
                                    <option
                                        value="<?= kmE($value) ?>"
                                        <?= ($form['loai_khuyen_mai'] ?? '') === $value
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= kmE($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div id="eventBox">
                            <label class="kmf-label">
                                Sự kiện / dịp áp dụng
                                <span class="text-muted fw-normal">(tùy chọn)</span>
                            </label>

                            <select
                                id="ma_su_kien"
                                class="form-select"
                                name="ma_su_kien"
                            >
                                <?php foreach ($eventGroups as $group => $events): ?>
                                    <optgroup label="<?= kmE($group) ?>">
                                        <?php foreach ($events as $value => $label): ?>
                                            <option
                                                value="<?= kmE($value) ?>"
                                                <?= ($form['ma_su_kien'] ?? '') === $value
                                                    ? 'selected'
                                                    : '' ?>
                                            >
                                                <?= kmE($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <div id="eventHelp" class="event-help" style="display:none"></div>
</div>

                        <div class="kmf-section">
                            <div class="kmf-section-title">
                                3. Mức giảm
                            </div>
                        </div>

                        <div>
                            <label class="kmf-label">
                                Kiểu giảm
                            </label>

                            <select
                                id="kieu_giam"
                                class="form-select"
                                name="kieu_giam"
                                required
                            >
                                <option
                                    value="phan_tram"
                                    <?= ($form['kieu_giam'] ?? '') === 'phan_tram'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Phần trăm (%)
                                </option>

                                <option
                                    value="so_tien"
                                    <?= ($form['kieu_giam'] ?? '') === 'so_tien'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Số tiền (VNĐ)
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="kmf-label">
                                Giá trị giảm
                            </label>

                            <input
                                class="form-control"
                                type="number"
                                name="gia_tri"
                                min="0.01"
                                step="0.01"
                                required
                                value="<?= kmE($form['gia_tri'] ?? '') ?>"
                            >
                        </div>
<div class="kmf-section">
                            <div class="kmf-section-title">
                                4. Điều kiện áp dụng
                            </div>
</div>

                        <div data-condition="mua_nhieu">
                            <label class="kmf-label">
                                Mua tối thiểu bao nhiêu sản phẩm
                            </label>

                            <input
                                class="form-control"
                                type="number"
                                name="so_luong_mua_toi_thieu"
                                min="1"
                                value="<?= kmE($form['so_luong_mua_toi_thieu'] ?? 1) ?>"
                            >
                        </div>

                        <div data-condition="xa_kho">
                            <label class="kmf-label">
                                Xả kho khi tồn kho từ
                            </label>

                            <div class="event-help" style="margin-bottom:7px">
                                Nếu áp dụng toàn sản phẩm có biến thể,
                                hệ thống xét tồn kho của từng biến thể riêng lẻ,
                                không cộng tổng các biến thể.
                            </div>

                            <div class="input-group">
                                <input
                                    class="form-control"
                                    id="ton_kho_toi_da"
                                    type="number"
                                    name="ton_kho_toi_da"
                                    min="1"
                                    step="1"
                                    value="<?= kmE($form['ton_kho_toi_da'] ?? '') ?>"
                                    placeholder="Ví dụ: 20"
                                >

                                <span class="input-group-text">
                                    sản phẩm trở lên
                                </span>
                            </div>
</div>

                        <div data-condition="audience">
                            <label class="kmf-label">
                                Đối tượng khách hàng
                            </label>

                            <select
                                id="doi_tuong_khach"
                                class="form-select"
                                name="doi_tuong_khach"
                            >
                                <?php foreach ($audienceLabels as $value => $label): ?>
                                    <option
                                        value="<?= kmE($value) ?>"
                                        <?= ($form['doi_tuong_khach'] ?? 'tat_ca') === $value
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= kmE($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div data-condition="loyalty">
                            <label class="kmf-label">
                                Số đơn hoàn thành tối thiểu
                            </label>

                            <input
                                class="form-control"
                                type="number"
                                name="don_hang_toi_thieu"
                                min="0"
                                value="<?= kmE($form['don_hang_toi_thieu'] ?? 0) ?>"
                            >
                        </div>

                        <div data-condition="loyalty">
                            <label class="kmf-label">
                                Tổng chi tiêu tối thiểu
                            </label>

                            <input
                                class="form-control"
                                type="number"
                                name="chi_tieu_toi_thieu"
                                min="0"
                                step="1000"
                                value="<?= kmE($form['chi_tieu_toi_thieu'] ?? 0) ?>"
                            >
                        </div>

                        <div class="kmf-section">
                            <div class="kmf-section-title">
                                5. Thời gian áp dụng
                            </div>
</div>

                        <div
                            id="flashScheduleGuide"
                            class="kmf-flash-guide"
                            style="display:none"
                        >
                            <div id="flashSchedulePreview" class="kmf-schedule-preview">
                                Chưa chọn lịch Flash sale.
                            </div>
                        </div>

                        <div>
                            <label class="kmf-label" id="startDateLabel">
                                Bắt đầu
                            </label>

                            <input
                                id="ngay_bat_dau"
                                class="form-control"
                                type="datetime-local"
                                name="ngay_bat_dau"
                                required
                                value="<?= kmE(
                                    substr(
                                        str_replace(
                                            ' ',
                                            'T',
                                            (string)($form['ngay_bat_dau'] ?? '')
                                        ),
                                        0,
                                        16
                                    )
                                ) ?>"
                            >
                        </div>

                        <div>
                            <label class="kmf-label" id="endDateLabel">
                                Kết thúc
                            </label>

                            <input
                                id="ngay_ket_thuc"
                                class="form-control"
                                type="datetime-local"
                                name="ngay_ket_thuc"
                                required
                                value="<?= kmE(
                                    substr(
                                        str_replace(
                                            ' ',
                                            'T',
                                            (string)($form['ngay_ket_thuc'] ?? '')
                                        ),
                                        0,
                                        16
                                    )
                                ) ?>"
                            >
                        </div>

                        <div data-condition="flash">
                            <label class="kmf-label">
                                Giờ bắt đầu sale mỗi ngày
                            </label>

                            <input
                                class="form-control"
                                type="time"
                                name="gio_bat_dau"
                                value="<?= kmE(
                                    substr(
                                        (string)($form['gio_bat_dau'] ?? ''),
                                        0,
                                        5
                                    )
                                ) ?>"
                            >
                        </div>

                        <div data-condition="flash">
                            <label class="kmf-label">
                                Giờ kết thúc sale mỗi ngày
                            </label>

                            <input
                                class="form-control"
                                type="time"
                                name="gio_ket_thuc"
                                value="<?= kmE(
                                    substr(
                                        (string)($form['gio_ket_thuc'] ?? ''),
                                        0,
                                        5
                                    )
                                ) ?>"
                            >
                        </div>

                        <details class="kmf-advanced">
                            <summary>
                                ⚙️ Cài đặt nâng cao
                            </summary>

                            <div class="kmf-advanced-body">
                                <div
                                                            id="capBox"

                                                        >
                                                            <label class="kmf-label">
                                                                Giảm tối đa
                                                            </label>

                                                            <input
                                                                class="form-control"
                                                                type="number"
                                                                name="gioi_han_giam"
                                                                min="0"
                                                                step="1000"
                                                                value="<?= kmE($form['gioi_han_giam'] ?? '') ?>"
                                                                placeholder="Ví dụ: 2.000.000"
                                                            >
                                </div>

                                <div>
                                    <label class="kmf-label">
                                        Độ ưu tiên
                                    </label>

                                    <input
                                        class="form-control"
                                        type="number"
                                        name="uu_tien"
                                        min="0"
                                        max="999"
                                        value="<?= kmE($form['uu_tien'] ?? 0) ?>"
                                    >
</div>

                                <div>
                                    <label class="kmf-label">
                                        Trạng thái
                                    </label>

                                    <div class="form-check form-switch mt-2">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            name="trang_thai"
                                            value="1"
                                            <?= !empty($form['trang_thai'])
                                                ? 'checked'
                                                : '' ?>
                                        >

                                        <label class="form-check-label">
                                            Kích hoạt chương trình
                                        </label>
                                    </div>
                                </div>

                                <div class="kmf-full">
                                    <label class="kmf-label">
                                        Ghi chú điều kiện
                                        <span class="text-muted fw-normal">
                                            (không bắt buộc)
                                        </span>
                                    </label>

                                    <textarea
                                        class="form-control"
                                        name="ghi_chu_dieu_kien"
                                        maxlength="500"
                                        rows="2"
                                        placeholder="Ghi chú nội bộ cho chương trình..."
                                    ><?= kmE($form['ghi_chu_dieu_kien'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </details>

                    </div>

                    <div class="kmf-actions">
                        <a
                            class="kmf-btn"
                            href="/iPhoneStore/admin/khuyen_mai/index.php"
                        >
                            Hủy
                        </a>

                        <button
                            id="savePromotionButton"
                            class="kmf-btn primary"
                            type="submit"
                            <?= (
                                $schemaReady &&
                                !$selectedProductOutOfStock
                            )
                                ? ''
                                : 'disabled' ?>
                        >
                            <?php if (!$schemaReady): ?>
                                Chưa sẵn sàng
                            <?php elseif ($selectedProductOutOfStock): ?>
                                Hết hàng - không thể lưu
                            <?php else: ?>
                                Lưu thay đổi
                            <?php endif; ?>
                        </button>
                    </div>
                </form>

            </div>
        </section>
    </div>
</div>

<script>
(function () {
    const product =
        document.getElementById(
            'san_pham_id'
        );

    const variant =
        document.getElementById(
            'bien_the_id'
        );

    const variantBox =
        document.getElementById(
            'variantBox'
        );

    const scopeAll =
        document.getElementById(
            'scopeAll'
        );

    const scopeVariant =
        document.getElementById(
            'scopeVariant'
        );

    const selectedVariant =
        0;

    const initialVariant =
        <?= json_encode(
            (int)(
                $form['bien_the_id']
                ?? 0
            )
        ) ?>;

    const savePromotionButton =
        document.getElementById(
            'savePromotionButton'
        );

    const schemaReady =
        <?= $schemaReady ? 'true' : 'false' ?>;

    const productInitiallyOutOfStock =
        <?= $selectedProductOutOfStock ? 'true' : 'false' ?>;

    async function loadVariants(
        productId,
        keepSelected
    ) {
        variant.innerHTML =
            '<option value="">-- Chọn biến thể --</option>';

        if (!productId) {
            return;
        }

        const loading =
            document.createElement(
                'option'
            );

        loading.textContent =
            'Đang tải biến thể...';

        loading.disabled =
            true;

        variant.appendChild(
            loading
        );

        try {
            const response =
                await fetch(
                    '/iPhoneStore/admin/khuyen_mai/san_pham.php?san_pham_id=' +
                    encodeURIComponent(productId),
                    {
                        headers: {
                            'X-Requested-With':
                                'XMLHttpRequest'
                        }
                    }
                );

            const data =
                await response.json();

            loading.remove();

            if (
                data.product &&
                product.options[
                    product.selectedIndex
                ]
            ) {
                const selectedProductOption =
                    product.options[
                        product.selectedIndex
                    ];

                selectedProductOption.dataset.hasVariants =
                    data.product.has_variants
                        ? '1'
                        : '0';

                selectedProductOption.dataset.totalStock =
                    String(
                        Number(
                            data.product.total_stock || 0
                        )
                    );

                selectedProductOption.dataset.maxVariantStock =
                    String(
                        Number(
                            data.product.max_variant_stock || 0
                        )
                    );
            }

            if (
                !response.ok ||
                !data.success
            ) {
                throw new Error(
                    data.error ||
                    'Không tải được biến thể.'
                );
            }

            if (!data.variants.length) {
                const empty =
                    document.createElement(
                        'option'
                    );

                empty.disabled =
                    true;

                empty.textContent =
                    'Sản phẩm này chưa có biến thể';

                variant.appendChild(
                    empty
                );

                return;
            }

            data.variants.forEach(
                function (item) {
                    const option =
                        document.createElement(
                            'option'
                        );

                    option.value =
                        item.id;

                    option.textContent =
                        item.label;

                    const isOutOfStock =
                        Boolean(item.disabled) ||
                        Boolean(item.out_of_stock) ||
                        Number(item.stock || 0) <= 0;

                    /*
                        Trang SỬA:
                        - biến thể hết hàng vẫn hiện để biết chương trình cũ
                          đang áp dụng cho biến thể nào;
                        - nhưng option bị khóa, không cho chọn mới.
                    */
                    option.disabled =
                        isOutOfStock;

                    option.dataset.outOfStock =
                        isOutOfStock
                            ? '1'
                            : '0';

                    option.dataset.stock =
                        String(
                            Number(item.stock || 0)
                        );

                    if (
                        keepSelected &&
                        Number(item.id)
                            === Number(initialVariant)
                    ) {
                        option.selected =
                            true;
                    }

                    variant.appendChild(
                        option
                    );
                }
            );

            refreshSaveButton();
        } catch (error) {
            loading.textContent =
                'Không tải được biến thể';

            refreshSaveButton();
        }
    }

    function refreshSaveButton() {
        if (!savePromotionButton) {
            return;
        }

        if (!schemaReady) {
            savePromotionButton.disabled = true;
            savePromotionButton.textContent =
                'Chưa sẵn sàng';
            return;
        }

        const productOption =
            product.options[
                product.selectedIndex
            ] || null;

        const productOut =
            productInitiallyOutOfStock &&
            Number(product.value) ===
                Number(<?= json_encode((int)($form['san_pham_id'] ?? 0)) ?>);

        if (!product.value) {
            savePromotionButton.disabled = true;
            savePromotionButton.textContent =
                'Chọn sản phẩm để lưu';
            return;
        }

        if (
            productOut ||
            (
                productOption &&
                productOption.disabled
            )
        ) {
            savePromotionButton.disabled = true;
            savePromotionButton.textContent =
                'Sản phẩm hết hàng - không thể lưu';
            return;
        }

        if (scopeVariant.checked) {
            const selectedOption =
                variant.options[
                    variant.selectedIndex
                ] || null;

            if (!variant.value) {
                savePromotionButton.disabled = true;
                savePromotionButton.textContent =
                    'Chọn biến thể để lưu';
                return;
            }

            if (
                selectedOption &&
                (
                    selectedOption.disabled ||
                    selectedOption.dataset.outOfStock === '1'
                )
            ) {
                savePromotionButton.disabled = true;
                savePromotionButton.textContent =
                    'Biến thể hết hàng - không thể lưu';
                return;
            }
        }

        /*
            XẢ KHO:
            - theo biến thể: tồn của chính biến thể >= ngưỡng;
            - toàn sản phẩm có biến thể: MAX(tồn từng biến thể) >= ngưỡng;
            - không có biến thể: tồn sản phẩm >= ngưỡng.
        */
        const promotionType =
            document.getElementById(
                'loai_khuyen_mai'
            );

        const stockThresholdInput =
            document.getElementById(
                'ton_kho_toi_da'
            );

        const isClearance =
            promotionType &&
            promotionType.value === 'xa_kho';

        const threshold =
            stockThresholdInput
                ? Number(stockThresholdInput.value || 0)
                : 0;

        if (
            isClearance &&
            threshold > 0
        ) {
            if (scopeVariant.checked) {
                const selectedOption =
                    variant.options[
                        variant.selectedIndex
                    ] || null;

                const variantStock =
                    selectedOption
                        ? Number(
                            selectedOption.dataset.stock || 0
                        )
                        : 0;

                if (variantStock < threshold) {
                    savePromotionButton.disabled = true;
                    savePromotionButton.textContent =
                        'Biến thể chưa đạt ngưỡng Xả kho';
                    return;
                }
            } else if (productOption) {
                const hasVariants =
                    productOption.dataset.hasVariants === '1';

                const stockForClearance =
                    hasVariants
                        ? Number(
                            productOption.dataset.maxVariantStock || 0
                        )
                        : Number(
                            productOption.dataset.totalStock || 0
                        );

                if (stockForClearance < threshold) {
                    savePromotionButton.disabled = true;
                    savePromotionButton.textContent =
                        hasVariants
                            ? 'Không có biến thể đạt ngưỡng Xả kho'
                            : 'Sản phẩm chưa đạt ngưỡng Xả kho';
                    return;
                }
            }
        }

        savePromotionButton.disabled = false;
        savePromotionButton.textContent =
            'Lưu thay đổi';
    }

    function refreshScope() {
        const isVariant =
            scopeVariant.checked;

        variantBox.style.display =
            isVariant
                ? ''
                : 'none';

        variant.required =
            isVariant;

        if (!isVariant) {
            variant.value = '';
        }

        refreshSaveButton();
    }

    scopeAll.addEventListener(
        'change',
        refreshScope
    );

    scopeVariant.addEventListener(
        'change',
        refreshScope
    );

    product.addEventListener(
        'change',
        function () {
            loadVariants(
                product.value,
                false
            );

            refreshSaveButton();
        }
    );

    variant.addEventListener(
        'change',
        refreshSaveButton
    );

    if (product.value) {
        loadVariants(
            product.value,
            true
        );
    }

    refreshScope();
    refreshSaveButton();

    const type =
        document.getElementById(
            'loai_khuyen_mai'
        );

    const audience =
        document.getElementById(
            'doi_tuong_khach'
        );

    const clearanceThresholdInput =
        document.getElementById(
            'ton_kho_toi_da'
        );

    if (clearanceThresholdInput) {
        clearanceThresholdInput.addEventListener(
            'input',
            refreshSaveButton
        );

        clearanceThresholdInput.addEventListener(
            'change',
            refreshSaveButton
        );
    }

    if (type) {
        type.addEventListener(
            'change',
            refreshSaveButton
        );
    }

    const discountType =
        document.getElementById(
            'kieu_giam'
        );

    const capBox =
        document.getElementById(
            'capBox'
        );

    function setConditionVisible(
        key,
        visible
    ) {
        document
            .querySelectorAll(
                '[data-condition="' +
                key +
                '"]'
            )
            .forEach(
                function (element) {
                    element.classList.toggle(
                        'show',
                        visible
                    );
                }
            );
    }

    const eventSelect =
        document.getElementById(
            'ma_su_kien'
        );

    const eventBox =
        document.getElementById(
            'eventBox'
        );

    const eventHelp =
        document.getElementById(
            'eventHelp'
        );

    const startDateInput =
        document.getElementById('ngay_bat_dau');

    const endDateInput =
        document.getElementById('ngay_ket_thuc');

    const startDateLabel =
        document.getElementById('startDateLabel');

    const endDateLabel =
        document.getElementById('endDateLabel');

    const flashScheduleGuide =
        document.getElementById('flashScheduleGuide');

    const flashSchedulePreview =
        document.getElementById('flashSchedulePreview');

    const flashStartTime =
        document.getElementById('gio_bat_dau');

    const flashEndTime =
        document.getElementById('gio_ket_thuc');

    function toDateOnly(value) {
        return String(value || '').split('T')[0].split(' ')[0];
    }

    function toDateTime(value, endOfDay) {
        const date = toDateOnly(value);
        if (!date) {
            return '';
        }

        return date + (endOfDay ? 'T23:59' : 'T00:00');
    }

    function updateFlashPreview() {
        if (!flashSchedulePreview || type.value !== 'flash_sale') {
            return;
        }

        const from = toDateOnly(startDateInput.value);
        const to = toDateOnly(endDateInput.value);
        const fromTime = flashStartTime.value || '--:--';
        const toTime = flashEndTime.value || '--:--';

        if (!from || !to) {
            flashSchedulePreview.textContent =
                'Hãy chọn ngày bắt đầu và ngày kết thúc.';
            return;
        }

        flashSchedulePreview.textContent =
            (from === to
                ? 'Ngày ' + from
                : 'Từ ' + from + ' đến ' + to) +
            ' · tự chạy mỗi ngày ' +
            fromTime +
            ' → ' +
            toTime;
    }

    function refreshScheduleMode() {
        const isFlash =
            type.value === 'flash_sale';

        if (flashScheduleGuide) {
            flashScheduleGuide.style.display =
                isFlash ? '' : 'none';
        }

        if (isFlash) {
            const startValue = toDateOnly(startDateInput.value);
            const endValue = toDateOnly(endDateInput.value);

            startDateInput.type = 'date';
            endDateInput.type = 'date';

            startDateInput.value = startValue;
            endDateInput.value = endValue;

            startDateLabel.textContent =
                'Ngày bắt đầu chạy Flash sale';

            endDateLabel.textContent =
                'Ngày kết thúc chạy Flash sale';

            flashStartTime.required = true;
            flashEndTime.required = true;
        } else {
            if (startDateInput.type !== 'datetime-local') {
                const startValue =
                    toDateTime(startDateInput.value, false);

                const endValue =
                    toDateTime(endDateInput.value, true);

                startDateInput.type = 'datetime-local';
                endDateInput.type = 'datetime-local';

                startDateInput.value = startValue;
                endDateInput.value = endValue;
            }

            startDateLabel.textContent = 'Bắt đầu';
            endDateLabel.textContent = 'Kết thúc';

            flashStartTime.required = false;
            flashEndTime.required = false;
        }

        updateFlashPreview();
    }

    function refreshTypeFields() {
        const value =
            type.value;

        setConditionVisible(
            'mua_nhieu',
            value === 'mua_nhieu'
        );

        setConditionVisible(
            'xa_kho',
            value === 'xa_kho'
        );

        setConditionVisible(
            'flash',
            value === 'flash_sale'
        );

        const audienceType =
            [
                'hoc_sinh_sinh_vien',
                'nguoi_co_cong',
                'tri_an_khach_hang',
            ].includes(value);

        setConditionVisible(
            'audience',
            audienceType
        );

        setConditionVisible(
            'loyalty',
            value === 'tri_an_khach_hang'
        );

        if (
            value === 'hoc_sinh_sinh_vien'
        ) {
            audience.value =
                'hoc_sinh_sinh_vien';
        } else if (
            value === 'nguoi_co_cong'
        ) {
            audience.value =
                'nguoi_co_cong';
        } else if (
            value === 'tri_an_khach_hang'
        ) {
            audience.value =
                'khach_than_thiet';
        }

        const eventAllowed =
            [
                'giam_truc_tiep',
                'flash_sale',
                'ra_mat',
                'uu_dai_online',
                'thanh_toan',
                'khac'
            ].includes(value);

        if (eventBox) {
            eventBox.style.display =
                eventAllowed
                    ? ''
                    : 'none';
        }

        if (!eventAllowed && eventSelect) {
            eventSelect.value = '';

            if (eventHelp) {
                eventHelp.textContent =
                    'Loại khuyến mãi này hoạt động theo điều kiện riêng nên không gắn với sự kiện/ngày lễ.';
            }
        }

        refreshScheduleMode();
    }

    function refreshDiscountType() {
        capBox.style.display =
            discountType.value
                === 'phan_tram'
                ? ''
                : 'none';
    }

    type.addEventListener(
        'change',
        refreshTypeFields
    );

    discountType.addEventListener(
        'change',
        refreshDiscountType
    );

    refreshTypeFields();
    refreshDiscountType();

    const eventDates =
        <?= json_encode(
            $fixedEventDates,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ) ?>;

    const lunarEvents =
        [
            'tet_nguyen_dan',
            'gio_to_hung_vuong',
            'trung_thu',
        ];

    function localValue(date) {
        const pad =
            value =>
                String(value)
                    .padStart(2, '0');

        return (
            date.getFullYear() +
            '-' +
            pad(date.getMonth() + 1) +
            '-' +
            pad(date.getDate()) +
            'T' +
            pad(date.getHours()) +
            ':' +
            pad(date.getMinutes())
        );
    }

    function applyEventDate() {
        const key =
            eventSelect.value;

        if (lunarEvents.includes(key)) {
            eventHelp.textContent =
                'Đây là ngày âm lịch nên ngày dương lịch thay đổi theo từng năm. Hãy chọn thời gian bắt đầu/kết thúc đúng năm chạy chương trình.';

            return;
        }

        const md =
            eventDates[key];

        if (!md) {
            eventHelp.textContent =
                'Bạn tự chọn thời gian bắt đầu và kết thúc cho chương trình này.';

            return;
        }

        const now =
            new Date();

        let year =
            now.getFullYear();

        let start =
            new Date(
                year,
                Number(md[0]) - 1,
                Number(md[1]),
                0,
                0
            );

        const today =
            new Date(
                now.getFullYear(),
                now.getMonth(),
                now.getDate()
            );

        if (start < today) {
            year++;

            start =
                new Date(
                    year,
                    Number(md[0]) - 1,
                    Number(md[1]),
                    0,
                    0
                );
        }

        const end =
            new Date(start);

        end.setHours(
            23,
            59,
            0,
            0
        );

        document.getElementById(
            'ngay_bat_dau'
        ).value =
            type.value === 'flash_sale'
                ? toDateOnly(localValue(start))
                : localValue(start);

        document.getElementById(
            'ngay_ket_thuc'
        ).value =
            type.value === 'flash_sale'
                ? toDateOnly(localValue(end))
                : localValue(end);

        eventHelp.textContent =
            type.value === 'flash_sale'
                ? 'Đã chọn ngày gần nhất cho sự kiện. Hãy chọn thêm khung giờ Flash sale trong ngày.'
                : 'Đã tự chọn ngày gần nhất cho sự kiện. Bạn vẫn có thể sửa thời gian nếu muốn chạy nhiều ngày.';

        updateFlashPreview();
    }

    [
        startDateInput,
        endDateInput,
        flashStartTime,
        flashEndTime
    ].forEach(function (element) {
        if (element) {
            element.addEventListener(
                'input',
                updateFlashPreview
            );
        }
    });

    eventSelect.addEventListener(
        'change',
        applyEventDate
    );
})();
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
