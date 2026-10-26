<?php

/*

    NAVBAR DESKTOP

    - Chỉ hiển thị từ 992px trở lên.

    - Giữ nguyên cấu trúc navbar desktop hiện tại.

    - Dữ liệu chung lấy từ navbar.php.

*/

?>

<style>

.desktop-navbar-only {

    display: block;

}

@media (max-width: 991px) {

    .desktop-navbar-only {

        display: none !important;

    }

}

/*

    Z-INDEX PRIORITY:

    account dropdown: 1500

    top-right/account area: 1400+

    mini cart: 1150

    mainbar: 1100

*/

:root {

    --nav-red: #d70018;

    --nav-red-dark: #b80014;

    --nav-red-soft: #e11d32;

    --nav-text: #202124;

    --nav-border: #ececec;

}

/* =========================================================

   HEADER

========================================================= */

.shop-header {

    position: sticky;

    top: 0;

    z-index: 1200;

    background: linear-gradient(

        180deg,

        #e45464 -13%,

        #d70018

    );

    color: #fff;

    box-shadow: 0 2px 12px rgba(0, 0, 0, .10);

    will-change: transform;

}

/* =========================================================

   TOP BAR

========================================================= */

.shop-topbar {

    position: relative;

    z-index: 1300;

    overflow: visible;

    height: 38px;

    background: rgba(128, 0, 14, .14);

    font-size: 13px;

}

.shop-topbar .container {

    height: 100%;

}

.top-left,

.top-right {

    display: flex;

    align-items: center;

}

.top-right {

    position: relative;

    z-index: 1400;

}

.shop-topbar .top-link {

    position: relative;

    padding: 0 11px;

    color: #fff;

    text-decoration: none;

    white-space: nowrap;

}

.shop-topbar .top-link:first-child {

    padding-left: 0;

}

.shop-topbar .top-link:not(:last-child)::after {

    content: "";

    position: absolute;

    top: 3px;

    right: 0;

    width: 1px;

    height: 15px;

    background: rgba(255, 255, 255, .34);

}

.shop-topbar .top-link:hover {

    color: #fff;

    opacity: .84;

}

/* =========================================================

   NOTIFICATION DROPDOWN

========================================================= */

.notification-area {

    position: relative;

    z-index: 1470;

    height: 38px;

    display: flex;

    align-items: center;

}

/*

    Vùng hover phủ toàn bộ chiều cao topbar.

    Không còn khoảng hở giữa chữ "Thông báo" và panel.

*/

.notification-trigger {

    height: 38px;

    display: inline-flex;

    align-items: center;

    gap: 6px;

    padding: 0 14px !important;

    cursor: pointer;

}

.notification-count {

    min-width: 18px;

    height: 18px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    padding: 0 5px;

    border: 1px solid rgba(255, 255, 255, .75);

    border-radius: 999px;

    background: #fff;

    color: var(--nav-red);

    font-size: 10px;

    line-height: 1;

    font-weight: 900;

}

.notification-panel {

    position: absolute;

    z-index: 1520;

    top: 100%;

    right: 0;

    width: 390px;

    max-height: 390px;

    overflow: visible;

    border: 1px solid #ececec;

    border-radius: 13px;

    background: #fff;

    color: #222;

    box-shadow: 0 16px 42px rgba(0, 0, 0, .20);

    opacity: 0;

    visibility: hidden;

    pointer-events: none;

    transform: translateY(8px);

    transition:

        opacity .16s ease,

        visibility .16s ease,

        transform .16s ease;

}

.notification-panel::before {

    content: "";

    position: absolute;

    top: -8px;

    right: 34px;

    width: 16px;

    height: 16px;

    transform: rotate(45deg);

    border-top: 1px solid #ececec;

    border-left: 1px solid #ececec;

    background: #fff;

}

.notification-area:hover .notification-panel,

.notification-area:focus-within .notification-panel {

    opacity: 1;

    visibility: visible;

    pointer-events: auto;

    transform: translateY(0);

}

.notification-panel-header {

    position: relative;

    z-index: 1;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 12px;

    padding: 14px 15px 11px;

    border-bottom: 1px solid #f0f0f0;

}

.notification-panel-title {

    color: #333;

    font-size: 14px;

    font-weight: 850;

}

.notification-panel-unread {

    color: var(--nav-red);

    font-size: 11px;

    font-weight: 750;

}

.notification-list {

    max-height: 245px;

    overflow-y: auto;

    overscroll-behavior: contain;

}

.notification-item {

    display: grid;

    grid-template-columns:

        39px

        minmax(0, 1fr)

        28px;

    gap: 10px;

    align-items: start;

    padding: 12px 10px 12px 14px;

    border-bottom: 1px solid #f2f2f2;

    color: inherit;

    transition: .15s ease;

}

.notification-item:hover {

    background: #fff7f8;

}

