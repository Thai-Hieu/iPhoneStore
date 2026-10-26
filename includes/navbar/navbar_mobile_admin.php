<?php

/*

\|--------------------------------------------------------------------------

\| NAVBAR MOBILE ADMIN

\|--------------------------------------------------------------------------

\| File này chỉ hiển thị giao diện mobile cho quản trị viên.

\| Dữ liệu chung vẫn lấy từ navbar.php.

*/

?>

<?php

if (!isset($adminSearchResults)) {

    $adminSearch = trim((string)($_GET['admin_q'] ?? ''));

    $adminSearchResults = [

        'functions' => [],

        'orders' => [],

        'products' => [],

        'users' => [],

        'payments' => [],

        'notifications' => []

    ];

    $adminLinks = [

        ['label' => 'Dashboard', 'icon' => '⌂', 'url' => '/iPhoneStore/admin/index.php'],

        ['label' => 'Đơn hàng', 'icon' => '▤', 'url' => '/iPhoneStore/admin/don_hang/index.php'],

        ['label' => 'Thông báo', 'icon' => '◉', 'url' => '/iPhoneStore/admin/thong_bao/index.php'],

        ['label' => 'Sản phẩm', 'icon' => '▣', 'url' => '/iPhoneStore/admin/san_pham/index.php'],

        ['label' => 'Danh mục', 'icon' => '▦', 'url' => '/iPhoneStore/admin/danh_muc/index.php'],

        ['label' => 'Người dùng', 'icon' => '♟', 'url' => '/iPhoneStore/admin/nguoi_dung/index.php'],

        ['label' => 'Thống kê', 'icon' => '▥', 'url' => '/iPhoneStore/admin/thong_ke/index.php']

    ];

    if ($adminSearch !== '' && isset($conn) && $conn instanceof mysqli) {

        $searchLower = mb_strtolower($adminSearch, 'UTF-8');

        $like = '%' . $adminSearch . '%';

        foreach ($adminLinks as $item) {

            if (str_contains(mb_strtolower($item['label'], 'UTF-8'), $searchLower)) {

                $adminSearchResults['functions'][] = $item;

            }

        }

        try {

            $stmt = $conn->prepare("

                SELECT

                    dh.id,

                    dh.ma_van_don,

                    dh.trang_thai,

                    dh.tong_tien,

                    nd.ho_ten,

                    nd.email,

                    nd.so_dien_thoai

                FROM don_hang dh

                LEFT JOIN nguoi_dung nd ON nd.id = dh.nguoi_dung_id

                WHERE CAST(dh.id AS CHAR) LIKE ?

                   OR dh.ma_van_don LIKE ?

                   OR dh.trang_thai LIKE ?

                   OR nd.ho_ten LIKE ?

                   OR nd.email LIKE ?

                   OR nd.so_dien_thoai LIKE ?

                ORDER BY dh.id DESC

                LIMIT 6

            ");

            $stmt->bind_param("ssssss", $like, $like, $like, $like, $like, $like);

            $stmt->execute();

            $adminSearchResults['orders'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        } catch (Throwable $e) {

            $adminSearchResults['orders'] = [];

        }

        try {

            $stmt = $conn->prepare("

                SELECT id, ma_san_pham, ten_san_pham, so_luong

                FROM san_pham

                WHERE ma_san_pham LIKE ? OR ten_san_pham LIKE ?

                ORDER BY id DESC

                LIMIT 6

            ");

            $stmt->bind_param("ss", $like, $like);

            $stmt->execute();

            $adminSearchResults['products'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        } catch (Throwable $e) {

            $adminSearchResults['products'] = [];

        }

        try {

            $stmt = $conn->prepare("

                SELECT id, ho_ten, email, so_dien_thoai

                FROM nguoi_dung

                WHERE CAST(id AS CHAR) LIKE ?

                   OR ho_ten LIKE ?

                   OR email LIKE ?

                   OR so_dien_thoai LIKE ?

                ORDER BY id DESC

                LIMIT 6

            ");

            $stmt->bind_param("ssss", $like, $like, $like, $like);

            $stmt->execute();

            $adminSearchResults['users'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        } catch (Throwable $e) {

            $adminSearchResults['users'] = [];

        }

        try {

            $stmt = $conn->prepare("

                SELECT id, don_hang_id, phuong_thuc, trang_thai

                FROM thanh_toan

                WHERE CAST(id AS CHAR) LIKE ?

                   OR CAST(don_hang_id AS CHAR) LIKE ?

                   OR phuong_thuc LIKE ?

                   OR trang_thai LIKE ?

                ORDER BY id DESC

                LIMIT 6

            ");

            $stmt->bind_param("ssss", $like, $like, $like, $like);

            $stmt->execute();

            $adminSearchResults['payments'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        } catch (Throwable $e) {

            $adminSearchResults['payments'] = [];

        }

    }

    $adminSearchCount = 0;

    foreach ($adminSearchResults as $items) {

        $adminSearchCount += count($items);

    }

}

?>

<style>

.mobile-navbar-only {

    display: none;

}

@media (max-width: 991px) {

    .mobile-navbar-only {

        display: block;

    }

}

.mobile-shop-header {

    position: sticky;

    top: 0;

    z-index: 1800;

    background: linear-gradient(180deg, #e45464 -13%, #d70018);

    color: #fff;

    box-shadow: 0 2px 12px rgba(0, 0, 0, .12);

}

.mobile-shop-bar {

    /*

        Chừa khoảng trống hai bên để cụm ☰ - logo - giỏ hàng,

        ô tìm kiếm và quick links thẳng hàng đẹp hơn với phần

        nội dung / sản phẩm bên dưới.

    */

    padding: 10px 16px 8px;

}

.mobile-shop-row {

    display: grid;

    grid-template-columns:

        44px

        minmax(0, 1fr)

        44px;

    gap: 9px;

    align-items: center;

}

.mobile-menu-button {

    width: 44px;

    height: 44px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    padding: 0;

    border: 1px solid rgba(255, 255, 255, .35);

    border-radius: 11px;

    background: rgba(255, 255, 255, .10);

    color: #fff;

    font-size: 25px;

    line-height: 1;

    cursor: pointer;

}

.mobile-menu-button:focus,

.mobile-menu-button:hover {

    outline: none;

    color: #fff;

    background: rgba(255, 255, 255, .18);

}

.mobile-brand {

    min-width: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    gap: 6px;

    color: #fff;

    text-decoration: none;

}

.mobile-brand:hover {

    color: #fff;

}

.mobile-brand-icon {

    font-size: 28px;

    line-height: 1;

}

.mobile-brand-text {

    overflow: hidden;

    font-size: 21px;

    line-height: 1;

    font-weight: 900;

    letter-spacing: -.45px;

    text-overflow: ellipsis;

    white-space: nowrap;

}

.mobile-cart-link,

.mobile-admin-link,

.mobile-login-link {

    position: relative;

    width: 44px;

    height: 44px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    justify-self: end;

    border-radius: 11px;

    color: #fff;

    text-decoration: none;

}

.mobile-cart-link:hover,

.mobile-admin-link:hover,

.mobile-login-link:hover {

    color: #fff;

    background: rgba(255, 255, 255, .10);

}

.mobile-cart-svg {

    width: 31px;

    height: 31px;

    stroke: currentColor;

}

.mobile-cart-count {

    position: absolute;

    top: 0;

    right: 1px;

    min-width: 19px;

    height: 19px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    padding: 0 5px;

    border: 2px solid #d70018;

    border-radius: 999px;

    background: #fff;

    color: #d70018;

    font-size: 9px;

    line-height: 1;

    font-weight: 900;

}

.mobile-search-wrap {

    margin-top: 9px;

}

.mobile-search-box {

    height: 46px;

    display: flex;

    align-items: center;

    padding: 4px;

    border-radius: 11px;

    background: #fff;

    box-shadow: 0 4px 14px rgba(0, 0, 0, .13);

}

.mobile-search-box input {

    min-width: 0;

    flex: 1;

    height: 38px;

    padding: 0 13px;

    border: 0;

    outline: 0;

    background: transparent;

    color: #222;

    font-size: 13px;

}

.mobile-search-submit {

    width: 52px;

    height: 38px;

    flex: 0 0 52px;

    border: 0;

    border-radius: 8px;

    background: #d70018;

    color: #fff;

    font-size: 18px;

}

.mobile-quick-links {

    display: flex;

    align-items: center;

    gap: 12px;

    margin-top: 7px;

    padding: 0 2px 2px;

    overflow-x: auto;

    scrollbar-width: none;

}

.mobile-quick-links::-webkit-scrollbar {

    display: none;

}

.mobile-quick-link {

    position: relative;

    flex: 0 0 auto;

    color: rgba(255, 255, 255, .92);

    text-decoration: none;

    font-size: 11px;

    white-space: nowrap;

}

.mobile-quick-link:hover,

.mobile-quick-link.active {

    color: #fff;

    text-decoration: underline;

    text-underline-offset: 3px;

}

/* Overlay */

.mobile-drawer-overlay {

    position: fixed;

    z-index: 2990;

    inset: 0;

    background: rgba(15, 23, 42, .58);

    -webkit-backdrop-filter: blur(2px);

    backdrop-filter: blur(2px);

    opacity: 0;

    visibility: hidden;

    pointer-events: none;

    transition: opacity .24s ease, visibility 0s linear .24s;

}

/* Drawer */

.mobile-drawer {

    position: fixed;

    z-index: 3000;

    top: 0;

    left: 0;

    width: min(86vw, 342px);

    height: 100dvh;

    display: flex;

    flex-direction: column;

    overflow: hidden;

    border-right: 1px solid #e9e9e9;

    background: #fff;

    color: #222;

    box-shadow: 18px 0 48px rgba(0, 0, 0, .22);

    opacity: 0;

    visibility: hidden;

    pointer-events: none;

    transform: translate3d(-105%, 0, 0);

    transition:

        transform .30s cubic-bezier(.22, .61, .36, 1),

        opacity .22s ease,

        visibility 0s linear .30s;

    will-change: transform;

}

body.mobile-drawer-open {

    overflow: hidden;

}

body.mobile-drawer-open .mobile-drawer-overlay {

    opacity: 1;

    visibility: visible;

    pointer-events: auto;

    transition-delay: 0s;

}

body.mobile-drawer-open .mobile-drawer {

    opacity: 1;

    visibility: visible;

    pointer-events: auto;

    transform: translate3d(0, 0, 0);

    transition-delay: 0s;

}

.mobile-drawer-header {

    min-height: 66px;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 12px;

    padding: 12px 15px 12px 17px;

    border-bottom: 1px solid #ededed;

}

.mobile-drawer-brand {

    display: inline-flex;

    align-items: center;

    gap: 8px;

    min-width: 0;

    color: #202124;

    text-decoration: none;

}

.mobile-drawer-brand:hover {

    color: #202124;

}

.mobile-drawer-brand-icon {

    color: #d70018;

    font-size: 25px;

}

.mobile-drawer-brand-name {

    overflow: hidden;

    font-size: 20px;

    font-weight: 900;

    letter-spacing: -.35px;

    text-overflow: ellipsis;

    white-space: nowrap;

}

.mobile-drawer-close {

    width: 38px;

    height: 38px;

    flex: 0 0 38px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    padding: 0;

    border: 0;

    border-radius: 10px;

    background: #f5f5f5;

    color: #555;

    font-size: 25px;

    line-height: 1;

    cursor: pointer;

}

.mobile-drawer-close:hover {

    background: #ffecef;

    color: #d70018;

}

.mobile-profile {

    display: flex;

    align-items: center;

    gap: 12px;

    padding: 15px 17px;

    border-bottom: 1px solid #f0f0f0;

    background: linear-gradient(135deg, #fff8f9, #ffffff);

}

.mobile-profile-avatar {

    width: 44px;

    height: 44px;

    flex: 0 0 44px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    border-radius: 14px;

    background: linear-gradient(135deg, #d70018, #ef5264);

    color: #fff;

    box-shadow: 0 6px 15px rgba(215, 0, 24, .18);

    font-size: 17px;

    font-weight: 900;

}

.mobile-profile-info {

    min-width: 0;

}

.mobile-profile-name {

    overflow: hidden;

    color: #232323;

    font-size: 14px;

    font-weight: 850;

    text-overflow: ellipsis;

    white-space: nowrap;

}

.mobile-profile-role {

    margin-top: 2px;

    color: #8b8b8b;

    font-size: 11px;

    font-weight: 650;

}

.mobile-drawer-scroll {

    flex: 1 1 auto;

    overflow-y: auto;

    overscroll-behavior: contain;

    padding: 10px 10px 18px;

}

.mobile-menu-section {

    padding: 7px 0 4px;

}

.mobile-menu-title {

    padding: 0 10px 7px;

    color: #999;

    font-size: 10px;

    line-height: 1.2;

    font-weight: 900;

    letter-spacing: .95px;

    text-transform: uppercase;

}

.mobile-menu-link {

    min-height: 49px;

    display: flex;

    align-items: center;

    gap: 11px;

    padding: 8px 10px;

    border-radius: 12px;

    color: #2b2b2b;

    text-decoration: none;

    font-size: 14px;

    font-weight: 720;

    transition:

        *background* .16s ease,

        color .16s ease,

        transform .16s ease;

}

.mobile-menu-link:hover,

.mobile-menu-link:focus {

    background: #fff2f4;

    color: #d70018;

    outline: 0;

    transform: translateX(2px);

}

.mobile-menu-icon {

    width: 34px;

    height: 34px;

    flex: 0 0 34px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    border-radius: 10px;

    background: #f4f5f7;

    color: #50545a;

    font-size: 16px;

}

.mobile-menu-link:hover .mobile-menu-icon {

    background: #ffe6ea;

    color: #d70018;

}

.mobile-menu-text {

    min-width: 0;

    flex: 1 1 auto;

}

.mobile-menu-badge {

    min-width: 22px;

    height: 22px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    padding: 0 6px;

    border-radius: 999px;

    background: #d70018;

    color: #fff;

    font-size: 10px;

    line-height: 1;

    font-weight: 900;

}

.mobile-menu-divider {

    height: 1px;

    margin: 8px 10px;

    background: #ededed;

}

.mobile-menu-link.logout {

    color: #c53045;

}

.mobile-menu-link.logout .mobile-menu-icon {

    background: #fff0f2;

    color: #c53045;

}

.mobile-drawer-footer {

    padding: 11px 16px 14px;

    border-top: 1px solid #efefef;

    background: #fafafa;

    color: #999;

    font-size: 10px;

    line-height: 1.4;

    text-align: center;

}

@media (max-width: 575px) {

    .mobile-shop-bar {

        padding-left: 14px;

        padding-right: 14px;

    }

    .mobile-shop-row {

        grid-template-columns:

            41px

            minmax(0, 1fr)

            41px;

        gap: 7px;

    }

    .mobile-menu-button {

        width: 41px;

        height: 41px;

        font-size: 23px;

    }

    .mobile-brand {

        justify-content: center;

        padding-left: 0;

    }

    .mobile-brand-icon {

        font-size: 26px;

    }

    .mobile-brand-text {

        font-size: 19px;

    }

    .mobile-cart-link,

    .mobile-admin-link,

    .mobile-login-link {

        width: 41px;

        height: 41px;

    }

}

/* Admin mobile: bỏ các thành phần chỉ dành cho user */

.mobile-admin-status {

    width: 44px;

    height: 44px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    justify-self: end;

    border: 1px solid rgba(255, 255, 255, .35);

    border-radius: 11px;

    background: rgba(255, 255, 255, .10);

    color: #fff;

    font-size: 19px;

}

.mobile-admin-badge {

    min-width: 19px;

    height: 19px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    padding: 0 5px;

    border: 2px solid #d70018;

    border-radius: 999px;

    background: #fff;

    color: #d70018;

    font-size: 9px;

    font-weight: 900;

}

</style>

<style>

.mobile-admin-search-wrap {

    position: relative;

    margin-top: 9px;

}

.mobile-admin-search-results {

    margin-top: 8px;

    max-height: 48vh;

    overflow-y: auto;

    border-radius: 12px;

    background: #fff;

    color: #1f2937;

    box-shadow: 0 10px 25px rgba(74, 0, 9, .20);

}

.mobile-admin-search-head {

    position: sticky;

    top: 0;

    z-index: 2;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 10px;

    padding: 11px 13px;

    border-bottom: 1px solid #eef0f3;

    background: #fff;

    font-size: 12px;

    font-weight: 850;

}

.mobile-admin-search-close {

    color: #9ca3af;

    text-decoration: none;

    font-size: 18px;

    line-height: 1;

}

.mobile-admin-result-group {

    border-bottom: 1px solid #f0f1f3;

}

.mobile-admin-result-title {

    padding: 8px 13px 5px;

    color: #9ca3af;

    font-size: 9px;

    font-weight: 900;

    letter-spacing: .5px;

    text-transform: uppercase;

}

.mobile-admin-result-item {

    display: block;

    padding: 10px 13px;

    color: #334155;

    text-decoration: none;

    transition: *background* .16s ease, color .16s ease;

}

.mobile-admin-result-item:hover,

.mobile-admin-result-item:focus {

    background: #fff2f4;

    color: #d70018;

}

.mobile-admin-result-main {

    font-size: 12px;

    font-weight: 850;

}

.mobile-admin-result-sub {

    margin-top: 2px;

    overflow: hidden;

    color: #8a94a3;

    font-size: 10px;

    text-overflow: ellipsis;

    white-space: nowrap;

}

.mobile-admin-search-empty {

    padding: 22px 14px;

    color: #8a94a3;

    text-align: center;

    font-size: 11px;

}

</style>

<div class="mobile-navbar-only">

    <header class="mobile-shop-header">

        <div class="container mobile-shop-bar">

            <div class="mobile-shop-row">

                <button type="button" class="mobile-menu-button" id="mobileNavbarMenuButton"

                        aria-label="Mở menu" aria-controls="mobileNavbarDrawer" aria-expanded="false">☰</button>

                <a href="/iPhoneStore/admin/index.php" class="mobile-brand">

                    <span class="mobile-brand-icon">⚙️</span>

                    <span class="mobile-brand-text">IphoneStore Admin</span>

                </a>

                <div class="mobile-admin-status" title="Quản trị viên">A</div>

            </div>

            <div class="mobile-admin-search-wrap">

                <form class="mobile-search-box" method="GET" action="/iPhoneStore/admin/index.php">

                    <input

                        type="text"

                        name="admin_q"

                        value="<?= htmlspecialchars((string)($adminSearch ?? '')) ?>"

                        placeholder="Tìm đơn hàng, mã vận đơn, sản phẩm, khách hàng..."

                        autocomplete="off"

                    >

                    <button type="submit" class="mobile-search-submit" aria-label="Tìm kiếm quản trị">🔍</button>

                </form>

                <?php if (!empty($adminSearch)): ?>

                    <div class="mobile-admin-search-results">

                        <div class="mobile-admin-search-head">

                            <span><?= (int)$adminSearchCount ?> kết quả cho “<?= htmlspecialchars($adminSearch) ?>”</span>

                            <a href="/iPhoneStore/admin/index.php" class="mobile-admin-search-close" title="Đóng">×</a>

                        </div>

                        <?php if ((int)$adminSearchCount === 0): ?>

                            <div class="mobile-admin-search-empty">Không tìm thấy dữ liệu phù hợp.</div>

                        <?php endif; ?>

                        <?php if (!empty($adminSearchResults['functions'])): ?>

                            <div class="mobile-admin-result-group">

                                <div class="mobile-admin-result-title">Chức năng</div>

                                <?php foreach ($adminSearchResults['functions'] as $item): ?>

                                    <a href="<?= htmlspecialchars($item['url']) ?>" class="mobile-admin-result-item">

                                        <div class="mobile-admin-result-main"><?= htmlspecialchars($item['icon']) ?> <?= htmlspecialchars($item['label']) ?></div>

                                    </a>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>

                        <?php if (!empty($adminSearchResults['orders'])): ?>

                            <div class="mobile-admin-result-group">

                                <div class="mobile-admin-result-title">Đơn hàng</div>

                                <?php foreach ($adminSearchResults['orders'] as $order): ?>

                                    <a href="/iPhoneStore/admin/don_hang/chi_tiet.php?id=<?= (int)$order['id'] ?>" class="mobile-admin-result-item">

                                        <div class="mobile-admin-result-main">

                                            <?= !empty($order['ma_van_don'])

                                                ? 'Mã vận đơn: ' . htmlspecialchars((string)$order['ma_van_don'])

                                                : 'Chưa có mã vận đơn' ?>

                                        </div>

                                        <div class="mobile-admin-result-sub">

                                            <?= htmlspecialchars((string)($order['ho_ten'] ?? 'Chưa có tên')) ?>

                                            · <?= htmlspecialchars((string)($order['trang_thai'] ?? '')) ?>

                                            · <?= number_format((float)($order['tong_tien'] ?? 0), 0, ',', '.') ?>đ

                                        </div>

                                    </a>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>

                        <?php if (!empty($adminSearchResults['products'])): ?>

                            <div class="mobile-admin-result-group">

                                <div class="mobile-admin-result-title">Sản phẩm</div>

                                <?php foreach ($adminSearchResults['products'] as $product): ?>

                                    <a href="/iPhoneStore/admin/san_pham/chi_tiet.php?id=<?= (int)$product['id'] ?>" class="mobile-admin-result-item">

                                        <div class="mobile-admin-result-main"><?= htmlspecialchars((string)$product['ten_san_pham']) ?></div>

                                        <div class="mobile-admin-result-sub">

                                            Mã: <?= htmlspecialchars((string)($product['ma_san_pham'] ?? '-')) ?>

                                            · Tồn: <?= (int)($product['so_luong'] ?? 0) ?>

                                        </div>

                                    </a>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>

                        <?php if (!empty($adminSearchResults['users'])): ?>

                            <div class="mobile-admin-result-group">

                                <div class="mobile-admin-result-title">Người dùng</div>

                                <?php foreach ($adminSearchResults['users'] as $user): ?>

                                    <a href="/iPhoneStore/admin/nguoi_dung/index.php" class="mobile-admin-result-item">

                                        <div class="mobile-admin-result-main"><?= htmlspecialchars((string)($user['ho_ten'] ?? 'Chưa có tên')) ?></div>

                                        <div class="mobile-admin-result-sub">

                                            <?= htmlspecialchars((string)($user['email'] ?? '-')) ?>

                                            · <?= htmlspecialchars((string)($user['so_dien_thoai'] ?? '-')) ?>

                                        </div>

                                    </a>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>

                        <?php if (!empty($adminSearchResults['payments'])): ?>

                            <div class="mobile-admin-result-group">

                                <div class="mobile-admin-result-title">Thanh toán</div>

                                <?php foreach ($adminSearchResults['payments'] as $payment): ?>

                                    <a href="/iPhoneStore/admin/don_hang/chi_tiet.php?id=<?= (int)$payment['don_hang_id'] ?>" class="mobile-admin-result-item">

                                        <div class="mobile-admin-result-main">

                                            Thanh toán #<?= (int)$payment['id'] ?> · Đơn #<?= (int)$payment['don_hang_id'] ?>

                                        </div>

                                        <div class="mobile-admin-result-sub">

                                            <?= htmlspecialchars((string)($payment['phuong_thuc'] ?? '-')) ?>

                                            · <?= htmlspecialchars((string)($payment['trang_thai'] ?? '-')) ?>

                                        </div>

                                    </a>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </header>

    <div class="mobile-drawer-overlay" id="mobileNavbarOverlay" aria-hidden="true"></div>

    <aside class="mobile-drawer" id="mobileNavbarDrawer" aria-hidden="true" aria-label="Menu quản trị">

        <div class="mobile-drawer-header">

            <a href="/iPhoneStore/admin/index.php" class="mobile-drawer-brand">

                <span class="mobile-drawer-brand-icon">⚙️</span>

                <span class="mobile-drawer-brand-name">IphoneStore Admin</span>

            </a>

            <button type="button" class="mobile-drawer-close" id="mobileNavbarClose" aria-label="Đóng menu">×</button>

        </div>

        <?php if ($navUser): ?>

            <div class="mobile-profile">

                <div class="mobile-profile-avatar"><?= htmlspecialchars($navInitial) ?></div>

                <div class="mobile-profile-info">

                    <div class="mobile-profile-name"><?= htmlspecialchars($navHoTen !== '' ? $navHoTen : 'Quản trị viên') ?></div>

                    <div class="mobile-profile-role">Quản trị viên</div>

                </div>

            </div>

        <?php endif; ?>

        <div class="mobile-drawer-scroll">

            <div class="mobile-menu-section">

                <div class="mobile-menu-title">Tổng quan</div>

                <a href="/iPhoneStore/admin/index.php" class="mobile-menu-link">

                    <span class="mobile-menu-icon">⌂</span>

                    <span class="mobile-menu-text">Bảng điều khiển</span>

                </a>

            </div>

            <div class="mobile-menu-divider"></div>

            <div class="mobile-menu-section">

                <div class="mobile-menu-title">Quản lý bán hàng</div>

                <a href="/iPhoneStore/admin/don_hang/index.php" class="mobile-menu-link">

                    <span class="mobile-menu-icon">◫</span>

                    <span class="mobile-menu-text">Đơn hàng</span>

                </a>

                <a href="/iPhoneStore/admin/thong_bao/index.php" class="mobile-menu-link">

                    <span class="mobile-menu-icon">◉</span>

                    <span class="mobile-menu-text">Thông báo</span>

                    <?php if ($navUnreadCount > 0): ?>

                        <span class="mobile-menu-badge"><?= $navUnreadCount > 99 ? '99+' : $navUnreadCount ?></span>

                    <?php endif; ?>

                </a>

            </div>

            <div class="mobile-menu-divider"></div>

            <div class="mobile-menu-section">

                <div class="mobile-menu-title">Sản phẩm</div>

                <a href="/iPhoneStore/admin/san_pham/index.php" class="mobile-menu-link">

                    <span class="mobile-menu-icon">▣</span>

                    <span class="mobile-menu-text">Sản phẩm & biến thể</span>

                </a>

                <a href="/iPhoneStore/admin/danh_muc/index.php" class="mobile-menu-link">

                    <span class="mobile-menu-icon">▦</span>

                    <span class="mobile-menu-text">Danh mục</span>

                </a>

            </div>

            <div class="mobile-menu-divider"></div>

            <div class="mobile-menu-section">

                <div class="mobile-menu-title">Hệ thống</div>

                <a href="/iPhoneStore/admin/nguoi_dung/index.php" class="mobile-menu-link">

                    <span class="mobile-menu-icon">♟</span>

                    <span class="mobile-menu-text">Người dùng</span>

                </a>

                <a href="/iPhoneStore/admin/thong_ke/index.php" class="mobile-menu-link">

                    <span class="mobile-menu-icon">▥</span>

                    <span class="mobile-menu-text">Thống kê</span>

                </a>

                <a href="/iPhoneStore/admin/tai_khoan/thong_tin.php" class="mobile-menu-link">

                    <span class="mobile-menu-icon">●</span>

                    <span class="mobile-menu-text">Thông tin tài khoản</span>

                </a>

                <a href="/iPhoneStore/admin/tai_khoan/doi_mat_khau.php" class="mobile-menu-link">

                    <span class="mobile-menu-icon">◆</span>

                    <span class="mobile-menu-text">Đổi mật khẩu</span>

                </a>

            </div>

            <div class="mobile-menu-divider"></div>

            <a href="/iPhoneStore/auth/logout.php" class="mobile-menu-link logout">

                <span class="mobile-menu-icon">↪</span>

                <span class="mobile-menu-text">Đăng xuất</span>

            </a>

        </div>

        <div class="mobile-drawer-footer">IphoneStore Admin · Hệ thống quản trị</div>

    </aside>

</div>

<script>

(function () {

    const button = document.getElementById('mobileNavbarMenuButton');

    const drawer = document.getElementById('mobileNavbarDrawer');

    const overlay = document.getElementById('mobileNavbarOverlay');

    const closeButton = document.getElementById('mobileNavbarClose');

    if (!button || !drawer || !overlay || !closeButton) return;

    let lastFocus = null;

    function openDrawer() {

        if (window.matchMedia('(min-width: 992px)').matches) return;

        lastFocus = document.activeElement;

        document.body.classList.add('mobile-drawer-open');

        button.setAttribute('aria-expanded', 'true');

        drawer.setAttribute('aria-hidden', 'false');

        overlay.setAttribute('aria-hidden', 'false');

        window.setTimeout(() => closeButton.focus(), 80);

    }

    function closeDrawer() {

        document.body.classList.remove('mobile-drawer-open');

        button.setAttribute('aria-expanded', 'false');

        drawer.setAttribute('aria-hidden', 'true');

        overlay.setAttribute('aria-hidden', 'true');

        if (lastFocus && typeof lastFocus.focus === 'function') {

            window.setTimeout(() => lastFocus.focus(), 120);

        }

    }

    button.addEventListener('click', openDrawer);

    closeButton.addEventListener('click', closeDrawer);

    overlay.addEventListener('click', closeDrawer);

    drawer.addEventListener('click', function (event) {

        if (event.target.closest('a.mobile-menu-link')) closeDrawer();

    });

    document.addEventListener('keydown', function (event) {

        if (event.key === 'Escape' && document.body.classList.contains('mobile-drawer-open')) {

            closeDrawer();

        }

    });

    window.addEventListener('resize', function () {

        if (window.matchMedia('(min-width: 992px)').matches) closeDrawer();

    });

})();

</script>
