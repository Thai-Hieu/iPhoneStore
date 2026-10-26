<?php

if (session_status() === PHP_SESSION_NONE) {

    session_start();

}

require_once "../../middleware/check_admin.php";

require_once "../../config/database.php";

require_once "../../includes/notification_helper.php";

mysqli_report(

    MYSQLI_REPORT_ERROR |

    MYSQLI_REPORT_STRICT

);

/* =========================================================
   IphoneStore - CẬP NHẬT TRẠNG THÁI ĐƠN ADMIN

   QUY TẮC:
   1. Vòng đời đơn:
      Chờ xử lý -> Đã duyệt -> Đang giao -> Hoàn thành.

   2. Thanh toán trong đồ án là dữ liệu  .
      Trạng thái thanh toán không chặn việc duyệt, giao
      hoặc hoàn thành đơn hàng.

   3. Mã vận đơn chỉ sinh khi chuyển:
      Đã duyệt -> Đang giao.
========================================================= */

function normalizeOrderStatus(?string $status): string

{

    $status = trim((string)$status);

    if (

        in_array(

            $status,

            [

                'cho_xu_ly',

                'da_dat',

                'Đã đặt',

                'cho_xac_nhan',

                'Chờ xác nhận'

            ],

            true

        )

    ) {

        return 'cho_xu_ly';

    }

    if (

        in_array(

            $status,

            [

                'da_duyet',

                'dang_xu_ly',

                'Đang xử lý'

            ],

            true

        )

    ) {

        return 'da_duyet';

    }

    if (

        in_array(

            $status,

            [

                'dang_giao',

                'Đang giao'

            ],

            true

        )

    ) {

        return 'dang_giao';

    }

    if (

        in_array(

            $status,

            [

                'hoan_thanh',

                'Hoàn thành',

                'da_giao',

                'Đã giao'

            ],

            true

        )

    ) {

        return 'hoan_thanh';

    }

    if (

        in_array(

            $status,

            [

                'huy',

                'da_huy',

                'Hủy',

                'Đã hủy'

            ],

            true

        )

    ) {

        return 'huy';

    }

    return $status;

}

function taoMaVanDon(mysqli $conn): string

