<?php

/**
 * Класс для установки модулей OpenCart из zip-архивов
 */
class ModuleInstaller {
    private $registry;
    private $verbose;
    private $logMessages = [];
    private $installedFiles = [];
    private $installedPaths = [];
    private $extensionInstallId = null;
    private $currentZipFile = null;

    public function __construct($registry, $verbose = false) {
        $this->registry = $registry;
        $this->verbose = $verbose;
    }

    /**
     * Установка модуля из zip-архива
     */
    public function install($zipPath, $overwrite = false) {
        $this->currentZipFile = $zipPath;
        $this->log("Начало установки модуля из: " . $zipPath);

        // Шаг 1: Распаковка zip-архива
        $extractDir = $this->unzip($zipPath);
        
        try {
            // Шаг 2: Перемещение файлов в нужные директории
            // В оригинале используется rename() для перемещения файлов из временной директории
            $this->moveFiles($extractDir, $overwrite);
            
            // Шаг 3: Обработка install.xml
            // Добавляет OCMOD модификацию в базу данных (но НЕ применяет её)
            $this->processXml($extractDir);
            
            // Шаг 4: Очистка временных файлов (соответствует remove() в оригинале)
            // В оригинале это делается сразу после xml()
            $this->cleanup($extractDir);
            
            // ВНИМАНИЕ: В оригинальном OpenCart модификации НЕ применяются автоматически
            // Они только добавляются в БД и должны быть применены вручную через админку
            // ОТКЛЮЧЕНО: Установщик не должен модифицировать системные файлы OpenCart
            // Если модификации необходимы, их можно применить вручную через админку
            // $this->log("Применение OCMOD модификаций (в оригинале OpenCart это делается вручную)...");
            // $this->applyModifications();
            
            // Очистка кэша (также не делается автоматически в оригинале, но полезно для CLI)
            $this->clearCache();
            
            $this->log("Установка модуля завершена успешно!");
        } catch (Exception $e) {
            // Откат изменений в случае ошибки
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Распаковка zip-архива
     */
    private function unzip($zipPath) {
        $this->log("Распаковка zip-архива...");
        
        if (!extension_loaded('zip')) {
            throw new Exception("Расширение ZipArchive не доступно");
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new Exception("Не удалось открыть zip-архив: " . $zipPath);
        }

        $extractDir = DIR_UPLOAD . 'tmp-' . uniqid('install_', true) . '/';
        
        if (!is_dir($extractDir)) {
            mkdir($extractDir, 0777, true);
        }

        $zip->extractTo($extractDir);
        $zip->close();

        // В оригинале OpenCart исходный zip файл удаляется после распаковки
        // Но мы работаем с исходным файлом, который может быть переиспользован
        // Поэтому не удаляем оригинальный файл (это отличается от оригинала, но безопаснее)
        
        $this->log("Архив распакован в: " . $extractDir);
        return $extractDir;
    }

    /**
     * Перемещение файлов из upload/ в нужные директории
     */
    private function moveFiles($extractDir, $overwrite) {
        $this->log("Перемещение файлов...");

        $uploadDir = $extractDir . 'upload/';
        
        if (!is_dir($uploadDir)) {
            $this->log("Директория upload/ не найдена, пропускаем перемещение файлов");
            return;
        }

        $loader = $this->registry->get('load');
        $loader->model('setting/extension');
        $model_extension = $this->registry->get('model_setting_extension');

        // Добавляем запись в extension_install (как в оригинале OpenCart)
        // В оригинале filename берется из имени загруженного файла
        $filename = basename($this->currentZipFile ?: $extractDir);
        $this->extensionInstallId = $model_extension->addExtensionInstall($filename);
        $this->log("Создана запись extension_install_id: " . $this->extensionInstallId);

        // Получаем список всех файлов
        $files = [];
        $path = [$uploadDir . '*'];

        while (count($path) != 0) {
            $next = array_shift($path);
            
            foreach ((array)glob($next) as $file) {
                if (is_dir($file)) {
                    $path[] = $file . '/*';
                }
                $files[] = $file;
            }
        }

        // Разрешенные директории для записи
        // Список соответствует стандартной структуре модулей OpenCart
        $allowed = [
            'admin/controller/extension/',
            'admin/language/',
            'admin/model/extension/',
            'admin/view/image/',
            'admin/view/javascript/',
            'admin/view/stylesheet/',
            'admin/view/template/extension/',
            'catalog/controller/extension/',
            'catalog/language/',
            'catalog/model/extension/',
            'catalog/view/javascript/',
            'catalog/view/template/extension/',
            'catalog/view/theme/',
            'system/config/',
            'system/library/',
            'image/catalog/'
        ];

        // Проверяем безопасность путей
        foreach ($files as $file) {
            $destination = str_replace('\\', '/', substr($file, strlen($uploadDir)));
            
            $safe = false;
            foreach ($allowed as $value) {
                if (strlen($destination) < strlen($value) && substr($value, 0, strlen($destination)) == $destination) {
                    $safe = true;
                    break;
                }
                if (strlen($destination) > strlen($value) && substr($destination, 0, strlen($value)) == $value) {
                    $safe = true;
                    break;
                }
            }
            
            if (!$safe) {
                throw new Exception("Неразрешенный путь: " . $destination);
            }
        }

        // Проверяем, что необходимые константы определены
        if (!defined('DIR_APPLICATION')) {
            throw new Exception("Константа DIR_APPLICATION не определена");
        }
        if (!defined('DIR_CATALOG')) {
            throw new Exception("Константа DIR_CATALOG не определена. Убедитесь, что она определена перед использованием ModuleInstaller");
        }
        if (!defined('DIR_IMAGE')) {
            throw new Exception("Константа DIR_IMAGE не определена");
        }
        if (!defined('DIR_SYSTEM')) {
            throw new Exception("Константа DIR_SYSTEM не определена");
        }

        // Определяем путь к admin директории
        // В catalog контроллере DIR_APPLICATION указывает на catalog/, поэтому нужно найти admin/
        $adminPath = null;
        
        // Нормализуем DIR_APPLICATION (убираем trailing slash если есть, потом добавим)
        $appDir = rtrim(DIR_APPLICATION, '/');
        
        if (basename($appDir) === 'catalog') {
            // DIR_APPLICATION указывает на catalog/, admin должен быть рядом
            // Например: /web/upload/catalog/ -> /web/upload/admin/
            $adminPath = dirname($appDir) . '/admin/';
        } elseif (basename($appDir) === 'admin') {
            // DIR_APPLICATION уже указывает на admin/
            $adminPath = $appDir . '/';
        } else {
            // Пытаемся найти admin относительно DIR_APPLICATION
            $possiblePaths = [
                dirname($appDir) . '/admin/',
                dirname(dirname($appDir)) . '/admin/',
                $appDir . '/../admin/',
            ];
            foreach ($possiblePaths as $possiblePath) {
                $normalized = rtrim(str_replace('\\', '/', $possiblePath), '/');
                $realPath = realpath($normalized);
                if ($realPath && is_dir($realPath)) {
                    $adminPath = $realPath . '/';
                    break;
                }
            }
            if (!$adminPath) {
                // Последняя попытка: проверяем стандартный путь OpenCart
                // Если DIR_APPLICATION = /web/upload/catalog/, то admin = /web/upload/admin/
                $uploadDir = dirname($appDir);
                if (basename($uploadDir) === 'upload' && is_dir($uploadDir . '/admin')) {
                    $adminPath = $uploadDir . '/admin/';
                } else {
                    // Fallback: используем DIR_APPLICATION (возможно, это уже admin)
                    $adminPath = DIR_APPLICATION;
                }
            }
        }
        
        // Проверяем, что admin путь действительно существует
        if ($adminPath && !is_dir($adminPath)) {
            throw new Exception("Не найдена admin директория по пути: " . $adminPath . " (DIR_APPLICATION = " . DIR_APPLICATION . ")");
        }
        
        // Отладочная информация (только если verbose)
        if ($this->verbose) {
            $this->log("Пути для установки:", true);
            $this->log("  DIR_APPLICATION = " . DIR_APPLICATION, true);
            $this->log("  DIR_CATALOG = " . (defined('DIR_CATALOG') ? DIR_CATALOG : 'не определена'), true);
            $this->log("  Admin путь = " . $adminPath, true);
        }

        // Перемещаем файлы
        foreach ($files as $file) {
            $destination = str_replace('\\', '/', substr($file, strlen($uploadDir)));
            $path = '';

            if (substr($destination, 0, 5) == 'admin') {
                $path = $adminPath . substr($destination, 6);
            } elseif (substr($destination, 0, 7) == 'catalog') {
                $path = DIR_CATALOG . substr($destination, 8);
            } elseif (substr($destination, 0, 5) == 'image') {
                $path = DIR_IMAGE . substr($destination, 6);
            } elseif (substr($destination, 0, 6) == 'system') {
                $path = DIR_SYSTEM . substr($destination, 7);
            }

            if ($path) {
                // Создание директорий (точно как в оригинале OpenCart - mkdir без recursive)
                if (is_dir($file) && !is_dir($path)) {
                    if (mkdir($path, 0777)) {
                        $this->installedPaths[] = $destination;
                        $model_extension->addExtensionPath($this->extensionInstallId, $destination);
                        $this->log("Создана директория: " . $path, true);
                    }
                } elseif (is_file($file)) {
                    // В оригинале OpenCart файлы перезаписываются без проверки
                    // Для CLI мы добавляем опциональную проверку через флаг --overwrite
                    if (is_file($path) && !$overwrite) {
                        $this->log("Файл уже существует: " . $path, true);
                        throw new Exception(
                            "Файл уже существует (используйте --overwrite для перезаписи): " . $path . "\n" .
                            "Используйте команду: php cli.php install-module " . basename($this->currentZipFile ?? 'module.zip') . " --overwrite"
                        );
                    }
                    
                    // Создаем директорию если нужно (как в оригинале)
                    $dir = dirname($path);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0777, true);
                    }
                    
                    // OpenCart использует rename() для перемещения файла из временной директории
                    if (rename($file, $path)) {
                        $this->installedFiles[] = $path;
                        $this->installedPaths[] = $destination;
                        $model_extension->addExtensionPath($this->extensionInstallId, $destination);
                        $this->log("Перемещен файл: " . $path, true);
                    } else {
                        throw new Exception("Не удалось переместить файл: " . $path);
                    }
                }
            }
        }

        $this->log("Файлы успешно перемещены");
    }

