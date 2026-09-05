<?php
namespace Tests\Feature\Api;
use Tests\TestCase;
class HealthTest extends TestCase
{
    public function test_versioned_health_endpoint(): void { $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('status', 'ok'); }
}
