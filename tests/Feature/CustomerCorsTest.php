<?php

namespace Tests\Feature;

use Tests\TestCase;

class CustomerCorsTest extends TestCase
{
    public function test_allowed_portal_can_preflight_deleting_its_saved_sender_address(): void
    {
        config(['cors.allowed_origins' => ['https://portal.example.test']]);

        $response = $this->withHeaders([
            'Origin' => 'https://portal.example.test',
            'Access-Control-Request-Method' => 'DELETE',
            'Access-Control-Request-Headers' => 'content-type,x-csrf-token',
        ])->options('/api/v1/customer/sender-address');

        $response->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://portal.example.test')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->assertContains('DELETE', array_map('trim', explode(',', $response->headers->get('Access-Control-Allow-Methods', ''))));
        $this->assertContains('x-csrf-token', array_map('trim', explode(',', strtolower($response->headers->get('Access-Control-Allow-Headers', '')))));
    }

    public function test_untrusted_origin_cannot_preflight_customer_writes(): void
    {
        config(['cors.allowed_origins' => ['https://portal.example.test']]);

        $this->withHeaders([
            'Origin' => 'https://untrusted.example.test',
            'Access-Control-Request-Method' => 'DELETE',
            'Access-Control-Request-Headers' => 'content-type,x-csrf-token',
        ])->options('/api/v1/customer/sender-address')
            ->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'https://portal.example.test');
    }
}
