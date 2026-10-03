<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ErrorPageTest extends TestCase
{
    public function test_unknown_web_routes_show_the_custom_404_page(): void
    {
        $this->get('/does-not-exist/nested-page')
            ->assertNotFound()
            ->assertSee('Page not found')
            ->assertSee('Go to workspace')
            ->assertSee('uddog');
    }

    public function test_api_errors_remain_json(): void
    {
        $response = $this->get('/api/does-not-exist');

        $response->assertNotFound()->assertJsonPath('message', 'The route api/does-not-exist could not be found.');
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
    }

    public function test_common_and_fallback_statuses_use_branded_pages(): void
    {
        config()->set('app.debug', false);
        Route::get('/error-preview/{status}', fn (int $status) => abort($status))->whereNumber('status');

        foreach ([401, 402, 403, 404, 419, 429, 500, 503, 418, 502] as $status) {
            $this->get("/error-preview/{$status}")
                ->assertStatus($status)
                ->assertSee("Error {$status}")
                ->assertSee('Go to workspace')
                ->assertSee('data-bn=', false);
        }

        $this->post('/')->assertStatus(405)->assertSee('Request could not be completed');
    }

    public function test_server_error_page_does_not_expose_exception_details(): void
    {
        config()->set('app.debug', false);
        Route::get('/error-preview/private', fn () => throw new RuntimeException('private database password is secret'));

        $this->get('/error-preview/private')
            ->assertStatus(500)
            ->assertSee('Something went wrong')
            ->assertDontSee('private database password is secret');
    }
}
