<?php

namespace Tests;

use App\Models\User;
use App\Support\StaffAuthentication;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function actingAs(Authenticatable $user, $guard = null): static
    {
        parent::actingAs($user, $guard);
        $this->withSession(['password_hash_'.($guard ?? config('auth.defaults.guard')) => $user->getAuthPassword()]);

        if (($guard ?? config('auth.defaults.guard')) === 'web' && $user instanceof User && $user->isStaff()) {
            request()->setLaravelSession(app('session.store'));
            app(StaffAuthentication::class)->begin(request(), $user);
            app('session.store')->save();
        }

        return $this;
    }

    public function withSession(array $data): static
    {
        parent::withSession($data);
        app('session.store')->save();

        return $this;
    }

    protected function prepareCookiesForRequest(): array
    {
        $this->defaultCookies[config('session.cookie')] = app('session.store')->getId();

        return parent::prepareCookiesForRequest();
    }

    protected function prepareCookiesForJsonRequest(): array
    {
        return $this->prepareCookiesForRequest();
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    protected function staffCheckoutData(array $data): array
    {
        $review = $this->post('/orders', $data)->assertOk()->assertViewIs('orders.checkout')->viewData('review');
        $this->assertNotNull($review['checkout_token']);

        return [...$review['data'], 'checkout_token' => $review['checkout_token']];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    protected function checkoutData(array $data, ?int $orderId = null): array
    {
        $path = $orderId ? '/api/v1/customer/orders/'.$orderId.'/checkout' : '/api/v1/customer/orders/checkout';
        $review = $this->postJson($path, $data)->assertOk()->json();
        $this->assertNotNull($review['checkout_token']);

        return [...$data, 'checkout_token' => $review['checkout_token']];
    }
}
