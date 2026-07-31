<?php
namespace Relod\SeoFixer\Model;

use Bitrix\Main\Entity;

class IssueTable extends Entity\DataManager
{
    public static function getTableName()
    {
        return 'b_relod_seofixer_issue';
    }

    public static function getMap()
    {
        return [
            new Entity\IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
            new Entity\IntegerField('IMPORT_ID', ['required' => true]),
            new Entity\StringField('ISSUE_TYPE', ['required' => true]),
            new Entity\StringField('SEVERITY'),
            new Entity\TextField('SOURCE_URL'),
            new Entity\TextField('TARGET_URL'),
            new Entity\TextField('FINAL_URL'),
            new Entity\TextField('ANCHOR_TEXT'),
            new Entity\TextField('OLD_VALUE'),
            new Entity\TextField('NEW_VALUE'),
            new Entity\StringField('PLACE_HINT'),
            new Entity\StringField('RISK_LEVEL'),
            new Entity\StringField('STATUS'),
            new Entity\TextField('RAW_DATA'),
            new Entity\StringField('GROUP_HASH'),
            new Entity\DatetimeField('CREATED_AT'),
            new Entity\DatetimeField('UPDATED_AT'),
        ];
    }
}