{

    $prefix = '8686';

    $stmtCheck = $conn->prepare("

        SELECT id

        FROM don_hang

        WHERE ma_van_don = NULLIF(?, '')

        LIMIT 1

    ");

    if (!$stmtCheck) {

        throw new Exception(

            'Không thể kiểm tra mã vận đơn.'

        );

    }

    for ($lanThu = 1; $lanThu <= 100; $lanThu++) {

        $digits = range(0, 9);

        $suffix = '';

        for ($i = 0; $i < 4; $i++) {

            $index = random_int(

                0,

                count($digits) - 1

            );

            $suffix .=

                (string)$digits[$index];

            array_splice(

                $digits,

                $index,

                1

            );

        }

        $maVanDon =

            $prefix .

            $suffix;

        $stmtCheck->bind_param(

            's',

            $maVanDon

        );

        $stmtCheck->execute();

        $daTonTai =

            $stmtCheck

                ->get_result()

                ->num_rows > 0;

        if (!$daTonTai) {

            $stmtCheck->close();

            return $maVanDon;

        }

    }

    $stmtCheck->close();

    throw new Exception(

        'Không thể tạo mã vận đơn không trùng. Vui lòng thử lại.'

    );

}

function redirectOrderDetail(

    int $orderId,

    array $extra = []

): never {

    $query =

        array_merge(

            ['id' => $orderId],

            $extra

        );

    header(

        'Location: /iPhoneStore/admin/don_hang/chi_tiet.php?' .

        http_build_query($query)

    );

    exit;

}

/* =========================================================

   REQUEST

   Tạm giữ tương thích GET vì giao diện hiện tại vẫn có link GET.

   Sau này có thể đổi toàn bộ nút trạng thái sang POST + CSRF.

========================================================= */

$request =

    $_SERVER['REQUEST_METHOD'] === 'POST'

        ? $_POST

        : $_GET;

$id =

    (int)($request['id'] ?? 0);

$trangThaiMoi =

    trim(

        (string)($request['tt'] ?? '')

    );

if ($id <= 0) {

    die('Mã đơn hàng không hợp lệ.');

}

$allowed = [

    'da_duyet',

    'dang_giao',

    'hoan_thanh'

];

if (

    !in_array(

        $trangThaiMoi,

        $allowed,

        true

    )

) {

    die('Trạng thái không hợp lệ.');

}

/* =========================================================

   KIỂM TRA CỘT MÃ VẬN ĐƠN

========================================================= */

$resultMaVanDonColumn =

    $conn->query("

        SHOW COLUMNS

        FROM don_hang

        LIKE 'ma_van_don'

    ");

if (

    !$resultMaVanDonColumn ||

    $resultMaVanDonColumn->num_rows === 0

) {

    die(

        'Bảng don_hang chưa có cột ma_van_don.'

    );

}

/* =========================================================

   TRANSACTION

========================================================= */

$conn->begin_transaction();

try {

    /* =====================================================

       1. KHÓA ĐƠN HÀNG

    ====================================================== */

    $stmtOrder = $conn->prepare("

        SELECT

            id,

            nguoi_dung_id,

            trang_thai,

            ma_van_don

        FROM don_hang

        WHERE id = ?

        LIMIT 1

        FOR UPDATE

    ");

    $stmtOrder->bind_param(

        'i',

        $id

    );

    $stmtOrder->execute();

    $donHang =

        $stmtOrder

            ->get_result()

            ->fetch_assoc();

    if (!$donHang) {

        throw new Exception(

            'Đơn hàng không tồn tại.'

        );

    }

    $nguoiDungId =

        (int)$donHang['nguoi_dung_id'];

    if ($nguoiDungId <= 0) {

        throw new Exception(

            'Không xác định được khách hàng của đơn hàng.'

        );

    }

    $trangThaiHienTai =

        normalizeOrderStatus(

            $donHang['trang_thai']

        );

    $maVanDonHienTai =

        $donHang['ma_van_don'] !== null

            ? trim((string)$donHang['ma_van_don'])

            : null;

    // Dữ liệu cũ có thể đang lưu chuỗi rỗng.

    // Chuẩn hóa về NULL để không vi phạm UNIQUE ma_van_don.

    if ($maVanDonHienTai === '') {

        $maVanDonHienTai = null;

    }

    if ($trangThaiHienTai === 'huy') {

        throw new Exception(

            'Đơn hàng đã hủy, không thể cập nhật trạng thái.'

        );

    }

    if ($trangThaiHienTai === 'hoan_thanh') {

        throw new Exception(

            'Đơn hàng đã hoàn thành, không thể cập nhật tiếp.'

        );

    }

        /* =====================================================
       2. KIỂM TRA ĐÚNG TIẾN TRÌNH ĐƠN

       Thanh toán là dữ liệu   nên không tham gia
       quyết định chuyển trạng thái đơn hàng.
    ====================================================== */

    $duocCapNhat = false;

    if (

        $trangThaiHienTai === 'cho_xu_ly' &&

        $trangThaiMoi === 'da_duyet'

    ) {

        $duocCapNhat = true;

    }

    if (

        $trangThaiHienTai === 'da_duyet' &&

        $trangThaiMoi === 'dang_giao'

    ) {

        $duocCapNhat = true;

    }

    if (

        $trangThaiHienTai === 'dang_giao' &&

        $trangThaiMoi === 'hoan_thanh'

    ) {

        $duocCapNhat = true;

    }

    if (!$duocCapNhat) {

        throw new Exception(

            'Không thể chuyển trạng thái từ "' .

            $trangThaiHienTai .

            '" sang "' .

            $trangThaiMoi .

            '".'

        );

    }

        /* =====================================================
       3. TẠO MÃ VẬN ĐƠN ĐÚNG MỘT LẦN

       Chỉ sinh khi chuyển Đã duyệt -> Đang giao.
       Nếu đã có mã thì giữ nguyên, tuyệt đối không sinh lại.
    ====================================================== */

    $maVanDon =

        $maVanDonHienTai;

    if (

        $trangThaiMoi === 'dang_giao' &&

        ($maVanDon === null || $maVanDon === '')

    ) {

        $maVanDon =

            taoMaVanDon(

                $conn

            );

    }

    /* =====================================================

       4. CẬP NHẬT ĐƠN

    ====================================================== */

    $stmtUpdate = $conn->prepare("

        UPDATE don_hang

        SET

            trang_thai = ?,

            ma_van_don = NULLIF(?, '')

        WHERE id = ?

        LIMIT 1

    ");

    $stmtUpdate->bind_param(

        'ssi',

        $trangThaiMoi,

        $maVanDon,

        $id

    );

    $stmtUpdate->execute();

    if ($stmtUpdate->affected_rows !== 1) {

        throw new Exception(

            'Không thể cập nhật trạng thái đơn hàng.'

        );

    }

    /* =====================================================

       5. COMMIT NGHIỆP VỤ CHÍNH

    ====================================================== */

    $conn->commit();

    /* =====================================================

       6. NOTIFICATION BEST-EFFORT

    ====================================================== */

    try {

        $daTaoThongBao = false;

        if ($trangThaiMoi === 'da_duyet') {

            $daTaoThongBao =

                thongBaoDonDuocDuyet(

                    $conn,

                    $nguoiDungId,

                    $id

                );

        }

        if ($trangThaiMoi === 'dang_giao') {

            $daTaoThongBao =

                thongBaoDonDangGiao(

                    $conn,

                    $nguoiDungId,

                    $id,

                    $maVanDon

                );

        }

        if ($trangThaiMoi === 'hoan_thanh') {

            $daTaoThongBao =

                thongBaoDonHoanThanh(

                    $conn,

                    $nguoiDungId,

                    $id

                );

        }

        if (!$daTaoThongBao) {

            error_log(

                'IphoneStore: Không tạo được thông báo cập nhật đơn #' .

                $id .

                ', trạng thái ' .

                $trangThaiMoi

            );

        }

    } catch (Throwable $notificationError) {

        error_log(

            'IphoneStore order status notification error, order #' .

            $id .

            ': ' .

            $notificationError->getMessage()

        );

    }

    $extra = [

        'updated' => 1

    ];

    redirectOrderDetail(

        $id,

        $extra

    );

} catch (Throwable $e) {

    try {

        $conn->rollback();

    } catch (Throwable $rollbackError) {

        error_log(

            'IphoneStore order status rollback error, order #' .

            $id .

            ': ' .

            $rollbackError->getMessage()

        );

    }

    die(

        'Không thể cập nhật đơn hàng: ' .

        htmlspecialchars(

            $e->getMessage(),

            ENT_QUOTES,

            'UTF-8'

        )

    );

}

?>
