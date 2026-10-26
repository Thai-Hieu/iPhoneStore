<?php

if (session_status() === PHP_SESSION_NONE) {

    session_start();

}

require_once "../../middleware/check_user.php";
require_once "../../config/database.php";

require_once "../../includes/notification_helper.php";

mysqli_report(

    MYSQLI_REPORT_ERROR |

    MYSQLI_REPORT_STRICT

);

/* =========================================================

   IphoneStore - USER TỰ HỦY ĐƠN

   QUY TẮC:

   1. User chỉ được tự hủy khi đơn vẫn ở nhóm Chờ xử lý.

   2. Khi hủy:

      - Đơn hàng chuyển sang trạng thái huy.

      - Hoàn lại tồn kho.

      - Không tạo hoặc cập nhật bất kỳ nghiệp vụ hoàn tiền nào.

   3. Thanh toán là dữ liệu  , được giữ nguyên và không chặn thao tác hủy.

========================================================= */

/* =========================================================

   USER

========================================================= */

$userId =

    (int)($_SESSION['user']['id'] ?? 0);

if ($userId <= 0) {

    header(

        "Location: /iPhoneStore/auth/login.php"

    );

    exit;

}

/* =========================================================

   CHỈ POST

========================================================= */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    die("Phương thức không hợp lệ.");

}

/* =========================================================

   INPUT

========================================================= */

$orderId =

    (int)($_POST['id'] ?? 0);

$reason =

    trim(

        (string)($_POST['ly_do_huy'] ?? '')

    );

$csrfToken =

    (string)($_POST['csrf_token'] ?? '');

$allowedReasons = [

    'Tôi không còn nhu cầu mua sản phẩm',

    'Tôi đặt nhầm sản phẩm',

    'Tôi muốn đổi sang sản phẩm khác',

    'Tôi muốn đổi màu hoặc phiên bản khác',

    'Tôi đặt trùng đơn hàng',

    'Tôi muốn thay đổi địa chỉ nhận hàng',

    'Tôi muốn thay đổi số điện thoại nhận hàng',

    'Tôi muốn thay đổi phương thức thanh toán',

    'Thời gian nhận hàng dự kiến không còn phù hợp',

    'Tôi tìm được lựa chọn phù hợp hơn',

    'Tôi nhập sai thông tin đặt hàng',

    'Lý do cá nhân khác'

];

$pendingStatuses = [

    'cho_xu_ly',

    'da_dat',

    'Đã đặt',

    'cho_xac_nhan',

    'Chờ xác nhận'

];

/* =========================================================

   CSRF + VALIDATE

========================================================= */

if (

    empty($_SESSION['csrf_user_cancel_order']) ||

    $csrfToken === '' ||

    !hash_equals(

        (string)$_SESSION['csrf_user_cancel_order'],

        $csrfToken

    )

) {

    die(

        "Phiên làm việc không hợp lệ. Vui lòng tải lại trang."

    );

}

if ($orderId <= 0) {

    die(

        "Đơn hàng không hợp lệ."

    );

}

if (

    $reason === '' ||

    !in_array(

        $reason,

        $allowedReasons,

        true

    )

) {

    die(

        "Lý do hủy đơn không hợp lệ."

    );

}

/* =========================================================

   TRANSACTION

========================================================= */

$conn->begin_transaction();

