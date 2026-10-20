<?php

return [
    'zhendianpu' => [
        'name' => '臻点铺',
        'version' => '1.0.0',
        'enabled' => true,
        'permission_keys' => ['bookkeeping.read', 'bookkeeping.write'],
        'navigation' => 'filament.admin.pages.bookkeeping-overview',
        'additional_navigation' => [],
    ],
    'system_status' => [
        'name' => '平台状态',
        'version' => '0.1.0',
        'enabled' => true,
        'permission_keys' => ['status.read'],
        'navigation' => null,
    ],
];
