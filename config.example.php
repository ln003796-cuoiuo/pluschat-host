<?php
declare(strict_types=1);

/*
 * Example only. The one-time installer writes config.php with the DB password.
 * Never commit config.php or production credentials.
 */
return [
    'db_host' => 'localhost',
    'db_name' => 'Pluschat',
    'db_user' => 'admin',
    'db_password' => '',
    'mail_from' => 'register@плюсчат.рф',
    // Configure privately on the host; never commit real credentials.
    'textbee_api_key' => '',
    'giphy_api_key' => '',
    'textbee_device_id' => '6ad27e0e10b63ade60583c87',
    'app_base_url' => 'https://xn--80avljg2a1c.xn--p1ai',
    'allowed_origins' => [
        'https://xn--80avljg2a1c.xn--p1ai',
    ],
];
