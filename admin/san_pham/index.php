<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../middleware/check_admin.php";
require_once "../../config/database.php";

/* =========================================================
   KIỂM TRA CỘT MÃ SẢN PHẨM
   Giúp trang không lỗi trắng nếu database chưa thêm cột.
========================================================= */
$resultMaSanPhamColumn = $conn->query("
    SHOW COLUMNS
    FROM san_pham
    LIKE 'ma_san_pham'
");

$coCotMaSanPham =
    $resultMaSanPhamColumn &&
    $resultMaSanPhamColumn->num_rows > 0;

$resultThuTuColumn = $conn->query("
    SHOW COLUMNS
    FROM san_pham
    LIKE 'thu_tu_hien_thi'
");

$coCotThuTu =
    $resultThuTuColumn &&
    $resultThuTuColumn->num_rows > 0;

/* =========================================================
   HÀM TÍNH GIÁ SAU KHUYẾN MÃI
========================================================= */
function tinhGiaKhuyenMai(
    float $giaGoc,
    ?string $kieuGiam,
    float $giaTri
): float {
    if (
        $giaGoc <= 0 ||
        $kieuGiam === null ||
        $giaTri <= 0
    ) {
        return $giaGoc;
    }

    if ($kieuGiam === 'phan_tram') {
        $giaBan =
            $giaGoc -
            ($giaGoc * $giaTri / 100);
    } else {
        $giaBan =
            $giaGoc -
            $giaTri;
    }

    return max(
        0,
        round($giaBan, 2)
    );
}

/* =========================================================
   DỮ LIỆU LỌC
========================================================= */
$tuKhoa =
    trim($_GET['q'] ?? '');

$danhMucId =
    (int)($_GET['danh_muc_id'] ?? 0);

$trangThai =
    $_GET['trang_thai'] ?? '';

if (
    $trangThai !== '' &&
    !in_array(
        $trangThai,
        ['0', '1'],
        true
    )
) {
    $trangThai = '';
}

/* =========================================================
   PHÂN TRANG
========================================================= */
$limit = 10;

$page =
    max(
        1,
        (int)($_GET['page'] ?? 1)
    );

/* =========================================================
   DANH MỤC
========================================================= */
$danhMucList = [];

$resultDanhMuc = $conn->query("
    SELECT
        id,
        ten_danh_muc
    FROM danh_muc
    ORDER BY ten_danh_muc ASC
");

while ($row = $resultDanhMuc->fetch_assoc()) {
    $danhMucList[] = $row;
}

/* =========================================================
   WHERE + PARAM
========================================================= */
$where = [
    '1 = 1'
];

$types = '';
$params = [];

if ($tuKhoa !== '') {
    $keyword = '%' . $tuKhoa . '%';

    if ($coCotMaSanPham) {
        $where[] = '(sp.ten_san_pham LIKE ? OR sp.ma_san_pham LIKE ?)';
        $types .= 'ss';
        $params[] = $keyword;
        $params[] = $keyword;
    } else {
        $where[] = 'sp.ten_san_pham LIKE ?';
        $types .= 's';
        $params[] = $keyword;
    }
}

if ($danhMucId > 0) {
    $where[] =
        'sp.danh_muc_id = ?';

    $types .= 'i';
    $params[] = $danhMucId;
}

if ($trangThai !== '') {
    $where[] =
        'sp.trang_thai = ?';

    $types .= 'i';
    $params[] = (int)$trangThai;
}

$whereSql =
    implode(
        ' AND ',
        $where
    );

/* =========================================================
   ĐẾM SẢN PHẨM
========================================================= */
$sqlCount = "
    SELECT COUNT(*) AS total
    FROM san_pham sp
    WHERE $whereSql
";

$stmtCount =
    $conn->prepare($sqlCount);

if (!$stmtCount) {
    die(
        'Lỗi SQL đếm sản phẩm: ' .
        htmlspecialchars($conn->error)
    );
}

if ($types !== '') {
    $stmtCount->bind_param(
        $types,
        ...$params
    );
}

$stmtCount->execute();

$totalRows =
    (int)(
        $stmtCount
            ->get_result()
            ->fetch_assoc()['total']
        ?? 0
    );

$totalPages =
    max(
        1,
        (int)ceil(
            $totalRows / $limit
        )
    );

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset =
    ($page - 1) * $limit;

/* =========================================================
   DANH SÁCH SẢN PHẨM

   Nếu database chưa có cột ma_san_pham thì trả NULL
   để trang vẫn hoạt động bình thường.
========================================================= */
$maSanPhamSelect =
    $coCotMaSanPham
        ? 'sp.ma_san_pham'
        : 'NULL AS ma_san_pham';

$thuTuSelect =
    $coCotThuTu
        ? 'sp.thu_tu_hien_thi'
        : '9999 AS thu_tu_hien_thi';

$orderBySql =
    $coCotThuTu
        ? 'CASE
               WHEN sp.thu_tu_hien_thi IS NULL
                    OR sp.thu_tu_hien_thi <= 0
               THEN 999999
               ELSE sp.thu_tu_hien_thi
           END ASC, sp.id DESC'
        : 'sp.id DESC';

/* =========================================================
   DANH SÁCH SẢN PHẨM

   - Giá biến thể: MIN / MAX
   - Tồn kho biến thể: SUM
   - Đếm thông số kỹ thuật
   - Đếm khuyến mãi đang hoạt động
   - Lấy khuyến mãi chung đang hoạt động mới nhất
========================================================= */
$sql = "
    SELECT
        sp.id,
        $maSanPhamSelect,
        $thuTuSelect,
        sp.danh_muc_id,
        sp.ten_san_pham,
        sp.gia,
        sp.so_luong,
        sp.hinh_anh,
        sp.trang_thai,
        sp.ngay_tao,

        dm.ten_danh_muc,

        COALESCE(v.tong_bien_the, 0) AS tong_bien_the,
        v.gia_thap_nhat,
        v.gia_cao_nhat,
        v.gia_km_thap_nhat,
        v.gia_km_cao_nhat,
        v.tong_ton_bien_the,

        COALESCE(ts.tong_thong_so, 0) AS tong_thong_so,

        COALESCE(km_count.tong_km_dang_chay, 0) AS tong_km_dang_chay,

        km_sp.kieu_giam AS km_sp_kieu_giam,
        km_sp.gia_tri AS km_sp_gia_tri,
        km_sp.ten_khuyen_mai AS km_sp_ten

    FROM san_pham sp

    JOIN danh_muc dm
        ON dm.id = sp.danh_muc_id

    LEFT JOIN (
        SELECT
            bt.san_pham_id,
            COUNT(*) AS tong_bien_the,
            MIN(bt.gia) AS gia_thap_nhat,
            MAX(bt.gia) AS gia_cao_nhat,
            SUM(bt.so_luong) AS tong_ton_bien_the,

            MIN(
                CASE
                    WHEN bt.so_luong > 0
                    AND km_bt.kieu_giam = 'phan_tram'
                    THEN GREATEST(
                        0,
                        bt.gia - (
                            bt.gia * km_bt.gia_tri / 100
                        )
                    )

                    WHEN bt.so_luong > 0
                    AND km_bt.kieu_giam IS NOT NULL
                    THEN GREATEST(
                        0,
                        bt.gia - km_bt.gia_tri
                    )

                    WHEN bt.so_luong > 0
                    AND km_chung.kieu_giam = 'phan_tram'
                    THEN GREATEST(
                        0,
                        bt.gia - (
                            bt.gia * km_chung.gia_tri / 100
                        )
                    )

                    WHEN bt.so_luong > 0
                    AND km_chung.kieu_giam IS NOT NULL
                    THEN GREATEST(
                        0,
                        bt.gia - km_chung.gia_tri
                    )

                    ELSE bt.gia
                END
            ) AS gia_km_thap_nhat,

            MAX(
                CASE
                    WHEN bt.so_luong > 0
                    AND km_bt.kieu_giam = 'phan_tram'
                    THEN GREATEST(
                        0,
                        bt.gia - (
                            bt.gia * km_bt.gia_tri / 100
                        )
                    )

                    WHEN bt.so_luong > 0
                    AND km_bt.kieu_giam IS NOT NULL
                    THEN GREATEST(
                        0,
                        bt.gia - km_bt.gia_tri
                    )

                    WHEN bt.so_luong > 0
                    AND km_chung.kieu_giam = 'phan_tram'
                    THEN GREATEST(
                        0,
                        bt.gia - (
                            bt.gia * km_chung.gia_tri / 100
                        )
                    )

                    WHEN bt.so_luong > 0
                    AND km_chung.kieu_giam IS NOT NULL
                    THEN GREATEST(
                        0,
                        bt.gia - km_chung.gia_tri
                    )

                    ELSE bt.gia
                END
            ) AS gia_km_cao_nhat

        FROM san_pham_bien_the bt

        LEFT JOIN khuyen_mai km_bt
            ON km_bt.id = (
                SELECT km_bt2.id
                FROM khuyen_mai km_bt2

                WHERE km_bt2.san_pham_id =
                    bt.san_pham_id
                AND km_bt2.bien_the_id =
                    bt.id
                AND km_bt2.trang_thai = 1
                AND NOW() BETWEEN
                    km_bt2.ngay_bat_dau
                    AND km_bt2.ngay_ket_thuc

                AND (
                    km_bt2.loai_khuyen_mai <> 'xa_kho'
                    OR
                    COALESCE(km_bt2.ton_kho_toi_da, 0) <= 0
                    OR
                    bt.so_luong >=
                        COALESCE(km_bt2.ton_kho_toi_da, 0)
                )

                ORDER BY km_bt2.id DESC
                LIMIT 1
            )

        LEFT JOIN khuyen_mai km_chung
            ON km_chung.id = (
                SELECT km_chung2.id
                FROM khuyen_mai km_chung2

                WHERE km_chung2.san_pham_id =
                    bt.san_pham_id
                AND km_chung2.bien_the_id IS NULL
                AND km_chung2.trang_thai = 1
                AND NOW() BETWEEN
                    km_chung2.ngay_bat_dau
                    AND km_chung2.ngay_ket_thuc

                AND (
                    km_chung2.loai_khuyen_mai <> 'xa_kho'
                    OR
                    COALESCE(km_chung2.ton_kho_toi_da, 0) <= 0
                    OR
                    bt.so_luong >=
                        COALESCE(km_chung2.ton_kho_toi_da, 0)
                )

                ORDER BY km_chung2.id DESC
                LIMIT 1
            )

        GROUP BY bt.san_pham_id
    ) v
        ON v.san_pham_id = sp.id

    LEFT JOIN (
        SELECT
            san_pham_id,
            COUNT(*) AS tong_thong_so
        FROM san_pham_thong_so
        GROUP BY san_pham_id
    ) ts
        ON ts.san_pham_id = sp.id

    LEFT JOIN (
        SELECT
            km.san_pham_id,
            COUNT(*) AS tong_km_dang_chay
        FROM khuyen_mai km

        LEFT JOIN san_pham_bien_the bt_km
            ON bt_km.id = km.bien_the_id

        WHERE km.trang_thai = 1
        AND NOW() BETWEEN km.ngay_bat_dau AND km.ngay_ket_thuc

        AND (
            (
                km.bien_the_id IS NOT NULL
                AND km.bien_the_id > 0
                AND COALESCE(bt_km.so_luong, 0) > 0
                AND (
                    km.loai_khuyen_mai <> 'xa_kho'
                    OR
                    COALESCE(km.ton_kho_toi_da, 0) <= 0
                    OR
                    COALESCE(bt_km.so_luong, 0) >=
                        COALESCE(km.ton_kho_toi_da, 0)
                )
            )
            OR
            (
                (km.bien_the_id IS NULL OR km.bien_the_id = 0)
                AND (
                    (
                        km.loai_khuyen_mai = 'xa_kho'
                        AND COALESCE(km.ton_kho_toi_da, 0) > 0
                        AND (
                            (
                                EXISTS (
                                    SELECT 1
                                    FROM san_pham_bien_the bt_exists
                                    WHERE bt_exists.san_pham_id = km.san_pham_id
                                )
                                AND EXISTS (
                                    SELECT 1
                                    FROM san_pham_bien_the bt_ok
                                    WHERE bt_ok.san_pham_id = km.san_pham_id
                                      AND bt_ok.so_luong >=
                                          COALESCE(km.ton_kho_toi_da, 0)
                                )
                            )
                            OR
                            (
                                NOT EXISTS (
                                    SELECT 1
                                    FROM san_pham_bien_the bt_none
                                    WHERE bt_none.san_pham_id = km.san_pham_id
                                )
                                AND COALESCE((
                                    SELECT sp2.so_luong
                                    FROM san_pham sp2
                                    WHERE sp2.id = km.san_pham_id
                                    LIMIT 1
                                ), 0) >=
                                    COALESCE(km.ton_kho_toi_da, 0)
                            )
                        )
                    )
                    OR
                    (
                        (
                            km.loai_khuyen_mai <> 'xa_kho'
                            OR COALESCE(km.ton_kho_toi_da, 0) <= 0
                        )
                        AND (
                            CASE
                                WHEN EXISTS (
                                    SELECT 1
                                    FROM san_pham_bien_the bt_exists2
                                    WHERE bt_exists2.san_pham_id = km.san_pham_id
                                )
                                THEN COALESCE((
                                    SELECT SUM(bt_all.so_luong)
                                    FROM san_pham_bien_the bt_all
                                    WHERE bt_all.san_pham_id = km.san_pham_id
                                ), 0)
                                ELSE COALESCE((
                                    SELECT sp3.so_luong
                                    FROM san_pham sp3
                                    WHERE sp3.id = km.san_pham_id
                                    LIMIT 1
                                ), 0)
                            END
                        ) > 0
                    )
                )
            )
        )

        GROUP BY km.san_pham_id
    ) km_count
        ON km_count.san_pham_id = sp.id

    LEFT JOIN khuyen_mai km_sp
        ON km_sp.id = (
            SELECT km2.id
            FROM khuyen_mai km2
            WHERE km2.san_pham_id = sp.id
            AND km2.bien_the_id IS NULL
            AND km2.trang_thai = 1
            AND NOW() BETWEEN km2.ngay_bat_dau AND km2.ngay_ket_thuc
            AND (
                (
                    km2.loai_khuyen_mai = 'xa_kho'
                    AND COALESCE(km2.ton_kho_toi_da, 0) > 0
                    AND (
                        (
                            EXISTS (
                                SELECT 1
                                FROM san_pham_bien_the bt_exists_sp
                                WHERE bt_exists_sp.san_pham_id = sp.id
                            )
                            AND EXISTS (
                                SELECT 1
                                FROM san_pham_bien_the bt_ok_sp
                                WHERE bt_ok_sp.san_pham_id = sp.id
                                  AND bt_ok_sp.so_luong >=
                                      COALESCE(km2.ton_kho_toi_da, 0)
                            )
                        )
                        OR
                        (
                            NOT EXISTS (
                                SELECT 1
                                FROM san_pham_bien_the bt_none_sp
                                WHERE bt_none_sp.san_pham_id = sp.id
                            )
                            AND COALESCE(sp.so_luong, 0) >=
                                COALESCE(km2.ton_kho_toi_da, 0)
                        )
                    )
                )
                OR
                (
                    (
                        km2.loai_khuyen_mai <> 'xa_kho'
                        OR COALESCE(km2.ton_kho_toi_da, 0) <= 0
                    )
                    AND (
                        CASE
                            WHEN EXISTS (
                                SELECT 1
                                FROM san_pham_bien_the bt_exists_sp2
                                WHERE bt_exists_sp2.san_pham_id = sp.id
                            )
                            THEN COALESCE((
                                SELECT SUM(bt_stock_sp.so_luong)
                                FROM san_pham_bien_the bt_stock_sp
                                WHERE bt_stock_sp.san_pham_id = sp.id
                            ), 0)
                            ELSE COALESCE(sp.so_luong, 0)
                        END
                    ) > 0
                )
            )
            ORDER BY km2.id DESC
            LIMIT 1
        )

    WHERE $whereSql

    ORDER BY $orderBySql

    LIMIT ?
    OFFSET ?
";

$stmt =
    $conn->prepare($sql);

if (!$stmt) {
    die(
        'Lỗi SQL danh sách sản phẩm: ' .
        htmlspecialchars($conn->error)
    );
}

$listTypes =
    $types . 'ii';

$listParams =
    array_merge(
        $params,
        [
            $limit,
            $offset
        ]
    );

$stmt->bind_param(
    $listTypes,
    ...$listParams
);

$stmt->execute();

$products =
    $stmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

/* =========================================================
   URL PHÂN TRANG
========================================================= */
function pageUrl(
    int $page,
    string $tuKhoa,
    int $danhMucId,
    string $trangThai
): string {
    $query = [
        'page' => max(1, $page)
    ];

    if ($tuKhoa !== '') {
        $query['q'] = $tuKhoa;
    }

    if ($danhMucId > 0) {
        $query['danh_muc_id'] =
            $danhMucId;
    }

    if ($trangThai !== '') {
        $query['trang_thai'] =
            $trangThai;
    }

    return 'index.php?' .
        http_build_query($query);
}

$from =
    $totalRows > 0
        ? $offset + 1
        : 0;

$to =
    min(
        $offset + $limit,
        $totalRows
    );
?>

<?php require_once "../../includes/header.php"; ?>
<?php require_once "../../includes/navbar.php"; ?>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
>

<style>
.product-manager {
    background: #f6f7f9;
    min-height: 70vh;
}

/*
    Trang quản lý sản phẩm cần tận dụng chiều ngang màn hình.
    Không để Bootstrap .container bó nội dung quá sâu vào giữa.
*/
.product-manager > .container {
    width: 100%;
    max-width: none;
    padding-left: 14px;
    padding-right: 14px;
}

.manager-card {
    border: 1px solid #e9edf2;
    border-radius: 14px;
    overflow: hidden;
    background: rgba(255, 255, 255, .82);
    box-shadow: 0 6px 20px rgba(15, 23, 42, .04);
}

.filter-card {
    border: 1px solid #ececec;
    border-radius: 16px;
    padding: 18px;
    background: #fff;
}

.product-image {
    width: 72px;
    height: 72px;
    object-fit: contain;
    border: 1px solid #eee;
    border-radius: 12px;
    background: #fff;
}

.no-image {
    width: 72px;
    height: 72px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #eee;
    border-radius: 12px;
    background: #fafafa;
    color: #aaa;
    font-size: 11px;
}

.product-name {
    max-width: 270px;
    font-weight: 700;
}

.product-code {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 9px;
    border: 1px solid #ffd9cc;
    border-radius: 8px;
    background: #fff5f1;
    color: #ee4d2d;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .6px;
    white-space: nowrap;
}

.product-code-empty {
    color: #8b8f97;
    background: #f5f6f8;
    border-color: #e3e5e8;
    font-weight: 600;
    letter-spacing: 0;
}

.notice-card {
    border: 0;
    border-radius: 12px;
    box-shadow: 0 5px 18px rgba(0, 0, 0, .04);
}

.price-box {
    min-width: 175px;
}

.price-label {
    color: #8b8f97;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
}

.price-original {
    margin-top: 2px;
    color: #8b8f97;
    font-size: 12px;
    line-height: 1.35;
    text-decoration: line-through;
}

.price-sale {
    margin-top: 3px;
    color: #d70018;
    font-size: 15px;
    line-height: 1.35;
    font-weight: 900;
    white-space: nowrap;
}

.price-normal {
    margin-top: 3px;
    color: #20242b;
    font-size: 14px;
    line-height: 1.35;
    font-weight: 800;
    white-space: nowrap;
}

.price-note {
    margin-top: 4px;
    color: #8a9099;
    font-size: 10px;
}

.meta-badge {
    display: inline-block;
    margin-top: 4px;
    margin-right: 4px;
    padding: 4px 7px;
    border-radius: 7px;
    background: #f2f4f7;
    color: #555;
    font-size: 11px;
}

.promo-badge {
    background: #fff2ed;
    color: #ee4d2d;
}

.spec-badge {
    background: #eef7ff;
    color: #0d6efd;
}

.table > :not(caption) > * > * {
    padding: 11px 10px;
}

.action-btn {
    width: 38px;
    height: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.pagination .page-link {
    color: #444;
}

.pagination .page-item.active .page-link {
    background: #0d6efd;
    border-color: #0d6efd;
    color: #fff;
}

.manager-card table {
    table-layout: fixed;
    width: 100%;
}

.manager-card th:nth-child(1),
.manager-card td:nth-child(1) {
    width: 120px;
}

.manager-card th:nth-child(2),
.manager-card td:nth-child(2) {
    width: 95px;
}

.manager-card th:nth-child(3),
.manager-card td:nth-child(3) {
    width: 260px;
}

.manager-card th:nth-child(4),
.manager-card td:nth-child(4) {
    width: 170px;
}

.manager-card th:nth-child(5),
.manager-card td:nth-child(5) {
    width: 250px;
}

.manager-card th:nth-child(6),
.manager-card td:nth-child(6) {
    width: 130px;
}

.manager-card th:nth-child(7),
.manager-card td:nth-child(7) {
    width: 130px;
}

.manager-card th:nth-child(8),
.manager-card td:nth-child(8) {
    width: 110px;
}

.manager-card td {
    vertical-align: middle;
}

@media (max-width: 991px) {
    .product-manager > .container {
        padding-left: 8px;
        padding-right: 8px;
    }

    .manager-card table {
        table-layout: auto;
        min-width: 1180px;
    }

    .product-name {
        min-width: 210px;
    }
}

/* =========================================================
   GIAO DIỆN QUẢN LÝ SẢN PHẨM - BẢN TINH GỌN
========================================================= */
.manager-card {
    border: 1px solid #e9edf2;
    background: rgba(255, 255, 255, .82);
    box-shadow: 0 6px 20px rgba(15, 23, 42, .04);
}

.manager-card .card-header {
    border-bottom: 1px solid #edf0f4;
    background: rgba(255, 255, 255, .55) !important;
    backdrop-filter: blur(4px);
}

.table-responsive {
    overflow-x: auto;
}

.table thead th {
    border-top: 0;
    border-bottom: 1px solid #e5e9ef;
    background: rgba(248, 250, 252, .92) !important;
    color: #4b5563;
    font-size: 10.5px;
    letter-spacing: .28px;
    text-transform: uppercase;
    white-space: nowrap;
}

.table tbody tr {
    transition: .16s ease;
}

.table tbody tr:hover {
    background: #fafcff;
}

.product-row-out {
    background: #fffdf9;
}

.product-row-out:hover {
    background: #fff8eb !important;
}

.product-image,
.no-image {
    width: 68px;
    height: 68px;
    border-radius: 12px;
}

.product-name {
    color: #172033;
    font-size: 14px;
    line-height: 1.45;
    font-weight: 850;
}

.stock-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 9px;
    border-radius: 999px;
    font-size: 11px;
    line-height: 1;
    font-weight: 850;
}

.stock-pill.ok {
    border: 1px solid #bfe7cc;
    background: #eaf8ef;
    color: #146c43;
}

.stock-pill.low {
    border: 1px solid #f1d38a;
    background: #fff6dc;
    color: #865c00;
}

.stock-pill.out {
    border: 1px solid #ffc9a8;
    background: #fff3e8;
    color: #c75a00;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    padding: 6px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 850;
}

.status-pill.active {
    border: 1px solid #bfe7cc;
    background: #eaf8ef;
    color: #146c43;
}

.status-pill.inactive {
    border: 1px solid #d9dde3;
    background: #f1f3f5;
    color: #6c757d;
}

.product-subline {
    margin-top: 5px;
    color: #8a919b;
    font-size: 10.5px;
}

.promo-badge {
    border: 1px solid #ffd5c8;
    background: #fff5f1;
}

.out-of-stock-note {
    margin-top: 5px;
    color: #b75b00;
    font-size: 10.5px;
    font-weight: 750;
}

.action-btn {
    border-radius: 10px;
    box-shadow: none !important;
}


.display-order-form {
    min-width: 150px;
}

.display-order-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
}

.display-order-input {
    width: 68px;
    min-width: 68px;
    height: 34px;
    border-radius: 9px;
    text-align: center;
    font-weight: 850;
}

.display-order-save {
    height: 34px;
    padding: 0 10px;
    border-radius: 9px;
    font-size: 11px;
    font-weight: 800;
    white-space: nowrap;
}

.display-order-note {
    margin-top: 4px;
    color: #8a919b;
    font-size: 9.5px;
    text-align: center;
}

.manager-card th:nth-child(8),
.manager-card td:nth-child(8) {
    width: 175px;
}

.manager-card th:nth-child(9),
.manager-card td:nth-child(9) {
    width: 110px;
}

@media (max-width: 991px) {
    .manager-card table {
        min-width: 1350px;
    }
}



/* =========================================================
   FIX BẢNG QUẢN LÝ SẢN PHẨM KHÔNG TRÀN TOÀN TRANG
   - Desktop: co gọn 9 cột trong khung.
   - Tablet/mobile: chỉ cuộn ngang bên trong bảng.
========================================================= */

html,
body {
    max-width: 100%;
    overflow-x: hidden;
}

.product-manager {
    width: 100%;
    max-width: 100%;
    overflow-x: hidden;
}

.product-manager > .container {
    width: 100%;
    max-width: 100%;
    overflow: hidden;
}

.manager-card {
    width: 100%;
    max-width: 100%;
    overflow: hidden;
}

.manager-card .card-body {
    min-width: 0;
}

.table-responsive {
    width: 100%;
    max-width: 100%;
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
}

.manager-card table {
    width: 100%;
    min-width: 0;
    table-layout: fixed;
}

.manager-card th,
.manager-card td {
    padding: 9px 7px !important;
    overflow-wrap: anywhere;
}

/* 1. Mã sản phẩm */
.manager-card th:nth-child(1),
.manager-card td:nth-child(1) {
    width: 9%;
}

/* 2. Ảnh */
.manager-card th:nth-child(2),
.manager-card td:nth-child(2) {
    width: 7%;
}

/* 3. Sản phẩm */
.manager-card th:nth-child(3),
.manager-card td:nth-child(3) {
    width: 20%;
}

/* 4. Danh mục */
.manager-card th:nth-child(4),
.manager-card td:nth-child(4) {
    width: 10%;
}

/* 5. Giá */
.manager-card th:nth-child(5),
.manager-card td:nth-child(5) {
    width: 17%;
}

/* 6. Tồn kho */
.manager-card th:nth-child(6),
.manager-card td:nth-child(6) {
    width: 9%;
}

/* 7. Trạng thái */
.manager-card th:nth-child(7),
.manager-card td:nth-child(7) {
    width: 9%;
}

/* 8. Thứ tự */
.manager-card th:nth-child(8),
.manager-card td:nth-child(8) {
    width: 12%;
}

/* 9. Hành động */
.manager-card th:nth-child(9),
.manager-card td:nth-child(9) {
    width: 7%;
}

.product-image,
.no-image {
    width: 54px;
    height: 54px;
    border-radius: 10px;
}

.product-name {
    max-width: none;
    min-width: 0;
    font-size: 13px;
    line-height: 1.35;
}

.product-code {
    max-width: 100%;
    padding: 4px 6px;
    overflow: hidden;
    font-size: 10.5px;
    text-overflow: ellipsis;
}

.price-box {
    min-width: 0;
}

.price-original,
.price-normal,
.price-sale {
    white-space: normal;
}

.price-normal,
.price-sale {
    font-size: 12.5px;
}

.price-original {
    font-size: 10.5px;
}

.price-label,
.price-note {
    font-size: 9px;
}

.meta-badge {
    margin-top: 3px;
    margin-right: 2px;
    padding: 3px 5px;
    font-size: 9px;
}

.stock-pill,
.status-pill {
    padding: 5px 7px;
    font-size: 9.5px;
    white-space: normal;
    text-align: center;
}

.display-order-form {
    min-width: 0;
}

.display-order-wrap {
    gap: 4px;
}

.display-order-input {
    width: 52px;
    min-width: 52px;
    height: 31px;
    padding-left: 4px;
    padding-right: 4px;
    font-size: 11px;
}

.display-order-save {
    height: 31px;
    padding: 0 7px;
    font-size: 10px;
}

.display-order-note {
    font-size: 8px;
}

.action-btn {
    width: 32px;
    height: 31px;
    padding: 0;
    border-radius: 8px;
    font-size: 12px;
}

/* Màn hình nhỏ: bảng tự cuộn trong card, không kéo cả trang */
@media (max-width: 1199.98px) {
    .manager-card table {
        width: 1080px;
        min-width: 1080px;
        table-layout: fixed;
    }

    .manager-card th:nth-child(1),
    .manager-card td:nth-child(1) { width: 105px; }

    .manager-card th:nth-child(2),
    .manager-card td:nth-child(2) { width: 72px; }

    .manager-card th:nth-child(3),
    .manager-card td:nth-child(3) { width: 205px; }

    .manager-card th:nth-child(4),
    .manager-card td:nth-child(4) { width: 110px; }

    .manager-card th:nth-child(5),
    .manager-card td:nth-child(5) { width: 175px; }

    .manager-card th:nth-child(6),
    .manager-card td:nth-child(6) { width: 95px; }

    .manager-card th:nth-child(7),
    .manager-card td:nth-child(7) { width: 100px; }

    .manager-card th:nth-child(8),
    .manager-card td:nth-child(8) { width: 135px; }

    .manager-card th:nth-child(9),
    .manager-card td:nth-child(9) { width: 83px; }
}

@media (max-width: 767.98px) {
    .product-manager > .container {
        padding-left: 6px;
        padding-right: 6px;
    }

    .filter-card {
        padding: 13px;
    }

    .manager-card table {
        width: 1030px;
        min-width: 1030px;
    }
}

</style>

<div class="product-manager py-3">
    <div class="container">

        <!-- =================================================
             HEADER
        ================================================== -->
        <div
            class="d-flex flex-wrap gap-3 justify-content-between align-items-center mb-3"
        >
            <div>
                <h3 class="fw-bold mb-1">
                    📦 Quản lý sản phẩm
                </h3>

                <div class="text-muted">
                    Theo dõi giá, tồn kho, biến thể, khuyến mãi và thông số kỹ thuật.
                </div>
            </div>

            <a
                href="them.php"
                class="btn btn-primary"
            >
                ➕ Thêm sản phẩm
            </a>
        </div>

        <?php if (($_GET['order'] ?? '') === 'updated'): ?>
            <div class="alert alert-success notice-card">
                ✅ Đã đổi vị trí sản phẩm. Các sản phẩm liên quan đã tự động dịch thứ tự.
            </div>
        <?php elseif (($_GET['order'] ?? '') === 'invalid'): ?>
            <div class="alert alert-warning notice-card">
                ⚠️ Thứ tự hiển thị phải là số lớn hơn 0.
            </div>
        <?php elseif (($_GET['order'] ?? '') === 'error'): ?>
            <div class="alert alert-danger notice-card">
                ❌ Không thể cập nhật thứ tự hiển thị.
            </div>
        <?php endif; ?>

        <?php if (!$coCotThuTu): ?>
            <div class="alert alert-warning notice-card">
                <strong>Chưa bật chức năng thứ tự hiển thị.</strong>
                Hãy chạy file SQL đi kèm để thêm cột
                <code>thu_tu_hien_thi</code>.
            </div>
        <?php endif; ?>

        <?php if (!$coCotMaSanPham): ?>
            <div class="alert alert-warning notice-card">
                <strong>Database chưa có cột mã sản phẩm.</strong>
                Hãy thêm cột <code>ma_san_pham</code> vào bảng
                <code>san_pham</code> để sử dụng mã dạng 8686xxxx.
            </div>
        <?php endif; ?>

        <?php if (($_GET['updated'] ?? '') === '1'): ?>
            <div class="alert alert-success notice-card">
                ✅ Cập nhật sản phẩm thành công.
            </div>
        <?php endif; ?>

        <?php if (($_GET['delete'] ?? '') === 'success'): ?>
            <div class="alert alert-success notice-card">
                ✅ Đã xóa hoàn toàn sản phẩm chưa phát sinh lịch sử đơn hàng.
            </div>
        <?php elseif (($_GET['delete'] ?? '') === 'archived'): ?>
            <div class="alert alert-warning notice-card">
                ⚠️ Sản phẩm đã từng phát sinh đơn hàng nên hệ thống
                <strong>không xóa lịch sử</strong>.
                Sản phẩm đã được chuyển sang trạng thái
                <strong>Ngừng bán</strong>.
            </div>
        <?php elseif (($_GET['delete'] ?? '') === 'not_found'): ?>
            <div class="alert alert-danger notice-card">
                Không tìm thấy sản phẩm cần xóa.
            </div>
        <?php endif; ?>

        <!-- =================================================
             BỘ LỌC
        ================================================== -->
        <form
            method="GET"
            class="filter-card mb-3"
        >
            <div class="row g-3 align-items-end">

                <div class="col-lg-5">
                    <label class="form-label fw-bold">
                        Tìm sản phẩm
                    </label>

                    <input
                        type="text"
                        name="q"
                        class="form-control"
                        value="<?= htmlspecialchars($tuKhoa) ?>"
                        placeholder="Nhập tên hoặc mã sản phẩm 8686xxxx..."
                    >
                </div>

                <div class="col-lg-3">
                    <label class="form-label fw-bold">
                        Danh mục
                    </label>

                    <select
                        name="danh_muc_id"
                        class="form-select"
                    >
                        <option value="0">
                            Tất cả danh mục
                        </option>

                        <?php foreach ($danhMucList as $dm): ?>

                            <option
                                value="<?= (int)$dm['id'] ?>"
                                <?= $danhMucId === (int)$dm['id']
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= htmlspecialchars($dm['ten_danh_muc']) ?>
                            </option>

                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-2">
                    <label class="form-label fw-bold">
                        Trạng thái
                    </label>

                    <select
                        name="trang_thai"
                        class="form-select"
                    >
                        <option value="">
                            Tất cả
                        </option>

                        <option
                            value="1"
                            <?= $trangThai === '1'
                                ? 'selected'
                                : '' ?>
                        >
                            Đang bán
                        </option>

                        <option
                            value="0"
                            <?= $trangThai === '0'
                                ? 'selected'
                                : '' ?>
                        >
                            Ngừng bán
                        </option>
                    </select>
                </div>

                <div class="col-lg-2 d-flex gap-2">
                    <button
                        type="submit"
                        class="btn btn-primary flex-fill"
                    >
                        🔎 Lọc
                    </button>

                    <a
                        href="index.php"
                        class="btn btn-outline-secondary"
                    >
                        Xóa
                    </a>
                </div>

            </div>
        </form>

        <!-- =================================================
             DANH SÁCH
        ================================================== -->
        <div class="card manager-card">
            <div
                class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2"
            >
                <strong>
                    Danh sách sản phẩm
                </strong>

                <span class="text-muted small">
                    Hiển thị
                    <?= $from ?>
                    -
                    <?= $to ?>
                    /
                    <?= $totalRows ?>
                    sản phẩm
                </span>
            </div>

            <div class="card-body p-0">

                <?php if (empty($products)): ?>

                    <div class="text-center py-5">
                        <div class="fs-3 mb-2">
                            📭
                        </div>

                        <div class="fw-bold">
                            Không tìm thấy sản phẩm
                        </div>

                        <div class="text-muted small mt-1">
                            Hãy thử thay đổi bộ lọc.
                        </div>
                    </div>

                <?php else: ?>

                    <div class="table-responsive">

                        <table class="table table-hover align-middle mb-0">

                            <thead class="table-dark">
                                <tr>
                                    <th>
                                        Mã sản phẩm
                                    </th>

                                    <th>
                                        Ảnh
                                    </th>

                                    <th>
                                        Sản phẩm
                                    </th>

                                    <th>
                                        Danh mục
                                    </th>

                                    <th>
                                        Giá
                                    </th>

                                    <th>
                                        Tồn kho
                                    </th>

                                    <th>
                                        Trạng thái
                                    </th>

                                    <th class="text-center">
                                        Thứ tự hiển thị
                                    </th>

                                    <th class="text-end">
                                        Hành động
                                    </th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($products as $sp): ?>

                                    <?php
                                    $coBienThe =
                                        (int)$sp['tong_bien_the'] > 0;

                                    if ($coBienThe) {
                                        $giaMin =
                                            (float)$sp['gia_thap_nhat'];

                                        $giaMax =
                                            (float)$sp['gia_cao_nhat'];

                                        $tonKho =
                                            (int)$sp['tong_ton_bien_the'];
                                    } else {
                                        $giaMin =
                                            (float)$sp['gia'];

                                        $giaMax =
                                            (float)$sp['gia'];

                                        $tonKho =
                                            (int)$sp['so_luong'];
                                    }

                                    /*
                                        Giá hiển thị:
                                        - Có biến thể:
                                          dùng khoảng giá gốc của biến thể.
                                          Giá KM đã tính theo KM riêng từng
                                          biến thể; nếu không có thì fallback
                                          sang KM chung của sản phẩm.
                                        - Không có biến thể:
                                          dùng giá sản phẩm + KM chung.
                                    */
                                    if ($coBienThe) {
                                        $giaKhuyenMaiMin =
                                            (float)(
                                                $sp[
                                                    'gia_km_thap_nhat'
                                                ]
                                                ?? $giaMin
                                            );

                                        $giaKhuyenMaiMax =
                                            (float)(
                                                $sp[
                                                    'gia_km_cao_nhat'
                                                ]
                                                ?? $giaMax
                                            );
                                    } else {
                                        $giaKhuyenMaiMin =
                                            tinhGiaKhuyenMai(
                                                $giaMin,
                                                $sp[
                                                    'km_sp_kieu_giam'
                                                ],
                                                (float)(
                                                    $sp[
                                                        'km_sp_gia_tri'
                                                    ]
                                                    ?? 0
                                                )
                                            );

                                        $giaKhuyenMaiMax =
                                            $giaKhuyenMaiMin;
                                    }

                                    $hetHang =
                                        $tonKho <= 0;

                                    if ($hetHang) {
                                        $giaKhuyenMaiMin = $giaMin;
                                        $giaKhuyenMaiMax = $giaMax;
                                    }

                                    $coKhuyenMai =
                                        !$hetHang &&
                                        (
                                            $giaKhuyenMaiMin <
                                                $giaMin - 0.01
                                            ||
                                            $giaKhuyenMaiMax <
                                                $giaMax - 0.01
                                        );
                                    ?>

                                    <tr class="<?= $hetHang ? 'product-row-out' : '' ?>">

                                        <!-- MÃ SẢN PHẨM -->
                                        <td>
                                            <?php
                                            $maSanPham = trim(
                                                (string)($sp['ma_san_pham'] ?? '')
                                            );
                                            ?>

                                            <?php if ($maSanPham !== ''): ?>

                                                <span class="product-code">
                                                    <?= htmlspecialchars(
                                                        $maSanPham
                                                    ) ?>
                                                </span>

                                            <?php else: ?>

                                                <span class="product-code product-code-empty">
                                                    Chưa có mã
                                                </span>

                                            <?php endif; ?>
                                        </td>

                                        <!-- ẢNH -->
                                        <td>

                                            <?php if (!empty($sp['hinh_anh'])): ?>

                                                <img
                                                    src="../../uploads/<?= htmlspecialchars($sp['hinh_anh']) ?>"
                                                    class="product-image"
                                                    alt=""
                                                >

                                            <?php else: ?>

                                                <div class="no-image">
                                                    No image
                                                </div>

                                            <?php endif; ?>

                                        </td>

                                        <!-- TÊN -->
                                        <td>

                                            <div class="product-name">
                                                <?= htmlspecialchars($sp['ten_san_pham']) ?>
                                            </div>

                                            <?php if ($hetHang): ?>
                                                <div class="out-of-stock-note">
                                                    Tạm hết hàng · Khuyến mãi tạm ngừng áp dụng
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($coBienThe): ?>

                                                <span class="meta-badge">
                                                    <?= (int)$sp['tong_bien_the'] ?>
                                                    biến thể
                                                </span>

                                            <?php endif; ?>

                                            <?php if ((int)$sp['tong_thong_so'] > 0): ?>

                                                <span class="meta-badge spec-badge">
                                                    ⚙
                                                    <?= (int)$sp['tong_thong_so'] ?>
                                                    thông số
                                                </span>

                                            <?php else: ?>

                                                <span class="meta-badge">
                                                    Chưa có thông số
                                                </span>

                                            <?php endif; ?>

                                            <?php if (
                                                !$hetHang &&
                                                (int)$sp['tong_km_dang_chay'] > 0
                                            ): ?>

                                                <span class="meta-badge promo-badge">
                                                    🏷
                                                    <?= (int)$sp['tong_km_dang_chay'] ?>
                                                    KM đang chạy
                                                </span>

                                            <?php endif; ?>

                                        </td>

                                        <!-- DANH MỤC -->
                                        <td>
                                            <span class="badge bg-info text-dark">
                                                <?= htmlspecialchars($sp['ten_danh_muc']) ?>
                                            </span>
                                        </td>

                                        <!-- GIÁ -->
                                        <td>
                                            <div class="price-box">

                                                <?php if ($coKhuyenMai): ?>

                                                    <div class="price-label">
                                                        Giá gốc
                                                    </div>

                                                    <div class="price-original">
                                                        <?php if (
                                                            abs(
                                                                $giaMax -
                                                                $giaMin
                                                            ) < 0.01
                                                        ): ?>
                                                            <?= number_format(
                                                                $giaMin,
                                                                0,
                                                                ',',
                                                                '.'
                                                            ) ?>₫
                                                        <?php else: ?>
                                                            <?= number_format(
                                                                $giaMin,
                                                                0,
                                                                ',',
                                                                '.'
                                                            ) ?>₫
                                                            -
                                                            <?= number_format(
                                                                $giaMax,
                                                                0,
                                                                ',',
                                                                '.'
                                                            ) ?>₫
                                                        <?php endif; ?>
                                                    </div>

                                                    <div class="price-label mt-2">
                                                        Giá khuyến mãi
                                                    </div>

                                                    <div class="price-sale">
                                                        <?php if (
                                                            abs(
                                                                $giaKhuyenMaiMax -
                                                                $giaKhuyenMaiMin
                                                            ) < 0.01
                                                        ): ?>
                                                            <?= number_format(
                                                                $giaKhuyenMaiMin,
                                                                0,
                                                                ',',
                                                                '.'
                                                            ) ?>₫
                                                        <?php else: ?>
                                                            <?= number_format(
                                                                $giaKhuyenMaiMin,
                                                                0,
                                                                ',',
                                                                '.'
                                                            ) ?>₫
                                                            -
                                                            <?= number_format(
                                                                $giaKhuyenMaiMax,
                                                                0,
                                                                ',',
                                                                '.'
                                                            ) ?>₫
                                                        <?php endif; ?>
                                                    </div>

                                                    <?php if (
                                                        !empty(
                                                            $sp['km_sp_ten']
                                                        ) &&
                                                        !$coBienThe
                                                    ): ?>
                                                        <div class="price-note">
                                                            <?= htmlspecialchars(
                                                                (string)$sp[
                                                                    'km_sp_ten'
                                                                ]
                                                            ) ?>
                                                        </div>
                                                    <?php elseif (
                                                        $coBienThe
                                                    ): ?>
                                                        <div class="price-note">
                                                            Giá thực tế theo biến thể
                                                            và khuyến mãi đang áp dụng.
                                                        </div>
                                                    <?php endif; ?>

                                                <?php else: ?>

                                                    <div class="price-label">
                                                        <?= $hetHang
                                                            ? 'Giá niêm yết'
                                                            : 'Giá bán' ?>
                                                    </div>

                                                    <div class="price-normal">
                                                        <?php if (
                                                            abs(
                                                                $giaMax -
                                                                $giaMin
                                                            ) < 0.01
                                                        ): ?>
                                                            <?= number_format(
                                                                $giaMin,
                                                                0,
                                                                ',',
                                                                '.'
                                                            ) ?>₫
                                                        <?php else: ?>
                                                            <?= number_format(
                                                                $giaMin,
                                                                0,
                                                                ',',
                                                                '.'
                                                            ) ?>₫
                                                            -
                                                            <?= number_format(
                                                                $giaMax,
                                                                0,
                                                                ',',
                                                                '.'
                                                            ) ?>₫
                                                        <?php endif; ?>
                                                    </div>

                                                    <?php if ($coBienThe): ?>
                                                        <div class="price-note">
                                                            Theo biến thể
                                                        </div>
                                                    <?php endif; ?>

                                                <?php endif; ?>

                                            </div>
                                        </td>

                                        <!-- TỒN -->
                                        <td>

                                            <?php if ($tonKho <= 0): ?>

                                                <span class="stock-pill out">
                                                    ⚠ Tạm hết hàng
                                                </span>

                                            <?php elseif ($tonKho <= 2): ?>

                                                <span class="stock-pill low">
                                                    ⚠ Còn <?= $tonKho ?>
                                                </span>

                                            <?php else: ?>

                                                <span class="stock-pill ok">
                                                    ● Còn <?= $tonKho ?>
                                                </span>

                                            <?php endif; ?>

                                        </td>

                                        <!-- TRẠNG THÁI -->
                                        <td>

                                            <?php if ((int)$sp['trang_thai'] === 1): ?>

                                                <span class="status-pill active">
                                                    ● Đang bán
                                                </span>

                                            <?php else: ?>

                                                <span class="status-pill inactive">
                                                    Ngừng bán
                                                </span>

                                            <?php endif; ?>

                                        </td>

                                        <!-- THỨ TỰ HIỂN THỊ -->
                                        <td>
                                            <?php if ($coCotThuTu): ?>
                                                <form
                                                    method="POST"
                                                    action="cap_nhat_thu_tu.php"
                                                    class="display-order-form"
                                                >
                                                    <input
                                                        type="hidden"
                                                        name="id"
                                                        value="<?= (int)$sp['id'] ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="page"
                                                        value="<?= (int)$page ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="q"
                                                        value="<?= htmlspecialchars($tuKhoa) ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="danh_muc_id"
                                                        value="<?= (int)$danhMucId ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="trang_thai"
                                                        value="<?= htmlspecialchars($trangThai) ?>"
                                                    >

                                                    <div class="display-order-wrap">
                                                        <input
                                                            type="number"
                                                            name="thu_tu_hien_thi"
                                                            class="form-control form-control-sm display-order-input"
                                                            min="1"
                                                            max="999999"
                                                            value="<?= max(
                                                                1,
                                                                (int)($sp['thu_tu_hien_thi'] ?? 9999)
                                                            ) ?>"
                                                            title="Số nhỏ hơn sẽ hiển thị trước"
                                                            required
                                                        >

                                                        <button
                                                            type="submit"
                                                            class="btn btn-sm btn-outline-primary display-order-save"
                                                            title="Lưu thứ tự hiển thị"
                                                        >
                                                            Lưu
                                                        </button>
                                                    </div>

                                                    <div class="display-order-note">
                                                        Đổi vị trí · danh sách tự dịch
                                                    </div>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted small">
                                                    Chưa bật
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- HÀNH ĐỘNG -->
                                        <td class="text-end">

                                            <!-- <a
                                                href="../../user/san_pham/chi_tiet.php?id=<?= (int)$sp['id'] ?>"
                                                class="btn btn-sm btn-outline-primary action-btn"
                                                title="Xem sản phẩm"
                                                target="_blank"
                                            >
                                                👁
                                            </a> -->

                                            <a
                                                href="sua.php?id=<?= (int)$sp['id'] ?>"
                                                class="btn btn-sm btn-warning action-btn"
                                                title="Sửa sản phẩm"
                                            >
                                                ✏️
                                            </a>

                                            <a
                                                href="xoa.php?id=<?= (int)$sp['id'] ?>"
                                                class="btn btn-sm btn-danger action-btn"
                                                title="Xóa / Ngừng bán"
                                                onclick="return confirm(
                                                    'Bạn có chắc muốn xử lý sản phẩm này?\n\n' +
                                                    'Nếu sản phẩm chưa từng có đơn hàng: hệ thống sẽ xóa hoàn toàn.\n' +
                                                    'Nếu sản phẩm đã từng được mua: hệ thống chỉ chuyển sang Ngừng bán để giữ lịch sử.'
                                                )"
                                            >
                                                🗑
                                            </a>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>
                        </table>

                    </div>

                <?php endif; ?>

            </div>

            <!-- =================================================
                 PHÂN TRANG
            ================================================== -->
            <?php if ($totalPages > 1): ?>

                <div class="card-footer bg-white py-3">

                    <nav>
                        <ul class="pagination justify-content-center mb-0">

                            <li
                                class="page-item <?= $page <= 1
                                    ? 'disabled'
                                    : '' ?>"
                            >
                                <a
                                    class="page-link"
                                    href="<?= htmlspecialchars(
                                        pageUrl(
                                            $page - 1,
                                            $tuKhoa,
                                            $danhMucId,
                                            $trangThai
                                        )
                                    ) ?>"
                                >
                                    ‹
                                </a>
                            </li>

                            <?php
                            $startPage =
                                max(
                                    1,
                                    $page - 2
                                );

                            $endPage =
                                min(
                                    $totalPages,
                                    $page + 2
                                );
                            ?>

                            <?php if ($startPage > 1): ?>

                                <li class="page-item">
                                    <a
                                        class="page-link"
                                        href="<?= htmlspecialchars(
                                            pageUrl(
                                                1,
                                                $tuKhoa,
                                                $danhMucId,
                                                $trangThai
                                            )
                                        ) ?>"
                                    >
                                        1
                                    </a>
                                </li>

                                <?php if ($startPage > 2): ?>
                                    <li class="page-item disabled">
                                        <span class="page-link">
                                            ...
                                        </span>
                                    </li>
                                <?php endif; ?>

                            <?php endif; ?>

                            <?php for (
                                $p = $startPage;
                                $p <= $endPage;
                                $p++
                            ): ?>

                                <li
                                    class="page-item <?= $p === $page
                                        ? 'active'
                                        : '' ?>"
                                >
                                    <a
                                        class="page-link"
                                        href="<?= htmlspecialchars(
                                            pageUrl(
                                                $p,
                                                $tuKhoa,
                                                $danhMucId,
                                                $trangThai
                                            )
                                        ) ?>"
                                    >
                                        <?= $p ?>
                                    </a>
                                </li>

                            <?php endfor; ?>

                            <?php if ($endPage < $totalPages): ?>

                                <?php if ($endPage < $totalPages - 1): ?>
                                    <li class="page-item disabled">
                                        <span class="page-link">
                                            ...
                                        </span>
                                    </li>
                                <?php endif; ?>

                                <li class="page-item">
                                    <a
                                        class="page-link"
                                        href="<?= htmlspecialchars(
                                            pageUrl(
                                                $totalPages,
                                                $tuKhoa,
                                                $danhMucId,
                                                $trangThai
                                            )
                                        ) ?>"
                                    >
                                        <?= $totalPages ?>
                                    </a>
                                </li>

                            <?php endif; ?>

                            <li
                                class="page-item <?= $page >= $totalPages
                                    ? 'disabled'
                                    : '' ?>"
                            >
                                <a
                                    class="page-link"
                                    href="<?= htmlspecialchars(
                                        pageUrl(
                                            $page + 1,
                                            $tuKhoa,
                                            $danhMucId,
                                            $trangThai
                                        )
                                    ) ?>"
                                >
                                    ›
                                </a>
                            </li>

                        </ul>
                    </nav>

                </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once "../../includes/footer.php"; ?>
