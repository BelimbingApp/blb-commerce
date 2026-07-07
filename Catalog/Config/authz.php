<?php
return [
    'domains' => [
        'commerce' => 'Commerce, catalog, inventory, marketplace, and sales operations',
    ],

    'capabilities' => [
        'commerce.catalog.manage',
        'commerce.catalog.view',
    ],

    'roles' => [
        'tenant_owner' => [
            'capabilities' => [
                'commerce.catalog.manage',
                'commerce.catalog.view',
            ],
        ],
    ],
];
