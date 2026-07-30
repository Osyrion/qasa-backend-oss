<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Support\OwnerConnection;
use App\Modules\Shared\Support\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

abstract class TestCase extends BaseTestCase
{
    /**
     * Run the edition's seeders after RefreshDatabase migrates — SaaS
     * policies depend on the spatie role/permission catalog existing.
     */
    protected bool $seed = true;

    protected string $seeder = DatabaseSeeder::class;

    /** Account the test is acting as, restored after each request. */
    private ?string $actingAccountId = null;

    /**
     * Every test starts unbound, the way a fresh request does.
     *
     * The binding is a session variable, not transactional state, so
     * RefreshDatabase's rollback does not touch it — without this it would
     * survive into the next test on the same connection and that test would
     * be setting up data for one account while bound to another.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // The application is rebuilt for every test, so the owner connection
        // goes back to the shared database each time while --parallel keeps
        // the default one pointed at this worker's. Only migrateFreshUsing()
        // reconciles them, and that runs once per worker.
        OwnerConnection::followDefaultDatabase();

        TenantContext::clear();
        $this->actingAccountId = null;
    }

    /**
     * Binds the database connection to whoever the test is acting as.
     *
     * In a real request the auth middleware does this. actingAs() bypasses
     * middleware entirely, so without this a test would act as one account
     * while the connection stayed bound to another — and Row Level Security
     * would refuse the very rows the test is about.
     *
     * AdminUser authenticates through its own guard and owns no account, so
     * the instanceof leaves admin tests unbound.
     */
    public function actingAs(Authenticatable $user, $guard = null): static
    {
        parent::actingAs($user, $guard);

        if ($user instanceof User) {
            $this->actingAccountId = $user->accountOwnerId();
            TenantContext::set($this->actingAccountId);
        }

        return $this;
    }

    /**
     * Restores the test's own binding once a request has been handled.
     *
     * The middleware clears on terminate, which is exactly right for
     * production — but it also runs inside a test, so anything the test
     * asserts afterwards would query an unbound connection and see nothing.
     * Rebinding here keeps the assertions looking at the account the test is
     * acting as, without weakening what terminate() does.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $server
     * @return TestResponse<Response>
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);

        if ($this->actingAccountId !== null) {
            TenantContext::set($this->actingAccountId);
        }

        return $response;
    }
}
