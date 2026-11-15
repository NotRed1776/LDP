<?php
return [
    'db' => [
        'host' => 'YOUR_IONOS_DATABASE_HOST',
        'name' => 'YOUR_IONOS_DATABASE_NAME',
        'user' => 'YOUR_IONOS_DATABASE_USERNAME',
        'password' => 'YOUR_IONOS_DATABASE_PASSWORD',
        'charset' => 'utf8mb4',
    ],
    'app_key' => 'YOUR_64_CHARACTER_RANDOM_SECRET',
    'setup_key' => 'YOUR_DIFFERENT_64_CHARACTER_RANDOM_SECRET',
    'storage_dir' => __DIR__ . '/lycan-private',
    'require_https' => true,
    'mail' => [
        'from' => 'Contact@lycandivision.com',
        'quote' => 'admin@lycandivision.com',
        'application' => 'admin@lycandivision.com',
        'timeoff' => 'l.kornick@lycandivision.com',
        'concern' => 'l.kornick@lycandivision.com',
    ],
];
