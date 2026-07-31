<?php
$__relodSeoFixerAdminPage = 'relod_seofixer_import.php';
$__relodSeoFixerModuleId = 'relod.seofixer';
$__relodSeoFixerCandidates = [
    $_SERVER['DOCUMENT_ROOT'] . '/local/modules/' . $__relodSeoFixerModuleId . '/admin/' . $__relodSeoFixerAdminPage,
    $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/' . $__relodSeoFixerModuleId . '/admin/' . $__relodSeoFixerAdminPage,
];

foreach ($__relodSeoFixerCandidates as $__relodSeoFixerPath) {
    if (is_file($__relodSeoFixerPath)) {
        require $__relodSeoFixerPath;
        return;
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
CAdminMessage::ShowMessage([
    'TYPE' => 'ERROR',
    'MESSAGE' => 'Не найден файл страницы модуля RELOD SEO Fixer.',
    'DETAILS' => 'Проверьте, что модуль установлен в /local/modules/relod.seofixer/ или /bitrix/modules/relod.seofixer/ и что внутри есть папка admin.',
    'HTML' => true,
]);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
