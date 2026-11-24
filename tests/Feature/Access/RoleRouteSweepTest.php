<?php

namespace Tests\Feature\Access;

use App\Models\Permission;
use App\Models\Role;
use App\Support\AccessPermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Opens every parameterless GET page as each system role with its default
 * permissions (the grant App\Models\Role gives on creation) and checks:
 * - no page answers 500 for any role;
 * - a page behind a permission middleware refuses a role that lacks it.
 *
 * The full matrix is written to storage/logs/role-route-matrix.txt.
 */
class RoleRouteSweepTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private const ROLES = ['employee', 'manager', 'HR-ALP', 'HR-SYR', 'general-manager', 'super-admin'];

    public function test_every_page_answers_each_role_without_a_server_error(): void
    {
        $this->withoutVite();

        foreach (AccessPermissionCatalog::names() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $city = $this->makeCity('ALP', '001');
        $this->makeCity('SYR', '000');

        $users = [];
        foreach (self::ROLES as $roleName) {
            Role::findOrCreate($roleName, 'web');
            $users[$roleName] = $this->makeUser($city, $roleName);
        }

        $routes = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $r) => in_array('GET', $r->methods(), true)
                && ! str_contains($r->uri(), '{')
                && in_array('web', $r->gatherMiddleware(), true)
                && in_array('auth', $r->gatherMiddleware(), true)
                && ! str_contains($r->uri(), 'logout'));

        $matrix = [];
        $errors = [];

        foreach ($routes as $route) {
            $uri = '/'.ltrim($route->uri(), '/');
            $permission = collect($route->gatherMiddleware())
                ->first(fn ($m) => is_string($m) && str_contains($m, 'ActivePermissionMiddleware:'));
            $permission = $permission ? explode(':', $permission, 2)[1] : null;

            foreach ($users as $roleName => $user) {
                $this->app['auth']->forgetGuards();
                try {
                    $status = $this->actingAs($user)->get($uri)->getStatusCode();
                } catch (Throwable $e) {
                    $status = 'EXC '.class_basename($e).': '.str($e->getMessage())->limit(120);
                }

                $matrix[$uri][$roleName] = $status;

                if ($status === 500 || is_string($status)) {
                    $errors[] = "{$roleName} {$uri} → {$status}";
                }

                if ($permission && $status === 200 && ! $user->fresh()->hasActivePermission($permission)) {
                    $errors[] = "{$roleName} {$uri} → 200 without {$permission}";
                }
            }
        }

        $lines = [str_pad('page', 45).implode(' ', array_map(fn ($r) => str_pad($r, 16), self::ROLES))];
        foreach ($matrix as $uri => $row) {
            $lines[] = str_pad($uri, 45).implode(' ', array_map(fn ($r) => str_pad((string) (is_int($row[$r]) ? $row[$r] : 'EXC'), 16), self::ROLES));
        }
        file_put_contents(storage_path('logs/role-route-matrix.txt'), implode(PHP_EOL, array_merge($lines, ['', ...$errors])));

        $this->assertSame([], $errors, implode(PHP_EOL, $errors));
    }
}
