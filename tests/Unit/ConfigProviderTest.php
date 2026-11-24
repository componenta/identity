<?php

declare(strict_types=1);

use Componenta\Config\ConfigKey;
use Componenta\Identity\ConfigProvider;
use Componenta\Identity\RfcUuidInterface;
use Componenta\Identity\UuidFactoryInterface;

covers(ConfigProvider::class);

it('registers a UUID factory that generates RFC version 7 identifiers by default', function (): void {
    $config = (new ConfigProvider())();
    expect($config)->toHaveKey(ConfigKey::DEPENDENCIES);
    $dependencies = $config[ConfigKey::DEPENDENCIES];
    expect($dependencies)->toBeArray()->toHaveKey(ConfigKey::FACTORIES);
    $factories = $dependencies[ConfigKey::FACTORIES];
    expect($factories)->toBeArray()->toHaveKey(UuidFactoryInterface::class);
    $factory = $factories[UuidFactoryInterface::class];
    expect(is_callable($factory))->toBeTrue();

    $service = $factory();

    expect($service)->toBeInstanceOf(UuidFactoryInterface::class);
    $uuid = $service->generate();
    expect($uuid)->toBeInstanceOf(RfcUuidInterface::class)
        ->and($uuid->version)->toBe(7);
});
