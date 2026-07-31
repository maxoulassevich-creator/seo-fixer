<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Relod\SeoFixer\Helper\AdminHelper;
use Relod\SeoFixer\Report\ReportCatalog;
use Relod\SeoFixer\Service\ImportService;

/** @var CMain $APPLICATION */
/** @var CUser $USER */

$APPLICATION->SetTitle('RELOD SEO Fixer — загрузка отчёта');

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
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    try {
        $service = new ImportService();
        $userId = (int)$USER->GetID();
        $comment = (string)($_POST['comment'] ?? '');
        $forcedSite = (string)($_POST['force_site_id'] ?? '');
        $autoEnrich = ($_POST['auto_enrich'] ?? '') === 'Y';
        $useLive = ($_POST['use_live'] ?? '') === 'Y';

        $files = $_FILES['netpeak_files'] ?? null;
        if (!$files || !isset($files['name']) || !is_array($files['name'])) {
            throw new RuntimeException('Файлы не выбраны.');
        }

        $count = count($files['name']);
        $lastImportId = 0;

        for ($i = 0; $i < $count; $i++) {
            $one = [
                'name' => $files['name'][$i],
                'type' => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error' => $files['error'][$i],
                'size' => $files['size'][$i],
            ];
            if ((int)$one['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            try {
                $importId = $service->importUploadedFile($one, $userId, $comment, $forcedSite);
                $lastImportId = $importId;
                if ($autoEnrich) {
                    $service->enrich(['=IMPORT_ID' => $importId], 300, $useLive);
                }
                $results[] = ['name' => (string)$one['name'], 'ok' => true, 'import_id' => $importId, 'text' => 'Загружен и разобран.'];
            } catch (Throwable $e) {
                $results[] = ['name' => (string)$one['name'], 'ok' => false, 'import_id' => 0, 'text' => $e->getMessage()];
            }
        }

        if (!$results) {
            throw new RuntimeException('Файлы не выбраны.');
        }

        $ok = count(array_filter($results, static function ($r) {
            return $r['ok'];
        }));
        $message = 'Обработано файлов: ' . count($results) . ', успешно: ' . $ok . '.';

        if ($ok === 1 && count($results) === 1 && $lastImportId > 0) {
            LocalRedirect('relod_seofixer_issues.php?lang=' . LANGUAGE_ID . '&import_id=' . $lastImportId);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
echo AdminHelper::style();
echo '<div class="sf-wrap">';
echo AdminHelper::nav('import');
if ($error) {
    echo AdminHelper::error($error);
}
if ($message) {
    echo AdminHelper::success($message);
}
echo AdminHelper::hint('Можно загрузить сразу несколько отчётов. Модуль определяет по имени файла дату, домен и тип проблемы, сам находит нужный сайт Битрикса и сводит повторные выгрузки в те же карточки — дублей не появится.');
?>

<?php if ($results): ?>
<h2 class="sf-h2">Результат загрузки</h2>
<table class="sf-table">
    <tr><th style="width:55%">Файл</th><th style="width:110px">Итог</th><th>Комментарий</th></tr>
    <?php foreach ($results as $r): ?>
    <tr>
        <td><?= AdminHelper::e($r['name']); ?></td>
        <td><?= $r['ok'] ? AdminHelper::badge('Загружен', 'ok') : AdminHelper::badge('Ошибка', 'bad'); ?></td>
        <td>
            <?= AdminHelper::e($r['text']); ?>
            <?php if ($r['import_id'] > 0): ?>
                <a class="sf-link" href="relod_seofixer_issues.php?lang=<?= LANGUAGE_ID; ?>&import_id=<?= (int)$r['import_id']; ?>">Открыть карточки →</a>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
<?= bitrix_sessid_post(); ?>
<table class="sf-form-table">
    <tr>
        <th>Файлы отчётов Netpeak (XLSX или CSV)</th>
        <td>
            <input type="file" name="netpeak_files[]" accept=".xlsx,.csv" multiple required>
            <small>Можно выбрать несколько файлов сразу. Имена менять не нужно — модуль читает из них дату, домен и название проблемы.</small>
        </td>
    </tr>
    <tr>
        <th>Сайт</th>
        <td>
            <?= AdminHelper::siteSelect('force_site_id', '', true); ?>
            <small>Оставьте «Все сайты», чтобы модуль определил сайт сам по домену из имени файла. Выбирайте конкретный сайт, только если автоопределение ошибается.</small>
        </td>
    </tr>
    <tr>
        <th>Сразу подготовить решения</th>
        <td>
            <label><input type="checkbox" name="auto_enrich" value="Y" checked> Найти место правки в Битриксе и подобрать значения</label>
            <small>Модуль сопоставит каждый адрес с элементом или разделом инфоблока и подготовит предложение. На больших отчётах занимает время.</small>
        </td>
    </tr>
    <tr>
        <th>Проверять сайт в реальном времени</th>
        <td>
            <label><input type="checkbox" name="use_live" value="Y" checked> Открывать страницы и сверять с отчётом</label>
            <small>Модуль запросит каждую страницу и запишет фактические title, description, H1 и код ответа. Всё, что уже исправлено, закроется как «Уже в порядке». Без этого модуль опирается только на данные из файла, а они устаревают.</small>
        </td>
    </tr>
    <tr>
        <th>Комментарий</th>
        <td>
            <textarea class="sf-textarea" name="comment" rows="2" placeholder="Например: обход от 9 июня, полный набор отчётов"></textarea>
        </td>
    </tr>
</table>

<div class="sf-actions">
    <div class="sf-actions-row">
        <button type="submit" class="sf-btn sf-btn-primary">Загрузить и разобрать</button>
        <span class="sf-note">После загрузки ничего не применяется автоматически — модуль только показывает найденное.</span>
    </div>
</div>
</form>

<h2 class="sf-h2">Какие отчёты модуль понимает</h2>
<p class="sf-explain">Типы, которые распознаются по имени файла и по колонкам — и по-русски, и по-английски. Приоритет определяет порядок в списке проблем.</p>
<table class="sf-table">
    <tr>
        <th style="width:90px">Приоритет</th>
        <th style="width:24%">Тип отчёта</th>
        <th style="width:120px">Серьёзность</th>
        <th style="width:150px">Исправит сам</th>
        <th>Что делает модуль</th>
    </tr>
    <?php foreach (ReportCatalog::byPriority() as $code => $meta):
        if ($code === ReportCatalog::TYPE_UNKNOWN) {
            continue;
        }
    ?>
    <tr>
        <td><?= AdminHelper::priorityBar((int)$meta['priority']); ?></td>
        <td><b><?= AdminHelper::e((string)$meta['title']); ?></b></td>
        <td><?= AdminHelper::badge(ReportCatalog::severityTitle((string)$meta['severity']), (string)$meta['severity']); ?></td>
        <td><?= !empty($meta['auto']) ? AdminHelper::badge('Да', 'ok') : AdminHelper::badge('Покажет решение', 'warn'); ?></td>
        <td class="sf-explain"><b><?= AdminHelper::e(ReportCatalog::strategyTitle((string)$meta['strategy'])); ?></b> — <?= AdminHelper::e((string)$meta['how']); ?></td>
    </tr>
    <?php endforeach; ?>
</table>
</div>
<?php
echo AdminHelper::script();
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
