<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/GiphyService.php';

function pc_giphy_dispatch(string $path, string $method): bool
{
    if ($path !== '/api/v1/gifs/search') return false;
    if ($method !== 'GET') {
        header('Allow: GET');
        pc_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
    }
    pc_rate_limit('giphy-search', 30, 60);
    pc_require_verified_user();
    $query = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
    $limit = filter_var($_GET['limit'] ?? 20, FILTER_VALIDATE_INT);
    if ($query === '' || strlen($query) > 120 || $limit === false || $limit < 1 || $limit > 25) {
        pc_json(422, ['ok' => false, 'error' => 'invalid_gif_search', 'message' => 'Укажи поисковый запрос и лимит от 1 до 25.']);
    }
    if (trim((string)(pc_config('giphy_api_key', '') ?: getenv('PLUSCHAT_GIPHY_API_KEY') ?: '')) === '') {
        pc_json(503, ['ok' => false, 'error' => 'giphy_not_configured', 'message' => 'Поиск GIF пока не настроен. Добавь GIPHY API-ключ в конфигурацию сервера.']);
    }
    try {
        pc_json(200, ['ok' => true, 'data' => PCGiphyService::search($query, $limit)]);
    } catch (Throwable $e) {
        error_log('[Pluschat GIPHY] Search failed; ' . get_class($e));
        pc_json(502, ['ok' => false, 'error' => 'giphy_unavailable']);
    }
}
