# Предложения по рефакторингу

## Текущая проблема

Логика установки модулей дублируется между:
- `ControllerMarketplaceInstall` (веб-интерфейс)
- `ModuleInstaller` (CLI)

## Решение 1: Использование рефлексии для вызова оригинальных методов

Можно создать адаптер, который вызывает оригинальные методы контроллера через рефлексию:

```php
private function applyModificationsViaController() {
    $loader = $this->registry->get('load');
    
    // Создаем минимальные зависимости для контроллера
    $this->registry->set('user', new class {
        public function hasPermission($action, $route) { return true; }
    });
    
    $this->registry->set('session', new class {
        public $data = [];
    });
    
    $loader->controller('marketplace/modification');
    $controller = $this->registry->get('controller_marketplace_modification');
    
    // Вызываем через рефлексию, обходя validate()
    $reflection = new ReflectionClass($controller);
    $method = $reflection->getMethod('refresh');
    $method->setAccessible(true);
    
    try {
        $method->invoke($controller, []);
        return true;
    } catch (Exception $e) {
        // Fallback на нашу реализацию
        return false;
    }
}
```

## Решение 2: Вынести логику в отдельный сервисный класс

Создать `system/library/modification_applier.php`:

```php
class ModificationApplier {
    private $registry;
    
    public function refresh() {
        // Вся логика из ControllerMarketplaceInstall::refresh()
        // но без зависимости от User, Session и т.д.
    }
}
```

И использовать его и в контроллере, и в CLI.

## Решение 3: Использовать существующий механизм через API

Если OpenCart имеет REST API для установки модулей, можно использовать его.

## Текущее решение (компромисс)

Использованы:
- ✅ Модели (`ModelSettingExtension`, `ModelSettingModification`)
- ❌ Дублируется логика применения OCMOD (из-за сложности вызова через рефлексию)
- ✅ Независимость от веб-контекста (главное преимущество)

## Рекомендация

Для продакшена лучше реализовать Решение 2 - вынести общую логику в сервисный класс, который может использоваться как из контроллера, так и из CLI.

