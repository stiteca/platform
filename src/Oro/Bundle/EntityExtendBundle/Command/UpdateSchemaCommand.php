<?php

namespace Oro\Bundle\EntityExtendBundle\Command;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManager;
use Oro\Bundle\EntityConfigBundle\Provider\ExtendEntityConfigProviderInterface;
use Oro\Bundle\EntityExtendBundle\EntityConfig\ExtendScope;
use Oro\Bundle\EntityExtendBundle\Tools\EnumSynchronizer;
use Oro\Bundle\EntityExtendBundle\Tools\SaveSchemaTool;
use Oro\Bundle\EntityExtendBundle\Tools\SchemaTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The CLI command to update schema according to data stored in entity config caches
 */
class UpdateSchemaCommand extends Command
{
    use SchemaTrait;

    /** @var string */
    protected static $defaultName = 'oro:entity-extend:update-schema';

    /** @var ManagerRegistry */
    private $registry;

    /** @var ExtendEntityConfigProviderInterface */
    private $extendEntityConfigProvider;

    /** @var EnumSynchronizer */
    private $enumSynchronizer;

    /**
     * @param ManagerRegistry $registry
     * @param ExtendEntityConfigProviderInterface $extendEntityConfigProvider
     * @param EnumSynchronizer $enumSynchronizer
     */
    public function __construct(
        ManagerRegistry $registry,
        ExtendEntityConfigProviderInterface $extendEntityConfigProvider,
        EnumSynchronizer $enumSynchronizer
    ) {
        parent::__construct();

        $this->registry = $registry;
        $this->extendEntityConfigProvider = $extendEntityConfigProvider;
        $this->enumSynchronizer = $enumSynchronizer;
    }

    /**
     * Console command configuration
     */
    public function configure()
    {
        $this
            ->setDescription('Synchronize extended and custom entities metadata with a database schema')
            ->setDefinition(
                [
                    new InputOption(
                        'dry-run',
                        null,
                        InputOption::VALUE_NONE,
                        'Dumps the generated SQL statements to the screen (does not execute them).'
                    )
                ]
            );
    }

    /**
     * Runs command
     *
     * @param  InputInterface  $input
     * @param  OutputInterface $output
     *
     * @return int|null|void
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln($this->getDescription());

        $this->overrideRemoveNamespacedAssets();
        $this->overrideSchemaDiff();

        /** @var EntityManager $em */
        $em = $this->registry->getManager();

        $metadata = $this->getClassesMetadata($em);

        $schemaTool = new SaveSchemaTool($em);
        $sqls       = $schemaTool->getUpdateSchemaSql($metadata, true);
        if (0 === count($sqls)) {
            $output->writeln('Nothing to update - a database is already in sync with the current entity metadata.');
        } else {
            if ($input->getOption('dry-run')) {
                $output->writeln(implode(';' . PHP_EOL, $sqls) . ';');
            } else {
                $output->writeln('Updating database schema...');
                $schemaTool->updateSchema($metadata, true);
                $output->writeln(
                    sprintf(
                        'Database schema updated successfully! "<info>%s</info>" queries were executed',
                        count($sqls)
                    )
                );
            }
        }

        if (!$input->getOption('dry-run')) {
            $this->enumSynchronizer->sync();
        }
    }

    /**
     * @param EntityManager $em
     *
     * @return array
     */
    protected function getClassesMetadata(EntityManager $em)
    {
        $extendConfigs = $this->extendEntityConfigProvider->getExtendEntityConfigs();
        $metadata = [];
        foreach ($extendConfigs as $extendConfig) {
            if (!$extendConfig->in('state', [ExtendScope::STATE_NEW, ExtendScope::STATE_DELETE])) {
                $metadata[] = $em->getClassMetadata($extendConfig->getId()->getClassName());
            }
        }

        return $metadata;
    }
}
