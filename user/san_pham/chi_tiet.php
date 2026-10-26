<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
    Trang chi tiết sản phẩm là PUBLIC:
    - khách chưa đăng nhập vẫn xem sản phẩm bình thường;
    - chỉ khi Thêm vào giỏ / Mua ngay mới yêu cầu đăng nhập.
*/
require_once "../../config/database.php";
require_once "../../includes/khuyen_mai_helper.php";

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

function layCotAnhBienThe(
    mysqli $conn
): ?string {
    /*
        Hỗ trợ cả 2 tên cột đã từng dùng:
        - hinh_anh
        - duong_dan_anh

        Nhờ vậy không cần đổi database cũ ngay.
    */
    foreach (
        [
            'hinh_anh',
            'duong_dan_anh'
        ]
        as $columnName
    ) {
        $stmt = $conn->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME =
                'san_pham_bien_the_hinh_anh'
            AND COLUMN_NAME = ?
            LIMIT 1
        ");

        if (!$stmt) {
            continue;
        }

        $stmt->bind_param(
            's',
            $columnName
        );

        $stmt->execute();

        if (
            $stmt
                ->get_result()
                ->fetch_assoc()
        ) {
            return $columnName;
        }
    }

    return null;
}

function coBangAnhBienThe(
    mysqli $conn
): bool {
    return layCotAnhBienThe(
        $conn
    ) !== null;
}



/* =========================================================
   1. SẢN PHẨM
========================================================= */
$id = (int)($_GET['id'] ?? 0);

/*
    Khi người dùng bấm từ khu vực khuyến mãi ở trang chủ,
    URL có thể truyền bien_the_id.
    Nếu ID này thuộc đúng sản phẩm hiện tại thì trang chi tiết
    sẽ tự chọn chính xác dung lượng + màu đang được ưu đãi.
*/
$requestedVariantId =
    (int)($_GET['bien_the_id'] ?? 0);

if ($id <= 0) {
    die("ID sản phẩm không hợp lệ");
}

