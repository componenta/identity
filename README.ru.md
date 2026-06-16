# Componenta Identity

UUID value objects и генераторы для доменных идентификаторов.

## Установка

```bash
composer require componenta/identity
```

Пакет объявляет `Componenta\Identity\ConfigProvider` в `extra.componenta.config-providers`.
Если установлен `componenta/composer-plugin`, провайдер автоматически добавляется в сгенерированный список провайдеров.

## Требования

- PHP 8.4+
- 64-bit PHP для `UuidFactory`

## Связанные пакеты

| Пакет | Зачем нужен здесь |
|---|---|
| `componenta/policy` | Использует `IdentityInterface` для акторов и владельцев ресурсов. |
| `componenta/cqrs` | `Operation` генерирует UUID для идентификатора операции. |
| `componenta/di` | Регистрирует `UuidFactoryInterface` через `ConfigProvider`. |
| `componenta/uuid` | Старый compatibility-провайдер Ramsey UUID; для нового доменного кода используйте этот пакет. |

## Что предоставляет пакет

- `UuidInterface`: минимальный контракт identity со string conversion и equality.
- `RfcUuidInterface`: UUID-контракт со свойствами `bytes`, `version`, `variant`.
- `Uuid`: иммутабельный RFC UUID-объект значения на базе 16 сырых байт.
- `UuidFactoryInterface`: контракт генерации и создания из bytes.
- `UuidFactory`: генератор UUID v1, v3, v4, v5, v6, v7 и v8.
- `UuidNamespace`: стандартные namespaces для name-based UUID.

## UUID Value Object

```php
use Componenta\Identity\Uuid;

$uuid = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abc');

$uuid->toString(); // 018f6d5d-3f7a-7a9b-8c2f-123456789abc
$uuid->bytes;      // 16-byte binary representation
$uuid->version;    // 7
$uuid->variant;    // UuidVariant::Rfc
```

`Uuid::fromString()` принимает uppercase input и нормализует output в lowercase canonical form.

## Equality

```php
$left = Uuid::fromString($id);
$right = Uuid::fromBytes($left->bytes);

$left->equals($right); // true
```

Доменный код обычно должен зависеть от `UuidInterface`. Инфраструктура, которой нужны raw bytes или inspection версии, может зависеть от `RfcUuidInterface`.

## UUID Factory

```php
use Componenta\Identity\UuidFactory;
use Componenta\Identity\UuidGenerationVersion;
use Componenta\Identity\UuidNamespace;

$factory = new UuidFactory(UuidGenerationVersion::V7);

$id = $factory->generate();              // версия по умолчанию
$random = $factory->v4();                // случайный UUID
$ordered = $factory->v7();               // UUID с Unix timestamp
$nameBased = $factory->v5(UuidNamespace::Dns, 'example.com');
```

Name-based UUIDs детерминированы для одного namespace и name.

## DI-регистрация

`ConfigProvider` регистрирует `UuidFactoryInterface` через фабрику, возвращающую `UuidFactory`.
