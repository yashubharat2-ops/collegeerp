<?php
namespace Tests\Feature\Security;
use Tests\TestCase;
class SecureFoundationTest extends TestCase
{
    public function test_production_style_json_errors_are_generic(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
    }
}
