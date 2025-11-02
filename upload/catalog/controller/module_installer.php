<?php
/**
 * Публичный контроллер для установки модулей через HTTP
 * Доступен извне без авторизации в админке
 * 
 * URL: index.php?route=module_installer/install&token=SECRET_KEY
 */
class ControllerModuleInstaller extends Controller {
    
    /**
     * Веб-эндпоинт для установки модулей через HTTP
     * URL: index.php?route=module_installer/install&token=SECRET_KEY
     * 
     * Принимает POST-запрос с:
     * - file: zip-архив модуля (multipart/form-data)
     * - overwrite: опционально, '1' для перезаписи существующих файлов
     * - verbose: опционально, '1' для подробного лога
     */
    public function install() {
        // Устанавливаем заголовок для JSON-ответа
        header('Content-Type: application/json; charset=utf-8');
        
        // Отключаем вывод ошибок в production (для безопасности)
        error_reporting(0);
        ini_set('display_errors', 0);
        
        $response = [
            'success' => false,
            'message' => '',
            'log' => []
        ];

        try {
            // Проверка секретного ключа
            $token = isset($this->request->get['token']) ? $this->request->get['token'] : '';
            $secretKey = $this->config->get('module_module_installer_secret_key');
            
            if (empty($secretKey)) {
                $response['message'] = 'Секретный ключ не настроен. Пожалуйста, настройте его в настройках модуля в админке.';
                echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;
            }
            
            if ($token !== $secretKey) {
                $response['message'] = 'Неверный секретный ключ';
                echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;
            }

            // Проверка наличия файла
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $errorMessage = 'Ошибка загрузки файла';
                if (isset($_FILES['file']['error'])) {
                    switch ($_FILES['file']['error']) {
                        case UPLOAD_ERR_INI_SIZE:
                        case UPLOAD_ERR_FORM_SIZE:
                            $errorMessage = 'Размер файла превышает допустимый';
                            break;
                        case UPLOAD_ERR_PARTIAL:
                            $errorMessage = 'Файл был загружен частично';
                            break;
                        case UPLOAD_ERR_NO_FILE:
                            $errorMessage = 'Файл не был загружен';
                            break;
                        case UPLOAD_ERR_NO_TMP_DIR:
                            $errorMessage = 'Отсутствует временная директория';
                            break;
                        case UPLOAD_ERR_CANT_WRITE:
                            $errorMessage = 'Ошибка записи файла на диск';
                            break;
                        case UPLOAD_ERR_EXTENSION:
                            $errorMessage = 'Загрузка файла остановлена расширением PHP';
                            break;
                    }
                }
                $response['message'] = $errorMessage . '. Убедитесь, что файл передан корректно.';
                echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;
            }

            $uploadedFile = $_FILES['file'];
            
            // Проверка типа файла
            $fileExtension = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
            if ($fileExtension !== 'zip') {
                $response['message'] = 'Поддерживаются только zip-архивы';
                echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;
            }

            // Проверка размера файла (максимум 50MB)
            if ($uploadedFile['size'] > 50 * 1024 * 1024) {
                $response['message'] = 'Размер файла превышает 50MB';
                echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;
            }

            // Перемещаем загруженный файл во временную директорию
            $tempDir = DIR_UPLOAD . 'tmp-web-install/';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0777, true);
            }
            
            $tempFile = $tempDir . uniqid('module_', true) . '.zip';
            if (!move_uploaded_file($uploadedFile['tmp_name'], $tempFile)) {
                throw new Exception('Не удалось сохранить загруженный файл');
            }

            // Получаем параметры
            $overwrite = isset($this->request->post['overwrite']) && $this->request->post['overwrite'] === '1';
            $verbose = isset($this->request->post['verbose']) && $this->request->post['verbose'] === '1';

            // Загружаем класс установщика
            require_once DIR_SYSTEM . 'library/module_installer.php';

            // Загружаем админские модели напрямую
            // В catalog контроллере DIR_APPLICATION указывает на catalog/, но нам нужны admin модели
            $adminModelPath = dirname(DIR_APPLICATION) . '/admin/model/';
            
            if (!is_dir($adminModelPath)) {
                throw new Exception('Не найдена директория админских моделей: ' . $adminModelPath);
            }
            
            require_once $adminModelPath . 'setting/extension.php';
            require_once $adminModelPath . 'setting/modification.php';
            require_once $adminModelPath . 'setting/setting.php';
            
            // Создаем экземпляры админских моделей и регистрируем их в registry
            // ModuleInstaller использует registry->get('model_setting_extension') и т.д.
            $this->registry->set('model_setting_extension', new ModelSettingExtension($this->registry));
            $this->registry->set('model_setting_modification', new ModelSettingModification($this->registry));
            $this->registry->set('model_setting_setting', new ModelSettingSetting($this->registry));

            // Создаем экземпляр установщика
            $installer = new ModuleInstaller($this->registry, $verbose);

            // Захватываем вывод для сбора логов
            ob_start();
            try {
                $installer->install($tempFile, $overwrite);
                $output = ob_get_clean();
                
                // Удаляем временный файл после успешной установки
                @unlink($tempFile);
                
                $response['success'] = true;
                $response['message'] = 'Модуль успешно установлен';
                $logLines = explode("\n", trim($output));
                $response['log'] = array_filter($logLines, function($line) {
                    return !empty(trim($line));
                });
            } catch (Exception $e) {
                ob_end_clean();
                
                // Удаляем временный файл в случае ошибки
                @unlink($tempFile);
                
                throw $e;
            }

        } catch (Exception $e) {
            $response['message'] = 'Ошибка при установке модуля: ' . $e->getMessage();
            if (isset($verbose) && $verbose) {
                $response['trace'] = $e->getTraceAsString();
            }
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

