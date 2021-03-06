<?php

declare(strict_types=1);

namespace Speicher210\OpenApiGenerator\Processor;

use cebe\openapi\spec\Components;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\SecurityScheme;
use Speicher210\OpenApiGenerator\Model\Specification;

use function array_filter;

final class SecurityDefinitions implements Processor
{
    public function process(OpenApi $openApi, Specification $specification): void
    {
        $definitions = [];
        foreach ($specification->securityDefinitions() as $securityDefinition) {
            $definitions[$securityDefinition->key()] = new SecurityScheme(
                array_filter(
                    [
                        'type' => $securityDefinition->type(),
                        'description' => $securityDefinition->description(),
                        'name' => $securityDefinition->name(),
                        'in' => $securityDefinition->in(),
                        'scheme' => $securityDefinition->scheme(),
                        'bearerFormat' => $securityDefinition->bearerFormat(),
                    ]
                )
            );
        }

        if ($openApi->components === null) {
            $openApi->components = new Components([]);
        }

        $openApi->components->securitySchemes = $definitions;
    }
}
