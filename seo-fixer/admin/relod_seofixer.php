<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';
use Bitrix\Main\Loader;
use Relod\SeoFixer\Model\ImportTable;
use Relod\SeoFixer\Model\IssueTable;
use Relod\SeoFixer\Model\ChangeTable;
use Relod\SeoFixer\Model\ErrorLogTable;
use Relod\SeoFixer\Service\ReportTypeDetector;
use Relod\SeoFixer\Service\MaintenanceService;
use Relod\SeoFixer\Helper\AdminHelper;

$APPLICATION->SetTitle('RELOD SEO Fixer');
if (!Loader::includeModule('relod.seofixer')) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    CAdminMessage::ShowMessage('Модуль relod.seofixer не подключён.');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    return;
}

$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    try {
        $maintenance = new MaintenanceService();
        $action = (string)($_POST['action_button'] ?? '');
        if ($action === 'delete_imports') {
            $result = $maintenance->deleteImports($_POST['import_ids'] ?? [], true);
            $message = 'Удалено загрузок: ' . $result['imports'] . ', проблем: ' . $result['issues'] . ', записей изменений: ' . $result['changes'] . ', ошибок: ' . $result['errors'] . ', файлов: ' . $result['files'] . '.';
        } elseif ($action === 'clear_all') {
            $result = $maintenance->clearAll(true);
            $message = 'Полная очистка выполнена. Удалено загрузок: ' . $result['imports'] . ', проблем: ' . $result['issues'] . ', записей изменений: ' . $result['changes'] . ', ошибок: ' . $result['errors'] . ', файлов: ' . $result['files'] . '.';
        } elseif ($action === 'clear_logs') {
            $result = $maintenance->clearLogs();
            $message = 'Журналы очищены. Удалено изменений: ' . $result['changes'] . ', ошибок: ' . $result['errors'] . '.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

echo AdminHelper::style();
echo AdminHelper::nav();
if ($error) CAdminMessage::ShowMessage($error);
if ($message) CAdminMessage::ShowNote($message);
echo AdminHelper::hint('Модуль ничего не меняет после загрузки отчёта. Сначала он показывает найденные проблемы и предлагает безопасное действие, затем администратор подтверждает или отклоняет пункты, и только после этого применяются выбранные исправления.');

$counts = [
    'issues_new' => IssueTable::getCount(['=STATUS' => 'new']),
    'issues_approved' => IssueTable::getCount(['=STATUS' => 'approved']),
    'issues_manual' => IssueTable::getCount(['=STATUS' => 'manual']),
    'issues_applied' => IssueTable::getCount(['=STATUS' => 'applied']),
    'changes' => ChangeTable::getCount([]),
    'errors' => ErrorLogTable::getCount([]),
    'imports' => ImportTable::getCount([]),
];
?>
<h2>Состояние</h2>
<div class="relod-seofixer-cols">
    <div class="relod-seofixer-card">Загрузок<br><b><?= (int)$counts['imports']; ?></b></div>
    <div class="relod-seofixer-card">Нужно проверить<br><b><?= (int)$counts['issues_new']; ?></b></div>
    <div class="relod-seofixer-card">Отмечено к исправлению<br><b><?= (int)$counts['issues_approved']; ?></b></div>
    <div class="relod-seofixer-card">Нужна ручная правка<br><b><?= (int)$counts['issues_manual']; ?></b></div>
    <div class="relod-seofixer-card">Исправлено<br><b><?= (int)$counts['issues_applied']; ?></b></div>
    <div class="relod-seofixer-card">Журнал изменений<br><b><?= (int)$counts['changes']; ?></b></div>
    <div class="relod-seofixer-card">Ошибки / пропуски<br><b><?= (int)$counts['errors']; ?></b></div>
</div>

<h2>Последние загрузки</h2>
<form method="post">
<?= bitrix_sessid_post(); ?>
<div class="relod-seofixer-wrap">
<table class="adm-list-table relod-seofixer-table">
<colgroup>
    <col style="width:34px"><col style="width:70px"><col style="width:34%"><col style="width:17%"><col style="width:8%"><col style="width:13%"><col style="width:14%"><col style="width:10%">
</colgroup>
<tr class="adm-list-table-header">
    <td><input type="checkbox" onclick="document.querySelectorAll('.import-check').forEach(cb=>cb.checked=this.checked)"></td>
    <td>ID</td><td>Файл</td><td>Тип</td><td>Строк</td><td>Дата</td><td>Комментарий</td><td>Действие</td>
</tr>
<?php
$imports = ImportTable::getList(['order' => ['ID' => 'DESC'], 'limit' => 50]);
while ($import = $imports->fetch()): ?>
<tr>
    <td><input class="import-check" type="checkbox" name="import_ids[]" value="<?= (int)$import['ID']; ?>"></td>
    <td><?= (int)$import['ID']; ?></td>
    <td><?= AdminHelper::e($import['ORIGINAL_NAME']); ?><br><span class="relod-seofixer-small"><?= AdminHelper::e($import['FILE_NAME']); ?></span></td>
    <td><?= AdminHelper::e(ReportTypeDetector::typeTitle($import['REPORT_TYPE'])); ?></td>
    <td><?= (int)$import['ROWS_TOTAL']; ?></td>
    <td><?= AdminHelper::e($import['IMPORTED_AT']); ?></td>
    <td><?= AdminHelper::e(AdminHelper::short($import['COMMENT_TEXT'], 160)); ?></td>
    <td><a href="relod_seofixer_issues.php?lang=<?= LANGUAGE_ID; ?>&import_id=<?= (int)$import['ID']; ?>">Открыть проблемы</a></td>
</tr>
<?php endwhile; ?>
</table>
</div>

<div class="relod-seofixer-actions">
    <p><b>Очистка данных</b></p>
    <p>
        <button type="submit" name="action_button" value="delete_imports" class="adm-btn" onclick="return confirm('Удалить выбранные загрузки, связанные проблемы, журналы и физические файлы отчётов?');">Удалить выбранные загрузки</button>
        <button type="submit" name="action_button" value="clear_logs" class="adm-btn" onclick="return confirm('Очистить только журналы изменений и ошибок?');">Очистить журналы</button>
        <button type="submit" name="action_button" value="clear_all" class="adm-btn adm-btn-delete" onclick="return confirm('Полностью очистить все загрузки, проблемы, журналы и файлы отчётов?');">Очистить всё</button>
    </p>
    <p class="relod-seofixer-small">Очистка удаляет только служебные данные модуля RELOD SEO Fixer и загруженные XLSX/CSV из /upload/relod_seofixer. Контент сайта не меняется.</p>
</div>
</form>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
