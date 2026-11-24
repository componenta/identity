<?php

declare(strict_types=1);

use Componenta\Identity\RfcUuidInterface;
use Componenta\Identity\Uuid;
use Componenta\Identity\UuidFactory;
use Componenta\Identity\UuidGenerationVersion;
use Componenta\Identity\UuidInterface;
use Componenta\Identity\UuidVariant;

covers(Uuid::class);

it('parses canonical text into the expected RFC fields and network-order bytes', function (): void {
    $uuid = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abc');

    expect($uuid)->toBeInstanceOf(RfcUuidInterface::class)
        ->and($uuid->toString())->toBe('018f6d5d-3f7a-7a9b-8c2f-123456789abc')
        ->and($uuid->bytes)->toBe(hex2bin('018f6d5d3f7a7a9b8c2f123456789abc'))
        ->and($uuid->version)->toBe(7)
        ->and($uuid->variant)->toBe(UuidVariant::Rfc);
});

it('normalizes accepted text and serializes the canonical lowercase UUID', function (string $text): void {
    $uuid = Uuid::fromString($text);

    expect((string) $uuid)->toBe('018f6d5d-3f7a-7a9b-8c2f-123456789abc')
        ->and(json_encode($uuid, JSON_THROW_ON_ERROR))->toBe('"018f6d5d-3f7a-7a9b-8c2f-123456789abc"');
})->with([
    'uppercase' => ['018F6D5D-3F7A-7A9B-8C2F-123456789ABC'],
    'mixed case and surrounding trim characters' => [" \t018F6d5D-3f7A-7a9B-8c2F-123456789aBc\r\n"],
]);

it('compares UUID values rather than object identity', function (): void {
    $uuid = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abc');
    $same = Uuid::fromBytes($uuid->bytes);
    $other = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abd');

    expect($uuid)->not->toBe($same)
        ->and($uuid->equals($same))->toBeTrue()
        ->and($uuid->equals($other))->toBeFalse()
        ->and($other->equals($uuid))->toBeFalse();
});

