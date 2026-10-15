<?php
declare(strict_types=1);

/*
 * Copy to config.php on the server. Never commit production credentials.
 * Use the exact database name as shown in your hosting panel.
 */
return [
    'db_host' => 'localhost',
    'db_name' => 'Pluschat',
    'db_user' => 'Admin',
    'db_password' => 'CHANGE_ME',
    'base_url' => 'https://прото.плюсчат.рф',
    'mail_from' => 'register@плюсчат.рф',
    'mail_from_name' => 'Pluschat',
    'token_pepper' => 'REPLACE_WITH_AT_LEAST_64_RANDOM_CHARACTERS',
    'allow_registration' => true,
];
