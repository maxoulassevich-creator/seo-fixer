<?php
namespace Relod\SeoFixer\Service;

class IssueSuggestionService
{
    private const SEO_TITLE_TYPES = [
        'duplicate_title', 'short_title', 'long_title', 'title_h1_same', 'title_special_chars',
    ];

    private const SEO_DESCRIPTION_TYPES = [
        'duplicate_description', 'short_description', 'long_description', 'description_special_chars', 'multiple_description',
    ];

    private const SEO_H1_TYPES = [
        'duplicate_h1', 'missing_h1', 'multiple_h1', 'long_h1',
    ];

    private const TEMPLATE_TYPES = [
        'max_html_size', 'min_text_html', 'max_internal_links', 'max_external_links', 'max_content_size',
        'empty_anchor', 'internal_nofollow', 'external_nofollow', 'self_links', 'pagerank_no_links', 'pagerank_no_outgoing',
    ];

    public function build(string $type, array $row): array
    {
        $sourceUrl = $this->findValue($row, [
            'source url', 'source', 'страница источник', 'страница-источник', 'url страницы', 'адрес страницы', 'страница', 'page url', 'url',
        ]);
        $targetUrl = $this->findValue($row, [
            'target url', 'target', 'url ссылки', 'адрес ссылки', 'ссылка', 'destination', 'адрес назначения', 'link url',
        ]);
        $finalUrl = $this->findValue($row, [
            'final url', 'конечный url', 'конечный адрес', 'final', 'redirect target', 'конечная страница', 'конечная ссылка',
        ]);
        $anchor = $this->findValue($row, ['анкор', 'anchor', 'текст ссылки', 'link text']);
        $severity = $this->findValue($row, ['критичность', 'severity', 'важность', 'risk']) ?: null;

        $oldValue = $this->detectOldValue($type, $row, $sourceUrl, $targetUrl, $finalUrl);
        $newValue = $this->detectNewValue($type, $oldValue, $sourceUrl, $targetUrl, $finalUrl, $row);
        $risk = $this->riskLevel($type, $oldValue, $newValue, $sourceUrl, $targetUrl, $finalUrl);
        $placeHint = $this->placeHint($type, $sourceUrl, $targetUrl, $oldValue);
        $groupBase = implode('|', [$type, $oldValue, $newValue, $finalUrl, $targetUrl, $sourceUrl]);

        return [
            'severity' => $severity,
            'source_url' => $sourceUrl,
            'target_url' => $targetUrl,
            'final_url' => $finalUrl,
            'anchor' => $anchor,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'place_hint' => $placeHint,
            'risk_level' => $risk,
            'group_hash' => hash('sha256', $groupBase),
        ];
    }

    public function refreshIssueFields(array $issue): array
    {
        $raw = json_decode((string)($issue['RAW_DATA'] ?? ''), true);
        if (!is_array($raw)) {
            $raw = [];
        }

        $built = $this->build((string)$issue['ISSUE_TYPE'], $raw);

        foreach (['source_url' => 'SOURCE_URL', 'target_url' => 'TARGET_URL', 'final_url' => 'FINAL_URL', 'anchor' => 'ANCHOR_TEXT', 'old_value' => 'OLD_VALUE'] as $builtKey => $issueKey) {
            if (($built[$builtKey] === null || $built[$builtKey] === '') && !empty($issue[$issueKey])) {
                $built[$builtKey] = (string)$issue[$issueKey];
            }
        }

        if (($built['new_value'] === null || $built['new_value'] === '') && !empty($issue['NEW_VALUE'])) {
            $built['new_value'] = (string)$issue['NEW_VALUE'];
        }

        $built['risk_level'] = $this->riskLevel(
            (string)$issue['ISSUE_TYPE'],
            $built['old_value'],
            $built['new_value'],
            $built['source_url'],
            $built['target_url'],
            $built['final_url']
        );
        $built['place_hint'] = $this->placeHint((string)$issue['ISSUE_TYPE'], $built['source_url'], $built['target_url'], $built['old_value']);

        return $built;
    }

