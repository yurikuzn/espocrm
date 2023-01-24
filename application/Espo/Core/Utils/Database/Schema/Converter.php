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

use Doctrine\DBAL\Schema\SchemaException;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Database\Schema\Utils as SchemaUtils;
use Espo\Core\Utils\File\Manager as FileManager;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\Core\Utils\Module\PathProvider;
use Espo\Core\Utils\Util;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\Schema as DbalSchema;
use Doctrine\DBAL\Types\Type as DbalType;
use Espo\ORM\Defs\AttributeDefs;
use Espo\ORM\Defs\IndexDefs;
use Espo\ORM\Entity;

class Converter
{
    private ?DbalSchema $dbalSchema = null;

    private const DEFAULT_PLATFORM = 'Mysql';
    private const ID_LENGTH = 24;
    private const DEFAULT_VARCHAR_LENGTH = 255;

    private string $tablesPath = 'Core/Utils/Database/Schema/tables';

    /** @var string[] */
    private $typeList;

    /**
     * ORM => doctrine
     * @var array<string,string>
     */
    private $allowedDbFieldParams = [
        'len' => 'length',
        'default' => 'default',
        'notNull' => 'notnull',
        'autoincrement' => 'autoincrement',
        'precision' => 'precision',
        'scale' => 'scale',
    ];

    /** @var string[] */
    private $notStorableTypes = [
        'foreign',
    ];

    private ColumnOptionsPreparator $columnOptionsPreparator;

    public function __construct(
        private Metadata $metadata,
        private FileManager $fileManager,
        private Config $config,
        private Log $log,
        private PathProvider $pathProvider,
        ColumnOptionsPreparatorFactory $columnOptionsPreparatorFactory
    ) {

        $this->typeList = array_keys(DbalType::getTypesMap());

        $platform = $this->config->get('database.platform') ?? self::DEFAULT_PLATFORM;

        $this->columnOptionsPreparator = $columnOptionsPreparatorFactory->create($platform);
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
        $this->log->debug('Schema\Converter - Start: building schema');

        // Check if exist files in "Tables" directory and merge with ormMetadata.

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

        // unset some keys in orm
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

        $schema = $this->getSchema(true);

        $indexes = SchemaUtils::getIndexes($ormMeta);

        $tables = [];

        foreach ($ormMeta as $entityName => $entityParams) {
            if ($entityParams['skipRebuild'] ?? false) {
                continue;
            }

            $tableName = Util::toUnderScore($entityName);

            if ($schema->hasTable($tableName)) {
                if (!isset($tables[$entityName])) {
                    $tables[$entityName] = $schema->getTable($tableName);
                }

                $this->log->debug('DBAL: Table ['.$tableName.'] exists.');

                continue;
            }

            $tables[$entityName] = $schema->createTable($tableName);

            if (isset($entityParams['params']) && is_array($entityParams['params'])) {
                foreach ($entityParams['params'] as $paramName => $paramValue) {
                    $tables[$entityName]->addOption($paramName, $paramValue);
                }
            }

            $primaryColumns = [];

            foreach ($entityParams['fields'] as $fieldName => $fieldParams) {
                $attributeDefs = AttributeDefs::fromRaw($fieldParams, $fieldName);

                if (
                    $attributeDefs->isNotStorable() ||
                    in_array($attributeDefs->getType(), $this->notStorableTypes)
                ) {
                    continue;
                }

                switch ($attributeDefs->getType()) {
                    case Entity::ID:
                        $primaryColumns[] = Util::toUnderScore($fieldName);

                        break;
                }

                $fieldType = $attributeDefs->getParam('dbType') ?? $attributeDefs->getType();

                /** Doctrine uses lower case for all field types. */
                $fieldType = strtolower($fieldType);

                if (!in_array($fieldType, $this->typeList)) {
                    $this->log->debug(
                        'Converters\Schema::process(): Field type [' . $fieldType . '] not supported, ' .
                        $entityName . ':' . $fieldName
                    );

                    continue;
                }

                $columnName = Util::toUnderScore($fieldName);

                if ($tables[$entityName]->hasColumn($columnName)) {
                    continue;
                }

                $columnOptions = $this->columnOptionsPreparator->prepare($attributeDefs);

                $tables[$entityName]->addColumn(
                    $columnName,
                    $columnOptions->getType(),
                    self::convertColumnOptions($columnOptions)
                );

                //$tables[$entityName]->addColumn($columnName, $fieldType, $this->getDbFieldParams($fieldParams));
            }

            $tables[$entityName]->setPrimaryKey($primaryColumns);

            if (!empty($indexes[$entityName])) {
                $this->addIndexes($tables[$entityName], $indexes[$entityName]);
            }
        }

        // Check and create columns/tables for relations.
        foreach ($ormMeta as $entityType => $entityParams) {
            if (!isset($entityParams['relations'])) {
                continue;
            }

            foreach ($entityParams['relations'] as $relationName => $relationParams) {
                 switch ($relationParams['type']) {
                    case Entity::MANY_MANY:
                        $tableName = $relationParams['relationName'];

                        // Check for duplicate tables.
                        if (!isset($tables[$tableName])) {
                            // No needs to create a table if it already exists.
                            $tables[$tableName] = $this->prepareManyMany($entityType, $relationParams);
                        }

                        break;
                }
            }
        }

        $this->log->debug('Schema\Converter - End: building schema');

        return $schema;
    }

