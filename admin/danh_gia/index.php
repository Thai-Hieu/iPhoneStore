<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../middleware/check_admin.php";
require_once "../../config/database.php";
require_once __DIR__ . "/ham_ho_tro.php";

$meta = adgMeta($conn);

$userColumn =
    $meta['user'];

$contentColumn =
    $meta['content'];

$dateColumn =
    $meta['date'];

$statusColumn =
    $meta['status'];

$idColumn =
    $meta['id'];

$coBangPhanHoi =
    adgCoBangPhanHoiDanhGia($conn);

$coBangHinhAnh =
    adgCoBangHinhAnhDanhGia($conn);

$keyword =
    trim(
        (string)(
            $_GET['keyword']
            ?? ''
        )
    );

$starFilter =
    (int)($_GET['star'] ?? 0);

$statusFilter =
    trim(
        (string)(
            $_GET['status']
            ?? ''
        )
    );

$replyFilter =
    trim(
        (string)(
            $_GET['reply']
            ?? ''
        )
    );

$page =
    max(
        1,
        (int)($_GET['page'] ?? 1)
    );

$limit = 20;
$offset =
    ($page - 1) * $limit;

$where = [
    '1 = 1'
];

$types = '';
$params = [];

if ($keyword !== '') {
    $keywordParts = [
        'sp.ten_san_pham LIKE ?'
    ];

    $search =
        '%' . $keyword . '%';

    $types .= 's';
    $params[] = $search;

    if ($userColumn !== null) {
        $keywordParts[] =
            'nd.ho_ten LIKE ?';

        $types .= 's';
        $params[] = $search;
    }

    if ($contentColumn !== null) {
        $keywordParts[] =
            "dg.`$contentColumn` LIKE ?";

        $types .= 's';
        $params[] = $search;
    }

    if ($coBangPhanHoi) {
        $keywordParts[] =
            'ph.noi_dung LIKE ?';

        $types .= 's';
        $params[] = $search;
    }

    $where[] =
        '(' .
        implode(
            ' OR ',
            $keywordParts
        ) .
        ')';
}

if ($starFilter >= 1 && $starFilter <= 5) {
    $where[] =
        'dg.so_sao = ?';

    $types .= 'i';
    $params[] = $starFilter;
}

if (
    $statusColumn !== null &&
    in_array(
        $statusFilter,
        ['1', '0'],
        true
    )
) {
    $where[] =
        "dg.`$statusColumn` = ?";

    $types .= 'i';
    $params[] =
        (int)$statusFilter;
}

if (
    $coBangPhanHoi &&
    $idColumn !== null
) {
    if ($replyFilter === 'answered') {
        $where[] =
            'ph.id IS NOT NULL';
    } elseif ($replyFilter === 'unanswered') {
        $where[] =
            'ph.id IS NULL';
    }
}

$whereSql =
    implode(
        ' AND ',
        $where
    );

$select = [
    'dg.so_sao',
    'dg.san_pham_id',
    'sp.ten_san_pham',
    'sp.hinh_anh'
];

$joinUser = '';

if ($userColumn !== null) {
    $select[] =
        "dg.`$userColumn` AS nguoi_dung_id";

    $select[] =
        'nd.ho_ten';

    $joinUser = "
        LEFT JOIN nguoi_dung nd
            ON nd.id = dg.`$userColumn`
    ";
} else {
    $select[] =
        'NULL AS nguoi_dung_id';

    $select[] =
        "'Không xác định' AS ho_ten";
}

if ($contentColumn !== null) {
    $select[] =
        "dg.`$contentColumn` AS noi_dung";
} else {
    $select[] =
        "'' AS noi_dung";
}

if ($dateColumn !== null) {
    $select[] =
        "dg.`$dateColumn` AS ngay_tao";
} else {
    $select[] =
        "NULL AS ngay_tao";
}

if ($statusColumn !== null) {
    $select[] =
        "dg.`$statusColumn` AS trang_thai";
} else {
    $select[] =
        "1 AS trang_thai";
}

if ($idColumn !== null) {
    $select[] =
        "dg.`$idColumn` AS id";
} else {
    $select[] =
        'NULL AS id';
}

$joinReply = '';

