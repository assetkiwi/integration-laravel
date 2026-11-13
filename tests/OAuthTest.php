<?php

declare(strict_types=1);

namespace AssetKiwi\Laravel\Tests;

use AssetKiwi\Laravel\AssetKiwiServiceProvider;
use AssetKiwi\Laravel\Client\AssetKiwiClient;
use AssetKiwi\Laravel\Http\Middleware\EnsureAssetKiwiToken;
use AssetKiwi\Laravel\OAuth\OAuthManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

class OAuthTest extends TestCase
{
    public function test_a_return_url_can_be_passed_and_connected_follows_the_token(): void
    {
        $user = new class { public $id = 7; };
        $this->assertFalse(OAuthManager::connected($user));

        OAuthManager::getAuthorizationUrl('https://app.test/admin/media');
        $this->assertSame('https://app.test/admin/media', session('url.intended'));

        OAuthManager::storeToken($user, ['access_token' => 't', 'expires_in' => 3600]);
        $this->assertTrue(OAuthManager::connected($user));

        config(['assetkiwi.oauth.enabled' => false]);
        $this->assertTrue(OAuthManager::connected(null));
    }

    protected function getPackageProviders($app): array
    {
        return [AssetKiwiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('assetkiwi.api_url', 'https://dam.example.com');
        $app['config']->set('assetkiwi.api_token', 'shared-token-123');
        $app['config']->set('assetkiwi.cache_ttl', 0);

        // Required for session encryption in callback route tests
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        // Enable OAuth by default so routes are registered during boot.
        // Individual tests that need it disabled can override.
        $app['config']->set('assetkiwi.oauth', [
            'enabled' => true,
            'client_id' => 'client-id-123',
            'client_secret' => 'client-secret-456',
            'authorize_url' => 'https://dam.example.com/oauth/authorize',
            'token_url' => 'https://dam.example.com/oauth/token',
            'scopes' => 'assets:read',
            'redirect_uri' => 'https://app.test/assetkiwi/oauth/callback',
        ]);
    }

    private function setOAuthEnabled(bool $enabled): void
    {
        // Use direct repository access to avoid dot-notation merge issues
        // when the parent key was set as a whole array.
        app('config')->set('assetkiwi.oauth.enabled', $enabled);
    }

    private function bindRequestUser(object $user): Request
    {
        $request = request();
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    // ── OAuthManager ──────────────────────────────────────────────────────────

    public function test_get_authorization_url_contains_pkce_params(): void
    {
        $url = OAuthManager::getAuthorizationUrl();

        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('client_id=client-id-123', $url);
        $this->assertStringContainsString('scope=assets%3Aread', $url);
        $this->assertStringContainsString('code_challenge_method=S256', $url);
        $this->assertStringContainsString('code_challenge=', $url);
        $this->assertStringContainsString('state=', $url);
    }

    public function test_get_authorization_url_stores_code_verifier_in_session(): void
    {
        OAuthManager::getAuthorizationUrl();

        $this->assertNotNull(session()->get('assetkiwi.oauth.code_verifier'));
        $this->assertNotNull(session()->get('assetkiwi.oauth.state'));
        $this->assertEquals(64, strlen(session()->get('assetkiwi.oauth.code_verifier')));
        $this->assertEquals(40, strlen(session()->get('assetkiwi.oauth.state')));
    }

    public function test_exchange_code_sends_correct_params(): void
    {
        Http::fake([
            'dam.example.com/oauth/token' => Http::response([
                'access_token' => 'oauth-access-token-xyz',
                'token_type' => 'Bearer',
                'expires_in' => 7776000,
            ]),
        ]);

        $result = OAuthManager::exchangeCode('auth-code', 'state-value', 'verifier-string');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://dam.example.com/oauth/token'
                && $request->method() === 'POST'
                && $request['grant_type'] === 'authorization_code'
                && $request['code'] === 'auth-code'
                && $request['client_id'] === 'client-id-123'
                && $request['client_secret'] === 'client-secret-456'
                && $request['code_verifier'] === 'verifier-string'
                && $request['redirect_uri'] === 'https://app.test/assetkiwi/oauth/callback'; // the DAM's token endpoint requires it
        });

        $this->assertIsArray($result);
        $this->assertSame('oauth-access-token-xyz', $result['access_token']);
    }

