<?php
if (!check_bitrix_sessid()) return;
CAdminMessage::ShowNote('Модуль RELOD SEO Fixer удалён.');
?>
<form action="<?= $APPLICATION->GetCurPage(); ?>">
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID; ?>">
    <input type="submit" value="Вернуться к списку модулей">
</form>
