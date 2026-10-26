<?php

/*

\|--------------------------------------------------------------------------

\| NAVBAR MOBILE USER / GUEST

\|--------------------------------------------------------------------------

\| File này chỉ hiển thị giao diện mobile cho khách hàng và khách chưa đăng nhập.

\| Dữ liệu chung vẫn lấy từ navbar.php.

*/

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

</style>

<div class="mobile-navbar-only">

    <header class="mobile-shop-header">

        <div class="container mobile-shop-bar">

            <div class="mobile-shop-row">

                <button type="button" class="mobile-menu-button" id="mobileNavbarMenuButton"

                        aria-label="Mở menu" aria-controls="mobileNavbarDrawer" aria-expanded="false">☰</button>

                <a href="<?= htmlspecialchars($homeLink) ?>" class="mobile-brand">

                    <span class="mobile-brand-icon">🍎</span>

                    <span class="mobile-brand-text">iPhoneStore</span>

                </a>

                <?php if ($navRole === 'user'): ?>

                    <a href="/iPhoneStore/user/gio_hang/index.php" class="mobile-cart-link"

                       aria-label="Giỏ hàng" title="Giỏ hàng">

                        <svg class="mobile-cart-svg" viewBox="0 0 24 24" fill="none" stroke-width="1.8"

                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">

                            <path d="M3 3h2l2.1 10.2a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 2-1.6L20.4 7H6.2"></path>

                            <circle cx="9" cy="20" r="1.2"></circle>

                            <circle cx="18" cy="20" r="1.2"></circle>

                        </svg>

                        <?php if ($miniCartQuantity > 0): ?>

                            <span class="mobile-cart-count"><?= $miniCartQuantity > 99 ? '99+' : $miniCartQuantity ?></span>

                        <?php endif; ?>

                    </a>

                <?php else: ?>

                    <a href="/iPhoneStore/auth/login.php" class="mobile-login-link"

                       aria-label="Đăng nhập" title="Đăng nhập">👤</a>

                <?php endif; ?>

            </div>

            <div class="mobile-search-wrap">

                <form class="mobile-search-box" method="GET" action="/iPhoneStore/user/index.php">

                    <input type="text" name="keyword" placeholder="Tìm iPhone 17, iPhone 16, iPhone 15..."

                           value="<?= htmlspecialchars($_GET['keyword'] ?? '') ?>" autocomplete="off">

                    <button type="submit" class="mobile-search-submit" aria-label="Tìm kiếm sản phẩm">🔍</button>

                </form>

                <?php $mobileCurrentKeyword = trim((string)($_GET['keyword'] ?? '')); ?>

                <div class="mobile-quick-links">

                    <?php foreach ($iphoneQuickSearches as $iphoneKeyword): ?>

                        <a href="/iPhoneStore/user/index.php?<?= http_build_query(['keyword' => $iphoneKeyword]) ?>"

                           class="mobile-quick-link <?= strcasecmp($mobileCurrentKeyword, $iphoneKeyword) === 0 ? 'active' : '' ?>">

                            <?= htmlspecialchars($iphoneKeyword) ?>

                        </a>

                    <?php endforeach; ?>

                </div>

            </div>

        </div>

    </header>

    <div class="mobile-drawer-overlay" id="mobileNavbarOverlay" aria-hidden="true"></div>

    <aside class="mobile-drawer" id="mobileNavbarDrawer" aria-hidden="true" aria-label="Menu điều hướng">

        <div class="mobile-drawer-header">

            <a href="<?= htmlspecialchars($homeLink) ?>" class="mobile-drawer-brand">

                <span class="mobile-drawer-brand-icon">🍎</span>

                <span class="mobile-drawer-brand-name">iPhoneStore</span>

            </a>

            <button type="button" class="mobile-drawer-close" id="mobileNavbarClose" aria-label="Đóng menu">×</button>

        </div>

        <?php if ($navUser): ?>

            <div class="mobile-profile">

                <div class="mobile-profile-avatar"><?= htmlspecialchars($navInitial) ?></div>

                <div class="mobile-profile-info">

                    <div class="mobile-profile-name"><?= htmlspecialchars($navHoTen !== '' ? $navHoTen : 'Tài khoản') ?></div>

                    <div class="mobile-profile-role">Khách hàng</div>

                </div>

            </div>

        <?php endif; ?>

        <div class="mobile-drawer-scroll">

            <div class="mobile-menu-section">

                <div class="mobile-menu-title">Mua sắm</div>

                <a href="/iPhoneStore/user/index.php" class="mobile-menu-link">

                    <span class="mobile-menu-icon">⌂</span>

                    <span class="mobile-menu-text">Trang chủ</span>

                </a>

                <a href="/iPhoneStore/user/index.php?keyword=iPhone" class="mobile-menu-link">

                    <span class="mobile-menu-icon">▣</span>

                    <span class="mobile-menu-text">Sản phẩm iPhone</span>

                </a>

                <?php if ($navRole === 'user'): ?>

                    <a href="/iPhoneStore/user/gio_hang/index.php" class="mobile-menu-link">

                        <span class="mobile-menu-icon">▤</span>

                        <span class="mobile-menu-text">Giỏ hàng</span>

                        <?php if ($miniCartQuantity > 0): ?>

                            <span class="mobile-menu-badge"><?= $miniCartQuantity > 99 ? '99+' : $miniCartQuantity ?></span>

                        <?php endif; ?>

                    </a>

                    <a href="/iPhoneStore/user/don_hang/don_hang_da_dat.php" class="mobile-menu-link">

                        <span class="mobile-menu-icon">◫</span>

                        <span class="mobile-menu-text">Đơn hàng của tôi</span>

                    </a>

                <?php endif; ?>

            </div>

            <div class="mobile-menu-divider"></div>

            <?php if ($navRole === 'user'): ?>

                <div class="mobile-menu-section">

                    <div class="mobile-menu-title">Tài khoản</div>

                    <a href="/iPhoneStore/user/thong_bao/index.php" class="mobile-menu-link">

                        <span class="mobile-menu-icon">◉</span>

                        <span class="mobile-menu-text">Thông báo</span>

                        <?php if ($navUnreadCount > 0): ?>

                            <span class="mobile-menu-badge"><?= $navUnreadCount > 99 ? '99+' : $navUnreadCount ?></span>

                        <?php endif; ?>

                    </a>

                    <a href="/iPhoneStore/user/tai_khoan/thong_tin.php" class="mobile-menu-link">

                        <span class="mobile-menu-icon">●</span>

                        <span class="mobile-menu-text">Tài khoản của tôi</span>

                    </a>

                    <a href="/iPhoneStore/user/tai_khoan/doi_mat_khau.php" class="mobile-menu-link">

                        <span class="mobile-menu-icon">◆</span>

                        <span class="mobile-menu-text">Đổi mật khẩu</span>

                    </a>

                </div>

                <div class="mobile-menu-divider"></div>

                <a href="/iPhoneStore/auth/logout.php" class="mobile-menu-link logout">

                    <span class="mobile-menu-icon">↪</span>

                    <span class="mobile-menu-text">Đăng xuất</span>

                </a>

            <?php else: ?>

                <a href="/iPhoneStore/auth/login.php" class="mobile-menu-link">

                    <span class="mobile-menu-icon">→</span>

                    <span class="mobile-menu-text">Đăng nhập</span>

                </a>

                <a href="/iPhoneStore/auth/register.php" class="mobile-menu-link">

                    <span class="mobile-menu-icon">＋</span>

                    <span class="mobile-menu-text">Đăng ký</span>

                </a>

            <?php endif; ?>

        </div>

        <div class="mobile-drawer-footer">iPhoneStore · Điều hướng nhanh</div>

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
