<?php
namespace Relod\SeoFixer\Model;

use Bitrix\Main\Entity;

/**
 * Загруженный отчёт Netpeak. Каждая загрузка привязана к конкретному
 * сайту Битрикса, поэтому один модуль обслуживает сколько угодно сайтов.
 */
class ImportTable extends Entity\DataManager
{
    public static function getTableName()
    {
        return 'b_relod_seofixer_import';
    }

    public static function getMap()
    {
        return [
            new Entity\IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
            new Entity\StringField('FILE_NAME', ['required' => true]),
            new Entity\TextField('ORIGINAL_NAME'),
            new Entity\StringField('SITE_ID', ['size' => 2]),
            new Entity\StringField('DOMAIN', ['size' => 255]),
            new Entity\StringField('REPORT_TYPE', ['required' => true, 'size' => 100]),
            new Entity\StringField('REPORT_DATE', ['size' => 20]),
            new Entity\StringField('SEVERITY', ['size' => 20]),
            new Entity\IntegerField('ROWS_TOTAL'),
            new Entity\IntegerField('ISSUES_CREATED'),
            new Entity\StringField('FILE_HASH', ['size' => 64]),
            new Entity\StringField('STATUS', ['size' => 30]),
            new Entity\TextField('COMMENT_TEXT'),
            new Entity\IntegerField('IMPORTED_BY'),
            new Entity\DatetimeField('IMPORTED_AT'),
        ];
    }
}
