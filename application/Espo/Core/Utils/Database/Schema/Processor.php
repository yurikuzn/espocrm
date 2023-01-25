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

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Database\Schema\Utils as SchemaUtils;
use Espo\Core\Utils\File\Manager as FileManager;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\Core\Utils\Module\PathProvider;
use Espo\Core\Utils\Util;

use Espo\ORM\Defs\AttributeDefs;
use Espo\ORM\Defs\EntityDefs;
use Espo\ORM\Defs\IndexDefs;
use Espo\ORM\Defs\RelationDefs;
use Espo\ORM\Entity;

use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\Schema as DbalSchema;
use Doctrine\DBAL\Types\Type as DbalType;

class Processor
{
    private ?DbalSchema $dbalSchema = null;

    private const DEFAULT_PLATFORM = 'Mysql';
    private const ID_LENGTH = 24;
    private const DEFAULT_VARCHAR_LENGTH = 255;

    private string $tablesPath = 'Core/Utils/Database/Schema/tables';

    /** @var string[] */
    private $typeList;

    /** @var string[] */
    private $notStorableTypes = [
        'foreign',
    ];

    private ColumnPreparator $columnPreparator;

    public function __construct(
        private Metadata $metadata,
        private FileManager $fileManager,
        private Config $config,
        private Log $log,
        private PathProvider $pathProvider,
        ColumnPreparatorFactory $columnPreparatorFactory
    ) {
        $this->typeList = array_keys(DbalType::getTypesMap());

        $platform = $this->config->get('database.platform') ?? self::DEFAULT_PLATFORM;

        $this->columnPreparator = $columnPreparatorFactory->create($platform);
    }

    private function getSchema(bool $reload = false): DbalSchema
    {
        if (!isset($this->dbalSchema) || $reload) {
            $this->dbalSchema = new DbalSchema();
        }

        return $this->dbalSchema;
    }

    /**
     * Schema conversation process.
     *
     * @param array<string, mixed> $ormMeta
     * @param string[]|string|null $entityList
     * @throws SchemaException
     */
    public function process(array $ormMeta, $entityList = null): DbalSchema
    {
        $this->log->debug('Schema\Processor - Start: building schema');

        $ormMeta = $this->amendMetadata($ormMeta, $entityList);
        $indexes = SchemaUtils::getIndexes($ormMeta);
        $tables = [];

        $schema = $this->getSchema(true);

        foreach ($ormMeta as $entityType => $entityParams) {
            $entityDefs = EntityDefs::fromRaw($entityParams, $entityType);
            $itemIndexes = $indexes[$entityType] ?? [];

            $this->processEntity($entityDefs, $schema, $tables, $itemIndexes);
        }

        foreach ($ormMeta as $entityType => $entityParams) {
            foreach (($entityParams['relations'] ?? []) as $relationName => $relationParams) {
                $relationDefs = RelationDefs::fromRaw($relationParams, $relationName);

                if ($relationDefs->getType() !== Entity::MANY_MANY) {
                    continue;
                }

                $this->processManyMany($entityType, $relationDefs, $schema, $tables);
            }
        }

        $this->log->debug('Schema\Processor - End: building schema');

        return $schema;
    }

    /**
     * @param array<string, Table> $tables
     * @param array<string, array<string, mixed>> $indexes
     * @throws SchemaException
     */
    private function processEntity(
        EntityDefs $entityDefs,
        DbalSchema $schema,
        array &$tables,
        array $indexes
    ): void {

        if ($entityDefs->getParam('skipRebuild')) {
            return;
        }

        $entityType = $entityDefs->getName();

        $tableName = Util::toUnderScore($entityType);

        if ($schema->hasTable($tableName)) {
            $tables[$entityType] ??= $schema->getTable($tableName);

            $this->log->debug('DBAL: Table [' . $tableName . '] exists.');

            return;
        }

        $table = $schema->createTable($tableName);

        $tables[$entityType] = $table;

        /** @var array<string, mixed> $tableParams */
        $tableParams = $entityDefs->getParam('params') ?? [];

        foreach ($tableParams as $paramName => $paramValue) {
            $table->addOption($paramName, $paramValue);
        }

        $primaryColumns = [];

        foreach ($entityDefs->getAttributeList() as $attributeDefs) {
            if (
                $attributeDefs->isNotStorable() ||
                in_array($attributeDefs->getType(), $this->notStorableTypes)
            ) {
                continue;
            }

            $column = $this->columnPreparator->prepare($attributeDefs);

            if ($attributeDefs->getType() === Entity::ID) {
                $primaryColumns[] = $column->getName();
            }

            if (!in_array($column->getType(), $this->typeList)) {
                $this->log->debug(
                    'Converters\Schema::process(): Column type [' . $column->getType() . '] not supported, ' .
                    $entityType . ':' . $attributeDefs->getName()
                );

                continue;
            }

            if ($table->hasColumn($column->getName())) {
                continue;
            }

            $this->addColumn($table, $column);
        }

        $table->setPrimaryKey($primaryColumns);

        $this->addIndexes($table, $indexes);
    }

