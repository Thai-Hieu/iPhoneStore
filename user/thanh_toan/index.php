<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../middleware/check_user.php";
require_once "../../config/database.php";
require_once "../../includes/khuyen_mai_helper.php";

/* =========================================================
   CHỈ MỞ CHECKOUT TỪ GIỎ HÀNG

   Trang này nhận danh sách cart_ids bằng POST.
   Nếu truy cập trực tiếp bằng URL thì quay lại giỏ hàng.
========================================================= */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header(
        "Location: /iPhoneStore/user/gio_hang/index.php"
    );
    exit;
}

if (!function_exists('layCotAnhBienThe')) {
    function layCotAnhBienThe(mysqli $conn): ?string
    {
        $result = $conn->query("
            SHOW COLUMNS
            FROM san_pham_bien_the_hinh_anh
        ");

        if (!$result) {
            return null;
        }

        $columns = [];

        while ($row = $result->fetch_assoc()) {
            $columns[] = (string)($row['Field'] ?? '');
        }

        /*
         * Database hiện tại của iPhoneStore dùng duong_dan_anh.
         * Các tên sau chỉ là fallback để không làm checkout chết
         * nếu tên cột ảnh từng được đổi ở một bản DB khác.
         */
        foreach (
            [
                'duong_dan_anh',
                'hinh_anh',
                'anh',
                'image'
            ]
            as $candidate
        ) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }
}

/* =========================================================
   CSRF + TOKEN MỘT LẦN CHO CHECKOUT

   csrf_checkout:
   - Chống request giả mạo.

   checkout_token:
   - Token riêng cho lần đặt hàng hiện tại.
   - Sau khi đơn được tạo thành công, server sẽ hủy token.
   - Giúp giảm nguy cơ gửi lại form tạo trùng đơn.
========================================================= */
if (empty($_SESSION['csrf_checkout'])) {
    $_SESSION['csrf_checkout'] =
        bin2hex(random_bytes(32));
}

$_SESSION['checkout_token'] =
    bin2hex(random_bytes(32));

$csrfCheckout =
    (string)$_SESSION['csrf_checkout'];

$checkoutToken =
    (string)$_SESSION['checkout_token'];

/* =========================================================
   KIỂM TRA CỘT MÃ SẢN PHẨM

   Trang thanh toán chỉ hiển thị ma_san_pham.
   Dữ liệu giỏ và đơn hàng vẫn liên kết bằng san_pham_id.
========================================================= */
$resultMaSanPhamColumn = $conn->query("
    SHOW COLUMNS
    FROM san_pham
    LIKE 'ma_san_pham'
");

$coCotMaSanPham =
    $resultMaSanPhamColumn &&
    $resultMaSanPhamColumn->num_rows > 0;

$maSanPhamSelect = $coCotMaSanPham
    ? 'sp.ma_san_pham,'
    : 'NULL AS ma_san_pham,';

/* =========================================================
   USER + CART IDS
========================================================= */
$nguoiDungId =
    (int)($_SESSION['user']['id'] ?? 0);

if ($nguoiDungId <= 0) {
    die("❌ Bạn chưa đăng nhập");
}

/* =========================================================
   THÔNG TIN NGƯỜI NHẬN

   Đây là dữ liệu được điền sẵn từ hồ sơ tài khoản.
   Khách có thể chỉnh lại ngay tại bước thanh toán.

   Khi tạo đơn, xu_ly_thanh_toan.php sẽ lưu bản sao vào:
   - don_hang.ten_nguoi_nhan
   - don_hang.so_dien_thoai_nhan
   - don_hang.dia_chi_nhan

   Nhờ vậy đơn cũ không bị thay đổi nếu khách sửa hồ sơ sau này.
========================================================= */
$stmtUserInfo = $conn->prepare("
    SELECT
        ho_ten,
        email,
        so_dien_thoai,
        dia_chi
    FROM nguoi_dung
    WHERE id = ?
    LIMIT 1
");

if (!$stmtUserInfo) {
    die(
        "Không thể đọc thông tin người nhận: " .
        htmlspecialchars($conn->error)
    );
}

$stmtUserInfo->bind_param(
    "i",
    $nguoiDungId
);

$stmtUserInfo->execute();

$nguoiDung = $stmtUserInfo
    ->get_result()
    ->fetch_assoc();

if (!$nguoiDung) {
    die("❌ Không tìm thấy thông tin tài khoản");
}

$tenNguoiNhanMacDinh =
    trim(
        (string)($nguoiDung['ho_ten'] ?? '')
    );

$soDienThoaiNhanMacDinh =
    trim(
        (string)($nguoiDung['so_dien_thoai'] ?? '')
    );

$diaChiNhanMacDinh =
    trim(
        (string)($nguoiDung['dia_chi'] ?? '')
    );

$emailTaiKhoan =
    trim(
        (string)($nguoiDung['email'] ?? '')
    );

$cartIds =
    array_map(
        'intval',
        (array)($_POST['cart_ids'] ?? [])
    );

$cartIds =
    array_values(
        array_unique(
            array_filter(
                $cartIds,
                fn($id) => $id > 0
            )
        )
    );

if (empty($cartIds)) {
    die("
        <h4 style='text-align:center;margin-top:40px'>
            ⚠️ Vui lòng chọn sản phẩm để đặt hàng
        </h4>
    ");
}

if (count($cartIds) > 100) {
    die("
        <h4 style='text-align:center;margin-top:40px'>
            ❌ Có quá nhiều sản phẩm trong một lần đặt hàng
        </h4>
    ");
}

/*
    Ảnh ở trang thanh toán:
    - ưu tiên ảnh chính của biến thể khách đã chọn;
    - nếu biến thể chưa có ảnh riêng thì dùng ảnh đại diện sản phẩm.
*/
$cotAnhBienThe =
    layCotAnhBienThe($conn);

if ($cotAnhBienThe !== null) {
    $maAnhBienTheSelect = "
        (
            SELECT
                ha.`{$cotAnhBienThe}`

            FROM san_pham_bien_the_hinh_anh ha

            WHERE ha.bien_the_id =
                gh.bien_the_id

            ORDER BY
                ha.la_anh_chinh DESC,
                ha.thu_tu ASC,
                ha.id ASC

            LIMIT 1
        ) AS hinh_anh_bien_the,
    ";
} else {
    $maAnhBienTheSelect = "
        NULL AS hinh_anh_bien_the,
    ";
}

/* =========================================================
   LẤY CÁC DÒNG GIỎ ĐƯỢC CHỌN
========================================================= */
$placeholders =
    implode(
        ',',
        array_fill(
            0,
            count($cartIds),
            '?'
        )
    );

$types =
    str_repeat(
        'i',
        count($cartIds)
    ) . 'i';

$params =
    array_merge(
        $cartIds,
        [$nguoiDungId]
    );

$sql = "
    SELECT
        gh.id AS gio_hang_id,
        gh.san_pham_id,
        gh.so_luong,
        gh.mau_id,
        gh.bien_the_id,
        gh.gia AS gia_luu_trong_gio,

        $maSanPhamSelect
        sp.ten_san_pham,

        $maAnhBienTheSelect

        sp.hinh_anh AS hinh_anh_san_pham,
        sp.gia AS gia_san_pham,
        sp.so_luong AS ton_kho_san_pham,
        sp.trang_thai AS trang_thai_san_pham,

        ms.ten_mau,
        ms.ma_mau,

        spm.mau_id AS mau_san_pham_hop_le,

        bt.id AS bien_the_hop_le,
        bt.mau_id AS mau_id_bien_the,
        bt.ram,
        bt.rom,
        bt.gia AS gia_bien_the,
        bt.so_luong AS ton_kho_bien_the,

        (
            SELECT COUNT(*)
            FROM san_pham_bien_the bt2
            WHERE bt2.san_pham_id = sp.id
        ) AS tong_bien_the,

        (
            SELECT COUNT(*)
            FROM san_pham_mau spm2
            WHERE spm2.san_pham_id = sp.id
        ) AS tong_mau

    FROM gio_hang gh

    JOIN san_pham sp
        ON sp.id = gh.san_pham_id

    LEFT JOIN mau_sac ms
        ON ms.id = gh.mau_id

    LEFT JOIN san_pham_mau spm
        ON spm.san_pham_id = gh.san_pham_id
        AND spm.mau_id = gh.mau_id

    LEFT JOIN san_pham_bien_the bt
        ON bt.id = gh.bien_the_id
        AND bt.san_pham_id = gh.san_pham_id
        AND (bt.mau_id <=> gh.mau_id)

    WHERE gh.id IN ($placeholders)
    AND gh.nguoi_dung_id = ?

    ORDER BY gh.id ASC
";

$stmtCart = $conn->prepare($sql);

if (!$stmtCart) {
    die(
        "Lỗi prepare SQL: " .
        htmlspecialchars($conn->error)
    );
}

$stmtCart->bind_param(
    $types,
    ...$params
);

$stmtCart->execute();

$cartRows = $stmtCart
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

if (count($cartRows) !== count($cartIds)) {
    die("
        <h4 style='text-align:center;margin-top:40px'>
            ❌ Một số sản phẩm trong giỏ hàng không hợp lệ
        </h4>
    ");
}

/* =========================================================
   CHUẨN HÓA DỮ LIỆU
========================================================= */
$tongGiaGocDuKien = 0;
foreach ($cartRows as $grossRow) {
    $grossPrice = $grossRow['gia_bien_the'] !== null ? (float)$grossRow['gia_bien_the'] : (float)$grossRow['gia_san_pham'];
    $tongGiaGocDuKien += $grossPrice * max(1,(int)$grossRow['so_luong']);
}
$items = [];
$stockGroups = [];

$tongGiaGoc = 0;
$tongGiamGia = 0;
$tongTien = 0;

$coLoiThanhToan = false;
$thongBaoLoi = [];

foreach ($cartRows as $row) {
    $gioHangId =
        (int)$row['gio_hang_id'];

    $sanPhamId =
        (int)$row['san_pham_id'];

    $soLuong =
        (int)$row['so_luong'];

    $mauId =
        $row['mau_id'] !== null
            ? (int)$row['mau_id']
            : null;

    $bienTheId =
        $row['bien_the_id'] !== null
            ? (int)$row['bien_the_id']
            : null;

    $coBienThe =
        (int)$row['tong_bien_the'] > 0;

    $coMau =
        (int)$row['tong_mau'] > 0;

    $hopLe = true;
    $lyDoKhongHopLe = '';

    /* -----------------------------------------------------
       SẢN PHẨM CÒN BÁN
    ----------------------------------------------------- */
    if (
        (int)$row['trang_thai_san_pham'] !== 1
    ) {
        $hopLe = false;
        $lyDoKhongHopLe =
            'Sản phẩm hiện không được bán';
    }

    /* -----------------------------------------------------
       BIẾN THỂ / MÀU
    ----------------------------------------------------- */
    if ($hopLe && $coBienThe) {
        if (
            $bienTheId === null ||
            $mauId === null ||
            empty($row['bien_the_hop_le'])
        ) {
            $hopLe = false;
            $lyDoKhongHopLe =
                'Màu hoặc phiên bản không còn hợp lệ';
        }
    }

    if (
        $hopLe &&
        !$coBienThe &&
        $coMau
    ) {
        if (
            $mauId === null ||
            $row['mau_san_pham_hop_le'] === null
        ) {
            $hopLe = false;
            $lyDoKhongHopLe =
                'Màu sản phẩm không còn hợp lệ';
        }
    }

    /* -----------------------------------------------------
       GIÁ GỐC + KHO
    ----------------------------------------------------- */
    if (
        $coBienThe &&
        $hopLe
    ) {
        $giaGoc =
            (float)$row['gia_bien_the'];

        $tonKho =
            (int)$row['ton_kho_bien_the'];
    } else {
        $giaGoc =
            (float)$row['gia_san_pham'];

        $tonKho =
            (int)$row['ton_kho_san_pham'];
    }

    /* -----------------------------------------------------
       KHUYẾN MÃI HIỆN TẠI
    ----------------------------------------------------- */
    $khuyenMai = null;

    if ($hopLe) {
        $khuyenMai =
            layKhuyenMaiHienTai(
                $conn,
                $sanPhamId,
                $coBienThe ? $bienTheId : null,
                [
                    'gia_goc' => $giaGoc,
                    'so_luong' => $soLuong,
                    'tong_don_goc' => $tongGiaGocDuKien,
                    'ton_kho' => $tonKho,
                    'nguoi_dung_id' => $nguoiDungId
                ]
            );
    }

    $giaBan =
        tinhGiaSauKhuyenMai(
            $giaGoc,
            $khuyenMai
        );

    $giaDaLuu =
        (float)$row['gia_luu_trong_gio'];

    $giaDaThayDoi =
        abs(
            $giaDaLuu -
            $giaBan
        ) >= 0.01;

    /* -----------------------------------------------------
       NHÓM TỒN KHO
    ----------------------------------------------------- */
    if ($coBienThe) {
        $stockKey =
            'variant:' .
            ($bienTheId ?? 0);
    } else {
        $stockKey =
            'product:' .
            $sanPhamId;
    }

    if (!isset($stockGroups[$stockKey])) {
        $stockGroups[$stockKey] = [
            'ton_kho' => $tonKho,
            'so_luong_can' => 0,
            'ten_san_pham' => $row['ten_san_pham'],
            'ram' => $row['ram'],
            'rom' => $row['rom'],
            'co_bien_the' => $coBienThe
        ];
    }

    $stockGroups[$stockKey]['so_luong_can'] +=
        $soLuong;

    $thanhTien =
        $giaBan *
        $soLuong;

    $hinhAnhBienThe =
        trim(
            (string)(
                $row['hinh_anh_bien_the']
                ?? ''
            )
        );

    $hinhAnhSanPham =
        trim(
            (string)(
                $row['hinh_anh_san_pham']
                ?? ''
            )
        );

    /*
        Luôn ưu tiên đúng ảnh của biến thể đã chọn.
        Nếu biến thể chưa có ảnh riêng thì fallback về ảnh sản phẩm.
    */
    $hinhAnhHienThi =
        $hinhAnhBienThe !== ''
            ? $hinhAnhBienThe
            : $hinhAnhSanPham;

    if ($hopLe) {
        $tongGiaGoc +=
            $giaGoc * $soLuong;

        $tongGiamGia +=
            max(
                0,
                ($giaGoc - $giaBan) * $soLuong
            );

        $tongTien +=
            $thanhTien;
    }

    $items[] = [
        'gio_hang_id' => $gioHangId,
        'san_pham_id' => $sanPhamId,
        'ma_san_pham' => $row['ma_san_pham'] ?? null,
        'ten_san_pham' => $row['ten_san_pham'],
        'hinh_anh' => $hinhAnhHienThi,
        'hinh_anh_bien_the' => $hinhAnhBienThe,
        'hinh_anh_san_pham' => $hinhAnhSanPham,

        'mau_id' => $mauId,
        'ten_mau' => $row['ten_mau'],
        'ma_mau' => $row['ma_mau'],

        'bien_the_id' => $bienTheId,
        'ram' => $row['ram'],
        'rom' => $row['rom'],

        'so_luong' => $soLuong,
        'ton_kho' => $tonKho,

        'gia_goc' => $giaGoc,
        'gia_ban' => $giaBan,
        'gia_da_thay_doi' => $giaDaThayDoi,
        'thanh_tien' => $thanhTien,

        'khuyen_mai' => $khuyenMai,

        'hop_le' => $hopLe,
        'ly_do_khong_hop_le' => $lyDoKhongHopLe
    ];

    if (!$hopLe) {
        $coLoiThanhToan = true;

        $thongBaoLoi[] =
            $row['ten_san_pham'] .
            ': ' .
            $lyDoKhongHopLe;
    }
}

/* =========================================================
   KIỂM TRA TỔNG TỒN KHO THEO NHÓM
========================================================= */
foreach ($stockGroups as $group) {
    $tonKho =
        (int)$group['ton_kho'];

    $soLuongCan =
        (int)$group['so_luong_can'];

    if ($soLuongCan > $tonKho) {
        $coLoiThanhToan = true;

        if ($group['co_bien_the']) {
            $thongBaoLoi[] =
                $group['ten_san_pham'] .
                ' ' .
                ($group['ram'] ?? '') .
                ' / ' .
                ($group['rom'] ?? '') .
                ': cần ' .
                $soLuongCan .
                ', hiện chỉ còn ' .
                $tonKho;
        } else {
            $thongBaoLoi[] =
                $group['ten_san_pham'] .
                ': cần ' .
                $soLuongCan .
                ', hiện chỉ còn ' .
                $tonKho;
        }
    }
}

$thongBaoLoi =
    array_values(
        array_unique(
            $thongBaoLoi
        )
    );

?>

<?php include "../../includes/header.php"; ?>
<?php include "../../includes/navbar.php"; ?>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
>

<style>
body {
    background: #f5f5f5;
}

.card-box {
    background: #fff;
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, .08);
}

.order-item {
    padding: 14px 0;
    border-bottom: 1px solid #eee;
}

.order-item:last-child {
    border-bottom: 0;
}

.product-image {
    width: 72px;
    height: 72px;
    flex-shrink: 0;
    object-fit: contain;
    border: 1px solid #eee;
    border-radius: 10px;
    background: #fff;
}

.product-name {
    font-weight: 700;
    color: #222;
}

.product-meta {
    color: #777;
    font-size: 13px;
}

.product-code {
    display: inline-flex;
    align-items: center;
    margin-top: 5px;
    padding: 4px 8px;
    border: 1px solid #ffd9cc;
    border-radius: 7px;
    background: #fff5f1;
    color: #ee4d2d;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .5px;
}

.product-code-empty {
    border-color: #e3e5e8;
    background: #f6f7f9;
    color: #8b8f97;
    font-weight: 600;
    letter-spacing: 0;
}

.checkout-notice {
    border: 0;
    border-radius: 12px;
    box-shadow: 0 5px 18px rgba(0, 0, 0, .04);
}

.original-price {
    color: #999;
    text-decoration: line-through;
    font-size: 12px;
}

.current-price {
    color: #ee4d2d;
    font-weight: 700;
}

.promo-mini {
    display: inline-block;
    margin-top: 5px;
    padding: 5px 8px;
    border: 1px dashed #ee4d2d;
    border-radius: 7px;
    background: #fff8f5;
    color: #555;
    font-size: 12px;
}

.price-changed {
    margin-top: 4px;
    color: #b26a00;
    font-size: 12px;
}

.payment-method {
    border: 1px solid #ddd;
    border-radius: 12px;
    padding: 15px;
    cursor: pointer;
    transition: .2s;
    margin-bottom: 10px;
    background: #fff;
}

.payment-method:hover {
    border-color: #dc3545;
}

.payment-method.active {
    border-color: #dc3545;
    background: #fff5f5;
    box-shadow: 0 0 0 1px #dc3545 inset;
}

.wallet-box {
    display: none;
    margin-bottom: 12px;
}

.wallet-box.show {
    display: block;
}

.wallet-item {
    border: 1px solid #ddd;
    border-radius: 10px;
    padding: 12px 8px;
    text-align: center;
    cursor: pointer;
    transition: .2s;
    background: #fff;
}

.wallet-item:hover {
    border-color: #dc3545;
}

.wallet-item.active {
    border-color: #dc3545;
    background: #fff5f5;
    color: #dc3545;
    font-weight: 700;
}

.summary-line {
    display: flex;
    justify-content: space-between;
    gap: 15px;
    margin-bottom: 7px;
}

.checkout-summary {
    margin-top: 18px;
    padding: 18px;
    border: 1px solid #f0e3de;
    border-radius: 14px;
    background: linear-gradient(
        180deg,
        #fffaf7 0%,
        #ffffff 100%
    );
}

.checkout-summary-title {
    margin-bottom: 14px;
    font-size: 16px;
    font-weight: 800;
    color: #333;
}

.checkout-summary-row {
    display: grid;
    grid-template-columns: 1fr auto;
    align-items: center;
    column-gap: 25px;
    margin-bottom: 11px;
}

.checkout-summary-row:last-child {
    margin-bottom: 0;
}

.checkout-summary-label {
    color: #666;
    font-size: 14px;
}

.checkout-summary-value {
    color: #333;
    font-weight: 700;
    white-space: nowrap;
    text-align: right;
}

.checkout-summary-discount {
    color: #ee4d2d;
}

.checkout-summary-divider {
    border-top: 1px dashed #dfd5d0;
    margin: 15px 0;
}

.checkout-summary-total-label {
    color: #222;
    font-size: 17px;
    font-weight: 800;
}

.checkout-summary-total {
    color: #ee4d2d;
    font-size: 27px;
    font-weight: 800;
    white-space: nowrap;
}

.checkout-saving {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-top: 12px;
    padding: 7px 11px;
    border-radius: 999px;
    background: #edf9f0;
    color: #198754;
    font-size: 13px;
    font-weight: 700;
}

.item-total-old {
    color: #999;
    font-size: 12px;
    text-decoration: line-through;
    white-space: nowrap;
}

.item-total-current {
    color: #ee4d2d;
    font-size: 17px;
    font-weight: 800;
    white-space: nowrap;
}

.total-price {
    color: #ee4d2d;
    font-size: 25px;
    font-weight: 700;
}

.payment-note {
    border-radius: 10px;
    padding: 12px;
    background: #f8f9fa;
    color: #666;
    font-size: 13px;
}

.invalid-item {
    opacity: .65;
    background: #fff7e6;
    border-radius: 10px;
    padding-left: 8px;
    padding-right: 8px;
}

.color-dot {
    display: inline-block;
    margin-right: 3px;
}

.text-truncate-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.payment-demo-banner {
    margin-top: 14px;
    padding: 11px 13px;
    border: 1px solid #dbe5f3;
    border-radius: 11px;
    background: #f8fbff;
    color: #536273;
    font-size: 12px;
    line-height: 1.55;
}

.payment-status-preview {
    display: none;
    margin-top: 12px;
    padding: 12px 14px;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    background: #f8f9fa;
    color: #555;
    font-size: 13px;
    line-height: 1.55;
}

.payment-status-preview.show {
    display: block;
}

.payment-status-preview strong {
    color: #222;
}

.payment-status-preview.online {
    border-color: #ffe0a6;
    background: #fff9ed;
}

.payment-status-preview.cod {
    border-color: #cfe8d7;
    background: #f2fbf5;
}

.payment-security-note {
    margin-top: 12px;
    padding: 10px 12px;
    border-radius: 10px;
    background: #f5f7fa;
    color: #70757a;
    font-size: 12px;
    line-height: 1.55;
}

.qr-warning-box {
    margin: 12px 0;
    padding: 10px 12px;
    border: 1px solid #ffe0a6;
    border-radius: 10px;
    background: #fff9ed;
    color: #75550b;
    font-size: 12px;
    line-height: 1.55;
}

.wallet-demo-note {
    margin: 10px 0 12px;
    padding: 10px 12px;
    border-radius: 10px;
    background: #f8f9fa;
    color: #666;
    font-size: 12px;
    line-height: 1.55;
}

.payment-flow-note {
    margin-top: 14px;
    padding: 14px 15px;
    border: 1px solid #dbe4f0;
    border-radius: 12px;
    background: #f8fbff;
    color: #536273;
    font-size: 12px;
    line-height: 1.65;
}

.payment-flow-note strong {
    color: #263747;
}

.payment-flow-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
    margin-top: 10px;
}

.payment-flow-item {
    padding: 9px 10px;
    border: 1px solid #e7edf4;
    border-radius: 9px;
    background: #fff;
}

/* =========================================================
   THÔNG TIN NHẬN HÀNG
========================================================= */
.receiver-box {
    margin-bottom: 24px;
}

.receiver-title {
    font-size: 17px;
    font-weight: 800;
    color: #222;
}

.receiver-subtitle {
    margin-top: 5px;
    color: #777;
    font-size: 13px;
    line-height: 1.55;
}

.receiver-label {
    margin-bottom: 6px;
    font-size: 13px;
    font-weight: 700;
    color: #333;
}

.receiver-required {
    color: #dc3545;
}

.receiver-box .form-control {
    min-height: 46px;
    border-radius: 10px;
}

.receiver-box textarea.form-control {
    min-height: 100px;
    resize: vertical;
}

.receiver-note {
    margin-top: 12px;
    padding: 10px 12px;
    border: 1px solid #dbe5f3;
    border-radius: 10px;
    background: #f8fbff;
    color: #607080;
    font-size: 12px;
    line-height: 1.55;
}

@media (max-width: 576px) {
    .payment-flow-grid {
        grid-template-columns: 1fr;
    }
}

/* =========================================================
   GIAO DIỆN CHECKOUT GỌN - CÂN ĐỐI
========================================================= */
body {
    background:
        linear-gradient(
            180deg,
            #f7f8fb 0%,
            #f4f5f7 100%
        );
}

.container.mt-4.mb-5 {
    max-width: 1320px;
}

.container.mt-4.mb-5 > h4 {
    margin-bottom: 22px !important;
    color: #1f2937;
    font-size: 28px;
    font-weight: 850;
}

.card-box {
    border: 1px solid #eceef2;
    border-radius: 20px;
    padding: 24px;
    box-shadow:
        0 10px 35px rgba(15, 23, 42, .055);
}

.section-heading {
    display: flex;
    align-items: center;
    gap: 12px;
}

.section-heading-icon {
    width: 42px;
    height: 42px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 42px;
    border-radius: 12px;
    background: #fff4f1;
    font-size: 20px;
}

.section-heading-title {
    color: #222;
    font-size: 19px;
    line-height: 1.25;
    font-weight: 850;
}

.section-heading-subtitle {
    margin-top: 2px;
    color: #8a8f98;
    font-size: 12px;
}

.receiver-box {
    margin-bottom: 24px;
}

.receiver-label {
    margin-bottom: 7px;
    color: #3c4046;
    font-size: 13px;
    font-weight: 750;
}

.receiver-box .form-control {
    min-height: 48px;
    border: 1px solid #dfe3e8;
    border-radius: 12px;
    padding-left: 14px;
    padding-right: 14px;
    background: #fff;
    box-shadow: none;
}

.receiver-box .form-control:focus {
    border-color: #ee4d2d;
    box-shadow: 0 0 0 3px rgba(238, 77, 45, .08);
}

.receiver-box textarea.form-control {
    min-height: 86px;
    padding-top: 12px;
    resize: vertical;
}

.receiver-readonly {
    background: #f7f8fa !important;
    color: #666;
}

.order-item {
    padding: 17px 0;
}

.checkout-summary {
    margin-top: 20px;
    padding: 19px;
    border: 1px solid #f2e2dc;
    border-radius: 16px;
}

.checkout-summary-total {
    font-size: 30px;
}

.payment-card {
    position: sticky;
    top: 18px;
}

.payment-method-list {
    display: grid;
    gap: 11px;
}

.payment-method {
    min-height: 68px;
    margin-bottom: 0;
    padding: 12px 14px;
    display: grid;
    grid-template-columns: 42px minmax(0, 1fr) 28px;
    align-items: center;
    gap: 12px;
    border: 1px solid #e2e5e9;
    border-radius: 14px;
    background: #fff;
    cursor: pointer;
    transition:
        border-color .18s ease,
        background .18s ease,
        transform .18s ease,
        box-shadow .18s ease;
}

.payment-method:hover {
    border-color: #ffb29f;
    background: #fffaf8;
    transform: translateY(-1px);
}

.payment-method.active {
    border-color: #ee4d2d;
    background: #fff7f4;
    box-shadow:
        0 0 0 2px rgba(238, 77, 45, .08);
}

.payment-method-icon {
    width: 42px;
    height: 42px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 11px;
    background: #f7f8fa;
    font-size: 21px;
}

.payment-method.active .payment-method-icon {
    background: #ffece6;
}

.payment-method-title {
    color: #25272b;
    font-size: 15px;
    font-weight: 800;
}

.payment-method-code {
    display: inline-flex;
    margin-left: 5px;
    padding: 2px 6px;
    border-radius: 999px;
    background: #f1f3f5;
    color: #777;
    font-size: 10px;
    vertical-align: middle;
}

.payment-method-check {
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #d9dde2;
    border-radius: 50%;
    color: transparent;
    font-size: 13px;
    font-weight: 900;
}

.payment-method.active .payment-method-check {
    border-color: #ee4d2d;
    background: #ee4d2d;
    color: #fff;
}

.wallet-box {
    margin: -1px 0 2px;
    padding: 12px;
    border: 1px solid #eceef2;
    border-radius: 14px;
    background: #fafbfc;
}

.wallet-title {
    margin-bottom: 9px;
    color: #777;
    font-size: 12px;
    font-weight: 700;
}

.wallet-item {
    padding: 11px 8px;
    border: 1px solid #e0e3e7;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
}

.wallet-item.active {
    border-color: #ee4d2d;
    background: #fff1ed;
    color: #ee4d2d;
}

.selected-payment-box {
    margin-top: 16px;
    padding: 12px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    border: 1px dashed #dfe3e8;
    border-radius: 12px;
    background: #fafbfc;
    color: #7a7f87;
    font-size: 13px;
}

.selected-payment-box strong {
    color: #25272b;
    text-align: right;
}

.checkout-submit-btn {
    width: 100%;
    min-height: 50px;
    margin-top: 15px;
    border-radius: 12px;
    font-size: 16px;
    font-weight: 800;
}

.payment-note,
.payment-security-note,
.payment-flow-note,
.payment-status-preview,
.wallet-demo-note,
.receiver-note {
    display: none !important;
}

@media (max-width: 991.98px) {
    .payment-card {
        position: static;
    }
}

@media (max-width: 767.98px) {
    .card-box {
        padding: 18px;
        border-radius: 16px;
    }

    .container.mt-4.mb-5 > h4 {
        font-size: 24px;
    }

    .checkout-summary-total {
        font-size: 25px;
    }
}


/* =========================================================
   FIX CHECKOUT - KHUYẾN MÃI + PHƯƠNG THỨC THANH TOÁN
========================================================= */
.promo-mini {
    display: block;
    max-width: 100%;
    margin-top: 7px;
    padding: 8px 10px;
    border: 1px dashed #ee4d2d;
    border-radius: 9px;
    background: #fff8f5;
    color: #5f6670;
    font-size: 11px;
    line-height: 1.5;
}

.promo-mini-title {
    color: #e52b3e;
    font-size: 11.5px;
    line-height: 1.4;
    font-weight: 850;
}

.promo-mini-reason {
    margin-top: 3px;
    color: #69717c;
}

.promo-mini-time {
    margin-top: 3px;
    color: #7a818b;
}

.promo-mini-time strong {
    color: #59616b;
    font-weight: 800;
}

</style>

<div class="container mt-4 mb-5">

    <h4 class="mb-3">
        💳 Thanh toán  
    </h4>

    <?php if (!$coCotMaSanPham): ?>
        <div class="alert alert-warning checkout-notice">
            Database chưa có cột <code>san_pham.ma_san_pham</code>.
            Thanh toán vẫn hoạt động nhưng chưa thể hiển thị mã sản phẩm.
        </div>
    <?php endif; ?>

    <?php if ($coLoiThanhToan): ?>

        <div class="alert alert-danger">

            <div class="fw-bold mb-2">
                ❌ Chưa thể tạo đơn hàng này
            </div>

            <?php foreach ($thongBaoLoi as $loi): ?>

                <div>
                    • <?= htmlspecialchars($loi) ?>
                </div>

            <?php endforeach; ?>

            <a
                href="../gio_hang/index.php"
                class="btn btn-outline-danger btn-sm mt-3"
            >
                Quay lại giỏ hàng
            </a>
        </div>

    <?php endif; ?>

    
    <div class="card-box receiver-box mb-4">

        <div class="section-heading">
            <div class="section-heading-icon">📍</div>

            <div>
                <div class="section-heading-title">
                    Thông tin nhận hàng
                </div>
                <div class="section-heading-subtitle">
                    Kiểm tra thông tin trước khi đặt hàng
                </div>
            </div>
        </div>

        <div class="row g-3 mt-1">

            <div class="col-lg-4 col-md-6">
                <label
                    for="tenNguoiNhan"
                    class="receiver-label"
                >
                    Họ và tên
                    <span class="receiver-required">*</span>
                </label>

                <input
                    type="text"
                    id="tenNguoiNhan"
                    name="ten_nguoi_nhan"
                    form="paymentForm"
                    class="form-control"
                    maxlength="100"
                    autocomplete="name"
                    required
                    value="<?= htmlspecialchars(
                        $tenNguoiNhanMacDinh,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                >
            </div>

            <div class="col-lg-4 col-md-6">
                <label
                    for="soDienThoaiNhan"
                    class="receiver-label"
                >
                    Số điện thoại
                    <span class="receiver-required">*</span>
                </label>

                <input
                    type="tel"
                    id="soDienThoaiNhan"
                    name="so_dien_thoai_nhan"
                    form="paymentForm"
                    class="form-control"
                    maxlength="20"
                    autocomplete="tel"
                    inputmode="tel"
                    required
                    value="<?= htmlspecialchars(
                        $soDienThoaiNhanMacDinh,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                >
            </div>

            <div class="col-lg-4 col-md-12">
                <label class="receiver-label">
                    Email tài khoản
                </label>

                <input
                    type="email"
                    class="form-control receiver-readonly"
                    value="<?= htmlspecialchars(
                        $emailTaiKhoan,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                    readonly
                >
            </div>

            <div class="col-12">
                <label
                    for="diaChiNhan"
                    class="receiver-label"
                >
                    Địa chỉ nhận hàng
                    <span class="receiver-required">*</span>
                </label>

                <textarea
                    id="diaChiNhan"
                    name="dia_chi_nhan"
                    form="paymentForm"
                    class="form-control"
                    maxlength="500"
                    autocomplete="street-address"
                    required
                    placeholder="Nhập số nhà, đường, phường/xã, quận/huyện, tỉnh/thành"
                ><?= htmlspecialchars(
                    $diaChiNhanMacDinh,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?></textarea>
            </div>

        </div>
    </div>

    <div class="row g-4">

        
        <div class="col-lg-6">

            <div class="card-box">

                <h5>
                    📦 Đơn hàng
                </h5>

                <hr>

                <?php foreach ($items as $item): ?>

                    <div
                        class="order-item <?= !$item['hop_le']
                            ? 'invalid-item'
                            : '' ?>"
                    >

                        <div class="d-flex gap-3">

                            <?php if (!empty($item['hinh_anh'])): ?>

                                <img
                                    src="../../uploads/<?= htmlspecialchars(
                                        $item['hinh_anh'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                    class="product-image"
                                    alt="<?= htmlspecialchars(
                                        $item['ten_san_pham'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                    title="<?= !empty(
                                        $item['hinh_anh_bien_the']
                                    )
                                        ? 'Ảnh đúng theo biến thể đã chọn'
                                        : 'Ảnh đại diện sản phẩm' ?>"
                                >

                            <?php else: ?>

                                <div
                                    class="product-image d-flex align-items-center justify-content-center text-muted small"
                                >
                                    No image
                                </div>

                            <?php endif; ?>

                            <div class="flex-grow-1">

                                <div class="product-name text-truncate-2">
                                    <?= htmlspecialchars($item['ten_san_pham']) ?>
                                </div>

                                <?php
                                $maSanPham = trim(
                                    (string)($item['ma_san_pham'] ?? '')
                                );
                                ?>

                                <?php if ($maSanPham !== ''): ?>

                                    <div class="product-code">
                                        Mã SP:
                                        <?= htmlspecialchars($maSanPham) ?>
                                    </div>

                                <?php else: ?>

                                    <div class="product-code product-code-empty">
                                        Mã SP: Chưa có
                                    </div>

                                <?php endif; ?>

                                <div class="product-meta mt-1">

                                    <?php if (!empty($item['ten_mau'])): ?>

                                        Màu:
                                        <b>
                                            <span
                                                class="color-dot"
                                                style="color:<?= htmlspecialchars($item['ma_mau'] ?? '#777') ?>"
                                            >
                                                ●
                                            </span>

                                            <?= htmlspecialchars($item['ten_mau']) ?>
                                        </b>

                                    <?php endif; ?>

                                    <?php if (!empty($item['bien_the_id'])): ?>

                                        <?php if (!empty($item['ten_mau'])): ?>
                                            |
                                        <?php endif; ?>

                                        Phiên bản:
                                        <b>
                                            <?= htmlspecialchars($item['ram'] ?? '') ?>
                                            /
                                            <?= htmlspecialchars($item['rom'] ?? '') ?>
                                        </b>

                                    <?php endif; ?>

                                </div>

                                <div class="small mt-2">

                                    <?php if (
                                        $item['gia_ban'] <
                                        $item['gia_goc']
                                    ): ?>

                                        <span class="original-price me-1">
                                            <?= number_format(
                                                $item['gia_goc'],
                                                0,
                                                ',',
                                                '.'
                                            ) ?>đ
                                        </span>

                                    <?php endif; ?>

                                    <span class="current-price">
                                        <?= number_format(
                                            $item['gia_ban'],
                                            0,
                                            ',',
                                            '.'
                                        ) ?>đ
                                    </span>

                                    ×
                                    <?= (int)$item['so_luong'] ?>

                                </div>

                                <?php if ($item['khuyen_mai']): ?>

                                    <?php
                                    $promo =
                                        $item['khuyen_mai'];
                                    ?>

                                    <div class="promo-mini">

                                        <div class="promo-mini-title">
                                            🏷
                                            <?= htmlspecialchars(
                                                $promo['ten_khuyen_mai']
                                                ?: tenLoaiKhuyenMai(
                                                    $promo['loai_khuyen_mai']
                                                )
                                            ) ?>
                                        </div>

                                        <?php if (!empty($promo['ly_do'])): ?>
                                            <div class="promo-mini-reason">
                                                <?= htmlspecialchars(
                                                    $promo['ly_do']
                                                ) ?>
                                            </div>
                                        <?php endif; ?>

                                        <div class="promo-mini-time">
                                            <?php if (
                                                ($promo['loai_khuyen_mai'] ?? '') === 'flash_sale' &&
                                                !empty($promo['gio_bat_dau']) &&
                                                !empty($promo['gio_ket_thuc'])
                                            ): ?>
                                                Mỗi ngày
                                                <strong>
                                                    <?= htmlspecialchars(
                                                        substr(
                                                            (string)$promo['gio_bat_dau'],
                                                            0,
                                                            5
                                                        )
                                                    ) ?>
                                                    →
                                                    <?= htmlspecialchars(
                                                        substr(
                                                            (string)$promo['gio_ket_thuc'],
                                                            0,
                                                            5
                                                        )
                                                    ) ?>
                                                </strong>
                                                · đến ngày
                                                <strong>
                                                    <?= date(
                                                        'd/m/Y',
                                                        strtotime(
                                                            $promo['ngay_ket_thuc']
                                                        )
                                                    ) ?>
                                                </strong>
                                            <?php else: ?>
                                                Áp dụng đến
                                                <strong>
                                                    <?= date(
                                                        'd/m/Y H:i',
                                                        strtotime(
                                                            $promo['ngay_ket_thuc']
                                                        )
                                                    ) ?>
                                                </strong>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                <?php endif; ?>

                                <?php if ($item['gia_da_thay_doi']): ?>

                                    <div class="price-changed">
                                        Giá hiện tại đã thay đổi so với lúc thêm vào giỏ.
                                    </div>

                                <?php endif; ?>

                                <?php if (!$item['hop_le']): ?>

                                    <div class="text-danger fw-bold small mt-2">
                                        ❌
                                        <?= htmlspecialchars(
                                            $item['ly_do_khong_hop_le']
                                        ) ?>
                                    </div>

                                <?php endif; ?>

                            </div>

                            <div class="text-end">

                                <?php
                                $tongGiaGocItem =
                                    (float)$item['gia_goc'] *
                                    (int)$item['so_luong'];
                                ?>

                                <?php if (
                                    $item['gia_ban'] <
                                    $item['gia_goc']
                                ): ?>

                                    <div class="item-total-old">
                                        <?= number_format(
                                            $tongGiaGocItem,
                                            0,
                                            ',',
                                            '.'
                                        ) ?>đ
                                    </div>

                                <?php endif; ?>

                                <div class="item-total-current">
                                    <?= number_format(
                                        $item['thanh_tien'],
                                        0,
                                        ',',
                                        '.'
                                    ) ?>đ
                                </div>

                            </div>

                        </div>
                    </div>

                <?php endforeach; ?>

                <div class="checkout-summary">

                    <div class="checkout-summary-title">
                        🧾 Tóm tắt đơn hàng
                    </div>

                    <div class="checkout-summary-row">
                        <span class="checkout-summary-label">
                            Số dòng sản phẩm
                        </span>

                        <span class="checkout-summary-value">
                            <?= count($items) ?>
                        </span>
                    </div>

                    <div class="checkout-summary-row">
                        <span class="checkout-summary-label">
                            Giá gốc sản phẩm
                        </span>

                        <span class="checkout-summary-value">
                            <?= number_format(
                                $tongGiaGoc,
                                0,
                                ',',
                                '.'
                            ) ?>đ
                        </span>
                    </div>

                    <div class="checkout-summary-row">
                        <span class="checkout-summary-label">
                            Giảm giá sản phẩm
                        </span>

                        <span
                            class="checkout-summary-value checkout-summary-discount"
                        >
                            <?php if ($tongGiamGia > 0): ?>

                                -<?= number_format(
                                    $tongGiamGia,
                                    0,
                                    ',',
                                    '.'
                                ) ?>đ

                            <?php else: ?>

                                0đ

                            <?php endif; ?>
                        </span>
                    </div>

                    <div class="checkout-summary-divider"></div>

                    <div class="checkout-summary-row">
                        <span class="checkout-summary-total-label">
                            Tổng đơn hàng
                        </span>

                        <span class="checkout-summary-total">
                            <?= number_format(
                                $tongTien,
                                0,
                                ',',
                                '.'
                            ) ?>đ
                        </span>
                    </div>

                    <?php if ($tongGiamGia > 0): ?>

                        <div class="text-end">
                            <span class="checkout-saving">
                                ✓ Tiết kiệm
                                <?= number_format(
                                    $tongGiamGia,
                                    0,
                                    ',',
                                    '.'
                                ) ?>đ
                            </span>
                        </div>

                    <?php endif; ?>

                    <div class="checkout-summary-divider"></div>

                    <div class="checkout-summary-row">
                        <span class="checkout-summary-label">
                            Phương thức thanh toán
                        </span>

                        <span
                            id="ptText"
                            class="checkout-summary-value"
                        >
                            Chưa chọn
                        </span>
                    </div>

                </div>
            </div>
        </div>

        
        <div class="col-lg-6">

            <div class="card-box payment-card">

                <div class="section-heading">
                    <div class="section-heading-icon">💳</div>

                    <div>
                        <div class="section-heading-title">
                            Phương thức thanh toán
                        </div>
                        <div class="section-heading-subtitle">
                            Chọn một hình thức thanh toán để  
                        </div>
                    </div>
                </div>

                
                <div class="payment-demo-banner">
                    ℹ️ Đây là thanh toán   cho đồ án.
                    Hệ thống không thực hiện giao dịch tiền thật.
                </div>

                <div class="payment-method-list mt-3">

                    <div
                        class="payment-method"
                        onclick="chonPT('COD', this)"
                    >
                        <div class="payment-method-icon">
                            🚚
                        </div>

                        <div class="payment-method-title">
                            Thanh toán khi nhận hàng
                            <span class="payment-method-code">
                                COD
                            </span>
                        </div>

                        <div class="payment-method-check">
                            ✓
                        </div>
                    </div>

                    <div
                        class="payment-method"
                        onclick="chonPT('VI', this)"
                    >
                        <div class="payment-method-icon">
                            📱
                        </div>

                        <div class="payment-method-title">
                            Ví điện tử
                        </div>

                        <div class="payment-method-check">
                            ✓
                        </div>
                    </div>

                    <div
                        class="wallet-box"
                        id="viBox"
                    >
                        <div class="wallet-title">
                            Chọn ví
                        </div>

                        <div class="row g-2">
                            <div class="col-6">
                                <div
                                    class="wallet-item"
                                    onclick="chonVi('MoMo', this, event)"
                                >
                                    MoMo
                                </div>
                            </div>

                            <div class="col-6">
                                <div
                                    class="wallet-item"
                                    onclick="chonVi('ZaloPay', this, event)"
                                >
                                    ZaloPay
                                </div>
                            </div>

                            <div class="col-6">
                                <div
                                    class="wallet-item"
                                    onclick="chonVi('VNPay', this, event)"
                                >
                                    VNPay
                                </div>
                            </div>

                            <div class="col-6">
                                <div
                                    class="wallet-item"
                                    onclick="chonVi('ShopeePay', this, event)"
                                >
                                    ShopeePay
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        class="payment-method"
                        onclick="chonPT('QR', this)"
                    >
                        <div class="payment-method-icon">
                            ▦
                        </div>

                        <div class="payment-method-title">
                            Chuyển khoản bằng QR
                        </div>

                        <div class="payment-method-check">
                            ✓
                        </div>
                    </div>

                </div>

                <button
                    type="button"
                    id="confirmPaymentButton"
                    class="btn btn-danger checkout-submit-btn"
                    onclick="xacNhan()"
                    <?= $coLoiThanhToan
                        ? 'disabled'
                        : '' ?>
                >
                    Đặt hàng
                </button>

            </div>

            </div>
        </div>

    </div>
</div>


<form
    id="paymentForm"
    action="xu_ly_thanh_toan.php"
    method="POST"
>
    <?php foreach ($cartIds as $cartId): ?>

        <input
            type="hidden"
            name="cart_ids[]"
            value="<?= (int)$cartId ?>"
        >

    <?php endforeach; ?>

    <input
        type="hidden"
        name="phuong_thuc"
        id="phuongThucInput"
        value=""
    >

    <input
        type="hidden"
        name="csrf_token"
        value="<?= htmlspecialchars($csrfCheckout) ?>"
    >

    <input
        type="hidden"
        name="checkout_token"
        value="<?= htmlspecialchars($checkoutToken) ?>"
    >
</form>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>
let phuongThuc = "";
let vi = "";

function capNhatTrangThaiThanhToan() {
    const button =
        document.getElementById(
            "confirmPaymentButton"
        );

    if (!button) {
        return;
    }

    /*
        Giao diện hiển thị lựa chọn thanh toán  .
        Server chỉ lưu phương thức và trạng thái  ,
        không thực hiện giao dịch tiền thật.
    */
    if (phuongThuc === "COD") {
        button.innerText =
            "Đặt hàng COD";
        return;
    }

    if (phuongThuc === "QR") {
        button.innerText =
            "Đặt hàng bằng QR";
        return;
    }

    if (phuongThuc === "VI") {
        button.innerText =
            vi
                ? "  qua " + vi
                : "Chọn ví điện tử";
        return;
    }

    button.innerText =
        "Đặt hàng";
}

/* =========================================================
   CHỌN PHƯƠNG THỨC
========================================================= */
function chonPT(pt, element) {
    document
        .querySelectorAll(
            ".payment-method"
        )
        .forEach(function (item) {
            item.classList.remove(
                "active"
            );
        });

    element.classList.add(
        "active"
    );

    phuongThuc = pt;

    if (pt !== "VI") {
        vi = "";

        document
            .querySelectorAll(
                ".wallet-item"
            )
            .forEach(function (item) {
                item.classList.remove(
                    "active"
                );
            });
    }

    const walletBox =
        document.getElementById(
            "viBox"
        );

    walletBox.classList.toggle(
        "show",
        pt === "VI"
    );

    if (pt === "COD") {
        document.getElementById(
            "ptText"
        ).innerText =
            "Thanh toán khi nhận hàng";
    } else if (pt === "QR") {
        document.getElementById(
            "ptText"
        ).innerText =
            "Chuyển khoản QR";
    } else {
        document.getElementById(
            "ptText"
        ).innerText =
            "Ví điện tử - chưa chọn ví";
    }

    capNhatTrangThaiThanhToan();
}

/* =========================================================
   CHỌN VÍ
========================================================= */
function chonVi(
    wallet,
    element,
    event
) {
    event.stopPropagation();

    document
        .querySelectorAll(
            ".wallet-item"
        )
        .forEach(function (item) {
            item.classList.remove(
                "active"
            );
        });

    element.classList.add(
        "active"
    );

    vi = wallet;

    document.getElementById(
        "ptText"
    ).innerText =
        "Ví - " + wallet;

    capNhatTrangThaiThanhToan();
}

/* =========================================================
   KIỂM TRA THÔNG TIN NHẬN HÀNG
========================================================= */
function kiemTraThongTinNhanHang() {
    const tenInput =
        document.getElementById(
            "tenNguoiNhan"
        );

    const phoneInput =
        document.getElementById(
            "soDienThoaiNhan"
        );

    const addressInput =
        document.getElementById(
            "diaChiNhan"
        );

    if (
        !tenInput ||
        !phoneInput ||
        !addressInput
    ) {
        alert(
            "Không tìm thấy thông tin nhận hàng."
        );
        return false;
    }

    const ten =
        tenInput.value.trim();

    const phoneRaw =
        phoneInput.value.trim();

    const address =
        addressInput.value.trim();

    if (ten.length < 2) {
        alert(
            "⚠️ Vui lòng nhập họ tên người nhận."
        );
        tenInput.focus();
        return false;
    }

    /*
        Cho phép người dùng nhập:
        0988888888
        0988 888 888
        0988-888-888
        +84988888888

        Server vẫn sẽ kiểm tra lại ở xu_ly_thanh_toan.php.
    */
    const phoneNormalized =
        phoneRaw.replace(
            /[\s.()-]/g,
            ""
        );

    const phoneValid =
        /^0\d{9}$/.test(
            phoneNormalized
        ) ||
        /^\+84\d{9}$/.test(
            phoneNormalized
        );

    if (!phoneValid) {
        alert(
            "⚠️ Số điện thoại nhận hàng chưa hợp lệ. " +
            "Ví dụ: 0988888888."
        );
        phoneInput.focus();
        return false;
    }

    if (address.length < 5) {
        alert(
            "⚠️ Vui lòng nhập địa chỉ nhận hàng đầy đủ."
        );
        addressInput.focus();
        return false;
    }

    /*
        Lưu số điện thoại ở dạng gọn,
        tránh khoảng trắng / dấu chấm / dấu gạch.
    */
    phoneInput.value =
        phoneNormalized;

    return true;
}

/* =========================================================
   XÁC NHẬN
========================================================= */
function xacNhan() {
    const button =
        document.getElementById(
            "confirmPaymentButton"
        );

    if (
        !button ||
        button.disabled
    ) {
        return;
    }

    if (!kiemTraThongTinNhanHang()) {
        return;
    }

    if (!phuongThuc) {
        alert(
            "⚠️ Vui lòng chọn phương thức thanh toán"
        );

        return;
    }

    if (
        phuongThuc === "VI" &&
        !vi
    ) {
        alert(
            "⚠️ Vui lòng chọn ví điện tử"
        );

        return;
    }

    let valueToSave =
        phuongThuc;

    if (phuongThuc === "VI") {
        valueToSave =
            "Ví - " + vi;
    }

    if (phuongThuc === "QR") {
        valueToSave =
            "QR";
    }

    document.getElementById(
        "phuongThucInput"
    ).value =
        valueToSave;

    if (phuongThuc === "QR") {
        if (
            !confirm(
                "Xác nhận tạo đơn với phương thức QR ( )? " +
                "Trạng thái thanh toán ban đầu sẽ là Chờ xác nhận."
            )
        ) {
            return;
        }

        submitPaymentForm();
        return;
    }

    if (phuongThuc === "COD") {
        if (
            !confirm(
                "Xác nhận tạo đơn COD  ?"
            )
        ) {
            return;
        }
    }

    if (phuongThuc === "VI") {
        if (
            !confirm(
                "Xác nhận tạo đơn với phương thức " +
                vi + " ( )? " +
                "Trạng thái thanh toán ban đầu sẽ là Chờ xác nhận."
            )
        ) {
            return;
        }
    }

    submitPaymentForm();
}

/* =========================================================
   SUBMIT CHỐNG BẤM 2 LẦN
========================================================= */
function submitPaymentForm() {
    if (!kiemTraThongTinNhanHang()) {
        return;
    }

    const form =
        document.getElementById(
            "paymentForm"
        );

    if (!form) {
        alert(
            "Không tìm thấy biểu mẫu thanh toán."
        );
        return;
    }

    /*
        Các ô thông tin nhận hàng dùng form="paymentForm",
        vì vậy checkValidity() vẫn kiểm tra đầy đủ required.
    */
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const button =
        document.getElementById(
            "confirmPaymentButton"
        );

    if (button) {
        button.disabled = true;
        button.innerText =
            "Đang xử lý...";
    }

    form.submit();
}

/* =========================================================
   ĐẾM NGƯỢC QR
========================================================= */
</script>

<?php include "../../includes/footer.php"; ?>
