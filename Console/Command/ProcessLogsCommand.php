<?php
namespace Gaurav\LogMonitor\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Gaurav\LogMonitor\Model\LogReader;
use Magento\Framework\Console\Cli;

class ProcessLogsCommand extends Command
{
    /**
     * Injected as LogReader\Proxy (see di.xml): console commands are built on
     * every bin/magento call, so the real reader and its DB dependencies are
     * only created when this command actually runs.
     */
    protected $logReader;

    public function __construct(LogReader $logReader)
    {
        $this->logReader = $logReader;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('logmonitor:process')
             ->setDescription('Manually scan Magento log files for new errors.')
             ->addOption(
                 'reset',
                 'r',
                 InputOption::VALUE_NONE,
                 'Re-read every log (up to its newest 16 MB). Errors already recorded are counted again.'
             );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $reset = (bool)$input->getOption('reset');
        if ($reset) {
            $output->writeln('<comment>Resetting all byte offsets - every log will be re-read (up to its newest 16 MB), '
                . 'and errors already recorded will have their counts increased again.</comment>');
        }

        $output->writeln('<info>Starting log scan...</info>');

        try {
            $result = $this->logReader->execute($reset);
        } catch (\Exception $e) {
            $output->writeln('<error>An error occurred: ' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }

        if ($result === LogReader::RESULT_DISABLED) {
            $output->writeln('<error>Log Monitor is disabled (Stores > Configuration > Gaurav Extensions > '
                . 'Log Monitor > Enable Module). Nothing was scanned.</error>');
            return Cli::RETURN_FAILURE;
        }

        if ($result === LogReader::RESULT_LOCKED) {
            $output->writeln('<comment>Another scan is already running (most likely the cron job). '
                . 'Nothing was scanned - try again once it finishes.</comment>');
            return Cli::RETURN_FAILURE;
        }

        $stats = $this->logReader->getLastRunStats();
        $output->writeln(sprintf(
            '<info>Log scan completed: %d file(s) had new data, %d log entr%s processed. '
            . 'Check the Admin Dashboard for new alerts.</info>',
            $stats['files'],
            $stats['entries'],
            $stats['entries'] === 1 ? 'y' : 'ies'
        ));

        // The per-run safety limits trade completeness for bounded server load;
        // say when they kicked in, so a partial result isn't mistaken for a full one.
        if ($stats['skipped_bytes'] > 0) {
            $output->writeln(sprintf(
                '<comment>Skipped %.1f MB of older log data (only the newest 16 MB of each file is read per run).</comment>',
                $stats['skipped_bytes'] / 1048576
            ));
        }
        if ($stats['deferred_files'] > 0) {
            $output->writeln(sprintf(
                '<comment>Run limit reached: %d file(s) left for the next run.</comment>',
                $stats['deferred_files']
            ));
        }
        if ($stats['dropped_errors'] > 0) {
            $output->writeln(sprintf(
                '<comment>Alert limit reached: %d new kind(s) of error not recorded.</comment>',
                $stats['dropped_errors']
            ));
        }
        return Cli::RETURN_SUCCESS;
    }
}
