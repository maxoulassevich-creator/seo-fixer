<?php
namespace Relod\SeoFixer\Service;

use Bitrix\Main\Application;

/**
 * Создание и обновление таблиц модуля.
 *
 * Вынесено в сервис, чтобы одну и ту же логику использовали и установщик,
 * и кнопка «Проверить и починить структуру БД» в настройках: на установках
 * версии 1.x поля были слишком короткими (VARCHAR(255) под длинные пояснения),
 * из-за чего MySQL в строгом режиме отклонял UPDATE и модуль не сохранял
 * изменения. Миграция расширяет такие поля до TEXT.
 */
class Schema
{
    /**
     * Ожидаемая структура: таблица => [колонка => определение].
     */
    private const TABLES = [
        'b_relod_seofixer_import' => [
            'ID' => 'INT NOT NULL AUTO_INCREMENT',
            'FILE_NAME' => 'VARCHAR(255) NOT NULL',
            'ORIGINAL_NAME' => 'TEXT NULL',
            'SITE_ID' => 'VARCHAR(2) NULL',
            'DOMAIN' => 'VARCHAR(255) NULL',
            'REPORT_TYPE' => 'VARCHAR(100) NOT NULL',
            'REPORT_DATE' => 'VARCHAR(20) NULL',
            'SEVERITY' => 'VARCHAR(20) NULL',
            'ROWS_TOTAL' => 'INT NOT NULL DEFAULT 0',
            'ISSUES_CREATED' => 'INT NOT NULL DEFAULT 0',
            'FILE_HASH' => 'VARCHAR(64) NULL',
            'STATUS' => 'VARCHAR(30) NOT NULL DEFAULT \'parsed\'',
            'COMMENT_TEXT' => 'TEXT NULL',
            'IMPORTED_BY' => 'INT NULL',
            'IMPORTED_AT' => 'DATETIME NULL',
        ],
        'b_relod_seofixer_issue' => [
            'ID' => 'INT NOT NULL AUTO_INCREMENT',
            'IMPORT_ID' => 'INT NOT NULL',
            'SITE_ID' => 'VARCHAR(2) NULL',
            'ISSUE_TYPE' => 'VARCHAR(100) NOT NULL',
            'SEVERITY' => 'VARCHAR(20) NULL',
            'CATEGORY' => 'VARCHAR(30) NULL',
            'PRIORITY' => 'INT NOT NULL DEFAULT 0',
            'STRATEGY' => 'VARCHAR(30) NULL',
            'SOURCE_URL' => 'TEXT NULL',
            'TARGET_URL' => 'TEXT NULL',
            'FINAL_URL' => 'TEXT NULL',
            'ANCHOR_TEXT' => 'TEXT NULL',
            'OLD_VALUE' => 'MEDIUMTEXT NULL',
            'NEW_VALUE' => 'MEDIUMTEXT NULL',
            'SUGGESTION_NOTE' => 'TEXT NULL',
            'CONFIDENCE' => 'INT NOT NULL DEFAULT 0',
            'TARGET_TYPE' => 'VARCHAR(30) NULL',
            'TARGET_IBLOCK_ID' => 'INT NOT NULL DEFAULT 0',
            'TARGET_ENTITY_ID' => 'INT NOT NULL DEFAULT 0',
            'TARGET_FIELD' => 'VARCHAR(30) NULL',
            'TARGET_NOTE' => 'TEXT NULL',
            'TARGET_ADMIN_URL' => 'TEXT NULL',
            'LIVE_STATUS' => 'INT NOT NULL DEFAULT 0',
            'LIVE_VALUE' => 'MEDIUMTEXT NULL',
            'LIVE_VERDICT' => 'TEXT NULL',
            'LIVE_CHECKED_AT' => 'DATETIME NULL',
            'RISK_LEVEL' => 'VARCHAR(20) NOT NULL DEFAULT \'review\'',
            'STATUS' => 'VARCHAR(20) NOT NULL DEFAULT \'new\'',
            'RAW_DATA' => 'MEDIUMTEXT NULL',
            'GROUP_HASH' => 'VARCHAR(64) NULL',
            'ISSUE_KEY' => 'VARCHAR(64) NULL',
            'CREATED_AT' => 'DATETIME NULL',
            'UPDATED_AT' => 'DATETIME NULL',
        ],
        'b_relod_seofixer_change' => [
            'ID' => 'INT NOT NULL AUTO_INCREMENT',
            'ISSUE_ID' => 'INT NOT NULL',
            'IMPORT_ID' => 'INT NULL',
            'SITE_ID' => 'VARCHAR(2) NULL',
            'STRATEGY' => 'VARCHAR(30) NULL',
            'ENTITY_TYPE' => 'VARCHAR(40) NOT NULL',
            'ENTITY_ID' => 'INT NULL',
            'IBLOCK_ID' => 'INT NOT NULL DEFAULT 0',
            'FIELD_NAME' => 'TEXT NULL',
            'TARGET_URL' => 'TEXT NULL',
            'OLD_VALUE' => 'MEDIUMTEXT NULL',
            'NEW_VALUE' => 'MEDIUMTEXT NULL',
            'STATUS' => 'VARCHAR(20) NOT NULL DEFAULT \'pending\'',
            'MESSAGE_TEXT' => 'TEXT NULL',
            'APPLIED_BY' => 'INT NULL',
            'APPLIED_AT' => 'DATETIME NULL',
            'ROLLED_BACK_BY' => 'INT NULL',
            'ROLLED_BACK_AT' => 'DATETIME NULL',
        ],
        'b_relod_seofixer_error_log' => [
            'ID' => 'INT NOT NULL AUTO_INCREMENT',
            'IMPORT_ID' => 'INT NULL',
            'ISSUE_ID' => 'INT NULL',
            'SITE_ID' => 'VARCHAR(2) NULL',
            'LEVEL' => 'VARCHAR(20) NOT NULL DEFAULT \'error\'',
            'MESSAGE_TEXT' => 'TEXT NOT NULL',
            'CONTEXT_JSON' => 'MEDIUMTEXT NULL',
            'CREATED_AT' => 'DATETIME NULL',
        ],
    ];

