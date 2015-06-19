<?php

namespace Bump\ThumbnailBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Filesystem\Filesystem;

class ThumbnailCommand extends ContainerAwareCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('bump:thumbnail:clear')
            ->setDescription('Remove generated thumbnails')
            ->setDefinition(
                array(
                    new InputOption(
                        'force',
                        null,
                        InputOption::VALUE_NONE,
                        'Causes the generated thumbnails to be physically removed from the filesystem.'
                    ),
                )
            )
            ->setHelp(
<<<EOF
The <info>%command.name%</info> remove generated thumbnails:

<info>php %command.full_name%</info>
EOF
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $force   = true === $input->getOption('force');

        if ($force) {
            $thumbnailer = $this->getContainer()->get('bump_thumbnail.generator');
            $basePath = $thumbnailer->getBasePath();
            $fs = new Filesystem();

            if ($fs->exists($basePath) && is_dir($basePath)) {
                $fs->remove($basePath);
                // $fs->mkdir($basePath, 0775);
            }

            $output->writeln("All thumbnails in <info>{$basePath}</info> successfully removed.");

            return 0;
        }

        $output->writeln(sprintf('Please run the operation by passing <info>%s --force</info> to execute the command', $this->getName()));

        return 1;
    }
}