it('accepts an alternative implementation of the minimal UUID contract in value equality', function (): void {
    $uuid = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abc');
    $other = new readonly class ('018f6d5d-3f7a-7a9b-8c2f-123456789abc') implements UuidInterface {
        public function __construct(private string $text) {}
        public function __toString(): string { return $this->text; }
        public function toString(): string { return $this->text; }
        public function equals(UuidInterface $other): bool
        {
            return $this->text === $other->toString();
        }
    };

    expect($uuid->equals($other))->toBeTrue()
        ->and($other->equals($uuid))->toBeTrue()
        ->and(Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abd')->equals($other))->toBeFalse();
});

it('rejects invalid UUID text', function (string $text): void {
    expect(fn () => Uuid::fromString($text))->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => [''],
    'arbitrary text' => ['not-a-uuid'],
    'missing dashes' => ['018f6d5d3f7a7a9b8c2f123456789abc'],
    'braced' => ['{018f6d5d-3f7a-7a9b-8c2f-123456789abc}'],
    'URN' => ['urn:uuid:018f6d5d-3f7a-7a9b-8c2f-123456789abc'],
    'non-hex digit' => ['018f6d5d-3f7a-7a9b-8c2f-123456789abg'],
    'internal NUL' => ["018f6d5d-3f7a-7a9b-8c2f-123456789ab\0"],
    'extra hex digit' => ['018f6d5d-3f7a-7a9b-8c2f-123456789abcd'],
]);

it('rejects binary values other than exactly 16 octets', function (int $length): void {
    expect(fn () => Uuid::fromBytes(str_repeat("\0", $length)))->toThrow(InvalidArgumentException::class);
})->with([0, 5, 15, 17, 32]);

it('represents Nil, Max and reserved bit patterns without inventing a supported version', function (string $text, UuidVariant $variant, int $version): void {
    $uuid = Uuid::fromString($text);

    expect($uuid->toString())->toBe($text)
        ->and($uuid->variant)->toBe($variant)
        ->and($uuid->version)->toBe($version)
        ->and(Uuid::fromBytes($uuid->bytes)->toString())->toBe($text);
})->with([
    'Nil' => ['00000000-0000-0000-0000-000000000000', UuidVariant::Ncs, 0],
    'Max' => ['ffffffff-ffff-ffff-ffff-ffffffffffff', UuidVariant::Future, 15],
    'Microsoft' => ['00000000-0000-7000-c000-000000000000', UuidVariant::Microsoft, 7],
    'unassigned RFC version' => ['00000000-0000-f000-b000-000000000000', UuidVariant::Rfc, 15],
]);

it('preserves every variant octet and version nibble in the 128-bit representation', function (): void {
    for ($octet = 0; $octet < 256; ++$octet) {
        $expected = match (true) {
            $octet < 128 => UuidVariant::Ncs,
            $octet < 192 => UuidVariant::Rfc,
            $octet < 224 => UuidVariant::Microsoft,
            default => UuidVariant::Future,
        };
        for ($nibble = 0; $nibble < 16; ++$nibble) {
            $bytes = "\x11\x22\x33\x44\x55\x66" . chr(($nibble << 4) | 0x0a)
                . "\xbb" . chr($octet) . "\x99\x88\x77\x66\x55\x44\x33";
            $uuid = Uuid::fromBytes($bytes);

            expect($uuid->version)->toBe($nibble)
                ->and($uuid->variant)->toBe($expected)
                ->and(Uuid::fromString($uuid->toString())->bytes)->toBe($bytes);
        }
    }
});

it('does not allow the public UUID fields to be reassigned', function (): void {
    $uuid = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abc');

    expect(fn () => $uuid->bytes = str_repeat("\0", 16))->toThrow(Error::class);
    expect(fn () => $uuid->version = 4)->toThrow(Error::class);
    expect(fn () => $uuid->variant = UuidVariant::Ncs)->toThrow(Error::class);
    expect($uuid->toString())->toBe('018f6d5d-3f7a-7a9b-8c2f-123456789abc');
});

it('round trips valid native serialized values', function (): void {
    $uuid = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abc');

    $restored = unserialize(serialize($uuid));

    expect($restored)->toBeInstanceOf(Uuid::class)
        ->and($uuid->equals($restored))->toBeTrue()
        ->and($restored->bytes)->toBe($uuid->bytes)
        ->and($restored->version)->toBe($uuid->version)
        ->and($restored->variant)->toBe($uuid->variant);
});

it('retains the legacy native serialization format', function (): void {
    $uuid = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abc');
    $legacy = 'O:' . strlen(Uuid::class) . ':"' . Uuid::class . '":3:{'
        . serialize('bytes') . serialize(hex2bin('018f6d5d3f7a7a9b8c2f123456789abc'))
        . serialize('version') . serialize(7)
        . serialize('variant') . serialize(UuidVariant::Rfc) . '}';

    expect(serialize($uuid))->toBe($legacy)
        ->and($uuid->equals(unserialize($legacy)))->toBeTrue();
});

it('rejects inconsistent serialized fields', function (string $field, mixed $replacement): void {
    $uuid = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abc');
    $fields = ['bytes' => $uuid->bytes, 'version' => 7, 'variant' => UuidVariant::Rfc];
    $fields[$field] = $replacement;
    $payload = 'O:' . strlen(Uuid::class) . ':"' . Uuid::class . '":3:{';
    foreach ($fields as $name => $value) {
        $payload .= serialize($name) . serialize($value);
    }
    $payload .= '}';

    expect(fn () => unserialize($payload))->toThrow(InvalidArgumentException::class);
})->with([
    'version mismatch' => ['version', 4],
    'short bytes' => ['bytes', 'short'],
    'variant mismatch' => ['variant', UuidVariant::Ncs],
    'invalid byte type' => ['bytes', 123],
    'invalid version type' => ['version', '7'],
]);

it('rejects an incomplete serialized UUID', function (): void {
    $class = Uuid::class;
    $payload = 'O:' . strlen($class) . ':"' . $class . '":0:{}';

    expect(fn () => unserialize($payload))->toThrow(InvalidArgumentException::class);
});

it('does not retain aliases to readonly fields in a restored serialized graph', function (): void {
    $uuid = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abc');
    $payload = 'a:4:{i:0;' . serialize($uuid) . 'i:1;R:3;i:2;R:4;i:3;R:5;}';
    $graph = unserialize($payload);

    expect($graph[0])->toBeInstanceOf(Uuid::class)
        ->and($graph[1])->toBe($uuid->bytes)
        ->and($graph[2])->toBe(7)
        ->and($graph[3])->toBe(UuidVariant::Rfc);

    $graph[1] = 'short';
    $graph[2] = 4;
    $graph[3] = UuidVariant::Ncs;

    expect($graph[0]->bytes)->toBe($uuid->bytes)
        ->and($graph[0]->version)->toBe(7)
        ->and($graph[0]->variant)->toBe(UuidVariant::Rfc)
        ->and($uuid->equals($graph[0]))->toBeTrue();
});

it('cannot restore different state into an initialized UUID', function (): void {
    $uuid = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abc');
    $replacement = Uuid::fromBytes(str_repeat("\0", 16));

    expect(fn () => $uuid->__unserialize($replacement->__serialize()))->toThrow(Error::class);
    expect($uuid->toString())->toBe('018f6d5d-3f7a-7a9b-8c2f-123456789abc');
});

it('does not reflect an arbitrarily long malformed value in an exception message', function (): void {
    $payload = "confidential-value\r\n" . str_repeat('x', 100000);

    try {
        Uuid::fromString($payload);
        test()->fail('Invalid UUID accepted.');
    } catch (InvalidArgumentException $exception) {
        expect(strlen($exception->getMessage()))->toBeLessThan(128)
            ->and($exception->getMessage())->not->toContain('confidential-value')
            ->and($exception->getMessage())->not->toContain("\r")
            ->and($exception->getMessage())->not->toContain("\n");
    }
});

it('redacts the input in library exception trace frames when argument capture is enabled', function (string $entry): void {
    $ignoreArgs = ini_get('zend.exception_ignore_args');
    $maxLength = ini_get('zend.exception_string_param_max_len');
    $invalid = 'private-invalid-uuid-value';
    $uuid = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abc');
    $factory = new UuidFactory();

    try {
        expect(ini_set('zend.exception_ignore_args', '0'))->not->toBeFalse()
            ->and(ini_set('zend.exception_string_param_max_len', '64'))->not->toBeFalse();
        try {
            match ($entry) {
                'text' => Uuid::fromString($invalid),
                'bytes' => Uuid::fromBytes($invalid),
                'factory bytes' => $factory->fromBytes($invalid),
                'restore UUID' => $uuid->__unserialize(['bytes' => $invalid]),
                'restore factory' => $factory->__unserialize(['defaultVersion' => $invalid]),
            };
            test()->fail('Malformed UUID input accepted.');
        } catch (InvalidArgumentException $exception) {
            $frames = array_filter($exception->getTrace(), static fn (array $frame): bool =>
                in_array($frame['class'] ?? null, [Uuid::class, UuidFactory::class], true));
            expect($frames)->not->toBeEmpty();
            foreach ($frames as $frame) {
                expect($frame['args'][0] ?? null)->toBeInstanceOf(SensitiveParameterValue::class);
            }
            expect($exception->getMessage())->not->toContain($invalid)
                ->and($exception->getTraceAsString())->not->toContain($invalid);
        }
    } finally {
        if ($ignoreArgs !== false) {
            ini_set('zend.exception_ignore_args', $ignoreArgs);
        }
        if ($maxLength !== false) {
            ini_set('zend.exception_string_param_max_len', $maxLength);
        }
    }
})->with(['text', 'bytes', 'factory bytes', 'restore UUID', 'restore factory']);

it('round trips every raw version nibble through native serialization', function (int $version): void {
    $bytes = str_repeat("\0", 6) . chr(($version << 4) | 0x0f) . "\x00\x80" . str_repeat("\0", 7);
    $uuid = Uuid::fromBytes($bytes);
    $restored = unserialize(serialize($uuid));

    expect($restored)->toBeInstanceOf(Uuid::class)
        ->and($restored->bytes)->toBe($bytes)
        ->and($restored->version)->toBe($version);
})->with(range(0, 15));

it('preserves the public variant enum backing values', function (UuidVariant $variant, int $value): void {
    expect($variant->value)->toBe($value)
        ->and(UuidVariant::from($value))->toBe($variant);
})->with([
    [UuidVariant::Ncs, 0],
    [UuidVariant::Rfc, 1],
    [UuidVariant::Microsoft, 2],
    [UuidVariant::Future, 3],
]);
