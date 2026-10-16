<?php
declare(strict_types=1);

/*
 * Example only. The one-time installer writes config.php with the DB password.
 * Never commit config.php or production credentials.
 */
return [
    'db_host' => 'localhost',
    'db_name' => 'pluschat',
    'db_user' => 'admin',
    'db_password' => '',
    'mail_from' => 'register@плюсчат.рф',
    'app_base_url' => 'https://xn--80avljg2a1c.xn--p1ai',
    'allowed_origins' => [
        'https://xn--80avljg2a1c.xn--p1ai',
        'https://xn--n1aabel.xn--80avljg2a1c.xn--p1ai',
    ],
];
