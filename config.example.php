<?php

return [
    'state_dir' => '/var/lib/sesame-portal',
    'db_path' => '/var/lib/sesame-portal/portal.sqlite',
    'db_dsn' => null,
    'db_user' => null,
    'db_password' => null,
    'app_secret' => 'replace-with-random-secret',
    'timezone' => 'UTC',
    'base_url' => 'https://portal.example.com',
    'auth_backend_path' => '/api/sesamedvr/auth',
    'crypto_primary_key' => 'primary',
    'crypto_keys' => [
        'primary' => 'replace-with-random-secret',
    ],
];
