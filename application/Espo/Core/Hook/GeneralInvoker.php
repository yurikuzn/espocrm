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

namespace Espo\Core\Hook;

use Espo\Core\Hook\Hook\AfterMassRelate;
use Espo\Core\Hook\Hook\AfterRelate;
use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Hook\Hook\AfterUnrelate;
use Espo\Core\Hook\Hook\BeforeSave;

use Espo\ORM\Entity;
use Espo\ORM\Query\Select;
use Espo\ORM\Repository\Option\MassRelateOptions;
use Espo\ORM\Repository\Option\RelateOptions;
use Espo\ORM\Repository\Option\SaveOptions;

use Espo\ORM\Repository\Option\UnrelateOptions;
use LogicException;

/**
 * Invokes hook methods, prepares needed arguments for specific hooks.
 */
class GeneralInvoker
{
    /**
     * @param object $hook A hook object.
     * @param string $name A hook name.
     * @param mixed $subject A subject.
     * @param array<string, mixed> $options Options.
     * @param array<string, mixed> $hookData Additional data.
     */
    public function invoke(
        object $hook,
        string $name,
        mixed $subject,
        array $options,
        array $hookData
    ): void {

        if ($hook instanceof BeforeSave) {
            if (!$subject instanceof Entity) {
                throw new LogicException();
            }

            $hook->beforeSave($subject, SaveOptions::fromAssoc($options));

            return;
        }

        if ($hook instanceof AfterSave) {
            if (!$subject instanceof Entity) {
                throw new LogicException();
            }

            $hook->afterSave($subject, SaveOptions::fromAssoc($options));

            return;
        }

        if ($hook instanceof AfterRelate) {
            $relationName = $hookData['relationName'] ?? null;
            $relatedEntity = $hookData['foreignEntity'] ?? null;
            $columnData = $hookData['relationData'] ?? [];

            if (
                !$subject instanceof Entity ||
                !is_string($relationName) ||
                !$relatedEntity instanceof Entity
            ) {
                throw new LogicException();
            }

            $hook->afterRelate(
                $subject,
                $relationName,
                $relatedEntity,
                $columnData,
                RelateOptions::fromAssoc($options)
            );

            return;
        }

        if ($hook instanceof AfterUnrelate) {
            $relationName = $hookData['relationName'] ?? null;
            $relatedEntity = $hookData['foreignEntity'] ?? null;

            if (
                !$subject instanceof Entity ||
                !is_string($relationName) ||
                !$relatedEntity instanceof Entity
            ) {
                throw new LogicException();
            }

            $hook->afterUnrelate(
                $subject,
                $relationName,
                $relatedEntity,
                UnrelateOptions::fromAssoc($options)
            );

            return;
        }

        if ($hook instanceof AfterMassRelate) {
            $relationName = $hookData['relationName'] ?? null;
            $query = $hookData['query'] ?? null;
            $columnData = $hookData['relationData'] ?? []; // Not implemented currently.

            if (
                !$subject instanceof Entity ||
                !is_string($relationName) ||
                !$query instanceof Select
            ) {
                throw new LogicException();
            }

            $hook->afterMassRelate(
                $subject,
                $relationName,
                $query,
                $columnData,
                MassRelateOptions::fromAssoc($options)
            );

            return;
        }

        $hook->$name($subject, $options, $hookData);
    }
}
