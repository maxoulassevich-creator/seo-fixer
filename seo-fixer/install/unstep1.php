<?php
if (!check_bitrix_sessid()) return;
?>
<form action="<?= $APPLICATION->GetCurPage(); ?>" method="post">
    <?= bitrix_sessid_post(); ?>
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID; ?>">
    <input type="hidden" name="id" value="relod.seofixer">
    <input type="hidden" name="uninstall" value="Y">
    <input type="hidden" name="step" value="2">
    <p><label><input type="checkbox" name="savedata" value="Y" checked> Сохранить загруженные отчёты, логи и историю изменений</label></p>
    <input type="submit" value="Удалить модуль">
</form>
