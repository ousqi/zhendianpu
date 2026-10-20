<?php

return [
    'single' => [
        'label' => '删除',
        'modal' => [
            'heading' => '删除 :label',
            'actions' => ['delete' => ['label' => '删除']],
        ],
        'notifications' => ['deleted' => ['title' => '已删除']],
    ],
    'multiple' => [
        'label' => '删除所选项',
        'modal' => [
            'heading' => '删除所选:label',
            'actions' => ['delete' => ['label' => '删除']],
        ],
        'notifications' => [
            'deleted' => ['title' => '已删除'],
            'deleted_partial' => ['title' => '已删除 :count / :total 项'],
            'deleted_none' => ['title' => '删除失败'],
        ],
    ],
];
