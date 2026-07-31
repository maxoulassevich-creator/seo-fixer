<?php
namespace Relod\SeoFixer\Model;

use Bitrix\Main\Entity;

/**
 * Карточка найденной проблемы.
 *
 * Все пояснительные поля — TEXT, а не VARCHAR. В версии 1.x подсказка
 * не помещалась в VARCHAR(255), и MySQL в строгом режиме отклонял UPDATE:
 * именно поэтому модуль «молча не сохранял» предложения.
 */
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
            new Entity\StringField('SITE_ID', ['size' => 2]),
            new Entity\StringField('ISSUE_TYPE', ['required' => true, 'size' => 100]),
            new Entity\StringField('SEVERITY', ['size' => 20]),
            new Entity\StringField('CATEGORY', ['size' => 30]),
            new Entity\IntegerField('PRIORITY'),
            new Entity\StringField('STRATEGY', ['size' => 30]),

            new Entity\TextField('SOURCE_URL'),
            new Entity\TextField('TARGET_URL'),
            new Entity\TextField('FINAL_URL'),
            new Entity\TextField('ANCHOR_TEXT'),

            new Entity\TextField('OLD_VALUE'),
            new Entity\TextField('NEW_VALUE'),
            new Entity\TextField('SUGGESTION_NOTE'),
            new Entity\IntegerField('CONFIDENCE'),

            new Entity\StringField('TARGET_TYPE', ['size' => 30]),
            new Entity\IntegerField('TARGET_IBLOCK_ID'),
            new Entity\IntegerField('TARGET_ENTITY_ID'),
            new Entity\StringField('TARGET_FIELD', ['size' => 30]),
            new Entity\TextField('TARGET_NOTE'),
            new Entity\TextField('TARGET_ADMIN_URL'),

            new Entity\IntegerField('LIVE_STATUS'),
            new Entity\TextField('LIVE_VALUE'),
            new Entity\TextField('LIVE_VERDICT'),
            new Entity\DatetimeField('LIVE_CHECKED_AT'),

            new Entity\StringField('RISK_LEVEL', ['size' => 20]),
            new Entity\StringField('STATUS', ['size' => 20]),
            new Entity\TextField('RAW_DATA'),
            new Entity\StringField('GROUP_HASH', ['size' => 64]),
            new Entity\StringField('ISSUE_KEY', ['size' => 64]),
            new Entity\DatetimeField('CREATED_AT'),
            new Entity\DatetimeField('UPDATED_AT'),
        ];
    }
}
