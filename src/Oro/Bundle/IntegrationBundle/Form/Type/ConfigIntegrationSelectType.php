<?php

namespace Oro\Bundle\IntegrationBundle\Form\Type;

use Doctrine\Common\Persistence\ManagerRegistry;
use Oro\Bundle\FormBundle\Form\DataTransformer\IdToEntityTransformer;
use Oro\Bundle\IntegrationBundle\Entity\Channel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

class ConfigIntegrationSelectType extends AbstractType
{
    /**
     * @var ManagerRegistry
     */
    private $registry;

    /**
     * @param ManagerRegistry $registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new IdToEntityTransformer($this->registry, Channel::class));
    }

    /**
     * {@inheritdoc}
     */
    public function getParent(): string
    {
        return IntegrationSelectType::class;
    }
}
