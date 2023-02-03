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

use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Database\Orm\LinkConverters\BelongsTo;
use Espo\Core\Utils\Database\Orm\LinkConverters\BelongsToParent;
use Espo\Core\Utils\Database\Orm\LinkConverters\HasChildren;
use Espo\Core\Utils\Database\Orm\LinkConverters\HasMany;
use Espo\Core\Utils\Database\Orm\LinkConverters\HasOne;
use Espo\Core\Utils\Database\Orm\LinkConverters\ManyMany;
use Espo\Core\Utils\Util;
use Espo\Core\Utils\Metadata;
use Espo\Core\Utils\Config;
use Espo\ORM\Defs\RelationDefs;
use Espo\ORM\Type\AttributeType;
use Espo\ORM\Type\RelationType;
use RuntimeException;

class RelationConverter
{
    private const DEFAULT_VARCHAR_LENGTH = 255;

    /** @var string[] */
    private $allowedParams = [
        'relationName',
        'conditions',
        'additionalColumns',
        'midKeys',
        'noJoin',
        'indexes',
    ];

    public function __construct(
        private Metadata $metadata,
        private Config $config,
        private InjectableFactory $injectableFactory
    ) {}

    /**
     * @param string $relationName
     */
    private function relationExists($relationName): bool
    {
        if ($this->getRelationClass($relationName) !== false) {
            return true;
        }

        return false;
    }

    /**
     * @param string $relationName
     * @return class-string<\Espo\Core\Utils\Database\Orm\Relations\Base>|false
     */
    private function getRelationClass($relationName)
    {
        $relationName = ucfirst($relationName);

        $className = 'Espo\Custom\Core\Utils\Database\Orm\Relations\\' . $relationName;

        if (!class_exists($className)) {
            $className = 'Espo\Core\Utils\Database\Orm\Relations\\' . $relationName;
        }

        if (class_exists($className)) {
            /** @var class-string<\Espo\Core\Utils\Database\Orm\Relations\Base> */
            return $className;
        }

        return false;
    }

    /**
     * @param string $name
     * @param array<string, mixed> $params
     * @param string $entityType
     * @param array<string, mixed> $ormMetadata
     * @return ?array<string, mixed>
     */
    public function process(string $name, array $params, string $entityType, array $ormMetadata): ?array
    {
        $foreignEntityType = $params['entity'] ?? null;
        $foreignLinkName = $params['foreign'] ?? null;

        /** @var ?array<string, mixed> $foreignParams */
        $foreignParams = $foreignEntityType && $foreignLinkName ?
            $this->metadata->get(['entityDefs', $foreignEntityType, 'links', $foreignLinkName]) :
            null;

        /** @var ?string $relationshipName */
        $relationshipName = $params['relationName'] ?? null;

        if ($relationshipName) {
            $relationshipName = lcfirst($relationshipName);
            $params['relationName'] = $relationshipName;
        }

        $linkType = $params['type'];
        $foreignLinkType = $foreignParams ? $foreignParams['type'] : null;

        // If-check for backward compatibility.
        if (!$relationshipName || !$this->getRelationClass($relationshipName)) {
            $params['hasField'] = (bool) $this->metadata
                ->get(['entityDefs', $entityType, 'fields', $name]);

            $relationDefs = RelationDefs::fromRaw($params, $name);

            $converter = $this->createLinkConverter($relationshipName, $linkType, $foreignLinkType);

            $convertedEntityDefs = $converter->convert($relationDefs, $entityType);

            $raw = $convertedEntityDefs->toAssoc();

            if (isset($raw['relations'][$name])) {
                $this->mergeAllowedParams($raw['relations'][$name], $params, $foreignParams ?? []);
                $this->correct($raw['relations'][$name]);
            }

            return [$entityType => $raw];
        }

        // Below is a legacy.

        $relType = $linkType;

        if ($foreignParams !== null) {
            $relType .= '_' . $foreignParams['type'];
        }

        $relType = Util::toCamelCase($relType);

        $type = $this->relationExists($relType) ?
            $relType /*hasManyHasMany*/ :
            $linkType /*hasMany*/;

        if ($relationshipName) {
            $className = $this->getRelationClass($relationshipName);

            if (!$className) {
                $type = $this->relationExists($relType) ? $relType : $linkType;
                $className = $this->getRelationClass($type);
            }
        } else {
            $className = $this->getRelationClass($type);
        }

        if ($className) {
            $foreignLinkName = $foreignParams ?
                ($params['foreign'] ?? null) : null;

            $entityDefs = $this->metadata->get('entityDefs');

            $helperClass = new $className($this->metadata, $ormMetadata, $entityDefs, $this->config);

            return $helperClass->process($name, $entityType, $foreignLinkName, $foreignEntityType);
        }

        return null;
    }