.notification-item.unread {

    background: #fff8f9;

}

.notification-item.unread:hover {

    background: #fff1f3;

}

.notification-item-link {

    min-width: 0;

    color: inherit;

    text-decoration: none;

}

.notification-item-link:hover {

    color: inherit;

}

.notification-delete-form {

    margin: 0;

    align-self: start;

}

.notification-delete-btn {

    width: 28px;

    height: 28px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    padding: 0;

    border: 0;

    border-radius: 7px;

    background: transparent;

    color: #aaa;

    font-size: 17px;

    line-height: 1;

    cursor: pointer;

}

.notification-delete-btn:hover {

    background: #ffe9ec;

    color: var(--nav-red);

}

.notification-item-icon {

    width: 38px;

    height: 38px;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 11px;

    background: #f4f5f7;

    font-size: 18px;

}

.notification-item-body {

    min-width: 0;

}

.notification-item-title {

    display: flex;

    align-items: center;

    gap: 6px;

    color: #2b2b2b;

    font-size: 12px;

    line-height: 1.4;

    font-weight: 850;

}

.notification-unread-dot {

    width: 7px;

    height: 7px;

    flex: 0 0 7px;

    border-radius: 50%;

    background: var(--nav-red);

}

.notification-item-text {

    overflow: hidden;

    display: -webkit-box;

    margin-top: 3px;

    color: #727272;

    font-size: 11px;

    line-height: 1.45;

    -webkit-line-clamp: 2;

    -webkit-box-orient: vertical;

}

.notification-item-time {

    margin-top: 5px;

    color: #aaa;

    font-size: 10px;

}

.notification-empty {

    min-height: 190px;

    display: flex;

    flex-direction: column;

    align-items: center;

    justify-content: center;

    padding: 26px;

    color: #888;

    text-align: center;

}

.notification-empty-icon {

    margin-bottom: 8px;

    font-size: 35px;

}

.notification-panel-footer {

    padding: 10px 14px;

    background: #fff;

    border-radius: 0 0 13px 13px;

}

.notification-view-all {

    min-height: 38px;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 8px;

    background: #fff4f5;

    color: var(--nav-red) !important;

    text-decoration: none;

    font-size: 12px;

    font-weight: 850;

}

.notification-view-all:hover {

    background: #ffe9ec;

}

.notification-retention-note {

    margin-top: 7px;

    color: #aaa;

    font-size: 9px;

    line-height: 1.4;

    text-align: center;

}

/* =========================================================

   ACCOUNT

========================================================= */

.account-dropdown {

    position: relative;

    z-index: 1450;

}

.account-btn {

    border: 0;

    background: transparent;

    color: #fff;

    padding: 0 8px;

    font-size: 13px;

    white-space: nowrap;

}

.account-btn:hover,

.account-btn:focus {

    color: #fff;

}

.account-dropdown .dropdown-menu {

    z-index: 1500 !important;

    min-width: 220px;

    padding: 8px 0;

    border: 0;

    border-radius: 10px;

    box-shadow: 0 10px 30px rgba(0, 0, 0, .18);

}

.account-dropdown .dropdown-item {

    color: #333 !important;

    padding: 10px 16px;

    font-size: 14px;

    text-decoration: none;

}

.account-dropdown .dropdown-item:hover {

    color: var(--nav-red) !important;

    background: #fff4f5;

}

.account-dropdown .logout-item {

    color: #dc3545 !important;

}

.account-dropdown .account-menu-label {

    padding: 7px 16px 5px;

    color: #999;

    font-size: 10px;

    line-height: 1.2;

    font-weight: 900;

    letter-spacing: .7px;

    text-transform: uppercase;

}

.account-dropdown .account-menu-divider {

    margin: 6px 0;

    border-color: #eeeeee;

    opacity: 1;

}

/* =========================================================

   KẾT NỐI

========================================================= */

.social-connect {

    display: inline-flex;

    align-items: center;

    gap: 7px;

    margin-left: 4px;

}

.social-label {

    color: rgba(255, 255, 255, .86);

    font-size: 12px;

    white-space: nowrap;

}

.social-icon-link {

    width: 27px;

    height: 27px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    border: 1px solid rgba(255, 255, 255, .35);

    border-radius: 50%;

    background: rgba(255, 255, 255, .10);

    color: #fff;

    text-decoration: none;

    transition: .18s ease;

}

.social-icon-link:hover {

    transform: translateY(-2px);

    background: #fff;

    color: var(--nav-red);

}

.zalo-icon {

    font-size: 9px;

    font-weight: 900;

    letter-spacing: -.4px;

}

.facebook-icon {

    font-family: Arial, sans-serif;

    font-size: 18px;

    font-weight: 900;

}

/* =========================================================

   MAIN BAR

   Dùng grid để logo - search - cart luôn thẳng hàng.

========================================================= */

