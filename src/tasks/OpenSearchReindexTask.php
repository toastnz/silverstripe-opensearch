<?php

namespace Toast\OpenSearch\Tasks;

use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Toast\OpenSearch\Helpers\OpenSearch;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

class OpenSearchReindexTask extends BuildTask
{
    protected static string $commandName = 'OpenSearchReindexTask';

    protected string $title = 'OpenSearch Reindex';

    protected static string $description = 'Rebuild the configured OpenSearch index from configured included classes.';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $search = OpenSearch::singleton();
        $index = $search->getIndexDefinition();
        $count = 0;

        $output->writeln(sprintf(
            'Index: %s',
            $index->getIndexName(),
        ));

        $search->clearIndex($index);

        foreach ($index->getRecordsForReindex() as $record) {
            $response = $search->updateIndex($record, null, $index);

            if (!empty($response['skipped']) || !empty($response['removed'])) {
                continue;
            }

            $count++;
            $output->writeln(sprintf(
                'Indexed: %s #%s',
                $record::class,
                $record->ID ?? 'unknown',
            ));
        }

        $response = [
            'acknowledged' => true,
            'index' => $index->getIndexName(),
            'count' => $count,
        ];

        $output->writeln(sprintf(
            'Reindexed: %d',
            $response['count'] ?? 0,
        ));
        $output->writeln(sprintf(
            'Response: %s',
            json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ));

        return Command::SUCCESS;
    }
}
