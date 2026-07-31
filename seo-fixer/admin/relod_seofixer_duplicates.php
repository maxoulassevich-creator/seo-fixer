<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Relod\SeoFixer\Fix\DuplicateResolver;
use Relod\SeoFixer\Fix\FixService;
use Relod\SeoFixer\Fix\SuggestionEngine;
use Relod\SeoFixer\Helper\AdminHelper;
use Relod\SeoFixer\Model\IssueTable;
use Relod\SeoFixer\Report\ReportCatalog;
use Relod\SeoFixer\Service\IssuePresenter;

/** @var CMain $APPLICATION */
/** @var CUser $USER */

$APPLICATION->SetTitle('RELOD SEO Fixer — дубликаты title и description');

if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Доступ только для администратора.');
}
if (!Loader::includeModule('relod.seofixer')) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    CAdminMessage::ShowMessage('Модуль relod.seofixer не подключён.');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    return;
}

/** Типы, которые обслуживает эта страница. */
$DUPLICATE_TYPES = ['duplicate_description', 'duplicate_title', 'duplicate_h1'];

$message = null;
$error = null;
$siteId = (string)($_REQUEST['site_id'] ?? '');
$typeFilter = (string)($_REQUEST['type'] ?? '');
$hideDone = ($_REQUEST['hide_done'] ?? '1') === '1';

