<?php
/*
|--------------------------------------------------------------------------
| IphoneStore - CẤU HÌNH EMAIL SMTP
|--------------------------------------------------------------------------
|
| - username / from_email = Gmail của cửa hàng dùng để gửi email.
| - password = App Password 16 ký tự Google cấp.
| - KHÔNG dùng mật khẩu Gmail thường.
| - KHÔNG đưa file này lên GitHub công khai.
|
*/

return [
    'enabled' => true,

    'host' => 'smtp.gmail.com',
    'port' => 587,
    'encryption' => 'tls',

    'username' => 'thaihieu171006@gmail.com',

    /*
        Dán App Password MỚI của Google vào đây.
        Nếu Google hiển thị có khoảng trắng thì vẫn có thể dán nguyên dạng,
        email_helper.php sẽ tự loại bỏ khoảng trắng khi gửi.
        Không thêm ký tự &, dấu chấm, hoặc ký tự nào khác.
    */
    'password' => 'ytwelklhmawzgwbr',

    'from_email' => 'thaihieu171006@gmail.com',
    'from_name' => 'IphoneStore',

    /*
        Dùng để đổi link tương đối trong hệ thống thành link tuyệt đối trong email.
        Laragon local: http://localhost
        Khi đưa lên hosting thì đổi thành domain thật.
    */
    'base_url' => 'http://localhost',

    /*
        0 = tắt debug SMTP
        2 = bật debug chi tiết khi cần kiểm tra lỗi
        Khi hệ thống chạy ổn nên để 0.
    */
    'debug' => 0
];
