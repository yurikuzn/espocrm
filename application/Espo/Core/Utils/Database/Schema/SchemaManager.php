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

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Schema as DbalSchema;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\SchemaDiff as DbalSchemaDiff;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Type;

use Espo\Core\Binding\BindingContainerBuilder;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Database\DBAL\Schema\Comparator;
use Espo\Core\Utils\Database\Helper;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata\OrmMetadataData;

use Throwable;

/**
 * A database schema manager.
 */
class SchemaManager
{
    private Comparator $comparator;
    private Builder $builder;

    /**
     * @throws DbalException
     */
    public function __construct(
        private OrmMetadataData $ormMetadataData,
        private Log $log,
        private Helper $helper,
        private MetadataProvider $metadataProvider,
        private InjectableFactory $injectableFactory
    ) {
        $this->comparator = new Comparator($this->getPlatform());

        $this->initFieldTypes();

        $this->builder = $this->injectableFactory->createWithBinding(
            Builder::class,
            BindingContainerBuilder::create()
                ->bindInstance(Helper::class, $this->helper)
                ->build()
        );
    }

    public function getDatabaseHelper(): Helper
    {
        return $this->helper;
    }

    private function getPlatform(): AbstractPlatform
    {
        return $this->getDbalConnection()->getDatabasePlatform();
    }

    private function getDbalConnection(): DbalConnection
    {
        return $this->getDatabaseHelper()->getDbalConnection();
    }

    /**
     * @throws DbalException
     */
    private function initFieldTypes(): void
    {
        foreach ($this->metadataProvider->getDbalTypeClassNameMap() as $type => $className) {
            Type::hasType($type) ?
                Type::overrideType($type, $className) :
                Type::addType($type, $className);

            $this->getDbalConnection()
                ->getDatabasePlatform()
                ->registerDoctrineTypeMapping($type, $type);
        }
    }

    /**
     * Rebuild database schema. Creates and alters needed tables and columns.
     * Does not remove columns, does not decrease column lengths.
     *
     * @param ?string[] $entityTypeList Specific entity types.
     * @throws SchemaException
     */
    public function rebuild(?array $entityTypeList = null): bool
    {
        $currentSchema = $this->getCurrentSchema();

        $schema = $this->builder->build($this->ormMetadataData->getData(), $entityTypeList);

        try {
            $this->processPreRebuildActions($currentSchema, $schema);
        }
        catch (Throwable $e) {
            $this->log->alert('Rebuild database pre-rebuild error: '. $e->getMessage());

            return false;
        }

        $queries = $this->getDiffSql($currentSchema, $schema);

        $result = true;

        $connection = $this->getDbalConnection();

        foreach ($queries as $sql) {
            $this->log->info('SCHEMA, Execute Query: '. $sql);

            try {
                $connection->executeQuery($sql);
            }
            catch (Throwable $e) {
                $this->log->alert('Rebuild database error: ' . $e->getMessage());

                $result = false;
            }
        }

        try {
            $this->processPostRebuildActions($currentSchema, $schema);
        }
        catch (Throwable $e) {
            $this->log->alert('Rebuild database post-rebuild error: ' . $e->getMessage());

            return false;
        }

        return $result;
    }

    /**
     * Get current database schema.
     */
    private function getCurrentSchema(): DbalSchema
    {
        return $this->getDbalConnection()
            ->getSchemaManager()
            ->createSchema();
    }

    /**
     * Get SQL queries of database schema.
     *
     * @return string[] Array of SQL queries.
     */
    private function toSql(DbalSchemaDiff $schema)
    {
        return $schema->toSaveSql($this->getPlatform());
    }

    /**
     * Get SQL queries to get from one to another schema.
     *
     * @return string[] Array of SQL queries.
     */
    private function getDiffSql(DbalSchema $fromSchema, DbalSchema $toSchema)
    {
        $diff = $this->comparator->compareSchemas($fromSchema, $toSchema);

        $this->amendSchemaDiff($diff);

        return $this->toSql($diff);
    }

    private function amendSchemaDiff(SchemaDiff $diff): void
    {
        $diff->removedTables = [];

        foreach ($diff->changedTables as $tableDiff) {
            $this->amendTableDiff($tableDiff);
        }

        print_r($this->toSql($diff)); die;
    }

    private function amendTableDiff(TableDiff $tableDiff): void
    {
        /**
         * @todo Leave only for MariaDB?
         * MariaDB has re-name index as of
         * Test how long does it take to rename fo different databases.
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

        // Prevent decreasing length for string columns to prevent data loss.
        foreach ($tableDiff->changedColumns as $name => $columnDiff) {
            $fromColumn = $columnDiff->fromColumn;
            $column = $columnDiff->column;

            if (!$fromColumn) {
                continue;
            }

            if (!in_array('length', $columnDiff->changedProperties)) {
                continue;
            }

            $fromLength = $fromColumn->getLength() ?? 255;
            $length = $column->getLength() ?? 255;

            if ($fromLength <= $length) {
                continue;
            }

            $column->setLength($fromLength);

            if (count($columnDiff->changedProperties) === 1) {
                unset($tableDiff->changedColumns[$name]);

                continue;
            }

            $columnDiff->changedProperties = array_diff($columnDiff->changedProperties, ['length']);
        }
    }

    private function processPreRebuildActions(DbalSchema $actualSchema, DbalSchema $schema): void
    {
        $binding = BindingContainerBuilder::create()
            ->bindInstance(Helper::class, $this->helper)
            ->build();

        foreach ($this->metadataProvider->getPreRebuildActionClassNameList() as $className) {
            $action = $this->injectableFactory->createWithBinding($className, $binding);

            $action->process($actualSchema, $schema);
        }
    }

    private function processPostRebuildActions(DbalSchema $actualSchema, DbalSchema $schema): void
    {
        $binding = BindingContainerBuilder::create()
            ->bindInstance(Helper::class, $this->helper)
            ->build();

        foreach ($this->metadataProvider->getPostRebuildActionClassNameList() as $className) {
            $action = $this->injectableFactory->createWithBinding($className, $binding);

            $action->process($actualSchema, $schema);
        }
    }
}
