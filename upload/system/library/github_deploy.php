<?php

/**
 * Класс для работы с GitHub API при автодеплое
 */
class GitHubDeploy {
    private $token;
    private $owner;
    private $repo;
    private $branch;
    private $apiBase = 'https://api.github.com';
    
    public function __construct($token, $owner, $repo, $branch = 'main') {
        $this->token = $token;
        $this->owner = $owner;
        $this->repo = $repo;
        $this->branch = $branch;
    }
    
    /**
     * Получить последний commit SHA для указанной ветки
     * 
     * @return string|null SHA коммита или null в случае ошибки
     */
    public function getLatestCommitSha() {
        $url = $this->apiBase . '/repos/' . urlencode($this->owner) . '/' . urlencode($this->repo) . '/commits/' . urlencode($this->branch);
        
        $response = $this->makeRequest($url);
        
        if ($response && isset($response['sha'])) {
            return $response['sha'];
        }
        
        return null;
    }
    
    /**
     * Найти и скачать artifact с конкретным именем для последнего коммита в ветке
     * 
     * @param string $tempDir Временная директория для сохранения файла
     * @param string $commitSha SHA коммита
     * @param string $artifactName Имя artifact для поиска (например, "oc_telegram_shop.ocmod.zip")
     * @return array|false ['path' => путь к файлу, 'name' => имя файла] или false
     */
    public function downloadLatestArtifact($tempDir, $commitSha = null, $artifactName = 'oc_telegram_shop.ocmod.zip') {
        // Получаем последний workflow run для этого коммита в указанной ветке
        $runsUrl = $this->apiBase . '/repos/' . urlencode($this->owner) . '/' . urlencode($this->repo) . '/actions/runs?head_sha=' . urlencode($commitSha) . '&head_branch=' . urlencode($this->branch) . '&per_page=1&status=completed';
        
        $runsResponse = $this->makeRequest($runsUrl);
        
        if (!$runsResponse || !isset($runsResponse['workflow_runs']) || empty($runsResponse['workflow_runs'])) {
            throw new Exception('Не найдено workflow runs для коммита ' . substr($commitSha, 0, 7) . ' в ветке ' . $this->branch);
        }
        
        // Берем первый (последний) workflow run
        $workflowRun = $runsResponse['workflow_runs'][0];
        $runId = $workflowRun['id'];
        
        // Получаем artifacts для этого workflow run
        $artifactsUrl = $this->apiBase . '/repos/' . urlencode($this->owner) . '/' . urlencode($this->repo) . '/actions/runs/' . $runId . '/artifacts';
        $artifactsResponse = $this->makeRequest($artifactsUrl);
        
        if (!$artifactsResponse || !isset($artifactsResponse['artifacts']) || empty($artifactsResponse['artifacts'])) {
            throw new Exception('В workflow run #' . $runId . ' не найдено artifacts');
        }
        
        // Ищем artifact с нужным именем
        $targetArtifact = null;
        foreach ($artifactsResponse['artifacts'] as $artifact) {
            if (isset($artifact['name']) && $artifact['name'] === $artifactName) {
                if (isset($artifact['expired']) && $artifact['expired'] === true) {
                    throw new Exception('Artifact "' . $artifactName . '" истёк. GitHub хранит artifacts 90 дней.');
                }
                if (isset($artifact['archive_download_url'])) {
                    $targetArtifact = $artifact;
                    break;
                }
            }
        }
        
        if (!$targetArtifact) {
            $availableNames = array_map(function($a) { return $a['name'] ?? 'unknown'; }, $artifactsResponse['artifacts']);
            throw new Exception('Не найден artifact с именем "' . $artifactName . '". Доступные artifacts: ' . implode(', ', $availableNames));
        }
        
        // Скачиваем artifact (это zip-архив от GitHub)
        $artifactZip = $tempDir . '/' . uniqid('artifact_', true) . '.zip';
        try {
            $success = $this->downloadFile($targetArtifact['archive_download_url'], $artifactZip);
            
            if (!$success) {
                throw new Exception('Не удалось скачать artifact "' . $artifactName . '"');
            }
        } catch (Exception $e) {
            throw new Exception('Ошибка при скачивании artifact "' . $artifactName . '": ' . $e->getMessage());
        }
        
        // GitHub artifacts - это zip-архивы, которые могут содержать другой zip-файл с модулем
        // Распаковываем artifact и ищем внутри zip-файл с модулем
        $extractedDir = $tempDir . '/' . uniqid('extracted_', true) . '/';
        if (!is_dir($extractedDir)) {
            mkdir($extractedDir, 0777, true);
        }
        
        $zip = new ZipArchive();
        if ($zip->open($artifactZip) !== true) {
            @unlink($artifactZip);
            throw new Exception('Не удалось открыть artifact zip: ' . $artifactZip);
        }
        
        $zip->extractTo($extractedDir);
        $zip->close();
        
        // Удаляем скачанный artifact zip
        @unlink($artifactZip);
        
        // Ищем zip-файл с модулем внутри распакованного artifact
        $moduleZip = null;
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($extractedDir),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'zip') {
                $files[] = $file->getPathname();
            }
        }
        
        // Если нашли zip-файлы, берем первый (или ищем по имени artifact)
        if (!empty($files)) {
            // Сначала пытаемся найти по имени artifact
            foreach ($files as $zipFile) {
                if (basename($zipFile) === $artifactName) {
                    $moduleZip = $zipFile;
                    break;
                }
            }
            // Если не нашли по имени, берем первый zip
            if (!$moduleZip) {
                $moduleZip = $files[0];
            }
            
            // Копируем найденный zip в tempDir с уникальным именем
            $finalZip = $tempDir . '/' . uniqid('module_', true) . '.zip';
            if (!copy($moduleZip, $finalZip)) {
                $this->removeDirectory($extractedDir);
                throw new Exception('Не удалось скопировать модуль из artifact');
            }
            
            // Удаляем временную директорию с распакованным artifact
            $this->removeDirectory($extractedDir);
            
            return [
                'path' => $finalZip,
                'name' => basename($moduleZip)
            ];
        }
        
        // Если zip не найден внутри, возможно artifact сам является модулем
        // Проверяем структуру: есть ли директория upload/ в распакованном artifact
        $uploadDir = $extractedDir . 'upload/';
        if (is_dir($uploadDir)) {
            // Artifact содержит правильную структуру, создаем zip из него
            $finalZip = $tempDir . '/' . uniqid('module_', true) . '.zip';
            $this->createZipFromDirectory($extractedDir, $finalZip);
            $this->removeDirectory($extractedDir);
            
            return [
                'path' => $finalZip,
                'name' => $targetArtifact['name']
            ];
        }
        
        // Если ничего не подошло
        $this->removeDirectory($extractedDir);
        throw new Exception('Не найден zip-файл с модулем внутри artifact "' . $artifactName . '" и нет структуры upload/. Проверьте содержимое artifact.');
    }
    
    /**
     * Выполнить HTTP запрос к GitHub API
     * 
     * @param string $url URL запроса
     * @return array|false Данные ответа или false в случае ошибки
     */
    private function makeRequest($url) {
        if (!function_exists('curl_init')) {
            throw new Exception('CURL extension не доступна');
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $this->token,
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: OpenCart-GitHub-Deploy'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('Ошибка CURL: ' . $error);
        }
        
        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            $message = isset($errorData['message']) ? $errorData['message'] : 'HTTP ' . $httpCode;
            throw new Exception('GitHub API ошибка: ' . $message);
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Ошибка декодирования JSON: ' . json_last_error_msg());
        }
        
        return $data;
    }
    
    /**
     * Скачать файл с авторизацией
     * 
     * @param string $url URL для скачивания
     * @param string $destination Путь для сохранения файла
     * @return bool Успех или неудача
     */
    private function downloadFile($url, $destination) {
        if (!function_exists('curl_init')) {
            throw new Exception('CURL extension не доступна');
        }
        
        // Создаем директорию если нужно
        $dir = dirname($destination);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        
        $fp = fopen($destination, 'wb');
        
        if (!$fp) {
            throw new Exception('Не удалось создать файл для записи: ' . $destination);
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 минут на скачивание
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        // Для скачивания artifacts не нужен Accept заголовок, только авторизация
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->token,
            'User-Agent: OpenCart-GitHub-Deploy'
        ]);
        
        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errorNumber = curl_errno($ch);
        curl_close($ch);
        fclose($fp);
        
        if ($error || $errorNumber) {
            @unlink($destination);
            throw new Exception('Ошибка CURL при скачивании (код ' . $errorNumber . '): ' . $error);
        }
        
        if (!$success || $httpCode !== 200) {
            @unlink($destination);
            
            // Если файл частично скачан, читаем его для диагностики
            if (file_exists($destination) && filesize($destination) > 0) {
                $partialContent = file_get_contents($destination, false, null, 0, 500);
                @unlink($destination);
                throw new Exception('Ошибка скачивания: HTTP ' . $httpCode . '. Частично скачано: ' . substr($partialContent, 0, 100));
            }
            
            throw new Exception('Ошибка скачивания: HTTP код ' . $httpCode . ' (ожидался 200)');
        }
        
        // Проверяем, что файл действительно скачался
        if (!file_exists($destination) || filesize($destination) === 0) {
            @unlink($destination);
            throw new Exception('Файл не был скачан или пуст после скачивания');
        }
        
        return true;
    }
    
    /**
     * Рекурсивно удалить директорию
     */
    private function removeDirectory($dir) {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
    
    /**
     * Создать zip-архив из директории
     */
    private function createZipFromDirectory($sourceDir, $zipPath) {
        if (!extension_loaded('zip')) {
            throw new Exception('Расширение ZipArchive не доступно');
        }
        
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Не удалось создать zip-архив: ' . $zipPath);
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($sourceDir));
                $zip->addFile($filePath, $relativePath);
            }
        }
        
        $zip->close();
    }
}

