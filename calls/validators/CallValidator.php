<?php
declare(strict_types=1);

final class PCCallValidator
{
    public static function callType(mixed $value): ?string
    {
        return is_string($value) && in_array($value, ['audio', 'video'], true) ? $value : null;
    }

    public static function signal(mixed $value): ?array
    {
        if (!is_array($value)) return null;
        $type = $value['type'] ?? null;
        if (!is_string($type) || !in_array($type, ['offer', 'answer', 'ice-candidate', 'decline', 'hangup'], true)) {
            return null;
        }

        if ($type === 'offer' || $type === 'answer') {
            $sdp = $value['sdp'] ?? null;
            if (!is_string($sdp) || $sdp === '' || strlen($sdp) > 12000) return null;
            return ['type' => $type, 'sdp' => $sdp];
        }

        if ($type === 'ice-candidate') {
            $candidate = $value['candidate'] ?? null;
            if (!is_array($candidate)) return null;
            $line = $candidate['candidate'] ?? null;
            if (!is_string($line) || $line === '' || strlen($line) > 4096) return null;
            $mid = $candidate['sdpMid'] ?? null;
            $index = $candidate['sdpMLineIndex'] ?? null;
            if ($mid !== null && (!is_string($mid) || strlen($mid) > 128)) return null;
            if ($index !== null && (!is_int($index) || $index < 0 || $index > 64)) return null;
            return ['type' => $type, 'candidate' => [
                'candidate' => $line,
                'sdpMid' => $mid,
                'sdpMLineIndex' => $index,
            ]];
        }

        return ['type' => $type];
    }
}
