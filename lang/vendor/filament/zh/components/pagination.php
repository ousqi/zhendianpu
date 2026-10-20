<?php

return [
    'label' => '分页导航',
    'overview' => '{1} 共 1 条记录|[2,*] 显示第 :first 至 :last 条，共 :total 条记录',
    'fields' => [
        'records_per_page' => [
            'label' => '每页记录数',
            'options' => ['all' => '全部'],
        ],
    ],
    'actions' => [
        'first' => ['label' => '首页'],
        'go_to_page' => ['label' => '前往第 :page 页'],
        'last' => ['label' => '末页'],
        'next' => ['label' => '下一页'],
        'previous' => ['label' => '上一页'],
    ],
];
