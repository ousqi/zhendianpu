<?php

namespace App\Models;

use Database\Factories\McpServerFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(McpServerFactory::class)]
class McpServer extends Model
{
    /** @use HasFactory<McpServerFactory> */
    use HasFactory;

    use HasUlids;

    protected $guarded = ['id'];

    protected $casts = ['enabled' => 'boolean'];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
