<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Zhendianpu\AddEntries;
use App\Mcp\Tools\Zhendianpu\AddTransfer;
use App\Mcp\Tools\Zhendianpu\GetBookkeepingContext;
use App\Mcp\Tools\Zhendianpu\GetBookkeepingSummary;
use App\Mcp\Tools\Zhendianpu\ListEntries;
use App\Mcp\Tools\Zhendianpu\UpdateEntry;
use App\Mcp\Tools\Zhendianpu\UpdateTransfer;
use App\Mcp\Tools\Zhendianpu\VoidEntry;
use App\Mcp\Tools\Zhendianpu\VoidTransfer;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('臻点铺')]
#[Version('1.0.0')]
#[Instructions('先调用get_bookkeeping_context获取北京时间、启用分类、账户、默认账户和权限。只记录已经发生的收入或支出；“准备买”、报价和欠款未付不得入账。add_entries可原子添加多笔，账户省略时使用默认账户。账户间移动资金使用add_transfer，不生成收入或支出，也不改变期间结余。每次写入使用新的event_id，重试必须使用原event_id和完全相同参数。修改或撤销前先查询记录ID和revision；版本冲突时不得覆盖。只在工具成功返回ok=true时说明已记录。汇总中的期间结余不是账户余额或利润。')]
class ZhendianpuServer extends Server
{
    public int $defaultPaginationLength = 30;

    protected array $tools = [
        GetBookkeepingContext::class,
        AddEntries::class,
        AddTransfer::class,
        ListEntries::class,
        GetBookkeepingSummary::class,
        UpdateEntry::class,
        UpdateTransfer::class,
        VoidEntry::class,
        VoidTransfer::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