$buildFilter = static function () use ($DUPLICATE_TYPES, $siteId, $typeFilter, $hideDone): array {
    $filter = ['@ISSUE_TYPE' => $typeFilter !== '' ? [$typeFilter] : $DUPLICATE_TYPES];
    if ($siteId !== '') {
        $filter['=SITE_ID'] = $siteId;
    }
    if ($hideDone) {
        $filter['!@STATUS'] = ['applied', 'resolved', 'skipped'];
    }
    return $filter;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    try {
        $fix = new FixService();
        $action = (string)($_POST['action_button'] ?? '');
        $ids = array_map('intval', (array)($_POST['issue_ids'] ?? []));
        $userId = (int)$USER->GetID();

        switch ($action) {
            case 'generate':
                $resolver = new DuplicateResolver();
                $useLive = COption::GetOptionString('relod.seofixer', 'verify_on_import', 'Y') === 'Y';
                $r = $resolver->generate($buildFilter(), 500, $useLive);
                $message = 'Подобрано уникальных значений: ' . $r['filled'] . ' из ' . $r['processed'] . '.'
                    . ($r['collisions'] > 0 ? ' Уточнено во избежание новых совпадений: ' . $r['collisions'] . '.' : '')
                    . ($r['skipped'] > 0 ? ' Не удалось подобрать: ' . $r['skipped'] . ' — впишите вручную.' : '');
                break;

            case 'save':
                $saved = $fix->saveProposals((array)($_POST['new_values'] ?? []));
                $message = 'Сохранено правок: ' . $saved . '.';
                break;

            case 'approve':
                $fix->saveProposals((array)($_POST['new_values'] ?? []));
                $n = $fix->approve($ids);
                $message = 'Подтверждено: ' . $n . '.';
                break;

            case 'approve_all':
                $fix->saveProposals((array)($_POST['new_values'] ?? []));
                $r = $fix->approveByFilter($buildFilter(), 5000);
                $message = 'Подтверждено всё по фильтру: ' . $r['approved'] . '.'
                    . ($r['without_value'] > 0 ? ' Без предложения пропущено: ' . $r['without_value'] . '.' : '');
                break;

            case 'apply':
                $fix->saveProposals((array)($_POST['new_values'] ?? []));
                $limit = (int)($_POST['limit'] ?? 100);
                $r = $fix->applyApproved($buildFilter(), $userId, $limit);
                $message = 'Применение завершено. Исправлено: ' . $r['applied']
                    . ', уже было в порядке: ' . $r['resolved']
                    . ', нужна ручная правка: ' . $r['manual']
                    . ', не удалось: ' . $r['failed'] . '.';
                break;

            case 'skip':
                $n = $fix->skip($ids);
                $message = 'Отклонено: ' . $n . '.';
                break;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$filter = $buildFilter();
$total = IssueTable::getCount($filter);
$readyCount = IssueTable::getCount($filter + ['!=NEW_VALUE' => '']);
$approvedCount = IssueTable::getCount($filter + ['=STATUS' => 'approved']);

// Собираем группы.
$rows = IssueTable::getList([
    'filter' => $filter,
    'order' => ['PRIORITY' => 'DESC', 'GROUP_HASH' => 'ASC', 'SOURCE_URL' => 'ASC'],
    'limit' => 800,
]);
$groups = [];
while ($issue = $rows->fetch()) {
    $hash = (string)$issue['GROUP_HASH'];
    if (!isset($groups[$hash])) {
        $groups[$hash] = [
            'type' => (string)$issue['ISSUE_TYPE'],
            'value' => (string)$issue['OLD_VALUE'],
            'items' => [],
        ];
    }
    $groups[$hash]['items'][] = $issue;
}
uasort($groups, static function ($a, $b) {
    return count($b['items']) <=> count($a['items']);
});

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
echo AdminHelper::style();
echo '<div class="sf-wrap">';
echo AdminHelper::nav('duplicates', ['site_id' => $siteId]);
if ($error) {
    echo AdminHelper::error($error);
}
if ($message) {
    echo AdminHelper::success($message);
}
echo AdminHelper::hint('Дубликаты title и description — проблема с самым высоким приоритетом: из-за них страницы конкурируют друг с другом. Порядок работы: «Подобрать уникальные значения» → просмотреть → «Подтвердить всё» → «Применить». Модуль гарантирует, что новые значения не совпадут между собой.');
?>

<div class="sf-stats">
    <?= AdminHelper::stat('Страниц с дубликатами', $total, $total > 0 ? 'high' : 'ok'); ?>
    <?= AdminHelper::stat('Групп дубликатов', count($groups)); ?>
    <?= AdminHelper::stat('Готово предложений', $readyCount, $readyCount >= $total && $total > 0 ? 'ok' : 'warn'); ?>
    <?= AdminHelper::stat('Подтверждено', $approvedCount, 'warn'); ?>
</div>

<form method="get" class="sf-filter">
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID; ?>">
    <label>Сайт:</label><?= AdminHelper::siteSelect('site_id', $siteId); ?>
    <label>Тип:</label>
    <select name="type" class="sf-select">
        <option value="">Все дубликаты</option>
        <?php foreach ($DUPLICATE_TYPES as $t): ?>
            <option value="<?= $t; ?>"<?= $typeFilter === $t ? ' selected' : ''; ?>><?= AdminHelper::e(ReportCatalog::title($t)); ?></option>
        <?php endforeach; ?>
    </select>
    <label><input type="checkbox" name="hide_done" value="1"<?= $hideDone ? ' checked' : ''; ?>> Скрыть закрытые</label>
    <button type="submit" class="sf-btn sf-btn-primary">Показать</button>
</form>

<form method="post">
<?= bitrix_sessid_post(); ?>
<input type="hidden" name="site_id" value="<?= AdminHelper::e($siteId); ?>">
<input type="hidden" name="type" value="<?= AdminHelper::e($typeFilter); ?>">
<input type="hidden" name="hide_done" value="<?= $hideDone ? '1' : '0'; ?>">

<?php if (!$groups): ?>
    <div class="sf-empty">Дубликатов title и description не найдено. Загрузите отчёты Netpeak «Duplicate Titles» и «Duplicate Descriptions», либо сводный отчёт «URLs and their Issues».</div>
<?php endif; ?>

<?php foreach ($groups as $hash => $group):
    $groupType = $group['type'];
    $field = ReportCatalog::valueField($groupType);
    $min = $field === 'description' ? SuggestionEngine::DESCRIPTION_MIN : SuggestionEngine::TITLE_MIN;
    $max = $field === 'description' ? SuggestionEngine::DESCRIPTION_MAX : SuggestionEngine::TITLE_MAX;
?>
<div class="sf-group">
    <div class="sf-group-head">
        <input class="sf-check" type="checkbox" onclick="sfToggleGroup(this,'<?= AdminHelper::e($hash); ?>')">
        <h3>
            <?= AdminHelper::e(ReportCatalog::title($groupType)); ?>:
            «<?= AdminHelper::e(AdminHelper::short($group['value'], 150)); ?>»
        </h3>
        <?= AdminHelper::badge('Страниц: ' . count($group['items']), 'error'); ?>
    </div>

    <table class="sf-table">
        <tr>
            <th style="width:34px"></th>
            <th style="width:32%">Страница</th>
            <th>Уникальное значение для этой страницы</th>
            <th style="width:130px">Статус</th>
        </tr>
        <?php foreach ($group['items'] as $issue):
            $newValue = (string)$issue['NEW_VALUE'];
            $statusCode = (string)$issue['STATUS'];
        ?>
        <tr>
            <td><input class="sf-check sf-g-<?= AdminHelper::e($hash); ?>" type="checkbox" name="issue_ids[]" value="<?= (int)$issue['ID']; ?>"></td>
            <td>
                <?= AdminHelper::externalLink((string)$issue['SOURCE_URL'], 60); ?>
                <?php if (trim((string)$issue['TARGET_NOTE']) !== ''): ?>
                    <div class="sf-note"><?= AdminHelper::e(AdminHelper::short((string)$issue['TARGET_NOTE'], 90)); ?></div>
                <?php endif; ?>
                <?php if (trim((string)$issue['LIVE_VERDICT']) !== ''): ?>
                    <div class="sf-note"><?= AdminHelper::e(AdminHelper::short((string)$issue['LIVE_VERDICT'], 130)); ?></div>
                <?php endif; ?>
            </td>
            <td>
                <textarea class="sf-textarea" name="new_values[<?= (int)$issue['ID']; ?>]" placeholder="Нажмите «Подобрать уникальные значения» или впишите вручную."><?= AdminHelper::e($newValue); ?></textarea>
                <div class="sf-note"><?= AdminHelper::lengthMeter($newValue, $min, $max); ?> <span class="sf-live-len"></span></div>
                <?php if (trim((string)$issue['TARGET_ADMIN_URL']) !== ''): ?>
                    <div class="sf-note"><a class="sf-link" target="_blank" href="<?= AdminHelper::e((string)$issue['TARGET_ADMIN_URL']); ?>">Открыть запись в Битриксе →</a></div>
                <?php endif; ?>
            </td>
            <td><?= AdminHelper::badge(IssuePresenter::statusTitle($statusCode), IssuePresenter::statusClass($statusCode)); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endforeach; ?>

<div class="sf-actions">
    <div class="sf-actions-row">
        <button type="submit" name="action_button" value="generate" class="sf-btn sf-btn-primary">1. Подобрать уникальные значения</button>
        <button type="submit" name="action_button" value="save" class="sf-btn">Сохранить мои правки</button>
        <span class="sf-counter">Выбрано: <b class="sf-selected-count">0</b> из <?= (int)$total; ?></span>
    </div>
    <div class="sf-actions-row" style="margin-top:10px">
        <button type="submit" name="action_button" value="approve" class="sf-btn">2. Подтвердить выбранные</button>
        <button type="submit" name="action_button" value="approve_all" class="sf-btn" onclick="return confirm('Подтвердить все страницы с заполненным предложением?');">2. Подтвердить всё сразу</button>
        <button type="submit" name="action_button" value="skip" class="sf-btn">Отклонить выбранные</button>
    </div>
    <div class="sf-actions-row" style="margin-top:10px">
        <label>За один запуск не более:</label>
        <input class="sf-input" style="width:70px" type="number" name="limit" min="1" max="500" value="100">
        <button type="submit" name="action_button" value="apply" class="sf-btn sf-btn-primary" onclick="return confirm('Записать подтверждённые значения в SEO-поля Битрикса? Старые значения сохранятся для отката.');">3. Применить подтверждённые</button>
        <span class="sf-note">Значения пишутся во вкладку «SEO» элемента или раздела инфоблока, для статических страниц — в файл страницы.</span>
    </div>
</div>
</form>
</div>
<script>
function sfToggleGroup(cb, hash){
  Array.prototype.slice.call(document.querySelectorAll('.sf-g-'+hash)).forEach(function(c){c.checked=cb.checked;});
  if(window.sfUpdateCount){sfUpdateCount();}
}
</script>
<?php
echo AdminHelper::script();
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
