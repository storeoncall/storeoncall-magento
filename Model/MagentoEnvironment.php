<?php
declare(strict_types=1);

namespace StoreOnCall\Agent\Model;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\State;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Store\Model\StoreManagerInterface;

class MagentoEnvironment implements EnvironmentInterface
{
    public function __construct(
        private readonly ProductMetadataInterface $metadata,
        private readonly State $state,
        private readonly ScopeConfigInterface $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly TypeListInterface $cacheTypes,
        private readonly ModuleListInterface $moduleList,
        private readonly DirectoryList $directoryList
    ) {
    }

    public function magentoVersion(): string
    {
        return $this->metadata->getVersion();
    }

    public function edition(): string
    {
        return $this->metadata->getEdition();
    }

    public function mode(): string
    {
        return $this->state->getMode();
    }

    public function asyncEmail(): bool
    {
        return (bool)$this->config->getValue('sales_email/general/async_sending');
    }

    public function baseUrl(): string
    {
        return (string)$this->storeManager->getDefaultStoreView()?->getBaseUrl();
    }

    public function timezone(): string
    {
        return (string)($this->config->getValue('general/locale/timezone') ?: 'UTC');
    }

    public function baseCurrency(): string
    {
        return (string)($this->config->getValue('currency/options/base') ?: 'USD');
    }

    public function cacheTypes(): array
    {
        $invalid = array_keys($this->cacheTypes->getInvalidated());
        $out = [];
        foreach ($this->cacheTypes->getTypes() as $id => $type) {
            $out[] = ['type' => (string)$id, 'enabled' => (bool)$type->getData('status'), 'invalidated' => in_array($id, $invalid, true)];
        }
        return $out;
    }

    public function modules(): array
    {
        $out = [];
        if (class_exists(\Composer\InstalledVersions::class)) {
            foreach (\Composer\InstalledVersions::getInstalledPackagesByType('magento2-module') as $package) {
                if (str_starts_with($package, 'magento/')) {
                    continue; // core modules change only with a Magento upgrade, which is tracked separately
                }
                $out[$package] = ['name' => $package, 'version' => (string)\Composer\InstalledVersions::getPrettyVersion($package)];
            }
        }
        // Modules in app/code have no Composer version.
        foreach ($this->moduleList->getNames() as $name) {
            if (!str_starts_with($name, 'Magento_') && !isset($out[$name])) {
                $out[$name] = ['name' => $name, 'version' => (string)($this->moduleList->getOne($name)['setup_version'] ?? 'app-code')];
            }
        }
        ksort($out);
        return array_values($out);
    }

    public function deployedVersion(): ?string
    {
        $file = $this->directoryList->getPath(DirectoryList::STATIC_VIEW) . '/deployed_version.txt';
        return is_readable($file) ? trim((string)file_get_contents($file)) : null;
    }
}
