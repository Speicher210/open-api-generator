<?php

declare(strict_types=1);

namespace Speicher210\OpenApiGenerator\Describer;

use Speicher210\OpenApiGenerator\Describer\Form\FlatNameResolver;
use Speicher210\OpenApiGenerator\Describer\Form\FormFactory;
use Speicher210\OpenApiGenerator\Describer\Form\NameResolver;
use Speicher210\OpenApiGenerator\Describer\Form\PropertyDescriberInterface;
use Speicher210\OpenApiGenerator\Describer\Form\RequirementsDescriberInterface;
use Speicher210\OpenApiGenerator\Model\FormDefinition;
use cebe\openapi\spec\Discriminator;
use cebe\openapi\spec\Schema;
use cebe\openapi\spec\Type;
use Symfony\Component\Form\FormConfigInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;

final class FormDescriber
{
    private FormFactory $formFactory;

    private PropertyDescriberInterface $propertyDescriber;

    private RequirementsDescriberInterface $requirementsDescriber;

    public function __construct(
        FormFactory $formFactory,
        PropertyDescriberInterface $propertyDescriber,
        RequirementsDescriberInterface $requirementsDescriber
    ) {
        $this->formFactory = $formFactory;
        $this->propertyDescriber = $propertyDescriber;
        $this->requirementsDescriber = $requirementsDescriber;
    }

    public function createSchema(FormInterface $form, NameResolver $nameResolver, string $httpMethod): Schema
    {
        $formConfig = $form->getConfig();
        $blockPrefix = $formConfig->getType()->getBlockPrefix();

        $schema = new Schema([]);

        switch ($blockPrefix) {
            case 'collection':
                $this->describeCollection($schema, $form, $nameResolver, $httpMethod);
                break;
            case 'polymorphic_collection':
                $this->describePolymorphicCollection($schema, $formConfig, $nameResolver, $httpMethod);
                break;
            default:
                $this->propertyDescriber->describe($schema, $blockPrefix, $form);
        }

        $this->requirementsDescriber->describe($schema, $form);

        return $schema;
    }

    public function addDeepSchema(FormInterface $form, NameResolver $nameResolver, string $httpMethod): Schema
    {
        if ($form->count() === 0) {
            $schema = $this->createSchema($form, $nameResolver, $httpMethod);
            $this->handleRequiredForParent($schema, $form, $nameResolver);
        } else {
            $schema = new Schema(['type' => Type::OBJECT]);
            foreach ($form->all() as $child) {
                $type = $child->getConfig()->getType();

                if ($this->isBuiltinType($type->getInnerType())) {
                    $this->addParameterToSchema($schema, $nameResolver, $child, $httpMethod);
                } else {
                    $childSchema = $this->addDeepSchema($child, $nameResolver, $httpMethod);
                    $name = $nameResolver->getPropertyName($child);
                    $schemaProperties = $schema->properties;
                    $schemaProperties[$name] = $childSchema;
                    $schema->properties = $schemaProperties;

                    $this->handleRequiredProperty($schema, $name, $child, $httpMethod);
                }
            }
        }
        if ($schema->required === []) {
            $schema->required = null;
        }

        return $schema;
    }

    public function addFlattenSchema(FormInterface $form, FlatNameResolver $nameResolver, string $httpMethod): Schema
    {
        $schema = new Schema(['type' => Type::OBJECT]);

        return $this->addParametersToFlattenSchema($schema, $form, $nameResolver, $httpMethod);
    }

    private function addParametersToFlattenSchema(
        Schema $schema,
        FormInterface $form,
        FlatNameResolver $nameResolver,
        string $httpMethod
    ): Schema {
        if ($form->count() === 0) {
            $this->addParameterToSchema($schema, $nameResolver, $form, $httpMethod);
        } else {
            foreach ($form->all() as $child) {
                $childConfig = $child->getConfig();
                $childType = $childConfig->getType();

                if (!$this->isBuiltinType($childType->getInnerType())) {
                    $this->addParametersToFlattenSchema($schema, $child, $nameResolver, $httpMethod);
                } elseif ($childType->getBlockPrefix() === 'collection') {
                    $subForm = $this->formFactory->create(
                        new FormDefinition(
                            $childConfig->getOption('entry_type'),
                            (array) $childConfig->getOption('validation_groups')
                        )
                    );

                    // Primitive type so we add array normally.
                    if ($subForm->count() === 0) {
                        $this->addParameterToSchema($schema, $nameResolver, $child, $httpMethod);
                    } else {
                        $prefix = $nameResolver->getPropertyName($child);

                        $this->addParametersToFlattenSchema(
                            $schema,
                            $subForm,
                            new NameResolver\PrefixedFlatArray($prefix),
                            $httpMethod
                        );
                    }
                } else {
                    $this->addParameterToSchema($schema, $nameResolver, $child, $httpMethod);
                }
            }
        }
        if ($schema->required === []) {
            $schema->required = null;
        }

        return $schema;
    }

