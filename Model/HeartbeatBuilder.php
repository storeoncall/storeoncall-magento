<?php
declare(strict_types=1);

namespace StoreOnCall\Agent\Model;

use StoreOnCall\Agent\Model\Collector\SqlCollector;

/**
 * Builds the heartbeat: counts, statuses and job codes only. Every part is
 * collected separately, so one failing part never stops the rest.
 */
class HeartbeatBuilder
{
    public const AGENT_VERSION = '0.1.2';

    public function __construct(
        private readonly SqlCollector $sql,
        private readonly EnvironmentInterface $env
    ) {
    }

    private function safe(callable $fn, mixed $fallback): mixed
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return $fallback;
        }
    }

    public function build(\DateTimeImmutable $now, ?\DateTimeImmutable $lastSent = null): array
    {
        $modules = $this->safe(fn () => $this->env->modules(), []);
        $hb = [
            'agent' => [
                'version' => self::AGENT_VERSION,
                'platform' => 'magento',
                'magento_version' => $this->safe(fn () => $this->env->magentoVersion(), null),
                'edition' => $this->safe(fn () => $this->env->edition(), null),
                'php' => PHP_VERSION,
                'mode' => $this->safe(fn () => $this->env->mode(), null),
                'async_email' => $this->safe(fn () => $this->env->asyncEmail(), false),
            ],
            'store' => [
                'base_url' => $this->safe(fn () => $this->env->baseUrl(), null),
                'timezone' => $this->safe(fn () => $this->env->timezone(), null),
                'currency' => $this->safe(fn () => $this->env->baseCurrency(), null),
            ],
            'sent_at' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'cron' => $this->safe(fn () => $this->sql->cron($now), [
                'last_success_at' => null, 'last_run_at' => null, 'pending' => 0, 'running' => 0,
                'errors_last_hour' => 0, 'missed_last_hour' => 0, 'stuck_running' => 0, 'failed_jobs' => [],
            ]),
            'indexers' => $this->safe(fn () => $this->sql->indexers(), []),
            'queues' => $this->safe(fn () => $this->sql->queues(), []),
            'orders' => $this->safe(fn () => $this->sql->orders($now), []),
            'payments' => $this->safe(fn () => $this->sql->payments($now), []),
            'emails' => ['unsent_order_emails' => $this->safe(fn () => $this->sql->unsentOrderEmails($now), 0)],
            'cache' => $this->safe(fn () => $this->env->cacheTypes(), []),
            'modules' => $modules,
            'modules_hash' => sha1((string)json_encode($modules)),
            'deployed_version' => $this->safe(fn () => $this->env->deployedVersion(), null),
            'config_changes' => $this->safe(fn () => $this->sql->configChanges($lastSent), []),
        ];
        // Catalog health is read once an hour (the first report of each hour).
        if ($lastSent === null || $lastSent->format('YmdH') !== $now->format('YmdH')) {
            $staging = (bool)array_filter($modules, static fn ($m) => ($m['name'] ?? '') === 'Magento_CatalogStaging');
            $catalog = $this->safe(fn () => $this->sql->catalog($now, $staging), []);
            if ($catalog) {
                $hb['catalog'] = $catalog;
            }
        }
        // Remove empty optional values so the server's strict checks see only real data.
        foreach (['magento_version', 'edition', 'mode'] as $k) {
            if ($hb['agent'][$k] === null) {
                unset($hb['agent'][$k]);
            }
        }
        foreach (['base_url', 'timezone', 'currency'] as $k) {
            if ($hb['store'][$k] === null || $hb['store'][$k] === '') {
                unset($hb['store'][$k]);
            }
        }
        if ($hb['deployed_version'] === null || $hb['deployed_version'] === '') {
            unset($hb['deployed_version']);
        }
        return $hb;
    }
}
