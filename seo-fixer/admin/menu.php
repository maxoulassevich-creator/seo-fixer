<?php
if (!$USER->IsAdmin()) {
    return [];
}

return [
    'parent_menu' => 'global_menu_services',
    'section' => 'relod_seofixer',
    'sort' => 500,
    'text' => 'RELOD SEO Fixer',
    'title' => 'Проверка и подтверждённые SEO-исправления по отчётам Netpeak',
    'icon' => 'default_menu_icon',
    'page_icon' => 'default_page_icon',
    'items_id' => 'menu_relod_seofixer',
    'items' => [
        [
            'text' => 'Обзор',
            'url' => 'relod_seofixer.php?lang=' . LANGUAGE_ID,
            'more_url' => ['relod_seofixer.php'],
            'title' => 'Обзор загруженных отчётов и статусов',
        ],
        [
            'text' => 'Загрузка отчёта',
            'url' => 'relod_seofixer_import.php?lang=' . LANGUAGE_ID,
            'more_url' => ['relod_seofixer_import.php'],
            'title' => 'Загрузить XLSX/CSV отчёт Netpeak',
        ],
        [
            'text' => 'Найденные проблемы',
            'url' => 'relod_seofixer_issues.php?lang=' . LANGUAGE_ID,
            'more_url' => ['relod_seofixer_issues.php'],
            'title' => 'Проверить найденные проблемы и выбрать исправления',
        ],
        [
            'text' => 'Журнал изменений',
            'url' => 'relod_seofixer_changes.php?lang=' . LANGUAGE_ID,
            'more_url' => ['relod_seofixer_changes.php'],
            'title' => 'Что было изменено и что можно откатить',
        ],
        [
            'text' => 'Ошибки и не выполнено',
            'url' => 'relod_seofixer_errors.php?lang=' . LANGUAGE_ID,
            'more_url' => ['relod_seofixer_errors.php'],
            'title' => 'Отдельный журнал ошибок и пропущенных пунктов',
        ],
        [
            'text' => 'Настройки',
            'url' => 'relod_seofixer_settings.php?lang=' . LANGUAGE_ID,
            'more_url' => ['relod_seofixer_settings.php'],
            'title' => 'Ограничения и режимы работы',
        ],
    ],
];
