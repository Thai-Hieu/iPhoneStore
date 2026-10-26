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
   IphoneStore - ADMIN CẬP NHẬT THANH TOÁN GIẢ LẬP

   QUY TẮC:
   1. Thanh toán chỉ là dữ liệu   phục vụ đồ án.

   2. Trạng thái thanh toán được cập nhật độc lập với
      trạng thái đơn hàng.

   3. QR/Ví:
      Chờ xác nhận / Chờ thanh toán / Chờ xử lý
      -> Đã thanh toán hoặc Thất bại.

   4. COD:
      Chưa thanh toán / Chờ thanh toán
      -> Đã thanh toán.

   5. File này KHÔNG tự duyệt, giao, hoàn thành hoặc hủy đơn.
========================================================= */

/* =========================================================

   HELPER

========================================================= */

function paymentSafeReturnTo(

    ?string $returnTo,

    int $paymentId

): string {

    $returnTo =

        trim(

            (string)$returnTo

        );

    if (

        $returnTo !== '' &&

        str_starts_with(

            $returnTo,

            '/iPhoneStore/admin/'

        ) &&

        !str_contains(

            $returnTo,

            "\r"

        ) &&

        !str_contains(

            $returnTo,

            "\n"

        )

    ) {

        return $returnTo;

    }

    return

        '/iPhoneStore/admin/thanh_toan/chi_tiet.php?id=' .

        $paymentId;

}

function paymentRedirect(

    string $url

): never {

    header(

        'Location: ' .

        $url

    );

    exit;

}

function isPaidPaymentStatus(

    ?string $status

): bool {

    return in_array(

        trim(

            (string)$status

        ),

        [

            'Đã thanh toán',

            'Thành công'

        ],

        true

    );

}

function paymentOrderReference(

    ?string $maVanDon

): string {

    $maVanDon =

        trim(

            (string)$maVanDon

        );

    if ($maVanDon !== '') {

        return

            'mã vận đơn ' .

            $maVanDon;

    }

    return

        'đơn hàng chưa có mã vận đơn';

}

/* =========================================================

   CHỈ POST

========================================================= */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    paymentRedirect(

        '/iPhoneStore/admin/thanh_toan/index.php'

    );

}

/* =========================================================

   INPUT

========================================================= */

$paymentId =

    (int)($_POST['payment_id'] ?? 0);

$action =

    trim(

        (string)($_POST['action'] ?? '')

    );

$csrfToken =

    (string)($_POST['csrf_token'] ?? '');

$returnTo =

    paymentSafeReturnTo(

        $_POST['return_to'] ?? null,

        $paymentId

    );

if ($paymentId <= 0) {

    $_SESSION['admin_payment_error'] =

        'Thanh toán không hợp lệ.';

    paymentRedirect(

        '/iPhoneStore/admin/thanh_toan/index.php'

    );

}

/* =========================================================

   CSRF

========================================================= */

if (

    empty($_SESSION['csrf_admin_payment']) ||

    $csrfToken === '' ||

    !hash_equals(

        (string)$_SESSION['csrf_admin_payment'],

        $csrfToken

    )

) {

    $_SESSION['admin_payment_error'] =

        'Phiên xác nhận thanh toán không hợp lệ. Vui lòng thử lại.';

    paymentRedirect(

        $returnTo

    );

}

$allowedActions = [

    'confirm_online',

    'reconcile_online_no_money',

    'confirm_cod_complete'

];

if (

    !in_array(

        $action,

        $allowedActions,

        true

    )

) {

    $_SESSION['admin_payment_error'] =

        'Thao tác thanh toán không hợp lệ.';

    paymentRedirect(

        $returnTo

    );

}

/* =========================================================

   TRANSACTION

========================================================= */

$conn->begin_transaction();

