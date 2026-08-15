<?php
namespace Relod\SeoFixer\Site;

use Bitrix\Main\Type\DateTime;
use Relod\SeoFixer\Model\IssueTable;
use Relod\SeoFixer\Report\ReportCatalog;

/**
 * Сверяет карточки проблем с реальным состоянием сайта.
 *
 * Отчёт Netpeak — это снимок прошлого. Модуль открывает адрес прямо сейчас
 * и записывает фактические значения. Если проблема уже исчезла, карточка
 * закрывается со статусом «уже в порядке» и не мешает работать.
 */
class LiveVerifier
{
    /** @var LivePageInspector */
    private $inspector;

    public function __construct(?LivePageInspector $inspector = null)
    {
        $this->inspector = $inspector ?: new LivePageInspector();
    }

    /**
     * Проверяет пачку проблем.
     *
     * @param array $filter Фильтр для IssueTable.
     * @param int   $limit  Сколько проблем проверить за один запуск.
     * @return array{checked:int,resolved:int,confirmed:int,unreachable:int}
     */
    public function verifyBatch(array $filter, int $limit = 100): array
    {
        $stats = ['checked' => 0, 'resolved' => 0, 'confirmed' => 0, 'unreachable' => 0];

        $rows = IssueTable::getList([
            'filter' => $filter,
            'order' => ['PRIORITY' => 'DESC', 'ID' => 'ASC'],
            'limit' => max(1, min(500, $limit)),
        ]);

        while ($issue = $rows->fetch()) {
            $outcome = $this->verifyIssue($issue);
            $stats['checked']++;
            if ($outcome === 'resolved') {
                $stats['resolved']++;
            } elseif ($outcome === 'unreachable') {
                $stats['unreachable']++;
            } else {
                $stats['confirmed']++;
            }
        }

        return $stats;
    }

    /**
     * Проверяет одну проблему и обновляет её живые поля.
     *
     * @return string confirmed | resolved | unreachable
     */
    public function verifyIssue(array $issue): string
    {
        $type = (string)$issue['ISSUE_TYPE'];
        $strategy = ReportCatalog::strategy($type);
        $checkUrl = $this->urlToCheck($issue, $strategy);

        if ($checkUrl === '') {
            IssueTable::update((int)$issue['ID'], [
                'LIVE_CHECKED_AT' => new DateTime(),
                'LIVE_VERDICT' => 'Нечего проверять: в строке отчёта нет адреса страницы.',
                'UPDATED_AT' => new DateTime(),
            ]);
            return 'confirmed';
        }

        $live = $this->inspector->inspect($checkUrl);
        $now = new DateTime();

        $update = [
            'LIVE_STATUS' => (int)$live['status'],
            'LIVE_CHECKED_AT' => $now,
            'UPDATED_AT' => $now,
        ];

        if ($live['error'] !== '' || $live['status'] === 0) {
            $update['LIVE_VERDICT'] = 'Проверка не удалась: ' . ($live['error'] ?: 'сервер не ответил') . '. Значения взяты из отчёта.';
            IssueTable::update((int)$issue['ID'], $update);
            return 'unreachable';
        }

        $verdict = $this->buildVerdict($issue, $strategy, $live, $update);

        $update['LIVE_VERDICT'] = $verdict['text'];
        if ($verdict['live_value'] !== null) {
            $update['LIVE_VALUE'] = $verdict['live_value'];
        }

        // Уже исправлено вне модуля — закрываем карточку, но не трогаем
        // то, что администратор уже подтвердил или что модуль уже применил.
        $current = (string)$issue['STATUS'];
        if ($verdict['resolved'] && in_array($current, ['new', 'manual', 'failed'], true)) {
            $update['STATUS'] = 'resolved';
        }

        IssueTable::update((int)$issue['ID'], $update);
        return $verdict['resolved'] ? 'resolved' : 'confirmed';
    }

    /**
     * Какой адрес проверять: страницу с проблемой или цель ссылки.
     */
    private function urlToCheck(array $issue, string $strategy): string
    {
        if ($strategy === ReportCatalog::STRATEGY_LINK_REPLACE) {
            $target = trim((string)($issue['TARGET_URL'] ?? ''));
            if ($target !== '') {
                return $target;
            }
        }
        $source = trim((string)($issue['SOURCE_URL'] ?? ''));
        if ($source !== '') {
            return $source;
        }
        return trim((string)($issue['TARGET_URL'] ?? ''));
    }