    private function createLinkConverter(?string $relationship, string $type, ?string $foreignType): LinkConverter
    {
        $className = $this->getLinkConverterClassName($relationship, $type, $foreignType);

        return $this->injectableFactory->create($className);
    }

    /**
     * @return class-string<LinkConverter>
     */
    private function getLinkConverterClassName(?string $relationship, string $type, ?string $foreignType): string
    {
        if ($relationship) {
            /** @var class-string<LinkConverter> $className */
            $className = $this->metadata->get(['app', 'relationships', $relationship, 'converterClassName']);

            if ($className) {
                return $className;
            }
        }

        if ($type === RelationType::HAS_MANY && $foreignType === RelationType::HAS_MANY) {
            return ManyMany::class;
        }

        if ($type === RelationType::HAS_MANY) {
            return HasMany::class;
        }

        if ($type === RelationType::HAS_CHILDREN) {
            return HasChildren::class;
        }

        if ($type === RelationType::HAS_ONE) {
            return HasOne::class;
        }

        if ($type === RelationType::BELONGS_TO) {
            return BelongsTo::class;
        }

        if ($type === RelationType::BELONGS_TO_PARENT) {
            return BelongsToParent::class;
        }

        throw new RuntimeException("Unsupported link type '{$type}'.");
    }

    /**
     * @param array<string, mixed> $relationDefs
     * @param array<string, mixed> $params
     * @param array<string, mixed> $foreignParams
     */
    private function mergeAllowedParams(array &$relationDefs, array $params, array $foreignParams): void
    {
        foreach ($this->allowedParams as $name) {
            $additionalParam = $this->getAllowedParam($name, $params, $foreignParams);

            if ($additionalParam === null) {
                continue;
            }

            $relationDefs[$name] = $additionalParam;
        }
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $foreignParams
     * @return array<string, mixed>|scalar|null
     */
    private function getAllowedParam(string $name, array $params, array $foreignParams): mixed
    {
        $itemLinkParams = $params[$name] ?? null;
        $itemForeignLinkParams = $foreignParams[$name] ?? null;

        if (isset($itemLinkParams) && isset($itemForeignLinkParams)) {
            if (!empty($itemLinkParams) && !is_array($itemLinkParams)) {
                return $itemLinkParams;
            }

            if (!empty($itemForeignLinkParams) && !is_array($itemForeignLinkParams)) {
                return $itemForeignLinkParams;
            }

            /** @var array<int|string, mixed> $itemLinkParams */
            /** @var array<int|string, mixed> $itemForeignLinkParams */

            /** @var array<string, mixed> */
            return Util::merge($itemLinkParams, $itemForeignLinkParams);
        }

        if (isset($itemLinkParams)) {
            return $itemLinkParams;
        }

        if (isset($itemForeignLinkParams)) {
            return $itemForeignLinkParams;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $relationDefs
     */
    private function correct(array &$relationDefs): void
    {
        if (!isset($relationDefs['additionalColumns'])) {
            return;
        }

        /** @var array<string, array<string, mixed>> $additionalColumns */
        $additionalColumns = &$relationDefs['additionalColumns'];

        foreach ($additionalColumns as &$columnDefs) {
            $columnDefs['type'] ??= AttributeType::VARCHAR;

            if (
                $columnDefs['type'] === AttributeType::VARCHAR &&
                !isset($columnDefs['len'])
            ) {
                $columnDefs['len'] = self::DEFAULT_VARCHAR_LENGTH;
            }
        }

        $relationDefs['additionalColumns'] = $additionalColumns;
    }
}
