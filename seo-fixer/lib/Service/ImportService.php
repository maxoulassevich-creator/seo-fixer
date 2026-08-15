<?php
namespace Relod\SeoFixer\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Relod\SeoFixer\Fix\SuggestionEngine;
use Relod\SeoFixer\Model\ErrorLogTable;
use Relod\SeoFixer\Model\ImportTable;
use Relod\SeoFixer\Model\IssueTable;
use Relod\SeoFixer\Report\CsvReader;
use Relod\SeoFixer\Report\ReportCatalog;
use Relod\SeoFixer\Report\ReportTypeDetector;
use Relod\SeoFixer\Report\RowMapper;
use Relod\SeoFixer\Report\XlsxReader;
use Relod\SeoFixer\Site\LivePageInspector;
use Relod\SeoFixer\Site\SiteContext;
use Relod\SeoFixer\Target\UrlResolver;

/**
 * Загрузка и разбор отчёта Netpeak.
 *
 * Отличия от версии 1.x:
 *  - сайт определяется автоматически по домену из имени файла;
 *  - тип отчёта берётся из структурного имени Netpeak, а не угадывается;
 *  - сводные отчёты («Сводка по ошибкам», «URL и их ошибки») разворачиваются
 *    в карточки конкретных типов;
 *  - повторная загрузка того же отчёта не плодит дубли карточек.
 */
class ImportService
{
    /** @var RowMapper */
    private $mapper;

    public function __construct()
    {
        $this->mapper = new RowMapper();
    }

