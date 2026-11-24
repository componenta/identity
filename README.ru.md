# Componenta Identity

[English](README.md)

Неизменяемые объекты-значения UUID, генерация UUID и контракты идентификации доменных сущностей на PHP. Поддерживаются версии 1, 3, 4, 5, 6, 7 и 8 по [RFC 9562](https://www.rfc-editor.org/rfc/rfc9562.html). По умолчанию генерируется UUIDv7.

## Установка

~~~bash
composer require componenta/identity
~~~

Требования:

- PHP 8.4 или новее в пределах ветки 8.x.
- 64-разрядная среда PHP для генерации UUID.
- `componenta/config ^3.0`, устанавливается автоматически через Composer.

Пакет не требует Ramsey UUID.

## Быстрый старт

~~~php
use Componenta\Identity\Uuid;
use Componenta\Identity\UuidFactory;

$factory = new UuidFactory();
$id = $factory->generate();

$restored = Uuid::fromString($id->toString());
$restored->equals($id); // true
~~~

## Публичный API

| Тип | Назначение |
| --- | --- |
| `IdentityInterface` | Постоянный идентификатор доменной сущности на основе UUID. |
| `UuidInterface` | Минимальный контракт UUID: каноническое представление и равенство значений. |
| `RfcUuidInterface` | Расширяет минимальный контракт свойствами байтов, версии и варианта. |
| `ComparableUuidInterface` | Расширяет минимальный контракт необязательным упорядоченным сравнением. |
| `Uuid` | Неизменяемая реализация обоих расширенных контрактов UUID с поддержкой сериализации в JSON. |
| `ComparisonResult` | Результат сравнения двух значений UUID. |
| `UuidFactoryInterface` | Общий контракт генерации UUID и восстановления из байтов. |
| `UuidFactory` | Стандартная фабрика с отдельными методами для каждой поддерживаемой версии. |
| `UuidGenerationVersion` | Версии, для генерации которых не нужны дополнительные аргументы. |
| `UuidNamespace` | Четыре предопределённых пространства имён для UUID на основе имени. |
| `UuidVariant` | Категории битовой структуры UUID. |
| `ConfigProvider` | Регистрация стандартной фабрики для внедрения зависимостей. |

## Значения UUID

~~~php
use Componenta\Identity\Uuid;

$uuid = Uuid::fromString('018F6D5D-3F7A-7A9B-8C2F-123456789ABC');

$uuid->toString(); // 018f6d5d-3f7a-7a9b-8c2f-123456789abc
(string) $uuid;    // 018f6d5d-3f7a-7a9b-8c2f-123456789abc
$uuid->version;    // 7
$uuid->variant;    // UuidVariant::Rfc

$copy = Uuid::fromBytes($uuid->bytes);
$uuid->equals($copy); // true
~~~

`Uuid::fromString(string $uuid): Uuid` принимает шестнадцатеричное представление формата 8-4-4-4-12 в любом регистре. Результат всегда приводится к нижнему регистру. Для совместимости допускаются начальные и конечные символы, удаляемые функцией PHP `trim()`; если необходим строгий формат передачи данных, проверяйте исходную строку отдельно. URN, фигурные скобки и строки без дефисов не принимаются.

`Uuid::fromBytes(string $bytes): Uuid` принимает ровно 16 байт в сетевом порядке (от старшего байта к младшему). Некорректная строка или неверная длина двоичного представления приводит к `InvalidArgumentException`.

`UuidInterface` расширяет стандартный интерфейс PHP `Stringable` и предоставляет методы `toString(): string` и `equals(UuidInterface $other): bool`. Равенство определяется значением UUID, а не идентичностью объектов PHP.

`RfcUuidInterface` добавляет три свойства, доступных для чтения:

| Свойство | Тип | Значение |
| --- | --- | --- |
| `bytes` | `string` | Ровно 16 байт в сетевом порядке. |
| `version` | `int` | Четырёхбитное поле (0–15); интерпретируется как версия UUID только для варианта RFC. |
| `variant` | `UuidVariant` | `Ncs` (0), `Rfc` (1), `Microsoft` (2) или `Future` (3). |

Разбор сохраняет любую 128-битную комбинацию, включая нулевой (Nil), максимальный (Max) UUID и зарезервированные версии или варианты. Успешный разбор не подтверждает, что значение получено одним из поддерживаемых алгоритмов RFC.

Используйте `UuidInterface` в доменном коде, если дополнительные поля RFC не требуются.

## Идентификация доменных сущностей

`IdentityInterface` предоставляет постоянный идентификатор сущности через доступное для чтения свойство `UuidInterface $uuid`. Сущность может использовать стандартный UUID или собственную реализацию минимального контракта. Идентификатор можно назначить до сохранения сущности в хранилище.

~~~php
use Componenta\Identity\IdentityInterface;
use Componenta\Identity\UuidFactory;
use Componenta\Identity\UuidInterface;

final readonly class Order implements IdentityInterface
{
    public function __construct(public UuidInterface $uuid) {}
}

$order = new Order((new UuidFactory())->generate());
$order->uuid->toString();
~~~

## Упорядоченное сравнение UUID

`ComparableUuidInterface` добавляет метод `compareTo(UuidInterface $other): ComparisonResult`. Его реализует `Uuid`, но ни `UuidInterface`, ни `RfcUuidInterface` не требуют его реализации.

~~~php
use Componenta\Identity\ComparableUuidInterface;
use Componenta\Identity\Uuid;
use Componenta\Identity\UuidInterface;

$left = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abc');
$right = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abd');

$left->compareTo($right); // ComparisonResult::Less
$right->compareTo($left); // ComparisonResult::Greater
$left->compareTo($left);  // ComparisonResult::Equal

$values = [$right, $left];
usort(
    $values,
    static fn (ComparableUuidInterface $a, UuidInterface $b): int =>
        $a->compareTo($b)->value,
);
~~~

| Результат | Целочисленное значение | Смысл |
| --- | ---: | --- |
| `ComparisonResult::Less` | -1 | Левый UUID меньше правого. |
| `ComparisonResult::Equal` | 0 | Оба UUID представляют одно значение. |
| `ComparisonResult::Greater` | 1 | Левый UUID больше правого. |

Сравнение выполняется по беззнаковому 128-битному значению в сетевом порядке согласно [RFC 9562, § 6.11](https://www.rfc-editor.org/rfc/rfc9562.html#section-6.11). Правым операндом может быть любая реализация, соблюдающая контракт `UuidInterface`. Биты версии и варианта участвуют в сравнении, а его результат согласован с `equals()`.

Порядок значений не обязательно совпадает с хронологией создания (особенно для UUIDv1, смешанных версий и независимых генераторов). Получив UUID от произвольной фабрики или реализации, проверьте `instanceof ComparableUuidInterface` перед вызовом `compareTo()`. В примере сортировки каждый элемент массива должен реализовывать этот необязательный контракт.

## Фабрика UUID

`UuidFactoryInterface` определяет методы `generate(): UuidInterface` и `fromBytes(string $bytes): RfcUuidInterface`. Используйте этот интерфейс, если выбор алгоритма генерации относится к конфигурации приложения, а не к требованиям предметной области.

Стандартная фабрика `UuidFactory` возвращает объекты `Uuid`, в том числе из методов `generate()` и `fromBytes()`. Конструктор принимает `UuidGenerationVersion::V1`, `V4`, `V6` или `V7` (по умолчанию).

~~~php
use Componenta\Identity\UuidFactory;
use Componenta\Identity\UuidGenerationVersion;

$factory = new UuidFactory(UuidGenerationVersion::V4);

$random = $factory->generate();
$ordered = $factory->v7();
$restored = $factory->fromBytes(hex2bin('6ba7b8109dad11d180b400c04fd430c8'));
~~~

### Доступные версии

Все методы генерации конкретных версий возвращают `UuidInterface`.

| Метод | Версия | Применение |
| --- | --- | --- |
| `v1()` | 1 | UUID на основе григорианского времени для совместимости с прежними системами. |
| `v3(UuidNamespace\|UuidInterface $namespace, string $name)` | 3 | Детерминированный UUID на основе имени и MD5 для совместимости. |
| `v4()` | 4 | Случайный UUID со 122 случайными битами. |
| `v5(UuidNamespace\|UuidInterface $namespace, string $name)` | 5 | Детерминированный UUID на основе имени и SHA-1. |
| `v6()` | 6 | UUID с переупорядоченным григорианским временем для сортируемого хранения и совместимости. |
| `v7()` | 7 | UUID на основе миллисекунд Unix; рекомендуется для новых упорядоченных идентификаторов. |
| `v8(string $customA, int $customB, string $customC)` | 8 | Формат, определяемый приложением. |

Генерация UUIDv2 не предусмотрена: его формат DCE Security выходит за пределы RFC 9562.

### UUID на основе имени

`UuidNamespace` — перечисление со строковыми значениями: `Dns`, `Url`, `Oid`, `X500`. Свойство `value` содержит каноническое представление UUID пространства имён, метод `toUuid()` возвращает `Uuid`, а `getBytes()` — его 16 байт в сетевом порядке. Другие пространства имён можно передать через любую реализацию, соблюдающую `UuidInterface`.

~~~php
use Componenta\Identity\Uuid;
use Componenta\Identity\UuidFactory;
use Componenta\Identity\UuidNamespace;

$factory = new UuidFactory();

$dns = UuidNamespace::Dns;
$dns->toUuid();
$dns->getBytes();

$legacy = $factory->v3($dns, 'www.example.com');
$named = $factory->v5($dns, 'www.example.com');

$privateNamespace = Uuid::fromString('c63c06d8-9c3e-4d1a-b002-2cb47a9e1375');
$privateId = $factory->v5($privateNamespace, 'example');
~~~

Для одной версии одинаковые пространство имён и последовательность байтов имени всегда дают одинаковый UUID. Имя хешируется в переданном виде, без изменения регистра, нормализации Unicode, URL или DNS-имени. Необходимую нормализацию выполняйте до вызова метода.

Для нового частного пространства имён генерируйте UUIDv4 или UUIDv7, не продолжая последовательность выделения значений реестра IANA ([RFC 9562, § 6.6](https://www.rfc-editor.org/rfc/rfc9562.html#section-6.6)). Не используйте зарезервированное значение `6ba7b813-9dad-11d1-80b4-00c04fd430c8`.

### Пользовательский UUIDv8

Передайте три пользовательских поля в сетевом порядке:

| Аргумент | Допустимое значение |
| --- | --- |
| `$customA` | 6 байт (48 бит). |
| `$customB` | Целое число от 0 до 4095 (12 бит). |
| `$customC` | 8 байт; два старших бита первого байта заменяются битами варианта RFC, остаётся 62 пользовательских бита. |

~~~php
use Componenta\Identity\UuidFactory;

$uuid = (new UuidFactory())->v8(
    "\x01\x02\x03\x04\x05\x06",
    0xABC,
    "\xFF\xEE\xDD\xCC\xBB\xAA\x99\x88",
);

$uuid->toString(); // 01020304-0506-8abc-bfee-ddccbbaa9988
~~~

Фабрика устанавливает биты версии и варианта. Неверная длина полей или выход целого числа за диапазон приводит к `InvalidArgumentException`. За пользовательский формат и его свойства уникальности отвечает вызывающий код.

## Генерация на основе времени и уникальность

UUIDv7 содержит 48-битную отметку времени в миллисекундах, 12-битный счётчик со случайным начальным значением и 62 новых криптографически случайных бита. В пределах одной фабрики при повторе или уменьшении физического времени увеличивается счётчик; его исчерпание увеличивает логическую отметку времени. UUIDv6 использует переупорядоченную 60-битную григорианскую отметку времени, новую случайную последовательность и случайный идентификатор узла с установленным битом групповой рассылки. UUIDv1 использует случайный идентификатор узла с этим же битом и случайное начальное значение последовательности, которое изменяется при откате физических часов.

Фабрика обеспечивает возрастание логических григорианских отметок времени между вызовами v1/v6. Это не делает двоичное представление UUIDv1 хронологически сортируемым. При клонировании или смене процесса генератор v1 обновляет идентификатор узла и последовательность. Клонирование не гарантирует глобальный порядок и не означает полного сброса логического времени. Сериализация фабрики сохраняет только выбранную версию по умолчанию; при восстановлении создаётся новое состояние генератора.

Выход временной отметки за допустимый диапазон или исчерпание диапазона приводит к `OverflowException`. Ошибки получения криптографической случайности распространяются без перехода на менее стойкий источник. Отказ получения случайных данных не расходует следующую логическую отметку или значение счётчика успешной генерации.

Фабрика не предназначена для одновременных или повторных входов в генерацию без внешней синхронизации. Независимые фабрики и процессы не координируют состояние; глобальный порядок и абсолютная уникальность не гарантируются. Если повторы недопустимы, используйте нативный тип UUID или двоичное поле хранилища с ограничением уникальности.

## Сериализация и безопасность

Стандартный `Uuid` поддерживает сериализацию в JSON в виде канонической строки:

~~~php
use Componenta\Identity\Uuid;

$uuid = Uuid::fromString('018f6d5d-3f7a-7a9b-8c2f-123456789abc');

$json = json_encode($uuid, JSON_THROW_ON_ERROR);
$uuid->equals(Uuid::fromString(json_decode($json, true, flags: JSON_THROW_ON_ERROR))); // true
~~~

Нативная сериализация UUID средствами PHP сохраняет значения и проверяет согласованность полей при восстановлении. Десериализуйте только доверенные данные в нативном формате PHP.

Используйте UUID как непрозрачные идентификаторы, а не как секреты, доказательство подлинности или средство предоставления доступа. Проверяйте права доступа независимо. Предпочитайте UUIDv4, если нежелательно раскрывать время или порядок создания либо UUID используется в операции, связанной с безопасностью. UUIDv1 позволяет связывать значения одного генератора, UUIDv6 и UUIDv7 раскрывают сведения о времени. Встроенные временные отметки не являются достоверным источником времени для аудита.

UUIDv3 и UUIDv5 используют предусмотренные RFC алгоритмы MD5 и SHA-1; оба не подходят для защиты от намеренно подбираемых коллизий. Безопасность UUIDv8 зависит от пользовательской схемы. Для секретных токенов используйте независимый криптографически стойкий генератор.

Некорректные исходные данные при разборе не включаются в сообщения исключений. Чувствительные аргументы разбора и восстановления скрываются в кадрах трассировки библиотеки; это не защищает кадры вызывающего кода и журналы приложения. В рабочей среде задавайте `zend.exception_ignore_args=1`.

Дополнительные рекомендации: [RFC 9562, § 8](https://www.rfc-editor.org/rfc/rfc9562.html#section-8).

## Внедрение зависимостей

`ConfigProvider` регистрирует `UuidFactoryInterface` со стандартной фабрикой UUIDv7. Провайдер объявлен в разделе `extra.componenta.config-providers` манифеста Composer; приложения с `componenta/composer-plugin` могут обнаруживать его автоматически.

Внедряйте интерфейс в сервисы вместо создания отдельного генератора для каждого идентификатора:

~~~php
use Componenta\Identity\UuidFactoryInterface;
use Componenta\Identity\UuidInterface;

final readonly class OrderIdGenerator
{
    public function __construct(private UuidFactoryInterface $factory) {}

    public function generate(): UuidInterface
    {
        return $this->factory->generate();
    }
}
~~~

Приложение может заменить фабрику иначе настроенным `UuidFactory` или собственной реализацией `UuidFactoryInterface`.

## Разработка

~~~bash
composer install
composer check
composer stress
~~~

`composer check` запускает PHPStan, обычные тесты Pest и тесты с управляемыми условиями. `composer stress` выполняет выборочные проверки коллизий и упорядочивания, в том числе в разветвлённых процессах при наличии PCNTL. Эти проверки не доказывают глобальную уникальность.

Для дополнительной диагностики используйте `composer test:negative` в среде с PCNTL или `XDEBUG_MODE=coverage composer test:mutation` с включённым сбором покрытия через Xdebug.

## Лицензия

MIT, согласно [composer.json](composer.json).
