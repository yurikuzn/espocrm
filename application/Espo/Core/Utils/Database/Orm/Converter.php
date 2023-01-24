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

namespace Espo\Core\Utils\Database\Orm;

use Espo\Core\Utils\Util;
use Espo\ORM\Defs\IndexDefs;
use Espo\ORM\Entity;
use Espo\Core\Utils\Metadata;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Metadata\Helper as MetadataHelper;

class Converter
{
    /** @var ?array<string, mixed> */
    private $entityDefs = null;

    private string $defaultFieldType = 'varchar';

    /** @var array<string, int> */
    private $defaultLength = [
        'varchar' => 255,
        'int' => 11,
    ];

    /** @var array<string, mixed>  */
    private $defaultValue = [
        'bool' => false,
    ];

    /**
     * Mapping entityDefs => ORM.
     *
     * @var array<string, string>
     */
    private $fieldAccordances = [
        'type' => 'type',
        'dbType' => 'dbType',
        'maxLength' => 'len',
        'len' => 'len',
        'notNull' => 'notNull',
        'exportDisabled' => 'notExportable',
        'autoincrement' => 'autoincrement',
        'entity' => 'entity',
        'notStorable' => 'notStorable',
        'link' => 'relation',
        'field' => 'foreign',  // @todo change "foreign" to "field"
        'unique' => 'unique',
        'index' => 'index',
        'default' => 'default',
        'select' => 'select',
        'order' => 'order',
        'where' => 'where',
        'storeArrayValues' => 'storeArrayValues',
        'binary' => 'binary',
        'dependeeAttributeList' => 'dependeeAttributeList',
        'precision' => 'precision',
        'scale' => 'scale',
    ];

    /** @var array<string, mixed> */
    private $idParams = [
        'dbType' => 'varchar',
        'len' => 24,
    ];

    /**
     * Permitted entityDefs parameters which will be copied to ormMetadata.
     *
     * @var string[]
     */
    private $permittedEntityOptions = [
        'indexes',
        'additionalTables',
    ];

    public function __construct(
        private Metadata $metadata,
        private Config $config,
        private RelationManager $relationManager,
        private MetadataHelper $metadataHelper
    ) {}

    /**
     * @param bool $reload
     * @return array<string, mixed>
     */
    private function getEntityDefs($reload = false)
    {
        if (empty($this->entityDefs) || $reload) {
            $this->entityDefs = $this->metadata->get('entityDefs');
        }

        return $this->entityDefs;
    }

    /**
     * Covert metadata > entityDefs to ORM metadata.
     *
     * @return array<string, array<string, mixed>>
     */
    public function process(): array
    {
        $entityDefs = $this->getEntityDefs(true);

        $ormMetadata = [];

        foreach ($entityDefs as $entityType => $entityMetadata) {
            if ($entityMetadata['skipRebuild'] ?? false) {
                $ormMetadata[$entityType]['skipRebuild'] = true;
            }

            /** @var array<string, array<string, mixed>> $ormMetadata */
            $ormMetadata = Util::merge($ormMetadata, $this->convertEntity($entityType, $entityMetadata));
        }

        $ormMetadata = $this->afterFieldsProcess($ormMetadata);

        foreach ($ormMetadata as $entityType => $entityOrmMetadata) {
            /** @var array<string, array<string, mixed>> $ormMetadata */
            $ormMetadata = Util::merge(
                $ormMetadata,
                $this->createRelationsEntityDefs($entityOrmMetadata)
            );

            /** @var array<string, array<string, mixed>> $ormMetadata */
            $ormMetadata = Util::merge(
                $ormMetadata,
                $this->createAdditionalEntityTypes($entityOrmMetadata)
            );
        }

        return $this->afterProcess($ormMetadata);
    }

    /**
     * @todo Move to IndexHelper interface.
     */
    private function generateIndexName(IndexDefs $defs, string $entityType): string
    {
        $maxLength = 60;

        $name = $defs->getName();
        $prefix = $defs->isUnique() ? 'UNIQ' : 'IDX';

        $parts = [$prefix, strtoupper(Util::toUnderScore($name))];

        $key = implode('_', $parts);

        return substr($key, 0, $maxLength);
    }

