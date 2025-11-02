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
$_['entry_github_repo'] = 'GitHub репозиторий';
$_['entry_github_repo_help'] = 'Формат: owner/repo (например, telecart-labs/oc_module_installer)';
$_['entry_github_token'] = 'GitHub API Token';
$_['entry_github_token_help'] = 'Personal Access Token (классический) с правами repo для приватных репозиториев или public_repo для публичных. Токен создается в GitHub → Settings → Developer settings → Personal access tokens → Tokens (classic). Для приватных репозиториев необходим scope "repo" (Full control of private repositories). НЕ логируется.';
$_['entry_github_branch'] = 'Ветка для проверки';
$_['entry_github_branch_help'] = 'Название ветки для проверки коммитов (например, main, master, develop)';
$_['entry_artifact_name'] = 'Имя artifact';
$_['entry_artifact_name_help'] = 'Точное имя artifact ZIP файла для скачивания (например, oc_telegram_shop.ocmod.zip)';
$_['entry_deploy_secret_key'] = 'Секретный ключ (автодеплой)';
$_['entry_deploy_secret_key_help'] = 'Секретный ключ для доступа к endpoint автодеплоя. Используйте этот ключ в URL для автоматического деплоя из GitHub.';
$_['entry_last_deployed_sha'] = 'Последний деплойнутый commit SHA';
$_['entry_last_deploy_log'] = 'Лог последнего деплоя';

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
$_['info_deploy_url'] = 'URL для автодеплоя:';
$_['info_deploy_description'] = 'Модуль поддерживает автоматический деплой из GitHub. Отправьте POST запрос на URL выше для запуска деплоя. Секретный ключ передается в теле POST запроса, а не в URL.';
$_['info_deploy_examples'] = 'Примеры использования:';
$_['info_deploy_example_normal'] = 'Обычный деплой:';
$_['info_deploy_example_force'] = 'Принудительный деплой:';
$_['info_deploy_example_json'] = 'JSON запрос:';
$_['info_deploy_example_github_actions'] = 'GitHub Actions:';
$_['info_deploy_example_no_key'] = 'Сначала установите секретный ключ для автодеплоя, чтобы увидеть примеры.';
$_['button_copy'] = 'Копировать';

