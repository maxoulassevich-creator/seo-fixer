<?php
namespace Relod\SeoFixer\Report;

/**
 * Приводит строку отчёта к единому набору полей независимо от того,
 * на каком языке выгружен отчёт и как называются колонки.
 *
 * Ключевые понятия:
 *  - page_url  — страница, на которой находится проблема (или где стоит ссылка);
 *  - link_url  — адрес, на который ведёт ссылка (то, что нужно заменить);
 *  - final_url — конечный адрес после редиректа (то, на что заменить).
 */
class RowMapper
{
    /**
     * Алиасы колонок: канонический ключ => варианты написания (RU + EN).
     * Сопоставление идёт по нормализованному названию.
     */
    private const ALIASES = [
        'page_url' => ['from url', 'url страницы источника', 'страница источник', 'исходная страница'],
        'url' => ['url', 'адрес', 'адрес страницы', 'страница', 'page url'],
        'link_url' => ['source url', 'target url', 'url ссылки', 'адрес ссылки'],
        'final_url' => ['final url', 'target redirect url', 'конечный url', 'конечный адрес', 'url редиректа', 'целевой url редиректа'],
        'status_code' => ['status code', 'код ответа', 'статус код'],
        'link_status_code' => ['target url status code', 'source url status code', 'status code of the final url', 'код ответа ссылки'],
        'content_type' => ['content type', 'тип контента'],
        'title' => ['title', 'тег title', 'заголовок title', 'meta title'],
        'title_length' => ['title length', 'длина title'],
        'description' => ['description', 'meta description', 'тег description', 'мета description', 'описание'],
        'description_length' => ['description length', 'длина description'],
        'h1' => ['h1 content', 'h1', 'заголовок h1', 'содержимое h1'],
        'h1_count' => ['h1 headings', 'количество h1', 'заголовки h1'],
        'anchor' => ['anchor link text', 'anchor text', 'anchor', 'анкор', 'текст ссылки', 'текст анкора'],
        'alt' => ['alt', 'альт', 'атрибут alt'],
        'rel' => ['rel'],
        'link_type' => ['link type', 'тип ссылки'],
        'url_source_view' => ['url source view', 'исходный вид url'],
        'issue' => ['issue', 'проблема', 'ошибка'],
        'issue_name' => ['issue name', 'название проблемы'],
        'issue_severity' => ['issue severity', 'severity', 'критичность', 'важность', 'серьезность'],
        'issue_urls' => ['urls with issue', 'url с проблемой'],
        'parameter' => ['parameter', 'параметр'],
        'info' => ['info', 'информация'],
        'threat' => ['threat', 'угроза', 'чем грозит'],
        'how_to_fix' => ['how to fix', 'как исправить'],
        'useful_links' => ['useful links', 'полезные ссылки'],
        'incoming_links' => ['incoming links', 'входящие ссылки'],
        'outgoing_links' => ['outgoing links', 'исходящие ссылки'],
        'internal_links' => ['internal links', 'внутренние ссылки'],
        'external_links' => ['external links', 'внешние ссылки'],
        'response_time' => ['response time', 'время ответа'],
        'html_size' => ['html size', 'размер html'],
        'content_size' => ['content size', 'размер контента'],
        'text_ratio' => ['text/html ratio', 'text html ratio', 'соотношение text html'],
        'characters' => ['characters', 'символы'],
        'words' => ['words', 'слова'],
        'pagerank' => ['internal pagerank', 'внутренний pagerank', 'pagerank'],
        'canonical' => ['canonical url', 'canonical', 'канонический url'],
        'meta_robots' => ['meta robots', 'мета robots'],
        'x_robots' => ['x robots tag', 'x-robots-tag'],
        'robots_allowed' => ['allowed in robots txt', 'allowed in robots.txt', 'разрешено в robots'],
        'robots_directive' => ['directive in robots txt', 'directive in robots.txt', 'директива robots'],
        'compliance' => ['compliance', 'соответствие'],
        'redirects_count' => ['redirects', 'редиректы', 'количество редиректов'],
        'image_url' => ['image url', 'src', 'изображение', 'url изображения', 'адрес изображения'],
        'row_number' => ['#', 'номер'],
    ];

    /**
     * Преобразует сырую строку в канонический набор полей.
     *
     * @param array<string,string> $row      Строка отчёта (ключи — заголовки колонок).
     * @param string               $type     Код типа отчёта.
     * @param string               $groupKey Значение группы (для отчётов о дубликатах).
     * @return array<string,string>
     */
    public function map(array $row, string $type, string $groupKey = ''): array
    {
        $normalized = [];
        foreach ($row as $header => $value) {
            $normalized[ReportTypeDetector::normalize((string)$header)] = trim((string)$value);
        }

        $out = [];
        foreach (self::ALIASES as $key => $aliases) {
            $out[$key] = '';
            foreach ($aliases as $alias) {
                $aliasKey = ReportTypeDetector::normalize($alias);
                if (isset($normalized[$aliasKey]) && $normalized[$aliasKey] !== '') {
                    $out[$key] = $normalized[$aliasKey];
                    break;
                }
            }
        }

        $meta = ReportCatalog::get($type);
        $isLinkLevel = !empty($meta['link_level']);

        // Разводим значения по смыслу.
        if ($isLinkLevel) {
            // В отчётах по ссылкам "From URL" — страница, "URL"/"Source URL" — цель ссылки.
            if ($out['page_url'] === '' && $out['url'] !== '') {
                $out['page_url'] = $out['url'];
            }
            if ($out['link_url'] === '' && $out['url'] !== '' && $out['url'] !== $out['page_url']) {
                $out['link_url'] = $out['url'];
            }
        } else {
            // В постраничных отчётах "URL" — сама проблемная страница.
            if ($out['page_url'] === '' && $out['url'] !== '') {
                $out['page_url'] = $out['url'];
            }
        }

        // Значение группы дубликатов подставляем в соответствующее поле.
        $valueField = ReportCatalog::valueField($type);
        if ($groupKey !== '' && $valueField !== null && ($out[$valueField] ?? '') === '') {
            $out[$valueField] = $groupKey;
        }
        $out['group_key'] = $groupKey;

        // Netpeak пишет статус вида "301 Moved Permanently → 200 OK".
        $out['status_first'] = $this->firstStatusCode($out['status_code']);
        $out['status_last'] = $this->lastStatusCode($out['status_code']);

        return $out;
    }

    /**
     * Числовой код первого ответа: "301 Moved Permanently → 200 OK" => 301.
     */
    public function firstStatusCode(string $raw): string
    {
        return preg_match('/(\d{3})/', $raw, $m) ? $m[1] : '';
    }

    /**
     * Числовой код последнего ответа в цепочке.
     */
    public function lastStatusCode(string $raw): string
    {
        if (preg_match_all('/(\d{3})/', $raw, $m) && $m[1]) {
            return (string)end($m[1]);
        }
        return '';
    }
}
