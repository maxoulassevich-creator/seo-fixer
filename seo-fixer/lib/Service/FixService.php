<?php
namespace Relod\SeoFixer\Service;

use Bitrix\Main\Type\DateTime;
use Relod\SeoFixer\Model\IssueTable;
use Relod\SeoFixer\Model\ChangeTable;
use Relod\SeoFixer\Model\ErrorLogTable;

class FixService
{
    public function approve(array $issueIds): void
    {
        foreach ($this->normalizeIds($issueIds) as $id) {
            IssueTable::update($id, ['STATUS' => 'approved', 'UPDATED_AT' => new DateTime()]);
        }
    }

    public function skip(array $issueIds): void
    {
        foreach ($this->normalizeIds($issueIds) as $id) {
            IssueTable::update($id, ['STATUS' => 'skipped', 'UPDATED_AT' => new DateTime()]);
        }
    }

    public function saveProposals(array $values, array $issueIds = []): int
    {
        $ids = $this->normalizeIds($issueIds ?: array_keys($values));
        if (!$ids) {
            return 0;
        }
        $saved = 0;
        $now = new DateTime();
        $suggestions = new IssueSuggestionService();
        foreach ($ids as $id) {
            if (!array_key_exists($id, $values)) {
                continue;
            }
            $issue = IssueTable::getById($id)->fetch();
            if (!$issue) {
                continue;
            }
            $newValue = trim((string)$values[$id]);
            $built = $suggestions->refreshIssueFields(array_merge($issue, ['NEW_VALUE' => $newValue]));
            IssueTable::update($id, [
                'NEW_VALUE' => $newValue,
                'RISK_LEVEL' => $built['risk_level'],
                'PLACE_HINT' => $built['place_hint'],
                'UPDATED_AT' => $now,
            ]);
            $saved++;
        }
        return $saved;
    }

    public function applyApproved(int $importId, int $userId, int $limit = 50): array
    {
        $result = ['applied' => 0, 'failed' => 0, 'manual' => 0];
        $filter = ['=STATUS' => 'approved'];
        if ($importId > 0) {
            $filter['=IMPORT_ID'] = $importId;
        }

        $rows = IssueTable::getList([
            'filter' => $filter,
            'limit' => max(1, min(200, $limit)),
            'order' => ['ID' => 'ASC'],
        ]);

        while ($issue = $rows->fetch()) {
            $apply = $this->applyIssue($issue, $userId);
            if ($apply === 'applied') {
                $result['applied']++;
            } elseif ($apply === 'manual') {
                $result['manual']++;
            } else {
                $result['failed']++;
            }
        }
        return $result;
    }

    public function applyIssue(array $issue, int $userId): string
    {
        $now = new DateTime();
        if (!IssuePresenter::canTryAutoFix($issue)) {
            IssueTable::update((int)$issue['ID'], ['STATUS' => 'manual', 'UPDATED_AT' => $now]);
            $this->logManual($issue, $now, 'Пункт не изменён автоматически: нет безопасной точной замены. ' . IssuePresenter::manualInstruction($issue));
            return 'manual';
        }

        $scanner = new StorageScanner();
        $occurrences = $scanner->findOccurrences((string)$issue['OLD_VALUE'], 30, true);
        if (!$occurrences) {
            IssueTable::update((int)$issue['ID'], ['STATUS' => 'failed', 'UPDATED_AT' => $now]);
            ErrorLogTable::add([
                'IMPORT_ID' => (int)$issue['IMPORT_ID'],
                'ISSUE_ID' => (int)$issue['ID'],
                'LEVEL' => 'error',
                'MESSAGE_TEXT' => 'Не удалось найти точное старое значение в разрешённых полях Битрикс или шаблонных файлах. Возможно, значение генерируется компонентом, SEO-шаблоном, меню или уже изменено.',
                'CONTEXT_JSON' => json_encode([
                    'old_value' => $issue['OLD_VALUE'],
                    'new_value' => $issue['NEW_VALUE'],
                    'instruction' => IssuePresenter::manualInstruction($issue),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'CREATED_AT' => $now,
            ]);
            return 'failed';
        }

        $ok = 0;
        $manual = 0;
        foreach ($occurrences as $occurrence) {
            $res = $scanner->updateField(
                (string)$occurrence['entity_type'],
                (int)$occurrence['entity_id'],
                (string)$occurrence['field_name'],
                (string)$issue['OLD_VALUE'],
                (string)$issue['NEW_VALUE']
            );

            ChangeTable::add([
                'ISSUE_ID' => (int)$issue['ID'],
                'IMPORT_ID' => (int)$issue['IMPORT_ID'],
                'ENTITY_TYPE' => (string)$occurrence['entity_type'],
                'ENTITY_ID' => (int)$occurrence['entity_id'],
                'FIELD_NAME' => (string)$occurrence['field_name'],
                'OLD_VALUE' => $res['old_value'] ?? ($occurrence['value'] ?? ''),
                'NEW_VALUE' => $res['new_value'] ?? null,
                'STATUS' => !empty($res['success']) ? 'applied' : 'failed',
                'MESSAGE_TEXT' => (string)$res['message'],
                'APPLIED_BY' => $userId,
                'APPLIED_AT' => $now,
            ]);

            if (!empty($res['success'])) {
                $ok++;
                continue;
            }

            if (($occurrence['entity_type'] ?? '') === 'template_file') {
                $manual++;
            }
            ErrorLogTable::add([
                'IMPORT_ID' => (int)$issue['IMPORT_ID'],
                'ISSUE_ID' => (int)$issue['ID'],
                'LEVEL' => (($occurrence['entity_type'] ?? '') === 'template_file') ? 'notice' : 'error',
                'MESSAGE_TEXT' => (string)$res['message'],
                'CONTEXT_JSON' => json_encode($occurrence + ['instruction' => IssuePresenter::manualInstruction($issue)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'CREATED_AT' => $now,
            ]);
        }

        if ($ok > 0) {
            IssueTable::update((int)$issue['ID'], ['STATUS' => 'applied', 'UPDATED_AT' => $now]);
            return 'applied';
        }

        $status = $manual > 0 ? 'manual' : 'failed';
        IssueTable::update((int)$issue['ID'], ['STATUS' => $status, 'UPDATED_AT' => $now]);
        return $status;
    }

    private function logManual(array $issue, DateTime $now, string $message): void
    {
        $scanner = new StorageScanner();
        $hints = [];
        if (trim((string)($issue['OLD_VALUE'] ?? '')) !== '') {
            $hints = $scanner->findTemplateHints((string)$issue['OLD_VALUE'], 10);
        }
        ErrorLogTable::add([
            'IMPORT_ID' => (int)$issue['IMPORT_ID'],
            'ISSUE_ID' => (int)$issue['ID'],
            'LEVEL' => 'notice',
            'MESSAGE_TEXT' => $message,
            'CONTEXT_JSON' => json_encode(['issue' => $issue, 'template_hints' => $hints], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'CREATED_AT' => $now,
        ]);
    }

    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}
