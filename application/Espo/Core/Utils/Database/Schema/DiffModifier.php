<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2023 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
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

namespace Espo\Core\Utils\Database\Schema;

use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Schema\ColumnDiff;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\TextType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Espo\Core\Utils\Database\DBAL\Types\LongtextType;
use Espo\Core\Utils\Database\DBAL\Types\MediumtextType;

class DiffModifier
{
    /**
     * @throws DbalException
     */
    public function modify(SchemaDiff $diff): void
    {
        $diff->removedTables = [];

        foreach ($diff->changedTables as $tableDiff) {
            $this->amendTableDiff($tableDiff);
        }
    }

    /**
     * @throws DbalException
     */
    private function amendTableDiff(TableDiff $tableDiff): void
    {
        /**
         * @todo Leave only for MariaDB?
         * MariaDB supports RENAME INDEX as of v10.5.
         * Find out how long does it take to rename fo different databases.
         */
        // Prevent index renaming as an operation may take a lot of time.
        $tableDiff->renamedIndexes = [];

        // Prevent column removal to prevent data loss.
        $tableDiff->removedColumns = [];

        // Prevent column renaming as a not desired behavior.
        foreach ($tableDiff->renamedColumns as $renamedColumn) {
            $addedName = strtolower($renamedColumn->getName());
            $tableDiff->addedColumns[$addedName] = $renamedColumn;
        }

        $tableDiff->renamedColumns = [];

        foreach ($tableDiff->changedColumns as $name => $columnDiff) {
            // Prevent decreasing length for string columns to prevent data loss.
            $this->amendColumnDiffLength($tableDiff, $columnDiff, $name);
            // Prevent longtext => mediumtext to prevent data loss.
            $this->amendColumnDiffTextType($tableDiff, $columnDiff, $name);
            // Prevent changing collation.
            $this->amendColumnDiffCollation($tableDiff, $columnDiff, $name);
            // Prevent changing charset.
            $this->amendColumnDiffCharset($tableDiff, $columnDiff, $name);
        }

        //print_r($tableDiff->changedColumns);die;
    }

    private function amendColumnDiffLength(TableDiff $tableDiff, ColumnDiff $columnDiff, string $name): void
    {
        $fromColumn = $columnDiff->fromColumn;
        $column = $columnDiff->column;

        if (!$fromColumn) {
            return;
        }

        if (!in_array('length', $columnDiff->changedProperties)) {
            return;
        }

        $fromLength = $fromColumn->getLength() ?? 255;
        $length = $column->getLength() ?? 255;

        if ($fromLength <= $length) {
            return;
        }

        $column->setLength($fromLength);

        self::unsetChangedColumnProperty($tableDiff, $columnDiff, $name, 'length');
    }

    /**
     * @throws DbalException
     */
    private function amendColumnDiffTextType(TableDiff $tableDiff, ColumnDiff $columnDiff, string $name): void
    {
        $fromColumn = $columnDiff->fromColumn;
        $column = $columnDiff->column;

        if (!$fromColumn) {
            return;
        }

        if (!in_array('type', $columnDiff->changedProperties)) {
            return;
        }

        $fromType = $fromColumn->getType();
        $type = $column->getType();

        if (
            !$fromType instanceof TextType ||
            !$type instanceof TextType
        ) {
            return;
        }

        $typePriority = [
            Types::TEXT,
            MediumtextType::NAME,
            LongtextType::NAME,
        ];

        $fromIndex = array_search($fromType->getName(), $typePriority);
        $index = array_search($type->getName(), $typePriority);

        if ($index >= $fromIndex) {
            return;
        }

        $column->setType(Type::getType($fromType->getName()));

        self::unsetChangedColumnProperty($tableDiff, $columnDiff, $name, 'type');
    }

    private function amendColumnDiffCollation(TableDiff $tableDiff, ColumnDiff $columnDiff, string $name): void
    {
        $fromColumn = $columnDiff->fromColumn;
        $column = $columnDiff->column;

        if (!$fromColumn) {
            return;
        }

        if (!in_array('collation', $columnDiff->changedProperties)) {
            return;
        }

        $fromCollation = $fromColumn->getPlatformOption('collation');

        if (!$fromCollation) {
            return;
        }

        $column->setPlatformOption('collation', $fromCollation);

        self::unsetChangedColumnProperty($tableDiff, $columnDiff, $name, 'collation');
    }

    private function amendColumnDiffCharset(TableDiff $tableDiff, ColumnDiff $columnDiff, string $name): void
    {
        $fromColumn = $columnDiff->fromColumn;
        $column = $columnDiff->column;

        if (!$fromColumn) {
            return;
        }

        if (!in_array('charset', $columnDiff->changedProperties)) {
            return;
        }

        $fromCharset = $fromColumn->getPlatformOption('charset');

        if (!$fromCharset) {
            return;
        }

        $column->setPlatformOption('charset', $fromCharset);

        self::unsetChangedColumnProperty($tableDiff, $columnDiff, $name, 'charset');
    }

    private static function unsetChangedColumnProperty(
        TableDiff $tableDiff,
        ColumnDiff $columnDiff,
        string $name,
        string $property
    ): void {

        if (count($columnDiff->changedProperties) === 1) {
            unset($tableDiff->changedColumns[$name]);

            //return;
        }

        $columnDiff->changedProperties = array_diff($columnDiff->changedProperties, [$property]);
    }
}
