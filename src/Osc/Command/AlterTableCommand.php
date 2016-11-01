<?php namespace Osc\Command;
use Osc\Logger;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AlterTableCommand extends Command
{
    const DEFAULT_USER = 'root';

    protected function configure()
    {
        $this
            ->setName('alter')
            ->setDescription('Runs an online alter table')
            ->addArgument('database', InputArgument::REQUIRED, 'The database')
            ->addArgument('table', InputArgument::REQUIRED, 'The table')
            ->addArgument('alter', InputArgument::REQUIRED, 'The alter statement')
            ->addOption('socket', 's', InputOption::VALUE_REQUIRED, 'The socket to connect with')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'The user to authenticate with', self::DEFAULT_USER)
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'The password to authenticate with')
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
            $socket = "host=localhost";
        }

        $pdo = new \PDO("mysql:$socket;", $input->getOption('user'), $input->getOption('password'), array(
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
        ));

        $onlineSchemaChange = new \OnlineSchemaChangeRefactor(
            $pdo,
            $logger,
            $input->getArgument('database'),
            $input->getArgument('table'),
            $input->getArgument('alter'),
            null,
            OSC_FLAGS_ACCEPT_VERSION
        );

        try
        {
            $onlineSchemaChange->execute();
        }
        catch(\Exception $e)
        {
            $logger->error($e->getMessage());
        }

    }
}