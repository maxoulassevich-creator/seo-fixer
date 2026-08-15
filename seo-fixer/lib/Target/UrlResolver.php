<?php
namespace Relod\SeoFixer\Target;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Relod\SeoFixer\Site\SiteContext;

/**
 * Определяет, что именно в Битриксе отвечает за конкретный адрес:
 * элемент инфоблока, раздел инфоблока или статическую страницу.
 *
 * Работает без привязки к конкретному сайту: шаблоны адресов
 * (DETAIL_PAGE_URL / SECTION_PAGE_URL) читаются из настроек инфоблоков
 * и превращаются в регулярные выражения.
 */
class UrlResolver
{
    public const TARGET_ELEMENT = 'iblock_element';
    public const TARGET_SECTION = 'iblock_section';
    public const TARGET_PAGE = 'static_page';
    public const TARGET_NONE = 'none';

    /** @var array<string,array>|null */
    private $iblockCache = null;

    /** @var array<string,array> */
    private $resolveCache = [];

    /**
     * @return array{
     *   type:string, iblock_id:int, entity_id:int, name:string,
     *   path:string, file:string, admin_url:string, confidence:int, note:string
     * }
     */
    public function resolve(string $url, string $siteId): array
    {
        $key = $siteId . '|' . $url;
        if (isset($this->resolveCache[$key])) {
            return $this->resolveCache[$key];
        }

        $empty = [
            'type' => self::TARGET_NONE,
            'iblock_id' => 0,
            'entity_id' => 0,
            'name' => '',
            'path' => '',
            'file' => '',
            'admin_url' => '',
            'confidence' => 0,
            'note' => '',
        ];

        $path = SiteContext::pathOf($url);
        if ($path === '') {
            $empty['note'] = 'В строке отчёта нет адреса страницы.';
            return $this->resolveCache[$key] = $empty;
        }
        $empty['path'] = $path;

        // Адреса с параметрами (?PAGEN_1=2, фильтры) — это не отдельные
        // сущности, а варианты вывода одной и той же страницы.
        $query = (string)parse_url($url, PHP_URL_QUERY);
        if ($query !== '') {
            $empty['note'] = 'Адрес с параметрами (' . $query . ') — это вариант вывода страницы, а не отдельная запись в Битриксе. Настраивается в компоненте или robots.txt.';
        }

        if (Loader::includeModule('iblock')) {
            $byTemplate = $this->resolveByIblockTemplates($path, $siteId);
            if ($byTemplate !== null) {
                return $this->resolveCache[$key] = $byTemplate;
            }
            $byCode = $this->resolveByCode($path, $siteId);
            if ($byCode !== null) {
                return $this->resolveCache[$key] = $byCode;
            }
        }

        $static = $this->resolveStaticPage($path, $siteId);
        if ($static !== null) {
            return $this->resolveCache[$key] = $static;
        }

        if ($empty['note'] === '') {
            $empty['note'] = 'Не удалось однозначно определить, что отвечает за этот адрес: возможно, страница формируется компонентом, фильтром или внешним сервисом.';
        }
        return $this->resolveCache[$key] = $empty;
    }

    /**
     * Сопоставление по шаблонам адресов инфоблоков.
     */
    private function resolveByIblockTemplates(string $path, string $siteId): ?array
    {
        foreach ($this->iblocks($siteId) as $iblock) {
            $iblockId = (int)$iblock['ID'];

            // Детальная страница элемента.
            $detail = (string)$iblock['DETAIL_PAGE_URL'];
            if ($detail !== '') {
                $vars = $this->matchTemplate($detail, $path, $iblock);
                if ($vars !== null) {
                    $element = $this->findElement($iblockId, $vars);
                    if ($element) {
                        return $this->elementTarget($iblockId, $element, $path, 95);
                    }
                }
            }

            // Страница раздела.
            $section = (string)$iblock['SECTION_PAGE_URL'];
            if ($section !== '') {
                $vars = $this->matchTemplate($section, $path, $iblock);
                if ($vars !== null) {
                    $found = $this->findSection($iblockId, $vars);
                    if ($found) {
                        return $this->sectionTarget($iblockId, $found, $path, 95);
                    }
                }
            }
        }
        return null;
    }

