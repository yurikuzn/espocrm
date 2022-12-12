<?php

namespace Espo\Tools\Export\Processors\Xlsx\CellValuePreparators;

use Espo\Core\Utils\Language;
use Espo\ORM\Defs;
use Espo\Tools\Export\Processors\Xlsx\CellValuePreparator;
use Espo\Tools\Export\Processors\Xlsx\FieldHelper;

class Enumeration implements CellValuePreparator
{
    public function __construct(
        private string $entityType,
        private Defs $ormDefs,
        private Language $language,
        private FieldHelper $fieldHelper
    ) {}

    public function prepare(string $name, array $data): ?string
    {
        if (!array_key_exists($name, $data)) {
            return null;
        }

        $value = $data[$name];

        $fieldData = $this->fieldHelper->getData($this->entityType, $name);

        if (!$fieldData) {
            return $value;
        }

        $entityType = $fieldData->getEntityType();
        $field = $fieldData->getField();

        $translation = $this->ormDefs
            ->getEntity($entityType)
            ->getField($field)
            ->getParam('translation');

        if (!$translation) {
            return $this->language->translateOption($value, $field, $entityType);
        }

        $map = $this->language->get($translation);

        if (!is_array($map)) {
            return $value;
        }

        return $map[$value] ?? $value;
    }
}
