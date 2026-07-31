<?php
namespace Relod\SeoFixer\Service;

class ReportTypeDetector
{
    public const TYPE_UNKNOWN = 'unknown';

    public static function decodeNetpeakName(string $name): string
    {
        return preg_replace_callback('/#U([0-9A-Fa-f]{4})/', static function ($m) {
            return html_entity_decode('&#x' . $m[1] . ';', ENT_NOQUOTES, 'UTF-8');
        }, $name);
    }

    public static function detect(string $fileName, array $headers = []): string
    {
        $name = self::lower(self::decodeNetpeakName($fileName));
        $headerLine = self::lower(implode(' ', $headers));
        $text = $name . ' ' . $headerLine;

        $map = [
            'invalid_url' => ['неправильным-форматом-url', 'неправильным форматом url', 'invalid url'],
            'redirect_links' => ['редиректы-входящие', 'входящие ссылки', '3xx', 'redirection'],
            'redirect_chain' => ['цепочка-редиректов', 'цепочки редиректов'],
            'pagerank_redirect' => ['pagerank-перенаправление', 'pagerank перенаправление'],
            'https_to_http' => ['https-→-http', 'https → http', 'https-to-http'],
            'not_https' => ['не-https-протокол', 'не https протокол'],
            'duplicate_title' => ['дубликаты-title', 'дубликаты title'],
            'short_title' => ['короткий-title', 'короткий title'],
            'long_title' => ['макс.-длина-title', 'макс. длина title', 'длина title'],
            'title_h1_same' => ['одинаковые-title-и-h1', 'одинаковые title и h1'],
            'title_special_chars' => ['спецсимволы-в-title', 'символы в title', 'эмодзи'],
            'duplicate_description' => ['дубликаты-description', 'дубликаты description'],
            'short_description' => ['короткий-description', 'короткий description'],
            'long_description' => ['макс.-длина-description', 'длина description'],
            'description_special_chars' => ['спецсимволы-в-description', 'символы в description'],
            'multiple_description' => ['несколько-тегов-description', 'несколько тегов description'],
            'duplicate_h1' => ['дубликаты-h1', 'дубликаты h1'],
            'missing_h1' => ['отсутствующий-или-пустой-h1', 'отсутствующий h1', 'пустой h1'],
            'multiple_h1' => ['несколько-заголовков-h1', 'несколько h1'],
            'long_h1' => ['макс.-длина-h1', 'длина h1'],
            'missing_alt' => ['изображения-без-атрибута-alt', 'изображения без атрибута alt'],
            'empty_anchor' => ['пустыми-анкорами', 'пустыми анкорами'],
            'internal_nofollow' => ['внутренние-nofollow', 'внутренние nofollow'],
            'external_nofollow' => ['внешние-nofollow', 'внешние nofollow'],
            'self_links' => ['циклические-гиперссылки', 'циклические гиперссылки'],
            'max_internal_links' => ['макс.-количество-внутренних', 'количество внутренних'],
            'max_external_links' => ['макс.-количество-внешних', 'количество внешних'],
            'max_html_size' => ['макс.-размер-html', 'размер html'],
            'min_text_html' => ['мин.-соотношение-texthtml', 'text/html', 'texthtml'],
            'max_content_size' => ['макс.-размер-контента', 'размер контента'],
            'encoded_url' => ['кодированные-url', 'кодированные url'],
            'url_special_chars' => ['нежелательными-спецсимволами', 'спецсимволами'],
            'uppercase_url' => ['заглавными-буквами', 'заглавными буквами'],
            'noncanonical_pages' => ['неканонические-страницы', 'неканонические страницы'],
            'same_canonical' => ['одинаковые-канонические-url', 'одинаковые canonical'],
            'duplicate_text' => ['дубликаты-текста', 'дубликаты текста'],
            'pagerank_no_links' => ['pagerank-отсутствуют-связи', 'pagerank отсутствуют связи'],
            'pagerank_no_outgoing' => ['pagerank-отсутствуют-исходящие', 'отсутствуют исходящие'],
            'summary' => ['сводка-по-ошибкам', 'сводка по ошибкам'],
            'url_errors' => ['url-и-их-ошибки', 'url и их ошибки'],
        ];

        foreach ($map as $type => $needles) {
            foreach ($needles as $needle) {
                if (self::contains($text, $needle)) {
                    return $type;
                }
            }
        }
        return self::TYPE_UNKNOWN;
    }


    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private static function contains(string $haystack, string $needle): bool
    {
        return function_exists('mb_strpos') ? mb_strpos($haystack, $needle) !== false : strpos($haystack, $needle) !== false;
    }

    public static function typeTitle(string $type): string
    {
        $titles = [
            'invalid_url' => 'Ссылки с неправильным форматом URL',
            'redirect_links' => 'Ссылки на редиректы и старые адреса',
            'redirect_chain' => 'Цепочки редиректов',
            'pagerank_redirect' => 'PageRank: перенаправление',
            'https_to_http' => 'HTTPS-страницы ссылаются на HTTP',
            'not_https' => 'Не HTTPS-протокол',
            'duplicate_title' => 'Дубликаты title',
            'short_title' => 'Короткий title',
            'long_title' => 'Слишком длинный title',
            'title_h1_same' => 'Одинаковые title и H1',
            'title_special_chars' => 'Спецсимволы в title',
            'duplicate_description' => 'Дубликаты description',
            'short_description' => 'Короткий description',
            'long_description' => 'Слишком длинный description',
            'description_special_chars' => 'Спецсимволы в description',
            'multiple_description' => 'Несколько meta description',
            'duplicate_h1' => 'Дубликаты H1',
            'missing_h1' => 'Отсутствующий или пустой H1',
            'multiple_h1' => 'Несколько H1',
            'long_h1' => 'Слишком длинный H1',
            'missing_alt' => 'Изображения без alt',
            'empty_anchor' => 'Пустые анкоры',
            'internal_nofollow' => 'Внутренние nofollow-ссылки',
            'external_nofollow' => 'Внешние nofollow-ссылки',
            'self_links' => 'Циклические ссылки',
            'max_internal_links' => 'Слишком много внутренних ссылок',
            'max_external_links' => 'Слишком много внешних ссылок',
            'max_html_size' => 'Слишком большой HTML',
            'min_text_html' => 'Низкое соотношение Text/HTML',
            'max_content_size' => 'Слишком большой контент',
            'encoded_url' => 'Кодированные URL',
            'url_special_chars' => 'URL со спецсимволами',
            'uppercase_url' => 'URL с заглавными буквами',
            'noncanonical_pages' => 'Неканонические страницы',
            'same_canonical' => 'Одинаковые canonical URL',
            'duplicate_text' => 'Дубликаты текста',
            'pagerank_no_links' => 'PageRank: отсутствуют связи',
            'pagerank_no_outgoing' => 'PageRank: отсутствуют исходящие ссылки',
            'summary' => 'Сводка по ошибкам',
            'url_errors' => 'URL и их ошибки',
            'unknown' => 'Неизвестный тип отчёта',
        ];
        return $titles[$type] ?? $type;
    }
}
