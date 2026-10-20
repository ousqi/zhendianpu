<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class OAuthConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_oauth_metadata_advertises_https_pkce_and_refresh_tokens(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
            ->assertJsonPath('issuer', 'https://demo.alphawx.cn')
            ->assertJsonPath('token_endpoint', 'https://demo.alphawx.cn/oauth/token')
            ->assertJsonPath('code_challenge_methods_supported.0', 'S256')
            ->assertJsonPath('grant_types_supported.1', 'refresh_token');
        $this->getJson('/.well-known/oauth-protected-resource/mcp/zhendianpu')->assertOk()
            ->assertJsonPath('resource', 'https://demo.alphawx.cn/mcp/zhendianpu');
    }

    public function test_registration_rejects_unapproved_callback_domains(): void
    {
        $this->postJson('/oauth/register', ['redirect_uris' => ['https://chatgpt.com.evil.example/callback']])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
    }

    public function test_authenticated_tools_list_includes_void_transfer_reason_limit(): void
    {
        $user = User::factory()->create();
        $this->artisan('platform:initialize', ['--email' => $user->email])->assertSuccessful();
        Passport::actingAs($user, ['mcp:use']);

        $response = $this->postJson('/mcp/zhendianpu', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertOk();
        $voidTransfer = collect($response->json('result.tools'))->firstWhere('name', 'void_transfer');

        $this->assertIsArray($voidTransfer);
        $this->assertSame(1000, data_get($voidTransfer, 'inputSchema.properties.void_reason.maxLength'));
    }

    public function test_real_pkce_authorization_tokens_refresh_and_revocation(): void
    {
        $user = User::factory()->create();
        $this->artisan('platform:initialize', ['--email' => $user->email])->assertSuccessful();
        $callback = 'https://chatgpt.com/connector_platform/oauth/callback';
        $registration = $this->postJson('/oauth/register', ['client_name' => 'ChatGPT 测试', 'redirect_uris' => [$callback]])->assertCreated();
        $clientId = $registration->json('client_id');
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $query = ['client_id' => $clientId, 'redirect_uri' => $callback, 'response_type' => 'code', 'scope' => 'mcp:use', 'state' => 'test-state', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256'];
        $this->get('/oauth/authorize?'.http_build_query($query))->assertRedirect(route('login'));
        $authorize = $this->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query($query))->assertOk()->assertViewIs('auth.oauth.authorize');
        $approval = $this->post('/oauth/authorize', ['client_id' => $clientId, 'state' => 'test-state', 'auth_token' => $authorize->viewData('authToken')])->assertRedirect();
        parse_str(parse_url($approval->headers->get('Location'), PHP_URL_QUERY), $parameters);
        $this->assertSame('test-state', $parameters['state']);
        $token = $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $clientId, 'redirect_uri' => $callback, 'code_verifier' => $verifier, 'code' => $parameters['code']])->assertOk();
        $this->assertNotEmpty($token->json('refresh_token'));
        $rpc = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_bookkeeping_context']];
        $this->withToken($token->json('access_token'))->postJson('/mcp/zhendianpu', $rpc)
            ->assertOk()->assertJsonPath('result.structuredContent.currency', 'CNY');
        $refresh = $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => $token->json('refresh_token')])->assertOk();
        $this->assertNotSame($token->json('access_token'), $refresh->json('access_token'));
        DB::table('oauth_access_tokens')->update(['revoked' => true]);
        app('auth')->forgetGuards();
        $this->withToken($refresh->json('access_token'))->postJson('/mcp/zhendianpu', $rpc)->assertUnauthorized();
    }
}
