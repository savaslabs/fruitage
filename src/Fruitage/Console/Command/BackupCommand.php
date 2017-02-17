<?php

namespace Fruitage\Console\Command;

use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Command\Command;
use Harvest\HarvestAPI;
use Harvest\Model\Range;
use Carbon\Carbon;
use League\Csv\Writer;
use SplTempFileObject;

class BackupCommand extends Command {

    /** @var \Harvest\HarvestAPI */
    private $harvestClient;
    /** @var array */
    private $config;
    /** @var array */
    private $projectMap;
    /** @var array */
    private $taskMap;
    /** @var array */
    private $userMap;
    /** @var array */
    private $clientMap;
    /** @var array */
    private $projectToClientMap;

    protected function configure()
    {
        $this->setName('backup')
            ->setDefinition([
                new InputArgument('file',
                    InputArgument::REQUIRED,
                    'Output file to write to.')

            ])
            ->setDescription('Backup time entries from Harvest to a CSV.');
    }

    protected function populateProjectMap()
    {
        $projects = $this->harvestClient->getProjects()->get('data');
        $clients = $this->harvestClient->getClients()->get('data');
        foreach ($projects as $project) {
            $this->projectMap[$project->get('id')] = $project->get('name');
            $client_id = $project->get('client-id');
            if (!isset($this->projectToClientMap[$project->get('id')])) {
//                $this->clientMap[$client_id] = $clients[$client_id]->get('name');
                $this->projectToClientMap[$project->get('id')] = $clients[$client_id]->get('name');
            }
        }
    }

    protected function populateClientMap()
    {
        $clients = $this->harvestClient->getClients()->get('data');
    }

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

    protected function populateUserMap()
    {
        $users = $this->harvestClient->getUsers()->get('data');
        foreach ($users as $user) {
            $this->userMap[$user->get('id')]['first_name'] = $user->get('first-name');
            $this->userMap[$user->get('id')]['last_name'] = $user->get('last-name');
        }
    }

    protected function populateTaskMap()
    {
        $tasks = $this->harvestClient->getTasks()->get('data');
        foreach ($tasks as $task) {
            $this->taskMap[$task->get('id')] = $task->get('name');
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->setHarvestClient();
        $io = new SymfonyStyle($input, $output);
        $io->title('Backing up Harvest entries to CSV');
        $output_file = getenv('HARVEST_CSV_OUTPUT_FILE');

        $to = Carbon::create()->format('Ymd');
        // From data is an arbitrary very old date.
        $range = new Range('20100101', $to);
        $io->note('Populating project map.');
        $this->populateProjectMap();
        $io->note('Populating user map.');
        $this->populateUserMap();
        $io->note('Populating task map.');
        $this->populateTaskMap();
        $project_ids = array_keys($this->projectMap);
        $io->progressStart(count($project_ids));
        $time_entries = [];
        // Get all Harvest projects.
        foreach ($project_ids as $id) {
            // Get all time entries for each project.
            $entries = $this->harvestClient->getProjectEntries($id, $range)->get('data');
            foreach ($entries as $entry) {
                // Format time entries.
                $time_entries[] = $this->formatEntry($entry);
            }
            $io->progressAdvance();
        }
        $io->progressFinish();
        $csv = Writer::createFromFileObject(new SplTempFileObject());
        $csv->insertOne(['Date', 'Client', 'Project', 'Task', 'Notes', 'Hours', 'First name', 'Last name']);
        $csv->insertAll($time_entries);
        $csv->output($output_file);
        return 0;
        // Write all time entries to CSV.
    }

    protected function formatEntry($entry)
    {
            return [
                'date' => $entry->get('spent-at'),
                'client' => $this->projectToClientMap[$entry->get('project-id')],
                'project' => $this->projectMap[$entry->get('project-id')],
                'task' => $this->taskMap[$entry->get('task-id')],
                'notes' => $entry->get('notes'),
                'hours' => $entry->get('hours'),
                'first_name' => $this->userMap[$entry->get('user-id')]['first_name'],
                'last_name' => $this->userMap[$entry->get('user-id')]['last_name'],
                'created_on' => $entry->get('created-at'),
                'updated_on' => $entry->get('updated-at'),
            ];
    }

}
