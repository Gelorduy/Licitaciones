<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'extraction_model' => env('OPENAI_EXTRACTION_MODEL', 'gpt-4.1-mini'),
    ],

    'pinecone' => [
        'api_key' => env('PINECONE_API_KEY'),
        'index_host' => env('PINECONE_INDEX_HOST'),
        'index_host_dev' => env('PINECONE_INDEX_HOST_DEV'),
    ],

    'ocr' => [
        'languages' => env('OCR_LANGUAGES', 'spa+eng'),
        'vision_pages' => (int) env('OCR_VISION_PAGES', 2),
        'vision_scan_pages' => (int) env('OCR_VISION_SCAN_PAGES', 8),
        'vision_max_width' => (int) env('OCR_VISION_MAX_WIDTH', 1100),
        'vision_quality' => (int) env('OCR_VISION_QUALITY', 60),
        'vision_min_text_chars' => (int) env('OCR_VISION_MIN_TEXT_CHARS', 7000),
        'vision_min_missing_fields' => (int) env('OCR_VISION_MIN_MISSING_FIELDS', 3),
    ],

    'embeddings' => [
        'engine' => env('EMBEDDING_ENGINE', 'openai'),
    ],

    'extraction' => [
        'engine' => env('EXTRACTION_ENGINE', 'openai'),
    ],

    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://ollama:11434'),
        'model' => env('OLLAMA_MODEL', 'nomic-embed-text'),
        'extraction_model' => env('OLLAMA_EXTRACTION_MODEL', 'qwen2.5:7b-instruct'),
        'vision_model' => env('OLLAMA_VISION_MODEL', 'qwen2.5vl:7b'),
        'vision_enabled' => filter_var(env('OLLAMA_VISION_ENABLED', true), FILTER_VALIDATE_BOOL),
        'vision_timeout' => (int) env('OLLAMA_VISION_TIMEOUT', 90),
        'vision_total_budget_ms' => (int) env('OLLAMA_VISION_TOTAL_BUDGET_MS', 95000),
        'vision_retry_attempts' => (int) env('OLLAMA_VISION_RETRY_ATTEMPTS', 2),
        'vision_retry_base_delay_ms' => (int) env('OLLAMA_VISION_RETRY_BASE_DELAY_MS', 1200),
        'vision_images_per_request' => (int) env('OLLAMA_VISION_IMAGES_PER_REQUEST', 2),
        'vision_image_max_width' => (int) env('OLLAMA_VISION_IMAGE_MAX_WIDTH', 320),
        'vision_image_quality' => (int) env('OLLAMA_VISION_IMAGE_QUALITY', 55),
        'vision_image_dpi' => (int) env('OLLAMA_VISION_IMAGE_DPI', 72),
        'vision_num_ctx' => (int) env('OLLAMA_VISION_NUM_CTX', 1024),
        'vision_max_output_tokens' => (int) env('OLLAMA_VISION_MAX_OUTPUT_TOKENS', 384),
        'extraction_timeout' => (int) env('OLLAMA_EXTRACTION_TIMEOUT', 300),
    ],

];
