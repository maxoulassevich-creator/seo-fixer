<?php
namespace Relod\SeoFixer\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

class StorageScanner
{
    public function findOccurrences(string $needle, int $limit = 20, bool $includeTemplateFiles = true): array
    {
        $needle = trim($needle);
        if ($needle === '') {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $items = $this->findDatabaseOccurrences($needle, $limit);

        if ($includeTemplateFiles && count($items) < $limit) {
            $items = array_merge($items, $this->findTemplateOccurrences($needle, $limit - count($items)));
        }

        return array_slice($items, 0, $limit);
    }

    public function updateField(string $entityType, int $entityId, string $fieldName, string $expectedOldValue, string $newValue): array
    {
        $connection = Application::getConnection();
        $sqlHelper = $connection->getSqlHelper();

        $allowed = [
            'iblock_element' => ['table' => 'b_iblock_element', 'id' => 'ID', 'fields' => ['PREVIEW_TEXT', 'DETAIL_TEXT']],
            'iblock_section' => ['table' => 'b_iblock_section', 'id' => 'ID', 'fields' => ['DESCRIPTION']],
            'iblock_property_value' => ['table' => 'b_iblock_element_property', 'id' => 'ID', 'fields' => ['VALUE']],
            'file_description' => ['table' => 'b_file', 'id' => 'ID', 'fields' => ['DESCRIPTION']],
        ];

        if ($entityType === 'template_file') {
            return $this->updateTemplateFile($fieldName, $expectedOldValue, $newValue);
        }

        if (!isset($allowed[$entityType])) {
            return ['success' => false, 'message' => 'Этот тип хранения не поддерживается для автоматической правки.'];
        }

        $meta = $allowed[$entityType];
        if (!in_array($fieldName, $meta['fields'], true)) {
            return ['success' => false, 'message' => 'Поле не входит в список разрешённых для безопасной замены.'];
        }

        $table = $meta['table'];
        $idField = $meta['id'];
        $row = $connection->query('SELECT ' . $fieldName . ' FROM ' . $table . ' WHERE ' . $idField . '=' . (int)$entityId)->fetch();
        if (!$row) {
            return ['success' => false, 'message' => 'Запись уже не найдена на сайте.'];
        }

        $current = (string)$row[$fieldName];
        if (strpos($current, $expectedOldValue) === false) {
            return ['success' => false, 'message' => 'Значение изменилось после проверки. Чтобы не сломать данные, исправление пропущено.'];
        }

        $updated = str_replace($expectedOldValue, $newValue, $current);
        $connection->queryExecute('UPDATE ' . $table . ' SET ' . $fieldName . "='" . $sqlHelper->forSql($updated) . "' WHERE " . $idField . '=' . (int)$entityId);

        $this->clearManagedCache();

        return [
            'success' => true,
            'old_value' => $current,
            'new_value' => $updated,
            'message' => 'Точное совпадение заменено в разрешённом поле Битрикс: ' . $entityType . ' #' . $entityId . ' / ' . $fieldName . '.',
        ];
    }

    public function findTemplateHints(string $needle, int $limit = 10): array
    {
        return $this->findTemplateOccurrences($needle, $limit);
    }

    private function findDatabaseOccurrences(string $needle, int $limit): array
    {
        $items = [];
        $connection = Application::getConnection();
        $sqlHelper = $connection->getSqlHelper();
        $like = '%' . $needle . '%';
        $escapedLike = $sqlHelper->forSql($like);

        if (Loader::includeModule('iblock')) {
            if ($connection->isTableExists('b_iblock_element')) {
                $q = $connection->query("SELECT ID, PREVIEW_TEXT, DETAIL_TEXT FROM b_iblock_element WHERE PREVIEW_TEXT LIKE '" . $escapedLike . "' OR DETAIL_TEXT LIKE '" . $escapedLike . "' LIMIT " . (int)$limit);
                while ($row = $q->fetch()) {
                    foreach (['PREVIEW_TEXT', 'DETAIL_TEXT'] as $field) {
                        if (strpos((string)$row[$field], $needle) !== false) {
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
            }

            if (count($items) < $limit && $connection->isTableExists('b_iblock_section')) {
                $q = $connection->query("SELECT ID, DESCRIPTION FROM b_iblock_section WHERE DESCRIPTION LIKE '" . $escapedLike . "' LIMIT " . (int)($limit - count($items)));
                while ($row = $q->fetch()) {
                    if (strpos((string)$row['DESCRIPTION'], $needle) !== false) {
                        $items[] = [
                            'entity_type' => 'iblock_section',
                            'entity_id' => (int)$row['ID'],
                            'field_name' => 'DESCRIPTION',
                            'value' => (string)$row['DESCRIPTION'],
                            'hint' => 'Раздел инфоблока #' . (int)$row['ID'] . ', поле DESCRIPTION',
                        ];
                    }
                }
            }

            if (count($items) < $limit && $connection->isTableExists('b_iblock_element_property')) {
                $q = $connection->query("SELECT ID, IBLOCK_ELEMENT_ID, IBLOCK_PROPERTY_ID, VALUE FROM b_iblock_element_property WHERE VALUE LIKE '" . $escapedLike . "' LIMIT " . (int)($limit - count($items)));
                while ($row = $q->fetch()) {
                    if (strpos((string)$row['VALUE'], $needle) !== false) {
                        $items[] = [
                            'entity_type' => 'iblock_property_value',
                            'entity_id' => (int)$row['ID'],
                            'field_name' => 'VALUE',
                            'value' => (string)$row['VALUE'],
                            'hint' => 'Свойство инфоблока: элемент #' . (int)$row['IBLOCK_ELEMENT_ID'] . ', свойство #' . (int)$row['IBLOCK_PROPERTY_ID'] . ', запись #' . (int)$row['ID'],
                        ];
                    }
                }
            }
        }

        if (count($items) < $limit && $connection->isTableExists('b_file')) {
            $q = $connection->query("SELECT ID, DESCRIPTION, ORIGINAL_NAME, FILE_NAME FROM b_file WHERE DESCRIPTION LIKE '" . $escapedLike . "' LIMIT " . (int)($limit - count($items)));
            while ($row = $q->fetch()) {
                if (strpos((string)$row['DESCRIPTION'], $needle) !== false) {
                    $items[] = [
                        'entity_type' => 'file_description',
                        'entity_id' => (int)$row['ID'],
                        'field_name' => 'DESCRIPTION',
                        'value' => (string)$row['DESCRIPTION'],
                        'hint' => 'Файл #' . (int)$row['ID'] . ' (' . (string)$row['ORIGINAL_NAME'] . '), описание файла',
                    ];
                }
            }
        }

        return $items;
    }

    private function findTemplateOccurrences(string $needle, int $limit): array
    {
        $limit = max(1, min(30, $limit));
        $docRoot = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/');
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
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile() || !$file->isReadable()) {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                if (!in_array($ext, $extensions, true)) {
                    continue;
                }
                $path = $file->getPathname();
                if (preg_match('~/(cache|managed_cache|stack_cache|tmp|upload)/~i', str_replace('\\', '/', $path))) {
                    continue;
                }
                if ($file->getSize() > 2 * 1024 * 1024) {
                    continue;
                }
                $content = @file_get_contents($path);
                if ($content === false || strpos($content, $needle) === false) {
                    continue;
                }
                $line = $this->lineNumber($content, $needle);
                $items[] = [
                    'entity_type' => 'template_file',
                    'entity_id' => 0,
                    'field_name' => $this->relativePath($path, $docRoot),
                    'value' => $this->snippet($content, $needle, 240),
                    'hint' => $this->relativePath($path, $docRoot) . ': строка ' . $line,
                    'path' => $this->relativePath($path, $docRoot),
                    'line' => $line,
                    'snippet' => $this->snippet($content, $needle, 240),
                ];
                if (count($items) >= $limit) {
                    return $items;
                }
            }
        }

        return $items;
    }

    private function updateTemplateFile(string $relativePath, string $expectedOldValue, string $newValue): array
    {
        $allow = \COption::GetOptionString('relod.seofixer', 'allow_template_file_autofix', 'N') === 'Y';
        if (!$allow) {
            return [
                'success' => false,
                'message' => 'Совпадение найдено в файле шаблона ' . $relativePath . '. Автоправка файлов отключена в настройках; создайте задачу разработчику или включите отдельный режим для файлов.',
            ];
        }

        $docRoot = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/');
        $path = $docRoot . '/' . ltrim($relativePath, '/');
        $realDoc = realpath($docRoot);
        $realFile = realpath($path);
        if (!$realDoc || !$realFile || strpos($realFile, $realDoc) !== 0 || !is_file($realFile) || !is_writable($realFile)) {
            return ['success' => false, 'message' => 'Файл шаблона не найден или недоступен для записи: ' . $relativePath];
        }

        $content = file_get_contents($realFile);
        if ($content === false) {
            return ['success' => false, 'message' => 'Не удалось прочитать файл шаблона: ' . $relativePath];
        }
        if (strpos($content, $expectedOldValue) === false) {
            return ['success' => false, 'message' => 'В файле шаблона уже нет старого значения: ' . $relativePath];
        }

        $backup = $realFile . '.relod_seofixer_bak_' . date('Ymd_His');
        if (!copy($realFile, $backup)) {
            return ['success' => false, 'message' => 'Не удалось создать резервную копию файла шаблона: ' . $relativePath];
        }

        $updated = str_replace($expectedOldValue, $newValue, $content);
        if (file_put_contents($realFile, $updated, LOCK_EX) === false) {
            return ['success' => false, 'message' => 'Не удалось записать исправленный файл шаблона: ' . $relativePath];
        }

        return [
            'success' => true,
            'old_value' => $content,
            'new_value' => $updated,
            'message' => 'Точное совпадение заменено в файле шаблона. Резервная копия: ' . basename($backup),
        ];
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
        if ($pos === false) {
            return 0;
        }
        return substr_count(substr($content, 0, $pos), "\n") + 1;
    }

    private function snippet(string $content, string $needle, int $length): string
    {
        $pos = strpos($content, $needle);
        if ($pos === false) {
            return '';
        }
        $start = max(0, $pos - (int)floor($length / 2));
        $snippet = substr($content, $start, $length);
        return trim($snippet);
    }

    private function clearManagedCache(): void
    {
        if (defined('BX_COMP_MANAGED_CACHE') && is_object($GLOBALS['CACHE_MANAGER'] ?? null)) {
            $GLOBALS['CACHE_MANAGER']->ClearByTag('iblock_id_*');
        }
    }
}
