<?php
declare(strict_types=1);

/*
 * Copy this file to config.php on the hosting account.
 * Never commit config.php or real credentials to GitHub.
 */
return [
    'db_host' => 'localhost',
    'db_name' => 'pluschat',
    'db_user' => 'Admin',
    'db_password' => '', // Set privately on hosting; do not send it in chat.
    'mail_from' => 'register@плюсчат.рф',
    'app_base_url' => 'https://xn--80avljg2a1c.xn--p1ai',
    'allowed_origins' => [
        'https://xn--80avljg2a1c.xn--p1ai',
    ],
];