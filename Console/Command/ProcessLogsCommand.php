<?php
namespace Gaurav\LogMonitor\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Gaurav\LogMonitor\Model\LogReader;
use Magento\Framework\App\ResourceConnection;

class ProcessLogsCommand extends Command
{
    protected $logReader;
    protected $resourceConnection;

    public function __construct(
        LogReader $logReader,
        ResourceConnection $resourceConnection
    ) {
        $this->logReader = $logReader;
        $this->resourceConnection = $resourceConnection;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('logmonitor:process')
             ->setDescription('Manually scan Magento log files for new errors.')
             ->addOption('reset', 'r', InputOption::VALUE_NONE, 'Force a full rescan by resetting all saved byte offsets to 0');
        
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // If the developer passes --reset, wipe the state tracking table
        if ($input->getOption('reset')) {
            $output->writeln('<comment>Resetting all file tracking states to 0...</comment>');
            $connection = $this->resourceConnection->getConnection();
            $stateTable = $this->resourceConnection->getTableName('gaurav_logmonitor_state');
            $connection->truncateTable($stateTable);
        }

        $output->writeln('<info>Starting log scan...</info>');
        
        try {
            $this->logReader->execute();
            $output->writeln('<info>Log scan completed successfully. Check the Admin Dashboard for new alerts.</info>');
            return \Magento\Framework\Console\Cli::RETURN_SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>An error occurred: ' . $e->getMessage() . '</error>');
            return \Magento\Framework\Console\Cli::RETURN_FAILURE;
        }
    }
}