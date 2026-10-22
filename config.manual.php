<?php
declare(strict_types=1);

/*
 * MANUAL CONFIGURATION TEMPLATE.
 * Copy this file to config.php in the project root on your hosting account.
 * Fill in the real MySQL credentials and GIPHY API key there.
 * Never commit real credentials or upload this template as config.php unchanged.
 */
return [
    'db_host' => 'localhost',
    'db_name' => 'pluschat',
    // Enter the exact MySQL username created in your hosting control panel.
    'db_user' => 'REPLACE_WITH_MYSQL_USERNAME',
    'db_password' => 'REPLACE_WITH_MYSQL_PASSWORD',

    'mail_from' => 'register@плюсчат.рф',
    'giphy_api_key' => 'REPLACE_WITH_GIPHY_API_KEY',

    // Replace each with a unique random 64-character hexadecimal value.
    // Keep the backup key separately safe; losing it can make encrypted backups unrecoverable.
    'backup_encryption_key' => 'REPLACE_WITH_64_HEX_CHAR_BACKUP_KEY',
    'app_secret' => 'REPLACE_WITH_64_HEX_CHAR_APP_SECRET',

    'backup_directory' => dirname(__DIR__) . '/../pluschat-private-backups',
    'app_base_url' => 'https://xn--80avljg2a1c.xn--p1ai',
    'allowed_origins' => [
        'https://xn--80avljg2a1c.xn--p1ai',
    ],
];
