<?php
namespace Platformsh\Cli\Command\Db;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Service\Relationships;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DbDumpCommand extends CommandBase
{

    protected function configure()
    {
        $this->setName('db:dump')
            ->setDescription('Create a local dump of the remote database');
        $this->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'A custom filename for the dump')
            ->addOption('gzip', 'z', InputOption::VALUE_NONE, 'Compress the dump using gzip')
            ->addOption('bzip2', 'b', InputOption::VALUE_NONE, 'Compress the dump using bzip2')
            ->addOption('timestamp', 't', InputOption::VALUE_NONE, 'Add a timestamp to the dump filename')
            ->addOption('stdout', 'o', InputOption::VALUE_NONE, 'Output to STDOUT instead of a file')
            ->addOption('table', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Table(s) to include')
            ->addOption('exclude-table', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Table(s) to exclude')
            ->addOption('schema-only', null, InputOption::VALUE_NONE, 'Dump only schemas, no data');
        $this->addProjectOption()->addEnvironmentOption()->addAppOption();
        Relationships::configureInput($this->getDefinition());
        Ssh::configureInput($this->getDefinition());
        $this->setHiddenAliases(['sql-dump', 'environment:sql-dump']);
        $this->addExample('Create an SQL dump file');
        $this->addExample('Create a gzipped SQL dump file named "dump.sql.gz"', '--gzip -f dump.sql.gz');
        $this->addExample('Create a bzip2-compressed SQL dump file named "dump.sql.bz2"', '--bzip2 -f dump.sql.bz2');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $project = $this->getSelectedProject();
        $environment = $this->getSelectedEnvironment();
        $appName = $this->selectApp($input);
        $sshUrl = $environment->getSshUrl($appName);
        $timestamp = $input->getOption('timestamp') ? date('Ymd-His-T') : null;
        $gzip = $input->getOption('gzip');
        $bzip2 = $input->getOption('bzip2');
        $includedTables = $input->getOption('table');
        $excludedTables = $input->getOption('exclude-table');
        $schemaOnly = $input->getOption('schema-only');

        if ($gzip && $bzip2) {
            $this->stdErr->writeln('Using both --gzip and --bzip2 is not supported.');
            return 1;
        }

        /** @var \Platformsh\Cli\Service\Filesystem $fs */
        $fs = $this->getService('fs');

        $dumpFile = null;
        if (!$input->getOption('stdout')) {
            // Determine a default dump filename.
            $defaultFilename = $project->id . '--' . $environment->id;
            if ($appName !== null) {
                $defaultFilename .= '--' . $appName;
            }
            if ($includedTables) {
                $defaultFilename .= '--' . implode(',', $includedTables);
            }
            if ($excludedTables) {
                $defaultFilename .= '--excl-' . implode(',', $excludedTables);
            }
            if ($schemaOnly) {
                $defaultFilename .= '--schema';
            }
            if ($timestamp !== null) {
                $defaultFilename .= '--' . $timestamp;
            }
            $defaultFilename .= '--dump.sql';
            if ($gzip) {
                $defaultFilename .= '.gz';
            } elseif ($bzip2) {
                $defaultFilename .= '.bz2';
            }
            if ($projectRoot = $this->getProjectRoot()) {
                $defaultFilename = $projectRoot . '/' . $defaultFilename;
            }
            $dumpFile = $defaultFilename;

            // Process the user --file option.
            if ($input->getOption('file')) {
                $dumpFile = rtrim($input->getOption('file'), '/');

                // Ensure the filename is not a directory.
                if (is_dir($dumpFile)) {
                    $dumpFile .= '/' . $defaultFilename;
                }

                // Insert a timestamp into the filename.
                if ($timestamp) {
                    $basename = basename($dumpFile);
                    $prefix = substr($dumpFile, 0, - strlen($basename));
                    if ($dotPos = strrpos($basename, '.')) {
                        $basename = substr($basename, 0, $dotPos) . '--' . $timestamp . substr($basename, $dotPos);
                    } else {
                        $basename .= '--' . $timestamp;
                    }
                    $dumpFile = $prefix . $basename;
                }
            }

            // Make the filename absolute.
            $dumpFile = $fs->makePathAbsolute($dumpFile);
        }

        if ($dumpFile) {
            if (file_exists($dumpFile)) {
                /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
                $questionHelper = $this->getService('question_helper');
                if (!$questionHelper->confirm("File exists: <comment>$dumpFile</comment>. Overwrite?", false)) {
                    return 1;
                }
            }
            $this->stdErr->writeln(sprintf(
                'Creating %s file: <info>%s</info>',
                $gzip ? 'gzipped SQL dump' : 'SQL dump',
                $dumpFile
            ));
        }

        /** @var \Platformsh\Cli\Service\Relationships $relationships */
        $relationships = $this->getService('relationships');

        $database = $relationships->chooseDatabase($sshUrl, $input);
        if (empty($database)) {
            return 1;
        }

        switch ($database['scheme']) {
            case 'pgsql':
                $dumpCommand = 'pg_dump --clean ' . $relationships->getSqlCommandArgs('pg_dump', $database);
                if ($schemaOnly) {
                    $dumpCommand .= ' --schema-only';
                }
                foreach ($includedTables as $table) {
                    $dumpCommand .= ' ' . escapeshellarg('--table=' . $table);
                }
                foreach ($excludedTables as $table) {
                    $dumpCommand .= ' ' . escapeshellarg('--exclude-table=' . $table);
                }
                break;

            default:
                $dumpCommand = 'mysqldump --no-autocommit --single-transaction --opt --quote-names '
                    . $relationships->getSqlCommandArgs('mysqldump', $database);
                if ($schemaOnly) {
                    $dumpCommand .= ' --no-data';
                }
                foreach ($excludedTables as $table) {
                    $dumpCommand .= ' ' . escapeshellarg(sprintf('--ignore-table=%s.%s', $database['path'], $table));
                }
                if ($includedTables) {
                    $dumpCommand .= ' --tables ' . implode(' ', array_map('escapeshellarg', $includedTables));
                }
                break;
        }

        /** @var \Platformsh\Cli\Service\Ssh $ssh */
        $ssh = $this->getService('ssh');
        $sshCommand = $ssh->getSshCommand();

        if ($gzip) {
            $dumpCommand .= ' | gzip --stdout';
        } elseif ($bzip2) {
            $dumpCommand .= ' | bzip2 --stdout';
        } else {
            // Compress data transparently as it's sent over the SSH connection.
            $sshCommand .= ' -C';
        }

        set_time_limit(0);

        // Build the complete SSH command.
        $command = $sshCommand
            . ' ' . escapeshellarg($sshUrl)
            . ' ' . escapeshellarg($dumpCommand);
        if ($dumpFile) {
            $command .= ' > ' . escapeshellarg($dumpFile);
        }

        // Execute the SSH command.
        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');
        $exitCode = $shell->executeSimple($command);

        // If a dump file exists, check that it's excluded in the project's
        // .gitignore configuration.
        if ($dumpFile && file_exists($dumpFile) && isset($projectRoot) && strpos($dumpFile, $projectRoot) === 0) {
            /** @var \Platformsh\Cli\Service\Git $git */
            $git = $this->getService('git');
            $relative = $fs->makePathRelative($dumpFile, $projectRoot);
            if (!$git->checkIgnore($relative, $projectRoot)) {
                $this->stdErr->writeln('<comment>Warning: the dump file is not excluded by Git</comment>');
                if ($pos = strrpos($dumpFile, '--dump.sql')) {
                    $extension = substr($dumpFile, $pos);
                    $this->stdErr->writeln('  You should probably exclude these files using .gitignore:');
                    $this->stdErr->writeln('    *' . $extension);
                }
            }
        }

        return $exitCode;
    }
}
