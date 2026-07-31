<?php
namespace Relod\SeoFixer\Service;

use Bitrix\Main\Type\DateTime;
use Relod\SeoFixer\Model\ChangeTable;
use Relod\SeoFixer\Model\ErrorLogTable;

class RollbackService
{
    public function rollbackChange(int $changeId, int $userId): array
    {
        $change = ChangeTable::getById($changeId)->fetch();
        if (!$change) {
            return ['success' => false, 'message' => 'Изменение не найдено.'];
        }
        if ($change['STATUS'] !== 'applied') {
            return ['success' => false, 'message' => 'Откат доступен только для выполненных изменений.'];
        }

        $scanner = new StorageScanner();
        $res = $scanner->updateField(
            (string)$change['ENTITY_TYPE'],
            (int)$change['ENTITY_ID'],
            (string)$change['FIELD_NAME'],
            (string)$change['NEW_VALUE'],
            (string)$change['OLD_VALUE']
        );

        if ($res['success']) {
            ChangeTable::update($changeId, [
                'STATUS' => 'rolled_back',
                'ROLLED_BACK_BY' => $userId,
                'ROLLED_BACK_AT' => new DateTime(),
                'MESSAGE_TEXT' => 'Изменение откатили. Старое значение восстановлено.',
            ]);
            return ['success' => true, 'message' => 'Изменение откатили.'];
        }

        ErrorLogTable::add([
            'IMPORT_ID' => (int)$change['IMPORT_ID'],
            'ISSUE_ID' => (int)$change['ISSUE_ID'],
            'LEVEL' => 'error',
            'MESSAGE_TEXT' => 'Откат не выполнен: ' . $res['message'],
            'CONTEXT_JSON' => json_encode($change, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'CREATED_AT' => new DateTime(),
        ]);
        return ['success' => false, 'message' => $res['message']];
    }
}
