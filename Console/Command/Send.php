<?php
declare(strict_types=1);

namespace StoreOnCall\Agent\Console\Command;

use StoreOnCall\Agent\Model\Agent;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Send extends Command
{
    public function __construct(private readonly Agent $agent)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('storeoncall:send')->setDescription('Send a health report to StoreOnCall now');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $r = $this->agent->send();
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $output->writeln($r['ok'] ? '<info>Sent.</info>' : '<error>' . $r['message'] . '</error>');
        return $r['ok'] ? Command::SUCCESS : Command::FAILURE;
    }
}
