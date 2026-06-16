<?php

declare(strict_types=1);

namespace Componenta\Identity;

class ConfigProvider extends \Componenta\Config\ConfigProvider
{
    protected function getFactories(): array
    {
        return [
            UuidFactoryInterface::class => static fn(): UuidFactoryInterface => new UuidFactory(),
        ];
    }
}
