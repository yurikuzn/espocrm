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

namespace Espo\Core\Api;

use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Metadata;
use Psr\Http\Server\MiddlewareInterface;

class MiddlewareProvider
{
    public function __construct(
        private Metadata $metadata,
        private InjectableFactory $injectableFactory
    ) {}

    /**
     * @return MiddlewareInterface[]
     */
    public function getGlobalMiddlewareList(): array
    {
        return $this->createFromClassNameList($this->getGlobalMiddlewareClassNameList());
    }

    /**
     * @return MiddlewareInterface[]
     */
    public function getRouteMiddlewareList(Route $route): array
    {
        $key = strtolower($route->getMethod()) . '_' . $route->getRoute();

        /** @var class-string<MiddlewareInterface>[] $classNameList */
        $classNameList = $this->metadata->get(['app', 'api', 'routeMiddlewareClassNameListMap', $key]) ?? [];

        return $this->createFromClassNameList($classNameList);
    }

    /**
     * @return class-string<MiddlewareInterface>[]
     */
    private function getGlobalMiddlewareClassNameList(): array
    {
        return $this->metadata->get(['app', 'api', 'globalMiddlewareClassNameList']) ?? [];
    }

    /**
     * @param class-string<MiddlewareInterface>[] $classNameList
     * @return MiddlewareInterface[]
     */
    private function createFromClassNameList(array $classNameList): array
    {
        $list = [];

        foreach ($classNameList as $className) {
            $list[] = $this->injectableFactory->create($className);
        }

        return $list;
    }
}
