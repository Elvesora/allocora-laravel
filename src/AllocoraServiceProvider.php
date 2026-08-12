<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Laravel;

use Elvesora\Allocora\Laravel\Contracts\ClientInterface;
use Elvesora\Allocora\Laravel\Exceptions\ConfigurationException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

final class AllocoraServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'allocora');

        $this->app->scoped(ClientInterface::class, static function (
            #[\SensitiveParameter]
            Container $container,
            #[\SensitiveParameter]
            array $_parameters = [],
        ): ClientInterface {
            $config = $container->make(ConfigRepository::class);

            return new Client(
                http: $container->make(Factory::class),
                apiKey: self::requiredString($config, 'allocora.api_key'),
                baseUrl: self::requiredString($config, 'allocora.base_url'),
                timeout: self::requiredFloat($config, 'allocora.timeout'),
                connectTimeout: self::requiredFloat($config, 'allocora.connect_timeout'),
                maxResponseBytes: self::requiredInteger($config, 'allocora.max_response_bytes'),
            );
        });

        $this->app->alias(ClientInterface::class, Client::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            $this->configPath() => config_path('allocora.php'),
        ], 'allocora-config');
    }

    private function configPath(): string
    {
        return dirname(__DIR__).'/config/allocora.php';
    }

    private static function requiredString(#[\SensitiveParameter] ConfigRepository $config, string $key): string
    {
        $value = $config->get($key);

        if (! is_string($value) || trim($value) === '') {
            throw new ConfigurationException(sprintf('The configuration value "%s" must be a non-empty string.', $key));
        }

        return $value;
    }

    private static function requiredFloat(#[\SensitiveParameter] ConfigRepository $config, string $key): float
    {
        $value = $config->get($key);

        if (! is_int($value) && ! is_float($value) && ! is_numeric($value)) {
            throw new ConfigurationException(sprintf('The configuration value "%s" must be numeric.', $key));
        }

        return (float) $value;
    }

    private static function requiredInteger(#[\SensitiveParameter] ConfigRepository $config, string $key): int
    {
        $value = $config->get($key);

        $validated = filter_var($value, FILTER_VALIDATE_INT);

        if ($validated === false) {
            throw new ConfigurationException(sprintf('The configuration value "%s" must be an integer.', $key));
        }

        return $validated;
    }
}
