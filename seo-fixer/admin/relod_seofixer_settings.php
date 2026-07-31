<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';
use Bitrix\Main\Loader;
use Relod\SeoFixer\Helper\AdminHelper;

$APPLICATION->SetTitle('RELOD SEO Fixer: настройки');
Loader::includeModule('relod.seofixer');
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    COption::SetOptionString('relod.seofixer', 'batch_limit', (string)max(1, min(200, (int)($_POST['batch_limit'] ?? 50))));
    COption::SetOptionString('relod.seofixer', 'care_mode', 'max');
    COption::SetOptionString('relod.seofixer', 'allow_template_file_autofix', (($_POST['allow_template_file_autofix'] ?? 'N') === 'Y') ? 'Y' : 'N');
    $message = 'Настройки сохранены.';
}

$batch = (int)COption::GetOptionString('relod.seofixer', 'batch_limit', '50');
$allowTemplate = COption::GetOptionString('relod.seofixer', 'allow_template_file_autofix', 'N') === 'Y';
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
echo AdminHelper::style();
echo AdminHelper::nav();
if ($message) CAdminMessage::ShowNote($message);
echo AdminHelper::hint('Основной режим остаётся безопасным: после загрузки отчёта ничего не исправляется автоматически. Все правки выполняются только после ручного подтверждения. Перед каждым изменением сохраняется старое значение.');
?>
<form method="post">
<?= bitrix_sessid_post(); ?>
<table class="adm-detail-content-table edit-table" style="max-width:1100px;">
<tr>
    <td width="35%" class="adm-detail-content-cell-l">Режим осторожности:</td>
    <td class="adm-detail-content-cell-r"><b>Максимальный</b><br><small>Все исправления требуют ручного подтверждения администратора.</small></td>
</tr>
<tr>
    <td class="adm-detail-content-cell-l">Количество исправлений за один запуск:</td>
    <td class="adm-detail-content-cell-r"><input type="number" name="batch_limit" value="<?= $batch; ?>" min="1" max="200"></td>
</tr>
<tr>
    <td class="adm-detail-content-cell-l">Резервная копия:</td>
    <td class="adm-detail-content-cell-r"><b>Всегда включена</b><br><small>Старое значение сохраняется в журнале изменений перед каждой правкой.</small></td>
</tr>
<tr>
    <td class="adm-detail-content-cell-l">Автоправка файлов шаблона:</td>
    <td class="adm-detail-content-cell-r">
        <label><input type="checkbox" name="allow_template_file_autofix" value="Y" <?= $allowTemplate ? 'checked' : ''; ?>> Разрешить точечную замену в файлах шаблона после подтверждения</label><br>
        <small>По умолчанию выключено. Если выключено, модуль всё равно ищет совпадение в /local/templates, /bitrix/templates, /include и показывает файл, строку и фрагмент кода для разработчика.</small>
    </td>
</tr>
<tr>
    <td class="adm-detail-content-cell-l">Области поиска безопасных замен:</td>
    <td class="adm-detail-content-cell-r">
        <b>Включены:</b> описания элементов и разделов инфоблоков, значения свойств инфоблоков, описания файлов, шаблонные файлы для диагностики.<br>
        <small>SEO-шаблоны и сложный код не меняются вслепую: для них модуль подготавливает предложение и/или задачу с конкретным местом проверки.</small>
    </td>
</tr>
</table>
<input type="submit" class="adm-btn-save" value="Сохранить настройки">
</form>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
