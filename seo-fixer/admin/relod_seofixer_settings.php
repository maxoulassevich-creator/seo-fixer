<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Relod\SeoFixer\Helper\AdminHelper;
use Relod\SeoFixer\Service\Schema;
use Relod\SeoFixer\Site\LivePageInspector;
use Relod\SeoFixer\Site\SiteContext;

/** @var CMain $APPLICATION */
/** @var CUser $USER */

$APPLICATION->SetTitle('RELOD SEO Fixer — настройки');

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
$schemaReport = null;
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    try {
        $action = (string)($_POST['action_button'] ?? 'save');

        if ($action === 'repair_schema') {
            $schemaReport = Schema::install();
            $parts = [];
            if ($schemaReport['created']) {
                $parts[] = 'создано таблиц: ' . count($schemaReport['created']);
            }
            if ($schemaReport['added']) {
                $parts[] = 'добавлено полей: ' . count($schemaReport['added']);
            }
            if ($schemaReport['changed']) {
                $parts[] = 'расширено полей: ' . count($schemaReport['changed']);
            }
            $message = $parts ? 'Структура БД обновлена — ' . implode(', ', $parts) . '.' : 'Структура БД уже в порядке, менять ничего не потребовалось.';
            if ($schemaReport['errors']) {
                $error = 'Не всё удалось: ' . implode('; ', $schemaReport['errors']);
            }
        } elseif ($action === 'test_http') {
            $url = trim((string)($_POST['test_url'] ?? ''));
            if ($url === '') {
                throw new RuntimeException('Укажите адрес для проверки.');
            }
            $inspector = new LivePageInspector();
            $testResult = $inspector->inspect($url);
        } else {
            COption::SetOptionString('relod.seofixer', 'batch_limit', (string)max(1, min(500, (int)($_POST['batch_limit'] ?? 50))));
            COption::SetOptionString('relod.seofixer', 'allow_template_file_autofix', (($_POST['allow_template_file_autofix'] ?? 'N') === 'Y') ? 'Y' : 'N');
            COption::SetOptionString('relod.seofixer', 'allow_static_page_write', (($_POST['allow_static_page_write'] ?? 'N') === 'Y') ? 'Y' : 'N');
            COption::SetOptionString('relod.seofixer', 'verify_on_import', (($_POST['verify_on_import'] ?? 'N') === 'Y') ? 'Y' : 'N');
            COption::SetOptionString('relod.seofixer', 'http_timeout', (string)max(3, min(60, (int)($_POST['http_timeout'] ?? 15))));
            COption::SetOptionString('relod.seofixer', 'http_delay_ms', (string)max(0, min(5000, (int)($_POST['http_delay_ms'] ?? 150))));
            COption::SetOptionString('relod.seofixer', 'http_retries', (string)max(0, min(5, (int)($_POST['http_retries'] ?? 2))));
            COption::SetOptionString('relod.seofixer', 'http_user_agent', trim((string)($_POST['http_user_agent'] ?? '')));
            COption::SetOptionString('relod.seofixer', 'tpl_title', trim((string)($_POST['tpl_title'] ?? '')));
            COption::SetOptionString('relod.seofixer', 'tpl_description', trim((string)($_POST['tpl_description'] ?? '')));
            COption::SetOptionString('relod.seofixer', 'tpl_description_tail', trim((string)($_POST['tpl_description_tail'] ?? '')));

            foreach ((array)($_POST['brand'] ?? []) as $site => $brand) {
                COption::SetOptionString('relod.seofixer', 'brand_' . preg_replace('/[^a-z0-9_]/i', '', (string)$site), trim((string)$brand));
            }
            $message = 'Настройки сохранены.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$batch = (int)COption::GetOptionString('relod.seofixer', 'batch_limit', '50');
$allowTemplate = COption::GetOptionString('relod.seofixer', 'allow_template_file_autofix', 'N') === 'Y';
$allowStatic = COption::GetOptionString('relod.seofixer', 'allow_static_page_write', 'Y') === 'Y';
$verifyOnImport = COption::GetOptionString('relod.seofixer', 'verify_on_import', 'Y') === 'Y';
$httpTimeout = (int)COption::GetOptionString('relod.seofixer', 'http_timeout', '15');
$httpDelay = (int)COption::GetOptionString('relod.seofixer', 'http_delay_ms', '150');
$httpRetries = (int)COption::GetOptionString('relod.seofixer', 'http_retries', '2');
$userAgent = (string)COption::GetOptionString('relod.seofixer', 'http_user_agent', '');
$tplTitle = (string)COption::GetOptionString('relod.seofixer', 'tpl_title', '');
$tplDescription = (string)COption::GetOptionString('relod.seofixer', 'tpl_description', '');
$tplTail = (string)COption::GetOptionString('relod.seofixer', 'tpl_description_tail', '');

$schemaCheck = Schema::check();

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
echo AdminHelper::style();
echo '<div class="sf-wrap">';
echo AdminHelper::nav('settings');
if ($error) {
    echo AdminHelper::error($error);
}
if ($message) {
    echo AdminHelper::success($message);
}

if (!$schemaCheck['ok']) {
    $details = [];
    if ($schemaCheck['missing_tables']) {
        $details[] = 'нет таблиц: ' . implode(', ', $schemaCheck['missing_tables']);
    }
    if ($schemaCheck['missing_columns']) {
        $details[] = 'нет полей: ' . implode(', ', array_slice($schemaCheck['missing_columns'], 0, 10));
    }
    if ($schemaCheck['narrow_columns']) {
        $details[] = 'слишком короткие поля: ' . implode(', ', array_slice($schemaCheck['narrow_columns'], 0, 10));
    }
    echo AdminHelper::error('Структура БД устарела и мешает сохранению данных — ' . implode('; ', $details) . '. Нажмите «Проверить и починить структуру БД» ниже.');
}

echo AdminHelper::hint('Базовый режим безопасный: после загрузки отчёта ничего не меняется само, все правки выполняются только после подтверждения, старое значение всегда сохраняется для отката.');
?>

<?php if ($testResult !== null): ?>
<h2 class="sf-h2">Результат тестового запроса</h2>
<table class="sf-table">
    <tr><th style="width:200px">Код ответа</th><td><?= (int)$testResult['status']; ?> — <?= AdminHelper::e((string)$testResult['status_text']); ?></td></tr>
    <tr><th>Конечный адрес</th><td><?= AdminHelper::e((string)$testResult['effective_url']); ?><?= $testResult['redirected'] ? ' (был редирект)' : ''; ?></td></tr>
    <tr><th>Время ответа</th><td><?= (int)$testResult['response_ms']; ?> мс</td></tr>
    <tr><th>Title</th><td><?= AdminHelper::e((string)$testResult['title']) ?: '<span class="sf-muted">пусто</span>'; ?></td></tr>
    <tr><th>Description</th><td><?= AdminHelper::e((string)$testResult['description']) ?: '<span class="sf-muted">пусто</span>'; ?></td></tr>
    <tr><th>H1</th><td><?= $testResult['h1'] ? AdminHelper::e(implode(' | ', $testResult['h1'])) : '<span class="sf-muted">пусто</span>'; ?> (найдено: <?= (int)$testResult['h1_count']; ?>)</td></tr>
    <tr><th>Canonical</th><td><?= AdminHelper::e((string)$testResult['canonical']) ?: '<span class="sf-muted">нет</span>'; ?></td></tr>
    <tr><th>Ошибка</th><td><?= AdminHelper::e((string)$testResult['error']) ?: '<span class="sf-muted">нет</span>'; ?></td></tr>
</table>
<?php endif; ?>

<form method="post">
<?= bitrix_sessid_post(); ?>

<h2 class="sf-h2">Названия сайтов для подстановки в title и description</h2>
<table class="sf-form-table">
    <?php foreach (SiteContext::listSites() as $id => $site):
        $current = (string)COption::GetOptionString('relod.seofixer', 'brand_' . $id, '');
    ?>
    <tr>
        <th><?= AdminHelper::e($site['name']); ?> (<?= AdminHelper::e($id); ?>)</th>
        <td>
            <input class="sf-input" style="width:340px" type="text" name="brand[<?= AdminHelper::e($id); ?>]" value="<?= AdminHelper::e($current); ?>" placeholder="<?= AdminHelper::e(SiteContext::brandName($id)); ?>">
            <small>
                Домены: <?= $site['domains'] ? AdminHelper::e(implode(', ', $site['domains'])) : '<b>не заданы</b> — модуль не сможет сопоставить отчёт с этим сайтом'; ?>.
                Если поле пустое, берётся название сайта из настроек Битрикса.
            </small>
        </td>
    </tr>
    <?php endforeach; ?>
</table>

<h2 class="sf-h2">Шаблоны предложений</h2>
<table class="sf-form-table">
    <tr>
        <th>Шаблон title</th>
        <td>
            <input class="sf-input" style="width:100%;max-width:560px" type="text" name="tpl_title" value="<?= AdminHelper::e($tplTitle); ?>" placeholder="{label}{ – section}{ | brand}">
            <small>Доступные подстановки: <b>{label}</b> — название страницы, <b>{section}</b> — родительский раздел, <b>{brand}</b> — название сайта, <b>{tail}</b> — дополнение. Блок в фигурных скобках исчезает целиком, если значение пустое.</small>
        </td>
    </tr>
    <tr>
        <th>Шаблон description</th>
        <td>
            <input class="sf-input" style="width:100%;max-width:560px" type="text" name="tpl_description" value="<?= AdminHelper::e($tplDescription); ?>" placeholder="{label}{ — раздел «section»}. {tail}">
            <small>
                <b>Название сайта в шаблон подставлять не нужно</b> — модуль добавляет его отдельным предложением в конце.
                Так сделано намеренно: внутри фразы его пришлось бы склонять («в интернет-магазине RELOD»,
                а не «в Интернет-магазин RELOD»), а падеж произвольного названия автоматически не определить.
            </small>
        </td>
    </tr>
    <tr>
        <th>Дополнение к описанию</th>
        <td>
            <input class="sf-input" style="width:100%;max-width:560px" type="text" name="tpl_description_tail" value="<?= AdminHelper::e($tplTail); ?>" placeholder="по умолчанию не используется">
            <small>
                <b>По умолчанию пусто — и это осознанно.</b> Одна и та же фраза в конце каждого описания
                делает их почти одинаковыми, то есть не решает задачу с дубликатами, ради которой всё затевалось.
                Заполняйте, только если такая приписка действительно уместна на всех страницах сайта.
            </small>
        </td>
    </tr>
</table>

<div class="sf-hint">
    <b>Откуда модуль берёт текст для описания</b>, по убыванию приоритета:
    1) настоящий текст страницы, прочитанный при живой проверке — самый естественный и всегда уникальный вариант;
    2) развёрнутый заголовок самой страницы, если он содержательнее короткого названия;
    3) типовая формулировка для страницы понятного назначения (контакты, доставка, возврат, каталог и т. п.);
    4) название страницы и сайта — короткий, но точный вариант.
    Модуль не добивает описание общими фразами ради длины: короткий осмысленный текст в выдаче работает лучше «воды».
