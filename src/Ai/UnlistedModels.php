<?php

namespace App\Ai;

use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;

/**
 * Any name is a model: placed after the catalog of a bridge, it keeps usable a model that the
 * bridge does not list yet. The game checks it by trying it (ModelCheck), as for a Generic provider.
 */
final readonly class UnlistedModels implements ModelCatalogInterface
{
    /**
     * @param class-string<Model> $modelClass The class the bridge wants for its models
     */
    public function __construct(private string $modelClass)
    {
    }

    public function getModel(string $modelName): Model
    {
        return new ($this->modelClass)($modelName, [
            Capability::INPUT_MESSAGES,
            Capability::INPUT_IMAGE,
            Capability::OUTPUT_TEXT,
            Capability::OUTPUT_STREAMING,
            Capability::TOOL_CALLING,
        ]);
    }

    public function getModels(): array
    {
        return [];
    }
}