    private const INDEXES = [
        'b_relod_seofixer_import' => [
            'ix_sf_import_site' => 'SITE_ID',
            'ix_sf_import_type' => 'REPORT_TYPE',
        ],
        'b_relod_seofixer_issue' => [
            'ix_sf_issue_import' => 'IMPORT_ID',
            'ix_sf_issue_site' => 'SITE_ID',
            'ix_sf_issue_type' => 'ISSUE_TYPE',
            'ix_sf_issue_status' => 'STATUS',
            'ix_sf_issue_group' => 'GROUP_HASH',
            'ix_sf_issue_key' => 'ISSUE_KEY',
            'ix_sf_issue_priority' => 'PRIORITY',
        ],
        'b_relod_seofixer_change' => [
            'ix_sf_change_issue' => 'ISSUE_ID',
            'ix_sf_change_import' => 'IMPORT_ID',
            'ix_sf_change_status' => 'STATUS',
        ],
        'b_relod_seofixer_error_log' => [
            'ix_sf_error_import' => 'IMPORT_ID',
            'ix_sf_error_issue' => 'ISSUE_ID',
            'ix_sf_error_level' => 'LEVEL',
        ],
    ];

    /**
     * Создаёт недостающие таблицы и приводит существующие к нужному виду.
     *
     * @return array{created:string[],added:string[],changed:string[],errors:string[]}
     */
    public static function install(): array
    {
        $report = ['created' => [], 'added' => [], 'changed' => [], 'errors' => []];
        $connection = Application::getConnection();

        foreach (self::TABLES as $table => $columns) {
            try {
                if (!$connection->isTableExists($table)) {
                    $connection->queryExecute(self::createSql($table, $columns));
                    $report['created'][] = $table;
                } else {
                    $existing = self::describe($table);
                    foreach ($columns as $name => $definition) {
                        if (!isset($existing[$name])) {
                            $connection->queryExecute('ALTER TABLE ' . $table . ' ADD COLUMN ' . $name . ' ' . self::addable($definition));
                            $report['added'][] = $table . '.' . $name;
                            continue;
                        }
                        if (self::needsWidening($existing[$name], $definition)) {
                            $connection->queryExecute('ALTER TABLE ' . $table . ' MODIFY COLUMN ' . $name . ' ' . self::addable($definition));
                            $report['changed'][] = $table . '.' . $name;
                        }
                    }
                }
                self::ensureIndexes($table, $report);
            } catch (\Throwable $e) {
                $report['errors'][] = $table . ': ' . $e->getMessage();
            }
        }

        return $report;
    }

