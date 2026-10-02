<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/health');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'healthy');
    }

    public function test_health_returns_503_when_database_is_unreachable(): void
    {
        // Monitoring must fail closed: a degraded dependency has to produce a
        // non-2xx status so orchestrators/load balancers pull the instance.
        config(['database.default' => 'nonexistent-connection']);

        $response = $this->get('/health');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('database', 'disconnected');
    }

    public function test_storage_route_serves_files_inside_public_directory(): void
    {
        Storage::disk('public')->put('route-tests/hello.txt', 'hello');

        $this->get('/storage/route-tests/hello.txt')->assertOk();
    }

    public function test_storage_route_rejects_path_traversal(): void
    {
        // storage_path('app/public/'.$path) must never escape the public
        // directory — realpath containment has to refuse `..` segments.
        $this->get('/storage/../../../.env')->assertNotFound();
    }
}
