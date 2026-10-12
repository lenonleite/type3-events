<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:events/Resources/Private/Language/locallang_db.xlf:event',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'default_sortby' => 'event_date ASC',
        'typeicon_classes' => [
            'default' => 'mimetypes-x-content-text',
        ],
    ],
    'columns' => [
        'slug' => [
            'label' => 'LLL:EXT:events/Resources/Private/Language/locallang_db.xlf:event.slug',
            'config' => [
                'type' => 'slug',
                'generatorOptions' => [
                    'fields' => ['title'],
                    'prefixParentPageSlug' => false,
                ],
                'fallbackCharacter' => '-',
                'eval' => 'uniqueInSite',
                'default' => '',
            ],
        ],
        'title' => [
            'label' => 'LLL:EXT:events/Resources/Private/Language/locallang_db.xlf:event.title',
            'config' => [
                'type' => 'input',
                'required' => true,
                'eval' => 'trim',
                'max' => 255,
            ],
        ],
        'description' => [
            'label' => 'LLL:EXT:events/Resources/Private/Language/locallang_db.xlf:event.description',
            'config' => [
                'type' => 'text',
                'rows' => 6,
            ],
        ],
        'event_date' => [
            'label' => 'LLL:EXT:events/Resources/Private/Language/locallang_db.xlf:event.event_date',
            'config' => [
                'type' => 'datetime',
                'format' => 'datetime',
                'required' => true,
            ],
        ],
        'location' => [
            'label' => 'LLL:EXT:events/Resources/Private/Language/locallang_db.xlf:event.location',
            'config' => [
                'type' => 'input',
                'eval' => 'trim',
                'max' => 255,
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'title, slug, description, event_date, location',
        ],
    ],
];
