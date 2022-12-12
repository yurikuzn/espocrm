<?php

namespace Espo\Tools\Export\Processors\Xlsx;

use Espo\Core\Binding\BindingContainerBuilder;
use Espo\Core\Binding\ContextualBinder;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Metadata;
use Espo\Tools\Export\Processors\Xlsx\CellValuePreparators\General;

class CellValuePreparatorFactory
{
    public function __construct(
        private InjectableFactory $injectableFactory,
        private Metadata $metadata
    ) {}

    public function create(string $fieldType, string $entityType): CellValuePreparator
    {
        /** @var class-string<CellValuePreparator> $className */
        $className = $this->metadata
            ->get(['app', 'export', 'formatDefs', 'xlsx', 'preparatorClassNameMap', $fieldType]) ??
            General::class;

        $binding = BindingContainerBuilder::create()
            ->inContext($className, function (ContextualBinder $binder) use ($entityType) {
                $binder->bindValue('entityType', $entityType);
            })
            ->build();

        return $this->injectableFactory->createWithBinding($className, $binding);
    }
}
