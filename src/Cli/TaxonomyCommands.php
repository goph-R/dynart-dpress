<?php

namespace Dynart\Dpress\Cli;

use Dynart\Micro\CliOutput;
use Dynart\Micro\CliOutputInterface;
use Dynart\Dpress\Service\TaxonomyService;

/**
 * Categories and tags from the console
 */
class TaxonomyCommands extends AbstractCommands {

    public function __construct(
        CliOutputInterface $output,
        protected TaxonomyService $taxonomy,
    ) {
        parent::__construct($output);
    }

    public function list(array $params = []): int {
        $categories = $this->taxonomy->categories();
        $this->output->writeLine('Categories ('.count($categories).'):');
        foreach ($categories as $row) {
            $this->output->setColor(CliOutput::CYAN);
            $this->output->write('  '.str_pad('#'.$row['id'], 6));
            $this->output->setColor(null);
            $this->output->write(str_pad('/'.$row['slug'], 26));
            $this->output->writeLine((string)$row['name'].($row['parent_id'] !== null ? '  (child of #'.$row['parent_id'].')' : ''));
        }
        $tags = $this->taxonomy->tagCloud();
        $this->output->writeLine('Tags ('.count($tags).', with published counts):');
        foreach ($tags as $row) {
            $this->output->setColor(CliOutput::CYAN);
            $this->output->write('  '.str_pad('/'.$row['slug'], 26));
            $this->output->setColor(null);
            $this->output->writeLine((string)$row['name'].'  ×'.$row['total']);
        }
        return 0;
    }
}
