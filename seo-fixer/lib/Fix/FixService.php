<?php
namespace Relod\SeoFixer\Fix;

use Bitrix\Main\Type\DateTime;
use Relod\SeoFixer\Model\ChangeTable;
use Relod\SeoFixer\Model\ErrorLogTable;
use Relod\SeoFixer\Model\IssueTable;
use Relod\SeoFixer\Report\ReportCatalog;
use Relod\SeoFixer\Service\IssuePresenter;
use Relod\SeoFixer\Service\StorageScanner;
use Relod\SeoFixer\Site\LivePageInspector;
use Relod\SeoFixer\Site\SiteContext;
use Relod\SeoFixer\Target\SeoWriter;
use Relod\SeoFixer\Target\UrlResolver;

/**
 * Применение подтверждённых исправлений.
 *
 * Порядок для каждой карточки:
 *  1. проверить, что администратор её подтвердил;
 *  2. открыть страницу вживую и убедиться, что проблема ещё есть;
 *  3. найти место хранения значения в Битриксе;
 *  4. сохранить старое значение;
 *  5. записать новое и перепроверить результат.
 *
 * Любой сбой на любом шаге — карточка не меняется, причина пишется в журнал.
 */
class FixService
{
    /** @var UrlResolver */
    private $resolver;

    /** @var SeoWriter */
    private $writer;

    /** @var LivePageInspector */
    private $inspector;

    public function __construct()
    {
        $this->resolver = new UrlResolver();
        $this->writer = new SeoWriter();
        $this->inspector = new LivePageInspector();
    }

    // -----------------------------------------------------------------
    // Массовые операции со статусами
    // -----------------------------------------------------------------

    public function approve(array $issueIds): int
    {
        return $this->setStatus($issueIds, 'approved');
    }

    public function skip(array $issueIds): int
    {
        return $this->setStatus($issueIds, 'skipped');
    }

    public function reopen(array $issueIds): int
    {
        return $this->setStatus($issueIds, 'new');
    }

