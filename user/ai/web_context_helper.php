<?php

function aiSiteMapToText(
    array $siteMap,
    string $role
): string {
    $lines = [];

    foreach ($siteMap as $item) {
        $access = (string)($item['quyen'] ?? 'public');

        $allowed =
            $access === 'public' ||
            ($access === 'login' && $role !== 'guest') ||
            ($access === 'admin' && $role === 'admin');

        $lines[] =
            '- ' .
            $item['ten'] .
            ' | URL: ' .
            $item['url'] .
            ' | Quyền: ' .
            $access .
            ' | Người hiện tại ' .
            ($allowed ? 'có thể truy cập' : 'chưa đủ quyền') .
            ' | ' .
            $item['mo_ta'];
    }

    return implode("\n", $lines);
}

function aiCurrentUserContext(
    mysqli $conn
): string {
    $userId = aiChatUserId();
    $role = aiChatRole();

    if ($userId === null) {
        return
            "Trạng thái đăng nhập: khách chưa đăng nhập.\n" .
            "Không được cung cấp dữ liệu giỏ hàng, đơn hàng, thông báo hoặc tài khoản riêng tư.";
    }

    $lines = [
        'Trạng thái đăng nhập: đã đăng nhập.',
        'Vai trò: ' . $role,
        'ID người dùng hiện tại: ' . $userId,
    ];

    if (aiTableExists($conn, 'gio_hang')) {
        $stmt = $conn->prepare("
            SELECT
                COUNT(*) AS dong,
                COALESCE(SUM(so_luong), 0) AS tong_so_luong,
                MAX(ngay_tao) AS lan_them_gan_nhat
            FROM gio_hang
            WHERE nguoi_dung_id = ?
        ");

        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                $lines[] =
                    'Giỏ hàng hiện tại: ' .
                    (int)$row['tong_so_luong'] .
                    ' sản phẩm; lần thêm gần nhất: ' .
                    ($row['lan_them_gan_nhat'] ?: 'chưa có');
            }
        }
    }

    if (aiTableExists($conn, 'don_hang')) {
        $stmt = $conn->prepare("
            SELECT
                dh.id,
                dh.ma_van_don,
                dh.tong_tien,
                dh.trang_thai,
                dh.ngay_dat,
                dh.ngay_huy,
                tt.phuong_thuc,
                tt.trang_thai AS trang_thai_thanh_toan,
                tt.ngay_thanh_toan
            FROM don_hang dh
            LEFT JOIN thanh_toan tt
                ON tt.don_hang_id = dh.id
            WHERE dh.nguoi_dung_id = ?
            ORDER BY dh.id DESC
            LIMIT 8
        ");

        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $rows = $stmt
                ->get_result()
                ->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            foreach ($rows as $row) {
                $lines[] =
                    'Đơn #' .
                    (int)$row['id'] .
                    ' | mã vận đơn: ' .
                    ($row['ma_van_don'] ?: 'chưa có') .
                    ' | trạng thái: ' .
                    ($row['trang_thai'] ?: 'chưa rõ') .
                    ' | tổng tiền: ' .
                    number_format(
                        (float)$row['tong_tien'],
                        0,
                        ',',
                        '.'
                    ) .
                    'đ | ngày đặt: ' .
                    ($row['ngay_dat'] ?: 'chưa có') .
                    ' | ngày hủy: ' .
                    ($row['ngay_huy'] ?: 'không') .
                    ' | thanh toán: ' .
                    ($row['trang_thai_thanh_toan'] ?: 'chưa có dữ liệu') .
                    ' | ngày thanh toán: ' .
                    ($row['ngay_thanh_toan'] ?: 'chưa có');
            }
        }
    }

    if (aiTableExists($conn, 'danh_gia')) {
        $stmt = $conn->prepare("
            SELECT
                dg.id,
                dg.san_pham_id,
                dg.so_sao,
                dg.ngay_tao,
                sp.ten_san_pham
            FROM danh_gia dg
            LEFT JOIN san_pham sp
                ON sp.id = dg.san_pham_id
            WHERE dg.nguoi_dung_id = ?
            ORDER BY dg.id DESC
            LIMIT 8
        ");

        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $rows = $stmt
                ->get_result()
                ->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            foreach ($rows as $row) {
                $lines[] =
                    'Đánh giá #' .
                    (int)$row['id'] .
                    ' | ' .
                    ($row['ten_san_pham'] ?: 'sản phẩm') .
                    ' | ' .
                    (int)$row['so_sao'] .
                    ' sao | ngày tạo: ' .
                    ($row['ngay_tao'] ?: 'chưa có');
            }
        }
    }

    if (aiTableExists($conn, 'thong_bao')) {
        $stmt = $conn->prepare("
            SELECT
                COUNT(*) AS tong,
                SUM(
                    CASE
                        WHEN da_doc = 0 THEN 1
                        ELSE 0
                    END
                ) AS chua_doc,
                MAX(ngay_tao) AS moi_nhat
            FROM thong_bao
            WHERE nguoi_dung_id = ?
        ");

        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                $lines[] =
                    'Thông báo: ' .
                    (int)$row['tong'] .
                    ' tổng, ' .
                    (int)$row['chua_doc'] .
                    ' chưa đọc, mới nhất: ' .
                    ($row['moi_nhat'] ?: 'chưa có');
            }
        }
    }

    return implode("\n", $lines);
}