    /**
     * Запасной путь: ищем по символьному коду из последнего сегмента адреса.
     */
    private function resolveByCode(string $path, string $siteId): ?array
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static function ($s) {
            return $s !== '';
        }));
        if (!$segments) {
            return null;
        }
        $code = rawurldecode((string)end($segments));
        $code = preg_replace('~\.(php|html?)$~i', '', $code);
        if ($code === '' || $code === null) {
            return null;
        }

        $iblockIds = [];
        foreach ($this->iblocks($siteId) as $iblock) {
            $iblockIds[] = (int)$iblock['ID'];
        }
        if (!$iblockIds) {
            return null;
        }

        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $codeSql = $helper->forSql($code);
        $idsSql = implode(',', $iblockIds);

        // Сначала раздел: разделов меньше и совпадение обычно точнее.
        $row = $connection->query(
            "SELECT ID, IBLOCK_ID, NAME FROM b_iblock_section WHERE CODE='" . $codeSql . "' AND IBLOCK_ID IN (" . $idsSql . ") AND ACTIVE='Y' LIMIT 2"
        )->fetchAll();
        if (count($row) === 1) {
            return $this->sectionTarget((int)$row[0]['IBLOCK_ID'], ['ID' => (int)$row[0]['ID'], 'NAME' => (string)$row[0]['NAME']], $path, 70);
        }

        $row = $connection->query(
            "SELECT ID, IBLOCK_ID, NAME FROM b_iblock_element WHERE CODE='" . $codeSql . "' AND IBLOCK_ID IN (" . $idsSql . ") AND ACTIVE='Y' LIMIT 2"
        )->fetchAll();
        if (count($row) === 1) {
            return $this->elementTarget((int)$row[0]['IBLOCK_ID'], ['ID' => (int)$row[0]['ID'], 'NAME' => (string)$row[0]['NAME']], $path, 70);
        }

        return null;
    }

    /**
     * Статическая страница в файловой системе.
     */
    private function resolveStaticPage(string $path, string $siteId): ?array
    {
        $docRoot = rtrim($this->docRoot($siteId), '/');
        if ($docRoot === '') {
            return null;
        }

        $candidates = [];
        $clean = '/' . ltrim((string)parse_url($path, PHP_URL_PATH), '/');
        if (preg_match('~\.php$~i', $clean)) {
            $candidates[] = $docRoot . $clean;
        } else {
            $candidates[] = $docRoot . rtrim($clean, '/') . '/index.php';
            $candidates[] = $docRoot . rtrim($clean, '/') . '.php';
        }

        foreach ($candidates as $file) {
            $real = realpath($file);
            $realRoot = realpath($docRoot);
            if ($real && $realRoot && strpos($real, $realRoot) === 0 && is_file($real)) {
                return [
                    'type' => self::TARGET_PAGE,
                    'iblock_id' => 0,
                    'entity_id' => 0,
                    'name' => $this->staticPageTitle($real),
                    'path' => $path,
                    'file' => ltrim(str_replace($realRoot, '', $real), '/'),
                    'admin_url' => '/bitrix/admin/fileman_file_edit.php?site=' . urlencode($siteId) . '&path=' . urlencode(str_replace($realRoot, '', $real)),
                    'confidence' => 85,
                    'note' => 'Статическая страница: ' . ltrim(str_replace($realRoot, '', $real), '/'),
                ];
            }
        }
        return null;
    }

    // -----------------------------------------------------------------
    // Вспомогательное
    // -----------------------------------------------------------------

    /**
     * Превращает шаблон адреса Битрикса в регулярное выражение и
     * извлекает подставленные значения.
     *
     * @return array<string,string>|null
     */
    private function matchTemplate(string $template, string $path, array $iblock): ?array
    {
        $siteDir = (string)($iblock['SITE_DIR'] ?? '/');
        $template = str_replace('#SITE_DIR#', rtrim($siteDir, '/'), $template);
        $template = str_replace(
            ['#IBLOCK_TYPE_ID#', '#IBLOCK_ID#', '#IBLOCK_CODE#', '#IBLOCK_EXTERNAL_ID#'],
            [(string)$iblock['IBLOCK_TYPE_ID'], (string)$iblock['ID'], (string)$iblock['CODE'], (string)$iblock['EXTERNAL_ID']],
            $template
        );

        $placeholders = [
            '#SECTION_CODE_PATH#' => '(?P<SECTION_CODE_PATH>[^?]+?)',
            '#SECTION_PATH#' => '(?P<SECTION_PATH>[^?]+?)',
            '#SECTION_CODE#' => '(?P<SECTION_CODE>[^/?]+)',
            '#SECTION_ID#' => '(?P<SECTION_ID>\d+)',
            '#ELEMENT_CODE#' => '(?P<ELEMENT_CODE>[^/?]+)',
            '#ELEMENT_ID#' => '(?P<ELEMENT_ID>\d+)',
            '#EXTERNAL_ID#' => '(?P<EXTERNAL_ID>[^/?]+)',
            '#CODE#' => '(?P<CODE>[^/?]+)',
            '#ID#' => '(?P<ID>\d+)',
        ];

        $parts = preg_split('/(#[A-Z_]+#)/', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return null;
        }
        $regex = '';
        $hasPlaceholder = false;
        foreach ($parts as $part) {
            if (isset($placeholders[$part])) {
                $regex .= $placeholders[$part];
                $hasPlaceholder = true;
            } elseif (preg_match('/^#[A-Z_]+#$/', $part)) {
                // Неизвестный плейсхолдер — принимаем любой сегмент.
                $regex .= '[^/?]*';
            } else {
                $regex .= preg_quote($part, '~');
            }
        }
        if (!$hasPlaceholder) {
            return null;
        }

        $target = rtrim($path, '/');
        $regex = '~^' . rtrim($regex, '/') . '/?$~u';
        if (!preg_match($regex, $target, $m)) {
            return null;
        }

        $vars = [];
        foreach ($m as $k => $v) {
            if (!is_int($k)) {
                $vars[$k] = rawurldecode((string)$v);
            }
        }
        return $vars;
    }

    /**
     * @param array<string,string> $vars
     */
    private function findElement(int $iblockId, array $vars): ?array
    {
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();

        $id = (int)($vars['ELEMENT_ID'] ?? $vars['ID'] ?? 0);
        if ($id > 0) {
            $row = $connection->query('SELECT ID, NAME FROM b_iblock_element WHERE ID=' . $id . ' AND IBLOCK_ID=' . $iblockId . " AND ACTIVE='Y' LIMIT 1")->fetch();
            return $row ?: null;
        }

        $code = (string)($vars['ELEMENT_CODE'] ?? $vars['CODE'] ?? '');
        if ($code !== '') {
            $row = $connection->query("SELECT ID, NAME FROM b_iblock_element WHERE CODE='" . $helper->forSql($code) . "' AND IBLOCK_ID=" . $iblockId . " AND ACTIVE='Y' LIMIT 1")->fetch();
            return $row ?: null;
        }
        return null;
    }

    /**
     * @param array<string,string> $vars
     */
    private function findSection(int $iblockId, array $vars): ?array
    {
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();

        $id = (int)($vars['SECTION_ID'] ?? $vars['ID'] ?? 0);
        if ($id > 0) {
            $row = $connection->query('SELECT ID, NAME FROM b_iblock_section WHERE ID=' . $id . ' AND IBLOCK_ID=' . $iblockId . " AND ACTIVE='Y' LIMIT 1")->fetch();
            return $row ?: null;
        }

        $codePath = (string)($vars['SECTION_CODE_PATH'] ?? $vars['SECTION_PATH'] ?? '');
        $code = (string)($vars['SECTION_CODE'] ?? $vars['CODE'] ?? '');
        if ($codePath !== '') {
            $segments = array_values(array_filter(explode('/', trim($codePath, '/'))));
            $code = $segments ? (string)end($segments) : '';
        }
        if ($code === '') {
            return null;
        }

        $rows = $connection->query("SELECT ID, NAME FROM b_iblock_section WHERE CODE='" . $helper->forSql($code) . "' AND IBLOCK_ID=" . $iblockId . " AND ACTIVE='Y' LIMIT 2")->fetchAll();
        return count($rows) === 1 ? $rows[0] : null;
    }

    private function elementTarget(int $iblockId, array $element, string $path, int $confidence): array
    {
        return [
            'type' => self::TARGET_ELEMENT,
            'iblock_id' => $iblockId,
            'entity_id' => (int)$element['ID'],
            'name' => (string)($element['NAME'] ?? ''),
            'path' => $path,
            'file' => '',
            'admin_url' => '/bitrix/admin/iblock_element_edit.php?IBLOCK_ID=' . $iblockId . '&type=' . urlencode($this->iblockTypeById($iblockId)) . '&ID=' . (int)$element['ID'] . '&lang=ru',
            'confidence' => $confidence,
            'note' => 'Элемент инфоблока «' . (string)($element['NAME'] ?? '') . '» (#' . (int)$element['ID'] . ')',
        ];
    }

    private function sectionTarget(int $iblockId, array $section, string $path, int $confidence): array
    {
        return [
            'type' => self::TARGET_SECTION,
            'iblock_id' => $iblockId,
            'entity_id' => (int)$section['ID'],
            'name' => (string)($section['NAME'] ?? ''),
            'path' => $path,
            'file' => '',
            'admin_url' => '/bitrix/admin/iblock_section_edit.php?IBLOCK_ID=' . $iblockId . '&type=' . urlencode($this->iblockTypeById($iblockId)) . '&ID=' . (int)$section['ID'] . '&lang=ru',
            'confidence' => $confidence,
            'note' => 'Раздел инфоблока «' . (string)($section['NAME'] ?? '') . '» (#' . (int)$section['ID'] . ')',
        ];
    }

    /**
     * Инфоблоки, привязанные к сайту.
     */
    private function iblocks(string $siteId): array
    {
        if ($this->iblockCache !== null && isset($this->iblockCache[$siteId])) {
            return $this->iblockCache[$siteId];
        }
        if ($this->iblockCache === null) {
            $this->iblockCache = [];
        }

        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $site = $helper->forSql($siteId);

        // Набор колонок b_iblock отличается между версиями Битрикса, поэтому
        // выбираем только те, что реально есть. Внешний код инфоблока в API
        // называется EXTERNAL_ID, а в таблице лежит в колонке XML_ID.
        $columns = $this->tableColumns('b_iblock');
        $select = ['i.ID', 'i.IBLOCK_TYPE_ID'];
        foreach (['CODE', 'DETAIL_PAGE_URL', 'SECTION_PAGE_URL', 'LIST_PAGE_URL'] as $column) {
            if (isset($columns[$column])) {
                $select[] = 'i.' . $column;
            }
        }
        if (isset($columns['XML_ID'])) {
            $select[] = 'i.XML_ID AS EXTERNAL_ID';
        } elseif (isset($columns['EXTERNAL_ID'])) {
            $select[] = 'i.EXTERNAL_ID';
        }

        $sql = 'SELECT ' . implode(', ', $select) . ", l.DIR AS SITE_DIR
                FROM b_iblock i
                INNER JOIN b_iblock_site s ON s.IBLOCK_ID = i.ID
                LEFT JOIN b_lang l ON l.LID = s.SITE_ID
                WHERE s.SITE_ID = '" . $site . "' AND i.ACTIVE = 'Y'
                ORDER BY i.ID ASC";

        $list = [];
        try {
            $res = $connection->query($sql);
            while ($row = $res->fetch()) {
                $list[] = [
                    'ID' => (int)$row['ID'],
                    'IBLOCK_TYPE_ID' => (string)($row['IBLOCK_TYPE_ID'] ?? ''),
                    'CODE' => (string)($row['CODE'] ?? ''),
                    'EXTERNAL_ID' => (string)($row['EXTERNAL_ID'] ?? ''),
                    'DETAIL_PAGE_URL' => (string)($row['DETAIL_PAGE_URL'] ?? ''),
                    'SECTION_PAGE_URL' => (string)($row['SECTION_PAGE_URL'] ?? ''),
                    'LIST_PAGE_URL' => (string)($row['LIST_PAGE_URL'] ?? ''),
                    'SITE_DIR' => (string)(($row['SITE_DIR'] ?? '') ?: '/'),
                ];
            }
        } catch (\Throwable $e) {
            // Без списка инфоблоков модуль просто не определит цель правки —
            // это не повод ронять разбор отчёта целиком.
            $list = [];
        }

        $this->iblockCache[$siteId] = $list;
        return $list;
    }

    /**
     * Реально существующие колонки таблицы.
     *
     * @return array<string,bool>
     */
    private function tableColumns(string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $columns = [];
        try {
            $res = Application::getConnection()->query('SHOW COLUMNS FROM ' . $table);
            while ($row = $res->fetch()) {
                $columns[(string)$row['Field']] = true;
            }
        } catch (\Throwable $e) {
            $columns = [];
        }

        return $cache[$table] = $columns;
    }

    private function iblockTypeById(int $iblockId): string
    {
        $connection = Application::getConnection();
        $row = $connection->query('SELECT IBLOCK_TYPE_ID FROM b_iblock WHERE ID=' . $iblockId . ' LIMIT 1')->fetch();
        return $row ? (string)$row['IBLOCK_TYPE_ID'] : '';
    }

    private function docRoot(string $siteId): string
    {
        $sites = SiteContext::listSites();
        if (isset($sites[$siteId]) && trim((string)$sites[$siteId]['doc_root']) !== '') {
            return (string)$sites[$siteId]['doc_root'];
        }
        return (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    }

    private function staticPageTitle(string $file): string
    {
        $content = @file_get_contents($file, false, null, 0, 4096);
        if ($content === false) {
            return basename($file);
        }
        if (preg_match('~SetTitle\s*\(\s*[\'"](.+?)[\'"]~s', $content, $m)) {
            return trim($m[1]);
        }
        if (preg_match('~\$sPageTitle\s*=\s*[\'"](.+?)[\'"]~s', $content, $m)) {
            return trim($m[1]);
        }
        return basename(dirname($file));
    }
}
