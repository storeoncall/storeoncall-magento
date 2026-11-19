<?php
declare(strict_types=1);

namespace StoreOnCall\Agent\Model;

/** Facts about the installation that do not come from SQL. */
interface EnvironmentInterface
{
    public function magentoVersion(): string;
    public function edition(): string;
    public function mode(): string;
    public function asyncEmail(): bool;
    public function baseUrl(): string;
    public function timezone(): string;
    public function baseCurrency(): string;
    /** @return array<int, array{type: string, enabled: bool, invalidated: bool}> */
    public function cacheTypes(): array;
    /** @return array<int, array{name: string, version: string}> installed Magento module packages */
    public function modules(): array;
    public function deployedVersion(): ?string;
}
