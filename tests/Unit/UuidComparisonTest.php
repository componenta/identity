<?php

declare(strict_types=1);

use Componenta\Identity\ComparableUuidInterface;
use Componenta\Identity\ComparisonResult;
use Componenta\Identity\RfcUuidInterface;
use Componenta\Identity\Tests\Fixture\TextUuid;
use Componenta\Identity\Uuid;
use Componenta\Identity\UuidInterface;
use Componenta\Identity\UuidVariant;

covers(Uuid::class, ComparisonResult::class);

it('offers ordering without requiring existing minimal UUID implementations to change', function (): void {
    $text = '018f6d5d-3f7a-7a9b-8c2f-123456789abc';
    $uuid = Uuid::fromString($text);
    $alternative = new TextUuid($text);

    expect($uuid)->toBeInstanceOf(ComparableUuidInterface::class)
        ->and($uuid)->toBeInstanceOf(UuidInterface::class)
        ->and($alternative)->toBeInstanceOf(UuidInterface::class)
        ->and($alternative)->not->toBeInstanceOf(ComparableUuidInterface::class)
        ->and($uuid->compareTo($alternative))->toBe(ComparisonResult::Equal);
});

it('uses the exact relative order of the unsigned 128-bit values', function (string $left, string $right, ComparisonResult $expected): void {
    $a = Uuid::fromString($left);
    $b = Uuid::fromString($right);

    $reverse = match ($expected) {
        ComparisonResult::Less => ComparisonResult::Greater,
        ComparisonResult::Equal => ComparisonResult::Equal,
        ComparisonResult::Greater => ComparisonResult::Less,
    };

    expect($a->compareTo($b))->toBe($expected)
        ->and($b->compareTo($a))->toBe($reverse)
        ->and($a->equals($b))->toBe($expected === ComparisonResult::Equal);
})->with([
    'equal distinct objects' => ['018f6d5d-3f7a-7a9b-8c2f-123456789abc', '018F6D5D-3F7A-7A9B-8C2F-123456789ABC', ComparisonResult::Equal],
    'Nil before Max' => ['00000000-0000-0000-0000-000000000000', 'ffffffff-ffff-ffff-ffff-ffffffffffff', ComparisonResult::Less],
    'Max after Nil' => ['ffffffff-ffff-ffff-ffff-ffffffffffff', '00000000-0000-0000-0000-000000000000', ComparisonResult::Greater],
    'last byte' => ['018f6d5d-3f7a-7a9b-8c2f-123456789abc', '018f6d5d-3f7a-7a9b-8c2f-123456789abd', ComparisonResult::Less],
    'unsigned high bit' => ['7fffffff-ffff-ffff-ffff-ffffffffffff', '80000000-0000-0000-0000-000000000000', ComparisonResult::Less],
    'version bits participate in value ordering' => ['018f6d5d-3f7a-4a9b-8c2f-123456789abc', '018f6d5d-3f7a-7a9b-8c2f-123456789abc', ComparisonResult::Less],
    'variant bits participate in value ordering' => ['00000000-0000-7000-8000-000000000000', '00000000-0000-7000-c000-000000000000', ComparisonResult::Less],
    'v1 order is not chronological across the low timestamp wrap' => ['ffffffff-0000-1000-8000-000000000000', '00000000-0001-1000-8000-000000000000', ComparisonResult::Greater],
]);

it('compares every unsigned byte at every position before considering subsequent bytes', function (int $position): void {
    $prefix = str_repeat("\xa5", $position);
    $tailLength = 15 - $position;

    for ($byte = 0; $byte < 255; ++$byte) {
        $left = Uuid::fromBytes($prefix . chr($byte) . str_repeat("\xff", $tailLength));
        $right = Uuid::fromBytes($prefix . chr($byte + 1) . str_repeat("\0", $tailLength));

        expect($left->compareTo($right))->toBe(ComparisonResult::Less)
            ->and($right->compareTo($left))->toBe(ComparisonResult::Greater);
    }
})->with(range(0, 15));

