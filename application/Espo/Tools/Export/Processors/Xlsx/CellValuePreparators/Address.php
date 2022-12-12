<?php

namespace Espo\Tools\Export\Processors\Xlsx\CellValuePreparators;

use Espo\Core\Field\Address as AddressValue;
use Espo\Core\Field\Address\AddressFormatterFactory;
use Espo\Tools\Export\Processors\Xlsx\CellValuePreparator;

class Address implements CellValuePreparator
{
    public function __construct(
        private AddressFormatterFactory $formatterFactory
    ) {}

    public function prepare(string $name, array $data): ?string
    {
        $address = AddressValue::createBuilder()
            ->setStreet($row[$name . 'Street'] ?? null)
            ->setCity($row[$name . 'City'] ?? null)
            ->setState($row[$name . 'State'] ?? null)
            ->setCountry($row[$name . 'Country'] ?? null)
            ->setPostalCode($row[$name . 'PostalCode'] ?? null)
            ->build();

        $formatter = $this->formatterFactory->createDefault();

        return $formatter->format($address) ?: null;
    }
}
