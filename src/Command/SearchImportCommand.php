<?php

namespace Algolia\SearchBundle\Command;

use Algolia\AlgoliaSearch\SearchClient;
use Algolia\SearchBundle\Entity\Aggregator;
use Algolia\SearchBundle\SearchService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 */
final class SearchImportCommand extends IndexCommand
{
    /**
     * @var string
     */
    protected static $defaultName = 'search:import';

    /**
     * @var SearchService
     */
    private $searchServiceForAtomicReindex;

    /**
     * @var ManagerRegistry|null
     */
    private $managerRegistry;

    /**
     * @var SearchClient
     */
    private $searchClient;

    public function __construct(
        SearchService $searchService,
        SearchService $searchServiceForAtomicReindex,
        ManagerRegistry $managerRegistry,
        SearchClient $searchClient
    ) {
        parent::__construct($searchService);

        $this->searchServiceForAtomicReindex = $searchServiceForAtomicReindex;
        $this->managerRegistry               = $managerRegistry;
        $this->searchClient                  = $searchClient;
    }

    /**
     * @return void
     */
    protected function configure()
    {
        $this
            ->setDescription('Import given entity into search engine')
            ->addOption('indices', 'i', InputOption::VALUE_OPTIONAL, 'Comma-separated list of index names')
            ->addOption('atomic', null, InputOption::VALUE_NONE, <<<EOT
If set, command replaces all records in an index without any downtime. It pushes a new set of objects and removes all previous ones.

Internally, this option causes command to copy existing index settings, synonyms and query rules and indexes all objects. Finally, the existing index is replaced by the temporary one.
EOT
            )
            ->addArgument(
                'extra',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Check your engine documentation for available options'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shouldDoAtomicReindex = (bool) $input->getOption('atomic');
        $indexesByEntity       = $this->getEntitiesFromArgs($input, $output);
        $config                = $this->searchService->getConfiguration();
        $indexingService       = ($shouldDoAtomicReindex ? $this->searchServiceForAtomicReindex : $this->searchService);

        foreach ($indexesByEntity as $entityClassName => $indexNames) {
            if (!$this->searchService->isSearchable($entityClassName)) {
                continue;
            }

            
            foreach ($indexNames as $indexName) {
                $sourceIndexName = $this->searchService->buildSearchableIndex($indexName);
                if ($shouldDoAtomicReindex) {
                    $temporaryIndexName = $this->searchServiceForAtomicReindex->buildSearchableIndex($indexName);
                    $output->writeln("Creating temporary index <info>$temporaryIndexName</info>");
                    $this->searchClient->copyIndex($sourceIndexName, $temporaryIndexName, ['scope' => ['settings', 'synonyms', 'rules']]);
                }

                $allResponses = [];
                foreach (is_subclass_of($entityClassName, Aggregator::class) ? $entityClassName::getEntities() : [$entityClassName] as $entityClass) {
                    $manager    = $this->managerRegistry->getManagerForClass($entityClass);
                    $repository = $manager->getRepository($entityClass);

                    $page = 0;
                    do {
                        $entities = $repository->findBy(
                            [],
                            null,
                            $config['batchSize'],
                            $config['batchSize'] * $page
                        );

                        $configuration = $this->searchService->getConfiguration();
                        if (str_contains($indexName, $configuration['prefix'])) {
                            $indexName = str_replace($configuration['prefix'], '', $indexName);
                        }

                        $response       = $indexingService->index($manager, $entities, ['currentIndex' => $indexName]);
                        $allResponses[] = $response;
                        $responses      = $this->formatIndexingResponse($response);

                        foreach ($responses as $index => $numberOfRecords) {
                            $output->writeln(
                                sprintf(
                                    'Indexed <comment>%s / %s</comment> %s entities into %s index',
                                    $numberOfRecords,
                                    count($entities),
                                    $entityClass,
                                    '<info>' . $index . '</info>'
                                )
                            );
                        }

                        $page++;
                        $manager->clear();
                    } while (count($entities) >= (int) $config['batchSize']);

                    $manager->clear();
                }

                if ($shouldDoAtomicReindex && isset($indexName)) {
                    $output->writeln("Waiting for indexing tasks to finalize\n");
                    foreach ($allResponses as $response) {
                        $response->wait();
                    }
                    $output->writeln("Moving <info>$indexName</info> -> <comment>$sourceIndexName</comment>\n");
                    $this->searchClient->moveIndex($indexName, $sourceIndexName);
                }
            }
        }

        $output->writeln('<info>Done!</info>');

        return 0;
    }

    /**
     * @param array<int, array> $batch
     *
     * @return array<string, int>
     */
    private function formatIndexingResponse($batch)
    {
        $formattedResponse = [];

        foreach ($batch as $chunk) {
            foreach ($chunk as $indexName => $apiResponse) {
                if (!array_key_exists($indexName, $formattedResponse)) {
                    $formattedResponse[$indexName] = 0;
                }

                $formattedResponse[$indexName] += count($apiResponse->current()['objectIDs']);
            }
        }

        return $formattedResponse;
    }
}
