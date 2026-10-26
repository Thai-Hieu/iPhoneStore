<?php

/*
    =========================================================
    HELPER KHUYẾN MÃI DÙNG CHUNG CHO USER
    =========================================================

    Mục tiêu:
    - Một nguồn tính giá duy nhất cho:
      + trang giỏ hàng
      + thêm vào giỏ
      + tăng / giảm số lượng
      + thanh toán (nên dùng cùng helper này)
    - Hỗ trợ:
      + khuyến mãi toàn sản phẩm (bien_the_id NULL/0)
      + khuyến mãi riêng biến thể
      + số lượng mua tối thiểu
      + xả kho theo tồn
      + HSSV / người có công không còn yêu cầu xác minh riêng
      + khách hàng thân thiết
      + số đơn hoàn thành tối thiểu
      + tổng chi tiêu tối thiểu
      + khung giờ
      + giới hạn số tiền giảm
      + ưu tiên khuyến mãi
*/

if (!function_exists('kmUserTableExists')) {
    function kmUserTableExists(
        mysqli $conn,
        string $table
    ): bool {
        $safe = $conn->real_escape_string($table);

        $rs = $conn->query(
            "SHOW TABLES LIKE '{$safe}'"
        );

        return $rs && $rs->num_rows > 0;
    }
}

if (!function_exists('kmUserColumnExists')) {
    function kmUserColumnExists(
        mysqli $conn,
        string $table,
        string $column
    ): bool {
        $stmt = $conn->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            'ss',
            $table,
            $column
        );

        $stmt->execute();

        $exists =
            (bool)$stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();

        return $exists;
    }
}

