<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Laravel;

use Elvesora\Allocora\Laravel\Contracts\ClientInterface;
use Elvesora\Allocora\Laravel\Resources\PayeesResource;
use Elvesora\Allocora\Laravel\Resources\ProductsResource;
use Elvesora\Allocora\Laravel\Resources\RevenuesResource;
use Elvesora\Allocora\Laravel\Resources\RulesResource;
use Elvesora\Allocora\Laravel\Support\Transport;
use Illuminate\Http\Client\Factory;
use LogicException;

final class Client implements ClientInterface
{
    public const VERSION = '0.1.0';

    private readonly Transport $transport;

    private readonly PayeesResource $payees;

    private readonly ProductsResource $products;

    private readonly RevenuesResource $revenues;

    private readonly RulesResource $rules;

    public function __construct(
        #[\SensitiveParameter]
        Factory $http,
        #[\SensitiveParameter]
        string $apiKey,
        #[\SensitiveParameter]
        string $baseUrl = 'https://www.allocora.com/api/v1',
        float $timeout = 30,
        float $connectTimeout = 5,
        int $maxResponseBytes = 10_485_760,
    ) {
        $this->transport = new Transport($http, $apiKey, $baseUrl, $timeout, $connectTimeout, $maxResponseBytes);
        $this->payees = new PayeesResource($this->transport);
        $this->products = new ProductsResource($this->transport);
        $this->revenues = new RevenuesResource($this->transport);
        $this->rules = new RulesResource($this->transport);
    }

    public function payees(): PayeesResource
    {
        return $this->payees;
    }

    public function products(): ProductsResource
    {
        return $this->products;
    }

    public function revenues(): RevenuesResource
    {
        return $this->revenues;
    }

    public function rules(): RulesResource
    {
        return $this->rules;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'api_key' => '[REDACTED]',
            'version' => self::VERSION,
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('The Allocora client cannot be serialized.');
    }
}
