<?php
// Heading
$_['heading_title']    = 'Установщик модулей (CLI)';

// Text
$_['text_extension']   = 'Расширения';
$_['text_success']     = 'Успешно: Настройки модуля обновлены!';
$_['text_edit']        = 'Редактировать модуль "Установщик модулей"';
$_['text_enabled']     = 'Включено';
$_['text_disabled']    = 'Отключено';
$_['text_home']        = 'Главная';
$_['text_success_install'] = 'Модуль успешно установлен!';
$_['text_success_uninstall'] = 'Модуль успешно удален!';

// Entry
$_['entry_status']     = 'Статус';
$_['entry_secret_key'] = 'Секретный ключ';
$_['entry_secret_key_help'] = 'Секретный ключ для доступа к веб-эндпоинту установки модулей. Используйте этот ключ в URL для установки модулей через HTTP.';

// Button
$_['button_save']      = 'Сохранить';
$_['button_cancel']    = 'Отмена';

// Error
$_['error_permission'] = 'Предупреждение: У вас нет прав для изменения модуля "Установщик модулей"!';

// Info
$_['info_cli_path']    = 'Путь к CLI скрипту:';
$_['info_cli_usage']   = 'Использование CLI:';
$_['info_cli_exists']  = 'CLI скрипт найден';
$_['info_cli_not_found'] = 'CLI скрипт не найден';
$_['info_description'] = '<p>Этот модуль предоставляет CLI-интерфейс для установки других модулей OpenCart из zip-архивов.</p><p>Используйте следующую команду для установки модуля:</p><pre>php cli.php install-module /path/to/module.zip [--overwrite] [--verbose]</pre><p>Флаги:</p><ul><li><strong>--overwrite</strong> - перезаписать существующие файлы</li><li><strong>--verbose</strong> - вывести подробный лог</li></ul>';
$_['info_web_install_url'] = 'URL для веб-установки:';
$_['info_web_install_description'] = '<p>Модуль также поддерживает установку через веб-интерфейс. Отправьте POST-запрос на URL выше со следующими параметрами:</p><ul><li><strong>file</strong> - zip-архив модуля (multipart/form-data)</li><li><strong>overwrite</strong> (опционально) - "1" для перезаписи существующих файлов</li><li><strong>verbose</strong> (опционально) - "1" для подробного лога</li></ul><p>Пример с curl:</p><pre>curl -X POST -F "file=@module.zip" -F "overwrite=1" "WEB_INSTALL_URL"</pre>';

