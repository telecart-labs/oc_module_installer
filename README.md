# Модуль установщика модулей OpenCart (CLI)

Модуль для OpenCart 3.x и 4.x, который предоставляет CLI-интерфейс для автоматической установки других модулей из zip-архивов.

## Возможности

- Установка модулей из zip-архивов через командную строку
- Автодеплой из приватного GitHub-репозитория через защищенный endpoint
- Полная поддержка процесса установки:
  - Распаковка файлов в нужные директории
  - Добавление записей в таблицу `extension` (или `oc_extension`)
  - Добавление настроек по умолчанию в таблицу `setting`
  - Применение OCMOD модификаций
  - Очистка кэша модификаций и тем
- Безопасная установка с проверкой разрешенных путей
- Подробное логирование всех действий
- Откат изменений в случае ошибки
- Поддержка флагов `--overwrite` и `--verbose`

## Установка

### Ручная установка через SSH

1. **Подключитесь к серверу по SSH:**
   ```bash
   ssh user@your-server.com
   ```

2. **Перейдите в директорию, где установлен OpenCart** (директория с папкой `upload`):
   ```bash
   cd /path/to/opencart
   ```

3. **Скачайте последний zip-архив модуля из GitHub:**
   ```bash
   wget https://github.com/telecart-labs/oc_module_installer/archive/master.zip
   ```
   
   Или используя `curl`:
   ```bash
   curl -L -o master.zip https://github.com/telecart-labs/oc_module_installer/archive/master.zip
   ```

4. **Распакуйте архив:**
   ```bash
   unzip master.zip
   ```

5. **Скопируйте всё содержимое папки `upload` из распакованного архива в директорию `upload` OpenCart** (существующие файлы будут перезаписаны):
   ```bash
   cp -a oc_module_installer-master/upload/. upload/
   ```
   
   Или используя `rsync` (если доступен):
   ```bash
   rsync -av oc_module_installer-master/upload/ upload/
   ```
   
   Эта команда скопирует все файлы и папки (включая скрытые) из `oc_module_installer-master/upload/` в `upload/` OpenCart, перезаписывая существующие файлы.

6. **Удалите временные файлы:**
   ```bash
   rm -rf oc_module_installer-master master.zip
   ```

7. **Проверьте права доступа:**
   ```bash
   chmod 755 upload/cli.php
   ```

После установки CLI-скрипт будет доступен по пути: `upload/cli.php`

## Использование

### CLI (Командная строка)

#### Базовая команда

```bash
php cli.php install-module /path/to/module.zip
```

#### С флагами

```bash
# Перезаписать существующие файлы
php cli.php install-module /path/to/module.zip --overwrite

# Подробный лог
php cli.php install-module /path/to/module.zip --verbose

# Комбинация флагов
php cli.php install-module /path/to/module.zip --overwrite --verbose
```

#### Примеры CLI

```bash
# Установка модуля из текущей директории
php cli.php install-module ./my_module.zip

# Установка с перезаписью и подробным логом
php cli.php install-module /tmp/module.zip --overwrite --verbose
```

### Автодеплой из GitHub

Модуль поддерживает автоматический деплой из приватного GitHub-репозитория через защищенный endpoint. Система проверяет последний commit SHA указанной ветки и автоматически скачивает и устанавливает последний artifact, если найден новый коммит.

#### Создание Personal Access Token (PAT)

Для работы автодеплоя необходимо создать Personal Access Token в GitHub:

1. **Перейдите в настройки GitHub:**
   - Откройте GitHub → **Settings** → **Developer settings** → **Personal access tokens** → **Tokens (classic)**
   - Или перейдите по прямой ссылке: https://github.com/settings/tokens

2. **Создайте новый токен:**
   - Нажмите **Generate new token** → **Generate new token (classic)**
   - Заполните поля:
     - **Note**: `OpenCart Auto-Deploy` (или любое другое описание)
     - **Expiration**: выберите срок действия (или `No expiration` для бессрочного)
   
3. **Настройте права доступа (Scopes):**
   
   **Для приватных репозиториев:**
   - ✅ Отметьте `repo` (Full control of private repositories)
     - Это включает доступ к репозиторию, artifacts и всем необходимым данным
   
   **Для публичных репозиториев:**
   - ✅ `public_repo` (если доступен) или `repo`
   
   **Важно:** Доступ к artifacts входит в scope `repo`, поэтому для приватных репозиториев обязательно нужен полный доступ `repo`.

4. **Создайте и скопируйте токен:**
   - Нажмите **Generate token**
   - ⚠️ **Важно:** Токен показывается только один раз! Скопируйте его сразу
   - Если токен потерян, нужно создать новый

#### Настройка модуля

1. Зайдите в админку OpenCart: **Extensions → Extensions → Modules → Module Installer (CLI)**

2. Заполните настройки GitHub:
   - **GitHub репозиторий**: формат `owner/repo` (например, `telecart-labs/oc_module_installer`)
   - **GitHub API Token**: вставьте созданный Personal Access Token
   - **Ветка для проверки**: название ветки (например, `main`, `master`, `develop`)
   - **Секретный ключ (автодеплой)**: секретный ключ для защиты endpoint (генерируется автоматически)

3. Сохраните настройки

4. Скопируйте URL для автодеплоя из админки

#### Использование

Отправьте GET или POST запрос на endpoint:
```
https://your-site.com/index.php?route=github_deploy/deploy&token=YOUR_DEPLOY_SECRET_KEY
```

