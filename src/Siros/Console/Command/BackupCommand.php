<?php

namespace Siros\Console\Command;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Command\Command;
use Harvest\HarvestAPI;
use Harvest\Model\Range;
use Carbon\Carbon;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Encoder\CsvEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;

/**
 * Class BackupCommand
 * @package Siros\Console\Command
 */
class BackupCommand extends Command {

    /** @var \Harvest\HarvestAPI */
    private $harvestClient;
    /** @var array */
    private $projectMap;
    /** @var array */
    private $taskMap;
    /** @var array */
    private $userMap;
    /** @var array */
    private $projectToClientMap;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('backup')
            ->setDefinition([
                new InputArgument('file',
                    InputArgument::OPTIONAL,
                    'Output file to write to.',
                    'data.csv')

            ])
            ->setDescription('Backup time entries from Harvest to a CSV.');
    }

    /**
     * Populate a map of project IDs to project names, and client IDs to client names.
     */
    protected function populateProjectMap()
    {
        $projects = $this->harvestClient->getProjects()->get('data');
        $clients = $this->harvestClient->getClients()->get('data');
        /** @var \Harvest\Model\Result $project */
        foreach ($projects as $project) {
            $this->projectMap[$project->get('id')] = array(
              'name' => $project->get('name'),
              'hourly-rate' => $project->get('hourly-rate'),
              'client-id' => $project->get('client-id'),
            );
            $client_id = $project->get('client-id');
            if (!isset($this->projectToClientMap[$project->get('id')])) {
                $this->projectToClientMap[$project->get('id')] = $clients[$client_id]->get('name');
            }
        }
    }

    /**
     * Initialize the Harvest client.
     */
    protected function setHarvestClient()
    {
        $this->harvestClient = new HarvestAPI();
        $this->harvestClient->setUser(getenv('HARVEST_USER'));
        $this->harvestClient->setPassword(getenv('HARVEST_PASSWORD'));
        $this->harvestClient->setAccount(getenv('HARVEST_ACCOUNT'));
        $ret = $this->harvestClient->getThrottleStatus();
        if ($ret->get('code') !== 200) {
            throw new \Exception('Unable to login to Harvest!');
        }
    }

    /**
     * Populate a map of user IDs to user names.
     */
    protected function populateUserMap()
    {
        $users = $this->harvestClient->getUsers()->get('data');
        foreach ($users as $user) {
            /** @var \Harvest\Model\Result $user */
            $this->userMap[$user->get('id')]['first_name'] = $user->get('first-name');
            $this->userMap[$user->get('id')]['last_name'] = $user->get('last-name');
        }
    }

    /**
     * Populate a map of task IDs to task names.
     */
    protected function populateTaskMap()
    {
        $tasks = $this->harvestClient->getTasks()->get('data');
        foreach ($tasks as $task) {
            /** @var \Harvest\Model\Result $task */
            $this->taskMap[$task->get('id')] = $task->get('name');
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Initialize client.
        $this->setHarvestClient();

        // Set up our file.
        $fs = new Filesystem();
        $fs->touch($input->getArgument('file'));

        // Initialize output.
        $io = new SymfonyStyle($input, $output);
        $io->title('Backing up Harvest entries to CSV');

        // Set up the date range for retrieving project entries.
        // From data is an arbitrary old date.
        $to = Carbon::create()->format('Ymd');
        $range = new Range('20100101', $to);

        $io->note('Populating project map.');
        $this->populateProjectMap();

        $io->note('Populating user map.');
        $this->populateUserMap();

        $io->note('Populating task map.');
        $this->populateTaskMap();

        $project_ids = array_keys($this->projectMap);

        // Retrieve data for projects.
        $io->section(sprintf('Retrieving data for %d projects', count($project_ids)));
        $io->progressStart(count($project_ids));
        $time_entries = [];
        $entries = [];
        // Get all Harvest projects.
        foreach ($project_ids as $id) {
            // Get all time entries for each project.
            $project_entries = $this->harvestClient->getProjectEntries($id, $range)->get('data');
            foreach ($project_entries as $entry) {
                $entries[] = $entry;
            }
            $io->progressAdvance();
        }
        $io->progressFinish();

        // Format and sort the time entries.
        $io->section(sprintf('Formatting and sorting %d time entries', count($entries)));
        $io->progressStart(count($entries));
        foreach ($entries as $entry) {
            // Format time entries.
            $time_entries[] = $this->formatEntry($entry);
            $io->progressAdvance();
        }
        $io->progressFinish();
        // Sort by Harvest ID.
        usort($time_entries, function ($a, $b) {
            return ($a['Harvest ID'] < $b['Harvest ID']) ? -1 : 1;
        });

        // Save the CSV.
        $serializer = new Serializer([new ObjectNormalizer()], [new CsvEncoder()]);
        $fs->dumpFile($input->getArgument('file'), $serializer->encode($time_entries, 'csv'));
        $io->success(sprintf('Wrote %d entries to %s', count($time_entries), $input->getArgument('file')));

        return 0;
    }

    /**
     * Format an entry for writing to the CSV file.
     *
     * @param \Harvest\Model\Result $entry;
     * @return array
     */
    protected function formatEntry($entry)
    {
            return [
                'Date' => $entry->get('spent-at'),
                'Client' => $this->projectToClientMap[$entry->get('project-id')],
                'Project' => $this->projectMap[$entry->get('project-id')]['name'],
                'Task' => $this->taskMap[$entry->get('task-id')],
                'Notes' => $entry->get('notes'),
                'Hours' => $entry->get('hours'),
                'Hours rounded' => ceil($entry->get('hours') * 4) / 4,
                'First name' => $this->userMap[$entry->get('user-id')]['first_name'],
                'Last name' => $this->userMap[$entry->get('user-id')]['last_name'],
                'Created on' => $entry->get('created-at'),
                'Updated on' => $entry->get('updated-at'),
                'Harvest ID' => $entry->get('id'),
                'Project ID' => $entry->get('project-id'),
                'User ID' => $entry->get('user-id'),
                'Project hourly rate' => $this->projectMap[$entry->get('project-id')]['hourly-rate'],
                'Task ID' => $entry->get('task-id'),
                'Client ID' => $this->projectMap[$entry->get('project-id')]['client-id'],
            ];
    }

}
