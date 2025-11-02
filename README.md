# Модуль установщика модулей OpenCart (CLI)

Модуль для OpenCart 3.x и 4.x, который предоставляет CLI-интерфейс для автоматической установки других модулей из zip-архивов.

## Возможности

- Установка модулей из zip-архивов через командную строку
- Полная поддержка процесса установки через веб-интерфейс:
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

### Веб-установка через HTTP

Модуль также поддерживает установку через веб-интерфейс без доступа к SSH. Для этого используется публичный эндпоинт в catalog директории.

#### Настройка

1. Зайдите в админку OpenCart: **Extensions → Extensions → Modules → Module Installer (CLI)**
2. В поле "Секретный ключ" задайте уникальный секретный ключ (или используйте автоматически сгенерированный)
3. Сохраните настройки
4. Скопируйте URL для веб-установки из админки (или сформируйте вручную)

#### Использование

Отправьте POST-запрос на URL:
```
https://your-site.com/index.php?route=module_installer/install&token=YOUR_SECRET_KEY
```

**Параметры запроса:**
- `file` (multipart/form-data) - zip-архив модуля (обязательный)
- `overwrite` (опционально) - значение `1` для перезаписи существующих файлов
- `verbose` (опционально) - значение `1` для подробного лога в ответе

#### Примеры веб-установки

**С использованием curl:**
```bash
curl -X POST \
  -F "file=@module.zip" \
  -F "overwrite=1" \
  -F "verbose=1" \
  "https://your-site.com/index.php?route=module_installer/install&token=YOUR_SECRET_KEY"
```

**С использованием wget:**
```bash
wget --post-data="overwrite=1&verbose=1" \
  --post-file=module.zip \
  "https://your-site.com/index.php?route=module_installer/install&token=YOUR_SECRET_KEY"
```

**Ответ сервера:**
```json
{
    "success": true,
    "message": "Модуль успешно установлен",
    "log": [
        "[2024-01-15 10:30:00] Начало установки модуля из: ...",
        "[2024-01-15 10:30:01] Распаковка zip-архива...",
        ...
    ]
}
```

**В случае ошибки:**
```json
{
    "success": false,
    "message": "Ошибка при установке модуля: ...",
    "log": []
}
```

#### Безопасность веб-установки

- ✅ Эндпоинт защищен секретным ключом - без правильного ключа установка невозможна
- ✅ Проверка типа файла (только zip-архивы)
- ✅ Ограничение размера файла (максимум 50MB)
- ✅ Проверка разрешенных путей перед установкой
- ✅ Автоматический откат изменений в случае ошибки
- ⚠️ **Важно:** Храните секретный ключ в безопасности и не передавайте его в открытом виде

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
- Для веб-установки: защита секретным ключом
- Валидация структуры zip-архива
- Проверка типа файла (только zip)
- Ограничение размера файла (максимум 50MB)
- Откат изменений при ошибке

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

