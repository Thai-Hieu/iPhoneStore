<?php

    if ((!isset($conn) || !($conn instanceof mysqli))) {

        $dbPath = __DIR__ . '/../../config/database.php';

        if (is_file($dbPath)) {

            require_once $dbPath;

        }

    }

    $adminPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

    $adminPath = is_string($adminPath) ? $adminPath : '';

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

        ['label' => 'Dashboard', 'icon' => '⌂', 'url' => '/iPhoneStore/admin/index.php', 'match' => '/admin/index.php'],

        ['label' => 'Đơn hàng', 'icon' => '▤', 'url' => '/iPhoneStore/admin/don_hang/index.php', 'match' => '/admin/don_hang/'],

        ['label' => 'Thông báo', 'icon' => '◉', 'url' => '/iPhoneStore/admin/thong_bao/index.php', 'match' => '/admin/thong_bao/'],

        ['label' => 'Sản phẩm', 'icon' => '▣', 'url' => '/iPhoneStore/admin/san_pham/index.php', 'match' => '/admin/san_pham/'],

        ['label' => 'Danh mục', 'icon' => '▦', 'url' => '/iPhoneStore/admin/danh_muc/index.php', 'match' => '/admin/danh_muc/'],

        ['label' => 'Người dùng', 'icon' => '♟', 'url' => '/iPhoneStore/admin/nguoi_dung/index.php', 'match' => '/admin/nguoi_dung/'],

        ['label' => 'Thống kê', 'icon' => '▥', 'url' => '/iPhoneStore/admin/thong_ke/index.php', 'match' => '/admin/thong_ke/'],

         ['label' => 'Khuyến mãi', 'icon' => '🔥', 'url' => '/iPhoneStore/admin/khuyen_mai/index.php', 'match' => '/admin/khuyen_mai/']

    ];

    if ($adminSearch !== '') {

        $searchLower = mb_strtolower($adminSearch, 'UTF-8');

        foreach ($adminLinks as $item) {

            $haystack = mb_strtolower(

                $item['label'] . ' ' . $item['url'],

                'UTF-8'

            );

            if (str_contains($haystack, $searchLower)) {

                $adminSearchResults['functions'][] = $item;

            }

        }

        if (isset($conn) && $conn instanceof mysqli) {

            $like = '%' . $adminSearch . '%';

            try {

                $stmt = $conn->prepare("

                    SELECT

                        dh.id,

                        dh.ma_van_don,

                        dh.trang_thai,

                        dh.tong_tien,

                        dh.ngay_dat,

                        nd.ho_ten,

                        nd.email,

                        nd.so_dien_thoai

                    FROM don_hang dh

                    LEFT JOIN nguoi_dung nd

                        ON nd.id = dh.nguoi_dung_id

                    WHERE CAST(dh.id AS CHAR) LIKE ?

                       OR dh.ma_van_don LIKE ?

                       OR dh.trang_thai LIKE ?

                       OR nd.ho_ten LIKE ?

                       OR nd.email LIKE ?

                       OR nd.so_dien_thoai LIKE ?

                    ORDER BY dh.id DESC

                    LIMIT 8

                ");

                $stmt->bind_param(

                    "ssssss",

                    $like,

                    $like,

                    $like,

                    $like,

                    $like,

                    $like

                );

                $stmt->execute();

                $adminSearchResults['orders'] = $stmt

                    ->get_result()

                    ->fetch_all(MYSQLI_ASSOC);

            } catch (Throwable $e) {

                $adminSearchResults['orders'] = [];

            }

            try {

                $stmt = $conn->prepare("

                    SELECT

                        id,

                        ma_san_pham,

                        ten_san_pham,

                        so_luong,

                        trang_thai

                    FROM san_pham

                    WHERE ma_san_pham LIKE ?

                       OR ten_san_pham LIKE ?

                    ORDER BY id DESC

                    LIMIT 8

                ");

                $stmt->bind_param("ss", $like, $like);

                $stmt->execute();

                $adminSearchResults['products'] = $stmt

                    ->get_result()

                    ->fetch_all(MYSQLI_ASSOC);

            } catch (Throwable $e) {

                $adminSearchResults['products'] = [];

            }

            try {

                $stmt = $conn->prepare("

                    SELECT

                        id,

                        ho_ten,

                        email,

                        so_dien_thoai,

                        trang_thai

                    FROM nguoi_dung

                    WHERE CAST(id AS CHAR) LIKE ?

                       OR ho_ten LIKE ?

                       OR email LIKE ?

                       OR so_dien_thoai LIKE ?

                    ORDER BY id DESC

                    LIMIT 8

                ");

                $stmt->bind_param(

                    "ssss",

                    $like,

                    $like,

                    $like,

                    $like

                );

                $stmt->execute();

                $adminSearchResults['users'] = $stmt

                    ->get_result()

                    ->fetch_all(MYSQLI_ASSOC);

            } catch (Throwable $e) {

                $adminSearchResults['users'] = [];

            }

            try {

                $stmt = $conn->prepare("

                    SELECT

                        id,

                        don_hang_id,

                        phuong_thuc,

                        trang_thai

                    FROM thanh_toan

                    WHERE CAST(id AS CHAR) LIKE ?

                       OR CAST(don_hang_id AS CHAR) LIKE ?

                       OR phuong_thuc LIKE ?

                       OR trang_thai LIKE ?

                    ORDER BY id DESC

                    LIMIT 8

                ");

                $stmt->bind_param(

                    "ssss",

                    $like,

                    $like,

                    $like,

                    $like

                );

                $stmt->execute();

                $adminSearchResults['payments'] = $stmt

                    ->get_result()

                    ->fetch_all(MYSQLI_ASSOC);

            } catch (Throwable $e) {

                $adminSearchResults['payments'] = [];

            }

            try {

                $stmt = $conn->prepare("

                    SELECT

                        id,

                        tieu_de,

                        noi_dung,

                        loai,

                        ngay_tao

                    FROM thong_bao

                    WHERE tieu_de LIKE ?

                       OR noi_dung LIKE ?

                       OR loai LIKE ?

                    ORDER BY id DESC

                    LIMIT 8

                ");

                $stmt->bind_param("sss", $like, $like, $like);

                $stmt->execute();

                $adminSearchResults['notifications'] = $stmt

                    ->get_result()

                    ->fetch_all(MYSQLI_ASSOC);

            } catch (Throwable $e) {

                $adminSearchResults['notifications'] = [];

            }

        }

    }

    $adminSearchCount = 0;

    foreach ($adminSearchResults as $items) {

        $adminSearchCount += count($items);

    }

?>

<style>

.admin-desktop-navbar-only {

    display: block;

}

@media (max-width: 991px) {

    .admin-desktop-navbar-only {

        display: none !important;

    }

}

:root {

    --admin-red: #d70018;

    --admin-red-dark: #b80014;

}

.admin-shop-header {

    position: sticky;

    top: 0;

    z-index: 1250;

    color: #fff;

    background: linear-gradient(180deg, #e45464 -13%, #d70018);

    box-shadow: 0 4px 18px rgba(100, 0, 12, .20);

}

.admin-topbar {

    min-height: 38px;

    border-bottom: 1px solid rgba(255, 255, 255, .18);

    background: rgba(105, 0, 13, .15);

}

.admin-topbar-inner,

.admin-top-left,

.admin-top-right {

    display: flex;

    align-items: center;

}

.admin-topbar-inner {

    min-height: 38px;

    justify-content: space-between;

    gap: 18px;

}

.admin-top-left,

.admin-top-right {

    gap: 8px;

}

.admin-channel {

    display: inline-flex;

    align-items: center;

    gap: 8px;

    font-size: 12px;

    font-weight: 800;

}

.admin-channel-dot {

    width: 8px;

    height: 8px;

    border-radius: 50%;

    background: #68ed94;

    box-shadow: 0 0 0 4px rgba(104, 237, 148, .16);

}

.admin-context {

    padding-left: 10px;

    border-left: 1px solid rgba(255, 255, 255, .28);

    color: rgba(255, 255, 255, .78);

    font-size: 11px;

}

.admin-notification-area,

.admin-account {

    position: relative;

    z-index: 1500;

}

/* Cầu nối hover thật giữa nút và popup */

.admin-hover-bridge {

    position: absolute;

    z-index: 1501;

    top: 100%;

    right: 0;

    width: 100%;

    height: 12px;

    display: block;

    background: transparent;

}

/* Mũi tên nhỏ phía trên popup */

.admin-notification-panel::before,

.admin-account-menu::before {

    content: "";

    position: absolute;

    z-index: 3;

    top: -7px;

    width: 14px;

    height: 14px;

    border-top: 1px solid #eceff3;

    border-left: 1px solid #eceff3;

    background: #fff;

    transform: rotate(45deg);

}

.admin-notification-panel::before {

    right: 38px;

}

.admin-account-menu::before {

    right: 24px;

}

/* Không cho bất kỳ ::after cũ nào tạo hình ngoài ý muốn */

.admin-notification-area::after,

.admin-account::after,

.admin-notification-panel::after,

.admin-account-menu::after {

    content: none !important;

    display: none !important;

}

.admin-notification-trigger,

.admin-account-button {

    min-height: 30px;

    display: inline-flex;

    align-items: center;

    gap: 7px;

    padding: 0 10px;

    border: 0;

    border-radius: 8px;

    background: transparent;

    color: #fff;

    text-decoration: none;

    font-size: 12px;

    font-weight: 700;

}

.admin-notification-trigger:hover,

.admin-account-button:hover,

.admin-account:focus-within .admin-account-button {

    background: rgba(255, 255, 255, .13);

    color: #fff;

}

.admin-notification-count {

    min-width: 18px;

    height: 18px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    padding: 0 5px;

    border-radius: 999px;

    background: #fff;

    color: var(--admin-red);

    font-size: 10px;

    font-weight: 900;

}

.admin-notification-panel,

.admin-account-menu {

    position: absolute;

    top: calc(100% + 10px);

    right: 0;

    border: 1px solid #eceff3;

    background: #fff;

    color: #1f2937;

    box-shadow: 0 18px 45px rgba(40, 10, 15, .22);

    opacity: 0;

    visibility: hidden;

    pointer-events: none;

    transform: translateY(7px);

    transition: .15s ease;

}

.admin-notification-panel {

    width: 390px;

    overflow: visible;

    border-radius: 14px;

}

.admin-account-menu {

    width: 225px;

    padding: 7px;

    border-radius: 12px;

}

.admin-notification-area:hover .admin-notification-panel,

.admin-notification-area:focus-within .admin-notification-panel,

.admin-account:hover .admin-account-menu,

.admin-account:focus-within .admin-account-menu {

    opacity: 1;

    visibility: visible;

    pointer-events: auto;

    transform: translateY(0);

}

.admin-notification-header {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 12px;

    padding: 14px 15px 11px;

    border-bottom: 1px solid #eef0f3;

}

.admin-notification-title {

    font-size: 14px;

    font-weight: 850;

}

.admin-notification-unread {

    color: var(--admin-red);

    font-size: 11px;

    font-weight: 800;

}

.admin-notification-list {

    max-height: 275px;

    overflow-y: auto;

}

.admin-notification-item {

    position: relative;

    display: grid;

    grid-template-columns: 38px minmax(0, 1fr) 28px;

    gap: 10px;

    align-items: start;

    padding: 12px 11px 12px 14px;

    border-bottom: 1px solid #f0f1f3;

    background: #fff;

    transition: *background* .18s ease, transform .18s ease, box-shadow .18s ease;

}

.admin-notification-item::before {

    content: "";

    position: absolute;

    top: 8px;

    bottom: 8px;

    left: 0;

    width: 3px;

    border-radius: 0 4px 4px 0;

    background: transparent;

    transition: *background* .18s ease;

}

.admin-notification-item.unread {

    background: #fff7f8;

}

.admin-notification-item:hover {

    z-index: 1;

    background: #fff1f3;

    box-shadow: inset 0 0 0 1px rgba(215, 0, 24, .06);

    transform: translateX(3px);

}

.admin-notification-item:hover::before {

    background: #d70018;

}

.admin-notification-item:hover .admin-notification-icon {

    background: #ffe7ea;

    transform: scale(1.05);

}

.admin-notification-item:hover .admin-notification-item-title {

    color: #d70018;

}

.admin-notification-item:hover .admin-notification-delete {

    background: #fff;

    color: #d70018;

}

.admin-notification-icon {

    width: 38px;

    height: 38px;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 10px;

    background: #f4f5f7;

    font-size: 17px;

    transition: *background* .18s ease, transform .18s ease;

}

.admin-notification-link {

    min-width: 0;

    color: inherit;

    text-decoration: none;

}

.admin-notification-item-title {

    font-size: 12px;

    line-height: 1.4;

    font-weight: 850;

}

.admin-notification-text {

    overflow: hidden;

    display: -webkit-box;

    margin-top: 3px;

    color: #6b7280;

    font-size: 11px;

    line-height: 1.45;

    -webkit-line-clamp: 2;

    -webkit-box-orient: vertical;

}

.admin-notification-time {

    margin-top: 5px;

    color: #9ca3af;

    font-size: 10px;

}

.admin-notification-delete-form {

    margin: 0;

}

.admin-notification-delete {

    width: 28px;

    height: 28px;

    padding: 0;

    border: 0;

    border-radius: 7px;

    background: transparent;

    color: #a0a7b2;

    font-size: 17px;

    cursor: pointer;

}

.admin-notification-delete:hover {

    background: #ffecef;

    color: var(--admin-red);

}

.admin-notification-empty {

    min-height: 170px;

    display: flex;

    align-items: center;

    justify-content: center;

    padding: 25px;

    color: #8b95a5;

    text-align: center;

}

.admin-notification-footer {

    padding: 10px 12px 12px;

}

.admin-notification-all {

    min-height: 38px;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 8px;

    background: #fff2f4;

    color: var(--admin-red);

    text-decoration: none;

    font-size: 12px;

    font-weight: 850;

}

.admin-account-button {

    cursor: pointer;

}

.admin-account-label {

    padding: 8px 10px 6px;

    color: #98a2b3;

    font-size: 10px;

    font-weight: 900;

    letter-spacing: .7px;

    text-transform: uppercase;

}

.admin-account-menu a {

    display: flex;

    align-items: center;

    gap: 9px;

    padding: 10px;

    border-radius: 8px;

    color: #344054;

    text-decoration: none;

    font-size: 13px;

    font-weight: 650;

}

.admin-account-menu a:hover {

    background: #fff5f6;

    color: var(--admin-red);

}

.admin-account-divider {

    height: 1px;

    margin: 6px 4px;

    background: #edf0f3;

}

.admin-account-menu .admin-logout {

    color: #d92d20;

}

.admin-mainbar {

    padding: 14px 0 11px;

}

.admin-mainbar-inner {

    display: grid;

    grid-template-columns: 245px minmax(0, 1fr) 150px;

    gap: 20px;

    align-items: start;

}

.admin-brand {

    height: 50px;

    display: flex;

    align-items: center;

    gap: 10px;

    color: #fff;

    text-decoration: none;

    white-space: nowrap;

}

.admin-brand:hover {

    color: #fff;

}

.admin-brand-logo {

    width: 43px;

    height: 43px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    flex-shrink: 0;

    border: 1px solid rgba(255, 255, 255, .35);

    border-radius: 13px;

    background: rgba(255, 255, 255, .14);

    font-size: 17px;

    font-weight: 900;

}

.admin-brand-name {

    font-size: 22px;

    line-height: 1.05;

    font-weight: 850;

}

.admin-brand-sub {

    margin-top: 3px;

    color: rgba(255, 255, 255, .72);

    font-size: 10px;

    font-weight: 700;

    letter-spacing: .35px;

}

.admin-search-area {

    position: relative;

    min-width: 0;

}

.admin-search-box {

    width: 100%;

    height: 50px;

    display: flex;

    align-items: center;

    padding: 4px;

    border-radius: 11px;

    background: #fff;

    box-shadow: 0 5px 16px rgba(103, 0, 12, .16);

}

.admin-search-icon {

    padding-left: 10px;

    color: #98a2b3;

    font-size: 16px;

}

.admin-search-box input {

    min-width: 0;

    flex: 1;

    height: 42px;

    padding: 0 11px;

    border: 0;

    outline: 0;

    background: transparent;

    color: #202124;

    font-size: 14px;

}

.admin-search-box input::placeholder {

    color: #9ba2ad;

}

.admin-search-button {

    height: 42px;

    min-width: 82px;

    padding: 0 14px;

    border: 0;

    border-radius: 8px;

    background: var(--admin-red);

    color: #fff;

    font-size: 12px;

    font-weight: 850;

}

.admin-search-button:hover {

    background: var(--admin-red-dark);

}

.admin-search-results {

    position: absolute;

    z-index: 1600;

    top: 56px;

    left: 0;

    right: 0;

    max-height: 520px;

    overflow-y: auto;

    border: 1px solid #eceff3;

    border-radius: 14px;

    background: #fff;

    color: #1f2937;

    box-shadow: 0 20px 45px rgba(80, 0, 10, .22);

}

.admin-search-results-head {

    position: sticky;

    top: 0;

    z-index: 2;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 12px;

    padding: 12px 14px;

    border-bottom: 1px solid #eef0f3;

    background: #fff;

}

.admin-search-results-title {

    font-size: 13px;

    font-weight: 850;

}

.admin-search-results-close {

    color: #98a2b3;

    text-decoration: none;

    font-size: 18px;

    line-height: 1;

}

.admin-search-group {

    border-bottom: 1px solid #f0f1f3;

}

.admin-search-group-title {

    padding: 9px 14px 6px;

    color: #98a2b3;

    font-size: 10px;

    font-weight: 900;

    letter-spacing: .55px;

    text-transform: uppercase;

}

.admin-search-result-item {

    display: block;

    padding: 9px 14px;

    color: #344054;

    text-decoration: none;

    transition: .15s ease;

}

.admin-search-result-item:hover {

    background: #fff5f6;

    color: #b80014;

}

.admin-search-result-main {

    font-size: 12px;

    font-weight: 800;

}

.admin-search-result-sub {

    margin-top: 2px;

    overflow: hidden;

    color: #8a94a3;

    font-size: 10px;

    line-height: 1.45;

    text-overflow: ellipsis;

    white-space: nowrap;

}

.admin-search-no-result {

    padding: 28px 16px;

    color: #8a94a3;

    text-align: center;

    font-size: 12px;

}

.admin-quick-links {

    display: flex;

    align-items: center;

    gap: 6px;

    margin-top: 8px;

    overflow-x: auto;

    padding-bottom: 2px;

    scrollbar-width: none;

}

.admin-quick-links::-webkit-scrollbar {

    display: none;

}

.admin-quick-link {

    flex: 0 0 auto;

    display: inline-flex;

    align-items: center;

    gap: 5px;

    min-height: 27px;

    padding: 0 9px;

    border: 1px solid rgba(255, 255, 255, .26);

    border-radius: 8px;

    background: rgba(255, 255, 255, .09);

    color: rgba(255, 255, 255, .90);

    text-decoration: none;

    font-size: 11px;

    font-weight: 700;

    white-space: nowrap;

}

.admin-quick-link:hover,

.admin-quick-link.active {

    border-color: #fff;

    background: #fff;

    color: var(--admin-red);

}

.admin-main-status {

    height: 50px;

    display: flex;

    align-items: center;

    justify-content: flex-end;

}

.admin-system-status {

    display: inline-flex;

    align-items: center;

    gap: 8px;

    min-height: 40px;

    padding: 0 12px;

    border: 1px solid rgba(255, 255, 255, .30);

    border-radius: 10px;

    background: rgba(255, 255, 255, .10);

    color: #fff;

    font-size: 11px;

    font-weight: 800;

    white-space: nowrap;

}

.admin-system-status-dot {

    width: 8px;

    height: 8px;

    border-radius: 50%;

    background: #6ff09a;

    box-shadow: 0 0 0 4px rgba(111, 240, 154, .14);

}

@media (max-width: 1199px) {

    .admin-mainbar-inner {

        grid-template-columns: 205px minmax(0, 1fr) 125px;

        gap: 14px;

    }

    .admin-brand-name {

        font-size: 19px;

    }

    .admin-search-button {

        min-width: 70px;

    }

}

</style>

<div class="admin-desktop-navbar-only">

<header class="admin-shop-header">

    <div class="admin-topbar">

        <div class="container admin-topbar-inner">

            <div class="admin-top-left">

                <span class="admin-channel">

                    <span class="admin-channel-dot"></span>

                    Kênh quản trị iphoneStore

                </span>

                <span class="admin-context">Quản lý bán hàng · vận hành hệ thống</span>

            </div>

            <div class="admin-top-right">

                <?php if (!empty($navUser)): ?>

                    <div class="admin-notification-area">

                        <a href="<?= htmlspecialchars((string)$navNotificationPageLink) ?>"

                            class="admin-notification-trigger"

                        >

                            <span>🔔</span>

                            <span>Thông báo</span>

                            <?php if ((int)$navUnreadCount > 0): ?>

                                <span class="admin-notification-count">

                                    <?= (int)$navUnreadCount > 99

                                        ? '99+'

                                        : (int)$navUnreadCount ?>

                                </span>

                            <?php endif; ?>

                        </a>

                        <span class="admin-hover-bridge" aria-hidden="true"></span>

                        <div class="admin-notification-panel">

                            <div class="admin-notification-header">

                                <div class="admin-notification-title">

                                    Thông báo quản trị

                                </div>

                                <div class="admin-notification-unread">

                                    <?= (int)$navUnreadCount ?> chưa đọc

                                </div>

                            </div>

                            <?php if (empty($navNotifications)): ?>

                                <div class="admin-notification-empty">

                                    Chưa có thông báo quản trị nào.

                                </div>

                            <?php else: ?>

                                <div class="admin-notification-list">

                                    <?php foreach ($navNotifications as $navNotification): ?>

                                        <?php

                                        $type = trim(

                                            (string)($navNotification['loai'] ?? 'he_thong')

                                        );

                                        $icon = $navNotificationIcons[$type] ?? '🔔';

                                        $unread = (int)(

                                            $navNotification['da_doc'] ?? 0

                                        ) === 0;

                                        ?>

                                        <div class="admin-notification-item <?= $unread ? 'unread' : '' ?>">

                                            <div class="admin-notification-icon">

                                                <?= $icon ?>

                                            </div>

                                            <a href="<?= htmlspecialchars( $navNotificationOpenBase . (int)$navNotification['id'] ) ?>"

                                                class="admin-notification-link"

                                            >

                                                <div class="admin-notification-item-title">

                                                    <?= htmlspecialchars(

                                                        (string)$navNotification['tieu_de']

                                                    ) ?>

                                                </div>

                                                <div class="admin-notification-text">

                                                    <?= htmlspecialchars(

                                                        (string)$navNotification['noi_dung']

                                                    ) ?>

                                                </div>

                                                <div class="admin-notification-time">

                                                    <?= !empty($navNotification['ngay_tao'])

                                                        ? date(

                                                            'd/m/Y H:i',

                                                            strtotime($navNotification['ngay_tao'])

                                                        )

                                                        : '-' ?>

                                                </div>

                                            </a>

                                            <form method="POST" action="<?= htmlspecialchars( (string)$navNotificationDeleteAction ) ?>"

                                                class="admin-notification-delete-form"

                                                onsubmit="return confirm('Xóa thông báo này?');"

                                            >

                                                <input type="hidden" name="id" value="<?= (int)$navNotification['id'] ?>"

                                                >

                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars( (string)$navNotificationCsrf ) ?>"

                                                >

                                                <input type="hidden" name="return_to" value="<?= htmlspecialchars( (string)( $_SERVER['REQUEST_URI'] ?? $navNotificationPageLink ) ) ?>"

                                                >

                                                <button type="submit" class="admin-notification-delete" title="Xóa thông báo">×</button>

                                            </form>

                                        </div>

                                    <?php endforeach; ?>

                                </div>

                            <?php endif; ?>

                            <div class="admin-notification-footer">

                                <a href="<?= htmlspecialchars( (string)$navNotificationPageLink ) ?>"

                                    class="admin-notification-all"

                                >

                                    Xem tất cả thông báo

                                </a>

                            </div>

                        </div>

                    </div>

                    <div class="admin-account">

                        <button type="button" class="admin-account-button">

                            👤 <?= htmlspecialchars((string)$navHoTen) ?> ▾

                        </button>

                        <span class="admin-hover-bridge" aria-hidden="true"></span>

                        <div class="admin-account-menu">

                            <div class="admin-account-label">

                                Tài khoản quản trị

                            </div>

                            <a href="/iPhoneStore/admin/tai_khoan/thong_tin.php">👤 Thông tin tài khoản</a>

                            <a href="/iPhoneStore/admin/tai_khoan/doi_mat_khau.php">🔐 Đổi mật khẩu</a>

                            <div class="admin-account-divider"></div>

                            <a href="/iPhoneStore/auth/logout.php" class="admin-logout">🚪 Đăng xuất</a>

                        </div>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </div>

    <div class="admin-mainbar">

        <div class="container">

            <div class="admin-mainbar-inner">

                <a href="/iPhoneStore/admin/index.php" class="admin-brand">

                    <span class="admin-brand-logo">iS</span>

                    <span>

                        <span class="admin-brand-name d-block">iphoneStore</span>

                        <span class="admin-brand-sub d-block">ADMIN MANAGEMENT</span>

                    </span>

                </a>

                <div class="admin-search-area">

                    <form class="admin-search-box" method="GET" action="/iPhoneStore/admin/index.php">

                        <span class="admin-search-icon">🔎</span>

                        <input type="text" name="admin_q" value="<?= htmlspecialchars($adminSearch) ?>"

                            placeholder="Tìm đơn hàng, mã vận đơn, sản phẩm, khách hàng, email, thanh toán..."

                            autocomplete="off"

                        >

                        <button type="submit" class="admin-search-button">Tìm kiếm</button>

                    </form>

                    <?php if ($adminSearch !== ''): ?>

                        <div class="admin-search-results">

                            <div class="admin-search-results-head">

                                <div class="admin-search-results-title">

                                    <?= $adminSearchCount ?> kết quả cho

                                    “<?= htmlspecialchars($adminSearch) ?>”

                                </div>

                                <a href="/iPhoneStore/admin/index.php" class="admin-search-results-close" title="Đóng">×</a>

                            </div>

                            <?php if ($adminSearchCount === 0): ?>

                                <div class="admin-search-no-result">

                                    Không tìm thấy dữ liệu phù hợp.

                                </div>

                            <?php endif; ?>

                            <?php if (!empty($adminSearchResults['functions'])): ?>

                                <div class="admin-search-group">

                                    <div class="admin-search-group-title">

                                        Chức năng quản trị

                                    </div>

                                    <?php foreach ($adminSearchResults['functions'] as $item): ?>

                                        <a href="<?= htmlspecialchars($item['url']) ?>"

                                            class="admin-search-result-item"

                                        >

                                            <div class="admin-search-result-main">

                                                <?= htmlspecialchars($item['icon']) ?>

                                                <?= htmlspecialchars($item['label']) ?>

                                            </div>

                                            <div class="admin-search-result-sub">

                                                Mở chức năng quản trị

                                            </div>

                                        </a>

                                    <?php endforeach; ?>

                                </div>

                            <?php endif; ?>

                            <?php if (!empty($adminSearchResults['orders'])): ?>

                                <div class="admin-search-group">

                                    <div class="admin-search-group-title">

                                        Đơn hàng

                                    </div>

                                    <?php foreach ($adminSearchResults['orders'] as $order): ?>

                                        <a href="/iPhoneStore/admin/don_hang/chi_tiet.php?id=<?= (int)$order['id'] ?>"

                                            class="admin-search-result-item"

                                        >

                                            <div class="admin-search-result-main">

                                                <?php if (!empty($order['ma_van_don'])): ?>

                                                    Mã vận đơn: <?= htmlspecialchars((string)$order['ma_van_don']) ?>

                                                <?php else: ?>

                                                    Chưa có mã vận đơn

                                                <?php endif; ?>

                                            </div>

                                            <?php

                                            $orderStatusLabels = [

                                                'cho_xu_ly' => 'Chờ xử lý',

                                                'da_duyet' => 'Đã duyệt',

                                                'dang_giao' => 'Đang giao',

                                                'hoan_thanh' => 'Hoàn thành',

                                                'huy' => 'Đã hủy'

                                            ];

                                            $orderStatus = (string)($order['trang_thai'] ?? '');

                                            $orderStatusLabel = $orderStatusLabels[$orderStatus] ?? $orderStatus;

                                            ?>

                                            <div class="admin-search-result-sub">

                                                Đơn nội bộ #<?= (int)$order['id'] ?>

                                                · <?= htmlspecialchars((string)($order['ho_ten'] ?? 'Chưa có tên')) ?>

                                                · <?= htmlspecialchars($orderStatusLabel) ?>

                                                · <?= number_format((float)$order['tong_tien'], 0, ',', '.') ?>đ

                                            </div>

                                        </a>

                                    <?php endforeach; ?>

                                </div>

                            <?php endif; ?>

                            <?php if (!empty($adminSearchResults['products'])): ?>

                                <div class="admin-search-group">

                                    <div class="admin-search-group-title">

                                        Sản phẩm

                                    </div>

                                    <?php foreach ($adminSearchResults['products'] as $product): ?>

                                        <a href="/iPhoneStore/admin/san_pham/chi_tiet.php?id=<?= (int)$product['id'] ?>"

                                            class="admin-search-result-item"

                                        >

                                            <div class="admin-search-result-main">

                                                <?= htmlspecialchars(

                                                    (string)$product['ten_san_pham']

                                                ) ?>

                                            </div>

                                            <div class="admin-search-result-sub">

                                                Mã:

                                                <?= htmlspecialchars(

                                                    (string)($product['ma_san_pham'] ?? '-')

                                                ) ?>

                                                · Tồn:

                                                <?= (int)($product['so_luong'] ?? 0) ?>

                                            </div>

                                        </a>

                                    <?php endforeach; ?>

                                </div>

                            <?php endif; ?>

                            <?php if (!empty($adminSearchResults['users'])): ?>

                                <div class="admin-search-group">

                                    <div class="admin-search-group-title">

                                        Người dùng

                                    </div>

                                    <?php foreach ($adminSearchResults['users'] as $user): ?>

                                        <a href="/iPhoneStore/admin/nguoi_dung/index.php" class="admin-search-result-item">

                                            <div class="admin-search-result-main">

                                                <?= htmlspecialchars(

                                                    (string)($user['ho_ten'] ?? 'Chưa có tên')

                                                ) ?>

                                            </div>

                                            <div class="admin-search-result-sub">

                                                <?= htmlspecialchars(

                                                    (string)($user['email'] ?? '-')

                                                ) ?>

                                                · <?= htmlspecialchars(

                                                    (string)($user['so_dien_thoai'] ?? '-')

                                                ) ?>

                                            </div>

                                        </a>

                                    <?php endforeach; ?>

                                </div>

                            <?php endif; ?>

                            <?php if (!empty($adminSearchResults['payments'])): ?>

                                <div class="admin-search-group">

                                    <div class="admin-search-group-title">

                                        Thanh toán

                                    </div>

                                    <?php foreach ($adminSearchResults['payments'] as $payment): ?>

                                        <a href="/iPhoneStore/admin/don_hang/chi_tiet.php?id=<?= (int)$payment['don_hang_id'] ?>"

                                            class="admin-search-result-item"

                                        >

                                            <div class="admin-search-result-main">

                                                Thanh toán #<?= (int)$payment['id'] ?>

                                                · Đơn #<?= (int)$payment['don_hang_id'] ?>

                                            </div>

                                            <div class="admin-search-result-sub">

                                                <?= htmlspecialchars(

                                                    (string)($payment['phuong_thuc'] ?? '-')

                                                ) ?>

                                                · <?= htmlspecialchars(

                                                    (string)($payment['trang_thai'] ?? '-')

                                                ) ?>

                                            </div>

                                        </a>

                                    <?php endforeach; ?>

                                </div>

                            <?php endif; ?>

                            <?php if (!empty($adminSearchResults['notifications'])): ?>

                                <div class="admin-search-group">

                                    <div class="admin-search-group-title">

                                        Thông báo

                                    </div>

                                    <?php foreach ($adminSearchResults['notifications'] as $notification): ?>

                                        <a href="/iPhoneStore/admin/thong_bao/index.php" class="admin-search-result-item">

                                            <div class="admin-search-result-main">

                                                <?= htmlspecialchars(

                                                    (string)$notification['tieu_de']

                                                ) ?>

                                            </div>

                                            <div class="admin-search-result-sub">

                                                <?= htmlspecialchars(

                                                    (string)$notification['noi_dung']

                                                ) ?>

                                            </div>

                                        </a>

                                    <?php endforeach; ?>

                                </div>

                            <?php endif; ?>

                        </div>

                    <?php endif; ?>

                    <div class="admin-quick-links">

                        <?php foreach ($adminLinks as $link): ?>

                            <?php

                            $active = $link['match'] === '/admin/index.php'

                                ? preg_match(

                                    '#/admin/?(?:index\.php)?$#i',

                                    $adminPath

                                ) === 1

                                : str_contains(

                                    $adminPath,

                                    $link['match']

                                );

                            ?>

                            <a href="<?= htmlspecialchars($link['url']) ?>"

                                class="admin-quick-link <?= $active ? 'active' : '' ?>"

                            >

                                <span><?= htmlspecialchars($link['icon']) ?></span>

                                <span><?= htmlspecialchars($link['label']) ?></span>

                            </a>

                        <?php endforeach; ?>

                    </div>

                </div>

                <div class="admin-main-status">

                    <div class="admin-system-status">

                        <span class="admin-system-status-dot"></span>

                        Hệ thống hoạt động

                    </div>

                </div>

            </div>

        </div>

    </div>

</header>

</div>
