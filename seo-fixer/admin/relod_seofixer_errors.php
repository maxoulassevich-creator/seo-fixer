<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Relod\SeoFixer\Helper\AdminHelper;
use Relod\SeoFixer\Model\ErrorLogTable;

/** @var CMain $APPLICATION */
/** @var CUser $USER */

$APPLICATION->SetTitle('RELOD SEO Fixer — не выполнено и ошибки');

if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Доступ только для администратора.');
}
if (!Loader::includeModule('relod.seofixer')) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    CAdminMessage::ShowMessage('Модуль relod.seofixer не подключён.');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    return;
}

$level = (string)($_REQUEST['level'] ?? '');
$importId = (int)($_REQUEST['import_id'] ?? 0);

$filter = [];
if ($level !== '') {
    $filter['=LEVEL'] = $level;
}
if ($importId > 0) {
    $filter['=IMPORT_ID'] = $importId;
}

$counts = [
    'error' => ErrorLogTable::getCount(['=LEVEL' => 'error']),
    'notice' => ErrorLogTable::getCount(['=LEVEL' => 'notice']),
];

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
echo AdminHelper::style();
echo '<div class="sf-wrap">';
echo AdminHelper::nav('errors');
echo AdminHelper::hint('Здесь собрано всё, что модуль намеренно не сделал: не нашёл место правки, не смог разобрать строку или остановился ради безопасности. Для каждой записи указана конкретная причина и что делать дальше.');
?>

<div class="sf-stats">
    <?= AdminHelper::stat('Ошибки', $counts['error'], $counts['error'] > 0 ? 'high' : 'ok', '?lang=' . LANGUAGE_ID . '&level=error'); ?>
    <?= AdminHelper::stat('Требуют внимания', $counts['notice'], 'warn', '?lang=' . LANGUAGE_ID . '&level=notice'); ?>
    <?= AdminHelper::stat('Показать все', ErrorLogTable::getCount([]), '', '?lang=' . LANGUAGE_ID); ?>
</div>

<table class="sf-table">
    <tr>
        <th style="width:60px">ID</th>
        <th style="width:90px">Загрузка</th>
        <th style="width:90px">Карточка</th>
        <th style="width:110px">Уровень</th>
        <th>Что произошло и что делать</th>
        <th style="width:130px">Когда</th>
    </tr>
    <?php
    $items = ErrorLogTable::getList(['filter' => $filter, 'order' => ['ID' => 'DESC'], 'limit' => 300]);
    $has = false;
    while ($e = $items->fetch()):
        $has = true;
        $context = AdminHelper::decodeJson((string)$e['CONTEXT_JSON']);
    ?>
    <tr>
        <td><?= (int)$e['ID']; ?></td>
        <td>
            <?php if ((int)$e['IMPORT_ID'] > 0): ?>
                <a class="sf-link" href="relod_seofixer_issues.php?lang=<?= LANGUAGE_ID; ?>&import_id=<?= (int)$e['IMPORT_ID']; ?>">#<?= (int)$e['IMPORT_ID']; ?></a>
            <?php else: ?><span class="sf-muted">—</span><?php endif; ?>
        </td>
        <td><?= (int)$e['ISSUE_ID'] > 0 ? '#' . (int)$e['ISSUE_ID'] : '<span class="sf-muted">—</span>'; ?></td>
        <td><?= AdminHelper::badge((string)$e['LEVEL'], (string)$e['LEVEL'] === 'error' ? 'bad' : 'warn'); ?></td>
        <td>
            <div><?= AdminHelper::e((string)$e['MESSAGE_TEXT']); ?></div>
            <?php if ($context): ?>
                <div class="sf-note">
                <?php foreach ($context as $k => $v):
                    if (is_array($v)) {
                        $v = json_encode($v, JSON_UNESCAPED_UNICODE);
                    }
                    if (trim((string)$v) === '') {
                        continue;
                    }
                ?>
                    <div><b><?= AdminHelper::e((string)$k); ?>:</b> <?= AdminHelper::e(AdminHelper::short((string)$v, 220)); ?></div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </td>
        <td><?= AdminHelper::e((string)$e['CREATED_AT']); ?></td>
    </tr>
    <?php endwhile; ?>
    <?php if (!$has): ?>
    <tr><td colspan="6" class="sf-muted">Записей нет — всё, что модуль пытался сделать, выполнено.</td></tr>
    <?php endif; ?>
</table>
</div>
<?php
echo AdminHelper::script();
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
