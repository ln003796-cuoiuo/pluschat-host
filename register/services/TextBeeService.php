<?php
declare(strict_types=1);

/**
 * TextBee SMS gateway. The API key must live only in the private server config.php
 * or PLUSCHAT_TEXTBEE_API_KEY environment variable, never in the Android app/repository.
 */
final class PCTextBeeService
{
    public static function send(string $phone, string $message): bool
    {
        $apiKey = trim((string)(pc_config('textbee_api_key', '') ?: getenv('PLUSCHAT_TEXTBEE_API_KEY') ?: ''));
        if ($apiKey === '' || !preg_match('/^\\+[1-9][0-9]{7,14}$/', $phone)) {
            return false;
        }

        $payload = json_encode(['recipients' => [$phone], 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) return false;
        $url = 'https://api.textbee.dev/api/v1/gateway/send-sms';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $apiKey],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $raw = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $context = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nx-api-key: {$apiKey}\r\n",
                'content' => $payload,
                'timeout' => 12,
                'ignore_errors' => true,
            ]]);
            $raw = @file_get_contents($url, false, $context);
            $status = 0;
            foreach (($http_response_header ?? []) as $header) {
                if (preg_match('/^HTTP\\/\\S+\\s+(\\d{3})/', $header, $m)) $status = (int)$m[1];
            }
        }

        if (!is_string($raw) || $status !== 200) return false;
        $decoded = json_decode($raw, true);
        return is_array($decoded) && (($decoded['data']['success'] ?? false) === true
            || isset($decoded['data']['smsBatchId'])
            || (int)($decoded['data']['successCount'] ?? 0) > 0);
    }
}