</div>

<h2 class="sf-h2">Проверка сайта в реальном времени</h2>
<table class="sf-form-table">
    <tr>
        <th>Проверять при загрузке отчёта</th>
        <td>
            <label><input type="checkbox" name="verify_on_import" value="Y"<?= $verifyOnImport ? ' checked' : ''; ?>> Открывать страницы и сверять с отчётом</label>
            <small>Отчёт Netpeak — снимок прошлого. С включённой проверкой модуль опирается на то, что на сайте сейчас, и сам закрывает уже исправленное.</small>
        </td>
    </tr>
    <tr>
        <th>Таймаут запроса, сек</th>
        <td><input class="sf-input" style="width:90px" type="number" name="http_timeout" min="3" max="60" value="<?= $httpTimeout; ?>"></td>
    </tr>
    <tr>
        <th>Пауза между запросами, мс</th>
        <td>
            <input class="sf-input" style="width:90px" type="number" name="http_delay_ms" min="0" max="5000" value="<?= $httpDelay; ?>">
            <small>Защищает сайт от нагрузки при массовой проверке. Если сайт отдаёт 503, увеличьте паузу до 500–1000 мс.</small>
        </td>
    </tr>
    <tr>
        <th>Повторов при ошибке 429 или 5xx</th>
        <td>
            <input class="sf-input" style="width:90px" type="number" name="http_retries" min="0" max="5" value="<?= $httpRetries; ?>">
            <small>Эти коды обычно означают сработавший антифлуд, а не сломанную страницу. Модуль повторит запрос с нарастающей паузой, прежде чем считать адрес недоступным.</small>
        </td>
    </tr>
    <tr>
        <th>User-Agent</th>
        <td>
            <input class="sf-input" style="width:100%;max-width:560px" type="text" name="http_user_agent" value="<?= AdminHelper::e($userAgent); ?>" placeholder="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36">
            <small>По умолчанию модуль представляется обычным браузером Chrome. Оставьте поле пустым, если не нужен свой вариант.</small>
        </td>
    </tr>
    <tr>
        <th>Тестовый запрос</th>
        <td>
            <input class="sf-input" style="width:340px" type="text" name="test_url" placeholder="https://example.com/catalog/">
            <button type="submit" name="action_button" value="test_http" class="sf-btn">Проверить адрес</button>
            <small>Проверяет, что модуль может достучаться до сайта и корректно читает title, description и H1.</small>
        </td>
    </tr>
