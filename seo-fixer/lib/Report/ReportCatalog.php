<?php
namespace Relod\SeoFixer\Report;

/**
 * База знаний по типам проблем Netpeak Spider.
 *
 * Для каждого типа хранится: русское название, серьёзность, категория,
 * приоритет, стратегия исправления, признак автоматической правки,
 * синонимы названий (RU + EN) для распознавания и понятное объяснение.
 *
 * Стратегии (STRATEGY_*) определяют, что модуль умеет сделать сам.
 */
class ReportCatalog
{
    public const TYPE_UNKNOWN = 'unknown';

    /** Записать meta title целевой страницы. */
    public const STRATEGY_SEO_TITLE = 'seo_title';
    /** Записать meta description целевой страницы. */
    public const STRATEGY_SEO_DESCRIPTION = 'seo_description';
    /** Записать заголовок H1 целевой страницы. */
    public const STRATEGY_SEO_H1 = 'seo_h1';
    /** Заменить точный URL в текстах/свойствах/шаблонах. */
    public const STRATEGY_LINK_REPLACE = 'link_replace';
    /** Проставить alt изображению. */
    public const STRATEGY_IMAGE_ALT = 'image_alt';
    /** Проблема на стороне сервера/хостинга. */
    public const STRATEGY_SERVER = 'server';
    /** Проблема в robots.txt / meta robots. */
    public const STRATEGY_ROBOTS = 'robots';
    /** Требуется правка шаблона или компонента. */
    public const STRATEGY_TEMPLATE = 'template';
    /** Нужно решение SEO-специалиста. */
    public const STRATEGY_REVIEW = 'review';
    /** Информационная строка, править нечего. */
    public const STRATEGY_INFO = 'info';

    /** @var array<string,array>|null */
    private static $cache = null;

