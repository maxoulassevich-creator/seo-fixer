<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';
use Bitrix\Main\Loader;
use Relod\SeoFixer\Model\ChangeTable;
use Relod\SeoFixer\Service\RollbackService;
use Relod\SeoFixer\Helper\AdminHelper;

$APPLICATION->SetTitle('RELOD SEO Fixer: журнал изменений');
Loader::includeModule('relod.seofixer');
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    try {
        global $USER;
        $res = (new RollbackService())->rollbackChange((int)($_POST['change_id'] ?? 0), (int)$USER->GetID());
        if ($res['success']) $message = $res['message']; else $error = $res['message'];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
echo AdminHelper::style();
echo AdminHelper::nav();
if ($error) CAdminMessage::ShowMessage($error);
if ($message) CAdminMessage::ShowNote($message);
echo AdminHelper::hint('Здесь видно, что именно было изменено: где, когда, старое значение, новое значение и доступен ли откат.');
?>
<div class="relod-seofixer-wrap">
<table class="adm-list-table relod-seofixer-table">
<tr class="adm-list-table-header"><td>ID</td><td>Проблема</td><td>Где изменено</td><td>Было</td><td>Стало</td><td>Статус</td><td>Дата</td><td>Откат</td></tr>
<?php
$changes = ChangeTable::getList(['order' => ['ID' => 'DESC'], 'limit' => 200]);
while ($c = $changes->fetch()): ?>
<tr>
    <td><?= (int)$c['ID']; ?></td>
    <td>#<?= (int)$c['ISSUE_ID']; ?></td>
    <td><?= AdminHelper::e($c['ENTITY_TYPE'] . ' #' . $c['ENTITY_ID'] . ' / ' . $c['FIELD_NAME']); ?></td>
    <td><code><?= AdminHelper::e(AdminHelper::short($c['OLD_VALUE'], 220)); ?></code></td>
    <td><code><?= AdminHelper::e(AdminHelper::short($c['NEW_VALUE'], 220)); ?></code></td>
    <td><?= AdminHelper::e($c['STATUS']); ?><br><small><?= AdminHelper::e($c['MESSAGE_TEXT']); ?></small></td>
    <td><?= AdminHelper::e($c['APPLIED_AT']); ?></td>
    <td>
        <?php if ($c['STATUS'] === 'applied'): ?>
        <form method="post" onsubmit="return confirm('Откатить это изменение?');">
            <?= bitrix_sessid_post(); ?>
            <input type="hidden" name="change_id" value="<?= (int)$c['ID']; ?>">
            <input type="submit" value="Откатить">
        </form>
        <?php else: ?>—<?php endif; ?>
    </td>
</tr>
<?php endwhile; ?>
</table>
</div>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
