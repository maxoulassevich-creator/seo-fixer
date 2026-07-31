<?php
namespace Relod\SeoFixer\Fix;

use Relod\SeoFixer\Report\ReportCatalog;
use Relod\SeoFixer\Site\PageFacts;
use Relod\SeoFixer\Site\SiteContext;

/**
 * Готовит конкретное предложение по исправлению.
 *
 * Ничего не зашито под конкретный сайт: название бренда берётся из
 * настроек сайта Битрикса, а формулировки — из настраиваемых шаблонов,
 * которые администратор может поменять один раз для всего сайта.
 */
class SuggestionEngine
{
    public const TITLE_MIN = 30;
    public const TITLE_MAX = 65;
    public const DESCRIPTION_MIN = 120;
    public const DESCRIPTION_MAX = 160;

    /** @var string */
    private $siteId;

    /** @var string */
    private $brand;

    public function __construct(string $siteId)
    {
        $this->siteId = $siteId;
        $this->brand = SiteContext::brandName($siteId);
    }

    /**
     * @param array $issue   Карточка проблемы (поля таблицы).
     * @param array $live    Результат живой проверки (может быть пустым).
     * @param array $target  Результат UrlResolver (может быть пустым).
     * @return array{value:?string,explanation:string,confidence:int}
     */
    public function suggest(array $issue, array $live = [], array $target = []): array
    {
        $type = (string)$issue['ISSUE_TYPE'];
        $strategy = ReportCatalog::strategy($type);

        switch ($strategy) {
            case ReportCatalog::STRATEGY_SEO_TITLE:
                return $this->suggestTitle($issue, $live, $target);
            case ReportCatalog::STRATEGY_SEO_DESCRIPTION:
                return $this->suggestDescription($issue, $live, $target);
            case ReportCatalog::STRATEGY_SEO_H1:
                return $this->suggestH1($issue, $live, $target);
            case ReportCatalog::STRATEGY_LINK_REPLACE:
                return $this->suggestLink($issue, $live);
            case ReportCatalog::STRATEGY_IMAGE_ALT:
                return $this->suggestAlt($issue, $live, $target);
            default:
                return ['value' => null, 'explanation' => '', 'confidence' => 0];
        }
    }

    // -----------------------------------------------------------------
    // Мета-теги
    // -----------------------------------------------------------------

    private function suggestTitle(array $issue, array $live, array $target): array
    {
        $type = (string)$issue['ISSUE_TYPE'];
        $label = $this->pageLabel($issue, $live, $target);
        if ($label === '') {
            return ['value' => null, 'explanation' => 'Не удалось определить название страницы — заполните заголовок вручную.', 'confidence' => 0];
        }

        // Бренд уже в заголовке — второй раз его добавлять не нужно.
        $brandNorm = $this->norm($this->brand);
        $brand = ($brandNorm !== '' && mb_strpos($this->norm($label), $brandNorm, 0, 'UTF-8') !== false)
            ? ''
            : $this->brand;

        // Слишком длинный заголовок режем по смысловой границе, а не по счёту
        // символов: обрыв посреди фразы читается хуже, чем короткий заголовок.
        $section = $this->sectionLabel($issue, $target);
        if ($type === 'long_title') {
            $budget = self::TITLE_MAX - ($brand !== '' ? $this->len($brand) + 3 : 0);
            $label = $this->trimToClause($label, $budget);
            $section = '';
        }

        if ($section !== '' && $this->norm($section) === $this->norm($label)) {
            $section = '';
        }

        $value = $this->render($this->template('tpl_title', '{label}{ – section}{ | brand}'), [
            'label' => $label,
            'section' => $section,
            'brand' => $brand,
            'tail' => '',
        ]);
        $value = $this->fitLength($value, self::TITLE_MAX);

        // Если бренд не поместился, оставляем самое важное — название страницы.
        if ($value === '') {
            $value = $this->fitLength($label, self::TITLE_MAX);
        }

        $len = $this->len($value);
        $short = $len < self::TITLE_MIN;

        $explanation = 'Заголовок собран из названия страницы'
            . ($section !== '' ? ', её раздела' : '')
            . ' и названия сайта, длина ' . $len . ' симв.'
            . ($short
                ? ' Это короче нормы 30–65: добавьте уточнение, по какому запросу должна находиться страница.'
                : ' Длина в норме (30–65).');

        return [
            'value' => $value,
            'explanation' => $explanation,
            'confidence' => $short ? 55 : 80,
        ];
    }

