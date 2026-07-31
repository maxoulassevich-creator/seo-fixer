<?php
namespace Relod\SeoFixer\Service;

use Bitrix\Main\Type\DateTime;
use Relod\SeoFixer\Model\ImportTable;
use Relod\SeoFixer\Model\IssueTable;
use Relod\SeoFixer\Model\ErrorLogTable;

class ImportService
{
    public function importUploadedFile(array $file, int $userId, string $comment = ''): int
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Файл не загружен. Проверьте размер файла и повторите загрузку.');
        }

        $originalName = (string)$file['name'];
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv'], true)) {
            throw new \RuntimeException('Поддерживаются только XLSX и CSV.');
        }

        $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/upload/relod_seofixer';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new \RuntimeException('Не удалось создать папку для загрузок: /upload/relod_seofixer');
        }

        $safeName = date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9_.-]+/u', '_', ReportTypeDetector::decodeNetpeakName($originalName));
        $targetPath = $uploadDir . '/' . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new \RuntimeException('Не удалось сохранить загруженный файл.');
        }

        return $this->importFile($targetPath, $originalName, $userId, $comment);
    }

    public function importFile(string $filePath, string $originalName, int $userId, string $comment = ''): int
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $data = $ext === 'csv' ? (new CsvReader())->read($filePath) : (new XlsxReader())->read($filePath);
        $reportType = ReportTypeDetector::detect($originalName, $data['headers']);
        $now = new DateTime();

        $importResult = ImportTable::add([
            'FILE_NAME' => basename($filePath),
            'ORIGINAL_NAME' => ReportTypeDetector::decodeNetpeakName($originalName),
            'REPORT_TYPE' => $reportType,
            'ROWS_TOTAL' => count($data['rows']),
            'FILE_HASH' => hash_file('sha256', $filePath),
            'STATUS' => 'checked',
            'COMMENT_TEXT' => $comment,
            'IMPORTED_BY' => $userId,
            'IMPORTED_AT' => $now,
        ]);

        if (!$importResult->isSuccess()) {
            throw new \RuntimeException('Не удалось создать запись импорта: ' . implode('; ', $importResult->getErrorMessages()));
        }

        $importId = (int)$importResult->getId();
        $this->createIssues($importId, $reportType, $data['rows']);
        return $importId;
    }

    public function refreshSuggestions(int $importId = 0, array $issueIds = []): int
    {
        $filter = [];
        if ($importId > 0) {
            $filter['=IMPORT_ID'] = $importId;
        }
        if ($issueIds) {
            $filter['@ID'] = array_values(array_unique(array_map('intval', $issueIds)));
        }

        $updated = 0;
        $now = new DateTime();
        $rows = IssueTable::getList(['filter' => $filter, 'order' => ['ID' => 'ASC']]);
        $suggestions = new IssueSuggestionService();
        while ($issue = $rows->fetch()) {
            $built = $suggestions->refreshIssueFields($issue);
            IssueTable::update((int)$issue['ID'], [
                'SOURCE_URL' => $built['source_url'],
                'TARGET_URL' => $built['target_url'],
                'FINAL_URL' => $built['final_url'],
                'ANCHOR_TEXT' => $built['anchor'],
                'OLD_VALUE' => $built['old_value'],
                'NEW_VALUE' => $built['new_value'],
                'PLACE_HINT' => $built['place_hint'],
                'RISK_LEVEL' => $built['risk_level'],
                'GROUP_HASH' => $built['group_hash'],
                'UPDATED_AT' => $now,
            ]);
            $updated++;
        }
        return $updated;
    }

    private function createIssues(int $importId, string $reportType, array $rows): void
    {
        $now = new DateTime();
        $suggestions = new IssueSuggestionService();
        foreach ($rows as $row) {
            try {
                $issue = $suggestions->build($reportType, $row);
                $result = IssueTable::add([
                    'IMPORT_ID' => $importId,
                    'ISSUE_TYPE' => $reportType,
                    'SEVERITY' => $issue['severity'],
                    'SOURCE_URL' => $issue['source_url'],
                    'TARGET_URL' => $issue['target_url'],
                    'FINAL_URL' => $issue['final_url'],
                    'ANCHOR_TEXT' => $issue['anchor'],
                    'OLD_VALUE' => $issue['old_value'],
                    'NEW_VALUE' => $issue['new_value'],
                    'PLACE_HINT' => $issue['place_hint'],
                    'RISK_LEVEL' => $issue['risk_level'],
                    'STATUS' => 'new',
                    'RAW_DATA' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'GROUP_HASH' => $issue['group_hash'],
                    'CREATED_AT' => $now,
                    'UPDATED_AT' => $now,
                ]);
                if (!$result->isSuccess()) {
                    throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
                }
            } catch (\Throwable $e) {
                ErrorLogTable::add([
                    'IMPORT_ID' => $importId,
                    'ISSUE_ID' => null,
                    'LEVEL' => 'error',
                    'MESSAGE_TEXT' => 'Строка отчёта не была разобрана: ' . $e->getMessage(),
                    'CONTEXT_JSON' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'CREATED_AT' => $now,
                ]);
            }
        }
    }
}