if (!function_exists('kmUserCompletedStats')) {
    function kmUserCompletedStats(
        mysqli $conn,
        int $userId
    ): array {
        if (
            $userId <= 0 ||
            !kmUserTableExists($conn, 'don_hang')
        ) {
            return [
                'orders' => 0,
                'spent' => 0.0,
            ];
        }

        $stmt = $conn->prepare("
            SELECT
                COUNT(*) AS tong_don,
                COALESCE(
                    SUM(tong_tien),
                    0
                ) AS tong_chi_tieu
            FROM don_hang
            WHERE nguoi_dung_id = ?
              AND trang_thai IN (
                  'hoan_thanh',
                  'Hoàn thành',
                  'da_giao',
                  'Đã giao'
              )
        ");

        if (!$stmt) {
            return [
                'orders' => 0,
                'spent' => 0.0,
            ];
        }

        $stmt->bind_param(
            'i',
            $userId
        );

        $stmt->execute();

        $row =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();

        return [
            'orders' =>
                (int)($row['tong_don'] ?? 0),
            'spent' =>
                (float)($row['tong_chi_tieu'] ?? 0),
        ];
    }
}

if (!function_exists('kmUserAudienceApproved')) {
    function kmUserAudienceApproved(
        mysqli $conn,
        int $userId,
        string $audience,
        array $completedStats
    ): bool {
        /*
            Không còn dùng bảng nguoi_dung_uu_dai
            và không còn cơ chế admin xác minh đối tượng riêng.

            Các nhóm HSSV / người có công nếu còn được chọn
            trong chương trình khuyến mãi thì được coi là nhãn
            phân loại chương trình, không chặn user theo trạng thái
            "đã duyệt" nữa.

            Khách thân thiết vẫn được xét theo lịch sử mua hàng
            vì đây là điều kiện có thể xác định tự động.
        */
        if ($audience === '' || $audience === 'tat_ca') {
            return true;
        }

        if (
            in_array(
                $audience,
                [
                    'hoc_sinh_sinh_vien',
                    'nguoi_co_cong',
                ],
                true
            )
        ) {
            return true;
        }

        if ($audience === 'khach_than_thiet') {
            if ($userId <= 0) {
                return false;
            }

            return
                (int)($completedStats['orders'] ?? 0) > 0 ||
                (float)($completedStats['spent'] ?? 0) > 0;
        }

        /*
            Giá trị đối tượng lạ/không còn hỗ trợ:
            không chặn toàn bộ khuyến mãi để tránh lỗi dữ liệu cũ.
        */
        return true;
    }
}

if (!function_exists('kmUserTimeWindowOk')) {
    function kmUserTimeWindowOk(
        ?string $startTime,
        ?string $endTime
    ): bool {
        $start =
            trim((string)$startTime);

        $end =
            trim((string)$endTime);

        if ($start === '' && $end === '') {
            return true;
        }

        if ($start === '' || $end === '') {
            return false;
        }

        $start = substr($start, 0, 5);
        $end = substr($end, 0, 5);
        $now = date('H:i');

        /*
            Cùng ngày: 08:00 -> 22:00
        */
        if ($start <= $end) {
            return
                $now >= $start &&
                $now <= $end;
        }

        /*
            Qua đêm: 22:00 -> 02:00
        */
        return
            $now >= $start ||
            $now <= $end;
    }
}

if (!function_exists('kmUserResolveStock')) {
    function kmUserResolveStock(
        mysqli $conn,
        int $sanPhamId,
        ?int $bienTheId
    ): int {
        if ($sanPhamId <= 0) {
            return 0;
        }

        if ($bienTheId !== null && $bienTheId > 0) {
            $stmt = $conn->prepare("
                SELECT so_luong
                FROM san_pham_bien_the
                WHERE id = ? AND san_pham_id = ?
                LIMIT 1
            ");

            if (!$stmt) {
                return 0;
            }

            $stmt->bind_param('ii', $bienTheId, $sanPhamId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return max(0, (int)($row['so_luong'] ?? 0));
        }

        $stmt = $conn->prepare("
            SELECT
                CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM san_pham_bien_the bt_exists
                        WHERE bt_exists.san_pham_id = sp.id
                    )
                    THEN COALESCE((
                        SELECT SUM(bt.so_luong)
                        FROM san_pham_bien_the bt
                        WHERE bt.san_pham_id = sp.id
                    ), 0)
                    ELSE COALESCE(sp.so_luong, 0)
                END AS ton_kho
            FROM san_pham sp
            WHERE sp.id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param('i', $sanPhamId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return max(0, (int)($row['ton_kho'] ?? 0));
    }
}


if (!function_exists('kmUserResolveClearanceStock')) {
    function kmUserResolveClearanceStock(
        mysqli $conn,
        int $sanPhamId,
        ?int $bienTheId = null
    ): int {
        if ($sanPhamId <= 0) {
            return 0;
        }

        /*
            XẢ KHO PHẢI XÉT THEO ĐƠN VỊ KHÁCH THỰC SỰ MUA.

            - Đã chọn biến thể:
              dùng đúng tồn kho của biến thể đó.

            - Chưa chọn biến thể / đang xét card toàn sản phẩm:
              nếu sản phẩm có biến thể thì lấy MAX tồn kho của một biến thể,
              KHÔNG lấy SUM.

              Ví dụ:
              2 + 2 + 2 = tổng 6 nhưng không biến thể nào đạt ngưỡng 5
              => không đủ điều kiện Xả kho.

              2 + 7 + 3, ngưỡng 5
              => có biến thể tồn 7 nên card Xả kho có thể xuất hiện.
        */
        if ($bienTheId !== null && $bienTheId > 0) {
            return kmUserResolveStock(
                $conn,
                $sanPhamId,
                $bienTheId
            );
        }

        $stmt = $conn->prepare("
            SELECT
                CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM san_pham_bien_the bt_exists
                        WHERE bt_exists.san_pham_id = sp.id
                    )
                    THEN COALESCE((
                        SELECT MAX(bt.so_luong)
                        FROM san_pham_bien_the bt
                        WHERE bt.san_pham_id = sp.id
                    ), 0)
                    ELSE COALESCE(sp.so_luong, 0)
                END AS ton_xet_xa_kho
            FROM san_pham sp
            WHERE sp.id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param('i', $sanPhamId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return max(
            0,
            (int)($row['ton_xet_xa_kho'] ?? 0)
        );
    }
}

if (!function_exists('kmUserEligible')) {
    function kmUserEligible(
        mysqli $conn,
        array $promo,
        array $context,
        array $completedStats
    ): bool {
        $qty =
            max(
                1,
                (int)($context['so_luong'] ?? 1)
            );

        $stock =
            max(
                0,
                (int)($context['ton_kho'] ?? 0)
            );

        /*
            =========================================================
            CHẶN KHUYẾN MÃI KHI HẾT HÀNG
            =========================================================

            Đây là helper dùng chung cho user nên rule này áp dụng
            đồng bộ ở mọi nơi đang gọi layKhuyenMaiHienTai():

            - trang chi tiết sản phẩm;
            - thêm vào giỏ;
            - giỏ hàng;
            - tăng / giảm số lượng;
            - thanh toán;
            - các nơi khác dùng chung helper này.

            Nếu tồn kho hiện tại <= 0:
            - không trả khuyến mãi;
            - không tính giá sale;
            - không áp Flash Sale / Xả kho / Ưu đãi Online;
            - không hiện % giảm nếu giao diện dùng đúng helper.
        */
        if ($stock <= 0) {
            return false;
        }

        $userId =
            (int)($context['nguoi_dung_id'] ?? 0);

        $minQty =
            max(
                1,
                (int)(
                    $promo['so_luong_mua_toi_thieu']
                    ?? 1
                )
            );

        if ($qty < $minQty) {
            return false;
        }

        $stockThreshold =
            $promo['ton_kho_toi_da']
            ?? null;

        /*
            Với loại "xả kho", cột ton_kho_toi_da được giữ lại
            để tương thích database cũ nhưng được hiểu là:

            "Ngưỡng tồn kho tối thiểu để kích hoạt xả kho".

            Ví dụ admin nhập 20:
            - tồn 5  -> không xả;
            - tồn 19 -> không xả;
            - tồn 20 -> bắt đầu xả;
            - tồn 50 -> vẫn xả.

            Các loại khuyến mãi khác không dùng điều kiện này.
        */
        $stockThresholdValue =
            ($stockThreshold !== null && $stockThreshold !== '')
                ? (int)$stockThreshold
                : 0;

        if (
            (string)($promo['loai_khuyen_mai'] ?? '') === 'xa_kho' &&
            $stockThresholdValue > 0
        ) {
            $sanPhamId =
                (int)($promo['san_pham_id'] ?? 0);

            $selectedVariantId =
                (int)($context['bien_the_id'] ?? 0);

            if ($selectedVariantId <= 0) {
                $selectedVariantId =
                    (int)($promo['bien_the_id'] ?? 0);
            }

            $clearanceStock =
                kmUserResolveClearanceStock(
                    $conn,
                    $sanPhamId,
                    $selectedVariantId > 0
                        ? $selectedVariantId
                        : null
                );

            if ($clearanceStock < $stockThresholdValue) {
                return false;
            }
        }

        $audience =
            trim(
                (string)(
                    $promo['doi_tuong_khach']
                    ?? 'tat_ca'
                )
            );

        if (
            !kmUserAudienceApproved(
                $conn,
                $userId,
                $audience,
                $completedStats
            )
        ) {
            return false;
        }

        $minCompletedOrders =
            max(
                0,
                (int)(
                    $promo['don_hang_toi_thieu']
                    ?? 0
                )
            );

        if (
            (int)$completedStats['orders'] <
            $minCompletedOrders
        ) {
            return false;
        }

        $minSpent =
            max(
                0,
                (float)(
                    $promo['chi_tieu_toi_thieu']
                    ?? 0
                )
            );

        if (
            (float)$completedStats['spent'] <
            $minSpent
        ) {
            return false;
        }

        if (
            !kmUserTimeWindowOk(
                $promo['gio_bat_dau'] ?? null,
                $promo['gio_ket_thuc'] ?? null
            )
        ) {
            return false;
        }

        return true;
    }
}

if (!function_exists('tinhGiaSauKhuyenMai')) {
    function tinhGiaSauKhuyenMai(
        float $giaGoc,
        ?array $khuyenMai
    ): float {
        if (!$khuyenMai) {
            return max(
                0,
                round($giaGoc, 2)
            );
        }

        $giaTri =
            max(
                0,
                (float)(
                    $khuyenMai['gia_tri']
                    ?? 0
                )
            );

        if (
            (string)(
                $khuyenMai['kieu_giam']
                ?? ''
            ) === 'phan_tram'
        ) {
            $soTienGiam =
                $giaGoc *
                $giaTri /
                100;
        } else {
            $soTienGiam =
                $giaTri;
        }

        /*
            Nếu admin đặt "giảm tối đa"
            thì không được giảm vượt mức đó.
        */
        $cap =
            $khuyenMai['gioi_han_giam']
            ?? null;

        if (
            $cap !== null &&
            $cap !== '' &&
            (float)$cap > 0
        ) {
            $soTienGiam =
                min(
                    $soTienGiam,
                    (float)$cap
                );
        }

        $soTienGiam =
            min(
                $giaGoc,
                max(0, $soTienGiam)
            );

        return max(
            0,
            round(
                $giaGoc - $soTienGiam,
                2
            )
        );
    }
}

if (!function_exists('layKhuyenMaiHienTai')) {
    function layKhuyenMaiHienTai(
        mysqli $conn,
        int $sanPhamId,
        ?int $bienTheId = null,
        array $context = []
    ): ?array {
        if (
            $sanPhamId <= 0 ||
            !kmUserTableExists(
                $conn,
                'khuyen_mai'
            )
        ) {
            return null;
        }

        /*
            Lấy mọi KM đang bật + đúng thời gian.
            Sau đó tách:
            1) KM riêng biến thể
            2) KM toàn sản phẩm
            để giữ đúng ưu tiên nghiệp vụ:
            biến thể riêng > toàn sản phẩm.
        */
        $stmt = $conn->prepare("
            SELECT *
            FROM khuyen_mai
            WHERE san_pham_id = ?
              AND trang_thai = 1
              AND NOW() BETWEEN
                    ngay_bat_dau
                    AND ngay_ket_thuc
              AND (
                    bien_the_id IS NULL
                    OR bien_the_id = 0
                    OR bien_the_id = ?
              )
            ORDER BY
                uu_tien DESC,
                id DESC
        ");

        if (!$stmt) {
            return null;
        }

        $variantForQuery =
            $bienTheId !== null &&
            $bienTheId > 0
                ? $bienTheId
                : 0;

        $stmt->bind_param(
            'ii',
            $sanPhamId,
            $variantForQuery
        );

        $stmt->execute();

        $rows =
            $stmt
                ->get_result()
                ->fetch_all(
                    MYSQLI_ASSOC
                );

        $stmt->close();

        if (!$rows) {
            return null;
        }

        $userId = (int)($context['nguoi_dung_id'] ?? 0);

        $context['san_pham_id'] = $sanPhamId;
        $context['bien_the_id'] =
            $bienTheId !== null && $bienTheId > 0
                ? $bienTheId
                : 0;

        if (!array_key_exists('ton_kho', $context)) {
            $context['ton_kho'] = kmUserResolveStock(
                $conn,
                $sanPhamId,
                $bienTheId
            );
        }

        $completedStats =
            kmUserCompletedStats(
                $conn,
                $userId
            );

        $variantPromos = [];
        $productPromos = [];

        foreach ($rows as $promo) {
            $promoVariant =
                $promo['bien_the_id'] !== null
                    ? (int)$promo['bien_the_id']
                    : 0;

            if (
                $bienTheId !== null &&
                $bienTheId > 0 &&
                $promoVariant === $bienTheId
            ) {
                $variantPromos[] = $promo;
            } elseif ($promoVariant <= 0) {
                $productPromos[] = $promo;
            }
        }

        /*
            KM riêng biến thể luôn được xét trước.
            Nếu không có KM riêng nào thỏa điều kiện,
            mới fallback về KM toàn sản phẩm.
        */
        foreach (
            [
                $variantPromos,
                $productPromos,
            ]
            as $group
        ) {
            foreach ($group as $promo) {
                if (
                    kmUserEligible(
                        $conn,
                        $promo,
                        $context,
                        $completedStats
                    )
                ) {
                    return $promo;
                }
            }
        }

        return null;
    }
}

if (!function_exists('tenLoaiKhuyenMai')) {
    function tenLoaiKhuyenMai(
        ?string $loai
    ): string {
        $labels = [
            'giam_truc_tiep' =>
                'Giảm trực tiếp',
            'flash_sale' =>
                'Flash Sale',
            'xa_kho' =>
                'Xả kho',
            'mua_nhieu' =>
                'Mua nhiều giảm nhiều',
            'hoc_sinh_sinh_vien' =>
                'Ưu đãi HSSV',
            'nguoi_co_cong' =>
                'Ưu đãi người có công',
            'tri_an_khach_hang' =>
                'Tri ân khách hàng',
            'ra_mat' =>
                'Ưu đãi ra mắt',
            'uu_dai_online' =>
                'Ưu đãi Online',
            'thanh_toan' =>
                'Ưu đãi thanh toán',
            'thu_cu_doi_moi' =>
                'Thu cũ đổi mới',
            'khac' =>
                'Khuyến mãi',
        ];

        return
            $labels[
                (string)$loai
            ]
            ?? 'Khuyến mãi';
    }
}
