<?php

/*

    USER / ĐÁNH GIÁ SẢN PHẨM



    File này là component được include từ:

    user/san_pham/chi_tiet.php



    Biến cần có từ trang cha:

    - $conn : mysqli

    - $id   : ID sản phẩm

    - $sp   : thông tin sản phẩm

*/



require_once __DIR__ . '/ham_ho_tro.php';



$dgNguoiDungId =

    (int)($_SESSION['user']['id'] ?? 0);



$dgMetaData = dgMeta($conn);



$dgUserColumn =

    $dgMetaData['user'];



$dgContentColumn =

    $dgMetaData['content'];



$dgDateColumn =

    $dgMetaData['date'];



$dgStatusColumn =

    $dgMetaData['status'];



$dgIdColumn =

    $dgMetaData['id'];



$dgCoBangPhanHoi =

    dgCoBangPhanHoiDanhGia($conn);



$dgCoBangHinhAnh =

    dgCoBangHinhAnhDanhGia($conn);



$dgSuccess =

    ($_GET['review'] ?? '') === 'success';



$dgDeleted =

    ($_GET['review'] ?? '') === 'deleted';



$dgError =

    trim(

        (string)(

            $_SESSION['review_error']

            ?? ''

        )

    );



unset($_SESSION['review_error']);



$dgExistingReview = null;

$dgDaMuaSanPham = false;



/* =========================================================

   KIỂM TRA ĐÃ MUA VÀ ĐƠN ĐÃ HOÀN THÀNH

========================================================= */

