<?php
namespace Relod\SeoFixer\Model;

use Bitrix\Main\Entity;

/**
 * Отдельный журнал того, что модуль НЕ сделал: не разобрал строку,
 * не нашёл место правки, пропустил ради безопасности.
 */
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
            new Entity\StringField('SITE_ID', ['size' => 2]),
            new Entity\StringField('LEVEL', ['size' => 20]),
            new Entity\TextField('MESSAGE_TEXT', ['required' => true]),
            new Entity\TextField('CONTEXT_JSON'),
            new Entity\DatetimeField('CREATED_AT'),
        ];
    }
}
