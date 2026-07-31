<?php
namespace Relod\SeoFixer\Target;

use Bitrix\Main\Loader;

/**
 * Записывает SEO-значения туда, где Битрикс их действительно хранит.
 *
 * Для элементов и разделов инфоблоков это вкладка «SEO» (таблицы
 * b_iblock_element_iprop / b_iblock_section_iprop), для статических
 * страниц — вызовы SetPageProperty/SetTitle в самом файле страницы.
 *
 * Именно этого не хватало прежней версии модуля: она умела только
 * искать подстроку в текстах и потому не могла исправить ни один
 * title или description.
 */
class SeoWriter
{
    /** Поля, которые модулю разрешено менять. */
    private const FIELD_MAP = [
        UrlResolver::TARGET_ELEMENT => [
            'title' => 'ELEMENT_META_TITLE',
            'description' => 'ELEMENT_META_DESCRIPTION',
            'keywords' => 'ELEMENT_META_KEYWORDS',
            'h1' => 'ELEMENT_PAGE_TITLE',
        ],
        UrlResolver::TARGET_SECTION => [
            'title' => 'SECTION_META_TITLE',
            'description' => 'SECTION_META_DESCRIPTION',
            'keywords' => 'SECTION_META_KEYWORDS',
            'h1' => 'SECTION_PAGE_TITLE',
        ],
    ];

    /**
     * Текущее значение поля.
     *
     * Читаем через штатный API инфоблоков, а не напрямую из таблиц:
     * имена служебных таблиц Битрикса менялись между версиями, а API
     * стабилен и вдобавок учитывает значения, унаследованные из шаблонов.
     */
    public function readValue(array $target, string $field): string
    {
        $type = (string)$target['type'];

        if ($type === UrlResolver::TARGET_PAGE) {
            return $this->readStaticValue((string)$target['file'], $field);
        }

        $code = self::FIELD_MAP[$type][$field] ?? '';
        if ($code === '') {
            return '';
        }

        $values = $this->ipropertyValues($target);
        if ($values === null) {
            return '';
        }

        try {
            $all = $values->getValues();
        } catch (\Throwable $e) {
            return '';
        }

        if (!is_array($all) || !array_key_exists($code, $all)) {
            return '';
        }
        $value = $all[$code];
        if (is_array($value)) {
            $value = $value['VALUE'] ?? '';
        }
        return trim((string)$value);
    }

    /**
     * Объект значений SEO-полей для элемента или раздела.
     *
     * @return \Bitrix\Iblock\InheritedProperty\BaseValues|null
     */
    private function ipropertyValues(array $target)
    {
        if (!Loader::includeModule('iblock')) {
            return null;
        }
        $iblockId = (int)$target['iblock_id'];
        $entityId = (int)$target['entity_id'];
        if ($iblockId <= 0 || $entityId <= 0) {
            return null;
        }

        try {
            if ((string)$target['type'] === UrlResolver::TARGET_ELEMENT) {
                return new \Bitrix\Iblock\InheritedProperty\ElementValues($iblockId, $entityId);
            }
            if ((string)$target['type'] === UrlResolver::TARGET_SECTION) {
                return new \Bitrix\Iblock\InheritedProperty\SectionValues($iblockId, $entityId);
            }
        } catch (\Throwable $e) {
            return null;
        }
        return null;
    }

