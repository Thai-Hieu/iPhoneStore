<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
    Trang chủ user là PUBLIC:
    - khách chưa đăng nhập vẫn xem sản phẩm, tìm kiếm, lọc bình thường;
    - navbar tự hiển thị trạng thái guest;
    - các chức năng mua hàng/tài khoản vẫn yêu cầu đăng nhập ở trang tương ứng.
*/
require_once "../config/database.php";
require_once "../includes/khuyen_mai_helper.php";

/* =========================================================
   HÀM HỖ TRỢ
========================================================= */

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $table): bool
{
    $table = $conn->real_escape_string($table);

    $rs = $conn->query("SHOW TABLES LIKE '{$table}'");

    return $rs && $rs->num_rows > 0;
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);

    $rs = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");

    return $rs && $rs->num_rows > 0;
}

function firstExistingColumn(mysqli $conn, string $table, array $columns): ?string
{
    foreach ($columns as $column) {
        if (columnExists($conn, $table, $column)) {
            return $column;
        }
    }

    return null;
}

/* =========================================================
   BỘ LỌC
========================================================= */

$search = trim($_GET['keyword'] ?? '');
$danhMucId = (int)($_GET['danh_muc'] ?? 0);
$rom = trim($_GET['rom'] ?? '');
$rating = (int)($_GET['rating'] ?? 0);
$sort = trim($_GET['sort'] ?? 'uu_tien');
$promotionFilter = trim($_GET['khuyen_mai'] ?? 'tat_ca');

$validSorts = [
    'uu_tien',
    'moi_nhat',
    'gia_tang',
    'gia_giam',
    'ban_chay',
    'danh_gia'
];

if (!in_array($sort, $validSorts, true)) {
    $sort = 'uu_tien';
}

$validPromotionFilters = [
    'tat_ca',
    'dang_khuyen_mai',
    'gia_thuong'
];

if (!in_array($promotionFilter, $validPromotionFilters, true)) {
    $promotionFilter = 'tat_ca';
}

/* =========================================================
   PHÁT HIỆN CẤU TRÚC DB
========================================================= */

$hasDanhMucId =
    columnExists($conn, 'san_pham', 'danh_muc_id') &&
    tableExists($conn, 'danh_muc');

$tenDanhMucColumn = null;

if ($hasDanhMucId) {
    $tenDanhMucColumn = firstExistingColumn(
        $conn,
        'danh_muc',
        ['ten_danh_muc', 'ten']
    );
}

$hasVariant =
    tableExists($conn, 'san_pham_bien_the') &&
    columnExists($conn, 'san_pham_bien_the', 'san_pham_id');

$hasRom =
    $hasVariant &&
    columnExists($conn, 'san_pham_bien_the', 'rom');

$hasVariantStock =
    $hasVariant &&
    columnExists($conn, 'san_pham_bien_the', 'so_luong');

$hasOrderDetail =
    tableExists($conn, 'chi_tiet_don_hang') &&
    tableExists($conn, 'don_hang');

$hasPromotionTable = tableExists($conn, 'khuyen_mai');

$hasDisplayOrder = columnExists($conn, 'san_pham', 'thu_tu_hien_thi');

/*
    KHUYẾN MÃI TRANG CHỦ

    Database hiện tại của project dùng bảng `khuyen_mai`.

    - bien_the_id IS NULL:
      khuyến mãi chung của sản phẩm.

    - bien_the_id có giá trị:
      khuyến mãi riêng cho biến thể.

    Trang chủ lấy ưu đãi còn hiệu lực có giá bán tốt nhất.
*/
function homeTinhGiaKhuyenMai(
    float $giaGoc,
    string $kieuGiam,
    float $giaTri
): float {
    if ($giaGoc <= 0) {
        return 0;
    }

    if ($kieuGiam === 'phan_tram') {
        $phanTram = max(
            0,
            min(100, $giaTri)
        );

        return max(
            0,
            $giaGoc * (
                1 - ($phanTram / 100)
            )
        );
    }

    if ($kieuGiam === 'so_tien') {
        return max(
            0,
            $giaGoc - max(0, $giaTri)
        );
    }

    return $giaGoc;
}

function homeLayKhuyenMaiTotNhat(
    mysqli $conn,
    int $sanPhamId,
    float $giaSanPham,
    int $tonKhoSanPham = 0,
    int $nguoiDungId = 0,
    ?string $chiLoaiKhuyenMai = null
): ?array {
    /*
        Sản phẩm đã hết hàng thì KHÔNG áp dụng khuyến mãi trên trang chủ.
        Nhờ vậy:
        - không xuất hiện trong Flash Sale / Xả kho / Khuyến mãi;
        - không hiện % giảm, giá sale hoặc tên chương trình;
        - card chỉ còn trạng thái "Tạm hết hàng".
    */
    if ($tonKhoSanPham <= 0) {
        return null;
    }

    if (!tableExists($conn, 'khuyen_mai')) {
        return null;
    }

    $hasVariant =
        tableExists(
            $conn,
            'san_pham_bien_the'
        );

    if ($hasVariant) {
        $sql = "
            SELECT
                km.*,
                bt.gia AS gia_bien_the,
                bt.so_luong AS ton_bien_the
            FROM khuyen_mai km

            LEFT JOIN san_pham_bien_the bt
                ON bt.id = km.bien_the_id
                AND bt.san_pham_id = km.san_pham_id

            WHERE km.san_pham_id = ?
              AND km.trang_thai = 1
              AND km.ngay_bat_dau <= NOW()
              AND km.ngay_ket_thuc >= NOW()

            ORDER BY km.id DESC
        ";
    } else {
        $sql = "
            SELECT
                km.*,
                NULL AS gia_bien_the,
                NULL AS ton_bien_the
            FROM khuyen_mai km

            WHERE km.san_pham_id = ?
              AND km.trang_thai = 1
              AND km.ngay_bat_dau <= NOW()
              AND km.ngay_ket_thuc >= NOW()

            ORDER BY km.id DESC
        ";
    }

    $stmt =
        $conn->prepare($sql);

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param(
        'i',
        $sanPhamId
    );

    $stmt->execute();

    $rows =
        $stmt
            ->get_result()
            ->fetch_all(
                MYSQLI_ASSOC
            );

    $stmt->close();

    /*
        Có thể yêu cầu chỉ xét một loại chương trình cụ thể
        (ví dụ flash_sale hoặc xa_kho).
        Việc lọc ở PHP giúp giữ nguyên câu SQL hiện tại và tránh
        phải nhân đôi toàn bộ logic điều kiện khuyến mãi.
    */
    if ($chiLoaiKhuyenMai !== null && $chiLoaiKhuyenMai !== '') {
        $rows = array_values(
            array_filter(
                $rows,
                static function (array $row) use ($chiLoaiKhuyenMai): bool {
                    return (
                        (string)($row['loai_khuyen_mai'] ?? '') ===
                        $chiLoaiKhuyenMai
                    );
                }
            )
        );
    }

    if (empty($rows)) {
        return null;
    }

    /*
        Dùng cùng bộ kiểm tra điều kiện với trang chi tiết / giỏ hàng:
        - đúng khung giờ;
        - đúng số lượng tối thiểu;
        - đúng tồn kho;
        - riêng Xả kho: tồn kho phải >= ngưỡng admin đã nhập;
        - đúng đối tượng khách hàng;
        - đúng điều kiện khách thân thiết...
    */
    $completedStats =
        function_exists('kmUserCompletedStats')
            ? kmUserCompletedStats(
                $conn,
                $nguoiDungId
            )
            : [
                'orders' => 0,
                'spent' => 0,
            ];

    $best = null;

    foreach ($rows as $row) {
        $giaBienThe =
            (float)(
                $row['gia_bien_the']
                ?? 0
            );

        $tonBienThe =
            (int)(
                $row['ton_bien_the']
                ?? 0
            );

        $tonKhoApDung =
            !empty($row['bien_the_id'])
                ? $tonBienThe
                : $tonKhoSanPham;

        if (
            function_exists('kmUserEligible') &&
            !kmUserEligible(
                $conn,
                $row,
                [
                    'gia_goc' =>
                        $giaBienThe > 0
                            ? $giaBienThe
                            : $giaSanPham,
                    'so_luong' => 1,
                    'ton_kho' => $tonKhoApDung,
                    'san_pham_id' => $sanPhamId,
                    'bien_the_id' =>
                        !empty($row['bien_the_id'])
                            ? (int)$row['bien_the_id']
                            : 0,
                    'nguoi_dung_id' => $nguoiDungId,
                ],
                $completedStats
            )
        ) {
            continue;
        }

        $giaGoc =
            !empty(
                $row['bien_the_id']
            ) &&
            $giaBienThe > 0
                ? $giaBienThe
                : $giaSanPham;

        if ($giaGoc <= 0) {
            continue;
        }

        $giaBan =
            homeTinhGiaKhuyenMai(
                $giaGoc,
                (string)(
                    $row['kieu_giam']
                    ?? ''
                ),
                (float)(
                    $row['gia_tri']
                    ?? 0
                )
            );

        if ($giaBan >= $giaGoc) {
            continue;
        }

        $phanTram =
            $giaGoc > 0
                ? (int)round(
                    (
                        (
                            $giaGoc -
                            $giaBan
                        ) /
                        $giaGoc
                    ) *
                    100
                )
                : 0;

        $candidate = $row;

        $candidate[
            'gia_goc_hien_thi'
        ] = $giaGoc;

        $candidate[
            'gia_ban_hien_thi'
        ] = $giaBan;

        $candidate[
            'phan_tram_giam'
        ] = max(
            1,
            min(
                100,
                $phanTram
            )
        );

        if (
            $best === null ||
            $giaBan <
                (float)$best[
                    'gia_ban_hien_thi'
                ] ||
            (
                abs(
                    $giaBan -
                    (float)$best[
                        'gia_ban_hien_thi'
                    ]
                ) < 0.01 &&
                (int)$candidate[
                    'phan_tram_giam'
                ] >
                (int)$best[
                    'phan_tram_giam'
                ]
            )
        ) {
            $best =
                $candidate;
        }
    }

    return $best;
}

$ratingColumn = null;

if (tableExists($conn, 'danh_gia')) {
    $ratingColumn = firstExistingColumn(
        $conn,
        'danh_gia',
        ['so_sao', 'diem_danh_gia', 'diem', 'rating']
    );
}

/* =========================================================
   DANH MỤC
========================================================= */

$categories = [];

if ($hasDanhMucId && $tenDanhMucColumn !== null) {
    $categoryResult = $conn->query(
        "SELECT id, `{$tenDanhMucColumn}` AS ten_danh_muc
         FROM danh_muc
         ORDER BY `{$tenDanhMucColumn}` ASC"
    );

    if ($categoryResult) {
        $categories = $categoryResult->fetch_all(MYSQLI_ASSOC);
    }
}

/* =========================================================
   DANH SÁCH DUNG LƯỢNG
========================================================= */

$romOptions = [];

if ($hasRom) {
    $romResult = $conn->query(
        "SELECT DISTINCT rom
         FROM san_pham_bien_the
         WHERE rom IS NOT NULL AND TRIM(rom) <> ''
         ORDER BY CAST(rom AS UNSIGNED) ASC, rom ASC"
    );

    if ($romResult) {
        while ($row = $romResult->fetch_assoc()) {
            $romOptions[] = $row['rom'];
        }
    }
}

/* =========================================================
   QUERY SẢN PHẨM
========================================================= */

$selectParts = ["sp.*"];

if ($hasOrderDetail) {
    $selectParts[] = "
        COALESCE(
            (
                SELECT SUM(ct.so_luong)
                FROM chi_tiet_don_hang ct
                WHERE ct.san_pham_id = sp.id
            ),
            0
        ) AS da_ban
    ";
} else {
    $selectParts[] = "0 AS da_ban";
}

if ($hasVariantStock) {
    $selectParts[] = "
        COALESCE(
            (
                SELECT SUM(bt_stock.so_luong)
                FROM san_pham_bien_the bt_stock
                WHERE bt_stock.san_pham_id = sp.id
            ),
            sp.so_luong,
            0
        ) AS ton_kho_hien_tai
    ";
} else {
    $selectParts[] = "COALESCE(sp.so_luong, 0) AS ton_kho_hien_tai";
}

if ($ratingColumn !== null) {
    $selectParts[] = "
        COALESCE(
            (
                SELECT AVG(dg.`{$ratingColumn}`)
                FROM danh_gia dg
                WHERE dg.san_pham_id = sp.id
            ),
            0
        ) AS diem_danh_gia
    ";

    $selectParts[] = "
        COALESCE(
            (
                SELECT COUNT(*)
                FROM danh_gia dg_count
                WHERE dg_count.san_pham_id = sp.id
            ),
            0
        ) AS so_danh_gia
    ";
} else {
    $selectParts[] = "0 AS diem_danh_gia";
    $selectParts[] = "0 AS so_danh_gia";
}

$sql = "
    SELECT
        " . implode(",\n", $selectParts) . "
    FROM san_pham sp
    WHERE sp.trang_thai = 1
";

$params = [];
$types = "";

// if ($search !== '') {
//     $sql .= " AND sp.ten_san_pham LIKE ? ";
//     $params[] = '%' . $search . '%';
//     $types .= 's';
// }

