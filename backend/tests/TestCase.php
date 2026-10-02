<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;

abstract class TestCase extends BaseTestCase
{
    /**
     * Disable console output mocking to prevent Mockery from throwing
     * BadMethodCallException when Laravel Prompts fallback callbacks
     * (registered by ConfigurePrompts for unit tests on Windows) invoke
     * $this->output->confirm() -> $this->askQuestion() on the mocked
     * OutputStyle without any expectation being set.
     */
    public $mockConsoleOutput = false;

    /**
     * Reset cached auth guards after every simulated request.
     *
     * Sanctum's guard is a RequestGuard that permanently caches the first
     * resolved user ($this->user). The framework only refreshes the request
     * instance between simulated requests (app()->refresh('request', ...)),
     * so without this reset every subsequent request in the same test would
     * inherit the FIRST request's identity regardless of its Bearer token.
     * Production is unaffected — each real request runs in a fresh process.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);

        Auth::shouldUse('sanctum');
        Auth::forgetGuards();

        return $response;
    }
}
