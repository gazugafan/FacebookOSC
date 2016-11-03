<?php namespace Osc\Command;
use Osc\Logger;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CleanupCommand extends Command
{
    const DEFAULT_USER = 'root';

    protected function configure()
    {
        $this
            ->setName('cleanup')
            ->setDescription('Attempts to cleanup from a previous run that might have been unsuccessful')
            ->addArgument('database', InputArgument::REQUIRED, 'The database')
            ->addOption('host', 'H', InputOption::VALUE_REQUIRED, 'The host to connect with', 'localhost')
            ->addOption('socket', 's', InputOption::VALUE_REQUIRED, 'The socket to connect with')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'The user to authenticate with', self::DEFAULT_USER)
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'The password to authenticate with')
			->addOption('output-folder', null, InputOption::VALUE_REQUIRED, 'The folder where temporary backup files were stored (optional)')
            ->addOption('logfile', null, InputOption::VALUE_REQUIRED, 'A filename to log to. Will write output to stdout unless specified')
            ->addOption('stdout', null, InputOption::VALUE_NONE, 'Log to stdout as well as to file. Only required if --logfile is specified')
        ;
    }
    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $verbosity = array(
            OutputInterface::VERBOSITY_NORMAL       => \Psr\Log\LogLevel::NOTICE,
            OutputInterface::VERBOSITY_VERY_VERBOSE => \Psr\Log\LogLevel::DEBUG,
            OutputInterface::VERBOSITY_VERBOSE      => \Psr\Log\LogLevel::INFO,
            OutputInterface::VERBOSITY_DEBUG        => \Psr\Log\LogLevel::DEBUG,
            OutputInterface::VERBOSITY_QUIET        => -1,
        );

        $files = array();

        if($logfile = $input->getOption('logfile'))
        {
            if(!$logHandle = fopen($logfile, 'w'))
            {
                $dir = dirname($logfile);

                throw new \RuntimeException("Log file '$logfile' could not be opened. Please check the folder '$dir' exists and has the correct permissions.");
            }

            $files[] = $logHandle;
        }

        if(!$logfile || $input->getOption('stdout'))
        {
            $files[] = STDOUT;
        }

        $logger = new Logger($files, $verbosity[$output->getVerbosity()]);

        if($socket = $input->getOption('socket'))
        {
            $socket = "unix_socket=$socket";
        }
        else
        {
            $socket = "host=" . $input->getOption('host');
        }

        $pdo = new \PDO("mysql:dbname=" . $input->getArgument('database') . ";$socket;", $input->getOption('user'), $input->getOption('password'), array(
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
        ));

		try
		{
			\OnlineSchemaChangeRefactor::serverCleanup(
				$pdo,
				$logger,
				$input->getOption('output-folder'),
				OSC_FLAGS_ACCEPT_VERSION
			);
        }
        catch(\Exception $e)
        {
            $logger->error($e->getMessage());
        }

    }
}