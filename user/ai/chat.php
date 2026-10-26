<?php



if (session_status() === PHP_SESSION_NONE) {

    session_start();

}



if (empty($_SESSION['ai_chat_csrf'])) {

    $_SESSION['ai_chat_csrf'] =

        bin2hex(

            random_bytes(32)

        );

}



$aiChatCsrf =

    (string)$_SESSION['ai_chat_csrf'];



require_once __DIR__ . '/../../config/database.php';

require_once __DIR__ . '/ai_helper.php';



try {

    aiChatEnsureHistoryTable($conn);



    $aiChatHistory =

        aiChatLoadHistory(

            $conn,

            50

        );

} catch (Throwable $e) {

    $aiChatHistory = [];

}



function aiRenderStoredChatText(string $text): string
{
    $html = htmlspecialchars(
        $text,
        ENT_QUOTES,
        'UTF-8'
    );

    /* Markdown link nội bộ do AI trả về. */
    $html = preg_replace_callback(
        '/\[([^\]]+)\]\((\/iPhoneStore\/[^)\s]+)\)/u',
        static function (array $match): string {
            $label = $match[1];
            $url = $match[2];

            return '<a href="' . $url . '" class="ai-chat-link">' . $label . '</a>';
        },
        $html
    );

    /* Highlight chữ quan trọng dạng **...**. */
    $html = preg_replace(
        '/\*\*(.+?)\*\*/us',
        '<span class="ai-highlight">$1</span>',
        $html
    );

    /* Link nội bộ dạng thuần. */
    $html = preg_replace(
        '/(?<!["=])(\/iPhoneStore\/[A-Za-z0-9_\-\/.]+(?:\?[A-Za-z0-9_\-=&amp;%]+)?(?:#[A-Za-z0-9_\-]+)?)/u',
        '<a href="$1" class="ai-chat-link">$1</a>',
        $html
    );

    /* Bullet Markdown. */
    $html = preg_replace(
        '/(^|\n)\s*-\s+/u',
        '$1<span class="ai-bullet">•</span> ',
        $html
    );

    return nl2br($html);
}


function aiStoredProducts(array $message): array
{
    $raw = trim((string)($message['products_json'] ?? ''));

    if ($raw === '') {
        return [];
    }

    $products = json_decode($raw, true);

    if (!is_array($products)) {
        return [];
    }

    $result = [];

    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }

        $name = trim((string)($product['name'] ?? $product['ten_san_pham'] ?? ''));
        $url = trim((string)($product['url'] ?? ''));
        $image = trim((string)($product['image'] ?? $product['hinh_anh'] ?? ''));
        $price = (float)($product['price'] ?? $product['gia'] ?? 0);
        $stock = (int)($product['stock'] ?? $product['so_luong'] ?? 0);

        if ($name === '') {
            continue;
        }

        $result[] = [
            'name' => $name,
            'url' => $url !== '' ? $url : '#',
            'image' => $image,
            'price' => $price,
            'stock' => $stock,
        ];

        if (count($result) >= 3) {
            break;
        }
    }

    return $result;
}

function aiMoney(float $value): string
{
    return number_format($value, 0, ',', '.') . 'đ';
}

require_once __DIR__ . '/../../includes/header.php';

require_once __DIR__ . '/../../includes/navbar.php';

?>



<style>

:root {

    --chat-red: #d70018;

    --chat-red-2: #ef3347;

    --chat-bg: #f6f7f9;

    --chat-border: #e8eaf0;

    --chat-text: #20242c;

    --chat-muted: #8a919d;

}



.ai-page {

    min-height: calc(100vh - 110px);

    padding: 22px 12px 36px;

    background:

        radial-gradient(

            circle at 12% 8%,

            rgba(215, 0, 24, .05),

            transparent 26%

        ),

        #f5f6f8;

}



.ai-shell {

    width: min(1080px, 100%);

    margin: 0 auto;

    overflow: hidden;

    border: 1px solid var(--chat-border);

    border-radius: 24px;

    background: #fff;

    box-shadow: 0 18px 50px rgba(15, 23, 42, .08);

}



.ai-header {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 14px;

    padding: 16px 18px;

    color: #fff;

    background:

        linear-gradient(

            135deg,

            #c90017 0%,

            #df1027 45%,

            #f35a6b 100%

        );

}



.ai-brand {

    display: flex;

    align-items: center;

    gap: 12px;

    min-width: 0;

}