    /**
     * Обрезает текст по ближайшей смысловой границе: точке, запятой,
     * тире или скобке. Если границы нет — режет по слову.
     */
    private function trimToClause(string $value, int $max): string
    {
        $value = trim($value);
        if ($max < 15 || $this->len($value) <= $max) {
            return rtrim($value, ' .,;:—–-');
        }

        $head = function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
        $best = 0;
        foreach (['. ', ', ', ' — ', ' – ', ' - ', '; ', ' ('] as $separator) {
            $pos = function_exists('mb_strrpos') ? mb_strrpos($head, $separator, 0, 'UTF-8') : strrpos($head, $separator);
            if ($pos !== false && $pos > $best) {
                $best = (int)$pos;
            }
        }
        if ($best > $max * 0.45) {
            $head = function_exists('mb_substr') ? mb_substr($head, 0, $best, 'UTF-8') : substr($head, 0, $best);
            return rtrim($head, ' .,;:—–-');
        }
        return $this->dropDanglingWord($this->fitLength($value, $max));
    }

    /**
     * Убирает повисший в конце предлог или союз: «…напрямую от» → «…напрямую».
     */
    private function dropDanglingWord(string $value): string
    {
        for ($i = 0; $i < 3; $i++) {
            if (!preg_match('~^(.*)\s(\p{L}{1,3})$~u', $value, $m)) {
                break;
            }
            $value = rtrim($m[1], ' ,;:—–-');
        }
        return $value;
    }

    private function suggestDescription(array $issue, array $live, array $target): array
    {
        $label = $this->pageLabel($issue, $live, $target);
        if ($label === '') {
            return ['value' => null, 'explanation' => 'Не удалось определить содержание страницы — напишите описание вручную.', 'confidence' => 0];
        }

        $section = $this->sectionLabel($issue, $target);
        if ($section !== '' && $this->norm($section) === $this->norm($label)) {
            $section = '';
        }
        $tail = $this->template('tpl_description_tail', 'Актуальная информация, условия, сроки и контакты — на странице сайта.');

        $value = $this->render($this->template('tpl_description', '{label}{ — раздел «section»}{ на сайте brand}. {tail}'), [
            'label' => $label,
            'section' => $section,
            'brand' => $this->brand,
            'tail' => $tail,
        ]);
        $value = $this->fitLength($value, self::DESCRIPTION_MAX);

        $len = $this->len($value);
        $short = $len < self::DESCRIPTION_MIN;

        // Описание намеренно не добивается «водой»: пустая фраза ради длины
        // вредит сниппету сильнее, чем короткий, но осмысленный текст.
        $explanation = 'Описание собрано из заголовка страницы'
            . ($section !== '' ? ', её раздела' : '')
            . ' и названия сайта, длина ' . $len . ' симв.'
            . ($short
                ? ' Это короче нормы 120–160: допишите одно предложение о содержании страницы.'
                : ' Длина в норме (120–160).');

        return [
            'value' => $value,
            'explanation' => $explanation,
            'confidence' => $short ? 55 : 75,
        ];
    }

    private function suggestH1(array $issue, array $live, array $target): array
    {
        $label = $this->pageLabel($issue, $live, $target, true);
        if ($label === '') {
            return ['value' => null, 'explanation' => 'Не удалось определить заголовок — заполните H1 вручную.', 'confidence' => 0];
        }
        // H1 не должен дословно повторять title.
        $liveTitle = trim((string)($live['title'] ?? ''));
        if ($liveTitle !== '' && $this->norm($liveTitle) === $this->norm($label)) {
            $section = $this->sectionLabel($issue, $target);
            if ($section !== '' && $this->norm($section) !== $this->norm($label)) {
                $label = $label . ': ' . $this->lcfirst($section);
            }
        }
        $value = $this->fitLength($label, 70);
        return [
            'value' => $value,
            'explanation' => 'Заголовок H1 предложен по названию страницы, длина ' . $this->len($value) . ' симв.',
            'confidence' => 75,
        ];
    }

