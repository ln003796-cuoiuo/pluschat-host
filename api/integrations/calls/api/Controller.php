<?php
declare(strict_types=1);

/*
 * Pluschat calls API routes.
 * Business logic remains in this feature module; shared HTTP/database helpers
 * are loaded by public/index.php through bootstrap.php.
 */
function pc_calls_integration_dispatch(string $path, string $method): bool
{
    if ($path === '/api/v1/calls' && $method === 'GET') {
            pc_require_verified_user();
            pc_json(200, ['ok' => true, 'calls_url' => 'https://tell.плюсчат.рф', 'separate_service' => true]);
        }
    return false;
}