</table>

<h2 class="sf-h2">Что модулю разрешено менять</h2>
<table class="sf-form-table">
    <tr>
        <th>SEO-поля инфоблоков</th>
        <td>
            <b>Всегда разрешено</b>
            <small>meta title, meta description и заголовок страницы у элементов и разделов инфоблоков — вкладка «SEO» в карточке записи. Это основной и самый безопасный способ правки.</small>
        </td>
    </tr>
    <tr>
        <th>Файлы статических страниц</th>
        <td>
            <label><input type="checkbox" name="allow_static_page_write" value="Y"<?= $allowStatic ? ' checked' : ''; ?>> Разрешить правку SetPageProperty и SetTitle в файле страницы</label>
            <small>Нужно для страниц вроде /contacts/ и /info/, которые лежат файлами, а не в инфоблоке. Перед каждой правкой создаётся резервная копия файла.</small>
        </td>
    </tr>
    <tr>
        <th>Файлы шаблона</th>
        <td>
            <label><input type="checkbox" name="allow_template_file_autofix" value="Y"<?= $allowTemplate ? ' checked' : ''; ?>> Разрешить точечную замену в файлах шаблона</label>
            <small>По умолчанию выключено. Даже с выключенной опцией модуль ищет совпадения в /local/templates, /bitrix/templates и /include и показывает файл, строку и фрагмент кода для разработчика.</small>
        </td>
    </tr>
    <tr>
        <th>Исправлений за один запуск</th>
        <td>
            <input class="sf-input" style="width:90px" type="number" name="batch_limit" min="1" max="500" value="<?= $batch; ?>">
            <small>Ограничение защищает от долгих запросов и даёт возможность проверить результат небольшими партиями.</small>
        </td>
    </tr>
    <tr>
        <th>Резервная копия</th>
        <td>
            <b>Всегда включена</b>
            <small>Старое значение сохраняется в журнале изменений перед каждой правкой, откат доступен всегда.</small>
        </td>
    </tr>
</table>

<h2 class="sf-h2">Обслуживание</h2>
<table class="sf-form-table">
    <tr>
        <th>Структура базы данных</th>
        <td>
            <?php if ($schemaCheck['ok']): ?>
                <?= AdminHelper::badge('В порядке', 'ok'); ?>
            <?php else: ?>
                <?= AdminHelper::badge('Требует обновления', 'bad'); ?>
            <?php endif; ?>
            <button type="submit" name="action_button" value="repair_schema" class="sf-btn" style="margin-left:10px">Проверить и починить структуру БД</button>
            <small>Создаёт недостающие таблицы и поля и расширяет слишком короткие колонки. Данные не удаляются. Обязательно выполните это после обновления с версии 1.x — иначе часть правок не будет сохраняться.</small>
        </td>
    </tr>
</table>

<div class="sf-actions">
    <div class="sf-actions-row">
        <button type="submit" name="action_button" value="save" class="sf-btn sf-btn-primary">Сохранить настройки</button>
    </div>
</div>
</form>
</div>
<?php
echo AdminHelper::script();
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