try {

    /* =====================================================

       1. KHÓA ĐƠN HÀNG + KIỂM TRA CHỦ SỞ HỮU

    ====================================================== */

    $stmtOrder = $conn->prepare("

        SELECT

            dh.id,

            dh.trang_thai,

            dh.ma_van_don,

            nd.ho_ten

        FROM don_hang dh

        JOIN nguoi_dung nd

            ON nd.id = dh.nguoi_dung_id

        WHERE dh.id = ?

        AND dh.nguoi_dung_id = ?

        LIMIT 1

        FOR UPDATE

    ");

    $stmtOrder->bind_param(

        "ii",

        $orderId,

        $userId

    );

    $stmtOrder->execute();

    $order =

        $stmtOrder

            ->get_result()

            ->fetch_assoc();

    if (!$order) {

        throw new Exception(

            "Đơn hàng không tồn tại hoặc không thuộc tài khoản của bạn."

        );

    }

    $currentStatus =

        trim(

            (string)$order['trang_thai']

        );

    $customerName =

        trim(

            (string)(

                $order['ho_ten']

                ?? ''

            )

        );

    if (

        !in_array(

            $currentStatus,

            $pendingStatuses,

            true

        )

    ) {

        throw new Exception(

            "Bạn chỉ có thể tự hủy khi đơn đang ở trạng thái Chờ xử lý."

        );

    }

    /* =====================================================

       2. LẤY CHI TIẾT ĐỂ HOÀN TỒN KHO

    ====================================================== */

    $stmtDetails = $conn->prepare("

        SELECT

            san_pham_id,

            so_luong,

            bien_the_id

        FROM chi_tiet_don_hang

        WHERE don_hang_id = ?

        FOR UPDATE

    ");

    $stmtDetails->bind_param(

        "i",

        $orderId

    );

    $stmtDetails->execute();

    $items =

        $stmtDetails

            ->get_result()

            ->fetch_all(MYSQLI_ASSOC);

    $variantProductIds = [];

    foreach ($items as $item) {

        $productId =

            (int)$item['san_pham_id'];

        $quantity =

            max(

                0,

                (int)$item['so_luong']

            );

        $variantId =

            (int)($item['bien_the_id'] ?? 0);

        if (

            $productId <= 0 ||

            $quantity <= 0

        ) {

            continue;

        }

        if ($variantId > 0) {

            $stmtRestoreVariant = $conn->prepare("

                UPDATE san_pham_bien_the

                SET so_luong = so_luong + ?

                WHERE id = ?

                AND san_pham_id = ?

                LIMIT 1

            ");

            $stmtRestoreVariant->bind_param(

                "iii",

                $quantity,

                $variantId,

                $productId

            );

            $stmtRestoreVariant->execute();

            if (

                $stmtRestoreVariant->affected_rows > 0

            ) {

                $variantProductIds[$productId] =

                    true;

            }

        } else {

            $stmtRestoreProduct = $conn->prepare("

                UPDATE san_pham

                SET so_luong = so_luong + ?

                WHERE id = ?

                LIMIT 1

            ");

            $stmtRestoreProduct->bind_param(

                "ii",

                $quantity,

                $productId

            );

            $stmtRestoreProduct->execute();

        }

    }

    /*

        Nếu đơn có biến thể thì tồn tổng sản phẩm

        phải bằng tổng tồn của các biến thể.

    */

    if (!empty($variantProductIds)) {

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

            array_keys($variantProductIds)

            as $productId

        ) {

            $productId =

                (int)$productId;

            $stmtSyncStock->bind_param(

                "ii",

                $productId,

                $productId

            );

            $stmtSyncStock->execute();

        }

    }

    /* =====================================================

       3. HỦY ĐƠN

    ====================================================== */

    $newStatus =

        'huy';

    $cancelledBy =

        'user';

    $stmtCancel = $conn->prepare("

        UPDATE don_hang

        SET

            trang_thai = ?,

            ly_do_huy = ?,

            ngay_huy = NOW(),

            nguoi_huy = ?

        WHERE id = ?

        AND nguoi_dung_id = ?

        AND trang_thai IN (

            'cho_xu_ly',

            'da_dat',

            'Đã đặt',

            'cho_xac_nhan',

            'Chờ xác nhận'

        )

        LIMIT 1

    ");

    $stmtCancel->bind_param(

        "sssii",

        $newStatus,

        $reason,

        $cancelledBy,

        $orderId,

        $userId

    );

    $stmtCancel->execute();

    if (

        $stmtCancel->affected_rows !== 1

    ) {

        throw new Exception(

            "Không thể hủy đơn hàng. Trạng thái đơn có thể đã thay đổi."

        );

    }

    /* =====================================================

       4. THANH TOÁN KHÔNG THAM GIA NGHIỆP VỤ HỦY

       Không thay đổi trạng thái thanh toán khi user hủy đơn.

       File này chỉ xử lý hủy đơn và hoàn tồn kho.

    ====================================================== */

    /* =====================================================

       5. COMMIT NGHIỆP VỤ CHÍNH

    ====================================================== */

    $conn->commit();

    $_SESSION['csrf_user_cancel_order'] =

        bin2hex(

            random_bytes(32)

        );

    /* =====================================================

       6. THÔNG BÁO SAU COMMIT

       Lỗi notification KHÔNG rollback đơn/tồn kho.

    ====================================================== */

    try {

        thongBaoDonBiHuy(

            $conn,

            $userId,

            $orderId,

            'user',

            $reason

        );

    } catch (Throwable $notificationError) {

        error_log(

            'IphoneStore user cancel notification error, order ' .

            $orderId .

            ': ' .

            $notificationError->getMessage()

        );

    }

    try {

        thongBaoKhachHuyChoAdmin(

            $conn,

            $orderId,

            $customerName,

            $reason

        );

    } catch (Throwable $notificationError) {

        error_log(

            'IphoneStore admin cancel notification error, order ' .

            $orderId .

            ': ' .

            $notificationError->getMessage()

        );

    }

    header(

        "Location: /iPhoneStore/user/don_hang/chi_tiet.php?id=" .

        $orderId .

        "&cancel=success"

    );

    exit;

} catch (Throwable $e) {

    try {

        $conn->rollback();

    } catch (Throwable $rollbackError) {

        error_log(

            'IphoneStore user cancel rollback error, order ' .

            $orderId .

            ': ' .

            $rollbackError->getMessage()

        );

    }

    error_log(

        'IphoneStore user cancel order error, order ' .

        $orderId .

        ': ' .

        $e->getMessage()

    );

    die(

        "Không thể hủy đơn hàng: " .

        htmlspecialchars(

            $e->getMessage(),

            ENT_QUOTES,

            "UTF-8"

        )

    );

}
