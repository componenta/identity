<?php

declare(strict_types=1);

use Componenta\Identity\ComparableUuidInterface;
use Componenta\Identity\IdentityInterface;
use Componenta\Identity\Tests\Fixture\TextUuid;
use Componenta\Identity\Uuid;
use Componenta\Identity\UuidInterface;

covers(Uuid::class);

it('allows an entity to expose an application-defined minimal UUID without requiring ordering', function (): void {
    $value = '018f6d5d-3f7a-7a9b-8c2f-123456789abc';
    $uuid = new TextUuid($value);
    $entity = new readonly class ($uuid) implements IdentityInterface {
        public function __construct(public UuidInterface $uuid) {}
    };

    $identityOf = static fn (IdentityInterface $entity): UuidInterface => $entity->uuid;
    $identity = $identityOf($entity);

    expect($identity)->toBe($uuid)
        ->and($identity)->not->toBeInstanceOf(ComparableUuidInterface::class)
        ->and($identity->equals(Uuid::fromString($value)))->toBeTrue();
});
