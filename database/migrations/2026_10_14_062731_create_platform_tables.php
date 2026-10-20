<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
        Schema::create('workspace_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role')->default('reader');
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
        });
        Schema::create('mcp_servers', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('module_key')->unique();
            $table->string('name');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
        Schema::create('mcp_server_access', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('server_id')->constrained('mcp_servers')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role')->default('reader');
            $table->timestamps();
            $table->unique(['workspace_id', 'server_id', 'user_id'], 'server_access_unique');
        });
        Schema::create('mcp_operations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('server_id')->constrained('mcp_servers')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('module_key', 64);
            $table->string('access_scope_key', 100);
            $table->json('scope');
            $table->string('tool_name', 100);
            $table->string('idempotency_key', 100);
            $table->char('parameters_hash', 64);
            $table->json('draft');
            $table->json('expected_revisions')->nullable();
            $table->string('status')->default('prepared');
            $table->json('result')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'server_id', 'access_scope_key', 'user_id', 'idempotency_key'], 'operation_idempotency_unique');
            $table->index(['status', 'expires_at']);
        });
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('request_id')->index();
            $table->foreignId('operation_id')->nullable()->constrained('mcp_operations')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('server_id')->nullable()->constrained('mcp_servers')->restrictOnDelete();
            $table->string('module_key', 64)->nullable();
            $table->json('scope')->nullable();
            $table->string('action', 100);
            $table->string('entity_type')->nullable();
            $table->string('entity_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['workspace_id', 'module_key', 'created_at'], 'audit_scope_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['audit_logs', 'mcp_operations', 'mcp_server_access', 'mcp_servers', 'workspace_members', 'workspaces'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
