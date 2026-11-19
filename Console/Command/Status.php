<?php
declare(strict_types=1);

namespace StoreOnCall\Agent\Console\Command;

use StoreOnCall\Agent\Model\Agent;
use StoreOnCall\Agent\Model\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Status extends Command
{
    public function __construct(private readonly Config $config, private readonly Agent $agent)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('storeoncall:status')->setDescription('Show whether this store is connected to StoreOnCall');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = $this->config->key();
        $output->writeln('Enabled:      ' . ($this->config->enabled() ? 'yes' : 'no'));
        $output->writeln('Store key:    ' . ($key ? '...' . substr($key, -4) . (getenv('STOREONCALL_KEY') ? ' (from STOREONCALL_KEY)' : '') : 'not set'));
        $last = $this->config->lastSent();
        $output->writeln('Last report:  ' . ($last ? $last->format('Y-m-d H:i') . ' UTC (' . $this->config->lastResult() . ')' : 'never'));
        if ($last && (time() - $last->getTimestamp()) > 900) {
            $output->writeln('<comment>No report in the last 15 minutes. Check that Magento cron is running: bin/magento cron:run</comment>');
        }
        if ($key) {
            try {
                $ping = $this->agent->ping($key);
                $output->writeln(($ping['ok'] ? '<info>' : '<error>') . $ping['message'] . ($ping['ok'] ? '</info>' : '</error>'));
                return $ping['ok'] ? Command::SUCCESS : Command::FAILURE;
            } catch (\Throwable $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
                return Command::FAILURE;
            }
        }
        return Command::FAILURE;
    }
}
