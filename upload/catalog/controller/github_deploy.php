<?php
/**
 * Публичный контроллер для автодеплоя из GitHub
 * Доступен извне без авторизации в админке
 * 
 * URL: index.php?route=github_deploy/deploy
 * Метод: POST
 * Тело запроса: token=SECRET_KEY&force=0 (опционально)
 */
class ControllerGitHubDeploy extends Controller {
    
    /**
     * Endpoint для автодеплоя из GitHub
     * 
     * URL: index.php?route=github_deploy/deploy
     * Метод: POST
     * Тело запроса (form-data или x-www-form-urlencoded):
     *   token=SECRET_KEY
     *   force=1 (опционально, для принудительного деплоя)
     */
    public function deploy() {
        // Разрешаем только POST запросы
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status' => 'error',
                'message' => 'Метод не разрешен. Используйте POST запрос.',
                'previous_sha' => '',
                'current_sha' => '',
                'deployed' => false
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }
        
        // Устанавливаем заголовок для JSON-ответа
        header('Content-Type: application/json; charset=utf-8');
        
        // Отключаем вывод ошибок в production (для безопасности)
        error_reporting(0);
        ini_set('display_errors', 0);
        
        $response = [
            'status' => 'error',
            'message' => '',
            'previous_sha' => '',
            'current_sha' => '',
            'deployed' => false
        ];
        
