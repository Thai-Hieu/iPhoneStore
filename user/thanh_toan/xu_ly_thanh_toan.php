<?php

if (session_status() === PHP_SESSION_NONE) {

    session_start();

}

require_once "../../middleware/check_user.php";

require_once "../../config/database.php";

require_once "../../includes/khuyen_mai_helper.php";

require_once "../../includes/notification_helper.php";

mysqli_report(

    MYSQLI_REPORT_ERROR |

    MYSQLI_REPORT_STRICT

);

/* =========================================================

   KIỂM TRA CẤU TRÚC THANH TOÁN

   Trạng thái chưa thanh toán/chờ xác nhận thì

   ngay_thanh_toan phải là NULL.

\========================================================= */

$resultPaymentDateColumn = $conn->query("

    SHOW COLUMNS

    FROM thanh_toan

    LIKE 'ngay_thanh_toan'

");

$paymentDateColumn =

    $resultPaymentDateColumn

        ? $resultPaymentDateColumn->fetch_assoc()

        : null;

if (!$paymentDateColumn) {

    die("❌ Bảng thanh_toan chưa có cột ngay_thanh_toan.");

}

$paymentDateNullable =

    strtoupper(

        (string)($paymentDateColumn['Null'] ?? 'NO')

    ) === 'YES';

if (!$paymentDateNullable) {

    die(

        "❌ Cột thanh_toan.ngay_thanh_toan cần cho phép NULL. " .

        "Hãy chạy file migration_thanh_toan_ngay_thanh_toan_nullable.sql trước."

    );

}

/* =========================================================

   KIỂM TRA CỘT MÃ SẢN PHẨM

   Không lưu ma_san_pham lặp lại trong chi_tiet_don_hang.

   Đơn hàng vẫn liên kết sản phẩm bằng san_pham_id.

   Mã sản phẩm chỉ được lấy để đối chiếu dữ liệu trong lúc

   xử lý thanh toán và phục vụ thông báo lỗi rõ ràng hơn.

\========================================================= */

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

   KIỂM TRA CỘT THÔNG TIN NHẬN HÀNG TRONG don_hang

   Trang checkout hiện gửi:

   - ten_nguoi_nhan

   - so_dien_thoai_nhan

   - dia_chi_nhan

   Các giá trị này phải được lưu cố định theo từng đơn.

\========================================================= */

$requiredOrderReceiverColumns = [

    'ten_nguoi_nhan',

    'so_dien_thoai_nhan',

    'dia_chi_nhan'

];

foreach (

    $requiredOrderReceiverColumns

    as $columnName

) {

    $safeColumnName =

        $conn->real_escape_string(

            $columnName

        );

    $resultColumn = $conn->query("

        SHOW COLUMNS

        FROM don_hang

        LIKE '{$safeColumnName}'

    ");

    if (

        !$resultColumn ||

        $resultColumn->num_rows <= 0

    ) {

        die(

            "❌ Bảng don_hang chưa có cột {$columnName}. " .

            "Hãy chạy ALTER TABLE bổ sung thông tin nhận hàng trước."

        );

    }

}

/* =========================================================

   HÀM HỖ TRỢ THÔNG TIN NGƯỜI NHẬN

\========================================================= */

function chuanHoaKhoangTrang(string $value): string {
    $value = trim($value);
    $normalized = preg_replace('/\s+/u', ' ', $value);
    return $normalized !== null ? $normalized : $value;
}

function doDaiChuoi(

    string $value

): int {

    if (function_exists('mb_strlen')) {

        return mb_strlen(

            $value,

            'UTF-8'

        );

    }

    return strlen($value);

}

/* =========================================================

   HÀM TÍNH GIÁ SAU KHUYẾN MÃI

\========================================================= */

/* =========================================================

   LẤY KHUYẾN MÃI ĐANG HIỆU LỰC CỦA BIẾN THỂ

   iPhoneStore hiện quản lý khuyến mãi theo từng biến thể.

   Không fallback sang khuyến mãi chung của sản phẩm.

   FOR UPDATE giúp khuyến mãi không bị thay đổi

   giữa lúc đang xử lý cùng một giao dịch thanh toán.

\========================================================= */

/* =========================================================

   CHUẨN HÓA PHƯƠNG THỨC THANH TOÁN

\========================================================= */

function chuanHoaPhuongThucThanhToan(

    string $raw

): array {

    $raw = trim($raw);

    $allowed = [

        'COD' => [

            'phuong_thuc' => 'COD',

            'loai' => 'cod'

        ],

        'QR' => [

            'phuong_thuc' => 'QR',

            'loai' => 'online'

        ],

        'Ví - MoMo' => [

            'phuong_thuc' => 'Ví - MoMo',

            'loai' => 'online'

        ],

        'Ví - ZaloPay' => [

            'phuong_thuc' => 'Ví - ZaloPay',

            'loai' => 'online'

        ],

        'Ví - VNPay' => [

            'phuong_thuc' => 'Ví - VNPay',

            'loai' => 'online'

        ],

        'Ví - ShopeePay' => [

            'phuong_thuc' => 'Ví - ShopeePay',

            'loai' => 'online'

        ]

    ];

    if (!isset($allowed[$raw])) {

        throw new Exception(

            "Phương thức thanh toán không hợp lệ."

        );

    }

    return $allowed[$raw];

}

/* =========================================================

   THÔNG BÁO ĐẶT HÀNG THEO KIỂU THANH TOÁN

\========================================================= */

function taoThongBaoDatHangTheoThanhToan(

    mysqli $conn,

    int $nguoiDungId,

    int $donHangId,

    string $paymentType,

    string $phuongThuc

): bool {

    $link =

        '/iPhoneStore/user/don_hang/chi_tiet.php?id=' .

        $donHangId;

    if ($paymentType === 'cod') {

        return taoThongBao(

            $conn,

            $nguoiDungId,

            'Đặt hàng thành công',

            'Đơn hàng của bạn đã được ghi nhận và đang chờ cửa hàng xử lý. ' .

            'Thanh toán COD đang ở trạng thái Chưa thanh toán ( ).',

            'dat_hang',

            $link,

            $donHangId

        );

    }

    return taoThongBao(

        $conn,

        $nguoiDungId,

        'Đặt hàng thành công',

        'Đơn hàng của bạn đã được ghi nhận. Thanh toán bằng ' .

        $phuongThuc .

        ' đang ở trạng thái Chờ xác nhận ( ).',

        'dat_hang',

        $link,

        $donHangId

    );

}

/* =========================================================

   DỮ LIỆU ĐẦU VÀO

\========================================================= */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header(

        "Location: /iPhoneStore/user/gio_hang/index.php"

    );

    exit;

}

$csrfToken =

    (string)($_POST['csrf_token'] ?? '');

$checkoutToken =

    (string)($_POST['checkout_token'] ?? '');

if (

    empty($_SESSION['csrf_checkout']) ||

    $csrfToken === '' ||

    !hash_equals(

        (string)$_SESSION['csrf_checkout'],

        $csrfToken

    )

) {

    die(

        "❌ Phiên thanh toán không hợp lệ. " .

        "Vui lòng quay lại giỏ hàng và thử lại."

    );

}

if (

    empty($_SESSION['checkout_token']) ||

    $checkoutToken === '' ||

    !hash_equals(

        (string)$_SESSION['checkout_token'],

        $checkoutToken

    )

) {

    die(

        "❌ Phiên đặt hàng đã hết hạn hoặc đã được sử dụng. " .

        "Vui lòng quay lại giỏ hàng."

    );

}

$nguoiDungId =

    (int)($_SESSION['user']['id'] ?? 0);

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

                fn($cartId) => $cartId > 0

            )

        )

    );