    private function detectOldValue(string $type, array $row, ?string $sourceUrl, ?string $targetUrl, ?string $finalUrl): ?string
    {
        $allValues = array_values($row);
        if ($type === 'https_to_http' || $type === 'not_https') {
            foreach ($allValues as $value) {
                if (preg_match('~http://shop\.relod\.ru[^\s"\'<>]*~i', (string)$value, $m)) {
                    return $m[0];
                }
            }
            if ($targetUrl && stripos($targetUrl, 'http://') === 0) {
                return $targetUrl;
            }
            if ($sourceUrl && stripos($sourceUrl, 'http://') === 0) {
                return $sourceUrl;
            }
        }

        if ($type === 'invalid_url') {
            foreach ($allValues as $value) {
                if (preg_match('~https?:///[^^\s"\'<>]+~i', (string)$value, $m)) {
                    return $m[0];
                }
            }
            return $targetUrl ?: $sourceUrl;
        }

        if (in_array($type, ['redirect_links', 'redirect_chain', 'pagerank_redirect'], true)) {
            return $targetUrl ?: $sourceUrl;
        }

        if (in_array($type, self::SEO_TITLE_TYPES, true)) {
            return $this->findValue($row, ['title', 'тег title', 'заголовок title', 'meta title']);
        }

        if (in_array($type, self::SEO_DESCRIPTION_TYPES, true)) {
            return $this->findValue($row, ['description', 'meta description', 'тег description', 'мета description']);
        }

        if (in_array($type, self::SEO_H1_TYPES, true)) {
            return $this->findValue($row, ['h1', 'заголовок h1']);
        }

        if ($type === 'missing_alt') {
            return $this->findValue($row, ['src', 'image', 'изображение', 'url изображения', 'адрес изображения', 'image url']) ?: $targetUrl;
        }

        if (in_array($type, ['encoded_url', 'url_special_chars', 'uppercase_url', 'noncanonical_pages', 'same_canonical', 'duplicate_text'], true)) {
            return $sourceUrl ?: $targetUrl;
        }

        return $targetUrl ?: $sourceUrl;
    }

    private function detectNewValue(string $type, ?string $oldValue, ?string $sourceUrl, ?string $targetUrl, ?string $finalUrl, array $row): ?string
    {
        if ($oldValue && ($type === 'https_to_http' || $type === 'not_https')) {
            return preg_replace('~^http://shop\.relod\.ru~i', 'https://shop.relod.ru', $oldValue);
        }

        if (in_array($type, ['redirect_links', 'redirect_chain', 'pagerank_redirect'], true)) {
            return $finalUrl ?: $this->normalizeRedirectUrl($targetUrl ?: $oldValue);
        }

        if ($type === 'invalid_url' && $oldValue) {
            $fixed = preg_replace('~^(https?:)/+~i', '$1//', $oldValue);
            return $fixed !== $oldValue ? $fixed : null;
        }

        if (in_array($type, self::SEO_DESCRIPTION_TYPES, true)) {
            return $this->buildDescriptionDraft($sourceUrl, $row);
        }

        if (in_array($type, self::SEO_TITLE_TYPES, true)) {
            return $this->buildTitleDraft($sourceUrl, $row);
        }

        if (in_array($type, self::SEO_H1_TYPES, true)) {
            return $this->buildH1Draft($sourceUrl, $row);
        }

        if ($type === 'missing_alt') {
            return $this->buildAltDraft($sourceUrl, $targetUrl ?: $oldValue, $row);
        }

        if ($type === 'uppercase_url' && $oldValue) {
            $path = parse_url($oldValue, PHP_URL_PATH);
            if ($path && preg_match('/[A-ZА-ЯЁ]/u', $path)) {
                return $this->lower($oldValue);
            }
        }

        if ($type === 'encoded_url' && $oldValue) {
            $decoded = rawurldecode($oldValue);
            return $decoded !== $oldValue ? $decoded : null;
        }

        return null;
    }

    private function riskLevel(string $type, ?string $oldValue, ?string $newValue, ?string $sourceUrl = null, ?string $targetUrl = null, ?string $finalUrl = null): string
    {
        if (in_array($type, ['https_to_http', 'not_https', 'redirect_links', 'redirect_chain', 'pagerank_redirect'], true) && $oldValue && $newValue) {
            return 'low';
        }
        if ($type === 'invalid_url' && $oldValue && $newValue) {
            return 'medium';
        }
        if (in_array($type, array_merge(self::SEO_TITLE_TYPES, self::SEO_DESCRIPTION_TYPES, self::SEO_H1_TYPES), true)) {
            return $newValue ? 'medium' : 'content';
        }
        if ($type === 'missing_alt') {
            return $newValue ? 'medium' : 'content';
        }
        if (in_array($type, ['uppercase_url', 'encoded_url', 'url_special_chars'], true)) {
            return $newValue ? 'medium' : 'content';
        }
        if (in_array($type, self::TEMPLATE_TYPES, true)) {
            return 'template';
        }
        if (in_array($type, ['noncanonical_pages', 'same_canonical', 'duplicate_text', 'summary', 'url_errors', 'unknown'], true)) {
            return 'review';
        }
        return $newValue ? 'medium' : 'review';
    }

