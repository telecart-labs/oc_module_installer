<?php
/**
 * Контроллер модуля для установки других модулей
 */
class ControllerExtensionModuleModuleInstaller extends Controller {
    
    private $error = [];

    public function index() {
        $this->load->language('extension/module/module_installer');

        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('module_module_installer', $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true));
        }

        $data['heading_title'] = $this->language->get('heading_title');

        $data['breadcrumbs'] = [];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
        ];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true)
        ];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/module/module_installer', 'user_token=' . $this->session->data['user_token'], true)
        ];

        if (isset($this->error['warning'])) {
            $data['error_warning'] = $this->error['warning'];
        } else {
            $data['error_warning'] = '';
        }

        if (isset($this->session->data['success'])) {
            $data['success'] = $this->session->data['success'];
            unset($this->session->data['success']);
        } else {
            $data['success'] = '';
        }

        $data['action'] = $this->url->link('extension/module/module_installer', 'user_token=' . $this->session->data['user_token'], true);
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true);

        if (isset($this->request->post['module_module_installer_status'])) {
            $data['module_module_installer_status'] = $this->request->post['module_module_installer_status'];
        } else {
            $data['module_module_installer_status'] = $this->config->get('module_module_installer_status');
        }

        if (isset($this->request->post['module_module_installer_secret_key'])) {
            $data['module_module_installer_secret_key'] = $this->request->post['module_module_installer_secret_key'];
        } else {
            $data['module_module_installer_secret_key'] = $this->config->get('module_module_installer_secret_key');
            // Генерируем случайный ключ по умолчанию, если его нет
            if (empty($data['module_module_installer_secret_key'])) {
                $data['module_module_installer_secret_key'] = bin2hex(random_bytes(32));
            }
        }
        
        // Формируем URL для веб-установки (публичный эндпоинт в catalog)
        // URL должен указывать на корень сайта, а не на админку
        // Используем HTTP_CATALOG или HTTPS_CATALOG, если они определены
        if (defined('HTTP_CATALOG')) {
            $baseUrl = HTTP_CATALOG;
        } elseif (defined('HTTPS_CATALOG') && isset($this->request->server['HTTPS']) && $this->request->server['HTTPS'] === 'on') {
            $baseUrl = HTTPS_CATALOG;
        } elseif (defined('HTTP_SERVER')) {
            // Убираем /admin из пути, если он есть
            $baseUrl = rtrim(HTTP_SERVER, '/');
            $baseUrl = preg_replace('#/admin/?$#', '', $baseUrl);
        } elseif (defined('HTTPS_SERVER')) {
            $baseUrl = rtrim(HTTPS_SERVER, '/');
            $baseUrl = preg_replace('#/admin/?$#', '', $baseUrl);
        } else {
            // Формируем URL из текущего запроса, убирая /admin
            $protocol = (!empty($this->request->server['HTTPS']) && $this->request->server['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $host = $this->request->server['HTTP_HOST'] ?? 'localhost';
            $requestUri = $this->request->server['REQUEST_URI'] ?? '/';
            // Убираем /admin из URI, если он есть
            $basePath = preg_replace('#/admin.*$#', '', $requestUri);
            $baseUrl = $protocol . $host . rtrim($basePath, '/') . '/';
        }
        $webInstallUrl = rtrim($baseUrl, '/') . '/index.php?route=module_installer/install&token=' . urlencode($data['module_module_installer_secret_key']);
        $data['web_install_url'] = $webInstallUrl;

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        // Информация о CLI
        $cliPath = rtrim(DIR_APPLICATION, '/') . '/../cli.php';
        $data['cli_path'] = $cliPath;
        $data['cli_exists'] = file_exists($cliPath);
        $data['cli_usage'] = 'php ' . $cliPath . ' install-module /path/to/module.zip [--overwrite] [--verbose]';

        $this->response->setOutput($this->load->view('extension/module/module_installer', $data));
    }

    protected function validate() {
        if (!$this->user->hasPermission('modify', 'extension/module/module_installer')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        return !$this->error;
    }

    public function install() {
        $this->load->language('extension/module/module_installer');
        
        if (!$this->user->hasPermission('modify', 'extension/module/module_installer')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        if (!$this->error) {
            $this->load->model('setting/extension');
            $this->load->model('user/user_group');

            $this->model_setting_extension->install('module', 'module_installer');

            // Добавляем права доступа
            $this->model_user_user_group->addPermission($this->user->getGroupId(), 'access', 'extension/module/module_installer');
            $this->model_user_user_group->addPermission($this->user->getGroupId(), 'modify', 'extension/module/module_installer');

            $this->session->data['success'] = $this->language->get('text_success_install');
        }

        $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true));
    }

    public function uninstall() {
        $this->load->language('extension/module/module_installer');
        
        if (!$this->user->hasPermission('modify', 'extension/module/module_installer')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        if (!$this->error) {
            $this->load->model('setting/extension');
            $this->model_setting_extension->uninstall('module', 'module_installer');
            
            $this->session->data['success'] = $this->language->get('text_success_uninstall');
        }

        $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true));
    }

    /**
     * Веб-эндпоинт для установки модулей через HTTP
     * URL: index.php?route=extension/module/module_installer/webInstall&token=SECRET_KEY
     * 
     * Принимает POST-запрос с:
     * - file: zip-архив модуля (multipart/form-data)
     * - token: секретный ключ из настроек модуля
     * - overwrite: опционально, '1' для перезаписи существующих файлов
     * - verbose: опционально, '1' для подробного лога
     */
    public function webInstall() {
        header('Content-Type: application/json; charset=utf-8');
        
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
                $response['message'] = 'Секретный ключ не настроен. Пожалуйста, настройте его в настройках модуля.';
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
                $response['message'] = 'Ошибка загрузки файла. Убедитесь, что файл передан корректно.';
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

            // Создаем экземпляр установщика
            $installer = new ModuleInstaller($this->registry, $verbose);

            // Устанавливаем callback для сбора логов
            ob_start();
            try {
                $installer->install($tempFile, $overwrite);
                $output = ob_get_clean();
                
                // Удаляем временный файл после успешной установки
                @unlink($tempFile);
                
                $response['success'] = true;
                $response['message'] = 'Модуль успешно установлен';
                $response['log'] = explode("\n", trim($output));
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