    /**
     * Сравнивает состояние сайта с проблемой из отчёта.
     *
     * @return array{resolved:bool,text:string,live_value:?string}
     */
    private function buildVerdict(array $issue, string $strategy, array $live, array &$update): array
    {
        $type = (string)$issue['ISSUE_TYPE'];
        $reported = trim((string)($issue['OLD_VALUE'] ?? ''));

        // Адрес отвечает ошибкой. Что это значит — зависит от типа проблемы:
        // для мета-тегов это стоп-сигнал, а для битой ссылки, наоборот,
        // подтверждение проблемы.
        if ($live['status'] >= 400) {
            return $this->verdictForErrorStatus($strategy, $live);
        }

        switch ($strategy) {
            case ReportCatalog::STRATEGY_SEO_TITLE:
                return $this->verdictForText($type, $reported, (string)$live['title'], 'заголовок title', 65);

            case ReportCatalog::STRATEGY_SEO_DESCRIPTION:
                return $this->verdictForText($type, $reported, (string)$live['description'], 'описание description', 160);

            case ReportCatalog::STRATEGY_SEO_H1:
                $liveH1 = $live['h1'] ? (string)$live['h1'][0] : '';
                if ($type === 'missing_h1') {
                    if ($liveH1 !== '') {
                        return ['resolved' => true, 'live_value' => $liveH1, 'text' => 'Уже в порядке: на странице есть H1 «' . $liveH1 . '».'];
                    }
                    return ['resolved' => false, 'live_value' => '', 'text' => 'Подтверждено: H1 на странице сейчас отсутствует.'];
                }
                if ($type === 'multiple_h1' && (int)$live['h1_count'] <= 1) {
                    return ['resolved' => true, 'live_value' => $liveH1, 'text' => 'Уже в порядке: на странице сейчас один H1.'];
                }
                return $this->verdictForText($type, $reported, $liveH1, 'заголовок H1', 70);

            case ReportCatalog::STRATEGY_LINK_REPLACE:
                if ($live['status'] >= 200 && $live['status'] < 300 && !$live['redirected']) {
                    return [
                        'resolved' => true,
                        'live_value' => (string)$live['effective_url'],
                        'text' => 'Уже в порядке: адрес отвечает ' . $live['status'] . ' без редиректа, менять ссылку не нужно.',
                    ];
                }
                if ($live['redirected']) {
                    $update['FINAL_URL'] = (string)$live['effective_url'];
                    return [
                        'resolved' => false,
                        'live_value' => (string)$live['effective_url'],
                        'text' => 'Подтверждено: адрес ведёт через редирект на ' . $live['effective_url'] . '. Именно этот конечный адрес и подставлен в предложение.',
                    ];
                }
                return [
                    'resolved' => false,
                    'live_value' => (string)$live['effective_url'],
                    'text' => 'Проверено сейчас: код ответа ' . $live['status'] . '.',
                ];

            case ReportCatalog::STRATEGY_SERVER:
                if ($live['status'] >= 200 && $live['status'] < 400) {
                    return [
                        'resolved' => true,
                        'live_value' => (string)$live['status'],
                        'text' => 'Уже в порядке: страница снова отвечает ' . $live['status'] . '. В отчёте она числилась недоступной — значит, сбой был временным.',
                    ];
                }
                return [
                    'resolved' => false,
                    'live_value' => (string)$live['status'],
                    'text' => 'Подтверждено: страница по-прежнему отдаёт ' . $live['status'] . '. Ответ занял ' . $live['response_ms'] . ' мс.',
                ];

            case ReportCatalog::STRATEGY_ROBOTS:
                $parts = [];
                if ($live['meta_robots'] !== '') {
                    $parts[] = 'meta robots: ' . $live['meta_robots'];
                }
                if ($live['x_robots'] !== '') {
                    $parts[] = 'X-Robots-Tag: ' . $live['x_robots'];
                }
                if (!$parts) {
                    return ['resolved' => false, 'live_value' => '', 'text' => 'Проверено сейчас: запрещающих директив в HTML и заголовках нет. Осталось сверить robots.txt.'];
                }
                return ['resolved' => false, 'live_value' => implode('; ', $parts), 'text' => 'Проверено сейчас — ' . implode('; ', $parts) . '.'];

            default:
                $extra = '';
                if ($type === 'max_html_size' || $type === 'min_text_html') {
                    $extra = ' Размер HTML сейчас: ' . number_format((float)$live['html_size'] / 1024, 0, ',', ' ') . ' КБ, полезного текста ' . round((float)$live['text_ratio'] * 100, 1) . '%.';
                }
                return [
                    'resolved' => false,
                    'live_value' => (string)$live['status'],
                    'text' => 'Проверено сейчас: страница отвечает ' . $live['status'] . ', ответ за ' . $live['response_ms'] . ' мс.' . $extra,
                ];
        }
    }

