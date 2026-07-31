<?php
namespace Relod\SeoFixer\Site;

use Bitrix\Main\Application;

/**
 * Определяет, к какому сайту Битрикса относится отчёт.
 *
 * Модуль не привязан к одному домену: он читает список сайтов из
 * настроек Битрикса (b_lang + b_lang_domain) и сопоставляет с доменом
 * из имени файла отчёта или из адресов внутри него.
 */
class SiteContext
{
    /** @var array<string,array>|null */
    private static $sites = null;

    /**
     * Все активные сайты Битрикса с их доменами.
     *
     * @return array<string,array{id:string,name:string,dir:string,server_name:string,domains:string[],doc_root:string}>
     */
    public static function listSites(): array
    {
        if (self::$sites !== null) {
            return self::$sites;
        }

        $sites = [];
        $connection = Application::getConnection();

        $res = $connection->query("SELECT LID, NAME, DIR, SERVER_NAME, SITE_NAME, DOC_ROOT FROM b_lang WHERE ACTIVE='Y' ORDER BY SORT ASC, LID ASC");
        while ($row = $res->fetch()) {
            $id = (string)$row['LID'];
            $sites[$id] = [
                'id' => $id,
                'name' => (string)($row['SITE_NAME'] ?: $row['NAME']),
                'dir' => (string)$row['DIR'],
                'server_name' => self::normalizeDomain((string)$row['SERVER_NAME']),
                'doc_root' => (string)$row['DOC_ROOT'],
                'domains' => [],
            ];
            if ($sites[$id]['server_name'] !== '') {
                $sites[$id]['domains'][] = $sites[$id]['server_name'];
            }
        }

        if ($connection->isTableExists('b_lang_domain')) {
            $res = $connection->query('SELECT LID, DOMAIN FROM b_lang_domain');
            while ($row = $res->fetch()) {
                $id = (string)$row['LID'];
                $domain = self::normalizeDomain((string)$row['DOMAIN']);
                if ($domain !== '' && isset($sites[$id]) && !in_array($domain, $sites[$id]['domains'], true)) {
                    $sites[$id]['domains'][] = $domain;
                }
            }
        }

        self::$sites = $sites;
        return $sites;
    }

    public static function resetCache(): void
    {
        self::$sites = null;
    }

    /**
     * Ищет сайт по домену. Учитывает поддомены и вариант с www.
     */
    public static function detectByDomain(string $domain): ?string
    {
        $domain = self::normalizeDomain($domain);
        if ($domain === '') {
            return null;
        }

        $sites = self::listSites();

        foreach ($sites as $id => $site) {
            if (in_array($domain, $site['domains'], true)) {
                return $id;
            }
        }
        // Совпадение без www.
        $bare = preg_replace('/^www\./', '', $domain);
        foreach ($sites as $id => $site) {
            foreach ($site['domains'] as $siteDomain) {
                if (preg_replace('/^www\./', '', $siteDomain) === $bare) {
                    return $id;
                }
            }
        }
        return null;
    }

    public static function detectByUrl(string $url): ?string
    {
        $host = (string)parse_url($url, PHP_URL_HOST);
        return $host !== '' ? self::detectByDomain($host) : null;
    }

    /**
     * Сайт по умолчанию — помеченный как основной, иначе первый активный.
     */
    public static function defaultSiteId(): string
    {
        $connection = Application::getConnection();
        $row = $connection->query("SELECT LID FROM b_lang WHERE ACTIVE='Y' AND DEF='Y' ORDER BY SORT ASC LIMIT 1")->fetch();
        if ($row) {
            return (string)$row['LID'];
        }
        $sites = self::listSites();
        return $sites ? (string)array_key_first($sites) : 's1';
    }