    /**
     * @param array<string, mixed> $entityMetadata
     * @return array<string, mixed>
     */
    private function convertEntity(string $entityType, array $entityMetadata): array
    {
        $ormMetadata = [];

        $ormMetadata[$entityType] = [
            'fields' => [],
            'relations' => [],
        ];

        foreach ($this->permittedEntityOptions as $optionName) {
            if (isset($entityMetadata[$optionName])) {
                $ormMetadata[$entityType][$optionName] = $entityMetadata[$optionName];
            }
        }

        $ormMetadata[$entityType]['fields'] = $this->convertFields($entityType, $entityMetadata);

        $ormMetadata = $this->correctFields($entityType, $ormMetadata);

        $convertedLinks = $this->convertLinks($entityType, $entityMetadata, $ormMetadata);

        $ormMetadata = Util::merge($ormMetadata, $convertedLinks);

        $this->applyFullTextSearch($ormMetadata, $entityType);
        $this->applyIndexes($ormMetadata, $entityType);

        if (!empty($entityMetadata['collection']) && is_array($entityMetadata['collection'])) {
            $collectionDefs = $entityMetadata['collection'];

            $ormMetadata[$entityType]['collection'] = [];

            if (array_key_exists('orderByColumn', $collectionDefs)) {
                $ormMetadata[$entityType]['collection']['orderBy'] = $collectionDefs['orderByColumn'];
            }
            else if (array_key_exists('orderBy', $collectionDefs)) {
                if (array_key_exists($collectionDefs['orderBy'], $ormMetadata[$entityType]['fields'])) {
                    $ormMetadata[$entityType]['collection']['orderBy'] = $collectionDefs['orderBy'];
                }
            }

            $ormMetadata[$entityType]['collection']['order'] = 'ASC';

            if (array_key_exists('order', $collectionDefs)) {
                $ormMetadata[$entityType]['collection']['order'] = strtoupper($collectionDefs['order']);
            }
        }

        return $ormMetadata;
    }

    /**
     * @param array<string, mixed> $ormMetadata
     * @return array<string, mixed>
     */
    private function afterFieldsProcess(array $ormMetadata): array
    {
        foreach ($ormMetadata as $entityType => &$entityParams) {
            foreach ($entityParams['fields'] as $attribute => &$attributeParams) {

                /* remove fields without type */
                if (
                    !isset($attributeParams['type']) &&
                    (!isset($attributeParams['notStorable']) || $attributeParams['notStorable'] === false)
                ) {
                    unset($entityParams['fields'][$attribute]);

                    continue;
                }

                $attributeType = $attributeParams['type'] ?? null;

                switch ($attributeType) {
                    case Entity::ID:
                        if ($attributeParams['dbType'] != 'int') {
                            $attributeParams = array_merge($this->idParams, $attributeParams);
                        }

                        break;

                    case Entity::FOREIGN_ID:
                        $attributeParams = array_merge($this->idParams, $attributeParams);
                        $attributeParams['notNull'] = false;

                        break;

                    case Entity::FOREIGN_TYPE:
                        $attributeParams['dbType'] = Entity::VARCHAR;
                        if (empty($attributeParams['len'])) {
                            $attributeParams['len'] = $this->defaultLength['varchar'];
                        }

                        break;

                    case Entity::BOOL:
                        $attributeParams['default'] = isset($attributeParams['default']) ?
                            (bool) $attributeParams['default'] :
                            $this->defaultValue['bool'];

                        break;

                    default:
                        $constName = strtoupper(Util::toUnderScore($attributeParams['type']));

                        if (!defined('Espo\\ORM\\Entity::' . $constName)) {
                            $attributeParams['type'] = $this->defaultFieldType;
                        }

                        break;
                }
            }
        }

        return $ormMetadata;
    }

