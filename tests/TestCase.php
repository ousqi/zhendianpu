<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $application = parent::createApplication();
        if ($application['config']->get('database.default') !== 'mysql' || $application['config']->get('database.connections.mysql.database') !== 'mcp_testing') {
            throw new \RuntimeException('测试仅允许使用独立的 mcp_testing MySQL 数据库。');
        }

        return $application;
    }
}
