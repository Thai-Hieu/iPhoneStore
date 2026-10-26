<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header(
    'Content-Type: application/json; charset=utf-8'
);

header(
    'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/ai_helper.php';
require_once __DIR__ . '/catalog_helper.php';
require_once __DIR__ . '/web_context_helper.php';
require_once __DIR__ . '/ai_logger.php';

$aiConfig =
    require __DIR__ . '/../../config/ai.php';

$storeKnowledge =
    require __DIR__ . '/../../config/ai_store_knowledge.php';

$siteMap =
    require __DIR__ . '/../../config/ai_site_map.php';

function aiJson(
    array $payload,
    int $status = 200
): never {
    http_response_code($status);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function aiAnswerText(array $data): string
{
    $answer = '';

    foreach (($data['output'] ?? []) as $item) {
        if (($item['type'] ?? '') !== 'message') {
            continue;
        }

        foreach (($item['content'] ?? []) as $content) {
            if (($content['type'] ?? '') === 'output_text') {
                $answer .=
                    (string)(
                        $content['text']
                        ?? ''
                    );
            }
        }
    }

    return trim($answer);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    aiJson(
        [
            'success' => false,
            'error' =>
                'Phương thức gửi không hợp lệ.'
        ],
        405
    );
}

$csrf = (string)($_POST['csrf_token'] ?? '');
$sessionCsrf =
    (string)($_SESSION['ai_chat_csrf'] ?? '');

if (
    $csrf === '' ||
    $sessionCsrf === '' ||
    !hash_equals($sessionCsrf, $csrf)
) {
    aiJson(
        [
            'success' => false,
            'error' =>
                'Phiên trò chuyện không hợp lệ. Vui lòng tải lại trang.'
        ],
        403
    );
}

$question =
    trim(
        (string)(
            $_POST['message']
            ?? ''
        )
    );

if ($question === '') {
    aiJson([
        'success' => false,
        'error' =>
            'Bạn hãy nhập câu hỏi nhé 😊'
    ]);
}

if (
    mb_strlen(
        $question,
        'UTF-8'
    ) > 1200
) {
    aiJson([
        'success' => false,
        'error' =>
            'Câu hỏi hơi dài rồi 😊 Bạn giúp mình rút gọn dưới 1.200 ký tự nhé.'
    ]);
}

$apiKey =
    trim(
        (string)(
            $aiConfig['api_key']
            ?? ''
        )
    );

if ($apiKey === '') {
    aiJson(
        [
            'success' => false,
            'error' =>
                'Chatbot AI chưa được cấu hình API key trên máy chủ.'
        ],
        503
    );
}

try {
    aiChatEnsureHistoryTable($conn);

    $history =
        aiChatLoadHistory(
            $conn,
            (int)(
                $aiConfig['history_limit']
                ?? 18
            )
        );
} catch (Throwable $e) {
    error_log(
        'AI history: ' .
        $e->getMessage()
    );

    $history = [];
}

$catalog =
    aiLoadCatalog(
        $conn,
        (int)(
            $aiConfig['catalog_scan_limit']
            ?? 150
        )
    );

$ranked =
    aiRankCatalog(
        $catalog,
        $question,
        (int)(
            $aiConfig['catalog_context_limit']
            ?? 16
        )
    );

$catalogText =
    !empty($ranked)
        ? aiCatalogToText($ranked)
        : 'Không có dữ liệu sản phẩm.';

$role = aiChatRole();

$siteMapText =
    aiSiteMapToText(
        $siteMap,
        $role
    );

$userContext =
    aiCurrentUserContext($conn);

$auditContext =
    aiAuditContext(
        $conn,
        (int)(
            $aiConfig['audit_context_limit']
            ?? 45
        )
    );

$historyLines = [];

foreach ($history as $message) {
    $who =
        ($message['vai_tro'] ?? '') === 'assistant'
            ? 'AI'
            : 'Khách';

    $content =
        trim(
            (string)(
                $message['noi_dung']
                ?? ''
            )
        );

    if ($content === '') {
        continue;
    }

    $historyLines[] =
        $who .
        ': ' .
        mb_substr(
            $content,
            0,
            1400,
            'UTF-8'
        );
}

$historyText =
    !empty($historyLines)
        ? implode("\n", $historyLines)
        : 'Chưa có lịch sử.';

$storeText =
    aiKnowledgeToText(
        $storeKnowledge
    );

$instructions = <<<PROMPT
Bạn là "iPhoneStore AI", trợ lý trung tâm của website iPhoneStore.

MỤC TIÊU CHÍNH
Bạn phải hỗ trợ gần như mọi câu hỏi liên quan đến website iPhoneStore:
- tìm sản phẩm;
- tư vấn theo nhiều điều kiện;
- thông số kỹ thuật;
- giá, tồn kho, phiên bản, màu, khuyến mãi;
- cách dùng website;
- người dùng muốn đi tới trang nào;
- giỏ hàng, đơn hàng, thanh toán, thông báo, đánh giá và tài khoản của CHÍNH người đang đăng nhập;
- thời gian tạo/thêm dữ liệu nếu hệ thống có;
- lịch sử thêm/sửa/xóa nếu nhật ký thay đổi có dữ liệu.

QUY TẮC DỮ LIỆU
1. Với dữ liệu của website, chỉ được dựa trên dữ liệu được cung cấp.
2. Tuyệt đối không bịa giá, tồn kho, thời gian, thông số, đơn hàng, chính sách hoặc đường dẫn.
3. Không được tiết lộ dữ liệu riêng của người dùng khác.
4. Nếu người hiện tại là guest, không được giả vờ biết giỏ hàng/đơn hàng/tài khoản riêng của họ.
5. Nếu dữ liệu bị xóa trước khi hệ thống audit được cài thì phải nói rõ không thể xác định lịch sử cũ.
6. Các thay đổi sau khi audit được cài có thể dùng nhật ký để trả lời "thêm lúc nào", "xóa lúc nào", "sửa lúc nào".

ĐIỀU HƯỚNG WEBSITE
- Nếu người dùng nói "tôi muốn đến...", "mở...", "vào...", "ở đâu..." thì dùng BẢN ĐỒ WEBSITE.
- Trả lời ngắn gọn và đưa đúng đường dẫn.
- Nếu trang cần đăng nhập mà người dùng đang là guest, nói rõ cần đăng nhập và có thể đưa link đăng nhập.
- Nếu trang admin mà người hiện tại không phải admin, không hướng dẫn vượt quyền.
- Khi đưa link nội bộ, ghi nguyên đường dẫn bắt đầu bằng /iPhoneStore/.

SẢN PHẨM
- Hiểu ngôn ngữ tự nhiên và lỗi chính tả phổ biến như "dynamic iland", "256g", "20tr".
- Kết hợp nhiều tiêu chí cùng lúc.
- Nếu đề xuất sản phẩm, thêm marker [[PRODUCT:ID]] cho tối đa 3 sản phẩm phù hợp.
- ID phải đúng dữ liệu.
- Không giải thích marker.

CÂU HỎI NGOÀI WEBSITE
- Có thể trả lời xã giao hoặc kiến thức phổ thông ngắn gọn nếu chắc chắn.
- Nếu không chắc hoặc cần dữ liệu thời gian thực mà không có, hãy từ chối lịch sự.
- Ví dụ phong cách:
  "Mình chưa có đủ dữ liệu để trả lời chính xác câu này 😊. Nếu bạn muốn, mình có thể hỗ trợ rất sâu về iPhone hoặc mọi chức năng trong iPhoneStore 📱✨."
- Dùng emoji/icon vừa phải, tự nhiên.

PHONG CÁCH
- Luôn trả lời tiếng Việt.
- Thân thiện, lịch sự, dễ hiểu.
- Ưu tiên câu trả lời thực tế, có bước làm hoặc link khi phù hợp.
- Không tự nhận là nhân viên con người.
PROMPT;

$input = <<<PROMPT
====================
THÔNG TIN CHÍNH THỨC CỬA HÀNG
====================
{$storeText}

====================
BẢN ĐỒ WEBSITE
====================
{$siteMapText}

====================
TRẠNG THÁI / DỮ LIỆU CỦA NGƯỜI DÙNG HIỆN TẠI
====================
{$userContext}

====================
SẢN PHẨM LIÊN QUAN NHẤT
====================
{$catalogText}

====================
NHẬT KÝ THÊM / SỬA / XÓA GẦN ĐÂY
====================
{$auditContext}

====================
LỊCH SỬ TRÒ CHUYỆN
====================
{$historyText}

====================
CÂU HỎI MỚI
====================
{$question}
PROMPT;

$payload = [
    'model' =>
        (string)(
            $aiConfig['model']
            ?? 'gpt-6-luna'
        ),
    'instructions' => $instructions,
    'input' => $input,
    'max_output_tokens' =>
        max(
            300,
            (int)(
                $aiConfig['max_output_tokens']
                ?? 1000
            )
        ),
    'store' => false,
];

$responseHeaders = [];

$ch = curl_init(
    (string)$aiConfig['endpoint']
);

curl_setopt_array(
    $ch,
    [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT =>
            max(
                20,
                (int)(
                    $aiConfig['timeout']
                    ?? 45
                )
            ),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS =>
            json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            ),

        /*
            Thu thập một số response header hữu ích
            để chẩn đoán lỗi mà không lộ API key.
        */
        CURLOPT_HEADERFUNCTION =>
            function (
                $curl,
                string $headerLine
            ) use (&$responseHeaders): int {
                $length =
                    strlen(
                        $headerLine
                    );

                $parts =
                    explode(
                        ':',
                        $headerLine,
                        2
                    );

                if (count($parts) === 2) {
                    $name =
                        strtolower(
                            trim(
                                $parts[0]
                            )
                        );

                    $value =
                        trim(
                            $parts[1]
                        );

                    if (
                        in_array(
                            $name,
                            [
                                'x-request-id',
                                'retry-after',
                                'x-ratelimit-limit-requests',
                                'x-ratelimit-remaining-requests',
                                'x-ratelimit-reset-requests',
                            ],
                            true
                        )
                    ) {
                        $responseHeaders[$name] =
                            $value;
                    }
                }

                return $length;
            },
    ]
);

$response =
    curl_exec($ch);

if ($response === false) {
    $curlNo =
        curl_errno($ch);

    $curlMessage =
        curl_error($ch);

    aiLogError(
        'curl_connection_error',
        [
            'curl_errno' =>
                $curlNo,
            'curl_message' =>
                $curlMessage,
            'model' =>
                (string)(
                    $aiConfig['model']
                    ?? ''
                ),
        ]
    );

    curl_close($ch);

    aiJson(
        [
            'success' => false,
            'error' =>
                'Không thể kết nối tới OpenAI 🌐. Có thể mạng, DNS hoặc SSL đang gặp vấn đề. Admin có thể xem log AI để biết lỗi cụ thể.',
            'error_type' =>
                'curl_connection_error',
        ],
        502
    );
}

$status =
    (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

curl_close($ch);

$data =
    json_decode(
        $response,
        true
    );

if (
    $data === null &&
    json_last_error() !== JSON_ERROR_NONE
) {
    aiLogError(
        'invalid_json_response',
        [
            'http_status' =>
                $status,
            'json_error' =>
                json_last_error_msg(),
            'request_id' =>
                (string)(
                    $responseHeaders['x-request-id']
                    ?? ''
                ),
            'response_preview' =>
                mb_substr(
                    (string)$response,
                    0,
                    700,
                    'UTF-8'
                ),
        ]
    );

    aiJson(
        [
            'success' => false,
            'error' =>
                'OpenAI trả về dữ liệu không hợp lệ 🧩. Chi tiết đã được lưu trong log AI.',
            'error_type' =>
                'invalid_json_response',
        ],
        502
    );
}

if (
    $status < 200 ||
    $status >= 300
) {
    $classified =
        aiClassifyApiError(
            $status,
            is_array($data)
                ? $data
                : [],
            $responseHeaders
        );

    aiLogError(
        $classified['kind'],
        [
            'http_status' =>
                $status,
            'api_code' =>
                $classified['api_code'],
            'api_type' =>
                $classified['api_type'],
            'api_message' =>
                $classified['api_message'],
            'retry_after' =>
                $classified['retry_after'],
            'request_id' =>
                (string)(
                    $responseHeaders['x-request-id']
                    ?? ''
                ),
            'rate_limit_remaining' =>
                (string)(
                    $responseHeaders['x-ratelimit-remaining-requests']
                    ?? ''
                ),
            'rate_limit_reset' =>
                (string)(
                    $responseHeaders['x-ratelimit-reset-requests']
                    ?? ''
                ),
            'model' =>
                (string)(
                    $aiConfig['model']
                    ?? ''
                ),
        ]
    );

    aiJson(
        [
            'success' => false,
            'error' =>
                $classified['message'],
            'error_type' =>
                $classified['kind'],
            'http_status' =>
                $status,
        ],
        502
    );
}

$answer =
    aiAnswerText(
        is_array($data)
            ? $data
            : []
    );

if ($answer === '') {
    aiLogError(
        'empty_ai_answer',
        [
            'http_status' => $status,
            'request_id' =>
                (string)(
                    $responseHeaders['x-request-id']
                    ?? ''
                ),
            'model' =>
                (string)(
                    $aiConfig['model']
                    ?? ''
                ),
        ]
    );

    aiJson(
        [
            'success' => false,
            'error' =>
                'OpenAI đã phản hồi nhưng không có nội dung trả lời 🧩. Lỗi này đã được ghi vào log AI.',
            'error_type' =>
                'empty_ai_answer',
        ],
        502
    );
}

$productIds = [];

if (
    preg_match_all(
        '/\[\[PRODUCT:(\d+)\]\]/',
        $answer,
        $matches
    )
) {
    foreach ($matches[1] as $id) {
        $id = (int)$id;

        if (
            $id > 0 &&
            !in_array(
                $id,
                $productIds,
                true
            )
        ) {
            $productIds[] = $id;
        }

        if (count($productIds) >= 3) {
            break;
        }
    }
}

$answer = trim(
    preg_replace(
        '/\s*\[\[PRODUCT:\d+\]\]\s*/',
        "\n",
        $answer
    )
);

$cardMap =
    aiCatalogCardMap($ranked);

$cards = [];

foreach ($productIds as $id) {
    if (isset($cardMap[$id])) {
        $cards[] = $cardMap[$id];
    }
}

$userMessageId = 0;
$assistantMessageId = 0;

try {
    /*
        Tin user không có card.
    */
    $userMessageId =
        aiChatSaveMessage(
            $conn,
            'user',
            $question,
            []
        );

    /*
        Quan trọng:
        lưu cards vào products_json cùng dòng trả lời AI.
        Vì vậy F5 / thoát vào lại vẫn dựng được card.
    */
    $assistantMessageId =
        aiChatSaveMessage(
            $conn,
            'assistant',
            $answer,
            $cards
        );
} catch (Throwable $e) {
    error_log(
        'AI save history: ' .
        $e->getMessage()
    );
}

aiJson([
    'success' => true,
    'answer' => $answer,
    'products' => $cards,

    /*
        Client dùng ID này để có nút xóa ngay,
        không cần F5 mới lấy được message_id.
    */
    'user_message_id' =>
        $userMessageId,

    'assistant_message_id' =>
        $assistantMessageId,

    'created_at' =>
        date('Y-m-d H:i:s'),
]);
