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
        'extraction_timeout' => (int) env('OLLAMA_EXTRACTION_TIMEOUT', 300),
    ],

];
