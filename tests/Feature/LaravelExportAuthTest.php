<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Feature;

use Alif\Export\Auth\LaravelExportAuth;
use Alif\Export\Contracts\ExportAuth;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Tests\Fixtures\User;
use Alif\Export\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final class LaravelExportAuthTest extends TestCase
{
    private LaravelExportAuth $auth;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auth = new LaravelExportAuth;
        $this->owner = User::create(['name' => 'owner']);
    }

    public function test_provider_binds_the_laravel_implementation(): void
    {
        $this->assertInstanceOf(LaravelExportAuth::class, app(ExportAuth::class));
    }

    // ---- allows ------------------------------------------------------------

    public function test_allows_passes_the_exportable_key_to_the_configured_ability(): void
    {
        $seen = [];
        Gate::define('data-export', function ($user, string $key) use (&$seen): bool {
            $seen[] = [$user->getKey(), $key];

            return $key === 'orders';
        });

        $this->assertTrue($this->auth->allows($this->owner, 'orders'));
        $this->assertFalse($this->auth->allows($this->owner, 'secrets'));
        $this->assertSame([[$this->owner->getKey(), 'orders'], [$this->owner->getKey(), 'secrets']], $seen);
    }

    public function test_allows_honours_a_custom_ability_name(): void
    {
        config(['export.ability' => 'export-anything']);
        Gate::define('data-export', fn (): bool => true);
        Gate::define('export-anything', fn (): bool => false);

        $this->assertFalse($this->auth->allows($this->owner, 'orders'));
    }

    public function test_allows_is_false_when_the_ability_is_not_defined(): void
    {
        $this->assertFalse($this->auth->allows($this->owner, 'orders'));
    }

    public function test_allows_evaluates_for_the_given_user_not_the_session_user(): void
    {
        $other = User::create(['name' => 'other']);
        Gate::define('data-export', fn (User $u): bool => $u->is($this->owner));
        $this->actingAs($other);

        $this->assertTrue($this->auth->allows($this->owner, 'orders'));
        $this->assertFalse($this->auth->allows($other, 'orders'));
    }

    // ---- actingAs ----------------------------------------------------------

    public function test_acting_as_resolves_the_owner_by_id_and_returns_the_callback_result(): void
    {
        $result = $this->auth->actingAs((string) $this->owner->getKey(), function (Authenticatable $user): string {
            $this->assertTrue($user->is($this->owner));

            return 'result';
        });

        $this->assertSame('result', $result);
    }

    public function test_acting_as_signs_the_owner_in_on_the_guard_and_makes_it_the_default_during_the_callback(): void
    {
        $this->auth->actingAs((string) $this->owner->getKey(), function (): void {
            $this->assertSame('web', Auth::getDefaultDriver());
            $this->assertSame($this->owner->getKey(), auth()->id());
            $this->assertSame($this->owner->getKey(), Auth::guard('web')->id());
        });
    }

    public function test_acting_as_signs_out_and_restores_the_default_guard_afterwards(): void
    {
        config(['auth.guards.alt' => ['driver' => 'session', 'provider' => 'users'], 'auth.defaults.guard' => 'alt']);
        Auth::shouldUse('alt');

        $this->auth->actingAs((string) $this->owner->getKey(), fn () => null);

        $this->assertSame('alt', Auth::getDefaultDriver());
        $this->assertFalse(Auth::guard('web')->check());
        $this->assertNull(Auth::guard('web')->user());
    }

    public function test_acting_as_restores_a_previously_signed_in_user(): void
    {
        $previous = User::create(['name' => 'request user']);
        $this->actingAs($previous, 'web');

        $this->auth->actingAs((string) $this->owner->getKey(), function (): void {
            $this->assertSame($this->owner->getKey(), Auth::guard('web')->id());
        });

        $this->assertSame($previous->getKey(), Auth::guard('web')->id());
    }

    public function test_acting_as_restores_state_when_the_callback_throws(): void
    {
        try {
            $this->auth->actingAs((string) $this->owner->getKey(), fn () => throw new RuntimeException('inside'));
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('inside', $e->getMessage());
        }

        $this->assertNull(Auth::guard('web')->user());
    }

    public function test_acting_as_an_unknown_owner_throws_owner_missing_without_running_the_callback(): void
    {
        $ran = false;

        try {
            $this->auth->actingAs('00000000-0000-4000-8000-000000000000', function () use (&$ran): void {
                $ran = true;
            });
            $this->fail('Expected ExportException');
        } catch (ExportException $e) {
            $this->assertSame('owner_missing', $e->errorCode);
        }

        $this->assertFalse($ran);
        $this->assertNull(Auth::guard('web')->user());
    }

    public function test_acting_as_uses_the_default_guard_when_none_is_configured(): void
    {
        config(['export.guard' => null, 'auth.defaults.guard' => 'web']);

        $this->auth->actingAs((string) $this->owner->getKey(), function (): void {
            $this->assertSame($this->owner->getKey(), auth()->id());
        });
    }

    public function test_nested_acting_as_unwinds_to_the_outer_owner(): void
    {
        $inner = User::create(['name' => 'inner']);

        $this->auth->actingAs((string) $this->owner->getKey(), function () use ($inner): void {
            $this->auth->actingAs((string) $inner->getKey(), function () use ($inner): void {
                $this->assertSame($inner->getKey(), auth()->id());
            });

            $this->assertSame($this->owner->getKey(), auth()->id());
        });
    }
}
