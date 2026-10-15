<?php
declare(strict_types=1);

/*
 * Copy to config.php on the server.
 * Production credentials belong in hosting environment variables or config.php
 * outside the public web root. Never commit passwords or secret tokens.
 */
return [
    'db_host' => getenv('PLUSCHAT_DB_HOST') ?: 'localhost',
    'db_name' => getenv('PLUSCHAT_DB_NAME') ?: 'PluschatMain',
    'db_user' => getenv('PLUSCHAT_DB_USER') ?: 'Admin',
    'db_password' => getenv('PLUSCHAT_DB_PASSWORD') ?: '',
    'base_url' => getenv('PLUSCHAT_BASE_URL') ?: 'https://xn--80avljg2a1c.xn--p1ai',
    'install_token' => getenv('INSTALL_TOKEN') ?: '',
    'mail_from' => getenv('PLUSCHAT_MAIL_FROM') ?: 'register@плюсчат.рф',
    'mail_from_name' => getenv('PLUSCHAT_MAIL_FROM_NAME') ?: 'Pluschat',
    'sms_api_url' => getenv('PLUSCHAT_SMS_API_URL') ?: '',
    'sms_api_token' => getenv('PLUSCHAT_SMS_API_TOKEN') ?: '',
    'access_ttl' => 900,
    'refresh_ttl' => 2592000,
];
