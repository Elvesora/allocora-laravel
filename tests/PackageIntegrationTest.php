<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Laravel\Tests;

use Elvesora\Allocora\Laravel\AllocoraServiceProvider;
use Elvesora\Allocora\Laravel\Client;
use Elvesora\Allocora\Laravel\Contracts\ClientInterface;
use Elvesora\Allocora\Laravel\Exceptions\ConfigurationException;
use Elvesora\Allocora\Laravel\Facades\Allocora;
use Illuminate\Support\ServiceProvider;
use LogicException;

final class PackageIntegrationTest extends TestCase
{
    public function test_service_provider_binds_contract_concrete_client_and_facade(): void
    {
        $contract = $this->app->make(ClientInterface::class);
        $concrete = $this->app->make(Client::class);

        self::assertInstanceOf(Client::class, $contract);
        self::assertSame($contract, $concrete);
        self::assertSame($contract->payees(), Allocora::payees());
    }

    public function test_configuration_is_publishable_under_the_documented_tag(): void
    {
        $paths = ServiceProvider::pathsToPublish(AllocoraServiceProvider::class, 'allocora-config');

        self::assertCount(1, $paths);
        self::assertSame(dirname(__DIR__).'/config/allocora.php', array_key_first($paths));
        self::assertStringEndsWith('config'.DIRECTORY_SEPARATOR.'allocora.php', (string) array_values($paths)[0]);
    }

    public function test_missing_or_invalid_configuration_fails_when_the_client_is_resolved(): void
    {
        $invalidValues = [null, '', '0.5'];

        foreach ($invalidValues as $value) {
            $this->app->forgetScopedInstances();
            $this->app['config']->set('allocora.api_key', self::API_KEY);
            $this->app['config']->set('allocora.max_response_bytes', 10_485_760);

            if ($value === null || $value === '') {
                $this->app['config']->set('allocora.api_key', $value);
            } else {
                $this->app['config']->set('allocora.max_response_bytes', $value);
            }

            try {
                $this->app->make(ClientInterface::class);
                self::fail('Expected invalid configuration to fail.');
            } catch (ConfigurationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_client_credentials_are_redacted_and_serialization_is_blocked(): void
    {
        $client = $this->app->make(ClientInterface::class);
        $debug = print_r($client, true);

        self::assertStringNotContainsString(self::API_KEY, $debug);
        self::assertStringContainsString('[REDACTED]', $debug);
        self::assertFalse(method_exists($client, 'request'));

        $this->expectException(LogicException::class);
        serialize($client);
    }

    public function test_invalid_configuration_traces_redact_the_container_and_config_repository(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($previous);
        $allocoraApiKey = 'fictional_allocora_api_key_for_trace_testing';
        $privateBaseUrl = 'https://private-tenant.example.test/api/v1';
        $siblingApiKey = 'fictional_sibling_service_api_key_for_trace_testing';

        $this->app['config']->set('allocora.api_key', $allocoraApiKey);
        $this->app['config']->set('allocora.base_url', $privateBaseUrl);
        $this->app['config']->set('allocora.timeout', 'not-a-number');
        $this->app['config']->set('services.sibling.api_key', $siblingApiKey);
        $this->app->forgetScopedInstances();

        try {
            $this->app->make(ClientInterface::class, ['fictional_override' => $siblingApiKey]);
            self::fail('Expected invalid configuration to fail.');
        } catch (ConfigurationException $exception) {
            $sdkTrace = array_values(array_filter(
                $exception->getTrace(),
                static fn (array $frame): bool => is_string($frame['class'] ?? null)
                    && str_starts_with($frame['class'], 'Elvesora\\Allocora\\Laravel\\'),
            ));
            $trace = print_r($sdkTrace, true);

            self::assertStringNotContainsString($allocoraApiKey, $trace);
            self::assertStringNotContainsString($privateBaseUrl, $trace);
            self::assertStringNotContainsString($siblingApiKey, $trace);
            self::assertStringContainsString('SensitiveParameterValue', $trace);
            self::assertContains('requiredFloat', array_column($sdkTrace, 'function'));
            self::assertGreaterThanOrEqual(3, substr_count($trace, 'SensitiveParameterValue'));
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }
}
