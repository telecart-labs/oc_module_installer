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
            // Если токен GitHub не передан или пустой, сохраняем существующий токен
            if (!isset($this->request->post['module_module_installer_github_token']) || 
                empty($this->request->post['module_module_installer_github_token'])) {
                $existingToken = $this->config->get('module_module_installer_github_token');
                if ($existingToken) {
                    $this->request->post['module_module_installer_github_token'] = $existingToken;
                }
            }
            
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

        // GitHub настройки
        if (isset($this->request->post['module_module_installer_github_repo'])) {
            $data['module_module_installer_github_repo'] = $this->request->post['module_module_installer_github_repo'];
        } else {
            $data['module_module_installer_github_repo'] = $this->config->get('module_module_installer_github_repo');
        }
        
        // GitHub токен - если не передан или пустой, оставляем старый (не перезаписываем)
        if (isset($this->request->post['module_module_installer_github_token']) && 
            !empty($this->request->post['module_module_installer_github_token'])) {
            $data['module_module_installer_github_token'] = $this->request->post['module_module_installer_github_token'];
        } else {
            $existingToken = $this->config->get('module_module_installer_github_token');
            $data['module_module_installer_github_token'] = $existingToken ? '***HIDDEN***' : '';
        }
        
        if (isset($this->request->post['module_module_installer_github_branch'])) {
            $data['module_module_installer_github_branch'] = $this->request->post['module_module_installer_github_branch'];
        } else {
            $data['module_module_installer_github_branch'] = $this->config->get('module_module_installer_github_branch') ?: 'main';
        }
        
        if (isset($this->request->post['module_module_installer_artifact_name'])) {
            $data['module_module_installer_artifact_name'] = $this->request->post['module_module_installer_artifact_name'];
        } else {
            $data['module_module_installer_artifact_name'] = $this->config->get('module_module_installer_artifact_name') ?: 'oc_telegram_shop.ocmod.zip';
        }
        
        if (isset($this->request->post['module_module_installer_deploy_secret_key'])) {
            $data['module_module_installer_deploy_secret_key'] = $this->request->post['module_module_installer_deploy_secret_key'];
        } else {
            $data['module_module_installer_deploy_secret_key'] = $this->config->get('module_module_installer_deploy_secret_key');
            // Генерируем случайный ключ по умолчанию, если его нет
            if (empty($data['module_module_installer_deploy_secret_key'])) {
                $data['module_module_installer_deploy_secret_key'] = bin2hex(random_bytes(32));
            }
        }
        
        // Последний деплойнутый SHA
        $data['module_module_installer_last_deployed_sha'] = $this->config->get('module_module_installer_last_deployed_sha');
        
        // Лог последнего деплоя
        $deployLogJson = $this->config->get('module_module_installer_last_deploy_log');
        if ($deployLogJson) {
            $deployLog = json_decode($deployLogJson, true);
            $data['module_module_installer_last_deploy_log'] = $deployLog;
        } else {
            $data['module_module_installer_last_deploy_log'] = null;
        }
        
        // Формируем URL для автодеплоя
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
        
        // URL для автодеплоя
        $deployUrl = rtrim($baseUrl, '/') . '/index.php?route=github_deploy/deploy&token=' . urlencode($data['module_module_installer_deploy_secret_key']);
        $data['deploy_url'] = $deployUrl;

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

}