    /**
     * @param array<string, mixed> $ormMeta
     * @param string[]|string|null $entityList
     * @return array<string, mixed>
     */
    private function amendMetadata(array $ormMeta, $entityList): array
    {
        /** @var array<string, mixed> $ormMeta */
        $ormMeta = Util::merge($ormMeta, $this->getCustomTables($ormMeta));

        if (isset($ormMeta['unsetIgnore'])) {
            $protectedOrmMeta = [];

            foreach ($ormMeta['unsetIgnore'] as $protectedKey) {
                $protectedOrmMeta = Util::merge(
                    $protectedOrmMeta,
                    Util::fillArrayKeys($protectedKey, Util::getValueByKey($ormMeta, $protectedKey))
                );
            }

            unset($ormMeta['unsetIgnore']);
        }

        // Unset some keys.
        if (isset($ormMeta['unset'])) {
            /** @var array<string, mixed> $ormMeta */
            $ormMeta = Util::unsetInArray($ormMeta, $ormMeta['unset']);

            unset($ormMeta['unset']);
        }

        if (isset($protectedOrmMeta)) {
            /** @var array<string, mixed> $ormMeta */
            $ormMeta = Util::merge($ormMeta, $protectedOrmMeta);
        }

        if (isset($entityList)) {
            $entityList = is_string($entityList) ? (array) $entityList : $entityList;

            $dependentEntities = $this->getDependentEntities($entityList, $ormMeta);

            $this->log->debug(
                'Rebuild Database for entities: [' .
                implode(', ', $entityList) . '] with dependent entities: [' .
                implode(', ', $dependentEntities) . ']'
            );

            $ormMeta = array_intersect_key($ormMeta, array_flip($dependentEntities));
        }

        return $ormMeta;
    }

    /**
     * @throws SchemaException
     */
    private function addColumn(Table $table, Column $column): void
    {
        $table->addColumn(
            $column->getName(),
            $column->getType(),
            self::convertColumnOptions($column)
        );
    }

    /**
     * Prepare a relation table for the manyMany relation.
     *
     * @param string $entityType
     * @param array<string, Table> $tables
     * @throws SchemaException
     */
    private function processManyMany(
        string $entityType,
        RelationDefs $relationDefs,
        DbalSchema $schema,
        array &$tables
    ): void {

        $relationshipName = $relationDefs->getRelationshipName();

        if (isset($tables[$relationshipName])) {
            return;
        }

        $tableName = Util::toUnderScore($relationshipName);

        $this->log->debug("Schema\Processor: ManyMany for {$entityType}.{$relationDefs->getName()}");

        if ($schema->hasTable($tableName)) {
            $this->log->debug('Schema\Processor: Table [' . $tableName . '] exists.');

            $tables[$relationshipName] ??= $schema->getTable($tableName);

            return;
        }

        $table = $this->getSchema()->createTable($tableName);

        $idColumn = $this->columnPreparator->prepare(
            AttributeDefs::fromRaw([
                'dbType' => 'bigint',
                'type' => Entity::ID,
                'len' => 20,
                'autoincrement' => true,
            ], 'id')
        );

        $this->addColumn($table, $idColumn);

        if (!$relationDefs->hasMidKey() || !$relationDefs->getForeignMidKey()) {
            $this->log->error('Schema\Processor: Relationship midKeys are empty.', [
                'entityType' => $entityType,
                'relationName' => $relationDefs->getName(),
            ]);

            return;
        }

        $midKeys = [
            $relationDefs->getMidKey(),
            $relationDefs->getForeignMidKey(),
        ];

        foreach ($midKeys as $midKey) {
            $column = $this->columnPreparator->prepare(
                AttributeDefs::fromRaw([
                    'dbType' => Entity::VARCHAR,
                    'type' => Entity::FOREIGN_ID,
                    'len' => self::ID_LENGTH,
                ], $midKey)
            );

            $this->addColumn($table, $column);
        }

        /** @var array<string, array<string, mixed>> $additionalColumns */
        $additionalColumns = $relationDefs->getParam('additionalColumns') ?? [];

        foreach ($additionalColumns as $fieldName => $fieldParams) {
            if (!isset($fieldParams['type'])) {
                $fieldParams = array_merge($fieldParams, [
                    'type' => Entity::VARCHAR,
                    'len' => self::DEFAULT_VARCHAR_LENGTH,
                ]);
            }

            $column = $this->columnPreparator->prepare(
                AttributeDefs::fromRaw($fieldParams, $fieldName)
            );

            $this->addColumn($table, $column);
        }

        $deletedColumn = $this->columnPreparator->prepare(
            AttributeDefs::fromRaw([
                'type' => Entity::BOOL,
                'default' => false,
            ], 'deleted')
        );

        $this->addColumn($table, $deletedColumn);

        $table->setPrimaryKey(['id']);

        /** @var ?array<string, array<string, mixed>> $indexes */
        $indexes = $relationDefs->getParam('indexes');

        if ($indexes) {
            $normalizedIndexes = SchemaUtils::getIndexes([
                $entityType => ['indexes' => $indexes]
            ]);

            $this->addIndexes($table, $normalizedIndexes[$entityType]);
        }

        $tables[$relationshipName] = $table;
    }