/*

    Sắp xếp ID trước khi khóa các dòng.

    Việc khóa theo cùng một thứ tự giúp giảm nguy cơ deadlock.

*/

sort($cartIds);

$phuongThucRaw =

    trim(

        (string)($_POST['phuong_thuc'] ?? '')

    );

/* =========================================================

   THÔNG TIN NHẬN HÀNG

   Không lấy lại từ hồ sơ tại thời điểm INSERT đơn.

   Dùng đúng dữ liệu khách đã kiểm tra/chỉnh ở trang checkout.

\========================================================= */

$tenNguoiNhan =

    chuanHoaKhoangTrang(

        (string)($_POST['ten_nguoi_nhan'] ?? '')

    );

$soDienThoaiNhan =

    trim(

        (string)($_POST['so_dien_thoai_nhan'] ?? '')

    );

$diaChiNhan =

    chuanHoaKhoangTrang(

        (string)($_POST['dia_chi_nhan'] ?? '')

    );

/*

    Loại bỏ các ký tự phân cách thường gặp trong SĐT.

    Ví dụ:

    0988 888 888

    0988-888-888

    0988.888.888

*/

$soDienThoaiNhan = preg_replace(
    '/[\s.()\-]+/',
    '',
    $soDienThoaiNhan
) ?? $soDienThoaiNhan;

