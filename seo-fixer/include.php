<?php
use Bitrix\Main\Loader;

Loader::registerAutoLoadClasses('relod.seofixer', [
    // Модели
    'Relod\\SeoFixer\\Model\\ImportTable' => 'lib/Model/ImportTable.php',
    'Relod\\SeoFixer\\Model\\IssueTable' => 'lib/Model/IssueTable.php',
    'Relod\\SeoFixer\\Model\\ChangeTable' => 'lib/Model/ChangeTable.php',
    'Relod\\SeoFixer\\Model\\ErrorLogTable' => 'lib/Model/ErrorLogTable.php',

    // Разбор отчётов
    'Relod\\SeoFixer\\Report\\XlsxReader' => 'lib/Report/XlsxReader.php',
    'Relod\\SeoFixer\\Report\\CsvReader' => 'lib/Report/CsvReader.php',
    'Relod\\SeoFixer\\Report\\ReportCatalog' => 'lib/Report/ReportCatalog.php',
    'Relod\\SeoFixer\\Report\\ReportTypeDetector' => 'lib/Report/ReportTypeDetector.php',
    'Relod\\SeoFixer\\Report\\RowMapper' => 'lib/Report/RowMapper.php',

    // Сайт и живая проверка
    'Relod\\SeoFixer\\Site\\SiteContext' => 'lib/Site/SiteContext.php',
    'Relod\\SeoFixer\\Site\\LivePageInspector' => 'lib/Site/LivePageInspector.php',
    'Relod\\SeoFixer\\Site\\LiveVerifier' => 'lib/Site/LiveVerifier.php',
    'Relod\\SeoFixer\\Site\\PageFacts' => 'lib/Site/PageFacts.php',

    // Поиск цели правки и запись
    'Relod\\SeoFixer\\Target\\UrlResolver' => 'lib/Target/UrlResolver.php',
    'Relod\\SeoFixer\\Target\\SeoWriter' => 'lib/Target/SeoWriter.php',

    // Исправления
    'Relod\\SeoFixer\\Fix\\SuggestionEngine' => 'lib/Fix/SuggestionEngine.php',
    'Relod\\SeoFixer\\Fix\\DuplicateResolver' => 'lib/Fix/DuplicateResolver.php',
    'Relod\\SeoFixer\\Fix\\FixService' => 'lib/Fix/FixService.php',
    'Relod\\SeoFixer\\Fix\\RollbackService' => 'lib/Fix/RollbackService.php',

    // Сервисы верхнего уровня
    'Relod\\SeoFixer\\Service\\ImportService' => 'lib/Service/ImportService.php',
    'Relod\\SeoFixer\\Service\\IssuePresenter' => 'lib/Service/IssuePresenter.php',
    'Relod\\SeoFixer\\Service\\MaintenanceService' => 'lib/Service/MaintenanceService.php',
    'Relod\\SeoFixer\\Service\\StorageScanner' => 'lib/Service/StorageScanner.php',
    'Relod\\SeoFixer\\Service\\Schema' => 'lib/Service/Schema.php',

    // Оформление
    'Relod\\SeoFixer\\Helper\\AdminHelper' => 'lib/Helper/AdminHelper.php',
]);
