<?php
namespace Relod\SeoFixer\Report;

/**
 * Определяет тип отчёта Netpeak Spider.
 *
 * Netpeak выгружает файлы строго по шаблону:
 *   ДАТА~ДОМЕН~[Серьёзность~]Название-проблемы~Количество~Netpeak-Spider~Время.xlsx
 * Например:
 *   2026-06-09~newrelease.relod.ru~Error~Duplicate-Descriptions~9~Netpeak-Spider~210200.xlsx
 *
 * Поэтому сначала разбираем имя структурно (самый надёжный источник),
 * затем — по названию проблемы из каталога, и только в конце —
 * по сигнатуре колонок. Работает и с русской, и с английской выгрузкой.
 */
class ReportTypeDetector
{
    /**
     * Netpeak экранирует не-ASCII символы в имени файла как #U0430.
     */
    public static function decodeNetpeakName(string $name): string
    {
        return (string)preg_replace_callback('/#U([0-9A-Fa-f]{4})/', static function ($m) {
            return html_entity_decode('&#x' . $m[1] . ';', ENT_NOQUOTES, 'UTF-8');
        }, $name);
    }

    /**
     * Разбирает имя файла Netpeak на составляющие.
     *
     * @return array{date:?string,domain:?string,severity:?string,issue:?string,count:?int,time:?string,clean_name:string}
     */
    public static function parseFileName(string $fileName): array
    {
        $name = self::decodeNetpeakName($fileName);
        $base = preg_replace('/\.(xlsx|xls|csv)$/i', '', basename($name));

        $out = [
            'date' => null,
            'domain' => null,
            'severity' => null,
            'issue' => null,
            'count' => null,
            'time' => null,
            'clean_name' => $base,
        ];

        $parts = array_values(array_filter(explode('~', $base), static function ($p) {
            return trim($p) !== '';
        }));
        if (count($parts) < 2) {
            return $out;
        }

        // Дата в начале.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $parts[0])) {
            $out['date'] = $parts[0];
            array_shift($parts);
        }

        // Домен: содержит точку и похож на хост.
        if ($parts && preg_match('/^(?:[a-z0-9-]+\.)+[a-z]{2,}$/i', $parts[0])) {
            $out['domain'] = mb_strtolower($parts[0], 'UTF-8');
            array_shift($parts);
        }

        // Хвост: ...~Количество~Netpeak-Spider~Время
        $spiderIndex = null;
        foreach ($parts as $i => $p) {
            if (stripos(str_replace(' ', '-', $p), 'netpeak-spider') === 0) {
                $spiderIndex = $i;
                break;
            }
        }
        if ($spiderIndex !== null) {
            if (isset($parts[$spiderIndex + 1])) {
                $out['time'] = $parts[$spiderIndex + 1];
            }
            if ($spiderIndex >= 1 && preg_match('/^\d+$/', $parts[$spiderIndex - 1])) {
                $out['count'] = (int)$parts[$spiderIndex - 1];
                $parts = array_slice($parts, 0, $spiderIndex - 1);
            } else {
                $parts = array_slice($parts, 0, $spiderIndex);
            }
        }

        // Серьёзность — отдельным сегментом перед названием.
        $severityMap = [
            'error' => 'error', 'ошибка' => 'error', 'ошибки' => 'error',
            'warning' => 'warning', 'предупреждение' => 'warning', 'предупреждения' => 'warning',
            'notice' => 'notice', 'замечание' => 'notice', 'замечания' => 'notice',
        ];
        if ($parts) {
            $first = mb_strtolower(trim($parts[0]), 'UTF-8');
            if (isset($severityMap[$first])) {
                $out['severity'] = $severityMap[$first];
                array_shift($parts);
            }
        }

        if ($parts) {
            $out['issue'] = trim(implode(' ', $parts));
        }

