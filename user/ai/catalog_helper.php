<?php

function aiTableExists(
    mysqli $conn,
    string $table
): bool {
    $safe = $conn->real_escape_string($table);

    $result = $conn->query(
        "SHOW TABLES LIKE '{$safe}'"
    );

    return $result && $result->num_rows > 0;
}

function aiLoadCatalog(
    mysqli $conn,
    int $limit = 150
): array {
    $limit = max(10, min(300, $limit));

    $result = $conn->query("
        SELECT
            id,
            ma_san_pham,
            ten_san_pham,
            gia,
            so_luong,
            hinh_anh,
            mo_ta_ngan,
            mo_ta_dai,
            mo_ta,
            ngay_tao
        FROM san_pham
        WHERE trang_thai = 1
        ORDER BY id DESC
        LIMIT {$limit}
    ");

    if (!$result) {
        return [];
    }

    $products = [];

    while ($row = $result->fetch_assoc()) {
        $id = (int)$row['id'];

        $products[$id] = [
            'id' => $id,
            'code' => (string)($row['ma_san_pham'] ?? ''),
            'name' => trim((string)$row['ten_san_pham']),
            'base_price' => (float)($row['gia'] ?? 0),
            'base_stock' => (int)($row['so_luong'] ?? 0),
            'image' => trim((string)($row['hinh_anh'] ?? '')),
            'created_at' => (string)($row['ngay_tao'] ?? ''),
            'short' => trim((string)($row['mo_ta_ngan'] ?? '')),
            'long' => trim(
                (string)(
                    $row['mo_ta_dai']
                    ?? $row['mo_ta']
                    ?? ''
                )
            ),
            'variants' => [],
            'specs' => [],
            'promotions' => [],
        ];
    }

    if (empty($products)) {
        return [];
    }

    $ids = implode(
        ',',
        array_map('intval', array_keys($products))
    );

    if (aiTableExists($conn, 'san_pham_bien_the')) {
        $rs = $conn->query("
            SELECT
                bt.id,
                bt.san_pham_id,
                bt.ram,
                bt.rom,
                bt.gia,
                bt.so_luong,
                ms.ten_mau
            FROM san_pham_bien_the bt
            LEFT JOIN mau_sac ms
                ON ms.id = bt.mau_id
            WHERE bt.san_pham_id IN ({$ids})
            ORDER BY
                bt.san_pham_id ASC,
                bt.gia ASC,
                bt.id ASC
        ");

        if ($rs) {
            while ($row = $rs->fetch_assoc()) {
                $productId = (int)$row['san_pham_id'];

                if (!isset($products[$productId])) {
                    continue;
                }

                $products[$productId]['variants'][] = [
                    'id' => (int)$row['id'],
                    'ram' => trim((string)($row['ram'] ?? '')),
                    'rom' => trim((string)($row['rom'] ?? '')),
                    'color' => trim((string)($row['ten_mau'] ?? '')),
                    'price' => (float)($row['gia'] ?? 0),
                    'stock' => (int)($row['so_luong'] ?? 0),
                ];
            }
        }
    }

    if (aiTableExists($conn, 'san_pham_thong_so')) {
        $rs = $conn->query("
            SELECT
                san_pham_id,
                nhom_ten,
                ten_thong_so,
                gia_tri
            FROM san_pham_thong_so
            WHERE san_pham_id IN ({$ids})
            ORDER BY
                san_pham_id ASC,
                thu_tu_nhom ASC,
                thu_tu ASC,
                id ASC
        ");

        if ($rs) {
            while ($row = $rs->fetch_assoc()) {
                $productId = (int)$row['san_pham_id'];

                if (!isset($products[$productId])) {
                    continue;
                }

                $products[$productId]['specs'][] = [
                    'group' => trim((string)($row['nhom_ten'] ?? '')),
                    'name' => trim((string)($row['ten_thong_so'] ?? '')),
                    'value' => trim((string)($row['gia_tri'] ?? '')),
                ];
            }
        }
    }

    if (aiTableExists($conn, 'khuyen_mai')) {
        $rs = $conn->query("
            SELECT
                san_pham_id,
                bien_the_id,
                ten_khuyen_mai,
                kieu_giam,
                gia_tri,
                ngay_bat_dau,
                ngay_ket_thuc,
                ngay_tao
            FROM khuyen_mai
            WHERE
                san_pham_id IN ({$ids})
                AND trang_thai = 1
                AND NOW() BETWEEN ngay_bat_dau AND ngay_ket_thuc
            ORDER BY id DESC
        ");

        if ($rs) {
            while ($row = $rs->fetch_assoc()) {
                $productId = (int)$row['san_pham_id'];

                if (!isset($products[$productId])) {
                    continue;
                }

                $products[$productId]['promotions'][] = [
                    'variant_id' => !empty($row['bien_the_id'])
                        ? (int)$row['bien_the_id']
                        : null,
                    'name' => trim((string)$row['ten_khuyen_mai']),
                    'type' => trim((string)$row['kieu_giam']),
                    'value' => (float)$row['gia_tri'],
                    'start' => (string)$row['ngay_bat_dau'],
                    'end' => (string)$row['ngay_ket_thuc'],
                    'created_at' => (string)$row['ngay_tao'],
                ];
            }
        }
    }

    foreach ($products as &$product) {
        if (!empty($product['variants'])) {
            $prices = [];
            $stock = 0;

            foreach ($product['variants'] as $variant) {
                if ($variant['price'] > 0) {
                    $prices[] = $variant['price'];
                }

                $stock += (int)$variant['stock'];
            }

            $product['min_price'] = !empty($prices)
                ? min($prices)
                : $product['base_price'];

            $product['max_price'] = !empty($prices)
                ? max($prices)
                : $product['base_price'];

            $product['stock'] = $stock;
        } else {
            $product['min_price'] = $product['base_price'];
            $product['max_price'] = $product['base_price'];
            $product['stock'] = $product['base_stock'];
        }

        $chunks = [
            $product['name'],
            $product['short'],
            $product['long'],
        ];

        foreach ($product['variants'] as $variant) {
            $chunks[] = implode(
                ' ',
                [
                    $variant['ram'],
                    $variant['rom'],
                    $variant['color'],
                ]
            );
        }

        foreach ($product['specs'] as $spec) {
            $chunks[] = implode(
                ' ',
                [
                    $spec['group'],
                    $spec['name'],
                    $spec['value'],
                ]
            );
        }

        $product['search_text'] = aiNormalizeText(
            implode(' ', $chunks)
        );
    }

    unset($product);

    return array_values($products);
}

function aiRankCatalog(
    array $products,
    string $question,
    int $limit = 16
): array {
    $q = aiNormalizeText($question);

    $tokens = preg_split(
        '/\s+/u',
        $q,
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    $tokens = array_values(
        array_unique(
            array_filter(
                $tokens,
                fn($x) => mb_strlen($x, 'UTF-8') >= 3
            )
        )
    );

    foreach ($products as &$product) {
        $score = 0;
        $text = (string)$product['search_text'];

        foreach ($tokens as $token) {
            if (str_contains($text, $token)) {
                $score += 5;
            }

            if (
                str_contains(
                    aiNormalizeText($product['name']),
                    $token
                )
            ) {
                $score += 8;
            }
        }

        if ((int)$product['stock'] > 0) {
            $score += 4;
        } else {
            $score -= 10;
        }

        $product['_score'] = $score;
    }

    unset($product);

    usort(
        $products,
        fn($a, $b) =>
            ($b['_score'] ?? 0)
            <=>
            ($a['_score'] ?? 0)
    );

    return array_slice(
        $products,
        0,
        max(3, min(30, $limit))
    );
}

function aiCatalogToText(array $products): string
{
    $blocks = [];

    foreach ($products as $product) {
        $lines = [];

        $lines[] =
            'SẢN PHẨM ID ' .
            $product['id'] .
            ': ' .
            $product['name'];

        $lines[] =
            'Link: /iPhoneStore/user/san_pham/chi_tiet.php?id=' .
            $product['id'];

        $lines[] =
            'Ngày thêm sản phẩm: ' .
            ($product['created_at'] ?: 'không có dữ liệu');

        $lines[] =
            'Giá từ: ' .
            number_format(
                (float)$product['min_price'],
                0,
                ',',
                '.'
            ) .
            'đ';

        $lines[] =
            'Tồn kho tổng: ' .
            (int)$product['stock'];

        if (!empty($product['variants'])) {
            $items = [];

            foreach (
                array_slice(
                    $product['variants'],
                    0,
                    24
                )
                as $variant
            ) {
                $items[] = implode(
                    ' | ',
                    array_filter([
                        $variant['rom'],
                        $variant['ram'],
                        $variant['color'],
                        number_format(
                            (float)$variant['price'],
                            0,
                            ',',
                            '.'
                        ) . 'đ',
                        'tồn ' . (int)$variant['stock'],
                    ])
                );
            }

            $lines[] =
                'Phiên bản: ' .
                implode(' ; ', $items);
        }

        if (!empty($product['specs'])) {
            $items = [];

            foreach (
                array_slice(
                    $product['specs'],
                    0,
                    60
                )
                as $spec
            ) {
                $items[] =
                    ($spec['group'] !== ''
                        ? $spec['group'] . ' - '
                        : '') .
                    $spec['name'] .
                    ': ' .
                    $spec['value'];
            }

            $lines[] =
                'Thông số: ' .
                implode(' ; ', $items);
        }

        if (!empty($product['promotions'])) {
            $items = [];

            foreach ($product['promotions'] as $promo) {
                $items[] =
                    $promo['name'] .
                    ' (' .
                    $promo['start'] .
                    ' → ' .
                    $promo['end'] .
                    ')';
            }

            $lines[] =
                'Khuyến mãi: ' .
                implode(' ; ', $items);
        }

        if ($product['short'] !== '') {
            $lines[] =
                'Mô tả: ' .
                mb_substr(
                    preg_replace('/\s+/u', ' ', $product['short']),
                    0,
                    550,
                    'UTF-8'
                );
        }

        $blocks[] = implode("\n", $lines);
    }

    return implode(
        "\n\n--------------------\n\n",
        $blocks
    );
}

function aiCatalogCardMap(array $products): array
{
    $map = [];

    foreach ($products as $product) {
        $map[(int)$product['id']] = [
            'id' => (int)$product['id'],
            'name' => (string)$product['name'],
            'price' => (float)$product['min_price'],
            'stock' => (int)$product['stock'],
            'image' => (string)($product['image'] ?? ''),
            'url' =>
                '/iPhoneStore/user/san_pham/chi_tiet.php?id=' .
                (int)$product['id'],
        ];
    }

    return $map;
}
