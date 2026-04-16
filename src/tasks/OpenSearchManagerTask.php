<?php

namespace Toast\OpenSearch\Tasks;

use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Toast\OpenSearch\Helpers\OpenSearch;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

class OpenSearchManagerTask extends BuildTask
{
    protected static string $commandName = 'OpenSearchManagerTask';

    protected string $title = 'OpenSearch Manager';

    protected static string $description = 'Initialise, inspect, clear, delete, or reset the configured OpenSearch index.';

    public function getOptions(): array
    {
        return [
            new InputOption('action', null, InputOption::VALUE_REQUIRED, 'Task action: init, reset, clear, delete, or status.', 'init'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $search = OpenSearch::singleton();
        $index = $search->getIndexDefinition();
        $action = strtolower((string) $input->getOption('action'));

        switch ($action ?: 'init') {
            case 'delete':
                $response = $search->deleteIndex($index);
                break;
            case 'reset':
                $indices = $search->getSearchService()->indices();
                $response = [
                    'acknowledged' => true,
                    'index' => $index->getIndexName(),
                    'deleted' => false,
                ];

                if ($indices->exists(['index' => $index->getIndexName()])) {
                    $response['deleted'] = $search->deleteIndex($index);
                }

                $response['created'] = $search->initIndex([], $index);
                break;
            case 'clear':
                $response = $search->clearIndex($index);
                break;
            case 'status':
                $response = [
                    'acknowledged' => true,
                    'index' => $index->getIndexName(),
                    'definition' => $index->getDefinition(),
                    'included_classes' => $index->getIncludedClasses(),
                    'excluded_classes' => $index->getExcludedClasses(),
                    'filter_field' => $index->getFilterField(),
                    'filters' => $index->getFilters(),
                ];
                break;
            case 'init':
            default:
                $response = $search->initIndex([], $index);
                break;
        }

        $output->writeln(sprintf(
            'Action: %s',
            $action ?: 'init'
        ));
        $output->writeln(sprintf(
            'Index: %s',
            $index->getIndexName()
        ));
        $output->writeln(sprintf(
            'Response: %s',
            json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ));

        return Command::SUCCESS;
    }
}
