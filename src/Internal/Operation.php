<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Laravel\Internal;

/** @internal */
enum Operation
{
    case PayeesIndex;
    case PayeesShow;
    case PayeesBatchStore;
    case PayeesBatchUpsert;
    case PayeesStore;
    case PayeesUpsert;
    case PayeesBatchUpdate;
    case PayeesUpdate;
    case ProductsIndex;
    case ProductsShow;
    case ProductsBatchStore;
    case ProductsBatchUpsert;
    case ProductsStore;
    case ProductsUpsert;
    case ProductsBatchUpdate;
    case ProductsUpdate;
    case ProductsBatchDestroy;
    case ProductsDestroy;
    case RevenuesIndex;
    case RevenuesShow;
    case RevenuesBatchStore;
    case RevenuesStore;
    case RulesIndex;
    case RulesShow;
    case RulesBatchStore;
    case RulesBatchUpsert;
    case RulesStore;
    case RulesUpsert;
    case RulesBatchUpdate;
    case RulesUpdate;
    case RulesToggleStatus;
    case RulesVersions;

    public function routeName(): string
    {
        return $this->definition()[0];
    }

    public function method(): string
    {
        return $this->definition()[1];
    }

    public function path(): string
    {
        return $this->definition()[2];
    }

    public function permission(): string
    {
        return $this->definition()[3];
    }

    public function requiresIdentifier(): bool
    {
        return str_contains($this->path(), '{id}');
    }

    /** @return array{string, 'GET'|'POST'|'PUT'|'DELETE', string, string} */
    private function definition(): array
    {
        return match ($this) {
            self::PayeesIndex => ['api.v1.payees.index', 'GET', '/payees', 'read:payees'],
            self::PayeesShow => ['api.v1.payees.show', 'GET', '/payees/{id}', 'read:payees'],
            self::PayeesBatchStore => ['api.v1.payees.batch-store', 'POST', '/payees/batch', 'write:payees'],
            self::PayeesBatchUpsert => ['api.v1.payees.batch-upsert', 'POST', '/payees/batch/upsert', 'write:payees'],
            self::PayeesStore => ['api.v1.payees.store', 'POST', '/payees', 'write:payees'],
            self::PayeesUpsert => ['api.v1.payees.upsert', 'POST', '/payees/upsert', 'write:payees'],
            self::PayeesBatchUpdate => ['api.v1.payees.batch-update', 'PUT', '/payees/batch', 'write:payees'],
            self::PayeesUpdate => ['api.v1.payees.update', 'PUT', '/payees/{id}', 'write:payees'],
            self::ProductsIndex => ['api.v1.products.index', 'GET', '/products', 'read:products'],
            self::ProductsShow => ['api.v1.products.show', 'GET', '/products/{id}', 'read:products'],
            self::ProductsBatchStore => ['api.v1.products.batch-store', 'POST', '/products/batch', 'write:products'],
            self::ProductsBatchUpsert => ['api.v1.products.batch-upsert', 'POST', '/products/batch/upsert', 'write:products'],
            self::ProductsStore => ['api.v1.products.store', 'POST', '/products', 'write:products'],
            self::ProductsUpsert => ['api.v1.products.upsert', 'POST', '/products/upsert', 'write:products'],
            self::ProductsBatchUpdate => ['api.v1.products.batch-update', 'PUT', '/products/batch', 'write:products'],
            self::ProductsUpdate => ['api.v1.products.update', 'PUT', '/products/{id}', 'write:products'],
            self::ProductsBatchDestroy => ['api.v1.products.batch-destroy', 'DELETE', '/products/batch', 'write:products'],
            self::ProductsDestroy => ['api.v1.products.destroy', 'DELETE', '/products/{id}', 'write:products'],
            self::RevenuesIndex => ['api.v1.revenues.index', 'GET', '/revenues', 'read:revenues'],
            self::RevenuesShow => ['api.v1.revenues.show', 'GET', '/revenues/{id}', 'read:revenues'],
            self::RevenuesBatchStore => ['api.v1.revenues.batch-store', 'POST', '/revenues/batch', 'write:revenues'],
            self::RevenuesStore => ['api.v1.revenues.store', 'POST', '/revenues', 'write:revenues'],
            self::RulesIndex => ['api.v1.rules.index', 'GET', '/rules', 'read:rules'],
            self::RulesShow => ['api.v1.rules.show', 'GET', '/rules/{id}', 'read:rules'],
            self::RulesBatchStore => ['api.v1.rules.batch-store', 'POST', '/rules/batch', 'write:rules'],
            self::RulesBatchUpsert => ['api.v1.rules.batch-upsert', 'POST', '/rules/batch/upsert', 'write:rules'],
            self::RulesStore => ['api.v1.rules.store', 'POST', '/rules', 'write:rules'],
            self::RulesUpsert => ['api.v1.rules.upsert', 'POST', '/rules/upsert', 'write:rules'],
            self::RulesBatchUpdate => ['api.v1.rules.batch-update', 'PUT', '/rules/batch', 'write:rules'],
            self::RulesUpdate => ['api.v1.rules.update', 'PUT', '/rules/{id}', 'write:rules'],
            self::RulesToggleStatus => ['api.v1.rules.toggle-status', 'POST', '/rules/{id}/toggle-status', 'write:rules'],
            self::RulesVersions => ['api.v1.rules.versions', 'GET', '/rules/{id}/versions', 'read:rules'],
        };
    }
}
