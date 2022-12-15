<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2022 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Tools\Export\Format\Xlsx;

use Espo\Core\Utils\Language;
use Espo\ORM\Entity;
use Espo\Tools\Export\Collection;
use Espo\Tools\Export\Format\CellValuePreparator;
use Espo\Tools\Export\Format\CellValuePreparatorFactory;
use Espo\Tools\Export\Processor as ProcessorInterface;
use Espo\Tools\Export\Processor\Params;

use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Common\Entity\Row;

use Psr\Http\Message\StreamInterface;

class OpenSpoutProcessor implements ProcessorInterface
{
    private const FORMAT = 'xlsx';

    /** @var array<string, CellValuePreparator> */
    private array $preparatorsCache = [];
    /** @var array<string, string> */
    private array $typesCache = [];

    public function __construct(
        private FieldHelper $fieldHelper,
        private CellValuePreparatorFactory $cellValuePreparatorFactory,
        private Language $language,
    ) {}

    public function process(Params $params, Collection $collection): StreamInterface
    {
        $sheetView = new SheetView();
        $sheetView->setFreezeRow(2);

        $options = new Options();

        $writer = new Writer($options);

        $writer->getCurrentSheet()->setSheetView($sheetView);

        $labelList = [];

        foreach ($params->getFieldList() as $name) {
            $labelList[] = $this->translateLabel($params->getEntityType(), $name);
        }

        $writer->addRow(Row::fromValues($labelList));

        foreach ($collection as $entity) {
            $this->processRow($params, $entity, $writer);
        }
    }

    private function translateLabel(string $entityType, string $name): string
    {
        $label = $name;

        $fieldData = $this->fieldHelper->getData($entityType, $name);
        $isForeignReference = $this->fieldHelper->isForeignReference($name);

        if ($isForeignReference && $fieldData && $fieldData->getLink()) {
            $label =
                $this->language->translateLabel($fieldData->getLink(), 'links', $entityType) . '.' .
                $this->language->translateLabel($fieldData->getField(), 'fields', $fieldData->getEntityType());
        }

        if (!$isForeignReference) {
            $label = $this->language->translateLabel($name, 'fields', $entityType);
        }

        return $label;
    }

    private function processRow(Params $params, Entity $entity, Writer $writer): void
    {
        $valueList = [];

        foreach ($params->getFieldList() as $name) {
            $valueList = $this->prepareCellValue($params, $entity, $name);
        }

        $writer->addRow(Row::fromValues($valueList));
    }

    private function prepareCellValue(Params $params, Entity $entity, mixed $name): mixed
    {
        $entityType = $entity->getEntityType();
        $key = $entityType . '-' . $name;

        $type = $this->typesCache[$key] ?? null;

        if (!$type) {
            $fieldData = $this->fieldHelper->getData($entityType, $name);
            $type = $fieldData ? $fieldData->getType() : 'base';
            $this->typesCache[$key] = $type;
        }

        $preparator = $this->getPreparator($type);

        $value = $preparator->prepare($entity, $name);


    }

    private function getPreparator(string $type): CellValuePreparator
    {
        if (!array_key_exists($type, $this->preparatorsCache)) {
            $this->preparatorsCache[$type] = $this->cellValuePreparatorFactory->create(self::FORMAT, $type);
        }

        return $this->preparatorsCache[$type];
    }
}
