<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Relod\SeoFixer\Helper\AdminHelper;
use Relod\SeoFixer\Model\ChangeTable;
use Relod\SeoFixer\Model\ErrorLogTable;
use Relod\SeoFixer\Model\ImportTable;
use Relod\SeoFixer\Model\IssueTable;
use Relod\SeoFixer\Report\ReportCatalog;
use Relod\SeoFixer\Service\MaintenanceService;
use Relod\SeoFixer\Site\SiteContext;

/** @var CMain $APPLICATION */
/** @var CUser $USER */

$APPLICATION->SetTitle('RELOD SEO Fixer — обзор');

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
$siteId = (string)($_REQUEST['site_id'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    try {
        $maintenance = new MaintenanceService();
        $action = (string)($_POST['action_button'] ?? '');
        if ($action === 'delete_imports') {
            $r = $maintenance->deleteImports($_POST['import_ids'] ?? [], true);
            $message = 'Удалено загрузок: ' . $r['imports'] . ', проблем: ' . $r['issues'] . ', записей журнала: ' . $r['changes'] . ', ошибок: ' . $r['errors'] . ', файлов: ' . $r['files'] . '.';
        } elseif ($action === 'clear_logs') {
            $r = $maintenance->clearLogs();
            $message = 'Журналы очищены. Удалено изменений: ' . $r['changes'] . ', ошибок: ' . $r['errors'] . '.';
        } elseif ($action === 'clear_all') {
            $r = $maintenance->clearAll(true);
            $message = 'Полная очистка выполнена. Удалено загрузок: ' . $r['imports'] . ', проблем: ' . $r['issues'] . ', файлов: ' . $r['files'] . '.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$issueFilter = [];
if ($siteId !== '') {
    $issueFilter['=SITE_ID'] = $siteId;
}

$counts = [
    'new' => IssueTable::getCount($issueFilter + ['=STATUS' => 'new']),
    'approved' => IssueTable::getCount($issueFilter + ['=STATUS' => 'approved']),
    'applied' => IssueTable::getCount($issueFilter + ['=STATUS' => 'applied']),
    'resolved' => IssueTable::getCount($issueFilter + ['=STATUS' => 'resolved']),
    'manual' => IssueTable::getCount($issueFilter + ['=STATUS' => 'manual']),
    'failed' => IssueTable::getCount($issueFilter + ['=STATUS' => 'failed']),
];
$totalImports = ImportTable::getCount($siteId !== '' ? ['=SITE_ID' => $siteId] : []);
$totalChanges = ChangeTable::getCount([]);
$totalErrors = ErrorLogTable::getCount([]);

// Топ проблем по приоритету.
$byType = [];
$res = IssueTable::getList([
    'filter' => $issueFilter + ['@STATUS' => ['new', 'approved', 'manual', 'failed']],
    'select' => ['ISSUE_TYPE', 'PRIORITY', 'CNT'],
    'runtime' => [new \Bitrix\Main\Entity\ExpressionField('CNT', 'COUNT(1)')],
    'group' => ['ISSUE_TYPE', 'PRIORITY'],
]);
while ($row = $res->fetch()) {
    $byType[] = $row;
}
usort($byType, static function ($a, $b) {
    return [(int)$b['PRIORITY'], (int)$b['CNT']] <=> [(int)$a['PRIORITY'], (int)$a['CNT']];
});

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
echo AdminHelper::style();
echo '<div class="sf-wrap">';
echo AdminHelper::nav('dashboard', ['site_id' => $siteId]);
if ($error) {
    echo AdminHelper::error($error);
}
if ($message) {
    echo AdminHelper::success($message);
}
echo AdminHelper::hint('Модуль ничего не меняет сам после загрузки отчёта. Сначала он сверяет отчёт с текущим состоянием сайта, показывает проблему и готовое решение, и только подтверждённые пункты применяет.');
?>

<form method="get" class="sf-filter">
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID; ?>">
    <label>Сайт:</label>
    <?= AdminHelper::siteSelect('site_id', $siteId); ?>
    <button type="submit" class="sf-btn">Показать</button>
</form>

<div class="sf-stats">
    <?= AdminHelper::stat('Нужно проверить', $counts['new'], $counts['new'] > 0 ? 'high' : '', 'relod_seofixer_issues.php?lang=' . LANGUAGE_ID . '&status=new&site_id=' . urlencode($siteId)); ?>
    <?= AdminHelper::stat('Подтверждено', $counts['approved'], 'warn', 'relod_seofixer_issues.php?lang=' . LANGUAGE_ID . '&status=approved&site_id=' . urlencode($siteId)); ?>
    <?= AdminHelper::stat('Исправлено модулем', $counts['applied'], 'ok', 'relod_seofixer_issues.php?lang=' . LANGUAGE_ID . '&status=applied&site_id=' . urlencode($siteId)); ?>
    <?= AdminHelper::stat('Уже в порядке', $counts['resolved'], 'ok', 'relod_seofixer_issues.php?lang=' . LANGUAGE_ID . '&status=resolved&site_id=' . urlencode($siteId)); ?>
    <?= AdminHelper::stat('Нужна ручная правка', $counts['manual'], '', 'relod_seofixer_issues.php?lang=' . LANGUAGE_ID . '&status=manual&site_id=' . urlencode($siteId)); ?>
    <?= AdminHelper::stat('Не удалось', $counts['failed'], $counts['failed'] > 0 ? 'high' : '', 'relod_seofixer_issues.php?lang=' . LANGUAGE_ID . '&status=failed&site_id=' . urlencode($siteId)); ?>
</div>

<div class="sf-stats">
    <?= AdminHelper::stat('Загрузок отчётов', $totalImports); ?>
    <?= AdminHelper::stat('Записей в журнале изменений', $totalChanges, '', 'relod_seofixer_changes.php?lang=' . LANGUAGE_ID); ?>
    <?= AdminHelper::stat('Не выполнено / ошибки', $totalErrors, '', 'relod_seofixer_errors.php?lang=' . LANGUAGE_ID); ?>
</div>

<h2 class="sf-h2">С чего начать: проблемы по приоритету</h2>
<?php if (!$byType): ?>
    <div class="sf-empty">Открытых проблем нет. Загрузите отчёт Netpeak, чтобы модуль их нашёл.</div>
<?php else: ?>
<table class="sf-table">
    <tr>
        <th style="width:90px">Приоритет</th>
        <th>Проблема</th>
        <th style="width:100px">Страниц</th>
        <th style="width:150px">Что делает модуль</th>
        <th style="width:120px"></th>
    </tr>
    <?php foreach (array_slice($byType, 0, 20) as $row):
        $type = (string)$row['ISSUE_TYPE'];
        $meta = ReportCatalog::get($type);
        $explain = ReportCatalog::explain($type);
    ?>
    <tr>
        <td><?= AdminHelper::priorityBar((int)$row['PRIORITY']); ?></td>
        <td>
            <b><?= AdminHelper::e(ReportCatalog::title($type)); ?></b>
            <?= AdminHelper::badge(ReportCatalog::severityTitle((string)$meta['severity']), (string)$meta['severity']); ?>
            <div class="sf-explain"><?= AdminHelper::e($explain['what']); ?></div>
        </td>
        <td><b><?= (int)$row['CNT']; ?></b></td>
        <td>
            <?php if (!empty($meta['auto'])): ?>
                <?= AdminHelper::badge('Исправит сам', 'ok'); ?>
            <?php else: ?>
                <?= AdminHelper::badge('Покажет решение', 'warn'); ?>
            <?php endif; ?>
            <div class="sf-note"><?= AdminHelper::e(ReportCatalog::strategyTitle((string)$meta['strategy'])); ?></div>
        </td>
        <td>
            <a class="sf-btn" href="relod_seofixer_issues.php?lang=<?= LANGUAGE_ID; ?>&type=<?= urlencode($type); ?>&site_id=<?= urlencode($siteId); ?>">Открыть</a>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>

<h2 class="sf-h2">Загруженные отчёты</h2>
<form method="post">
<?= bitrix_sessid_post(); ?>
<table class="sf-table">
    <tr>
        <th style="width:34px"><input type="checkbox" onclick="sfToggleAll(this)"></th>
        <th style="width:60px">ID</th>
        <th>Файл</th>
        <th style="width:180px">Тип отчёта</th>
        <th style="width:140px">Сайт</th>
        <th style="width:80px">Строк</th>
        <th style="width:90px">Карточек</th>
        <th style="width:140px">Загружен</th>
        <th style="width:110px"></th>
    </tr>
    <?php
    $importFilter = $siteId !== '' ? ['=SITE_ID' => $siteId] : [];
    $imports = ImportTable::getList(['filter' => $importFilter, 'order' => ['ID' => 'DESC'], 'limit' => 60]);
    $hasImports = false;
    while ($import = $imports->fetch()):
        $hasImports = true;
    ?>
    <tr>
        <td><input class="sf-check" type="checkbox" name="import_ids[]" value="<?= (int)$import['ID']; ?>"></td>
        <td><?= (int)$import['ID']; ?></td>
        <td>
            <?= AdminHelper::e(AdminHelper::short((string)$import['ORIGINAL_NAME'], 90)); ?>
            <?php if ($import['COMMENT_TEXT']): ?>
                <div class="sf-note"><?= AdminHelper::e(AdminHelper::short((string)$import['COMMENT_TEXT'], 120)); ?></div>
            <?php endif; ?>
        </td>
        <td>
            <?= AdminHelper::e(ReportCatalog::title((string)$import['REPORT_TYPE'])); ?>
            <?php if ((string)$import['REPORT_TYPE'] === ReportCatalog::TYPE_UNKNOWN): ?>
                <div class="sf-note">Тип не распознан — проверьте, что имя файла не менялось.</div>
            <?php endif; ?>
        </td>
        <td>
            <?= AdminHelper::e((string)$import['SITE_ID']); ?>
            <div class="sf-note"><?= AdminHelper::e((string)$import['DOMAIN']); ?></div>
        </td>
        <td><?= (int)$import['ROWS_TOTAL']; ?></td>
        <td><b><?= (int)$import['ISSUES_CREATED']; ?></b></td>
        <td><?= AdminHelper::e((string)$import['IMPORTED_AT']); ?></td>
        <td><a class="sf-btn" href="relod_seofixer_issues.php?lang=<?= LANGUAGE_ID; ?>&import_id=<?= (int)$import['ID']; ?>">Открыть</a></td>
    </tr>
    <?php endwhile; ?>
    <?php if (!$hasImports): ?>
    <tr><td colspan="9" class="sf-muted">Отчёты ещё не загружались.</td></tr>
    <?php endif; ?>
</table>

<div class="sf-actions">
    <div class="sf-actions-row">
        <button type="submit" name="action_button" value="delete_imports" class="sf-btn sf-btn-danger" onclick="return confirm('Удалить выбранные загрузки со всеми их карточками и журналами? Контент сайта не изменится.');">Удалить выбранные загрузки</button>
        <button type="submit" name="action_button" value="clear_logs" class="sf-btn" onclick="return confirm('Очистить журналы изменений и ошибок?');">Очистить журналы</button>
        <button type="submit" name="action_button" value="clear_all" class="sf-btn sf-btn-danger" onclick="return confirm('Полностью очистить все данные модуля? Контент сайта не изменится.');">Очистить всё</button>
        <span class="sf-counter">Выбрано: <b class="sf-selected-count">0</b></span>
    </div>
    <div class="sf-note">Очистка удаляет только служебные данные модуля и загруженные файлы отчётов из /upload/relod_seofixer. Контент сайта не затрагивается.</div>
</div>
</form>
</div>
<?php
echo AdminHelper::script();
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