.ai-avatar {

    position: relative;

    width: 46px;

    height: 46px;

    display: grid;

    place-items: center;

    flex: 0 0 46px;

    border: 1px solid rgba(255,255,255,.28);

    border-radius: 15px;

    background: rgba(255,255,255,.14);

    font-size: 23px;

}



.ai-avatar::after {

    content: "";

    position: absolute;

    right: -1px;

    bottom: -1px;

    width: 11px;

    height: 11px;

    border: 2px solid #e52b40;

    border-radius: 50%;

    background: #35d071;

}



.ai-title {

    margin: 0;

    font-size: 18px;

    font-weight: 900;

    line-height: 1.2;

}



.ai-subtitle {

    margin-top: 3px;

    overflow: hidden;

    color: rgba(255,255,255,.82);

    font-size: 11px;

    text-overflow: ellipsis;

    white-space: nowrap;

}



.ai-clear {

    min-height: 34px;

    padding: 0 11px;

    border: 1px solid rgba(255,255,255,.30);

    border-radius: 10px;

    background: rgba(255,255,255,.10);

    color: #fff;

    font-size: 11px;

    cursor: pointer;

    transition: .16s ease;

}



.ai-clear:hover {

    background: rgba(255,255,255,.20);

}



.ai-content {

    display: grid;

    grid-template-columns: 210px minmax(0, 1fr);

    min-height: 630px;

}



.ai-sidebar {

    padding: 18px 14px;

    border-right: 1px solid #edf0f3;

    background:

        linear-gradient(

            180deg,

            #ffffff,

            #fbfbfc

        );

}



.ai-sidebar-title {

    margin: 0 5px 10px;

    color: #9aa0aa;

    font-size: 10px;

    font-weight: 900;

    letter-spacing: .7px;

    text-transform: uppercase;

}



.ai-feature {

    display: flex;

    align-items: flex-start;

    gap: 9px;

    padding: 9px 8px;

    border-radius: 11px;

    color: #4f5663;

    font-size: 10.5px;

    line-height: 1.5;

}



.ai-feature + .ai-feature {

    margin-top: 2px;

}



.ai-feature:hover {

    background: #f7f8fa;

}



.ai-feature-icon {

    width: 27px;

    height: 27px;

    display: grid;

    place-items: center;

    flex: 0 0 27px;

    border-radius: 9px;

    background: #fff1f3;

    font-size: 13px;

}



.ai-chat-main {

    min-width: 0;

    display: flex;

    flex-direction: column;

    background: #fff;

}



.ai-messages {

    height: 500px;

    overflow-y: auto;

    padding: 20px 20px 8px;

    background:

        radial-gradient(

            circle at top left,

            rgba(215,0,24,.018),

            transparent 23%

        ),

        #fbfcfd;

    scroll-behavior: smooth;

}



.ai-row {

    display: flex;

    margin-bottom: 13px;

}



.ai-row.user {

    justify-content: flex-end;

}



.ai-wrap {

    width: fit-content;

    max-width: min(72%, 680px);

}



.ai-row.user .ai-wrap {

    display: flex;

    flex-direction: column;

    align-items: flex-end;

}



.ai-author-line {

    display: flex;

    align-items: center;

    gap: 6px;

    min-height: 22px;

    margin: 0 4px 4px;

}



.ai-row.user .ai-author-line {

    justify-content: flex-end;

}



.ai-author {

    color: #959ca7;

    font-size: 10px;

    font-weight: 800;

    line-height: 1;

}





.ai-message-time {

    margin-top: 4px;

    padding: 0 4px;

    color: #a0a6b0;

    font-size: 9.5px;

    line-height: 1.25;

}



.ai-row.user .ai-message-time {

    text-align: right;

}



.ai-delete-message {

    width: 22px;

    height: 22px;

    display: inline-grid;

    place-items: center;

    padding: 0;

    border: 0;

    border-radius: 7px;

    background: transparent;

    color: #b0b6c0;

    font-size: 11px;

    cursor: pointer;

    opacity: 0;

    transition: .15s ease;

}



.ai-row:hover .ai-delete-message,

.ai-delete-message:focus-visible {

    opacity: 1;

}



.ai-delete-message:hover {

    background: #ffe9ed;

    color: var(--chat-red);

}



.ai-message {

    display: inline-block;

    width: auto;

    max-width: 100%;

    padding: 11px 14px;

    border-radius: 16px;

    font-size: 13px;

    line-height: 1.58;

    overflow-wrap: anywhere;

    word-break: break-word;

}