    /**
     * Записывает значение.
     *
     * @return array{success:bool,message:string,old_value:string,new_value:string,where:string}
     */
    public function write(array $target, string $field, string $newValue, string $expectedOldValue = null): array
    {
        $type = (string)$target['type'];
        $newValue = trim($newValue);

        if ($newValue === '') {
            return $this->fail('Пустое значение записывать нельзя.');
        }

        if ($type === UrlResolver::TARGET_PAGE) {
            return $this->writeStatic($target, $field, $newValue, $expectedOldValue);
        }

        if (!isset(self::FIELD_MAP[$type][$field])) {
            return $this->fail('Для этого типа страницы модуль не умеет менять поле «' . $field . '».');
        }
        if (!Loader::includeModule('iblock')) {
            return $this->fail('Модуль «Информационные блоки» недоступен.');
        }

        $iblockId = (int)$target['iblock_id'];
        $entityId = (int)$target['entity_id'];
        if ($iblockId <= 0 || $entityId <= 0) {
            return $this->fail('Не определена запись Битрикса для этого адреса.');
        }

        $current = $this->readValue($target, $field);

        // Защита от гонки: если значение изменилось после проверки — не трогаем.
        if ($expectedOldValue !== null && trim($expectedOldValue) !== '' && trim($current) !== trim($expectedOldValue) && trim($current) !== '') {
            return [
                'success' => false,
                'message' => 'Значение изменилось после проверки (сейчас «' . $current . '»). Чтобы не затереть чужую правку, исправление пропущено. Перепроверьте карточку и примените заново.',
                'old_value' => $current,
                'new_value' => '',
                'where' => '',
            ];
        }

        $code = self::FIELD_MAP[$type][$field];
        $values = $this->ipropertyValues($target);
        if ($values === null) {
            return $this->fail('Не удалось открыть SEO-поля записи в Битриксе.');
        }

        try {
            // Только setValues: clearValues() сбрасывает собственные значения
            // записи и стёр бы то, что мы записали.
            $values->setValues([$code => $newValue]);
        } catch (\Throwable $e) {
            return $this->fail('Ошибка записи SEO-поля: ' . $e->getMessage());
        }

        $written = $this->readValue($target, $field);
        if (trim($written) !== $newValue) {
            return $this->fail('Битрикс не сохранил значение: после записи в поле осталось «' . $written . '».');
        }

        $this->clearCache($iblockId);

        return [
            'success' => true,
            'message' => 'Записано в SEO-поле ' . $code . ' — ' . $target['note'] . '.',
            'old_value' => $current,
            'new_value' => $newValue,
            'where' => $code,
        ];
    }

    // -----------------------------------------------------------------
    // Статические страницы
    // -----------------------------------------------------------------

    private function readStaticValue(string $relativeFile, string $field): string
    {
        $file = $this->staticPath($relativeFile);
        if ($file === null) {
            return '';
        }
        $content = (string)@file_get_contents($file);
        if ($content === '') {
            return '';
        }

        if ($field === 'title' || $field === 'description' || $field === 'keywords') {
            $prop = $field === 'title' ? 'title' : $field;
            if (preg_match('~SetPageProperty\s*\(\s*([\'"])' . preg_quote($prop, '~') . '\1\s*,\s*([\'"])(.*?)\2~s', $content, $m)) {
                return $this->unescapePhp($m[3]);
            }
        }
        if ($field === 'h1' || $field === 'title') {
            if (preg_match('~SetTitle\s*\(\s*([\'"])(.*?)\1~s', $content, $m)) {
                return $this->unescapePhp($m[2]);
            }
            if (preg_match('~\$sPageTitle\s*=\s*([\'"])(.*?)\1~s', $content, $m)) {
                return $this->unescapePhp($m[2]);
            }
        }
        return '';
    }

