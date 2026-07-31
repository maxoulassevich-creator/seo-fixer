<?php
/**
 * Прокси-файл в /bitrix/admin/. Реальная страница лежит внутри модуля —
 * ищем её и в /local/modules/, и в /bitrix/modules/.
 */
$page = 'relod_seofixer.php';
$moduleId = 'relod.seofixer';
$candidates = [
    $_SERVER['DOCUMENT_ROOT'] . '/local/modules/' . $moduleId . '/admin/' . $page,
    $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/' . $moduleId . '/admin/' . $page,
];

foreach ($candidates as $path) {
    if (is_file($path)) {
        require $path;
        return;
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
CAdminMessage::ShowMessage([
    'TYPE' => 'ERROR',
    'MESSAGE' => 'Не найден файл страницы модуля RELOD SEO Fixer.',
    'DETAILS' => 'Проверьте, что модуль установлен в /local/modules/relod.seofixer/ или /bitrix/modules/relod.seofixer/ и внутри есть папка admin.',
    'HTML' => true,
]);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
