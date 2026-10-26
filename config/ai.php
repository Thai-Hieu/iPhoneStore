<?php

$secretFile = __DIR__ . '/ai_secret.php';

$secret = file_exists($secretFile)
    ? require $secretFile
    : [];

return [
    'api_key' => trim(
        (string)(
            $secret['api_key']
            ?? getenv('OPENAI_API_KEY')
            ?: ''
        )
    ),

    'model' => 'gpt-6-luna',
    'endpoint' => 'https://api.openai.com/v1/responses',
    'timeout' => 45,
    'max_output_tokens' => 1000,

    'history_limit' => 18,
    'catalog_scan_limit' => 150,
    'catalog_context_limit' => 16,

    /*
        Số bản ghi nhật ký thay đổi gần nhất
        cho AI đọc khi cần trả lời câu hỏi
        "thêm lúc nào", "xóa lúc nào", "đã sửa gì".
    */
    'audit_context_limit' => 45,
];
