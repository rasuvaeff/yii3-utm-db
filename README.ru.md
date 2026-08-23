# rasuvaeff/yii3-utm-db

[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-utm-db/v)](https://packagist.org/packages/rasuvaeff/yii3-utm-db)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-utm-db/downloads)](https://packagist.org/packages/rasuvaeff/yii3-utm-db)
[![Build](https://github.com/rasuvaeff/yii3-utm-db/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-utm-db/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/yii3-utm-db/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-utm-db/actions/workflows/static-analysis.yml)
[![Psalm level](https://shepherd.dev/github/rasuvaeff/yii3-utm-db/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-utm-db)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

Хранилище для [rasuvaeff/yii3-utm](https://github.com/rasuvaeff/yii3-utm):
portable-реализация журнала атрибуции на `yiisoft/db` — race-safe запись,
канонический серверный порядок, retention и стирание данных.

> Используете AI-ассистента? [llms.txt](llms.txt) — компактный справочник API
> для LLM.

## Требования

- PHP 8.3 – 8.5
- `ext-json`, `ext-pdo`
- `rasuvaeff/yii3-utm`, `yiisoft/db` 2.x, `yiisoft/db-migration` 2.x

## Установка

```bash
composer require rasuvaeff/yii3-utm-db
```

Установка — это и есть вся проводка: ядро сознательно оставляет
`UtmAttributionRepository` несвязанным, а этот пакет его биндит. Не биндите его
второй раз в приложении — `yiisoft/config` сообщит
`Duplicate key while building "di"`, когда один ключ определён двумя
источниками.

## Миграция

`MigrationService::setSourceNamespaces()` **не находит** миграции этого пакета:
`yiisoft/db-migration` матчит PSR-4-карту по строковому префиксу, а
`Rasuvaeff\Yii3Utm\` — префикс `Rasuvaeff\Yii3UtmDb\`, поэтому discovery уходит
в пакет-ядро и молча ничего не находит. Применяйте миграцию напрямую:

```php
use Rasuvaeff\Yii3UtmDb\Migration\M260801000000CreateUtmAttributionTable;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;

$builder = new MigrationBuilder($db, new NullMigrationInformer());
$injector->make(M260801000000CreateUtmAttributionTable::class)->up($builder);
```

`Injector::make()` резолвит имя таблицы **по типу**, поэтому миграция и
репозиторий не могут разойтись.

## Схема

| Колонка | Тип | Смысл |
|---|---|---|
| `id` | big primary key | Порядок вставки; часть канонического порядка |
| `entity_id` | `string(191)` | Кому принадлежит атрибуция |
| `event_id` | `string(191)` | Ключ идемпотентности, задаёт приложение |
| `interaction_type` | `string(32)` | `purchase`, `registration` и любой валидный |
| `utm_source` … `utm_id` | `string(255)` | Кортеж кампании, включая GA4 `utm_id` |
| `click_ids` | `string(500)` | JSON-объект в порядке whitelist, `{}` если пусто |
| `referrer`, `referrer_host` | `string(500)`, `string(255)` | В fingerprint входит только хост |
| `landing_page` | `string(500)` | Санитизируется до попадания в строку |
| `occurred_at` | `string(30)` | Заявлено источником; только аналитика |
| `recorded_at` | `string(30)` | Серверные часы в момент вставки |
| `fingerprint`, `dedupe_key` | `string(64)` | Вычисляются; unique-индекс на `dedupe_key` |

Индексы: `UNIQUE (dedupe_key)`, `(entity_id, recorded_at, id)`,
`(entity_id, interaction_type, recorded_at, id)`, `(recorded_at)`.

Время хранится строками фиксированной ширины в UTC (`Y-m-d H:i:s.u`), чтобы
канонический порядок был лексикографическим на любом драйвере. Колонки
`is_first_interaction` нет: первое касание — то, что сервер записал первым.

## Конфигурация

```php
// config/common/params.php
'rasuvaeff/yii3-utm-db' => [
    'table' => 'utm_attribution',
    'table_prefix' => '',
    'retentionDays' => 365,
    'purgeBatchSize' => 1000,
],
```

`purgeBatchSize` — число строк, которые `purgeOlderThan()` удаляет за один
стейтмент; значения меньше единицы отвергаются в конструкторе.

## Retention и стирание

```bash
./yii utm:purge --older-than=P365D          # сгребание по сроку хранения
./yii utm:purge --older-than=P90D --dry-run
```

`--older-than` принимает положительный ISO-8601 интервал: `P90D`, `P6M` или
`PT12H`. Без опции используется дефолт из `retentionDays`. `--dry-run`
показывает число строк, которые были бы удалены, ничего не удаляя.

`purgeOlderThan()` удаляет пачками по первичному ключу —
`DbUtmAttributionRepository::DEFAULT_PURGE_BATCH_SIZE` (1000) строк на
стейтмент, настраивается аргументом конструктора `purgeBatchSize` или
одноимённым пакетным параметром. Первый проход по таблице, которую больше
ничто не подрезает, может удалить месяцы накоплений, и один неограниченный
`DELETE` — это одна длинная транзакция: раздувание undo/WAL, лаг репликации,
а на MySQL — next-key блокировки по всему диапазону `recorded_at`.

Стирание данных конкретного человека — другая операция, она на репозитории:
`deleteByEntity($entityId)`.

## Контракт данных

Маппер не доверяет таблице: он пересчитывает fingerprint и dedupe key из
строки и отвергает расхождение, а также требует, чтобы `click_ids`,
`landing_page`, значения `utm_*` и обе метки времени были в канонической
форме. Именно это не пускает недоверенную строку в отчёт по атрибуции — и это
же означает, что каноническая форма является **данными**, а не деталью
реализации.

Заморожено на весь срок жизни этой схемы:

| Заморожено | Почему |
|---|---|
| `ClickIds::KNOWN_KEYS` — только добавление, порядок не меняется | Порядок входит в канонический JSON `click_ids` и в fingerprint |
| `ClickIds::MAX_VALUE_LENGTH` и паттерн значения | Другая отсечка даёт другой канонический JSON для того же входа |
| `UtmParameters::normalize()` и `MAX_VALUE_LENGTH` | Питает fingerprint и проверку каноничности `utm_*` |
| `UtmAttributionRowMapper::TIME_FORMAT` | Порядок лексикографический по хранимой строке |

Изменение любого из них превращает уже записанные строки в `MalformedRow` и
требует миграции: смена `TIME_FORMAT` переписывает `occurred_at` и
`recorded_at`, изменение `ClickIds` переписывает `click_ids`, а любое изменение
нормализуемых значений пересчитывает `fingerprint` и `dedupe_key`. Это никогда
не минорный релиз.

### Строка, не прошедшая контракт

Битая строка **пропускается, а не роняет запрос**. Если строгость маппера
делать фатальной на чтении, одна чужая строка скрывает всю историю атрибуции
сущности — а оператор, которому её удалять, не может сначала прочитать
остальное. Поэтому:

| Чтение | Поведение |
|---|---|
| `findByEntity()` | Возвращает читаемые строки. Страница может прийти **короче `$limit`**, а `$offset` считает строки, а не записи |
| `findFirst()` / `findLast()` | Перешагивают битую крайнюю строку и возвращают первую читаемую в своём направлении, просматривая не больше `UtmAttributionRepository::MAX_LIMIT` строк |
| `countByEntity()` / `countOlderThan()` | Считают **строки**, включая нечитаемые; расхождение с укоротившейся страницей и есть сигнал |

Каждый вызов, пропустивший строки, сообщает о них в PSR-3 логгер на уровне
`error`: первый пропуск — с id, причиной и исключением, остальные пропуски того
же вызова сходятся в одну summary-строку:

```php
$repository = new DbUtmAttributionRepository(
    db: $db,
    clock: $clock,
    logger: $logger, // без него отчёт молчит
);
```

По умолчанию стоит `NullLogger`, поэтому **привяжите логгер приложения** —
иначе изменение нормализации в ядре сделает нечитаемыми все строки, а отчёты
уйдут в никуда. Поставляемая конфигурация контейнера уже передаёт
`LoggerInterface` приложения, если он привязан.

`MalformedRow::$rowId` несёт первичный ключ, а сообщение заканчивается на
`(row id N)`; сам `UtmAttributionRowMapper` по-прежнему бросает `MalformedRow`,
если вызывать маппер напрямую.

## Безопасность

| Аспект | Поведение |
|---|---|
| SQL | Все значения — bound parameters |
| Идентификаторы | Имена таблицы и индексов только из валидируемого `UtmAttributionTableName` |
| Порядок | Назначает сервер; клиент не может задним числом стать первым касанием |
| Дубли | Unique-индекс плюс race-safe upsert, а не «проверить, потом вставить»; как «уже записано» глушится только конфликт уникального ключа — нарушение FK, CHECK или NOT NULL на той же таблице выходит исключением, а не считается обработанной доставкой |
| Чужие строки | Строка, не соответствующая канонической схеме, никогда не приводится к записи: маппер поднимает `MalformedRow` с id битой строки, а репозиторий эту строку пропускает и сообщает о ней, а не возвращает. Все исключения пакета реализуют `UtmDbException`, наследник корового `UtmException`. |

## Примеры

Исполняемые скрипты — в [examples/](examples/).

## Разработка

```bash
make build
make test
make cs-fix
make psalm
```

Интеграционный сьют гоняется на in-memory SQLite:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/testo --suite=Integration
```

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
