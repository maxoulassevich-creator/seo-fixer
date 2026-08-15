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
        $truncated = false;
        if ($type === 'long_title') {
            // Заголовок важнее названия сайта: если длинное название сайта
            // оставляет на сам заголовок слишком мало, оно не добавляется.
            $budget = self::TITLE_MAX - ($brand !== '' ? $this->len($brand) + 3 : 0);
            $trimmed = $this->trimToClause($label, $budget);

            // Если с названием сайта от заголовка остаётся огрызок, лучше
            // отдать все 65 символов самому заголовку: он важнее бренда.
            if ($brand !== '' && $this->len($trimmed) < 30) {
                $full = $this->trimToClause($label, self::TITLE_MAX);
                if ($this->len($full) > $this->len($trimmed)) {
                    $trimmed = $full;
                    $brand = '';
                }
            }

            $truncated = $trimmed !== $label;
            $label = $trimmed;
            $section = '';
        }

        // «Учебники — раздел Учебники» звучит как ошибка: убираем повтор.
        if ($section !== '' && $this->overlaps($section, $label)) {
            $section = '';
        }

        // Слишком общий заголовок вроде «Акции» не содержит запросов, по которым
        // страницу ищут. Смотрим на длину самого заголовка, а не строки целиком:
        // длинное название сайта не делает «Акции» информативнее.
        if (!$truncated && $this->len($label) < 20) {
            $extended = $this->titleQualifier($label, $issue);
            if ($extended !== '' && !$this->repeatsWithin($extended)) {
                $label = $extended;
            }
        }

        // Название сайта добавляем, только если оно целиком помещается рядом
        // с заголовком: обрезанное «| Интернет-маг» выглядит как ошибка.
        $core = $label . ($section !== '' ? ' – ' . $section : '');
        if ($brand !== '' && $this->len($core) + 3 + $this->len($brand) > self::TITLE_MAX) {
            $brand = '';
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

        if ($truncated) {
            // Исходный заголовок был перегружен ключевыми словами. Механическая
            // обрезка почти всегда рвёт фразу, поэтому честно предупреждаем.
            return [
                'value' => $value,
                'explanation' => 'Исходный заголовок (' . $this->len((string)$issue['OLD_VALUE']) . ' симв.) сокращён механически до ' . $len . ' симв. '
                    . 'Фраза могла оборваться — перечитайте и при необходимости перепишите: заголовок должен быть законченным и содержать главный запрос страницы.',
                'confidence' => 40,
            ];
        }

        $explanation = 'Заголовок собран из названия страницы'
            . ($section !== '' ? ', её раздела' : '')
            . ' и названия сайта, длина ' . $len . ' симв.'
            . ($short
                ? ' Короче нормы 30–65: добавьте уточнение, по какому запросу должна находиться страница.'
                : ' Длина в норме (30–65).');

        return [
            'value' => $value,
            'explanation' => $explanation,
            'confidence' => $short ? 55 : 80,
        ];
    }

    /**
     * Естественное уточнение для слишком короткого заголовка.
     * Ничего не выдумывает о содержимом — только уточняет назначение страницы.
     */
    private function titleQualifier(string $label, array $issue): string
    {
        $haystack = $this->norm($label . ' ' . (string)parse_url((string)($issue['SOURCE_URL'] ?? ''), PHP_URL_PATH));
        if ($haystack === '') {
            return '';
        }

        $map = [
            'контакт|contacts' => '%s: адреса, телефоны и часы работы',
            'акци|sales|скидк|распродаж' => '%s и специальные предложения',
            'доставк|delivery' => '%s: способы, сроки и условия',
            'оплат|payment' => '%s: способы оплаты заказа',
            'возврат|refund' => '%s: сроки и порядок обращения',
            'реквизит|requisites' => '%s компании',
            'прайс|pricelist' => '%s: цены и условия',
            'корзин|basket|cart' => '%s: оформление заказа',
            'информац|/info' => '%s для покупателей',
            'каталог|catalog' => '%s товаров',
            'блог|blog' => '%s: статьи и новости',
            'новост|news' => '%s компании',
            'вопрос|faq|помощ' => '%s покупателям',
            'о компании|about|о нас' => '%s: направления работы',
            'издательств|publisher' => '%s и их книги',
        ];

        foreach ($map as $needle => $template) {
            if (preg_match('~(' . $needle . ')~u', $haystack)) {
                return trim(sprintf($template, rtrim($label, ' .')));
            }
        }
        return '';
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
            // Обрезка по запятой тоже может оставить незаконченную фразу
            // («…купить учебную»), поэтому чистим хвост и здесь.
            return $this->dropDanglingWord(rtrim($head, ' .,;:—–-'));
        }
        return $this->dropDanglingWord($this->fitLength($value, $max));
    }

    /**
     * Убирает слова, на которых фраза не может закончиться: предлоги, союзы,
     * глаголы в неопределённой форме и прилагательные без существительного.
     *
     * «Учебники по китайскому купить лучшую методическую» → «Учебники по китайскому».
     */
    private function dropDanglingWord(string $value): string
    {
        // Инфинитивы (-ть/-ти/-чь) и однозначно прилагательные окончания.
        // Окончания вроде -ей/-ой/-их намеренно не берём: под них попадают
        // обычные существительные («дней», «людей»), и фраза резалась бы зря.
        $dangling = '~^(.*?)\s(\p{L}{3,}(?:ть|ти|чь)|\p{L}{4,}(?:ую|ые|ый|ая|ое|ого|ому|ыми|ими))$~u';
        $functionWord = '~\s(?:в|во|на|за|для|из|от|до|по|к|ко|с|со|о|об|при|над|под|про|у|и|а|но|или|что|как)$~ui';
        $prepositions = 'в|во|на|за|для|из|от|до|по|к|ко|с|со|о|об|при|над|под|про|у';

        // Оборванное перечисление после запятой: «…рабочих дней, в регионы».
        // Такой хвост не несёт законченной мысли — отбрасываем его целиком.
        $value = rtrim($value, ' ,;:—–-');
        if (preg_match('~^(.{20,}),\s*(?:' . $prepositions . ')\s+[^,]{1,25}$~u', $value, $fragment)) {
            $value = rtrim($fragment[1], ' ,;:—–-');
        }

        for ($i = 0; $i < 5; $i++) {
            if (!preg_match($dangling, $value, $m)) {
                break;
            }
            $trimmed = rtrim($m[1], ' ,;:—–-');
            // Не срезаем до бессмысленного огрызка.
            if ($this->len($trimmed) < 10) {
                break;
            }
            // Если после среза фраза кончается предлогом («Учебники по»),
            // значит срезали как раз то слово, ради которого предлог и стоял.
            if (preg_match($functionWord, $trimmed)) {
                break;
            }
            $value = $trimmed;
        }

        // Висящие служебные слова в самом конце убираем в любом случае:
        // их может оказаться несколько подряд («…от издателей и с»).
        $value = rtrim($value, ' ,;:—–-');
        for ($i = 0; $i < 4; $i++) {
            $stripped = rtrim((string)preg_replace($functionWord, '', $value), ' ,;:—–-');
            if ($stripped === $value || $this->len($stripped) < 10) {
                break;
            }
            $value = $stripped;
        }
        return $value;
    }

    private function suggestDescription(array $issue, array $live, array $target): array
    {
        $label = $this->pageLabel($issue, $live, $target);
        if ($label === '') {
            return ['value' => null, 'explanation' => 'Не удалось определить содержание страницы — напишите описание вручную.', 'confidence' => 0];
        }

        // Лучший источник — настоящий текст страницы. Он уникален сам по себе
        // и читается естественно, в отличие от собранной по шаблону фразы.
        $content = $this->cleanText((string)($live['content'] ?? ''));
        if ($content !== '') {
            $built = $this->descriptionFromContent($label, $content);
            if ($built !== '') {
                $len = $this->len($built);
                return [
                    'value' => $built,
                    'explanation' => 'Описание составлено из текста самой страницы, длина ' . $len . ' симв.'
                        . ($len < self::DESCRIPTION_MIN ? ' Это короче нормы 120–160 — при желании дополните.' : ' Длина в норме (120–160).'),
                    'confidence' => 85,
                ];
            }
        }

        // Второй источник — собственный длинный заголовок страницы. На многих
        // сайтах в title вынесено развёрнутое описание товара или раздела:
        // это настоящий текст страницы, а не выдумка модуля.
        $rich = $this->richTextFromFacts($issue, $label);
        if ($rich !== '') {
            $built = $this->descriptionFromContent($label, $rich);
            if ($built !== '' && $this->len($built) > $this->len($label) + 20) {
                $len = $this->len($built);
                return [
                    'value' => $built,
                    'explanation' => 'Описание составлено из развёрнутого заголовка самой страницы, длина ' . $len . ' симв.'
                        . ($len < self::DESCRIPTION_MIN ? ' Короче нормы 120–160 — при желании дополните.' : ' Длина в норме (120–160).'),
                    'confidence' => 70,
                ];
            }
        }

        // Третий источник — типовая формулировка для служебной страницы.
        $pattern = $this->descriptionPattern($label, $issue);
        if ($pattern !== '') {
            $value = $this->appendBrand($pattern, self::DESCRIPTION_MAX);
            $len = $this->len($value);
            return [
                'value' => $value,
                'explanation' => 'Использована типовая формулировка для страницы такого назначения, длина ' . $len . ' симв. '
                    . 'Она описывает, зачем страница нужна; добавьте конкретику — и описание станет сильнее.',
                'confidence' => 60,
            ];
        }

        $section = $this->sectionLabel($issue, $target);
        if ($section !== '' && $this->overlaps($section, $label)) {
            $section = '';
        }

        // Хвост-заглушка по умолчанию пуст: одинаковая фраза на всех страницах
        // делает описания почти дубликатами, то есть не решает исходную задачу.
        $tail = $this->template('tpl_description_tail', '');
        if ($tail !== '' && $this->overlaps($tail, $label . ' ' . $section)) {
            $tail = '';
        }

        $value = $this->render($this->template('tpl_description', '{label}{ — раздел «section»}. {tail}'), [
            'label' => $label,
            'section' => $section,
            'brand' => $this->brand,
            'tail' => $tail,
        ]);
        $value = $this->appendBrand($value, self::DESCRIPTION_MAX);

        $len = $this->len($value);
        $short = $len < self::DESCRIPTION_MIN;

        // Описание намеренно не добивается «водой»: одинаковая фраза ради
        // длины вредит сниппету сильнее, чем короткий, но точный текст.
        $explanation = 'Описание собрано из заголовка страницы'
            . ($section !== '' ? ', её раздела' : '')
            . ' и названия сайта, длина ' . $len . ' симв.'
            . ($short
                ? ' Короче нормы 120–160: страница сейчас недоступна для чтения, поэтому взять текст с неё не удалось — допишите предложение о её содержании.'
                : ' Длина в норме (120–160).');

        return [
            'value' => $value,
            'explanation' => $explanation,
            'confidence' => $short ? 45 : 70,
        ];
    }

    /**
     * Развёрнутый текст о странице, известный из отчётов: длинный title
     * или H1, который заметно содержательнее короткого названия.
     */
    private function richTextFromFacts(array $issue, string $label): string
    {
        $url = trim((string)($issue['SOURCE_URL'] ?? ''));
        if ($url === '' || !class_exists(PageFacts::class)) {
            return '';
        }
        try {
            $facts = PageFacts::get($this->siteId, $url);
        } catch (\Throwable $e) {
            return '';
        }

        foreach (['title', 'h1'] as $key) {
            $text = $this->cleanText((string)($facts[$key] ?? ''));
            if ($text === '' || PageFacts::isServiceStub($text)) {
                continue;
            }
            if ($this->len($text) < 60 || $this->norm($text) === $this->norm($label)) {
                continue;
            }
            return $this->normalizePrepositions($this->stripBrand($text));
        }
        return '';
    }

    /**
     * Типовая формулировка для страницы понятного назначения.
     *
     * Фразы описывают, зачем страница нужна, и не утверждают фактов о её
     * содержимом, которые модуль не может проверить.
     *
     * @return string Пустая строка, если назначение страницы не распознано.
     */
    private function descriptionPattern(string $label, array $issue): string
    {
        $haystack = $this->norm($label . ' ' . (string)parse_url((string)($issue['SOURCE_URL'] ?? ''), PHP_URL_PATH));
        if ($haystack === '') {
            return '';
        }

        // Формат: [что ищем, основная фраза, запасная фраза без повторов,
        //          безопасное дополнение для длины].
        //
        // %1$s — название страницы. Название сайта сюда НЕ подставляется:
        // в русском оно потребовало бы склонения («в интернет-магазине RELOD»,
        // а не «в Интернет-магазин RELOD»), а склонять произвольное имя
        // автоматически нельзя. Оно добавляется отдельным предложением.
        $patterns = [
            [
                'контакт|contacts',
                '%1$s: как связаться с компанией и где нас найти.',
                '%1$s: телефоны, адреса и часы работы.',
                'Все способы связи собраны на одной странице.',
            ],
            [
                'доставк|delivery',
                '%1$s: доступные способы получения заказа, сроки и условия.',
                '%1$s: как получить заказ, сколько это займёт и сколько стоит.',
                'Выберите подходящий вариант при оформлении.',
            ],
            [
                'оплат|payment|how to buy|оформление заказа',
                '%1$s: доступные способы оплаты и порядок оформления.',
                '%1$s: чем можно рассчитаться и что происходит после подтверждения.',
                'Все варианты перечислены на странице.',
            ],
            [
                'возврат|refund|обмен',
                '%1$s: сроки, необходимые документы и порядок обращения.',
                '%1$s: что делать, если товар не подошёл.',
                'Здесь описан весь порядок действий.',
            ],
            [
                'реквизит|requisites',
                '%1$s для оформления договоров и платёжных документов.',
                'Юридические и банковские данные для договоров и платежей.',
                'Сверяйте данные перед отправкой платежа.',
            ],
            [
                'прайс|pricelist',
                '%1$s: цены и условия покупки.',
                'Цены одним списком: %1$s.',
                'Уточняйте актуальность перед заказом.',
            ],
            [
                'акци|sales|скидк|распродаж',
                '%1$s и специальные предложения: действующие скидки и условия участия.',
                'Выгодные предложения: что сейчас продаётся дешевле и на каких условиях.',
                'Список обновляется по мере появления новых предложений.',
            ],
            [
                'корзин|basket|cart',
                '%1$s: проверьте выбранные товары и количество перед оформлением.',
                'Выбранные товары: проверьте состав и переходите к оформлению.',
                'Изменить состав можно до подтверждения заказа.',
            ],
            [
                'услови|usloviya|оферт|договор|правил',
                '%1$s: правила, права сторон и порядок оформления.',
                'Правила работы: что важно знать покупателю до заказа.',
                'Документ действует для всех заказов.',
            ],
            [
                'каталог|catalog',
                '%1$s: подбор и покупка товаров с доставкой.',
                'Ассортимент: выбирайте по разделам и оформляйте заказ онлайн.',
                'Разделы помогут быстрее найти нужное.',
            ],
            [
                'блог|blog|новост|news|стать',
                '%1$s: статьи, новости и обзоры по теме.',
                'Публикации: полезные материалы и новости компании.',
                'Новые материалы выходят регулярно.',
            ],
            [
                'о компании|about|о нас',
                '%1$s: чем занимается компания и как она работает.',
                'Коротко о компании: направления работы и подход к делу.',
                'Здесь же — контакты и реквизиты.',
            ],
            [
                'вопрос|faq|помощ|справк',
                '%1$s: ответы на частые вопросы покупателей.',
                'Помощь покупателям: разбор типовых ситуаций.',
                'Не нашли ответ — напишите нам.',
            ],
            [
                'издательств|publisher',
                '%1$s: издательства и их книги в одном разделе.',
                'Книги по издательствам: выбирайте нужное издание.',
                'Список пополняется новыми поступлениями.',
            ],
            [
                'информац|^info$|/info',
                '%1$s для покупателей: оплата, доставка, возврат и другие важные разделы.',
                'Полезные разделы: всё, что нужно знать до и после заказа.',
                'Выберите нужный раздел из списка.',
            ],
        ];

        $labelClean = rtrim($label, ' .');

        foreach ($patterns as $pattern) {
            if (!preg_match('~(' . $pattern[0] . ')~u', $haystack)) {
                continue;
            }

            $main = trim(sprintf($pattern[1], $labelClean));
            // Если основная фраза повторяет слова заголовка, берём запасную:
            // «Реквизиты компании: … реквизиты компании» читается как брак.
            if ($this->repeatsWithin($main)) {
                $alt = trim(sprintf($pattern[2], $labelClean));
                if (!$this->repeatsWithin($alt)) {
                    $main = $alt;
                }
            }

            if ($this->len($main) < self::DESCRIPTION_MIN && $pattern[3] !== '') {
                $extra = trim($pattern[3]);
                if (!$this->overlaps($extra, $main)) {
                    $main = rtrim($main, ' .') . '. ' . $extra;
                }
            }

            return $main;
        }
        return '';
    }

    /**
     * Добавляет название сайта отдельным предложением в конце.
     *
     * Именно отдельным: в русском языке название внутри фразы пришлось бы
     * склонять («доставка в интернет-магазине RELOD»), а падеж произвольного
     * имени автоматически не определить. В конце оно всегда в именительном —
     * это корректно для любого названия.
     */
    private function appendBrand(string $text, int $max): string
    {
        $text = rtrim(trim($text), ' .');
        if ($text === '' || $this->brand === '') {
            return $text === '' ? '' : $text . '.';
        }

        $suffix = '. ' . rtrim($this->brand, ' .') . '.';
        if ($this->overlaps($this->brand, $text) || $this->len($text . $suffix) > $max) {
            return $text . '.';
        }
        return $text . $suffix;
    }

    /**
     * Есть ли в одной фразе повтор одного и того же слова.
     */
    private function repeatsWithin(string $value): bool
    {
        $text = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $words = preg_split('~[^\p{L}]+~u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stems = [];
        foreach ($words as $word) {
            if ((function_exists('mb_strlen') ? mb_strlen($word, 'UTF-8') : strlen($word)) < 5) {
                continue;
            }
            $stem = function_exists('mb_substr') ? mb_substr($word, 0, 6, 'UTF-8') : substr($word, 0, 6);
            if (isset($stems[$stem])) {
                return true;
            }
            $stems[$stem] = true;
        }
        return false;
    }

    /**
     * Собирает описание из текста страницы, обрезая по границе предложения.
     */
    private function descriptionFromContent(string $label, string $content): string
    {
        $content = $this->normalizePrepositions($content);
        $value = $this->trimToSentence($content, self::DESCRIPTION_MAX);

        // Если заголовок не звучит в тексте, ставим его в начало — так сниппет
        // сразу отвечает на вопрос «что это за страница».
        if ($label !== '' && !$this->overlaps($label, $value)) {
            $prefix = rtrim($label, ' .') . '. ';
            $value = $prefix . $this->trimToSentence($content, self::DESCRIPTION_MAX - $this->len($prefix));
        }

        if ($this->len($value) < 40) {
            return '';
        }
        // Описание — законченная мысль, поэтому завершаем его точкой.
        return preg_match('~[.!?…]$~u', $value) ? $value : $value . '.';
    }

    /**
     * Набирает текст целыми предложениями, пока помещается в лимит.
     *
     * Если после целых предложений остаётся заметный запас, добавляет начало
     * следующего, обрезанное по запятой и очищенное от повисших слов, — так
     * описание получается и длинным, и законченным по смыслу.
     */
    private function trimToSentence(string $value, int $max): string
    {
        $value = trim((string)preg_replace('~\s+~u', ' ', $value));
        if ($max < 20) {
            return '';
        }
        if ($this->len($value) <= $max) {
            return $value;
        }

        $sentences = preg_split('~(?<=[.!?…])\s+~u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $result = '';
        $rest = [];

        foreach ($sentences as $index => $sentence) {
            $candidate = $result === '' ? $sentence : $result . ' ' . $sentence;
            if ($this->len($candidate) <= $max) {
                $result = $candidate;
                continue;
            }
            $rest = array_slice($sentences, $index);
            break;
        }

        // Целые предложения не набрали нужной длины — дотягиваем клаузой.
        if ($rest && $this->len($result) < self::DESCRIPTION_MIN) {
            $budget = $max - $this->len($result) - 2;
            if ($budget >= 35) {
                $piece = $this->dropDanglingWord($this->trimToClause((string)$rest[0], $budget));
                if ($this->len($piece) >= 30) {
                    $result = ($result !== '' ? rtrim($result, ' ') . ' ' : '') . $piece;
                }
            }
        }

        if ($result !== '') {
            return trim($result);
        }

        // Даже первое предложение не помещается — режем его по клаузе.
        return $this->dropDanglingWord($this->trimToClause($value, $max));
    }

    /**
     * Пересекаются ли тексты по значимым словам. Нужно, чтобы не получалось
     * «раздел «Информация» … Актуальная информация».
     */
    private function overlaps(string $a, string $b): bool
    {
        $stems = static function (string $text): array {
            $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
            $words = preg_split('~[^\p{L}]+~u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $out = [];
            foreach ($words as $word) {
                if ((function_exists('mb_strlen') ? mb_strlen($word, 'UTF-8') : strlen($word)) < 5) {
                    continue;
                }
                $out[] = function_exists('mb_substr') ? mb_substr($word, 0, 6, 'UTF-8') : substr($word, 0, 6);
            }
            return array_unique($out);
        };

        $left = $stems($a);
        if (!$left) {
            return false;
        }
        return (bool)array_intersect($left, $stems($b));
    }

    /**
     * Убирает подряд идущие предлоги — типовая опечатка в текстах сайтов
     * («условия продажи товаров в для физических лиц»).
     */
    private function normalizePrepositions(string $value): string
    {
        $prepositions = 'в|во|на|за|для|из|от|до|по|к|ко|с|со|о|об|при|над|под|про|у';
        return (string)preg_replace(
            '~\b(?:' . $prepositions . ')\s+(?=(?:' . $prepositions . ')\s)~ui',
            '',
            $value
        );
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
        // Обрезаем по смысловой границе, иначе H1 обрывается на предлоге.
        $value = $this->trimToClause($label, 70);
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
            $clean = $this->normalizePrepositions($this->cleanText((string)$candidate));
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
