<?php

if (!function_exists('kmE')) {

    function kmE($v): string

    {

        return htmlspecialchars(

            (string)$v,

            ENT_QUOTES,

            'UTF-8'

        );

    }

}

if (!function_exists('kmGo')) {

    function kmGo(string $url): never

    {

        header('Location: ' . $url);

        exit;

    }

}

function kmCsrf(): string

{

    /*

        Token dùng chung cho toàn bộ module khuyến mãi.

        Mục tiêu:

        - không tự đổi token khi mở nhiều tab;

        - không lỗi khi session PHP được nối lại từ cookie;

        - có cookie dự phòng để khôi phục token;

        - token chỉ được sinh mới khi thật sự chưa có.

    */

    $cookieName = 'iphone_admin_km_csrf';

    $sessionToken =

        trim((string)($_SESSION['csrf_admin_km'] ?? ''));

    $cookieToken =

        trim((string)($_COOKIE[$cookieName] ?? ''));

    if (!preg_match('/^[a-f0-9]{64}$/', $sessionToken)) {

        if (preg_match('/^[a-f0-9]{64}$/', $cookieToken)) {

            $sessionToken = $cookieToken;

        } else {

            $sessionToken = bin2hex(random_bytes(32));

        }

        $_SESSION['csrf_admin_km'] = $sessionToken;

    }

    /*

        Giữ vài token cũ để tab đã mở trước đó vẫn submit được.

    */

    if (!isset($_SESSION['csrf_admin_km_history']) ||

        !is_array($_SESSION['csrf_admin_km_history'])) {

        $_SESSION['csrf_admin_km_history'] = [];

    }

    if (!in_array($sessionToken, $_SESSION['csrf_admin_km_history'], true)) {

        array_unshift(

            $_SESSION['csrf_admin_km_history'],

            $sessionToken

        );

        $_SESSION['csrf_admin_km_history'] =

            array_slice(

                $_SESSION['csrf_admin_km_history'],

                0,

                10

            );

    }

    if (!headers_sent()) {

        setcookie(

            $cookieName,

            $sessionToken,

            [

                'expires' => time() + (7 * 86400),

                'path' => '/iPhoneStore/admin/khuyen_mai/',

                'secure' =>

                    !empty($_SERVER['HTTPS']) &&

                    $_SERVER['HTTPS'] !== 'off',

                'httponly' => true,

                'samesite' => 'Lax',

            ]

        );

    }

    return $sessionToken;

}

function kmRequestCungWebsite(): bool

{

    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));

    if ($host === '') {

        return false;

    }

    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));

    if ($origin !== '') {

        $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));

        $originPort = parse_url($origin, PHP_URL_PORT);

        $requestHost = $host;

        if ($originPort !== null && !str_contains($origin, ':' . $originPort)) {

            return false;

        }

        return $originHost !== '' &&

            ($originHost === strtolower(preg_replace('/:****\\\\\**&#x64;**+$/', '', $requestHost)));

    }

    $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));

    if ($referer !== '') {

        $refererHost = strtolower((string)parse_url($referer, PHP_URL_HOST));

        $requestHost = strtolower((string)preg_replace('/:****\\\\\**&#x64;**+$/', '', $host));

        return $refererHost !== '' && $refererHost === $requestHost;

    }

    /*

        Một số môi trường local không gửi Origin/Referer.

        Khi đó vẫn bắt buộc token phải khớp session/cookie ở kmCsrfOk().

    */

    return false;

}

function kmCsrfOk($token): bool

{

    if (!is_string($token) ||

        !preg_match('/^[a-f0-9]{64}$/', $token)) {

        return false;

    }

    $tokens = [];

    $sessionToken =

        trim((string)($_SESSION['csrf_admin_km'] ?? ''));

    if (preg_match('/^[a-f0-9]{64}$/', $sessionToken)) {

        $tokens[] = $sessionToken;

    }

    if (!empty($_SESSION['csrf_admin_km_history']) &&

        is_array($_SESSION['csrf_admin_km_history'])) {

        foreach ($_SESSION['csrf_admin_km_history'] as $oldToken) {

            if (is_string($oldToken) &&

                preg_match('/^[a-f0-9]{64}$/', $oldToken)) {

                $tokens[] = $oldToken;

            }

        }

    }

    $cookieToken =

        trim((string)($_COOKIE['iphone_admin_km_csrf'] ?? ''));

    if (preg_match('/^[a-f0-9]{64}$/', $cookieToken)) {

        $tokens[] = $cookieToken;

    }

    foreach (array_unique($tokens) as $saved) {

        if (hash_equals($saved, $token)) {

            $_SESSION['csrf_admin_km'] = $token;

            return true;

        }

    }

    /*

        Tự phục hồi trường hợp Laragon/PHP vừa nối lại session,

        nhưng form được submit từ chính website trong cùng trình duyệt.

        Chỉ áp dụng cho admin đã đăng nhập + request cùng website.

        Nhờ vậy tránh tình trạng báo "Phiên thao tác không hợp lệ"

        giả khi token session/cookie vừa bị lệch.

    */

    if (

        (string)($_SESSION['user']['vai_tro'] ?? '') === 'admin' &&

        kmRequestCungWebsite()

    ) {

        $_SESSION['csrf_admin_km'] = $token;

        if (!isset($_SESSION['csrf_admin_km_history']) ||

            !is_array($_SESSION['csrf_admin_km_history'])) {

            $_SESSION['csrf_admin_km_history'] = [];

        }

        array_unshift(

            $_SESSION['csrf_admin_km_history'],

            $token

        );

        $_SESSION['csrf_admin_km_history'] =

            array_slice(

                array_values(

                    array_unique($_SESSION['csrf_admin_km_history'])

                ),

                0,

                10

            );

        return true;

    }

    return false;

}

