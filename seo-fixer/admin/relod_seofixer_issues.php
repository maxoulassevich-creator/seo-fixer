<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Relod\SeoFixer\Fix\FixService;
use Relod\SeoFixer\Fix\SuggestionEngine;
use Relod\SeoFixer\Helper\AdminHelper;
use Relod\SeoFixer\Model\ImportTable;
use Relod\SeoFixer\Model\IssueTable;
use Relod\SeoFixer\Report\ReportCatalog;
use Relod\SeoFixer\Service\ImportService;
use Relod\SeoFixer\Service\IssuePresenter;
use Relod\SeoFixer\Service\MaintenanceService;
use Relod\SeoFixer\Site\LiveVerifier;

/** @var CMain $APPLICATION */
/** @var CUser $USER */

$APPLICATION->SetTitle('RELOD SEO Fixer — найденные проблемы');

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

$importId = (int)($_REQUEST['import_id'] ?? 0);
$siteId = (string)($_REQUEST['site_id'] ?? '');
$status = (string)($_REQUEST['status'] ?? '');
$risk = (string)($_REQUEST['risk'] ?? '');
$type = (string)($_REQUEST['type'] ?? '');
$category = (string)($_REQUEST['category'] ?? '');
$search = trim((string)($_REQUEST['q'] ?? ''));
$viewLimit = max(10, min(200, (int)($_REQUEST['view_limit'] ?? 50)));

