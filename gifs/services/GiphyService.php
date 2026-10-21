<?php
declare(strict_types=1);

/** Server-side GIPHY proxy. The API key is never returned to Android clients. */
final class PCGiphyService
{
    public static function search(string $query, int $limit = 20): array
    {
        $apiKey = trim((string)(pc_config('giphy_api_key', '') ?: getenv('PLUSCHAT_GIPHY_API_KEY') ?: ''));
        if ($apiKey === '') {
            throw new RuntimeException('GIPHY is not configured');
        }
        $query = trim($query);
        if ($query === '' || strlen($query) > 120) {
            throw new InvalidArgumentException('Invalid GIF search query');
        }
        $limit = max(1, min(25, $limit));
        $url = 'https://api.giphy.com/v1/gifs/search?' . http_build_query([
            'api_key' => $apiKey,
            'q' => $query,
            'limit' => $limit,
            'rating' => 'pg-13',
            'lang' => 'en',
        ]);
        $raw = false;
        $status = 0;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $raw = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $context = stream_context_create(['http' => [
                'method' => 'GET',
                'timeout' => 8,
                'ignore_errors' => true,
            ]]);
            $raw = @file_get_contents($url, false, $context);
            foreach (($http_response_header ?? []) as $header) {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $m)) $status = (int)$m[1];
            }
        }
        if (!is_string($raw) || $status !== 200) {
            error_log('[Pluschat GIPHY] Search request failed; HTTP ' . $status);
            throw new RuntimeException('GIPHY request failed');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !is_array($decoded['data'] ?? null)) {
            throw new RuntimeException('Invalid GIPHY response');
        }
        $items = [];
        foreach ($decoded['data'] as $gif) {
            if (!is_array($gif)) continue;
            $images = is_array($gif['images'] ?? null) ? $gif['images'] : [];
            $original = is_array($images['fixed_width'] ?? null) ? $images['fixed_width'] : ($images['original'] ?? []);
            $preview = is_array($images['fixed_width_small'] ?? null) ? $images['fixed_width_small'] : $original;
            $gifUrl = is_string($original['url'] ?? null) ? $original['url'] : '';
            $previewUrl = is_string($preview['url'] ?? null) ? $preview['url'] : $gifUrl;
            if ($gifUrl === '' || !preg_match('~^https://.+~i', $gifUrl)) continue;
            $items[] = [
                'id' => (string)($gif['id'] ?? ''),
                'title' => (string)($gif['title'] ?? ''),
                'url' => $gifUrl,
                'preview_url' => $previewUrl,
                'width' => (int)($original['width'] ?? 0),
                'height' => (int)($original['height'] ?? 0),
            ];
        }
        return $items;
    }
}
