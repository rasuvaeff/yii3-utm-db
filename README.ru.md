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
],
```

## Retention и стирание

```bash
./yii utm:purge --older-than=365          # сгребание по сроку хранения
./yii utm:purge --older-than=365 --dry-run
```

Стирание данных конкретного человека — другая операция, она на репозитории:
`deleteByEntity($entityId)`.

## Безопасность

| Аспект | Поведение |
|---|---|
| SQL | Все значения — bound parameters |
| Идентификаторы | Имена таблицы и индексов только из валидируемого `UtmAttributionTableName` |
| Порядок | Назначает сервер; клиент не может задним числом стать первым касанием |
| Дубли | Unique-индекс плюс race-safe upsert, а не «проверить, потом вставить» |
| Чужие строки | Строка, не соответствующая схеме, поднимает `MalformedRow`, а не приводится к типу  Все исключения пакета реализуют `UtmDbException`, наследник корового `UtmException`. |

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
