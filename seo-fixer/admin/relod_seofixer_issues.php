<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';
use Bitrix\Main\Loader;
use Relod\SeoFixer\Model\ImportTable;
use Relod\SeoFixer\Model\IssueTable;
use Relod\SeoFixer\Service\FixService;
use Relod\SeoFixer\Service\ImportService;
use Relod\SeoFixer\Service\IssuePresenter;
use Relod\SeoFixer\Service\ReportTypeDetector;
use Relod\SeoFixer\Service\MaintenanceService;
use Relod\SeoFixer\Helper\AdminHelper;

$APPLICATION->SetTitle('RELOD SEO Fixer: найденные проблемы');
Loader::includeModule('relod.seofixer');
$message = null;
$error = null;
$importId = (int)($_REQUEST['import_id'] ?? 0);

$status = (string)($_REQUEST['status'] ?? '');
$risk = (string)($_REQUEST['risk'] ?? '');
$type = (string)($_REQUEST['type'] ?? '');
$limitView = max(20, min(500, (int)($_REQUEST['view_limit'] ?? 200)));

$filter = [];
if ($importId > 0) $filter['=IMPORT_ID'] = $importId;
if ($status !== '') $filter['=STATUS'] = $status;
if ($risk !== '') $filter['=RISK_LEVEL'] = $risk;
if ($type !== '') $filter['=ISSUE_TYPE'] = $type;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $ids = array_map('intval', $_POST['issue_ids'] ?? []);
    $service = new FixService();
    $action = (string)($_POST['action_button'] ?? '');
    try {
        if ($action === 'save') {
            $saved = $service->saveProposals($_POST['new_values'] ?? []);
            $message = 'Сохранено предложений: ' . $saved . '.';
        } elseif ($action === 'approve') {
            $service->approve($ids);
            $message = 'Выбранные пункты отмечены к исправлению.';
        } elseif ($action === 'skip') {
            $service->skip($ids);
            $message = 'Выбранные пункты пропущены.';
        } elseif ($action === 'apply') {
            global $USER;
            $limit = (int)($_POST['limit'] ?? COption::GetOptionString('relod.seofixer', 'batch_limit', '50'));
            $result = $service->applyApproved($importId, (int)$USER->GetID(), $limit);
            $message = 'Применение завершено. Исправлено: ' . $result['applied'] . ', не выполнено: ' . $result['failed'] . ', нужна ручная правка: ' . $result['manual'] . '.';
        } elseif ($action === 'refresh_selected') {
            $updated = (new ImportService())->refreshSuggestions(0, $ids);
            $message = 'Пересчитано выбранных предложений и рисков: ' . $updated . '.';
        } elseif ($action === 'refresh_filter') {
            $updated = (new ImportService())->refreshSuggestions($importId);
            $message = 'Пересчитано предложений и рисков по текущей загрузке: ' . $updated . '.';
        } elseif ($action === 'delete_selected') {
            $result = (new MaintenanceService())->clearIssues(0, $ids);
            $message = 'Удалено из списка проблем: ' . $result['issues'] . ', связанных записей изменений: ' . $result['changes'] . ', ошибок: ' . $result['errors'] . '.';
        } elseif ($action === 'clear_import_issues') {
            $result = (new MaintenanceService())->clearIssues($importId, []);
            $message = 'Список проблем очищен. Удалено проблем: ' . $result['issues'] . ', связанных записей изменений: ' . $result['changes'] . ', ошибок: ' . $result['errors'] . '.';
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

echo AdminHelper::hint('Галочка сама по себе ничего не меняет. Сначала проверьте строку и поле «Предлагается», при необходимости отредактируйте его и сохраните, затем отметьте пункт к исправлению. Кнопка применения работает только по статусу «Отмечено к исправлению».');

if ($importId > 0) {
    $import = ImportTable::getById($importId)->fetch();
    if ($import) {
        echo '<h2>Отчёт: ' . AdminHelper::e($import['ORIGINAL_NAME']) . '</h2>';
        echo '<p>Тип: ' . AdminHelper::e(ReportTypeDetector::typeTitle($import['REPORT_TYPE'])) . ', строк: ' . (int)$import['ROWS_TOTAL'] . '</p>';
    }
}

$types = [];
$typeRows = IssueTable::getList(['select' => ['ISSUE_TYPE'], 'group' => ['ISSUE_TYPE'], 'order' => ['ISSUE_TYPE' => 'ASC']]);
while ($row = $typeRows->fetch()) {
    $types[] = (string)$row['ISSUE_TYPE'];
}
?>
<form method="get" class="relod-seofixer-filter">
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID; ?>">
    ID загрузки: <input type="text" name="import_id" value="<?= $importId ?: ''; ?>" size="5">
    Тип:
    <select name="type">
        <option value="">Все</option>
        <?php foreach ($types as $t): ?>
            <option value="<?= AdminHelper::e($t); ?>" <?= $type === $t ? 'selected' : ''; ?>><?= AdminHelper::e(ReportTypeDetector::typeTitle($t)); ?></option>
        <?php endforeach; ?>
    </select>
    Статус:
    <select name="status">
        <option value="">Все</option>
        <?php foreach (['new','approved','manual','applied','failed','skipped'] as $s): ?>
            <option value="<?= $s; ?>" <?= $status === $s ? 'selected' : ''; ?>><?= AdminHelper::e(IssuePresenter::statusTitle($s)); ?></option>
        <?php endforeach; ?>
    </select>
    Риск:
    <select name="risk">
        <option value="">Все</option>
        <?php foreach (['low','medium','content','review','template','manual'] as $r): ?>
            <option value="<?= $r; ?>" <?= $risk === $r ? 'selected' : ''; ?>><?= AdminHelper::e(IssuePresenter::riskTitle($r)); ?></option>
        <?php endforeach; ?>
    </select>
    Показать строк: <input type="number" name="view_limit" value="<?= (int)$limitView; ?>" min="20" max="500" style="width:70px;">
    <input type="submit" value="Показать">
</form>

<form method="post">
<?= bitrix_sessid_post(); ?>
<input type="hidden" name="import_id" value="<?= $importId; ?>">
<input type="hidden" name="status" value="<?= AdminHelper::e($status); ?>">
<input type="hidden" name="risk" value="<?= AdminHelper::e($risk); ?>">
<input type="hidden" name="type" value="<?= AdminHelper::e($type); ?>">
<div class="relod-seofixer-wrap">
<table class="adm-list-table relod-seofixer-table">
<colgroup>
    <col style="width:34px"><col style="width:60px"><col style="width:19%"><col style="width:21%"><col style="width:18%"><col style="width:24%"><col style="width:9%"><col style="width:11%">
</colgroup>
<tr class="adm-list-table-header">
    <td><input type="checkbox" onclick="document.querySelectorAll('.issue-check').forEach(cb=>cb.checked=this.checked)"></td>
    <td>ID</td><td>Проблема</td><td>Где найдено</td><td>Сейчас</td><td>Предлагается</td><td>Статус</td><td>Риск</td>
</tr>
<?php
$issues = IssueTable::getList(['filter' => $filter, 'order' => ['ID' => 'ASC'], 'limit' => $limitView]);
$shown = 0;
while ($issue = $issues->fetch()):
    $shown++;
    $raw = AdminHelper::decodeJson($issue['RAW_DATA']);
?>
<tr>
    <td><input class="issue-check" type="checkbox" name="issue_ids[]" value="<?= (int)$issue['ID']; ?>"></td>
    <td><?= (int)$issue['ID']; ?></td>
    <td>
        <b><?= AdminHelper::e(ReportTypeDetector::typeTitle($issue['ISSUE_TYPE'])); ?></b><br>
        <span class="relod-seofixer-small"><?= AdminHelper::e(IssuePresenter::explain($issue)); ?></span>
        <?php if ($issue['ANCHOR_TEXT']): ?><br><span class="relod-seofixer-small">Анкор: <?= AdminHelper::e($issue['ANCHOR_TEXT']); ?></span><?php endif; ?>
    </td>
    <td>
        <?= AdminHelper::e(AdminHelper::short($issue['SOURCE_URL'] ?: $issue['PLACE_HINT'], 220)); ?><br>
        <span class="relod-seofixer-small"><?= AdminHelper::e($issue['PLACE_HINT']); ?></span>
        <?php if (!empty($issue['FINAL_URL'])): ?><br><span class="relod-seofixer-small">Конечный URL: <?= AdminHelper::e(AdminHelper::short($issue['FINAL_URL'], 180)); ?></span><?php endif; ?>
    </td>
    <td><code><?= AdminHelper::e(AdminHelper::short($issue['OLD_VALUE'], 600)); ?></code></td>
    <td>
        <textarea class="relod-seofixer-textarea" name="new_values[<?= (int)$issue['ID']; ?>]" placeholder="Нет предложения. Нажмите «Пересчитать предложения» или впишите корректное значение вручную."><?= AdminHelper::e($issue['NEW_VALUE']); ?></textarea>
        <?php if ($issue['NEW_VALUE'] === '' || $issue['NEW_VALUE'] === null): ?>
            <span class="relod-seofixer-small relod-seofixer-danger">Предложение пустое: нажмите пересчёт или заполните поле.</span>
        <?php endif; ?>
    </td>
    <td><span class="relod-seofixer-status"><?= AdminHelper::e(IssuePresenter::statusTitle($issue['STATUS'])); ?></span></td>
    <td><?= AdminHelper::badge(IssuePresenter::riskTitle($issue['RISK_LEVEL']), (string)$issue['RISK_LEVEL']); ?></td>
</tr>
<?php endwhile; ?>
<?php if ($shown === 0): ?>
<tr><td colspan="8">По текущему фильтру проблем не найдено.</td></tr>
<?php endif; ?>
</table>
</div>

<div class="relod-seofixer-actions">
    <p><b>Работа со списком</b></p>
    <p>
        <button type="submit" name="action_button" value="save" class="adm-btn">Сохранить предложения</button>
        <button type="submit" name="action_button" value="refresh_selected" class="adm-btn">Пересчитать выбранные предложения и риски</button>
        <?php if ($importId > 0): ?>
            <button type="submit" name="action_button" value="refresh_filter" class="adm-btn">Пересчитать весь отчёт</button>
        <?php endif; ?>
        <button type="submit" name="action_button" value="delete_selected" class="adm-btn" onclick="return confirm('Удалить выбранные проблемы из списка? Контент сайта не изменится.');">Удалить выбранные из списка</button>
        <?php if ($importId > 0): ?>
            <button type="submit" name="action_button" value="clear_import_issues" class="adm-btn adm-btn-delete" onclick="return confirm('Очистить весь список проблем этой загрузки? Контент сайта не изменится.');">Очистить список проблем этой загрузки</button>
        <?php endif; ?>
    </p>
</div>

<div class="relod-seofixer-actions">
    <p><b>Подтверждение и применение</b></p>
    <p>
        <button type="submit" name="action_button" value="approve" class="adm-btn">Отметить выбранные к исправлению</button>
        <button type="submit" name="action_button" value="skip" class="adm-btn">Отклонить / пропустить выбранные</button>
    </p>
    <p>Количество за один запуск: <input type="number" name="limit" value="<?= (int)COption::GetOptionString('relod.seofixer', 'batch_limit', '50'); ?>" min="1" max="200"></p>
    <p><button type="submit" name="action_button" value="apply" class="adm-btn-save" onclick="return confirm('Применить только подтверждённые исправления? Перед изменениями будут сохранены старые значения.');">Применить подтверждённые исправления</button></p>
    <p class="relod-seofixer-small">Для SEO-текстов модуль не выдумывает окончательное значение без проверки: поле «Предлагается» можно отредактировать до применения. Для файлов шаблона модуль покажет файл и строку; автоправка файлов включается отдельно в настройках.</p>
</div>
</form>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
