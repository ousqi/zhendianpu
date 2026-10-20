<?php

namespace App\Mcp\Tools\Zhendianpu;

use App\Data\Mcp\AccessContext;
use App\Services\Bookkeeping\BookkeepingQueryService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_bookkeeping_context')]
#[Description('获取北京时间、人民币口径、当前权限、启用分类、启用账户和默认账户。没有店铺或账本选择。')]
#[IsReadOnly]
#[IsIdempotent]
class GetBookkeepingContext extends BookkeepingTool
{
    protected function respond(AccessContext $context, Request $request): array
    {
        return app(BookkeepingQueryService::class)->context($context, $request->all());
    }
}
