<?php
namespace Relod\SeoFixer\Fix;

use Bitrix\Main\Type\DateTime;
use Relod\SeoFixer\Model\IssueTable;
use Relod\SeoFixer\Report\ReportCatalog;
use Relod\SeoFixer\Site\LivePageInspector;
use Relod\SeoFixer\Site\PageFacts;
use Relod\SeoFixer\Target\UrlResolver;

/**
 * Разбор дубликатов title / description / H1.
 *
 * Это самый частый и самый важный класс проблем, поэтому он вынесен
 * отдельно: модуль строит для каждой страницы уникальное значение и
 * гарантирует, что внутри группы совпадений больше не останется.
 */
class DuplicateResolver
{
    /** @var LivePageInspector */
    private $inspector;

    /** @var UrlResolver */
    private $resolver;

    public function __construct(?LivePageInspector $inspector = null, ?UrlResolver $resolver = null)
    {
        $this->inspector = $inspector ?: new LivePageInspector();
        $this->resolver = $resolver ?: new UrlResolver();
    }

    /**
     * Генерирует уникальные значения для группы дубликатов и сохраняет
     * их в поле «Предлагается».
     *
     * @param array $filter Фильтр IssueTable (например, по IMPORT_ID или GROUP_HASH).
     * @param int   $limit  Максимум карточек за один проход.
     * @param bool  $useLive Опрашивать ли сайт (медленнее, но точнее).
     * @return array{processed:int,filled:int,skipped:int,collisions:int}
     */
    public function generate(array $filter, int $limit = 200, bool $useLive = true): array
    {
        $stats = ['processed' => 0, 'filled' => 0, 'skipped' => 0, 'collisions' => 0];

        $rows = IssueTable::getList([
            'filter' => $filter,
            'order' => ['GROUP_HASH' => 'ASC', 'SOURCE_URL' => 'ASC', 'ID' => 'ASC'],
            'limit' => max(1, min(1000, $limit)),
        ]);

        $issues = [];
        while ($issue = $rows->fetch()) {
            $issues[] = $issue;
        }
        if (!$issues) {
            return $stats;
        }

        // Уже занятые значения, чтобы не создать новые дубликаты.
        $taken = $this->collectTakenValues($issues);
        $now = new DateTime();

        foreach ($issues as $issue) {
            $stats['processed']++;
            $type = (string)$issue['ISSUE_TYPE'];
            $field = ReportCatalog::valueField($type);
            if ($field === null) {
                $stats['skipped']++;
                continue;
            }

            $siteId = (string)(($issue['SITE_ID'] ?? '') ?: 's1');
            $engine = new SuggestionEngine($siteId);

            $live = [];
            if ($useLive) {
                $url = trim((string)$issue['SOURCE_URL']);
                if ($url !== '') {
                    $live = $this->inspector->inspect($url);
                }
            }

            $target = [];
            $url = trim((string)$issue['SOURCE_URL']);
            if ($url !== '') {
                $target = $this->resolver->resolve($url, $siteId);
            }

            $suggestion = $engine->suggest($issue, $live, $target);
            $value = $suggestion['value'];
            if ($value === null || trim($value) === '') {
                $stats['skipped']++;
                continue;
            }

            $unique = $this->makeUnique($value, $taken, $issue, $target, $engine, $field);
            $note = (string)$suggestion['explanation'];
            $confidence = (int)$suggestion['confidence'];
            if ($unique['collided']) {
                $stats['collisions']++;
                $note .= ' Значение совпадало с соседней страницей, поэтому добавлено уточнение — проверьте его и при необходимости перепишите.';
                $confidence = min($confidence, 45);
            }
            $value = $unique['value'];
            $taken[$this->norm($value)] = true;

            IssueTable::update((int)$issue['ID'], [
                'NEW_VALUE' => $value,
                'SUGGESTION_NOTE' => $note,
                'CONFIDENCE' => $confidence,
                'UPDATED_AT' => $now,
            ]);
            $stats['filled']++;
        }

        return $stats;
    }

    /**
     * Собирает уже существующие значения, чтобы новые не совпали ни с ними,
     * ни между собой.
     *
     * @param array<int,array> $issues
     * @return array<string,bool>
     */
    private function collectTakenValues(array $issues): array
    {
        $taken = [];
        foreach ($issues as $issue) {
            $existing = trim((string)($issue['NEW_VALUE'] ?? ''));
            if ($existing !== '') {
                $taken[$this->norm($existing)] = true;
            }
        }
        return $taken;
    }