function aiAuditContext(
    mysqli $conn,
    int $limit = 45
): string {
    if (!aiTableExists($conn, 'ai_audit_log')) {
        return
            'Nhật ký thay đổi chưa được cài. ' .
            'AI chỉ biết thời gian tạo từ các cột ngày_tạo hiện có; ' .
            'không thể biết chính xác dữ liệu đã bị xóa trước đây.';
    }

    $limit = max(5, min(100, $limit));

    $allowedTables = [
        'san_pham',
        'san_pham_bien_the',
        'san_pham_thong_so',
        'khuyen_mai',
        'danh_muc',
        'mau_sac',
    ];

    $quoted = implode(
        ',',
        array_map(
            fn($x) =>
                "'" .
                $conn->real_escape_string($x) .
                "'",
            $allowedTables
        )
    );

    $result = $conn->query("
        SELECT
            id,
            bang,
            ban_ghi_id,
            hanh_dong,
            du_lieu_cu,
            du_lieu_moi,
            ngay_tao
        FROM ai_audit_log
        WHERE bang IN ({$quoted})
        ORDER BY id DESC
        LIMIT {$limit}
    ");

    if (!$result) {
        return 'Không đọc được nhật ký thay đổi.';
    }

    $lines = [];

    while ($row = $result->fetch_assoc()) {
        $old = trim((string)($row['du_lieu_cu'] ?? ''));
        $new = trim((string)($row['du_lieu_moi'] ?? ''));

        if (mb_strlen($old, 'UTF-8') > 900) {
            $old = mb_substr($old, 0, 900, 'UTF-8') . '...';
        }

        if (mb_strlen($new, 'UTF-8') > 900) {
            $new = mb_substr($new, 0, 900, 'UTF-8') . '...';
        }

        $lines[] =
            'Audit #' .
            (int)$row['id'] .
            ' | bảng ' .
            $row['bang'] .
            ' | bản ghi ' .
            ($row['ban_ghi_id'] ?? '') .
            ' | hành động ' .
            $row['hanh_dong'] .
            ' | lúc ' .
            $row['ngay_tao'] .
            ' | cũ: ' .
            ($old ?: '-') .
            ' | mới: ' .
            ($new ?: '-');
    }

    return !empty($lines)
        ? implode("\n", $lines)
        : 'Chưa có nhật ký thay đổi.';
}