    /**
     * Полный каталог типов.
     *
     * @return array<string,array>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $c = [];

        // ---------------------------------------------------------------
        // META: title / description / h1 — высший приоритет
        // ---------------------------------------------------------------
        $c['duplicate_description'] = [
            'title' => 'Дубликаты Description',
            'severity' => 'error',
            'category' => 'meta',
            'priority' => 100,
            'strategy' => self::STRATEGY_SEO_DESCRIPTION,
            'auto' => true,
            'grouped' => true,
            'value_field' => 'description',
            'names' => ['duplicate descriptions', 'duplicate description', 'дубликаты description', 'дубликаты дескрипшн'],
            'what' => 'У нескольких страниц одинаковый мета-тег description.',
            'why' => 'Поисковик не понимает, чем страницы отличаются, и может занизить их в выдаче или склеить как дубли.',
            'how' => 'Написать для каждой страницы своё описание на 120–160 символов, отражающее именно её содержимое.',
        ];
        $c['duplicate_title'] = [
            'title' => 'Дубликаты Title',
            'severity' => 'error',
            'category' => 'meta',
            'priority' => 100,
            'strategy' => self::STRATEGY_SEO_TITLE,
            'auto' => true,
            'grouped' => true,
            'value_field' => 'title',
            'names' => ['duplicate titles', 'duplicate title', 'дубликаты title', 'дубликаты тайтл'],
            'what' => 'У нескольких страниц одинаковый тег title.',
            'why' => 'Одинаковые заголовки в выдаче мешают ранжированию и снижают кликабельность.',
            'how' => 'Сделать заголовок уникальным: название страницы + уточнение + название сайта.',
        ];
        $c['missing_description'] = [
            'title' => 'Отсутствующий или пустой Description',
            'severity' => 'warning',
            'category' => 'meta',
            'priority' => 96,
            'strategy' => self::STRATEGY_SEO_DESCRIPTION,
            'auto' => true,
            'value_field' => 'description',
            'names' => ['missing or empty description', 'missing description', 'отсутствующий или пустой description', 'пустой description'],
            'what' => 'На странице нет мета-описания.',
            'why' => 'Поисковик подставит случайный кусок текста, и сниппет получится нерелевантным.',
            'how' => 'Добавить описание на 120–160 символов с ключевой мыслью страницы.',
        ];
        $c['missing_title'] = [
            'title' => 'Отсутствующий или пустой Title',
            'severity' => 'error',
            'category' => 'meta',
            'priority' => 97,
            'strategy' => self::STRATEGY_SEO_TITLE,
            'auto' => true,
            'value_field' => 'title',
            'names' => ['missing or empty title', 'missing title', 'отсутствующий или пустой title', 'пустой title'],
            'what' => 'У страницы нет тега title.',
            'why' => 'Title — главный текстовый сигнал для поиска. Без него страница почти не ранжируется.',
            'how' => 'Добавить заголовок 40–65 символов.',
        ];
        $c['short_description'] = [
            'title' => 'Короткий Description',
            'severity' => 'notice',
            'category' => 'meta',
            'priority' => 92,
            'strategy' => self::STRATEGY_SEO_DESCRIPTION,
            'auto' => true,
            'value_field' => 'description',
            'names' => ['short description', 'короткий description'],
            'what' => 'Описание короче рекомендуемой длины.',
            'why' => 'Короткое описание не раскрывает содержание и хуже привлекает клики.',
            'how' => 'Расширить до 120–160 символов, добавив конкретику: что за раздел, для кого, что можно сделать.',
        ];
        $c['short_title'] = [
            'title' => 'Короткий Title',
            'severity' => 'notice',
            'category' => 'meta',
            'priority' => 90,
            'strategy' => self::STRATEGY_SEO_TITLE,
            'auto' => true,
            'value_field' => 'title',
            'names' => ['short title', 'короткий title'],
            'what' => 'Заголовок короче рекомендуемой длины.',
            'why' => 'Слишком общий заголовок вроде «Акции» не содержит запросов, по которым ищут страницу.',
            'how' => 'Дополнить уточнением и названием сайта — 40–65 символов.',
        ];
        $c['long_title'] = [
            'title' => 'Слишком длинный Title',
            'severity' => 'notice',
            'category' => 'meta',
            'priority' => 88,
            'strategy' => self::STRATEGY_SEO_TITLE,
            'auto' => true,
            'value_field' => 'title',
            'names' => ['max title length', 'long title', 'макс. длина title', 'длина title', 'слишком длинный title'],
            'what' => 'Заголовок длиннее рекомендуемой длины.',
            'why' => 'Поисковик обрежет заголовок в выдаче, и смысл потеряется.',
            'how' => 'Сократить до 65 символов, оставив главное в начале.',
        ];
        $c['long_description'] = [
            'title' => 'Слишком длинный Description',
            'severity' => 'notice',
            'category' => 'meta',
            'priority' => 86,
            'strategy' => self::STRATEGY_SEO_DESCRIPTION,
            'auto' => true,
            'value_field' => 'description',
            'names' => ['max description length', 'long description', 'макс. длина description', 'длина description'],
            'what' => 'Описание длиннее рекомендуемой длины.',
            'why' => 'Хвост описания не попадёт в сниппет.',
            'how' => 'Сократить до 160 символов.',
        ];
        $c['multiple_description'] = [
            'title' => 'Несколько тегов Description',
            'severity' => 'warning',
            'category' => 'meta',
            'priority' => 85,
            'strategy' => self::STRATEGY_SEO_DESCRIPTION,
            'auto' => true,
            'value_field' => 'description',
            'names' => ['multiple descriptions', 'несколько тегов description', 'несколько description'],
            'what' => 'На странице больше одного мета-тега description.',
            'why' => 'Поисковик выберет любой из них — результат непредсказуем.',
            'how' => 'Оставить один description. Часто второй выводит компонент или шаблон.',
        ];
        $c['multiple_title'] = [
            'title' => 'Несколько тегов Title',
            'severity' => 'warning',
            'category' => 'meta',
            'priority' => 85,
            'strategy' => self::STRATEGY_SEO_TITLE,
            'auto' => true,
            'value_field' => 'title',
            'names' => ['multiple titles', 'несколько тегов title', 'несколько title'],
            'what' => 'На странице больше одного тега title.',
            'why' => 'Поисковик учтёт только первый, остальные создают шум.',
            'how' => 'Оставить один title в шаблоне.',
        ];
        $c['duplicate_h1'] = [
            'title' => 'Дубликаты H1',
            'severity' => 'error',
            'category' => 'meta',
            'priority' => 82,
            'strategy' => self::STRATEGY_SEO_H1,
            'auto' => true,
            'grouped' => true,
            'value_field' => 'h1',
            'names' => ['duplicate h1', 'дубликаты h1'],
            'what' => 'У нескольких страниц одинаковый заголовок H1.',
            'why' => 'Страницы выглядят как дубли и конкурируют друг с другом.',
            'how' => 'Сделать H1 уникальным для каждой страницы.',
        ];
        $c['missing_h1'] = [
            'title' => 'Отсутствующий или пустой H1',
            'severity' => 'warning',
            'category' => 'meta',
            'priority' => 84,
            'strategy' => self::STRATEGY_SEO_H1,
            'auto' => true,
            'value_field' => 'h1',
            'names' => ['missing or empty h1', 'missing h1', 'отсутствующий или пустой h1', 'пустой h1'],
            'what' => 'На странице нет заголовка H1.',
            'why' => 'H1 объясняет поиску и пользователю, о чём страница.',
            'how' => 'Добавить один H1, совпадающий по смыслу с title, но не дословно.',
        ];
        $c['multiple_h1'] = [
            'title' => 'Несколько заголовков H1',
            'severity' => 'warning',
            'category' => 'meta',
            'priority' => 78,
            'strategy' => self::STRATEGY_TEMPLATE,
            'auto' => false,
            'names' => ['multiple h1 headings', 'multiple h1', 'несколько заголовков h1', 'несколько h1'],
            'what' => 'На странице больше одного H1.',
            'why' => 'Размывается главная тема страницы.',
            'how' => 'Оставить один H1, остальные перевести в H2. Обычно лишний H1 приходит из шаблона или компонента.',
        ];
        $c['long_h1'] = [
            'title' => 'Слишком длинный H1',
            'severity' => 'notice',
            'category' => 'meta',
            'priority' => 70,
            'strategy' => self::STRATEGY_SEO_H1,
            'auto' => true,
            'value_field' => 'h1',
            'names' => ['max h1 length', 'long h1', 'макс. длина h1', 'длина h1'],
            'what' => 'Заголовок H1 слишком длинный.',
            'why' => 'Длинный H1 плохо читается и размывает тему.',
            'how' => 'Сократить до 70 символов.',
        ];
        $c['same_title_h1'] = [
            'title' => 'Одинаковые Title и H1',
            'severity' => 'notice',
            'category' => 'meta',
            'priority' => 64,
            'strategy' => self::STRATEGY_SEO_TITLE,
            'auto' => true,
            'value_field' => 'title',
            'names' => ['same title and h1', 'одинаковые title и h1'],
            'what' => 'Title и H1 совпадают слово в слово.',
            'why' => 'Теряется возможность охватить два разных запроса.',
            'how' => 'Оставить H1 как есть, а в title добавить уточнение и название сайта.',
        ];
        $c['title_special_chars'] = [
            'title' => 'Спецсимволы в Title',
            'severity' => 'notice',
            'category' => 'meta',
            'priority' => 55,
            'strategy' => self::STRATEGY_SEO_TITLE,
            'auto' => true,
            'value_field' => 'title',
            'names' => ['bad title format', 'спецсимволы в title', 'символы в title'],
            'what' => 'В заголовке есть символы, которые поиск может отобразить некорректно.',
            'why' => 'Сниппет выглядит неаккуратно.',
            'how' => 'Убрать эмодзи и лишние спецсимволы.',
        ];
        $c['description_special_chars'] = [
            'title' => 'Спецсимволы в Description',
            'severity' => 'notice',
            'category' => 'meta',
            'priority' => 54,
            'strategy' => self::STRATEGY_SEO_DESCRIPTION,
            'auto' => true,
            'value_field' => 'description',
            'names' => ['bad description format', 'спецсимволы в description', 'символы в description'],
            'what' => 'В описании есть символы, которые поиск может отобразить некорректно.',
            'why' => 'Сниппет выглядит неаккуратно.',
            'how' => 'Убрать эмодзи и лишние спецсимволы.',
        ];

        // ---------------------------------------------------------------
        // Доступность страниц
        // ---------------------------------------------------------------
        $c['broken_pages'] = [
            'title' => 'Битые страницы',
            'severity' => 'error',
            'category' => 'availability',
            'priority' => 76,
            'strategy' => self::STRATEGY_SERVER,
            'auto' => false,
            'names' => ['broken pages', 'битые страницы', 'недоступные страницы'],
            'what' => 'Страница недоступна: сервер вернул ошибку вместо содержимого.',
            'why' => 'Пользователь и поисковый робот не видят страницу; ссылки на неё теряют вес.',
            'how' => 'Это не правится текстом: нужно разобраться на сервере. Модуль проверяет код ответа в реальном времени и показывает, какие страницы уже восстановились.',
        ];
        $c['error_5xx'] = [
            'title' => '5xx: ошибка сервера',
            'severity' => 'error',
            'category' => 'availability',
            'priority' => 77,
            'strategy' => self::STRATEGY_SERVER,
            'auto' => false,
            'names' => ['5xx error pages: server error', '5xx error pages', '5xx', 'ошибка сервера'],
            'what' => 'Сервер отвечает кодом 5xx (например, 503).',
            'why' => 'Массовые 5xx означают, что сайт был недоступен во время сканирования — это критично для индексации.',
            'how' => 'Проверить нагрузку, лимиты хостинга и режим технических работ. Если 503 отдавался из-за защиты от сканера, повторить обход с меньшей скоростью.',
        ];
        $c['error_4xx'] = [
            'title' => '4xx: ошибка клиента',
            'severity' => 'error',
            'category' => 'availability',
            'priority' => 74,
            'strategy' => self::STRATEGY_LINK_REPLACE,
            'auto' => true,
            'names' => ['4xx error pages: client error', '4xx error pages', '4xx', 'ошибка клиента'],
            'what' => 'Страница отдаёт 404 или другой код 4xx.',
            'why' => 'Ссылки ведут в никуда, вес теряется.',
            'how' => 'Удалить или заменить ссылки на рабочий адрес, либо настроить редирект.',
        ];
        $c['broken_links'] = [
            'title' => 'Битые ссылки',
            'severity' => 'error',
            'category' => 'links',
            'priority' => 72,
            'strategy' => self::STRATEGY_LINK_REPLACE,
            'auto' => true,
            'link_level' => true,
            'names' => ['broken links', 'битые ссылки'],
            'what' => 'Со страницы стоит ссылка на недоступный адрес.',
            'why' => 'Посетитель попадает на ошибку, а внутренний вес утекает в пустоту.',
            'how' => 'Заменить ссылку на рабочий адрес или убрать её. Модуль проверяет адрес вживую: если он уже отвечает нормально, пункт закрывается автоматически.',
        ];
        $c['broken_redirect'] = [
            'title' => 'Битый редирект',
            'severity' => 'error',
            'category' => 'redirects',
            'priority' => 71,
            'strategy' => self::STRATEGY_LINK_REPLACE,
            'auto' => true,
            'names' => ['broken redirect', 'битый редирект'],
            'what' => 'Редирект ведёт на недоступную страницу.',
            'why' => 'Это то же самое, что битая ссылка, только в два шага.',
            'how' => 'Исправить цель редиректа или заменить исходную ссылку на рабочий адрес.',
        ];
        $c['long_response_time'] = [
            'title' => 'Долгий ответ сервера',
            'severity' => 'warning',
            'category' => 'performance',
            'priority' => 60,
            'strategy' => self::STRATEGY_SERVER,
            'auto' => false,
            'names' => ['long server response time', 'долгий ответ сервера', 'время ответа сервера'],
            'what' => 'Сервер отвечает дольше рекомендованного времени.',
            'why' => 'Скорость — фактор ранжирования и конверсии.',
            'how' => 'Включить кеширование компонентов, проверить тяжёлые запросы и настройки хостинга. Модуль замеряет реальное время ответа при проверке.',
        ];

        // ---------------------------------------------------------------
        // Редиректы и ссылки
        // ---------------------------------------------------------------
        $c['redirect_links'] = [
            'title' => 'Ссылки на редиректы',
            'severity' => 'warning',
            'category' => 'redirects',
            'priority' => 68,
            'strategy' => self::STRATEGY_LINK_REPLACE,
            'auto' => true,
            'link_level' => true,
            'names' => ['redirects: incoming links and final urls', 'redirects incoming links and final urls', 'редиректы входящие ссылки'],
            'what' => 'Внутренняя ссылка ведёт на адрес, который отдаёт редирект.',
            'why' => 'Лишний переход замедляет загрузку и немного теряет вес ссылки.',
            'how' => 'Заменить ссылку сразу на конечный адрес. Это самое безопасное автоматическое исправление — модуль ищет точное совпадение и меняет только его.',
        ];
        $c['redirected_pages'] = [
            'title' => '3xx: страницы с редиректом',
            'severity' => 'warning',
            'category' => 'redirects',
            'priority' => 66,
            'strategy' => self::STRATEGY_LINK_REPLACE,
            'auto' => true,
            'names' => ['3xx redirected pages', '3xx', 'redirected pages', 'страницы с редиректом'],
            'what' => 'Страница отдаёт редирект на другой адрес.',
            'why' => 'Если на такой адрес ведут внутренние ссылки, каждый переход стоит лишнего запроса.',
            'how' => 'Заменить внутренние ссылки на конечный адрес. Сам редирект обычно оставляют.',
        ];
        $c['redirect_chain'] = [
            'title' => 'Цепочка редиректов',
            'severity' => 'warning',
            'category' => 'redirects',
            'priority' => 67,
            'strategy' => self::STRATEGY_LINK_REPLACE,
            'auto' => true,
            'names' => ['max redirections', 'redirect chain', 'цепочка редиректов', 'цепочки редиректов'],
            'what' => 'Адрес проходит через несколько редиректов подряд.',
            'why' => 'Каждое звено теряет вес и время.',
            'how' => 'Сократить цепочку до одного шага и заменить ссылки на конечный адрес.',
        ];
        $c['endless_redirect'] = [
            'title' => 'Бесконечный редирект',
            'severity' => 'error',
            'category' => 'redirects',
            'priority' => 73,
            'strategy' => self::STRATEGY_REVIEW,
            'auto' => false,
            'names' => ['endless redirect', 'бесконечный редирект', 'зацикленный редирект'],
            'what' => 'Адрес перенаправляет сам на себя по кругу.',
            'why' => 'Страница не открывается вообще.',
            'how' => 'Найти правило редиректа в .htaccess, настройках сайта или коде и убрать цикл.',
        ];
        $c['redirect_blocked_robots'] = [
            'title' => 'Редирект заблокирован в robots.txt',
            'severity' => 'error',
            'category' => 'redirects',
            'priority' => 62,
            'strategy' => self::STRATEGY_ROBOTS,
            'auto' => false,
            'names' => ['redirect blocked by robots.txt', 'редирект заблокирован'],
            'what' => 'Редирект ведёт на адрес, закрытый в robots.txt.',
            'why' => 'Робот не дойдёт до конечной страницы.',
            'how' => 'Либо убрать ссылку на такой адрес, либо открыть цель в robots.txt.',
        ];
        $c['empty_anchor'] = [
            'title' => 'Ссылки с пустым анкором',
            'severity' => 'notice',
            'category' => 'links',
            'priority' => 50,
            'strategy' => self::STRATEGY_TEMPLATE,
            'auto' => false,
            'names' => ['hyperlinks with empty anchor text', 'пустыми анкорами', 'пустой анкор'],
            'what' => 'У ссылки нет текста — обычно это иконка или картинка без alt.',
            'why' => 'Поиск не понимает, о чём страница по ссылке.',
            'how' => 'Добавить текст ссылки, alt у картинки или aria-label. Чаще всего это шаблон меню, футера или карточки товара.',
        ];
        $c['internal_nofollow'] = [
            'title' => 'Внутренние nofollow-ссылки',
            'severity' => 'notice',
            'category' => 'links',
            'priority' => 46,
            'strategy' => self::STRATEGY_REVIEW,
            'auto' => false,
            'names' => ['internal nofollow links', 'внутренние nofollow'],
            'what' => 'На внутренней ссылке стоит rel="nofollow".',
            'why' => 'Внутренний вес не передаётся по такой ссылке.',
            'how' => 'Решать точечно: со служебных страниц (корзина, личный кабинет) nofollow уместен, с продвигаемых — нет. Массово снимать нельзя.',
        ];
        $c['external_nofollow'] = [
            'title' => 'Внешние nofollow-ссылки',
            'severity' => 'notice',
            'category' => 'links',
            'priority' => 30,
            'strategy' => self::STRATEGY_INFO,
            'auto' => false,
            'names' => ['external nofollow links', 'внешние nofollow'],
            'what' => 'У внешней ссылки стоит rel="nofollow".',
            'why' => 'Обычно это нормально и даже правильно.',
            'how' => 'Чаще всего действий не требуется.',
        ];
        $c['self_links'] = [
            'title' => 'Циклические ссылки',
            'severity' => 'notice',
            'category' => 'links',
            'priority' => 40,
            'strategy' => self::STRATEGY_TEMPLATE,
            'auto' => false,
            'names' => ['self-referencing hyperlinks', 'self referencing hyperlinks', 'циклические гиперссылки'],
            'what' => 'Страница ссылается сама на себя.',
            'why' => 'Небольшая потеря внутреннего веса, обычно некритично.',
            'how' => 'В хлебных крошках и меню текущий пункт делают не ссылкой, а текстом.',
        ];
        $c['max_internal_links'] = [
            'title' => 'Слишком много внутренних ссылок',
            'severity' => 'notice',
            'category' => 'links',
            'priority' => 44,
            'strategy' => self::STRATEGY_TEMPLATE,
            'auto' => false,
            'names' => ['max internal links', 'макс. количество внутренних', 'много внутренних ссылок'],
            'what' => 'На странице очень много внутренних ссылок.',
            'why' => 'Вес размазывается тонким слоем, робот хуже выделяет главное.',
            'how' => 'Сократить мегаменю, футер и блоки перелинковки. Правится только в шаблоне.',
        ];
        $c['max_external_links'] = [
            'title' => 'Слишком много внешних ссылок',
            'severity' => 'notice',
            'category' => 'links',
            'priority' => 34,
            'strategy' => self::STRATEGY_TEMPLATE,
            'auto' => false,
            'names' => ['max external links', 'макс. количество внешних', 'много внешних ссылок'],
            'what' => 'На странице много внешних ссылок.',
            'why' => 'Часть веса уходит на другие сайты.',
            'how' => 'Проверить блок партнёров/издателей и при необходимости закрыть часть ссылок.',
        ];

        // ---------------------------------------------------------------
        // PageRank
        // ---------------------------------------------------------------
        $c['pagerank_dead_end'] = [
            'title' => 'PageRank: тупик',
            'severity' => 'error',
            'category' => 'pagerank',
            'priority' => 58,
            'strategy' => self::STRATEGY_REVIEW,
            'auto' => false,
            'names' => ['pagerank: dead end', 'pagerank dead end', 'pagerank тупик', 'отсутствуют исходящие'],
            'what' => 'Страница получает вес, но никуда его не передаёт.',
            'why' => 'Внутренний вес накапливается и не работает на другие страницы.',
            'how' => 'Добавить осмысленные ссылки со страницы. Если страница недоступна (5xx/404) — сначала чинить доступность: тупик исчезнет сам.',
        ];
        $c['pagerank_orphan'] = [
            'title' => 'PageRank: страница-сирота',
            'severity' => 'notice',
            'category' => 'pagerank',
            'priority' => 56,
            'strategy' => self::STRATEGY_REVIEW,
            'auto' => false,
            'names' => ['pagerank: orphan', 'pagerank orphan', 'pagerank сирота', 'отсутствуют связи'],
            'what' => 'На страницу нет внутренних ссылок.',
            'why' => 'Робот может её вообще не найти.',
            'how' => 'Добавить ссылку из меню, каталога или блока перелинковки. Для служебных страниц (корзина) это нормально.',
        ];
        $c['pagerank_redirect'] = [
            'title' => 'PageRank: перенаправление',
            'severity' => 'warning',
            'category' => 'pagerank',
            'priority' => 63,
            'strategy' => self::STRATEGY_LINK_REPLACE,
            'auto' => true,
            'names' => ['pagerank: redirect', 'pagerank redirect', 'pagerank перенаправление'],
            'what' => 'Вес уходит через редирект.',
            'why' => 'Часть веса теряется на промежуточном шаге.',
            'how' => 'Заменить ссылку на конечный адрес.',
        ];

        // ---------------------------------------------------------------
        // Индексация
        // ---------------------------------------------------------------
        $c['blocked_by_robots'] = [
            'title' => 'Заблокировано в robots.txt',
            'severity' => 'warning',
            'category' => 'indexing',
            'priority' => 61,
            'strategy' => self::STRATEGY_ROBOTS,
            'auto' => false,
            'names' => ['blocked by robots.txt', 'заблокировано в robots'],
            'what' => 'Страница закрыта директивой в robots.txt.',
            'why' => 'Если закрыт нужный раздел — он выпадет из поиска. Если служебный — всё в порядке.',
            'how' => 'Сверить список закрытых адресов со структурой сайта. Модуль показывает конкретную директиву, которая блокирует адрес.',
        ];
        $c['blocked_by_meta_robots'] = [
            'title' => 'Заблокировано в meta robots',
            'severity' => 'warning',
            'category' => 'indexing',
            'priority' => 61,
            'strategy' => self::STRATEGY_ROBOTS,
            'auto' => false,
            'names' => ['blocked by meta robots', 'nofollowed by meta robots', 'заблокировано meta robots'],
            'what' => 'Страница закрыта тегом meta robots noindex.',
            'why' => 'Страница не попадёт в индекс.',
            'how' => 'Проверить, действительно ли страницу нужно скрывать.',
        ];
        $c['blocked_by_x_robots'] = [
            'title' => 'Заблокировано в X-Robots-Tag',
            'severity' => 'warning',
            'category' => 'indexing',
            'priority' => 61,
            'strategy' => self::STRATEGY_ROBOTS,
            'auto' => false,
            'names' => ['blocked by x-robots-tag', 'x-robots-tag'],
            'what' => 'Страница закрыта HTTP-заголовком X-Robots-Tag.',
            'why' => 'Страница не попадёт в индекс, и это не видно в HTML.',
            'how' => 'Проверить настройки сервера и приложения.',
        ];
        $c['missing_robots_txt'] = [
            'title' => 'Отсутствует robots.txt',
            'severity' => 'notice',
            'category' => 'indexing',
            'priority' => 48,
            'strategy' => self::STRATEGY_ROBOTS,
            'auto' => false,
            'names' => ['missing or empty robots.txt file', 'missing robots.txt', 'отсутствующий robots'],
            'what' => 'Файл robots.txt не найден или пуст.',
            'why' => 'Нет управления обходом: робот пойдёт по служебным разделам.',
            'how' => 'Создать robots.txt через «Маркетинг → Поисковая оптимизация → Управление robots.txt» в Битриксе.',
        ];
        $c['noncanonical_pages'] = [
            'title' => 'Неканонические страницы',
            'severity' => 'warning',
            'category' => 'indexing',
            'priority' => 57,
            'strategy' => self::STRATEGY_REVIEW,
            'auto' => false,
            'names' => ['non-canonical pages', 'non canonical pages', 'неканонические страницы'],
            'what' => 'Страница указывает canonical на другой адрес.',
            'why' => 'Она сама не будет ранжироваться — весь вес уходит на канонический адрес.',
            'how' => 'Проверить, правда ли эта страница должна быть скрыта из поиска.',
        ];
        $c['same_canonical'] = [
            'title' => 'Одинаковые canonical URL',
            'severity' => 'warning',
            'category' => 'indexing',
            'priority' => 57,
            'strategy' => self::STRATEGY_REVIEW,
            'auto' => false,
            'names' => ['identical canonical urls', 'same canonical urls', 'одинаковые канонические url'],
            'what' => 'Несколько разных страниц указывают один и тот же canonical.',
            'why' => 'Все они схлопнутся в одну в глазах поиска.',
            'how' => 'Разделить: у самостоятельных страниц canonical должен указывать сам на себя.',
        ];
        $c['canonical_chain'] = [
            'title' => 'Цепочка canonical',
            'severity' => 'error',
            'category' => 'indexing',
            'priority' => 57,
            'strategy' => self::STRATEGY_REVIEW,
            'auto' => false,
            'names' => ['canonical chain', 'цепочка canonical'],
            'what' => 'Canonical ведёт на страницу, у которой свой canonical.',
            'why' => 'Поиск может проигнорировать всю цепочку.',
            'how' => 'Все canonical в группе должны указывать на один конечный адрес.',
        ];

        // ---------------------------------------------------------------
        // Контент и вёрстка
        // ---------------------------------------------------------------
        $c['missing_alt'] = [
            'title' => 'Изображения без alt',
            'severity' => 'warning',
            'category' => 'content',
            'priority' => 52,
            'strategy' => self::STRATEGY_IMAGE_ALT,
            'auto' => true,
            'names' => ['images without alt attribute', 'missing alt', 'изображения без атрибута alt', 'без alt'],
            'what' => 'У изображения нет атрибута alt.',
            'why' => 'Поиск по картинкам не видит изображение, а незрячим пользователям нечего прочитать.',
            'how' => 'Добавить описание изображения. Модуль предлагает черновик по названию товара, раздела или файла.',
        ];
        $c['max_html_size'] = [
            'title' => 'Слишком большой HTML',
            'severity' => 'notice',
            'category' => 'performance',
            'priority' => 45,
            'strategy' => self::STRATEGY_TEMPLATE,
            'auto' => false,
            'names' => ['max html size', 'макс. размер html', 'размер html'],
            'what' => 'HTML-код страницы очень тяжёлый.',
            'why' => 'Страница дольше грузится, робот тратит на неё больше ресурса обхода.',
            'how' => 'Обычно виноваты мегаменю, слайдеры и скрытые блоки, которые рендерятся целиком. Правится в шаблоне.',
        ];
        $c['min_text_html'] = [
            'title' => 'Низкое соотношение Text/HTML',
            'severity' => 'notice',
            'category' => 'performance',
            'priority' => 43,
            'strategy' => self::STRATEGY_TEMPLATE,
            'auto' => false,
            'names' => ['min text/html ratio', 'min texthtml ratio', 'text/html', 'соотношение texthtml'],
            'what' => 'Полезного текста мало относительно объёма кода.',
            'why' => 'Косвенный признак «пустой» страницы.',
            'how' => 'Добавить осмысленный текст в раздел и/или облегчить шаблон.',
        ];
        $c['max_content_size'] = [
            'title' => 'Слишком большой контент',
            'severity' => 'notice',
            'category' => 'performance',
            'priority' => 35,
            'strategy' => self::STRATEGY_REVIEW,
            'auto' => false,
            'names' => ['max content size', 'макс. размер контента', 'размер контента'],
            'what' => 'Текста на странице очень много.',
            'why' => 'Обычно не проблема, но стоит проверить читаемость.',
            'how' => 'Разбить на подразделы или отдельные страницы.',
        ];
        $c['duplicate_text'] = [
            'title' => 'Дубликаты текста',
            'severity' => 'error',
            'category' => 'content',
            'priority' => 59,
            'strategy' => self::STRATEGY_REVIEW,
            'auto' => false,
            'grouped' => true,
            'names' => ['duplicate text', 'duplicate pages', 'дубликаты текста', 'дубликаты страниц'],
            'what' => 'У нескольких страниц полностью совпадает текст.',
            'why' => 'Поиск оставит в индексе одну и отбросит остальные.',
            'how' => 'Переписать тексты или объединить страницы и настроить редирект.',
        ];

        // ---------------------------------------------------------------
        // URL
        // ---------------------------------------------------------------
        $c['uppercase_url'] = [
            'title' => 'URL с заглавными буквами',
            'severity' => 'notice',
            'category' => 'url',
            'priority' => 38,
            'strategy' => self::STRATEGY_LINK_REPLACE,
            'auto' => true,
            'names' => ['urls with uppercase characters', 'заглавными буквами'],
            'what' => 'В адресе есть заглавные буквы.',
            'why' => 'Один и тот же материал доступен по двум адресам — риск дублей.',
            'how' => 'Привести ссылки к нижнему регистру. Важно: параметры вроде PAGEN_1 трогать нельзя — модуль их не меняет.',
        ];
        $c['encoded_url'] = [
            'title' => 'URL с процентным кодированием',
            'severity' => 'notice',
            'category' => 'url',
            'priority' => 37,
            'strategy' => self::STRATEGY_LINK_REPLACE,
            'auto' => true,
            'names' => ['percent-encoded urls', 'percent encoded urls', 'кодированные url'],
            'what' => 'В адресе есть закодированные символы (кириллица, пробелы).',
            'why' => 'Такие адреса плохо читаются и хуже выглядят в выдаче.',
            'how' => 'Перевести адрес на латиницу через транслитерацию.',
        ];
        $c['url_special_chars'] = [
            'title' => 'URL со спецсимволами',
            'severity' => 'notice',
            'category' => 'url',
            'priority' => 36,
            'strategy' => self::STRATEGY_LINK_REPLACE,
            'auto' => true,
            'names' => ['urls with non-ascii characters', 'нежелательными спецсимволами', 'спецсимволами в url'],
            'what' => 'В адресе есть нежелательные символы.',
            'why' => 'Возможны ошибки при переходе и копировании ссылки.',
            'how' => 'Оставить только латиницу, цифры, дефис и слеш.',
        ];
        $c['long_url'] = [
            'title' => 'Слишком длинный URL',
            'severity' => 'notice',
            'category' => 'url',
            'priority' => 32,
            'strategy' => self::STRATEGY_REVIEW,
            'auto' => false,
            'names' => ['max url length', 'макс. длина url', 'длинный url'],
            'what' => 'Адрес страницы очень длинный.',
            'why' => 'Хуже воспринимается пользователями и в выдаче.',
            'how' => 'Сократить символьный код раздела или элемента.',
        ];
        $c['invalid_url'] = [
            'title' => 'Неправильный формат URL',
            'severity' => 'error',
            'category' => 'url',
            'priority' => 69,
            'strategy' => self::STRATEGY_LINK_REPLACE,
            'auto' => true,
            'names' => ['bad url format', 'invalid url format', 'invalid url', 'неправильным форматом url'],
            'what' => 'Адрес записан с ошибкой (например, три слеша после протокола).',
            'why' => 'Ссылка не работает.',
            'how' => 'Исправить опечатку в адресе. Модуль умеет чинить типовые технические ошибки автоматически.',
        ];
        $c['https_to_http'] = [
            'title' => 'HTTPS-страница ссылается на HTTP',
            'severity' => 'warning',
            'category' => 'url',
            'priority' => 65,
            'strategy' => self::STRATEGY_LINK_REPLACE,
            'auto' => true,
            'names' => ['https pages with http resources', 'https pages that link to http', 'https → http', 'https to http'],
            'what' => 'С защищённой страницы стоит ссылка на незащищённый адрес.',
            'why' => 'Браузер ругается на смешанное содержимое, часть ресурсов может не загрузиться.',
            'how' => 'Заменить http:// на https:// для адресов своего сайта. Модуль делает это точной заменой.',
        ];
        $c['not_https'] = [
            'title' => 'Не HTTPS-протокол',
            'severity' => 'warning',
            'category' => 'url',
            'priority' => 65,
            'strategy' => self::STRATEGY_LINK_REPLACE,
            'auto' => true,
            'names' => ['non-https protocol', 'not https', 'не https протокол'],
            'what' => 'Адрес использует незащищённый протокол.',
            'why' => 'Небезопасно и хуже ранжируется.',
            'how' => 'Перевести на https.',
        ];

        // ---------------------------------------------------------------
        // Служебные отчёты
        // ---------------------------------------------------------------
        $c['issue_overview'] = [
            'title' => 'Сводка по ошибкам',
            'severity' => 'info',
            'category' => 'meta_report',
            'priority' => 20,
            'strategy' => self::STRATEGY_INFO,
            'auto' => false,
            'expands' => true,
            'names' => ['issue overview', 'issue-overview', 'сводка по ошибкам'],
            'what' => 'Общая сводка Netpeak: какие проблемы найдены и сколько страниц затронуто.',
            'why' => 'Помогает понять масштаб, но сама по себе ничего не исправляет.',
            'how' => 'Модуль разворачивает сводку в список проблем с приоритетами и официальным объяснением Netpeak.',
        ];
        $c['url_issues'] = [
            'title' => 'URL и их ошибки',
            'severity' => 'info',
            'category' => 'meta_report',
            'priority' => 25,
            'strategy' => self::STRATEGY_INFO,
            'auto' => false,
            'expands' => true,
            'names' => ['urls and their issues', 'url и их ошибки'],
            'what' => 'Построчный список: адрес — какая на нём проблема.',
            'why' => 'Самый полный отчёт: покрывает сразу все типы проблем.',
            'how' => 'Модуль разбирает каждую строку и превращает её в отдельную карточку нужного типа.',
        ];
        $c[self::TYPE_UNKNOWN] = [
            'title' => 'Нераспознанный отчёт',
            'severity' => 'info',
            'category' => 'meta_report',
            'priority' => 10,
            'strategy' => self::STRATEGY_REVIEW,
            'auto' => false,
            'names' => [],
            'what' => 'Модуль не смог определить тип отчёта по имени файла и заголовкам колонок.',
            'why' => 'Без типа нельзя подобрать правильное исправление.',
            'how' => 'Проверьте, что файл выгружен из Netpeak Spider без переименования. Данные строк всё равно сохранены и видны в карточке.',
        ];

        self::$cache = $c;
        return $c;
    }

    public static function get(string $type): array
    {
        $all = self::all();
        return $all[$type] ?? $all[self::TYPE_UNKNOWN];
    }

    public static function exists(string $type): bool
    {
        return isset(self::all()[$type]);
    }

    public static function title(string $type): string
    {
        return (string)self::get($type)['title'];
    }

    public static function priority(string $type): int
    {
        return (int)self::get($type)['priority'];
    }

    public static function severity(string $type): string
    {
        return (string)self::get($type)['severity'];
    }

    public static function category(string $type): string
    {
        return (string)self::get($type)['category'];
    }

    public static function strategy(string $type): string
    {
        return (string)self::get($type)['strategy'];
    }

    public static function isAuto(string $type): bool
    {
        return !empty(self::get($type)['auto']);
    }

    public static function isGrouped(string $type): bool
    {
        return !empty(self::get($type)['grouped']);
    }

    public static function expands(string $type): bool
    {
        return !empty(self::get($type)['expands']);
    }

    /** Поле страницы, которое надо переписать: title | description | h1 | null */
    public static function valueField(string $type): ?string
    {
        $v = self::get($type)['value_field'] ?? null;
        return $v ? (string)$v : null;
    }

