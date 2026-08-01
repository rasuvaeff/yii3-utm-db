# Examples

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/sqlite-journal.php
```

| Script | Shows | Needs server? |
|---|---|---|
| `sqlite-journal.php` | Applying the migration the documented way, writing an event, idempotent redelivery, why a backdated delivery cannot become the first touch, retention and erasure | No — in-memory SQLite |

The same code runs against MySQL or PostgreSQL by swapping the `yiisoft/db`
connection; nothing in this package is driver-specific.