    /**
     * Делает значение уникальным, добавляя уточнения, а не цифры.
     *
     * @param array<string,bool> $taken
     * @return array{value:string,collided:bool}
     */
    private function makeUnique(string $value, array $taken, array $issue, array $target, SuggestionEngine $engine, string $field): array
    {
        if (!isset($taken[$this->norm($value)])) {
            return ['value' => $value, 'collided' => false];
        }

        $max = $field === 'description' ? SuggestionEngine::DESCRIPTION_MAX : SuggestionEngine::TITLE_MAX;
        $siteId = (string)(($issue['SITE_ID'] ?? '') ?: 's1');
        $url = (string)$issue['SOURCE_URL'];

        // 1. Настоящее название раздела — лучшее уточнение.
        $section = $engine->sectionLabel($issue, $target);
        if ($section !== '') {
            $candidate = $engine->fitLength($this->injectQualifier($value, $section), $max);
            if (!isset($taken[$this->norm($candidate)])) {
                return ['value' => $candidate, 'collided' => true];
            }
        }

        // 2. Собственный заголовок страницы, известный из других отчётов.
        $known = $this->knownLabel($siteId, $url);
        if ($known !== '' && mb_stripos($value, $known, 0, 'UTF-8') === false) {
            $candidate = $engine->fitLength($this->injectQualifier($value, $known), $max);
            if (!isset($taken[$this->norm($candidate)])) {
                return ['value' => $candidate, 'collided' => true];
            }
        }

        // 3. Осмысленного текста нет. Добавляем технический маркер с адресом:
        //    он гарантированно уникален и явно выглядит как заглушка, которую
        //    нужно заменить. Транслит из адреса («Dostavka») сюда не подставляем —
        //    латинское слово посреди русского описания выглядит как ошибка.
        $path = '/' . trim((string)parse_url($url, PHP_URL_PATH), '/');
        $marker = 'страница ' . ($path !== '/' ? $path : '#' . (int)$issue['ID']);
        $candidate = $engine->fitLength($this->injectQualifier($value, $marker), $max);
        return ['value' => $candidate, 'collided' => true];
    }

    /**
     * Заголовок страницы, известный из других отчётов по этому адресу.
     */
    private function knownLabel(string $siteId, string $url): string
    {
        if ($url === '' || !class_exists(PageFacts::class)) {
            return '';
        }
        try {
            return PageFacts::labelFor($siteId, $url);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Аккуратно вставляет уточнение перед хвостом строки.
     */
    private function injectQualifier(string $value, string $qualifier): string
    {
        $qualifier = trim((string)preg_replace('~\s+~u', ' ', $qualifier));
        if ($qualifier === '') {
            return $value;
        }
        if (mb_stripos($value, $qualifier, 0, 'UTF-8') !== false) {
            return $value;
        }
        // Вставляем после первого предложения/разделителя.
        if (preg_match('~^(.*?)(\s[—–|]\s.*)$~u', $value, $m)) {
            return $m[1] . ' (' . $qualifier . ')' . $m[2];
        }
        if (preg_match('~^(.*?\.)\s(.*)$~u', $value, $m)) {
            return $m[1] . ' ' . $this->ucfirst($qualifier) . '. ' . $m[2];
        }
        return $value . ' — ' . $qualifier;
    }

    /**
     * Сводка по группам дубликатов для интерфейса.
     *
     * @return array<int,array{group_hash:string,type:string,value:string,count:int,ready:int,ids:int[]}>
     */
    public function groups(array $filter, int $limit = 200): array
    {
        $rows = IssueTable::getList([
            'filter' => $filter,
            'order' => ['GROUP_HASH' => 'ASC', 'ID' => 'ASC'],
            'limit' => max(1, min(2000, $limit)),
        ]);

        $groups = [];
        while ($issue = $rows->fetch()) {
            $hash = (string)$issue['GROUP_HASH'];
            if (!isset($groups[$hash])) {
                $groups[$hash] = [
                    'group_hash' => $hash,
                    'type' => (string)$issue['ISSUE_TYPE'],
                    'value' => (string)$issue['OLD_VALUE'],
                    'count' => 0,
                    'ready' => 0,
                    'ids' => [],
                ];
            }
            $groups[$hash]['count']++;
            $groups[$hash]['ids'][] = (int)$issue['ID'];
            if (trim((string)$issue['NEW_VALUE']) !== '') {
                $groups[$hash]['ready']++;
            }
        }

        uasort($groups, static function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        return array_values($groups);
    }

    private function norm(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $value = (string)preg_replace('~[^\p{L}\p{N}]+~u', ' ', $value);
        return trim($value);
    }

    private function ucfirst(string $value): string
    {
        if ($value === '' || !function_exists('mb_substr')) {
            return ucfirst($value);
        }
        return mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($value, 1, null, 'UTF-8');
    }
}
