<?php

declare(strict_types=1);

namespace Speicher210\OpenApiGenerator\Describer\Form\NameResolver;

use Speicher210\OpenApiGenerator\Assert\Assert;
use Speicher210\OpenApiGenerator\Describer\Form\FlatNameResolver;
use Speicher210\OpenApiGenerator\Describer\Form\NameResolver\FlatNameResolver as FlatNameResolverTrait;
use Symfony\Component\Form\FormInterface;

use function array_shift;

final class FlatArray implements FlatNameResolver
{
    use FlatNameResolverTrait;

    public function getPropertyName(FormInterface $form): string
    {
        $names = $this->namesFromForm($form);

        $name = array_shift($names);
        Assert::notNull($name);

        return $this->fromArray($name, $names, $form->getConfig());
    }
}