    /**
     * Обработка install.xml файла
     */
    private function processXml($extractDir) {
        $this->log("Обработка install.xml...");

        $xmlFile = $extractDir . 'install.xml';
        
        if (!is_file($xmlFile)) {
            $this->log("Файл install.xml не найден, пропускаем обработку XML");
            return;
        }

        $xml = file_get_contents($xmlFile);
        
        if (!$xml) {
            throw new Exception("Не удалось прочитать install.xml");
        }

        $loader = $this->registry->get('load');
        $loader->model('setting/modification');
        $loader->model('setting/extension');
        $model_modification = $this->registry->get('model_setting_modification');
        $model_extension = $this->registry->get('model_setting_extension');

        try {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->loadXml($xml);

            // Получаем информацию о модификации
            $name = $dom->getElementsByTagName('name')->item(0);
            $name = $name ? $name->nodeValue : '';

            $code = $dom->getElementsByTagName('code')->item(0);
            $code = $code ? $code->nodeValue : '';

            if (!$code) {
                throw new Exception("Код модификации не найден в install.xml");
            }

            // Проверяем существование модификации
            $modification_info = $model_modification->getModificationByCode($code);
            if ($modification_info) {
                $this->log("Найдена существующая модификация с кодом: " . $code . ", удаляем старую версию");
                $model_modification->deleteModification($modification_info['modification_id']);
            }

            $author = $dom->getElementsByTagName('author')->item(0);
            $author = $author ? $author->nodeValue : '';

            $version = $dom->getElementsByTagName('version')->item(0);
            $version = $version ? $version->nodeValue : '';

            $link = $dom->getElementsByTagName('link')->item(0);
            $link = $link ? $link->nodeValue : '';

            // Добавляем модификацию
            $modification_data = [
                'extension_install_id' => $this->extensionInstallId,
                'name' => $name,
                'code' => $code,
                'author' => $author,
                'version' => $version,
                'link' => $link,
                'xml' => $xml,
                'status' => 1
            ];

            $model_modification->addModification($modification_data);
            $this->log("Модификация добавлена в базу данных: " . $code);

            // Получаем тип и код модуля из install.xml для добавления в extension
            // Пытаемся найти информацию из различных источников
            $type = '';
            $moduleCode = '';

            // Метод 1: Ищем в элементах install в корне XML (если есть)
            $installNodes = $dom->getElementsByTagName('install');
            if ($installNodes->length > 0) {
                $installNode = $installNodes->item(0);
                $type = $installNode->getAttribute('type');
                $moduleCode = $installNode->getAttribute('code');
            }

            // Метод 2: Пытаемся определить из путей файлов
            if (!$type || !$moduleCode) {
                $fileNodes = $dom->getElementsByTagName('file');
                for ($i = 0; $i < $fileNodes->length; $i++) {
                    $fileNode = $fileNodes->item($i);
                    $filePath = $fileNode->getAttribute('path');
                    
                    // Ищем пути контроллеров
                    if (preg_match('#(admin|catalog)/controller/extension/([^/]+)/([^/]+)#', $filePath, $matches)) {
                        $type = $matches[2]; // module, payment, shipping и т.д.
                        $moduleCode = $matches[3]; // код модуля
                        break;
                    }
                }
            }

            // Метод 3: Используем код модификации как fallback
            if (!$type || !$moduleCode) {
                // Пытаемся извлечь тип из кода модификации
                // Например, если code="module_tgshop", то type="module", code="tgshop"
                if (preg_match('/^([a-z]+)_(.+)$/', $code, $matches)) {
                    $type = $matches[1];
                    $moduleCode = $matches[2];
                }
            }

            // ПРИМЕЧАНИЕ: В оригинальном OpenCart добавление в extension делается через метод install() контроллера модуля
            // Например: ControllerExtensionModuleTgshop->install() вызывает model_setting_extension->install()
            // Здесь мы пытаемся определить тип и код автоматически для удобства CLI-установки
            // Это является расширением функциональности по сравнению с оригиналом
            if ($type && $moduleCode) {
                $extensions = $model_extension->getInstalled($type);
                if (!in_array($moduleCode, $extensions)) {
                    $model_extension->install($type, $moduleCode);
                    $this->log("Модуль добавлен в extension: type={$type}, code={$moduleCode}");
                    $this->log("Примечание: В оригинале OpenCart это делается через метод install() контроллера модуля", true);
                } else {
                    $this->log("Модуль уже существует в extension: type={$type}, code={$moduleCode}");
                }
            } else {
                $this->log("Не удалось определить type и code модуля для добавления в extension", true);
                $this->log("В оригинале OpenCart модуль должен иметь метод install() для добавления в extension", true);
            }

            // Обработка настроек по умолчанию из install.xml
            $this->processDefaultSettings($dom);

        } catch (Exception $e) {
            throw new Exception("Ошибка при обработке install.xml: " . $e->getMessage());
        }
    }