    /**
     * Определяет сайт и домен отчёта: сначала по домену из имени файла,
     * затем по адресам внутри отчёта.
     *
     * @param string[] $sampleUrls
     * @return array{site_id:string,domain:string,matched:bool}
     */
    public static function resolveForReport(?string $fileDomain, array $sampleUrls = []): array
    {
        $domain = self::normalizeDomain((string)$fileDomain);

        if ($domain === '') {
            foreach ($sampleUrls as $url) {
                $host = self::normalizeDomain((string)parse_url((string)$url, PHP_URL_HOST));
                if ($host !== '') {
                    $domain = $host;
                    break;
                }
            }
        }

        if ($domain !== '') {
            $siteId = self::detectByDomain($domain);
            if ($siteId !== null) {
                return ['site_id' => $siteId, 'domain' => $domain, 'matched' => true];
            }
            return ['site_id' => self::defaultSiteId(), 'domain' => $domain, 'matched' => false];
        }

        $siteId = self::defaultSiteId();
        return ['site_id' => $siteId, 'domain' => self::primaryDomain($siteId), 'matched' => true];
    }

    public static function primaryDomain(string $siteId): string
    {
        $sites = self::listSites();
        if (isset($sites[$siteId]) && $sites[$siteId]['domains']) {
            return (string)$sites[$siteId]['domains'][0];
        }
        return self::normalizeDomain((string)($_SERVER['HTTP_HOST'] ?? ''));
    }

    public static function siteName(string $siteId): string
    {
        $sites = self::listSites();
        return isset($sites[$siteId]) ? (string)$sites[$siteId]['name'] : $siteId;
    }

    /**
     * Базовый адрес сайта, например https://example.com
     */
    public static function baseUrl(string $siteId, string $domain = ''): string
    {
        $domain = $domain !== '' ? self::normalizeDomain($domain) : self::primaryDomain($siteId);
        if ($domain === '') {
            return '';
        }
        return self::scheme() . '://' . $domain;
    }

    private static function scheme(): string
    {
        $https = (string)($_SERVER['HTTPS'] ?? '');
        return ($https !== '' && strtolower($https) !== 'off') ? 'https' : 'https';
    }

    /**
     * Название бренда для подстановки в title/description.
     * Берётся из настроек модуля для сайта, иначе — из названия сайта Битрикса.
     */
    public static function brandName(string $siteId): string
    {
        $custom = trim((string)\COption::GetOptionString('relod.seofixer', 'brand_' . $siteId, ''));
        if ($custom !== '') {
            return $custom;
        }
        $name = trim(self::siteName($siteId));
        if ($name !== '') {
            return $name;
        }
        $domain = self::primaryDomain($siteId);
        return $domain !== '' ? $domain : 'Сайт';
    }

    public static function normalizeDomain(string $domain): string
    {
        $domain = trim($domain);
        if ($domain === '') {
            return '';
        }
        if (strpos($domain, '://') !== false) {
            $domain = (string)parse_url($domain, PHP_URL_HOST);
        }
        $domain = preg_replace('~[/:].*$~', '', $domain);
        $domain = function_exists('mb_strtolower') ? mb_strtolower((string)$domain, 'UTF-8') : strtolower((string)$domain);
        return trim((string)$domain);
    }

    /**
     * Приводит адрес к абсолютному виду для указанного сайта.
     */
    public static function absoluteUrl(string $url, string $siteId, string $domain = ''): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('~^https?://~i', $url)) {
            return $url;
        }
        $base = self::baseUrl($siteId, $domain);
        if ($base === '') {
            return $url;
        }
        return $base . '/' . ltrim($url, '/');
    }

    /**
     * Путь без домена и без параметров — для сопоставления с сущностями Битрикса.
     */
    public static function pathOf(string $url): string
    {
        $path = (string)parse_url(trim($url), PHP_URL_PATH);
        if ($path === '') {
            return '/';
        }
        return '/' . ltrim($path, '/');
    }

    /**
     * Проверяет, принадлежит ли адрес указанному сайту.
     */
    public static function belongsToSite(string $url, string $siteId, string $domain = ''): bool
    {
        $host = self::normalizeDomain((string)parse_url(trim($url), PHP_URL_HOST));
        if ($host === '') {
            return true; // относительный адрес — считаем своим
        }
        if ($domain !== '' && self::normalizeDomain($domain) === $host) {
            return true;
        }
        $sites = self::listSites();
        if (!isset($sites[$siteId])) {
            return false;
        }
        $bare = preg_replace('/^www\./', '', $host);
        foreach ($sites[$siteId]['domains'] as $siteDomain) {
            if (preg_replace('/^www\./', '', $siteDomain) === $bare) {
                return true;
            }
        }
        return false;
    }
}