.ai-message.assistant {

    border: 1px solid #e8ebef;

    border-top-left-radius: 5px;

    background: #fff;

    color: var(--chat-text);

    box-shadow: 0 4px 14px rgba(15,23,42,.035);

}



.ai-message.user {

    border-top-right-radius: 5px;

    background:

        linear-gradient(

            135deg,

            #dc0019,

            #f02840

        );

    color: #fff;

    box-shadow: 0 5px 13px rgba(215,0,24,.12);

}



.ai-message p {

    margin: 0 0 7px;

}



.ai-message p:last-child {

    margin-bottom: 0;

}



.ai-message ul {

    margin: 6px 0 0;

    padding-left: 18px;

}



.ai-message li + li {

    margin-top: 3px;

}



.ai-message a {

    color: #0b6cff;

    font-weight: 750;

    text-decoration: none;

}



.ai-message a:hover {

    text-decoration: underline;

}



.ai-products {

    display: grid;

    grid-template-columns: repeat(3, minmax(0, 1fr));

    gap: 8px;

    margin-top: 8px;

}



.ai-product {

    overflow: hidden;

    border: 1px solid #e8eaf0;

    border-radius: 13px;

    background: #fff;

    color: inherit;

    text-decoration: none;

    transition:

        transform .15s ease,

        box-shadow .15s ease,

        border-color .15s ease;

}



.ai-product:hover {

    transform: translateY(-2px);

    border-color: #f3b9c0;

    color: inherit;

    box-shadow: 0 9px 22px rgba(15,23,42,.07);

}



.ai-product-img {

    height: 94px;

    display: grid;

    place-items: center;

    padding: 8px;

    background: #fafbfc;

}



.ai-product-img img {

    width: 100%;

    height: 100%;

    object-fit: contain;

}



.ai-product-info {

    padding: 8px 9px 9px;

}



.ai-product-name {

    overflow: hidden;

    display: -webkit-box;

    color: #222;

    font-size: 10.5px;

    line-height: 1.42;

    font-weight: 850;

    -webkit-line-clamp: 2;

    -webkit-box-orient: vertical;

}



.ai-product-price {

    margin-top: 4px;

    color: var(--chat-red);

    font-size: 11.5px;

    font-weight: 900;

}



.ai-product-stock {

    margin-top: 3px;

    color: #858b95;

    font-size: 9px;

}



.ai-quick-area {

    padding: 10px 16px 0;

    border-top: 1px solid #f0f1f4;

    background: #fff;

}



.ai-quick-title {

    margin-bottom: 7px;

    color: #999faa;

    font-size: 9.5px;

    font-weight: 800;

}



.ai-quick {

    display: flex;

    gap: 7px;

    overflow-x: auto;

    padding-bottom: 2px;

    scrollbar-width: none;

}



.ai-quick::-webkit-scrollbar {

    display: none;

}



.ai-chip {

    flex: 0 0 auto;

    padding: 7px 10px;

    border: 1px solid #e4e6eb;

    border-radius: 999px;

    background: #fff;

    color: #525b69;

    font-size: 10.5px;

    cursor: pointer;

    transition: .15s ease;

}



.ai-chip:hover {

    border-color: #f0a7b0;

    background: #fff7f8;

    color: var(--chat-red);

}



.ai-compose {

    padding: 11px 16px 16px;

    background: #fff;

}



.ai-compose-box {

    display: flex;

    align-items: flex-end;

    gap: 8px;

    padding: 6px;

    border: 1px solid #dde1e7;

    border-radius: 15px;

    background: #fff;

    transition: .15s ease;

}



.ai-compose-box:focus-within {

    border-color: #eaa0a9;

    box-shadow: 0 0 0 4px rgba(215,0,24,.05);

}



.ai-input {

    min-height: 40px;

    max-height: 120px;

    flex: 1;

    resize: none;

    padding: 9px 10px;

    border: 0;

    outline: 0;

    background: transparent;

    color: #222;

    font-size: 13px;

    line-height: 1.5;

}



.ai-send {

    width: 42px;

    height: 42px;

    display: grid;

    place-items: center;

    flex: 0 0 42px;

    border: 0;

    border-radius: 12px;

    background:

        linear-gradient(

            135deg,

            #d70018,

            #ec3045

        );

    color: #fff;

    font-size: 16px;

    cursor: pointer;

    box-shadow: 0 6px 14px rgba(215,0,24,.16);

}