    // -----------------------------------------------------------------
    // Ссылки
    // -----------------------------------------------------------------

    private function suggestLink(array $issue, array $live): array
    {
        $type = (string)$issue['ISSUE_TYPE'];
        $old = trim((string)($issue['TARGET_URL'] ?? $issue['OLD_VALUE'] ?? ''));
        $final = trim((string)($issue['FINAL_URL'] ?? ''));

        // Живая проверка знает конечный адрес точнее отчёта.
        if (!empty($live['redirected']) && !empty($live['effective_url'])) {
            $final = (string)$live['effective_url'];
        }

        if ($type === 'uppercase_url' && $old !== '') {
            $fixed = $this->lowercasePath($old);
            if ($fixed === $old) {
                return ['value' => null, 'explanation' => 'Заглавные буквы только в параметрах адреса (например, PAGEN_1) — их менять нельзя.', 'confidence' => 0];
            }
            return ['value' => $fixed, 'explanation' => 'Путь адреса приведён к нижнему регистру, параметры не тронуты.', 'confidence' => 70];
        }

        if ($type === 'encoded_url' && $old !== '') {
            $decoded = rawurldecode($old);
            if ($decoded === $old) {
                return ['value' => null, 'explanation' => 'Закодированных символов в адресе не найдено.', 'confidence' => 0];
            }
            return ['value' => $decoded, 'explanation' => 'Адрес раскодирован для читаемого вида. Проверьте, что такой адрес реально открывается.', 'confidence' => 40];
        }

        if ($type === 'invalid_url' && $old !== '') {
            $fixed = (string)preg_replace('~^(https?:)/+~i', '$1//', $old);
            if ($fixed !== $old) {
                return ['value' => $fixed, 'explanation' => 'Исправлена техническая ошибка в записи протокола.', 'confidence' => 90];
            }
            return ['value' => null, 'explanation' => 'Ошибка в адресе не распознана автоматически — проверьте вручную.', 'confidence' => 0];
        }

        if (($type === 'https_to_http' || $type === 'not_https') && $old !== '') {
            $fixed = (string)preg_replace('~^http://~i', 'https://', $old);
            if ($fixed !== $old) {
                return ['value' => $fixed, 'explanation' => 'Протокол заменён на https для адреса этого же сайта.', 'confidence' => 90];
            }
            return ['value' => null, 'explanation' => 'Адрес уже использует https.', 'confidence' => 0];
        }

        if ($final !== '' && $final !== $old) {
            return [
                'value' => $final,
                'explanation' => 'Ссылку нужно вести сразу на конечный адрес — это убирает лишний редирект.',
                'confidence' => 90,
            ];
        }

        return ['value' => null, 'explanation' => 'Конечный адрес неизвестен: укажите, чем заменить ссылку, или удалите её.', 'confidence' => 0];
    }

    private function suggestAlt(array $issue, array $live, array $target): array
    {
        $label = $this->pageLabel($issue, $live, $target, true);
        $image = trim((string)($issue['TARGET_URL'] ?? ''));
        $fileName = '';
        if ($image !== '') {
            $path = (string)parse_url($image, PHP_URL_PATH);
            $fileName = pathinfo($path, PATHINFO_FILENAME);
            $fileName = $this->humanize(rawurldecode($fileName));
        }
        $value = $label !== '' ? $label : $fileName;
        if ($value === '') {
            return ['value' => null, 'explanation' => 'Недостаточно данных для описания изображения.', 'confidence' => 0];
        }
        return [
            'value' => $this->fitLength($value, 120),
            'explanation' => 'Черновик alt по названию страницы и имени файла. Проверьте, что описание соответствует картинке.',
            'confidence' => 50,
        ];
    }

    // -----------------------------------------------------------------
    // Определение названия страницы
    // -----------------------------------------------------------------

