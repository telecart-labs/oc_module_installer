#!/usr/bin/env php
<?php

/**
 * CLI скрипт для установки модулей OpenCart из zip-архивов
 * 
 * Использование:
 *   php cli.php install-module /path/to/module.zip [--overwrite] [--verbose]
 */

// Проверка, что скрипт запускается из CLI
if (php_sapi_name() !== 'cli') {
    die("Этот скрипт должен запускаться только из командной строки.\n");
}

// Определяем базовый путь OpenCart
// Пытаемся найти upload директорию относительно текущего скрипта
$currentDir = __DIR__;
$opencartRoot = null;

// Пробуем несколько вариантов
$possiblePaths = [
    dirname($currentDir) . '/upload', // Если скрипт в корне модуля
    dirname($currentDir, 2) . '/upload', // Если скрипт в поддиректории
    dirname($currentDir, 3) . '/src/upload', // Для проекта telecart
    dirname($currentDir, 3) . '/upload', // Стандартная структура OpenCart
];

foreach ($possiblePaths as $path) {
    if (is_dir($path) && is_file($path . '/config.php')) {
        $opencartRoot = $path;
        break;
    }
}

// Если не нашли, используем текущую директорию как базовую
if (!$opencartRoot) {
    // Ищем config.php в родительских директориях
    $dir = $currentDir;
    while ($dir != '/' && $dir != '') {
        if (is_file($dir . '/config.php')) {
            $opencartRoot = $dir;
            break;
        }
        $dir = dirname($dir);
    }
}

if (!$opencartRoot || !is_dir($opencartRoot)) {
    die("Ошибка: Не удалось найти директорию OpenCart. Убедитесь, что скрипт находится в правильной директории.\n");
}

// Загружаем конфигурацию OpenCart
// КРИТИЧНО: Не загружаем config.php, так как он определяет DIR_APPLICATION как catalog/
// Нам нужен только admin/config.php для загрузки админских моделей
// Загружаем только admin/config.php
require_once $opencartRoot . '/admin/config.php';

// Если admin/config.php не определил DIR_APPLICATION (что маловероятно), определяем сами
$adminPath = $opencartRoot . '/admin/';
if (!defined('DIR_APPLICATION')) {
    define('DIR_APPLICATION', $adminPath);
}

// Загружаем остальные константы из config.php вручную (без DIR_APPLICATION)
// Но используем значения из admin/config.php где возможно
if (is_file($opencartRoot . '/config.php')) {
    // Парсим config.php без выполнения, чтобы получить константы, которые не определены в admin/config.php
    $configContent = file_get_contents($opencartRoot . '/config.php');
    // Извлекаем только нужные константы (DIR_CATALOG и т.д.)
    // Но не DIR_APPLICATION, так как он уже определен в admin/config.php
    eval('?>' . preg_replace('/define\s*\(\s*[\'"]DIR_APPLICATION[\'"]/', '// define("DIR_APPLICATION"', $configContent));
}
if (!defined('DIR_SYSTEM')) {
    // Пытаемся найти system относительно upload
    $systemPath = dirname($opencartRoot) . '/system/';
    if (!is_dir($systemPath)) {
        $systemPath = $opencartRoot . '/../system/';
    }
    if (!is_dir($systemPath)) {
        $systemPath = $opencartRoot . '/system/';
    }
    define('DIR_SYSTEM', $systemPath);
}
if (!defined('DIR_IMAGE')) {
    define('DIR_IMAGE', dirname($opencartRoot) . '/image/');
}
if (!defined('DIR_STORAGE')) {
    // Пытаемся найти storage
    $storagePath = dirname($opencartRoot) . '/storage/';
    if (!is_dir($storagePath)) {
        $storagePath = DIR_SYSTEM . 'storage/';
    }
    if (!is_dir($storagePath)) {
        $storagePath = $opencartRoot . '/../storage/';
    }
    define('DIR_STORAGE', $storagePath);
}
if (!defined('DIR_MODIFICATION')) {
    define('DIR_MODIFICATION', DIR_STORAGE . 'modification/');
}
if (!defined('DIR_UPLOAD')) {
    define('DIR_UPLOAD', DIR_STORAGE . 'upload/');
}
if (!defined('DIR_LOGS')) {
    define('DIR_LOGS', DIR_STORAGE . 'logs/');
}
if (!defined('DIR_CATALOG')) {
    define('DIR_CATALOG', $opencartRoot . '/catalog/');
}

// Загружаем startup.php
require_once DIR_SYSTEM . 'startup.php';

