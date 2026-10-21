<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Zhendianpu\AddCategory;
use App\Mcp\Tools\Zhendianpu\AddEntries;
use App\Mcp\Tools\Zhendianpu\AddRefund;
use App\Mcp\Tools\Zhendianpu\AddTransfer;
use App\Mcp\Tools\Zhendianpu\ArchiveLedger;
use App\Mcp\Tools\Zhendianpu\CreateAccount;
use App\Mcp\Tools\Zhendianpu\CreateLedger;
use App\Mcp\Tools\Zhendianpu\GetBookkeepingContext;
use App\Mcp\Tools\Zhendianpu\GetBookkeepingSummary;
use App\Mcp\Tools\Zhendianpu\GetLedger;
use App\Mcp\Tools\Zhendianpu\ListAccounts;
use App\Mcp\Tools\Zhendianpu\ListCategories;
use App\Mcp\Tools\Zhendianpu\ListEntries;
use App\Mcp\Tools\Zhendianpu\ListLedgers;
use App\Mcp\Tools\Zhendianpu\ListRefunds;
use App\Mcp\Tools\Zhendianpu\SetDefaultAccount;
use App\Mcp\Tools\Zhendianpu\ToggleAccount;
use App\Mcp\Tools\Zhendianpu\ToggleCategory;
use App\Mcp\Tools\Zhendianpu\UpdateAccount;
use App\Mcp\Tools\Zhendianpu\UpdateCategory;
use App\Mcp\Tools\Zhendianpu\UpdateEntry;
use App\Mcp\Tools\Zhendianpu\UpdateLedger;
use App\Mcp\Tools\Zhendianpu\UpdateRefund;
use App\Mcp\Tools\Zhendianpu\UpdateTransfer;
use App\Mcp\Tools\Zhendianpu\VoidEntry;
use App\Mcp\Tools\Zhendianpu\VoidRefund;
use App\Mcp\Tools\Zhendianpu\VoidTransfer;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('臻点铺')]
#[Version('1.0.0')]
#[Instructions('先调用get_bookkeeping_context获取北京时间、启用分类、账户、默认账户和权限。使用所有业务操作工具前必须从list_ledgers或get_bookkeeping_context中选择并传入ledger_id；list_ledgers和未选择账本的get_bookkeeping_context例外。只记录已经发生的收入或支出；“准备买”、报价和欠款未付不得入账。add_entries可原子添加多笔，账户省略时使用默认账户。账户间移动资金使用add_transfer，不生成收入或支出，也不改变期间结余。发生退款时使用add_refund，必须指定原收支和退款到账账户；系统会自动按原收支方向处理并在退款发生日冲减报表。需要管理分类时，先调用list_categories，再使用add_category、update_category或toggle_category；需要管理账户时，先调用list_accounts，再使用create_account、update_account、toggle_account或set_default_account。只有具备写入权限的用户可以修改分类、账户或账本，归档账本不能写入账户和收支。每次写入使用新的event_id，重试必须使用原event_id和完全相同参数。修改或撤销前先查询记录ID和revision；版本冲突时不得覆盖。只在工具成功返回ok=true时说明已记录。汇总中的期间结余不是账户余额或利润。')]
class ZhendianpuServer extends Server
{
    public int $defaultPaginationLength = 30;

    protected array $tools = [
        GetBookkeepingContext::class,
        ListLedgers::class,
        CreateLedger::class,
        GetLedger::class,
        UpdateLedger::class,
        ArchiveLedger::class,
        ListAccounts::class,
        CreateAccount::class,
        UpdateAccount::class,
        ToggleAccount::class,
        SetDefaultAccount::class,
        ListCategories::class,
        AddCategory::class,
        UpdateCategory::class,
        ToggleCategory::class,
        AddEntries::class,
        AddTransfer::class,
        AddRefund::class,
        ListRefunds::class,
        ListEntries::class,
        GetBookkeepingSummary::class,
        UpdateEntry::class,
        UpdateTransfer::class,
        UpdateRefund::class,
        VoidEntry::class,
        VoidRefund::class,
        VoidTransfer::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
