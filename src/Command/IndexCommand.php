<?php

namespace Algolia\SearchBundle\Command;

use Algolia\SearchBundle\SearchService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 */
abstract class IndexCommand extends Command
{
    /**
     * @var SearchService
     */
    protected $searchService;

    public function __construct(SearchService $searchService)
    {
        $this->searchService = $searchService;

        parent::__construct();
    }

    /**
     * @return array
     */
    protected function getEntitiesFromArgs(InputInterface $input, OutputInterface $output)
    {
        $entities   = [];
        $indexNames = [];
        $indexList  = $input->getOption('indices');

        if ((bool) $indexList) {
            $indexNames = explode(',', $indexList);
        }

        $config = $this->searchService->getConfiguration();

        if (count($indexNames) === 0) {
            $indexNames = array_keys($config['indices']);
        }

        foreach ($indexNames as $name) {
            if (isset($config['indices'][$name])) {
                $entities[$config['indices'][$name]['class']][] = $name;
            } else {
                $output->writeln('<comment>No index named <info>' . $name . '</info> was found. Check you configuration.</comment>');
            }
        }

        return $entities;
    }
}
