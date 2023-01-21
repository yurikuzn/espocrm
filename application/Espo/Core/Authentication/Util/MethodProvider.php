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

namespace Espo\Core\Authentication\Util;

use Espo\Core\ApplicationState;
use Espo\Core\Authentication\ConfigDataProvider;
use Espo\Core\Authentication\Logins\Espo;
use Espo\Core\ORM\EntityManagerProxy;
use Espo\Core\Utils\Metadata;
use Espo\Entities\AuthenticationProvider;
use Espo\Entities\Portal;
use RuntimeException;

class MethodProvider
{
    public function __construct(
        private EntityManagerProxy $entityManager,
        private ApplicationState $applicationState,
        private ConfigDataProvider $configDataProvider,
        private Metadata $metadata
    ) {}

    public function get(): string
    {
        if ($this->applicationState->isPortal()) {
            $method = $this->getForPortal($this->applicationState->getPortal());

            if ($method) {
                return $method;
            }
        }

        $method = $this->configDataProvider->getDefaultAuthenticationMethod();

        if ($this->applicationState->isPortal()) {
            $allow = $this->metadata->get(['authenticationMethods', $method, 'portalDefault']);

            if (!$allow) {
                return Espo::NAME;
            }
        }

        return $method;
    }

    public function getForPortal(Portal $portal): ?string
    {
        $providerId = $portal->getAuthenticationProvider()?->getId();

        if (!$providerId) {
            return null;
        }

        /** @var ?AuthenticationProvider $provider */
        $provider = $this->entityManager->getEntityById(AuthenticationProvider::ENTITY_TYPE, $providerId);

        if (!$provider) {
            throw new RuntimeException("No authentication provider for portal.");
        }

        $method = $provider->getMethod();

        if (!$method) {
            throw new RuntimeException("No method in authentication provider.");
        }

        return $method;
    }
}
