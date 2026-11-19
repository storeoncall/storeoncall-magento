<?php
declare(strict_types=1);

namespace StoreOnCall\Agent\Console\Command;

use Magento\Framework\App\Cache\TypeListInterface;
use StoreOnCall\Agent\Model\Agent;
use StoreOnCall\Agent\Model\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Connect extends Command
{
    public function __construct(private readonly Config $config, private readonly Agent $agent, private readonly TypeListInterface $cache)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('storeoncall:connect')
            ->setDescription('Connect this store to StoreOnCall with your store key')
            ->addArgument('key', InputArgument::REQUIRED, 'The store key from StoreOnCall (starts with soc_)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = trim((string)$input->getArgument('key'));
        if (!preg_match('/^soc_[A-Za-z0-9_-]{20,}$/', $key)) {
            $output->writeln('<error>That does not look like a StoreOnCall store key. Copy it again from StoreOnCall.</error>');
            return Command::FAILURE;
        }
        try {
            $ping = $this->agent->ping($key);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        if (!$ping['ok']) {
            $output->writeln('<error>' . $ping['message'] . '</error>');
            return Command::FAILURE;
        }
        $this->config->saveKey($key);
        $this->cache->cleanType('config');
        $output->writeln('<info>' . $ping['message'] . '</info>');
        $send = $this->agent->send();
        $output->writeln($send['ok'] ? '<info>First health report sent. Your StoreOnCall dashboard updates within a minute.</info>' : '<comment>' . $send['message'] . '</comment>');
        $output->writeln('From now on, Magento cron sends a report every 5 minutes. See exactly what is sent with: bin/magento storeoncall:preview');
        return Command::SUCCESS;
    }
}