    /**
     * Загрузка файла через форму админки.
     */
    public function importUploadedFile(array $file, int $userId, string $comment = '', string $forcedSiteId = ''): int
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException($this->uploadErrorText((int)($file['error'] ?? UPLOAD_ERR_NO_FILE)));
        }

        $originalName = (string)$file['name'];
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv'], true)) {
            throw new \RuntimeException('Поддерживаются только файлы XLSX и CSV. Выгрузите отчёт из Netpeak Spider в одном из этих форматов.');
        }

        $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/upload/relod_seofixer';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new \RuntimeException('Не удалось создать папку для загрузок: /upload/relod_seofixer');
        }

        $decoded = ReportTypeDetector::decodeNetpeakName($originalName);
        $safeName = date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9_.-]+/u', '_', $decoded);
        $targetPath = $uploadDir . '/' . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new \RuntimeException('Не удалось сохранить загруженный файл в /upload/relod_seofixer. Проверьте права на папку.');
        }

        return $this->importFile($targetPath, $originalName, $userId, $comment, $forcedSiteId);
    }

    /**
     * Разбор файла, уже лежащего на диске.
     *
     * @return int ID записи загрузки
     */
    public function importFile(string $filePath, string $originalName, int $userId, string $comment = '', string $forcedSiteId = ''): int
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $data = $ext === 'csv' ? (new CsvReader())->read($filePath) : (new XlsxReader())->read($filePath);

        $parsedName = ReportTypeDetector::parseFileName($originalName);
        $reportType = ReportTypeDetector::detect($originalName, $data['headers']);

        // Домен: из имени файла, иначе из первых адресов внутри отчёта.
        $sampleUrls = [];
        foreach (array_slice($data['rows'], 0, 20) as $row) {
            foreach ($row as $value) {
                if (is_string($value) && preg_match('~^https?://~i', $value)) {
                    $sampleUrls[] = $value;
                    break;
                }
            }
        }
        $siteInfo = SiteContext::resolveForReport($parsedName['domain'], $sampleUrls);
        $siteId = $forcedSiteId !== '' ? $forcedSiteId : $siteInfo['site_id'];
        $domain = (string)$siteInfo['domain'];

        $now = new DateTime();
        $importResult = ImportTable::add([
            'FILE_NAME' => basename($filePath),
            'ORIGINAL_NAME' => ReportTypeDetector::decodeNetpeakName($originalName),
            'SITE_ID' => $siteId,
            'DOMAIN' => $domain,
            'REPORT_TYPE' => $reportType,
            'REPORT_DATE' => (string)($parsedName['date'] ?? ''),
            'SEVERITY' => (string)($parsedName['severity'] ?? ReportCatalog::severity($reportType)),
            'ROWS_TOTAL' => count($data['rows']),
            'ISSUES_CREATED' => 0,
            'FILE_HASH' => (string)hash_file('sha256', $filePath),
            'STATUS' => 'parsed',
            'COMMENT_TEXT' => $comment,
            'IMPORTED_BY' => $userId,
            'IMPORTED_AT' => $now,
        ]);

        if (!$importResult->isSuccess()) {
            throw new \RuntimeException('Не удалось создать запись загрузки: ' . implode('; ', $importResult->getErrorMessages()));
        }

        $importId = (int)$importResult->getId();

        if (!$siteInfo['matched'] && $domain !== '') {
            ErrorLogTable::add([
                'IMPORT_ID' => $importId,
                'SITE_ID' => $siteId,
                'LEVEL' => 'notice',
                'MESSAGE_TEXT' => 'Домен отчёта «' . $domain . '» не совпал ни с одним сайтом в настройках Битрикса. Отчёт привязан к сайту «' . $siteId . '». Если это неверно, укажите сайт вручную при загрузке.',
                'CONTEXT_JSON' => json_encode(['domain' => $domain, 'sites' => array_keys(SiteContext::listSites())], JSON_UNESCAPED_UNICODE),
                'CREATED_AT' => $now,
            ]);
        }

        $created = $this->createIssues($importId, $siteId, $domain, $reportType, $data);

        ImportTable::update($importId, [
            'ISSUES_CREATED' => $created,
            'STATUS' => $created > 0 ? 'ready' : 'empty',
        ]);

        return $importId;
    }

    /**
     * Создаёт карточки проблем из строк отчёта.
     */
    private function createIssues(int $importId, string $siteId, string $domain, string $reportType, array $data): int
    {
        $now = new DateTime();
        $created = 0;
        $rows = $data['rows'];
        $groups = $data['groups'] ?? [];

        foreach ($rows as $index => $row) {
            $groupKey = (string)($groups[$index] ?? '');
            try {
                // Сводные отчёты разворачиваем в конкретные типы.
                $rowType = $reportType;
                if (ReportCatalog::expands($reportType)) {
                    $rowType = $this->resolveRowType($reportType, $row) ?: $reportType;
                }

                $mapped = $this->mapper->map($row, $rowType, $groupKey);
                $issue = $this->buildIssue($importId, $siteId, $domain, $reportType, $rowType, $mapped, $row, $now);
                if ($issue === null) {
                    continue;
                }

                // Дедупликация: та же проблема на том же адресе того же сайта.
                $existingId = $this->findExisting($siteId, (string)$issue['ISSUE_KEY']);
                if ($existingId > 0) {
                    IssueTable::update($existingId, [
                        'IMPORT_ID' => $importId,
                        'RAW_DATA' => $issue['RAW_DATA'],
                        'OLD_VALUE' => $issue['OLD_VALUE'],
                        'FINAL_URL' => $issue['FINAL_URL'],
                        'UPDATED_AT' => $now,
                    ]);
                    continue;
                }

                $result = IssueTable::add($issue);
                if (!$result->isSuccess()) {
                    throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
                }
                $created++;
            } catch (\Throwable $e) {
                ErrorLogTable::add([
                    'IMPORT_ID' => $importId,
                    'SITE_ID' => $siteId,
                    'LEVEL' => 'error',
                    'MESSAGE_TEXT' => 'Строку отчёта не удалось разобрать: ' . $e->getMessage(),
                    'CONTEXT_JSON' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'CREATED_AT' => $now,
                ]);
            }
        }

        return $created;
    }

    /**
     * Для сводных отчётов определяет тип по колонке с названием проблемы.
     */
    private function resolveRowType(string $reportType, array $row): ?string
    {
        $mapped = $this->mapper->map($row, $reportType, '');
        $name = $mapped['issue'] !== '' ? $mapped['issue'] : $mapped['issue_name'];
        if ($name === '') {
            return null;
        }
        return ReportTypeDetector::matchIssueName($name);
    }

    /**
     * Собирает поля карточки.
     *
     * @return array<string,mixed>|null
     */
    private function buildIssue(int $importId, string $siteId, string $domain, string $reportType, string $rowType, array $mapped, array $rawRow, DateTime $now): ?array
    {
        $meta = ReportCatalog::get($rowType);
        $strategy = (string)$meta['strategy'];

        $sourceUrl = $this->absolute($mapped['page_url'], $siteId, $domain);
        $targetUrl = $this->absolute($mapped['link_url'], $siteId, $domain);
        $finalUrl = $this->absolute($mapped['final_url'], $siteId, $domain);

        // Сводка по ошибкам: строки без адреса — это обзор, а не задача.
        $isOverviewRow = $reportType === 'issue_overview';
        if (!$isOverviewRow && $sourceUrl === '' && $targetUrl === '') {
            return null;
        }

        $oldValue = $this->detectOldValue($rowType, $mapped, $targetUrl, $sourceUrl);

        $groupBase = ReportCatalog::isGrouped($rowType)
            ? $rowType . '|' . $this->normalizeForHash($oldValue)
            : $rowType . '|' . $this->normalizeForHash($sourceUrl . '|' . $targetUrl . '|' . $oldValue);

        $issueKey = hash('sha256', implode('|', [
            $siteId,
            $rowType,
            $this->normalizeForHash($sourceUrl),
            $this->normalizeForHash($targetUrl),
            $isOverviewRow ? 'overview' : '',
        ]));

        $note = '';
        if ($isOverviewRow) {
            $parts = array_filter([
                $mapped['info'] !== '' ? 'Что это: ' . $mapped['info'] : '',
                $mapped['threat'] !== '' ? 'Чем грозит: ' . $mapped['threat'] : '',
                $mapped['how_to_fix'] !== '' ? 'Как чинить: ' . $mapped['how_to_fix'] : '',
            ]);
            $note = implode(' ', $parts);
        }

        return [
            'IMPORT_ID' => $importId,
            'SITE_ID' => $siteId,
            'ISSUE_TYPE' => $rowType,
            'SEVERITY' => $mapped['issue_severity'] !== '' ? $this->normalizeSeverity($mapped['issue_severity']) : (string)$meta['severity'],
            'CATEGORY' => (string)$meta['category'],
            'PRIORITY' => (int)$meta['priority'],
            'STRATEGY' => $strategy,
            'SOURCE_URL' => $sourceUrl,
            'TARGET_URL' => $targetUrl,
            'FINAL_URL' => $finalUrl,
            'ANCHOR_TEXT' => $mapped['anchor'],
            'OLD_VALUE' => $oldValue,
            'NEW_VALUE' => '',
            'SUGGESTION_NOTE' => $note,
            'CONFIDENCE' => 0,
            'TARGET_TYPE' => '',
            'TARGET_IBLOCK_ID' => 0,
            'TARGET_ENTITY_ID' => 0,
            'TARGET_FIELD' => (string)(ReportCatalog::valueField($rowType) ?? ''),
            'TARGET_NOTE' => '',
            'TARGET_ADMIN_URL' => '',
            'LIVE_STATUS' => 0,
            'LIVE_VALUE' => '',
            'LIVE_VERDICT' => '',
            'RISK_LEVEL' => $this->riskLevel($strategy),
            'STATUS' => $isOverviewRow ? 'info' : 'new',
            'RAW_DATA' => json_encode($rawRow, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'GROUP_HASH' => hash('sha256', $groupBase),
            'ISSUE_KEY' => $issueKey,
            'CREATED_AT' => $now,
            'UPDATED_AT' => $now,
        ];
    }

    /**
     * Что именно считается «текущим значением» для этого типа.
     */
    private function detectOldValue(string $type, array $mapped, string $targetUrl, string $sourceUrl): string
    {
        $field = ReportCatalog::valueField($type);
        if ($field !== null && ($mapped[$field] ?? '') !== '') {
            return (string)$mapped[$field];
        }
        if (ReportCatalog::strategy($type) === ReportCatalog::STRATEGY_LINK_REPLACE) {
            return $targetUrl !== '' ? $targetUrl : $sourceUrl;
        }
        if ($type === 'issue_overview' || $mapped['issue_urls'] !== '') {
            return (string)$mapped['issue_urls'];
        }
        if ($mapped['status_code'] !== '') {
            return (string)$mapped['status_code'];
        }
        return $sourceUrl !== '' ? $sourceUrl : $targetUrl;
    }

    private function riskLevel(string $strategy): string
    {
        switch ($strategy) {
            case ReportCatalog::STRATEGY_LINK_REPLACE:
                return 'low';
            case ReportCatalog::STRATEGY_SEO_TITLE:
            case ReportCatalog::STRATEGY_SEO_DESCRIPTION:
            case ReportCatalog::STRATEGY_SEO_H1:
            case ReportCatalog::STRATEGY_IMAGE_ALT:
                return 'medium';
            case ReportCatalog::STRATEGY_TEMPLATE:
                return 'template';
            case ReportCatalog::STRATEGY_SERVER:
            case ReportCatalog::STRATEGY_ROBOTS:
                return 'infra';
            case ReportCatalog::STRATEGY_INFO:
                return 'info';
            default:
                return 'review';
        }
    }

    private function findExisting(string $siteId, string $issueKey): int
    {
        if ($issueKey === '') {
            return 0;
        }
        $row = IssueTable::getList([
            'filter' => ['=SITE_ID' => $siteId, '=ISSUE_KEY' => $issueKey],
            'select' => ['ID'],
            'limit' => 1,
        ])->fetch();
        return $row ? (int)$row['ID'] : 0;
    }

    private function absolute(string $url, string $siteId, string $domain): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        return SiteContext::absoluteUrl($url, $siteId, $domain);
    }

    private function normalizeForHash(string $value): string
    {
        $value = trim($value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        return (string)preg_replace('~\s+~u', ' ', $value);
    }

    private function normalizeSeverity(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $map = [
            'error' => 'error', 'ошибка' => 'error',
            'warning' => 'warning', 'предупреждение' => 'warning',
            'notice' => 'notice', 'замечание' => 'notice',
        ];
        return $map[$value] ?? 'notice';
    }

    // -----------------------------------------------------------------
    // Обогащение карточек: цель правки, живая проверка, предложения
    // -----------------------------------------------------------------

    /**
     * Определяет для каждой карточки, что в Битриксе отвечает за адрес,
     * проверяет страницу вживую и готовит предложение.
     *
     * @return array{processed:int,targeted:int,suggested:int,resolved:int}
     */
    public function enrich(array $filter, int $limit = 100, bool $useLive = true): array
    {
        $stats = ['processed' => 0, 'targeted' => 0, 'suggested' => 0, 'resolved' => 0, 'failed' => 0];

        $rows = IssueTable::getList([
            'filter' => $filter,
            'order' => ['PRIORITY' => 'DESC', 'ID' => 'ASC'],
            'limit' => max(1, min(1000, $limit)),
        ]);

        $resolver = new UrlResolver();
        $inspector = new LivePageInspector();
        $now = new DateTime();

        while ($issue = $rows->fetch()) {
            $stats['processed']++;
            try {
            $siteId = (string)(($issue['SITE_ID'] ?? '') ?: SiteContext::defaultSiteId());
            $update = ['UPDATED_AT' => $now];

            // 1. Куда писать правку.
            $target = [];
            $url = trim((string)$issue['SOURCE_URL']);
            if ($url !== '') {
                $target = $resolver->resolve($url, $siteId);
                $update['TARGET_TYPE'] = (string)$target['type'];
                $update['TARGET_IBLOCK_ID'] = (int)$target['iblock_id'];
                $update['TARGET_ENTITY_ID'] = (int)$target['entity_id'];
                $update['TARGET_NOTE'] = (string)$target['note'];
                $update['TARGET_ADMIN_URL'] = (string)$target['admin_url'];
                if ($target['type'] !== UrlResolver::TARGET_NONE) {
                    $stats['targeted']++;
                }
            }

            // 2. Что на сайте прямо сейчас.
            $live = [];
            if ($useLive && $url !== '') {
                $live = $inspector->inspect($url);
                $update['LIVE_STATUS'] = (int)$live['status'];
                $update['LIVE_CHECKED_AT'] = $now;
            }

            // 3. Конкретное предложение.
            $engine = new SuggestionEngine($siteId);
            $suggestion = $engine->suggest($issue, $live, $target);
            if ($suggestion['value'] !== null && trim((string)$suggestion['value']) !== '') {
                if (trim((string)$issue['NEW_VALUE']) === '') {
                    $update['NEW_VALUE'] = (string)$suggestion['value'];
                    $stats['suggested']++;
                }
                $update['SUGGESTION_NOTE'] = (string)$suggestion['explanation'];
                $update['CONFIDENCE'] = (int)$suggestion['confidence'];
            }

            IssueTable::update((int)$issue['ID'], $update);
            } catch (\Throwable $e) {
                // Одна проблемная страница не должна ронять разбор всего отчёта.
                $stats['failed']++;
                ErrorLogTable::add([
                    'IMPORT_ID' => (int)$issue['IMPORT_ID'],
                    'ISSUE_ID' => (int)$issue['ID'],
                    'SITE_ID' => (string)($issue['SITE_ID'] ?? ''),
                    'LEVEL' => 'error',
                    'MESSAGE_TEXT' => 'Не удалось подготовить решение для этой карточки: ' . $e->getMessage(),
                    'CONTEXT_JSON' => json_encode(['url' => $issue['SOURCE_URL'] ?? ''], JSON_UNESCAPED_UNICODE),
                    'CREATED_AT' => $now,
                ]);
            }
        }

        return $stats;
    }

    /**
     * Пересчёт предложений для выбранных карточек (кнопка в интерфейсе).
     */
    public function refreshSuggestions(int $importId = 0, array $issueIds = [], bool $useLive = true): int
    {
        $filter = [];
        if ($importId > 0) {
            $filter['=IMPORT_ID'] = $importId;
        }
        if ($issueIds) {
            $filter['@ID'] = array_values(array_unique(array_map('intval', $issueIds)));
        }
        $stats = $this->enrich($filter, 500, $useLive);
        return (int)$stats['processed'];
    }

    private function uploadErrorText(int $code): string
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'Файл больше разрешённого размера. Увеличьте upload_max_filesize и post_max_size в настройках PHP.';
            case UPLOAD_ERR_PARTIAL:
                return 'Файл передан не полностью. Повторите загрузку.';
            case UPLOAD_ERR_NO_FILE:
                return 'Файл не выбран.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'На сервере не настроена временная папка для загрузок.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Сервер не смог записать файл на диск.';
            default:
                return 'Файл не загружен (код ошибки ' . $code . ').';
        }
    }
}