$stmtProduct = $conn->prepare("
    SELECT *
    FROM san_pham
    WHERE id = ?
    AND trang_thai = 1
    LIMIT 1
");
$stmtProduct->bind_param("i", $id);
$stmtProduct->execute();
$sp = $stmtProduct->get_result()->fetch_assoc();

if (!$sp) {
    die("Không tìm thấy sản phẩm");
}

/* =========================================================
   MODULE ĐÁNH GIÁ
   Chỉ lấy metadata sớm để phần "Sản phẩm liên quan"
   biết có cột trạng thái hay không.
========================================================= */
require_once __DIR__ . '/../danh_gia/ham_ho_tro.php';

$reviewModuleMeta =
    dgMeta($conn);

$reviewStatusColumn =
    $reviewModuleMeta['status'];

/* =========================================================
   2. HÌNH ẢNH
========================================================= */
$stmtImages = $conn->prepare("
    SELECT hinh_anh
    FROM san_pham_hinh_anh
    WHERE san_pham_id = ?
    ORDER BY thu_tu ASC, id ASC
");
$stmtImages->bind_param("i", $id);
$stmtImages->execute();
$resultImages = $stmtImages->get_result();
$productImages = [];

while ($image = $resultImages->fetch_assoc()) {
    if (!empty($image['hinh_anh'])) {
        $productImages[] = $image['hinh_anh'];
    }
}

if (empty($productImages) && !empty($sp['hinh_anh'])) {
    $productImages[] = $sp['hinh_anh'];
}

if (
    !empty($sp['hinh_anh']) &&
    !in_array($sp['hinh_anh'], $productImages, true)
) {
    array_unshift($productImages, $sp['hinh_anh']);
}

$mainImage = $productImages[0] ?? '';
$galleryUrls = [];

foreach ($productImages as $image) {
    $galleryUrls[] = "../../uploads/" . $image;
}

/* =========================================================
   3. BIẾN THỂ = MÀU + RAM + ROM + GIÁ + TỒN KHO
========================================================= */
$stmtVariants = $conn->prepare("
    SELECT
        bt.id,
        bt.san_pham_id,
        bt.mau_id,
        bt.ram,
        bt.rom,
        bt.gia,
        bt.so_luong,
        ms.ten_mau,
        ms.ma_mau
    FROM san_pham_bien_the bt
    LEFT JOIN mau_sac ms ON ms.id = bt.mau_id
    WHERE bt.san_pham_id = ?
    ORDER BY bt.mau_id ASC, bt.gia ASC, bt.id ASC
");
$stmtVariants->bind_param("i", $id);
$stmtVariants->execute();
$variantsArray = $stmtVariants->get_result()->fetch_all(MYSQLI_ASSOC);
$coBienThe = count($variantsArray) > 0;

$requestedVariantValid = false;

if ($requestedVariantId > 0) {
    foreach ($variantsArray as $variantRow) {
        if (
            (int)($variantRow['id'] ?? 0) ===
            $requestedVariantId
        ) {
            $requestedVariantValid = true;
            break;
        }
    }
}

/*
    Không cho URL ép một biến thể của sản phẩm khác.
*/
if (!$requestedVariantValid) {
    $requestedVariantId = 0;
}

/* =========================================================
   3.1 ẢNH RIÊNG THEO BIẾN THỂ
========================================================= */
$cotAnhBienThe =
    layCotAnhBienThe($conn);

$coBangAnhBienThe =
    $cotAnhBienThe !== null;

$variantImagesById = [];

if (
    $coBangAnhBienThe &&
    $coBienThe
) {
    $stmtVariantImages =
        $conn->prepare("
            SELECT
                ha.bien_the_id,
                ha.`{$cotAnhBienThe}` AS hinh_anh,
                ha.la_anh_chinh,
                ha.thu_tu,
                ha.id

            FROM san_pham_bien_the_hinh_anh ha

            JOIN san_pham_bien_the bt
                ON bt.id = ha.bien_the_id

            WHERE bt.san_pham_id = ?

            ORDER BY
                ha.bien_the_id ASC,
                ha.la_anh_chinh DESC,
                ha.thu_tu ASC,
                ha.id ASC
        ");

    if ($stmtVariantImages) {
        $stmtVariantImages->bind_param(
            "i",
            $id
        );

        $stmtVariantImages->execute();

        $variantImageRows =
            $stmtVariantImages
                ->get_result()
                ->fetch_all(MYSQLI_ASSOC);

        foreach (
            $variantImageRows
            as $variantImage
        ) {
            $variantId =
                (int)$variantImage[
                    'bien_the_id'
                ];

            if (
                !isset(
                    $variantImagesById[
                        $variantId
                    ]
                )
            ) {
                $variantImagesById[
                    $variantId
                ] = [];
            }

            $imagePath =
                trim(
                    (string)$variantImage[
                        'hinh_anh'
                    ]
                );

            if ($imagePath !== '') {
                $variantImagesById[
                    $variantId
                ][] =
                    "../../uploads/" .
                    $imagePath;
            }
        }
    }
}

/* =========================================================
   4. MÀU SẮC
========================================================= */
$colorsArray = [];

if ($coBienThe) {
    $seenColors = [];

    foreach ($variantsArray as $variant) {
        $mauId = (int)($variant['mau_id'] ?? 0);

        if ($mauId <= 0 || isset($seenColors[$mauId])) {
            continue;
        }

        $seenColors[$mauId] = true;

        $colorsArray[] = [
            'id' => $mauId,
            'ten_mau' => $variant['ten_mau'] ?: 'Màu #' . $mauId,
            'ma_mau' => $variant['ma_mau'] ?: '#999999'
        ];
    }
} else {
    $stmtColors = $conn->prepare("
        SELECT ms.id, ms.ten_mau, ms.ma_mau
        FROM san_pham_mau spm
        JOIN mau_sac ms ON ms.id = spm.mau_id
        WHERE spm.san_pham_id = ?
        ORDER BY ms.id ASC
    ");
    $stmtColors->bind_param("i", $id);
    $stmtColors->execute();
    $colorsArray = $stmtColors->get_result()->fetch_all(MYSQLI_ASSOC);
}

$coMau = count($colorsArray) > 0;

/* =========================================================
   5. KHUYẾN MÃI ĐANG HIỆU LỰC
   DÙNG CHUNG HELPER VỚI GIỎ HÀNG.

   Nhờ vậy:
   - chi tiết sản phẩm;
   - thêm vào giỏ;
   - giỏ hàng;
   - tăng / giảm số lượng

   đều xét cùng một bộ điều kiện khuyến mãi.
========================================================= */
$nguoiDungKhuyenMaiId =
    (int)($_SESSION['user']['id'] ?? 0);

/*
    Tồn kho dùng để xét KM cấp sản phẩm phải phản ánh đúng tồn thực tế.
    Nếu có biến thể thì lấy tổng tồn các biến thể, không phụ thuộc số lượng
    tổng hợp cũ trong san_pham.
*/
$productPromotionStock =
    $coBienThe
        ? max(
            0,
            ...array_map(
                fn($variant) => (int)$variant['so_luong'],
                $variantsArray
            )
        )
        : (int)$sp['so_luong'];

$productPromotion =
    layKhuyenMaiHienTai(
        $conn,
        $id,
        null,
        [
            'gia_goc' =>
                (float)$sp['gia'],
            'so_luong' => 1,
            'ton_kho' =>
                $productPromotionStock,
            'nguoi_dung_id' =>
                $nguoiDungKhuyenMaiId
        ]
    );

$variantPromotions = [];

foreach ($variantsArray as $variant) {
    $variantId =
        (int)$variant['id'];

    $variantPromo =
        layKhuyenMaiHienTai(
            $conn,
            $id,
            $variantId,
            [
                'gia_goc' =>
                    (float)$variant['gia'],
                'so_luong' => 1,
                'ton_kho' =>
                    (int)$variant['so_luong'],
                'nguoi_dung_id' =>
                    $nguoiDungKhuyenMaiId
            ]
        );

    if ($variantPromo) {
        $variantPromotions[
            $variantId
        ] = $variantPromo;
    }
}

/* =========================================================
   6. DATA BIẾN THỂ CHO JAVASCRIPT
   KM riêng biến thể ưu tiên hơn KM chung.
========================================================= */
$allGalleryUrls = $galleryUrls;

foreach ($variantImagesById as $variantImageList) {
    foreach ($variantImageList as $variantImageUrl) {
        if (
            $variantImageUrl !== '' &&
            !in_array(
                $variantImageUrl,
                $allGalleryUrls,
                true
            )
        ) {
            $allGalleryUrls[] =
                $variantImageUrl;
        }
    }
}

$variantsForJs = [];

foreach ($variantsArray as $variant) {
    $variantId = (int)$variant['id'];
    $promo = $variantPromotions[$variantId] ?? null;
    $giaGoc = (float)$variant['gia'];

    $variantsForJs[] = [
        'id' => $variantId,
        'mau_id' => (int)($variant['mau_id'] ?? 0),
        'ram' => (string)$variant['ram'],
        'rom' => (string)$variant['rom'],
        'gia_goc' => $giaGoc,
        'gia_ban' => tinhGiaSauKhuyenMai($giaGoc, $promo),
        'so_luong' => (int)$variant['so_luong'],
        'images' =>
            $variantImagesById[$variantId]
            ?? [],
        'khuyen_mai' => $promo ? [
            'id' => (int)$promo['id'],
            'ten' => $promo['ten_khuyen_mai'],
            'ly_do' => $promo['ly_do'],
            'loai' => $promo['loai_khuyen_mai'],
            'kieu' => $promo['kieu_giam'],
            'gia_tri' => (float)$promo['gia_tri'],
            'ngay_bat_dau' => $promo['ngay_bat_dau'],
            'ngay_ket_thuc' => $promo['ngay_ket_thuc'],
            'gio_bat_dau' => $promo['gio_bat_dau'] ?? null,
            'gio_ket_thuc' => $promo['gio_ket_thuc'] ?? null,
            'la_km_rieng' => isset($variantPromotions[$variantId])
        ] : null
    ];
}

/* =========================================================
   7. TỒN KHO
========================================================= */
if ($coBienThe) {
    $tongTonKho = array_sum(
        array_map(
            fn($variant) => (int)$variant['so_luong'],
            $variantsArray
        )
    );
} else {
    $tongTonKho = (int)$sp['so_luong'];
}

$productOutOfStock =
    $tongTonKho <= 0;

/*
    Lớp bảo vệ giao diện:
    sản phẩm hết hàng tuyệt đối không giữ lại KM để hiển thị.
*/
if ($productOutOfStock) {
    $productPromotion = null;
}

/* =========================================================
   8. ĐÃ BÁN
========================================================= */
$stmtSold = $conn->prepare("
    SELECT COALESCE(SUM(so_luong), 0) AS da_ban
    FROM chi_tiet_don_hang
    WHERE san_pham_id = ?
");
$stmtSold->bind_param("i", $id);
$stmtSold->execute();
$dataSold = $stmtSold->get_result()->fetch_assoc();
$daBan = (int)($dataSold['da_ban'] ?? 0);

/* =========================================================
   9. TÓM TẮT ĐÁNH GIÁ CHO PHẦN THÔNG TIN SẢN PHẨM

   Phần đánh giá chi tiết đã tách sang:
   user/danh_gia/index.php

   Tuy nhiên ở đầu trang chi tiết vẫn cần:
   - $avgSao
   - $tongDanhGia

   để hiển thị ngay dưới tên sản phẩm.
========================================================= */
$avgSao = 0.0;
$tongDanhGia = 0;

$reviewSummaryCondition = '';

if ($reviewStatusColumn !== null) {
    $reviewSummaryCondition =
        "AND `$reviewStatusColumn` = 1";
}

$stmtReviewSummary = $conn->prepare("
    SELECT
        COUNT(*) AS tong_danh_gia,
        COALESCE(
            AVG(so_sao),
            0
        ) AS avg_sao

    FROM danh_gia

    WHERE san_pham_id = ?
    $reviewSummaryCondition
");

if ($stmtReviewSummary) {
    $stmtReviewSummary->bind_param(
        "i",
        $id
    );

    $stmtReviewSummary->execute();

    $reviewSummary =
        $stmtReviewSummary
            ->get_result()
            ->fetch_assoc();

    $tongDanhGia =
        (int)(
            $reviewSummary['tong_danh_gia']
            ?? 0
        );

    $avgSao =
        round(
            (float)(
                $reviewSummary['avg_sao']
                ?? 0
            ),
            1
        );
}

/* =========================================================
   10. MÔ TẢ NGẮN + MÔ TẢ DÀI
========================================================= */
$moTaNgan = trim((string)($sp['mo_ta_ngan'] ?? ''));
$moTaDai = trim((string)($sp['mo_ta_dai'] ?? ''));

if ($moTaDai === '') {
    $moTaDai = trim((string)($sp['mo_ta'] ?? ''));
}

/* =========================================================
   11. THÔNG SỐ KỸ THUẬT
========================================================= */
$thongSoGroups = [];

$stmtSpecs = $conn->prepare("
    SELECT
        nhom_ma,
        nhom_ten,
        ten_thong_so,
        gia_tri,
        thu_tu_nhom,
        thu_tu
    FROM san_pham_thong_so
    WHERE san_pham_id = ?
    ORDER BY
        thu_tu_nhom ASC,
        thu_tu ASC,
        id ASC
");

if ($stmtSpecs) {
    $stmtSpecs->bind_param(
        "i",
        $id
    );

    $stmtSpecs->execute();

    $specRows =
        $stmtSpecs
            ->get_result()
            ->fetch_all(MYSQLI_ASSOC);

    foreach ($specRows as $specRow) {
        $groupKey =
            (string)$specRow['nhom_ma'];

        if (!isset($thongSoGroups[$groupKey])) {
            $thongSoGroups[$groupKey] = [
                'ma' => $groupKey,
                'ten' => $specRow['nhom_ten'],
                'items' => []
            ];
        }

        $thongSoGroups[$groupKey]['items'][] = [
            'ten' => $specRow['ten_thong_so'],
            'gia_tri' => $specRow['gia_tri']
        ];
    }
}

/* =========================================================
   12. SẢN PHẨM LIÊN QUAN
   - Cùng danh mục với sản phẩm đang xem.
   - Không lấy chính sản phẩm hiện tại.
   - Ưu tiên sản phẩm còn hàng.
   - Ưu tiên mức giá gần sản phẩm hiện tại.
   - Tối đa 8 sản phẩm.
========================================================= */
$relatedProducts = [];

$danhMucIdHienTai =
    (int)($sp['danh_muc_id'] ?? 0);

$giaThamChieu =
    $coBienThe && !empty($variantsArray)
        ? (float)min(
            array_column(
                $variantsArray,
                'gia'
            )
        )
        : (float)$sp['gia'];

$relatedReviewWhere =
    $reviewStatusColumn !== null
        ? "WHERE `$reviewStatusColumn` = 1"
        : '';

if ($danhMucIdHienTai > 0) {
    $sqlRelated = "
        SELECT
            p.id,
            p.ten_san_pham,
            p.hinh_anh,
            p.gia,
            p.gia_khuyen_mai,
            p.so_luong,

            COALESCE(
                bt.min_gia,
                p.gia
            ) AS gia_hien_thi,

            CASE
                WHEN bt.san_pham_id IS NOT NULL
                    THEN COALESCE(bt.tong_ton, 0)
                ELSE COALESCE(p.so_luong, 0)
            END AS tong_ton,

            COALESCE(
                dg.avg_sao,
                0
            ) AS avg_sao,

            COALESCE(
                dg.tong_danh_gia,
                0
            ) AS tong_danh_gia,

            COALESCE(
                db.da_ban,
                0
            ) AS da_ban,

            km.kieu_giam AS km_kieu_giam,
            km.gia_tri AS km_gia_tri

        FROM san_pham p

        LEFT JOIN (
            SELECT
                san_pham_id,
                MIN(gia) AS min_gia,
                SUM(so_luong) AS tong_ton
            FROM san_pham_bien_the
            GROUP BY san_pham_id
        ) bt
            ON bt.san_pham_id = p.id

        LEFT JOIN (
            SELECT
                san_pham_id,
                AVG(so_sao) AS avg_sao,
                COUNT(*) AS tong_danh_gia
            FROM danh_gia
            $relatedReviewWhere
            GROUP BY san_pham_id
        ) dg
            ON dg.san_pham_id = p.id

        LEFT JOIN (
            SELECT
                san_pham_id,
                SUM(so_luong) AS da_ban
            FROM chi_tiet_don_hang
            GROUP BY san_pham_id
        ) db
            ON db.san_pham_id = p.id

        LEFT JOIN khuyen_mai km
            ON km.id = (
                SELECT km2.id
                FROM khuyen_mai km2
                WHERE km2.san_pham_id = p.id
                AND km2.bien_the_id IS NULL
                AND km2.trang_thai = 1
                AND NOW() BETWEEN
                    km2.ngay_bat_dau
                    AND km2.ngay_ket_thuc
                ORDER BY km2.id DESC
                LIMIT 1
            )

        WHERE p.trang_thai = 1
        AND p.danh_muc_id = ?
        AND p.id <> ?

        ORDER BY
            CASE
                WHEN (
                    CASE
                        WHEN bt.san_pham_id IS NOT NULL
                            THEN COALESCE(bt.tong_ton, 0)
                        ELSE COALESCE(p.so_luong, 0)
                    END
                ) > 0
                    THEN 0
                ELSE 1
            END ASC,

            ABS(
                COALESCE(
                    bt.min_gia,
                    p.gia
                ) - ?
            ) ASC,

            p.id DESC

        LIMIT 8
    ";

    $stmtRelated =
        $conn->prepare($sqlRelated);

    if ($stmtRelated) {
        $stmtRelated->bind_param(
            "iid",
            $danhMucIdHienTai,
            $id,
            $giaThamChieu
        );

        $stmtRelated->execute();

        $relatedProducts =
            $stmtRelated
                ->get_result()
                ->fetch_all(MYSQLI_ASSOC);

        /*
            Đồng bộ khuyến mãi sản phẩm liên quan với helper dùng chung.
            Không dùng kết quả JOIN khuyến mãi phía trên làm nguồn quyết định
            vì JOIN cũ chỉ xét ngày, chưa xét đầy đủ:
            - khung giờ;
            - đối tượng khách hàng;
            - tồn kho;
            - số lượng tối thiểu;
            - các điều kiện nâng cao khác.
        */
        foreach ($relatedProducts as &$relatedProduct) {
            $relatedPromo =
                layKhuyenMaiHienTai(
                    $conn,
                    (int)$relatedProduct['id'],
                    null,
                    [
                        'gia_goc' =>
                            (float)$relatedProduct['gia_hien_thi'],
                        'so_luong' => 1,
                        'ton_kho' =>
                            (int)$relatedProduct['tong_ton'],
                        'nguoi_dung_id' =>
                            $nguoiDungKhuyenMaiId,
                    ]
                );

            $relatedProduct['km_kieu_giam'] =
                $relatedPromo['kieu_giam']
                ?? null;

            $relatedProduct['km_gia_tri'] =
                $relatedPromo['gia_tri']
                ?? null;
        }

        unset($relatedProduct);
    }
}

/* =========================================================
   13. GIÁ HIỂN THỊ BAN ĐẦU
========================================================= */
$initialSalePrice =
    $productOutOfStock
        ? (float)$sp['gia']
        : tinhGiaSauKhuyenMai(
            (float)$sp['gia'],
            $productPromotion
        );

if ($coBienThe && !empty($variantsForJs)) {
    $initialSalePrice =
        $productOutOfStock
            ? min(
                array_column(
                    $variantsForJs,
                    'gia_goc'
                )
            )
            : min(
                array_column(
                    $variantsForJs,
                    'gia_ban'
                )
            );
}
?>

<?php require_once "../../includes/header.php"; ?>
<?php require_once "../../includes/navbar.php"; ?>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
>

<style>
.shopee-card {
    background: #fff;
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, .08);
}

.price-label {
    color: #777;
    font-size: 14px;
}

.price-big {
    font-size: 30px;
    color: #ee4d2d;
    font-weight: 700;
}

.old-price {
    display: inline-block;
    margin-right: 10px;
    text-decoration: line-through;
    color: #999;
    font-size: 18px;
    font-weight: 400;
}

.discount-badge {
    display: inline-block;
    vertical-align: middle;
    background: #ee4d2d;
    color: #fff;
    border-radius: 6px;
    padding: 3px 8px;
    font-size: 13px;
    font-weight: 700;
}

.promo-box {
    display: none;
    margin-top: 14px;
    padding: 14px;
    border: 1px dashed #ee4d2d;
    border-radius: 10px;
    background: #fff8f5;
}

.promo-name {
    color: #ee4d2d;
    font-weight: 700;
}

.color-btn.active,
.variant-btn.active {
    background: #ee4d2d !important;
    color: #fff !important;
    border-color: #ee4d2d !important;
}

.product-variant-panel {
    margin-top: 24px;
}

.variant-section-title {
    margin-bottom: 10px;
    color: #202124;
    font-size: 18px;
    font-weight: 800;
}

.version-options {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
}

.version-option {
    min-height: 70px;
    border: 1px solid #dfe3e8;
    border-radius: 12px;
    background: #fff;
    color: #222;
    font-size: 15px;
    font-weight: 700;
    transition: .18s ease;
}

.version-option:hover {
    border-color: #ee4d2d;
}

.version-option.active {
    position: relative;
    border: 2px solid #ee4d2d;
    color: #ee4d2d;
    background: #fff;
}

.version-option.active::after {
    content: "✓";
    position: absolute;
    top: -1px;
    right: -1px;
    width: 23px;
    height: 23px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 0 9px 0 10px;
    background: #ee4d2d;
    color: #fff;
    font-size: 12px;
    font-weight: 900;
}

.version-option:disabled {
    opacity: .45;
    cursor: not-allowed;
    background: #f5f5f5;
}

.color-variant-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
}

.color-variant-card {
    position: relative;
    min-height: 80px;
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 9px 11px;
    border: 1px solid #dfe3e8;
    border-radius: 12px;
    background: #fff;
    color: #222;
    text-align: left;
    transition: .18s ease;
}

.color-variant-card:hover {
    border-color: #ee4d2d;
}

.color-variant-card.active {
    border: 2px solid #ee4d2d;
}

.color-variant-card.active::after {
    content: "✓";
    position: absolute;
    top: -1px;
    right: -1px;
    width: 23px;
    height: 23px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 0 9px 0 10px;
    background: #ee4d2d;
    color: #fff;
    font-size: 12px;
    font-weight: 900;
}

.color-variant-card:disabled {
    opacity: .5;
    cursor: not-allowed;
    background: #f7f7f7;
}

.color-variant-thumb {
    width: 46px;
    height: 54px;
    flex: 0 0 46px;
    object-fit: contain;
    border-radius: 7px;
    background: #f6f7f8;
}

.color-variant-thumb-placeholder {
    width: 46px;
    height: 54px;
    flex: 0 0 46px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 7px;
    background: #f6f7f8;
    font-size: 24px;
}

.color-variant-info {
    min-width: 0;
    flex: 1;
}

.color-variant-name {
    overflow: hidden;
    color: #222;
    font-size: 14px;
    font-weight: 800;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.color-variant-price {
    margin-top: 2px;
    color: #333;
    font-size: 13px;
    font-weight: 600;
}

.color-variant-stock {
    margin-top: 2px;
    color: #8a8f98;
    font-size: 11px;
}

.variant-empty {
    padding: 12px 14px;
    border-radius: 9px;
    background: #f8f9fa;
    color: #777;
}

@media (max-width: 767px) {
    .version-options,
    .color-variant-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 430px) {
    .version-options,
    .color-variant-grid {
        grid-template-columns: 1fr;
    }
}

.color-btn {
    min-width: 90px;
}

.variant-btn:disabled {
    opacity: .5;
    cursor: not-allowed;
    background: #eee;
    border-color: #ccc;
    color: #777;
}

.variant-placeholder {
    padding: 12px 14px;
    border-radius: 8px;
    background: #f8f9fa;
    color: #777;
}

.qty-btn {
    width: 42px;
    height: 42px;
}

.qty-input {
    width: 80px;
    height: 42px;
}

.stock-text {
    min-width: 220px;
}

.product-out-of-stock-box {
    margin-top: 12px;
    padding: 13px 15px;
    border: 1px solid #ffd59a;
    border-radius: 12px;
    background: #fff8ec;
}

.product-out-of-stock-title {
    color: #d97706;
    font-size: 14px;
    font-weight: 900;
}

.product-out-of-stock-note {
    margin-top: 3px;
    color: #7c8798;
    font-size: 12px;
}

.product-out-of-stock-box .badge {
    margin-right: 6px;
}

.short-description {
    padding: 14px;
    border-left: 4px solid #ee4d2d;
    border-radius: 8px;
    background: #fff8f5;
    color: #555;
}

.description-content {
    line-height: 1.8;
    color: #555;
}

.description-content.collapsed {
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 7;
    -webkit-box-orient: vertical;
}

.show-more {
    color: #ee4d2d;
    cursor: pointer;
    font-weight: 600;
}

/* =========================================================
   TAB MÔ TẢ / THÔNG SỐ
========================================================= */
.product-content-tabs {
    display: flex;
    gap: 12px;
    margin-bottom: 22px;
}

.product-tab-btn {
    min-width: 150px;
    padding: 11px 20px;
    border: 1px solid #ddd;
    border-radius: 11px;
    background: #fff;
    color: #333;
    font-weight: 700;
    transition: .2s;
}

.product-tab-btn:hover {
    border-color: #0d6efd;
    color: #0d6efd;
}

.product-tab-btn.active {
    border-color: #8ec5ff;
    background: #eef6ff;
    color: #0d6efd;
}

.product-tab-pane {
    display: none;
}

.product-tab-pane.active {
    display: block;
}

.empty-content-box {
    padding: 25px;
    border-radius: 12px;
    background: #f8f9fa;
    color: #777;
    text-align: center;
}

/* =========================================================
   THÔNG SỐ KỸ THUẬT
========================================================= */
.spec-group {
    margin-bottom: 14px;
}

.spec-group:last-child {
    margin-bottom: 0;
}

.spec-group-header {
    width: 100%;
    min-height: 64px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    padding: 15px 17px;
    border: 0;
    border-radius: 12px;
    background: #f1f3f6;
    color: #172033;
    text-align: left;
    font-size: 17px;
    font-weight: 700;
}

.spec-arrow {
    font-size: 24px;
    line-height: 1;
    transition: transform .2s;
}

.spec-group.open .spec-arrow {
    transform: rotate(180deg);
}

.spec-group-body {
    display: none;
    padding: 4px 0 8px;
}

.spec-group.open .spec-group-body {
    display: block;
}

.spec-row {
    display: grid;
    grid-template-columns: minmax(180px, 34%) 1fr;
    gap: 25px;
    padding: 14px 16px;
    border-bottom: 1px solid #e8e8e8;
}

.spec-row:last-child {
    border-bottom: 0;
}

.spec-name {
    color: #333;
    font-weight: 700;
}

.spec-value {
    color: #1677ff;
    line-height: 1.65;
}

/* =========================================================
   SẢN PHẨM LIÊN QUAN
========================================================= */
.related-section {
    position: relative;
}

.related-header {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 18px;
    margin-bottom: 18px;
}

.related-title {
    margin: 0;
    color: #172033;
    font-size: 22px;
    font-weight: 800;
}

.related-subtitle {
    margin-top: 4px;
    color: #7a8496;
    font-size: 13px;
}

.related-nav {
    display: flex;
    gap: 8px;
}

.related-nav-btn {
    width: 40px;
    height: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #dedede;
    border-radius: 50%;
    background: #fff;
    color: #333;
    font-size: 24px;
    line-height: 1;
    transition: .2s;
}

.related-nav-btn:hover {
    border-color: #ee4d2d;
    color: #ee4d2d;
    box-shadow: 0 6px 18px rgba(238, 77, 45, .10);
}

.related-track {
    display: grid;
    grid-auto-flow: column;
    grid-auto-columns: calc((100% - 48px) / 4);
    gap: 16px;
    overflow-x: auto;
    padding: 4px 2px 12px;
    scroll-behavior: smooth;
    scroll-snap-type: x proximity;
    scrollbar-width: none;
}

.related-track::-webkit-scrollbar {
    display: none;
}

.related-card {
    min-width: 0;
    overflow: hidden;
    border: 1px solid #ececec;
    border-radius: 14px;
    background: #fff;
    scroll-snap-align: start;
    transition:
        transform .2s ease,
        border-color .2s ease,
        box-shadow .2s ease;
}

.related-card:hover {
    transform: translateY(-4px);
    border-color: #ffd4ca;
    box-shadow: 0 12px 30px rgba(0, 0, 0, .09);
}

.related-card-link {
    display: block;
    height: 100%;
    color: inherit;
    text-decoration: none;
}

.related-image-box {
    position: relative;
    height: 210px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    padding: 14px;
    background: #fff;
}

.related-image {
    width: 100%;
    height: 100%;
    object-fit: contain;
    transition: transform .25s ease;
}

.related-card:hover .related-image {
    transform: scale(1.045);
}

.related-no-image {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    background: #f5f6f8;
    color: #9ca3af;
    font-size: 13px;
}

.related-discount {
    position: absolute;
    top: 12px;
    left: 12px;
    z-index: 2;
    padding: 5px 8px;
    border-radius: 6px;
    background: #ee4d2d;
    color: #fff;
    font-size: 11px;
    font-weight: 800;
}

.related-stock-badge {
    position: absolute;
    right: 12px;
    bottom: 10px;
    padding: 4px 7px;
    border-radius: 999px;
    background: rgba(23, 32, 51, .82);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
}

.related-card-body {
    padding: 13px 14px 16px;
}

.related-name {
    min-height: 44px;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    color: #222;
    font-size: 14px;
    line-height: 1.55;
    font-weight: 700;
}

.related-price-row {
    min-height: 48px;
    margin-top: 9px;
}

.related-price {
    color: #d70018;
    font-size: 17px;
    font-weight: 800;
}

.related-old-price {
    margin-top: 1px;
    color: #9ca3af;
    font-size: 12px;
    text-decoration: line-through;
}

.related-meta {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 5px 8px;
    margin-top: 9px;
    color: #7c8798;
    font-size: 11px;
}

.related-rating {
    color: #ff9800;
    font-weight: 700;
}

.related-dot {
    width: 3px;
    height: 3px;
    border-radius: 50%;
    background: #c4c8cf;
}

@media (max-width: 991px) {
    .related-track {
        grid-auto-columns: calc((100% - 32px) / 3);
    }
}

@media (max-width: 767px) {
    .related-track {
        grid-auto-columns: calc((100% - 14px) / 2);
        gap: 14px;
    }

    .related-image-box {
        height: 175px;
    }

    .related-nav {
        display: none;
    }
}

@media (max-width: 430px) {
    .related-track {
        grid-auto-columns: 72%;
    }

    .related-title {
        font-size: 20px;
    }
}

.product-gallery {
    overflow: hidden;
}

.main-image-box {
    width: 100%;
    height: 520px;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #fff;
    border-radius: 14px;
    overflow: hidden;
}

.product-image {
    width: 100%;
    height: 100%;
    object-fit: contain;
    border-radius: 12px;
    transition: opacity .16s ease;
    will-change: opacity;
}

.product-image.changing {
    opacity: .35;
}

.no-image {
    width: 100%;
    height: 100%;
    min-height: 350px;
    display: flex;
    justify-content: center;
    align-items: center;
    color: #999;
    background: #f5f5f5;
    border-radius: 12px;
}

.gallery-arrow {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    z-index: 10;
    width: 42px;
    height: 58px;
    border: 0;
    border-radius: 8px;
    color: #fff;
    background: rgba(0, 0, 0, .35);
    font-size: 35px;
    opacity: 0;
    transition: .2s;
}

.product-gallery:hover .gallery-arrow {
    opacity: 1;
}

.gallery-arrow:hover {
    background: rgba(0, 0, 0, .68);
}

.gallery-prev {
    left: 10px;
}

.gallery-next {
    right: 10px;
}

.thumbnail-wrapper {
    display: flex;
    align-items: center;
    gap: 8px;
}

.thumbnail-list {
    display: flex;
    gap: 10px;
    flex: 1;
    min-width: 0;
    overflow-x: auto;
    scroll-behavior: smooth;
    scrollbar-width: none;
    padding: 3px 0;
}

.thumbnail-list::-webkit-scrollbar {
    display: none;
}

.product-thumbnail {
    width: 78px;
    height: 78px;
    flex-shrink: 0;
    object-fit: cover;
    border-radius: 10px;
    border: 2px solid transparent;
    padding: 2px;
    cursor: pointer;
    opacity: .68;
    background: #fff;
    transition: .2s;
}

.product-thumbnail:hover {
    opacity: 1;
    transform: translateY(-2px);
}

.product-thumbnail.active {
    opacity: 1;
    border-color: #ee4d2d;
}

.thumbnail-scroll-btn {
    width: 30px;
    height: 65px;
    flex-shrink: 0;
    border: 0;
    border-radius: 7px;
    background: #eee;
    font-size: 25px;
}

.image-counter {
    font-size: 13px;
    color: #888;
}

@media (max-width: 767px) {
    .main-image-box {
        height: 380px;
    }

    .product-thumbnail {
        width: 65px;
        height: 65px;
    }

    .gallery-arrow {
        opacity: 1;
        width: 36px;
        height: 50px;
    }

    .product-content-tabs {
        gap: 8px;
    }

    .product-tab-btn {
        flex: 1;
        min-width: 0;
        padding: 10px 12px;
    }

    .spec-row {
        grid-template-columns: 1fr;
        gap: 6px;
    }

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
}

/* =========================================================
   AUTO SELECT BIẾN THỂ KHUYẾN MÃI
========================================================= */
.promo-auto-selected-note {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    margin: 0 0 12px;
    padding: 8px 11px;
    border: 1px solid #ffd2d8;
    border-radius: 10px;
    background: #fff5f6;
    color: #c51d30;
    font-size: 12px;
    line-height: 1.35;
    font-weight: 750;
}

.promo-type-label {
    display: inline-flex;
    align-items: center;
    margin-right: 6px;
    padding: 3px 7px;
    border-radius: 999px;
    background: #fff0f2;
    color: #d70018;
    font-size: 10px;
    line-height: 1;
    font-weight: 850;
}

.promo-box.promo-clearance {
    border-color: #f39c12;
    background: #fffaf0;
}

.promo-box.promo-clearance .promo-name {
    color: #c87500;
}

@media (max-width: 767px) {
    .promo-auto-selected-note {
        width: 100%;
        font-size: 11px;
    }
}

</style>

<div class="container mt-4 mb-5">
    <div class="row g-4">

        <!-- HÌNH ẢNH -->
        <div class="col-md-5">
            <div
                class="shopee-card product-gallery"
                id="productGallery"
            >

                <div class="main-image-box">

                    <button
                        type="button"
                        id="galleryPrevButton"
                        class="gallery-arrow gallery-prev"
                        onclick="previousImage()"
                        style="display:none"
                    >
                        ‹
                    </button>

                    <img
                        id="mainProductImage"
                        src="<?= $mainImage !== ''
                            ? '../../uploads/' .
                                htmlspecialchars($mainImage)
                            : '' ?>"
                        class="product-image"
                        alt="<?= htmlspecialchars(
                            $sp['ten_san_pham']
                        ) ?>"
                        <?= $mainImage === ''
                            ? 'style="display:none"'
                            : '' ?>
                    >

                    <div
                        id="noProductImage"
                        class="no-image"
                        <?= $mainImage !== ''
                            ? 'style="display:none"'
                            : '' ?>
                    >
                        Không có hình ảnh
                    </div>

                    <button
                        type="button"
                        id="galleryNextButton"
                        class="gallery-arrow gallery-next"
                        onclick="nextImage()"
                        style="display:none"
                    >
                        ›
                    </button>

                </div>

                <div
                    id="thumbnailWrapper"
                    class="thumbnail-wrapper mt-3"
                    style="display:none"
                >

                    <button
                        type="button"
                        class="thumbnail-scroll-btn"
                        onclick="scrollThumbnails(-1)"
                    >
                        ‹
                    </button>

                    <div
                        class="thumbnail-list"
                        id="thumbnailList"
                    ></div>

                    <button
                        type="button"
                        class="thumbnail-scroll-btn"
                        onclick="scrollThumbnails(1)"
                    >
                        ›
                    </button>

                </div>

                <div
                    id="imageCounterBox"
                    class="image-counter text-center mt-2"
                    style="display:none"
                >
                    <span id="imageCounter">1</span>
                    /
                    <span id="imageCounterTotal">1</span>
                </div>

            </div>
        </div>

        <!-- THÔNG TIN -->
        <div class="col-md-7">
            <div class="shopee-card">

                <h3 class="fw-bold mb-1">
                    <?= htmlspecialchars($sp['ten_san_pham']) ?>
                </h3>
<div class="mb-3">
                    <span class="text-warning">
                        ★ <?= number_format(
                            $avgSao,
                            1,
                            ',',
                            ''
                        ) ?>
                    </span>

                    <span>
                        (<?= $tongDanhGia ?> đánh giá)
                    </span>

                    <span class="text-muted">
                        | Đã bán <?= $daBan ?>
                    </span>
                </div>

                <?php if ($moTaNgan !== ''): ?>
                    <div class="short-description mb-3">
                        <?= nl2br(htmlspecialchars($moTaNgan)) ?>
                    </div>
                <?php endif; ?>

                <!-- GIÁ -->
                <div class="mb-4">
                    <?php if ($coBienThe): ?>
                        <div class="price-label">
                            <?= $productOutOfStock
                                ? 'Giá niêm yết từ'
                                : 'Giá từ' ?>
                        </div>

                        <div id="priceBox" class="price-big">
                            <?= number_format($initialSalePrice, 0, ',', '.') ?> đ
                        </div>
                    <?php else: ?>
                        <?php
                        $giaGocSanPham = (float)$sp['gia'];
                        $giaBanSanPham =
                            $productOutOfStock
                                ? $giaGocSanPham
                                : tinhGiaSauKhuyenMai(
                                    $giaGocSanPham,
                                    $productPromotion
                                );
                        ?>

                        <div id="priceBox" class="price-big">
                            <?php if ($giaBanSanPham < $giaGocSanPham): ?>
                                <span class="old-price">
                                    <?= number_format($giaGocSanPham, 0, ',', '.') ?> đ
                                </span>

                                <span>
                                    <?= number_format($giaBanSanPham, 0, ',', '.') ?> đ
                                </span>
                            <?php else: ?>
                                <?= number_format($giaGocSanPham, 0, ',', '.') ?> đ
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div
                        id="promotionBox"
                        class="promo-box <?= (
                            !$productOutOfStock &&
                            !$coBienThe &&
                            $productPromotion &&
                            ($productPromotion['loai_khuyen_mai'] ?? '') === 'xa_kho'
                        ) ? 'promo-clearance' : '' ?>"
                        <?= (
                            !$productOutOfStock &&
                            !$coBienThe &&
                            $productPromotion
                        ) ? 'style="display:block"' : '' ?>
                    >
                        <?php if (
                            !$productOutOfStock &&
                            !$coBienThe &&
                            $productPromotion
                        ): ?>
                            <div class="promo-name">
                                <span class="promo-type-label">
                                    <?php
                                    $promotionTypeLabels = [
                                        'flash_sale' => 'Flash Sale',
                                        'xa_kho' => 'Xả kho',
                                        'mua_nhieu' => 'Mua nhiều ưu đãi',
                                        'tri_an' => 'Tri ân khách hàng',
                                        'hoc_sinh_sinh_vien' => 'Học sinh - Sinh viên',
                                        'hssv' => 'Học sinh - Sinh viên',
                                        'ngay_doi' => 'Ngày đôi',
                                        'ngay_le' => 'Ngày lễ',
                                        'le_tet' => 'Lễ / Tết',
                                        'giua_thang' => 'Giữa tháng',
                                        'cuoi_thang' => 'Cuối tháng',
                                        'dau_tuan' => 'Đầu tuần',
                                        'cuoi_tuan' => 'Cuối tuần',
                                        'thanh_vien' => 'Thành viên',
                                        'online' => 'Ưu đãi Online',
                                        'uu_dai_online' => 'Ưu đãi Online',
                                        'thanh_toan' => 'Ưu đãi thanh toán',
                                        'thu_cu_doi_moi' => 'Thu cũ đổi mới',
                                        'ra_mat' => 'Ưu đãi ra mắt',
                                        'giam_truc_tiep' => 'Giảm trực tiếp',
                                        'khac' => 'Khuyến mãi'
                                    ];

                                    $productPromotionType =
                                        (string)($productPromotion['loai_khuyen_mai'] ?? 'khac');

                                    echo htmlspecialchars(
                                        $promotionTypeLabels[$productPromotionType]
                                        ?? 'Khuyến mãi'
                                    );
                                    ?>
                                </span>

                                <?= htmlspecialchars(
                                    $productPromotion['ten_khuyen_mai']
                                    ?: 'Khuyến mãi'
                                ) ?>
                            </div>

                            <div class="small mt-1">
                                <?= htmlspecialchars($productPromotion['ly_do']) ?>
                            </div>

                            <div class="small text-muted mt-1">
                                <?php if (
                                    ($productPromotion['loai_khuyen_mai'] ?? '') === 'flash_sale' &&
                                    !empty($productPromotion['gio_bat_dau']) &&
                                    !empty($productPromotion['gio_ket_thuc'])
                                ): ?>
                                    Mỗi ngày
                                    <?= htmlspecialchars(substr((string)$productPromotion['gio_bat_dau'], 0, 5)) ?>
                                    →
                                    <?= htmlspecialchars(substr((string)$productPromotion['gio_ket_thuc'], 0, 5)) ?>
                                    · đến ngày
                                    <?= date(
                                        'd/m/Y',
                                        strtotime($productPromotion['ngay_ket_thuc'])
                                    ) ?>
                                <?php else: ?>
                                    Áp dụng đến
                                    <?= date(
                                        'd/m/Y H:i',
                                        strtotime($productPromotion['ngay_ket_thuc'])
                                    ) ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($productOutOfStock): ?>
                        <div class="product-out-of-stock-box">
                            <div class="product-out-of-stock-title">
                                <span class="badge bg-warning text-dark">
                                    TẠM HẾT HÀNG
                                </span>
                                Sản phẩm hiện chưa còn hàng
                            </div>

                            <div class="product-out-of-stock-note">
                                Sắp về hàng · Vui lòng quay lại sau
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- BIẾN THỂ -->
                <?php if ($coBienThe): ?>

                    <?php if ($requestedVariantId > 0): ?>
                        <div class="promo-auto-selected-note">
                            🏷 Đã tự chọn phiên bản đang có ưu đãi
                        </div>
                    <?php endif; ?>

                    <div class="product-variant-panel">

                        <div>
                            <div class="variant-section-title">
                                Phiên bản
                            </div>

                            <div
                                id="versionOptions"
                                class="version-options"
                            ></div>
                        </div>

                        <div class="mt-4">
                            <div class="variant-section-title">
                                Màu sắc
                            </div>

                            <div
                                id="colorVariantGrid"
                                class="color-variant-grid"
                            ></div>

                            <div
                                id="colorVariantEmpty"
                                class="variant-empty"
                                style="display:none"
                            >
                                Không có màu phù hợp.
                            </div>
                        </div>

                    </div>
                <?php elseif ($coMau): ?>
                    <div class="mt-4">
                        <div class="variant-section-title">
                            Màu sắc
                        </div>

                        <div class="d-flex gap-2 flex-wrap">
                            <?php foreach ($colorsArray as $mau): ?>
                                <button
                                    type="button"
                                    class="btn btn-outline-secondary color-btn"
                                    onclick="selectColorOnly(this, <?= (int)$mau['id'] ?>)"
                                >
                                    <span style="color:<?= htmlspecialchars($mau['ma_mau']) ?>">
                                        ●
                                    </span>

                                    <?= htmlspecialchars($mau['ten_mau']) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <input type="hidden" id="mau_id" value="">
                <input type="hidden" id="bien_the_id" value="">

                <!-- SỐ LƯỢNG -->
                <div class="d-flex align-items-center gap-2 mt-4">
                    <button
                        id="btnMinus"
                        type="button"
                        class="btn btn-outline-secondary qty-btn"
                        onclick="giam()"
                        <?= ($coBienThe || $tongTonKho <= 0) ? 'disabled' : '' ?>
                    >
                        −
                    </button>

                    <input
                        type="number"
                        id="qty"
                        class="form-control text-center qty-input"
                        value="1"
                        min="1"
                        max="<?= $coBienThe ? 1 : max(1, $tongTonKho) ?>"
                        <?= ($coBienThe || $tongTonKho <= 0) ? 'disabled' : '' ?>
                    >

                    <button
                        id="btnPlus"
                        type="button"
                        class="btn btn-outline-secondary qty-btn"
                        onclick="tang()"
                        <?= ($coBienThe || $tongTonKho <= 0) ? 'disabled' : '' ?>
                    >
                        +
                    </button>

                    <span id="stockInfo" class="stock-text ms-2">
                        <?php if ($tongTonKho <= 0): ?>
                            <span class="badge bg-danger">
                                TẠM HẾT HÀNG
                            </span>
                        <?php elseif ($coBienThe): ?>
                            <span class="text-muted">
                                Vui lòng chọn màu và phiên bản
                            </span>
                        <?php elseif ($coMau): ?>
                            <span class="text-muted">
                                Vui lòng chọn màu sắc
                            </span>
                        <?php elseif ($tongTonKho <= 2): ?>
                            <span class="badge bg-warning text-dark">
                                ⚠️ Sắp hết hàng, còn <?= $tongTonKho ?> sản phẩm
                            </span>
                        <?php else: ?>
                            <span class="text-muted">
                                Còn <?= $tongTonKho ?> sản phẩm
                            </span>
                        <?php endif; ?>
                    </span>
                </div>

                <!-- NÚT MUA -->
                <div class="mt-4 d-flex gap-2">
                    <button
                        id="btnAddCart"
                        type="button"
                        class="btn btn-outline-danger"
                        onclick="addToCart()"
                        <?= ($coBienThe || $coMau || $tongTonKho <= 0) ? 'disabled' : '' ?>
                    >
                        🛒 Thêm vào giỏ
                    </button>

                    <button
                        id="btnBuyNow"
                        type="button"
                        class="btn btn-danger"
                        onclick="muaNgay()"
                        <?= ($coBienThe || $coMau || $tongTonKho <= 0) ? 'disabled' : '' ?>
                    >
                        Mua ngay
                    </button>
                </div>

                <div id="alertBox" class="mt-3"></div>
            </div>
        </div>
    </div>

    <!-- =====================================================
         MÔ TẢ + THÔNG SỐ KỸ THUẬT
    ====================================================== -->
    <div class="shopee-card mt-4">

        <div class="product-content-tabs">

            <button
                type="button"
                class="product-tab-btn active"
                data-product-tab="description"
                onclick="showProductTab('description')"
            >
                Mô tả
            </button>

            <button
                type="button"
                class="product-tab-btn"
                data-product-tab="specifications"
                onclick="showProductTab('specifications')"
            >
                Thông số kỹ thuật
            </button>

        </div>

        <!-- MÔ TẢ -->
        <div
            id="productTabDescription"
            class="product-tab-pane active"
        >

            <?php if ($moTaDai !== ''): ?>

                <div
                    id="descriptionContent"
                    class="description-content collapsed"
                >
                    <?= nl2br(htmlspecialchars($moTaDai)) ?>
                </div>

                <div
                    id="showMoreBtn"
                    class="show-more mt-3"
                    onclick="toggleDescription()"
                >
                    Xem thêm
                </div>

            <?php else: ?>

                <div class="empty-content-box">
                    Sản phẩm chưa có mô tả chi tiết.
                </div>

            <?php endif; ?>

        </div>

        <!-- THÔNG SỐ -->
        <div
            id="productTabSpecifications"
            class="product-tab-pane"
        >

            <?php if (!empty($thongSoGroups)): ?>

                <?php
                $specGroupIndex = 0;
                ?>

                <?php foreach ($thongSoGroups as $group): ?>

                    <?php
                    $specGroupIndex++;

                    $groupDomId =
                        'specGroup' .
                        $specGroupIndex;

                    $openClass =
                        $specGroupIndex === 1
                            ? 'open'
                            : '';
                    ?>

                    <div
                        id="<?= $groupDomId ?>"
                        class="spec-group <?= $openClass ?>"
                    >

                        <button
                            type="button"
                            class="spec-group-header"
                            onclick="toggleSpecGroup('<?= $groupDomId ?>')"
                        >
                            <span>
                                <?= htmlspecialchars($group['ten']) ?>
                            </span>

                            <span class="spec-arrow">
                               ⌄
                            </span>
                        </button>

                        <div class="spec-group-body">

                            <?php foreach ($group['items'] as $spec): ?>

                                <div class="spec-row">

                                    <div class="spec-name">
                                        <?= htmlspecialchars($spec['ten']) ?>:
                                    </div>

                                    <div class="spec-value">
                                        <?= nl2br(
                                            htmlspecialchars(
                                                $spec['gia_tri']
                                            )
                                        ) ?>
                                    </div>

                                </div>

                            <?php endforeach; ?>

                        </div>
                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <div class="empty-content-box">
                    Sản phẩm chưa có thông số kỹ thuật.
                </div>

            <?php endif; ?>

        </div>

    </div>

    <!-- =====================================================
         ĐÁNH GIÁ / BÌNH LUẬN - MODULE RIÊNG
    ====================================================== -->
    <?php require __DIR__ . '/../danh_gia/index.php'; ?>

    <!-- =====================================================
         SẢN PHẨM LIÊN QUAN
    ====================================================== -->
    <?php if (!empty($relatedProducts)): ?>

        <section class="shopee-card related-section mt-4">

            <div class="related-header">

                <div>
                    <h2 class="related-title">
                        Sản phẩm liên quan
                    </h2>

                    <div class="related-subtitle">
                        Các sản phẩm cùng danh mục có mức giá gần với sản phẩm bạn đang xem.
                    </div>
                </div>

                <?php if (count($relatedProducts) > 4): ?>
                    <div class="related-nav">

                        <button
                            type="button"
                            class="related-nav-btn"
                            onclick="scrollRelatedProducts(-1)"
                            aria-label="Xem sản phẩm trước"
                        >
                            ‹
                        </button>

                        <button
                            type="button"
                            class="related-nav-btn"
                            onclick="scrollRelatedProducts(1)"
                            aria-label="Xem sản phẩm tiếp theo"
                        >
                            ›
                        </button>

                    </div>
                <?php endif; ?>

            </div>

            <div
                class="related-track"
                id="relatedProductsTrack"
            >

                <?php foreach ($relatedProducts as $related): ?>

                    <?php
                    $relatedBasePrice =
                        (float)$related['gia_hien_thi'];

                    $relatedPromotion = null;

                    if (
                        !empty($related['km_kieu_giam']) &&
                        $related['km_gia_tri'] !== null
                    ) {
                        $relatedPromotion = [
                            'kieu_giam' =>
                                $related['km_kieu_giam'],

                            'gia_tri' =>
                                (float)$related['km_gia_tri']
                        ];
                    }

                    $relatedSalePrice =
                        tinhGiaSauKhuyenMai(
                            $relatedBasePrice,
                            $relatedPromotion
                        );

                    $relatedLegacySale =
                        (float)($related['gia_khuyen_mai'] ?? 0);

                    if (
                        (int)$related['tong_ton'] > 0 &&
                        $relatedPromotion === null &&
                        $relatedLegacySale > 0 &&
                        $relatedLegacySale < $relatedBasePrice
                    ) {
                        $relatedSalePrice =
                            $relatedLegacySale;
                    }

                    if ((int)$related['tong_ton'] <= 0) {
                        $relatedPromotion = null;
                        $relatedSalePrice =
                            $relatedBasePrice;
                    }

                    $relatedDiscountPercent = 0;

                    if (
                        $relatedBasePrice > 0 &&
                        $relatedSalePrice < $relatedBasePrice
                    ) {
                        $relatedDiscountPercent =
                            (int)round(
                                (
                                    $relatedBasePrice -
                                    $relatedSalePrice
                                ) /
                                $relatedBasePrice *
                                100
                            );
                    }

                    $relatedRating =
                        round(
                            (float)$related['avg_sao'],
                            1
                        );

                    $relatedReviewCount =
                        (int)$related['tong_danh_gia'];

                    $relatedSold =
                        (int)$related['da_ban'];

                    $relatedStock =
                        (int)$related['tong_ton'];
                    ?>

                    <article class="related-card">

                        <a
                            href="chi_tiet.php?id=<?= (int)$related['id'] ?>"
                            class="related-card-link"
                        >

                            <div class="related-image-box">

                                <?php if (
                                    $relatedStock > 0 &&
                                    $relatedDiscountPercent > 0
                                ): ?>
                                    <span class="related-discount">
                                        -<?= $relatedDiscountPercent ?>%
                                    </span>
                                <?php endif; ?>

                                <?php if (!empty($related['hinh_anh'])): ?>

                                    <img
                                        src="../../uploads/<?= htmlspecialchars(
                                            $related['hinh_anh']
                                        ) ?>"
                                        class="related-image"
                                        alt="<?= htmlspecialchars(
                                            $related['ten_san_pham']
                                        ) ?>"
                                        loading="lazy"
                                    >

                                <?php else: ?>

                                    <div class="related-no-image">
                                        Không có hình ảnh
                                    </div>

                                <?php endif; ?>

                                <?php if ($relatedStock <= 0): ?>
                                    <span class="related-stock-badge">
                                        Tạm hết hàng
                                    </span>
                                <?php endif; ?>

                            </div>

                            <div class="related-card-body">

                                <div class="related-name">
                                    <?= htmlspecialchars(
                                        $related['ten_san_pham']
                                    ) ?>
                                </div>

                                <div class="related-price-row">

                                    <div class="related-price">
                                        <?= number_format(
                                            $relatedSalePrice,
                                            0,
                                            ',',
                                            '.'
                                        ) ?> đ
                                    </div>

                                    <?php if (
                                        $relatedSalePrice <
                                        $relatedBasePrice
                                    ): ?>
                                        <div class="related-old-price">
                                            <?= number_format(
                                                $relatedBasePrice,
                                                0,
                                                ',',
                                                '.'
                                            ) ?> đ
                                        </div>
                                    <?php endif; ?>

                                </div>

                                <div class="related-meta">

                                    <?php if ($relatedReviewCount > 0): ?>

                                        <span class="related-rating">
                                            ★
                                            <?= number_format(
                                                $relatedRating,
                                                1,
                                                ',',
                                                ''
                                            ) ?>
                                        </span>

                                        <span>
                                            (<?= $relatedReviewCount ?>)
                                        </span>

                                        <span class="related-dot"></span>

                                    <?php endif; ?>

                                    <span>
                                        Đã bán
                                        <?= number_format(
                                            $relatedSold
                                        ) ?>
                                    </span>

                                </div>

                            </div>

                        </a>

                    </article>

                <?php endforeach; ?>

            </div>

        </section>

    <?php endif; ?>

</div>

<script>
function scrollRelatedProducts(direction) {
    const track =
        document.getElementById(
            "relatedProductsTrack"
        );

    if (!track) {
        return;
    }

    track.scrollBy({
        left:
            direction *
            Math.max(
                240,
                track.clientWidth * 0.82
            ),
        behavior: "smooth"
    });
}

const productGalleryImages = <?= json_encode(
    $galleryUrls,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>;

const allGalleryImages = <?= json_encode(
    $allGalleryUrls,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>;

let galleryImages = [
    ...allGalleryImages
];

const variants = <?= json_encode(
    $variantsForJs,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>;

const productColorMap = <?= json_encode(
    array_reduce(
        $colorsArray,
        function ($carry, $item) {
            $carry[(int)$item['id']] = [
                'ten_mau' => $item['ten_mau'],
                'ma_mau' => $item['ma_mau']
            ];
            return $carry;
        },
        []
    ),
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
) ?>;

const hasVariant = <?= $coBienThe ? 'true' : 'false' ?>;
const hasColor = <?= $coMau ? 'true' : 'false' ?>;
const productId = <?= (int)$sp['id'] ?>;
const requestedVariantId = <?= (int)$requestedVariantId ?>;

/*
    Trạng thái đăng nhập chỉ dùng cho hành động mua hàng.
    Khách vẫn được xem toàn bộ trang chi tiết.
*/
const isLoggedIn = <?= !empty($_SESSION['user']['id']) ? 'true' : 'false' ?>;

const loginUrl = <?= json_encode(
    '/iPhoneStore/auth/login.php?return_url=' .
    rawurlencode(
        '/iPhoneStore/user/san_pham/chi_tiet.php?id=' .
        (int)$sp['id'] .
        (
            $requestedVariantId > 0
                ? '&bien_the_id=' . (int)$requestedVariantId
                : ''
        )
    ),
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
) ?>;
const productStock = <?= (int)$tongTonKho ?>;
const initialPriceText = <?= json_encode(
    number_format($initialSalePrice, 0, ',', '.') . ' đ',
    JSON_UNESCAPED_UNICODE
) ?>;

const qtyInput = document.getElementById("qty");
const btnAdd = document.getElementById("btnAddCart");
const btnBuy = document.getElementById("btnBuyNow");
const btnPlus = document.getElementById("btnPlus");
const btnMinus = document.getElementById("btnMinus");
const stockInfo = document.getElementById("stockInfo");
const priceBox = document.getElementById("priceBox");
const promotionBox = document.getElementById("promotionBox");

let currentStock = hasVariant ? 0 : productStock;
let selectedVariant = null;

function formatMoney(number) {
    return Number(number).toLocaleString("vi-VN") + " đ";
}

function escapeHtml(value) {
    return String(value ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}

function formatDateTime(value) {
    if (!value) {
        return "";
    }

    const date = new Date(value.replace(" ", "T"));

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString("vi-VN");
}

function formatTimeOnly(value) {
    if (!value) {
        return "";
    }

    return String(value).slice(0, 5);
}

function formatDateOnly(value) {
    if (!value) {
        return "";
    }

    const date = new Date(value.replace(" ", "T"));

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleDateString("vi-VN");
}

function getPromotionTypeLabel(type) {
    const labels = {
        flash_sale: "Flash Sale",
        xa_kho: "Xả kho",
        mua_nhieu: "Mua nhiều ưu đãi",
        tri_an: "Tri ân khách hàng",
        hoc_sinh_sinh_vien: "Học sinh - Sinh viên",
        hssv: "Học sinh - Sinh viên",
        ngay_doi: "Ngày đôi",
        ngay_le: "Ngày lễ",
        le_tet: "Lễ / Tết",
        giua_thang: "Giữa tháng",
        cuoi_thang: "Cuối tháng",
        dau_tuan: "Đầu tuần",
        cuoi_tuan: "Cuối tuần",
        thanh_vien: "Thành viên",
        online: "Ưu đãi Online",
        uu_dai_online: "Ưu đãi Online",
        thanh_toan: "Ưu đãi thanh toán",
        thu_cu_doi_moi: "Thu cũ đổi mới",
        ra_mat: "Ưu đãi ra mắt",
        giam_truc_tiep: "Giảm trực tiếp",
        khac: "Khuyến mãi"
    };

    return labels[String(type || "")] || "Khuyến mãi";
}

function getDiscountPercent(originalPrice, salePrice) {
    originalPrice = Number(originalPrice);
    salePrice = Number(salePrice);

    if (originalPrice <= 0 || salePrice >= originalPrice) {
        return 0;
    }

    return Math.round(
        ((originalPrice - salePrice) / originalPrice) * 100
    );
}

function showAlert(message, type = "success") {
    document.getElementById("alertBox").innerHTML = `
        <div class="alert alert-${type}">
            ${escapeHtml(message)}
        </div>
    `;
}

function renderPromotion(promotion) {
    if (
        !promotion ||
        currentStock <= 0
    ) {
        promotionBox.style.display = "none";
        promotionBox.innerHTML = "";
        promotionBox.classList.remove("promo-clearance");
        return;
    }

    const source = promotion.la_km_rieng
        ? "Khuyến mãi riêng cho phiên bản này"
        : "Khuyến mãi chung của sản phẩm";

    const promotionType =
        String(promotion.loai || "");

    const typeLabel =
        getPromotionTypeLabel(
            promotionType
        );

    const isFlash =
        promotionType === "flash_sale" &&
        promotion.gio_bat_dau &&
        promotion.gio_ket_thuc;

    const isClearance =
        promotionType === "xa_kho";

    const timeText = isFlash
        ? `Mỗi ngày ${formatTimeOnly(promotion.gio_bat_dau)} → ${formatTimeOnly(promotion.gio_ket_thuc)} · đến ngày ${formatDateOnly(promotion.ngay_ket_thuc)}`
        : `Áp dụng đến ${formatDateTime(promotion.ngay_ket_thuc)}`;

    promotionBox.classList.toggle(
        "promo-clearance",
        isClearance
    );

    promotionBox.innerHTML = `
        <div class="promo-name">
            <span class="promo-type-label">
                ${escapeHtml(typeLabel)}
            </span>
            ${escapeHtml(promotion.ten || typeLabel)}
        </div>

        ${
            promotion.ly_do
                ? `<div class="small mt-1">${escapeHtml(promotion.ly_do)}</div>`
                : ""
        }

        <div class="small text-muted mt-1">
            ${source} · ${escapeHtml(timeText)}
        </div>
    `;

    promotionBox.style.display = "block";
}

function renderPrice(variant) {
    const originalPrice = Number(variant.gia_goc);

    const variantStock =
        Number(variant.so_luong) || 0;

    const salePrice =
        variantStock <= 0
            ? originalPrice
            : Number(variant.gia_ban);

    if (salePrice < originalPrice) {
        const percent = getDiscountPercent(originalPrice, salePrice);

        priceBox.innerHTML = `
            <span class="old-price">
                ${formatMoney(originalPrice)}
            </span>

            <span>
                ${formatMoney(salePrice)}
            </span>

            ${percent > 0
                ? `<span class="discount-badge">-${percent}%</span>`
                : ""
            }
        `;
    } else {
        priceBox.innerHTML = formatMoney(originalPrice);
    }

    renderPromotion(variant.khuyen_mai);
}

function enablePurchase() {
    qtyInput.disabled = false;
    btnPlus.disabled = false;
    btnMinus.disabled = false;
    btnAdd.disabled = false;
    btnBuy.disabled = false;
}

function disablePurchase() {
    qtyInput.disabled = true;
    btnPlus.disabled = true;
    btnMinus.disabled = true;
    btnAdd.disabled = true;
    btnBuy.disabled = true;
}

function resetVariantSelection() {
    selectedVariant = null;
    currentStock = 0;

    document.getElementById("bien_the_id").value = "";

    qtyInput.value = 1;
    qtyInput.max = 1;

    document
        .querySelectorAll(".color-variant-card")
        .forEach(function(button) {
            button.classList.remove("active");
        });

    document.getElementById("mau_id").value = "";

    priceBox.innerHTML = initialPriceText;
    promotionBox.style.display = "none";
    promotionBox.innerHTML = "";

    setGalleryImages(
        allGalleryImages
    );

    disablePurchase();
}

function normalizeVersionLabel(variant) {
    const rom =
        String(variant.rom || "")
            .trim()
            .toUpperCase()
            .replace(/\s+/g, "");

    if (rom !== "") {
        return rom;
    }

    return "Phiên bản";
}

function compareStorageLabel(a, b) {
    const parseStorage =
        function (value) {
            const match =
                String(value)
                    .toUpperCase()
                    .match(/^(\d+(?:\.\d+)?)(GB|TB)$/);

            if (!match) {
                return {
                    value: Number.MAX_SAFE_INTEGER,
                    label: value
                };
            }

            let amount =
                Number(match[1]);

            if (match[2] === "TB") {
                amount *= 1024;
            }

            return {
                value: amount,
                label: value
            };
        };

    const aa = parseStorage(a);
    const bb = parseStorage(b);

    if (aa.value !== bb.value) {
        return aa.value - bb.value;
    }

    return aa.label.localeCompare(bb.label);
}

function getVersionList() {
    return Array.from(
        new Set(
            variants.map(
                function (variant) {
                    return normalizeVersionLabel(
                        variant
                    );
                }
            )
        )
    ).sort(compareStorageLabel);
}

function getVariantForVersionAndColor(
    versionLabel,
    colorId
) {
    return variants.find(
        function (variant) {
            return (
                normalizeVersionLabel(
                    variant
                ) === versionLabel &&
                Number(variant.mau_id) ===
                    Number(colorId)
            );
        }
    ) || null;
}

function getSelectedVersionLabel() {
    const active =
        document.querySelector(
            ".version-option.active"
        );

    return active
        ? String(
            active.dataset.version || ""
        )
        : "";
}

function getAllProductColors() {
    const seen =
        new Set();

    const colors = [];

    variants.forEach(function (variant) {
        const colorId =
            Number(variant.mau_id);

        if (
            !colorId ||
            seen.has(colorId)
        ) {
            return;
        }

        seen.add(colorId);

        colors.push({
            id: colorId,
            info:
                productColorMap[colorId]
                || {}
        });
    });

    return colors;
}

function renderVersionOptions() {
    const container =
        document.getElementById(
            "versionOptions"
        );

    if (!container) {
        return;
    }

    container.innerHTML = "";

    const versionList =
        getVersionList();

    versionList.forEach(
        function (versionLabel) {
            const versionVariants =
                variants.filter(
                    function (variant) {
                        return (
                            normalizeVersionLabel(
                                variant
                            ) === versionLabel
                        );
                    }
                );

            const button =
                document.createElement(
                    "button"
                );

            button.type = "button";
            button.className =
                "version-option";

            button.dataset.version =
                versionLabel;

            button.textContent =
                versionLabel;

            const hasAnyStock =
                versionVariants.some(
                    function (variant) {
                        return (
                            Number(
                                variant.so_luong
                            ) > 0
                        );
                    }
                );

            if (!hasAnyStock) {
                button.disabled = true;
            }

            button.addEventListener(
                "click",
                function () {
                    selectVersion(
                        button,
                        versionLabel
                    );
                }
            );

            container.appendChild(
                button
            );
        }
    );
}

function selectVersion(
    button,
    versionLabel
) {
    document
        .querySelectorAll(
            ".version-option"
        )
        .forEach(function (item) {
            item.classList.remove(
                "active"
            );
        });

    button.classList.add(
        "active"
    );

    selectedVariant = null;
    currentStock = 0;

    document.getElementById(
        "bien_the_id"
    ).value = "";

    document.getElementById(
        "mau_id"
    ).value = "";

    qtyInput.value = 1;
    qtyInput.max = 1;

    priceBox.innerHTML =
        initialPriceText;

    promotionBox.style.display =
        "none";

    promotionBox.innerHTML = "";

    setGalleryImages(
        allGalleryImages
    );

    disablePurchase();

    renderAllColors(
        versionLabel
    );
}

function renderAllColors(
    selectedVersionLabel = ""
) {
    const grid =
        document.getElementById(
            "colorVariantGrid"
        );

    const empty =
        document.getElementById(
            "colorVariantEmpty"
        );

    if (!grid || !empty) {
        return;
    }

    const allColors =
        getAllProductColors();

    grid.innerHTML = "";

    if (allColors.length === 0) {
        grid.style.display = "none";
        empty.style.display = "block";
        return;
    }

    grid.style.display = "grid";
    empty.style.display = "none";

    allColors.forEach(
        function (colorRow) {
            const colorId =
                Number(colorRow.id);

            const variantsOfColor =
                variants.filter(
                    function (variant) {
                        return (
                            Number(
                                variant.mau_id
                            ) === colorId
                        );
                    }
                );

            const exactVariant =
                selectedVersionLabel !== ""
                    ? getVariantForVersionAndColor(
                        selectedVersionLabel,
                        colorId
                    )
                    : null;

            const displayVariant =
                exactVariant ||
                variantsOfColor.find(
                    function (variant) {
                        return (
                            Number(
                                variant.so_luong
                            ) > 0
                        );
                    }
                ) ||
                variantsOfColor[0];

            if (!displayVariant) {
                return;
            }

            const button =
                document.createElement(
                    "button"
                );

            button.type =
                "button";

            button.className =
                "color-variant-card";

            button.dataset.colorId =
                String(colorId);

            const color =
                colorRow.info || {};

            const colorName =
                color.ten_mau ||
                "Màu #" + colorId;

            const colorCode =
                color.ma_mau ||
                "#999999";

            const image =
                Array.isArray(
                    displayVariant.images
                ) &&
                displayVariant.images.length > 0
                    ? displayVariant.images[0]
                    : "";

            const price =
                formatMoney(
                    displayVariant.gia_ban
                );

            const exactStock =
                exactVariant
                    ? Number(
                        exactVariant.so_luong
                    ) || 0
                    : 0;

            const hasExactCombination =
                exactVariant !== null;

            button.innerHTML = `
                ${
                    image
                        ? `<img
                            src="${escapeHtml(image)}"
                            class="color-variant-thumb"
                            alt=""
                        >`
                        : `<div
                            class="color-variant-thumb-placeholder"
                            style="color:${escapeHtml(colorCode)}"
                        >●</div>`
                }

                <div class="color-variant-info">
                    <div class="color-variant-name">
                        ${escapeHtml(colorName)}
                    </div>

                    <div class="color-variant-price">
                        ${escapeHtml(price)}
                    </div>

                    <div class="color-variant-stock">
                        ${
                            hasExactCombination
                                ? (
                                    exactStock > 0
                                        ? `Còn ${exactStock} sản phẩm`
                                        : "Hết hàng"
                                )
                                : "Có phiên bản khác"
                        }
                    </div>
                </div>
            `;

            button.addEventListener(
                "click",
                function () {
                    let targetVariant =
                        exactVariant;

                    if (!targetVariant) {
                        targetVariant =
                            variantsOfColor.find(
                                function (variant) {
                                    return (
                                        Number(
                                            variant.so_luong
                                        ) > 0
                                    );
                                }
                            ) ||
                            variantsOfColor[0];
                    }

                    if (!targetVariant) {
                        return;
                    }

                    const targetVersion =
                        normalizeVersionLabel(
                            targetVariant
                        );

                    if (
                        targetVersion !==
                        getSelectedVersionLabel()
                    ) {
                        const targetVersionButton =
                            Array.from(
                                document.querySelectorAll(
                                    ".version-option"
                                )
                            ).find(
                                function (item) {
                                    return (
                                        String(
                                            item.dataset.version
                                        ) ===
                                        targetVersion
                                    );
                                }
                            );

                        if (
                            targetVersionButton &&
                            !targetVersionButton.disabled
                        ) {
                            document
                                .querySelectorAll(
                                    ".version-option"
                                )
                                .forEach(
                                    function (item) {
                                        item.classList.remove(
                                            "active"
                                        );
                                    }
                                );

                            targetVersionButton
                                .classList.add(
                                    "active"
                                );
                        }
                    }

                    const resolvedVersionLabel =
                        normalizeVersionLabel(
                            targetVariant
                        );

                    /*
                        Nếu màu này buộc phải chuyển sang dung lượng khác,
                        render lại danh sách màu TRƯỚC khi chọn biến thể.
                        Làm như vậy để renderAllColors không reset
                        selectedVariant / tồn kho sau khi đã chọn.
                    */
                    if (
                        resolvedVersionLabel !==
                        selectedVersionLabel
                    ) {
                        renderAllColors(
                            resolvedVersionLabel
                        );
                    }

                    const selectedColorButton =
                        Array.from(
                            document.querySelectorAll(
                                ".color-variant-card"
                            )
                        ).find(
                            function (item) {
                                return (
                                    Number(
                                        item.dataset.colorId
                                    ) === colorId
                                );
                            }
                        );

                    selectVariant(
                        selectedColorButton || button,
                        targetVariant
                    );
                }
            );

            grid.appendChild(
                button
            );
        }
    );

    if (!selectedVariant) {
        stockInfo.innerHTML = `
            <span class="text-muted">
                Vui lòng chọn màu sắc
            </span>
        `;

        disablePurchase();
    }
}

function selectColorOnly(
    button,
    colorId
) {
    document
        .querySelectorAll(
            ".color-btn"
        )
        .forEach(function (item) {
            item.classList.remove(
                "active"
            );
        });

    button.classList.add(
        "active"
    );

    document.getElementById(
        "mau_id"
    ).value = colorId;

    currentStock =
        productStock;

    qtyInput.value = 1;
    qtyInput.max =
        Math.max(
            1,
            productStock
        );

    if (productStock <= 0) {
        stockInfo.innerHTML = `
            <span class="badge bg-warning text-dark">
                TẠM HẾT HÀNG
            </span>
            <span class="text-muted ms-1">
                Sắp về hàng · Vui lòng quay lại sau
            </span>
        `;

        disablePurchase();
        return;
    }

    enablePurchase();

    stockInfo.innerHTML = `
        <span class="text-muted">
            Còn ${productStock} sản phẩm
        </span>
    `;
}

function selectVariant(button, variant) {
    document
        .querySelectorAll(".color-variant-card")
        .forEach(function(item) {
            item.classList.remove("active");
        });

    if (button) {
        button.classList.add("active");
    }

    selectedVariant = variant;

    document.getElementById(
        "bien_the_id"
    ).value = variant.id;

    document.getElementById(
        "mau_id"
    ).value = variant.mau_id;

    currentStock = Number(variant.so_luong) || 0;
    qtyInput.value = 1;

    renderPrice(variant);

    /*
        Hết hàng thì bảo đảm khối KM bị ẩn ngay cả khi dữ liệu JS cũ
        còn mang theo khuyen_mai.
    */
    if (currentStock <= 0) {
        renderPromotion(null);
    }

    setGalleryImages(
        allGalleryImages
    );

    if (
        Array.isArray(variant.images) &&
        variant.images.length > 0
    ) {
        const targetImage =
            variant.images[0];

        const targetIndex =
            galleryImages.indexOf(
                targetImage
            );

        if (targetIndex >= 0) {
            updateGallery(
                targetIndex,
                true
            );
        }
    }

    if (currentStock <= 0) {
        qtyInput.max = 1;
        stockInfo.innerHTML = `
            <span class="badge bg-warning text-dark">TẠM HẾT HÀNG</span>
            <span class="text-muted ms-1">Sắp về hàng · Vui lòng quay lại sau</span>
        `;
        disablePurchase();
        return;
    }

    qtyInput.max = currentStock;
    enablePurchase();

    if (currentStock <= 2) {
        stockInfo.innerHTML = `
            <span class="badge bg-warning text-dark">
                ⚠️ Sắp hết hàng, còn ${currentStock} sản phẩm
            </span>
        `;
    } else {
        stockInfo.innerHTML = `
            <span class="text-muted">
                Còn ${currentStock} sản phẩm
            </span>
        `;
    }
}

function tang() {
    if (currentStock <= 0) {
        showAlert("Sản phẩm đã hết hàng", "danger");
        return;
    }

    const qty = parseInt(qtyInput.value) || 1;

    if (qty < currentStock) {
        qtyInput.value = qty + 1;
    } else {
        showAlert(
            "Phiên bản này chỉ còn " + currentStock + " sản phẩm",
            "warning"
        );
    }
}

function giam() {
    const qty = parseInt(qtyInput.value) || 1;

    if (qty > 1) {
        qtyInput.value = qty - 1;
    }
}

qtyInput.addEventListener("input", function() {
    let value = parseInt(this.value);

    if (currentStock <= 0) {
        this.value = 1;
        disablePurchase();
        return;
    }

    if (Number.isNaN(value) || value < 1) {
        this.value = 1;
        return;
    }

    if (value > currentStock) {
        this.value = currentStock;
        showAlert(
            "Phiên bản này chỉ còn " + currentStock + " sản phẩm",
            "warning"
        );
    }
});

function validateSelection() {
    const mauId = document.getElementById("mau_id").value;
    const variantId = document.getElementById("bien_the_id").value;
    const qty = parseInt(qtyInput.value) || 1;

    if (hasVariant && !variantId) {
        showAlert(
            "Vui lòng chọn phiên bản và màu sắc",
            "danger"
        );
        return false;
    }

    if (hasColor && !mauId) {
        showAlert(
            "Vui lòng chọn màu sắc",
            "danger"
        );
        return false;
    }

    if (
        hasVariant &&
        selectedVariant &&
        Number(selectedVariant.mau_id) !== Number(mauId)
    ) {
        showAlert("Màu và phiên bản không khớp", "danger");
        resetVariantSelection();
        return false;
    }

    if (currentStock <= 0) {
        showAlert("Sản phẩm hoặc phiên bản này đã hết hàng", "danger");
        return false;
    }

    if (qty < 1 || qty > currentStock) {
        showAlert(
            "Số lượng không hợp lệ. Chỉ còn " + currentStock + " sản phẩm",
            "danger"
        );
        return false;
    }

    return true;
}

async function refreshNavbarCart() {
    const currentCartArea =
        document.querySelector(
            ".cart-area"
        );

    if (!currentCartArea) {
        return;
    }

    try {
        const response =
            await fetch(
                window.location.href,
                {
                    method: "GET",
                    cache: "no-store",
                    headers: {
                        "X-Requested-With":
                            "XMLHttpRequest"
                    }
                }
            );

        if (!response.ok) {
            return;
        }

        const html =
            await response.text();

        const parser =
            new DOMParser();

        const freshDocument =
            parser.parseFromString(
                html,
                "text/html"
            );

        const freshCartArea =
            freshDocument.querySelector(
                ".cart-area"
            );

        if (!freshCartArea) {
            return;
        }

        currentCartArea.innerHTML =
            freshCartArea.innerHTML;
    } catch (error) {
        console.warn(
            "Không thể làm mới giỏ hàng trên navbar:",
            error
        );
    }
}

function requireLoginForPurchase() {
    if (isLoggedIn) {
        return true;
    }

    window.location.href = loginUrl;
    return false;
}

function sendToCart(redirectAfter = false) {
    if (!requireLoginForPurchase()) {
        return;
    }

    if (!validateSelection()) {
        return;
    }

    const qty = parseInt(qtyInput.value);
    const mauId = document.getElementById("mau_id").value;
    const variantId = document.getElementById("bien_the_id").value;

    fetch("../gio_hang/them.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded"
        },
        body: new URLSearchParams({
            san_pham_id: productId,
            so_luong: qty,
            mau_id: mauId,
            bien_the_id: variantId
        })
    })
    .then(async function(response) {
        const text = await response.text();

        if (!response.ok) {
            throw new Error("HTTP " + response.status + ": " + text);
        }

        try {
            return JSON.parse(text);
        } catch (error) {
            throw new Error("Server không trả JSON hợp lệ: " + text);
        }
    })
    .then(function(data) {
        if (!data.success) {
            showAlert(
                data.error || "Không thể thêm sản phẩm vào giỏ hàng",
                "danger"
            );

            const message = String(data.error || "").toLowerCase();

            if (
                message.includes("hết hàng") ||
                message.includes("chỉ còn") ||
                message.includes("không đủ")
            ) {
                setTimeout(function() {
                    window.location.reload();
                }, 1200);
            }

            return;
        }

        if (redirectAfter) {
            /*
                Mua ngay:
                - vẫn thêm vào giỏ như cũ;
                - chỉ truyền dấu hiệu để giỏ hàng tự check đúng dòng vừa mua.
                - không thay đổi nghiệp vụ thêm giỏ hiện tại.
            */
            const buyNowParams =
                new URLSearchParams({
                    buy_now: "1",
                    san_pham_id: String(productId)
                });

            if (mauId) {
                buyNowParams.set(
                    "mau_id",
                    String(mauId)
                );
            }

            if (variantId) {
                buyNowParams.set(
                    "bien_the_id",
                    String(variantId)
                );
            }

            window.location.href =
                "../gio_hang/index.php?" +
                buyNowParams.toString();
        } else {
            refreshNavbarCart()
                .finally(function () {
                    showAlert(
                        "Đã thêm vào giỏ hàng",
                        "success"
                    );
                });
        }
    })
    .catch(function(error) {
        console.error(error);
        showAlert("Lỗi: " + error.message, "danger");
    });
}

function addToCart() {
    sendToCart(false);
}

function muaNgay() {
    sendToCart(true);
}

/* =========================================================
   TAB MÔ TẢ / THÔNG SỐ
========================================================= */
function showProductTab(tabName) {
    document
        .querySelectorAll(".product-tab-btn")
        .forEach(function(button) {
            button.classList.toggle(
                "active",
                button.dataset.productTab === tabName
            );
        });

    const descriptionPane =
        document.getElementById(
            "productTabDescription"
        );

    const specificationPane =
        document.getElementById(
            "productTabSpecifications"
        );

    if (descriptionPane) {
        descriptionPane.classList.toggle(
            "active",
            tabName === "description"
        );
    }

    if (specificationPane) {
        specificationPane.classList.toggle(
            "active",
            tabName === "specifications"
        );
    }
}

/* =========================================================
   THU GỌN / MỞ NHÓM THÔNG SỐ
========================================================= */
function toggleSpecGroup(groupId) {
    const group =
        document.getElementById(groupId);

    if (!group) {
        return;
    }

    group.classList.toggle("open");
}

function toggleDescription() {
    const content = document.getElementById("descriptionContent");
    const button = document.getElementById("showMoreBtn");

    if (!content || !button) {
        return;
    }

    content.classList.toggle("collapsed");

    button.innerText = content.classList.contains("collapsed")
        ? "Xem thêm"
        : "Thu gọn";
}

/* =========================================================
   GALLERY ĐỘNG
   - Mặc định dùng ảnh chung sản phẩm.
   - Chọn màu: ưu tiên bộ ảnh đầu tiên có ảnh của màu đó.
   - Chọn phiên bản: dùng đúng ảnh của biến thể.
========================================================= */
let currentImageIndex = 0;
let galleryTimer = null;
const galleryPreloadCache = new Map();

function preloadGalleryImages(images) {
    if (!Array.isArray(images)) {
        return;
    }

    images.forEach(function (src) {
        if (
            typeof src !== "string" ||
            src.trim() === "" ||
            galleryPreloadCache.has(src)
        ) {
            return;
        }

        const image = new Image();

        image.decoding = "async";
        image.src = src;

        galleryPreloadCache.set(
            src,
            image
        );
    });
}

preloadGalleryImages(
    allGalleryImages
);

function renderGalleryControls() {
    const thumbnailWrapper =
        document.getElementById(
            "thumbnailWrapper"
        );

    const thumbnailList =
        document.getElementById(
            "thumbnailList"
        );

    const counterBox =
        document.getElementById(
            "imageCounterBox"
        );

    const counterTotal =
        document.getElementById(
            "imageCounterTotal"
        );

    const prevButton =
        document.getElementById(
            "galleryPrevButton"
        );

    const nextButton =
        document.getElementById(
            "galleryNextButton"
        );

    if (thumbnailList) {
        thumbnailList.innerHTML = "";

        galleryImages.forEach(
            function(imageUrl, index) {
                const image =
                    document.createElement(
                        "img"
                    );

                image.src = imageUrl;
                image.className =
                    "product-thumbnail" +
                    (
                        index === currentImageIndex
                            ? " active"
                            : ""
                    );

                image.dataset.index =
                    String(index);

                image.alt =
                    "Ảnh sản phẩm " +
                    (index + 1);

                image.addEventListener(
                    "click",
                    function() {
                        selectProductImage(
                            index
                        );
                    }
                );

                thumbnailList.appendChild(
                    image
                );
            }
        );
    }

    const hasMultiple =
        galleryImages.length > 1;

    if (thumbnailWrapper) {
        thumbnailWrapper.style.display =
            hasMultiple
                ? "flex"
                : "none";
    }

    if (counterBox) {
        counterBox.style.display =
            hasMultiple
                ? "block"
                : "none";
    }

    if (counterTotal) {
        counterTotal.innerText =
            String(
                galleryImages.length
            );
    }

    if (prevButton) {
        prevButton.style.display =
            hasMultiple
                ? ""
                : "none";
    }

    if (nextButton) {
        nextButton.style.display =
            hasMultiple
                ? ""
                : "none";
    }
}

function setGalleryImages(images) {
    const cleanedImages =
        Array.isArray(images)
            ? images.filter(
                function(image) {
                    return (
                        typeof image === "string" &&
                        image.trim() !== ""
                    );
                }
            )
            : [];

    galleryImages =
        cleanedImages.length > 0
            ? [...cleanedImages]
            : [...allGalleryImages];

    currentImageIndex = 0;

    const mainImage =
        document.getElementById(
            "mainProductImage"
        );

    const noImage =
        document.getElementById(
            "noProductImage"
        );

    if (galleryImages.length === 0) {
        if (mainImage) {
            mainImage.style.display =
                "none";
            mainImage.removeAttribute(
                "src"
            );
        }

        if (noImage) {
            noImage.style.display =
                "flex";
        }

        renderGalleryControls();
        clearInterval(galleryTimer);
        return;
    }

    if (mainImage) {
        mainImage.style.display =
            "block";

        mainImage.src =
            galleryImages[0];
    }

    if (noImage) {
        noImage.style.display =
            "none";
    }

    renderGalleryControls();
    restartGalleryTimer();
}

function updateGallery(
    index,
    restartTimer = true
) {
    if (!galleryImages[index]) {
        return;
    }

    currentImageIndex = index;

    const mainImage =
        document.getElementById(
            "mainProductImage"
        );

    if (!mainImage) {
        return;
    }

    const nextSrc =
        galleryImages[index];

    mainImage.classList.add(
        "changing"
    );

    const applyImage = function () {
        mainImage.src =
            nextSrc;

        requestAnimationFrame(
            function () {
                mainImage.classList.remove(
                    "changing"
                );
            }
        );
    };

    const cachedImage =
        galleryPreloadCache.get(
            nextSrc
        );

    if (
        cachedImage &&
        cachedImage.complete
    ) {
        setTimeout(
            applyImage,
            90
        );
    } else {
        const preload =
            cachedImage ||
            new Image();

        if (!cachedImage) {
            preload.decoding =
                "async";

            preload.src =
                nextSrc;

            galleryPreloadCache.set(
                nextSrc,
                preload
            );
        }

        preload.onload =
            function () {
                setTimeout(
                    applyImage,
                    60
                );
            };

        preload.onerror =
            function () {
                applyImage();
            };
    }

    document
        .querySelectorAll(
            ".product-thumbnail"
        )
        .forEach(
            function(item) {
                item.classList.remove(
                    "active"
                );
            }
        );

    const thumbnail =
        document.querySelector(
            `.product-thumbnail[data-index="${index}"]`
        );

    if (thumbnail) {
        thumbnail.classList.add(
            "active"
        );

        const thumbnailList =
            document.getElementById(
                "thumbnailList"
            );

        if (thumbnailList) {
            const targetLeft =
                thumbnail.offsetLeft -
                (
                    thumbnailList.clientWidth -
                    thumbnail.offsetWidth
                ) / 2;

            thumbnailList.scrollTo({
                left: Math.max(
                    0,
                    targetLeft
                ),
                behavior: restartTimer
                    ? "smooth"
                    : "auto"
            });
        }
    }

    const counter =
        document.getElementById(
            "imageCounter"
        );

    if (counter) {
        counter.innerText =
            index + 1;
    }

    if (restartTimer) {
        restartGalleryTimer();
    }
}

function selectProductImage(index) {
    updateGallery(
        index,
        true
    );
}

function nextImage(
    restartTimer = true
) {
    if (galleryImages.length <= 1) {
        return;
    }

    let index =
        currentImageIndex + 1;

    if (
        index >=
        galleryImages.length
    ) {
        index = 0;
    }

    updateGallery(
        index,
        restartTimer
    );
}

function previousImage() {
    if (galleryImages.length <= 1) {
        return;
    }

    let index =
        currentImageIndex - 1;

    if (index < 0) {
        index =
            galleryImages.length - 1;
    }

    updateGallery(
        index,
        true
    );
}

function scrollThumbnails(direction) {
    const list =
        document.getElementById(
            "thumbnailList"
        );

    if (!list) {
        return;
    }

    list.scrollBy({
        left:
            direction * 250,
        behavior: "smooth"
    });
}

function startGalleryTimer() {
    clearInterval(
        galleryTimer
    );

    if (galleryImages.length <= 1) {
        return;
    }

    galleryTimer =
        setInterval(
            function() {
                nextImage(false);
            },
            4000
        );
}

function restartGalleryTimer() {
    clearInterval(
        galleryTimer
    );

    startGalleryTimer();
}

const productGallery =
    document.getElementById(
        "productGallery"
    );

if (productGallery) {
    productGallery.addEventListener(
        "mouseenter",
        function() {
            clearInterval(
                galleryTimer
            );
        }
    );

    productGallery.addEventListener(
        "mouseleave",
        function() {
            startGalleryTimer();
        }
    );
}

setGalleryImages(
    allGalleryImages
);

if (hasVariant) {
    renderVersionOptions();

    /*
        Ưu tiên biến thể được truyền từ card khuyến mãi.
        Ví dụ:
        chi_tiet.php?id=19&bien_the_id=45&from=khuyen_mai

        Khi đó:
        - chọn đúng ROM;
        - chọn đúng màu;
        - cập nhật giá ưu đãi;
        - cập nhật ảnh;
        - cập nhật tồn kho;
        - hiện đúng khối khuyến mãi.
    */
    const requestedVariant =
        requestedVariantId > 0
            ? variants.find(function (variant) {
                return (
                    Number(variant.id) ===
                    Number(requestedVariantId)
                );
            }) || null
            : null;

    if (requestedVariant) {
        const requestedVersion =
            normalizeVersionLabel(
                requestedVariant
            );

        const requestedVersionButton =
            Array.from(
                document.querySelectorAll(
                    ".version-option"
                )
            ).find(function (button) {
                return (
                    String(
                        button.dataset.version || ""
                    ) === requestedVersion
                );
            });

        if (
            requestedVersionButton &&
            !requestedVersionButton.disabled
        ) {
            /*
                Click dung lượng trước để render danh sách màu đúng.
            */
            requestedVersionButton.click();

            const requestedColorButton =
                Array.from(
                    document.querySelectorAll(
                        ".color-variant-card"
                    )
                ).find(function (button) {
                    return (
                        Number(
                            button.dataset.colorId
                        ) ===
                        Number(
                            requestedVariant.mau_id
                        )
                    );
                });

            if (requestedColorButton) {
                selectVariant(
                    requestedColorButton,
                    requestedVariant
                );
            }
        }
    }

    /*
        Không đi từ card khuyến mãi hoặc ID không hợp lệ:
        giữ nguyên hành vi cũ.
    */
    if (!selectedVariant) {
        const firstVersionButton =
            Array.from(
                document.querySelectorAll(
                    ".version-option"
                )
            ).find(function (button) {
                return !button.disabled;
            });

        if (firstVersionButton) {
            firstVersionButton.click();
        } else {
            renderAllColors("");
        }
    }
}

/* Sản phẩm không màu, không biến thể */
if (!hasVariant && !hasColor && productStock > 0) {
    enablePurchase();
}

window.addEventListener("pageshow", function(event) {
    if (event.persisted) {
        window.location.reload();
    }
});
</script>

<?php require_once "../../includes/footer.php"; ?>