.shop-mainbar {

    position: relative;

    z-index: 1100;

    padding: 15px 0 9px;

}

.mainbar-inner {

    display: grid;

    grid-template-columns:

        220px

        minmax(0, 1fr)

        82px;

    column-gap: 24px;

    align-items: start;

}

/* =========================================================

   LOGO

========================================================= */

.shop-logo {

    height: 52px;

    display: flex;

    align-items: center;

    align-self: start;

    color: #fff;

    text-decoration: none;

    white-space: nowrap;

}

.shop-logo:hover {

    color: #fff;

}

.logo-icon {

    margin-right: 8px;

    font-size: 39px;

    line-height: 1;

}

.logo-text {

    font-size: 29px;

    line-height: 1;

    font-weight: 800;

    letter-spacing: -.7px;

}

/* =========================================================

   SEARCH

========================================================= */

.search-area {

    min-width: 0;

}

.search-box {

    width: 100%;

    height: 52px;

    display: flex;

    align-items: center;

    padding: 4px;

    border-radius: 10px;

    background: #fff;

    box-shadow: 0 4px 14px rgba(0, 0, 0, .14);

}

.search-box input {

    min-width: 0;

    flex: 1;

    height: 44px;

    padding: 0 15px;

    border: 0;

    outline: 0;

    background: transparent;

    color: #222;

    box-shadow: none !important;

    font-size: 15px;

}

.search-btn {

    width: 66px;

    height: 44px;

    flex-shrink: 0;

    border: 0;

    border-radius: 8px;

    background: var(--nav-red);

    color: #fff;

    font-size: 19px;

}

.search-btn:hover {

    background: var(--nav-red-dark);

}

/* =========================================================

   GỢI Ý DÒNG IPHONE

========================================================= */

.iphone-quick-links {

    display: flex;

    align-items: center;

    gap: 6px 13px;

    margin-top: 8px;

    overflow-x: auto;

    padding: 0 2px 2px;

    scrollbar-width: none;

}

.iphone-quick-links::-webkit-scrollbar {

    display: none;

}

.iphone-quick-link {

    position: relative;

    flex: 0 0 auto;

    color: rgba(255, 255, 255, .92);

    font-size: 12px;

    line-height: 1.45;

    text-decoration: none;

    white-space: nowrap;

}

.iphone-quick-link:not(:last-child)::after {

    content: "";

    position: absolute;

    top: 3px;

    right: -7px;

    width: 1px;

    height: 13px;

    background: rgba(255, 255, 255, .30);

}

.iphone-quick-link:hover,

.iphone-quick-link.active {

    color: #fff;

    text-decoration: underline;

    text-underline-offset: 3px;

}

/* =========================================================

   CART

========================================================= */

.cart-area {

    position: relative;

    z-index: 1100;

    width: 82px;

    height: 52px;

    display: flex;

    align-items: center;

    justify-content: center;

    align-self: start;

}

.cart-trigger {

    position: relative;

    width: 58px;

    height: 52px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    color: #fff;

    text-decoration: none;

}

.cart-trigger:hover {

    color: #fff;

}

.cart-svg {

    width: 36px;

    height: 36px;

    stroke: currentColor;

}

.cart-count {

    position: absolute;

    top: 0;

    right: 2px;

    min-width: 20px;

    height: 20px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    padding: 0 5px;

    border: 2px solid var(--nav-red);

    border-radius: 999px;

    background: #fff;

    color: var(--nav-red);

    font-size: 10px;

    line-height: 1;

    font-weight: 900;

}

/* =========================================================

   MINI CART HOVER

========================================================= */

.mini-cart-panel {

    position: absolute;

    z-index: 1150;

    top: 52px;

    right: 0;

    width: 390px;

    overflow: visible;

    border: 1px solid #ededed;

    border-radius: 12px;

    background: #fff;

    color: #222;

    box-shadow: 0 14px 38px rgba(0, 0, 0, .18);

    opacity: 0;

    visibility: hidden;

    pointer-events: none;

    transform: translateY(8px);

    transition:

        opacity .16s ease,

        visibility .16s ease,

        transform .16s ease;

}

.mini-cart-panel::before {

    content: "";

    position: absolute;

    top: -9px;

    right: 25px;

    width: 18px;

    height: 18px;

    transform: rotate(45deg);

    border-top: 1px solid #ededed;

    border-left: 1px solid #ededed;

    background: #fff;

}

.cart-area:hover .mini-cart-panel,

.cart-area:focus-within .mini-cart-panel {

    opacity: 1;

    visibility: visible;

    pointer-events: auto;

    transform: translateY(0);

}

.mini-cart-title {

    position: relative;

    z-index: 1;

    padding: 15px 16px 11px;

    border-bottom: 1px solid #f0f0f0;

    color: #555;

    font-size: 13px;

    font-weight: 800;

}

