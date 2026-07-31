<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';
use Bitrix\Main\Loader;
use Relod\SeoFixer\Model\ErrorLogTable;
use Relod\SeoFixer\Helper\AdminHelper;

$APPLICATION->SetTitle('RELOD SEO Fixer: ошибки и не выполнено');
Loader::includeModule('relod.seofixer');
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
echo AdminHelper::style();
echo AdminHelper::nav();
echo AdminHelper::hint('Здесь отдельно собраны пункты, которые модуль не выполнил: не нашёл место изменения, не смог разобрать строку, пропустил пункт ради безопасности или требует ручной правки разработчика.');
?>
<div class="relod-seofixer-wrap">
<table class="adm-list-table relod-seofixer-table">
<tr class="adm-list-table-header"><td>ID</td><td>Загрузка</td><td>Проблема</td><td>Уровень</td><td>Сообщение</td><td>Дата</td></tr>
<?php
$items = ErrorLogTable::getList(['order' => ['ID' => 'DESC'], 'limit' => 300]);
while ($e = $items->fetch()): ?>
<tr>
    <td><?= (int)$e['ID']; ?></td>
    <td><?= (int)$e['IMPORT_ID']; ?></td>
    <td><?= (int)$e['ISSUE_ID']; ?></td>
    <td><?= AdminHelper::e($e['LEVEL']); ?></td>
    <td><?= AdminHelper::e($e['MESSAGE_TEXT']); ?><br><small><code><?= AdminHelper::e(AdminHelper::short($e['CONTEXT_JSON'], 500)); ?></code></small></td>
    <td><?= AdminHelper::e($e['CREATED_AT']); ?></td>
</tr>
<?php endwhile; ?>
</table>
</div>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