if ($dgNguoiDungId > 0) {

    $stmtDgPurchased = $conn->prepare("

        SELECT 1

        FROM chi_tiet_don_hang ct



        JOIN don_hang dh

            ON dh.id = ct.don_hang_id



        WHERE dh.nguoi_dung_id = ?

        AND ct.san_pham_id = ?

        AND dh.trang_thai IN (

            'hoan_thanh',

            'Hoàn thành',

            'da_giao',

            'Đã giao'

        )



        LIMIT 1

    ");



    if ($stmtDgPurchased) {

        $stmtDgPurchased->bind_param(

            "ii",

            $dgNguoiDungId,

            $id

        );



        $stmtDgPurchased->execute();



        $dgDaMuaSanPham =

            (bool)$stmtDgPurchased

                ->get_result()

                ->fetch_assoc();

    }

}



/* =========================================================

   LẤY ĐÁNH GIÁ HIỆN TẠI CỦA USER

========================================================= */

if (

    $dgNguoiDungId > 0 &&

    $dgUserColumn !== null

) {

    $selectParts = ['so_sao'];



    if ($dgIdColumn !== null) {

        $selectParts[] =

            "`$dgIdColumn` AS review_id";

    }



    if ($dgContentColumn !== null) {

        $selectParts[] =

            "`$dgContentColumn` AS review_content";

    }



    $orderBy =

        $dgIdColumn !== null

            ? "ORDER BY `$dgIdColumn` DESC"

            : '';



    $stmtMyDg = $conn->prepare("

        SELECT " .

        implode(', ', $selectParts) . "

        FROM danh_gia



        WHERE san_pham_id = ?

        AND `$dgUserColumn` = ?



        $orderBy

        LIMIT 1

    ");



    if ($stmtMyDg) {

        $stmtMyDg->bind_param(

            "ii",

            $id,

            $dgNguoiDungId

        );



        $stmtMyDg->execute();



        $dgExistingReview =

            $stmtMyDg

                ->get_result()

                ->fetch_assoc();

    }

}



/*

    Mọi tài khoản đã đăng nhập đều được đánh giá.

    Việc đã mua hàng chỉ dùng để hiện huy hiệu "Đã mua hàng",

    không còn là điều kiện bắt buộc để gửi đánh giá.

*/

$dgCoTheDanhGia =

    $dgNguoiDungId > 0 &&

    $dgUserColumn !== null;



/* =========================================================

   THỐNG KÊ

========================================================= */

$dgVisibleCondition = '';



if ($dgStatusColumn !== null) {

    $dgVisibleCondition =

        "AND `$dgStatusColumn` = 1";

}



$stmtDgAvg = $conn->prepare("

    SELECT

        COUNT(*) AS tong,

        AVG(so_sao) AS avg_sao



    FROM danh_gia



    WHERE san_pham_id = ?

    $dgVisibleCondition

");



$dgAvgSao = 0.0;

$dgTongDanhGia = 0;



if ($stmtDgAvg) {

    $stmtDgAvg->bind_param(

        "i",

        $id

    );



    $stmtDgAvg->execute();



    $avgRow =

        $stmtDgAvg

            ->get_result()

            ->fetch_assoc();



    $dgAvgSao =

        round(

            (float)($avgRow['avg_sao'] ?? 0),

            1

        );



    $dgTongDanhGia =

        (int)($avgRow['tong'] ?? 0);

}



$dgRatingCounts = [

    5 => 0,

    4 => 0,

    3 => 0,

    2 => 0,

    1 => 0

];



$stmtDgCount = $conn->prepare("

    SELECT

        so_sao,

        COUNT(*) AS total



    FROM danh_gia



    WHERE san_pham_id = ?

    $dgVisibleCondition



    GROUP BY so_sao

");



if ($stmtDgCount) {

    $stmtDgCount->bind_param(

        "i",

        $id

    );



    $stmtDgCount->execute();



    $resultDgCount =

        $stmtDgCount->get_result();



    while (

        $row =

            $resultDgCount->fetch_assoc()

    ) {

        $star = (int)$row['so_sao'];



        if (isset($dgRatingCounts[$star])) {

            $dgRatingCounts[$star] =

                (int)$row['total'];

        }

    }

}



/* =========================================================

   DANH SÁCH ĐÁNH GIÁ

   Có JOIN nguoi_dung để hiển thị tên khách hàng nếu cột user tồn tại.

========================================================= */

$dgReviewList = [];



$selectList = [

    'dg.so_sao'

];



$joinUser = '';

$joinReply = '';



if ($dgUserColumn !== null) {

    $selectList[] =

        "dg.`$dgUserColumn` AS review_user_id";



    $selectList[] =

        "nd.ho_ten AS review_user_name";



    $joinUser = "

        LEFT JOIN nguoi_dung nd

            ON nd.id = dg.`$dgUserColumn`

    ";

}



if ($dgContentColumn !== null) {

    $selectList[] =

        "dg.`$dgContentColumn` AS review_content";

}



if ($dgDateColumn !== null) {

    $selectList[] =

        "dg.`$dgDateColumn` AS review_date";

}



if ($dgIdColumn !== null) {

    $selectList[] =

        "dg.`$dgIdColumn` AS review_id";

}



if (

    $dgCoBangPhanHoi &&

    $dgIdColumn !== null

) {

    $selectList[] =

        "ph.noi_dung AS store_reply";



    $selectList[] =

        "COALESCE(

            ph.ngay_cap_nhat,

            ph.ngay_tao

        ) AS store_reply_date";



    $selectList[] =

        "admin_reply.ho_ten AS store_reply_admin";



    $joinReply = "

        LEFT JOIN phan_hoi_danh_gia ph

            ON ph.danh_gia_id = dg.`$dgIdColumn`



        LEFT JOIN nguoi_dung admin_reply

            ON admin_reply.id = ph.admin_id

    ";

}



$orderList =

    $dgDateColumn !== null

        ? "dg.`$dgDateColumn` DESC"

        : (

            $dgIdColumn !== null

                ? "dg.`$dgIdColumn` DESC"

                : 'dg.so_sao DESC'

        );



$sqlDgList = "

    SELECT " .

    implode(', ', $selectList) . "



    FROM danh_gia dg



    $joinUser

    $joinReply



    WHERE dg.san_pham_id = ?

    " .

    (

        $dgStatusColumn !== null

            ? "AND dg.`$dgStatusColumn` = 1"

            : ''

    ) . "



    ORDER BY $orderList



    LIMIT 8

";



$stmtDgList =

    $conn->prepare($sqlDgList);



if ($stmtDgList) {

    $stmtDgList->bind_param(

        "i",

        $id

    );



    $stmtDgList->execute();



    $dgReviewList =

        $stmtDgList

            ->get_result()

            ->fetch_all(MYSQLI_ASSOC);

}



/*

    THÔNG TIN PHIÊN BẢN THỰC TẾ KHÁCH ĐÃ MUA

    - Lấy từ đơn hàng đã hoàn thành/đã giao.

    - Ưu tiên lần mua gần nhất của đúng user + sản phẩm.

    - Hiển thị màu, RAM, ROM nếu có.

*/

$dgPurchaseMap = [];



if (

    $dgUserColumn !== null &&

    !empty($dgReviewList)

) {

    $stmtDgPurchasedVariant = $conn->prepare("

        SELECT

            ct.mau_id,

            ct.bien_the_id,

            ms.ten_mau,

            bt.ram,

            bt.rom



        FROM chi_tiet_don_hang ct



        JOIN don_hang dh

            ON dh.id = ct.don_hang_id



        LEFT JOIN mau_sac ms

            ON ms.id = ct.mau_id



        LEFT JOIN san_pham_bien_the bt

            ON bt.id = ct.bien_the_id



        WHERE dh.nguoi_dung_id = ?

        AND ct.san_pham_id = ?

        AND dh.trang_thai IN (

            'hoan_thanh',

            'Hoàn thành',

            'da_giao',

            'Đã giao'

        )



        ORDER BY

            dh.ngay_dat DESC,

            ct.id DESC



        LIMIT 1

    ");



    if ($stmtDgPurchasedVariant) {

        foreach ($dgReviewList as $reviewRow) {

            $reviewUserId =

                (int)(

                    $reviewRow['review_user_id']

                    ?? 0

                );



            if (

                $reviewUserId <= 0 ||

                isset(

                    $dgPurchaseMap[

                        $reviewUserId

                    ]

                )

            ) {

                continue;

            }



            $stmtDgPurchasedVariant->bind_param(

                "ii",

                $reviewUserId,

                $id

            );



            $stmtDgPurchasedVariant->execute();



            $purchaseRow =

                $stmtDgPurchasedVariant

                    ->get_result()

                    ->fetch_assoc();



            $dgPurchaseMap[

                $reviewUserId

            ] =

                $purchaseRow ?: [];

        }

    }

}



$dgImageMap = [];



if (

    $dgCoBangHinhAnh &&

    $dgIdColumn !== null

) {

    $reviewIds = [];



    foreach ($dgReviewList as $row) {

        if (!empty($row['review_id'])) {

            $reviewIds[] =

                (int)$row['review_id'];

        }

    }



    if (

        $dgExistingReview &&

        !empty(

            $dgExistingReview['review_id']

        )

    ) {

        $reviewIds[] =

            (int)$dgExistingReview['review_id'];

    }



    $dgImageMap =

        dgLayHinhAnhDanhGia(

            $conn,

            $reviewIds

        );

}



$dgExistingImages = [];



if (

    $dgExistingReview &&

    !empty($dgExistingReview['review_id'])

) {

    $dgExistingImages =

        $dgImageMap[

            (int)$dgExistingReview['review_id']

        ] ?? [];

}



if (empty($_SESSION['review_csrf'])) {

    $_SESSION['review_csrf'] =

        bin2hex(random_bytes(32));

}



$dgCsrf =

    (string)$_SESSION['review_csrf'];

?>



<style>

.review-section {

    scroll-margin-top: 90px;

}



.review-title {

    font-size: 22px;

    font-weight: 800;

}



.review-summary {

    display: grid;

    grid-template-columns: 260px 1fr;

    gap: 38px;

    align-items: center;

    padding: 22px 10px 12px;

}



.review-score-box {

    text-align: center;

}



.review-score-line {

    display: flex;

    justify-content: center;

    align-items: baseline;

    gap: 7px;

}



.review-star-big {

    color: #ff9800;

    font-size: 30px;

}



.review-score {

    color: #172033;

    font-size: 52px;

    line-height: 1;

    font-weight: 800;

}



.review-score-max {

    color: #8c98ad;

    font-size: 18px;

}



.review-customer-count {

    margin-top: 10px;

    color: #333;

    font-size: 15px;

}



.rating-row {

    display: grid;

    grid-template-columns: 52px 1fr 54px;

    gap: 10px;

    align-items: center;

    margin-bottom: 10px;

}



.rating-label .star,

.review-stars {

    color: #ff9800;

}



.rating-track {

    height: 9px;

    overflow: hidden;

    border-radius: 999px;

    background: #ececec;

}



.rating-fill {

    height: 100%;

    border-radius: inherit;

    background: #57aaf7;

}



.rating-percent {

    text-align: right;

    color: #222;

    font-weight: 700;

}



.review-action-wrap {

    display: flex;

    justify-content: center;

    margin-top: 26px;

}



.review-write-btn {

    width: min(490px, 100%);

    min-height: 58px;

    border-radius: 11px;

    font-size: 18px;

    font-weight: 700;

}



.review-help-text {

    margin-top: 10px;

    color: #777;

    font-size: 13px;

    text-align: center;

}



.review-list {

    margin-top: 28px;

    padding-top: 22px;

    border-top: 1px solid #eee;

}



.review-item {

    padding: 17px 0;

    border-bottom: 1px solid #eee;

}



.review-item:last-child {

    border-bottom: 0;

}



.review-item-head {

    display: flex;

    justify-content: space-between;

    gap: 16px;

}



.review-user-wrap {

    display: flex;

    align-items: flex-start;

    gap: 11px;

}



.review-avatar {

    width: 42px;

    height: 42px;

    flex: 0 0 42px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    border-radius: 50%;

    background: #f1f3f6;

    color: #333;

    font-size: 16px;

    font-weight: 900;

}



.review-user-info {

    min-width: 0;

}



.review-user-line {

    display: flex;

    align-items: center;

    flex-wrap: wrap;

    gap: 6px;

}



.review-user {

    font-weight: 800;

}



.review-mine-badge {

    display: inline-block;

    padding: 2px 7px;

    border-radius: 999px;

    background: #eef4ff;

    color: #1b64d8;

    font-size: 10px;

    font-weight: 800;

}



.review-verified {

    display: inline-block;

    margin-left: 6px;

    padding: 2px 7px;

    border-radius: 999px;

    background: #eaf8ef;

    color: #16803a;

    font-size: 10px;

    font-weight: 800;

}



.review-date {

    color: #999;

    font-size: 12px;

    white-space: nowrap;

}



.review-product-bought {

    margin-top: 7px;

    padding: 8px 10px;

    border-radius: 9px;

    background: #f8f9fb;

    color: #5a6270;

    font-size: 12px;

    line-height: 1.5;

}



.review-product-bought-name {

    color: #222;

    font-weight: 800;

}



.review-product-bought-meta {

    margin-top: 2px;

}



.review-comment {

    margin-top: 8px;

    color: #555;

    line-height: 1.7;

}



.review-images {

    display: flex;

    flex-wrap: wrap;

    gap: 9px;

    margin-top: 11px;

}



.review-image-link {

    display: block;

    width: 88px;

    height: 88px;

    overflow: hidden;

    border: 1px solid #ececec;

    border-radius: 10px;

    background: #f8f8f8;

}



.review-image-link img {

    width: 100%;

    height: 100%;

    object-fit: cover;

    transition: transform .18s ease;

}



.review-image-link:hover img {

    transform: scale(1.05);

}



.review-upload-box {

    margin-top: 16px;

    padding: 14px;

    border: 1px dashed #cfd4da;

    border-radius: 11px;

    background: #fafbfc;

}



.review-upload-title {

    font-weight: 800;

    color: #333;

}



.review-upload-note {

    margin-top: 4px;

    color: #888;

    font-size: 12px;

}



.review-existing-images {

    display: flex;

    flex-wrap: wrap;

    gap: 10px;

    margin-top: 10px;

}



.review-existing-image {

    position: relative;

    width: 86px;

}



.review-existing-image img {

    width: 86px;

    height: 86px;

    object-fit: cover;

    border: 1px solid #e6e6e6;

    border-radius: 9px;

}



.review-remove-image {

    display: flex;

    align-items: center;

    gap: 5px;

    margin-top: 5px;

    color: #c53045;

    font-size: 10px;

}



.review-store-reply {

    position: relative;

    margin: 13px 0 0 18px;

    padding: 13px 14px 13px 16px;

    border-left: 3px solid #d70018;

    border-radius: 0 11px 11px 0;

    background: #fff7f8;

}



.review-store-reply-title {

    display: flex;

    align-items: center;

    flex-wrap: wrap;

    gap: 7px;

    color: #d70018;

    font-size: 12px;

    font-weight: 900;

}



.review-store-reply-text {

    margin-top: 5px;

    color: #4f5561;

    line-height: 1.65;

}



.review-store-reply-date {

    margin-top: 6px;

    color: #9a9a9a;

    font-size: 10px;

}



.review-action-wrap {

    display: flex;

    justify-content: center;

    align-items: center;

    flex-wrap: wrap;

    gap: 10px;

    margin-top: 26px;

}

.review-delete-form {
    margin: 0;
    flex: 0 1 360px;
    width: min(360px, 100%);
}

.review-action-wrap > .review-write-btn {
    flex: 0 1 360px;
    width: min(360px, 100%);
}

.review-write-btn,
.review-delete-btn {
    min-width: 0;
    min-height: 58px;
    border-radius: 11px;
    font-weight: 800;
}

.review-delete-btn {
    width: 100%;
}

.review-modal-backdrop {

    position: fixed; inset: 0; z-index: 2080; display: flex;

    align-items: center; justify-content: center; padding: 12px;

    background: rgba(15,23,42,.52); backdrop-filter: blur(3px); -webkit-backdrop-filter: blur(3px);

    opacity: 0; visibility: hidden; pointer-events: none;

    transition: opacity .18s ease, visibility .18s ease;

}

.review-modal-backdrop.show { opacity: 1; visibility: visible; pointer-events: auto; }

.review-modal-card {

    width: min(620px, calc(100vw - 24px)); max-height: calc(100dvh - 24px);

    display: flex; flex-direction: column; overflow: hidden;

    border: 1px solid rgba(226,232,240,.95); border-radius: 18px; background: #fff;

    box-shadow: 0 26px 80px rgba(15,23,42,.28);

    transform: translateY(14px) scale(.985); transition: transform .2s ease;

}

.review-modal-backdrop.show .review-modal-card { transform: translateY(0) scale(1); }

.review-modal-header {

    display: flex; justify-content: space-between; align-items: center; flex: 0 0 auto;

    gap: 15px; padding: 15px 18px; border-bottom: 1px solid #eceef2; background: #fff;

}

.review-modal-card > form { min-height: 0; display: flex; flex: 1 1 auto; flex-direction: column; }

.review-modal-body {

    min-height: 0; flex: 1 1 auto; overflow-y: auto; overscroll-behavior: contain;

    -webkit-overflow-scrolling: touch; padding: 16px 18px 18px;

}

.review-modal-close {

    width: 34px; height: 34px; display: grid; place-items: center; flex: 0 0 34px;

    border: 0; border-radius: 50%; background: #f3f4f6; color: #5f6672; font-size: 21px;

    cursor: pointer; transition: *background* .15s ease, transform .15s ease;

}

.review-modal-close:hover { background: #e9ebef; transform: rotate(4deg); }

.review-star-picker { display: flex; justify-content: center; gap: 7px; margin: 11px 0 16px; }

.review-star-choice {

    border: 0; background: transparent; color: #d7dbe2; font-size: clamp(31px,6vw,38px);

    line-height: 1; cursor: pointer; transition: transform .14s ease,color .14s ease;

}

.review-star-choice:hover { transform: translateY(-2px) scale(1.06); }

.review-star-choice.active { color: #ff9800; }



@media (max-width: 767px) {

    .review-summary {

        grid-template-columns: 1fr;

        gap: 24px;

    }



    .review-score {

        font-size: 45px;

    }



    .rating-row {

        grid-template-columns: 45px 1fr 48px;

    }



    .review-item-head {

        flex-direction: column;

        gap: 7px;

    }

    .review-modal-backdrop { padding: 8px; align-items: flex-end; }

    .review-modal-card { width: 100%; max-height: calc(100dvh - 8px); border-radius: 18px 18px 0 0; }

    .review-modal-header { padding: 13px 14px; }

    .review-modal-body { padding: 14px; }

    .review-action-wrap { gap: 8px; }

    .review-action-wrap > .review-write-btn,
    .review-delete-form {
        flex: 1 1 0;
        width: calc(50% - 4px);
        max-width: none;
    }

    .review-write-btn,
    .review-delete-btn {
        min-width: 0;
        width: 100%;
    }

}

</style>



<div

    id="reviews"

    class="shopee-card review-section mt-4"

>

    <div class="review-title">

        Đánh giá

        <?= htmlspecialchars(

            (string)$sp['ten_san_pham']

        ) ?>

    </div>



    <?php if ($dgSuccess): ?>

        <div class="alert alert-success mt-3 mb-0">

            ✅ Đánh giá của bạn đã được lưu.

        </div>

    <?php endif; ?>



    <?php if ($dgDeleted): ?>

        <div class="alert alert-success mt-3 mb-0">

            ✅ Đánh giá của bạn đã được xóa.

        </div>

    <?php endif; ?>



    <?php if ($dgError !== ''): ?>

        <div class="alert alert-danger mt-3 mb-0">

            <?= htmlspecialchars($dgError) ?>

        </div>

    <?php endif; ?>



    <div class="review-summary">



        <div class="review-score-box">

            <div class="review-score-line">

                <span class="review-star-big">

                    ★

                </span>



                <span class="review-score">

                    <?= $dgTongDanhGia > 0

                        ? number_format(

                            $dgAvgSao,

                            1,

                            ',',

                            ''

                        )

                        : '0' ?>

                </span>



                <span class="review-score-max">

                    /5

                </span>

            </div>



            <div class="review-customer-count">

                <?= $dgTongDanhGia ?>

                khách hàng đã đánh giá

            </div>

        </div>



        <div>

            <?php for (

                $star = 5;

                $star >= 1;

                $star--

            ): ?>



                <?php

                $count =

                    (int)$dgRatingCounts[$star];



                $percent =

                    $dgTongDanhGia > 0

                        ? round(

                            $count /

                            $dgTongDanhGia *

                            100

                        )

                        : 0;

                ?>



                <div class="rating-row">

                    <div class="rating-label">

                        <?= $star ?>

                        <span class="star">★</span>

                    </div>



                    <div class="rating-track">

                        <div

                            class="rating-fill"

                            style="width:<?= $percent ?>%"

                        ></div>

                    </div>



                    <div class="rating-percent">

                        <?= $percent ?>%

                    </div>

                </div>



            <?php endfor; ?>

        </div>



    </div>



    <div class="review-action-wrap">

        <?php if ($dgNguoiDungId <= 0): ?>

            <a

                class="btn btn-primary review-write-btn"

                href="/iPhoneStore/auth/login.php?return=<?= rawurlencode(

                    '/iPhoneStore/user/san_pham/chi_tiet.php?id=' .

                    (int)$id .

                    '#reviews'

                ) ?>"

            >

                Đăng nhập để đánh giá

            </a>



        <?php elseif ($dgExistingReview): ?>

            <button

                type="button"

                class="btn btn-primary review-write-btn"

                onclick="openReviewModal()"

            >

                ✏️ Sửa đánh giá

            </button>



            <?php if (

                $dgIdColumn !== null &&

                !empty($dgExistingReview['review_id'])

            ): ?>

                <form

                    method="POST"

                    action="/iPhoneStore/user/danh_gia/xoa.php"

                    class="review-delete-form"

                    onsubmit="return confirm('Bạn chắc chắn muốn xóa đánh giá này? Ảnh và phản hồi liên quan cũng sẽ bị xóa.');"

                >

                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($dgCsrf, ENT_QUOTES, 'UTF-8') ?>">

                    <input type="hidden" name="san_pham_id" value="<?= (int)$id ?>">

                    <input type="hidden" name="danh_gia_id" value="<?= (int)$dgExistingReview['review_id'] ?>">

                    <button type="submit" class="btn btn-outline-danger review-delete-btn">

                        🗑 Xóa đánh giá

                    </button>

                </form>

            <?php endif; ?>



        <?php elseif ($dgCoTheDanhGia): ?>

            <button

                type="button"

                class="btn btn-primary review-write-btn"

                onclick="openReviewModal()"

            >

                ⭐ Viết đánh giá

            </button>

        <?php endif; ?>

    </div>



    <?php if ($dgNguoiDungId <= 0): ?>

        <div class="review-help-text">

            Bạn chỉ cần đăng nhập để viết đánh giá. Không bắt buộc phải mua sản phẩm trước.

        </div>

    <?php elseif (!$dgCoTheDanhGia): ?>

        <div class="review-help-text">

            Chức năng đánh giá hiện chưa sẵn sàng.

        </div>

    <?php endif; ?>



    <div class="review-list">

        <h5 class="fw-bold mb-2">

            Đánh giá gần đây

        </h5>



        <?php if (empty($dgReviewList)): ?>



            <div class="text-muted py-3">

                Chưa có đánh giá nào cho sản phẩm này.

            </div>



        <?php else: ?>



            <?php foreach (

                $dgReviewList

                as $review

            ): ?>



                <?php

                $reviewStars =

                    max(

                        1,

                        min(

                            5,

                            (int)$review['so_sao']

                        )

                    );



                $laCuaToi =

                    isset(

                        $review['review_user_id']

                    ) &&

                    (int)$review['review_user_id'] ===

                    $dgNguoiDungId;



                $tenNguoiDanhGia =

                    trim(

                        (string)(

                            $review['review_user_name']

                            ?? ''

                        )

                    );



                if ($tenNguoiDanhGia === '') {

                    $tenNguoiDanhGia =

                        $laCuaToi

                            ? 'Bạn'

                            : 'Khách hàng';

                }

                ?>



                <?php

                $reviewUserId =

                    (int)(

                        $review['review_user_id']

                        ?? 0

                    );



                $purchaseInfo =

                    $dgPurchaseMap[

                        $reviewUserId

                    ] ?? [];



                $reviewInitial =

                    mb_strtoupper(

                        mb_substr(

                            $tenNguoiDanhGia,

                            0,

                            1,

                            'UTF-8'

                        ),

                        'UTF-8'

                    );



                $variantParts = [];



                if (

                    !empty(

                        $purchaseInfo['ten_mau']

                    )

                ) {

                    $variantParts[] =

                        'Màu: ' .

                        $purchaseInfo['ten_mau'];

                }



                if (

                    !empty(

                        $purchaseInfo['ram']

                    )

                ) {

                    $variantParts[] =

                        'RAM: ' .

                        $purchaseInfo['ram'];

                }



                if (

                    !empty(

                        $purchaseInfo['rom']

                    )

                ) {

                    $variantParts[] =

                        'ROM: ' .

                        $purchaseInfo['rom'];

                }

                ?>



                <div class="review-item">

                    <div class="review-item-head">



                        <div class="review-user-wrap">



                            <div class="review-avatar">

                                <?= htmlspecialchars(

                                    $reviewInitial

                                ) ?>

                            </div>



                            <div class="review-user-info">



                                <div class="review-user-line">

                                    <span class="review-user">

                                        <?= htmlspecialchars(

                                            $tenNguoiDanhGia

                                        ) ?>

                                    </span>



                                    <?php if ($laCuaToi): ?>

                                        <span class="review-mine-badge">

                                            Đánh giá của bạn

                                        </span>

                                    <?php endif; ?>



                                    <?php if (!empty($purchaseInfo)): ?>

                                        <span class="review-verified">

                                            ✓ Đã mua hàng

                                        </span>

                                    <?php endif; ?>

                                </div>



                                <div class="review-stars">

                                    <?= str_repeat(

                                        '★',

                                        $reviewStars

                                    ) ?>



                                    <span style="color:#ddd">

                                        <?= str_repeat(

                                            '★',

                                            5 - $reviewStars

                                        ) ?>

                                    </span>

                                </div>



                                <div class="review-product-bought">



                                    <div class="review-product-bought-name">

                                        <?= htmlspecialchars(

                                            (string)$sp[

                                                'ten_san_pham'

                                            ]

                                        ) ?>

                                    </div>



                                    <?php if (

                                        !empty(

                                            $variantParts

                                        )

                                    ): ?>

                                        <div class="review-product-bought-meta">

                                            <?= htmlspecialchars(

                                                implode(

                                                    ' · ',

                                                    $variantParts

                                                )

                                            ) ?>

                                        </div>

                                    <?php endif; ?>



                                </div>



                            </div>



                        </div>



                        <?php if (

                            !empty(

                                $review['review_date']

                            )

                        ): ?>

                            <div class="review-date">

                                <?= date(

                                    'd/m/Y H:i',

                                    strtotime(

                                        $review['review_date']

                                    )

                                ) ?>

                            </div>

                        <?php endif; ?>



                    </div>



                    <?php if (

                        !empty(

                            trim(

                                (string)(

                                    $review['review_content']

                                    ?? ''

                                )

                            )

                        )

                    ): ?>

                        <div class="review-comment">

                            <?= nl2br(

                                htmlspecialchars(

                                    (string)$review[

                                        'review_content'

                                    ]

                                )

                            ) ?>

                        </div>

                    <?php endif; ?>



                    <?php

                    $reviewImages =

                        !empty($review['review_id'])

                            ? (

                                $dgImageMap[

                                    (int)$review['review_id']

                                ] ?? []

                            )

                            : [];

                    ?>



                    <?php if (

                        !empty($reviewImages)

                    ): ?>

                        <div class="review-images">

                            <?php foreach (

                                $reviewImages

                                as $reviewImage

                            ): ?>

                                <a

                                    class="review-image-link"

                                    href="/iPhoneStore/uploads/danh_gia/<?= rawurlencode(

                                        basename(

                                            (string)$reviewImage[

                                                'hinh_anh'

                                            ]

                                        )

                                    ) ?>"

                                    target="_blank"

                                    rel="noopener"

                                >

                                    <img

                                        src="/iPhoneStore/uploads/danh_gia/<?= rawurlencode(

                                            basename(

                                                (string)$reviewImage[

                                                    'hinh_anh'

                                                ]

                                            )

                                        ) ?>"

                                        alt="Ảnh đánh giá"

                                        loading="lazy"

                                    >

                                </a>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>



                    <?php if (

                        !empty(

                            trim(

                                (string)(

                                    $review['store_reply']

                                    ?? ''

                                )

                            )

                        )

                    ): ?>

                        <div class="review-store-reply">

                            <div class="review-store-reply-title">

                                <span>💬 Phản hồi từ iPhoneStore</span>



                                <?php if (

                                    !empty(

                                        $review['store_reply_admin']

                                    )

                                ): ?>

                                    <span class="text-muted">

                                        · <?= htmlspecialchars(

                                            (string)$review[

                                                'store_reply_admin'

                                            ]

                                        ) ?>

                                    </span>

                                <?php endif; ?>

                            </div>



                            <div class="review-store-reply-text">

                                <?= nl2br(

                                    htmlspecialchars(

                                        (string)$review[

                                            'store_reply'

                                        ]

                                    )

                                ) ?>

                            </div>



                            <?php if (

                                !empty(

                                    $review[

                                        'store_reply_date'

                                    ]

                                )

                            ): ?>

                                <div class="review-store-reply-date">

                                    <?= date(

                                        'd/m/Y H:i',

                                        strtotime(

                                            $review[

                                                'store_reply_date'

                                            ]

                                        )

                                    ) ?>

                                </div>

                            <?php endif; ?>

                        </div>

                    <?php endif; ?>

                </div>



            <?php endforeach; ?>



        <?php endif; ?>

    </div>

</div>



<div

    id="reviewModal"

    class="review-modal-backdrop"

    onclick="closeReviewModalFromBackdrop(event)"

>

    <div class="review-modal-card">



        <div class="review-modal-header">

            <div>

                <div class="fw-bold fs-5">

                    <?= $dgExistingReview

                        ? 'Sửa đánh giá'

                        : 'Viết đánh giá' ?>

                </div>



                <div class="small text-muted">

                    <?= htmlspecialchars(

                        (string)$sp['ten_san_pham']

                    ) ?>

                </div>

            </div>



            <button

                type="button"

                class="review-modal-close"

                onclick="closeReviewModal()"

            >

                ×

            </button>

        </div>



        <form

            method="POST"

            enctype="multipart/form-data"

            action="/iPhoneStore/user/danh_gia/xu_ly.php"

        >

            <div class="review-modal-body">



                <input

                    type="hidden"

                    name="san_pham_id"

                    value="<?= (int)$id ?>"

                >



                <input

                    type="hidden"

                    name="csrf_token"

                    value="<?= htmlspecialchars(

                        $dgCsrf

                    ) ?>"

                >



                <input

                    type="hidden"

                    name="so_sao"

                    id="reviewRatingInput"

                    value="<?= (int)(

                        $dgExistingReview['so_sao']

                        ?? 5

                    ) ?>"

                >



                <div class="text-center fw-bold">

                    Bạn đánh giá sản phẩm này thế nào?

                </div>



                <div class="review-star-picker">

                    <?php for (

                        $star = 1;

                        $star <= 5;

                        $star++

                    ): ?>

                        <button

                            type="button"

                            class="review-star-choice"

                            data-rating-star="<?= $star ?>"

                            onclick="setReviewRating(

                                <?= $star ?>

                            )"

                            aria-label="<?= $star ?> sao"

                        >

                            ★

                        </button>

                    <?php endfor; ?>

                </div>



                <?php if (

                    $dgContentColumn !== null

                ): ?>

                    <label class="form-label fw-bold">

                        Nhận xét

                    </label>



                    <textarea

                        name="noi_dung"

                        class="form-control"

                        rows="5"

                        maxlength="1500"

                        placeholder="Chia sẻ trải nghiệm của bạn về sản phẩm..."

                    ><?= htmlspecialchars(

                        (string)(

                            $dgExistingReview[

                                'review_content'

                            ]

                            ?? ''

                        )

                    ) ?></textarea>



                    <div class="form-text">

                        Tối đa 1500 ký tự.

                    </div>

                <?php endif; ?>



                <?php if ($dgCoBangHinhAnh): ?>

                    <div class="review-upload-box">

                        <div class="review-upload-title">

                            Ảnh thực tế

                        </div>



                        <div class="review-upload-note">

                            Tối đa 5 ảnh cho mỗi đánh giá.

                            Hỗ trợ JPG, PNG, WEBP.

                            Mỗi ảnh tối đa 5MB.

                        </div>



                        <?php if (

                            !empty($dgExistingImages)

                        ): ?>

                            <div class="review-existing-images">

                                <?php foreach (

                                    $dgExistingImages

                                    as $image

                                ): ?>

                                    <div class="review-existing-image">

                                        <a

                                            href="/iPhoneStore/uploads/danh_gia/<?= rawurlencode(

                                                basename(

                                                    (string)$image[

                                                        'hinh_anh'

                                                    ]

                                                )

                                            ) ?>"

                                            target="_blank"

                                            rel="noopener"

                                        >

                                            <img

                                                src="/iPhoneStore/uploads/danh_gia/<?= rawurlencode(

                                                    basename(

                                                        (string)$image[

                                                            'hinh_anh'

                                                        ]

                                                    )

                                                ) ?>"

                                                alt="Ảnh đã tải"

                                            >

                                        </a>



                                        <label class="review-remove-image">

                                            <input

                                                type="checkbox"

                                                name="xoa_hinh_anh[]"

                                                value="<?= (int)$image['id'] ?>"

                                            >

                                            Xóa ảnh

                                        </label>

                                    </div>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>



                        <input

                            type="file"

                            name="hinh_anh[]"

                            class="form-control mt-3"

                            accept="image/jpeg,image/png,image/webp"

                            multiple

                        >

                    </div>

                <?php endif; ?>



                <div class="d-flex gap-2 justify-content-end mt-4">

                    <button

                        type="button"

                        class="btn btn-outline-secondary"

                        onclick="closeReviewModal()"

                    >

                        Hủy

                    </button>



                    <button

                        type="submit"

                        class="btn btn-primary"

                    >

                        Lưu đánh giá

                    </button>

                </div>



            </div>

        </form>



    </div>