/* Empty cart */

.mini-cart-empty {

    min-height: 285px;

    display: flex;

    flex-direction: column;

    align-items: center;

    justify-content: center;

    padding: 30px 20px;

    text-align: center;

}

.empty-cart-illustration {

    width: 108px;

    height: 108px;

    display: flex;

    align-items: center;

    justify-content: center;

    margin-bottom: 15px;

    border-radius: 50%;

    background:

        radial-gradient(

            circle at 35% 26%,

            #ffd59f 0 3px,

            transparent 4px

        ),

        radial-gradient(

            circle at 74% 18%,

            #cfebe9 0 7px,

            transparent 8px

        ),

        #c4dc62;

}

.empty-cart-svg {

    width: 72px;

    height: 72px;

}

.empty-cart-title {

    color: #424242;

    font-size: 18px;

    font-weight: 700;

}

.empty-cart-note {

    margin-top: 5px;

    color: #999;

    font-size: 12px;

}

/* Cart items */

.mini-cart-list {

    max-height: 355px;

    overflow-y: auto;

}

.mini-cart-item {

    display: grid;

    grid-template-columns:

        64px

        minmax(0, 1fr)

        auto;

    gap: 11px;

    align-items: center;

    padding: 12px 14px;

    border-bottom: 1px solid #f1f1f1;

    color: inherit;

    text-decoration: none;

}

.mini-cart-item:hover {

    background: #fff7f8;

    color: inherit;

}

.mini-cart-image,

.mini-cart-no-image {

    width: 64px;

    height: 64px;

    border: 1px solid #ececec;

    border-radius: 9px;

    background: #fff;

}

.mini-cart-image {

    object-fit: contain;

    padding: 3px;

}

.mini-cart-no-image {

    display: flex;

    align-items: center;

    justify-content: center;

    color: #aaa;

    font-size: 9px;

}

.mini-cart-info {

    min-width: 0;

}

.mini-cart-name {

    overflow: hidden;

    display: -webkit-box;

    -webkit-line-clamp: 2;

    -webkit-box-orient: vertical;

    color: #252525;

    font-size: 13px;

    line-height: 1.45;

    font-weight: 750;

}

.mini-cart-variant {

    overflow: hidden;

    margin-top: 4px;

    color: #8a8a8a;

    font-size: 11px;

    text-overflow: ellipsis;

    white-space: nowrap;

}

.mini-cart-price-box {

    min-width: 94px;

    text-align: right;

}

.mini-cart-price {

    color: var(--nav-red);

    font-size: 13px;

    font-weight: 800;

    white-space: nowrap;

}

.mini-cart-qty {

    margin-top: 4px;

    color: #888;

    font-size: 11px;

}

.mini-cart-more {

    padding: 9px 14px;

    border-bottom: 1px solid #f1f1f1;

    color: #777;

    font-size: 11px;

    text-align: center;

}

.mini-cart-footer {

    padding: 13px 14px 14px;

    background: #fff;

    border-radius: 0 0 12px 12px;

}

.mini-cart-summary {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 14px;

    margin-bottom: 11px;

}

.mini-cart-summary-label {

    color: #777;

    font-size: 12px;

}

.mini-cart-total {

    color: var(--nav-red);

    font-size: 16px;

    font-weight: 850;

}

.mini-cart-button {

    min-height: 42px;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 8px;

    background: var(--nav-red);

    color: #fff !important;

    text-decoration: none;

    font-size: 13px;

    font-weight: 800;

}

.mini-cart-button:hover {

    background: var(--nav-red-dark);

}

/* Guest cart */

.mini-cart-login {

    min-height: 225px;

    display: flex;

    flex-direction: column;

    align-items: center;

    justify-content: center;

    padding: 25px;

    text-align: center;

}

.mini-cart-login-icon {

    margin-bottom: 10px;

    font-size: 42px;

}

.mini-cart-login-text {

    color: #555;

    font-size: 14px;

}

.mini-cart-login-btn {

    margin-top: 14px;

    padding: 9px 20px;

    border-radius: 8px;

    background: var(--nav-red);

    color: #fff !important;

    text-decoration: none;

    font-size: 13px;

    font-weight: 800;

}

/* =========================================================

   ADMIN

========================================================= */

.admin-status {

    padding: 5px;

    background: #212529;

    color: #fff;

    text-align: center;

    font-size: 13px;

}

/* =========================================================

   RESPONSIVE

========================================================= */

@media (max-width: 1199px) {

    .mainbar-inner {

        grid-template-columns:

            185px

            minmax(0, 1fr)

            72px;

        column-gap: 17px;

    }

    .logo-text {

        font-size: 25px;

    }

    .logo-icon {

        font-size: 35px;

    }

}

