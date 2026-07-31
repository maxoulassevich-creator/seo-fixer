<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Relod\SeoFixer\Fix\RollbackService;
use Relod\SeoFixer\Helper\AdminHelper;
use Relod\SeoFixer\Model\ChangeTable;
use Relod\SeoFixer\Report\ReportCatalog;
use Relod\SeoFixer\Service\IssuePresenter;

/** @var CMain $APPLICATION */
/** @var CUser $USER */

$APPLICATION->SetTitle('RELOD SEO Fixer — журнал изменений');

if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Доступ только для администратора.');
}
if (!Loader::includeModule('relod.seofixer')) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    CAdminMessage::ShowMessage('Модуль relod.seofixer не подключён.');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    return;
}

$message = null;
$error = null;
$statusFilter = (string)($_REQUEST['status'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    try {
        $service = new RollbackService();
        $userId = (int)$USER->GetID();
        $action = (string)($_POST['action_button'] ?? '');

        if ($action === 'rollback_one') {
            $res = $service->rollbackChange((int)($_POST['change_id'] ?? 0), $userId);
            if ($res['success']) {
                $message = $res['message'];
            } else {
                $error = $res['message'];
            }
        } elseif ($action === 'rollback_selected') {
            $r = $service->rollbackMany((array)($_POST['change_ids'] ?? []), $userId);
            $message = 'Откачено: ' . $r['done'] . ', не удалось: ' . $r['failed'] . '.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$filter = [];
if ($statusFilter !== '') {
    $filter['=STATUS'] = $statusFilter;
}

$counts = [
    'applied' => ChangeTable::getCount(['=STATUS' => 'applied']),
    'rolled_back' => ChangeTable::getCount(['=STATUS' => 'rolled_back']),
    'failed' => ChangeTable::getCount(['=STATUS' => 'failed']),
];

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
echo AdminHelper::style();
echo '<div class="sf-wrap">';
echo AdminHelper::nav('changes');
if ($error) {
    echo AdminHelper::error($error);
}
if ($message) {
    echo AdminHelper::success($message);
}
echo AdminHelper::hint('Здесь видно каждое изменение: где, когда, что было и что стало. Перед любой правкой модуль сохраняет старое значение, поэтому откат доступен всегда.');
?>

<div class="sf-stats">
    <?= AdminHelper::stat('Применено', $counts['applied'], 'ok', '?lang=' . LANGUAGE_ID . '&status=applied'); ?>
    <?= AdminHelper::stat('Откачено', $counts['rolled_back'], '', '?lang=' . LANGUAGE_ID . '&status=rolled_back'); ?>
    <?= AdminHelper::stat('Не удалось', $counts['failed'], $counts['failed'] > 0 ? 'high' : '', '?lang=' . LANGUAGE_ID . '&status=failed'); ?>
    <?= AdminHelper::stat('Показать все', ChangeTable::getCount([]), '', '?lang=' . LANGUAGE_ID); ?>
</div>

<form method="post">
<?= bitrix_sessid_post(); ?>
<table class="sf-table">
    <tr>
        <th style="width:34px"><input type="checkbox" onclick="sfToggleAll(this)"></th>
        <th style="width:60px">ID</th>
        <th style="width:170px">Что изменено</th>
        <th>Было</th>
        <th>Стало</th>
        <th style="width:150px">Итог</th>
        <th style="width:130px">Когда</th>
        <th style="width:100px"></th>
    </tr>
    <?php
    $changes = ChangeTable::getList(['filter' => $filter, 'order' => ['ID' => 'DESC'], 'limit' => 300]);
    $has = false;
    while ($c = $changes->fetch()):
        $has = true;
        $isApplied = (string)$c['STATUS'] === 'applied';
    ?>
    <tr>
        <td><?= $isApplied ? '<input class="sf-check" type="checkbox" name="change_ids[]" value="' . (int)$c['ID'] . '">' : ''; ?></td>
        <td><?= (int)$c['ID']; ?></td>
        <td>
            <b><?= AdminHelper::e(ReportCatalog::strategyTitle((string)$c['STRATEGY'])); ?></b>
            <div class="sf-note"><?= AdminHelper::e((string)$c['ENTITY_TYPE']); ?><?= (int)$c['ENTITY_ID'] > 0 ? ' #' . (int)$c['ENTITY_ID'] : ''; ?></div>
            <div class="sf-note">поле: <?= AdminHelper::e(AdminHelper::short((string)$c['FIELD_NAME'], 60)); ?></div>
            <?php if (trim((string)$c['TARGET_URL']) !== ''): ?>
                <div class="sf-note"><?= AdminHelper::externalLink((string)$c['TARGET_URL'], 40); ?></div>
            <?php endif; ?>
        </td>
        <td><div class="sf-value"><?= AdminHelper::e(AdminHelper::short((string)$c['OLD_VALUE'], 260)); ?></div></td>
        <td><div class="sf-value"><?= AdminHelper::e(AdminHelper::short((string)$c['NEW_VALUE'], 260)); ?></div></td>
        <td>
            <?php
            $tone = $isApplied ? 'ok' : ((string)$c['STATUS'] === 'failed' ? 'bad' : 'muted');
            echo AdminHelper::badge((string)$c['STATUS'], $tone);
            ?>
            <div class="sf-note"><?= AdminHelper::e(AdminHelper::short((string)$c['MESSAGE_TEXT'], 180)); ?></div>
        </td>
        <td>
            <?= AdminHelper::e((string)$c['APPLIED_AT']); ?>
            <?php if ($c['ROLLED_BACK_AT']): ?>
                <div class="sf-note">откат: <?= AdminHelper::e((string)$c['ROLLED_BACK_AT']); ?></div>
            <?php endif; ?>
        </td>
        <td>
            <?php if ($isApplied): ?>
                <button type="submit" name="action_button" value="rollback_one" class="sf-btn" onclick="document.getElementById('sf-change-id').value='<?= (int)$c['ID']; ?>';return confirm('Откатить это изменение?');">Откатить</button>
            <?php else: ?>
                <span class="sf-muted">—</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php endwhile; ?>
    <?php if (!$has): ?>
    <tr><td colspan="8" class="sf-muted">Изменений пока нет.</td></tr>
    <?php endif; ?>
</table>

<input type="hidden" name="change_id" id="sf-change-id" value="0">

<div class="sf-actions">
    <div class="sf-actions-row">
        <button type="submit" name="action_button" value="rollback_selected" class="sf-btn sf-btn-danger" onclick="return confirm('Откатить все выбранные изменения?');">Откатить выбранные</button>
        <span class="sf-counter">Выбрано: <b class="sf-selected-count">0</b></span>
    </div>
    <div class="sf-note">Правки в файлах шаблона откатываются вручную из резервной копии, которая создаётся рядом с файлом.</div>
</div>
</form>
</div>
<?php
echo AdminHelper::script();
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