    /**
     * Обработка настроек по умолчанию из install.xml
     */
    private function processDefaultSettings($dom) {
        $loader = $this->registry->get('load');
        $loader->model('setting/setting');
        $model_setting = $this->registry->get('model_setting_setting');

        // Ищем секцию settings в XML
        $settingsNodes = $dom->getElementsByTagName('setting');
        
        if ($settingsNodes->length == 0) {
            return;
        }

        $settings = [];
        foreach ($settingsNodes as $settingNode) {
            $key = $settingNode->getAttribute('key');
            $value = $settingNode->nodeValue;
            
            if ($key) {
                $settings[$key] = $value;
            }
        }

        if (!empty($settings)) {
            // Группируем настройки по коду модуля
            $groupedSettings = [];
            foreach ($settings as $key => $value) {
                // Определяем код модуля из ключа (например, module_tgshop_status -> module_tgshop)
                if (preg_match('/^([a-z_]+)_/', $key, $matches)) {
                    $code = $matches[1];
                    if (!isset($groupedSettings[$code])) {
                        $groupedSettings[$code] = [];
                    }
                    $groupedSettings[$code][$key] = $value;
                }
            }

            // Сохраняем настройки
            foreach ($groupedSettings as $code => $moduleSettings) {
                $existingSettings = $model_setting->getSetting($code);
                $mergedSettings = array_merge($existingSettings, $moduleSettings);
                $model_setting->editSetting($code, $mergedSettings);
                $this->log("Добавлены настройки по умолчанию для модуля: " . $code);
            }
        }
    }

