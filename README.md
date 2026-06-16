# Componenta Identity

UUID value objects and generators for domain identities.

## Installation

```bash
composer require componenta/identity
```

The package declares `Componenta\Identity\ConfigProvider` in `extra.componenta.config-providers`.
When `componenta/composer-plugin` is installed, the provider is added to the generated provider list automatically.

## Requirements

- PHP 8.4+
- 64-bit PHP runtime for `UuidFactory`

## Related Packages

| Package | Why it matters here |
|---|---|
| `componenta/policy` | Uses `IdentityInterface` for actors and owned resources. |
| `componenta/cqrs` | `Operation` uses UUIDs as operation identifiers. |
| `componenta/di` | Registers `UuidFactoryInterface` through `ConfigProvider`. |
| `componenta/uuid` | Legacy Ramsey compatibility; new domain code should prefer this package. |

## What It Provides

- `UuidInterface`: minimal identity contract with string conversion and equality.
- `RfcUuidInterface`: UUID contract with `bytes`, `version`, and `variant` properties.
- `Uuid`: immutable RFC UUID value object backed by 16 raw bytes.
- `UuidFactoryInterface`: generation/from-bytes contract.
- `UuidFactory`: UUID v1, v3, v4, v5, v6, v7, and v8 generator.
- `UuidNamespace`: standard namespaces for name-based UUIDs.

## UUID Value Object

```php
use Componenta\Identity\Uuid;

$uuid = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abc');

$uuid->toString(); // 018f6d5d-3f7a-7a9b-8c2f-123456789abc
$uuid->bytes;      // 16-byte binary representation
$uuid->version;    // 7
$uuid->variant;    // UuidVariant::Rfc
```

`Uuid::fromString()` accepts uppercase input and normalizes output to lowercase canonical form.

## Equality

```php
$left = Uuid::fromString($id);
$right = Uuid::fromBytes($left->bytes);

$left->equals($right); // true
```

Domain code should usually depend on `UuidInterface`. Infrastructure that needs raw bytes or version inspection can depend on `RfcUuidInterface`.

## UUID Factory

```php
use Componenta\Identity\UuidFactory;
use Componenta\Identity\UuidGenerationVersion;
use Componenta\Identity\UuidNamespace;

$factory = new UuidFactory(UuidGenerationVersion::V7);

$id = $factory->generate();              // default version
$random = $factory->v4();                // random UUID
$ordered = $factory->v7();               // Unix timestamp UUID
$nameBased = $factory->v5(UuidNamespace::Dns, 'example.com');
```

Name-based UUIDs are deterministic for the same namespace and name.

## DI Registration

`ConfigProvider` registers `UuidFactoryInterface` as a factory returning `UuidFactory`.
