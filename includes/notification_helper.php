<?php

/* =========================================================
   IphoneStore - NOTIFICATION HELPER

   Dùng cho:
   - Thông báo trong website.
   - Điều phối Email.
   - Thông báo nhanh bằng session flash.

   Không tự require database để tránh tạo kết nối trùng.

   email_helper.php là module email tùy chọn.
   Nếu chưa có email_helper.php thì notification website vẫn hoạt động
   bình thường, không làm hỏng nghiệp vụ đơn hàng.
========================================================= */

$emailHelperPath =
    __DIR__ . '/email_helper.php';

if (is_file($emailHelperPath)) {
    require_once $emailHelperPath;
}

if (!function_exists('timPhpCliEmail')) {

    /*
        Tìm php.exe cho worker.
        Ưu tiên config/email.php -> php_cli.
        Nếu không khai báo thì tự tìm trong Laragon C: / D:.
    */
    function timPhpCliEmail(): ?string
    {
        $candidates = [];

        $configPath =
            dirname(__DIR__) .
            DIRECTORY_SEPARATOR .
            'config' .
            DIRECTORY_SEPARATOR .
            'email.php';

        if (is_file($configPath)) {
            try {
                $config = require $configPath;

                if (
                    is_array($config) &&
                    !empty($config['php_cli'])
                ) {
                    $candidates[] =
                        trim(
                            (string)$config['php_cli']
                        );
                }
            } catch (Throwable $e) {
                // Bỏ qua, tiếp tục tự tìm.
            }
        }

        /*
            PHP_BINARY chỉ dùng nếu thật sự là php.exe.
            Với Apache module, PHP_BINARY có thể là httpd.exe
            nên tuyệt đối không dùng mù quáng.
        */
        $phpBinary = trim(
            (string)PHP_BINARY
        );

        if (
            $phpBinary !== '' &&
            strtolower(
                basename($phpBinary)
            ) === 'php.exe'
        ) {
            $candidates[] = $phpBinary;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            foreach (
                [
                    'C:\\laragon\\bin\\php\\php-*\\php.exe',
                    'D:\\laragon\\bin\\php\\php-*\\php.exe'
                ]
                as $pattern
            ) {
                $matches = glob($pattern);

                if (is_array($matches)) {
                    rsort(
                        $matches,
                        SORT_NATURAL
                    );

                    foreach ($matches as $match) {
                        $candidates[] = $match;
                    }
                }
            }
        }

        foreach (
            array_values(
                array_unique($candidates)
            )
            as $candidate
        ) {
            if (
                $candidate !== '' &&
                is_file($candidate)
            ) {
                return $candidate;
            }
        }

        return null;
    }
}


if (!function_exists('khoiDongEmailWorkerNen')) {

    /*
        Chạy email_worker.php ở process riêng.
        Request web chỉ mất thời gian khởi động process,
        KHÔNG chờ Gmail SMTP gửi xong.
    */
    function khoiDongEmailWorkerNen(
        string $jobPath
    ): bool {
        if (
            PHP_OS_FAMILY !== 'Windows' ||
            !is_file($jobPath)
        ) {
            return false;
        }

        $phpCli =
            timPhpCliEmail();

        if ($phpCli === null) {
            error_log(
                'IphoneStore email worker: không tìm thấy php.exe CLI.'
            );
            return false;
        }

        $workerPath =
            __DIR__ .
            DIRECTORY_SEPARATOR .
            'email_worker.php';

        if (!is_file($workerPath)) {
            error_log(
                'IphoneStore email worker: thiếu ' .
                $workerPath
            );
            return false;
        }

        /*
            start /B trả quyền điều khiển cho request web ngay.
            STDOUT/STDERR của worker đưa về NUL để không giữ
            kết nối với Apache.
        */
        $command =
            'cmd.exe /D /C start "" /B ' .
            '"' . str_replace('"', '', $phpCli) . '" ' .
            '"' . str_replace('"', '', $workerPath) . '" ' .
            '"' . str_replace('"', '', $jobPath) . '" ' .
            '>NUL 2>&1';

        try {
            $handle = @popen(
                $command,
                'r'
            );

            if (!is_resource($handle)) {
                return false;
            }

            /*
                pclose chỉ chờ cmd.exe kết thúc.
                cmd.exe kết thúc ngay sau khi start worker,
                không chờ worker gửi SMTP.
            */
            @pclose($handle);

            return true;

        } catch (Throwable $e) {
            error_log(
                'IphoneStore email worker launch error: ' .
                $e->getMessage()
            );

            return false;
        }
    }
}


if (!function_exists('xepEmailThongBao')) {

    function xepEmailThongBao(
        string $email,
        string $hoTen,
        string $tieuDe,
        string $noiDung,
        ?string $lienKet = null
    ): bool {
        $email = trim($email);
        $hoTen = trim($hoTen);
        $tieuDe = trim($tieuDe);
        $noiDung = trim($noiDung);

        if (
            $email === '' ||
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            ) ||
            $tieuDe === '' ||
            $noiDung === ''
        ) {
            return false;
        }

        $queueDir =
            dirname(__DIR__) .
            DIRECTORY_SEPARATOR .
            'storage' .
            DIRECTORY_SEPARATOR .
            'email_queue';

        if (
            !is_dir($queueDir) &&
            !@mkdir(
                $queueDir,
                0775,
                true
            ) &&
            !is_dir($queueDir)
        ) {
            error_log(
                'IphoneStore email queue: không tạo được ' .
                $queueDir
            );
            return false;
        }

        try {
            $suffix =
                bin2hex(
                    random_bytes(8)
                );
        } catch (Throwable $e) {
            $suffix =
                str_replace(
                    '.',
                    '',
                    uniqid('', true)
                );
        }

        $jobPath =
            $queueDir .
            DIRECTORY_SEPARATOR .
            'mail_' .
            date('Ymd_His') .
            '_' .
            $suffix .
            '.json';

        $payload = [
            'email' => $email,
            'ho_ten' => $hoTen,
            'tieu_de' => $tieuDe,
            'noi_dung' => $noiDung,
            'lien_ket' => $lienKet,
            'attempt' => 0,
            'ngay_tao' => date('c')
        ];

        $json =
            json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

        if (
            $json === false ||
            @file_put_contents(
                $jobPath,
                $json,
                LOCK_EX
            ) === false
        ) {
            return false;
        }

        if (
            !khoiDongEmailWorkerNen(
                $jobPath
            )
        ) {
            /*
                Giữ job lại để không mất email.
                Có thể xem file trong storage/email_queue.
            */
            error_log(
                'IphoneStore email queue: đã tạo job nhưng không khởi động được worker: ' .
                $jobPath
            );

            return false;
        }

        return true;
    }
}


