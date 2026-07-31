<?php
if (!check_bitrix_sessid()) return;
CAdminMessage::ShowNote('Модуль RELOD SEO Fixer установлен. Откройте раздел «Сервисы → RELOD SEO Fixer».');
?>
<form action="<?= $APPLICATION->GetCurPage(); ?>">
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID; ?>">
    <input type="submit" value="Вернуться к списку модулей">
</form>