.ai-send:disabled {

    opacity: .55;

    cursor: wait;

}



.ai-thinking {

    display: inline-flex;

    gap: 5px;

    align-items: center;

}



.ai-thinking span {

    width: 6px;

    height: 6px;

    border-radius: 50%;

    background: #aab0ba;

    animation: aiBlink .8s infinite alternate;

}



.ai-thinking span:nth-child(2) {

    animation-delay: .15s;

}



.ai-thinking span:nth-child(3) {

    animation-delay: .3s;

}



.ai-highlight {
    display: inline;
    padding: 1px 4px;
    border-radius: 5px;
    background: #fff1a8;
    color: #c5221f;
    font-weight: 850;
    box-decoration-break: clone;
    -webkit-box-decoration-break: clone;
}

.ai-chat-link {

    color: #0d6efd !important;

    font-weight: 800;

    text-decoration: none;

}



.ai-chat-link:hover {

    color: #0a58ca !important;

    text-decoration: underline;

}



.ai-bullet {

    color: #d70018;

    font-weight: 900;

}



@keyframes aiBlink {

    from {

        opacity: .3;

        transform: translateY(0);

    }



    to {

        opacity: 1;

        transform: translateY(-3px);

    }

}



@media (max-width: 991px) {

    .ai-content {

        grid-template-columns: 1fr;

    }



    .ai-sidebar {

        display: none;

    }

}



@media (max-width: 767px) {

    .ai-page {

        padding: 8px 0 20px;

    }



    .ai-shell {

        border-radius: 15px;

    }



    .ai-header {

        padding: 12px;

    }



    .ai-avatar {

        width: 41px;

        height: 41px;

        flex-basis: 41px;

        border-radius: 13px;

        font-size: 20px;

    }



    .ai-title {

        font-size: 15px;

    }



    .ai-subtitle {

        display: none;

    }



    .ai-clear {

        min-height: 32px;

        padding: 0 9px;

        font-size: 10px;

    }



    .ai-messages {

        height: 58vh;

        min-height: 390px;

        padding: 13px 12px 7px;

    }



    .ai-wrap {

        max-width: 86%;

    }



    .ai-message {

        padding: 10px 12px;

        font-size: 12.5px;

    }



    .ai-delete-message {

        opacity: 1;

    }



    .ai-products {

        grid-template-columns: 1fr;

    }



    .ai-product {

        display: grid;

        grid-template-columns: 82px minmax(0, 1fr);

    }



    .ai-product-img {

        height: 82px;

    }



    .ai-quick-area {

        padding-left: 11px;

        padding-right: 11px;

    }



    .ai-compose {

        padding: 10px 11px 12px;

    }



}

</style>



