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
        
        // URL для автодеплоя (без токена в URL - токен передается в POST)
        $deployUrl = rtrim($baseUrl, '/') . '/index.php?route=github_deploy/deploy';
        $data['deploy_url'] = $deployUrl;
        
        // Передаем секретный ключ для примеров в шаблон
        $data['deploy_secret_key'] = $data['module_module_installer_deploy_secret_key'];
        
        // URL для деплоя через админку
        // Декодируем HTML-сущности, так как Twig будет их экранировать
        $deployActionUrl = $this->url->link('extension/module/module_installer/deploy', 'user_token=' . $this->session->data['user_token'], true);
        $data['deploy_action_url'] = html_entity_decode($deployActionUrl, ENT_QUOTES, 'UTF-8');

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
     * Метод для запуска принудительного деплоя из админки
     */
    public function deploy() {
        $this->load->language('extension/module/module_installer');
        $this->load->model('setting/setting');
        
        $json = [
            'status' => 'error',
            'message' => ''
        ];
        
        if (!$this->user->hasPermission('modify', 'extension/module/module_installer')) {
            $json['message'] = $this->language->get('error_permission');
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode($json));
            return;
        }
        
        // Получаем настройки
        $settings = $this->model_setting_setting->getSetting('module_module_installer');
        $secretKey = isset($settings['module_module_installer_deploy_secret_key']) ? $settings['module_module_installer_deploy_secret_key'] : '';
        
        // Всегда пересчитываем URL, используя ту же логику, что и в методе index()
        // Это гарантирует правильный URL даже если сохраненный был неправильным
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
        
        // URL для автодеплоя (без токена в URL - токен передается в POST)
        $deployUrl = rtrim($baseUrl, '/') . '/index.php?route=github_deploy/deploy';
        
        if (empty($secretKey)) {
            $json['message'] = 'Секретный ключ не настроен. Пожалуйста, настройте его в настройках модуля.';
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode($json));
            return;
        }
        
        // Проверяем, принудительный ли деплой (из GET или POST параметра)
        $force = false;
        if (isset($this->request->get['force']) && $this->request->get['force'] == '1') {
            $force = true;
        } elseif (isset($this->request->post['force']) && ($this->request->post['force'] == '1' || $this->request->post['force'] === '1')) {
            $force = true;
        }
        
        // Выполняем запрос к endpoint деплоя
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $deployUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 минут таймаут
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'token' => $secretKey,
                'force' => $force ? '1' : '0'
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/x-www-form-urlencoded'
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                $json['message'] = 'Ошибка CURL: ' . $error;
                $json['curl_error'] = $error;
            } elseif ($httpCode !== 200) {
                // Пробуем извлечь информацию об ошибке из ответа
                $errorMessage = 'HTTP ошибка: ' . $httpCode;
                if ($response) {
                    // Пробуем распарсить как JSON
                    $responseData = @json_decode($response, true);
                    if ($responseData && isset($responseData['message'])) {
                        $errorMessage = $responseData['message'];
                        // Сохраняем все поля из ответа
                        if (isset($responseData['current_sha'])) {
                            $json['current_sha'] = $responseData['current_sha'];
                        }
                        if (isset($responseData['previous_sha'])) {
                            $json['previous_sha'] = $responseData['previous_sha'];
                        }
                    } else {
                        // Если не JSON, показываем первые 500 символов
                        $errorMessage .= '. Ответ: ' . mb_substr($response, 0, 500);
                        $json['raw_response'] = mb_substr($response, 0, 500);
                    }
                }
                $json['message'] = $errorMessage;
                $json['http_code'] = $httpCode;
            } else {
                // Успешный HTTP ответ, парсим JSON
                if (empty($response)) {
                    $json['message'] = 'Пустой ответ от сервера';
                } else {
                    $responseData = @json_decode($response, true);
                    if ($responseData === null && json_last_error() !== JSON_ERROR_NONE) {
                        // Ошибка парсинга JSON
                        $json['message'] = 'Неверный формат JSON ответа от сервера: ' . json_last_error_msg();
                        $json['json_error'] = json_last_error_msg();
                        $json['raw_response'] = mb_substr($response, 0, 1000);
                    } elseif ($responseData) {
                        // Успешный парсинг
                        $json = $responseData;
                    } else {
                        $json['message'] = 'Неверный формат ответа от сервера';
                        $json['raw_response'] = mb_substr($response, 0, 500);
                    }
                }
            }
        } else {
            $json['message'] = 'CURL extension не доступна';
        }
        
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json, JSON_UNESCAPED_UNICODE));
    }

}


