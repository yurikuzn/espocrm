<?php

namespace Espo\Tools\Export\Processors\Xlsx;

use Espo\ORM\Defs;

class FieldHelper
{
    public function __construct(
        private Defs $ormDefs
    ) {}

    public function isForeign(string $entityType, string $name): bool
    {
        if (str_contains($name, '_')) {
            return true;
        }

        $entityDefs = $this->ormDefs->getEntity($entityType);

        return
            $entityDefs->hasField($name) &&
            $entityDefs->getField($name)->getType() === 'foreign';
    }

    public function getData(string $entityType, string $name): ?FieldData
    {
        $entityDefs = $this->ormDefs->getEntity($entityType);

        if (!$this->isForeign($entityType, $name)) {
            if (!$entityDefs->hasField($name)) {
                return null;
            }

            $type = $entityDefs
                ->getField($name)
                ->getType();

            return new FieldData($entityType, $name, $type);
        }

        $link = null;
        $field = null;

        if (
            $entityDefs->hasField($name) &&
            $entityDefs->getField($name)->getType() === 'foreign'
        ) {
            $fieldDefs = $entityDefs->getField($name);

            $link = $fieldDefs->getParam('link');
            $field = $fieldDefs->getParam('field');
        }
        else if (str_contains($name, '_')) {
            [$link, $field] = explode('_', $name);
        }

        if (!$link || !$field) {
            return null;
        }

        $entityDefs = $this->ormDefs->getEntity($entityType);

        if (!$entityDefs->hasRelation($link)) {
            return null;
        }

        $relationDefs = $entityDefs->getRelation($link);

        if (!$relationDefs->hasForeignEntityType()) {
            return null;
        }

        $foreignEntityType = $relationDefs->getForeignEntityType();

        $type = $this->ormDefs
            ->getEntity($foreignEntityType)
            ->getField($field)
            ->getType();

        return new FieldData($foreignEntityType, $field, $type);
    }
}
