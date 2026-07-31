<?php
namespace Relod\SeoFixer\Model;

use Bitrix\Main\Entity;

class ErrorLogTable extends Entity\DataManager
{
    public static function getTableName()
    {
        return 'b_relod_seofixer_error_log';
    }

    public static function getMap()
    {
        return [
            new Entity\IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
            new Entity\IntegerField('IMPORT_ID'),
            new Entity\IntegerField('ISSUE_ID'),
            new Entity\StringField('LEVEL'),
            new Entity\TextField('MESSAGE_TEXT', ['required' => true]),
            new Entity\TextField('CONTEXT_JSON'),
            new Entity\DatetimeField('CREATED_AT'),
        ];
    }
}
