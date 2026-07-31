<?php
if (!$USER->IsAdmin()) {
    return [];
}

return [
    'parent_menu' => 'global_menu_services',
    'section' => 'relod_seofixer',
    'sort' => 500,
    'text' => 'RELOD SEO Fixer',
    'title' => 'Разбор отчётов Netpeak, проверка сайта в реальном времени и подтверждаемые SEO-исправления',
    'icon' => 'default_menu_icon',
    'page_icon' => 'default_page_icon',
    'items_id' => 'menu_relod_seofixer',
    'items' => [
        [
            'text' => 'Обзор',
            'url' => 'relod_seofixer.php?lang=' . LANGUAGE_ID,
            'more_url' => ['relod_seofixer.php'],
            'title' => 'Состояние по сайтам и приоритеты',
        ],
        [
            'text' => 'Загрузка отчёта',
            'url' => 'relod_seofixer_import.php?lang=' . LANGUAGE_ID,
            'more_url' => ['relod_seofixer_import.php'],
            'title' => 'Загрузить один или несколько отчётов Netpeak Spider',
        ],
        [
            'text' => 'Проблемы',
            'url' => 'relod_seofixer_issues.php?lang=' . LANGUAGE_ID,
            'more_url' => ['relod_seofixer_issues.php'],
            'title' => 'Список проблем по приоритету с готовыми решениями',
        ],
        [
            'text' => 'Дубликаты title и description',
            'url' => 'relod_seofixer_duplicates.php?lang=' . LANGUAGE_ID,
            'more_url' => ['relod_seofixer_duplicates.php'],
            'title' => 'Массовый подбор уникальных мета-тегов',
        ],
        [
            'text' => 'Журнал изменений',
            'url' => 'relod_seofixer_changes.php?lang=' . LANGUAGE_ID,
            'more_url' => ['relod_seofixer_changes.php'],
            'title' => 'Что изменено и что можно откатить',
        ],
        [
            'text' => 'Не выполнено',
            'url' => 'relod_seofixer_errors.php?lang=' . LANGUAGE_ID,
            'more_url' => ['relod_seofixer_errors.php'],
            'title' => 'Пропущенные пункты с точной причиной',
        ],
        [
            'text' => 'Настройки',
            'url' => 'relod_seofixer_settings.php?lang=' . LANGUAGE_ID,
            'more_url' => ['relod_seofixer_settings.php'],
            'title' => 'Сайты, шаблоны, живая проверка, права на правку',
        ],
    ],
];
