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

`Uuid::fromString()` accepts upper-, lower-, and mixed-case hex digits and normalizes output to lowercase canonical form. For compatibility, surrounding characters removed by PHP `trim()` are accepted; validate the original input separately if a strict wire-format check is needed. Invalid input is not copied into exception messages.

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

UUIDv7 uses a randomly seeded 12-bit counter and fresh 62-bit cryptographically random data for every generated value (RFC 9562 §§ 5.7 and 6.2, method 1). The same factory preserves ordering through a repeated or decreasing millisecond; counter exhaustion advances its logical timestamp. Consequently, an embedded timestamp may be ahead of the wall clock. Out-of-range timestamps fail with `OverflowException` instead of wrapping. UUIDv1 uses a random multicast node and a randomly initialized clock sequence; a physical clock rollback advances the sequence, and copying a factory or forking the process initializes independent state. UUIDv6 refreshes its 14-bit sequence and random multicast node for each UUID (RFC 9562 § 5.6). Neither version consumes a logical timestamp on an entropy failure. A serialized factory preserves its configured default version, not a replayable generator state; old native factory snapshots can be restored as new generators.

Neither ordering across independent factories/processes nor absolute global uniqueness is guaranteed. Do not use UUIDs as bearer credentials or authorization checks. Prefer UUIDv4 if random identifiers are needed; use independent cryptographic tokens for secrets. UUIDv3/v5 are deterministic legacy name-based formats, not collision-resistant cryptographic hashes. UUIDv8 uniqueness depends on supplied custom fields. Enforce uniqueness in persistent storage when collisions must be detected. Native PHP unserialization is for trusted data only; UUID values validate their internal fields and reconstruct them by value when restored, preserving the legacy wire format without retaining references from the serialized graph.

### Choosing a UUID version

Use UUIDv7 for new time-ordered identifiers and UUIDv4 when creation time/order should not be exposed. RFC 9562 § 8 recommends UUIDv4 where UUIDs are required in a security-sensitive operation; access must still be authorized independently. Parsing a UUID does not authenticate it or detect tampering. Prefer UUIDv5 to UUIDv3 for legacy name-based compatibility; neither is suitable for adversarial collision resistance. If a protocol requires stronger name hashing, it needs a separately specified UUIDv8 scheme, not a changed UUIDv5 algorithm.

The default generator uses PHP's OS-backed `random_bytes()` and `random_int()`. Entropy failures propagate; there is no weaker random fallback. UUIDv4 has 122 random bits, UUIDv6 refreshes 61 random bits (sequence and node), and UUIDv7 refreshes 62 random bits for each fixed timestamp/counter prefix. UUIDv1 uses a randomly initialized per-generator node/sequence, which can correlate UUIDs from the same generator. The probability of a collision depends on the version, distribution of prefixes, number of independent generators and operating-system entropy. The generator is not reentrant or thread-safe; use separate instances or synchronize shared access. It does not coordinate with other processes or persist generator state.

Treat UUIDs as opaque identifiers. The timestamp in a time-based UUID is not an authoritative audit timestamp. Store the 16-byte representation in database-native UUID or binary columns where appropriate, with a unique constraint when duplicates must be detected. `Uuid::fromBytes()` and `Uuid::fromString()` represent all valid 128-bit layouts, including Nil, Max and reserved variants; accepting their representation is not an assertion that they were generated by a supported RFC algorithm. The four predefined namespace values are the RFC 9562 namespaces; arbitrary registered or private namespaces can also be supplied as UUID objects.