if (
    $coBangPhanHoi &&
    $idColumn !== null
) {
    $select[] =
        'ph.id AS phan_hoi_id';

    $select[] =
        'ph.noi_dung AS phan_hoi_noi_dung';

    $select[] =
        'ph.ngay_tao AS phan_hoi_ngay_tao';

    $select[] =
        'ph.ngay_cap_nhat AS phan_hoi_ngay_cap_nhat';

    $select[] =
        'admin_reply.ho_ten AS phan_hoi_admin';

    $joinReply = "
        LEFT JOIN phan_hoi_danh_gia ph
            ON ph.danh_gia_id = dg.`$idColumn`

        LEFT JOIN nguoi_dung admin_reply
            ON admin_reply.id = ph.admin_id
    ";
} else {
    $select[] =
        'NULL AS phan_hoi_id';

    $select[] =
        'NULL AS phan_hoi_noi_dung';

    $select[] =
        'NULL AS phan_hoi_ngay_tao';

    $select[] =
        'NULL AS phan_hoi_ngay_cap_nhat';

    $select[] =
        'NULL AS phan_hoi_admin';
}

$orderBy =
    $dateColumn !== null
        ? "dg.`$dateColumn` DESC"
        : (
            $idColumn !== null
                ? "dg.`$idColumn` DESC"
                : 'dg.so_sao DESC'
        );

$countSql = "
    SELECT COUNT(*) AS tong

    FROM danh_gia dg

    JOIN san_pham sp
        ON sp.id = dg.san_pham_id

    $joinUser
    $joinReply

    WHERE $whereSql
";

$stmtCount =
    $conn->prepare($countSql);

$totalRows = 0;

if ($stmtCount) {
    if ($types !== '') {
        adgBindParams(
            $stmtCount,
            $types,
            $params
        );
    }

    $stmtCount->execute();

    $totalRows =
        (int)(
            $stmtCount
                ->get_result()
                ->fetch_assoc()['tong']
            ?? 0
        );
}

$totalPages =
    max(
        1,
        (int)ceil(
            $totalRows / $limit
        )
    );

$listSql = "
    SELECT " .
    implode(
        ', ',
        $select
    ) . "

    FROM danh_gia dg

    JOIN san_pham sp
        ON sp.id = dg.san_pham_id

    $joinUser
    $joinReply

    WHERE $whereSql

    ORDER BY $orderBy

    LIMIT ?
    OFFSET ?
";

$stmtList =
    $conn->prepare($listSql);

$rows = [];

if ($stmtList) {
    $listTypes =
        $types . 'ii';

    $listParams =
        $params;

    $listParams[] = $limit;
    $listParams[] = $offset;

    adgBindParams(
        $stmtList,
        $listTypes,
        $listParams
    );

    $stmtList->execute();

    $rows =
        $stmtList
            ->get_result()
            ->fetch_all(MYSQLI_ASSOC);
}

$adminImageMap = [];

if (
    $coBangHinhAnh &&
    $idColumn !== null
) {
    $adminReviewIds = [];

    foreach ($rows as $row) {
        if (!empty($row['id'])) {
            $adminReviewIds[] =
                (int)$row['id'];
        }
    }

    $adminImageMap =
        adgLayHinhAnhDanhGia(
            $conn,
            $adminReviewIds
        );
}

$stats = [
    'tong' => 0,
    'hien' => 0,
    'an' => 0,
    'chua_tra_loi' => 0,
    'avg' => 0
];

$statsStatusSelect =
    $statusColumn !== null
        ? "
            SUM(
                CASE
                    WHEN dg.`$statusColumn` = 1
                    THEN 1
                    ELSE 0
                END
            ) AS hien,

            SUM(
                CASE
                    WHEN dg.`$statusColumn` = 0
                    THEN 1
                    ELSE 0
                END
            ) AS an,
        "
        : "
            COUNT(*) AS hien,
            0 AS an,
        ";

$statsReplyJoin = '';
$statsReplySelect =
    '0 AS chua_tra_loi,';

if (
    $coBangPhanHoi &&
    $idColumn !== null
) {
    $statsReplyJoin = "
        LEFT JOIN phan_hoi_danh_gia ph
            ON ph.danh_gia_id = dg.`$idColumn`
    ";

    $statsReplySelect = "
        SUM(
            CASE
                WHEN ph.id IS NULL
                THEN 1
                ELSE 0
            END
        ) AS chua_tra_loi,
    ";
}

