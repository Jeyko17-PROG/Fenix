<?php

namespace Tests\Feature;

use App\Services\WompiService;
use Tests\TestCase;

class WompiCheckoutTest extends TestCase
{
    public function test_checkout_falls_back_to_frontend_when_wompi_is_not_configured(): void
    {
        config()->set('services.wompi.public_key', null);
        $service = new WompiService();

        $url = $service->checkoutUrl(50000, 'LOGIX-REF-123');

        $this->assertStringContainsString('/planes', $url);
        $this->assertStringContainsString('status=error', $url);
        $this->assertStringContainsString('ref=LOGIX-REF-123', $url);
    }
}
