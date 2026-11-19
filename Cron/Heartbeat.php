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
            if (!$r['ok']) {
                $this->logger->warning('StoreOnCall: ' . $r['message']);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('StoreOnCall: ' . $e->getMessage());
        }
    }
}
