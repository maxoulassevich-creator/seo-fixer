<?php
use Bitrix\Main\Loader;

Loader::registerAutoLoadClasses('relod.seofixer', [
    'Relod\\SeoFixer\\Model\\ImportTable' => 'lib/Model/ImportTable.php',
    'Relod\\SeoFixer\\Model\\IssueTable' => 'lib/Model/IssueTable.php',
    'Relod\\SeoFixer\\Model\\ChangeTable' => 'lib/Model/ChangeTable.php',
    'Relod\\SeoFixer\\Model\\ErrorLogTable' => 'lib/Model/ErrorLogTable.php',
    'Relod\\SeoFixer\\Service\\XlsxReader' => 'lib/Service/XlsxReader.php',
    'Relod\\SeoFixer\\Service\\CsvReader' => 'lib/Service/CsvReader.php',
    'Relod\\SeoFixer\\Service\\ReportTypeDetector' => 'lib/Service/ReportTypeDetector.php',
    'Relod\\SeoFixer\\Service\\ImportService' => 'lib/Service/ImportService.php',
    'Relod\\SeoFixer\\Service\\IssueSuggestionService' => 'lib/Service/IssueSuggestionService.php',
    'Relod\\SeoFixer\\Service\\MaintenanceService' => 'lib/Service/MaintenanceService.php',
    'Relod\\SeoFixer\\Service\\IssuePresenter' => 'lib/Service/IssuePresenter.php',
    'Relod\\SeoFixer\\Service\\StorageScanner' => 'lib/Service/StorageScanner.php',
    'Relod\\SeoFixer\\Service\\FixService' => 'lib/Service/FixService.php',
    'Relod\\SeoFixer\\Service\\RollbackService' => 'lib/Service/RollbackService.php',
    'Relod\\SeoFixer\\Helper\\AdminHelper' => 'lib/Helper/AdminHelper.php',
]);