    /**
     * @param array<string, mixed> $ormMetadata
     * @return array<string, mixed>
     */
    private function afterProcess(array $ormMetadata): array
    {
        foreach ($ormMetadata as $entityType => &$entityParams) {
            foreach ($entityParams['fields'] as $attribute => &$attributeParams) {
                $attributeType = $attributeParams['type'] ?? null;

                switch ($attributeType) {
                    case Entity::FOREIGN:
                        $attributeParams['foreignType'] =
                            $this->obtainForeignType($ormMetadata, $entityType, $attribute);

                        break;
                }
            }
        }

        return $ormMetadata;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function obtainForeignType(array $data, string $entityType, string $attribute): ?string
    {
        $params = $data[$entityType]['fields'][$attribute] ?? [];

        $foreign = $params['foreign'] ?? null;
        $relation = $params['relation'] ?? null;

        if (!$foreign || !$relation) {
            return null;
        }

        $relationParams = $data[$entityType]['relations'][$relation] ?? [];

        $foreignEntityType = $relationParams['entity'] ?? null;

        if (!$foreignEntityType) {
            return null;
        }

        $foreignParams = $data[$foreignEntityType]['fields'][$foreign] ?? [];

        return $foreignParams['type'] ?? null;
    }

    /**
     * @param array<string, mixed> $entityMetadata
     * @return array<string, mixed>
     */
    private function convertFields(string $entityType, array &$entityMetadata): array
    {
        $entityMetadata['fields'] ??= [];

        // List of unmerged fields with default field definitions in $output.
        $unmergedFields = [
            'name',
        ];

        $output = [
            'id' => [
                'type' => Entity::ID,
                'dbType' => 'varchar',
            ],
            'name' => [
                'type' => $entityMetadata['fields']['name']['type'] ?? Entity::VARCHAR,
                'notStorable' => true,
            ],
            'deleted' => [
                'type' => Entity::BOOL,
                'default' => false,
            ],
        ];

        if ($entityMetadata['noDeletedAttribute'] ?? false) {
            unset($output['deleted']);
        }

        foreach ($entityMetadata['fields'] as $attribute => $attributeParams) {
            if (empty($attributeParams['type'])) {
                continue;
            }

            $fieldTypeMetadata = $this->metadataHelper->getFieldDefsByType($attributeParams);

            $fieldDefs = $this->convertField($entityType, $attribute, $attributeParams, $fieldTypeMetadata);

            if ($fieldDefs !== false) {
                if (isset($output[$attribute]) && !in_array($attribute, $unmergedFields)) {
                    $output[$attribute] = array_merge($output[$attribute], $fieldDefs);
                }
                else {
                    $output[$attribute] = $fieldDefs;
                }

                /** @var array<string,array<string,mixed>> $output */
            }

            if (isset($fieldTypeMetadata['linkDefs'])) {
                $linkDefs = $this->metadataHelper->getLinkDefsInFieldMeta(
                    $entityType,
                    $attributeParams
                );

                if (isset($linkDefs)) {
                    if (!isset($entityMetadata['links'])) {
                        $entityMetadata['links'] = [];
                    }

                    $entityMetadata['links'] = Util::merge(
                        [$attribute => $linkDefs],
                        $entityMetadata['links']
                    );
                }
            }
        }

        return $output;
    }

    /**
     * Correct fields definitions based on Espo\Custom\Core\Utils\Database\Orm\Fields.
     *
     * @param array<string, mixed> $ormMetadata
     * @return array<string, mixed>
     */
    private function correctFields(string $entityType, array $ormMetadata): array
    {
        $entityDefs = $this->getEntityDefs();

        $entityMetadata = $ormMetadata[$entityType];

        // Load custom field definitions.
        foreach ($entityMetadata['fields'] as $attribute => $attributeParams) {
            if (empty($attributeParams['type'])) {
                continue;
            }

            $fieldType = $attributeParams['type'];

            $className = $this->metadata->get(['fields', $fieldType, 'converterClassName']);

            if (!$className) {
                $className = 'Espo\Custom\Core\Utils\Database\Orm\Fields\\' . ucfirst($fieldType);

                if (!class_exists($className)) {
                    $className = 'Espo\Core\Utils\Database\Orm\Fields\\' . ucfirst($fieldType);
                }
            }

            if (
                class_exists($className) &&
                method_exists($className, 'load') &&
                method_exists($className, 'process')
            ) {
                $helperClass = new $className($this->metadata, $ormMetadata, $entityDefs, $this->config);

                assert(method_exists($helperClass, 'process'));

                $fieldResult = $helperClass->process($attribute, $entityType);

                if (isset($fieldResult['unset'])) {
                    $ormMetadata = Util::unsetInArray($ormMetadata, $fieldResult['unset']);

                    unset($fieldResult['unset']);
                }

                /** @var array<string,mixed> $ormMetadata */
                $ormMetadata = Util::merge($ormMetadata, $fieldResult);
            }

            $defaultAttributes = $this->metadata
                ->get(['entityDefs', $entityType, 'fields', $attribute, 'defaultAttributes']);

            if ($defaultAttributes && array_key_exists($attribute, $defaultAttributes)) {
                $defaultMetadataPart = [
                    $entityType => [
                        'fields' => [
                            $attribute => [
                                'default' => $defaultAttributes[$attribute]
                            ]
                        ]
                    ]
                ];

                /** @var array<string,mixed> $ormMetadata */
                $ormMetadata = Util::merge($ormMetadata, $defaultMetadataPart);
            }
        }

        // @todo move to separate file
        $scopeDefs = $this->metadata->get('scopes.'.$entityType);

        if (isset($scopeDefs['stream']) && $scopeDefs['stream']) {
            if (!isset($entityMetadata['fields']['isFollowed'])) {
                $ormMetadata[$entityType]['fields']['isFollowed'] = [
                    'type' => 'varchar',
                    'notStorable' => true,
                    'notExportable' => true,
                ];

                $ormMetadata[$entityType]['fields']['followersIds'] = [
                    'type' => 'jsonArray',
                    'notStorable' => true,
                    'notExportable' => true,
                ];

                $ormMetadata[$entityType]['fields']['followersNames'] = [
                    'type' => 'jsonObject',
                    'notStorable' => true,
                    'notExportable' => true,
                ];
            }
        }

        // @todo move to separate file
        if ($this->metadata->get(['entityDefs', $entityType, 'optimisticConcurrencyControl'])) {
            $ormMetadata[$entityType]['fields']['versionNumber'] = [
                'type' => Entity::INT,
                'dbType' => 'bigint',
                'notExportable' => true,
            ];
        }

        return $ormMetadata;
    }

    /**
     * @param array<string,mixed> $fieldParams
     * @param ?array<string,mixed> $fieldTypeMetadata
     * @return array<string,mixed>|false
     */
    private function convertField(
        string $entityType,
        string $field,
        array $fieldParams,
        ?array $fieldTypeMetadata = null
    ) {
        if (!isset($fieldTypeMetadata)) {
            $fieldTypeMetadata = $this->metadataHelper->getFieldDefsByType($fieldParams);
        }

        $this->prepareFieldParamsBeforeConvert($fieldParams);

        if (isset($fieldTypeMetadata['fieldDefs'])) {
            /** @var array<string,mixed> $fieldParams */
            $fieldParams = Util::merge($fieldParams, $fieldTypeMetadata['fieldDefs']);
        }

        if ($fieldParams['type'] == 'base' && isset($fieldParams['dbType'])) {
            $fieldParams['notStorable'] = false;
        }

        if (!empty($fieldTypeMetadata['skipOrmDefs']) || !empty($fieldParams['skipOrmDefs'])) {
            return false;
        }

        if (
            isset($fieldParams['notNull']) && !$fieldParams['notNull'] &&
            isset($fieldParams['required']) && $fieldParams['required']
        ) {
            unset($fieldParams['notNull']);
        }

        $fieldDefs = $this->getInitValues($fieldParams);

        if (isset($fieldParams['db']) && $fieldParams['db'] === false) {
            $fieldDefs['notStorable'] = true;
        }

        if (
            isset($fieldDefs['type']) && !isset($fieldDefs['len']) &&
            in_array($fieldDefs['type'], array_keys($this->defaultLength))
        ) {
            $fieldDefs['len'] = $this->defaultLength[$fieldDefs['type']];
        }

        return $fieldDefs;
    }

    /**
     * @param array<string,mixed> $fieldParams
     */
    private function prepareFieldParamsBeforeConvert(array &$fieldParams): void
    {
        $type = $fieldParams['type'] ?? null;

        if ($type === 'enum') {
            if (($fieldParams['default'] ?? null) === '') {
                $fieldParams['default'] = null;
            }
        }
    }

    /**
     * @param array<string, mixed> $entityMetadata
     * @param array<string, mixed> $ormMetadata
     * @return array<string, mixed>
     */
    private function convertLinks(string $entityType, array $entityMetadata, array $ormMetadata): array
    {
        if (!isset($entityMetadata['links'])) {
            return [];
        }

        $relationships = [];
        foreach ($entityMetadata['links'] as $linkName => $linkParams) {

            if (isset($linkParams['skipOrmDefs']) && $linkParams['skipOrmDefs'] === true) {
                continue;
            }

            $convertedLink = $this->relationManager->convert($linkName, $linkParams, $entityType, $ormMetadata);

            if (isset($convertedLink)) {
                /** @var array<string,mixed> $relationships */
                $relationships = Util::merge($convertedLink, $relationships);
            }
        }

        return $relationships;
    }

    /**
     * @param array<string, mixed> $attributeParams
     * @return array<string, mixed>
     */
    private function getInitValues(array $attributeParams)
    {
        $values = [];

        foreach ($this->fieldAccordances as $espoType => $ormType) {
            if (!array_key_exists($espoType, $attributeParams)) {
                continue;
            }

            switch ($espoType) {
                case 'default':
                    if (
                        is_null($attributeParams[$espoType]) ||
                        is_array($attributeParams[$espoType]) ||
                        !preg_match('/^javascript:/i', $attributeParams[$espoType])
                    ) {
                        $values[$ormType] = $attributeParams[$espoType];
                    }

                    break;

                default:
                    $values[$ormType] = $attributeParams[$espoType];

                    break;
            }
        }

        if (isset($attributeParams['type'])) {
            $values['fieldType'] = $attributeParams['type'];
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $ormMetadata
     */
    private function applyFullTextSearch(array &$ormMetadata, string $entityType): void
    {
        if (!$this->metadata->get(['entityDefs', $entityType, 'collection', 'fullTextSearch'])) {
            return;
        }

        $fieldList = $this->metadata
            ->get(['entityDefs', $entityType, 'collection', 'textFilterFields'], ['name']);

        $fullTextSearchColumnList = [];

        foreach ($fieldList as $field) {
            $defs = $this->metadata->get(['entityDefs', $entityType, 'fields', $field], []);

            if (empty($defs['type'])) {
                continue;
            }

            $fieldType = $defs['type'];

            if (!empty($defs['notStorable'])) {
                continue;
            }

            if (!$this->metadata->get(['fields', $fieldType, 'fullTextSearch'])) {
                continue;
            }

            $partList = $this->metadata->get(['fields', $fieldType, 'fullTextSearchColumnList']);

            if ($partList) {
                if ($this->metadata->get(['fields', $fieldType, 'naming']) === 'prefix') {
                    foreach ($partList as $part) {
                        $fullTextSearchColumnList[] = $part . ucfirst($field);
                    }
                }
                else {
                    foreach ($partList as $part) {
                        $fullTextSearchColumnList[] = $field . ucfirst($part);
                    }
                }
            }
            else {
                $fullTextSearchColumnList[] = $field;
            }
        }

        if (!empty($fullTextSearchColumnList)) {
            $ormMetadata[$entityType]['fullTextSearchColumnList'] = $fullTextSearchColumnList;

            if (!array_key_exists('indexes', $ormMetadata[$entityType])) {
                $ormMetadata[$entityType]['indexes'] = [];
            }

            $ormMetadata[$entityType]['indexes']['system_fullTextSearch'] = [
                'columns' => $fullTextSearchColumnList,
                'flags' => ['fulltext']
            ];
        }
    }

    /**
     * @param array<string, mixed> $ormMetadata
     */
    private function applyIndexes(&$ormMetadata, string $entityType): void
    {
        $defs = &$ormMetadata[$entityType];

        $defs['indexes'] ??= [];

        if (isset($defs['fields'])) {
            $indexList = self::getEntityIndexListByFieldsDefs($defs['fields']);

            foreach ($indexList as $indexName => $indexParams) {
                if (!isset($defs['indexes'][$indexName])) {
                    $defs['indexes'][$indexName] = $indexParams;
                }
            }
        }

        foreach ($defs['indexes'] as $indexName => &$indexData) {
            $indexDefs = IndexDefs::fromRaw($indexData, $indexName);

            if (!$indexDefs->getKey()) {
                $indexData['key'] = $this->generateIndexName($indexDefs, $entityType);
            }
        }

        if (isset($defs['relations'])) {
            foreach ($defs['relations'] as &$relationData) {
                $type = $relationData['type'] ?? null;

                if ($type !== Entity::MANY_MANY) {
                    continue;
                }

                $relationName = $relationData['relationName'] ?? '';

                $relationData['indexes'] ??= [];

                $uniqueColumnList = [];

                foreach (($relationData['midKeys'] ?? []) as $midKey) {
                    $indexName = $midKey;

                    $indexDefs = IndexDefs::fromRaw(['columns' => [$midKey]], $indexName);

                    $relationData['indexes'][$indexName] = [
                        'columns' => $indexDefs->getColumnList(),
                        'key' => $this->generateIndexName($indexDefs, ucfirst($relationName)),
                    ];

                    $uniqueColumnList[] = $midKey;
                }

                foreach ($relationData['indexes'] as $indexName => &$indexData) {
                    if (!empty($indexData['key'])) {
                        continue;
                    }

                    $indexDefs = IndexDefs::fromRaw($indexData, $indexName);

                    $indexData['key'] = $this->generateIndexName($indexDefs, ucfirst($relationName));
                }

                foreach (($relationData['conditions'] ?? []) as $column => $fieldParams) {
                    $uniqueColumnList[] = $column;
                }

                if ($uniqueColumnList !== []) {
                    $indexName = implode('_', $uniqueColumnList);

                    $indexDefs = IndexDefs
                        ::fromRaw(['columns' => $uniqueColumnList, 'type' => 'unique'], $indexName);

                    $relationData['indexes'][$indexName] = [
                        'type' => 'unique',
                        'columns' => $indexDefs->getColumnList(),
                        'key' => $this->generateIndexName($indexDefs, ucfirst($relationName)),
                    ];
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $defs
     * @return array<string, mixed>
     */
    private function createAdditionalEntityTypes(array $defs): array
    {
        /** @var array<string, array<string, mixed>> $additionalDefs */
        $additionalDefs = $defs['additionalTables'] ?? [];

        if ($additionalDefs === []) {
            return [];
        }

        /** @var string[] $entityTypeList */
        $entityTypeList = array_keys($additionalDefs);

        foreach ($entityTypeList as $itemEntityType) {
            $this->applyIndexes($additionalDefs, $itemEntityType);
        }

        return $additionalDefs;
    }

    /**
     * @param array<string, mixed> $defs
     * @return array<string, mixed>
     */
    private function createRelationsEntityDefs(array $defs): array
    {
        $result = [];

        foreach ($defs['relations'] as $relationParams) {
            if ($relationParams['type'] !== 'manyMany') {
                continue;
            }

            $relationEntityType = ucfirst($relationParams['relationName']);

            $itemDefs = [
                'skipRebuild' => true,
                'fields' => [
                    'id' => [
                        'type' => 'id',
                        'autoincrement' => true,
                        'dbType' => 'bigint', // ignored because of `skipRebuild`
                    ],
                    'deleted' => [
                        'type' => 'bool'
                    ],
                ],
            ];

            foreach ($relationParams['midKeys'] ?? [] as $key) {
                $itemDefs['fields'][$key] = [
                    'type' => 'foreignId',
                ];
            }

            foreach ($relationParams['additionalColumns'] ?? [] as $columnName => $columnItem) {
                $itemDefs['fields'][$columnName] = [
                    'type' => $columnItem['type'] ?? 'varchar',
                ];
            }

            $result[$relationEntityType] = $itemDefs;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $fieldsDefs
     * @return array<string, mixed>
     */
    private static function getEntityIndexListByFieldsDefs(array $fieldsDefs): array
    {
        $indexList = [];

        foreach ($fieldsDefs as $fieldName => $fieldParams) {
            if (isset($fieldParams['notStorable']) && $fieldParams['notStorable']) {
                continue;
            }

            $indexType = self::getIndexTypeByFieldDefs($fieldParams);
            $indexName = self::getIndexNameByFieldDefs($fieldName, $fieldParams);

            if (!$indexType || !$indexName) {
                continue;
            }

            $keyValue = $fieldParams[$indexType];

            $columnName = $fieldName;

            if ($keyValue === true) {
                $indexList[$indexName]['type'] = $indexType;
                $indexList[$indexName]['columns'] = [$columnName];
            }
            else if (is_string($keyValue)) {
                $indexList[$indexName]['type'] = $indexType;
                $indexList[$indexName]['columns'][] = $columnName;
            }
        }

        /** @var array<string, mixed> */
        return $indexList;
    }

    /**
     * @param array<string, mixed> $fieldDefs
     */
    private static function getIndexTypeByFieldDefs(array $fieldDefs): ?string
    {
        if (
            $fieldDefs['type'] !== 'id' &&
            isset($fieldDefs['unique']) && $fieldDefs['unique']
        ) {
            return 'unique';
        }

        if (isset($fieldDefs['index']) && $fieldDefs['index']) {
            return 'index';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $fieldDefs
     */
    private static function getIndexNameByFieldDefs(string $fieldName, array $fieldDefs): ?string
    {
        $indexType = self::getIndexTypeByFieldDefs($fieldDefs);

        if ($indexType) {
            $keyValue = $fieldDefs[$indexType];

            if ($keyValue === true) {
                return $fieldName;
            }

            if (is_string($keyValue)) {
                return $keyValue;
            }
        }

        return null;
    }
}
