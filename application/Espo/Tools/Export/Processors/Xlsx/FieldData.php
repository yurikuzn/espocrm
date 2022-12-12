<?php

namespace Espo\Tools\Export\Processors\Xlsx;

class FieldData
{
    public function __construct(
        private string $entityType,
        private string $field,
        private string $type
    ) {}

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getType(): string
    {
        return $this->type;
    }
}
