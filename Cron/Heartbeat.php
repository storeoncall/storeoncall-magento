<?php
declare(strict_types=1);

namespace StoreOnCall\Agent\Cron;

use Psr\Log\LoggerInterface;
use StoreOnCall\Agent\Model\Agent;

/** Runs every 5 minutes from Magento cron. Never throws: monitoring must not break the store. */
class Heartbeat
{
    public function __construct(private readonly Agent $agent, private readonly LoggerInterface $logger)
    {
    }

    public function execute(): void
    {
        try {
            $r = $this->agent->send();
            // A lasting problem (for example the plan ended, or the key was replaced) is logged when it starts, not every 5 minutes.
            if (!$r['ok'] && ($r['changed'] ?? true)) {
                $this->logger->warning('StoreOnCall: ' . $r['message']);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('StoreOnCall: ' . $e->getMessage());
        }
    }
}
