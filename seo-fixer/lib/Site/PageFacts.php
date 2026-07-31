<?php
namespace Relod\SeoFixer\Site;

use Relod\SeoFixer\Model\IssueTable;
use Relod\SeoFixer\Report\RowMapper;

/**
 * Справочник известных фактов о страницах сайта.
 *
 * Разные отчёты Netpeak содержат разные колонки: в одном есть Title,
 * в другом H1, в третьем только Description. По отдельности их мало,
 * но вместе они дают связную картину: для каждого адреса — его реальный
 * заголовок на русском.
 *
 * Благодаря этому модуль предлагает осмысленные значения даже тогда,
 * когда сайт недоступен и живую проверку сделать нельзя: иначе он был
 * бы вынужден собирать текст из латинского адреса вроде /info/refund/.
 */
class PageFacts
{
    /** @var array<string,array<string,array{title:string,h1:string,description:string}>> */
    private static $cache = [];

    /**
     * Загружает факты по всем карточкам сайта.
     *
     * @return array<string,array{title:string,h1:string,description:string}>
     */
    public static function forSite(string $siteId, int $limit = 5000): array
    {
        if (isset(self::$cache[$siteId])) {
            return self::$cache[$siteId];
        }

        $facts = [];
        $mapper = new RowMapper();

        $rows = IssueTable::getList([
            'filter' => ['=SITE_ID' => $siteId, '!=SOURCE_URL' => false],
            'select' => ['SOURCE_URL', 'ISSUE_TYPE', 'RAW_DATA', 'LIVE_VALUE', 'TARGET_FIELD'],
            'order' => ['ID' => 'ASC'],
            'limit' => max(1, min(20000, $limit)),
        ]);

        while ($row = $rows->fetch()) {
            $url = self::normalizeUrl((string)$row['SOURCE_URL']);
            if ($url === '') {
                continue;
            }
            if (!isset($facts[$url])) {
                $facts[$url] = ['title' => '', 'h1' => '', 'description' => ''];
            }

            $raw = json_decode((string)$row['RAW_DATA'], true);
            if (is_array($raw)) {
                $mapped = $mapper->map($raw, (string)$row['ISSUE_TYPE'], '');
                foreach (['title', 'h1', 'description'] as $field) {
                    $value = trim((string)($mapped[$field] ?? ''));
                    if ($value !== '' && $facts[$url][$field] === '' && !self::isServiceStub($value)) {
                        $facts[$url][$field] = $value;
                    }
                }
            }

            // Живая проверка точнее отчёта — она перекрывает данные файла.
            $liveValue = trim((string)$row['LIVE_VALUE']);
            $liveField = (string)$row['TARGET_FIELD'];
            if ($liveValue !== '' && isset($facts[$url][$liveField]) && !self::isServiceStub($liveValue)) {
                $facts[$url][$liveField] = $liveValue;
            }
        }

        self::$cache[$siteId] = $facts;
        return $facts;
    }

    public static function resetCache(): void
    {
        self::$cache = [];
    }

    /**
     * Известный заголовок страницы: сначала H1, затем title.
     */
    public static function labelFor(string $siteId, string $url): string
    {
        $facts = self::forSite($siteId);
        $key = self::normalizeUrl($url);
        if (!isset($facts[$key])) {
            return '';
        }
        $h1 = trim($facts[$key]['h1']);
        if ($h1 !== '') {
            return $h1;
        }
        return trim($facts[$key]['title']);
    }

    /**
     * Заголовок родительского раздела — берём факты по адресу на уровень выше.
     */
    public static function parentLabelFor(string $siteId, string $url): string
    {
        $path = trim((string)parse_url($url, PHP_URL_PATH), '/');
        if ($path === '') {
            return '';
        }
        $segments = array_values(array_filter(explode('/', $path)));
        if (count($segments) < 2) {
            return '';
        }
        array_pop($segments);

        $host = (string)parse_url($url, PHP_URL_SCHEME) . '://' . (string)parse_url($url, PHP_URL_HOST);
        $parentUrl = $host . '/' . implode('/', $segments) . '/';
        return self::labelFor($siteId, $parentUrl);
    }

    /**
     * @return array{title:string,h1:string,description:string}
     */
    public static function get(string $siteId, string $url): array
    {
        $facts = self::forSite($siteId);
        $key = self::normalizeUrl($url);
        return $facts[$key] ?? ['title' => '', 'h1' => '', 'description' => ''];
    }

    /**
     * Служебные заглушки сервера не годятся как название страницы.
     */
    public static function isServiceStub(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return true;
        }
        return (bool)preg_match(
            '~^\d{3}\s|service (temporarily )?unavailable|not found|bad gateway|forbidden|ошибка \d{3}|страница не найдена~iu',
            $value
        );
    }

    private static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $url = (string)preg_replace('~#.*$~', '', $url);
        $url = (string)preg_replace('~\?.*$~', '', $url);
        $url = rtrim($url, '/');
        return function_exists('mb_strtolower') ? mb_strtolower($url, 'UTF-8') : strtolower($url);
    }
}