    /**
     * Лучшее доступное название страницы: живой H1 → название записи в
     * Битриксе → живой title → сегмент адреса.
     */
    public function pageLabel(array $issue, array $live = [], array $target = [], bool $preferH1 = false): string
    {
        $url = trim((string)($issue['SOURCE_URL'] ?? ''));
        $candidates = [];

        // 1. Что реально отдаёт страница прямо сейчас.
        if (!empty($live['h1']) && is_array($live['h1'])) {
            $candidates[] = (string)$live['h1'][0];
        }
        // 2. Название записи в Битриксе.
        if (!empty($target['name'])) {
            $candidates[] = (string)$target['name'];
        }
        // 3. Заголовок, известный из других отчётов по этому же адресу.
        $candidates[] = $this->knownLabel($url);

        if (!$preferH1 && !empty($live['title'])) {
            $candidates[] = $this->stripBrand((string)$live['title']);
        }
        $reported = trim((string)($issue['OLD_VALUE'] ?? ''));
        $valueField = ReportCatalog::valueField((string)$issue['ISSUE_TYPE']);
        if ($reported !== '' && in_array($valueField, ['h1', 'title'], true)) {
            $candidates[] = $this->stripBrand($reported);
        }
        // 4. Последнее средство — сегмент адреса.
        $candidates[] = $this->pathHint($issue);

        foreach ($candidates as $candidate) {
            $clean = $this->cleanText((string)$candidate);
            if ($clean === '' || $this->isStub($clean)) {
                continue;
            }
            return $this->fitLength($clean, 110);
        }
        return '';
    }