@media (max-width: 991px) {

    .shop-topbar {

        display: none;

    }

    .shop-mainbar {

        padding: 11px 0;

    }

    .mainbar-inner {

        grid-template-columns:

            minmax(0, 1fr)

            62px;

        gap: 10px;

    }

    .shop-logo {

        grid-column: 1;

        grid-row: 1;

        height: 46px;

    }

    .cart-area {

        grid-column: 2;

        grid-row: 1;

        width: 62px;

        height: 46px;

    }

    .cart-trigger {

        height: 46px;

    }

    .search-area {

        grid-column: 1 / -1;

        grid-row: 2;

    }

    .search-box {

        height: 48px;

    }

    .search-box input,

    .search-btn {

        height: 40px;

    }

    .logo-icon {

        font-size: 31px;

    }

    .logo-text {

        font-size: 23px;

    }

    /*

        Trên điện thoại không dùng hover panel,

        nhấn icon sẽ đi thẳng vào trang giỏ hàng.

    */

    .mini-cart-panel {

        display: none;

    }

}

@media (max-width: 575px) {

    .iphone-quick-link {

        font-size: 11px;

    }

    .search-box input {

        font-size: 13px;

    }

    .search-btn {

        width: 54px;

    }

}

</style>

<div class="desktop-navbar-only">

