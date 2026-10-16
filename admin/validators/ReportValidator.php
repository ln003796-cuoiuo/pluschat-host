<?php
declare(strict_types=1);

final class PCReportValidator
{
    public static function validate(array $input): array
    {
        $targetUserId = filter_var($input['target_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $messageId = filter_var($input['message_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        $details = is_string($input['details'] ?? null) ? trim($input['details']) : '';
        if (!in_array($reason, ['spam', 'harassment', 'scam', 'violence', 'other'], true)
            || pc_len($details) > 2000
            || (!$targetUserId && !$messageId)) {
            return ['error' => 'invalid_report'];
        }
        return [
            'target_user_id' => $targetUserId ?: null,
            'message_id' => $messageId ?: null,
            'reason' => $reason,
            'details' => $details,
        ];
    }
}