/*

    Chỉ các loại mang tính CHIẾN DỊCH mới nên gắn với

    ngày lễ / sự kiện. Các loại vận hành theo điều kiện

    như xả kho, mua nhiều, tri ân khách hàng... không nên

    mang nhãn Valentine, 8/3, 11/11... nếu admin không chủ ý.

*/

function kmLoaiChoPhepSuKien(string $loai): bool

{

    return in_array(

        $loai,

        [

            'giam_truc_tiep',

            'flash_sale',

            'ra_mat',

            'uu_dai_online',

            'thanh_toan',

            'khac',

        ],

        true

    );

}

/*

    Loại khuyến mãi mô tả CÁCH chương trình hoạt động.

    Dịp/ngày lễ được tách riêng ở kmSuKienNhom().

*/

function kmLoai(): array

{

    return [

        'giam_truc_tiep' =>

            'Giảm trực tiếp',

        'flash_sale' =>

            'Flash sale / khung giờ vàng',

        'xa_kho' =>

            'Xả kho',

        'mua_nhieu' =>

            'Mua nhiều giảm nhiều',

        'hoc_sinh_sinh_vien' =>

            'Ưu đãi học sinh / sinh viên',

        'nguoi_co_cong' =>

            'Ưu đãi người có công',

        'tri_an_khach_hang' =>

            'Tri ân khách hàng thân thiết',

        'ra_mat' =>

            'Ưu đãi ra mắt sản phẩm',

        'uu_dai_online' =>

            'Ưu đãi mua online',

        'thanh_toan' =>

            'Ưu đãi thanh toán',

        'thu_cu_doi_moi' =>

            'Thu cũ đổi mới',

        'khac' =>

            'Khác',

    ];

}

function kmDoiTuong(): array

{

    return [

        'tat_ca' =>

            'Tất cả khách hàng',

        'hoc_sinh_sinh_vien' =>

            'Học sinh / sinh viên',

        'nguoi_co_cong' =>

            'Người có công',

        'khach_than_thiet' =>

            'Khách hàng thân thiết',

    ];

}

/*

    Danh sách sự kiện được chia nhóm để người bàn giao

    nhìn vào là hiểu ngay.

    - Ngày đôi: đủ 01/01 -> 12/12.

    - Ngày lễ / sự kiện lịch sử Việt Nam: các ngày lớn,

      ngày kỷ niệm phổ biến và phù hợp để lập chiến dịch.

    - Sự kiện thương mại / xã hội: dùng khi cần.

    - Sự kiện âm lịch: ngày dương lịch thay đổi theo năm,

      nên hệ thống không tự đặt ngày bắt đầu/kết thúc.

*/

function kmSuKienNhom(): array