        try {
            // Проверка секретного ключа из POST запроса
            $token = isset($this->request->post['token']) ? $this->request->post['token'] : '';
            
            // Также проверяем raw POST данные (для JSON запросов)
            if (empty($token)) {
                $rawInput = file_get_contents('php://input');
                $jsonData = json_decode($rawInput, true);
                if ($jsonData && isset($jsonData['token'])) {
                    $token = $jsonData['token'];
                } else {
                    // Пробуем парсить как form-data
                    parse_str($rawInput, $parsed);
                    if (isset($parsed['token'])) {
                        $token = $parsed['token'];
                    }
                }
            }
            
            $secretKey = $this->config->get('module_module_installer_deploy_secret_key');
            
            if (empty($secretKey)) {
                $response['message'] = 'Секретный ключ не настроен';
                http_response_code(403);
                echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;
            }
            
            if ($token !== $secretKey) {
                $response['message'] = 'Неверный секретный ключ';
                http_response_code(403);
                echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;
            }
            
            // Загружаем админскую модель настроек напрямую (в catalog контроллере она недоступна через load->model)
            // Пробуем несколько путей к админской модели
            $possiblePaths = [
                dirname(DIR_APPLICATION) . '/admin/model/setting/setting.php',
                dirname(dirname(DIR_APPLICATION)) . '/admin/model/setting/setting.php',
                DIR_SYSTEM . '../admin/model/setting/setting.php',
                dirname(DIR_SYSTEM) . '/admin/model/setting/setting.php'
            ];
            
            $adminModelPath = null;
            foreach ($possiblePaths as $path) {
                if (is_file($path)) {
                    $adminModelPath = $path;
                    break;
                }
            }
            
            if (!$adminModelPath) {
                $errorMsg = 'Не найдена модель setting/setting. Проверенные пути: ' . implode(', ', $possiblePaths);
                throw new Exception($errorMsg);
            }
            
            require_once $adminModelPath;
            
            // Проверяем, что класс существует
            if (!class_exists('ModelSettingSetting')) {
                throw new Exception('Класс ModelSettingSetting не найден после загрузки файла: ' . $adminModelPath);
            }
            
            $modelSetting = new ModelSettingSetting($this->registry);
            $this->registry->set('model_setting_setting', $modelSetting);
            
            // Проверка лимита запросов (минимум 10 секунд между вызовами)
            $this->checkRateLimit();
            
            // Получаем настройки - используем модель из registry напрямую
            $settings = $modelSetting->getSetting('module_module_installer');
            
            $githubToken = isset($settings['module_module_installer_github_token']) ? $settings['module_module_installer_github_token'] : '';
            $githubRepo = isset($settings['module_module_installer_github_repo']) ? $settings['module_module_installer_github_repo'] : '';
            $githubBranch = isset($settings['module_module_installer_github_branch']) ? $settings['module_module_installer_github_branch'] : 'main';
            $artifactName = isset($settings['module_module_installer_artifact_name']) ? $settings['module_module_installer_artifact_name'] : 'oc_telegram_shop.ocmod.zip';
            $lastSha = isset($settings['module_module_installer_last_deployed_sha']) ? $settings['module_module_installer_last_deployed_sha'] : '';
            
            if (empty($githubToken) || empty($githubRepo)) {
                $response['message'] = 'GitHub настройки не заполнены';
                http_response_code(500);
                echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;
            }
            
            // Парсим owner/repo
            if (!preg_match('/^([^\/]+)\/([^\/]+)$/', $githubRepo, $matches)) {
                $response['message'] = 'Неверный формат репозитория (должен быть owner/repo)';
                http_response_code(500);
                echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;
            }
            
            $owner = $matches[1];
            $repo = $matches[2];
            
            // Загружаем класс GitHub API
            require_once DIR_SYSTEM . 'library/github_deploy.php';
            
            $github = new GitHubDeploy($githubToken, $owner, $repo, $githubBranch);
            
            // Шаг 1: Получаем последний commit SHA
            $currentSha = $github->getLatestCommitSha();
            
            if (!$currentSha) {
                $response['message'] = 'Не удалось получить commit SHA из GitHub';
                http_response_code(500);
                echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;
            }
            
            $response['previous_sha'] = $lastSha ?: 'none';
            $response['current_sha'] = $currentSha;
            
            // Проверяем параметр force для принудительного деплоя (из POST)
            $force = false;
            if (isset($this->request->post['force'])) {
                $forceValue = $this->request->post['force'];
                $force = ($forceValue === '1' || $forceValue === 'true' || $forceValue === true);
            } else {
                // Пробуем из raw POST данных (для JSON запросов)
                $rawInput = file_get_contents('php://input');
                $jsonData = json_decode($rawInput, true);
                if ($jsonData && isset($jsonData['force'])) {
                    $force = ($jsonData['force'] === '1' || $jsonData['force'] === 'true' || $jsonData['force'] === true);
                }
            }
            
            // Шаг 2: Проверяем, совпадает ли SHA (если не force)
            if (!$force && $currentSha === $lastSha) {
                $response['status'] = 'success';
                $response['message'] = 'Already up-to-date';
                $response['deployed'] = false;
                echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;
            }
            
            // Шаг 3: Скачиваем последний artifact ZIP
            $tempDir = DIR_UPLOAD . 'tmp-github-deploy/';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0777, true);
            }
            
            try {
                // Ищем конкретный artifact по имени для этого коммита
                $artifact = $github->downloadLatestArtifact($tempDir, $currentSha, $artifactName);
            } catch (Exception $e) {
                $response['message'] = 'Ошибка при получении artifact: ' . $e->getMessage();
                http_response_code(500);
                echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;
            }
            
            if (!$artifact) {
                $response['message'] = 'Не найден ZIP artifact для скачивания';
                http_response_code(500);
                echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;
            }
            
            // Шаг 4: Устанавливаем модуль через CLI
            // Определяем путь к cli.php
            // DIR_APPLICATION в catalog указывает на catalog/, нужно подняться выше
            $cliPath = dirname(dirname(DIR_APPLICATION)) . '/cli.php';
            
            // Альтернативные пути для проверки
            if (!file_exists($cliPath)) {
                $altPath1 = dirname(DIR_APPLICATION) . '/cli.php';
                if (file_exists($altPath1)) {
                    $cliPath = $altPath1;
                } else {
                    // Если не нашли, пробуем относительно system
                    $cliPath2 = dirname(DIR_SYSTEM) . '/cli.php';
                    if (file_exists($cliPath2)) {
                        $cliPath = $cliPath2;
                    }
                }
            }
            
            if (!file_exists($cliPath)) {
                @unlink($artifact['path']);
                $response['message'] = 'CLI скрипт не найден. Проверьте наличие файла cli.php в корне OpenCart.';
                http_response_code(500);
                echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;
            }
            
            // Определяем константы, если они отсутствуют (для catalog контроллера)
            if (!defined('DIR_CATALOG')) {
                // В catalog контроллере DIR_APPLICATION уже указывает на catalog/
                // Но для совместимости определяем DIR_CATALOG как DIR_APPLICATION
                if (defined('DIR_APPLICATION') && basename(DIR_APPLICATION) === 'catalog') {
                    define('DIR_CATALOG', DIR_APPLICATION);
                } else {
                    // Альтернативный путь: относительно upload директории
                    $uploadDir = dirname(DIR_APPLICATION);
                    if (is_dir($uploadDir . '/catalog')) {
                        define('DIR_CATALOG', $uploadDir . '/catalog/');
                    } else {
                        // Если не нашли, используем DIR_APPLICATION
                        define('DIR_CATALOG', DIR_APPLICATION);
                    }
                }
            }
            
            if (!defined('DIR_IMAGE')) {
                $uploadDir = dirname(DIR_APPLICATION);
                if (is_dir($uploadDir . '/../image')) {
                    define('DIR_IMAGE', $uploadDir . '/../image/');
                } elseif (is_dir(dirname($uploadDir) . '/image')) {
                    define('DIR_IMAGE', dirname($uploadDir) . '/image/');
                } else {
                    // Fallback
                    define('DIR_IMAGE', dirname(DIR_APPLICATION) . '/../image/');
                }
            }
            
            // Загружаем админские модели, которые нужны для установщика
            $adminModelPath = dirname(DIR_APPLICATION) . '/admin/model/';
            if (!is_dir($adminModelPath)) {
                $adminModelPath = dirname(dirname(DIR_APPLICATION)) . '/admin/model/';
            }
            
            // Загружаем модели, если они есть
            $modelsToLoad = ['setting/extension', 'setting/modification', 'setting/setting'];
            foreach ($modelsToLoad as $model) {
                $modelFile = $adminModelPath . str_replace('/', DIRECTORY_SEPARATOR, $model) . '.php';
                if (is_file($modelFile)) {
                    require_once $modelFile;
                    // Преобразуем путь в имя класса: setting/extension -> ModelSettingExtension
                    $parts = explode('/', $model);
                    $className = 'Model';
                    foreach ($parts as $part) {
                        $className .= ucfirst($part);
                    }
                    if (class_exists($className)) {
                        $this->registry->set('model_' . str_replace('/', '_', $model), new $className($this->registry));
                    }
                }
            }
            
            // Загружаем класс установщика напрямую (чтобы получить список установленных файлов)
            require_once DIR_SYSTEM . 'library/module_installer.php';
            
            // Захватываем вывод для логов
            ob_start();
            $deploySuccess = false;
            $installedFiles = [];
            $logOutput = '';
            
            try {
                // Создаем экземпляр установщика
                $installer = new ModuleInstaller($this->registry, true); // verbose для подробных логов
                
                // Устанавливаем модуль
                $installer->install($artifact['path'], true); // overwrite = true
                
                // Получаем список установленных файлов
                $installedFiles = $installer->getInstalledFiles();
                
                // Получаем логи
                $logOutput = ob_get_clean();
                $logMessages = $installer->getLogMessages();
                
                // Добавляем список файлов в лог
                if (!empty($installedFiles)) {
                    $logOutput .= "\n\n=== Установленные файлы ===\n";
                    foreach ($installedFiles as $file) {
                        $logOutput .= $file . "\n";
                    }
                }
                
                $deploySuccess = true;
            } catch (Exception $e) {
                $logOutput = ob_get_clean() . "\n\nОшибка: " . $e->getMessage();
                if (!empty($installedFiles)) {
                    $logOutput .= "\n\n=== Частично установленные файлы ===\n";
                    foreach ($installedFiles as $file) {
                        $logOutput .= $file . "\n";
                    }
                }
            }
            
            // Удаляем временный файл
            @unlink($artifact['path']);
            
            if (!$deploySuccess) {
                $response['message'] = 'Ошибка при установке модуля';
                $this->saveDeployLog($currentSha, false, $logOutput, $installedFiles);
                http_response_code(500);
                echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;
            }
            
            // Шаг 5: Обновляем состояние
            $settings['module_module_installer_last_deployed_sha'] = $currentSha;
            $modelSetting->editSetting('module_module_installer', $settings);
            
            $this->saveDeployLog($currentSha, true, $logOutput, $installedFiles);
            
            $response['status'] = 'success';
            $response['message'] = 'Deployment successful';
            $response['deployed'] = true;
            
        } catch (Exception $e) {
            $response['message'] = 'Ошибка: ' . $e->getMessage();
            if (isset($currentSha)) {
                $this->saveDeployLog($currentSha, false, $e->getMessage(), isset($installedFiles) ? $installedFiles : []);
            }
            http_response_code(500);
        }
        
        // Устанавливаем HTTP статус код в зависимости от результата
        // Если статус ошибки и код ответа еще не установлен на специальные значения (403, 405, 429), устанавливаем 500
        if ($response['status'] === 'error') {
            $currentCode = http_response_code();
            // Проверяем, не установлен ли уже специальный код (403, 405, 429)
            if ($currentCode !== 403 && $currentCode !== 405 && $currentCode !== 429) {
                http_response_code(500);
            }
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * Проверка лимита запросов (минимум 10 секунд между вызовами)
     */
    private function checkRateLimit() {
        // Получаем модель из registry
        $modelSetting = $this->registry->get('model_setting_setting');
        if (!$modelSetting) {
            throw new Exception('Модель setting/setting не загружена в registry');
        }
        
        $settings = $modelSetting->getSetting('module_module_installer');
        
        $lastRequestTime = isset($settings['module_module_installer_last_request_time']) ? 
            (int)$settings['module_module_installer_last_request_time'] : 0;
        
        $currentTime = time();
        $timeSinceLastRequest = $currentTime - $lastRequestTime;
        
        if ($timeSinceLastRequest < 10) {
            $waitTime = 10 - $timeSinceLastRequest;
            http_response_code(429);
            $response = [
                'status' => 'error',
                'message' => 'Rate limit: пожалуйста, подождите ' . $waitTime . ' секунд',
                'previous_sha' => '',
                'current_sha' => '',
                'deployed' => false
            ];
            echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }
        
        // Обновляем время последнего запроса
        $settings['module_module_installer_last_request_time'] = $currentTime;
        $modelSetting->editSetting('module_module_installer', $settings);
    }
    
    /**
     * Сохранить лог деплоя
     */
    private function saveDeployLog($sha, $success, $log, $installedFiles = []) {
        // Получаем модель из registry
        $modelSetting = $this->registry->get('model_setting_setting');
        if (!$modelSetting) {
            throw new Exception('Модель setting/setting не загружена в registry');
        }
        
        $settings = $modelSetting->getSetting('module_module_installer');
        
        $settings['module_module_installer_last_deploy_log'] = json_encode([
            'timestamp' => date('Y-m-d H:i:s'),
            'sha' => $sha,
            'success' => $success,
            'log' => $log,
            'installed_files' => $installedFiles,
            'files_count' => count($installedFiles)
        ], JSON_UNESCAPED_UNICODE);
        
        $modelSetting->editSetting('module_module_installer', $settings);
    }
}