if ($search !== '') {
    $keywords = preg_split(
        '/\s+/u',
        $search,
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    foreach ($keywords as $keyword) {
        $sql .= " AND sp.ten_san_pham LIKE ? ";
        $params[] = '%' . $keyword . '%';
        $types .= 's';
    }
}

if ($danhMucId > 0 && $hasDanhMucId) {
    $sql .= " AND sp.danh_muc_id = ? ";
    $params[] = $danhMucId;
    $types .= 'i';
}

if ($rom !== '' && $hasRom) {
    $sql .= "
        AND EXISTS (
            SELECT 1
            FROM san_pham_bien_the bt_rom
            WHERE bt_rom.san_pham_id = sp.id
              AND bt_rom.rom = ?
        )
    ";
    $params[] = $rom;
    $types .= 's';
}

if ($rating >= 1 && $rating <= 5 && $ratingColumn !== null) {
    $sql .= "
        AND (
            SELECT COALESCE(AVG(dg_filter.`{$ratingColumn}`), 0)
            FROM danh_gia dg_filter
            WHERE dg_filter.san_pham_id = sp.id
        ) >= ?
    ";
    $params[] = $rating;
    $types .= 'i';
}

switch ($sort) {
    case 'gia_tang':
    case 'gia_giam':
        $sql .= " ORDER BY sp.id DESC ";
        break;

    case 'ban_chay':
        $sql .= " ORDER BY da_ban DESC, sp.id DESC ";
        break;

    case 'danh_gia':
        $sql .= " ORDER BY diem_danh_gia DESC, sp.id DESC ";
        break;

    case 'moi_nhat':
        $sql .= " ORDER BY sp.id DESC ";
        break;

    case 'uu_tien':
    default:
        if ($hasDisplayOrder) {
            $sql .= "
                ORDER BY
                    CASE
                        WHEN sp.thu_tu_hien_thi IS NULL
                             OR sp.thu_tu_hien_thi <= 0
                        THEN 999999
                        ELSE sp.thu_tu_hien_thi
                    END ASC,
                    sp.id DESC
            ";
        } else {
            $sql .= " ORDER BY sp.id DESC ";
        }
        break;
}

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();

$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* =========================================================
   PHÂN LOẠI THEO CHƯƠNG TRÌNH SALE
========================================================= */

$promotionProducts = [];
$normalProducts = [];

foreach ($products as &$product) {
    $giaSanPham =
        (float)($product['gia'] ?? 0);

    $productId =
        (int)$product['id'];

    $productStock =
        (int)($product['ton_kho_hien_tai'] ?? 0);

    $userId =
        (int)($_SESSION['user']['id'] ?? 0);

    /*
        HẾT HÀNG:
        - không xét bất kỳ khuyến mãi nào;
        - không đưa vào khu Flash Sale / Xả kho / Khuyến mãi;
        - vẫn giữ sản phẩm ở khu sản phẩm thường để khách biết shop có bán;
        - card sẽ hiện "Tạm hết hàng - Sắp về hàng".
    */
    if ($productStock <= 0) {
        $product['co_khuyen_mai'] = 0;
        $product['gia_goc_hien_thi'] = $giaSanPham;
        $product['gia_ban_hien_thi'] = $giaSanPham;
        $product['phan_tram_giam'] = 0;
        $product['ten_khuyen_mai_hien_thi'] = '';
        $product['loai_khuyen_mai_hien_thi'] = '';
        $product['kieu_giam_khuyen_mai'] = '';
        $product['gia_tri_khuyen_mai'] = 0;
        $product['bien_the_khuyen_mai_id'] = 0;
        $product['ngay_bat_dau_khuyen_mai'] = '';
        $product['ngay_ket_thuc_khuyen_mai'] = '';
        $product['gio_bat_dau_khuyen_mai'] = '';
        $product['gio_ket_thuc_khuyen_mai'] = '';
        $product['ly_do_khuyen_mai'] = '';

        $normalProducts[] = $product;
        continue;
    }

    /*
        Giá mặc định vẫn lấy chương trình tốt nhất.
    */
    $activePromotion =
        homeLayKhuyenMaiTotNhat(
            $conn,
            $productId,
            $giaSanPham,
            $productStock,
            $userId
        );

    /*
        Nhưng việc chia khu vực phải nhận diện Flash Sale / Xả kho
        độc lập, không phụ thuộc promo tốt nhất tình cờ là loại nào.
    */
    $flashPromotion =
        homeLayKhuyenMaiTotNhat(
            $conn,
            $productId,
            $giaSanPham,
            $productStock,
            $userId,
            'flash_sale'
        );

    $clearancePromotion =
        homeLayKhuyenMaiTotNhat(
            $conn,
            $productId,
            $giaSanPham,
            $productStock,
            $userId,
            'xa_kho'
        );

    /*
        Ưu tiên khu hiển thị:
        1. Flash Sale (vì có countdown riêng)
        2. Xả kho
        3. Các khuyến mãi khác

        Khi đã chọn khu, card sử dụng chính promo của khu đó
        để tên/giá/% giảm không bị lệch nhau.
    */
    $displayPromotion =
        $flashPromotion
        ?: (
            $clearancePromotion
            ?: $activePromotion
        );

    if ($displayPromotion) {
        $activePromotion =
            $displayPromotion;
        $product['co_khuyen_mai'] = 1;

        $product['gia_goc_hien_thi'] =
            (float)$activePromotion['gia_goc_hien_thi'];

        $product['gia_ban_hien_thi'] =
            (float)$activePromotion['gia_ban_hien_thi'];

        $product['phan_tram_giam'] =
            (int)$activePromotion['phan_tram_giam'];

        $product['ten_khuyen_mai_hien_thi'] =
            (string)(
                $activePromotion['ten_khuyen_mai']
                ?? 'Khuyến mãi'
            );

        $product['loai_khuyen_mai_hien_thi'] =
            (string)(
                $activePromotion['loai_khuyen_mai']
                ?? 'khac'
            );

        $product['kieu_giam_khuyen_mai'] =
            (string)($activePromotion['kieu_giam'] ?? '');

        $product['gia_tri_khuyen_mai'] =
            (float)($activePromotion['gia_tri'] ?? 0);

        /*
            Nếu ưu đãi chỉ áp dụng cho một biến thể,
            lưu lại ID để khi bấm xem chi tiết có thể tự chọn
            đúng dung lượng + màu đang được giảm giá.
        */
        $product['bien_the_khuyen_mai_id'] =
            (int)($activePromotion['bien_the_id'] ?? 0);

        $product['ngay_bat_dau_khuyen_mai'] =
            (string)($activePromotion['ngay_bat_dau'] ?? '');

        $product['ngay_ket_thuc_khuyen_mai'] =
            (string)($activePromotion['ngay_ket_thuc'] ?? '');

        $product['gio_bat_dau_khuyen_mai'] =
            (string)($activePromotion['gio_bat_dau'] ?? '');

        $product['gio_ket_thuc_khuyen_mai'] =
            (string)($activePromotion['gio_ket_thuc'] ?? '');

        $product['ly_do_khuyen_mai'] =
            (string)(
                $activePromotion['ly_do']
                ?? ''
            );

        $promotionProducts[] = $product;
    } else {
        $product['co_khuyen_mai'] = 0;

        $product['gia_goc_hien_thi'] =
            $giaSanPham;

        $product['gia_ban_hien_thi'] =
            $giaSanPham;

        $product['phan_tram_giam'] = 0;
        $product['ten_khuyen_mai_hien_thi'] = '';
        $product['loai_khuyen_mai_hien_thi'] = '';
        $product['kieu_giam_khuyen_mai'] = '';
        $product['gia_tri_khuyen_mai'] = 0;
        $product['bien_the_khuyen_mai_id'] = 0;
        $product['ngay_bat_dau_khuyen_mai'] = '';
        $product['ngay_ket_thuc_khuyen_mai'] = '';
        $product['gio_bat_dau_khuyen_mai'] = '';
        $product['gio_ket_thuc_khuyen_mai'] = '';
        $product['ly_do_khuyen_mai'] = '';

        $normalProducts[] = $product;
    }
}

unset($product);

if ($promotionFilter === 'dang_khuyen_mai') {
    $normalProducts = [];
} elseif ($promotionFilter === 'gia_thuong') {
    $promotionProducts = [];
}

/*
    Sắp xếp bằng giá hiển thị thực tế sau khuyến mãi.
*/
if (
    $sort === 'gia_tang' ||
    $sort === 'gia_giam'
) {
    $sortByDisplayPrice = static function (
        array $a,
        array $b
    ) use ($sort): int {
        $priceA = (float)(
            $a['gia_ban_hien_thi']
            ?? $a['gia']
            ?? 0
        );

        $priceB = (float)(
            $b['gia_ban_hien_thi']
            ?? $b['gia']
            ?? 0
        );

        $compare = $priceA <=> $priceB;

        return $sort === 'gia_giam'
            ? -$compare
            : $compare;
    };

    usort(
        $promotionProducts,
        $sortByDisplayPrice
    );

    usort(
        $normalProducts,
        $sortByDisplayPrice
    );
}

/* =========================================================
   CHIA NHÓM SALE + TỔNG HỢP CHƯƠNG TRÌNH ĐANG CHẠY
========================================================= */

$flashSaleProducts = [];
$clearanceProducts = [];
$eventPromotionProducts = [];
$activeSalePrograms = [];

function homePromotionTypeLabel(string $type): string
{
    $labels = [
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
        'khac' => 'Khuyến mãi',
    ];

    if (isset($labels[$type])) {
        return $labels[$type];
    }

    if (function_exists('tenLoaiKhuyenMai')) {
        return tenLoaiKhuyenMai($type);
    }

    return 'Khuyến mãi';
}

function homePromotionValueLabel(array $product): string
{
    $kieu = (string)($product['kieu_giam_khuyen_mai'] ?? '');
    $giaTri = (float)($product['gia_tri_khuyen_mai'] ?? 0);

    if ($kieu === 'phan_tram' && $giaTri > 0) {
        return 'Giảm ' . rtrim(rtrim(number_format($giaTri, 2, '.', ''), '0'), '.') . '%';
    }

    if ($kieu === 'so_tien' && $giaTri > 0) {
        return 'Giảm ' . number_format($giaTri, 0, ',', '.') . 'đ';
    }

    $phanTram = (int)($product['phan_tram_giam'] ?? 0);

    return $phanTram > 0
        ? 'Giảm ' . $phanTram . '%'
        : 'Giá ưu đãi';
}

function homeDateTimeLabel(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);

    return $timestamp
        ? date('H:i d/m/Y', $timestamp)
        : $value;
}

function homeDateLabel(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);

    return $timestamp
        ? date('d/m/Y', $timestamp)
        : $value;
}

/*
    Deadline thật của Flash sale đang chạy.

    Ví dụ chiến dịch:
    24/10 -> 25/10, mỗi ngày 01:00 -> 15:30

    Nếu hiện tại là 24/10 13:00 thì countdown phải về
    24/10 15:30, KHÔNG phải 25/10 23:59.
*/
function homePromotionCurrentDeadline(array $product): string
{
    $campaignEnd =
        trim(
            (string)(
                $product['ngay_ket_thuc_khuyen_mai']
                ?? ''
            )
        );

    $type =
        trim(
            (string)(
                $product['loai_khuyen_mai_hien_thi']
                ?? ''
            )
        );

    $startTime =
        substr(
            trim(
                (string)(
                    $product['gio_bat_dau_khuyen_mai']
                    ?? ''
                )
            ),
            0,
            5
        );

    $endTime =
        substr(
            trim(
                (string)(
                    $product['gio_ket_thuc_khuyen_mai']
                    ?? ''
                )
            ),
            0,
            5
        );

    if (
        $type !== 'flash_sale' ||
        $startTime === '' ||
        $endTime === ''
    ) {
        return $campaignEnd;
    }

    $today = date('Y-m-d');
    $nowTime = date('H:i');

    if ($startTime <= $endTime) {
        $windowEnd =
            $today . ' ' . $endTime . ':00';
    } else {
        /*
            Khung giờ qua đêm, ví dụ 22:00 -> 02:00.
            - 23:00: kết thúc vào ngày mai 02:00
            - 01:00: kết thúc hôm nay 02:00
        */
        $endDate =
            $nowTime >= $startTime
                ? date(
                    'Y-m-d',
                    strtotime('+1 day')
                )
                : $today;

        $windowEnd =
            $endDate . ' ' . $endTime . ':00';
    }

    if ($campaignEnd !== '') {
        $campaignEndTs =
            strtotime($campaignEnd);

        $windowEndTs =
            strtotime($windowEnd);

        if (
            $campaignEndTs !== false &&
            $windowEndTs !== false &&
            $campaignEndTs < $windowEndTs
        ) {
            return $campaignEnd;
        }
    }

    return $windowEnd;
}

foreach ($promotionProducts as $promoProduct) {
    $type = (string)(
        $promoProduct['loai_khuyen_mai_hien_thi']
        ?? 'khac'
    );

    if ($type === 'flash_sale') {
        $flashSaleProducts[] = $promoProduct;
    } elseif ($type === 'xa_kho') {
        $clearanceProducts[] = $promoProduct;
    } else {
        $eventPromotionProducts[] = $promoProduct;
    }

    $programName = trim(
        (string)(
            $promoProduct['ten_khuyen_mai_hien_thi']
            ?? 'Khuyến mãi'
        )
    );

    $programStart = trim(
        (string)(
            $promoProduct['ngay_bat_dau_khuyen_mai']
            ?? ''
        )
    );

    $programEnd = trim(
        (string)(
            $promoProduct['ngay_ket_thuc_khuyen_mai']
            ?? ''
        )
    );

    $programTimeStart =
        substr(
            trim(
                (string)(
                    $promoProduct['gio_bat_dau_khuyen_mai']
                    ?? ''
                )
            ),
            0,
            5
        );

    $programTimeEnd =
        substr(
            trim(
                (string)(
                    $promoProduct['gio_ket_thuc_khuyen_mai']
                    ?? ''
                )
            ),
            0,
            5
        );

    $programDeadline =
        homePromotionCurrentDeadline(
            $promoProduct
        );

    $programKey = md5(
        $programName . '|' .
        $type . '|' .
        $programStart . '|' .
        $programEnd . '|' .
        $programTimeStart . '|' .
        $programTimeEnd
    );

    if (!isset($activeSalePrograms[$programKey])) {
        $activeSalePrograms[$programKey] = [
            'name' => $programName,
            'type' => $type,
            'type_label' => homePromotionTypeLabel($type),
            'value_label' => homePromotionValueLabel($promoProduct),
            'start' => $programStart,
            'end' => $programEnd,
            'time_start' => $programTimeStart,
            'time_end' => $programTimeEnd,
            'deadline' => $programDeadline,
            'count' => 0,
        ];
    }

    $activeSalePrograms[$programKey]['count']++;
}

$activeSalePrograms = array_values($activeSalePrograms);

/*
    Thời gian hiển thị cho khu Xả kho.
    Chỉ lấy từ các chương trình Xả kho đang thực sự áp dụng.
*/
$clearanceStart = '';
$clearanceEnd = '';

foreach ($clearanceProducts as $clearanceProduct) {
    $startValue = trim(
        (string)($clearanceProduct['ngay_bat_dau_khuyen_mai'] ?? '')
    );

    $endValue = trim(
        (string)($clearanceProduct['ngay_ket_thuc_khuyen_mai'] ?? '')
    );

    if (
        $startValue !== '' &&
        (
            $clearanceStart === '' ||
            strtotime($startValue) < strtotime($clearanceStart)
        )
    ) {
        $clearanceStart = $startValue;
    }

    if (
        $endValue !== '' &&
        (
            $clearanceEnd === '' ||
            strtotime($endValue) > strtotime($clearanceEnd)
        )
    ) {
        $clearanceEnd = $endValue;
    }
}

usort(
    $activeSalePrograms,
    static function (array $a, array $b): int {
        $priority = static function (string $type): int {
            if ($type === 'flash_sale') {
                return 0;
            }

            if ($type === 'xa_kho') {
                return 1;
            }

            return 2;
        };

        $aPriority = $priority((string)($a['type'] ?? ''));
        $bPriority = $priority((string)($b['type'] ?? ''));

        if ($aPriority !== $bPriority) {
            return $aPriority <=> $bPriority;
        }

        return strtotime((string)($a['end'] ?? '')) <=> strtotime((string)($b['end'] ?? ''));
    }
);


/*
    GOM CÁC CHƯƠNG TRÌNH CÙNG LOẠI THÀNH 1 KHỐI TÓM TẮT.
    Ví dụ nhiều chương trình Xả kho sẽ chỉ còn 1 thẻ Xả kho ở khu
    "Khuyến mãi & sự kiện nổi bật", tránh giao diện dài và lặp.

    Khi bấm thẻ:
    - Flash Sale -> #flash-sale
    - Xả kho -> #clearance
    - Các ưu đãi còn lại -> #promotion-products
*/
$saleProgramGroups = [];

foreach ($activeSalePrograms as $program) {
    $type = trim(
        (string)($program['type'] ?? 'khac')
    );

    if ($type === '') {
        $type = 'khac';
    }

    if (!isset($saleProgramGroups[$type])) {
        $anchor = 'promotion-products';
        $icon = '🎉';

        if ($type === 'flash_sale') {
            $anchor = 'flash-sale';
            $icon = '⚡';
        } elseif ($type === 'xa_kho') {
            $anchor = 'clearance';
            $icon = '🔥';
        } elseif (
            in_array(
                $type,
                ['online', 'uu_dai_online'],
                true
            )
        ) {
            $icon = '🌐';
        } elseif (
            in_array(
                $type,
                ['hoc_sinh_sinh_vien', 'hssv'],
                true
            )
        ) {
            $icon = '🎓';
        } elseif ($type === 'thanh_toan') {
            $icon = '💳';
        } elseif ($type === 'thu_cu_doi_moi') {
            $icon = '♻️';
        }

        $saleProgramGroups[$type] = [
            'type' => $type,
            'type_label' =>
                (string)(
                    $program['type_label']
                    ?? homePromotionTypeLabel($type)
                ),
            'icon' => $icon,
            'anchor' => $anchor,
            'program_count' => 0,
            'product_count' => 0,
            'value_labels' => [],
            'start' => '',
            'end' => '',
            'deadline' => '',
        ];
    }

    $saleProgramGroups[$type]['program_count']++;

    $saleProgramGroups[$type]['product_count'] +=
        max(
            0,
            (int)($program['count'] ?? 0)
        );

    $valueLabel = trim(
        (string)($program['value_label'] ?? '')
    );

    if ($valueLabel !== '') {
        $saleProgramGroups[$type]['value_labels'][$valueLabel] =
            true;
    }

    $start = trim(
        (string)($program['start'] ?? '')
    );

    if (
        $start !== '' &&
        (
            $saleProgramGroups[$type]['start'] === '' ||
            strtotime($start) <
            strtotime(
                (string)$saleProgramGroups[$type]['start']
            )
        )
    ) {
        $saleProgramGroups[$type]['start'] = $start;
    }

    $end = trim(
        (string)($program['end'] ?? '')
    );

    if (
        $end !== '' &&
        (
            $saleProgramGroups[$type]['end'] === '' ||
            strtotime($end) >
            strtotime(
                (string)$saleProgramGroups[$type]['end']
            )
        )
    ) {
        $saleProgramGroups[$type]['end'] = $end;
    }

    $deadline = trim(
        (string)($program['deadline'] ?? '')
    );

    if (
        $deadline !== '' &&
        (
            $saleProgramGroups[$type]['deadline'] === '' ||
            strtotime($deadline) <
            strtotime(
                (string)$saleProgramGroups[$type]['deadline']
            )
        )
    ) {
        $saleProgramGroups[$type]['deadline'] = $deadline;
    }
}

