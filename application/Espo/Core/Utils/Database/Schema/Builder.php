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

class Builder
{
    private const DEFAULT_PLATFORM = 'Mysql';
    private const ID_LENGTH = 24; // @todo Make configurable.
    private const DEFAULT_VARCHAR_LENGTH = 255;

    private string $tablesPath = 'Core/Utils/Database/Schema/tables';
    /** @var string[] */
    private $typeList;
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

    /**
     * Schema conversation process.
     *
     * @param array<string, mixed> $ormMeta
     * @param ?string[] $entityList
     * @throws SchemaException
     */
    public function build(array $ormMeta, $entityList = null): DbalSchema
    {
        $this->log->debug('Schema\Builder - Start');

        $ormMeta = $this->amendMetadata($ormMeta, $entityList);
        $tables = [];

        $schema = new DbalSchema();

        foreach ($ormMeta as $entityType => $entityParams) {
            $entityDefs = EntityDefs::fromRaw($entityParams, $entityType);

            $this->buildEntity($entityDefs, $schema, $tables);
        }

        foreach ($ormMeta as $entityType => $entityParams) {
            foreach (($entityParams['relations'] ?? []) as $relationName => $relationParams) {
                $relationDefs = RelationDefs::fromRaw($relationParams, $relationName);

                if ($relationDefs->getType() !== Entity::MANY_MANY) {
                    continue;
                }

                $this->buildManyMany($entityType, $relationDefs, $schema, $tables);
            }
        }

        $this->log->debug('Schema\Builder - End');

        return $schema;
    }

    /**
     * @param array<string, Table> $tables
     * @throws SchemaException
     */
    private function buildEntity(EntityDefs $entityDefs, DbalSchema $schema, array &$tables): void
    {
        if ($entityDefs->getParam('skipRebuild')) {
            return;
        }

        $entityType = $entityDefs->getName();

        $this->log->debug("Schema\Builder: Entity {$entityType}");

        $tableName = Util::toUnderScore($entityType);

        if ($schema->hasTable($tableName)) {
            $tables[$entityType] ??= $schema->getTable($tableName);

            $this->log->debug('Schema\Builder: Table [' . $tableName . '] exists.');

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
                $attributeDefs->getType() === Entity::FOREIGN
            ) {
                continue;
            }

            $column = $this->columnPreparator->prepare($attributeDefs);

            if ($attributeDefs->getType() === Entity::ID) {
                $primaryColumns[] = $column->getName();
            }

            if (!in_array($column->getType(), $this->typeList)) {
                $this->log->debug(
                    'Schema\Builder: Column type [' . $column->getType() . '] not supported, ' .
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

        $this->addIndexes($table, $entityDefs->getIndexList());
    }

    /**
     * @param array<string, mixed> $ormMeta
     * @param ?string[] $entityList
     * @return array<string, mixed>
     */
    private function amendMetadata(array $ormMeta, $entityList): array
    {
        /** @var array<string, mixed> $ormMeta */
        $ormMeta = Util::merge(
            $ormMeta,
            $this->getCustomTables()
        );

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
            $dependentEntities = $this->getDependentEntities($entityList, $ormMeta);

            $this->log->debug(
                'Schema\Builder: Rebuild for entity types: [' .
                implode(', ', $entityList) . '] with dependent entity types: [' .
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
            self::convertColumn($column)
        );
    }

    /**
     * Prepare a relation table for the manyMany relation.
     *
     * @param string $entityType
     * @param array<string, Table> $tables
     * @throws SchemaException
     */
    private function buildManyMany(
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

        $this->log->debug("Schema\Builder: ManyMany for {$entityType}.{$relationDefs->getName()}");

        if ($schema->hasTable($tableName)) {
            $this->log->debug('Schema\Builder: Table [' . $tableName . '] exists.');

            $tables[$relationshipName] ??= $schema->getTable($tableName);

            return;
        }

        $table = $schema->createTable($tableName);

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
            $this->log->error('Schema\Builder: Relationship midKeys are empty.', [
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

        $this->addIndexes($table, $relationDefs->getIndexList());

        $tables[$relationshipName] = $table;
    }

    /**
     * @param IndexDefs[] $indexDefsList
     * @throws SchemaException
     */
    private function addIndexes(Table $table, array $indexDefsList): void
    {
        foreach ($indexDefsList as $indexDefs) {
            $columns = array_map(
                fn($item) => Util::toUnderScore($item),
                $indexDefs->getColumnList()
            );

            if ($indexDefs->isUnique()) {
                $table->addUniqueIndex($columns, $indexDefs->getKey());

                continue;
            }

            $table->addIndex($columns, $indexDefs->getKey(), $indexDefs->getFlagList());
        }
    }

    /**
     * @todo Move to static class. Add unit test.
     * @return array<string, mixed>
     */
    private static function convertColumn(Column $column): array
    {
        $result = [
            'notnull' => $column->isNotNull(),
        ];

        if ($column->getLength() !== null) {
            $result['length'] = $column->getLength();
        }

        if ($column->getDefault() !== null) {
            $result['default'] = $column->getDefault();
        }

        if ($column->getAutoincrement() !== null) {
            $result['autoincrement'] = $column->getAutoincrement();
        }

        if ($column->getPrecision() !== null) {
            $result['precision'] = $column->getPrecision();
        }

        if ($column->getScale() !== null) {
            $result['scale'] = $column->getScale();
        }

        if ($column->getUnsigned() !== null) {
            $result['unsigned'] = $column->getUnsigned();
        }

        if ($column->getPlatformOptions()) {
            $result['platformOptions'] = [];

            if ($column->getPlatformOptions()->getCollation()) {
                $result['platformOptions']['collation'] = $column->getPlatformOptions()->getCollation();
            }
        }

        return $result;
    }

    /**
     * Get custom table definition in `application/Espo/Core/Utils/Database/Schema/tables`.
     * This logic can be removed in the future. Usage of table files in not recommended.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getCustomTables(): array
    {
        $customTables = $this->loadData($this->pathProvider->getCore() . $this->tablesPath);

        foreach ($this->metadata->getModuleList() as $moduleName) {
            $modulePath = $this->pathProvider->getModule($moduleName) . $this->tablesPath;

            $customTables = Util::merge(
                $customTables,
                $this->loadData($modulePath)
            );
        }

        /** @var array<string, mixed> $customTables */
        $customTables = Util::merge(
            $customTables,
            $this->loadData($this->pathProvider->getCustom() . $this->tablesPath)
        );

        return $customTables;
    }

    /**
     * @param string[] $entityList
     * @param array<string, mixed> $ormMeta
     * @param string[] $dependentEntities
     * @return string[]
     */
    private function getDependentEntities(array $entityList, array $ormMeta, array $dependentEntities = []): array
    {
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
                                [$relationEntity],
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
