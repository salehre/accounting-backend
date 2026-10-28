<?php

namespace Tests\Feature\Security;

use App\Models\Role;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Guards against the most common security mistake: adding a new API route and
 * forgetting to lock it. These tests fail the moment that happens.
 */
class RouteProtectionTest extends TestCase
{
    /** Routes anyone may call without logging in. */
    private const PUBLIC_ROUTES = ['api/health', 'api/auth/login', 'api/auth/token'];

    /** Routes any logged-in user may call (no specific permission). */
    private const LOGGED_IN_ONLY = ['api/auth/me', 'api/auth/logout', 'api/auth/change-password'];

    /** @return array<int, LaravelRoute> */
    private function apiRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (LaravelRoute $route) => str_starts_with($route->uri(), 'api/'),
        ));
    }

    private function label(LaravelRoute $route): string
    {
        return implode('|', $route->methods()).' /'.$route->uri();
    }

    private function permissionOf(LaravelRoute $route): ?string
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                return substr($middleware, strlen('permission:'));
            }
        }

        return null;
    }

    public function test_every_api_route_requires_a_logged_in_active_user_unless_it_is_public(): void
    {
        $protected = 0;

        foreach ($this->apiRoutes() as $route) {
            if (in_array($route->uri(), self::PUBLIC_ROUTES, true)) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware, $this->label($route).' is not behind auth:sanctum');
            $this->assertContains('active', $middleware, $this->label($route).' does not check that the user is active');
            $protected++;
        }

        $this->assertGreaterThan(5, $protected);
    }

    public function test_every_data_route_declares_the_permission_it_needs(): void
    {
        foreach ($this->apiRoutes() as $route) {
            if (in_array($route->uri(), [...self::PUBLIC_ROUTES, ...self::LOGGED_IN_ONLY], true)) {
                continue;
            }

            $this->assertNotNull(
                $this->permissionOf($route),
                $this->label($route).' has no permission: middleware',
            );
        }
    }

    public function test_permission_names_used_by_routes_are_granted_to_some_role(): void
    {
        $granted = Role::query()->pluck('permissions')->flatten()->unique()->all();

        foreach ($this->apiRoutes() as $route) {
            $permission = $this->permissionOf($route);
            if ($permission === null) {
                continue;
            }

            $this->assertContains(
                $permission,
                $granted,
                $this->label($route)." needs \"{$permission}\", which no role has (typo?)",
            );
        }
    }

    public function test_error_answers_contain_only_a_message(): void
    {
        config(['app.debug' => false]);

        $viewer = $this->tokenFor($this->makeUser('viewer'));
        $admin = $this->tokenFor($this->makeUser('admin'));

        $answers = [
            'unauthenticated' => $this->api('GET', '/api/entries'),
            'forbidden' => $this->api('POST', '/api/entries/1/post', [], $viewer),
            'not found' => $this->api('GET', '/api/entries/999999', [], $admin),
        ];

        foreach ($answers as $case => $response) {
            $this->assertSame(['message'], array_keys($response->json()), "The \"{$case}\" answer exposes more than a message.");
        }
    }

    public function test_server_errors_do_not_reveal_internal_details(): void
    {
        config(['app.debug' => false]);

        Route::get('api/_boom', function () {
            throw new \RuntimeException('secret-internal-detail');
        });

        $response = $this->api('GET', '/api/_boom');

        $response->assertStatus(500);
        $this->assertStringNotContainsString('secret-internal-detail', $response->getContent());
        $this->assertStringNotContainsString('trace', $response->getContent());
        $this->assertStringNotContainsString('.php', $response->getContent());
    }
}
