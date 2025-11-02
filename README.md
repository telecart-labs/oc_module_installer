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

### Базовая команда

```bash
php cli.php install-module /path/to/module.zip
```

### С флагами

```bash
# Перезаписать существующие файлы
php cli.php install-module /path/to/module.zip --overwrite

# Подробный лог
php cli.php install-module /path/to/module.zip --verbose

# Комбинация флагов
php cli.php install-module /path/to/module.zip --overwrite --verbose
```

### Примеры

```bash
# Установка модуля из текущей директории
php cli.php install-module ./my_module.zip

# Установка с перезаписью и подробным логом
php cli.php install-module /tmp/module.zip --overwrite --verbose
```

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
- Доступ только через CLI (проверка `php_sapi_name()`)
- Валидация структуры zip-архива
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