{

    return [

        'Không gắn sự kiện' => [

            '' =>

                'Không gắn sự kiện',

        ],

        'Ngày đôi' => [

            'double_01_01' => '01/01',

            'double_02_02' => '02/02',

            'double_03_03' => '03/03',

            'double_04_04' => '04/04',

            'double_05_05' => '05/05',

            'double_06_06' => '06/06',

            'double_07_07' => '07/07',

            'double_08_08' => '08/08',

            'double_09_09' => '09/09',

            'double_10_10' => '10/10',

            'double_11_11' => '11/11',

            'double_12_12' => '12/12',

        ],

        'Ngày lễ / sự kiện lịch sử Việt Nam' => [

            'tet_duong_lich' =>

                'Tết Dương lịch - 01/01',

            'thanh_lap_dang' =>

                'Ngày thành lập Đảng Cộng sản Việt Nam - 03/02',

            'gio_to_hung_vuong' =>

                'Giỗ Tổ Hùng Vương - 10/03 âm lịch',

            'giai_phong_mien_nam' =>

                'Ngày Giải phóng miền Nam, thống nhất đất nước - 30/04',

            'quoc_te_lao_dong' =>

                'Ngày Quốc tế Lao động - 01/05',

            'dien_bien_phu' =>

                'Chiến thắng Điện Biên Phủ - 07/05',

            'sinh_nhat_bac_ho' =>

                'Ngày sinh Chủ tịch Hồ Chí Minh - 19/05',

            'thuong_binh_liet_si' =>

                'Ngày Thương binh - Liệt sĩ - 27/07',

            'cach_mang_thang_tam' =>

                'Cách mạng Tháng Tám - 19/08',

            'quoc_khanh' =>

                'Quốc khánh Việt Nam - 02/09',

            'quan_doi_nhan_dan' =>

                'Ngày thành lập Quân đội nhân dân Việt Nam - 22/12',

            'tet_nguyen_dan' =>

                'Tết Nguyên đán - ngày âm lịch thay đổi theo năm',

        ],

        'Ngày kỷ niệm / sự kiện phổ biến' => [

            'valentine' =>

                'Valentine - 14/02',

            'quoc_te_phu_nu' =>

                'Quốc tế Phụ nữ - 08/03',

            'thanh_lap_doan' =>

                'Ngày thành lập Đoàn TNCS Hồ Chí Minh - 26/03',

            'quoc_te_thieu_nhi' =>

                'Quốc tế Thiếu nhi - 01/06',

            'phu_nu_viet_nam' =>

                'Phụ nữ Việt Nam - 20/10',

            'nha_giao_viet_nam' =>

                'Nhà giáo Việt Nam - 20/11',

            'giang_sinh' =>

                'Giáng sinh - 24/12',

            'trung_thu' =>

                'Tết Trung thu - 15/08 âm lịch',

        ],

        'Khác' => [

            'khac' =>

                'Sự kiện khác / tự chọn thời gian',

        ],

    ];

}

/*

    Bản phẳng để validate và hiển thị ở các nơi cũ.

*/

function kmSuKien(): array

{

    $flat = [];

    foreach (

        kmSuKienNhom()

        as $items

    ) {

        foreach ($items as $key => $label) {

            $flat[$key] = $label;

        }

    }

    return $flat;

}

/*

    Ngày cố định theo dương lịch.

    Các dịp âm lịch KHÔNG đặt ở đây vì thay đổi theo năm.

*/

function kmNgaySuKienCoDinh(): array

{

    return [

        'double_01_01' => [1, 1],

        'double_02_02' => [2, 2],

        'double_03_03' => [3, 3],

        'double_04_04' => [4, 4],

        'double_05_05' => [5, 5],

        'double_06_06' => [6, 6],

        'double_07_07' => [7, 7],

        'double_08_08' => [8, 8],

        'double_09_09' => [9, 9],

        'double_10_10' => [10, 10],

        'double_11_11' => [11, 11],

        'double_12_12' => [12, 12],

        'tet_duong_lich' => [1, 1],

        'thanh_lap_dang' => [2, 3],

        'giai_phong_mien_nam' => [4, 30],

        'quoc_te_lao_dong' => [5, 1],

        'dien_bien_phu' => [5, 7],

        'sinh_nhat_bac_ho' => [5, 19],

        'thuong_binh_liet_si' => [7, 27],

        'cach_mang_thang_tam' => [8, 19],

        'quoc_khanh' => [9, 2],

        'quan_doi_nhan_dan' => [12, 22],

        'valentine' => [2, 14],

        'quoc_te_phu_nu' => [3, 8],

        'thanh_lap_doan' => [3, 26],

        'quoc_te_thieu_nhi' => [6, 1],

        'phu_nu_viet_nam' => [10, 20],

        'nha_giao_viet_nam' => [11, 20],

        'giang_sinh' => [12, 24],

    ];

}

/*

    Hai cột ten_khuyen_mai và ly_do vẫn được giữ trong DB

    để tương thích code cũ.

    Admin KHÔNG phải nhập nữa:

    - tên được sinh từ Loại + Sự kiện;

    - lý do để chuỗi rỗng.

*/

function kmTenTuLoaiSuKien(

    string $loai,

    string $maSuKien

): string {

    $types = kmLoai();

    $events = kmSuKien();

    $typeLabel =

        $types[$loai]

        ?? 'Khuyến mãi';

    $eventLabel =

        trim(

            (string)(

                $events[$maSuKien]

                ?? ''

            )

        );

    if (

        $maSuKien !== '' &&

        $eventLabel !== ''

    ) {

        return

            $typeLabel .

            ' · ' .

            $eventLabel;

    }

    return $typeLabel;

}