    /**
     * Вердикт, когда адрес ответил кодом ошибки.
     *
     * @return array{resolved:bool,text:string,live_value:?string}
     */
    private function verdictForErrorStatus(string $strategy, array $live): array
    {
        $status = (int)$live['status'];
        $isLink = $strategy === ReportCatalog::STRATEGY_LINK_REPLACE;

        // Сайт отказывает и на самой странице, и на главной — значит дело
        // не в конкретной странице. Чаще всего это защита от автоматических
        // запросов, а не поломка: в браузере такие страницы открываются.
        if (!empty($live['refused']) && $this->inspector->siteRefusesRequests((string)$live['url'])) {
            return [
                'resolved' => false,
                'live_value' => null,
                'text' => 'Проверить не удалось: сайт отвечает ' . $status . ' и на этот адрес, и на главную страницу'
                    . (($live['attempts'] ?? 1) > 1 ? ' (попыток: ' . (int)$live['attempts'] . ')' : '')
                    . '. Обычно так ведёт себя защита от автоматических запросов — в браузере страница при этом открывается. '
                    . 'Увеличьте паузу между запросами или укажите User-Agent браузера в настройках модуля, затем повторите проверку. '
                    . 'Данные в карточке взяты из отчёта.',
            ];
        }

        if ($isLink) {
            return [
                'resolved' => false,
                'live_value' => (string)$status,
                'text' => 'Подтверждено: адрес ссылки отвечает ' . $status . ' (' . $live['status_text'] . '). '
                    . 'Ссылку нужно заменить на рабочий адрес или убрать со страницы.',
            ];
        }

        if ($strategy === ReportCatalog::STRATEGY_SERVER) {
            return [
                'resolved' => false,
                'live_value' => (string)$status,
                'text' => 'Подтверждено: страница по-прежнему отдаёт ' . $status . '. Ответ занял ' . (int)$live['response_ms'] . ' мс.',
            ];
        }

        return [
            'resolved' => false,
            'live_value' => null,
            'text' => 'Сейчас страница отдаёт ' . $status . ' (' . $live['status_text'] . '). '
                . 'Пока она недоступна, править мета-теги бесполезно — сначала нужно восстановить страницу.',
        ];
    }

    /**
     * Общая логика для текстовых мета-полей.
     *
     * @return array{resolved:bool,text:string,live_value:?string}
     */
    private function verdictForText(string $type, string $reported, string $liveValue, string $label, int $maxLen): array
    {
        $len = function_exists('mb_strlen') ? mb_strlen($liveValue, 'UTF-8') : strlen($liveValue);

        if ($liveValue === '') {
            return [
                'resolved' => false,
                'live_value' => '',
                'text' => 'Подтверждено: ' . $label . ' на странице сейчас пустой.',
            ];
        }

        // Значение изменилось после выгрузки отчёта.
        if ($reported !== '' && $liveValue !== $reported) {
            $stillShort = in_array($type, ['short_title', 'short_description'], true) && $len < ($maxLen > 100 ? 120 : 40);
            $stillLong = in_array($type, ['long_title', 'long_description'], true) && $len > $maxLen;
            if (!$stillShort && !$stillLong) {
                return [
                    'resolved' => true,
                    'live_value' => $liveValue,
                    'text' => 'Уже исправлено вне модуля: сейчас на странице «' . $liveValue . '» (' . $len . ' симв.), а в отчёте было «' . $reported . '».',
                ];
            }
            return [
                'resolved' => false,
                'live_value' => $liveValue,
                'text' => 'Значение изменилось после отчёта, но проблема осталась: сейчас «' . $liveValue . '» (' . $len . ' симв.).',
            ];
        }

        return [
            'resolved' => false,
            'live_value' => $liveValue,
            'text' => 'Подтверждено на сайте: ' . $label . ' сейчас «' . $liveValue . '» (' . $len . ' симв.).',
        ];
    }
}