**Пример с curl:**
```bash
curl "https://your-site.com/index.php?route=github_deploy/deploy&token=YOUR_DEPLOY_SECRET_KEY"
```

**Пример с GitHub Actions:**
```yaml
- name: Trigger deployment
  run: |
    curl "https://your-site.com/index.php?route=github_deploy/deploy&token=${{ secrets.DEPLOY_SECRET_KEY }}"
```

#### Ответ сервера

**Успешный деплой:**
```json
{
    "status": "success",
    "message": "Deployment successful",
    "previous_sha": "abc123...",
    "current_sha": "def456...",
    "deployed": true
}
```

**Уже актуальная версия:**
```json
{
    "status": "success",
    "message": "Already up-to-date",
    "previous_sha": "abc123...",
    "current_sha": "abc123...",
    "deployed": false
}
```

**Ошибка:**
```json
{
    "status": "error",
    "message": "Ошибка: ...",
    "previous_sha": "...",
    "current_sha": "...",
    "deployed": false
}
```

#### Как это работает

1. **Проверка commit SHA**: Система запрашивает последний commit SHA указанной ветки через GitHub API
2. **Сравнение**: Если SHA совпадает с последним деплойнутым - возвращается "Already up-to-date"
3. **Скачивание artifact**: Если найден новый коммит - скачивается последний ZIP artifact из GitHub Actions
4. **Установка**: Установка модуля через CLI (`cli.php install-module`)
5. **Обновление состояния**: Сохранение нового SHA и лога деплоя

#### Безопасность автодеплоя

- ✅ Endpoint защищен секретным ключом - без правильного ключа деплой невозможен
- ✅ Лимит запросов - минимум 10 секунд между вызовами (защита от DDoS)
- ✅ GitHub токен не логируется и хранится безопасно
- ✅ Проверка разрешенных путей перед установкой
- ✅ Автоматический откат изменений в случае ошибки
- ⚠️ **Важно:** 
  - Храните секретный ключ деплоя в безопасности
  - Не передавайте токен и ключ в открытом виде
  - Используйте переменные окружения в CI/CD системах

## Структура zip-архива модуля

Модуль должен быть упакован в следующем формате:

```
module.zip
└── upload/
    ├── admin/
    │   ├── controller/
    │   ├── language/
    │   └── view/
    ├── catalog/
    │   ├── controller/
    │   ├── language/
    │   └── view/
    ├── system/
    │   └── library/
    └── install.xml (опционально)
```

## Процесс установки

Модуль выполняет следующие шаги:

1. **Распаковка zip-архива** во временную директорию
2. **Проверка безопасности** путей файлов (разрешенные директории)
3. **Перемещение файлов** из `upload/` в соответствующие директории OpenCart
4. **Обработка install.xml**:
   - Добавление OCMOD модификации в базу данных
   - Определение типа и кода модуля для таблицы `extension`
   - Обработка настроек по умолчанию
5. **Применение OCMOD модификаций**:
   - Очистка старых модификаций
   - Применение всех активных модификаций
   - Сохранение модифицированных файлов в `/system/storage/modification/`
6. **Очистка кэша**:
   - Кэш модификаций
   - Кэш тем
7. **Очистка временных файлов**

## Безопасность

- Проверка разрешенных путей перед копированием файлов
- Для CLI: доступ только через командную строку (проверка `php_sapi_name()`)
- Для автодеплоя: защита секретным ключом + лимит запросов
- GitHub токен не логируется и хранится безопасно
- Валидация структуры zip-архива
- Проверка типа файла (только zip)
- Автоматический откат изменений при ошибке

## Разрешенные директории

Модуль позволяет устанавливать файлы только в следующие директории:

- `admin/controller/extension/`
- `admin/language/`
- `admin/model/extension/`
- `admin/view/image/`
- `admin/view/javascript/`
- `admin/view/stylesheet/`
- `admin/view/template/extension/`
- `catalog/controller/extension/`
- `catalog/language/`
- `catalog/model/extension/`
- `catalog/view/javascript/`
- `catalog/view/theme/`
- `system/config/`
- `system/library/`
- `image/catalog/`

## Логирование

При использовании флага `--verbose` модуль выводит подробную информацию о каждом шаге установки:

```
[2024-01-15 10:30:00] Начало установки модуля из: /path/to/module.zip
[2024-01-15 10:30:00] Распаковка zip-архива...
[2024-01-15 10:30:01] Архив распакован в: /path/to/storage/upload/tmp-install_xxx/
[2024-01-15 10:30:01] Перемещение файлов...
[2024-01-15 10:30:02] Создана запись extension_install_id: 123
[2024-01-15 10:30:02] Скопирован файл: /path/to/admin/controller/extension/module/my_module.php
...
[2024-01-15 10:30:05] Модуль успешно установлен!
```

## Обработка ошибок

В случае ошибки модуль автоматически откатывает все изменения:

- Удаляет скопированные файлы
- Удаляет созданные директории
- Удаляет записи из базы данных (`extension_install`, `modification`, `extension_path`)
- Выводит сообщение об ошибке с трассировкой (если используется `--verbose`)

## Совместимость

- OpenCart 3.x
- OpenCart 4.x (ocStore)
- PHP 7.0+

## Требования

- PHP с расширением ZipArchive
- Права на запись в директории OpenCart
- Доступ к базе данных

## Лицензия

Разработано для внутреннего использования.