    private function setStatus(array $issueIds, string $status): int
    {
        $count = 0;
        $now = new DateTime();
        foreach ($this->normalizeIds($issueIds) as $id) {
            $result = IssueTable::update($id, ['STATUS' => $status, 'UPDATED_AT' => $now]);
            if ($result->isSuccess()) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Подтверждает все карточки, подходящие под фильтр, у которых есть
     * заполненное предложение. Нужен для массового подтверждения дубликатов.
     *
     * @return array{approved:int,without_value:int}
     */
    public function approveByFilter(array $filter, int $limit = 1000): array
    {
        $stats = ['approved' => 0, 'without_value' => 0];
        $rows = IssueTable::getList([
            'filter' => $filter,
            'select' => ['ID', 'NEW_VALUE', 'STATUS'],
            'order' => ['PRIORITY' => 'DESC', 'ID' => 'ASC'],
            'limit' => max(1, min(5000, $limit)),
        ]);
        $now = new DateTime();
        while ($issue = $rows->fetch()) {
            if (trim((string)$issue['NEW_VALUE']) === '') {
                $stats['without_value']++;
                continue;
            }
            if (in_array((string)$issue['STATUS'], ['applied', 'resolved'], true)) {
                continue;
            }
            IssueTable::update((int)$issue['ID'], ['STATUS' => 'approved', 'UPDATED_AT' => $now]);
            $stats['approved']++;
        }
        return $stats;
    }

    /**
     * Сохраняет отредактированные администратором предложения.
     */
    public function saveProposals(array $values): int
    {
        $saved = 0;
        $now = new DateTime();
        foreach ($values as $rawId => $rawValue) {
            $id = (int)$rawId;
            if ($id <= 0) {
                continue;
            }
            $issue = IssueTable::getById($id)->fetch();
            if (!$issue) {
                continue;
            }
            $newValue = trim((string)$rawValue);
            if ($newValue === (string)$issue['NEW_VALUE']) {
                continue;
            }
            $result = IssueTable::update($id, [
                'NEW_VALUE' => $newValue,
                'SUGGESTION_NOTE' => $newValue !== '' ? 'Значение отредактировано администратором.' : '',
                'CONFIDENCE' => $newValue !== '' ? 100 : 0,
                'UPDATED_AT' => $now,
            ]);
            if ($result->isSuccess()) {
                $saved++;
            } else {
                ErrorLogTable::add([
                    'IMPORT_ID' => (int)$issue['IMPORT_ID'],
                    'ISSUE_ID' => $id,
                    'SITE_ID' => (string)$issue['SITE_ID'],
                    'LEVEL' => 'error',
                    'MESSAGE_TEXT' => 'Не удалось сохранить предложение: ' . implode('; ', $result->getErrorMessages()),
                    'CONTEXT_JSON' => json_encode(['value' => $newValue], JSON_UNESCAPED_UNICODE),
                    'CREATED_AT' => $now,
                ]);
            }
        }
        return $saved;
    }

    // -----------------------------------------------------------------
    // Применение
    // -----------------------------------------------------------------

    /**
     * @return array{applied:int,failed:int,manual:int,resolved:int,skipped:int}
     */
    public function applyApproved(array $filter, int $userId, int $limit = 50): array
    {
        $result = ['applied' => 0, 'failed' => 0, 'manual' => 0, 'resolved' => 0, 'skipped' => 0];

        $filter['=STATUS'] = 'approved';
        $rows = IssueTable::getList([
            'filter' => $filter,
            'order' => ['PRIORITY' => 'DESC', 'ID' => 'ASC'],
            'limit' => max(1, min(500, $limit)),
        ]);

        $issues = [];
        while ($issue = $rows->fetch()) {
            $issues[] = $issue;
        }

        foreach ($issues as $issue) {
            $outcome = $this->applyIssue($issue, $userId);
            if (!isset($result[$outcome])) {
                $result[$outcome] = 0;
            }
            $result[$outcome]++;
        }

        return $result;
    }

    /**
     * @return string applied | failed | manual | resolved | skipped
     */
    public function applyIssue(array $issue, int $userId): string
    {
        $now = new DateTime();
        $issueId = (int)$issue['ID'];
        $type = (string)$issue['ISSUE_TYPE'];
        $strategy = ReportCatalog::strategy($type);
        $siteId = (string)(($issue['SITE_ID'] ?? '') ?: SiteContext::defaultSiteId());
        $newValue = trim((string)$issue['NEW_VALUE']);

        if ($newValue === '') {
            return $this->markManual($issue, $now, 'Нет значения для замены. Заполните поле «Предлагается» и подтвердите пункт заново.');
        }

        if (!ReportCatalog::isAuto($type)) {
            return $this->markManual($issue, $now, IssuePresenter::manualInstruction($issue));
        }

        switch ($strategy) {
            case ReportCatalog::STRATEGY_SEO_TITLE:
            case ReportCatalog::STRATEGY_SEO_DESCRIPTION:
            case ReportCatalog::STRATEGY_SEO_H1:
                return $this->applySeoField($issue, $siteId, $newValue, $userId, $now);

            case ReportCatalog::STRATEGY_LINK_REPLACE:
                return $this->applyLinkReplace($issue, $newValue, $userId, $now);

            case ReportCatalog::STRATEGY_IMAGE_ALT:
                return $this->markManual($issue, $now, 'Атрибут alt задаётся у конкретной картинки в контенте или в описании файла. Черновик готов: ' . $newValue);

            default:
                return $this->markManual($issue, $now, IssuePresenter::manualInstruction($issue));
        }
    }

    /**
     * Правка meta title / description / H1.
     */
    private function applySeoField(array $issue, string $siteId, string $newValue, int $userId, DateTime $now): string
    {
        $issueId = (int)$issue['ID'];
        $type = (string)$issue['ISSUE_TYPE'];
        $field = ReportCatalog::valueField($type);
        if ($field === null) {
            return $this->markManual($issue, $now, 'Для этого типа проблемы не определено поле страницы.');
        }

        $url = trim((string)$issue['SOURCE_URL']);
        if ($url === '') {
            return $this->markManual($issue, $now, 'В карточке нет адреса страницы — модуль не знает, что править.');
        }

        // Живая проверка перед правкой: вдруг страница лежит или уже исправлена.
        $live = $this->inspector->inspect($url);
        if ($live['status'] >= 400 || $live['status'] === 0) {
            return $this->markFailed($issue, $now, 'Страница сейчас недоступна (код ' . $live['status'] . '). Правка отменена, чтобы не менять данные вслепую.');
        }
        $liveValue = $field === 'h1'
            ? (string)($live['h1'][0] ?? '')
            : (string)($live[$field] ?? '');
        if (trim($liveValue) === trim($newValue)) {
            IssueTable::update($issueId, ['STATUS' => 'resolved', 'LIVE_VALUE' => $liveValue, 'LIVE_VERDICT' => 'На сайте уже стоит нужное значение.', 'LIVE_CHECKED_AT' => $now, 'UPDATED_AT' => $now]);
            return 'resolved';
        }

        // Куда писать.
        $target = $this->resolver->resolve($url, $siteId);
        if ($target['type'] === UrlResolver::TARGET_NONE) {
            return $this->markManual(
                $issue,
                $now,
                'Не удалось определить, что в Битриксе отвечает за адрес ' . $url . '. ' . $target['note']
                . ' Готовое значение для ручной вставки: ' . $newValue
            );
        }

        $writeResult = $this->writer->write($target, $field, $newValue, (string)$issue['OLD_VALUE']);

        ChangeTable::add([
            'ISSUE_ID' => $issueId,
            'IMPORT_ID' => (int)$issue['IMPORT_ID'],
            'SITE_ID' => $siteId,
            'STRATEGY' => ReportCatalog::strategy($type),
            'ENTITY_TYPE' => (string)$target['type'],
            'ENTITY_ID' => (int)$target['entity_id'],
            'IBLOCK_ID' => (int)$target['iblock_id'],
            'FIELD_NAME' => $field,
            'TARGET_URL' => $url,
            'OLD_VALUE' => (string)$writeResult['old_value'],
            'NEW_VALUE' => (string)$writeResult['new_value'],
            'STATUS' => $writeResult['success'] ? 'applied' : 'failed',
            'MESSAGE_TEXT' => (string)$writeResult['message'],
            'APPLIED_BY' => $userId,
            'APPLIED_AT' => $now,
        ]);

        if (!$writeResult['success']) {
            return $this->markFailed($issue, $now, (string)$writeResult['message']);
        }

        IssueTable::update($issueId, [
            'STATUS' => 'applied',
            'TARGET_TYPE' => (string)$target['type'],
            'TARGET_IBLOCK_ID' => (int)$target['iblock_id'],
            'TARGET_ENTITY_ID' => (int)$target['entity_id'],
            'TARGET_FIELD' => $field,
            'TARGET_NOTE' => (string)$target['note'],
            'TARGET_ADMIN_URL' => (string)$target['admin_url'],
            'UPDATED_AT' => $now,
        ]);
        return 'applied';
    }

    /**
     * Точная замена адреса в текстах, свойствах и (по настройке) в шаблонах.
     */
    private function applyLinkReplace(array $issue, string $newValue, int $userId, DateTime $now): string
    {
        $issueId = (int)$issue['ID'];
        $oldValue = trim((string)$issue['OLD_VALUE']);
        if ($oldValue === '' || $oldValue === $newValue) {
            return $this->markManual($issue, $now, 'Старое и новое значения совпадают или пусты — заменять нечего.');
        }

        // Живая проверка: если адрес уже отвечает нормально, менять не нужно.
        $live = $this->inspector->inspect($oldValue);
        if ($live['status'] >= 200 && $live['status'] < 300 && empty($live['redirected'])) {
            IssueTable::update($issueId, [
                'STATUS' => 'resolved',
                'LIVE_STATUS' => (int)$live['status'],
                'LIVE_VERDICT' => 'Адрес уже отвечает ' . $live['status'] . ' без редиректа — замена не требуется.',
                'LIVE_CHECKED_AT' => $now,
                'UPDATED_AT' => $now,
            ]);
            return 'resolved';
        }

        $scanner = new StorageScanner();
        $occurrences = $scanner->findOccurrences($oldValue, 30);
        if (!$occurrences) {
            return $this->markManual(
                $issue,
                $now,
                'Точное совпадение адреса «' . $oldValue . '» не найдено ни в текстах, ни в свойствах, ни в файлах шаблона. '
                . 'Скорее всего, ссылку формирует компонент, меню или SEO-шаблон. Нужный адрес: ' . $newValue
            );
        }

        $applied = 0;
        $failed = 0;
        foreach ($occurrences as $occurrence) {
            $res = $scanner->replace($occurrence, $oldValue, $newValue);

            ChangeTable::add([
                'ISSUE_ID' => $issueId,
                'IMPORT_ID' => (int)$issue['IMPORT_ID'],
                'SITE_ID' => (string)$issue['SITE_ID'],
                'STRATEGY' => ReportCatalog::STRATEGY_LINK_REPLACE,
                'ENTITY_TYPE' => (string)$occurrence['entity_type'],
                'ENTITY_ID' => (int)$occurrence['entity_id'],
                'IBLOCK_ID' => 0,
                'FIELD_NAME' => (string)$occurrence['field_name'],
                'TARGET_URL' => $oldValue,
                'OLD_VALUE' => (string)($res['old_value'] ?? ''),
                'NEW_VALUE' => (string)($res['new_value'] ?? ''),
                'STATUS' => !empty($res['success']) ? 'applied' : 'failed',
                'MESSAGE_TEXT' => (string)$res['message'],
                'APPLIED_BY' => $userId,
                'APPLIED_AT' => $now,
            ]);

            if (!empty($res['success'])) {
                $applied++;
                continue;
            }
            $failed++;
            ErrorLogTable::add([
                'IMPORT_ID' => (int)$issue['IMPORT_ID'],
                'ISSUE_ID' => $issueId,
                'SITE_ID' => (string)$issue['SITE_ID'],
                'LEVEL' => (string)$occurrence['entity_type'] === 'template_file' ? 'notice' : 'error',
                'MESSAGE_TEXT' => (string)$res['message'],
                'CONTEXT_JSON' => json_encode($occurrence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'CREATED_AT' => $now,
            ]);
        }

        if ($applied > 0) {
            IssueTable::update($issueId, [
                'STATUS' => 'applied',
                'TARGET_NOTE' => 'Заменено вхождений: ' . $applied . ($failed ? ', пропущено: ' . $failed : ''),
                'UPDATED_AT' => $now,
            ]);
            return 'applied';
        }

        return $this->markFailed($issue, $now, 'Совпадения найдены, но ни одно не удалось заменить. Подробности — в журнале ошибок.');
    }

    // -----------------------------------------------------------------

    private function markManual(array $issue, DateTime $now, string $message): string
    {
        IssueTable::update((int)$issue['ID'], ['STATUS' => 'manual', 'UPDATED_AT' => $now]);
        ErrorLogTable::add([
            'IMPORT_ID' => (int)$issue['IMPORT_ID'],
            'ISSUE_ID' => (int)$issue['ID'],
            'SITE_ID' => (string)$issue['SITE_ID'],
            'LEVEL' => 'notice',
            'MESSAGE_TEXT' => $message,
            'CONTEXT_JSON' => json_encode([
                'url' => $issue['SOURCE_URL'],
                'old' => $issue['OLD_VALUE'],
                'new' => $issue['NEW_VALUE'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'CREATED_AT' => $now,
        ]);
        return 'manual';
    }

    private function markFailed(array $issue, DateTime $now, string $message): string
    {
        IssueTable::update((int)$issue['ID'], ['STATUS' => 'failed', 'UPDATED_AT' => $now]);
        ErrorLogTable::add([
            'IMPORT_ID' => (int)$issue['IMPORT_ID'],
            'ISSUE_ID' => (int)$issue['ID'],
            'SITE_ID' => (string)$issue['SITE_ID'],
            'LEVEL' => 'error',
            'MESSAGE_TEXT' => $message,
            'CONTEXT_JSON' => json_encode([
                'url' => $issue['SOURCE_URL'],
                'old' => $issue['OLD_VALUE'],
                'new' => $issue['NEW_VALUE'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'CREATED_AT' => $now,
        ]);
        return 'failed';
    }

    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}