/** Собирает фильтр для выборки. */
$buildFilter = static function () use ($importId, $siteId, $status, $risk, $type, $category, $search): array {
    $filter = [];
    if ($importId > 0) {
        $filter['=IMPORT_ID'] = $importId;
    }
    if ($siteId !== '') {
        $filter['=SITE_ID'] = $siteId;
    }
    if ($status !== '') {
        $filter['=STATUS'] = $status;
    } else {
        $filter['!=STATUS'] = 'skipped';
    }
    if ($risk !== '') {
        $filter['=RISK_LEVEL'] = $risk;
    }
    if ($type !== '') {
        $filter['=ISSUE_TYPE'] = $type;
    }
    if ($category !== '') {
        $filter['=CATEGORY'] = $category;
    }
    if ($search !== '') {
        $filter['%SOURCE_URL'] = $search;
    }
    return $filter;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    try {
        $ids = array_map('intval', (array)($_POST['issue_ids'] ?? []));
        $action = (string)($_POST['action_button'] ?? '');
        $fix = new FixService();
        $userId = (int)$USER->GetID();

        switch ($action) {
            case 'save':
                $saved = $fix->saveProposals((array)($_POST['new_values'] ?? []));
                $message = 'Сохранено предложений: ' . $saved . '.';
                break;

            case 'approve':
                $n = $fix->approve($ids);
                $message = 'Подтверждено к исправлению: ' . $n . '.';
                break;

            case 'approve_all':
                $fix->saveProposals((array)($_POST['new_values'] ?? []));
                $r = $fix->approveByFilter($buildFilter(), 2000);
                $message = 'Подтверждено по текущему фильтру: ' . $r['approved'] . '.'
                    . ($r['without_value'] > 0 ? ' Пропущено без предложения: ' . $r['without_value'] . ' — сначала нажмите «Подобрать предложения».' : '');
                break;

            case 'skip':
                $n = $fix->skip($ids);
                $message = 'Отклонено: ' . $n . '.';
                break;

            case 'reopen':
                $n = $fix->reopen($ids);
                $message = 'Возвращено в работу: ' . $n . '.';
                break;

            case 'apply':
                $fix->saveProposals((array)($_POST['new_values'] ?? []));
                $limit = (int)($_POST['limit'] ?? COption::GetOptionString('relod.seofixer', 'batch_limit', '50'));
                $r = $fix->applyApproved($buildFilter(), $userId, $limit);
                $message = 'Применение завершено. Исправлено: ' . $r['applied']
                    . ', уже было в порядке: ' . $r['resolved']
                    . ', нужна ручная правка: ' . $r['manual']
                    . ', не удалось: ' . $r['failed'] . '.';
                break;

            case 'verify':
                $verifier = new LiveVerifier();
                $filter = $buildFilter();
                if ($ids) {
                    $filter = ['@ID' => $ids];
                }
                $r = $verifier->verifyBatch($filter, 150);
                $message = 'Проверено на сайте: ' . $r['checked']
                    . '. Уже в порядке: ' . $r['resolved']
                    . ', проблема подтверждена: ' . $r['confirmed']
                    . ', сайт не ответил: ' . $r['unreachable'] . '.';
                break;

            case 'suggest':
                $service = new ImportService();
                $filter = $ids ? ['@ID' => $ids] : $buildFilter();
                $r = $service->enrich($filter, 200, COption::GetOptionString('relod.seofixer', 'verify_on_import', 'Y') === 'Y');
                $message = 'Обработано карточек: ' . $r['processed']
                    . '. Найдено место правки: ' . $r['targeted']
                    . ', подготовлено предложений: ' . $r['suggested'] . '.';
                break;

            case 'delete_selected':
                $r = (new MaintenanceService())->clearIssues(0, $ids);
                $message = 'Удалено карточек: ' . $r['issues'] . '. Контент сайта не изменился.';
                break;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$filter = $buildFilter();
$total = IssueTable::getCount($filter);

// Списки для фильтров.
$types = [];
$res = IssueTable::getList(['select' => ['ISSUE_TYPE'], 'group' => ['ISSUE_TYPE'], 'order' => ['ISSUE_TYPE' => 'ASC']]);
while ($row = $res->fetch()) {
    $types[] = (string)$row['ISSUE_TYPE'];
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
echo AdminHelper::style();
echo '<div class="sf-wrap">';
echo AdminHelper::nav('issues', ['site_id' => $siteId]);
if ($error) {
    echo AdminHelper::error($error);
}
if ($message) {
    echo AdminHelper::success($message);
}
echo AdminHelper::hint('Галочка ничего не меняет сама по себе. Проверьте предложение, при необходимости отредактируйте его, затем подтвердите пункт и нажмите «Применить подтверждённые». Кнопка «Проверить на сайте» опрашивает страницы прямо сейчас и закрывает то, что уже исправлено.');

if ($importId > 0) {
    $import = ImportTable::getById($importId)->fetch();
    if ($import) {
        echo '<div class="sf-hint">Отчёт: <b>' . AdminHelper::e((string)$import['ORIGINAL_NAME']) . '</b> — '
            . AdminHelper::e(ReportCatalog::title((string)$import['REPORT_TYPE']))
            . ', сайт ' . AdminHelper::e((string)$import['SITE_ID']) . ' (' . AdminHelper::e((string)$import['DOMAIN']) . ')'
            . ', строк: ' . (int)$import['ROWS_TOTAL'] . '</div>';
    }
}
?>

<form method="get" class="sf-filter">
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID; ?>">
    <label>Сайт:</label><?= AdminHelper::siteSelect('site_id', $siteId); ?>
    <label>Загрузка:</label><input class="sf-input" style="width:70px" type="text" name="import_id" value="<?= $importId ?: ''; ?>">
    <label>Тип:</label>
    <select name="type" class="sf-select">
        <option value="">Все типы</option>
        <?php foreach ($types as $t): ?>
            <option value="<?= AdminHelper::e($t); ?>"<?= $type === $t ? ' selected' : ''; ?>><?= AdminHelper::e(ReportCatalog::title($t)); ?></option>
        <?php endforeach; ?>
    </select>
    <label>Статус:</label>
    <select name="status" class="sf-select">
        <option value="">Кроме отклонённых</option>
        <?php foreach (IssuePresenter::statuses() as $s): ?>
            <option value="<?= $s; ?>"<?= $status === $s ? ' selected' : ''; ?>><?= AdminHelper::e(IssuePresenter::statusTitle($s)); ?></option>
        <?php endforeach; ?>
    </select>
    <label>Риск:</label>
    <select name="risk" class="sf-select">
        <option value="">Любой</option>
        <?php foreach (IssuePresenter::risks() as $r): ?>
            <option value="<?= $r; ?>"<?= $risk === $r ? ' selected' : ''; ?>><?= AdminHelper::e(IssuePresenter::riskTitle($r)); ?></option>
        <?php endforeach; ?>
    </select>
    <label>Адрес содержит:</label><input class="sf-input" style="width:170px" type="text" name="q" value="<?= AdminHelper::e($search); ?>">
    <label>Показать:</label><input class="sf-input" style="width:60px" type="number" name="view_limit" min="10" max="200" value="<?= $viewLimit; ?>">
    <button type="submit" class="sf-btn sf-btn-primary">Применить фильтр</button>
    <span class="sf-counter">Найдено: <b><?= (int)$total; ?></b></span>
</form>

<form method="post">
<?= bitrix_sessid_post(); ?>
<input type="hidden" name="import_id" value="<?= $importId; ?>">
<input type="hidden" name="site_id" value="<?= AdminHelper::e($siteId); ?>">
<input type="hidden" name="status" value="<?= AdminHelper::e($status); ?>">
<input type="hidden" name="risk" value="<?= AdminHelper::e($risk); ?>">
<input type="hidden" name="type" value="<?= AdminHelper::e($type); ?>">
<input type="hidden" name="q" value="<?= AdminHelper::e($search); ?>">
<input type="hidden" name="view_limit" value="<?= $viewLimit; ?>">

<div class="sf-filter" style="margin-bottom:12px">
    <label><input type="checkbox" onclick="sfToggleAll(this)"> Выделить все на странице</label>
    <span class="sf-counter">Выбрано: <b class="sf-selected-count">0</b></span>
</div>

<?php
$issues = IssueTable::getList([
    'filter' => $filter,
    'order' => ['PRIORITY' => 'DESC', 'GROUP_HASH' => 'ASC', 'ID' => 'ASC'],
    'limit' => $viewLimit,
]);
$shown = 0;
while ($issue = $issues->fetch()):
    $shown++;
    $issueType = (string)$issue['ISSUE_TYPE'];
    $meta = ReportCatalog::get($issueType);
    $explain = ReportCatalog::explain($issueType);
    $statusCode = (string)$issue['STATUS'];
    $field = ReportCatalog::valueField($issueType);
    $isDone = in_array($statusCode, ['applied', 'resolved', 'skipped'], true);
    $newValue = (string)$issue['NEW_VALUE'];
    $liveVerdict = trim((string)$issue['LIVE_VERDICT']);
    $verdictTone = '';
    if ($statusCode === 'resolved') {
        $verdictTone = ' sf-verdict-ok';
    } elseif ((int)$issue['LIVE_STATUS'] >= 400) {
        $verdictTone = ' sf-verdict-bad';
    }
?>
<div class="sf-card<?= $isDone ? ' sf-card-done' : ''; ?>">
    <div class="sf-card-head">
        <input class="sf-check" type="checkbox" name="issue_ids[]" value="<?= (int)$issue['ID']; ?>"<?= $isDone ? '' : ''; ?>>
        <div class="sf-card-title">
            <h3><?= AdminHelper::e(ReportCatalog::title($issueType)); ?> <span class="sf-muted">#<?= (int)$issue['ID']; ?></span></h3>
            <div class="sf-explain"><?= AdminHelper::e($explain['what']); ?> <b><?= AdminHelper::e($explain['why']); ?></b></div>
            <div class="sf-card-meta">
                <?= AdminHelper::priorityBar((int)$issue['PRIORITY']); ?>
                <?= AdminHelper::badge(ReportCatalog::severityTitle((string)$issue['SEVERITY']), (string)$issue['SEVERITY']); ?>
                <?= AdminHelper::badge(IssuePresenter::statusTitle($statusCode), IssuePresenter::statusClass($statusCode)); ?>
                <?= AdminHelper::badge(IssuePresenter::riskTitle((string)$issue['RISK_LEVEL']), (string)$issue['RISK_LEVEL'], IssuePresenter::riskHint((string)$issue['RISK_LEVEL'])); ?>
                <?= AdminHelper::badge(ReportCatalog::categoryTitle((string)$issue['CATEGORY']), 'muted'); ?>
            </div>
        </div>
    </div>

    <div class="sf-card-body">
        <div class="sf-block">
            <h4>Где найдено</h4>
            <div><?= AdminHelper::externalLink((string)$issue['SOURCE_URL'], 90); ?></div>
            <?php if (trim((string)$issue['TARGET_NOTE']) !== ''): ?>
                <div class="sf-note"><?= AdminHelper::e((string)$issue['TARGET_NOTE']); ?></div>
            <?php endif; ?>
            <?php if (trim((string)$issue['TARGET_ADMIN_URL']) !== ''): ?>
                <div class="sf-note"><a class="sf-link" href="<?= AdminHelper::e((string)$issue['TARGET_ADMIN_URL']); ?>" target="_blank">Открыть форму редактирования в Битриксе →</a></div>
            <?php endif; ?>
            <?php if (trim((string)$issue['ANCHOR_TEXT']) !== ''): ?>
                <div class="sf-note">Текст ссылки: «<?= AdminHelper::e(AdminHelper::short((string)$issue['ANCHOR_TEXT'], 90)); ?>»</div>
            <?php endif; ?>

            <h4 style="margin-top:12px">Сейчас (из отчёта)</h4>
            <div class="sf-value<?= trim((string)$issue['OLD_VALUE']) === '' ? ' sf-value-empty' : ''; ?>">
                <?= trim((string)$issue['OLD_VALUE']) !== '' ? AdminHelper::e(AdminHelper::short((string)$issue['OLD_VALUE'], 400)) : 'пусто'; ?>
            </div>

            <?php if ($liveVerdict !== ''): ?>
                <div class="sf-verdict<?= $verdictTone; ?>"><?= AdminHelper::e($liveVerdict); ?></div>
            <?php else: ?>
                <div class="sf-note">Страница ещё не проверялась на сайте — нажмите «Проверить на сайте».</div>
            <?php endif; ?>
        </div>

        <div class="sf-block">
            <h4>Предлагается</h4>
            <?php if (ReportCatalog::isAuto($issueType)): ?>
                <textarea class="sf-textarea" name="new_values[<?= (int)$issue['ID']; ?>]" placeholder="Нажмите «Подобрать предложения» или впишите значение вручную."><?= AdminHelper::e($newValue); ?></textarea>
                <?php if ($field === 'title'): ?>
                    <div class="sf-note"><?= AdminHelper::lengthMeter($newValue, SuggestionEngine::TITLE_MIN, SuggestionEngine::TITLE_MAX); ?> <span class="sf-live-len"></span></div>
                <?php elseif ($field === 'description'): ?>
                    <div class="sf-note"><?= AdminHelper::lengthMeter($newValue, SuggestionEngine::DESCRIPTION_MIN, SuggestionEngine::DESCRIPTION_MAX); ?> <span class="sf-live-len"></span></div>
                <?php endif; ?>
            <?php else: ?>
                <div class="sf-value<?= $newValue === '' ? ' sf-value-empty' : ''; ?>"><?= $newValue !== '' ? AdminHelper::e($newValue) : 'Автоматическая замена для этого типа не применяется.'; ?></div>
            <?php endif; ?>

            <?php if (trim((string)$issue['SUGGESTION_NOTE']) !== ''): ?>
                <div class="sf-note"><?= AdminHelper::e((string)$issue['SUGGESTION_NOTE']); ?></div>
            <?php endif; ?>

            <h4 style="margin-top:12px">Что произойдёт при применении</h4>
            <div class="sf-explain"><?= AdminHelper::e(IssuePresenter::plannedAction($issue)); ?></div>

            <?php if (!ReportCatalog::isAuto($issueType) || $statusCode === 'manual' || $statusCode === 'failed'): ?>
                <h4 style="margin-top:12px">Что сделать вручную</h4>
                <div class="sf-explain"><?= AdminHelper::e(IssuePresenter::manualInstruction($issue)); ?></div>
            <?php endif; ?>

            <?php if ($explain['how'] !== ''): ?>
                <div class="sf-note" style="margin-top:8px"><b>Как правильно:</b> <?= AdminHelper::e($explain['how']); ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endwhile; ?>

<?php if ($shown === 0): ?>
    <div class="sf-empty">По текущему фильтру проблем не найдено.</div>
<?php endif; ?>

<div class="sf-actions">
    <div class="sf-actions-row">
        <button type="submit" name="action_button" value="suggest" class="sf-btn">Подобрать предложения</button>
        <button type="submit" name="action_button" value="verify" class="sf-btn">Проверить на сайте</button>
        <button type="submit" name="action_button" value="save" class="sf-btn">Сохранить правки текста</button>
        <span class="sf-counter">Выбрано: <b class="sf-selected-count">0</b> из <?= (int)$total; ?></span>
    </div>
    <div class="sf-actions-row" style="margin-top:10px">
        <button type="submit" name="action_button" value="approve" class="sf-btn">Подтвердить выбранные</button>
        <button type="submit" name="action_button" value="approve_all" class="sf-btn" onclick="return confirm('Подтвердить ВСЕ карточки, подходящие под текущий фильтр, у которых заполнено предложение?');">Подтвердить все по фильтру</button>
        <button type="submit" name="action_button" value="skip" class="sf-btn">Отклонить выбранные</button>
        <button type="submit" name="action_button" value="reopen" class="sf-btn">Вернуть в работу</button>
        <button type="submit" name="action_button" value="delete_selected" class="sf-btn sf-btn-danger" onclick="return confirm('Удалить выбранные карточки из списка? Контент сайта не изменится.');">Удалить из списка</button>
    </div>
    <div class="sf-actions-row" style="margin-top:10px">
        <label>За один запуск не более:</label>
        <input class="sf-input" style="width:70px" type="number" name="limit" min="1" max="500" value="<?= (int)COption::GetOptionString('relod.seofixer', 'batch_limit', '50'); ?>">
        <button type="submit" name="action_button" value="apply" class="sf-btn sf-btn-primary" onclick="return confirm('Применить подтверждённые исправления? Перед каждой правкой модуль проверит страницу вживую и сохранит старое значение.');">Применить подтверждённые</button>
        <span class="sf-note">Применяются только карточки со статусом «Подтверждено к исправлению» и заполненным предложением.</span>
    </div>
</div>
</form>
</div>
<?php
echo AdminHelper::script();
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