$statsResult =
    $conn->query("
        SELECT
            COUNT(*) AS tong,
            $statsStatusSelect
            $statsReplySelect
            COALESCE(
                AVG(dg.so_sao),
                0
            ) AS avg_sao

        FROM danh_gia dg

        $statsReplyJoin
    ");

if ($statsResult) {
    $s =
        $statsResult->fetch_assoc();

    $stats = [
        'tong' =>
            (int)($s['tong'] ?? 0),

        'hien' =>
            (int)($s['hien'] ?? 0),

        'an' =>
            (int)($s['an'] ?? 0),

        'chua_tra_loi' =>
            (int)(
                $s['chua_tra_loi']
                ?? 0
            ),

        'avg' =>
            round(
                (float)(
                    $s['avg_sao']
                    ?? 0
                ),
                1
            )
    ];
}

$replySuccess =
    ($_GET['reply_status'] ?? '') ===
    'success';

$replyError =
    trim(
        (string)(
            $_SESSION[
                'admin_review_reply_error'
            ]
            ?? ''
        )
    );

unset(
    $_SESSION[
        'admin_review_reply_error'
    ]
);

require_once "../../includes/header.php";
require_once "../../includes/navbar.php";
?>

<style>
.review-admin-wrap {
    padding: 28px 0 50px;
}

.review-admin-title {
    font-weight: 900;
    color: #172033;
}

.review-stat-grid {
    display: grid;
    grid-template-columns:
        repeat(
            5,
            minmax(0, 1fr)
        );
    gap: 14px;
    margin: 20px 0;
}

.review-stat-card,
.review-filter-card,
.review-table-card {
    border: 1px solid #ececec;
    border-radius: 15px;
    background: #fff;
    box-shadow:
        0 8px 22px
        rgba(0, 0, 0, .05);
}

.review-stat-card {
    padding: 18px;
}

.review-stat-label {
    color: #7b8493;
    font-size: 11px;
    font-weight: 800;
}

.review-stat-value {
    margin-top: 6px;
    color: #172033;
    font-size: 27px;
    font-weight: 900;
}

.review-filter-card {
    padding: 16px;
    margin-bottom: 16px;
}

.review-table-card {
    overflow: hidden;
}

.review-table {
    margin: 0;
}

.review-product {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 210px;
}

.review-product-image {
    width: 52px;
    height: 52px;
    object-fit: contain;
    border: 1px solid #ececec;
    border-radius: 9px;
    background: #fff;
}

.review-admin-stars {
    color: #ff9800;
    letter-spacing: 1px;
    white-space: nowrap;
}

.review-content-cell {
    min-width: 250px;
    max-width: 390px;
    color: #555;
    line-height: 1.55;
}

.review-status {
    display: inline-flex;
    align-items: center;
    padding: 5px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 850;
    white-space: nowrap;
}

.review-status.show {
    background: #e9f8ef;
    color: #16803a;
}

.review-status.hide {
    background: #fff0f2;
    color: #c53045;
}

.review-admin-images {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 9px;
}

.review-admin-image {
    width: 58px;
    height: 58px;
    overflow: hidden;
    border: 1px solid #e8e8e8;
    border-radius: 8px;
    background: #f8f8f8;
}

.review-admin-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.review-reply-cell {
    min-width: 310px;
    max-width: 430px;
}

.review-reply-existing {
    margin-bottom: 9px;
    padding: 10px 11px;
    border-left: 3px solid #d70018;
    border-radius: 0 9px 9px 0;
    background: #fff7f8;
}

.review-reply-existing-title {
    color: #d70018;
    font-size: 11px;
    font-weight: 900;
}

.review-reply-existing-text {
    margin-top: 4px;
    color: #555;
    line-height: 1.5;
}

.review-reply-existing-meta {
    margin-top: 5px;
    color: #999;
    font-size: 10px;
}

.review-reply-form textarea {
    min-height: 76px;
    resize: vertical;
    font-size: 12px;
}

.review-reply-form .btn {
    margin-top: 7px;
}

@media (max-width: 1199px) {
    .review-stat-grid {
        grid-template-columns:
            repeat(
                3,
                minmax(0, 1fr)
            );
    }
}

@media (max-width: 767px) {
    .review-stat-grid {
        grid-template-columns:
            repeat(
                2,
                minmax(0, 1fr)
            );
    }
}

@media (max-width: 520px) {
    .review-stat-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="container review-admin-wrap">

    <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
        <div>
            <h2 class="review-admin-title mb-1">
                Quản lý đánh giá sản phẩm
            </h2>

            <div class="text-muted">
                Theo dõi, ẩn/hiện và phản hồi đánh giá của khách hàng.
            </div>
        </div>
    </div>

    <?php if (!$coBangPhanHoi): ?>
        <div class="alert alert-warning mt-3">
            ⚠ Chưa có bảng
            <b>phan_hoi_danh_gia</b>.
            Hãy chạy file
            <b>migration_phan_hoi_danh_gia.sql</b>
            để bật chức năng trả lời đánh giá.
        </div>
    <?php endif; ?>

    <?php if ($replySuccess): ?>
        <div class="alert alert-success mt-3">
            ✅ Phản hồi đánh giá đã được lưu.
        </div>
    <?php endif; ?>

    <?php if ($replyError !== ''): ?>
        <div class="alert alert-danger mt-3">
            <?= htmlspecialchars(
                $replyError
            ) ?>
        </div>
    <?php endif; ?>

    <div class="review-stat-grid">

        <div class="review-stat-card">
            <div class="review-stat-label">
                TỔNG ĐÁNH GIÁ
            </div>

            <div class="review-stat-value">
                <?= $stats['tong'] ?>
            </div>
        </div>

        <div class="review-stat-card">
            <div class="review-stat-label">
                ĐANG HIỂN THỊ
            </div>

            <div class="review-stat-value">
                <?= $stats['hien'] ?>
            </div>
        </div>

        <div class="review-stat-card">
            <div class="review-stat-label">
                ĐANG ẨN
            </div>

            <div class="review-stat-value">
                <?= $stats['an'] ?>
            </div>
        </div>

        <div class="review-stat-card">
            <div class="review-stat-label">
                CHƯA TRẢ LỜI
            </div>

            <div class="review-stat-value">
                <?= $stats['chua_tra_loi'] ?>
            </div>
        </div>

        <div class="review-stat-card">
            <div class="review-stat-label">
                ĐIỂM TRUNG BÌNH
            </div>

            <div class="review-stat-value">
                <?= number_format(
                    $stats['avg'],
                    1,
                    ',',
                    ''
                ) ?>/5
            </div>
        </div>

    </div>

    <form
        method="GET"
        class="review-filter-card"
    >
        <div class="row g-2">

            <div class="col-lg-4">
                <input
                    type="text"
                    name="keyword"
                    class="form-control"
                    placeholder="Tìm sản phẩm, khách hàng, nội dung..."
                    value="<?= htmlspecialchars(
                        $keyword
                    ) ?>"
                >
            </div>

            <div class="col-md-4 col-lg-2">
                <select
                    name="star"
                    class="form-select"
                >
                    <option value="0">
                        Tất cả số sao
                    </option>

                    <?php for (
                        $star = 5;
                        $star >= 1;
                        $star--
                    ): ?>
                        <option
                            value="<?= $star ?>"
                            <?= $starFilter === $star
                                ? 'selected'
                                : '' ?>
                        >
                            <?= $star ?> sao
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <?php if (
                $statusColumn !== null
            ): ?>
                <div class="col-md-4 col-lg-2">
                    <select
                        name="status"
                        class="form-select"
                    >
                        <option value="">
                            Tất cả trạng thái
                        </option>

                        <option
                            value="1"
                            <?= $statusFilter === '1'
                                ? 'selected'
                                : '' ?>
                        >
                            Đang hiển thị
                        </option>

                        <option
                            value="0"
                            <?= $statusFilter === '0'
                                ? 'selected'
                                : '' ?>
                        >
                            Đang ẩn
                        </option>
                    </select>
                </div>
            <?php endif; ?>

            <?php if ($coBangPhanHoi): ?>
                <div class="col-md-4 col-lg-2">
                    <select
                        name="reply"
                        class="form-select"
                    >
                        <option value="">
                            Tất cả phản hồi
                        </option>

                        <option
                            value="unanswered"
                            <?= $replyFilter === 'unanswered'
                                ? 'selected'
                                : '' ?>
                        >
                            Chưa trả lời
                        </option>

                        <option
                            value="answered"
                            <?= $replyFilter === 'answered'
                                ? 'selected'
                                : '' ?>
                        >
                            Đã trả lời
                        </option>
                    </select>
                </div>
            <?php endif; ?>

            <div class="col-lg-2">
                <button
                    type="submit"
                    class="btn btn-danger w-100"
                >
                    Lọc
                </button>
            </div>

        </div>
    </form>

    <div class="review-table-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle review-table">

                <thead class="table-light">
                    <tr>
                        <th>Sản phẩm</th>
                        <th>Khách hàng</th>
                        <th>Số sao</th>
                        <th>Nhận xét</th>
                        <th>Ngày</th>
                        <th>Trạng thái</th>
                        <th>Phản hồi cửa hàng</th>
                        <th style="width:120px">
                            Hiển thị
                        </th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (empty($rows)): ?>

                        <tr>
                            <td
                                colspan="8"
                                class="text-center text-muted py-5"
                            >
                                Chưa có đánh giá phù hợp.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach (
                            $rows
                            as $row
                        ): ?>

                            <?php
                            $stars =
                                max(
                                    1,
                                    min(
                                        5,
                                        (int)$row[
                                            'so_sao'
                                        ]
                                    )
                                );

                            $isVisible =
                                (int)$row[
                                    'trang_thai'
                                ] === 1;

                            $replyText =
                                trim(
                                    (string)(
                                        $row[
                                            'phan_hoi_noi_dung'
                                        ]
                                        ?? ''
                                    )
                                );

                            $replyDate =
                                $row[
                                    'phan_hoi_ngay_cap_nhat'
                                ]
                                ?: (
                                    $row[
                                        'phan_hoi_ngay_tao'
                                    ]
                                    ?? null
                                );
                            ?>

                            <tr>

                                <td>
                                    <div class="review-product">

                                        <?php if (
                                            !empty(
                                                $row['hinh_anh']
                                            )
                                        ): ?>
                                            <img
                                                src="/iPhoneStore/uploads/<?= htmlspecialchars(
                                                    (string)$row['hinh_anh']
                                                ) ?>"
                                                class="review-product-image"
                                                alt=""
                                            >
                                        <?php endif; ?>

                                        <div>
                                            <div class="fw-bold">
                                                <?= htmlspecialchars(
                                                    (string)$row[
                                                        'ten_san_pham'
                                                    ]
                                                ) ?>
                                            </div>

                                            <a
                                                href="/iPhoneStore/user/san_pham/chi_tiet.php?id=<?= (int)$row['san_pham_id'] ?>#reviews"
                                                target="_blank"
                                                class="small"
                                            >
                                                Xem sản phẩm
                                            </a>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        (string)(
                                            $row['ho_ten']
                                            ?? 'Không xác định'
                                        )
                                    ) ?>
                                </td>

                                <td>
                                    <span class="review-admin-stars">
                                        <?= str_repeat(
                                            '★',
                                            $stars
                                        ) ?>

                                        <span style="color:#ddd">
                                            <?= str_repeat(
                                                '★',
                                                5 - $stars
                                            ) ?>
                                        </span>
                                    </span>
                                </td>

                                <td class="review-content-cell">
                                    <?= nl2br(
                                        htmlspecialchars(
                                            (string)(
                                                $row[
                                                    'noi_dung'
                                                ]
                                                ?? ''
                                            )
                                        )
                                    ) ?>

                                    <?php
                                    $adminImages =
                                        !empty($row['id'])
                                            ? (
                                                $adminImageMap[
                                                    (int)$row['id']
                                                ]
                                                ?? []
                                            )
                                            : [];
                                    ?>

                                    <?php if (
                                        !empty($adminImages)
                                    ): ?>
                                        <div class="review-admin-images">
                                            <?php foreach (
                                                $adminImages
                                                as $image
                                            ): ?>
                                                <a
                                                    class="review-admin-image"
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
                                                        alt="Ảnh đánh giá"
                                                        loading="lazy"
                                                    >
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= !empty(
                                        $row['ngay_tao']
                                    )
                                        ? date(
                                            'd/m/Y H:i',
                                            strtotime(
                                                $row[
                                                    'ngay_tao'
                                                ]
                                            )
                                        )
                                        : '-' ?>
                                </td>

                                <td>
                                    <span
                                        class="review-status <?= $isVisible
                                            ? 'show'
                                            : 'hide' ?>"
                                    >
                                        <?= $isVisible
                                            ? 'Đang hiển thị'
                                            : 'Đang ẩn' ?>
                                    </span>
                                </td>

                                <td class="review-reply-cell">

                                    <?php if (
                                        $replyText !== ''
                                    ): ?>
                                        <div class="review-reply-existing">

                                            <div class="review-reply-existing-title">
                                                💬 Đã phản hồi
                                            </div>

                                            <div class="review-reply-existing-text">
                                                <?= nl2br(
                                                    htmlspecialchars(
                                                        $replyText
                                                    )
                                                ) ?>
                                            </div>

                                            <div class="review-reply-existing-meta">
                                                <?= htmlspecialchars(
                                                    (string)(
                                                        $row[
                                                            'phan_hoi_admin'
                                                        ]
                                                        ?? 'Admin'
                                                    )
                                                ) ?>

                                                <?php if ($replyDate): ?>
                                                    ·
                                                    <?= date(
                                                        'd/m/Y H:i',
                                                        strtotime(
                                                            $replyDate
                                                        )
                                                    ) ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (
                                        $coBangPhanHoi &&
                                        $idColumn !== null &&
                                        !empty($row['id'])
                                    ): ?>

                                        <form
                                            method="POST"
                                            action="/iPhoneStore/admin/danh_gia/tra_loi.php"
                                            class="review-reply-form"
                                        >
                                            <input
                                                type="hidden"
                                                name="danh_gia_id"
                                                value="<?= (int)$row['id'] ?>"
                                            >

                                            <textarea
                                                name="noi_dung"
                                                class="form-control"
                                                maxlength="2000"
                                                required
                                                placeholder="Nhập phản hồi của cửa hàng..."
                                            ><?= htmlspecialchars(
                                                $replyText
                                            ) ?></textarea>

                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-danger"
                                            >
                                                <?= $replyText !== ''
                                                    ? 'Cập nhật phản hồi'
                                                    : 'Trả lời đánh giá' ?>
                                            </button>
                                        </form>

                                    <?php else: ?>
                                        <span class="text-muted small">
                                            Chưa bật chức năng phản hồi.
                                        </span>
                                    <?php endif; ?>

                                </td>

                                <td>
                                    <?php if (
                                        $statusColumn !== null &&
                                        $idColumn !== null &&
                                        !empty($row['id'])
                                    ): ?>

                                        <form
                                            method="POST"
                                            action="/iPhoneStore/admin/danh_gia/cap_nhat_trang_thai.php"
                                        >
                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= (int)$row['id'] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="trang_thai"
                                                value="<?= $isVisible
                                                    ? 0
                                                    : 1 ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="btn btn-sm <?= $isVisible
                                                    ? 'btn-outline-danger'
                                                    : 'btn-outline-success' ?>"
                                            >
                                                <?= $isVisible
                                                    ? 'Ẩn'
                                                    : 'Hiện' ?>
                                            </button>
                                        </form>

                                    <?php else: ?>
                                        <span class="text-muted small">
                                            Không hỗ trợ
                                        </span>
                                    <?php endif; ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>
            </table>
        </div>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="mt-3">
            <ul class="pagination justify-content-center">

                <?php for (
                    $p = 1;
                    $p <= $totalPages;
                    $p++
                ): ?>
                    <li class="page-item <?= $p === $page
                        ? 'active'
                        : '' ?>">

                        <a
                            class="page-link"
                            href="?<?= http_build_query([
                                'keyword' => $keyword,
                                'star' => $starFilter,
                                'status' => $statusFilter,
                                'reply' => $replyFilter,
                                'page' => $p
                            ]) ?>"
                        >
                            <?= $p ?>
                        </a>

                    </li>
                <?php endfor; ?>

            </ul>
        </nav>
    <?php endif; ?>

</div>

<?php
require_once "../../includes/footer.php";
?>
