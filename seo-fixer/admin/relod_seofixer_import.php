<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';
use Bitrix\Main\Loader;
use Relod\SeoFixer\Service\ImportService;
use Relod\SeoFixer\Helper\AdminHelper;

$APPLICATION->SetTitle('RELOD SEO Fixer: загрузка отчёта');
Loader::includeModule('relod.seofixer');
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    try {
        global $USER;
        $importId = (new ImportService())->importUploadedFile($_FILES['netpeak_file'], (int)$USER->GetID(), (string)($_POST['comment'] ?? ''));
        LocalRedirect('relod_seofixer_issues.php?lang=' . LANGUAGE_ID . '&import_id=' . $importId);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
echo AdminHelper::style();
echo AdminHelper::nav();
if ($error) CAdminMessage::ShowMessage($error);
if ($message) CAdminMessage::ShowNote($message);
echo AdminHelper::hint('Загружайте отчёты Netpeak по одному. Это медленнее, но надёжнее: после каждой загрузки можно проверить, правильно ли модуль понял файл и что именно предлагает изменить.');
?>
<form method="post" enctype="multipart/form-data">
    <?= bitrix_sessid_post(); ?>
    <table class="adm-detail-content-table edit-table">
        <tr>
            <td width="30%" class="adm-detail-content-cell-l">Файл Netpeak XLSX/CSV:</td>
            <td class="adm-detail-content-cell-r"><input type="file" name="netpeak_file" required></td>
        </tr>
        <tr>
            <td class="adm-detail-content-cell-l">Комментарий:</td>
            <td class="adm-detail-content-cell-r"><textarea name="comment" rows="3" cols="70" placeholder="Например: отчёт по редиректам, пакет 1"></textarea></td>
        </tr>
    </table>
    <input type="submit" class="adm-btn-save" value="Загрузить и проверить">
</form>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