$saleProgramGroups = array_values(
    $saleProgramGroups
);

usort(
    $saleProgramGroups,
    static function (array $a, array $b): int {
        $priority = static function (string $type): int {
            if ($type === 'flash_sale') {
                return 0;
            }

            if ($type === 'xa_kho') {
                return 1;
            }

            if (
                in_array(
                    $type,
                    ['online', 'uu_dai_online'],
                    true
                )
            ) {
                return 2;
            }

            return 3;
        };

        $aPriority =
            $priority(
                (string)($a['type'] ?? '')
            );

        $bPriority =
            $priority(
                (string)($b['type'] ?? '')
            );

        if ($aPriority !== $bPriority) {
            return $aPriority <=> $bPriority;
        }

        return strcmp(
            (string)($a['type_label'] ?? ''),
            (string)($b['type_label'] ?? '')
        );
    }
);

/* =========================================================
   SLIDER CỐ ĐỊNH
   Đặt ảnh tại: assets/banners/
========================================================= */
$slides = [
    [
        'image' => 'https://cdn2.fptshop.com.vn/unsafe/1920x0/filters:format(webp):quality(75)/NPI_100925_cover_8a5241669c.png',
    ],
    [
        'image' => 'https://www.homecredit.vn/static/cdf3446968e365f4f8fbc9266010e77a/ab7c8/mua_tra_gop_iphone_16_banner_74273b74f0.webp',
    ],
    [
        'image' => 'https://i.ytimg.com/vi/KpI-QDbrf0Y/maxresdefault.jpg',
    ],
    [
        'image' => 'https://duongphi.com/wp-content/uploads/2024/06/BANNER-WEBSITE-IPHONE-18-SERIES.png',
    ],
    [
        'image' => 'https://www.shutterstock.com/image-illustration/iphone-15-pro-max-render-260nw-2410630321.jpg',
    ],
    [
        'image' => 'https://img.pikbest.com/origin/10/01/53/35bpIkbEsTBzN.png!bw700',
    ],
    [
        'image' => 'https://cdn2.cellphones.com.vn/insecure/rs:fill:1036:450/q:100/plain/https://media-asset.cellphones.com.vn/dashboard-v1/manage-banner/home-ip18-avai.png',
    ]
];

/*
    Flash Sale ưu tiên đếm ngược tới chương trình
    kết thúc sớm nhất. Nếu không có Flash Sale thì dùng
    chương trình khuyến mãi kết thúc sớm nhất.
*/
$promotionDeadline = '';
$deadlineProducts = !empty($flashSaleProducts)
    ? $flashSaleProducts
    : $promotionProducts;

foreach ($deadlineProducts as $promoProduct) {
    $deadline =
        homePromotionCurrentDeadline(
            $promoProduct
        );

    if ($deadline === '') {
        continue;
    }

    if (
        $promotionDeadline === '' ||
        strtotime($deadline) < strtotime($promotionDeadline)
    ) {
        $promotionDeadline = $deadline;
    }
}

function renderProductCard(array $product): void
{
    $id = (int)($product['id'] ?? 0);
    $ten = trim((string)($product['ten_san_pham'] ?? ''));
    $hinhAnh = trim((string)($product['hinh_anh'] ?? ''));

    $tonKho = (int)($product['ton_kho_hien_tai'] ?? 0);
    $isOutOfStock = $tonKho <= 0;

    /*
        Lớp bảo vệ giao diện:
        dù dữ liệu cũ còn cờ khuyến mãi, sản phẩm hết hàng
        vẫn không được render badge giảm giá / giá sale.
    */
    $hasDiscount =
        !empty($product['co_khuyen_mai']) &&
        !$isOutOfStock;

    $giaGoc = (float)(
        $product['gia_goc_hien_thi']
        ?? $product['gia']
        ?? 0
    );

    $giaHienTai = $isOutOfStock
        ? $giaGoc
        : (float)(
            $product['gia_ban_hien_thi']
            ?? $giaGoc
        );

    $phanTram = (int)(
        $product['phan_tram_giam']
        ?? 0
    );

    $promotionName = trim(
        (string)(
            $product['ten_khuyen_mai_hien_thi']
            ?? ''
        )
    );

    $promotionType = trim(
        (string)(
            $product['loai_khuyen_mai_hien_thi']
            ?? ''
        )
    );

    $promotionTypeLabel = $hasDiscount
        ? homePromotionTypeLabel($promotionType)
        : '';

    $promotionEnd =
        homePromotionCurrentDeadline(
            $product
        );

    $savedAmount = max(0, $giaGoc - $giaHienTai);

    $promotionVariantId =
        (int)(
            $product['bien_the_khuyen_mai_id']
            ?? 0
        );

    $detailUrl =
        './san_pham/chi_tiet.php?id=' . $id;

    if (
        $hasDiscount &&
        $promotionVariantId > 0
    ) {
        $detailUrl .=
            '&bien_the_id=' .
            $promotionVariantId .
            '&from=khuyen_mai';
    }

    $daBan = (int)($product['da_ban'] ?? 0);
    $diemDanhGia = (float)($product['diem_danh_gia'] ?? 0);
    $soDanhGia = (int)($product['so_danh_gia'] ?? 0);
    ?>
    <div class="col-6 col-md-4 col-xl-3 js-product-item">
        <a
            href="<?= e($detailUrl) ?>"
            class="product-card-link <?= $isOutOfStock ? 'product-card-link-out-of-stock' : '' ?>"
            aria-label="<?= e($ten) ?><?= $isOutOfStock ? ' - Tạm hết hàng' : '' ?>"
        >
            <article class="product-card h-100
                <?= $promotionType === 'flash_sale' ? 'product-card-flash' : '' ?>
                <?= $promotionType === 'xa_kho' ? 'product-card-clearance' : '' ?>
                <?= $isOutOfStock ? 'product-card-out-of-stock' : '' ?>
            ">
                <div class="product-image-wrap">
                    <?php if ($hasDiscount): ?>
                        <span class="discount-badge">
                            <span>-<?= max(1, $phanTram) ?>%</span>
                        </span>

                        <?php if ($promotionType === 'flash_sale'): ?>
                            <span class="flash-mini-badge">
                                <i class="fa fa-bolt"></i>
                                FLASH SALE
                            </span>
                        <?php elseif ($promotionType === 'xa_kho'): ?>
                            <span class="clearance-mini-badge">
                                🔥 XẢ KHO
                            </span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($isOutOfStock): ?>
                        <div class="out-of-stock-overlay">
                            <span class="out-of-stock-badge">
                                <i class="fa fa-clock"></i>
                                TẠM HẾT HÀNG
                            </span>
                        </div>
                    <?php endif; ?>

                    <?php if ($hinhAnh !== ''): ?>
                        <img
                            src="../uploads/<?= e($hinhAnh) ?>"
                            alt="<?= e($ten) ?>"
                            class="product-image"
                            loading="lazy"
                        >
                    <?php else: ?>
                        <div class="product-image product-image-empty">
                            <i class="fa fa-image"></i>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="product-body">
                    <div class="product-name" title="<?= e($ten) ?>">
                        <?= e($ten) ?>
                    </div>

                    <div class="rating-row">
                        <?php
                        $ratingRounded = max(
                            0,
                            min(
                                5,
                                (int)round($diemDanhGia)
                            )
                        );

                        $ratingStars =
                            str_repeat('★', $ratingRounded) .
                            str_repeat('☆', 5 - $ratingRounded);
                        ?>

                        <span class="rating-score">
                            <?= number_format($diemDanhGia, 1) ?>
                        </span>

                        <span
                            class="rating-stars"
                            aria-label="<?= number_format($diemDanhGia, 1) ?> trên 5 sao"
                        >
                            <?= $ratingStars ?>
                        </span>

                        <span class="rating-count">
                            (<?= $soDanhGia ?>)
                        </span>
                    </div>

                    <div class="price-block">
                        <div class="price-row">
                            <div class="current-price">
                                <?= number_format($giaHienTai, 0, ',', '.') ?>đ
                            </div>

                            <?php if ($hasDiscount): ?>
                                <span class="old-price">
                                    <?= number_format($giaGoc, 0, ',', '.') ?>đ
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if ($hasDiscount): ?>
                            <div class="promotion-summary">
                                <span class="promotion-type-pill">
                                    <?= e($promotionTypeLabel) ?>
                                </span>

                                <span class="save-text">
                                    Tiết kiệm <?= number_format($savedAmount, 0, ',', '.') ?>đ
                                </span>
                            </div>

                            <?php if ($promotionName !== ''): ?>
                                <div class="promotion-name" title="<?= e($promotionName) ?>">
                                    <?= e($promotionName) ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($promotionType === 'flash_sale' && $promotionEnd !== ''): ?>
                                <div class="product-flash-countdown">
                                    <i class="fa fa-clock"></i>
                                    Còn
                                    <strong
                                        class="sale-countdown-inline"
                                        data-sale-deadline="<?= e($promotionEnd) ?>"
                                    >
                                        00:00:00
                                    </strong>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="regular-price-note">
                                <?= $isOutOfStock ? 'Giá niêm yết' : 'Giá bán hiện tại' ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($isOutOfStock): ?>
                        <div class="stock-row stock-row-out">
                            <div class="stock-out-main">
                                <span class="stock-out-title">
                                    <i class="fa fa-box-open"></i>
                                    Tạm hết hàng
                                </span>
                                <span class="stock-out-note">
                                    Sắp về hàng · Vui lòng quay lại sau
                                </span>
                            </div>

                            <span class="stock-out-sold">
                                Đã bán <strong><?= $daBan ?></strong>
                            </span>
                        </div>
                    <?php else: ?>
                        <div class="stock-row">
                            <span>
                                <i class="fa fa-shopping-bag"></i>
                                Đã bán <strong><?= $daBan ?></strong>
                            </span>

                            <span class="stock-available">
                                <i class="fa fa-box"></i>
                                Còn <strong><?= $tonKho ?></strong>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            </article>
        </a>
    </div>
    <?php
}
?>

<?php include "../includes/header.php"; ?>
<?php include "../includes/navbar.php"; ?>

<style>
:root {
    --home-bg: #f5f6f8;
    --card-bg: #ffffff;
    --text-main: #171717;
    --text-muted: #737373;
    --line: #e8e8e8;
    --danger: #ea2f2f;
    --danger-dark: #c92121;
    --danger-soft: #fff1f1;
    --star: #f59e0b;
    --dark: #111111;
}

body {
    background: var(--home-bg);
    color: var(--text-main);
}

.home-page {
    padding-bottom: 48px;
}

/* =========================
   SLIDER
========================= */

.hero-shell {
    margin-top: 24px;
}

.hero-carousel {
    overflow: hidden;
    border-radius: 24px;
    background: #111;
    box-shadow: 0 12px 30px rgba(0, 0, 0, .08);
}

.hero-slide {
    position: relative;
    height: clamp(250px, 36vw, 430px);
    overflow: hidden;
    background: radial-gradient(circle at 20% 20%, #3a3a3a, #111 60%);
}

.hero-slide img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    opacity: 1;
    transform: none;
}

.hero-slide::after {
    content: "";
    position: absolute;
    inset: 0;
    background: linear-gradient(
        90deg,
        rgba(0, 0, 0, .72) 0%,
        rgba(0, 0, 0, .38) 42%,
        rgba(0, 0, 0, .06) 78%
    );
}

.hero-content {
    position: absolute;
    z-index: 2;
    left: clamp(22px, 5vw, 68px);
    top: 50%;
    transform: translateY(-50%);
    max-width: 520px;
    color: #fff;
}

.hero-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
    padding: 7px 12px;
    border-radius: 999px;
    background: rgba(255, 255, 255, .15);
    backdrop-filter: blur(8px);
    font-size: 13px;
    font-weight: 700;
}

.hero-title {
    margin: 0 0 10px;
    font-size: clamp(24px, 4vw, 46px);
    line-height: 1.08;
    font-weight: 800;
}

.hero-price {
    margin-bottom: 12px;
    font-size: clamp(18px, 2.4vw, 28px);
    font-weight: 800;
}

.hero-price del {
    margin-right: 8px;
    color: rgba(255, 255, 255, .66);
    font-size: .67em;
    font-weight: 500;
}

.hero-sale-note {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 18px;
    padding: 9px 12px;
    border-radius: 12px;
    background: rgba(255, 255, 255, .12);
    font-size: 13px;
    color: #fff;
}

.hero-sale-note strong {
    font-size: 14px;
}

.hero-button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 11px 18px;
    border-radius: 11px;
    background: #fff;
    color: #111;
    font-weight: 700;
    text-decoration: none;
    transition: .2s ease;
}

.hero-button:hover {
    color: #111;
    transform: translateY(-2px);
}

.hero-carousel .carousel-control-prev,
.hero-carousel .carousel-control-next {
    top: 50%;
    bottom: auto;
    width: 48px;
    height: 48px;
    transform: translateY(-50%);
    margin: 0 14px;
    border-radius: 50%;
    background: rgba(255, 255, 255, .92);
    opacity: 1;
    box-shadow: 0 4px 16px rgba(0, 0, 0, .16);
}

.hero-carousel .carousel-control-prev-icon,
.hero-carousel .carousel-control-next-icon {
    width: 18px;
    height: 18px;
    filter: invert(1);
}

.hero-carousel .carousel-indicators {
    margin-bottom: 14px;
}

.hero-carousel .carousel-indicators [data-bs-target] {
    width: 8px;
    height: 8px;
    border: 0;
    border-radius: 50%;
    opacity: .55;
}

.hero-carousel .carousel-indicators .active {
    width: 24px;
    border-radius: 999px;
    opacity: 1;
}

/* =========================
   LAYOUT / FILTER
========================= */

.shop-layout {
    margin-top: 28px;
}