    private function placeHint(string $type, ?string $sourceUrl = null, ?string $targetUrl = null, ?string $oldValue = null): string
    {
        if (in_array($type, ['redirect_links', 'redirect_chain', 'pagerank_redirect', 'https_to_http', 'not_https', 'invalid_url'], true)) {
            return 'Модуль ищет точное значение в описаниях элементов/разделов, свойствах инфоблоков, описаниях файлов и шаблонных файлах. В БД правка может быть применена после подтверждения; файл шаблона меняется только если это включено в настройках.';
        }

        if (in_array($type, self::SEO_DESCRIPTION_TYPES, true)) {
            return 'SEO-поле meta description конкретной страницы. Проверьте предложенный текст, при необходимости отредактируйте и перенесите в SEO-настройки страницы/раздела/элемента. Если старое значение найдено в разрешённых полях БД, модуль сможет заменить его после подтверждения.';
        }

        if (in_array($type, self::SEO_TITLE_TYPES, true)) {
            return 'SEO-поле title. Модуль подготавливает черновик, но не сокращает и не меняет официальные названия автоматически без проверки администратора.';
        }

        if (in_array($type, self::SEO_H1_TYPES, true)) {
            return 'Заголовок H1 страницы. Нужно оставить один главный H1 и проверить, не выводится ли лишний заголовок из шаблона.';
        }

        if ($type === 'missing_alt') {
            return 'Атрибут alt изображения или описание файла. Проверьте, относится ли изображение к товару, разделу или декоративному элементу.';
        }

        if (in_array($type, self::TEMPLATE_TYPES, true)) {
            return 'Вероятная зона: компонент, меню, футер, карточка товара, фильтр, слайдер или шаблон Aspro Premier. Модуль ищет совпадения в шаблонных файлах и показывает файл/строку для задачи разработчику.';
        }

        if (in_array($type, ['noncanonical_pages', 'same_canonical'], true)) {
            return 'Canonical нужно проверять по назначению страницы: основная страница должна иметь самоссылочный canonical, служебные и дублирующие страницы могут указывать на основную.';
        }

        return 'Проверьте строку отчёта и предложенное действие. Если точного безопасного действия нет, модуль оставит задачу на проверку.';
    }

    private function buildDescriptionDraft(?string $sourceUrl, array $row): ?string
    {
        $label = $this->guessPageLabel($sourceUrl, $row);
        $path = $sourceUrl ? (string)parse_url($sourceUrl, PHP_URL_PATH) : '';

        if ($path === '/contacts/' || $path === '/contacts') {
            return 'Контакты RELOD: адреса, телефоны и способы связи с интернет-магазином иностранной литературы и образовательной компанией.';
        }
        if ($path === '/basket/' || $path === '/basket') {
            return 'Корзина интернет-магазина RELOD: проверьте выбранные книги, учебники и пособия перед оформлением заказа.';
        }
        if ($path === '/blog/' || $path === '/blog') {
            return 'Блог RELOD: новости, подборки книг, обзоры учебных материалов и идеи для изучения иностранных языков.';
        }
        if ($path === '/catalog/' || $path === '/catalog') {
            return 'Каталог RELOD: книги, учебники, пособия и художественная литература на иностранных языках для обучения и чтения.';
        }
        if ($path === '/publishers/' || $path === '/publishers') {
            return 'Издательства в каталоге RELOD: учебная и художественная литература на иностранных языках от российских и зарубежных издателей.';
        }
        if ($path === '/sales/' || $path === '/sales') {
            return 'Акции RELOD: скидки и специальные предложения на книги, учебники и пособия на иностранных языках.';
        }
        if (strpos($path, '/info/') === 0) {
            return 'Информация для покупателей RELOD: оплата, доставка, возврат, условия заказа и полезные разделы интернет-магазина.';
        }
        if (strpos($path, '/landing/') === 0 && stripos($path, 'harry') !== false) {
            return 'Оригинальные издания Harry Potter в магазине RELOD: книги на английском языке для поклонников истории о Гарри Поттере.';
        }

        if ($label !== '') {
            return $label . ' в интернет-магазине RELOD: книги, учебные материалы и литература на иностранных языках с доставкой по России.';
        }

        return null;
    }