it('does not coerce numeric-looking binary UUIDs to PHP numbers', function (): void {
    // Both strings would compare as numeric zero using PHP's loose numeric
    // comparison, even though they encode different 128-bit identifiers.
    $left = Uuid::fromBytes('0e00000000000000');
    $right = Uuid::fromBytes('0e11111111111111');

    expect($left->compareTo($right))->toBe(ComparisonResult::Less)
        ->and($right->compareTo($left))->toBe(ComparisonResult::Greater)
        ->and($left->equals($right))->toBeFalse();
});

it('compares UUIDs from other implementations of the minimal contract', function (string $other, ComparisonResult $expected): void {
    $uuid = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abc');
    $operand = new TextUuid($other);

    expect($uuid->compareTo($operand))->toBe($expected)
        ->and($uuid->equals($operand))->toBe($expected === ComparisonResult::Equal);
})->with([
    'smaller' => ['00000000-0000-0000-0000-000000000000', ComparisonResult::Greater],
    'same canonical value' => ['018f6d5d-3f7a-7a9b-8c2f-123456789abc', ComparisonResult::Equal],
    'same value in uppercase for consistency with equals' => ['018F6D5D-3F7A-7A9B-8C2F-123456789ABC', ComparisonResult::Equal],
    'greater' => ['018f6d5d-3f7a-7a9b-8c2f-123456789abd', ComparisonResult::Less],
]);

it('compares other RFC implementations by their UUID value', function (): void {
    $target = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abc');
    $other = new readonly class ($target) implements RfcUuidInterface {
        public string $bytes;
        public int $version;
        public UuidVariant $variant;

        public function __construct(private Uuid $uuid)
        {
            $this->bytes = $uuid->bytes;
            $this->version = $uuid->version;
            $this->variant = $uuid->variant;
        }

        public function __toString(): string { return $this->uuid->toString(); }
        public function toString(): string { return $this->uuid->toString(); }
        public function equals(UuidInterface $other): bool { return $this->uuid->equals($other); }
    };

    expect($target->compareTo($other))->toBe(ComparisonResult::Equal)
        ->and(Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abb')->compareTo($other))->toBe(ComparisonResult::Less)
        ->and(Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abd')->compareTo($other))->toBe(ComparisonResult::Greater);
});

it('preserves reflexivity transitivity and the enum values needed by PHP sort callbacks', function (): void {
    $nil = Uuid::fromString('00000000-0000-0000-0000-000000000000');
    $middle = Uuid::fromString('00000000-0000-0000-0000-000000000080');
    $max = Uuid::fromString('ffffffff-ffff-ffff-ffff-ffffffffffff');

    expect($nil->compareTo($nil))->toBe(ComparisonResult::Equal)
        ->and($nil->compareTo($middle))->toBe(ComparisonResult::Less)
        ->and($middle->compareTo($max))->toBe(ComparisonResult::Less)
        ->and($nil->compareTo($max))->toBe(ComparisonResult::Less)
        ->and(ComparisonResult::Less->value)->toBe(-1)
        ->and(ComparisonResult::Equal->value)->toBe(0)
        ->and(ComparisonResult::Greater->value)->toBe(1);

    $values = [$max, $middle, $nil];
    usort($values, static fn (ComparableUuidInterface $a, UuidInterface $b): int => $a->compareTo($b)->value);

    expect($values)->toBe([$nil, $middle, $max]);
});

it('preserves value and ordering through native serialization', function (): void {
    $uuid = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abc');
    $restored = unserialize(serialize($uuid));

    expect($restored)->toBeInstanceOf(ComparableUuidInterface::class)
        ->and($uuid->compareTo($restored))->toBe(ComparisonResult::Equal)
        ->and($uuid->equals($restored))->toBeTrue();
});
