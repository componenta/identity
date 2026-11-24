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
- `componenta/config ^3.0` for the shipped `ConfigProvider`

## Related Packages

| Package | Why it matters here |
|---|---|
| `componenta/policy` | Uses `IdentityInterface` for actors and owned resources. |
| `componenta/cqrs` | `Operation` uses UUIDs as operation identifiers. |
| `componenta/di` | Registers `UuidFactoryInterface` through `ConfigProvider`. |
| `componenta/uuid` | Optional legacy Ramsey compatibility. Require it explicitly if your application still uses it; Identity no longer installs it. |

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

## Generation, security and uniqueness

UUIDv7 uses a randomly seeded 12-bit counter and fresh 62-bit cryptographically random data for every generated value (RFC 9562 §§ 5.7 and 6.2, method 1). The same factory preserves ordering through a repeated or decreasing millisecond; counter exhaustion advances its logical timestamp. Consequently, an embedded timestamp may be ahead of the wall clock. Out-of-range timestamps fail with `OverflowException` instead of wrapping. UUIDv1/v6 use a random multicast node and sequence; copying a factory or forking the process initializes independent node/sequence state. A serialized factory preserves its configured default version, not a replayable generator state; old native factory snapshots can be restored as new generators.

Neither ordering across independent factories/processes nor absolute global uniqueness is guaranteed. Do not use UUIDs as bearer credentials or authorization checks. Prefer UUIDv4 if random identifiers are needed; use independent cryptographic tokens for secrets. UUIDv3/v5 are deterministic legacy name-based formats, not collision-resistant cryptographic hashes. UUIDv8 uniqueness depends on supplied custom fields. Enforce uniqueness in persistent storage when collisions must be detected. Native PHP unserialization is for trusted data only; UUID values verify their internal fields when restored.
