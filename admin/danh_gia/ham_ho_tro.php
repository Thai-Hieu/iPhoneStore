<?php

if (!function_exists('adgLayCotBangDanhGia')) {
    function adgLayCotBangDanhGia(mysqli $conn): array
    {
        $columns = [];

        $result = $conn->query("
            SHOW COLUMNS FROM danh_gia
        ");

        if (!$result) {
            return $columns;
        }

        while ($row = $result->fetch_assoc()) {
            $columns[$row['Field']] = $row;
        }

        return $columns;
    }

    function adgTimCotDauTien(
        array $columns,
        array $candidates
    ): ?string {
        foreach ($candidates as $candidate) {
            if (isset($columns[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    function adgBindParams(
        mysqli_stmt $stmt,
        string $types,
        array &$values
    ): void {
        if ($types === '') {
            return;
        }

        $params = [$types];

        foreach ($values as &$value) {
            $params[] = &$value;
        }

        call_user_func_array(
            [$stmt, 'bind_param'],
            $params
        );
    }

    function adgMeta(mysqli $conn): array
    {
        $columns = adgLayCotBangDanhGia($conn);

        return [
            'columns' => $columns,
            'user' => adgTimCotDauTien(
                $columns,
                ['nguoi_dung_id', 'user_id']
            ),
            'content' => adgTimCotDauTien(
                $columns,
                [
                    'noi_dung',
                    'binh_luan',
                    'comment',
                    'nhan_xet'
                ]
            ),
            'date' => adgTimCotDauTien(
                $columns,
                [
                    'ngay_danh_gia',
                    'ngay_tao',
                    'created_at'
                ]
            ),
            'status' => adgTimCotDauTien(
                $columns,
                ['trang_thai']
            ),
            'id' => isset($columns['id'])
                ? 'id'
                : null
        ];
    }
    function adgCoBangPhanHoiDanhGia(
        mysqli $conn
    ): bool {
        $stmt = $conn->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'phan_hoi_danh_gia'
            LIMIT 1
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->execute();

        return (bool)$stmt
            ->get_result()
            ->fetch_assoc();
    }

    function adgCoBangHinhAnhDanhGia(
        mysqli $conn
    ): bool {
        $stmt = $conn->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'danh_gia_hinh_anh'
            LIMIT 1
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->execute();

        return (bool)$stmt
            ->get_result()
            ->fetch_assoc();
    }

    function adgLayHinhAnhDanhGia(
        mysqli $conn,
        array $reviewIds
    ): array {
        $result = [];

        $reviewIds = array_values(
            array_unique(
                array_filter(
                    array_map(
                        'intval',
                        $reviewIds
                    ),
                    static fn($id) => $id > 0
                )
            )
        );

        if (
            empty($reviewIds) ||
            !adgCoBangHinhAnhDanhGia($conn)
        ) {
            return $result;
        }

        $placeholders =
            implode(
                ',',
                array_fill(
                    0,
                    count($reviewIds),
                    '?'
                )
            );

        $types =
            str_repeat(
                'i',
                count($reviewIds)
            );

        $values = $reviewIds;

        $stmt = $conn->prepare("
            SELECT
                id,
                danh_gia_id,
                hinh_anh,
                thu_tu
            FROM danh_gia_hinh_anh
            WHERE danh_gia_id IN ($placeholders)
            ORDER BY
                danh_gia_id ASC,
                thu_tu ASC,
                id ASC
        ");

        if (!$stmt) {
            return $result;
        }

        adgBindParams(
            $stmt,
            $types,
            $values
        );

        $stmt->execute();

        $rows =
            $stmt->get_result();

        while ($row = $rows->fetch_assoc()) {
            $reviewId =
                (int)$row['danh_gia_id'];

            if (!isset($result[$reviewId])) {
                $result[$reviewId] = [];
            }

            $result[$reviewId][] = $row;
        }

        return $result;
    }

}