    /** Понятное объяснение: что, чем грозит, как чинить. */
    public static function explain(string $type): array
    {
        $meta = self::get($type);
        return [
            'what' => (string)($meta['what'] ?? ''),
            'why' => (string)($meta['why'] ?? ''),
            'how' => (string)($meta['how'] ?? ''),
        ];
    }

    /** Все типы, отсортированные по убыванию приоритета. */
    public static function byPriority(): array
    {
        $all = self::all();
        uasort($all, static function ($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });
        return $all;
    }

    public static function categoryTitle(string $category): string
    {
        $map = [
            'meta' => 'Мета-теги и заголовки',
            'availability' => 'Доступность страниц',
            'links' => 'Ссылки',
            'redirects' => 'Редиректы',
            'pagerank' => 'Внутренний вес',
            'indexing' => 'Индексация',
            'content' => 'Контент',
            'performance' => 'Скорость и вёрстка',
            'url' => 'Адреса страниц',
            'meta_report' => 'Служебные отчёты',
        ];
        return $map[$category] ?? $category;
    }

    public static function severityTitle(string $severity): string
    {
        $map = [
            'error' => 'Ошибка',
            'warning' => 'Предупреждение',
            'notice' => 'Замечание',
            'info' => 'Информация',
        ];
        return $map[$severity] ?? $severity;
    }

    public static function strategyTitle(string $strategy): string
    {
        $map = [
            self::STRATEGY_SEO_TITLE => 'Замена title',
            self::STRATEGY_SEO_DESCRIPTION => 'Замена description',
            self::STRATEGY_SEO_H1 => 'Замена H1',
            self::STRATEGY_LINK_REPLACE => 'Замена ссылки в контенте',
            self::STRATEGY_IMAGE_ALT => 'Заполнение alt',
            self::STRATEGY_SERVER => 'Сервер / хостинг',
            self::STRATEGY_ROBOTS => 'Индексация и robots',
            self::STRATEGY_TEMPLATE => 'Правка шаблона',
            self::STRATEGY_REVIEW => 'Решение SEO-специалиста',
            self::STRATEGY_INFO => 'Информация',
        ];
        return $map[$strategy] ?? $strategy;
    }
}