</div>



<script>

let reviewBodyOverflowBeforeOpen = '';

function openReviewModal() {

    const modal = document.getElementById('reviewModal');

    if (!modal) return;

    reviewBodyOverflowBeforeOpen = document.body.style.overflow || '';

    modal.classList.add('show');

    document.body.style.overflow = 'hidden';

    window.setTimeout(function () {

        modal.querySelector('.review-modal-close')?.focus();

    }, 80);

}



function closeReviewModal() {

    const modal =

        document.getElementById(

            'reviewModal'

        );



    if (!modal) {

        return;

    }



    modal.classList.remove('show');



    document.body.style.overflow =

        reviewBodyOverflowBeforeOpen;

}



function closeReviewModalFromBackdrop(

    event

) {

    if (

        event.target &&

        event.target.id === 'reviewModal'

    ) {

        closeReviewModal();

    }

}



function setReviewRating(rating) {

    rating =

        Math.max(

            1,

            Math.min(

                5,

                Number(rating) || 1

            )

        );



    const input =

        document.getElementById(

            'reviewRatingInput'

        );



    if (input) {

        input.value = rating;

    }



    document

        .querySelectorAll(

            '.review-star-choice'

        )

        .forEach(function (button) {

            const star =

                Number(

                    button.dataset.ratingStar

                );



            button.classList.toggle(

                'active',

                star <= rating

            );

        });

}



document.addEventListener(

    'keydown',

    function (event) {

        if (event.key === 'Escape') {

            closeReviewModal();

        }

    }

);



setReviewRating(

    Number(

        document.getElementById(

            'reviewRatingInput'

        )?.value || 5

    )

);



<?php if ($dgError !== ''): ?>

window.addEventListener(

    'load',

    function () {

        openReviewModal();

    }

);

<?php endif; ?>

</script>