// Загружаем Registry
$registry = new Registry();

// Загружаем Config
$config = new Config();
$config->set('application_config', DIR_APPLICATION . 'config.php');
$registry->set('config', $config);

// Загружаем Database
$db = new DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, DB_PORT);
$registry->set('db', $db);

// Загружаем Event (нужен для Loader)
$event = new Event($registry);
$registry->set('event', $event);

// Загружаем Loader
$loader = new Loader($registry);
$registry->set('load', $loader);

// Проверяем, что DIR_APPLICATION правильный
$actualModelPath = DIR_APPLICATION . 'model/setting/modification.php';
$expectedModelPath = $opencartRoot . '/admin/model/setting/modification.php';

if (!is_file($actualModelPath)) {
    // Если модель не найдена по текущему DIR_APPLICATION, проверяем альтернативный путь
    if (is_file($expectedModelPath)) {
        // DIR_APPLICATION неправильный, нужно временно изменить поведение Loader
        // Но так как константа уже определена, мы не можем её переопределить
        // Поэтому просто проверим, что модель существует, и продолжим
        // Loader должен найти модель по правильному пути
        if (strpos(DIR_APPLICATION, '/admin/') === false) {
            echo "Предупреждение: DIR_APPLICATION указывает не на admin: " . DIR_APPLICATION . "\n";
            echo "Ожидаемый путь к модели: $expectedModelPath\n";
            echo "Модель существует: " . (is_file($expectedModelPath) ? 'ДА' : 'НЕТ') . "\n";
        }
    } else {
        die("Ошибка: Не найдена модель setting/modification.\n" . 
            "Искали по пути: $actualModelPath\n" .
            "Альтернативный путь: $expectedModelPath\n" .
            "DIR_APPLICATION = " . DIR_APPLICATION . "\n" .
            "opencartRoot = $opencartRoot\n");
    }
}

// Отладочная информация (только если verbose)
if (isset($argv) && in_array('--verbose', $argv)) {
    echo "Отладка путей:\n";
    echo "DIR_APPLICATION = " . DIR_APPLICATION . "\n";
    echo "Модель modification: " . (is_file(DIR_APPLICATION . 'model/setting/modification.php') ? 'найдена' : 'не найдена') . "\n";
    echo "Полный путь: " . DIR_APPLICATION . 'model/setting/modification.php' . "\n";
}

// Загружаем модели
try {
    $loader->model('setting/extension');
    $loader->model('setting/modification');
    $loader->model('setting/setting');
} catch (Exception $e) {
    die("Ошибка загрузки модели: " . $e->getMessage() . "\n" .
        "DIR_APPLICATION = " . DIR_APPLICATION . "\n" .
        "Проверьте путь: " . DIR_APPLICATION . 'model/setting/modification.php' . "\n" .
        "Файл существует: " . (is_file(DIR_APPLICATION . 'model/setting/modification.php') ? 'ДА' : 'НЕТ') . "\n");
}

// Загружаем класс установщика
// Файл находится в system/library/module_installer.php
$installerPath = DIR_SYSTEM . 'library/module_installer.php';
if (!is_file($installerPath)) {
    // Пробуем альтернативный путь относительно текущего скрипта
    $installerPath = __DIR__ . '/system/library/module_installer.php';
}
if (!is_file($installerPath)) {
    die("Ошибка: Не найден класс установщика. Искали по пути: $installerPath\n" .
        "Убедитесь, что файл system/library/module_installer.php существует.\n");
}
require_once $installerPath;

// Обработка аргументов командной строки
$command = $argv[1] ?? null;
$modulePath = $argv[2] ?? null;
$overwrite = in_array('--overwrite', $argv);
$verbose = in_array('--verbose', $argv);

if ($command !== 'install-module') {
    die("Использование: php cli.php install-module /path/to/module.zip [--overwrite] [--verbose]\n");
}

if (!$modulePath || !is_file($modulePath)) {
    die("Ошибка: Файл модуля не найден или не указан: " . ($modulePath ?: 'не указан') . "\n");
}

// Создаем экземпляр установщика
$installer = new ModuleInstaller($registry, $verbose);

try {
    $installer->install($modulePath, $overwrite);
    echo "Модуль успешно установлен!\n";
    exit(0);
} catch (Exception $e) {
    echo "Ошибка при установке модуля: " . $e->getMessage() . "\n";
    if ($verbose) {
        echo "Трассировка:\n" . $e->getTraceAsString() . "\n";
    }
    exit(1);
}

