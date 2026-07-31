<?php
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;

class relod_seofixer extends CModule
{
    public $MODULE_ID = 'relod.seofixer';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'RELOD SEO Fixer';
    public $MODULE_DESCRIPTION = 'Импорт отчётов Netpeak Spider, ручное подтверждение SEO-исправлений, логи и откат.';
    public $PARTNER_NAME = 'RELOD';
    public $PARTNER_URI = 'https://shop.relod.ru/';

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';
        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
    }

    public function DoInstall()
    {
        global $APPLICATION;
        ModuleManager::registerModule($this->MODULE_ID);
        Loader::includeModule($this->MODULE_ID);
        $this->InstallDB();
        $this->InstallFiles();
        $APPLICATION->IncludeAdminFile('Установка модуля RELOD SEO Fixer', __DIR__ . '/step.php');
    }

    public function DoUninstall()
    {
        global $APPLICATION, $step;
        $step = (int)$step;
        if ($step < 2) {
            $APPLICATION->IncludeAdminFile('Удаление модуля RELOD SEO Fixer', __DIR__ . '/unstep1.php');
        } else {
            $saveData = ($_REQUEST['savedata'] ?? 'Y') === 'Y';
            $this->UnInstallFiles();
            if (!$saveData) {
                $this->UnInstallDB();
            }
            ModuleManager::unRegisterModule($this->MODULE_ID);
            $APPLICATION->IncludeAdminFile('Удаление модуля RELOD SEO Fixer', __DIR__ . '/unstep2.php');
        }
    }

    public function InstallDB()
    {
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();

        if (!$connection->isTableExists('b_relod_seofixer_import')) {
            $connection->queryExecute("CREATE TABLE b_relod_seofixer_import (
                ID INT NOT NULL AUTO_INCREMENT,
                FILE_NAME VARCHAR(255) NOT NULL,
                ORIGINAL_NAME VARCHAR(255) NULL,
                REPORT_TYPE VARCHAR(100) NOT NULL,
                ROWS_TOTAL INT NOT NULL DEFAULT 0,
                FILE_HASH VARCHAR(64) NULL,
                STATUS VARCHAR(50) NOT NULL DEFAULT 'uploaded',
                COMMENT_TEXT TEXT NULL,
                IMPORTED_BY INT NULL,
                IMPORTED_AT DATETIME NOT NULL,
                PRIMARY KEY (ID),
                INDEX ix_relod_seofixer_import_type (REPORT_TYPE),
                INDEX ix_relod_seofixer_import_status (STATUS)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        if (!$connection->isTableExists('b_relod_seofixer_issue')) {
            $connection->queryExecute("CREATE TABLE b_relod_seofixer_issue (
                ID INT NOT NULL AUTO_INCREMENT,
                IMPORT_ID INT NOT NULL,
                ISSUE_TYPE VARCHAR(100) NOT NULL,
                SEVERITY VARCHAR(30) NULL,
                SOURCE_URL TEXT NULL,
                TARGET_URL TEXT NULL,
                FINAL_URL TEXT NULL,
                ANCHOR_TEXT TEXT NULL,
                OLD_VALUE MEDIUMTEXT NULL,
                NEW_VALUE MEDIUMTEXT NULL,
                PLACE_HINT VARCHAR(255) NULL,
                RISK_LEVEL VARCHAR(30) NOT NULL DEFAULT 'manual',
                STATUS VARCHAR(30) NOT NULL DEFAULT 'new',
                RAW_DATA MEDIUMTEXT NULL,
                GROUP_HASH VARCHAR(64) NULL,
                CREATED_AT DATETIME NOT NULL,
                UPDATED_AT DATETIME NULL,
                PRIMARY KEY (ID),
                INDEX ix_relod_seofixer_issue_import (IMPORT_ID),
                INDEX ix_relod_seofixer_issue_type (ISSUE_TYPE),
                INDEX ix_relod_seofixer_issue_status (STATUS),
                INDEX ix_relod_seofixer_issue_group (GROUP_HASH)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        if (!$connection->isTableExists('b_relod_seofixer_change')) {
            $connection->queryExecute("CREATE TABLE b_relod_seofixer_change (
                ID INT NOT NULL AUTO_INCREMENT,
                ISSUE_ID INT NOT NULL,
                IMPORT_ID INT NOT NULL,
                ENTITY_TYPE VARCHAR(80) NOT NULL,
                ENTITY_ID INT NULL,
                FIELD_NAME VARCHAR(100) NULL,
                OLD_VALUE MEDIUMTEXT NULL,
                NEW_VALUE MEDIUMTEXT NULL,
                STATUS VARCHAR(30) NOT NULL DEFAULT 'pending',
                MESSAGE_TEXT TEXT NULL,
                APPLIED_BY INT NULL,
                APPLIED_AT DATETIME NULL,
                ROLLED_BACK_BY INT NULL,
                ROLLED_BACK_AT DATETIME NULL,
                PRIMARY KEY (ID),
                INDEX ix_relod_seofixer_change_issue (ISSUE_ID),
                INDEX ix_relod_seofixer_change_import (IMPORT_ID),
                INDEX ix_relod_seofixer_change_status (STATUS)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        if (!$connection->isTableExists('b_relod_seofixer_error_log')) {
            $connection->queryExecute("CREATE TABLE b_relod_seofixer_error_log (
                ID INT NOT NULL AUTO_INCREMENT,
                IMPORT_ID INT NULL,
                ISSUE_ID INT NULL,
                LEVEL VARCHAR(20) NOT NULL DEFAULT 'error',
                MESSAGE_TEXT TEXT NOT NULL,
                CONTEXT_JSON MEDIUMTEXT NULL,
                CREATED_AT DATETIME NOT NULL,
                PRIMARY KEY (ID),
                INDEX ix_relod_seofixer_error_import (IMPORT_ID),
                INDEX ix_relod_seofixer_error_issue (ISSUE_ID),
                INDEX ix_relod_seofixer_error_level (LEVEL)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        return true;
    }

    public function UnInstallDB()
    {
        $connection = Application::getConnection();
        foreach (['b_relod_seofixer_error_log','b_relod_seofixer_change','b_relod_seofixer_issue','b_relod_seofixer_import'] as $table) {
            if ($connection->isTableExists($table)) {
                $connection->queryExecute('DROP TABLE ' . $table);
            }
        }
        return true;
    }

    public function InstallFiles()
    {
        CopyDirFiles(__DIR__ . '/admin', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin', true, true);
        return true;
    }

    public function UnInstallFiles()
    {
        foreach (glob($_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin/relod_seofixer*.php') as $file) {
            @unlink($file);
        }
        return true;
    }
}
