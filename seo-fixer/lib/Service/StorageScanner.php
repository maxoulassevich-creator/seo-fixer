<?php
namespace Relod\SeoFixer\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

/**
 * Поиск и точечная замена строки (обычно — адреса) в местах,
 * где Битрикс хранит редактируемый контент.
 *
 * Список полей жёстко ограничен: модуль не имеет права трогать
 * ничего, кроме перечисленного здесь.
 */
class StorageScanner
{
    /** Разрешённые для правки таблицы и поля. */
    private const ALLOWED = [
        'iblock_element' => ['table' => 'b_iblock_element', 'id' => 'ID', 'fields' => ['PREVIEW_TEXT', 'DETAIL_TEXT']],
        'iblock_section' => ['table' => 'b_iblock_section', 'id' => 'ID', 'fields' => ['DESCRIPTION']],
        'iblock_property_value' => ['table' => 'b_iblock_element_property', 'id' => 'ID', 'fields' => ['VALUE']],
        'file_description' => ['table' => 'b_file', 'id' => 'ID', 'fields' => ['DESCRIPTION']],
    ];

    /**
     * Ищет точные вхождения строки.
     *
     * @return array<int,array{entity_type:string,entity_id:int,field_name:string,value:string,hint:string}>
     */
    public function findOccurrences(string $needle, int $limit = 20, bool $includeTemplateFiles = true): array
    {
        $needle = trim($needle);
        if ($needle === '') {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $items = $this->findInDatabase($needle, $limit);

        if ($includeTemplateFiles && count($items) < $limit) {
            $items = array_merge($items, $this->findInTemplates($needle, $limit - count($items)));
        }

        return array_slice($items, 0, $limit);
    }

    /**
     * Заменяет вхождение, предварительно убедившись, что значение не изменилось.
     *
     * @param array $occurrence Элемент из findOccurrences().
     * @return array{success:bool,message:string,old_value:string,new_value:string}
     */
    public function replace(array $occurrence, string $expectedOldValue, string $newValue): array
    {
        $entityType = (string)($occurrence['entity_type'] ?? '');

        if ($entityType === 'template_file') {
            return $this->replaceInTemplateFile((string)$occurrence['field_name'], $expectedOldValue, $newValue);
        }

        if (!isset(self::ALLOWED[$entityType])) {
            return $this->fail('Этот тип хранения не разрешён для автоматической правки.');
        }

        $meta = self::ALLOWED[$entityType];
        $fieldName = (string)$occurrence['field_name'];
        if (!in_array($fieldName, $meta['fields'], true)) {
            return $this->fail('Поле «' . $fieldName . '» не входит в список разрешённых для безопасной замены.');
        }

        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $entityId = (int)$occurrence['entity_id'];

        $row = $connection->query(
            'SELECT ' . $fieldName . ' FROM ' . $meta['table'] . ' WHERE ' . $meta['id'] . '=' . $entityId . ' LIMIT 1'
        )->fetch();
        if (!$row) {
            return $this->fail('Запись #' . $entityId . ' больше не существует на сайте.');
        }

        $current = (string)$row[$fieldName];
        if (strpos($current, $expectedOldValue) === false) {
            return $this->fail('Значение изменилось после проверки — старого адреса в поле уже нет. Замена пропущена, чтобы не испортить данные.');
        }

        $updated = str_replace($expectedOldValue, $newValue, $current);
        $connection->queryExecute(
            'UPDATE ' . $meta['table'] . ' SET ' . $fieldName . "='" . $helper->forSql($updated) . "' WHERE " . $meta['id'] . '=' . $entityId
        );

        $this->clearCache();

        return [
            'success' => true,
            'old_value' => $current,
            'new_value' => $updated,
            'message' => 'Заменено в ' . $entityType . ' #' . $entityId . ', поле ' . $fieldName . '.',
        ];
    }

    /**
     * Обратная операция для отката.
     */
    public function restore(string $entityType, int $entityId, string $fieldName, string $valueToRestore): array
    {
        if ($entityType === 'template_file') {
            return $this->fail('Откат правок в файлах шаблона выполняется из резервной копии рядом с файлом.');
        }
        if (!isset(self::ALLOWED[$entityType])) {
            return $this->fail('Тип хранения не поддерживается.');
        }
        $meta = self::ALLOWED[$entityType];
        if (!in_array($fieldName, $meta['fields'], true)) {
            return $this->fail('Поле не разрешено для правки.');
        }

        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $connection->queryExecute(
            'UPDATE ' . $meta['table'] . ' SET ' . $fieldName . "='" . $helper->forSql($valueToRestore) . "' WHERE " . $meta['id'] . '=' . (int)$entityId
        );
        $this->clearCache();

        return ['success' => true, 'message' => 'Прежнее значение восстановлено.', 'old_value' => '', 'new_value' => $valueToRestore];
    }

    /**
     * Подсказки для разработчика: где в шаблонах встречается строка.
     */
    public function findTemplateHints(string $needle, int $limit = 10): array
    {
        return $this->findInTemplates($needle, $limit);
    }

    // -----------------------------------------------------------------

    private function findInDatabase(string $needle, int $limit): array
    {
        $items = [];
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        // Экранируем спецсимволы LIKE, иначе % и _ из адреса сработают как маска.
        $like = '%' . $this->escapeLike($needle) . '%';
        $escapedLike = $helper->forSql($like);

        if (Loader::includeModule('iblock')) {
            if ($connection->isTableExists('b_iblock_element')) {
                $q = $connection->query(
                    "SELECT ID, PREVIEW_TEXT, DETAIL_TEXT FROM b_iblock_element
                     WHERE PREVIEW_TEXT LIKE '" . $escapedLike . "' ESCAPE '\\\\'
                        OR DETAIL_TEXT LIKE '" . $escapedLike . "' ESCAPE '\\\\'
                     LIMIT " . (int)$limit
                );
                while ($row = $q->fetch()) {
                    foreach (['PREVIEW_TEXT', 'DETAIL_TEXT'] as $field) {
                        if (strpos((string)$row[$field], $needle) === false) {
                            continue;
                        }
                        $items[] = [
                            'entity_type' => 'iblock_element',
                            'entity_id' => (int)$row['ID'],
                            'field_name' => $field,
                            'value' => (string)$row[$field],
                            'hint' => 'Элемент инфоблока #' . (int)$row['ID'] . ', поле ' . $field,
                        ];
                        if (count($items) >= $limit) {
                            return $items;
                        }
                    }
                }
            }

            if (count($items) < $limit && $connection->isTableExists('b_iblock_section')) {
                $q = $connection->query(
                    "SELECT ID, DESCRIPTION FROM b_iblock_section WHERE DESCRIPTION LIKE '" . $escapedLike . "' ESCAPE '\\\\' LIMIT " . (int)($limit - count($items))
                );
                while ($row = $q->fetch()) {
                    if (strpos((string)$row['DESCRIPTION'], $needle) === false) {
                        continue;
                    }
                    $items[] = [
                        'entity_type' => 'iblock_section',
                        'entity_id' => (int)$row['ID'],
                        'field_name' => 'DESCRIPTION',
                        'value' => (string)$row['DESCRIPTION'],
                        'hint' => 'Раздел инфоблока #' . (int)$row['ID'] . ', поле DESCRIPTION',
                    ];
                }
            }

            if (count($items) < $limit && $connection->isTableExists('b_iblock_element_property')) {
                $q = $connection->query(
                    "SELECT ID, IBLOCK_ELEMENT_ID, IBLOCK_PROPERTY_ID, VALUE FROM b_iblock_element_property
                     WHERE VALUE LIKE '" . $escapedLike . "' ESCAPE '\\\\' LIMIT " . (int)($limit - count($items))
                );
                while ($row = $q->fetch()) {
                    if (strpos((string)$row['VALUE'], $needle) === false) {
                        continue;
                    }
                    $items[] = [
                        'entity_type' => 'iblock_property_value',
                        'entity_id' => (int)$row['ID'],
                        'field_name' => 'VALUE',
                        'value' => (string)$row['VALUE'],
                        'hint' => 'Свойство элемента #' . (int)$row['IBLOCK_ELEMENT_ID'] . ' (запись #' . (int)$row['ID'] . ')',
                    ];
                }
            }
        }

        if (count($items) < $limit && $connection->isTableExists('b_file')) {
            $q = $connection->query(
                "SELECT ID, DESCRIPTION, ORIGINAL_NAME FROM b_file WHERE DESCRIPTION LIKE '" . $escapedLike . "' ESCAPE '\\\\' LIMIT " . (int)($limit - count($items))
            );
            while ($row = $q->fetch()) {
                if (strpos((string)$row['DESCRIPTION'], $needle) === false) {
                    continue;
                }
                $items[] = [
                    'entity_type' => 'file_description',
                    'entity_id' => (int)$row['ID'],
                    'field_name' => 'DESCRIPTION',
                    'value' => (string)$row['DESCRIPTION'],
                    'hint' => 'Описание файла #' . (int)$row['ID'] . ' (' . (string)$row['ORIGINAL_NAME'] . ')',
                ];
            }
        }

        return $items;
    }

    private function findInTemplates(string $needle, int $limit): array
    {
        $limit = max(1, min(30, $limit));
        $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        if ($docRoot === '') {
            return [];
        }

        $roots = [
            $docRoot . '/local/templates',
            $docRoot . '/bitrix/templates',
            $docRoot . '/include',
            $docRoot . '/local/php_interface',
        ];
        $extensions = ['php', 'html', 'htm', 'css', 'js', 'json', 'txt'];
        $items = [];

        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST
                );
            } catch (\Throwable $e) {
                continue;
            }

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile() || !$file->isReadable()) {
                    continue;
                }
                if (!in_array(strtolower($file->getExtension()), $extensions, true)) {
                    continue;
                }
                $path = str_replace('\\', '/', $file->getPathname());
                if (preg_match('~/(cache|managed_cache|stack_cache|tmp|upload|\.git)/~i', $path)) {
                    continue;
                }
                if ($file->getSize() > 2 * 1024 * 1024) {
                    continue;
                }
                $content = @file_get_contents($path);
                if ($content === false || strpos($content, $needle) === false) {
                    continue;
                }
                $relative = $this->relativePath($path, $docRoot);
                $line = $this->lineNumber($content, $needle);
                $items[] = [
                    'entity_type' => 'template_file',
                    'entity_id' => 0,
                    'field_name' => $relative,
                    'value' => $this->snippet($content, $needle, 240),
                    'hint' => $relative . ', строка ' . $line,
                    'path' => $relative,
                    'line' => $line,
                ];
                if (count($items) >= $limit) {
                    return $items;
                }
            }
        }

        return $items;
    }

    private function replaceInTemplateFile(string $relativePath, string $expectedOldValue, string $newValue): array
    {
        if (\COption::GetOptionString('relod.seofixer', 'allow_template_file_autofix', 'N') !== 'Y') {
            return $this->fail(
                'Совпадение найдено в файле шаблона ' . $relativePath . ', строка изменения показана в журнале. '
                . 'Автоправка файлов шаблона выключена в настройках — включите её или передайте задачу разработчику.'
            );
        }

        $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        $realRoot = realpath($docRoot);
        $realFile = realpath($docRoot . '/' . ltrim($relativePath, '/'));
        if (!$realRoot || !$realFile || strpos($realFile, $realRoot) !== 0 || !is_file($realFile)) {
            return $this->fail('Файл шаблона не найден: ' . $relativePath);
        }
        if (!is_writable($realFile)) {
            return $this->fail('Файл шаблона недоступен для записи: ' . $relativePath);
        }

        $content = file_get_contents($realFile);
        if ($content === false) {
            return $this->fail('Не удалось прочитать файл шаблона: ' . $relativePath);
        }
        if (strpos($content, $expectedOldValue) === false) {
            return $this->fail('В файле шаблона больше нет старого значения: ' . $relativePath);
        }

        $backup = $realFile . '.seofixer-bak-' . date('Ymd_His');
        if (!@copy($realFile, $backup)) {
            return $this->fail('Не удалось создать резервную копию файла: ' . $relativePath);
        }

        $updated = str_replace($expectedOldValue, $newValue, $content);
        if (@file_put_contents($realFile, $updated, LOCK_EX) === false) {
            return $this->fail('Не удалось записать файл шаблона: ' . $relativePath);
        }

        return [
            'success' => true,
            'old_value' => $content,
            'new_value' => $updated,
            'message' => 'Заменено в файле шаблона ' . $relativePath . '. Резервная копия: ' . basename($backup),
        ];
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function relativePath(string $path, string $docRoot): string
    {
        $path = str_replace('\\', '/', $path);
        $docRoot = str_replace('\\', '/', rtrim($docRoot, '/'));
        if (strpos($path, $docRoot) === 0) {
            return ltrim(substr($path, strlen($docRoot)), '/');
        }
        return $path;
    }

    private function lineNumber(string $content, string $needle): int
    {
        $pos = strpos($content, $needle);
        return $pos === false ? 0 : substr_count(substr($content, 0, $pos), "\n") + 1;
    }

    private function snippet(string $content, string $needle, int $length): string
    {
        $pos = strpos($content, $needle);
        if ($pos === false) {
            return '';
        }
        $start = max(0, $pos - (int)floor($length / 2));
        return trim(substr($content, $start, $length));
    }

    private function clearCache(): void
    {
        if (isset($GLOBALS['CACHE_MANAGER']) && is_object($GLOBALS['CACHE_MANAGER'])) {
            $GLOBALS['CACHE_MANAGER']->ClearByTag('iblock_id_*');
        }
    }

    private function fail(string $message): array
    {
        return ['success' => false, 'message' => $message, 'old_value' => '', 'new_value' => ''];
    }
}