<div class="ai-page">

    <div class="container">

        <section class="ai-shell">



            <header class="ai-header">

                <div class="ai-brand">

                    <div class="ai-avatar">

                        🤖

                    </div>



                    <div style="min-width:0;">

                        <h1 class="ai-title">

                            iPhoneStore AI

                        </h1>



                        <div class="ai-subtitle">

                            Tư vấn sản phẩm và hỗ trợ sử dụng website

                        </div>

                    </div>

                </div>



                <button

                    id="aiClear"

                    class="ai-clear"

                    type="button"

                >

                    🗑 Xóa tất cả

                </button>

            </header>



            <div class="ai-content">



                <aside class="ai-sidebar">

                    <div class="ai-sidebar-title">

                        AI có thể hỗ trợ

                    </div>



                    <div class="ai-feature">

                        <div class="ai-feature-icon">

                            📱

                        </div>



                        <div>

                            <b>Tư vấn iPhone</b><br>

                            Giá, ROM, chip, camera,

                            màn hình, tồn kho, màu...

                        </div>

                    </div>



                    <div class="ai-feature">

                        <div class="ai-feature-icon">

                            🧭

                        </div>



                        <div>

                            <b>Điều hướng website</b><br>

                            Hỏi muốn vào đâu, AI sẽ

                            đưa đúng đường dẫn.

                        </div>

                    </div>



                    <div class="ai-feature">

                        <div class="ai-feature-icon">

                            📦

                        </div>



                        <div>

                            <b>Đơn hàng & tài khoản</b><br>

                            Khi đã đăng nhập, AI hỗ trợ

                            dữ liệu của chính bạn.

                        </div>

                    </div>



                    <div class="ai-feature">

                        <div class="ai-feature-icon">

                            🕒

                        </div>



                        <div>

                            <b>Lịch sử & thời gian</b><br>

                            Ngày thêm, sửa, xóa nếu

                            hệ thống có dữ liệu.

                        </div>

                    </div>

                </aside>



                <main class="ai-chat-main">



                    <div

                        id="aiMessages"

                        class="ai-messages"

                        aria-live="polite"

                    >

                        <?php if (empty($aiChatHistory)): ?>



                            <div class="ai-row">

                                <div class="ai-wrap">

                                    <div class="ai-author-line">

                                        <div class="ai-author">

                                            iPhoneStore AI

                                        </div>

                                    </div>



                                    <div class="ai-message assistant">

                                        Chào bạn 👋 Mình là iPhoneStore AI. Bạn có thể hỏi về sản phẩm, đơn hàng hoặc cách sử dụng website nhé 😊

                                    </div>

                                </div>

                            </div>



                        <?php else: ?>



                            <?php foreach ($aiChatHistory as $message): ?>

                                <?php

                                $role =

                                    ($message['vai_tro'] ?? '') === 'assistant'

                                        ? 'assistant'

                                        : 'user';



                                $messageId =

                                    (int)(

                                        $message['id']

                                        ?? 0

                                    );

                                ?>



                                <div

                                    class="ai-row <?= $role === 'user' ? 'user' : '' ?>"

                                    data-message-row

                                    data-message-id="<?= $messageId ?>"

                                >

                                    <div class="ai-wrap">



                                        <div class="ai-author-line">

                                            <div class="ai-author">

                                                <?= $role === 'user'

                                                    ? 'Bạn'

                                                    : 'iPhoneStore AI' ?>

                                            </div>



                                            <?php if ($messageId > 0): ?>

                                                <button

                                                    type="button"

                                                    class="ai-delete-message"

                                                    data-delete-message="<?= $messageId ?>"

                                                    title="Xóa tin nhắn"

                                                    aria-label="Xóa tin nhắn"

                                                >

                                                    🗑

                                                </button>

                                            <?php endif; ?>

                                        </div>



                                        <div class="ai-message <?= $role ?>">

                                            <?= aiRenderStoredChatText(

                                                (string)(

                                                    $message['noi_dung']

                                                    ?? ''

                                                )

                                            ) ?>

                                        </div>



                                        <?php if (!empty($message['ngay_tao'])): ?>

                                            <div class="ai-message-time">

                                                <?= date(

                                                    'H:i · d/m/Y',

                                                    strtotime(

                                                        (string)$message['ngay_tao']

                                                    )

                                                ) ?>

                                            </div>

                                        <?php endif; ?>

                                        <?php
                                        $storedProducts =
                                            $role === 'assistant'
                                                ? aiStoredProducts($message)
                                                : [];
                                        ?>

                                        <?php if (!empty($storedProducts)): ?>

                                            <div class="ai-products">

                                                <?php foreach ($storedProducts as $product): ?>

                                                    <a
                                                        class="ai-product"
                                                        href="<?= htmlspecialchars(
                                                            (string)$product['url'],
                                                            ENT_QUOTES,
                                                            'UTF-8'
                                                        ) ?>"
                                                    >

                                                        <div class="ai-product-img">

                                                            <?php if (!empty($product['image'])): ?>

                                                                <img
                                                                    src="/iPhoneStore/uploads/<?= htmlspecialchars(
                                                                        (string)$product['image'],
                                                                        ENT_QUOTES,
                                                                        'UTF-8'
                                                                    ) ?>"
                                                                    alt="<?= htmlspecialchars(
                                                                        (string)$product['name'],
                                                                        ENT_QUOTES,
                                                                        'UTF-8'
                                                                    ) ?>"
                                                                >

                                                            <?php else: ?>

                                                                📱

                                                            <?php endif; ?>

                                                        </div>

                                                        <div class="ai-product-info">

                                                            <div class="ai-product-name">
                                                                <?= htmlspecialchars(
                                                                    (string)$product['name'],
                                                                    ENT_QUOTES,
                                                                    'UTF-8'
                                                                ) ?>
                                                            </div>

                                                            <div class="ai-product-price">
                                                                Từ <?= aiMoney(
                                                                    (float)$product['price']
                                                                ) ?>
                                                            </div>

                                                            <div class="ai-product-stock">
                                                                <?= (int)$product['stock'] > 0
                                                                    ? '✅ Còn hàng'
                                                                    : '⚠️ Tạm hết hàng' ?>
                                                            </div>

                                                        </div>

                                                    </a>

                                                <?php endforeach; ?>

                                            </div>

                                        <?php endif; ?>



                                    </div>

                                </div>



                            <?php endforeach; ?>



                        <?php endif; ?>

                    </div>



                    <div class="ai-quick-area">

                        <div class="ai-quick-title">

                            Gợi ý nhanh

                        </div>



                        <div class="ai-quick">

                            <button

                                class="ai-chip"

                                type="button"

                                data-question="Tôi muốn đến giỏ hàng."

                            >

                                🧭 Đi tới giỏ hàng

                            </button>



                            <button

                                class="ai-chip"

                                type="button"

                                data-question="Tôi muốn máy 256GB có Dynamic Island, tài chính khoảng 20 triệu."

                            >

                                📱 Tìm máy phù hợp

                            </button>



                            <button

                                class="ai-chip"

                                type="button"

                                data-question="Đơn hàng gần nhất của tôi đang thế nào?"

                            >

                                📦 Đơn gần nhất

                            </button>



                            <button

                                class="ai-chip"

                                type="button"

                                data-question="Tôi muốn đổi mật khẩu thì vào đâu?"

                            >

                                🔐 Đổi mật khẩu

                            </button>

                        </div>

                    </div>



                    <div class="ai-compose">

                        <form

                            id="aiForm"

                            class="ai-compose-box"

                        >

                            <textarea

                                id="aiInput"

                                class="ai-input"

                                rows="1"

                                maxlength="1200"

                                placeholder="Nhập câu hỏi của bạn..."

                                required

                            ></textarea>



                            <button

                                id="aiSend"

                                class="ai-send"

                                type="submit"

                                aria-label="Gửi"

                            >

                                ➤

                            </button>

                        </form>

                    </div>



                </main>

            </div>



        </section>

    </div>

