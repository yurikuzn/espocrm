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
use Espo\ORM\Type\RelationType;
use RuntimeException;

class RelationConverter
{
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
     * Get a foreign link.
     *
     * @param array<string, mixed> $linkParams
     * @param array<string, mixed> $currentEntityDefs
     * @return array{name: string, params: array<string, mixed>}|null
     */
    private function getForeignLinkParams(array $linkParams, array $currentEntityDefs): ?array
    {
        if (
            isset($linkParams['foreign']) &&
            isset($currentEntityDefs['links'][$linkParams['foreign']])
        ) {
            return $currentEntityDefs['links'][$linkParams['foreign']];
        }

        return null;
    }

    /**
     * @param string $linkName
     * @param array<string, mixed> $linkParams
     * @param string $entityType
     * @param array<string, mixed> $ormMetadata
     * @return ?array<string, mixed>
     */
    public function process(string $linkName, array $linkParams, string $entityType, array $ormMetadata): ?array
    {
        $entityDefs = $this->metadata->get('entityDefs');

        $foreignEntityType = $linkParams['entity'] ?? null;

        $foreignLinkParams = $foreignEntityType ?
            $this->getForeignLinkParams($linkParams, $entityDefs[$foreignEntityType] ?? []) :
            null;

        $relationshipName = $linkParams['relationName'] ?? null;

        $linkType = $linkParams['type'];
        $foreignLinkType = $foreignLinkParams ? $foreignLinkParams['type'] : null;

        // Check for backward compatibility.
        if (!$relationshipName || !$this->getRelationClass($relationshipName)) {
            $linkParams['hasField'] = (bool) $this->metadata
                ->get(['entityDefs', $entityType, 'fields', $linkName]);

            $relationDefs = RelationDefs::fromRaw($linkParams, $linkName);

            $converter = $this->createLinkConverter($relationshipName, $linkType, $foreignLinkType);

            $convertedEntityDefs = $converter->convert($relationDefs, $entityType);

            return [$entityType => $convertedEntityDefs->toAssoc()];
        }

        // Below is a legacy.

        $relType = $linkType;

        if ($foreignLinkParams !== null) {
            $relType .= '_' . $foreignLinkParams['type'];
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
            $foreignLinkName = $foreignLinkParams ?
                ($linkParams['foreign'] ?? null) : null;

            $helperClass = new $className($this->metadata, $ormMetadata, $entityDefs, $this->config);

            return $helperClass->process($linkName, $entityType, $foreignLinkName, $foreignEntityType);
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
            $className = $this->metadata
                ->get(['app', 'ormMetadata', 'relationships', $relationship, 'converterClassName']);

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
}