if ($nguoiDungId <= 0) {

    die("❌ Bạn chưa đăng nhập");

}

if (empty($cartIds)) {

    die("❌ Đơn hàng không hợp lệ");

}

if (count($cartIds) > 100) {

    die("❌ Có quá nhiều sản phẩm trong một lần thanh toán.");

}

/* =========================================================

   KIỂM TRA THÔNG TIN NHẬN HÀNG Ở SERVER

   JavaScript chỉ hỗ trợ trải nghiệm.

   Backend vẫn bắt buộc kiểm tra lại để không thể bỏ qua bằng

   cách tự gửi POST trực tiếp.

\========================================================= */

if (

    $tenNguoiNhan === '' ||

    doDaiChuoi($tenNguoiNhan) < 2

) {

    die("❌ Vui lòng nhập họ tên người nhận hợp lệ.");

}

if (

    doDaiChuoi($tenNguoiNhan) > 100

) {

    die("❌ Họ tên người nhận không được vượt quá 100 ký tự.");

}

$soDienThoaiHopLe =
    preg_match('/^0\d{9}$/', $soDienThoaiNhan) === 1 ||
    preg_match('/^\+84\d{9}$/', $soDienThoaiNhan) === 1;

if (!$soDienThoaiHopLe) {

    die(

        "❌ Số điện thoại nhận hàng không hợp lệ. " .

        "Ví dụ: 0988888888."

    );

}

if (doDaiChuoi($soDienThoaiNhan) > 20) {

    die("❌ Số điện thoại nhận hàng quá dài.");

}

if (

    $diaChiNhan === '' ||

    doDaiChuoi($diaChiNhan) < 5

) {

    die("❌ Vui lòng nhập địa chỉ nhận hàng đầy đủ.");

}

if (doDaiChuoi($diaChiNhan) > 500) {

    die("❌ Địa chỉ nhận hàng không được vượt quá 500 ký tự.");

}

$paymentMethod =

    chuanHoaPhuongThucThanhToan(

        $phuongThucRaw

    );

$phuongThuc =

    $paymentMethod['phuong_thuc'];

$paymentType =

    $paymentMethod['loai'];

/* =========================================================

   LẤY TÊN KHÁCH HÀNG

   Dùng tên trong database thay vì chỉ dựa vào session,

   để nội dung thông báo gửi admin chính xác hơn.

\========================================================= */

$hoTenKhach =

    trim(

        (string)(

            $_SESSION['user']['ho_ten']

            ?? ''

        )

    );

