<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** @param array<string,mixed> $data @return array<string,mixed> */
    protected function checkoutData(array $data, ?int $orderId = null): array
    {
        $path = $orderId ? '/api/v1/customer/orders/'.$orderId.'/checkout' : '/api/v1/customer/orders/checkout';
        $review = $this->postJson($path, $data)->assertOk()->json();
        $this->assertNotNull($review['checkout_token']);

        return [...$data, 'checkout_token' => $review['checkout_token']];
    }
}
