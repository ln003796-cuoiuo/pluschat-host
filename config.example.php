<?php
declare(strict_types=1);

/*
 * Copy this file to config.php on the hosting server and fill in the real values.
 * Do not commit production passwords or API tokens to GitHub.
 */
return [
    'db_host' => getenv('PLUSCHAT_DB_HOST') ?: 'localhost',
    'db_name' => getenv('PLUSCHAT_DB_NAME') ?: 'pluschat',
    'db_user' => getenv('PLUSCHAT_DB_USER') ?: 'admin',
    'db_password' => getenv('PLUSCHAT_DB_PASSWORD') ?: '',
    'base_url' => getenv('PLUSCHAT_BASE_URL') ?: 'https://xn--n1aabel.xn--80avljg2a1c.xn--p1ai',
    // Generate a long random value for the one-time web installer; remove it after installation.
    'install_token' => getenv('INSTALL_TOKEN') ?: '',
    'mail_from' => getenv('PLUSCHAT_MAIL_FROM') ?: 'register@плюсчат.рф',
    'mail_from_name' => getenv('PLUSCHAT_MAIL_FROM_NAME') ?: 'Pluschat',
    'sms_api_url' => getenv('PLUSCHAT_SMS_API_URL') ?: '',
    'sms_api_token' => getenv('PLUSCHAT_SMS_API_TOKEN') ?: '',
    'access_ttl' => 900,
    'refresh_ttl' => 2592000,
];
