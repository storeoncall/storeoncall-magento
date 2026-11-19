<?php
declare(strict_types=1);

namespace StoreOnCall\Agent\Console\Command;

use StoreOnCall\Agent\Model\Agent;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Prints exactly what would be sent, without sending it. */
class Preview extends Command
{
    public function __construct(private readonly Agent $agent)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('storeoncall:preview')->setDescription('Print the exact data StoreOnCall would send (nothing is sent)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln((string)json_encode($this->agent->preview(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return Command::SUCCESS;
    }
}