    public static function uninstall(): void
    {
        $connection = Application::getConnection();
        foreach (array_reverse(array_keys(self::TABLES)) as $table) {
            if ($connection->isTableExists($table)) {
                $connection->queryExecute('DROP TABLE ' . $table);
            }
        }
    }

    /**
     * Проверка без изменений — для кнопки диагностики.
     *
     * @return array{ok:bool,missing_tables:string[],missing_columns:string[],narrow_columns:string[]}
     */
    public static function check(): array
    {
        $result = ['ok' => true, 'missing_tables' => [], 'missing_columns' => [], 'narrow_columns' => []];
        $connection = Application::getConnection();

        foreach (self::TABLES as $table => $columns) {
            if (!$connection->isTableExists($table)) {
                $result['missing_tables'][] = $table;
                $result['ok'] = false;
                continue;
            }
            $existing = self::describe($table);
            foreach ($columns as $name => $definition) {
                if (!isset($existing[$name])) {
                    $result['missing_columns'][] = $table . '.' . $name;
                    $result['ok'] = false;
                } elseif (self::needsWidening($existing[$name], $definition)) {
                    $result['narrow_columns'][] = $table . '.' . $name . ' (сейчас ' . $existing[$name] . ')';
                    $result['ok'] = false;
                }
            }
        }

        return $result;
    }

    // -----------------------------------------------------------------

    private static function createSql(string $table, array $columns): string
    {
        $parts = [];
        foreach ($columns as $name => $definition) {
            $parts[] = $name . ' ' . $definition;
        }
        $parts[] = 'PRIMARY KEY (ID)';
        return 'CREATE TABLE ' . $table . ' (' . implode(', ', $parts) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }

    /**
     * Определение без AUTO_INCREMENT — его нельзя добавлять через ADD COLUMN.
     */
    private static function addable(string $definition): string
    {
        return trim((string)str_ireplace('AUTO_INCREMENT', '', $definition));
    }

    /**
     * @return array<string,string> колонка => тип в нижнем регистре
     */
    private static function describe(string $table): array
    {
        $connection = Application::getConnection();
        $out = [];
        $res = $connection->query('SHOW COLUMNS FROM ' . $table);
        while ($row = $res->fetch()) {
            $out[(string)$row['Field']] = strtolower((string)$row['Type']);
        }
        return $out;
    }

    /**
     * Нужно ли расширить колонку: VARCHAR там, где ожидается TEXT,
     * или слишком короткий VARCHAR.
     */
    private static function needsWidening(string $currentType, string $wantedDefinition): bool
    {
        $wanted = strtolower($wantedDefinition);
        $current = strtolower($currentType);

        $wantsText = strpos($wanted, 'text') !== false;
        $isText = strpos($current, 'text') !== false;

        if ($wantsText && !$isText) {
            return true;
        }
        if ($wantsText && $isText) {
            // MEDIUMTEXT шире TEXT.
            return strpos($wanted, 'mediumtext') !== false && strpos($current, 'mediumtext') === false;
        }

        if (preg_match('/varchar\((\d+)\)/', $wanted, $w) && preg_match('/varchar\((\d+)\)/', $current, $c)) {
            return (int)$c[1] < (int)$w[1];
        }

        return false;
    }

    private static function ensureIndexes(string $table, array &$report): void
    {
        if (!isset(self::INDEXES[$table])) {
            return;
        }
        $connection = Application::getConnection();
        $existing = [];
        $res = $connection->query('SHOW INDEX FROM ' . $table);
        while ($row = $res->fetch()) {
            $existing[(string)$row['Key_name']] = true;
        }
        foreach (self::INDEXES[$table] as $name => $column) {
            if (isset($existing[$name])) {
                continue;
            }
            try {
                $connection->queryExecute('CREATE INDEX ' . $name . ' ON ' . $table . ' (' . $column . ')');
            } catch (\Throwable $e) {
                $report['errors'][] = $table . '.' . $name . ': ' . $e->getMessage();
            }
        }
    }
}
