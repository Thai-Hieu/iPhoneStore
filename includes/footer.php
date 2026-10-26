<?php

/*

\|--------------------------------------------------------------------------

\| IphoneStore - FOOTER DÙNG CHUNG USER / ADMIN

\|--------------------------------------------------------------------------

|

\| - Khu user: giữ footer cửa hàng đầy đủ như hiện tại.

\| - Khu admin: dùng footer tối, hiện đại, cùng phong cách với user

\|   nhưng không lặp lại các nút quản trị đã có ở phía trên/sidebar.

|

\| Lưu ý:

\| Phân quyền truy cập admin vẫn phải do middleware/check_admin.php xử lý.

\| File footer chỉ quyết định giao diện hiển thị.

|

*/

$requestPathFooter = parse_url(

    $_SERVER['REQUEST_URI'] ?? '',

    PHP_URL_PATH

);

$requestPathFooter = is_string($requestPathFooter)

    ? $requestPathFooter

    : '';

$isAdminFooter = preg_match(

    '#/iPhoneStore/admin(?:/|$)#i',

    $requestPathFooter

) === 1;

?>

<style>

/* =========================================================

   STYLE CHUNG

========================================================= */

.store-footer {

    margin-top: 60px;

    background: linear-gradient(135deg, #0d0f13 0%, #12151b 55%, #171b22 100%);

    color: #aeb4bf;

    font-size: 14px;

}

.store-footer-main {

    padding: 52px 0 38px;

}

.store-footer-grid {

    display: grid;

    grid-template-columns: 1.2fr .95fr 1fr 1.45fr;

    gap: 42px;

    align-items: start;

}

.store-footer-brand {

    display: inline-flex;

    align-items: center;

    gap: 11px;

    margin-bottom: 18px;

    color: #fff;

    text-decoration: none;

    font-size: 26px;

    font-weight: 800;

}

.store-footer-brand:hover {

    color: #fff;

}

.store-footer-logo-box {

    width: 48px;

    height: 48px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    flex-shrink: 0;

    border-radius: 15px;

    background: linear-gradient(135deg, #ff6b4a, #ee4d2d);

    color: #fff;

    font-size: 19px;

    font-weight: 900;

    box-shadow:

        0 10px 24px rgba(238, 77, 45, .22);

}

.store-footer-title {

    margin-bottom: 19px;

    color: #fff;

    font-size: 15px;

    font-weight: 800;

    text-transform: uppercase;

    letter-spacing: .35px;

}

.store-footer-text {

    margin-bottom: 11px;

    color: #aeb4bf;

    line-height: 1.75;

}

.store-footer-list {

    margin: 0;

    padding: 0;

    list-style: none;

}

.store-footer-list li {

    position: relative;

    margin-bottom: 11px;

    padding-left: 18px;

    color: #aeb4bf;

    line-height: 1.65;

}

.store-footer-list li::before {

    content: "•";

    position: absolute;

    left: 0;

    top: 0;

    color: #ff6240;

    font-weight: 900;

}

.store-footer-links {

    margin: 0;

    padding: 0;

    list-style: none;

}

.store-footer-links li {

    margin-bottom: 11px;

}

.store-footer-links a {

    color: #aeb4bf;

    text-decoration: none;

    transition:

        color .2s ease,

        padding-left .2s ease;

}

.store-footer-links a:hover {

    color: #ff6b4a;

    padding-left: 4px;

}

.store-footer-contact {

    display: flex;

    align-items: flex-start;

    gap: 11px;

    margin-bottom: 15px;

    color: #aeb4bf;

}

.store-footer-contact-icon {

    width: 26px;

    flex-shrink: 0;

    font-size: 17px;

}

.store-footer-contact strong {

    color: #fff;

}

.store-footer-badges {

    display: flex;

    flex-wrap: wrap;

    gap: 8px;

    margin-top: 17px;

}

.store-footer-badge {

    display: inline-flex;

    align-items: center;

    min-height: 34px;

    padding: 6px 11px;

    border: 1px solid #2d333d;

    border-radius: 9px;

    background: #191d24;

    color: #e9edf3;

    font-size: 11px;

    font-weight: 800;

}

.store-footer-map {

    overflow: hidden;

    border: 1px solid #2b3038;

    border-radius: 14px;

    background: #171a20;

}

.store-footer-map iframe {

    width: 100%;

    height: 205px;

    display: block;

    border: 0;

}

.store-footer-address {

    margin-bottom: 14px;

    color: #aeb4bf;

    line-height: 1.75;

}

.store-footer-address strong {

    color: #fff;

}

.store-footer-bottom {

    border-top: 1px solid #292e36;

    background: rgba(0, 0, 0, .25);

    padding: 17px 0;

}

.store-footer-bottom-inner {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 18px;

}

.store-footer-bottom strong {

    color: #fff;

}

.store-footer-bottom-note {

    color: #818995;

    font-size: 12px;

}

.store-footer-social {

    display: flex;

    gap: 9px;

    margin-top: 20px;

}

.store-footer-social a {

    width: 38px;

    height: 38px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    border: 1px solid #2b3038;

    border-radius: 50%;

    background: #191d24;

    color: #fff;

    text-decoration: none;

    transition:

        transform .2s ease,

        background .2s ease,

        border-color .2s ease;

}

.store-footer-social a:hover {

    transform: translateY(-2px);

    border-color: #ff6240;

    background: #ee4d2d;

}

@media (max-width: 1199.98px) {

    .store-footer-grid {

        grid-template-columns: repeat(2, 1fr);

    }

}

@media (max-width: 767.98px) {

    .store-footer {

        margin-top: 45px;

    }

    .store-footer-main {

        padding: 40px 0 28px;

    }

    .store-footer-grid {

        grid-template-columns: 1fr;

        gap: 32px;

    }

    .store-footer-bottom-inner {

        align-items: flex-start;

        flex-direction: column;

    }

    .store-footer-map iframe {

        height: 250px;

    }

}

/* =========================================================
   RESPONSIVE FOOTER
========================================================= */
.store-footer-grid-user {
    grid-template-columns: 1.25fr .9fr 1fr 1fr 1.6fr;
}

.store-footer-bottom-links {
    display: flex;
    flex-wrap: wrap;
    gap: 18px;
}

.store-footer-bottom-links a {
    color: #9aa3af;
    text-decoration: none;
    font-size: 12px;
    transition: color .2s ease;
}

.store-footer-bottom-links a:hover {
    color: #fff;
}

@media (max-width: 1199.98px) {
    .store-footer-main {
        padding: 44px 0 34px;
    }

    .store-footer-grid,
    .store-footer-grid-user {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 34px 30px;
    }

    .store-footer-grid-user > div:last-child {
        grid-column: 1 / -1;
    }

    .store-footer-map iframe {
        height: 220px;
    }
}

@media (max-width: 991.98px) {
    .store-footer {
        margin-top: 48px;
        font-size: 13px;
    }

    .store-footer-main {
        padding: 38px 0 28px;
    }

    .store-footer-brand {
        margin-bottom: 14px;
        font-size: 22px;
    }

    .store-footer-logo-box {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        font-size: 17px;
    }

    .store-footer-title {
        margin-bottom: 14px;
        font-size: 13px;
    }

    .store-footer-text {
        margin-bottom: 8px;
        line-height: 1.65;
    }

    .store-footer-list li,
    .store-footer-links li {
        margin-bottom: 8px;
    }

    .store-footer-contact {
        gap: 9px;
        margin-bottom: 11px;
    }

    .store-footer-badges {
        gap: 6px;
        margin-top: 12px;
    }

    .store-footer-badge {
        min-height: 30px;
        padding: 5px 9px;
        border-radius: 8px;
        font-size: 10px;
    }

    .store-footer-social {
        margin-top: 16px;
    }

    .store-footer-social a {
        width: 35px;
        height: 35px;
    }
}

@media (max-width: 767.98px) {
    .store-footer {
        margin-top: 36px;
    }

    .store-footer-main {
        padding: 30px 0 22px;
    }

    .store-footer-grid,
    .store-footer-grid-user {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 26px 18px;
    }

    .store-footer-grid > div:first-child,
    .store-footer-grid-user > div:first-child,
    .store-footer-grid > div:last-child,
    .store-footer-grid-user > div:last-child {
        grid-column: 1 / -1;
    }

    .store-footer-brand {
        font-size: 21px;
    }

    .store-footer-title {
        margin-bottom: 12px;
        font-size: 12px;
    }

    .store-footer-map {
        border-radius: 12px;
    }

    .store-footer-map iframe {
        height: 190px;
    }

    .store-footer-address {
        margin-bottom: 10px;
        line-height: 1.6;
    }

    .store-footer-bottom {
        padding: 14px 0;
    }

    .store-footer-bottom-inner {
        align-items: center;
        flex-direction: column;
        gap: 10px;
        text-align: center;
    }

    .store-footer-bottom-links {
        justify-content: center;
        gap: 12px 16px;
    }
}

@media (max-width: 575.98px) {
    .store-footer {
        margin-top: 28px;
        font-size: 12px;
    }

    .store-footer-main {
        padding: 26px 0 18px;
    }

    .store-footer-grid,
    .store-footer-grid-user {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 22px 14px;
    }

    .store-footer-grid > div:first-child,
    .store-footer-grid-user > div:first-child,
    .store-footer-grid > div:last-child,
    .store-footer-grid-user > div:last-child {
        grid-column: 1 / -1;
    }

    .store-footer-brand {
        gap: 9px;
        margin-bottom: 11px;
        font-size: 19px;
    }

    .store-footer-logo-box {
        width: 38px;
        height: 38px;
        border-radius: 11px;
        font-size: 15px;
    }

    .store-footer-title {
        margin-bottom: 10px;
        font-size: 11px;
        letter-spacing: .25px;
    }

    .store-footer-text {
        font-size: 12px;
        line-height: 1.55;
    }

    .store-footer-list li {
        margin-bottom: 7px;
        padding-left: 14px;
        font-size: 11px;
        line-height: 1.5;
    }

    .store-footer-links li {
        margin-bottom: 7px;
    }

    .store-footer-links a {
        font-size: 11px;
    }

    .store-footer-links a:hover {
        padding-left: 0;
    }

    .store-footer-contact {
        gap: 7px;
        margin-bottom: 9px;
        font-size: 11px;
    }

    .store-footer-contact-icon {
        width: 20px;
        font-size: 14px;
    }

    .store-footer-badge {
        min-height: 27px;
        padding: 4px 8px;
        font-size: 9px;
    }

    .store-footer-social {
        gap: 8px;
        margin-top: 13px;
    }

    .store-footer-social a {
        width: 32px;
        height: 32px;
        font-size: 12px;
    }

    .store-footer-address {
        font-size: 11px;
    }

    .store-footer-map iframe {
        height: 165px;
    }

    .store-footer-bottom {
        padding: 12px 0;
    }

    .store-footer-bottom-inner {
        font-size: 10.5px;
    }

    .store-footer-bottom-note,
    .store-footer-bottom-links a {
        font-size: 10px;
    }
}

@media (max-width: 380px) {
    .store-footer-grid,
    .store-footer-grid-user {
        grid-template-columns: 1fr;
        gap: 18px;
    }

    .store-footer-grid > div,
    .store-footer-grid-user > div {
        grid-column: auto !important;
    }

    .store-footer-brand {
        font-size: 18px;
    }

    .store-footer-map iframe {
        height: 150px;
    }
}

</style>

<?php if ($isAdminFooter): ?>

<!-- =========================================================

     FOOTER ADMIN

     Cùng phong cách footer user nhưng nội dung dành cho quản trị.

     Không lặp lại menu/nút điều hướng đã có phía trên.

========================================================= -->



<footer class="store-footer">

    <div class="store-footer-main">

        <div class="container-fluid px-3 px-lg-4">

            <div class="store-footer-grid">

                <!-- CỘT 1 -->

                <div>

                    <div class="store-footer-brand">

                        <span class="store-footer-logo-box">

                            iS

                        </span>

                        <span>

                            iphoneStore Admin

                        </span>

                    </div>

                    <p class="store-footer-text">

                        Khu vực quản trị tập trung của iphoneStore,

                        hỗ trợ theo dõi hoạt động bán hàng và vận hành

                        hệ thống một cách rõ ràng, nhất quán.

                    </p>

                    <p class="store-footer-text">

                        Dữ liệu đơn hàng, thanh toán, tồn kho và thông báo

                        được quản lý theo đúng quy trình của cửa hàng.

                    </p>

                </div>

                <!-- CỘT 2 -->

                <div>

                    <h6 class="store-footer-title">

                        Hệ thống quản trị

                    </h6>

                    <ul class="store-footer-list">

                        <li>

                            Quản lý sản phẩm và từng biến thể.

                        </li>

                        <li>

                            Theo dõi đơn hàng và mã vận đơn.

                        </li>

                        <li>

                            Quản lý thanh toán và hoàn tiền riêng biệt.

                        </li>

                        <li>

                            Theo dõi thông báo Email của hệ thống.

                        </li>

                        <li>

                            Tổng hợp doanh thu và tồn kho.

                        </li>

                    </ul>

                </div>

                <!-- CỘT 3 -->

                <div>

                    <h6 class="store-footer-title">

                        Thông tin cửa hàng

                    </h6>

                    <div class="store-footer-contact">

                        <span class="store-footer-contact-icon">

                            📞

                        </span>

                        <div>

                            Hotline:

                            <br>

                            <strong>

                                0962314801

                            </strong>

                        </div>

                    </div>

                    <div class="store-footer-contact">

                        <span class="store-footer-contact-icon">

                            ✉️

                        </span>

                        <div>

                            Email:

                            <br>

                            <strong>

                                iPhoneStore@gmail.com

                            </strong>

                        </div>

                    </div>

                    <div class="store-footer-contact">

                        <span class="store-footer-contact-icon">

                            🕐

                        </span>

                        <div>

                            Giờ làm việc:

                            <br>

                            <strong>

                                08:00 - 22:00

                            </strong>

                        </div>

                    </div>

                    <div class="store-footer-badges">

                        <span class="store-footer-badge">

                            SMTP Email

                        </span>

                        <span class="store-footer-badge">

                            MySQL

                        </span>

                        <span class="store-footer-badge">

                            PHP

                        </span>

                    </div>

                </div>

                <!-- CỘT 4 -->

                <div>

                    <h6 class="store-footer-title">

                        📍 Hệ thống cửa hàng

                    </h6>

                    <div class="store-footer-address">

                        <strong>

                            🍎 iphoneStore

                        </strong>

                        <br>

                        Trường Đại học Công Nghệ Kỹ thuật Vinh

                        <br>

                        Nghệ An, Việt Nam

                    </div>

                    <div class="store-footer-map">

                        <iframe

                            src="https://www.google.com/maps?q=Truong+Dai+hoc+Su+pham+Ky+thuat+Vinh+Nghe+An&output=embed"

                            loading="lazy"

                            allowfullscreen

                            referrerpolicy="no-referrer-when-downgrade"

                            title="Bản đồ IphoneStore"

                        ></iframe>

                    </div>

                </div>

            </div>

        </div>

    </div>

    <div class="store-footer-bottom">

        <div class="container-fluid px-3 px-lg-4">

            <div class="store-footer-bottom-inner">

                <div>

                    © <?= date('Y') ?>

                    <strong>iphoneStore</strong>

                    · Hệ thống quản trị cửa hàng

                </div>

                <div class="store-footer-bottom-note">

                    Khu vực dành cho quản trị viên

                </div>

            </div>

        </div>

    </div>

</footer>

<?php else: ?>

<!-- =========================================================

     FOOTER USER

========================================================= -->

<footer class="store-footer">

    <div class="store-footer-main">

        <div class="container">

            <div class="store-footer-grid store-footer-grid-user">

                <!-- CỘT 1 -->

                <div>

                    <a

                        href="/iPhoneStore/user/index.php"

                        class="store-footer-brand"

                    >

                        <span style="font-size:34px;">

                            🍎

                        </span>

                        <span>

                            iPhoneStore

                        </span>

                    </a>

                    <p class="store-footer-text">

                        Chuyên cung cấp các dòng iPhone,

                        chất lượng cao với mức giá tốt và chế độ

                        bảo hành uy tín.

                    </p>

                    <p class="store-footer-text">

                        Mua iPhone dễ dàng, an toàn và nhanh chóng

                        tại IphoneStore.

                    </p>

                    <div class="store-footer-social">

                        <a href="#" title="Facebook">

                            f

                        </a>

                        <a href="#" title="Instagram">

                            ◎

                        </a>

                        <a href="#" title="TikTok">

                            ♪

                        </a>

                        <a href="#" title="YouTube">

                            ▶

                        </a>

                    </div>

                </div>

                <!-- CỘT 2 -->

                <div>

                    <h6 class="store-footer-title">

                        iPhone

                    </h6>

                    <ul class="store-footer-links">

                        <li>

                            <a href="/iPhoneStore/user/index.php?keyword=iPhone">

                                Tất cả iPhone

                            </a>

                        </li>

                        <li>

                            <a href="/iPhoneStore/user/index.php?keyword=iPhone+17">

                                iPhone 17 Series

                            </a>

                        </li>

                        <li>

                            <a href="/iPhoneStore/user/index.php?keyword=iPhone+16">

                                iPhone 16 Series

                            </a>

                        </li>

                        <li>

                            <a href="/iPhoneStore/user/index.php?keyword=iPhone+15">

                                iPhone 15 Series

                            </a>

                        </li>

                        <li>

                            <a href="/iPhoneStore/user/index.php?keyword=iPhone+14">

                                iPhone 14 Series

                            </a>

                        </li>

                    </ul>

                </div>

                <!-- CỘT 3 -->

                <div>

                    <h6 class="store-footer-title">

                        Hỗ trợ khách hàng

                    </h6>

                    <ul class="store-footer-links">

                        <li>

                            <a href="#">

                                Hướng dẫn mua hàng

                            </a>

                        </li>

                        <li>

                            <a href="#">

                                Chính sách bảo hành

                            </a>

                        </li>

                        <li>

                            <a href="#">

                                Chính sách đổi trả

                            </a>

                        </li>

                        <li>

                            <a href="#">

                                Chính sách vận chuyển

                            </a>

                        </li>

                        <li>

                            <a href="#">

                                Chính sách bảo mật

                            </a>

                        </li>

                        <li>

                            <a href="#">

                                Câu hỏi thường gặp

                            </a>

                        </li>

                    </ul>

                </div>

                <!-- CỘT 4 -->

                <div>

                    <h6 class="store-footer-title">

                        Liên hệ

                    </h6>

                    <div class="store-footer-contact">

                        <span class="store-footer-contact-icon">

                            📞

                        </span>

                        <div>

                            Hotline:

                            <br>

                            <strong>

                                0962314801

                            </strong>

                        </div>

                    </div>

                    <div class="store-footer-contact">

                        <span class="store-footer-contact-icon">

                            ✉️

                        </span>

                        <div>

                            Email:

                            <br>

                            <strong>

                                iPhoneStore@gmail.com

                            </strong>

                        </div>

                    </div>

                    <div class="store-footer-contact">

                        <span class="store-footer-contact-icon">

                            🕐

                        </span>

                        <div>

                            Giờ làm việc:

                            <br>

                            <strong>

                                08:00 - 22:00

                            </strong>

                        </div>

                    </div>

                    <div class="store-footer-badges">

                        <span class="store-footer-badge">

                            VISA

                        </span>

                        <span class="store-footer-badge">

                            COD

                        </span>

                        <span class="store-footer-badge">

                            MOMO

                        </span>

                        <span class="store-footer-badge">

                            BANK

                        </span>

                    </div>

                </div>

                <!-- CỘT 5 -->

                <div>

                    <h6 class="store-footer-title">

                        📍 Hệ thống cửa hàng

                    </h6>

                    <div class="store-footer-address">

                        <strong>

                            🍎 iPhoneStore

                        </strong>

                        <br>

                        Trường Đại học Công Nghệ Kỹ thuật Vinh

                        <br>

                        Nghệ An, Việt Nam

                    </div>

                    <div class="store-footer-map">

                        <iframe

                            src="https://www.google.com/maps?q=Truong+Dai+hoc+Su+pham+Ky+thuat+Vinh+Nghe+An&output=embed"

                            loading="lazy"

                            allowfullscreen

                            referrerpolicy="no-referrer-when-downgrade"

                            title="Bản đồ iPhoneStore"

                        ></iframe>

                    </div>

                </div>

            </div>

        </div>

    </div>

    <div class="store-footer-bottom">

        <div class="container">

            <div class="store-footer-bottom-inner">

                <div>

                    © <?= date('Y') ?>

                    <strong>iphoneStore</strong>

                    - Cửa hàng iPhone.

                </div>

                <div class="store-footer-bottom-links">

                    <a href="#">

                        Chính sách bảo mật

                    </a>

                    <a href="#">

                        Điều khoản sử dụng

                    </a>

                    <a href="#">

                        Liên hệ

                    </a>

                </div>

            </div>

        </div>

    </div>

</footer>

<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>

</html>
