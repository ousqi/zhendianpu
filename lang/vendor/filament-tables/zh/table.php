<?php

return [
    'fields' => [
        'search' => [
            'label' => '搜索',
            'placeholder' => '搜索',
            'indicator' => '搜索',
        ],
    ],
    'columns' => [
        'actions' => ['label' => '操作'],
        'icon' => ['boolean' => ['true' => '是', 'false' => '否']],
        'select' => [
            'loading_message' => '加载中…',
            'no_options_message' => '暂无可选项。',
            'no_search_results_message' => '未找到匹配选项。',
            'placeholder' => '请选择',
            'searching_message' => '搜索中…',
            'search_prompt' => '输入关键词搜索',
        ],
    ],
    'actions' => [
        'filter' => ['label' => '筛选'],
        'group' => ['label' => '分组'],
        'open_bulk_actions' => ['label' => '批量操作'],
        'column_manager' => ['label' => '列设置'],
    ],
    'filters' => [
        'actions' => [
            'apply' => ['label' => '应用筛选'],
            'remove' => ['label' => '移除筛选'],
            'remove_all' => ['label' => '移除全部筛选', 'tooltip' => '移除全部筛选'],
            'reset' => ['label' => '重置'],
        ],
        'heading' => '筛选',
        'indicator' => '已启用的筛选',
        'multi_select' => ['placeholder' => '全部'],
        'select' => ['placeholder' => '全部', 'relationship' => ['empty_option_label' => '无']],
    ],
    'empty' => [
        'heading' => '暂无:model',
        'description' => '新增:model后将在这里显示。',
    ],
    'loading' => '加载中…',
    'result_count' => '{0} 没有结果|{1} 1 条结果|[2,*] :count 条结果',
];
