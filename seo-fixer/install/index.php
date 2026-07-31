<?php
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;

class relod_seofixer extends CModule
{
    public $MODULE_ID = 'relod.seofixer';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'RELOD SEO Fixer';
    public $MODULE_DESCRIPTION = 'Импорт отчётов Netpeak Spider, проверка сайта в реальном времени, подтверждаемые SEO-исправления, журналы и откат. Работает с любым количеством сайтов.';
    public $PARTNER_NAME = 'RELOD';
    public $PARTNER_URI = '';

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
        $this->setDefaultOptions();
        $APPLICATION->IncludeAdminFile('Установка модуля RELOD SEO Fixer', __DIR__ . '/step.php');
    }

    public function DoUninstall()
    {
        global $APPLICATION, $step;
        $step = (int)$step;
        if ($step < 2) {
            $APPLICATION->IncludeAdminFile('Удаление модуля RELOD SEO Fixer', __DIR__ . '/unstep1.php');
            return;
        }

        $saveData = ($_REQUEST['savedata'] ?? 'Y') === 'Y';
        $this->UnInstallFiles();
        if (!$saveData) {
            $this->UnInstallDB();
        }
        ModuleManager::unRegisterModule($this->MODULE_ID);
        $APPLICATION->IncludeAdminFile('Удаление модуля RELOD SEO Fixer', __DIR__ . '/unstep2.php');
    }

    /**
     * Структура БД создаётся и обновляется одним сервисом, поэтому
     * повторная установка поверх версии 1.x безопасно доводит таблицы
     * до актуального вида, не теряя данные.
     */
    public function InstallDB()
    {
        Loader::includeModule($this->MODULE_ID);
        \Relod\SeoFixer\Service\Schema::install();
        return true;
    }

    public function UnInstallDB()
    {
        Loader::includeModule($this->MODULE_ID);
        \Relod\SeoFixer\Service\Schema::uninstall();
        foreach ([
            'batch_limit', 'allow_template_file_autofix', 'allow_static_page_write',
            'http_timeout', 'http_delay_ms', 'http_user_agent', 'verify_on_import',
            'tpl_title', 'tpl_description', 'tpl_description_tail',
        ] as $option) {
            COption::RemoveOption('relod.seofixer', $option);
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

    private function setDefaultOptions(): void
    {
        $defaults = [
            'batch_limit' => '50',
            'allow_template_file_autofix' => 'N',
            'allow_static_page_write' => 'Y',
            'http_timeout' => '15',
            'http_delay_ms' => '150',
            'verify_on_import' => 'Y',
        ];
        foreach ($defaults as $name => $value) {
            if (COption::GetOptionString('relod.seofixer', $name, '') === '') {
                COption::SetOptionString('relod.seofixer', $name, $value);
            }
        }
    }
}
