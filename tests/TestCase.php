<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Laravel\Tests;

use Elvesora\Allocora\Laravel\AllocoraServiceProvider;
use Illuminate\Support\Facades\Facade;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected const API_KEY = 'test_allocora_api_key_not_a_real_credential';

    protected const BASE_URL = 'https://api.example.test/api/v1';

    protected const RESOURCE_ID = '11111111-1111-4111-8111-111111111111';

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [AllocoraServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('allocora', [
            'api_key' => self::API_KEY,
            'base_url' => self::BASE_URL,
            'timeout' => 30,
            'connect_timeout' => 5,
            'max_response_bytes' => 10_485_760,
        ]);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();

        parent::tearDown();
    }
}
