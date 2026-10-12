<?php

declare(strict_types=1);

use Study\Events\Middleware\EventApiMiddleware;

return [
    'frontend' => [
        'study/events/api' => [
            'target' => EventApiMiddleware::class,
            'after' => [
                'typo3/cms-frontend/site',
                'typo3/cms-frontend/maintenance-mode',
                'typo3/cms-frontend/base-redirect-resolver',
            ],
            'before' => ['typo3/cms-frontend/page-resolver'],
        ],
    ],
];
