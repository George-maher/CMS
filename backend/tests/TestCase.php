<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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
}
