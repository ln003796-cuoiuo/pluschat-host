<?php
declare(strict_types=1);

require_once __DIR__ . '/../validators/ReportValidator.php';
require_once __DIR__ . '/../repositories/ReportRepository.php';

final class PCReportService
{
    public static function listOpen(int $userId): array
    {
        $pdo = pc_db();
        if (!PCReportRepository::isModerator($pdo, $userId)) {
            return ['status' => 403, 'body' => ['ok' => false, 'error' => 'admin_required']];
        }
        return ['status' => 200, 'body' => ['ok' => true, 'reports' => PCReportRepository::listOpen($pdo)]];
    }

    public static function submit(int $reporterId, array $input): array
    {
        $report = PCReportValidator::validate($input);
        if (isset($report['error'])) {
            return ['status' => 422, 'body' => ['ok' => false, 'error' => $report['error']]];
        }
        $pdo = pc_db();
        if ($report['message_id'] !== null && !PCReportRepository::canViewMessage($pdo, $reporterId, (int)$report['message_id'])) {
            return ['status' => 404, 'body' => ['ok' => false, 'error' => 'message_not_found']];
        }
        PCReportRepository::create($pdo, $reporterId, $report);
        return ['status' => 201, 'body' => ['ok' => true, 'report_submitted' => true]];
    }
}
