<?php

return [
    'direction' => 'ltr',
    'skip_to_content' => ['label' => '跳转到内容'],
    'actions' => [
        'logout' => ['label' => '退出登录'],
        'open_database_notifications' => ['label' => '通知', 'label_with_unread_count' => '{1} 通知，:count 条未读|[2,*] 通知，:count 条未读'],
        'open_user_menu' => ['label' => '用户菜单'],
        'sidebar' => ['collapse' => ['label' => '收起侧边栏'], 'expand' => ['label' => '展开侧边栏']],
        'theme_switcher' => [
            'label' => '主题',
            'dark' => ['label' => '启用深色主题'],
            'light' => ['label' => '启用浅色主题'],
            'system' => ['label' => '跟随系统主题'],
        ],
    ],
    'navigation' => ['label' => '侧边栏导航'],
    'topbar' => ['label' => '顶部导航'],
    'avatar' => ['alt' => ':name 的头像'],
    'logo' => ['alt' => ':name 标志'],
];
