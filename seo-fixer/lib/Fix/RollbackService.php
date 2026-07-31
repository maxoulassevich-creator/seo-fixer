<?php
namespace Relod\SeoFixer\Fix;

use Bitrix\Main\Type\DateTime;
use Relod\SeoFixer\Model\ChangeTable;
use Relod\SeoFixer\Model\ErrorLogTable;
use Relod\SeoFixer\Model\IssueTable;
use Relod\SeoFixer\Report\ReportCatalog;
use Relod\SeoFixer\Service\StorageScanner;
use Relod\SeoFixer\Target\SeoWriter;
use Relod\SeoFixer\Target\UrlResolver;

/**
 * Откат применённых изменений. Работает на сохранённых старых значениях
 * из журнала изменений.
 */
class RollbackService
{
    /**
     * @return array{success:bool,message:string}
     */
    public function rollbackChange(int $changeId, int $userId): array
    {
        $change = ChangeTable::getById($changeId)->fetch();
        if (!$change) {
            return ['success' => false, 'message' => 'Запись изменения не найдена.'];
        }
        if ((string)$change['STATUS'] !== 'applied') {
            return ['success' => false, 'message' => 'Откат доступен только для успешно применённых изменений.'];
        }

        $strategy = (string)$change['STRATEGY'];
        $entityType = (string)$change['ENTITY_TYPE'];
        $oldValue = (string)$change['OLD_VALUE'];
        $newValue = (string)$change['NEW_VALUE'];

        if ($entityType === 'template_file') {
            return ['success' => false, 'message' => 'Правки в файлах шаблона откатываются вручную из резервной копии, которая лежит рядом с файлом.'];
        }

        $result = $this->isSeoStrategy($strategy)
            ? $this->rollbackSeoField($change, $oldValue)
            : $this->rollbackTextReplace($change, $oldValue, $newValue);

        $now = new DateTime();

        if ($result['success']) {
            ChangeTable::update($changeId, [
                'STATUS' => 'rolled_back',
                'ROLLED_BACK_BY' => $userId,
                'ROLLED_BACK_AT' => $now,
                'MESSAGE_TEXT' => 'Изменение откачено, прежнее значение восстановлено.',
            ]);
            $issueId = (int)$change['ISSUE_ID'];
            if ($issueId > 0) {
                IssueTable::update($issueId, ['STATUS' => 'new', 'UPDATED_AT' => $now]);
            }
            return ['success' => true, 'message' => 'Изменение откачено.'];
        }

        ErrorLogTable::add([
            'IMPORT_ID' => (int)$change['IMPORT_ID'],
            'ISSUE_ID' => (int)$change['ISSUE_ID'],
            'SITE_ID' => (string)$change['SITE_ID'],
            'LEVEL' => 'error',
            'MESSAGE_TEXT' => 'Откат не выполнен: ' . $result['message'],
            'CONTEXT_JSON' => json_encode($change, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'CREATED_AT' => $now,
        ]);
        return $result;
    }

    /**
     * Массовый откат — например, всех изменений одной загрузки.
     *
     * @return array{done:int,failed:int}
     */
    public function rollbackMany(array $changeIds, int $userId): array
    {
        $stats = ['done' => 0, 'failed' => 0];
        foreach (array_unique(array_map('intval', $changeIds)) as $id) {
            if ($id <= 0) {
                continue;
            }
            $res = $this->rollbackChange($id, $userId);
            $stats[$res['success'] ? 'done' : 'failed']++;
        }
        return $stats;
    }

    private function isSeoStrategy(string $strategy): bool
    {
        return in_array($strategy, [
            ReportCatalog::STRATEGY_SEO_TITLE,
            ReportCatalog::STRATEGY_SEO_DESCRIPTION,
            ReportCatalog::STRATEGY_SEO_H1,
        ], true);
    }

    private function rollbackSeoField(array $change, string $oldValue): array
    {
        $writer = new SeoWriter();
        $target = [
            'type' => (string)$change['ENTITY_TYPE'],
            'iblock_id' => (int)$change['IBLOCK_ID'],
            'entity_id' => (int)$change['ENTITY_ID'],
            'file' => (string)$change['FIELD_NAME'],
            'note' => 'откат изменения #' . (int)$change['ID'],
        ];

        // Для статической страницы путь к файлу лежит в MESSAGE_TEXT/FIELD_NAME,
        // поэтому восстанавливаем цель через резолвер по сохранённому адресу.
        if ($target['type'] === UrlResolver::TARGET_PAGE) {
            $resolver = new UrlResolver();
            $resolved = $resolver->resolve((string)$change['TARGET_URL'], (string)$change['SITE_ID']);
            if ($resolved['type'] !== UrlResolver::TARGET_PAGE) {
                return ['success' => false, 'message' => 'Не удалось найти файл страницы для отката.'];
            }
            $target = $resolved;
        }

        if ($oldValue === '') {
            return ['success' => false, 'message' => 'Прежнее значение было пустым — модуль не стирает поля автоматически. Очистите его вручную, если нужно.'];
        }

        $field = (string)$change['FIELD_NAME'];
        $res = $writer->write($target, $field, $oldValue, null);
        return ['success' => (bool)$res['success'], 'message' => (string)$res['message']];
    }

    private function rollbackTextReplace(array $change, string $oldValue, string $newValue): array
    {
        $scanner = new StorageScanner();
        $res = $scanner->restore(
            (string)$change['ENTITY_TYPE'],
            (int)$change['ENTITY_ID'],
            (string)$change['FIELD_NAME'],
            $oldValue
        );
        return ['success' => (bool)$res['success'], 'message' => (string)$res['message']];
    }
}
