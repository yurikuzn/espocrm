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

namespace Espo\Core\Utils\Database\Orm\LinkConverters;

use Espo\Core\Utils\Database\Orm\Defs\EntityDefs;
use Espo\Core\Utils\Database\Orm\LinkConverter;
use Espo\Entities\PhoneNumber;
use Espo\ORM\Defs\RelationDefs as LinkDefs;
use LogicException;

class SmsPhoneNumber implements LinkConverter
{
    public function __construct(private HasMany $hasMany) {}

    public function convert(LinkDefs $linkDefs, string $entityType): EntityDefs
    {
        $name = $linkDefs->getName();
        $foreignEntityType = PhoneNumber::ENTITY_TYPE;

        $entityDefs = $this->hasMany->convert($linkDefs, $entityType);

        $relationDefs = $entityDefs->getRelation($name);

        if (!$relationDefs) {
            throw new LogicException();
        }

        $key1 = lcfirst($entityType) . 'Id';
        $key2 = lcfirst($foreignEntityType) . 'Id';

        $relationDefs = $relationDefs->withMidKeys($key1, $key2);

        return $entityDefs->withRelation($relationDefs);
    }
}