    private function addParameterToSchema(
        Schema $schema,
        NameResolver $nameResolver,
        FormInterface $form,
        string $httpMethod
    ): void {
        $childSchema = $this->createSchema($form, $nameResolver, $httpMethod);
        $this->handleRequiredForParent($childSchema, $form, $nameResolver);

        $name = $nameResolver->getPropertyName($form);
        $schemaProperties = $schema->properties;
        $schemaProperties[$name] = $childSchema;
        $schema->properties = $schemaProperties;

        $this->handleRequiredProperty($schema, $name, $form, $httpMethod);
    }

    private function updateDescription(?string $originalDescription, string $newText): string
    {
        return \nl2br(\implode(\PHP_EOL, \array_filter([$originalDescription, $newText])), false);
    }

    private function describeCollection(
        Schema $schema,
        FormInterface $form,
        NameResolver $nameResolver,
        string $httpMethod
    ): void {
        $formConfig = $form->getConfig();

        $subForm = $this->formFactory->create(
            new FormDefinition(
                $formConfig->getOption('entry_type'),
                (array) $formConfig->getOption('validation_groups')
            )
        );
        $subForm->setParent($form);

        $schema->type = Type::ARRAY;
        $schema->items = $this->addDeepSchema($subForm, $nameResolver, $httpMethod);
    }

    private function isBuiltinType(FormTypeInterface $formType): bool
    {
        $formClass = \get_class($formType);

        return $formClass !== false && \strpos($formClass, 'Symfony\Component\Form\Extension\Core\Type') === 0;
    }

    private function handleRequiredForParent(Schema $schema, FormInterface $form, NameResolver $nameResolver): void
    {
        if ($form->getConfig()->getRequired() === true) {
            $parentForm = $form->getParent();
            if ($parentForm !== null && !$parentForm->isRoot() && $parentForm->isRequired() === false) {
                $schema->description = $this->updateDescription(
                    $schema->description,
                    \sprintf('Field required for %s', $nameResolver->getPropertyName($parentForm))
                );
            }
        }
    }

    private function handleRequiredProperty(Schema $schema, string $name, FormInterface $form, string $httpMethod): void
    {
        if ($this->isFormPropertyRequired($form, $httpMethod) === true) {
            $schemaRequired = $schema->required;
            $schemaRequired[] = $name;
            $schema->required = $schemaRequired;
        }
    }

    private function isFormPropertyRequired(FormInterface $form, string $httpMethod): bool
    {
        // For PATCH endpoints all properties are optional.
        if ($httpMethod === 'PATCH') {
            return false;
        }

        if ($form->getConfig()->getRequired() === false) {
            return false;
        }

        $parentForm = $form->getParent();

        return !($parentForm !== null && !$parentForm->isRoot() && $parentForm->isRequired() === false);
    }

    private function describePolymorphicCollection(
        Schema $schema,
        FormConfigInterface $formConfig,
        NameResolver $nameResolver,
        string $httpMethod
    ): void {
        $discriminatorFieldName = $formConfig->getOption('discriminator_field_name');
        $entryTypes = $formConfig->getOption('entry_types');

        $polymorphicCollectionSchema = new Schema([]);
        $polymorphicCollectionSchema->oneOf = \array_values(
            \array_map(
                function (string $entryType) use ($formConfig, $nameResolver, $httpMethod) {
                    $subForm = $this->formFactory->create(
                        new FormDefinition($entryType, (array) $formConfig->getOption('validation_groups'))
                    );

                    if ($nameResolver instanceof FlatNameResolver) {
                        return $this->addFlattenSchema($subForm, $nameResolver, $httpMethod);
                    }

                    return $this->addDeepSchema($subForm, $nameResolver, $httpMethod);
                },
                $entryTypes
            )
        );
        $polymorphicCollectionSchema->discriminator = new Discriminator(['propertyName' => $discriminatorFieldName]);

        $schema->type = Type::ARRAY;
        $schema->items = $polymorphicCollectionSchema;
    }
}