<header class="shop-header">

    <!-- =========================

         TOP BAR

    ========================= -->

    <div class="shop-topbar">

        <div class="container d-flex justify-content-between align-items-center">

            <!-- TRÁI -->

            <div class="top-left">

                <?php if ($navRole === 'admin'): ?>

                    <a href="/iPhoneStore/admin/index.php" class="top-link">Kênh quản trị</a>

                <?php else: ?>

                    <a href="/iPhoneStore/user/index.php" class="top-link">iphoneStore</a>

                <?php endif; ?>

                <a href="/iPhoneStore/user/ai/chat.php" class="top-link">Hỗ trợ mua hàng</a>

                <a href="#" class="top-link">Chính sách bảo hành</a>

                <?php if ($navRole === 'user'): ?>

                    <a href="/iPhoneStore/user/don_hang/don_hang_da_dat.php" class="top-link">Tra cứu đơn hàng</a>

                <?php endif; ?>

                <div class="social-connect">

                    <span class="social-label">Kết nối</span>

                    <a href="<?= htmlspecialchars($zaloLink) ?>"

                        class="social-icon-link"

                        title="Kết nối qua Zalo"

                        target="_blank"

                        rel="noopener noreferrer"

                    >

                        <span class="zalo-icon">Zalo</span>

                    </a>

                    <a href="<?= htmlspecialchars($facebookLink) ?>"

                        class="social-icon-link"

                        title="Kết nối qua Facebook"

                        target="_blank"

                        rel="noopener noreferrer"

                    >

                        <span class="facebook-icon">f</span>

                    </a>

                </div>

            </div>

            <!-- PHẢI -->

            <div class="top-right">

                <?php if (

                    $navUser &&

                    in_array(

                        $navRole,

                        ['user', 'admin'],

                        true

                    )

                ): ?>

                    <div class="notification-area">

                        <a href="<?= htmlspecialchars( $navNotificationPageLink ) ?>"

                            class="top-link notification-trigger"

                            aria-label="Thông báo"

                        >

                            <span>🔔</span>

                            <span>Thông báo</span>

                            <?php if ($navUnreadCount > 0): ?>

                                <span class="notification-count">

                                    <?= $navUnreadCount > 99

                                        ? '99+'

                                        : $navUnreadCount ?>

                                </span>

                            <?php endif; ?>

                        </a>

                        <div class="notification-panel">

                            <div class="notification-panel-header">

                                <div class="notification-panel-title">

                                    Thông báo mới

                                </div>

                                <div class="notification-panel-unread">

                                    <?= $navUnreadCount ?>

                                    chưa đọc

                                </div>

                            </div>

                            <?php if (empty($navNotifications)): ?>

                                <div class="notification-empty">

                                    <div class="notification-empty-icon">

                                        🔕

                                    </div>

                                    <div>

                                        Chưa có thông báo nào.

                                    </div>

                                </div>

                            <?php else: ?>

                                <div class="notification-list">

                                    <?php foreach ($navNotifications as $navNotification): ?>

                                        <?php

                                        $notificationType =

                                            trim(

                                                (string)(

                                                    $navNotification['loai']

                                                    ?? 'he_thong'

                                                )

                                            );

                                        $notificationIcon =

                                            $navNotificationIcons[

                                                $notificationType

                                            ]

                                            ?? '🔔';

                                        $notificationUnread =

                                            (int)(

                                                $navNotification['da_doc']

                                                ?? 0

                                            ) === 0;

                                        ?>

                                        <div class="notification-item <?= $notificationUnread ? 'unread' : '' ?>"

                                        >

                                            <div class="notification-item-icon">

                                                <?= $notificationIcon ?>

                                            </div>

                                            <a href="<?= htmlspecialchars( $navNotificationOpenBase . (int)$navNotification['id'] ) ?>"

                                                class="notification-item-link"

                                            >

                                                <div class="notification-item-body">

                                                    <div class="notification-item-title">

                                                        <span>

                                                            <?= htmlspecialchars(

                                                                (string)$navNotification['tieu_de']

                                                            ) ?>

                                                        </span>

                                                        <?php if ($notificationUnread): ?>

                                                            <span class="notification-unread-dot" title="Chưa đọc"></span>

                                                        <?php endif; ?>

                                                    </div>

                                                    <div class="notification-item-text">

                                                        <?= htmlspecialchars(

                                                            (string)$navNotification['noi_dung']

                                                        ) ?>

                                                    </div>

                                                    <div class="notification-item-time">

                                                        <?= !empty(

                                                            $navNotification['ngay_tao']

                                                        )

                                                            ? date(

                                                                'd/m/Y H:i',

                                                                strtotime(

                                                                    $navNotification['ngay_tao']

                                                                )

                                                            )

                                                            : '-' ?>

                                                    </div>

                                                </div>

                                            </a>

                                            <form method="POST" action="<?= htmlspecialchars( $navNotificationDeleteAction ) ?>"

                                                class="notification-delete-form"

                                                onsubmit="return confirm(

                                                    'Xóa thông báo này?'

                                                );"

                                            >

                                                <input type="hidden" name="id" value="<?= (int)$navNotification['id'] ?>"

                                                >

                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars( $navNotificationCsrf ) ?>"

                                                >

                                                <input

                                                    type="hidden"

                                                    name="return_to"

                                                    value="<?= htmlspecialchars(

                                                        (string)(

                                                            $_SERVER['REQUEST_URI']

                                                            ?? $navNotificationPageLink

                                                        )

                                                    ) ?>"

                                                >

                                                <button type="submit" class="notification-delete-btn" title="Xóa thông báo" aria-label="Xóa thông báo">×</button>

                                            </form>

                                        </div>

                                    <?php endforeach; ?>

                                </div>

                            <?php endif; ?>

                            <div class="notification-panel-footer">

                                <a href="<?= htmlspecialchars( $navNotificationPageLink ) ?>"

                                    class="notification-view-all"

                                >

                                    Xem tất cả thông báo

                                </a>

                                <div class="notification-retention-note">

                                    Đã đọc tự xóa sau 30 ngày ·

                                    Chưa đọc giữ tối đa 90 ngày

                                </div>

                            </div>

                        </div>

                    </div>

                <?php else: ?>

                    <a href="/iPhoneStore/auth/login.php" class="top-link">🔔 Thông báo</a>

                <?php endif; ?>

                <?php if ($navUser): ?>

                    <div class="dropdown account-dropdown">

                        <button type="button" class="account-btn dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">

                            👤 <?= htmlspecialchars($navHoTen) ?>

                        </button>

                        <ul class="dropdown-menu dropdown-menu-end">

                            <?php if ($navRole === 'user'): ?>

                                <li class="account-menu-label">

                                    Mua hàng

                                </li>

                                <li><a class="dropdown-item" href="/iPhoneStore/user/don_hang/don_hang_da_dat.php">📦 Đơn hàng của tôi</a></li>

                                <li>

                                    <a class="dropdown-item" href="/iPhoneStore/user/thong_bao/index.php">

                                        🔔 Thông báo

                                        <?php if ($navUnreadCount > 0): ?>

                                            <span class="badge bg-danger ms-1">

                                                <?= $navUnreadCount > 99

                                                    ? '99+'

                                                    : $navUnreadCount ?>

                                            </span>

                                        <?php endif; ?>

                                    </a>

                                </li>

                                <li>

                                    <hr class="dropdown-divider account-menu-divider">

                                </li>

                                <li class="account-menu-label">

                                    Tài khoản

                                </li>

                                <li><a class="dropdown-item" href="/iPhoneStore/user/tai_khoan/thong_tin.php">👤 Tài khoản của tôi</a></li>

                                <li><a class="dropdown-item" href="/iPhoneStore/user/tai_khoan/doi_mat_khau.php">🔐 Đổi mật khẩu</a></li>

                            <?php endif; ?>

                            <?php if ($navRole === 'admin'): ?>

                                <li><a class="dropdown-item" href="/iPhoneStore/admin/tai_khoan/thong_tin.php">👤 Thông tin tài khoản</a></li>

                                <li><a class="dropdown-item" href="/iPhoneStore/admin/tai_khoan/doi_mat_khau.php">🔐 Đổi mật khẩu</a></li>

                                <li><a class="dropdown-item" href="/iPhoneStore/admin/index.php">⚙️ Trang quản trị</a></li>

                            <?php endif; ?>

                            <li>

                                <hr class="dropdown-divider">

                            </li>

                            <li><a class="dropdown-item logout-item" href="/iPhoneStore/auth/logout.php">🚪 Đăng xuất</a></li>

                        </ul>

                    </div>

                <?php else: ?>

                    <a href="/iPhoneStore/auth/register.php" class="top-link">Đăng ký</a>

                    <a href="/iPhoneStore/auth/login.php" class="top-link">Đăng nhập</a>

                <?php endif; ?>

            </div>

        </div>

    </div>

    <!-- =========================

         MAIN BAR

    ========================= -->

    <div class="shop-mainbar">

        <div class="container">

            <div class="mainbar-inner">

                <!-- LOGO -->

                <a href="<?= $homeLink ?>" class="shop-logo">

                    <span class="logo-icon">🍎</span>

                    <span class="logo-text">iPhoneStore</span>

                </a>

                <!-- SEARCH -->

                <div class="search-area">

                    <form class="search-box" method="GET" action="/iPhoneStore/user/index.php">

                        <input type="text" name="keyword" placeholder="Tìm iPhone 17, iPhone 16, iPhone 15..." value="<?= htmlspecialchars($_GET['keyword'] ?? '') ?>"

                            autocomplete="off"

                        >

                        <button type="submit" class="search-btn" aria-label="Tìm kiếm sản phẩm" title="Tìm kiếm">🔍</button>

                    </form>

                    <!-- =========================================

                         GỢI Ý NHANH DÒNG IPHONE

                         Nhấn vào sẽ tìm bằng keyword như ô search.

                    ========================================== -->

                    <div class="iphone-quick-links">

                        <?php

                        $currentKeyword =

                            trim(

                                (string)(

                                    $_GET['keyword']

                                    ?? ''

                                )

                            );

                        ?>

                        <?php foreach ($iphoneQuickSearches as $iphoneKeyword): ?>

                            <a href="/iPhoneStore/user/index.php?<?= http_build_query([ 'keyword' => $iphoneKeyword

                                ]) ?>"

                                class="iphone-quick-link <?= strcasecmp(

                                    $currentKeyword,

                                    $iphoneKeyword

                                ) === 0 ? 'active' : '' ?>"

                            >

                                <?= htmlspecialchars($iphoneKeyword) ?>

                            </a>

                        <?php endforeach; ?>

                    </div>

                </div>

                <!-- CART -->

                <div class="cart-area">

                    <?php if ($navRole === 'user'): ?>

                        <a href="/iPhoneStore/user/gio_hang/index.php" class="cart-trigger" title="Giỏ hàng" aria-label="Giỏ hàng">

                            <svg

                                class="cart-svg"

                                viewBox="0 0 24 24"

                                fill="none"

                                stroke-width="1.8"

                                stroke-linecap="round"

                                stroke-linejoin="round"

                                aria-hidden="true"

                            >

                                <path d="M3 3h2l2.1 10.2a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 2-1.6L20.4 7H6.2"></path>

                                <circle cx="9" cy="20" r="1.2"></circle>

                                <circle cx="18" cy="20" r="1.2"></circle>

                            </svg>

                            <?php if ($miniCartQuantity > 0): ?>

                                <span class="cart-count">

                                    <?= $miniCartQuantity > 99

                                        ? '99+'

                                        : $miniCartQuantity ?>

                                </span>

                            <?php endif; ?>

                        </a>

                        <!-- MINI CART HOVER -->

                        <div class="mini-cart-panel">

                            <?php if (empty($miniCartItems)): ?>

                                <div class="mini-cart-empty">

                                    <div class="empty-cart-illustration">

                                        <svg class="empty-cart-svg" viewBox="0 0 120 120" aria-hidden="true">

                                            <path d="M30 41h60l-5 51H35z" fill="#ffe79b" />

                                            <path d="M34 41h52l5-10H39z" fill="#f6d56c" />

                                            <path d="M46 58c2 13 10 20 21 20s19-7 21-20" fill="none" stroke="#805b2d" stroke-width="3" stroke-linecap="round" />

                                            <circle cx="48" cy="56" r="3" fill="#805b2d" />

                                            <circle cx="86" cy="56" r="3" fill="#805b2d" />

                                            <rect

                                                x="80"

                                                y="79"

                                                width="27"

                                                height="17"

                                                rx="3"

                                                fill="#cdebec"

                                                stroke="#86c7cc"

                                                stroke-width="2"

                                            />

                                        </svg>

                                    </div>

                                    <div class="empty-cart-title">

                                        Chưa có sản phẩm

                                    </div>

                                    <div class="empty-cart-note">

                                        Sản phẩm bạn thêm vào giỏ sẽ hiển thị tại đây.

                                    </div>

                                </div>

                            <?php else: ?>

                                <div class="mini-cart-title">

                                    Sản phẩm mới thêm

                                </div>

                                <div class="mini-cart-list">

                                    <?php foreach ($miniCartItems as $cartItem): ?>

                                        <?php

                                        $variantParts = [];

                                        if (!empty($cartItem['ten_mau'])) {

                                            $variantParts[] =

                                                $cartItem['ten_mau'];

                                        }

                                        if (!empty($cartItem['ram'])) {

                                            $variantParts[] =

                                                $cartItem['ram'];

                                        }

                                        if (!empty($cartItem['rom'])) {

                                            $variantParts[] =

                                                $cartItem['rom'];

                                        }

                                        $variantText =

                                            implode(

                                                ' · ',

                                                $variantParts

                                            );

                                        ?>

                                        <a href="/iPhoneStore/user/san_pham/chi_tiet.php?id=<?= (int)$cartItem['san_pham_id'] ?>"

                                            class="mini-cart-item"

                                        >

                                            <?php if (!empty($cartItem['hinh_anh'])): ?>

                                                <img src="/iPhoneStore/uploads/<?= htmlspecialchars( (string)$cartItem['hinh_anh'] ) ?>"

                                                    class="mini-cart-image"

                                                    alt="<?= htmlspecialchars(

                                                        (string)$cartItem['ten_san_pham']

                                                    ) ?>"

                                                >

                                            <?php else: ?>

                                                <div class="mini-cart-no-image">

                                                    No image

                                                </div>

                                            <?php endif; ?>

                                            <div class="mini-cart-info">

                                                <div class="mini-cart-name">

                                                    <?= htmlspecialchars(

                                                        (string)$cartItem['ten_san_pham']

                                                    ) ?>

                                                </div>

                                                <?php if ($variantText !== ''): ?>

                                                    <div class="mini-cart-variant">

                                                        <?= htmlspecialchars($variantText) ?>

                                                    </div>

                                                <?php endif; ?>

                                            </div>

                                            <div class="mini-cart-price-box">

                                                <div class="mini-cart-price">

                                                    <?= number_format(

                                                        (float)$cartItem['gia'],

                                                        0,

                                                        ',',

                                                        '.'

                                                    ) ?>đ

                                                </div>

                                                <div class="mini-cart-qty">

                                                    x<?= (int)$cartItem['so_luong'] ?>

                                                </div>

                                            </div>

                                        </a>

                                    <?php endforeach; ?>

                                </div>

                                <?php if ($miniCartTotalRows > count($miniCartItems)): ?>

                                    <div class="mini-cart-more">

                                        Còn

                                        <?= $miniCartTotalRows - count($miniCartItems) ?>

                                        sản phẩm khác trong giỏ.

                                    </div>

                                <?php endif; ?>

                                <div class="mini-cart-footer">

                                    <div class="mini-cart-summary">

                                        <span class="mini-cart-summary-label">Tạm tính</span>

                                        <span class="mini-cart-total">

                                            <?= number_format(

                                                $miniCartTotal,

                                                0,

                                                ',',

                                                '.'

                                            ) ?>đ

                                        </span>

                                    </div>

                                    <a href="/iPhoneStore/user/gio_hang/index.php" class="mini-cart-button">Xem giỏ hàng</a>

                                </div>

                            <?php endif; ?>

                        </div>

                    <?php elseif ($navRole === 'admin'): ?>

                        <a href="/iPhoneStore/admin/index.php" class="btn btn-outline-light btn-sm">Quản trị</a>

                    <?php else: ?>

                        <a href="/iPhoneStore/auth/login.php" class="cart-trigger" title="Đăng nhập để xem giỏ hàng" aria-label="Đăng nhập để xem giỏ hàng">

                            <svg

                                class="cart-svg"

                                viewBox="0 0 24 24"

                                fill="none"

                                stroke-width="1.8"

                                stroke-linecap="round"

                                stroke-linejoin="round"

                                aria-hidden="true"

                            >

                                <path d="M3 3h2l2.1 10.2a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 2-1.6L20.4 7H6.2"></path>

                                <circle cx="9" cy="20" r="1.2"></circle>

                                <circle cx="18" cy="20" r="1.2"></circle>

                            </svg>

                        </a>

                        <div class="mini-cart-panel">

                            <div class="mini-cart-login">

                                <div class="mini-cart-login-icon">

                                    🛒

                                </div>

                                <div class="mini-cart-login-text">

                                    Đăng nhập để xem sản phẩm trong giỏ hàng.

                                </div>

                                <a href="/iPhoneStore/auth/login.php" class="mini-cart-login-btn">Đăng nhập</a>

                            </div>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>

</header>

<!-- <?php if ($navRole === 'admin'): ?>

    <div class="admin-status">

        Đang đăng nhập với quyền <b>ADMIN</b>

    </div>

<?php endif; ?> -->

</div>
