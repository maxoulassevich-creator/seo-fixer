<?php
namespace Relod\SeoFixer\Service;

use Bitrix\Main\Application;
use Relod\SeoFixer\Model\ImportTable;

class MaintenanceService
{
    public function deleteImports(array $importIds, bool $deleteFiles = true): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $importIds))));
        if (!$ids) {
            return ['imports' => 0, 'issues' => 0, 'changes' => 0, 'errors' => 0, 'files' => 0];
        }

        $filesDeleted = 0;
        if ($deleteFiles) {
            $filesDeleted = $this->deletePhysicalFiles($ids);
        }

        $connection = Application::getConnection();
        $idSql = implode(',', $ids);
        $counts = [
            'imports' => count($ids),
            'issues' => $this->countRows('b_relod_seofixer_issue', 'IMPORT_ID IN (' . $idSql . ')'),
            'changes' => $this->countRows('b_relod_seofixer_change', 'IMPORT_ID IN (' . $idSql . ')'),
            'errors' => $this->countRows('b_relod_seofixer_error_log', 'IMPORT_ID IN (' . $idSql . ')'),
            'files' => $filesDeleted,
        ];

        $connection->queryExecute('DELETE FROM b_relod_seofixer_error_log WHERE IMPORT_ID IN (' . $idSql . ')');
        $connection->queryExecute('DELETE FROM b_relod_seofixer_change WHERE IMPORT_ID IN (' . $idSql . ')');
        $connection->queryExecute('DELETE FROM b_relod_seofixer_issue WHERE IMPORT_ID IN (' . $idSql . ')');
        $connection->queryExecute('DELETE FROM b_relod_seofixer_import WHERE ID IN (' . $idSql . ')');

        return $counts;
    }

    public function clearIssues(int $importId = 0, array $issueIds = []): array
    {
        $connection = Application::getConnection();
        $where = '';
        if ($issueIds) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $issueIds))));
            if (!$ids) {
                return ['issues' => 0, 'changes' => 0, 'errors' => 0];
            }
            $where = 'ID IN (' . implode(',', $ids) . ')';
        } elseif ($importId > 0) {
            $where = 'IMPORT_ID=' . (int)$importId;
        } else {
            $where = '1=1';
        }

        $issueIdRows = $connection->query('SELECT ID FROM b_relod_seofixer_issue WHERE ' . $where);
        $ids = [];
        while ($row = $issueIdRows->fetch()) {
            $ids[] = (int)$row['ID'];
        }
        if (!$ids) {
            return ['issues' => 0, 'changes' => 0, 'errors' => 0];
        }

        $idSql = implode(',', $ids);
        $counts = [
            'issues' => count($ids),
            'changes' => $this->countRows('b_relod_seofixer_change', 'ISSUE_ID IN (' . $idSql . ')'),
            'errors' => $this->countRows('b_relod_seofixer_error_log', 'ISSUE_ID IN (' . $idSql . ')'),
        ];

        $connection->queryExecute('DELETE FROM b_relod_seofixer_error_log WHERE ISSUE_ID IN (' . $idSql . ')');
        $connection->queryExecute('DELETE FROM b_relod_seofixer_change WHERE ISSUE_ID IN (' . $idSql . ')');
        $connection->queryExecute('DELETE FROM b_relod_seofixer_issue WHERE ID IN (' . $idSql . ')');

        return $counts;
    }

    public function clearLogs(): array
    {
        $connection = Application::getConnection();
        $counts = [
            'changes' => $this->countRows('b_relod_seofixer_change', '1=1'),
            'errors' => $this->countRows('b_relod_seofixer_error_log', '1=1'),
        ];
        $connection->queryExecute('DELETE FROM b_relod_seofixer_error_log');
        $connection->queryExecute('DELETE FROM b_relod_seofixer_change');
        return $counts;
    }

    public function clearAll(bool $deleteFiles = true): array
    {
        $ids = [];
        $imports = ImportTable::getList(['select' => ['ID'], 'order' => ['ID' => 'ASC']]);
        while ($import = $imports->fetch()) {
            $ids[] = (int)$import['ID'];
        }
        if (!$ids) {
            $logs = $this->clearLogs();
            return ['imports' => 0, 'issues' => 0, 'changes' => $logs['changes'], 'errors' => $logs['errors'], 'files' => 0];
        }
        $result = $this->deleteImports($ids, $deleteFiles);
        $leftoverLogs = $this->clearLogs();
        $result['changes'] += $leftoverLogs['changes'];
        $result['errors'] += $leftoverLogs['errors'];
        return $result;
    }

    private function deletePhysicalFiles(array $importIds): int
    {
        $deleted = 0;
        $docRoot = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/');
        $res = ImportTable::getList(['filter' => ['@ID' => $importIds], 'select' => ['FILE_NAME']]);
        while ($import = $res->fetch()) {
            $fileName = basename((string)$import['FILE_NAME']);
            if ($fileName === '') {
                continue;
            }
            $path = $docRoot . '/upload/relod_seofixer/' . $fileName;
            $realDir = realpath($docRoot . '/upload/relod_seofixer');
            $realFile = realpath($path);
            if ($realDir && $realFile && strpos($realFile, $realDir) === 0 && is_file($realFile) && @unlink($realFile)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    private function countRows(string $table, string $where): int
    {
        $connection = Application::getConnection();
        if (!$connection->isTableExists($table)) {
            return 0;
        }
        $row = $connection->query('SELECT COUNT(*) CNT FROM ' . $table . ' WHERE ' . $where)->fetch();
        return (int)($row['CNT'] ?? 0);
    }
}
