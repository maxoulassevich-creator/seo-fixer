<?php
namespace Relod\SeoFixer\Model;

use Bitrix\Main\Entity;
use Bitrix\Main\Type\DateTime;

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
            new Entity\StringField('ORIGINAL_NAME'),
            new Entity\StringField('REPORT_TYPE', ['required' => true]),
            new Entity\IntegerField('ROWS_TOTAL'),
            new Entity\StringField('FILE_HASH'),
            new Entity\StringField('STATUS'),
            new Entity\TextField('COMMENT_TEXT'),
            new Entity\IntegerField('IMPORTED_BY'),
            new Entity\DatetimeField('IMPORTED_AT'),
        ];
    }
}