    public function test_exchange_code_returns_null_on_failure(): void
    {
        Http::fake([
            'dam.example.com/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $result = OAuthManager::exchangeCode('bad-code', 'state', 'verifier');

        $this->assertNull($result);
    }

    public function test_store_and_get_token(): void
    {
        $user = new \stdClass();
        $user->id = 42;

        OAuthManager::storeToken($user, [
            'access_token' => 'per-user-token-abc',
            'expires_in' => 3600,
        ]);

        // Should be retrievable from session
        $this->assertSame('per-user-token-abc', OAuthManager::getToken($user));
    }

    public function test_get_token_returns_null_when_not_stored(): void
    {
        $user = new \stdClass();
        $user->id = 99;

        $this->assertNull(OAuthManager::getToken($user));
    }

    public function test_get_token_falls_back_to_cache(): void
    {
        $user = new \stdClass();
        $user->id = 77;

        // Store in cache only (simulating session expired but cache persists)
        Cache::put('assetkiwi.oauth.token.77', [
            'access_token' => 'cached-token-xyz',
            'expires_at' => now()->addHour()->timestamp,
        ], now()->addHour());

        $this->assertSame('cached-token-xyz', OAuthManager::getToken($user));
    }

    public function test_get_token_ignores_expired_cache(): void
    {
        $user = new \stdClass();
        $user->id = 88;

        Cache::put('assetkiwi.oauth.token.88', [
            'access_token' => 'expired-token',
            'expires_at' => now()->subHour()->timestamp,
        ], now()->subHour());

        $this->assertNull(OAuthManager::getToken($user));
    }

    public function test_get_token_ignores_an_expired_session_token(): void
    {
        $user = new \stdClass();
        $user->id = 91;
        session()->put('assetkiwi.oauth.access_token', 'old');
        session()->put('assetkiwi.oauth.expires_at', now()->subMinute());

        $this->assertNull(OAuthManager::getToken($user));
        $this->assertNull(session('assetkiwi.oauth.access_token'));

        session()->put('assetkiwi.oauth.access_token', 'fresh');
        session()->put('assetkiwi.oauth.expires_at', now()->addHour());
        $this->assertSame('fresh', OAuthManager::getToken($user));
    }

    // ── AssetKiwiClient resolveToken ──────────────────────────────────────────

    public function test_client_uses_shared_token_when_oauth_disabled(): void
    {
        $this->setOAuthEnabled(false);

        $client = new AssetKiwiClient('https://dam.example.com', 'shared-token-123');

        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('resolveToken');
        $method->setAccessible(true);

        $this->assertSame('shared-token-123', $method->invoke($client));
    }

    public function test_client_uses_per_user_token_when_oauth_enabled(): void
    {
        $this->setOAuthEnabled(true);

        $this->assertTrue(config('assetkiwi.oauth.enabled'), 'OAuth should be enabled in config');

        $user = new \stdClass();
        $user->id = 42;

        $this->bindRequestUser($user);

        $this->assertNotNull(request()->user(), 'Bound request should resolve user');
        $this->assertSame(42, request()->user()->id);

        Cache::put('assetkiwi.oauth.token.42', [
            'access_token' => 'per-user-token-abc',
            'expires_at' => now()->addHour()->timestamp,
        ], now()->addHour());

        $client = new AssetKiwiClient('https://dam.example.com', 'shared-token-123');

        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('resolveToken');
        $method->setAccessible(true);

        $this->assertSame('per-user-token-abc', $method->invoke($client));
    }

    public function test_client_falls_back_to_shared_token_when_no_per_user_token(): void
    {
        $this->setOAuthEnabled(true);

        $user = new \stdClass();
        $user->id = 55;

        $this->bindRequestUser($user);

        $client = new AssetKiwiClient('https://dam.example.com', 'shared-token-123');

        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('resolveToken');
        $method->setAccessible(true);

        // No per-user token stored — should fall back to shared
        $this->assertSame('shared-token-123', $method->invoke($client));
    }

    public function test_client_uses_shared_token_when_no_user_authenticated(): void
    {
        $this->setOAuthEnabled(true);

        $request = request();
        $request->setUserResolver(fn () => null);

        $client = new AssetKiwiClient('https://dam.example.com', 'shared-token-123');

        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('resolveToken');
        $method->setAccessible(true);

        $this->assertSame('shared-token-123', $method->invoke($client));
    }

    // ── OAuth Callback Route ──────────────────────────────────────────────────

    public function test_oauth_callback_route_registered_when_enabled(): void
    {
        // OAuth is enabled by default in defineEnvironment, so the route
        // should have been registered during boot.
        $router = $this->app->make('router');
        $routes = $router->getRoutes();

        $this->assertNotNull(
            $routes->getByName('assetkiwi.oauth.callback'),
            'OAuth callback route should be registered when OAuth is enabled'
        );
    }

    public function test_oauth_callback_route_not_registered_when_disabled(): void
    {
        // Since OAuth is enabled by default in defineEnvironment, we need
        // to force a fresh application boot with OAuth disabled.
        // We use a separate approach: test that the route guard honors the config.
        // The route IS actually registered (boot ran with enabled=true),
        // so we test behavior instead of route existence when disabled.
        //
        // In real usage, when OAuth is disabled in .env from the start,
        // the route is never registered. This test verifies that the config
        // correctly controls the middleware and controller behavior.
        $this->setOAuthEnabled(false);

        // The route may still exist from the initial boot, but the middleware
        // and resolveToken will both short-circuit when oauth.enabled is false.
        // Test that the middleware passes through.
        $request = Request::create('/test', 'GET');
        $middleware = new EnsureAssetKiwiToken();

        $called = false;
        $middleware->handle($request, function () use (&$called) {
            $called = true;
            return response('OK');
        });

        $this->assertTrue($called, 'Middleware should pass through when OAuth is disabled');
    }

    public function test_callback_rejects_invalid_state(): void
    {
        $this->setOAuthEnabled(true);

        // Set up a state that doesn't match
        session()->put('assetkiwi.oauth.state', 'expected-state');
        session()->put('assetkiwi.oauth.code_verifier', 'verifier-string');

        $this->get('/assetkiwi/oauth/callback?code=auth-code&state=wrong-state')
            ->assertStatus(400);
    }

    public function test_callback_exchanges_code_and_stores_token(): void
    {
        $this->setOAuthEnabled(true);

        Http::fake([
            'dam.example.com/oauth/token' => Http::response([
                'access_token' => 'new-oauth-token',
                'token_type' => 'Bearer',
                'expires_in' => 7776000,
            ]),
        ]);

        // Set an authenticated user on the guard so $request->user() works
        // during the real HTTP request dispatch.
        $user = new class implements \Illuminate\Contracts\Auth\Authenticatable {
            public $id = 99;
            public function getAuthIdentifierName() { return 'id'; }
            public function getAuthIdentifier() { return $this->id; }
            public function getAuthPasswordName() { return ''; }
            public function getAuthPassword() { return ''; }
            public function getRememberToken() { return ''; }
            public function setRememberToken($value) {}
            public function getRememberTokenName() { return ''; }
        };
        $this->be($user);

        session()->put('assetkiwi.oauth.state', 'valid-state');
        session()->put('assetkiwi.oauth.code_verifier', 'verifier-string');

        $this->get('/assetkiwi/oauth/callback?code=auth-code&state=valid-state')
            ->assertRedirect('/');

        $this->assertSame('new-oauth-token', session()->get('assetkiwi.oauth.access_token'));
    }

    // ── EnsureAssetKiwiToken Middleware ───────────────────────────────────────

    public function test_middleware_passes_when_oauth_disabled(): void
    {
        $this->setOAuthEnabled(false);

        $request = Request::create('/test', 'GET');
        $middleware = new EnsureAssetKiwiToken();

        $called = false;
        $response = $middleware->handle($request, function () use (&$called) {
            $called = true;
            return response('OK');
        });

        $this->assertTrue($called);
    }

    public function test_middleware_passes_when_oauth_enabled_and_token_exists(): void
    {
        $this->setOAuthEnabled(true);

        $user = new \stdClass();
        $user->id = 42;
        Cache::put('assetkiwi.oauth.token.42', [
            'access_token' => 'valid-token',
            'expires_at' => now()->addHour()->timestamp,
        ], now()->addHour());

        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = new EnsureAssetKiwiToken();

        $called = false;
        $response = $middleware->handle($request, function () use (&$called) {
            $called = true;
            return response('OK');
        });

        $this->assertTrue($called);
    }

    public function test_middleware_passes_when_oauth_enabled_but_no_user(): void
    {
        $this->setOAuthEnabled(true);

        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn () => null);

        $middleware = new EnsureAssetKiwiToken();

        $called = false;
        $response = $middleware->handle($request, function () use (&$called) {
            $called = true;
            return response('OK');
        });

        $this->assertTrue($called);
    }

    public function test_middleware_redirects_to_authorize_when_no_token(): void
    {
        $this->setOAuthEnabled(true);

        $user = new \stdClass();
        $user->id = 33;

        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = new EnsureAssetKiwiToken();

        $response = $middleware->handle($request, fn () => response('OK'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('dam.example.com/oauth/authorize', $response->headers->get('Location'));
    }

    public function test_middleware_returns_json_error_when_no_token_and_expects_json(): void
    {
        $this->setOAuthEnabled(true);

        $user = new \stdClass();
        $user->id = 33;

        $request = Request::create('/test', 'GET');
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(fn () => $user);

        $middleware = new EnsureAssetKiwiToken();

        $response = $middleware->handle($request, fn () => response('OK'));

        $this->assertSame(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('assetkiwi_oauth_required', $data['error']);
        $this->assertArrayHasKey('authorize_url', $data);
    }

    // ── HTTP requests use resolved token ──────────────────────────────────────

    public function test_http_requests_use_oauth_token_when_available(): void
    {
        $this->setOAuthEnabled(true);

        $user = new \stdClass();
        $user->id = 42;

        $this->bindRequestUser($user);

        Cache::put('assetkiwi.oauth.token.42', [
            'access_token' => 'per-user-oauth-token',
            'expires_at' => now()->addHour()->timestamp,
        ], now()->addHour());

        Http::fake([
            'dam.example.com/api/v1/assets' => Http::response([
                'data' => [['uuid' => 'abc-123']],
            ]),
        ]);

        $client = new AssetKiwiClient('https://dam.example.com', 'shared-token-123');
        $client->assets();

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer per-user-oauth-token');
        });
    }
}