function kmColumnExists(

    mysqli $conn,

    string $column

): bool {

    $stmt =

        $conn->prepare("

            SELECT 1

            FROM INFORMATION_SCHEMA.COLUMNS

            WHERE TABLE_SCHEMA = DATABASE()

              AND TABLE_NAME = 'khuyen_mai'

              AND COLUMN_NAME = ?

            LIMIT 1

        ");

    if (!$stmt) {

        return false;

    }

    $stmt->bind_param(

        's',

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

function kmSchemaReady(mysqli $conn): bool

{

    foreach (

        [

            'so_luong_mua_toi_thieu',

            'ton_kho_toi_da', // Giữ tên cột cũ; với Xả kho hiểu là ngưỡng tồn tối thiểu.

            'doi_tuong_khach',

            'don_hang_toi_thieu',

            'chi_tieu_toi_thieu',

            'gio_bat_dau',

            'gio_ket_thuc',

            'gioi_han_giam',

            'uu_tien',

            'ma_su_kien',

            'ghi_chu_dieu_kien',

        ]

        as $column

    ) {

        if (!kmColumnExists($conn, $column)) {

            return false;

        }

    }

    return true;

}

function kmEffectiveState(

    array $km,

    ?int $nowTs = null

): array {

    $nowTs = $nowTs ?? time();

    if ((int)($km['trang_thai'] ?? 0) !== 1) {

        return [

            'code' => 'off',

            'label' => 'Đang tắt',

            'class' => 'off',

            'active' => false,

            'note' => 'Admin đang tắt chương trình.',

        ];

    }

    $startTs = strtotime((string)($km['ngay_bat_dau'] ?? ''));

    $endTs = strtotime((string)($km['ngay_ket_thuc'] ?? ''));

    if (!$startTs || !$endTs) {

        return [

            'code' => 'invalid',

            'label' => 'Sai thời gian',

            'class' => 'danger',

            'active' => false,

            'note' => 'Cần kiểm tra lại thời gian bắt đầu / kết thúc.',

        ];

    }

    if ($nowTs < $startTs) {

        return [

            'code' => 'scheduled',

            'label' => 'Sắp diễn ra',

            'class' => 'scheduled',

            'active' => false,

            'note' => 'Đã bật nhưng chưa tới ngày áp dụng.',

        ];

    }

    if ($nowTs > $endTs) {

        return [

            'code' => 'expired',

            'label' => 'Đã kết thúc',

            'class' => 'expired',

            'active' => false,

            'note' => 'Đã qua thời gian áp dụng.',

        ];

    }

    $startTime = trim((string)($km['gio_bat_dau'] ?? ''));

    $endTime = trim((string)($km['gio_ket_thuc'] ?? ''));

    if ($startTime !== '' || $endTime !== '') {

        if ($startTime === '' || $endTime === '') {

            return [

                'code' => 'invalid',

                'label' => 'Thiếu khung giờ',

                'class' => 'danger',

                'active' => false,

                'note' => 'Cần đủ giờ bắt đầu và giờ kết thúc.',

            ];

        }

        $startTime = substr($startTime, 0, 5);

        $endTime = substr($endTime, 0, 5);

        $nowTime = date('H:i', $nowTs);

        $insideWindow =

            $startTime <= $endTime

                ? (

                    $nowTime >= $startTime &&

                    $nowTime <= $endTime

                )

                : (

                    $nowTime >= $startTime ||

                    $nowTime <= $endTime

                );

        if (!$insideWindow) {

            return [

                'code' => 'waiting_time',

                'label' => 'Chờ khung giờ',

                'class' => 'waiting',

                'active' => false,

                'note' =>

                    'Tự chạy mỗi ngày từ ' .

                    $startTime .

                    ' đến ' .

                    $endTime .

                    '.',

            ];

        }

    }

    return [

        'code' => 'running',

        'label' => 'Đang áp dụng',

        'class' => 'on',

        'active' => true,

        'note' => 'Khuyến mãi đang có hiệu lực.',

    ];

}

function kmValidate(

    mysqli $conn,

    array $in

): array {

    $scope =

        trim(

            (string)(

                $in['pham_vi']

                ?? 'toan_san_pham'

            )

        );

    if (

        !in_array(

            $scope,

            [

                'toan_san_pham',

                'bien_the',

            ],

            true

        )

    ) {

        $scope = 'toan_san_pham';

    }

    $variantRaw =

        trim(

            (string)(

                $in['bien_the_id']

                ?? ''

            )

        );

    $variantId =

        $scope === 'bien_the'

            ? (

                $variantRaw === ''

                    ? 0

                    : (int)$variantRaw

            )

            : 0;

    $d = [

        'pham_vi' => $scope,

        'san_pham_id' =>

            (int)($in['san_pham_id'] ?? 0),

        'bien_the_id' =>

            $variantId,

        'loai_khuyen_mai' =>

            trim(

                (string)(

                    $in['loai_khuyen_mai']

                    ?? ''

                )

            ),

        'ma_su_kien' =>

            trim(

                (string)(

                    $in['ma_su_kien']

                    ?? ''

                )

            ),

        'kieu_giam' =>

            trim(

                (string)(

                    $in['kieu_giam']

                    ?? ''

                )

            ),

        'gia_tri' =>

            (float)($in['gia_tri'] ?? 0),

        'ngay_bat_dau' =>

            trim(

                (string)(

                    $in['ngay_bat_dau']

                    ?? ''

                )

            ),

        'ngay_ket_thuc' =>

            trim(

                (string)(

                    $in['ngay_ket_thuc']

                    ?? ''

                )

            ),

        'trang_thai' =>

            isset($in['trang_thai'])

                ? 1

                : 0,

        'so_luong_mua_toi_thieu' =>

            max(

                1,

                (int)(

                    $in['so_luong_mua_toi_thieu']

                    ?? 1

                )

            ),

        'ton_kho_toi_da' =>

            trim(

                (string)(

                    $in['ton_kho_toi_da']

                    ?? ''

                )

            ),

        'doi_tuong_khach' =>

            trim(

                (string)(

                    $in['doi_tuong_khach']

                    ?? 'tat_ca'

                )

            ),

        'don_hang_toi_thieu' =>

            max(

                0,

                (int)(

                    $in['don_hang_toi_thieu']

                    ?? 0

                )

            ),

        'chi_tieu_toi_thieu' =>

            max(

                0,

                (float)(

                    $in['chi_tieu_toi_thieu']

                    ?? 0

                )

            ),

        'gio_bat_dau' =>

            trim(

                (string)(

                    $in['gio_bat_dau']

                    ?? ''

                )

            ),

        'gio_ket_thuc' =>

            trim(

                (string)(

                    $in['gio_ket_thuc']

                    ?? ''

                )

            ),

        'gioi_han_giam' =>

            trim(

                (string)(

                    $in['gioi_han_giam']

                    ?? ''

                )

            ),

        'uu_tien' =>

            max(

                0,

                (int)(

                    $in['uu_tien']

                    ?? 0

                )

            ),

        'ghi_chu_dieu_kien' =>

            trim(

                (string)(

                    $in['ghi_chu_dieu_kien']

                    ?? ''

                )

            ),

    ];

    /*

        Tự đặt đối tượng theo loại.

    */

    if (

        $d['loai_khuyen_mai']

        === 'hoc_sinh_sinh_vien'

    ) {

        $d['doi_tuong_khach'] =

            'hoc_sinh_sinh_vien';

    } elseif (

        $d['loai_khuyen_mai']

        === 'nguoi_co_cong'

    ) {

        $d['doi_tuong_khach'] =

            'nguoi_co_cong';

    } elseif (

        $d['loai_khuyen_mai']

        === 'tri_an_khach_hang'

    ) {

        $d['doi_tuong_khach'] =

            'khach_than_thiet';

    }

    /*

        Không cho các chương trình theo ĐIỀU KIỆN nghiệp vụ

        vô tình giữ lại sự kiện từ lần chọn trước.

        Ví dụ: đổi từ "Giảm trực tiếp - Valentine" sang

        "Xả kho" thì ma_su_kien phải được xóa ngay.

    */

    if (!kmLoaiChoPhepSuKien($d['loai_khuyen_mai'])) {

        $d['ma_su_kien'] = '';

    }

    /*

        Tự sinh 2 trường cũ để không làm hỏng code khác.

    */

    $d['ten_khuyen_mai'] =

        kmTenTuLoaiSuKien(

            $d['loai_khuyen_mai'],

            $d['ma_su_kien']

        );

    $d['ly_do'] = '';

    $errors = [];

    if (!kmSchemaReady($conn)) {

        $errors[] =

            'Database chưa có các cột khuyến mãi nâng cao. Hãy chạy migration khuyến mãi nâng cao trước.';

        return [

            $errors,

            $d,

        ];

    }

    if ($d['san_pham_id'] <= 0) {

        $errors[] =

            'Vui lòng chọn sản phẩm.';

    }

    /*

        =========================================================

        CHẶN KHUYẾN MÃI KHI HẾT HÀNG

        =========================================================

        - Toàn sản phẩm: có biến thể thì xét tồn toàn sản phẩm; không có biến thể dùng san_pham.so_luong.

        - Theo biến thể: biến thể được chọn phải có so_luong > 0.

        - Kiểm tra server-side nên không thể lách bằng sửa HTML/request.

    */

    if ($d['san_pham_id'] > 0) {

        $stmtStock = $conn->prepare("

            SELECT
                sp.id,
                sp.ten_san_pham,

                CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM san_pham_bien_the bt_exists
                        WHERE bt_exists.san_pham_id = sp.id
                    )
                    THEN COALESCE(
                        (
                            SELECT SUM(bt.so_luong)
                            FROM san_pham_bien_the bt
                            WHERE bt.san_pham_id = sp.id
                        ),
                        0
                    )
                    ELSE COALESCE(
                        sp.so_luong,
                        0
                    )
                END AS tong_ton_kho

            FROM san_pham sp
            WHERE sp.id = ?
            LIMIT 1

        ");

        if ($stmtStock) {

            $stmtStock->bind_param(

                'i',

                $d['san_pham_id']

            );

            $stmtStock->execute();

            $productStockRow =

                $stmtStock

                    ->get_result()

                    ->fetch_assoc();

            $stmtStock->close();

            if (!$productStockRow) {

                $errors[] =

                    'Sản phẩm đã chọn không tồn tại.';

            } elseif ((int)$productStockRow['tong_ton_kho'] <= 0) {

                $errors[] =

                    'Sản phẩm "' .

                    (string)$productStockRow['ten_san_pham'] .

                    '" đã hết hàng nên không thể tạo hoặc kích hoạt khuyến mãi.';

            }

        }

    }

    if (

        $d['pham_vi'] === 'bien_the' &&

        $d['bien_the_id'] > 0 &&

        $d['san_pham_id'] > 0

    ) {

        $stmtVariantStock = $conn->prepare("

            SELECT

                bt.id,

                bt.so_luong,

                bt.ram,

                bt.rom

            FROM san_pham_bien_the bt

            WHERE bt.id = ?

              AND bt.san_pham_id = ?

            LIMIT 1

        ");

        if ($stmtVariantStock) {

            $stmtVariantStock->bind_param(

                'ii',

                $d['bien_the_id'],

                $d['san_pham_id']

            );

            $stmtVariantStock->execute();

            $variantStockRow =

                $stmtVariantStock

                    ->get_result()

                    ->fetch_assoc();

            $stmtVariantStock->close();

            if (

                $variantStockRow &&

                (int)$variantStockRow['so_luong'] <= 0

            ) {

                $variantLabel = trim(

                    (string)($variantStockRow['ram'] ?? '') .

                    ' ' .

                    (string)($variantStockRow['rom'] ?? '')

                );

                $errors[] =

                    'Biến thể' .

                    ($variantLabel !== ''

                        ? ' ' . $variantLabel

                        : '') .

                    ' đã hết hàng nên không thể áp dụng khuyến mãi.';

            }

        }

    }

    if (

        !array_key_exists(

            $d['loai_khuyen_mai'],

            kmLoai()

        )

    ) {

        $errors[] =

            'Loại khuyến mãi không hợp lệ.';

    }

    if (

        !array_key_exists(

            $d['ma_su_kien'],

            kmSuKien()

        )

    ) {

        $errors[] =

            'Sự kiện không hợp lệ.';

    }

    if (

        !in_array(

            $d['kieu_giam'],

            [

                'so_tien',

                'phan_tram',

            ],

            true

        )

    ) {

        $errors[] =

            'Kiểu giảm không hợp lệ.';

    }

    if ($d['gia_tri'] <= 0) {

        $errors[] =

            'Giá trị giảm phải lớn hơn 0.';

    }

    if (

        $d['kieu_giam'] === 'phan_tram' &&

        $d['gia_tri'] > 100

    ) {

        $errors[] =

            'Phần trăm giảm không được vượt 100%.';

    }

    if (

        !array_key_exists(

            $d['doi_tuong_khach'],

            kmDoiTuong()

        )

    ) {

        $errors[] =

            'Đối tượng khách hàng không hợp lệ.';

    }

    if (

        $d['pham_vi'] === 'bien_the' &&

        $d['bien_the_id'] <= 0

    ) {

        $errors[] =

            'Bạn đã chọn áp dụng cho biến thể nhưng chưa chọn biến thể cụ thể.';

    }

    $start =

        strtotime(

            $d['ngay_bat_dau']

        );

    $end =

        strtotime(

            $d['ngay_ket_thuc']

        );

    if (!$start || !$end) {

        $errors[] =

            'Thời gian khuyến mãi không hợp lệ.';

    } elseif ($d['loai_khuyen_mai'] === 'flash_sale') {

        /*

            Flash sale dùng:

            - khoảng NGÀY áp dụng;

            - một KHUNG GIỜ lặp lại trong từng ngày.

            Vì vậy ngày đầu luôn bắt đầu 00:00:00

            và ngày cuối kết thúc 23:59:59.

        */

        $startDay = date('Y-m-d', $start);

        $endDay = date('Y-m-d', $end);

        if ($endDay < $startDay) {

            $errors[] =

                'Ngày kết thúc Flash sale không được trước ngày bắt đầu.';

        } else {

            $d['ngay_bat_dau'] =

                $startDay . ' 00:00:00';

            $d['ngay_ket_thuc'] =

                $endDay . ' 23:59:59';

        }

    } elseif ($end <= $start) {

        $errors[] =

            'Thời gian kết thúc phải sau thời gian bắt đầu.';

    } else {

        $d['ngay_bat_dau'] =

            date(

                'Y-m-d H:i:s',

                $start

            );

        $d['ngay_ket_thuc'] =

            date(

                'Y-m-d H:i:s',

                $end

            );

    }

    if (

        $d['bien_the_id'] > 0 &&

        $d['san_pham_id'] > 0

    ) {

        $stmt =

            $conn->prepare("

                SELECT id

                FROM san_pham_bien_the

                WHERE id = ?

                  AND san_pham_id = ?

                LIMIT 1

            ");

        if ($stmt) {

            $stmt->bind_param(

                'ii',

                $d['bien_the_id'],

                $d['san_pham_id']

            );

            $stmt->execute();

            if (

                !$stmt

                    ->get_result()

                    ->fetch_assoc()

            ) {

                $errors[] =

                    'Biến thể không thuộc sản phẩm đã chọn.';

            }

            $stmt->close();

        }

    }

    $d['ton_kho_toi_da'] =

        $d['ton_kho_toi_da'] === ''

            ? null

            : max(

                0,

                (int)$d['ton_kho_toi_da']

            );

    $d['gioi_han_giam'] =

        $d['gioi_han_giam'] === ''

            ? null

            : max(

                0,

                (float)$d['gioi_han_giam']

            );

    if (

        $d['loai_khuyen_mai'] === 'xa_kho' &&

        (

            $d['ton_kho_toi_da'] === null ||

            $d['ton_kho_toi_da'] <= 0

        )

    ) {

        $errors[] =

            'Khuyến mãi xả kho cần nhập ngưỡng tồn kho tối thiểu để kích hoạt, ví dụ 20.';

    }


    /*
        =========================================================
        XẢ KHO - XÉT NGƯỠNG THEO TỪNG BIẾN THỂ
        =========================================================

        Toàn sản phẩm:
        - nếu có biến thể: phải có ÍT NHẤT 1 biến thể có tồn >= ngưỡng;
        - không dùng SUM tồn các biến thể;
        - nếu không có biến thể: dùng san_pham.so_luong.

        Theo biến thể:
        - chính biến thể được chọn phải có tồn >= ngưỡng.
    */
    if (
        $d['loai_khuyen_mai'] === 'xa_kho' &&
        $d['ton_kho_toi_da'] !== null &&
        $d['ton_kho_toi_da'] > 0 &&
        $d['san_pham_id'] > 0
    ) {
        $nguongXaKho =
            (int)$d['ton_kho_toi_da'];

        if (
            $d['pham_vi'] === 'bien_the' &&
            $d['bien_the_id'] > 0
        ) {
            $stmtXaKho = $conn->prepare("
                SELECT
                    bt.so_luong,
                    bt.ram,
                    bt.rom
                FROM san_pham_bien_the bt
                WHERE bt.id = ?
                  AND bt.san_pham_id = ?
                LIMIT 1
            ");

            if ($stmtXaKho) {
                $stmtXaKho->bind_param(
                    'ii',
                    $d['bien_the_id'],
                    $d['san_pham_id']
                );

                $stmtXaKho->execute();
                $xaKhoRow =
                    $stmtXaKho
                        ->get_result()
                        ->fetch_assoc();
                $stmtXaKho->close();

                if (
                    $xaKhoRow &&
                    (int)$xaKhoRow['so_luong'] < $nguongXaKho
                ) {
                    $variantLabel = trim(
                        (string)($xaKhoRow['ram'] ?? '') .
                        ' ' .
                        (string)($xaKhoRow['rom'] ?? '')
                    );

                    $errors[] =
                        'Không thể áp dụng Xả kho' .
                        ($variantLabel !== ''
                            ? ' cho biến thể ' . $variantLabel
                            : ' cho biến thể đã chọn') .
                        ': tồn kho hiện tại là ' .
                        (int)$xaKhoRow['so_luong'] .
                        ', nhỏ hơn ngưỡng ' .
                        $nguongXaKho .
                        '.';
                }
            }
        } else {
            $stmtXaKho = $conn->prepare("
                SELECT
                    sp.ten_san_pham,

                    EXISTS (
                        SELECT 1
                        FROM san_pham_bien_the bt_exists
                        WHERE bt_exists.san_pham_id = sp.id
                    ) AS co_bien_the,

                    COALESCE((
                        SELECT MAX(bt_max.so_luong)
                        FROM san_pham_bien_the bt_max
                        WHERE bt_max.san_pham_id = sp.id
                    ), 0) AS ton_bien_the_cao_nhat,

                    COALESCE(sp.so_luong, 0) AS ton_san_pham

                FROM san_pham sp
                WHERE sp.id = ?
                LIMIT 1
            ");

            if ($stmtXaKho) {
                $stmtXaKho->bind_param(
                    'i',
                    $d['san_pham_id']
                );

                $stmtXaKho->execute();
                $xaKhoRow =
                    $stmtXaKho
                        ->get_result()
                        ->fetch_assoc();
                $stmtXaKho->close();

                if ($xaKhoRow) {
                    $coBienThe =
                        (int)$xaKhoRow['co_bien_the'] === 1;

                    $tonDeXet =
                        $coBienThe
                            ? (int)$xaKhoRow['ton_bien_the_cao_nhat']
                            : (int)$xaKhoRow['ton_san_pham'];

                    if ($tonDeXet < $nguongXaKho) {
                        $errors[] =
                            $coBienThe
                                ? 'Không thể áp dụng Xả kho toàn sản phẩm "' .
                                    (string)$xaKhoRow['ten_san_pham'] .
                                    '": không có biến thể nào đạt tồn kho từ ' .
                                    $nguongXaKho .
                                    ' trở lên. Biến thể tồn cao nhất hiện chỉ có ' .
                                    $tonDeXet .
                                    '.'
                                : 'Không thể áp dụng Xả kho cho sản phẩm "' .
                                    (string)$xaKhoRow['ten_san_pham'] .
                                    '": tồn kho hiện tại là ' .
                                    $tonDeXet .
                                    ', nhỏ hơn ngưỡng ' .
                                    $nguongXaKho .
                                    '.';
                    }
                }
            }
        }
    }

    if (

        $d['loai_khuyen_mai'] === 'mua_nhieu' &&

        $d['so_luong_mua_toi_thieu'] < 2

    ) {

        $errors[] =

            'Khuyến mãi mua nhiều phải yêu cầu tối thiểu từ 2 sản phẩm.';

    }

    if (

        $d['doi_tuong_khach']

            === 'khach_than_thiet' &&

        $d['don_hang_toi_thieu'] <= 0 &&

        $d['chi_tieu_toi_thieu'] <= 0

    ) {

        $errors[] =

            'Tri ân khách hàng thân thiết cần ít nhất một điều kiện: số đơn hoàn thành hoặc tổng chi tiêu.';

    }

    if (

        ($d['gio_bat_dau'] === '')

        xor

        ($d['gio_ket_thuc'] === '')

    ) {

        $errors[] =

            'Nếu dùng khung giờ, cần nhập cả giờ bắt đầu và giờ kết thúc.';

    }

    foreach (

        [

            'gio_bat_dau',

            'gio_ket_thuc',

        ]

        as $key

    ) {

        if (

            $d[$key] !== '' &&

            !preg_match(

                '/^([01]****\d****|2[0-3]):[0-5]****\d****$/',

                $d[$key]

            )

        ) {

            $errors[] =

                'Khung giờ không hợp lệ.';

            break;

        }

    }

    if ($d['loai_khuyen_mai'] === 'flash_sale') {

        if (

            $d['gio_bat_dau'] === '' ||

            $d['gio_ket_thuc'] === ''

        ) {

            $errors[] =

                'Flash sale cần chọn đầy đủ giờ bắt đầu và giờ kết thúc mỗi ngày.';

        } elseif (

            preg_match(

                '/^([01]****\d****|2[0-3]):[0-5]****\d****$/',

                $d['gio_bat_dau']

            ) &&

            preg_match(

                '/^([01]****\d****|2[0-3]):[0-5]****\d****$/',

                $d['gio_ket_thuc']

            ) &&

            $d['gio_ket_thuc'] <= $d['gio_bat_dau']

        ) {

            $errors[] =

                'Flash sale trong ngày cần giờ kết thúc sau giờ bắt đầu, ví dụ 09:00 → 12:00.';

        }

    } else {

        /*

            Khung giờ chỉ dành cho Flash sale.

            Xóa dữ liệu giờ cũ nếu admin đổi sang loại khác.

        */

        $d['gio_bat_dau'] = '';

        $d['gio_ket_thuc'] = '';

    }

    if (

        mb_strlen(

            $d['ghi_chu_dieu_kien'],

            'UTF-8'

        ) > 500

    ) {

        $errors[] =

            'Ghi chú điều kiện tối đa 500 ký tự.';

    }

    return [

        $errors,

        $d,

    ];

}

function kmConditionSummary(array $km): array

{

    $items = [];

    if (

        (int)(

            $km['so_luong_mua_toi_thieu']

            ?? 1

        ) >= 2

    ) {

        $items[] =

            'Mua ≥ ' .

            (int)$km['so_luong_mua_toi_thieu'];

    }

    if (

        $km['ton_kho_toi_da'] !== null &&

        $km['ton_kho_toi_da'] !== ''

    ) {

        $items[] =

            (

                (string)($km['loai_khuyen_mai'] ?? '') === 'xa_kho'

                    ? 'Tồn ≥ '

                    : 'Ngưỡng tồn '

            ) .

            (int)$km['ton_kho_toi_da'];

    }

    $audience =

        (string)(

            $km['doi_tuong_khach']

            ?? 'tat_ca'

        );

    if ($audience !== 'tat_ca') {

        $labels = kmDoiTuong();

        $items[] =

            $labels[$audience]

            ?? $audience;

    }

    if (

        (int)(

            $km['don_hang_toi_thieu']

            ?? 0

        ) > 0

    ) {

        $items[] =

            '≥ ' .

            (int)$km['don_hang_toi_thieu'] .

            ' đơn hoàn thành';

    }

    if (

        (float)(

            $km['chi_tieu_toi_thieu']

            ?? 0

        ) > 0

    ) {

        $items[] =

            'Chi tiêu ≥ ' .

            number_format(

                (float)$km['chi_tieu_toi_thieu'],

                0,

                ',',

                '.'

            ) .

            'đ';

    }

    if (

        !empty($km['gio_bat_dau']) &&

        !empty($km['gio_ket_thuc'])

    ) {

        $items[] =

            substr(

                (string)$km['gio_bat_dau'],

                0,

                5

            ) .

            '–' .

            substr(

                (string)$km['gio_ket_thuc'],

                0,

                5

            );

    }

    return $items;

}