    /**
     * Заголовок этой страницы, собранный из других отчётов.
     */
    private function knownLabel(string $url): string
    {
        if ($url === '' || !class_exists(PageFacts::class)) {
            return '';
        }
        try {
            return PageFacts::labelFor($this->siteId, $url);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function knownParentLabel(string $url): string
    {
        if ($url === '' || !class_exists(PageFacts::class)) {
            return '';
        }
        try {
            return PageFacts::parentLabelFor($this->siteId, $url);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Служебные заглушки сервера не годятся как название страницы.
     */
    private function isStub(string $value): bool
    {
        return (bool)preg_match(
            '~^\d{3}\s|service (temporarily )?unavailable|not found|bad gateway|forbidden|страница не найдена~iu',
            $value
        );
    }

    /**
     * Название родительского раздела — по предпоследнему сегменту адреса
     * или по названию раздела в Битриксе.
     */
    public function sectionLabel(array $issue, array $target = []): string
    {
        $url = trim((string)($issue['SOURCE_URL'] ?? ''));
        if ($url === '') {
            return '';
        }

        // Настоящее название раздела, если оно встречалось в отчётах.
        $known = $this->knownParentLabel($url);
        if ($known !== '' && !$this->isStub($known)) {
            return $this->fitLength($this->stripBrand($known), 60);
        }

        // Слаг раздела (/catalog/, /info/) намеренно не используется:
        // «Catalog» рядом с русским заголовком выглядит как ошибка.
        // Лучше обойтись без уточнения, чем подставить транслит.
        return '';
    }

    /**
     * Человекочитаемая подсказка из последнего сегмента адреса.
     */
    private function pathHint(array $issue): string
    {
        $url = trim((string)($issue['SOURCE_URL'] ?? ''));
        $path = trim((string)parse_url($url, PHP_URL_PATH), '/');
        if ($path === '') {
            return '';
        }
        $segments = array_values(array_filter(explode('/', $path)));
        if (!$segments) {
            return '';
        }
        $last = (string)end($segments);
        $last = preg_replace('~\.(php|html?)$~i', '', $last);
        return $this->humanize(rawurldecode((string)$last));
    }

    /**
     * Превращает slug в читаемую фразу: knigi-dlya-detey → «Книги для детей»
     * не получится без словаря, поэтому просто чистим разделители.
     */
    private function humanize(string $value): string
    {
        $value = str_replace(['-', '_', '+'], ' ', $value);
        $value = (string)preg_replace('~\s+~u', ' ', $value);
        $value = trim($value);
        if ($value === '' || preg_match('~^\d+$~', $value)) {
            return '';
        }
        return $this->ucfirst($value);
    }

    /**
     * Убирает хвост с названием сайта из чужого title.
     */
    private function stripBrand(string $title): string
    {
        $brand = preg_quote($this->brand, '~');
        $title = (string)preg_replace('~\s*[|—–\-]\s*' . $brand . '\s*$~ui', '', $title);
        return trim($title);
    }

    // -----------------------------------------------------------------
    // Шаблоны и работа со строками
    // -----------------------------------------------------------------

    /**
     * Шаблон с необязательными блоками вида "{ — section}".
     * Если значение пустое, блок целиком исчезает.
     */
    private function render(string $template, array $vars): string
    {
        $result = (string)preg_replace_callback('~\{([^{}]+)\}~u', static function ($m) use ($vars) {
            $body = $m[1];
            foreach ($vars as $key => $value) {
                if (strpos($body, $key) === false) {
                    continue;
                }
                $value = trim((string)$value);
                if ($value === '') {
                    return '';
                }
                return str_replace($key, $value, $body);
            }
            return '';
        }, $template);

        $result = (string)preg_replace('~\s+~u', ' ', $result);
        $result = (string)preg_replace('~\s+([.,;:!?])~u', '$1', $result);
        $result = (string)preg_replace('~([.,;:!?]){2,}~u', '$1', $result);
        return trim($result, " \t\n\r\0\x0B-–—|:;,");
    }

    private function template(string $option, string $default): string
    {
        $value = (string)\COption::GetOptionString('relod.seofixer', $option . '_' . $this->siteId, '');
        if (trim($value) === '') {
            $value = (string)\COption::GetOptionString('relod.seofixer', $option, '');
        }
        return trim($value) !== '' ? $value : $default;
    }

    /**
     * Обрезает по границе слова, не разрывая слова посередине.
     */
    public function fitLength(string $value, int $max): string
    {
        $value = trim((string)preg_replace('~\s+~u', ' ', $value));
        if ($this->len($value) <= $max) {
            return $value;
        }
        $cut = function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
        $lastSpace = function_exists('mb_strrpos') ? mb_strrpos($cut, ' ', 0, 'UTF-8') : strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > $max * 0.6) {
            $cut = function_exists('mb_substr') ? mb_substr($cut, 0, $lastSpace, 'UTF-8') : substr($cut, 0, $lastSpace);
        }
        return rtrim($cut, " \t.,;:-–—|");
    }

    public function len(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function cleanText(string $value): string
    {
        $value = strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = (string)preg_replace('~\s+~u', ' ', $value);
        // Хвостовая пунктуация мешает склейке с разделителем: без неё
        // получается «Доставка — Информация», а не «Доставка. — Информация».
        return trim($value, " \t\n\r\0\x0B.,;:—–-|");
    }

    private function norm(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        return trim((string)preg_replace('~[^\p{L}\p{N}]+~u', ' ', $value));
    }

    private function ucfirst(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (!function_exists('mb_substr')) {
            return ucfirst($value);
        }
        return mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($value, 1, null, 'UTF-8');
    }

    private function lcfirst(string $value): string
    {
        if ($value === '' || !function_exists('mb_substr')) {
            return lcfirst($value);
        }
        return mb_strtolower(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($value, 1, null, 'UTF-8');
    }

    private function lowercasePath(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['path'])) {
            return $url;
        }
        $lowerPath = function_exists('mb_strtolower') ? mb_strtolower($parts['path'], 'UTF-8') : strtolower($parts['path']);
        if ($lowerPath === $parts['path']) {
            return $url;
        }
        $rebuilt = '';
        if (!empty($parts['scheme'])) {
            $rebuilt .= $parts['scheme'] . '://';
        }
        if (!empty($parts['host'])) {
            $rebuilt .= $parts['host'];
        }
        if (!empty($parts['port'])) {
            $rebuilt .= ':' . $parts['port'];
        }
        $rebuilt .= $lowerPath;
        if (!empty($parts['query'])) {
            $rebuilt .= '?' . $parts['query'];
        }
        if (!empty($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }
        return $rebuilt;
    }
}