    /**
     * @param array<string, array<string, mixed>> $indexes
     * @throws SchemaException
     */
    private function addIndexes(Table $table, array $indexes): void
    {
        foreach ($indexes as $indexName => $indexParams) {
            $indexDefs = IndexDefs::fromRaw($indexParams, $indexName);

            if ($indexDefs->isUnique()) {
                $table->addUniqueIndex($indexDefs->getColumnList(), $indexName);

                continue;
            }

            $table->addIndex($indexDefs->getColumnList(), $indexName, $indexDefs->getFlagList());
        }
    }

    /**
     * @todo Move to static class. Add unit test.
     * @return array<string, mixed>
     */
    private static function convertColumnOptions(Column $options): array
    {
        $result = [
            'notnull' => $options->isNotNull(),
        ];

        if ($options->getLength() !== null) {
            $result['length'] = $options->getLength();
        }

        if ($options->getDefault() !== null) {
            $result['default'] = $options->getDefault();
        }

        if ($options->getAutoincrement() !== null) {
            $result['autoincrement'] = $options->getAutoincrement();
        }

        if ($options->getPrecision() !== null) {
            $result['precision'] = $options->getPrecision();
        }

        if ($options->getScale() !== null) {
            $result['scale'] = $options->getScale();
        }

        if ($options->getUnsigned() !== null) {
            $result['unsigned'] = $options->getUnsigned();
        }

        if ($options->getPlatformOptions()) {
            $result['platformOptions'] = [];

            if ($options->getPlatformOptions()->getCollation()) {
                $result['platformOptions']['collation'] = $options->getPlatformOptions()->getCollation();
            }
        }

        return $result;
    }

    /**
     * Get custom table definition in
     * `application/Espo/Core/Utils/Database/Schema/tables/` and in metadata 'additionalTables'.
     *
     * @param array<string,mixed> $ormMeta
     * @return array<string,array<string,mixed>>
     */
    private function getCustomTables(array $ormMeta): array
    {
        $customTables = $this->loadData($this->pathProvider->getCore() . $this->tablesPath);

        foreach ($this->metadata->getModuleList() as $moduleName) {
            $modulePath = $this->pathProvider->getModule($moduleName) . $this->tablesPath;

            $customTables = Util::merge(
                $customTables,
                $this->loadData($modulePath)
            );
        }

        /** @var array<string,mixed> $customTables */
        $customTables = Util::merge(
            $customTables,
            $this->loadData($this->pathProvider->getCustom() . $this->tablesPath)
        );

        // Get custom tables from metadata 'additionalTables'.
        foreach ($ormMeta as $entityParams) {
            if (isset($entityParams['additionalTables']) && is_array($entityParams['additionalTables'])) {
                /** @var array<string,mixed> $customTables */
                $customTables = Util::merge($customTables, $entityParams['additionalTables']);
            }
        }

        return $customTables;
    }

    /**
     *
     * @param string[]|string $entityList
     * @param array<string,mixed> $ormMeta
     * @param string[] $dependentEntities
     * @return string[]
     */
    private function getDependentEntities($entityList, $ormMeta, $dependentEntities = [])
    {
        if (is_string($entityList)) {
            $entityList = (array) $entityList;
        }

        foreach ($entityList as $entityName) {
            if (in_array($entityName, $dependentEntities)) {
                continue;
            }

            $dependentEntities[] = $entityName;

            if (array_key_exists('relations', $ormMeta[$entityName])) {
                foreach ($ormMeta[$entityName]['relations'] as $relationName => $relationParams) {
                    if (isset($relationParams['entity'])) {
                        $relationEntity = $relationParams['entity'];

                        if (!in_array($relationEntity, $dependentEntities)) {
                            $dependentEntities = $this->getDependentEntities(
                                $relationEntity,
                                $ormMeta,
                                $dependentEntities
                            );
                        }
                    }
                }
            }
        }

        return $dependentEntities;
    }

    /**
     * @param string $path
     * @return array<string, array<string, mixed>>
     */
    private function loadData(string $path): array
    {
        $tables = [];

        if (!file_exists($path)) {
            return $tables;
        }

        /** @var string[] $fileList */
        $fileList = $this->fileManager->getFileList($path, false, '\.php$', true);

        foreach ($fileList as $fileName) {
            $itemPath = $path . '/' . $fileName;

            if (!$this->fileManager->isFile($itemPath)) {
                continue;
            }

            $fileData = $this->fileManager->getPhpContents($itemPath);

            if (!is_array($fileData)) {
                continue;
            }

            /** @var array<string, array<string, mixed>> $tables */
            $tables = Util::merge($tables, $fileData);
        }

        return $tables;
    }
}