$stmtCustomer = $conn->prepare("

    SELECT ho_ten

    FROM nguoi_dung

    WHERE id = ?

    LIMIT 1

");

if ($stmtCustomer) {

    $stmtCustomer->bind_param(

        "i",

        $nguoiDungId

    );

    $stmtCustomer->execute();

    $customer =

        $stmtCustomer

            ->get_result()

            ->fetch_assoc();

    if (!empty($customer['ho_ten'])) {

        $hoTenKhach =

            trim(

                (string)$customer['ho_ten']

            );

    }

}

/* =========================================================

   TRANSACTION

\========================================================= */

$conn->begin_transaction();

try {

    $items = [];

    /*

        Cache số biến thể để không phải COUNT nhiều lần

        cho cùng một sản phẩm.

    */

    $productVariantCount = [];

    /* =====================================================

       1. LẤY TỪNG DÒNG GIỎ VÀ KHÓA

       Không dùng giá gh.gia để thanh toán.

       Giá sẽ được tính lại hoàn toàn từ database.

    ====================================================== */

    $stmtCart = $conn->prepare("

        SELECT

            gh.id AS gio_hang_id,

            gh.san_pham_id,

            gh.so_luong,

            gh.mau_id,

            gh.bien_the_id,

            $maSanPhamSelect

            sp.ten_san_pham,

            sp.gia AS gia_san_pham,

            sp.so_luong AS ton_kho_san_pham,

            sp.trang_thai AS trang_thai_san_pham

        FROM gio_hang gh

        JOIN san_pham sp

            ON sp.id = gh.san_pham_id

        WHERE gh.id = ?

        AND gh.nguoi_dung_id = ?

        FOR UPDATE

    ");

    foreach ($cartIds as $cartId) {

        $stmtCart->bind_param(

            "ii",

            $cartId,

            $nguoiDungId

        );

        $stmtCart->execute();

        $row = $stmtCart

            ->get_result()

            ->fetch_assoc();

        if (!$row) {

            throw new Exception(

                "Một sản phẩm trong giỏ hàng không còn hợp lệ"

            );

        }

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

        if ($soLuong <= 0) {

            throw new Exception(

                "Số lượng trong giỏ hàng không hợp lệ"

            );

        }

        if (

            (int)$row['trang_thai_san_pham'] !== 1

        ) {

            throw new Exception(

                "Sản phẩm " .

                $row['ten_san_pham'] .

                " hiện không được bán"

            );

        }

        /* =================================================

           2. KIỂM TRA SẢN PHẨM CÓ BIẾN THỂ

        ================================================= */

        if (

            !isset(

                $productVariantCount[$sanPhamId]

            )

        ) {

            $stmtCountVariant = $conn->prepare("

                SELECT COUNT(*) AS total

                FROM san_pham_bien_the

                WHERE san_pham_id = ?

            ");

            $stmtCountVariant->bind_param(

                "i",

                $sanPhamId

            );

            $stmtCountVariant->execute();

            $countVariantData =

                $stmtCountVariant

                    ->get_result()

                    ->fetch_assoc();

            $productVariantCount[$sanPhamId] =

                (int)($countVariantData['total'] ?? 0);

        }

        if (

            $productVariantCount[$sanPhamId] <= 0

        ) {

            throw new Exception(

                "Sản phẩm " .

                $row['ten_san_pham'] .

                " chưa có biến thể để thanh toán"

            );

        }

        /* =================================================

           3. KIỂM TRA BIẾN THỂ ĐÃ CHỌN

           BẮT BUỘC:

           - có mau_id

           - có bien_the_id

           - biến thể thuộc đúng sản phẩm

           - biến thể đúng màu khách đã chọn

        ================================================= */

        if ($mauId === null) {

            throw new Exception(

                "Vui lòng chọn màu cho " .

                $row['ten_san_pham']

            );

        }

        if ($bienTheId === null) {

            throw new Exception(

                "Vui lòng chọn phiên bản cho " .

                $row['ten_san_pham']

            );

        }

        $stmtVariant = $conn->prepare("

            SELECT

                id,

                mau_id,

                ram,

                rom,

                gia,

                so_luong

            FROM san_pham_bien_the

            WHERE id = ?

            AND san_pham_id = ?

            AND mau_id = ?

            FOR UPDATE

        ");

        $stmtVariant->bind_param(

            "iii",

            $bienTheId,

            $sanPhamId,

            $mauId

        );

        $stmtVariant->execute();

        $variant = $stmtVariant

            ->get_result()

            ->fetch_assoc();

        if (!$variant) {

            throw new Exception(

                "Màu và phiên bản của " .

                $row['ten_san_pham'] .

                " không còn hợp lệ"

            );

        }

        $giaGoc =

            (float)$variant['gia'];

        $tonKho =

            (int)$variant['so_luong'];

        $ram =

            $variant['ram'];

        $rom =

            $variant['rom'];

        /* =================================================

           4. TÍNH LẠI KHUYẾN MÃI BẰNG HELPER DÙNG CHUNG

        ================================================= */

        /*

            Dùng đúng cùng helper với trang sản phẩm / giỏ hàng:

            - KM riêng biến thể được xét theo bien_the_id;

            - KM toàn sản phẩm vẫn có thể áp dụng cho biến thể;

            - kiểm tra số lượng mua, tồn kho, đối tượng khách,

              khung giờ, xả kho, ưu tiên và giới hạn giảm;

            - ton_kho <= 0 thì helper không trả khuyến mãi.

        */

        $khuyenMai = layKhuyenMaiHienTai(

            $conn,

            $sanPhamId,

            $bienTheId,

            [

                'gia_goc' => $giaGoc,

                'so_luong' => $soLuong,

                'ton_kho' => $tonKho,

                'nguoi_dung_id' => $nguoiDungId

            ]

        );

        $giaBan =

            tinhGiaSauKhuyenMai(

                $giaGoc,

                $khuyenMai

            );

        $items[] = [

            'gio_hang_id' => $cartId,

            'san_pham_id' => $sanPhamId,

            'ma_san_pham' => trim(

                (string)($row['ma_san_pham'] ?? '')

            ),

            'ten_san_pham' => $row['ten_san_pham'],

            'so_luong' => $soLuong,

            'mau_id' => $mauId,

            'bien_the_id' => $bienTheId,

            'ram' => $ram,

            'rom' => $rom,

            'gia_goc' => $giaGoc,

            'gia_ban' => $giaBan,

            'ton_kho' => $tonKho,

            'khuyen_mai' => $khuyenMai

        ];

    }

    /* =====================================================

       5. GỘP NHU CẦU TỒN KHO

       Mỗi bien_the_id là một kho riêng.

       Nếu nhiều dòng giỏ trỏ cùng biến thể thì cộng dồn

       trước khi kiểm tra và trừ tồn kho.

    ====================================================== */

    $stockGroups = [];

    foreach ($items as $item) {

        $stockKey =

            'variant:' .

            (int)$item['bien_the_id'];

        if (!isset($stockGroups[$stockKey])) {

            $stockGroups[$stockKey] = [

                'san_pham_id' =>

                    (int)$item['san_pham_id'],

                'bien_the_id' =>

                    (int)$item['bien_the_id'],

                'mau_id' =>

                    (int)$item['mau_id'],

                'ram' =>

                    $item['ram'],

                'rom' =>

                    $item['rom'],

                'ton_kho' =>

                    (int)$item['ton_kho'],

                'so_luong_can' => 0,

                'ten_san_pham' =>

                    $item['ten_san_pham']

            ];

        }

        $stockGroups[$stockKey]['so_luong_can'] +=

            (int)$item['so_luong'];

    }

    /* =====================================================

       6. KIỂM TRA TỔNG TỒN KHO TRƯỚC KHI TẠO ĐƠN

    ====================================================== */

    foreach ($stockGroups as $group) {

        $tonKho =

            (int)$group['ton_kho'];

        $soLuongCan =

            (int)$group['so_luong_can'];

        if ($tonKho <= 0) {

            throw new Exception(

                "Phiên bản " .

                ($group['ram'] ?? '') .

                " / " .

                ($group['rom'] ?? '') .

                " của " .

                $group['ten_san_pham'] .

                " đã hết hàng"

            );

        }

        if ($soLuongCan > $tonKho) {

            throw new Exception(

                "Phiên bản " .

                ($group['ram'] ?? '') .

                " / " .

                ($group['rom'] ?? '') .

                " của " .

                $group['ten_san_pham'] .

                " chỉ còn " .

                $tonKho .

                " sản phẩm"

            );

        }

    }

    /* =====================================================

       7. TÍNH TỔNG TIỀN THEO GIÁ HIỆN TẠI

    ====================================================== */

    $tongTien = 0;

    foreach ($items as $item) {

        $tongTien +=

            (float)$item['gia_ban'] *

            (int)$item['so_luong'];

    }

    /* =====================================================

       9. TẠO ĐƠN HÀNG

       Trạng thái ban đầu dùng mã nội bộ:

       cho_xu_ly

       ma_van_don KHÔNG sinh ở bước thanh toán.

       Mã vận đơn chỉ được tạo khi admin chuyển đơn sang

       trạng thái dang_giao.

       Thông tin người nhận được lưu snapshot theo đơn:

       - ten_nguoi_nhan

       - so_dien_thoai_nhan

       - dia_chi_nhan

       Sau này khách sửa hồ sơ thì đơn cũ vẫn giữ đúng địa chỉ

       và số điện thoại tại thời điểm đặt.

    ====================================================== */

    $stmtOrder = $conn->prepare("

        INSERT INTO don_hang (

            nguoi_dung_id,

            tong_tien,

            trang_thai,

            ten_nguoi_nhan,

            so_dien_thoai_nhan,

            dia_chi_nhan

        )

        VALUES (

            ?,

            ?,

            'cho_xu_ly',

            ?,

            ?,

            ?

        )

    ");

    $stmtOrder->bind_param(

        "idsss",

        $nguoiDungId,

        $tongTien,

        $tenNguoiNhan,

        $soDienThoaiNhan,

        $diaChiNhan

    );

    $stmtOrder->execute();

    $donHangId =

        $stmtOrder->insert_id;

    /* =====================================================

       10. LƯU CHI TIẾT ĐƠN

       Cột gia lưu GIÁ TẠI THỜI ĐIỂM khách đặt hàng.

       Sau này khuyến mãi hết hạn thì lịch sử đơn

       vẫn giữ đúng giá tại thời điểm đặt hàng.

    ====================================================== */

    $stmtDetail = $conn->prepare("

        INSERT INTO chi_tiet_don_hang (

            don_hang_id,

            san_pham_id,

            gia,

            so_luong,

            mau_id,

            bien_the_id

        )

        VALUES (?, ?, ?, ?, ?, ?)

    ");

    foreach ($items as $item) {

        $sanPhamId =

            (int)$item['san_pham_id'];

        $giaBan =

            (float)$item['gia_ban'];

        $soLuong =

            (int)$item['so_luong'];

        $mauId =

            $item['mau_id'];

        $bienTheId =

            $item['bien_the_id'];

        $stmtDetail->bind_param(

            "iidiii",

            $donHangId,

            $sanPhamId,

            $giaBan,

            $soLuong,

            $mauId,

            $bienTheId

        );

        $stmtDetail->execute();

    }

    /* =====================================================

       10. TRỪ TỒN KHO THEO BIẾN THỂ

       Mỗi kho biến thể chỉ bị trừ một lần theo tổng số lượng

       của tất cả dòng giỏ đang cùng trỏ vào biến thể đó.

    ====================================================== */

    $productsNeedSync = [];

    foreach ($stockGroups as $group) {

        $sanPhamId =

            (int)$group['san_pham_id'];

        $bienTheId =

            (int)$group['bien_the_id'];

        $mauId =

            (int)$group['mau_id'];

        $soLuongCan =

            (int)$group['so_luong_can'];

        $stmtStockVariant = $conn->prepare("

            UPDATE san_pham_bien_the

            SET so_luong = so_luong - ?

            WHERE id = ?

            AND san_pham_id = ?

            AND mau_id = ?

            AND so_luong >= ?

        ");

        $stmtStockVariant->bind_param(

            "iiiii",

            $soLuongCan,

            $bienTheId,

            $sanPhamId,

            $mauId,

            $soLuongCan

        );

        $stmtStockVariant->execute();

        if (

            $stmtStockVariant->affected_rows !== 1

        ) {

            throw new Exception(

                "Biến thể không đủ số lượng để thanh toán"

            );

        }

        $productsNeedSync[$sanPhamId] = true;

    }

    /* =====================================================

       11. ĐỒNG BỘ TỔNG TỒN KHO SẢN PHẨM

    ====================================================== */

    if (!empty($productsNeedSync)) {

        $stmtSyncStock = $conn->prepare("

            UPDATE san_pham

            SET so_luong = (

                SELECT COALESCE(

                    SUM(so_luong),

                    0

                )

                FROM san_pham_bien_the

                WHERE san_pham_id = ?

            )

            WHERE id = ?

        ");

        foreach (

            array_keys($productsNeedSync)

            as $sanPhamId

        ) {

            $sanPhamId =

                (int)$sanPhamId;

            $stmtSyncStock->bind_param(

                "ii",

                $sanPhamId,

                $sanPhamId

            );

            $stmtSyncStock->execute();

        }

    }

    /* =====================================================

       13. LƯU THANH TOÁN GIẢ LẬP

       COD:

       - trang_thai = Chưa thanh toán

       - ngay_thanh_toan = NULL

       QR / Ví:

       - trang_thai = Chờ xác nhận

       - ngay_thanh_toan = NULL

       Admin có thể cập nhật trạng thái   sau đó.

    ====================================================== */

    $trangThaiThanhToan =

        $paymentType === 'cod'

            ? 'Chưa thanh toán'

            : 'Chờ xác nhận';

    $stmtPayment = $conn->prepare("

        INSERT INTO thanh_toan (

            don_hang_id,

            phuong_thuc,

            trang_thai,

            ngay_thanh_toan

        )

        VALUES (?, ?, ?, NULL)

    ");

    $stmtPayment->bind_param(

        "iss",

        $donHangId,

        $phuongThuc,

        $trangThaiThanhToan

    );

    $stmtPayment->execute();

    if ($stmtPayment->affected_rows !== 1) {

        throw new Exception(

            "Không thể tạo thông tin thanh toán."

        );

    }

    /* =====================================================

       14. XÓA ĐÚNG CÁC DÒNG ĐÃ MUA KHỎI GIỎ

       Kiểm tra cả user để không thể xóa giỏ người khác.

    ====================================================== */

    $stmtDeleteCart = $conn->prepare("

        DELETE FROM gio_hang

        WHERE id = ?

        AND nguoi_dung_id = ?

    ");

    foreach ($cartIds as $cartId) {

        $stmtDeleteCart->bind_param(

            "ii",

            $cartId,

            $nguoiDungId

        );

        $stmtDeleteCart->execute();

    }

    /* =====================================================

       15. COMMIT ĐƠN HÀNG

       Notification là tác vụ phụ, không được làm hỏng

       nghiệp vụ đặt hàng nếu hệ thống thông báo tạm thời lỗi.

    ====================================================== */

    $conn->commit();

    /*

        Hủy token một lần sau khi đơn đã commit.

        Request gửi lại cùng token sẽ không thể tạo thêm đơn.

    */

    unset(

        $_SESSION['checkout_token']

    );

    $_SESSION['csrf_checkout'] =

        bin2hex(random_bytes(32));

    /* =====================================================

       16. GỬI THÔNG BÁO SAU COMMIT

    ====================================================== */

    try {

        $userNotificationCreated =

            taoThongBaoDatHangTheoThanhToan(

                $conn,

                $nguoiDungId,

                $donHangId,

                $paymentType,

                $phuongThuc

            );

        if (!$userNotificationCreated) {

            error_log(

                "IphoneStore: Không tạo được thông báo cho user " .

                $nguoiDungId .

                ", order " .

                $donHangId

            );

        }

        thongBaoDonMoiChoAdmin(

            $conn,

            $donHangId,

            $hoTenKhach,

            $tongTien

        );

        if (

            $paymentType === 'online' &&

            function_exists(

                'guiThongBaoChoTatCaAdmin'

            )

        ) {

            guiThongBaoChoTatCaAdmin(

                $conn,

                'Có thanh toán   chờ cập nhật',

                ($hoTenKhach !== ''

                    ? $hoTenKhach

                    : 'Khách hàng'

                ) .

                ' đã chọn phương thức ' .

                $phuongThuc .

                '. Trạng thái thanh toán hiện tại là Chờ xác nhận ( ).',

                'thanh_toan',

                '/iPhoneStore/admin/thanh_toan/index.php',

                $donHangId

            );

        }

    } catch (Throwable $notificationError) {

        error_log(

            "IphoneStore notification error, order " .

            $donHangId .

            ": " .

            $notificationError->getMessage()

        );

    }

    /* =====================================================

       17. QUAY LẠI CHI TIẾT ĐƠN

    ====================================================== */

    header(

        "Location: /iPhoneStore/user/don_hang/chi_tiet.php?" .

        http_build_query([

            'id' => $donHangId,

            'created' => 1

        ])

    );

    exit;

} catch (Throwable $e) {

    try {

        $conn->rollback();

    } catch (Throwable $rollbackError) {

        error_log(

            "IphoneStore rollback error: " .

            $rollbackError->getMessage()

        );

    }

    error_log(

        "IphoneStore checkout error, user " .

        $nguoiDungId .

        ": " .

        $e->getMessage()

    );

    die(

        "❌ Không thể đặt hàng: " .

        htmlspecialchars(

            $e->getMessage(),

            ENT_QUOTES,

            "UTF-8"

        )

    );

}