.filter-card {
    position: sticky;
    top: 92px;
    padding: 20px;
    border: 1px solid #e8ebf0;
    border-radius: 20px;
    background: linear-gradient(180deg, #ffffff 0%, #fbfcfe 100%);
    box-shadow: 0 10px 30px rgba(15, 23, 42, .055);
}

.filter-title {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 18px;
    padding-bottom: 14px;
    border-bottom: 1px solid #eef0f3;
    color: #202124;
    font-size: 18px;
    font-weight: 850;
}

.filter-title i {
    width: 34px;
    height: 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    background: #fff1f2;
    color: #d70018;
    font-size: 14px;
}

.filter-group + .filter-group {
    margin-top: 15px;
}

.filter-label {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-bottom: 7px;
    color: #4b5563;
    font-size: 12.5px;
    font-weight: 750;
}

.filter-label i {
    width: 18px;
    color: #8a94a3;
    text-align: center;
    font-size: 12px;
}

.filter-card .form-control,
.filter-card .form-select {
    min-height: 46px;
    border: 1px solid #e2e5ea;
    border-radius: 13px;
    background-color: #fff;
    color: #252b33;
    font-size: 13.5px;
    box-shadow: none;
    transition: .18s ease;
}

.filter-card .form-control:focus,
.filter-card .form-select:focus {
    border-color: #ff9da8;
    box-shadow: 0 0 0 3px rgba(215, 0, 24, .07);
}

.filter-card .form-control::placeholder {
    color: #b0b6bf;
}

.filter-actions {
    display: grid;
    grid-template-columns: 1fr;
    gap: 10px;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid #eef0f3;
}

.btn-filter {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    min-height: 47px;
    border: 0;
    border-radius: 13px;
    background: linear-gradient(135deg, #e11d48, #d70018);
    color: #fff;
    font-size: 14px;
    font-weight: 800;
    box-shadow: 0 8px 18px rgba(215, 0, 24, .18);
    transition: .2s ease;
}

.btn-filter i {
    color: #fff;
}

.btn-filter:hover {
    transform: translateY(-1px);
    background: linear-gradient(135deg, #d91840, #c70016);
    color: #fff;
    box-shadow: 0 10px 22px rgba(215, 0, 24, .23);
}

.btn-reset {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 43px;
    border: 1px solid #e0e3e8;
    border-radius: 12px;
    background: #fff;
    color: #606975;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
}

.btn-reset:hover {
    border-color: #cfd4da;
    background: #f8f9fb;
    color: #333;
}

/* =========================
   SECTION
========================= */

.section-block + .section-block {
    margin-top: 34px;
}

.section-head {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 15px;
}

.section-title {
    margin: 0;
    font-size: clamp(21px, 2.4vw, 29px);
    font-weight: 800;
}

.section-subtitle {
    margin-top: 5px;
    color: var(--text-muted);
    font-size: 14px;
}

.section-count {
    flex: 0 0 auto;
    padding: 7px 11px;
    border-radius: 999px;
    background: #fff;
    border: 1px solid var(--line);
    color: #555;
    font-size: 13px;
}

.promo-header-box {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

.promo-countdown {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    border-radius: 14px;
    background: #fff7f7;
    border: 1px solid #ffd8d8;
    color: #bf1e2e;
    font-size: 13px;
    font-weight: 700;
}

.promo-time {
    display: inline-flex;
    gap: 6px;
}

.promo-time-box {
    min-width: 40px;
    padding: 6px 8px;
    border-radius: 10px;
    background: #fff;
    text-align: center;
    box-shadow: inset 0 0 0 1px #ffd3d3;
}

.promo-time-box strong {
    display: block;
    font-size: 14px;
    line-height: 1;
}

.promo-time-box span {
    display: block;
    margin-top: 2px;
    font-size: 10px;
    color: #8d8d8d;
}

/* =========================
   PRODUCT CARD
========================= */

.product-card-link {
    display: block;
    height: 100%;
    color: inherit;
    text-decoration: none;
}

.product-card {
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid var(--line);
    border-radius: 22px;
    background: var(--card-bg);
    box-shadow: 0 3px 12px rgba(0, 0, 0, .035);
    transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease;
}

.product-card:hover {
    transform: translateY(-5px);
    border-color: #d7d7d7;
    box-shadow: 0 14px 28px rgba(0, 0, 0, .10);
}

.product-image-wrap {
    position: relative;
    display: block;
    aspect-ratio: 1 / 1;
    padding: 6px 6px 0;
    overflow: hidden;
    background: linear-gradient(180deg, #fcfcfc 0%, #f4f4f4 100%);
}

.product-image {
    width: 100%;
    height: 100%;
    object-fit: contain;
    transition: transform .3s ease;
}

.product-card:hover .product-image {
    transform: scale(1.04);
}

.product-image-empty {
    display: grid;
    place-items: center;
    color: #bbb;
    font-size: 42px;
}

.discount-badge {
    position: absolute;
    z-index: 3;
    top: 10px;
    left: 0;
    min-width: 88px;
    padding: 9px 18px 10px 15px;
    background: linear-gradient(180deg, #ff2f3f 0%, #dc1524 100%);
    color: #fff;
    font-size: 13px;
    font-weight: 850;
    line-height: 1;
    letter-spacing: .1px;
    box-shadow: 0 7px 14px rgba(220, 21, 36, .24);
    clip-path: polygon(
        0 0,
        100% 0,
        88% 50%,
        100% 100%,
        0 100%
    );
}

.discount-badge::before {
    content: "";
    position: absolute;
    left: 0;
    bottom: -7px;
    width: 8px;
    height: 8px;
    background: #a80f1b;
    clip-path: polygon(0 0, 100% 0, 100% 100%);
}

.discount-badge span {
    position: relative;
    z-index: 1;
}

.product-body {
    display: flex;
    flex: 1;
    flex-direction: column;
    padding: 8px 14px 14px;
}

.product-name {
    display: -webkit-box;
    min-height: 48px;
    overflow: hidden;
    color: #1c1c1c;
    font-size: 15px;
    line-height: 1.45;
    font-weight: 800;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
}

.product-card:hover .product-name {
    color: var(--danger-dark);
}

.rating-row {
    display: flex;
    align-items: center;
    gap: 6px;
    min-height: 22px;
    margin-top: 6px;
    font-size: 12px;
}

.rating-score {
    color: #3f3f46;
    font-weight: 800;
}

.rating-stars {
    display: inline-block;
    color: var(--star);
    font-size: 13px;
    line-height: 1;
    letter-spacing: 1px;
    white-space: nowrap;
}

.rating-count {
    color: #9ca3af;
}

.price-block {
    margin-top: 8px;
    padding: 4px 0 7px;
}

.price-row {
    display: flex;
    align-items: baseline;
    flex-wrap: nowrap;
    gap: 7px;
    width: 100%;
    min-width: 0;
}

.current-price {
    flex: 0 1 auto;
    min-width: 0;
    color: var(--danger);
    font-size: clamp(15px, 1.45vw, 18px);
    line-height: 1.15;
    font-weight: 900;
    white-space: nowrap;
}

.old-price {
    flex: 0 0 auto;
    color: #a1a1aa;
    font-size: 10px;
    line-height: 1.2;
    text-decoration: line-through;
    white-space: nowrap;
}

.save-text {
    margin-top: 5px;
    padding-top: 2px;
    color: #d32f2f;
    font-size: 11px;
    line-height: 1.35;
    font-weight: 700;
}

.stock-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px dashed #e5e5e5;
    color: #6b7280;
    font-size: 12px;
}

.stock-row span {
    white-space: nowrap;
}

.stock-row i {
    margin-right: 3px;
    color: #999;
}

/* =========================
   TRẠNG THÁI HẾT HÀNG
   - Vẫn hiển thị sản phẩm để khách biết shop có mẫu này.
   - Báo rõ ngay trên card, không cần vào trang chi tiết mới biết.
========================= */

.product-card-out-of-stock {
    position: relative;
    border-color: #e3e5e8;
    background: #fbfbfc;
}

.product-card-link-out-of-stock .product-image {
    filter: saturate(.68);
    opacity: .72;
}

.product-card-link-out-of-stock:hover .product-card {
    border-color: #d6d9de;
    box-shadow: 0 10px 24px rgba(15, 23, 42, .07);
}

.out-of-stock-overlay {
    position: absolute;
    z-index: 5;
    inset: 0;
    pointer-events: none;
}

.out-of-stock-badge {
    position: absolute;
    top: 46px;
    left: 0;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-width: 112px;
    padding: 9px 18px 10px 14px;
    background: linear-gradient(180deg, #ff9d16 0%, #df7600 100%);
    color: #fff;
    font-size: 10px;
    line-height: 1;
    font-weight: 950;
    letter-spacing: .35px;
    box-shadow: 0 7px 14px rgba(223, 118, 0, .24);
    clip-path: polygon(
        0 0,
        100% 0,
        88% 50%,
        100% 100%,
        0 100%
    );
}

.out-of-stock-badge::before {
    content: "";
    position: absolute;
    left: 0;
    bottom: -7px;
    width: 8px;
    height: 8px;
    background: #a85a00;
    clip-path: polygon(0 0, 100% 0, 100% 100%);
}

.out-of-stock-badge i {
    position: relative;
    z-index: 1;
    font-size: 9px;
    color: #fff4d6;
}

.out-of-stock-badge {
    isolation: isolate;
}

.out-of-stock-badge > * {
    position: relative;
    z-index: 1;
}

.stock-row-out {
    align-items: stretch;
    gap: 10px;
}

.stock-out-main {
    min-width: 0;
    display: grid;
    gap: 3px;
}

.stock-out-title {
    display: flex;
    align-items: center;
    color: #c2410c;
    font-size: 11px;
    font-weight: 900;
    white-space: nowrap;
}

.stock-out-title i {
    color: #ea580c;
}

.stock-out-note {
    color: #8b929d;
    font-size: 9.5px;
    line-height: 1.3;
    white-space: normal !important;
}

.stock-out-sold {
    flex: 0 0 auto;
    align-self: center;
    color: #8a919d;
    font-size: 9.5px;
}

.stock-available {
    color: #198754;
    font-weight: 750;
}

.stock-available i {
    color: #198754;
}

@media (max-width: 767.98px) {
    .out-of-stock-badge {
        padding: 6px 9px;
        font-size: 8.5px;
    }

    .stock-row-out {
        display: grid;
        grid-template-columns: 1fr;
        gap: 5px;
    }

    .stock-out-sold {
        justify-self: start;
    }

    .stock-out-title {
        font-size: 10px;
    }

    .stock-out-note,
    .stock-out-sold {
        font-size: 8.5px;
    }
}

/* =========================
   EMPTY
========================= */

.empty-state {
    padding: 45px 20px;
    border: 1px dashed #d8d8d8;
    border-radius: 16px;
    background: #fff;
    text-align: center;
    color: #777;
}

.empty-state i {
    display: block;
    margin-bottom: 10px;
    font-size: 32px;
    color: #bbb;
}

/* =========================
   MOBILE
========================= */

.mobile-filter-button {
    display: none;
}

@media (max-width: 991.98px) {
    .desktop-filter {
        display: none;
    }

    .mobile-filter-button {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        min-height: 46px;
        margin-bottom: 16px;
        padding: 0 14px;
        border: 1px solid var(--line);
        border-radius: 12px;
        background: #fff;
        font-weight: 700;
    }

    .filter-card {
        position: static;
    }
}

@media (max-width: 767.98px) {
    .hero-shell {
        margin-top: 14px;
    }

    .hero-carousel {
        border-radius: 16px;
    }

    .hero-slide {
        height: 265px;
    }

    .hero-slide::after {
        background: linear-gradient(90deg, rgba(0, 0, 0, .76), rgba(0, 0, 0, .25));
    }

    .hero-content {
        left: 22px;
        right: 66px;
    }

    .hero-eyebrow {
        font-size: 11px;
    }

    .hero-title {
        margin-bottom: 8px;
        font-size: 24px;
    }

    .hero-sale-note {
        font-size: 11px;
    }

    .hero-carousel .carousel-control-prev,
    .hero-carousel .carousel-control-next {
        width: 38px;
        height: 38px;
        margin: 0 8px;
    }

    .section-head {
        align-items: flex-start;
        flex-direction: column;
    }

    .promo-header-box {
        align-items: flex-start;
        flex-direction: column;
    }

    .product-body {
        padding: 6px 10px 12px;
    }

    .product-name {
        min-height: 42px;
        font-size: 13px;
    }

    .current-price {
        font-size: 14px;
    }

    .old-price {
        font-size: 9px;
    }

    .rating-stars {
        font-size: 11px;
        letter-spacing: .5px;
    }

    .save-text {
        display: block;
    }

    .stock-row {
        gap: 6px;
        font-size: 10px;
    }

    .discount-badge {
        top: 8px;
        min-width: 76px;
        padding: 8px 15px 9px 12px;
        font-size: 11px;
    }
}

@media (max-width: 420px) {
    .stock-row {
        align-items: center;
        flex-direction: row;
        justify-content: space-between;
        flex-wrap: wrap;
    }
}

.filter-title-icon {
    width: 34px;
    height: 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 34px;
    border-radius: 10px;
    background: #fff1f2;
    color: #d70018;
    font-size: 17px;
    font-weight: 900;
    line-height: 1;
}

.filter-label-icon {
    width: 20px;
    height: 20px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 20px;
    border-radius: 6px;
    background: #f4f6f8;
    color: #687386;
    font-size: 11px;
    font-weight: 800;
    line-height: 1;
}

.filter-title i,
.filter-label i {
    display: none !important;
}


/* =========================
   SLIDER ẢNH THUẦN
========================= */
.hero-carousel {
    overflow: hidden;
    border-radius: 22px;
    background: #fff;
    box-shadow: 0 10px 28px rgba(0, 0, 0, .08);
}

.hero-slide-image-only {
    height: clamp(220px, 32vw, 410px);
    background: #f4f5f7;
}

.hero-slide-image-only::after {
    display: none !important;
}

.hero-slide-image-only img {
    width: 100%;
    height: 100%;
    display: block;
    object-fit: cover;
    opacity: 1 !important;
    transform: none !important;
}

.hero-content,
.hero-eyebrow,
.hero-title,
.hero-price,
.hero-sale-note,
.hero-button {
    display: none !important;
}

.hero-carousel .carousel-control-prev,
.hero-carousel .carousel-control-next {
    top: 50%;
    bottom: auto;
    width: 44px;
    height: 44px;
    margin: 0 12px;
    transform: translateY(-50%);
    border-radius: 50%;
    background: rgba(255, 255, 255, .92);
    opacity: .88;
    box-shadow: 0 5px 16px rgba(0, 0, 0, .15);
    transition: .18s ease;
}

.hero-carousel .carousel-control-prev:hover,
.hero-carousel .carousel-control-next:hover {
    opacity: 1;
    transform: translateY(-50%) scale(1.05);
}

.hero-carousel .carousel-control-prev-icon,
.hero-carousel .carousel-control-next-icon {
    width: 18px;
    height: 18px;
    filter: invert(1);
}

.hero-carousel .carousel-indicators {
    gap: 6px;
    margin-bottom: 12px;
}

.hero-carousel .carousel-indicators [data-bs-target] {
    width: 8px;
    height: 8px;
    margin: 0;
    border: 0;
    border-radius: 999px;
    background: #fff;
    opacity: .6;
    transition: .2s ease;
}

.hero-carousel .carousel-indicators .active {
    width: 24px;
    opacity: 1;
}

@media (max-width: 767.98px) {
    .hero-slide-image-only {
        height: 220px;
    }

    .hero-carousel {
        border-radius: 16px;
    }

    .hero-carousel .carousel-control-prev,
    .hero-carousel .carousel-control-next {
        width: 36px;
        height: 36px;
        margin: 0 8px;
    }
}


/* =========================
   FILTER - GỌN & HIỆN ĐẠI
========================= */
.filter-card {
    position: sticky;
    top: 92px;
    padding: 18px;
    border: 1px solid #e8eaf0;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 8px 24px rgba(15, 23, 42, .055);
}

.filter-title {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
    padding-bottom: 14px;
    border-bottom: 1px solid #eef0f3;
    color: #1f2937;
    font-size: 17px;
    font-weight: 850;
}

.filter-title-icon {
    width: 32px;
    height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 32px;
    border-radius: 9px;
    background: #fff1f2;
    color: #d70018;
    font-size: 15px;
    font-weight: 900;
}

.filter-group + .filter-group {
    margin-top: 13px;
}

.filter-label {
    display: flex;
    align-items: center;
    gap: 7px;
    margin: 0 0 7px;
    color: #596273;
    font-size: 12.5px;
    line-height: 1;
    font-weight: 750;
}

.filter-label-icon {
    width: 19px;
    height: 19px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 19px;
    border-radius: 6px;
    background: #f5f6f8;
    color: #697386;
    font-size: 10px;
    font-weight: 850;
}

.filter-card .form-control,
.filter-card .form-select {
    min-height: 44px;
    border: 1px solid #e2e5ea;
    border-radius: 12px;
    background-color: #fff;
    color: #2b313b;
    font-size: 13.5px;
    box-shadow: none;
    transition:
        border-color .18s ease,
        box-shadow .18s ease,
        background .18s ease;
}

.filter-card .form-control:hover,
.filter-card .form-select:hover {
    border-color: #cfd4dc;
}

.filter-card .form-control:focus,
.filter-card .form-select:focus {
    border-color: #ef9aa5;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(215, 0, 24, .06);
}

.filter-card .form-control::placeholder {
    color: #b2b8c1;
}

.filter-actions {
    display: grid;
    grid-template-columns: 1fr;
    gap: 9px;
    margin-top: 18px;
    padding-top: 15px;
    border-top: 1px solid #eef0f3;
}

.btn-filter {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 46px;
    border: 0;
    border-radius: 12px;
    background: #d70018;
    color: #fff;
    font-size: 13.5px;
    font-weight: 800;
    box-shadow: 0 7px 16px rgba(215, 0, 24, .16);
    transition: .18s ease;
}

.btn-filter:hover {
    transform: translateY(-1px);
    background: #c70016;
    color: #fff;
}

.btn-filter i {
    color: #fff;
}

.btn-reset {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 41px;
    border: 1px solid #e2e5ea;
    border-radius: 11px;
    background: #fff;
    color: #646d79;
    font-size: 12.5px;
    font-weight: 700;
    text-decoration: none;
    transition: .18s ease;
}

.btn-reset:hover {
    border-color: #cfd4da;
    background: #f8f9fb;
    color: #30363d;
}

.mobile-filter-button {
    border: 1px solid #e2e5ea !important;
    border-radius: 13px !important;
    background: #fff !important;
    box-shadow: 0 5px 16px rgba(15, 23, 42, .04);
}

@media (max-width: 991.98px) {
    .filter-card {
        position: static;
        border-radius: 15px;
        box-shadow: 0 6px 18px rgba(15, 23, 42, .04);
    }
}


/* =========================================================
   HERO HOME 75% + TRỢ LÝ AI 25%
========================================================= */
.home-hero-grid {
    display: grid;
    grid-template-columns: minmax(0, 3fr) minmax(245px, 1fr);
    gap: 16px;
    align-items: stretch;
    margin-top: 22px;
}

.home-hero-grid .hero-shell {
    min-width: 0;
    margin-top: 0;
}

.home-hero-grid .hero-carousel,
.home-hero-grid .carousel-inner,
.home-hero-grid .carousel-item,
.home-hero-grid .hero-slide-image-only {
    height: 315px;
}

.home-hero-grid .hero-carousel {
    border-radius: 20px;
}

.home-hero-grid .hero-slide-image-only img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.home-support-panel {
    min-width: 0;
    height: 315px;
    display: grid;
    grid-template-rows: 1.08fr .92fr;
    gap: 12px;
}

.home-ai-card,
.home-utility-card {
    border: 1px solid #e8eaf0;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 8px 24px rgba(15, 23, 42, .055);
}

.home-ai-card {
    overflow: hidden;
}

.home-utility-card {
    min-height: 0;
    overflow-y: auto;
    overflow-x: hidden;
    overscroll-behavior: contain;
    scrollbar-width: thin;
    scrollbar-color: #d7dbe2 transparent;
}

.home-utility-card::-webkit-scrollbar {
    width: 5px;
}

.home-utility-card::-webkit-scrollbar-track {
    background: transparent;
}

.home-utility-card::-webkit-scrollbar-thumb {
    background: #d7dbe2;
    border-radius: 999px;
}

.home-utility-card::-webkit-scrollbar-thumb:hover {
    background: #b9bec7;
}

.home-ai-card {
    position: relative;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 17px;
    background:
        radial-gradient(
            circle at 100% 0,
            rgba(215, 0, 24, .10),
            transparent 42%
        ),
        linear-gradient(
            145deg,
            #ffffff 0%,
            #fff8f9 100%
        );
}

.home-ai-card::after {
    content: "AI";
    position: absolute;
    right: 12px;
    bottom: -25px;
    color: rgba(215, 0, 24, .045);
    font-size: 92px;
    line-height: 1;
    font-weight: 950;
    pointer-events: none;
}

.home-ai-top,
.home-ai-actions {
    position: relative;
    z-index: 1;
}

.home-ai-heading {
    display: flex;
    align-items: center;
    gap: 10px;
}

.home-ai-avatar {
    position: relative;
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    flex: 0 0 42px;
    border-radius: 13px;
    background: linear-gradient(135deg, #d70018, #f13d51);
    color: #fff;
    font-size: 20px;
    box-shadow: 0 7px 16px rgba(215, 0, 24, .20);
}

.home-ai-avatar::after {
    content: "";
    position: absolute;
    right: -1px;
    bottom: -1px;
    width: 10px;
    height: 10px;
    border: 2px solid #fff;
    border-radius: 50%;
    background: #22c55e;
}

.home-ai-title {
    color: #20242c;
    font-size: 14px;
    line-height: 1.25;
    font-weight: 900;
}

.home-ai-status {
    margin-top: 2px;
    color: #8a919d;
    font-size: 10.5px;
}

.home-ai-copy {
    margin: 12px 0 0;
    color: #586170;
    font-size: 11.5px;
    line-height: 1.55;
}

.home-ai-button {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    min-height: 38px;
    padding: 8px 12px;
    border-radius: 11px;
    background: #d70018;
    color: #fff;
    font-size: 11.5px;
    font-weight: 850;
    text-decoration: none;
    transition: .18s ease;
}

.home-ai-button:hover {
    transform: translateY(-1px);
    background: #c90017;
    color: #fff;
    box-shadow: 0 8px 18px rgba(215, 0, 24, .18);
}

.home-ai-hint {
    margin-top: 7px;
    color: #9aa0aa;
    font-size: 9.5px;
    text-align: center;
}

.home-utility-card {
    padding: 12px;
}

.home-utility-title {
    margin-bottom: 8px;
    color: #737b87;
    font-size: 10px;
    font-weight: 850;
    letter-spacing: .35px;
    text-transform: uppercase;
}

.home-utility-list {
    display: grid;
    gap: 5px;
}

.home-utility-link {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 7px;
    min-height: 32px;
    padding: 7px 8px;
    border-radius: 10px;
    color: #3f4753;
    font-size: 10.8px;
    font-weight: 700;
    text-decoration: none;
    transition: .16s ease;
}

.home-utility-link:hover {
    background: #f7f8fa;
    color: #d70018;
}

.home-utility-left {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
}

.home-utility-icon {
    width: 24px;
    height: 24px;
    display: grid;
    place-items: center;
    flex: 0 0 24px;
    border-radius: 8px;
    background: #fff0f2;
    color: #d70018;
    font-size: 11px;
}

.home-utility-arrow {
    color: #b2b7c0;
    font-size: 10px;
}

@media (max-width: 1199.98px) {
    .home-hero-grid {
        grid-template-columns: minmax(0, 2.6fr) minmax(225px, 1fr);
    }

    .home-hero-grid .hero-carousel,
    .home-hero-grid .carousel-inner,
    .home-hero-grid .carousel-item,
    .home-hero-grid .hero-slide-image-only,
    .home-support-panel {
        height: 300px;
    }

    .home-ai-copy {
        font-size: 11px;
    }
}

@media (max-width: 991.98px) {
    .home-hero-grid {
        grid-template-columns: 1fr;
        gap: 12px;
        margin-top: 16px;
    }

    .home-hero-grid .hero-carousel,
    .home-hero-grid .carousel-inner,
    .home-hero-grid .carousel-item,
    .home-hero-grid .hero-slide-image-only {
        height: 285px;
    }

    .home-support-panel {
        height: auto;
        grid-template-columns: 1fr 1fr;
        grid-template-rows: none;
    }

    .home-ai-card,
    .home-utility-card {
        min-height: 175px;
    }
}

@media (max-width: 767.98px) {
    .home-hero-grid {
        margin-top: 12px;
    }

    .home-hero-grid .hero-carousel,
    .home-hero-grid .carousel-inner,
    .home-hero-grid .carousel-item,
    .home-hero-grid .hero-slide-image-only {
        height: 210px;
    }

    .home-hero-grid .hero-carousel {
        border-radius: 15px;
    }

    .home-support-panel {
        grid-template-columns: 1fr;
    }

    .home-ai-card,
    .home-utility-card {
        min-height: 0;
    }

    .home-ai-card {
        padding: 15px;
    }

    .home-utility-card {
        padding: 11px;
    }
}



/* =========================================================
   HOME PROMO UI 2026 - BỔ SUNG
========================================================= */

/* --- Cột tiện ích cạnh slider --- */
.home-support-panel {
    grid-template-rows: .9fr 1.1fr;
}

.home-ai-card-compact {
    padding: 15px 16px;
}

.home-quick-card {
    min-height: 0;
    padding: 13px;
    border: 1px solid #e8eaf0;
    border-radius: 18px;
    background:
        linear-gradient(180deg, #fff 0%, #fbfcff 100%);
    box-shadow: 0 8px 24px rgba(15, 23, 42, .055);
}

.home-quick-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 10px;
}

.home-quick-kicker {
    color: #9aa1ad;
    font-size: 8.5px;
    line-height: 1;
    font-weight: 900;
    letter-spacing: .8px;
}

.home-quick-title {
    margin-top: 4px;
    color: #20242c;
    font-size: 13px;
    line-height: 1.1;
    font-weight: 900;
}

.home-quick-spark {
    width: 30px;
    height: 30px;
    display: grid;
    place-items: center;
    flex: 0 0 30px;
    border-radius: 10px;
    background: #fff2f3;
    font-size: 14px;
}

.home-quick-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 7px;
}

.home-quick-link {
    min-width: 0;
    min-height: 54px;
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 8px;
    border: 1px solid #edf0f3;
    border-radius: 12px;
    background: #fff;
    color: #414955;
    text-decoration: none;
    transition: .16s ease;
}

.home-quick-link:hover {
    transform: translateY(-1px);
    border-color: #f1b3bb;
    color: #d70018;
    box-shadow: 0 6px 14px rgba(15, 23, 42, .05);
}

.home-quick-link-sale {
    border-color: #ffd7dc;
    background: #fff6f7;
    color: #cf1024;
}

.home-quick-icon {
    width: 28px;
    height: 28px;
    display: grid;
    place-items: center;
    flex: 0 0 28px;
    border-radius: 9px;
    background: #f6f7f9;
    font-size: 13px;
}

.home-quick-link-sale .home-quick-icon {
    background: #ffe8eb;
}

.home-quick-label {
    min-width: 0;
    overflow: hidden;
    font-size: 10.5px;
    line-height: 1.2;
    font-weight: 800;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* --- Tiêu đề section --- */
.section-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 7px;
    padding: 5px 9px;
    border-radius: 999px;
    background: #fff0f2;
    color: #d70018;
    font-size: 10px;
    line-height: 1;
    font-weight: 900;
    letter-spacing: .25px;
    text-transform: uppercase;
}

.section-eyebrow-neutral {
    background: #f0f2f5;
    color: #596273;
}

.section-count-sale {
    border-color: #ffd6dc;
    background: #fff6f7;
    color: #cf1024;
}

/* --- Danh sách chương trình sale --- */
.sale-programs-section {
    scroll-margin-top: 105px;
}

.sale-programs-head {
    align-items: center;
}

.sale-program-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.sale-program-card {
    min-width: 0;
    padding: 15px;
    border: 1px solid #e8eaf0;
    border-radius: 18px;
    background:
        radial-gradient(circle at 100% 0, rgba(215,0,24,.045), transparent 38%),
        #fff;
    box-shadow: 0 7px 20px rgba(15,23,42,.045);
}

.sale-program-card-flash {
    border-color: #ffd3d9;
    background:
        radial-gradient(circle at 100% 0, rgba(255,213,76,.16), transparent 38%),
        linear-gradient(145deg, #fff 0%, #fff6f7 100%);
}

.sale-program-top,
.sale-program-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.sale-program-type,
.sale-program-value {
    display: inline-flex;
    align-items: center;
    min-width: 0;
    padding: 5px 8px;
    border-radius: 999px;
    font-size: 9.5px;
    line-height: 1;
    font-weight: 850;
}

.sale-program-type {
    background: #f5f6f8;
    color: #555e6b;
}

.sale-program-card-flash .sale-program-type {
    background: #ffe9ec;
    color: #d70018;
}

.sale-program-value {
    flex: 0 0 auto;
    background: #ecfdf3;
    color: #198754;
}

.sale-program-name {
    margin-top: 11px;
    overflow: hidden;
    color: #20242c;
    font-size: 14px;
    line-height: 1.35;
    font-weight: 900;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.sale-program-time {
    display: grid;
    gap: 4px;
    margin-top: 9px;
    color: #777f8b;
    font-size: 10.5px;
}

.sale-program-time span {
    display: flex;
    align-items: center;
    gap: 6px;
    min-width: 0;
}

.sale-program-time i {
    width: 14px;
    color: #a4a9b1;
}

.sale-program-bottom {
    margin-top: 12px;
    padding-top: 10px;
    border-top: 1px dashed #e5e8ec;
    color: #7b828c;
    font-size: 9.5px;
}

.sale-program-mini-countdown {
    flex: 0 0 auto;
    color: #d70018;
    font-weight: 800;
}

/* --- Flash sale nổi bật --- */
.flash-sale-section {
    overflow: hidden;
    border: 1px solid #2a2a2a;
    border-radius: 24px;
    background: #171717;
    box-shadow: 0 15px 35px rgba(0,0,0,.12);
}

.flash-sale-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    padding: 17px 18px;
    background:
        radial-gradient(circle at 15% 0, rgba(255,59,76,.34), transparent 32%),
        linear-gradient(110deg, #161616 0%, #242424 55%, #1b1b1b 100%);
    color: #fff;
}

.flash-sale-brand {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.flash-sale-bolt {
    width: 45px;
    height: 45px;
    display: grid;
    place-items: center;
    flex: 0 0 45px;
    border-radius: 14px;
    background: linear-gradient(135deg, #ffcc2e, #ff7b00);
    box-shadow: 0 8px 20px rgba(255,140,0,.24);
    font-size: 22px;
}

.flash-sale-kicker {
    color: #ffd54f;
    font-size: 10px;
    line-height: 1;
    font-weight: 950;
    letter-spacing: 1.5px;
}

.flash-sale-title {
    margin: 4px 0 0;
    color: #fff;
    font-size: 25px;
    line-height: 1.05;
    font-weight: 950;
}

.flash-sale-subtitle {
    margin-top: 4px;
    color: rgba(255,255,255,.66);
    font-size: 10.5px;
}

.flash-sale-timer-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 0 0 auto;
}

.flash-sale-timer-label {
    color: rgba(255,255,255,.72);
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .45px;
}

.flash-sale-timer {
    display: flex;
    align-items: center;
    gap: 5px;
}

.flash-time-box {
    min-width: 48px;
    padding: 7px 7px 6px;
    border: 1px solid rgba(255,255,255,.14);
    border-radius: 11px;
    background: rgba(255,255,255,.09);
    text-align: center;
    backdrop-filter: blur(8px);
}

.flash-time-box strong {
    display: block;
    color: #fff;
    font-size: 17px;
    line-height: 1;
    font-weight: 950;
    font-variant-numeric: tabular-nums;
}

.flash-time-box span {
    display: block;
    margin-top: 3px;
    color: rgba(255,255,255,.55);
    font-size: 8px;
    line-height: 1;
    text-transform: uppercase;
}

.flash-time-separator {
    color: #ffcc2e;
    font-size: 18px;
    line-height: 1;
    font-weight: 900;
}

.flash-sale-body {
    padding: 15px;
    background: #f8f8f8;
}

.flash-sale-body .product-card {
    border-color: #e1e1e1;
}

/* --- Product card promo --- */
.product-card {
    min-width: 0;
}

.product-card-flash {
    border-color: #ffd3d9;
}

.flash-mini-badge {
    position: absolute;
    z-index: 3;
    top: 10px;
    right: 9px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 5px 7px;
    border-radius: 8px;
    background: rgba(22,22,22,.9);
    color: #ffd84d;
    font-size: 8.5px;
    line-height: 1;
    font-weight: 950;
    letter-spacing: .35px;
    box-shadow: 0 5px 12px rgba(0,0,0,.14);
}

.product-name {
    min-height: 43px;
    max-height: 43px;
    line-height: 1.42;
    -webkit-line-clamp: 2;
}

.price-block {
    min-width: 0;
}

.price-row {
    display: flex;
    align-items: baseline;
    flex-wrap: nowrap !important;
    gap: 7px;
    min-width: 0;
    width: 100%;
    overflow-x: auto;
    overflow-y: hidden;
    white-space: nowrap;
    scrollbar-width: none;
}

.price-row::-webkit-scrollbar {
    display: none;
}

.current-price {
    flex: 0 0 auto;
    min-width: 0;
    color: var(--danger);
    font-size: clamp(14px, 1.25vw, 17px);
    line-height: 1.15;
    font-weight: 900;
    white-space: nowrap;
}

.old-price {
    flex: 0 0 auto;
    min-width: 0;
    color: #9ca3af;
    font-size: 10px;
    line-height: 1.2;
    text-decoration: line-through;
    white-space: nowrap;
}

.promotion-summary {
    display: flex;
    align-items: center;
    gap: 6px;
    min-width: 0;
    margin-top: 7px;
}

.promotion-type-pill {
    flex: 0 1 auto;
    max-width: 48%;
    overflow: hidden;
    padding: 4px 7px;
    border-radius: 999px;
    background: #fff0f2;
    color: #d70018;
    font-size: 9px;
    line-height: 1;
    font-weight: 850;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.promotion-summary .save-text {
    flex: 1 1 auto;
    min-width: 0;
    margin: 0;
    padding: 0;
    overflow: hidden;
    color: #198754;
    font-size: 9.5px;
    line-height: 1.2;
    font-weight: 850;
    text-align: right;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.promotion-name {
    margin-top: 6px;
    overflow: hidden;
    color: #676f7b;
    font-size: 9.5px;
    line-height: 1.25;
    font-weight: 700;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.product-flash-countdown {
    display: flex;
    align-items: center;
    gap: 5px;
    margin-top: 6px;
    color: #d70018;
    font-size: 9.5px;
    line-height: 1;
    font-weight: 750;
}

.product-flash-countdown strong {
    font-variant-numeric: tabular-nums;
}

.regular-price-note {
    margin-top: 7px;
    color: #9aa1aa;
    font-size: 9.5px;
}

.stock-row {
    margin-top: auto;
}

/* --- Mobile --- */
@media (max-width: 991.98px) {
    .home-support-panel {
        grid-template-columns: 1fr 1fr;
        grid-template-rows: none;
    }

    .sale-program-grid {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 767.98px) {
    .home-support-panel {
        grid-template-columns: 1fr;
    }

    .home-quick-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .home-quick-link {
        min-height: 62px;
        flex-direction: column;
        justify-content: center;
        gap: 5px;
        padding: 7px 4px;
    }

    .home-quick-label {
        width: 100%;
        font-size: 9.5px;
        text-align: center;
    }

    .sale-program-grid {
        grid-template-columns: 1fr;
    }

    .flash-sale-head {
        align-items: flex-start;
        flex-direction: column;
        padding: 15px;
    }

    .flash-sale-title {
        font-size: 21px;
    }

    .flash-sale-timer-wrap {
        width: 100%;
        justify-content: space-between;
    }

    .flash-time-box {
        min-width: 43px;
    }

    .flash-sale-body {
        padding: 10px;
    }

    .current-price {
        max-width: 66%;
        font-size: 13px;
    }

    .old-price {
        max-width: 34%;
        font-size: 8.5px;
    }

    .promotion-summary {
        gap: 4px;
    }

    .promotion-type-pill,
    .promotion-summary .save-text,
    .promotion-name,
    .product-flash-countdown {
        font-size: 8.5px;
    }

    .promotion-summary .save-text {
        display: block;
    }
}

@media (max-width: 420px) {
    .home-quick-grid {
        gap: 5px;
    }

    .home-quick-link {
        min-height: 58px;
    }

    .product-body {
        padding-left: 8px;
        padding-right: 8px;
    }

    .price-row {
        gap: 4px;
    }

    .current-price {
        font-size: 12px;
    }

    .old-price {
        font-size: 8px;
    }
}


/* =========================================================
   FIX 24/10/2026
   - Không để khối AI / Truy cập nhanh bị khuất.
   - Desktop tăng chiều cao hero đồng bộ 2 cột.
========================================================= */
.sale-program-daily-window {
    color: #9a6200;
    font-weight: 850;
}

@media (min-width: 992px) {
    .home-hero-grid .hero-carousel,
    .home-hero-grid .carousel-inner,
    .home-hero-grid .carousel-item,
    .home-hero-grid .hero-slide-image-only,
    .home-support-panel {
        height: 360px;
    }

    .home-support-panel {
        grid-template-rows: minmax(158px, .92fr) minmax(178px, 1.08fr);
        gap: 12px;
    }

    .home-ai-card-compact {
        min-height: 0;
        padding: 14px 16px;
        overflow: hidden;
    }

    .home-ai-copy {
        margin: 9px 0 8px;
        line-height: 1.45;
    }

    .home-ai-actions {
        margin-top: 8px;
    }

    .home-ai-button {
        min-height: 36px;
        padding: 7px 12px;
    }

    .home-quick-card {
        min-height: 0;
        overflow: hidden;
        padding: 12px;
    }

    .home-quick-head {
        margin-bottom: 8px;
    }

    .home-quick-link {
        min-height: 52px;
    }
}


/* FIX hiển thị nhãn khuyến mãi trên card */
.product-card .promotion-summary {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px 8px;
    min-width: 0;
}

.product-card .promotion-type-pill,
.product-card .save-text {
    max-width: 100%;
    overflow: visible;
    text-overflow: clip;
    white-space: normal;
}

.product-card .promotion-type-pill {
    flex: 0 0 auto;
}

.product-card .save-text {
    flex: 1 1 120px;
    margin-top: 0;
}

.product-card .promotion-name {
    white-space: normal;
    overflow: visible;
    text-overflow: clip;
}

@media (max-width: 575.98px) {
    .product-card .promotion-summary {
        display: grid;
        grid-template-columns: 1fr;
        gap: 4px;
    }

    .product-card .save-text {
        flex-basis: auto;
    }
}


/* =========================================================
   CARD PRODUCT UI - CÂN LẠI FLASH SALE
   Chỉ chỉnh giao diện, không thay đổi logic giá / khuyến mãi.
========================================================= */

.flash-sale-body .col-6,
.promotion-products-section .col-6,
.normal-products-section .col-6 {
    display: flex;
}

.product-card-link {
    width: 100%;
}

.product-card {
    width: 100%;
    min-height: 100%;
}

.product-body {
    gap: 0;
    padding: 12px 14px 14px;
}

.product-name {
    min-height: 46px;
    max-height: none;
    margin-bottom: 4px;
    font-size: 15px;
    line-height: 1.42;
}

.rating-row {
    margin-top: 4px;
}

.price-block {
    margin-top: 9px;
    padding-bottom: 4px;
}

.price-row {
    overflow: visible;
    flex-wrap: wrap !important;
    row-gap: 3px;
}

.current-price {
    max-width: none;
    font-size: 17px;
}

.old-price {
    max-width: none;
    font-size: 10px;
}

.product-card .promotion-summary {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    align-items: center;
    gap: 7px;
    margin-top: 8px;
}

.product-card .promotion-type-pill {
    max-width: none;
    padding: 5px 9px;
    overflow: visible;
    font-size: 9px;
    line-height: 1;
    white-space: nowrap;
    text-overflow: clip;
}

.product-card .promotion-summary .save-text {
    min-width: 0;
    margin: 0;
    padding: 0;
    overflow: visible;
    color: #14804a;
    font-size: 9.5px;
    line-height: 1.25;
    font-weight: 850;
    text-align: right;
    white-space: normal;
    text-overflow: clip;
}

.product-card .promotion-name {
    margin-top: 7px;
    overflow: visible;
    color: #667085;
    font-size: 9.5px;
    line-height: 1.3;
    font-weight: 750;
    white-space: normal;
    text-overflow: clip;
}

.product-flash-countdown {
    margin-top: 6px;
    padding-bottom: 3px;
    font-size: 9.5px;
}

.stock-row {
    margin-top: 10px;
    padding-top: 10px;
}

@media (max-width: 1199.98px) {
    .product-card .promotion-summary {
        grid-template-columns: 1fr;
        gap: 5px;
    }

    .product-card .promotion-summary .save-text {
        text-align: left;
    }
}

@media (max-width: 767.98px) {
    .product-body {
        padding: 10px 11px 12px;
    }

    .product-name {
        min-height: 40px;
        font-size: 13px;
    }

    .current-price {
        font-size: 14px;
    }

    .product-card .promotion-summary {
        grid-template-columns: 1fr;
    }

    .product-card .promotion-summary .save-text {
        font-size: 8.5px;
    }
}


/* =========================================================
   XẢ KHO - NHÓM KHUYẾN MÃI RIÊNG
========================================================= */
.clearance-section {
    overflow: hidden;
    border: 1px solid #f2c780;
    border-radius: 24px;
    background: #fffaf1;
    box-shadow: 0 12px 30px rgba(176, 103, 0, .08);
}

.clearance-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 17px 18px;
    border-bottom: 1px solid #f4d7aa;
    background:
        radial-gradient(circle at 0 0, rgba(255, 184, 77, .23), transparent 34%),
        linear-gradient(135deg, #fffaf1 0%, #fff3dc 100%);
}

.clearance-brand {
    display: flex;
    align-items: center;
    gap: 12px;
}

.clearance-icon {
    width: 46px;
    height: 46px;
    display: grid;
    place-items: center;
    flex: 0 0 46px;
    border-radius: 14px;
    background: linear-gradient(135deg, #ffad22, #ed7d00);
    color: #fff;
    font-size: 22px;
}

.clearance-kicker {
    color: #af6500;
    font-size: 10px;
    font-weight: 950;
    letter-spacing: 1.2px;
}

.clearance-title {
    margin: 4px 0 0;
    color: #7d4800;
    font-size: 24px;
    line-height: 1.05;
    font-weight: 950;
}

.clearance-subtitle {
    margin-top: 5px;
    color: #887257;
    font-size: 10.5px;
}

.clearance-count {
    padding: 7px 11px;
    border: 1px solid #efc886;
    border-radius: 999px;
    background: #fff;
    color: #9b5900;
    font-size: 11px;
    font-weight: 850;
}

.clearance-body {
    padding: 15px;
}

.product-card-clearance {
    border-color: #efc786;
}

.clearance-mini-badge {
    position: absolute;
    z-index: 3;
    top: 10px;
    right: 9px;
    padding: 5px 7px;
    border-radius: 8px;
    background: linear-gradient(135deg, #ff9d16, #df7600);
    color: #fff;
    font-size: 8.5px;
    font-weight: 950;
}

@media (max-width: 767.98px) {
    .clearance-head {
        align-items: flex-start;
        flex-direction: column;
        padding: 15px;
    }

    .clearance-title {
        font-size: 21px;
    }

    .clearance-body {
        padding: 10px;
    }
}


/* =========================================================
   FIX GIÁ CARD - GIỮ GIÁ SALE + GIÁ GỐC CÙNG MỘT HÀNG
========================================================= */
.product-card .price-row {
    display: flex;
    align-items: baseline;
    flex-wrap: nowrap !important;
    gap: 6px;
    overflow: visible;
    white-space: nowrap;
}

.product-card .current-price {
    flex: 0 0 auto;
    max-width: none;
    font-size: 15px;
    line-height: 1.1;
    white-space: nowrap;
}

.product-card .old-price {
    flex: 0 0 auto;
    max-width: none;
    font-size: 8.5px;
    line-height: 1.1;
    white-space: nowrap;
}

@media (max-width: 1199.98px) {
    .product-card .current-price {
        font-size: 14px;
    }

    .product-card .old-price {
        font-size: 8px;
    }
}

@media (max-width: 767.98px) {
    .product-card .price-row {
        gap: 4px;
    }

    .product-card .current-price {
        font-size: 12.5px;
    }

    .product-card .old-price {
        font-size: 7.5px;
    }
}


/* =========================================================
   XẢ KHO TRONG DANH SÁCH CHƯƠNG TRÌNH
========================================================= */
.sale-program-card-clearance {
    border-color: #f2c987;
    background:
        radial-gradient(circle at 100% 0, rgba(255,173,34,.16), transparent 38%),
        linear-gradient(145deg, #fff 0%, #fffaf0 100%);
}

.sale-program-card-clearance .sale-program-type {
    background: #fff1d9;
    color: #a65f00;
}


/* =========================================================
   THỜI GIAN XẢ KHO - HIỂN THỊ NGANG, RÕ NGÀY GIỜ
========================================================= */
.clearance-time-bar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 9px;
}

.clearance-time-label {
    padding: 5px 9px;
    border-radius: 999px;
    background: rgba(255, 173, 34, .14);
    color: #a66200;
    font-size: 9px;
    font-weight: 950;
    letter-spacing: .75px;
    text-transform: uppercase;
    white-space: nowrap;
}

.clearance-time-box {
    min-width: 154px;
    padding: 8px 11px;
    border: 1px solid #efcb92;
    border-radius: 12px;
    background: rgba(255,255,255,.78);
    box-shadow: inset 0 1px 0 rgba(255,255,255,.7);
}

.clearance-time-box small {
    display: block;
    margin-bottom: 3px;
    color: #a97a35;
    font-size: 8.5px;
    line-height: 1;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .55px;
}

.clearance-time-box strong {
    display: block;
    color: #7d4800;
    font-size: 11px;
    line-height: 1.25;
    font-weight: 900;
}

.clearance-time-sep {
    color: #c47a16;
    font-size: 15px;
    line-height: 1;
    font-weight: 950;
}

@media (max-width: 991.98px) {
    .clearance-time-box {
        min-width: 142px;
    }

    .clearance-time-box strong {
        font-size: 10.5px;
    }
}

@media (max-width: 767.98px) {
    .clearance-time-bar {
        gap: 6px;
        margin-top: 8px;
    }

    .clearance-time-label {
        font-size: 8.5px;
        padding: 4px 8px;
    }

    .clearance-time-box {
        min-width: calc(50% - 18px);
        flex: 1 1 145px;
        padding: 8px 10px;
    }

    .clearance-time-box strong {
        font-size: 10px;
    }

    .clearance-time-sep {
        display: none;
    }
}


/* =========================================================
   PROMOTION NAV COMPACT + PRODUCT PAGINATION 2026
   - Gom chương trình theo loại
   - Card nhỏ, bấm để nhảy tới khu tương ứng
   - Phân trang sản phẩm đẹp, tối đa 8 sản phẩm / trang
========================================================= */

.sale-programs-section {
    scroll-margin-top: 110px;
}

.sale-programs-head {
    margin-bottom: 12px;
}

.sale-programs-section .section-title {
    font-size: clamp(20px, 2vw, 27px);
}

.sale-programs-section .section-subtitle {
    max-width: 680px;
    font-size: 12.5px;
}

.sale-program-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
}

.sale-program-card {
    position: relative;
    display: block;
    min-height: 118px;
    padding: 13px 14px;
    overflow: hidden;
    color: inherit;
    text-decoration: none;
    cursor: pointer;
    transition:
        transform .18s ease,
        box-shadow .18s ease,
        border-color .18s ease;
}

.sale-program-card::after {
    content: "→";
    position: absolute;
    right: 13px;
    bottom: 12px;
    width: 27px;
    height: 27px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: #f5f6f8;
    color: #9aa1ad;
    font-size: 12px;
    font-weight: 900;
    transition: .18s ease;
}

.sale-program-card:hover {
    transform: translateY(-2px);
    color: inherit;
    box-shadow: 0 11px 24px rgba(15, 23, 42, .08);
}

.sale-program-card:hover::after {
    transform: translateX(2px);
    background: #fff0f2;
    color: #d70018;
}

.sale-program-card-clearance:hover {
    border-color: #e6b96c;
}

.sale-program-card-clearance:hover::after {
    background: #fff2dd;
    color: #a65f00;
}

.sale-program-main {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}

.sale-program-icon-box {
    width: 36px;
    height: 36px;
    display: grid;
    place-items: center;
    flex: 0 0 36px;
    border-radius: 11px;
    background: #f5f6f8;
    font-size: 17px;
}

.sale-program-card-flash .sale-program-icon-box {
    background: #fff1f2;
}

.sale-program-card-clearance .sale-program-icon-box {
    background: #fff1d9;
}

.sale-program-summary {
    min-width: 0;
}

.sale-program-summary-title {
    overflow: hidden;
    color: #20242c;
    font-size: 13.5px;
    line-height: 1.2;
    font-weight: 900;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.sale-program-summary-meta {
    margin-top: 4px;
    color: #8a919d;
    font-size: 9.5px;
    line-height: 1.2;
}

.sale-program-summary-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 10px;
    padding-right: 36px;
}

.sale-program-summary-chip {
    display: inline-flex;
    align-items: center;
    min-height: 24px;
    padding: 4px 8px;
    border: 1px solid #ebedf0;
    border-radius: 999px;
    background: #fff;
    color: #626b78;
    font-size: 9px;
    line-height: 1;
    font-weight: 800;
}

.sale-program-summary-chip.is-value {
    border-color: #d8f3e3;
    background: #effbf4;
    color: #14804a;
}

.sale-program-summary-chip.is-time {
    border-color: #f0e4cd;
    background: #fffaf1;
    color: #9a650e;
}

.sale-program-countdown-compact {
    margin-top: 8px;
    padding-right: 38px;
    color: #d70018;
    font-size: 9px;
    line-height: 1;
    font-weight: 800;
}

.sale-program-countdown-compact strong {
    font-variant-numeric: tabular-nums;
}

/* Mục tiêu cuộn tới section không bị navbar che */
#flash-sale,
#clearance,
#promotion-products,
#normal-products {
    scroll-margin-top: 110px;
}

/* ---------- Pagination ---------- */
.js-paginated-products .js-product-item {
    transition:
        opacity .16s ease,
        transform .16s ease;
}

.product-pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: 20px;
    padding-top: 17px;
    border-top: 1px solid #edf0f3;
}

.product-pagination[hidden] {
    display: none !important;
}

.product-page-btn {
    min-width: 38px;
    height: 38px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 11px;
    border: 1px solid #e1e5ea;
    border-radius: 11px;
    background: #fff;
    color: #505866;
    font-size: 12px;
    line-height: 1;
    font-weight: 850;
    box-shadow: 0 3px 10px rgba(15, 23, 42, .035);
    transition: .16s ease;
}

.product-page-btn:hover:not(:disabled) {
    transform: translateY(-1px);
    border-color: #f0a8b1;
    color: #d70018;
    box-shadow: 0 7px 15px rgba(15, 23, 42, .06);
}

.product-page-btn.is-active {
    border-color: #d70018;
    background: #d70018;
    color: #fff;
    box-shadow: 0 7px 16px rgba(215, 0, 24, .18);
}

.product-page-btn:disabled {
    opacity: .38;
    cursor: default;
}

.product-page-info {
    margin-left: 3px;
    color: #8b929d;
    font-size: 10px;
    font-weight: 750;
}

.product-pagination-dots {
    min-width: 24px;
    color: #9aa1aa;
    text-align: center;
    font-size: 12px;
    font-weight: 850;
}

@media (max-width: 1199.98px) {
    .sale-program-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 767.98px) {
    .sale-program-grid {
        grid-template-columns: 1fr;
    }

    .sale-program-card {
        min-height: 108px;
        padding: 12px;
    }

    .product-pagination {
        gap: 5px;
        margin-top: 15px;
        padding-top: 14px;
    }

    .product-page-btn {
        min-width: 34px;
        height: 34px;
        padding: 0 9px;
        border-radius: 10px;
        font-size: 11px;
    }

    .product-page-info {
        width: 100%;
        margin-top: 3px;
        text-align: center;
    }
}

</style>

<div class="home-page">
    <div class="container">

        <?php if (!empty($slides)): ?>
            <section class="home-hero-grid">

                <div class="hero-shell">
                    <div
                        id="homeHeroCarousel"
                        class="carousel slide hero-carousel"
                        data-bs-ride="carousel"
                        data-bs-interval="4800"
                    >
                        <div class="carousel-indicators">
                            <?php foreach ($slides as $index => $slide): ?>
                                <button
                                    type="button"
                                    data-bs-target="#homeHeroCarousel"
                                    data-bs-slide-to="<?= $index ?>"
                                    class="<?= $index === 0 ? 'active' : '' ?>"
                                    aria-label="Slide <?= $index + 1 ?>"
                                ></button>
                            <?php endforeach; ?>
                        </div>

                        <div class="carousel-inner">
                            <?php foreach ($slides as $index => $slide): ?>
                                <div class="carousel-item <?= $index === 0 ? 'active' : '' ?>">
                                    <div class="hero-slide hero-slide-image-only">
                                        <img
                                            src="<?= e($slide['image']) ?>"
                                            alt="Banner <?= $index + 1 ?>"
                                            loading="<?= $index === 0 ? 'eager' : 'lazy' ?>"
                                            onerror="this.style.display='none'"
                                        >
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <button
                            class="carousel-control-prev"
                            type="button"
                            data-bs-target="#homeHeroCarousel"
                            data-bs-slide="prev"
                            aria-label="Ảnh trước"
                        >
                            <span class="carousel-control-prev-icon"></span>
                        </button>

                        <button
                            class="carousel-control-next"
                            type="button"
                            data-bs-target="#homeHeroCarousel"
                            data-bs-slide="next"
                            aria-label="Ảnh tiếp theo"
                        >
                            <span class="carousel-control-next-icon"></span>
                        </button>
                    </div>
                </div>

                <aside class="home-support-panel">
                    <div class="home-ai-card home-ai-card-compact">
                        <div class="home-ai-top">
                            <div class="home-ai-heading">
                                <div class="home-ai-avatar">
                                    🤖
                                </div>

                                <div>
                                    <div class="home-ai-title">
                                        iPhoneStore AI
                                    </div>

                                    <div class="home-ai-status">
                                        ● Trợ lý mua sắm đang online
                                    </div>
                                </div>
                            </div>

                            <p class="home-ai-copy">
                                Hỏi nhanh máy phù hợp theo ngân sách, dung lượng,
                                camera hoặc nhu cầu sử dụng.
                            </p>
                        </div>

                        <div class="home-ai-actions">
                            <a
                                href="/iPhoneStore/user/ai/chat.php"
                                class="home-ai-button"
                            >
                                <span>✨</span>
                                <span>Hỏi AI chọn máy</span>
                            </a>
                        </div>
                    </div>

                    <div class="home-quick-card">
                        <div class="home-quick-head">
                            <div>
                                <div class="home-quick-kicker">TRUY CẬP NHANH</div>
                                <div class="home-quick-title">Bạn cần làm gì?</div>
                            </div>

                            <span class="home-quick-spark">⚡</span>
                        </div>

                        <div class="home-quick-grid">
                            <a
                                href="?khuyen_mai=dang_khuyen_mai#promotions"
                                class="home-quick-link home-quick-link-sale"
                            >
                                <span class="home-quick-icon">🔥</span>
                                <span class="home-quick-label">Khuyến mãi</span>
                            </a>

                            <a
                                href="/iPhoneStore/user/gio_hang/index.php"
                                class="home-quick-link"
                            >
                                <span class="home-quick-icon">🛒</span>
                                <span class="home-quick-label">Giỏ hàng</span>
                            </a>

                            <a
                                href="/iPhoneStore/user/don_hang/don_hang_da_dat.php"
                                class="home-quick-link"
                            >
                                <span class="home-quick-icon">📦</span>
                                <span class="home-quick-label">Đơn hàng</span>
                            </a>

                            <a
                                href="/iPhoneStore/user/tai_khoan/thong_tin.php"
                                class="home-quick-link"
                            >
                                <span class="home-quick-icon">👤</span>
                                <span class="home-quick-label">Tài khoản</span>
                            </a>
                        </div>
                    </div>
                </aside>

            </section>
        <?php endif; ?>

        <button
            class="mobile-filter-button mt-4"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#mobileFilter"
        >
            <span>
                <i class="fa fa-sliders-h me-2"></i>
                Bộ lọc sản phẩm
            </span>
            <i class="fa fa-chevron-down"></i>
        </button>

        <div class="collapse d-lg-none" id="mobileFilter">
            <form method="get" class="filter-card mb-3">
                <div class="filter-title"><span class="filter-title-icon">⌘</span><span>Bộ lọc</span></div>

                <div class="filter-group">
                    <label class="filter-label"><span class="filter-label-icon">⌕</span><span>Tìm kiếm</span></label>
                    <input
                        type="text"
                        name="keyword"
                        class="form-control"
                        value="<?= e($search) ?>"
                        placeholder="Tên sản phẩm..."
                    >
                </div>

                <?php if (!empty($categories)): ?>
                    <div class="filter-group">
                        <label class="filter-label"><span class="filter-label-icon">▦</span><span>Danh mục</span></label>
                        <select name="danh_muc" class="form-select">
                            <option value="0">Tất cả danh mục</option>
                            <?php foreach ($categories as $category): ?>
                                <option
                                    value="<?= (int)$category['id'] ?>"
                                    <?= $danhMucId === (int)$category['id'] ? 'selected' : '' ?>
                                >
                                    <?= e($category['ten_danh_muc']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php if (!empty($romOptions)): ?>
                    <div class="filter-group">
                        <label class="filter-label"><span class="filter-label-icon">▣</span><span>Dung lượng</span></label>
                        <select name="rom" class="form-select">
                            <option value="">Tất cả dung lượng</option>
                            <?php foreach ($romOptions as $romOption): ?>
                                <option
                                    value="<?= e($romOption) ?>"
                                    <?= $rom === (string)$romOption ? 'selected' : '' ?>
                                >
                                    <?= e($romOption) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php if ($ratingColumn !== null): ?>
                    <div class="filter-group">
                        <label class="filter-label"><span class="filter-label-icon">★</span><span>Đánh giá</span></label>
                        <select name="rating" class="form-select">
                            <option value="0">Tất cả đánh giá</option>
                            <?php for ($star = 5; $star >= 1; $star--): ?>
                                <option value="<?= $star ?>" <?= $rating === $star ? 'selected' : '' ?>>
                                    Từ <?= $star ?> sao
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php if ($hasPromotionTable): ?>
                    <div class="filter-group">
                        <label class="filter-label"><span class="filter-label-icon">%</span><span>Khuyến mãi</span></label>

                        <select
                            name="khuyen_mai"
                            class="form-select"
                        >
                            <option
                                value="tat_ca"
                                <?= $promotionFilter === 'tat_ca' ? 'selected' : '' ?>
                            >
                                Tất cả sản phẩm
                            </option>

                            <option
                                value="dang_khuyen_mai"
                                <?= $promotionFilter === 'dang_khuyen_mai' ? 'selected' : '' ?>
                            >
                                Đang khuyến mãi
                            </option>

                            <option
                                value="gia_thuong"
                                <?= $promotionFilter === 'gia_thuong' ? 'selected' : '' ?>
                            >
                                Không khuyến mãi
                            </option>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="filter-group">
                    <label class="filter-label"><span class="filter-label-icon">⇅</span><span>Sắp xếp</span></label>
                    <select name="sort" class="form-select">
                        <option value="uu_tien" <?= $sort === 'uu_tien' ? 'selected' : '' ?>>Ưu tiên cửa hàng</option>
                        <option value="moi_nhat" <?= $sort === 'moi_nhat' ? 'selected' : '' ?>>Mới nhất</option>
                        <option value="gia_tang" <?= $sort === 'gia_tang' ? 'selected' : '' ?>>Giá tăng dần</option>
                        <option value="gia_giam" <?= $sort === 'gia_giam' ? 'selected' : '' ?>>Giá giảm dần</option>
                        <option value="ban_chay" <?= $sort === 'ban_chay' ? 'selected' : '' ?>>Mua nhiều nhất</option>
                        <option value="danh_gia" <?= $sort === 'danh_gia' ? 'selected' : '' ?>>Đánh giá cao</option>
                    </select>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn-filter">
                        <i class="fa fa-filter"></i>
                        Áp dụng bộ lọc
                    </button>

                    <a href="./index.php" class="btn-reset"><span class="me-2">↺</span>Xóa bộ lọc</a>
                </div>
            </form>
        </div>

        <div class="row g-4 shop-layout">
            <aside class="col-lg-3 desktop-filter">
                <form method="get" class="filter-card">
                    <div class="filter-title"><span class="filter-title-icon">⌘</span><span>Bộ lọc sản phẩm</span></div>

                    <div class="filter-group">
                        <label class="filter-label"><span class="filter-label-icon">⌕</span><span>Tìm kiếm</span></label>
                        <input
                            type="text"
                            name="keyword"
                            class="form-control"
                            value="<?= e($search) ?>"
                            placeholder="Tên sản phẩm..."
                        >
                    </div>

                    <?php if (!empty($categories)): ?>
                        <div class="filter-group">
                            <label class="filter-label"><span class="filter-label-icon">▦</span><span>Danh mục</span></label>
                            <select name="danh_muc" class="form-select">
                                <option value="0">Tất cả danh mục</option>
                                <?php foreach ($categories as $category): ?>
                                    <option
                                        value="<?= (int)$category['id'] ?>"
                                        <?= $danhMucId === (int)$category['id'] ? 'selected' : '' ?>
                                    >
                                        <?= e($category['ten_danh_muc']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($romOptions)): ?>
                        <div class="filter-group">
                            <label class="filter-label"><span class="filter-label-icon">▣</span><span>Dung lượng</span></label>
                            <select name="rom" class="form-select">
                                <option value="">Tất cả dung lượng</option>
                                <?php foreach ($romOptions as $romOption): ?>
                                    <option
                                        value="<?= e($romOption) ?>"
                                        <?= $rom === (string)$romOption ? 'selected' : '' ?>
                                    >
                                        <?= e($romOption) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <?php if ($ratingColumn !== null): ?>
                        <div class="filter-group">
                            <label class="filter-label"><span class="filter-label-icon">★</span><span>Đánh giá</span></label>
                            <select name="rating" class="form-select">
                                <option value="0">Tất cả đánh giá</option>
                                <?php for ($star = 5; $star >= 1; $star--): ?>
                                    <option value="<?= $star ?>" <?= $rating === $star ? 'selected' : '' ?>>
                                        Từ <?= $star ?> sao
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <?php if ($hasPromotionTable): ?>
                        <div class="filter-group">
                            <label class="filter-label"><span class="filter-label-icon">%</span><span>Khuyến mãi</span></label>

                            <select
                                name="khuyen_mai"
                                class="form-select"
                            >
                                <option
                                        value="tat_ca"
                                        <?= $promotionFilter === 'tat_ca' ? 'selected' : '' ?>
                                >
                                        Tất cả sản phẩm
                                </option>

                                <option
                                        value="dang_khuyen_mai"
                                        <?= $promotionFilter === 'dang_khuyen_mai' ? 'selected' : '' ?>
                                >
                                        Đang khuyến mãi
                                </option>

                                <option
                                        value="gia_thuong"
                                        <?= $promotionFilter === 'gia_thuong' ? 'selected' : '' ?>
                                >
                                        Không khuyến mãi
                                </option>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="filter-group">
                        <label class="filter-label"><span class="filter-label-icon">⇅</span><span>Sắp xếp</span></label>
                        <select name="sort" class="form-select">
                            <option value="uu_tien" <?= $sort === 'uu_tien' ? 'selected' : '' ?>>Ưu tiên cửa hàng</option>
                        <option value="moi_nhat" <?= $sort === 'moi_nhat' ? 'selected' : '' ?>>Mới nhất</option>
                            <option value="gia_tang" <?= $sort === 'gia_tang' ? 'selected' : '' ?>>Giá tăng dần</option>
                            <option value="gia_giam" <?= $sort === 'gia_giam' ? 'selected' : '' ?>>Giá giảm dần</option>
                            <option value="ban_chay" <?= $sort === 'ban_chay' ? 'selected' : '' ?>>Mua nhiều nhất</option>
                            <option value="danh_gia" <?= $sort === 'danh_gia' ? 'selected' : '' ?>>Đánh giá cao</option>
                        </select>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn-filter">
                            <i class="fa fa-filter"></i>
                            Áp dụng bộ lọc
                        </button>

                        <a href="./index.php" class="btn-reset"><span class="me-2">↺</span>Xóa bộ lọc</a>
                    </div>
                </form>
            </aside>

            <main class="col-lg-9">
                <?php if ($search !== ''): ?>
                    <div class="alert alert-light border rounded-3 mb-4">
                        Kết quả tìm kiếm cho: <strong><?= e($search) ?></strong>
                    </div>
                <?php endif; ?>

                <?php if (!empty($saleProgramGroups)): ?>
                    <section
                        class="section-block sale-programs-section"
                        id="promotions"
                    >
                        <div class="section-head sale-programs-head">
                            <div>
                                <div class="section-eyebrow">
                                    <span>🎁</span>
                                    Chương trình đang diễn ra
                                </div>

                                <h2 class="section-title">
                                    Khuyến mãi & sự kiện nổi bật
                                </h2>

                                <div class="section-subtitle">
                                    Các chương trình cùng loại đã được gom chung.
                                    Chọn một mục để xem ngay sản phẩm tương ứng.
                                </div>
                            </div>

                            <span class="section-count">
                                <?= count($activeSalePrograms) ?>
                                chương trình ·
                                <?= count($saleProgramGroups) ?>
                                nhóm
                            </span>
                        </div>

                        <div class="sale-program-grid">
                            <?php foreach ($saleProgramGroups as $group): ?>
                                <?php
                                $groupType =
                                    (string)(
                                        $group['type']
                                        ?? 'khac'
                                    );

                                $groupIsFlash =
                                    $groupType === 'flash_sale';

                                $groupIsClearance =
                                    $groupType === 'xa_kho';

                                $valueLabels =
                                    array_keys(
                                        is_array(
                                            $group['value_labels']
                                            ?? null
                                        )
                                            ? $group['value_labels']
                                            : []
                                    );

                                $valueSummary =
                                    count($valueLabels) === 1
                                        ? $valueLabels[0]
                                        : (
                                            count($valueLabels) > 1
                                                ? count($valueLabels) .
                                                    ' mức ưu đãi'
                                                : 'Đang áp dụng'
                                        );

                                $programCount =
                                    max(
                                        0,
                                        (int)(
                                            $group['program_count']
                                            ?? 0
                                        )
                                    );

                                $productCount =
                                    max(
                                        0,
                                        (int)(
                                            $group['product_count']
                                            ?? 0
                                        )
                                    );

                                $groupAnchor =
                                    trim(
                                        (string)(
                                            $group['anchor']
                                            ?? 'promotion-products'
                                        )
                                    );
                                ?>

                                <a
                                    href="#<?= e($groupAnchor) ?>"
                                    class="sale-program-card
                                        <?= $groupIsFlash ? 'sale-program-card-flash' : '' ?>
                                        <?= $groupIsClearance ? 'sale-program-card-clearance' : '' ?>
                                    "
                                    data-scroll-target="<?= e($groupAnchor) ?>"
                                >
                                    <div class="sale-program-main">
                                        <div class="sale-program-icon-box">
                                            <?= e($group['icon'] ?? '🎉') ?>
                                        </div>

                                        <div class="sale-program-summary">
                                            <div class="sale-program-summary-title">
                                                <?= e(
                                                    $group['type_label']
                                                    ?? 'Khuyến mãi'
                                                ) ?>
                                            </div>

                                            <div class="sale-program-summary-meta">
                                                <?= $programCount ?>
                                                chương trình ·
                                                <?= $productCount ?>
                                                sản phẩm áp dụng
                                            </div>
                                        </div>
                                    </div>

                                    <div class="sale-program-summary-row">
                                        <span class="sale-program-summary-chip is-value">
                                            <?= e($valueSummary) ?>
                                        </span>

                                        <?php if (!empty($group['end'])): ?>
                                            <span class="sale-program-summary-chip is-time">
                                                Đến
                                                <?= e(
                                                    homeDateLabel(
                                                        (string)$group['end']
                                                    )
                                                ) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!empty($group['deadline'])): ?>
                                        <div class="sale-program-countdown-compact">
                                            Còn
                                            <strong
                                                class="sale-countdown-inline"
                                                data-sale-deadline="<?= e(
                                                    (string)$group['deadline']
                                                ) ?>"
                                            >00:00:00</strong>
                                        </div>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if (!empty($flashSaleProducts)): ?>
                    <section class="section-block flash-sale-section" id="flash-sale">
                        <div class="flash-sale-head">
                            <div class="flash-sale-brand">
                                <div class="flash-sale-bolt">⚡</div>

                                <div>
                                    <div class="flash-sale-kicker">
                                        FLASH SALE
                                    </div>

                                    <h2 class="flash-sale-title">
                                        Giá sốc theo giờ
                                    </h2>

                                </div>
                            </div>

                            <?php if ($promotionDeadline !== ''): ?>
                                <div class="flash-sale-timer-wrap">
                                    <span class="flash-sale-timer-label">
                                        Kết thúc sau
                                    </span>

                                    <div
                                        class="flash-sale-timer sale-countdown"
                                        data-sale-deadline="<?= e($promotionDeadline) ?>"
                                    >
                                        <div class="flash-time-box">
                                            <strong class="sale-hours">00</strong>
                                            <span>Giờ</span>
                                        </div>

                                        <div class="flash-time-separator">:</div>

                                        <div class="flash-time-box">
                                            <strong class="sale-minutes">00</strong>
                                            <span>Phút</span>
                                        </div>

                                        <div class="flash-time-separator">:</div>

                                        <div class="flash-time-box">
                                            <strong class="sale-seconds">00</strong>
                                            <span>Giây</span>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="flash-sale-body">
                            <div
                                class="row g-3 js-paginated-products"
                                data-page-size="8"
                                data-pagination-label="Flash Sale"
                            >
                                <?php foreach ($flashSaleProducts as $product) {
                                    renderProductCard($product);
                                } ?>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if (!empty($clearanceProducts)): ?>
                    <section class="section-block clearance-section" id="clearance">
                        <div class="clearance-head">
                            <div class="clearance-brand">
                                <div class="clearance-icon">🔥</div>

                                <div>
                                    <div class="clearance-kicker">
                                        XẢ KHO
                                    </div>

                                    <h2 class="clearance-title">
                                        Sản phẩm đang xả kho
                                    </h2>

                                    <?php if ($clearanceStart !== '' || $clearanceEnd !== ''): ?>
                                        <div class="clearance-time-bar">
                                            <span class="clearance-time-label">
                                                🕒 Áp dụng
                                            </span>

                                            <?php if ($clearanceStart !== ''): ?>
                                                <div class="clearance-time-box">
                                                    <small>Bắt đầu</small>
                                                    <strong><?= e(homeDateTimeLabel($clearanceStart)) ?></strong>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($clearanceStart !== '' && $clearanceEnd !== ''): ?>
                                                <span class="clearance-time-sep">→</span>
                                            <?php endif; ?>

                                            <?php if ($clearanceEnd !== ''): ?>
                                                <div class="clearance-time-box">
                                                    <small>Kết thúc</small>
                                                    <strong><?= e(homeDateTimeLabel($clearanceEnd)) ?></strong>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <span class="clearance-count">
                                <?= count($clearanceProducts) ?> sản phẩm
                            </span>
                        </div>

                        <div class="clearance-body">
                            <div
                                class="row g-3 js-paginated-products"
                                data-page-size="8"
                                data-pagination-label="Xả kho"
                            >
                                <?php foreach ($clearanceProducts as $product) {
                                    renderProductCard($product);
                                } ?>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if (!empty($eventPromotionProducts)): ?>
                    <section
                        class="section-block promotion-products-section"
                        id="promotion-products"
                    >
                        <div class="section-head">
                            <div>
                                <div class="section-eyebrow">
                                    <span>🏷️</span>
                                    Ưu đãi đang áp dụng
                                </div>

                                <h2 class="section-title">
                                    Sản phẩm đang có khuyến mãi
                                </h2>

                            </div>

                            <span class="section-count section-count-sale">
                                <?= count($eventPromotionProducts) ?> sản phẩm
                            </span>
                        </div>

                        <div
                            class="row g-3 js-paginated-products"
                            data-page-size="8"
                            data-pagination-label="Khuyến mãi"
                        >
                            <?php foreach ($eventPromotionProducts as $product) {
                                renderProductCard($product);
                            } ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if (!empty($normalProducts)): ?>
                    <section
                        class="section-block normal-products-section"
                        id="normal-products"
                    >
                        <div class="section-head">
                            <div>
                                <div class="section-eyebrow section-eyebrow-neutral">
                                    <span>📱</span>
                                    Sản phẩm hiện có
                                </div>

                                <h2 class="section-title">
                                    Khám phá thêm iPhone
                                </h2>

                            </div>

                            <span class="section-count">
                                <?= count($normalProducts) ?> sản phẩm
                            </span>
                        </div>

                        <div
                            class="row g-3 js-paginated-products"
                            data-page-size="8"
                            data-pagination-label="Sản phẩm"
                        >
                            <?php foreach ($normalProducts as $product) {
                                renderProductCard($product);
                            } ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if (empty($products)): ?>
                    <div class="empty-state">
                        <i class="fa fa-search"></i>
                        Không tìm thấy sản phẩm phù hợp với bộ lọc.
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    let saleReloadScheduled = false;

    function parseDeadline(value) {
        if (!value) {
            return NaN;
        }

        return new Date(
            value.replace(' ', 'T')
        ).getTime();
    }

    function handleExpiredSale(deadlineValue) {
        if (saleReloadScheduled) {
            return;
        }

        /*
            Tránh vòng lặp reload nếu PHP/MySQL và trình duyệt
            lệch thời gian trong một vài giây.
        */
        const reloadKey =
            'sale_expired_reload_' +
            String(deadlineValue || '')
                .replace(/[^0-9]/g, '');

        if (
            sessionStorage.getItem(reloadKey) === '1'
        ) {
            return;
        }

        sessionStorage.setItem(
            reloadKey,
            '1'
        );

        saleReloadScheduled = true;

        /*
            Ẩn ngay Flash Sale để khách không còn thấy
            chương trình đã hết giờ.
        */
        document
            .querySelectorAll(
                '.flash-sale-section'
            )
            .forEach(function (section) {
                section.style.display = 'none';
            });

        /*
            Reload lại để PHP tính lại khuyến mãi.
            Sản phẩm sẽ trở về khu bình thường hoặc
            chuyển sang KM khác nếu còn chương trình khác.
        */
        setTimeout(function () {
            window.location.reload();
        }, 500);
    }

    function updateCountdown(root) {
        const deadlineValue =
            root.getAttribute(
                'data-sale-deadline'
            );

        const deadline =
            parseDeadline(deadlineValue);

        const now =
            Date.now();

        let distance =
            deadline - now;

        if (
            isNaN(deadline) ||
            distance <= 0
        ) {
            distance = 0;

            const h =
                root.querySelector(
                    '.sale-hours'
                );

            const m =
                root.querySelector(
                    '.sale-minutes'
                );

            const s =
                root.querySelector(
                    '.sale-seconds'
                );

            if (h) {
                h.textContent = '00';
            }

            if (m) {
                m.textContent = '00';
            }

            if (s) {
                s.textContent = '00';
            }

            handleExpiredSale(
                deadlineValue
            );

            return;
        }

        const hours =
            String(
                Math.floor(
                    distance /
                    (1000 * 60 * 60)
                )
            ).padStart(2, '0');

        const minutes =
            String(
                Math.floor(
                    (
                        distance %
                        (1000 * 60 * 60)
                    ) /
                    (1000 * 60)
                )
            ).padStart(2, '0');

        const seconds =
            String(
                Math.floor(
                    (
                        distance %
                        (1000 * 60)
                    ) /
                    1000
                )
            ).padStart(2, '0');

        const h =
            root.querySelector(
                '.sale-hours'
            );

        const m =
            root.querySelector(
                '.sale-minutes'
            );

        const s =
            root.querySelector(
                '.sale-seconds'
            );

        if (h) {
            h.textContent = hours;
        }

        if (m) {
            m.textContent = minutes;
        }

        if (s) {
            s.textContent = seconds;
        }
    }

    function updateInlineCountdown(el) {
        const deadlineValue =
            el.getAttribute(
                'data-sale-deadline'
            );

        const deadline =
            parseDeadline(
                deadlineValue
            );

        const now =
            Date.now();

        let distance =
            deadline - now;

        if (
            isNaN(deadline) ||
            distance <= 0
        ) {
            el.textContent =
                '00:00:00';

            handleExpiredSale(
                deadlineValue
            );

            return;
        }

        const hours =
            String(
                Math.floor(
                    distance /
                    (1000 * 60 * 60)
                )
            ).padStart(2, '0');

        const minutes =
            String(
                Math.floor(
                    (
                        distance %
                        (1000 * 60 * 60)
                    ) /
                    (1000 * 60)
                )
            ).padStart(2, '0');

        const seconds =
            String(
                Math.floor(
                    (
                        distance %
                        (1000 * 60)
                    ) /
                    1000
                )
            ).padStart(2, '0');

        el.textContent =
            `${hours}:${minutes}:${seconds}`;
    }

    const countdownBoxes =
        document.querySelectorAll(
            '.sale-countdown'
        );

    const countdownInline =
        document.querySelectorAll(
            '.sale-countdown-inline'
        );

    /* =====================================================
       CUỘN MƯỢT TỪ THẺ KHUYẾN MÃI ĐẾN KHU SẢN PHẨM
    ===================================================== */
    document
        .querySelectorAll('[data-scroll-target]')
        .forEach(function (link) {
            link.addEventListener(
                'click',
                function (event) {
                    const targetId =
                        this.getAttribute(
                            'data-scroll-target'
                        );

                    const target =
                        targetId
                            ? document.getElementById(
                                targetId
                            )
                            : null;

                    if (!target) {
                        return;
                    }

                    event.preventDefault();

                    history.replaceState(
                        null,
                        '',
                        '#' + targetId
                    );

                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            );
        });

    /* =====================================================
       PHÂN TRANG SẢN PHẨM PHÍA CLIENT
       - Mặc định 8 sản phẩm / trang.
       - Mỗi khu có phân trang độc lập.
       - Không reload trang.
    ===================================================== */
    function paginationVisiblePages(
        current,
        total
    ) {
        const pages = [];

        if (total <= 7) {
            for (
                let page = 1;
                page <= total;
                page++
            ) {
                pages.push(page);
            }

            return pages;
        }

        pages.push(1);

        const start =
            Math.max(
                2,
                current - 1
            );

        const end =
            Math.min(
                total - 1,
                current + 1
            );

        if (start > 2) {
            pages.push('dots-start');
        }

        for (
            let page = start;
            page <= end;
            page++
        ) {
            pages.push(page);
        }

        if (end < total - 1) {
            pages.push('dots-end');
        }

        pages.push(total);

        return pages;
    }

    function setupProductPagination(grid) {
        const items =
            Array.from(
                grid.querySelectorAll(
                    ':scope > .js-product-item'
                )
            );

        const pageSize =
            Math.max(
                1,
                parseInt(
                    grid.getAttribute(
                        'data-page-size'
                    ),
                    10
                ) || 8
            );

        const totalPages =
            Math.max(
                1,
                Math.ceil(
                    items.length /
                    pageSize
                )
            );

        if (items.length <= pageSize) {
            return;
        }

        let currentPage = 1;

        const pager =
            document.createElement('nav');

        pager.className =
            'product-pagination';

        pager.setAttribute(
            'aria-label',
            'Phân trang sản phẩm'
        );

        grid.insertAdjacentElement(
            'afterend',
            pager
        );

        function makeButton(
            text,
            className,
            disabled,
            onClick
        ) {
            const button =
                document.createElement(
                    'button'
                );

            button.type =
                'button';

            button.className =
                'product-page-btn ' +
                (className || '');

            button.textContent =
                text;

            button.disabled =
                !!disabled;

            button.addEventListener(
                'click',
                onClick
            );

            return button;
        }

        function goToPage(
            page,
            shouldScroll = true
        ) {
            currentPage =
                Math.max(
                    1,
                    Math.min(
                        totalPages,
                        page
                    )
                );

            const start =
                (currentPage - 1) *
                pageSize;

            const end =
                start + pageSize;

            items.forEach(
                function (item, index) {
                    const visible =
                        index >= start &&
                        index < end;

                    item.style.display =
                        visible
                            ? ''
                            : 'none';

                    item.setAttribute(
                        'aria-hidden',
                        visible
                            ? 'false'
                            : 'true'
                    );
                }
            );

            renderPager();

            if (shouldScroll) {
                const section =
                    grid.closest(
                        'section'
                    );

                if (section) {
                    section.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            }
        }

        function renderPager() {
            pager.innerHTML = '';

            pager.appendChild(
                makeButton(
                    '‹',
                    'product-page-prev',
                    currentPage === 1,
                    function () {
                        goToPage(
                            currentPage - 1
                        );
                    }
                )
            );

            paginationVisiblePages(
                currentPage,
                totalPages
            ).forEach(
                function (page) {
                    if (
                        typeof page ===
                        'string'
                    ) {
                        const dots =
                            document.createElement(
                                'span'
                            );

                        dots.className =
                            'product-pagination-dots';

                        dots.textContent =
                            '…';

                        pager.appendChild(
                            dots
                        );

                        return;
                    }

                    const button =
                        makeButton(
                            String(page),
                            page === currentPage
                                ? 'is-active'
                                : '',
                            false,
                            function () {
                                goToPage(page);
                            }
                        );

                    if (
                        page === currentPage
                    ) {
                        button.setAttribute(
                            'aria-current',
                            'page'
                        );
                    }

                    pager.appendChild(
                        button
                    );
                }
            );

            pager.appendChild(
                makeButton(
                    '›',
                    'product-page-next',
                    currentPage ===
                        totalPages,
                    function () {
                        goToPage(
                            currentPage + 1
                        );
                    }
                )
            );

            const info =
                document.createElement(
                    'span'
                );

            info.className =
                'product-page-info';

            const label =
                grid.getAttribute(
                    'data-pagination-label'
                ) || 'Sản phẩm';

            info.textContent =
                label +
                ': trang ' +
                currentPage +
                '/' +
                totalPages;

            pager.appendChild(info);
        }

        goToPage(
            1,
            false
        );
    }

    document
        .querySelectorAll(
            '.js-paginated-products'
        )
        .forEach(
            setupProductPagination
        );

    function tickAll() {
        countdownBoxes.forEach(
            updateCountdown
        );

        countdownInline.forEach(
            updateInlineCountdown
        );
    }

    tickAll();

    setInterval(
        tickAll,
        1000
    );
});
</script>

<?php include "../includes/footer.php"; ?>
