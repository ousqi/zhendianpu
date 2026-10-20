<?php

namespace App\Mcp\Tools\Zhendianpu;

use App\Data\Mcp\AccessContext;
use App\Services\Bookkeeping\BookkeepingQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_bookkeeping_summary')]
#[Description('汇总指定期间的收入、支出、期间结余和分类金额。期间结余不是账户余额或利润。')]
#[IsReadOnly]
#[IsIdempotent]
class GetBookkeepingSummary extends ListEntries
{
    protected function respond(AccessContext $context, Request $request): array
    {
        return app(BookkeepingQueryService::class)->summary($context, $request->all());
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->filters($schema, false);
    }
}