    private function writeStatic(array $target, string $field, string $newValue, ?string $expectedOldValue): array
    {
        if (\COption::GetOptionString('relod.seofixer', 'allow_static_page_write', 'Y') !== 'Y') {
            return $this->fail('Правка файлов статических страниц выключена в настройках модуля. Значение подготовлено — примените вручную: ' . $target['file']);
        }

        $file = $this->staticPath((string)$target['file']);
        if ($file === null || !is_file($file)) {
            return $this->fail('Файл страницы не найден: ' . $target['file']);
        }
        if (!is_writable($file)) {
            return $this->fail('Файл страницы недоступен для записи: ' . $target['file']);
        }

        $content = (string)file_get_contents($file);
        $current = $this->readStaticValue((string)$target['file'], $field);

        if ($expectedOldValue !== null && trim($expectedOldValue) !== '' && trim($current) !== '' && trim($current) !== trim($expectedOldValue)) {
            return [
                'success' => false,
                'message' => 'В файле страницы значение изменилось после проверки. Исправление пропущено.',
                'old_value' => $current,
                'new_value' => '',
                'where' => '',
            ];
        }

        $escaped = $this->escapePhp($newValue);
        $updated = null;

        if ($field === 'description' || $field === 'keywords') {
            $prop = $field;
            $pattern = '~(SetPageProperty\s*\(\s*([\'"])' . preg_quote($prop, '~') . '\2\s*,\s*)([\'"])(.*?)\3~s';
            if (preg_match($pattern, $content)) {
                $updated = preg_replace($pattern, '${1}"' . str_replace('$', '\\$', $escaped) . '"', $content, 1);
            } else {
                $updated = $this->insertStatement($content, '$APPLICATION->SetPageProperty("' . $escaped . '");', $prop, $escaped);
            }
        } elseif ($field === 'title') {
            $pattern = '~(SetPageProperty\s*\(\s*([\'"])title\2\s*,\s*)([\'"])(.*?)\3~s';
            if (preg_match($pattern, $content)) {
                $updated = preg_replace($pattern, '${1}"' . str_replace('$', '\\$', $escaped) . '"', $content, 1);
            } else {
                $updated = $this->insertStatement($content, '', 'title', $escaped);
            }
        } elseif ($field === 'h1') {
            $pattern = '~(SetTitle\s*\(\s*)([\'"])(.*?)\2~s';
            if (preg_match($pattern, $content)) {
                $updated = preg_replace($pattern, '${1}"' . str_replace('$', '\\$', $escaped) . '"', $content, 1);
            } else {
                $pattern = '~(\$sPageTitle\s*=\s*)([\'"])(.*?)\2~s';
                if (preg_match($pattern, $content)) {
                    $updated = preg_replace($pattern, '${1}"' . str_replace('$', '\\$', $escaped) . '"', $content, 1);
                }
            }
        }

        if ($updated === null || $updated === $content) {
            return $this->fail('Не удалось найти в файле место для записи «' . $field . '». Файл: ' . $target['file'] . '. Значение подготовлено, вставьте его вручную.');
        }

        $backup = $file . '.seofixer-bak-' . date('Ymd_His');
        if (!@copy($file, $backup)) {
            return $this->fail('Не удалось создать резервную копию файла перед правкой: ' . $target['file']);
        }
        if (@file_put_contents($file, $updated, LOCK_EX) === false) {
            return $this->fail('Не удалось записать файл страницы: ' . $target['file']);
        }

        return [
            'success' => true,
            'message' => 'Записано в файл страницы ' . $target['file'] . '. Резервная копия: ' . basename($backup),
            'old_value' => $current,
            'new_value' => $newValue,
            'where' => $target['file'],
        ];
    }

    /**
     * Вставляет вызов SetPageProperty после подключения header.php.
     */
    private function insertStatement(string $content, string $unusedLine, string $prop, string $escaped): ?string
    {
        $statement = "\n\$APPLICATION->SetPageProperty(\"" . $prop . "\", \"" . $escaped . "\");";
        if (preg_match('~(require\s*\(?\s*\$_SERVER\[[\'"]DOCUMENT_ROOT[\'"]\]\s*\.\s*[\'"]/bitrix/header\.php[\'"]\s*\)?\s*;)~', $content, $m)) {
            return (string)preg_replace(
                '~' . preg_quote($m[1], '~') . '~',
                $m[1] . str_replace('$', '\\$', $statement),
                $content,
                1
            );
        }
        return null;
    }

    private function staticPath(string $relativeFile): ?string
    {
        $relativeFile = trim($relativeFile);
        if ($relativeFile === '') {
            return null;
        }
        $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        $realRoot = realpath($docRoot);
        $real = realpath($docRoot . '/' . ltrim($relativeFile, '/'));
        if (!$realRoot || !$real || strpos($real, $realRoot) !== 0) {
            return null;
        }
        return $real;
    }

    private function escapePhp(string $value): string
    {
        return str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);
    }

    private function unescapePhp(string $value): string
    {
        return str_replace(['\\"', "\\'", '\\$', '\\\\'], ['"', "'", '$', '\\'], $value);
    }

    // -----------------------------------------------------------------

    private function clearCache(int $iblockId): void
    {
        if (isset($GLOBALS['CACHE_MANAGER']) && is_object($GLOBALS['CACHE_MANAGER'])) {
            $GLOBALS['CACHE_MANAGER']->ClearByTag('iblock_id_' . $iblockId);
        }
        if (class_exists('\CBitrixComponent')) {
            \CBitrixComponent::clearComponentCache('bitrix:catalog');
        }
    }

    private function fail(string $message): array
    {
        return ['success' => false, 'message' => $message, 'old_value' => '', 'new_value' => '', 'where' => ''];
    }

    /** Человеческое название поля. */
    public static function fieldTitle(string $field): string
    {
        $map = [
            'title' => 'meta title',
            'description' => 'meta description',
            'keywords' => 'meta keywords',
            'h1' => 'заголовок H1',
        ];
        return $map[$field] ?? $field;
    }
}
