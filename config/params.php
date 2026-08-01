<?php

declare(strict_types=1);

use Rasuvaeff\Yii3UtmDb\Command\PurgeUtmAttributionCommand;
use Rasuvaeff\Yii3UtmDb\UtmAttributionTableName;

return [
    'yiisoft/yii-console' => [
        'commands' => [
            'utm:purge' => PurgeUtmAttributionCommand::class,
        ],
    ],
    'rasuvaeff/yii3-utm-db' => [
        // one source of truth: the repository and the bundled migration both
        // read the resulting name through UtmAttributionTableName
        'table' => UtmAttributionTableName::DEFAULT,
        // prepended to `table`; set it once to keep every rasuvaeff table out
        // of the way of your application's own
        'table_prefix' => '',
        'retentionDays' => 365,
    ],
];