    /**
     * Prepare a relation table for the manyMany relation.
     *
     * @param string $entityType
     * @param array<string, mixed> $relationParams
     * @throws SchemaException
     */
    private function prepareManyMany(string $entityType, $relationParams): Table
    {
        $relationName = $relationParams['relationName'];

        $tableName = Util::toUnderScore($relationName);

        $this->log->debug('DBAL: prepareManyMany invoked for ' . $entityType, [
            'tableName' => $tableName, 'parameters' => $relationParams
        ]);

        if ($this->getSchema()->hasTable($tableName)) {
            $this->log->debug('DBAL: Table ['.$tableName.'] exists.');

            return $this->getSchema()->getTable($tableName);
        }

        $table = $this->getSchema()->createTable($tableName);

        $idColumnOptions = $this->columnOptionsPreparator->prepare(
            AttributeDefs::fromRaw([
                'dbType' => 'bigint',
                'type' => Entity::ID,
                'len' => 20,
                'autoincrement' => true,
            ], 'id')
        );

        $table->addColumn(
            'id',
            $idColumnOptions->getType(),
            self::convertColumnOptions($idColumnOptions)
        );

        $midKeys = $relationParams['midKeys'] ?? [];

        if ($midKeys === []) {
            $this->log->warning('REBUILD: Relationship midKeys are empty.', [
                'scope' => $entityType,
                'tableName' => $tableName,
                'parameters' => $relationParams,
            ]);
        }

        foreach ($midKeys as $midKey) {
            $columnOptions = $this->columnOptionsPreparator->prepare(
                AttributeDefs::fromRaw([
                    'dbType' => Entity::VARCHAR,
                    'type' => Entity::FOREIGN_ID,
                    'len' => self::ID_LENGTH,
                ], $midKey)
            );

            $table->addColumn(
                Util::toUnderScore($midKey),
                $columnOptions->getType(),
                self::convertColumnOptions($columnOptions)
            );
        }

        foreach (($relationParams['additionalColumns'] ?? []) as $fieldName => $fieldParams) {
            if (!isset($fieldParams['type'])) {
                $fieldParams = array_merge($fieldParams, [
                    'type' => Entity::VARCHAR,
                    'len' => self::DEFAULT_VARCHAR_LENGTH,
                ]);
            }

            $columnOptions = $this->columnOptionsPreparator->prepare(
                AttributeDefs::fromRaw($fieldParams, $fieldName)
            );

            $table->addColumn(
                Util::toUnderScore($fieldName),
                $columnOptions->getType(),
                self::convertColumnOptions($columnOptions)
            );
        }

        $deletedColumnOptions = $this->columnOptionsPreparator->prepare(
            AttributeDefs::fromRaw([
                'type' => Entity::BOOL,
                'default' => false,
            ], 'deleted')
        );

        $table->addColumn(
            'deleted',
            $deletedColumnOptions->getType(),
            self::convertColumnOptions($deletedColumnOptions)
        );

        $table->setPrimaryKey(['id']);

        if (!empty($relationParams['indexes'])) {
            $normalizedIndexes = SchemaUtils::getIndexes([
                $entityType => [
                    'indexes' => $relationParams['indexes']
                ]
            ]);

            $this->addIndexes($table, $normalizedIndexes[$entityType]);
        }

        return $table;
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
    private static function convertColumnOptions(ColumnOptions $options): array
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
     * @param array<string, mixed> $fieldParams
     * @return array<string, mixed>
     */
    private function getDbFieldParams($fieldParams)
    {
        $dbFieldParams = [
            'notnull' => false,
        ];

        foreach ($this->allowedDbFieldParams as $espoName => $dbalName) {
            if (isset($fieldParams[$espoName])) {
                $dbFieldParams[$dbalName] = $fieldParams[$espoName];
            }
        }

        $databaseParams = $this->config->get('database');

        if (!isset($databaseParams['charset']) || $databaseParams['charset'] == 'utf8mb4') {
            $dbFieldParams['platformOptions'] = [
                'collation' => 'utf8mb4_unicode_ci',
            ];
        }

        switch ($fieldParams['type']) {
            case 'id':
            case 'foreignId':
            case 'foreignType':
                /*if ($this->getMaxIndexLength() < 3072) {
                    $fieldParams['utf8mb3'] = true;
                }*/

                break;

            case 'array':
            case 'jsonArray':
            case 'text':
            case 'longtext':
                unset($dbFieldParams['default']); // for db type TEXT can't be defined a default value

                break;

            case 'bool':
                $default = false;

                if (array_key_exists('default', $dbFieldParams)) {
                    $default = $dbFieldParams['default'];
                }

                $dbFieldParams['default'] = intval($default);

                break;
        }

        if (
            $fieldParams['type'] != 'id' &&
            isset($fieldParams['autoincrement']) &&
            $fieldParams['autoincrement']
        ) {
            $dbFieldParams['notnull'] = true;
            $dbFieldParams['unsigned'] = true;
        }

        if (isset($fieldParams['binary']) && $fieldParams['binary']) {
            $dbFieldParams['platformOptions'] = [
                'collation' => 'utf8mb4_bin',
            ];
        }

        if (isset($fieldParams['utf8mb3']) && $fieldParams['utf8mb3']) {
            $dbFieldParams['platformOptions'] = [
                'collation' =>
                    (isset($fieldParams['binary']) && $fieldParams['binary']) ?
                    'utf8_bin' :
                    'utf8_unicode_ci',
            ];
        }

        return $dbFieldParams;
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

            /** @var array<string,array<string,mixed>> $tables */
            $tables = Util::merge($tables, $fileData);
        }

        return $tables;
    }
}
