<?php

/*
    Bản đồ các khu vực quan trọng trong website.
    AI dùng dữ liệu này để chỉ đúng đường dẫn khi người dùng hỏi:
    "Tôi muốn tới giỏ hàng", "đổi mật khẩu ở đâu?", ...
*/

return [
    [
        'ten' => 'Trang chủ / Danh sách sản phẩm',
        'url' => '/iPhoneStore/user/index.php',
        'quyen' => 'public',
        'mo_ta' => 'Xem, tìm kiếm và lọc sản phẩm.',
    ],
    [
        'ten' => 'AI hỗ trợ mua hàng',
        'url' => '/iPhoneStore/user/ai/chat.php',
        'quyen' => 'public',
        'mo_ta' => 'Chat với trợ lý AI của iPhoneStore.',
    ],
    [
        'ten' => 'Chi tiết sản phẩm',
        'url' => '/iPhoneStore/user/san_pham/chi_tiet.php?id={SAN_PHAM_ID}',
        'quyen' => 'public',
        'mo_ta' => 'Xem giá, phiên bản, màu, thông số và đánh giá sản phẩm.',
    ],
    [
        'ten' => 'Đánh giá sản phẩm',
        'url' => '/iPhoneStore/user/san_pham/chi_tiet.php?id={SAN_PHAM_ID}#reviews',
        'quyen' => 'login',
        'mo_ta' => 'Đọc đánh giá thì không cần đăng nhập; gửi đánh giá cần đăng nhập.',
    ],
    [
        'ten' => 'Giỏ hàng',
        'url' => '/iPhoneStore/user/gio_hang/index.php',
        'quyen' => 'login',
        'mo_ta' => 'Xem và chỉnh sửa sản phẩm trong giỏ.',
    ],
    [
        'ten' => 'Thanh toán',
        'url' => '/iPhoneStore/user/thanh_toan/index.php',
        'quyen' => 'login',
        'mo_ta' => 'Thanh toán đơn hàng.',
    ],
    [
        'ten' => 'Đơn hàng của tôi',
        'url' => '/iPhoneStore/user/don_hang/don_hang_da_dat.php',
        'quyen' => 'login',
        'mo_ta' => 'Xem đơn đã đặt, trạng thái và thông tin liên quan.',
    ],
    [
        'ten' => 'Thông báo',
        'url' => '/iPhoneStore/user/thong_bao/index.php',
        'quyen' => 'login',
        'mo_ta' => 'Xem thông báo của tài khoản.',
    ],
    [
        'ten' => 'Thông tin tài khoản',
        'url' => '/iPhoneStore/user/tai_khoan/thong_tin.php',
        'quyen' => 'login',
        'mo_ta' => 'Xem và quản lý thông tin tài khoản.',
    ],
    [
        'ten' => 'Đổi mật khẩu',
        'url' => '/iPhoneStore/user/tai_khoan/doi_mat_khau.php',
        'quyen' => 'login',
        'mo_ta' => 'Đổi mật khẩu tài khoản.',
    ],
    [
        'ten' => 'Đăng nhập',
        'url' => '/iPhoneStore/auth/login.php',
        'quyen' => 'public',
        'mo_ta' => 'Đăng nhập tài khoản.',
    ],
    [
        'ten' => 'Đăng ký',
        'url' => '/iPhoneStore/auth/register.php',
        'quyen' => 'public',
        'mo_ta' => 'Tạo tài khoản mới.',
    ],
    [
        'ten' => 'Đăng xuất',
        'url' => '/iPhoneStore/auth/logout.php',
        'quyen' => 'login',
        'mo_ta' => 'Đăng xuất tài khoản hiện tại.',
    ],
    [
        'ten' => 'Trang quản trị',
        'url' => '/iPhoneStore/admin/index.php',
        'quyen' => 'admin',
        'mo_ta' => 'Khu vực dành riêng cho quản trị viên.',
    ],
];
