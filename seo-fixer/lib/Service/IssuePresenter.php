<?php
namespace Relod\SeoFixer\Service;

class IssuePresenter
{
    public static function statusTitle(string $status): string
    {
        $map = [
            'new' => 'Нужно проверить',
            'approved' => 'Отмечено к исправлению',
            'skipped' => 'Пропущено',
            'applied' => 'Исправлено',
            'failed' => 'Не выполнено',
            'manual' => 'Нужна ручная правка',
        ];
        return $map[$status] ?? $status;
    }

    public static function riskTitle(string $risk): string
    {
        $map = [
            'low' => 'Низкий риск: можно применить после проверки',
            'medium' => 'Средний риск: проверьте и при необходимости отредактируйте',
            'content' => 'Нужно подготовить текст/значение, без правки кода',
            'review' => 'Нужна SEO-проверка решения, не обязательно разработчик',
            'template' => 'Вероятно шаблон/компонент: показать файл и строку разработчику',
            'manual' => 'Ручная задача только если модуль не найдёт безопасное место правки',
        ];
        return $map[$risk] ?? $risk;
    }

    public static function explain(array $issue): string
    {
        $type = (string)$issue['ISSUE_TYPE'];
        $messages = [
            'redirect_links' => 'Ссылка ведёт на старый адрес с редиректом. После подтверждения модуль заменит точное совпадение на конечный адрес там, где найдёт его в разрешённых полях.',
            'redirect_chain' => 'Найдена цепочка редиректов. Безопасное действие — заменить исходную ссылку на конечный URL, если он есть в отчёте.',
            'pagerank_redirect' => 'PageRank уходит через перенаправление. Безопасное действие — заменить старую ссылку на конечный URL при точном совпадении.',
            'https_to_http' => 'HTTPS-страница ссылается на HTTP-адрес. После подтверждения модуль попробует заменить http://shop.relod.ru на https://shop.relod.ru.',
            'not_https' => 'Найден внутренний HTTP-адрес. После подтверждения модуль попробует заменить его на HTTPS.',
            'invalid_url' => 'Найдена ссылка с неправильным форматом. Если модуль распознал однозначную техническую ошибку, он предлагает исправленный URL; иначе оставляет задачу на проверку.',
            'missing_alt' => 'У изображения нет alt. Модуль подготавливает черновик alt по данным строки отчёта; его нужно проверить перед переносом.',
            'empty_anchor' => 'Ссылка есть, но у неё нет понятного текста. Частая причина — картинка-ссылка без alt или шаблонный блок.',
            'internal_nofollow' => 'На внутренней ссылке стоит nofollow. Массовое снятие запрещено: нужно понять, важная ли ссылка для индексации.',
            'external_nofollow' => 'У внешней ссылки стоит nofollow. Это может быть нормальным для рекламных, пользовательских или недоверенных ссылок.',
            'self_links' => 'Страница ссылается сама на себя. Часто это меню, хлебные крошки или карточка в списке.',
            'max_html_size' => 'Страница содержит слишком много HTML. Модуль не удаляет части шаблона автоматически, но ищет возможные участки в шаблонных файлах.',
            'min_text_html' => 'Полезного текста мало относительно HTML-кода. Обычно причина в меню, футере, фильтрах, слайдерах или скрытых блоках.',
            'same_canonical' => 'Несколько страниц указывают один canonical. Нужно выбрать основной адрес и проверить назначение каждой страницы.',
            'noncanonical_pages' => 'Страница не является основной по canonical. Нужно проверить, должна ли она продвигаться отдельно.',
        ];
        if (isset($messages[$type])) {
            return $messages[$type];
        }
        if (strpos($type, 'title') !== false) {
            return 'Проблема связана с title. Модуль показывает текущее значение и готовит черновик исправления, но не обрезает названия механически.';
        }
        if (strpos($type, 'description') !== false) {
            return 'Проблема связана с description. Нужно сделать описание конкретным для страницы, без одинакового текста для всего сайта.';
        }
        if (strpos($type, 'h1') !== false) {
            return 'Проблема связана с H1. На странице должен быть один главный заголовок; официальные названия нельзя сокращать механически.';
        }
        return 'Проблема загружена из отчёта Netpeak. Проверьте старое значение, предложенное действие и отметьте пункт к исправлению только если всё верно.';
    }

    public static function canTryAutoFix(array $issue): bool
    {
        $type = (string)$issue['ISSUE_TYPE'];
        $old = trim((string)$issue['OLD_VALUE']);
        $new = trim((string)$issue['NEW_VALUE']);
        if ($old === '' || $new === '') {
            return false;
        }

        $exactReplaceTypes = [
            'redirect_links', 'redirect_chain', 'pagerank_redirect',
            'https_to_http', 'not_https', 'invalid_url', 'uppercase_url', 'encoded_url',
        ];

        return in_array($type, $exactReplaceTypes, true);
    }

    public static function manualInstruction(array $issue): string
    {
        $type = (string)$issue['ISSUE_TYPE'];
        $source = (string)($issue['SOURCE_URL'] ?? '');
        $old = (string)($issue['OLD_VALUE'] ?? '');
        $new = (string)($issue['NEW_VALUE'] ?? '');

        if (strpos($type, 'description') !== false) {
            return 'Откройте SEO-поля страницы' . ($source !== '' ? ' ' . $source : '') . ', замените общий meta description на индивидуальный. Черновик в поле «Предлагается»: ' . $new;
        }
        if (strpos($type, 'title') !== false) {
            return 'Откройте SEO-поле title страницы' . ($source !== '' ? ' ' . $source : '') . ' и проверьте предложенный title: ' . $new;
        }
        if (strpos($type, 'h1') !== false) {
            return 'Проверьте вывод H1 на странице' . ($source !== '' ? ' ' . $source : '') . '. Должен остаться один главный H1. Предложение: ' . $new;
        }
        if ($type === 'missing_alt') {
            return 'Найдите изображение в контенте или медиабиблиотеке и заполните alt. Черновик: ' . $new;
        }
        if (in_array($type, ['max_html_size', 'min_text_html', 'max_internal_links', 'max_external_links', 'empty_anchor', 'internal_nofollow', 'external_nofollow', 'self_links'], true)) {
            return 'Проверьте шаблон, компонент, меню, футер, фильтр или карточку товара. Если модуль нашёл файл, в журнале будет путь, строка и фрагмент кода.';
        }
        if ($old !== '' && $new !== '') {
            return 'Замените точное значение «' . $old . '» на «' . $new . '» в месте, указанном в строке отчёта.';
        }
        return 'Точного безопасного исправления нет. Проверьте строку отчёта и создайте задачу на SEO/разработчика только после подтверждения причины.';
    }
}