        return $out;
    }

    /**
     * Основной метод: определяет код типа отчёта.
     *
     * @param string   $fileName Имя файла (как загрузил пользователь).
     * @param string[] $headers  Заголовки колонок первой строки.
     */
    public static function detect(string $fileName, array $headers = []): string
    {
        $parsed = self::parseFileName($fileName);

        // 1. По названию проблемы из имени файла — самый надёжный путь.
        if (!empty($parsed['issue'])) {
            $type = self::matchIssueName((string)$parsed['issue']);
            if ($type !== null) {
                return $type;
            }
        }

        // 2. По всему имени файла целиком (на случай переименования).
        $type = self::matchIssueName(self::decodeNetpeakName($fileName));
        if ($type !== null) {
            return $type;
        }

        // 3. По сигнатуре колонок.
        $type = self::detectByHeaders($headers);
        if ($type !== null) {
            return $type;
        }

        return ReportCatalog::TYPE_UNKNOWN;
    }

    /**
     * Сопоставляет произвольную строку с названием проблемы из каталога.
     * Используется и для имени файла, и для колонки "Issue" в отчёте
     * "URLs and their Issues".
     */
    public static function matchIssueName(string $raw): ?string
    {
        $needle = self::normalize($raw);
        if ($needle === '') {
            return null;
        }

        $best = null;
        $bestLen = 0;
        foreach (ReportCatalog::all() as $code => $meta) {
            foreach ((array)($meta['names'] ?? []) as $name) {
                $candidate = self::normalize((string)$name);
                if ($candidate === '') {
                    continue;
                }
                if ($candidate === $needle) {
                    return $code;
                }
                // Более длинное совпадение точнее: "duplicate descriptions"
                // должно выиграть у "description".
                if (strpos($needle, $candidate) !== false && strlen($candidate) > $bestLen) {
                    $best = $code;
                    $bestLen = strlen($candidate);
                }
            }
        }
        return $best;
    }

    /**
     * Определение по набору колонок — запасной вариант.
     */
    private static function detectByHeaders(array $headers): ?string
    {
        $set = [];
        foreach ($headers as $h) {
            $set[self::normalize((string)$h)] = true;
        }
        if (!$set) {
            return null;
        }

        $has = static function (array $names) use ($set) {
            foreach ($names as $n) {
                if (!isset($set[self::normalize($n)])) {
                    return false;
                }
            }
            return true;
        };

        // Служебные отчёты опознаются однозначно.
        if ($has(['issue severity', 'issue name', 'urls with issue'])) {
            return 'issue_overview';
        }
        if ($has(['url', 'issue', 'issue severity'])) {
            return 'url_issues';
        }
        if ($has(['from url', 'final url'])) {
            return 'redirect_links';
        }
        if ($has(['from url', 'target url status code'])) {
            return 'broken_links';
        }
        if ($has(['url', 'target redirect url', 'redirects'])) {
            return 'redirected_pages';
        }
        if ($has(['url', 'description', 'description length'])) {
            return 'short_description';
        }
        if ($has(['url', 'title', 'title length'])) {
            return 'short_title';
        }
        if ($has(['url', 'title', 'h1 content'])) {
            return 'same_title_h1';
        }
        if ($has(['url', 'h1 content', 'h1 headings'])) {
            return 'missing_h1';
        }
        if ($has(['url', 'text/html ratio'])) {
            return 'min_text_html';
        }
        if ($has(['url', 'html size'])) {
            return 'max_html_size';
        }
        if ($has(['url', 'response time'])) {
            return 'long_response_time';
        }
        if ($has(['url', 'internal pagerank', 'incoming links'])) {
            return 'pagerank_orphan';
        }
        if ($has(['url', 'internal pagerank', 'outgoing links'])) {
            return 'pagerank_dead_end';
        }
        if ($has(['url', 'directive in robots.txt'])) {
            return 'blocked_by_robots';
        }
        if ($has(['url', 'incoming links', 'status code'])) {
            return 'broken_pages';
        }

        return null;
    }

    /**
     * Приводит название к сравнимому виду:
     * нижний регистр, дефисы/подчёркивания/двоеточия → пробелы, схлопнутые пробелы.
     */
    public static function normalize(string $value): string
    {
        $value = self::decodeNetpeakName($value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $value = str_replace(['ё'], ['е'], $value);
        $value = (string)preg_replace('~[\-_:,/\\\\\.]+~u', ' ', $value);
        $value = (string)preg_replace('~\s+~u', ' ', $value);
        return trim($value);
    }

    /** Совместимость со старым API модуля. */
    public static function typeTitle(string $type): string
    {
        return ReportCatalog::title($type);
    }
}
