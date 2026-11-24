<?php

declare(strict_types=1);

use Componenta\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\Identity\ComparableUuidInterface;
use Componenta\Identity\ComparisonResult;
use Componenta\Identity\ConfigProvider;
use Componenta\Identity\RfcUuidInterface;
use Componenta\Identity\Uuid;
use Componenta\Identity\UuidFactory;
use Componenta\Identity\UuidFactoryInterface;
use Componenta\Identity\UuidGenerationVersion;
use Componenta\Identity\UuidVariant;

require $argv[1];

function verifyConsumer(bool $valid, string $message): void
{
    if (!$valid) {
        throw new RuntimeException($message);
    }
}

final readonly class IdentityConsumer
{
    public function __construct(public UuidFactoryInterface $factory) {}
}

$composition = (new ConfigFactory())->create(new Environment([]), new ConfigProvider());
$container = (new ContainerFactory())->create($composition->config, $composition->dependencies)->container;
$factory = $container->get(UuidFactoryInterface::class);
verifyConsumer($factory instanceof UuidFactoryInterface, 'Factory provider contract');
verifyConsumer($factory === $container->get(UuidFactoryInterface::class), 'Container should share the configured factory');

$consumer = $container->get(IdentityConsumer::class);
verifyConsumer($consumer instanceof IdentityConsumer && $consumer->factory === $factory, 'DI injects the same UUID generator');

$first = $factory->generate();
$second = $consumer->factory->generate();
verifyConsumer($first instanceof RfcUuidInterface && $second instanceof RfcUuidInterface, 'Default factory returns RFC values');
verifyConsumer($first->version === 7 && $second->version === 7 && $first->variant === UuidVariant::Rfc, 'Default UUIDv7');
verifyConsumer(strcmp($second->bytes, $first->bytes) > 0 && !$first->equals($second), 'Shared generator preserves UUIDv7 ordering');
verifyConsumer($first->equals(Uuid::fromString($first->toString())), 'Canonical round trip');
verifyConsumer($first instanceof ComparableUuidInterface && $second instanceof ComparableUuidInterface, 'Standard generator supports optional UUID ordering');
verifyConsumer($first->compareTo($second) === ComparisonResult::Less && $second->compareTo($first) === ComparisonResult::Greater, 'Ordering through the public comparison contract');
verifyConsumer($first->compareTo(Uuid::fromBytes($first->bytes)) === ComparisonResult::Equal, 'Equal UUID values compare equally');

$replacement = new UuidFactory(UuidGenerationVersion::V4);
$container->set(UuidFactoryInterface::class, $replacement);
verifyConsumer($container->get(UuidFactoryInterface::class) === $replacement, 'Application can replace UUID generator');
$uuid = $replacement->generate();
verifyConsumer($uuid instanceof RfcUuidInterface && $uuid->version === 4, 'Configured replacement version');
verifyConsumer(!\Composer\InstalledVersions::isInstalled('ramsey/uuid'), 'Standalone Identity and pinned DI 6 do not require Ramsey UUID');
echo 'Production Identity + DI 6 consumer passed on PHP ', PHP_VERSION, PHP_EOL;
