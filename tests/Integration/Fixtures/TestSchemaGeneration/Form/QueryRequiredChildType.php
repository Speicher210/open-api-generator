<?php

declare(strict_types=1);

namespace Speicher210\OpenApiGenerator\Tests\Integration\Fixtures\TestSchemaGeneration\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

final class QueryRequiredChildType extends AbstractType
{
    /**
     * {@inheritDoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options) : void
    {
        $builder
            ->add('paramRequired', TextType::class, ['required' => true])
            ->add('paramRequiredWithCustomLabel', TextType::class, ['required' => true, 'label' => 'Custom Label'])
            ->add('paramOptional', TextType::class, ['required' => false]);
    }
}