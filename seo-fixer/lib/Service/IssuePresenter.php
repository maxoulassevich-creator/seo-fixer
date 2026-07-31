<?php
namespace Relod\SeoFixer\Service;

use Relod\SeoFixer\Report\ReportCatalog;
use Relod\SeoFixer\Target\SeoWriter;
use Relod\SeoFixer\Target\UrlResolver;

/**
 * Человеческие формулировки для интерфейса: что за проблема,
 * чем грозит, что модуль сделает и что нужно сделать руками.
 */
class IssuePresenter
{
    public static function statusTitle(string $status): string
    {
        $map = [
            'new' => 'Нужно проверить',
            'approved' => 'Подтверждено к исправлению',
            'applied' => 'Исправлено модулем',
            'resolved' => 'Уже в порядке',
            'manual' => 'Нужна ручная правка',
            'failed' => 'Не удалось',
            'skipped' => 'Отклонено',
            'info' => 'Информация',
        ];
        return $map[$status] ?? $status;
    }

    /** CSS-класс для статуса. */
    public static function statusClass(string $status): string
    {
        $map = [
            'new' => 'new',
            'approved' => 'approved',
            'applied' => 'ok',
            'resolved' => 'ok',
            'manual' => 'warn',
            'failed' => 'bad',
            'skipped' => 'muted',
            'info' => 'muted',
        ];
        return $map[$status] ?? 'muted';
    }

    public static function riskTitle(string $risk): string
    {
        $map = [
            'low' => 'Низкий риск',
            'medium' => 'Средний риск',
            'template' => 'Нужен разработчик',
            'infra' => 'Сервер или robots',
            'review' => 'Нужно решение',
            'info' => 'Без действий',
        ];
        return $map[$risk] ?? $risk;
    }

    public static function riskHint(string $risk): string
    {
        $map = [
            'low' => 'Точная замена по совпадению — безопасно применять пачкой.',
            'medium' => 'Модуль запишет значение сам, но текст стоит просмотреть.',
            'template' => 'Правка в шаблоне или компоненте: модуль покажет файл и строку.',
            'infra' => 'Правится на сервере или в robots.txt, а не в контенте.',
            'review' => 'Нужно решение человека: модуль не выбирает за вас.',
            'info' => 'Информационная строка, действий не требует.',
        ];
        return $map[$risk] ?? '';
    }

    /**
     * Короткое объяснение проблемы для карточки.
     */
    public static function explain(array $issue): string
    {
        $meta = ReportCatalog::explain((string)$issue['ISSUE_TYPE']);
        return trim($meta['what']);
    }

    public static function threat(array $issue): string
    {
        $meta = ReportCatalog::explain((string)$issue['ISSUE_TYPE']);
        return trim($meta['why']);
    }

    public static function howTo(array $issue): string
    {
        $meta = ReportCatalog::explain((string)$issue['ISSUE_TYPE']);
        return trim($meta['how']);
    }

    /**
     * Что именно сделает модуль, если подтвердить пункт.
     */
    public static function plannedAction(array $issue): string
    {
        $type = (string)$issue['ISSUE_TYPE'];
        $strategy = ReportCatalog::strategy($type);
        $newValue = trim((string)($issue['NEW_VALUE'] ?? ''));
        $targetNote = trim((string)($issue['TARGET_NOTE'] ?? ''));

        if (!ReportCatalog::isAuto($type)) {
            return 'Модуль не меняет это автоматически — он показывает точное место и готовое решение.';
        }
        if ($newValue === '') {
            return 'Нужно значение для замены: нажмите «Подобрать предложения» или впишите его вручную.';
        }

        switch ($strategy) {
            case ReportCatalog::STRATEGY_SEO_TITLE:
            case ReportCatalog::STRATEGY_SEO_DESCRIPTION:
            case ReportCatalog::STRATEGY_SEO_H1:
                $field = SeoWriter::fieldTitle((string)(ReportCatalog::valueField($type) ?? ''));
                $where = $targetNote !== '' ? $targetNote : 'найденная запись Битрикса';
                return 'Модуль запишет ' . $field . ' в SEO-поля: ' . $where . '.';

            case ReportCatalog::STRATEGY_LINK_REPLACE:
                return 'Модуль найдёт точное вхождение адреса в текстах, свойствах и файлах шаблона и заменит только его.';

            case ReportCatalog::STRATEGY_IMAGE_ALT:
                return 'Модуль подготовил описание картинки — вставьте его в alt изображения.';

            default:
                return 'Модуль покажет место проблемы и предложит решение.';
        }
    }

