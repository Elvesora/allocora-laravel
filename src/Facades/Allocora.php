<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Laravel\Facades;

use Elvesora\Allocora\Laravel\Contracts\ClientInterface;
use Elvesora\Allocora\Laravel\Resources\PayeesResource;
use Elvesora\Allocora\Laravel\Resources\ProductsResource;
use Elvesora\Allocora\Laravel\Resources\RevenuesResource;
use Elvesora\Allocora\Laravel\Resources\RulesResource;
use Illuminate\Support\Facades\Facade;

/**
 * @method static PayeesResource payees()
 * @method static ProductsResource products()
 * @method static RevenuesResource revenues()
 * @method static RulesResource rules()
 */
final class Allocora extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ClientInterface::class;
    }
}