</div>



<script>

(function () {

    const form =

        document.getElementById('aiForm');



    const input =

        document.getElementById('aiInput');



    const send =

        document.getElementById('aiSend');



    const messages =

        document.getElementById('aiMessages');



    const clearButton =

        document.getElementById('aiClear');



    const csrf =

        <?= json_encode(

            $aiChatCsrf,

            JSON_UNESCAPED_UNICODE |

            JSON_UNESCAPED_SLASHES

        ) ?>;



    function escapeHtml(value) {

        return String(value ?? '')

            .replaceAll('&', '&amp;')

            .replaceAll('<', '&lt;')

            .replaceAll('>', '&gt;')

            .replaceAll('"', '&quot;')

            .replaceAll("'", '&#039;');

    }



    /*

        Hỗ trợ:

        1) link nội bộ dạng thuần:

           /iPhoneStore/...

        2) link AI trả kiểu Markdown:

           [Thông tin tài khoản]\(/iPhoneStore/...)

    */

    function formatAssistantText(text) {
        let html = escapeHtml(text);

        /*
            Link Markdown:
            [Tên link](/iPhoneStore/...)
        */
        html = html.replace(
            /\[([^\]]+)\]\((\/iPhoneStore\/[^)\s]+)\)/g,
            '<a href="$2" class="ai-chat-link">$1</a>'
        );

        /*
            Highlight chữ quan trọng:
            **iPhone 18 Pro**
            -> chữ nổi bật, không còn hiện dấu **
        */
        html = html.replace(
            /\*\*(.+?)\*\*/g,
            '<span class="ai-highlight">$1</span>'
        );

        /*
            Bullet Markdown:
            - nội dung
        */
        html = html.replace(
            /(^|\n)\s*-\s+/g,
            '$1<span class="ai-bullet">•</span> '
        );

        /* Link nội bộ dạng thuần */
        html = html.replace(
            /(^|[\s>])(\/iPhoneStore\/[A-Za-z0-9_\-\/.]+(?:\?[A-Za-z0-9_\-=&%]+)?(?:#[A-Za-z0-9_\-]+)?)/g,
            '$1<a href="$2" class="ai-chat-link">$2</a>'
        );

        html = html.replace(
            /\n/g,
            '<br>'
        );

        return html;
    }

    function money(value) {

        return new Intl.NumberFormat(

            'vi-VN'

        ).format(

            Number(value || 0)

        ) + 'đ';

    }



    function scrollBottom() {

        messages.scrollTop =

            messages.scrollHeight;

    }



    function formatMessageTime(value = null) {

        const date = value

            ? new Date(value)

            : new Date();



        if (Number.isNaN(date.getTime())) {

            return '';

        }



        return new Intl.DateTimeFormat(

            'vi-VN',

            {

                hour: '2-digit',

                minute: '2-digit',

                day: '2-digit',

                month: '2-digit',

                year: 'numeric'

            }

        ).format(date);

    }



    function addMessage(

        text,

        role,

        products = [],

        messageId = null,

        createdAt = null

    ) {

        const row =

            document.createElement('div');



        row.className =

            'ai-row ' +

            (

                role === 'user'

                    ? 'user'

                    : ''

            );



        if (messageId) {

            row.dataset.messageRow = '';

            row.dataset.messageId =

                String(messageId);

        }



        const wrap =

            document.createElement('div');



        wrap.className =

            'ai-wrap';



        const authorLine =

            document.createElement('div');



        authorLine.className =

            'ai-author-line';



        const author =

            document.createElement('div');



        author.className =

            'ai-author';



        author.textContent =

            role === 'user'

                ? 'Bạn'

                : 'iPhoneStore AI';



        authorLine.appendChild(author);



        if (messageId) {

            const deleteButton =

                document.createElement('button');



            deleteButton.type =

                'button';



            deleteButton.className =

                'ai-delete-message';



            deleteButton.dataset.deleteMessage =

                String(messageId);



            deleteButton.title =

                'Xóa tin nhắn';



            deleteButton.textContent =

                '🗑';



            authorLine.appendChild(

                deleteButton

            );

        }



        const bubble =

            document.createElement('div');



        bubble.className =

            'ai-message ' +

            (

                role === 'user'

                    ? 'user'

                    : 'assistant'

            );



        if (role === 'assistant') {

            bubble.innerHTML =

                formatAssistantText(text);

        } else {

            bubble.textContent =

                text;

        }



        wrap.appendChild(

            authorLine

        );



        wrap.appendChild(

            bubble

        );



        const timeElement =

            document.createElement('div');



        timeElement.className =

            'ai-message-time';



        timeElement.textContent =

            formatMessageTime(createdAt);



        wrap.appendChild(

            timeElement

        );



        if (

            role === 'assistant' &&

            Array.isArray(products) &&

            products.length

        ) {

            const grid =

                document.createElement('div');



            grid.className =

                'ai-products';



            products

                .slice(0, 3)

                .forEach(function (product) {

                    const card =

                        document.createElement('a');



                    card.className =

                        'ai-product';



                    card.href =

                        product.url;



                    const image =

                        product.image

                            ? (

                                '<img src="/iPhoneStore/uploads/' +

                                escapeHtml(product.image) +

                                '" alt="">'

                            )

                            : '📱';



                    card.innerHTML = `

                        <div class="ai-product-img">

                            ${image}

                        </div>



                        <div class="ai-product-info">

                            <div class="ai-product-name">

                                ${escapeHtml(product.name)}

                            </div>



                            <div class="ai-product-price">

                                Từ ${money(product.price)}

                            </div>



                            <div class="ai-product-stock">

                                ${

                                    Number(product.stock) > 0

                                        ? '✅ Còn hàng'

                                        : '⚠️ Tạm hết hàng'

                                }

                            </div>

                        </div>

                    `;



                    grid.appendChild(

                        card

                    );

                });



            wrap.appendChild(

                grid

            );

        }



        row.appendChild(

            wrap

        );



        messages.appendChild(

            row

        );



        scrollBottom();



        return row;

    }



    function addThinking() {

        const row =

            document.createElement('div');



        row.className =

            'ai-row';



        row.innerHTML = `

            <div class="ai-wrap">

                <div class="ai-author-line">

                    <div class="ai-author">

                        iPhoneStore AI

                    </div>

                </div>



                <div class="ai-message assistant">

                    <span class="ai-thinking">

                        <span></span>

                        <span></span>

                        <span></span>

                    </span>

                </div>

            </div>

        `;



        messages.appendChild(

            row

        );



        scrollBottom();



        return row;

    }



    function resize() {

        input.style.height =

            'auto';



        input.style.height =

            Math.min(

                input.scrollHeight,

                120

            ) +

            'px';

    }



    messages.addEventListener(

        'click',

        async function (event) {

            const button =

                event.target.closest(

                    '[data-delete-message]'

                );



            if (!button) {

                return;

            }



            const row =

                button.closest(

                    '[data-message-row]'

                );



            const messageId =

                Number(

                    button.dataset.deleteMessage

                );



            if (

                !row ||

                !messageId

            ) {

                return;

            }



            if (

                !confirm(

                    'Xóa tin nhắn này?'

                )

            ) {

                return;

            }



            button.disabled =

                true;



            try {

                const body =

                    new URLSearchParams({

                        csrf_token: csrf,

                        message_id:

                            String(messageId)

                    });



                const response =

                    await fetch(

                        '/iPhoneStore/user/ai/xoa_tin_nhan.php',

                        {

                            method: 'POST',

                            headers: {

                                'Content-Type':

                                    'application/x-www-form-urlencoded;charset=UTF-8',

                                'X-Requested-With':

                                    'XMLHttpRequest'

                            },

                            body: body.toString()

                        }

                    );



                const data =

                    await response.json();



                if (

                    !response.ok ||

                    !data.success

                ) {

                    alert(

                        data.error ||

                        'Không thể xóa tin nhắn.'

                    );



                    button.disabled =

                        false;



                    return;

                }



                row.remove();

            } catch (error) {

                alert(

                    'Không thể xóa tin nhắn lúc này.'

                );



                button.disabled =

                    false;

            }

        }

    );



    input.addEventListener(

        'input',

        resize

    );



    input.addEventListener(

        'keydown',

        function (event) {

            if (

                event.key === 'Enter' &&

                !event.shiftKey

            ) {

                event.preventDefault();

                form.requestSubmit();

            }

        }

    );



    document

        .querySelectorAll(

            '[data-question]'

        )

        .forEach(function (button) {

            button.addEventListener(

                'click',

                function () {

                    input.value =

                        button.dataset.question

                        || '';



                    resize();

                    input.focus();

                }

            );

        });



    clearButton.addEventListener(

        'click',

        async function () {

            if (

                !confirm(

                    'Xóa toàn bộ lịch sử chat AI?'

                )

            ) {

                return;

            }



            const body =

                new URLSearchParams({

                    csrf_token: csrf

                });



            const response =

                await fetch(

                    '/iPhoneStore/user/ai/xoa_lich_su.php',

                    {

                        method: 'POST',

                        headers: {

                            'Content-Type':

                                'application/x-www-form-urlencoded;charset=UTF-8'

                        },

                        body: body.toString()

                    }

                );



            const data =

                await response.json();



            if (!data.success) {

                alert(

                    data.error ||

                    'Không thể xóa lịch sử.'

                );



                return;

            }



            messages.innerHTML =

                '';



            addMessage(

                'Đã xóa toàn bộ lịch sử rồi nhé 😊.',

                'assistant'

            );

        }

    );



    form.addEventListener(

        'submit',

        async function (event) {

            event.preventDefault();



            const message =

                input.value.trim();



            if (

                message === '' ||

                send.disabled

            ) {

                return;

            }



            addMessage(

                message,

                'user'

            );



            input.value =

                '';



            resize();



            send.disabled =

                true;



            const thinking =

                addThinking();



            try {

                const body =

                    new URLSearchParams({

                        message: message,

                        csrf_token: csrf

                    });



                const response =

                    await fetch(

                        '/iPhoneStore/user/ai/xu_ly.php',

                        {

                            method: 'POST',

                            cache: 'no-store',

                            headers: {

                                'Content-Type':

                                    'application/x-www-form-urlencoded;charset=UTF-8',

                                'X-Requested-With':

                                    'XMLHttpRequest'

                            },

                            body: body.toString()

                        }

                    );



                const text =

                    await response.text();



                let data;



                try {

                    data =

                        JSON.parse(text);

                } catch (error) {

                    throw new Error(

                        'Server không trả JSON hợp lệ.'

                    );

                }



                thinking.remove();



                if (

                    !response.ok ||

                    !data.success

                ) {

                    addMessage(

                        data.error ||

                        'AI hiện chưa thể trả lời 😥.',

                        'assistant'

                    );



                    return;

                }



                addMessage(

                    data.answer ||

                        'Mình chưa tạo được câu trả lời.',

                    'assistant',

                    data.products || [],

                    null,

                    data.assistant_message_time || null

                );

            } catch (error) {

                thinking.remove();



                addMessage(

                    'Có lỗi kết nối chatbot 😥. Bạn thử lại giúp mình nhé.',

                    'assistant'

                );

            } finally {

                send.disabled =

                    false;



                input.focus();

            }

        }

    );



    resize();

    scrollBottom();

})();

</script>



<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