    private function buildTitleDraft(?string $sourceUrl, array $row): ?string
    {
        $label = $this->guessPageLabel($sourceUrl, $row);
        if ($label === '') {
            return null;
        }
        $suffix = ' | RELOD';
        $title = $label . $suffix;
        if (function_exists('mb_strlen') && mb_strlen($title) > 70) {
            $title = mb_substr($label, 0, 70 - mb_strlen($suffix) - 1) . '…' . $suffix;
        }
        return $title;
    }

    private function buildH1Draft(?string $sourceUrl, array $row): ?string
    {
        $label = $this->guessPageLabel($sourceUrl, $row);
        return $label !== '' ? $label : null;
    }

    private function buildAltDraft(?string $sourceUrl, ?string $imageUrl, array $row): ?string
    {
        $existing = $this->findValue($row, ['title', 'h1', 'название', 'name']);
        if ($existing) {
            return 'Изображение: ' . $this->cleanText($existing, 120);
        }

        $fileName = '';
        if ($imageUrl) {
            $path = parse_url($imageUrl, PHP_URL_PATH) ?: $imageUrl;
            $fileName = pathinfo($path, PATHINFO_FILENAME);
            $fileName = $this->cleanText(str_replace(['-', '_'], ' ', rawurldecode($fileName)), 80);
        }
        if ($fileName !== '') {
            return 'Изображение ' . $fileName;
        }

        $label = $this->guessPageLabel($sourceUrl, $row);
        return $label !== '' ? 'Изображение на странице «' . $label . '»' : null;
    }

    private function normalizeRedirectUrl(?string $url): ?string
    {
        if (!$url) {
            return null;
        }
        $url = trim($url);
        if (stripos($url, 'http://shop.relod.ru') === 0) {
            return preg_replace('~^http://shop\.relod\.ru~i', 'https://shop.relod.ru', $url);
        }
        return $url;
    }

    private function guessPageLabel(?string $sourceUrl, array $row): string
    {
        $fromRow = $this->findValue($row, ['h1', 'заголовок h1', 'title', 'тег title', 'название', 'name']);
        if ($fromRow) {
            return $this->cleanText($fromRow, 110);
        }

        if (!$sourceUrl) {
            return '';
        }
        $path = (string)parse_url($sourceUrl, PHP_URL_PATH);
        $path = trim($path, '/');
        if ($path === '') {
            return 'Интернет-магазин RELOD';
        }
        $parts = explode('/', $path);
        $last = end($parts) ?: '';
        $last = preg_replace('~\.html?$~i', '', $last);
        $last = rawurldecode($last);
        $last = str_replace(['-', '_'], ' ', $last);
        $last = preg_replace('/\s+/u', ' ', $last);
        $last = trim($last);
        if ($last === '') {
            return '';
        }
        if (function_exists('mb_convert_case')) {
            return mb_convert_case($last, MB_CASE_TITLE, 'UTF-8');
        }
        return ucfirst($last);
    }

    private function cleanText(string $text, int $maxLength): string
    {
        $text = trim(strip_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $text = preg_replace('/\s+/u', ' ', $text);
        if (function_exists('mb_strlen') && mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, max(1, $maxLength - 1)) . '…';
        } elseif (!function_exists('mb_strlen') && strlen($text) > $maxLength) {
            $text = substr($text, 0, max(1, $maxLength - 1)) . '…';
        }
        return $text;
    }


    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function contains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        return function_exists('mb_strpos') ? mb_strpos($haystack, $needle) !== false : strpos($haystack, $needle) !== false;
    }

    private function findValue(array $row, array $needles): ?string
    {
        foreach ($row as $key => $value) {
            $k = $this->lower((string)$key);
            foreach ($needles as $needle) {
                if ($this->contains($k, $this->lower($needle)) && trim((string)$value) !== '') {
                    return trim((string)$value);
                }
            }
        }
        return null;
    }
}
