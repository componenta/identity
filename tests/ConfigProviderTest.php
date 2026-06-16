<?php

declare(strict_types=1);

namespace Componenta\Identity\Tests;

use Componenta\Config\ConfigKey;
use Componenta\Identity\ConfigProvider;
use Componenta\Identity\UuidFactoryInterface;
use PHPUnit\Framework\TestCase;

final class ConfigProviderTest extends TestCase
{
    public function testRegistersUuidFactoryContract(): void
    {
        $config = (new ConfigProvider())();
        $factory = $config[ConfigKey::DEPENDENCIES][ConfigKey::FACTORIES][UuidFactoryInterface::class];

        self::assertInstanceOf(UuidFactoryInterface::class, $factory());
    }
}