if (!function_exists('xepEmailChoNguoiDung')) {

    /*
        Lấy email trực tiếp từ nguoi_dung rồi đưa vào queue.
        Dùng cho taoThongBao() để mọi thông báo website
        tự có email cùng tiêu đề / nội dung / liên kết.
    */
    function xepEmailChoNguoiDung(
        mysqli $conn,
        int $nguoiDungId,
        string $tieuDe,
        string $noiDung,
        ?string $lienKet = null
    ): bool {
        if ($nguoiDungId <= 0) {
            return false;
        }

        $stmt =
            $conn->prepare("
                SELECT
                    ho_ten,
                    email
                FROM nguoi_dung
                WHERE id = ?
                LIMIT 1
            ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            'i',
            $nguoiDungId
        );

        $stmt->execute();

        $user =
            $stmt
                ->get_result()
                ->fetch_assoc();

        if (!$user) {
            return false;
        }

        return xepEmailThongBao(
            trim(
                (string)(
                    $user['email']
                    ?? ''
                )
            ),
            trim(
                (string)(
                    $user['ho_ten']
                    ?? ''
                )
            ),
            $tieuDe,
            $noiDung,
            $lienKet
        );
    }
}


if (!function_exists('taoThongBao')) {

    function taoThongBao(
        mysqli $conn,
        int $nguoiDungId,
        string $tieuDe,
        string $noiDung,
        string $loai = 'he_thong',
        ?string $lienKet = null,
        ?int $donHangId = null
    ): bool {
        $nguoiDungId = max(0, $nguoiDungId);
        $tieuDe = trim($tieuDe);
        $noiDung = trim($noiDung);
        $loai = trim($loai);
        $lienKet = $lienKet !== null
            ? trim($lienKet)
            : null;

        if (
            $nguoiDungId <= 0 ||
            $tieuDe === '' ||
            $noiDung === ''
        ) {
            return false;
        }

        if ($loai === '') {
            $loai = 'he_thong';
        }

        if ($lienKet === '') {
            $lienKet = null;
        }

        if (
            $donHangId !== null &&
            $donHangId <= 0
        ) {
            $donHangId = null;
        }

        $stmt = $conn->prepare("
            INSERT INTO thong_bao (
                nguoi_dung_id,
                don_hang_id,
                tieu_de,
                noi_dung,
                loai,
                lien_ket,
                da_doc,
                ngay_tao
            )
            VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            "iissss",
            $nguoiDungId,
            $donHangId,
            $tieuDe,
            $noiDung,
            $loai,
            $lienKet
        );

        $websiteCreated =
            $stmt->execute();

        if ($websiteCreated) {
            try {
                /*
                    Đồng bộ nội dung:
                    cứ tạo được thông báo website thì tự xếp email
                    với CHÍNH tiêu đề / nội dung / liên kết đó.
                */
                xepEmailChoNguoiDung(
                    $conn,
                    $nguoiDungId,
                    $tieuDe,
                    $noiDung,
                    $lienKet
                );
            } catch (Throwable $e) {
                /*
                    Email là tác vụ phụ:
                    lỗi email không được làm hỏng thông báo website.
                */
                error_log(
                    'IphoneStore queue email error: ' .
                    $e->getMessage()
                );
            }
        }

        return $websiteCreated;
    }


    /* =====================================================
       KIỂM TRA CỘT / BẢNG PHỤ TRỢ
    ====================================================== */

    function coCotThongBao(
        mysqli $conn,
        string $tableName,
        string $columnName
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
            "ss",
            $tableName,
            $columnName
        );

        $stmt->execute();

        return (bool)$stmt
            ->get_result()
            ->fetch_assoc();
    }


    function coBangLichSuGuiThongBao(
        mysqli $conn
    ): bool {
        $stmt = $conn->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'lich_su_gui_thong_bao'
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


    /* =====================================================
       GHI LOG EMAIL / SMS

       Log là tác vụ phụ:
       - không throw lỗi ra ngoài;
       - không làm rollback đơn hàng;
       - nếu bảng log chưa tồn tại thì chỉ bỏ qua.
    ====================================================== */

    function ghiLichSuGuiThongBao(
        mysqli $conn,
        ?int $nguoiDungId,
        ?int $donHangId,
        string $kenh,
        string $nguoiNhan,
        ?string $tieuDe,
        string $noiDung,
        string $trangThai,
        ?string $loi = null
    ): bool {
        try {
            if (!coBangLichSuGuiThongBao($conn)) {
                return false;
            }

            $kenh = trim($kenh);
            $nguoiNhan = trim($nguoiNhan);
            $tieuDe = $tieuDe !== null
                ? trim($tieuDe)
                : null;
            $noiDung = trim($noiDung);
            $trangThai = trim($trangThai);
            $loi = $loi !== null
                ? trim($loi)
                : null;

            if (
                $kenh === '' ||
                $nguoiNhan === '' ||
                $noiDung === '' ||
                $trangThai === ''
            ) {
                return false;
            }

            if (
                $nguoiDungId !== null &&
                $nguoiDungId <= 0
            ) {
                $nguoiDungId = null;
            }

            if (
                $donHangId !== null &&
                $donHangId <= 0
            ) {
                $donHangId = null;
            }

            $stmt = $conn->prepare("
                INSERT INTO lich_su_gui_thong_bao (
                    nguoi_dung_id,
                    don_hang_id,
                    kenh,
                    nguoi_nhan,
                    tieu_de,
                    noi_dung,
                    trang_thai,
                    loi,
                    ngay_gui
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            if (!$stmt) {
                return false;
            }

            $stmt->bind_param(
                "iissssss",
                $nguoiDungId,
                $donHangId,
                $kenh,
                $nguoiNhan,
                $tieuDe,
                $noiDung,
                $trangThai,
                $loi
            );

            return $stmt->execute();

        } catch (Throwable $e) {
            error_log(
                "IphoneStore notification log error: " .
                $e->getMessage()
            );

            return false;
        }
    }


    /* =====================================================
       LẤY THÔNG TIN LIÊN LẠC

       Email:
       - lấy từ tài khoản nguoi_dung.

       Tên / số điện thoại / địa chỉ:
       - nếu thông báo thuộc chính đơn của user thì ưu tiên
         snapshot trong don_hang;
       - nếu không có snapshot thì fallback hồ sơ tài khoản.

       Nhờ điều kiện nguoi_dung_id, khi gửi cho admin về đơn của
       khách sẽ KHÔNG lấy nhầm tên / SĐT của người nhận hàng.
    ====================================================== */

    function layThongTinLienLacThongBao(
        mysqli $conn,
        int $nguoiDungId,
        ?int $donHangId = null
    ): ?array {
        if ($nguoiDungId <= 0) {
            return null;
        }

        $stmtUser = $conn->prepare("
            SELECT
                id,
                ho_ten,
                email,
                so_dien_thoai,
                dia_chi
            FROM nguoi_dung
            WHERE id = ?
            LIMIT 1
        ");

        if (!$stmtUser) {
            return null;
        }

        $stmtUser->bind_param(
            "i",
            $nguoiDungId
        );

        $stmtUser->execute();

        $user = $stmtUser
            ->get_result()
            ->fetch_assoc();

        if (!$user) {
            return null;
        }

        $info = [
            'nguoi_dung_id' => $nguoiDungId,
            'ho_ten' => trim(
                (string)($user['ho_ten'] ?? '')
            ),
            'email' => trim(
                (string)($user['email'] ?? '')
            ),
            'so_dien_thoai' => trim(
                (string)($user['so_dien_thoai'] ?? '')
            ),
            'dia_chi' => trim(
                (string)($user['dia_chi'] ?? '')
            )
        ];

        if (
            $donHangId === null ||
            $donHangId <= 0
        ) {
            return $info;
        }

        $receiverColumnsReady =
            coCotThongBao(
                $conn,
                'don_hang',
                'ten_nguoi_nhan'
            ) &&
            coCotThongBao(
                $conn,
                'don_hang',
                'so_dien_thoai_nhan'
            ) &&
            coCotThongBao(
                $conn,
                'don_hang',
                'dia_chi_nhan'
            );

        if (!$receiverColumnsReady) {
            return $info;
        }

        $stmtOrder = $conn->prepare("
            SELECT
                ten_nguoi_nhan,
                so_dien_thoai_nhan,
                dia_chi_nhan
            FROM don_hang
            WHERE id = ?
            AND nguoi_dung_id = ?
            LIMIT 1
        ");

        if (!$stmtOrder) {
            return $info;
        }

        $stmtOrder->bind_param(
            "ii",
            $donHangId,
            $nguoiDungId
        );

        $stmtOrder->execute();

        $orderReceiver = $stmtOrder
            ->get_result()
            ->fetch_assoc();

        if (!$orderReceiver) {
            return $info;
        }

        $tenNguoiNhan = trim(
            (string)(
                $orderReceiver['ten_nguoi_nhan']
                ?? ''
            )
        );

        $soDienThoaiNhan = trim(
            (string)(
                $orderReceiver['so_dien_thoai_nhan']
                ?? ''
            )
        );

        $diaChiNhan = trim(
            (string)(
                $orderReceiver['dia_chi_nhan']
                ?? ''
            )
        );

        if ($tenNguoiNhan !== '') {
            $info['ho_ten'] =
                $tenNguoiNhan;
        }

        if ($soDienThoaiNhan !== '') {
            $info['so_dien_thoai'] =
                $soDienThoaiNhan;
        }

        if ($diaChiNhan !== '') {
            $info['dia_chi'] =
                $diaChiNhan;
        }

        return $info;
    }


    /* =====================================================
       CHUẨN HÓA KẾT QUẢ HELPER NGOÀI

       email_helper.php sau này trả:
       [
           'success' => true/false,
           'error'   => null hoặc chuỗi lỗi
       ]

       Vẫn hỗ trợ bool để dễ tương thích.
    ====================================================== */

    function chuanHoaKetQuaGuiThongBaoNgoai(
        mixed $result
    ): array {
        if (is_bool($result)) {
            return [
                'success' => $result,
                'error' => $result
                    ? null
                    : 'Không gửi được thông báo.'
            ];
        }

        if (is_array($result)) {
            return [
                'success' =>
                    (bool)($result['success'] ?? false),

                'error' =>
                    !empty($result['error'])
                        ? trim(
                            (string)$result['error']
                        )
                        : null
            ];
        }

        return [
            'success' => false,
            'error' => 'Helper trả về kết quả không hợp lệ.'
        ];
    }


    /* =====================================================
       ĐIỀU PHỐI THÔNG BÁO ĐA KÊNH

       Kênh hỗ trợ:
       - website
       - email

       Quan trọng:
       - Website vẫn hoạt động độc lập.
       - Email lỗi KHÔNG làm lỗi đơn hàng.
       - Chỉ ghi log Email vì thong_bao đã là log của website.
       - Nếu helper Email chưa được tạo thì bỏ qua kênh đó,
         không ghi log lỗi rác trong giai đoạn đang phát triển.
    ====================================================== */

    function guiThongBaoNguoiDungDaKenh(
        mysqli $conn,
        int $nguoiDungId,
        string $tieuDe,
        string $noiDung,
        string $loai = 'he_thong',
        ?string $lienKet = null,
        ?int $donHangId = null,
        array $kenh = ['website']
    ): array {
        $kenh = array_values(
            array_unique(
                array_map(
                    static fn($item) =>
                        strtolower(
                            trim(
                                (string)$item
                            )
                        ),
                    $kenh
                )
            )
        );

        $ketQua = [
            'website' => null,
            'email' => null
        ];

        $coWebsite =
            in_array(
                'website',
                $kenh,
                true
            );

        $coEmail =
            in_array(
                'email',
                $kenh,
                true
            );

        /*
            taoThongBao() đã tự queue email.
            Vì vậy ['website','email'] chỉ tạo đúng 1 email,
            không gửi trùng.
        */
        if ($coWebsite) {
            try {
                $ketQua['website'] =
                    taoThongBao(
                        $conn,
                        $nguoiDungId,
                        $tieuDe,
                        $noiDung,
                        $loai,
                        $lienKet,
                        $donHangId
                    );

                if ($coEmail) {
                    $ketQua['email'] =
                        $ketQua['website'];
                }

            } catch (Throwable $e) {
                $ketQua['website'] = false;

                if ($coEmail) {
                    $ketQua['email'] = false;
                }

                error_log(
                    'IphoneStore website notification error: ' .
                    $e->getMessage()
                );
            }

            return $ketQua;
        }

        /*
            Trường hợp hiếm: chỉ yêu cầu email, không tạo website.
        */
        if ($coEmail) {
            try {
                $ketQua['email'] =
                    xepEmailChoNguoiDung(
                        $conn,
                        $nguoiDungId,
                        $tieuDe,
                        $noiDung,
                        $lienKet
                    );
            } catch (Throwable $e) {
                $ketQua['email'] = false;

                error_log(
                    'IphoneStore email queue error: ' .
                    $e->getMessage()
                );
            }
        }

        return $ketQua;
    }


    function demThongBaoChuaDoc(
        mysqli $conn,
        int $nguoiDungId
    ): int {
        if ($nguoiDungId <= 0) {
            return 0;
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS tong
            FROM thong_bao
            WHERE nguoi_dung_id = ?
            AND da_doc = 0
        ");

        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param(
            "i",
            $nguoiDungId
        );

        $stmt->execute();

        return (int)(
            $stmt
                ->get_result()
                ->fetch_assoc()['tong']
            ?? 0
        );
    }


    function layThongBaoMoi(
        mysqli $conn,
        int $nguoiDungId,
        int $limit = 5
    ): array {
        if ($nguoiDungId <= 0) {
            return [];
        }

        $limit = max(
            1,
            min(20, $limit)
        );

        $stmt = $conn->prepare("
            SELECT
                id,
                don_hang_id,
                tieu_de,
                noi_dung,
                loai,
                lien_ket,
                da_doc,
                ngay_tao,
                ngay_doc
            FROM thong_bao
            WHERE nguoi_dung_id = ?
            ORDER BY
                ngay_tao DESC,
                id DESC
            LIMIT ?
        ");

        if (!$stmt) {
            return [];
        }

        $stmt->bind_param(
            "ii",
            $nguoiDungId,
            $limit
        );

        $stmt->execute();

        return $stmt
            ->get_result()
            ->fetch_all(MYSQLI_ASSOC);
    }


    function guiThongBaoChoTatCaAdmin(
        mysqli $conn,
        string $tieuDe,
        string $noiDung,
        string $loai = 'don_hang',
        ?string $lienKet = null,
        ?int $donHangId = null,
        array $kenh = ['website']
    ): int {
        $result = $conn->query("
            SELECT nd.id
            FROM nguoi_dung nd
            JOIN vai_tro vt
                ON vt.id = nd.vai_tro_id
            WHERE vt.vai_tro = 'admin'
            AND nd.trang_thai = 1
        ");

        if (!$result) {
            return 0;
        }

        $count = 0;

        while ($row = $result->fetch_assoc()) {
            $result =
                guiThongBaoNguoiDungDaKenh(
                    $conn,
                    (int)$row['id'],
                    $tieuDe,
                    $noiDung,
                    $loai,
                    $lienKet,
                    $donHangId,
                    $kenh
                );

            if ($result['website'] === true) {
                $count++;
            }
        }

        return $count;
    }


    /* =====================================================
       CÁC THÔNG BÁO NGHIỆP VỤ ĐƠN HÀNG
    ====================================================== */

    function thongBaoDatHangThanhCong(
        mysqli $conn,
        int $nguoiDungId,
        int $donHangId
    ): bool {
        $result =
            guiThongBaoNguoiDungDaKenh(
                $conn,
                $nguoiDungId,
                'Đặt hàng thành công',
                'Đơn hàng của bạn đã được ghi nhận và đang chờ cửa hàng xác nhận.',
                'dat_hang',
                '/iPhoneStore/user/don_hang/chi_tiet.php?id=' .
                    $donHangId,
                $donHangId,
                [
                    'website',
                    'email'
                ]
            );

        return $result['website'] === true;
    }


    function thongBaoDonDuocDuyet(
        mysqli $conn,
        int $nguoiDungId,
        int $donHangId
    ): bool {
        $result =
            guiThongBaoNguoiDungDaKenh(
                $conn,
                $nguoiDungId,
                'Đơn hàng đã được duyệt',
                'Cửa hàng đã xác nhận đơn hàng của bạn và đang chuẩn bị hàng.',
                'da_duyet',
                '/iPhoneStore/user/don_hang/chi_tiet.php?id=' .
                    $donHangId,
                $donHangId,
                [
                    'website',
                    'email'
                ]
            );

        return $result['website'] === true;
    }


    function thongBaoDonDangGiao(
        mysqli $conn,
        int $nguoiDungId,
        int $donHangId,
        ?string $maVanDon
    ): bool {
        $noiDung =
            'Đơn hàng của bạn đã được bàn giao để vận chuyển.';

        $maVanDon = trim(
            (string)$maVanDon
        );

        if ($maVanDon !== '') {
            $noiDung .=
                ' Mã vận đơn: ' .
                $maVanDon .
                '.';
        }

        /*
            Khi có snapshot địa chỉ giao hàng, đưa vào nội dung
            để Email sau này có đủ thông tin giao nhận.
        */
        $contact =
            layThongTinLienLacThongBao(
                $conn,
                $nguoiDungId,
                $donHangId
            );

        $diaChiNhan =
            trim(
                (string)(
                    $contact['dia_chi']
                    ?? ''
                )
            );

        if ($diaChiNhan !== '') {
            $noiDung .=
                ' Địa chỉ giao hàng: ' .
                $diaChiNhan .
                '.';
        }

        $result =
            guiThongBaoNguoiDungDaKenh(
                $conn,
                $nguoiDungId,
                'Đơn hàng đang được giao',
                $noiDung,
                'dang_giao',
                '/iPhoneStore/user/don_hang/chi_tiet.php?id=' .
                    $donHangId,
                $donHangId,
                [
                    'website',
                    'email']
            );

        return $result['website'] === true;
    }


    function thongBaoDonHoanThanh(
        mysqli $conn,
        int $nguoiDungId,
        int $donHangId
    ): bool {
        $result =
            guiThongBaoNguoiDungDaKenh(
                $conn,
                $nguoiDungId,
                'Đơn hàng đã hoàn thành',
                'Đơn hàng đã hoàn thành. Cảm ơn bạn đã mua sắm tại IphoneStore.',
                'hoan_thanh',
                '/iPhoneStore/user/don_hang/chi_tiet.php?id=' .
                    $donHangId,
                $donHangId,
                [
                    'website',
                    'email']
            );

        return $result['website'] === true;
    }


    function thongBaoDonBiHuy(
        mysqli $conn,
        int $nguoiDungId,
        int $donHangId,
        string $nguoiHuy,
        string $lyDo
    ): bool {
        $nguoiHuy = trim($nguoiHuy);
        $lyDo = trim($lyDo);

        if ($nguoiHuy === 'admin') {
            $tieuDe =
                'Đơn hàng đã được cửa hàng hủy';

            $noiDung =
                'IphoneStore thành thật xin lỗi vì phải hủy đơn hàng của bạn. ' .
                'Cảm ơn bạn đã thông cảm và đã tin tưởng lựa chọn cửa hàng';

        } elseif ($nguoiHuy === 'user') {
            $tieuDe =
                'Đơn hàng của bạn đã được hủy';

            $noiDung =
                'Đơn hàng đã được hủy theo yêu cầu của bạn. ' .
                'Cảm ơn bạn đã thông báo cho IphoneStore. ' .
                'Rất tiếc vì lần này chưa thể tiếp tục phục vụ bạn và hy vọng sẽ được đồng hành cùng bạn trong lần mua sắm tiếp theo';

        } else {
            $tieuDe =
                'Đơn hàng đã được hủy';

            $noiDung =
                'Đơn hàng đã được hủy. ' .
                'IphoneStore rất tiếc về sự thay đổi này và cảm ơn bạn đã theo dõi đơn hàng';
        }

        if ($lyDo !== '') {
            $noiDung .=
                ' vì lý do: ' .
                $lyDo .
                '.';
        } else {
            $noiDung .= '.';
        }

        $kenh = [
            'website',
            'email'
        ];
$result =
            guiThongBaoNguoiDungDaKenh(
                $conn,
                $nguoiDungId,
                $tieuDe,
                $noiDung,
                'huy_don',
                '/iPhoneStore/user/don_hang/chi_tiet.php?id=' .
                    $donHangId,
                $donHangId,
                $kenh
            );

        return $result['website'] === true;
    }


    function thongBaoDonMoiChoAdmin(
        mysqli $conn,
        int $donHangId,
        string $tenKhach,
        float $tongTien
    ): int {
        $tenKhach = trim($tenKhach);

        $noiDung =
            ($tenKhach !== ''
                ? $tenKhach
                : 'Một khách hàng'
            ) .
            ' vừa đặt một đơn hàng mới trị giá ' .
            number_format(
                $tongTien,
                0,
                ',',
                '.'
            ) .
            'đ.';

        return guiThongBaoChoTatCaAdmin(
            $conn,
            'Có đơn hàng mới',
            $noiDung,
            'don_moi',
            '/iPhoneStore/admin/don_hang/chi_tiet.php?id=' .
                $donHangId,
            $donHangId,
            [
                'website',
                'email'
            ]
        );
    }


    function thongBaoKhachHuyChoAdmin(
        mysqli $conn,
        int $donHangId,
        string $tenKhach,
        string $lyDo
    ): int {
        $tenKhach = trim($tenKhach);
        $lyDo = trim($lyDo);

            $noiDung =
            ($tenKhach !== ''
                ? $tenKhach
                : 'Khách hàng'
            ) .
            ' đã hủy đơn hàng. ' .
            'Khách hàng xin lỗi cửa hàng vì sự thay đổi này và cảm ơn cửa hàng đã tiếp nhận yêu cầu hủy';

        if ($lyDo !== '') {
            $noiDung .=
                '. Lý do: ' .
                $lyDo;
        }

        $noiDung .= '.';

        return guiThongBaoChoTatCaAdmin(
            $conn,
            'Khách hàng đã hủy đơn',
            $noiDung,
            'khach_huy_don',
            '/iPhoneStore/admin/don_hang/chi_tiet.php?id=' .
                $donHangId,
            $donHangId,
            [
                'website',
                'email'
            ]
        );
    }



    /* =====================================================
       DỌN THÔNG BÁO TỰ ĐỘNG

       Chính sách mặc định:
       - Đã đọc: tự xóa sau 30 ngày.
       - Chưa đọc: tự xóa sau 90 ngày.

       Không cần cron.
       Hàm này được gọi khi navbar / trang thông báo được mở.
       Vì vậy dữ liệu cũ sẽ được dọn tự động theo lượt truy cập.

       Muốn đổi thời gian sau này chỉ cần đổi 30 và 90
       ở nơi gọi hàm hoặc giữ mặc định tại đây.
    ====================================================== */

    function donDepThongBaoTuDong(
        mysqli $conn,
        int $nguoiDungId,
        int $soNgayDaDoc = 30,
        int $soNgayChuaDoc = 90
    ): int {
        if ($nguoiDungId <= 0) {
            return 0;
        }

        $soNgayDaDoc =
            max(
                1,
                min(3650, $soNgayDaDoc)
            );

        $soNgayChuaDoc =
            max(
                $soNgayDaDoc,
                min(3650, $soNgayChuaDoc)
            );

        /*
            Hai giá trị ngày đã được ép về integer an toàn
            trước khi đưa vào câu SQL.
        */
        $sql = "
            DELETE FROM thong_bao
            WHERE nguoi_dung_id = ?
            AND (
                (
                    da_doc = 1
                    AND ngay_tao <
                        DATE_SUB(
                            NOW(),
                            INTERVAL {$soNgayDaDoc} DAY
                        )
                )
                OR
                (
                    da_doc = 0
                    AND ngay_tao <
                        DATE_SUB(
                            NOW(),
                            INTERVAL {$soNgayChuaDoc} DAY
                        )
                )
            )
        ";

        $stmt =
            $conn->prepare($sql);

        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param(
            "i",
            $nguoiDungId
        );

        if (!$stmt->execute()) {
            return 0;
        }

        return max(
            0,
            (int)$stmt->affected_rows
        );
    }


    function chinhSachLuuThongBao(): array
    {
        return [
            'da_doc_ngay' => 30,
            'chua_doc_ngay' => 90
        ];
    }


    /* =====================================================
       FLASH MESSAGE
       Dùng cho đăng nhập/đăng ký/đổi mật khẩu thành công.
       Không lưu database để tránh làm bảng thông báo bị rác.
    ====================================================== */

    function taoFlashThongBao(
        string $tieuDe,
        string $noiDung,
        string $loai = 'success'
    ): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['flash_notification'] = [
            'tieu_de' => trim($tieuDe),
            'noi_dung' => trim($noiDung),
            'loai' => trim($loai)
        ];
    }


    function layFlashThongBao(): ?array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $flash =
            $_SESSION['flash_notification']
            ?? null;

        unset(
            $_SESSION['flash_notification']
        );

        return is_array($flash)
            ? $flash
            : null;
    }

    /* =====================================================
       CẢNH BÁO TỰ ĐỘNG: KHUYẾN MÃI + TỒN KHO

       Mục tiêu:
       - Giữ nguyên toàn bộ hệ thống thông báo cũ.
       - Không tạo bảng mới.
       - Tận dụng taoThongBao() để:
         + lưu thông báo website;
         + Toast tự lấy qua navbar;
         + email tự được xếp vào queue.
       - Chống gửi lặp bằng chính bảng thong_bao.

       Khuyến mãi:
       - Cảnh báo khi còn <= 24 giờ.
       - Cảnh báo gấp khi còn <= 1 giờ.
       - Gửi cho admin và user đang hoạt động.

       Tồn kho:
       - Mặc định cảnh báo khi còn <= 5.
       - Chỉ gửi cho admin.
       - Nếu sản phẩm có biến thể thì cảnh báo theo biến thể.
       - Nếu không có biến thể thì dùng san_pham.so_luong.
    ====================================================== */

    function thongBaoTuDongDaTonTai(
        mysqli $conn,
        int $nguoiDungId,
        string $loai,
        ?string $lienKet = null,
        ?string $tieuDe = null,
        int $trongVongGio = 0
    ): bool {
        if ($nguoiDungId <= 0) {
            return false;
        }

        $loai = trim($loai);
        $lienKet = $lienKet !== null
            ? trim($lienKet)
            : null;
        $tieuDe = $tieuDe !== null
            ? trim($tieuDe)
            : null;

        $sql = "
            SELECT id
            FROM thong_bao
            WHERE nguoi_dung_id = ?
            AND loai = ?
        ";

        $types = "is";
        $params = [
            $nguoiDungId,
            $loai
        ];

        if ($lienKet !== null && $lienKet !== '') {
            $sql .= " AND lien_ket = ?";
            $types .= "s";
            $params[] = $lienKet;
        }

        if ($tieuDe !== null && $tieuDe !== '') {
            $sql .= " AND tieu_de = ?";
            $types .= "s";
            $params[] = $tieuDe;
        }

        if ($trongVongGio > 0) {
            $trongVongGio =
                max(
                    1,
                    min(24 * 365, $trongVongGio)
                );

            $sql .= "
                AND ngay_tao >= DATE_SUB(
                    NOW(),
                    INTERVAL {$trongVongGio} HOUR
                )
            ";
        }

        $sql .= "
            ORDER BY id DESC
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            $types,
            ...$params
        );

        $stmt->execute();

        return (bool)$stmt
            ->get_result()
            ->fetch_assoc();
    }


    function layDanhSachAdminThongBao(
        mysqli $conn
    ): array {
        $result = $conn->query("
            SELECT nd.id
            FROM nguoi_dung nd
            JOIN vai_tro vt
                ON vt.id = nd.vai_tro_id
            WHERE vt.vai_tro = 'admin'
            AND nd.trang_thai = 1
            ORDER BY nd.id ASC
        ");

        if (!$result) {
            return [];
        }

        return array_map(
            static fn(array $row): int =>
                (int)$row['id'],
            $result->fetch_all(MYSQLI_ASSOC)
        );
    }


    function layDanhSachUserThongBao(
        mysqli $conn
    ): array {
        $result = $conn->query("
            SELECT nd.id
            FROM nguoi_dung nd
            JOIN vai_tro vt
                ON vt.id = nd.vai_tro_id
            WHERE vt.vai_tro = 'user'
            AND nd.trang_thai = 1
            ORDER BY nd.id ASC
        ");

        if (!$result) {
            return [];
        }

        return array_map(
            static fn(array $row): int =>
                (int)$row['id'],
            $result->fetch_all(MYSQLI_ASSOC)
        );
    }


    function taoThongBaoTuDongNeuChuaCo(
        mysqli $conn,
        int $nguoiDungId,
        string $tieuDe,
        string $noiDung,
        string $loai,
        ?string $lienKet = null,
        int $trongVongGio = 0
    ): bool {
        if (
            thongBaoTuDongDaTonTai(
                $conn,
                $nguoiDungId,
                $loai,
                $lienKet,
                $tieuDe,
                $trongVongGio
            )
        ) {
            return false;
        }

        /*
            taoThongBao() hiện tại đã tự:
            - INSERT vào bảng thong_bao;
            - queue email cho người nhận.
            Toast sẽ tự hiển thị từ thông báo chưa đọc.
        */
        return taoThongBao(
            $conn,
            $nguoiDungId,
            $tieuDe,
            $noiDung,
            $loai,
            $lienKet,
            null
        );
    }


    function tinhMocKetThucKhuyenMaiGanNhat(
        array $promo,
        ?DateTimeImmutable $now = null
    ): ?DateTimeImmutable {
        $tz =
            new DateTimeZone(
                'Asia/Ho_Chi_Minh'
            );

        $now ??=
            new DateTimeImmutable(
                'now',
                $tz
            );

        $endRaw =
            trim(
                (string)(
                    $promo['ngay_ket_thuc']
                    ?? ''
                )
            );

        if ($endRaw === '') {
            return null;
        }

        try {
            $campaignEnd =
                new DateTimeImmutable(
                    $endRaw,
                    $tz
                );
        } catch (Throwable $e) {
            return null;
        }

        /*
            Flash Sale / khuyến mãi theo khung giờ:
            nếu hôm nay đang nằm trong khoảng áp dụng,
            mốc cảnh báo gần nhất là giờ kết thúc của hôm nay.
        */
        $timeStart =
            trim(
                (string)(
                    $promo['gio_bat_dau']
                    ?? ''
                )
            );

        $timeEnd =
            trim(
                (string)(
                    $promo['gio_ket_thuc']
                    ?? ''
                )
            );

        if (
            $timeStart !== '' &&
            $timeEnd !== ''
        ) {
            $dateToday =
                $now->format('Y-m-d');

            try {
                $todayStart =
                    new DateTimeImmutable(
                        $dateToday . ' ' . $timeStart,
                        $tz
                    );

                $todayEnd =
                    new DateTimeImmutable(
                        $dateToday . ' ' . $timeEnd,
                        $tz
                    );

                /*
                    Hỗ trợ khung giờ qua đêm:
                    22:00 -> 02:00.
                */
                if ($todayEnd <= $todayStart) {
                    $todayEnd =
                        $todayEnd->modify('+1 day');
                }

                if (
                    $now >= $todayStart &&
                    $now <= $todayEnd &&
                    $todayEnd <= $campaignEnd
                ) {
                    return $todayEnd;
                }
            } catch (Throwable $e) {
                // Fallback về ngày kết thúc chương trình.
            }
        }

        return $campaignEnd;
    }


    function kiemTraKhuyenMaiSapHetHan(
        mysqli $conn,
        int $gioCanhBao = 24,
        int $gioCanhBaoGap = 1
    ): array {
        $gioCanhBao =
            max(
                1,
                min(168, $gioCanhBao)
            );

        $gioCanhBaoGap =
            max(
                1,
                min($gioCanhBao, $gioCanhBaoGap)
            );

        $tz =
            new DateTimeZone(
                'Asia/Ho_Chi_Minh'
            );

        $now =
            new DateTimeImmutable(
                'now',
                $tz
            );

        /*
            Chỉ lấy chương trình vẫn bật và chưa hết ngày tổng.
            Điều kiện chi tiết theo khung giờ được xử lý ở PHP.
        */
        $result = $conn->query("
            SELECT
                km.id,
                km.san_pham_id,
                km.bien_the_id,
                km.ten_khuyen_mai,
                km.loai_khuyen_mai,
                km.kieu_giam,
                km.gia_tri,
                km.ngay_bat_dau,
                km.ngay_ket_thuc,
                km.gio_bat_dau,
                km.gio_ket_thuc,
                sp.ten_san_pham,
                bt.rom,
                bt.ram,
                bt.so_luong AS ton_bien_the,
                ms.ten_mau,

                EXISTS (
                    SELECT 1
                    FROM san_pham_bien_the bt_exists
                    WHERE bt_exists.san_pham_id = sp.id
                ) AS co_bien_the,

                CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM san_pham_bien_the bt_exists2
                        WHERE bt_exists2.san_pham_id = sp.id
                    )
                    THEN COALESCE(
                        (
                            SELECT SUM(bt_stock.so_luong)
                            FROM san_pham_bien_the bt_stock
                            WHERE bt_stock.san_pham_id = sp.id
                        ),
                        0
                    )
                    ELSE COALESCE(sp.so_luong, 0)
                END AS tong_ton_san_pham

            FROM khuyen_mai km

            JOIN san_pham sp
                ON sp.id = km.san_pham_id

            LEFT JOIN san_pham_bien_the bt
                ON bt.id = km.bien_the_id

            LEFT JOIN mau_sac ms
                ON ms.id = bt.mau_id

            WHERE km.trang_thai = 1
            AND km.ngay_ket_thuc >= NOW()

            ORDER BY
                km.ngay_ket_thuc ASC,
                km.id ASC
        ");

        if (!$result) {
            return [
                'admin' => 0,
                'user' => 0,
                'programs' => 0
            ];
        }

        $adminIds =
            layDanhSachAdminThongBao(
                $conn
            );

        $userIds =
            layDanhSachUserThongBao(
                $conn
            );

        $sentAdmin = 0;
        $sentUser = 0;
        $matchedPrograms = 0;

        while ($promo = $result->fetch_assoc()) {
            /*
                =====================================================
                KHÔNG CẢNH BÁO KHUYẾN MÃI KHI HẾT HÀNG
                =====================================================

                - KM theo một biến thể:
                  kiểm tra đúng so_luong của biến thể đó.

                - KM toàn sản phẩm:
                  + có biến thể -> dùng tổng tồn tất cả biến thể;
                  + không có biến thể -> dùng san_pham.so_luong.

                Nếu tồn <= 0 thì chương trình hiện không thể bán,
                vì vậy không gửi cảnh báo "khuyến mãi sắp hết"
                cho admin hoặc khách hàng và cũng không queue email.
            */
            $apDungTheoBienThe =
                !empty($promo['bien_the_id']);

            $tonKhoHienTai =
                $apDungTheoBienThe
                    ? (int)($promo['ton_bien_the'] ?? 0)
                    : (int)($promo['tong_ton_san_pham'] ?? 0);

            if ($tonKhoHienTai <= 0) {
                continue;
            }

            $deadline =
                tinhMocKetThucKhuyenMaiGanNhat(
                    $promo,
                    $now
                );

            if (!$deadline) {
                continue;
            }

            $secondsLeft =
                $deadline->getTimestamp() -
                $now->getTimestamp();

            if ($secondsLeft <= 0) {
                continue;
            }

            $hoursLeft =
                $secondsLeft / 3600;

            if ($hoursLeft > $gioCanhBao) {
                continue;
            }

            $isUrgent =
                $hoursLeft <= $gioCanhBaoGap;

            $warningCode =
                $isUrgent
                    ? 'khuyen_mai_sap_het_1h'
                    : 'khuyen_mai_sap_het_24h';

            $matchedPrograms++;

            $productName =
                trim(
                    (string)(
                        $promo['ten_san_pham']
                        ?? 'Sản phẩm'
                    )
                );

            $promoName =
                trim(
                    (string)(
                        $promo['ten_khuyen_mai']
                        ?? 'Khuyến mãi'
                    )
                );

            $variantText = '';

            if (
                !empty($promo['rom']) ||
                !empty($promo['ram']) ||
                !empty($promo['ten_mau'])
            ) {
                $variantParts =
                    array_values(
                        array_filter(
                            [
                                trim((string)($promo['rom'] ?? '')),
                                trim((string)($promo['ram'] ?? '')),
                                trim((string)($promo['ten_mau'] ?? ''))
                            ],
                            static fn(string $v): bool =>
                                $v !== ''
                        )
                    );

                if (!empty($variantParts)) {
                    $variantText =
                        ' (' .
                        implode(
                            ' - ',
                            $variantParts
                        ) .
                        ')';
                }
            }

            $deadlineText =
                $deadline->format(
                    'H:i d/m/Y'
                );

            $remainingText =
                $isUrgent
                    ? 'còn chưa tới 1 giờ'
                    : 'còn chưa tới 24 giờ';

            $adminTitle =
                $isUrgent
                    ? '⚠️ Khuyến mãi sắp kết thúc trong 1 giờ'
                    : '⏰ Khuyến mãi sắp hết hạn trong 24 giờ';

            $adminBody =
                $promoName .
                ' - ' .
                $productName .
                $variantText .
                ' sẽ kết thúc lúc ' .
                $deadlineText .
                ' (' .
                $remainingText .
                ').';

            $adminLink =
                '/iPhoneStore/admin/khuyen_mai/sua.php?id=' .
                (int)$promo['id'];

            foreach ($adminIds as $adminId) {
                if (
                    taoThongBaoTuDongNeuChuaCo(
                        $conn,
                        $adminId,
                        $adminTitle,
                        $adminBody,
                        $warningCode,
                        $adminLink,
                        0
                    )
                ) {
                    $sentAdmin++;
                }
            }

            $userTitle =
                $isUrgent
                    ? '🔥 Ưu đãi chỉ còn ít thời gian'
                    : '🎁 Ưu đãi sắp kết thúc';

            $userBody =
                $productName .
                $variantText .
                ' đang có chương trình "' .
                $promoName .
                '". Ưu đãi kết thúc lúc ' .
                $deadlineText .
                '.';

            $userLink =
                '/iPhoneStore/user/san_pham/chi_tiet.php?id=' .
                (int)$promo['san_pham_id'];

            if (
                !empty($promo['bien_the_id'])
            ) {
                $userLink .=
                    '&bien_the_id=' .
                    (int)$promo['bien_the_id'] .
                    '&from=khuyen_mai';
            }

            /*
                Mỗi mốc 24h / 1h chỉ gửi một lần cho mỗi user
                vì loai + link + tiêu đề được kiểm tra toàn bộ lịch sử.
            */
            foreach ($userIds as $userId) {
                if (
                    taoThongBaoTuDongNeuChuaCo(
                        $conn,
                        $userId,
                        $userTitle,
                        $userBody,
                        $warningCode,
                        $userLink,
                        0
                    )
                ) {
                    $sentUser++;
                }
            }
        }

        return [
            'admin' => $sentAdmin,
            'user' => $sentUser,
            'programs' => $matchedPrograms
        ];
    }


    function kiemTraSanPhamSapHetHangChoAdmin(
        mysqli $conn,
        int $nguongSapHet = 5,
        int $lapLaiSauGio = 24
    ): array {
        $nguongSapHet =
            max(
                1,
                min(9999, $nguongSapHet)
            );

        $lapLaiSauGio =
            max(
                1,
                min(24 * 30, $lapLaiSauGio)
            );

        $adminIds =
            layDanhSachAdminThongBao(
                $conn
            );

        if (empty($adminIds)) {
            return [
                'sent' => 0,
                'items' => 0
            ];
        }

        $items = [];

        /*
            1. Sản phẩm CÓ biến thể:
               cảnh báo từng biến thể để admin biết chính xác
               màu / dung lượng nào sắp hết.
        */
        $stmtVariant =
            $conn->prepare("
                SELECT
                    sp.id AS san_pham_id,
                    sp.ten_san_pham,
                    bt.id AS bien_the_id,
                    bt.rom,
                    bt.ram,
                    bt.so_luong,
                    ms.ten_mau
                FROM san_pham_bien_the bt
                JOIN san_pham sp
                    ON sp.id = bt.san_pham_id
                LEFT JOIN mau_sac ms
                    ON ms.id = bt.mau_id
                WHERE sp.trang_thai = 1
                AND bt.so_luong <= ?
                ORDER BY bt.so_luong ASC, sp.id ASC, bt.id ASC
            ");

        if ($stmtVariant) {
            $stmtVariant->bind_param(
                'i',
                $nguongSapHet
            );

            $stmtVariant->execute();

            foreach (
                $stmtVariant
                    ->get_result()
                    ->fetch_all(MYSQLI_ASSOC)
                as $row
            ) {
                $row['source'] = 'variant';
                $items[] = $row;
            }
        }

        /*
            2. Sản phẩm KHÔNG có biến thể:
               dùng trực tiếp san_pham.so_luong.
        */
        $stmtProduct =
            $conn->prepare("
                SELECT
                    sp.id AS san_pham_id,
                    sp.ten_san_pham,
                    NULL AS bien_the_id,
                    NULL AS rom,
                    NULL AS ram,
                    sp.so_luong,
                    NULL AS ten_mau
                FROM san_pham sp
                WHERE sp.trang_thai = 1
                AND COALESCE(sp.so_luong, 0) <= ?
                AND NOT EXISTS (
                    SELECT 1
                    FROM san_pham_bien_the bt
                    WHERE bt.san_pham_id = sp.id
                )
                ORDER BY sp.so_luong ASC, sp.id ASC
            ");

        if ($stmtProduct) {
            $stmtProduct->bind_param(
                'i',
                $nguongSapHet
            );

            $stmtProduct->execute();

            foreach (
                $stmtProduct
                    ->get_result()
                    ->fetch_all(MYSQLI_ASSOC)
                as $row
            ) {
                $row['source'] = 'product';
                $items[] = $row;
            }
        }

        $sent = 0;

        foreach ($items as $item) {
            $stock =
                (int)($item['so_luong'] ?? 0);

            $productName =
                trim(
                    (string)(
                        $item['ten_san_pham']
                        ?? 'Sản phẩm'
                    )
                );

            $variantParts =
                array_values(
                    array_filter(
                        [
                            trim((string)($item['rom'] ?? '')),
                            trim((string)($item['ten_mau'] ?? ''))
                        ],
                        static fn(string $v): bool =>
                            $v !== ''
                    )
                );

            $variantText =
                !empty($variantParts)
                    ? ' - ' .
                        implode(
                            ' / ',
                            $variantParts
                        )
                    : '';

            $isOut =
                $stock <= 0;

            $title =
                $isOut
                    ? '🚨 Sản phẩm đã hết hàng'
                    : '⚠️ Sản phẩm sắp hết hàng';

            $body =
                $productName .
                $variantText .
                (
                    $isOut
                        ? ' hiện đã hết hàng.'
                        : ' hiện chỉ còn ' .
                            $stock .
                            ' sản phẩm.'
                ) .
                ' Vui lòng kiểm tra và bổ sung tồn kho.';

            $link =
                '/iPhoneStore/admin/san_pham/sua.php?id=' .
                (int)$item['san_pham_id'];

            /*
                Dùng loại khác nhau giữa hết hàng / sắp hết hàng.
                Cùng một item chỉ nhắc lại sau số giờ cấu hình
                nếu tồn kho vẫn chưa được bổ sung.
            */
            $type =
                $isOut
                    ? 'san_pham_het_hang'
                    : 'san_pham_sap_het';

            foreach ($adminIds as $adminId) {
                if (
                    taoThongBaoTuDongNeuChuaCo(
                        $conn,
                        $adminId,
                        $title,
                        $body,
                        $type,
                        $link,
                        $lapLaiSauGio
                    )
                ) {
                    $sent++;
                }
            }
        }

        return [
            'sent' => $sent,
            'items' => count($items)
        ];
    }


    function kiemTraCanhBaoTuDongIphoneStore(
        mysqli $conn,
        int $nguongSapHet = 5
    ): array {
        /*
            Hàm gom để navbar/admin chỉ cần gọi một lần.

            Chưa gọi tự động ngay trong helper để tránh:
            - mỗi lần require file là tự query DB;
            - khó kiểm soát tần suất.

            Bước tiếp theo sẽ gọi hàm này từ navbar
            với session throttle 5-10 phút/lần.
        */
        return [
            'khuyen_mai' =>
                kiemTraKhuyenMaiSapHetHan(
                    $conn,
                    24,
                    1
                ),

            'ton_kho' =>
                kiemTraSanPhamSapHetHangChoAdmin(
                    $conn,
                    $nguongSapHet,
                    24
                )
        ];
    }

}