try {

    /* =====================================================

       1. KHÓA PAYMENT CỤ THỂ

    ====================================================== */

    $stmtPayment = $conn->prepare("

        SELECT

            id,

            don_hang_id,

            phuong_thuc,

            trang_thai,

            ngay_thanh_toan

        FROM thanh_toan

        WHERE id = ?

        LIMIT 1

        FOR UPDATE

    ");

    $stmtPayment->bind_param(

        "i",

        $paymentId

    );

    $stmtPayment->execute();

    $payment =

        $stmtPayment

            ->get_result()

            ->fetch_assoc();

    if (!$payment) {

        throw new Exception(

            'Không tìm thấy thanh toán.'

        );

    }

    $donHangId =

        (int)$payment['don_hang_id'];

    $paymentMethod =

        trim(

            (string)$payment['phuong_thuc']

        );

    $paymentStatus =

        trim(

            (string)$payment['trang_thai']

        );

    $isCOD =

        strtoupper(

            $paymentMethod

        ) === 'COD';

    $isPaid =

        isPaidPaymentStatus(

            $paymentStatus

        );

    /* =====================================================

       2. CHỈ XỬ LÝ PAYMENT MỚI NHẤT CỦA ĐƠN

    ====================================================== */

    $stmtLatest = $conn->prepare("

        SELECT id

        FROM thanh_toan

        WHERE don_hang_id = ?

        ORDER BY id DESC

        LIMIT 1

        FOR UPDATE

    ");

    $stmtLatest->bind_param(

        "i",

        $donHangId

    );

    $stmtLatest->execute();

    $latestPayment =

        $stmtLatest

            ->get_result()

            ->fetch_assoc();

    if (

        !$latestPayment ||

        (int)$latestPayment['id'] !== $paymentId

    ) {

        throw new Exception(

            'Chỉ được xác nhận giao dịch thanh toán mới nhất của đơn hàng.'

        );

    }

    /* =====================================================

       3. KHÓA ĐƠN HÀNG

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

        "i",

        $donHangId

    );

    $stmtOrder->execute();

    $order =

        $stmtOrder

            ->get_result()

            ->fetch_assoc();

    if (!$order) {

        throw new Exception(

            'Đơn hàng của thanh toán không còn tồn tại.'

        );

    }

    $nguoiDungId =

        (int)$order['nguoi_dung_id'];

    $maVanDon =

        trim(

            (string)(

                $order['ma_van_don']

                ?? ''

            )

        );

    $successType = '';

    /* =====================================================
       5A. QR / VÍ - XÁC NHẬN THANH TOÁN GIẢ LẬP
    ====================================================== */

    if ($action === 'confirm_online') {

        if ($isCOD) {
            throw new Exception(
                'Đơn COD không dùng thao tác xác nhận thanh toán online.'
            );
        }

        if ($isPaid) {
            throw new Exception(
                'Thanh toán đã được ghi nhận Đã thanh toán trước đó.'
            );
        }

        if (
            !in_array(
                $paymentStatus,
                [
                    'Chờ xác nhận',
                    'Chờ thanh toán',
                    'Chờ xử lý'
                ],
                true
            )
        ) {
            throw new Exception(
                'Trạng thái thanh toán hiện tại không cho phép xác nhận.'
            );
        }

        $stmtUpdatePayment = $conn->prepare("
            UPDATE thanh_toan
            SET
                trang_thai = 'Đã thanh toán',
                ngay_thanh_toan = NOW()
            WHERE id = ?
              AND don_hang_id = ?
              AND trang_thai IN (
                  'Chờ xác nhận',
                  'Chờ thanh toán',
                  'Chờ xử lý'
              )
            LIMIT 1
        ");

        $stmtUpdatePayment->bind_param(
            "ii",
            $paymentId,
            $donHangId
        );

        $stmtUpdatePayment->execute();

        if ($stmtUpdatePayment->affected_rows !== 1) {
            throw new Exception(
                'Không thể cập nhật thanh toán. Dữ liệu có thể đã thay đổi.'
            );
        }

        $successType = 'online';
    }

    /* =====================================================
       5B. QR / VÍ - ĐÁNH DẤU THẤT BẠI GIẢ LẬP

       Giữ action cũ để tương thích giao diện hiện tại.
    ====================================================== */

    if ($action === 'reconcile_online_no_money') {

        if ($isCOD) {
            throw new Exception(
                'Thao tác này chỉ áp dụng cho thanh toán QR/Ví.'
            );
        }

        if ($isPaid) {
            throw new Exception(
                'Thanh toán đã ở trạng thái Đã thanh toán nên không thể chuyển sang Thất bại.'
            );
        }

        if (
            !in_array(
                $paymentStatus,
                [
                    'Chờ xác nhận',
                    'Chờ thanh toán',
                    'Chờ xử lý'
                ],
                true
            )
        ) {
            throw new Exception(
                'Trạng thái thanh toán hiện tại không cho phép đánh dấu Thất bại.'
            );
        }

        $stmtFailed = $conn->prepare("
            UPDATE thanh_toan
            SET
                trang_thai = 'Thất bại',
                ngay_thanh_toan = NULL
            WHERE id = ?
              AND don_hang_id = ?
              AND trang_thai IN (
                  'Chờ xác nhận',
                  'Chờ thanh toán',
                  'Chờ xử lý'
              )
            LIMIT 1
        ");

        $stmtFailed->bind_param(
            "ii",
            $paymentId,
            $donHangId
        );

        $stmtFailed->execute();

        if ($stmtFailed->affected_rows !== 1) {
            throw new Exception(
                'Không thể cập nhật thanh toán sang Thất bại.'
            );
        }

        $successType = 'online_failed';
    }

    /* =====================================================
       5C. COD - XÁC NHẬN THANH TOÁN GIẢ LẬP

       Chỉ cập nhật thanh toán, không tự hoàn thành đơn hàng.
    ====================================================== */

    if ($action === 'confirm_cod_complete') {

        if (!$isCOD) {
            throw new Exception(
                'Thao tác này chỉ áp dụng cho thanh toán COD.'
            );
        }

        if ($isPaid) {
            throw new Exception(
                'Thanh toán COD đã được ghi nhận trước đó.'
            );
        }

        if (
            !in_array(
                $paymentStatus,
                [
                    'Chưa thanh toán',
                    'Chờ thanh toán'
                ],
                true
            )
        ) {
            throw new Exception(
                'Trạng thái thanh toán COD hiện tại không hợp lệ.'
            );
        }

        $stmtUpdatePayment = $conn->prepare("
            UPDATE thanh_toan
            SET
                trang_thai = 'Đã thanh toán',
                ngay_thanh_toan = NOW()
            WHERE id = ?
              AND don_hang_id = ?
              AND trang_thai IN (
                  'Chưa thanh toán',
                  'Chờ thanh toán'
              )
            LIMIT 1
        ");

        $stmtUpdatePayment->bind_param(
            "ii",
            $paymentId,
            $donHangId
        );

        $stmtUpdatePayment->execute();

        if ($stmtUpdatePayment->affected_rows !== 1) {
            throw new Exception(
                'Không thể cập nhật thanh toán COD.'
            );
        }

        $successType = 'cod';
    }

    if ($successType === '') {
        throw new Exception(
            'Không có thay đổi thanh toán nào được thực hiện.'
        );
    }

    /* =====================================================

       6. COMMIT NGHIỆP VỤ CHÍNH

    ====================================================== */

    $conn->commit();

    $_SESSION['csrf_admin_payment'] =

        bin2hex(

            random_bytes(32)

        );

    /* =====================================================
       7. THÔNG BÁO SAU COMMIT
    ====================================================== */

    try {

        $userLink =
            '/iPhoneStore/user/don_hang/chi_tiet.php?id=' .
            $donHangId;

        if ($successType === 'online') {
            taoThongBao(
                $conn,
                $nguoiDungId,
                'Thanh toán đã được cập nhật',
                'Trạng thái thanh toán bằng ' .
                $paymentMethod .
                ' của đơn hàng đã được chuyển sang Đã thanh toán.',
                'thanh_toan',
                $userLink,
                $donHangId
            );
        }

        if ($successType === 'online_failed') {
            taoThongBao(
                $conn,
                $nguoiDungId,
                'Thanh toán đã được cập nhật',
                'Trạng thái thanh toán bằng ' .
                $paymentMethod .
                ' của đơn hàng đã được chuyển sang Thất bại.',
                'thanh_toan',
                $userLink,
                $donHangId
            );
        }

        if ($successType === 'cod') {
            taoThongBao(
                $conn,
                $nguoiDungId,
                'Thanh toán đã được cập nhật',
                'Trạng thái thanh toán COD của đơn hàng đã được chuyển sang Đã thanh toán.',
                'thanh_toan',
                $userLink,
                $donHangId
            );
        }

    } catch (Throwable $notificationError) {

        error_log(
            'IphoneStore payment notification error, payment ' .
            $paymentId .
            ': ' .
            $notificationError->getMessage()
        );
    }

    /* =====================================================
       8. FLASH - THANH TOÁN GIẢ LẬP
    ====================================================== */

    $orderReference =
        paymentOrderReference(
            $maVanDon
        );

    if ($successType === 'online') {
        $_SESSION['admin_payment_success'] =
            'Đã cập nhật thanh toán   bằng ' .
            $paymentMethod .
            ' thành Đã thanh toán cho ' .
            $orderReference .
            '.';
    }

    if ($successType === 'online_failed') {
        $_SESSION['admin_payment_success'] =
            'Đã cập nhật thanh toán   của ' .
            $orderReference .
            ' thành Thất bại.';
    }

    if ($successType === 'cod') {
        $_SESSION['admin_payment_success'] =
            'Đã cập nhật thanh toán COD   thành Đã thanh toán cho ' .
            $orderReference .
            '.';
    }

    paymentRedirect(

        $returnTo

    );

} catch (Throwable $e) {

    try {

        $conn->rollback();

    } catch (Throwable $rollbackError) {

        error_log(

            'IphoneStore payment rollback error: ' .

            $rollbackError->getMessage()

        );

    }

    error_log(

        'IphoneStore admin payment error, payment ' .

        $paymentId .

        ': ' .

        $e->getMessage()

    );

    $_SESSION['admin_payment_error'] =

        $e->getMessage();

    paymentRedirect(

        $returnTo

    );

}
