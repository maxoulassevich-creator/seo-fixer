<?php
namespace Relod\SeoFixer\Model;

use Bitrix\Main\Entity;

/**
 * Журнал изменений: что, где и когда поменял модуль.
 * Старое значение сохраняется всегда — на нём построен откат.
 */
class ChangeTable extends Entity\DataManager
{
    public static function getTableName()
    {
        return 'b_relod_seofixer_change';
    }

    public static function getMap()
    {
        return [
            new Entity\IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
            new Entity\IntegerField('ISSUE_ID', ['required' => true]),
            new Entity\IntegerField('IMPORT_ID'),
            new Entity\StringField('SITE_ID', ['size' => 2]),
            new Entity\StringField('STRATEGY', ['size' => 30]),
            new Entity\StringField('ENTITY_TYPE', ['required' => true, 'size' => 40]),
            new Entity\IntegerField('ENTITY_ID'),
            new Entity\IntegerField('IBLOCK_ID'),
            new Entity\TextField('FIELD_NAME'),
            new Entity\TextField('TARGET_URL'),
            new Entity\TextField('OLD_VALUE'),
            new Entity\TextField('NEW_VALUE'),
            new Entity\StringField('STATUS', ['size' => 20]),
            new Entity\TextField('MESSAGE_TEXT'),
            new Entity\IntegerField('APPLIED_BY'),
            new Entity\DatetimeField('APPLIED_AT'),
            new Entity\IntegerField('ROLLED_BACK_BY'),
            new Entity\DatetimeField('ROLLED_BACK_AT'),
        ];
    }
}
