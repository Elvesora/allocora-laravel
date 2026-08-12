<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Laravel\Contracts;

use Elvesora\Allocora\Laravel\Resources\PayeesResource;
use Elvesora\Allocora\Laravel\Resources\ProductsResource;
use Elvesora\Allocora\Laravel\Resources\RevenuesResource;
use Elvesora\Allocora\Laravel\Resources\RulesResource;

interface ClientInterface
{
    public function payees(): PayeesResource;

    public function products(): ProductsResource;

    public function revenues(): RevenuesResource;

    public function rules(): RulesResource;
}