    /**
     * Применение OCMOD модификаций
     * Применяет только модификации, связанные с текущим устанавливаемым модулем
     */
    private function applyModifications() {
        $this->log("Применение OCMOD модификаций...");

        // Загружаем модификации из базы данных
        $loader = $this->registry->get('load');
        $loader->model('setting/modification');
        $model_modification = $this->registry->get('model_setting_modification');

        $xml = [];

        // Получаем только модификации текущего модуля (по extension_install_id)
        // НЕ применяем базовые модификации OpenCart и модификации других модулей
        if ($this->extensionInstallId) {
            $results = $model_modification->getModifications();
            foreach ($results as $result) {
                // Применяем только модификации, связанные с текущим устанавливаемым модулем
                if ($result['status'] && isset($result['extension_install_id']) && 
                    $result['extension_install_id'] == $this->extensionInstallId) {
                    $xml[] = $result['xml'];
                }
            }
        }
        
        // Если нет модификаций для текущего модуля, пропускаем применение
        if (empty($xml)) {
            $this->log("Нет модификаций для применения (модуль не содержит OCMOD модификаций)");
            return;
        }

        // Применяем модификации
        $modification = [];
        foreach ($xml as $xmlContent) {
            if (empty($xmlContent)) {
                continue;
            }

            try {
                $dom = new DOMDocument('1.0', 'UTF-8');
                $dom->preserveWhiteSpace = false;
                $dom->loadXml($xmlContent);

                $name = $dom->getElementsByTagName('name')->item(0);
                if ($name) {
                    $this->log("Применение модификации: " . $name->textContent, true);
                }

                $files = $dom->getElementsByTagName('modification')->item(0)->getElementsByTagName('file');
                
                foreach ($files as $fileNode) {
                    $operations = $fileNode->getElementsByTagName('operation');
                    $filePaths = explode('|', str_replace("\\", '/', $fileNode->getAttribute('path')));

                    foreach ($filePaths as $filePath) {
                        $path = '';
                        
                        if (substr($filePath, 0, 7) == 'catalog') {
                            $path = DIR_CATALOG . substr($filePath, 8);
                        } elseif (substr($filePath, 0, 5) == 'admin') {
                            $path = DIR_APPLICATION . substr($filePath, 6);
                        } elseif (substr($filePath, 0, 6) == 'system') {
                            $path = DIR_SYSTEM . substr($filePath, 7);
                        }

                        if ($path) {
                            $matchedFiles = glob($path, GLOB_BRACE);
                            
                            if ($matchedFiles) {
                                foreach ($matchedFiles as $matchedFile) {
                                    $key = '';
                                    
                                    if (substr($matchedFile, 0, strlen(DIR_CATALOG)) == DIR_CATALOG) {
                                        $key = 'catalog/' . substr($matchedFile, strlen(DIR_CATALOG));
                                    } elseif (substr($matchedFile, 0, strlen(DIR_APPLICATION)) == DIR_APPLICATION) {
                                        $key = 'admin/' . substr($matchedFile, strlen(DIR_APPLICATION));
                                    } elseif (substr($matchedFile, 0, strlen(DIR_SYSTEM)) == DIR_SYSTEM) {
                                        $key = 'system/' . substr($matchedFile, strlen(DIR_SYSTEM));
                                    }

                                    if ($key) {
                                        // Читаем оригинальный файл
                                        if (!isset($modification[$key])) {
                                            if (is_file($matchedFile)) {
                                                $modification[$key] = file_get_contents($matchedFile);
                                            }
                                        }

                                        // Применяем операции
                                        foreach ($operations as $operation) {
                                            $search = $operation->getElementsByTagName('search')->item(0);
                                            $add = $operation->getElementsByTagName('add')->item(0);
                                            $replace = $operation->getElementsByTagName('replace')->item(0);

                                            if ($search && $add) {
                                                // Операция добавления
                                                $searchAttr = $search->getAttribute('position');
                                                $index = (int)$search->getAttribute('index');
                                                
                                                if ($searchAttr == 'replace') {
                                                    $searchContent = $search->textContent;
                                                    $addContent = $add->textContent;
                                                    
                                                    if (strpos($modification[$key], $searchContent) !== false) {
                                                        if ($index >= 0) {
                                                            $parts = explode($searchContent, $modification[$key]);
                                                            $newContent = '';
                                                            foreach ($parts as $i => $part) {
                                                                $newContent .= $part;
                                                                if ($i < count($parts) - 1) {
                                                                    if ($i == $index) {
                                                                        $newContent .= $addContent;
                                                                    }
                                                                    $newContent .= $searchContent;
                                                                }
                                                            }
                                                            $modification[$key] = $newContent;
                                                        } else {
                                                            $modification[$key] = str_replace($searchContent, $addContent, $modification[$key]);
                                                        }
                                                    }
                                                } elseif ($searchAttr == 'after' || $searchAttr == 'before') {
                                                    $searchContent = $search->textContent;
                                                    $addContent = $add->textContent;
                                                    
                                                    if (strpos($modification[$key], $searchContent) !== false) {
                                                        if ($searchAttr == 'after') {
                                                            $modification[$key] = str_replace($searchContent, $searchContent . $addContent, $modification[$key]);
                                                        } else {
                                                            $modification[$key] = str_replace($searchContent, $addContent . $searchContent, $modification[$key]);
                                                        }
                                                    }
                                                }
                                            } elseif ($search && $replace) {
                                                // Операция замены
                                                $searchContent = $search->textContent;
                                                $replaceContent = $replace->textContent;
                                                
                                                if (strpos($modification[$key], $searchContent) !== false) {
                                                    $modification[$key] = str_replace($searchContent, $replaceContent, $modification[$key]);
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                $this->log("Ошибка при обработке модификации: " . $e->getMessage(), true);
            }
        }

        // Сохраняем модифицированные файлы
        foreach ($modification as $key => $content) {
            $destination = DIR_MODIFICATION . $key;
            $dir = dirname($destination);
            
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            
            file_put_contents($destination, $content);
            $this->log("Создан модифицированный файл: " . $destination, true);
        }

        $this->log("OCMOD модификации применены успешно");
    }

    /**
     * Очистка кэша
     */
    private function clearCache() {
        $this->log("Очистка кэша...");

        // Очистка кэша тем - используем упрощенный безопасный метод
        // Очищаем только верхний уровень, без глубокой рекурсии
        $cacheDirs = [
            DIR_CACHE . 'template/',
            DIR_CACHE . 'catalog/',
            DIR_CACHE . 'admin/',
        ];

        foreach ($cacheDirs as $cacheDir) {
            if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
                continue;
            }

            try {
                // Простая очистка без глубокой рекурсии
                $files = @scandir($cacheDir);
                if ($files !== false) {
                    $deleted = 0;
                    foreach ($files as $file) {
                        if ($file == '.' || $file == '..') {
                            continue;
                        }
                        $filePath = $cacheDir . $file;
                        if (is_file($filePath)) {
                            @unlink($filePath);
                            $deleted++;
                        } elseif (is_dir($filePath)) {
                            // Очищаем только первый уровень поддиректорий
                            $this->clearCacheDirectoryShallow($filePath);
                            @rmdir($filePath);
                            $deleted++;
                        }
                    }
                    if ($deleted > 0) {
                        $this->log("Очищен кэш: " . $cacheDir . " (удалено записей: $deleted)", true);
                    }
                }
            } catch (Exception $e) {
                $this->log("Предупреждение: Не удалось очистить кэш " . $cacheDir . ": " . $e->getMessage(), true);
            } catch (Throwable $e) {
                $this->log("Ошибка при очистке кэша " . $cacheDir . ": " . $e->getMessage(), true);
            }
        }
        
        $this->log("Очистка кэша завершена");
    }

    /**
     * Поверхностная очистка директории кэша (один уровень вглубь)
     */
    private function clearCacheDirectoryShallow($dir) {
        if (!is_dir($dir) || !is_readable($dir)) {
            return;
        }

        $files = @scandir($dir);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            $filePath = $dir . '/' . $file;
            if (is_file($filePath)) {
                @unlink($filePath);
            }
        }
    }


    /**
     * Откат изменений в случае ошибки
     */
    private function rollback() {
        $this->log("Откат изменений...");

        // Удаляем скопированные файлы
        foreach ($this->installedFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        // Удаляем созданные директории (в обратном порядке)
        $paths = array_reverse($this->installedPaths);
        foreach ($paths as $path) {
            $fullPath = '';
            
            if (substr($path, 0, 5) == 'admin') {
                $fullPath = DIR_APPLICATION . substr($path, 6);
            } elseif (substr($path, 0, 7) == 'catalog') {
                $fullPath = DIR_CATALOG . substr($path, 8);
            } elseif (substr($path, 0, 5) == 'image') {
                $fullPath = DIR_IMAGE . substr($path, 6);
            } elseif (substr($path, 0, 6) == 'system') {
                $fullPath = DIR_SYSTEM . substr($path, 7);
            }

            if ($fullPath && is_dir($fullPath)) {
                $this->removeDirectory($fullPath);
            }
        }

        // Удаляем запись extension_install
        if ($this->extensionInstallId) {
            $loader = $this->registry->get('load');
            $loader->model('setting/extension');
            $loader->model('setting/modification');
            $model_extension = $this->registry->get('model_setting_extension');
            $model_modification = $this->registry->get('model_setting_modification');

            $model_modification->deleteModificationsByExtensionInstallId($this->extensionInstallId);
            $model_extension->deleteExtensionInstall($this->extensionInstallId);
        }

        $this->log("Откат завершен");
    }

    /**
     * Удаление директории рекурсивно
     */
    private function removeDirectory($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $filePath = $dir . '/' . $file;
            is_dir($filePath) ? $this->removeDirectory($filePath) : unlink($filePath);
        }

        rmdir($dir);
    }

    /**
     * Очистка временных файлов (соответствует remove() в оригинале OpenCart)
     */
    private function cleanup($extractDir) {
        $this->log("Очистка временных файлов...");
        
        // Используем тот же алгоритм, что и в оригинале OpenCart
        if (is_dir($extractDir)) {
            $files = [];
            $path = [$extractDir];

            while (count($path) != 0) {
                $next = array_shift($path);

                // Используем scandir как в оригинале (glob не подхватывает dot-файлы)
                foreach (array_diff(scandir($next), ['.', '..']) as $file) {
                    $file = $next . '/' . $file;

                    if (is_dir($file)) {
                        $path[] = $file;
                    }

                    $files[] = $file;
                }
            }

            // Сортируем в обратном порядке для правильного удаления
            rsort($files);

            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                } elseif (is_dir($file)) {
                    @rmdir($file);
                }
            }

            if (is_dir($extractDir)) {
                @rmdir($extractDir);
            }
        }
        
        $this->log("Временные файлы удалены");
    }

    /**
     * Логирование сообщений
     */
    private function log($message, $detail = false) {
        $this->logMessages[] = $message;
        
        if ($this->verbose || !$detail) {
            echo "[" . date('Y-m-d H:i:s') . "] " . $message . "\n";
        }
    }

    /**
     * Получить все сообщения лога
     */
    public function getLogMessages() {
        return $this->logMessages;
    }
    
    /**
     * Получить список установленных файлов
     */
    public function getInstalledFiles() {
        return $this->installedFiles;
    }
    
    /**
     * Получить список установленных путей
     */
    public function getInstalledPaths() {
        return $this->installedPaths;
    }
}

