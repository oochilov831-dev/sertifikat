<?php

return [
    'name'    => 'Sertifikat Tizimi',
    'url'     => $_ENV['APP_URL'] ?? 'http://localhost:8000',
    'env'     => $_ENV['APP_ENV'] ?? 'production',
    'debug'   => ($_ENV['APP_DEBUG'] ?? 'false') === 'true',
    'version' => '1.0.0',

    'plans' => [
        'free' => [
            'name'            => 'Bepul',
            'price'           => 0,
            'limit'           => 5,
            'watermark'       => true,
            'custom_template' => false,
        ],
        'standard' => [
            'name'            => 'Standart',
            'price'           => 35000,
            'limit'           => 100,
            'watermark'       => false,
            'custom_template' => true,
        ],
        'pro' => [
            'name'            => 'Pro',
            'price'           => 99000,
            'limit'           => -1,
            'watermark'       => false,
            'custom_template' => true,
        ],
    ],
];