    /**
     * Точная инструкция «сделай вот это», когда автоматика не применима.
     */
    public static function manualInstruction(array $issue): string
    {
        $type = (string)$issue['ISSUE_TYPE'];
        $strategy = ReportCatalog::strategy($type);
        $url = trim((string)($issue['SOURCE_URL'] ?? ''));
        $newValue = trim((string)($issue['NEW_VALUE'] ?? ''));
        $adminUrl = trim((string)($issue['TARGET_ADMIN_URL'] ?? ''));
        $targetNote = trim((string)($issue['TARGET_NOTE'] ?? ''));

        $where = $targetNote !== '' ? $targetNote : ($url !== '' ? 'страница ' . $url : 'страница из отчёта');
        $link = $adminUrl !== '' ? ' Форма редактирования: ' . $adminUrl : '';

        switch ($strategy) {
            case ReportCatalog::STRATEGY_SEO_TITLE:
            case ReportCatalog::STRATEGY_SEO_DESCRIPTION:
            case ReportCatalog::STRATEGY_SEO_H1:
                $field = SeoWriter::fieldTitle((string)(ReportCatalog::valueField($type) ?? ''));
                return 'Откройте ' . $where . ', вкладка «SEO», и запишите ' . $field . ': ' . ($newValue !== '' ? '«' . $newValue . '»' : '(значение нужно подобрать)') . '.' . $link;

            case ReportCatalog::STRATEGY_LINK_REPLACE:
                $old = trim((string)($issue['OLD_VALUE'] ?? ''));
                return 'Замените ссылку «' . $old . '» на «' . ($newValue !== '' ? $newValue : 'рабочий адрес') . '». Ссылка стоит на странице ' . $url . '.';

            case ReportCatalog::STRATEGY_SERVER:
                return 'Это серверная проблема: страница ' . $url . ' отдаёт ошибку. Проверьте нагрузку, лимиты хостинга и режим технических работ. Текстовыми правками это не лечится.';

            case ReportCatalog::STRATEGY_ROBOTS:
                return 'Проверьте правила индексации для ' . $url . ': robots.txt («Маркетинг → Поисковая оптимизация → Управление robots.txt»), meta robots и заголовок X-Robots-Tag.';

            case ReportCatalog::STRATEGY_TEMPLATE:
                return 'Правка в шаблоне или компоненте. Проверьте меню, футер, карточку товара или фильтр на странице ' . $url . '. Если модуль нашёл файл, путь и строка есть в журнале ошибок.';

            case ReportCatalog::STRATEGY_IMAGE_ALT:
                return 'Заполните alt у изображения на странице ' . $url . '. Черновик: ' . $newValue;

            case ReportCatalog::STRATEGY_INFO:
                return 'Информационная строка — действий не требует.';

            default:
                return 'Нужно решение специалиста: ' . ReportCatalog::explain($type)['how'];
        }
    }

    /**
     * Название места хранения для интерфейса.
     */
    public static function targetTitle(array $issue): string
    {
        $type = (string)($issue['TARGET_TYPE'] ?? '');
        switch ($type) {
            case UrlResolver::TARGET_ELEMENT:
                return 'Элемент инфоблока';
            case UrlResolver::TARGET_SECTION:
                return 'Раздел инфоблока';
            case UrlResolver::TARGET_PAGE:
                return 'Статическая страница';
            case UrlResolver::TARGET_NONE:
            case '':
                return 'Не определено';
            default:
                return $type;
        }
    }

    /**
     * Итог живой проверки в коротком виде.
     */
    public static function liveSummary(array $issue): string
    {
        $status = (int)($issue['LIVE_STATUS'] ?? 0);
        $checked = trim((string)($issue['LIVE_CHECKED_AT'] ?? ''));
        if ($checked === '' && $status === 0) {
            return 'Сайт ещё не проверялся';
        }
        if ($status === 0) {
            return 'Сайт не ответил';
        }
        return 'Код ответа ' . $status;
    }

    /** Все статусы для фильтров. */
    public static function statuses(): array
    {
        return ['new', 'approved', 'applied', 'resolved', 'manual', 'failed', 'skipped', 'info'];
    }

    /** Все уровни риска для фильтров. */
    public static function risks(): array
    {
        return ['low', 'medium', 'template', 'infra', 'review', 'info'];
    }
}
