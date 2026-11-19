<?php
declare(strict_types=1);

namespace StoreOnCall\Agent\Console\Command;

use StoreOnCall\Agent\Model\Agent;
use StoreOnCall\Agent\Model\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * For deploy scripts: asks StoreOnCall to check the store right now (it opens, pages
 * load, search works, checkout reaches payment) and waits for the result.
 * Exit code 0 when the checks pass, 1 when a check fails. If the checks could not
 * finish (for example a firewall blocked the test shopper) it prints a warning and
 * exits 0, unless --strict is given (then 2), so a firewall never blocks a release.
 */
class Verify extends Command
{
    public function __construct(private readonly Config $config, private readonly Agent $agent)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('storeoncall:verify')
            ->setDescription('After a deployment: ask StoreOnCall to check the store now and wait for the result')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'A short note, such as the release or commit')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Seconds to wait for the result', '240')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Exit 2 when the checks could not finish');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = $this->config->key();
        if (!$key) {
            $output->writeln('<error>No store key. Run bin/magento storeoncall:connect <key> first.</error>');
            return Command::FAILURE;
        }
        $note = $input->getOption('note');
        $output->writeln('Asking StoreOnCall to check the store. This usually takes under a minute...');
        try {
            $r = $this->agent->verify($key, is_string($note) ? $note : null, (int)$input->getOption('timeout'));
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return $input->getOption('strict') ? 2 : Command::SUCCESS;
        }
        $output->writeln($r['summary']);
        if ($r['status'] === 'passed') {
            $output->writeln('<info>All after-deploy checks passed.</info>');
            return Command::SUCCESS;
        }
        if ($r['status'] === 'failed') {
            $output->writeln('<error>An after-deploy check failed. See the details above.</error>');
            return Command::FAILURE;
        }
        $output->writeln('<comment>The checks could not finish (' . $r['status'] . '). The release is not blocked.</comment>');
        return $input->getOption('strict') ? 2 : Command::SUCCESS;
    }
}
