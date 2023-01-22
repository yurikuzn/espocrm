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

namespace Espo\Core\Authentication\Oidc;

use Espo\Core\ApplicationState;
use Espo\Core\ORM\EntityManagerProxy;
use Espo\Core\Utils\Config;
use Espo\Entities\AuthenticationProvider;

class ConfigDataProvider
{
    private Config|AuthenticationProvider $object;

    public function __construct(
        private Config $config,
        private ApplicationState $applicationState,
        private EntityManagerProxy $entityManager
    ) {
        $this->object = $this->getAuthenticationProvider() ?? $this->config;
    }

    private function isAuthenticationProvider(): bool
    {
        return $this->object instanceof AuthenticationProvider;
    }

    private function getAuthenticationProvider(): ?AuthenticationProvider
    {
        if (!$this->applicationState->isPortal()) {
            return null;
        }

        $link = $this->applicationState->getPortal()->getAuthenticationProvider();

        if (!$link) {
            return null;
        }

        /** @var ?AuthenticationProvider */
        return $this->entityManager->getEntityById(AuthenticationProvider::ENTITY_TYPE, $link->getId());
    }

    public function getRedirectUri(): string
    {
        return rtrim($this->config->get('siteUrl'), '/') . '/oauth-callback.php';
    }

    public function getClientId(): ?string
    {
        return $this->object->get('oidcClientId');
    }

    public function getClientSecret(): ?string
    {
        return $this->object->get('oidcClientSecret');
    }

    public function getAuthorizationEndpoint(): ?string
    {
        return $this->object->get('oidcAuthorizationEndpoint');
    }

    public function getTokenEndpoint(): ?string
    {
        return $this->object->get('oidcTokenEndpoint');
    }

    /**
     * @return string[]
     */
    public function getScopes(): array
    {
        /** @var string[] */
        return $this->object->get('oidcScopes') ?? [];
    }

    public function getLogoutUrl(): ?string
    {
        return $this->object->get('oidcLogoutUrl');
    }

    public function getUsernameClaim(): ?string
    {
        return $this->object->get('oidcUsernameClaim');
    }

    public function createUser(): bool
    {
        return (bool) $this->object->get('oidcCreateUser');
    }

    public function sync(): bool
    {
        return (bool) $this->object->get('oidcSync');
    }

    public function syncTeams(): bool
    {
        if ($this->isAuthenticationProvider()) {
            return false;
        }

        return (bool) $this->config->get('oidcSyncTeams');
    }

    public function fallback(): bool
    {
        if ($this->isAuthenticationProvider()) {
            return false;
        }

        return (bool) $this->config->get('oidcFallback');
    }

    public function allowRegularUserFallback(): bool
    {
        if ($this->isAuthenticationProvider()) {
            return false;
        }

        return (bool) $this->config->get('oidcAllowRegularUserFallback');
    }

    public function getGroupClaim(): ?string
    {
        if ($this->isAuthenticationProvider()) {
            return null;
        }

        return $this->config->get('oidcGroupClaim');
    }

    public function getAuthorizationPrompt(): string
    {
        return $this->config->get('oidcAuthorizationPrompt') ?? 'consent';
    }

    public function getAuthorizationMaxAge(): ?int
    {
        return $this->config->get('oidcAuthorizationMaxAge');
    }

    public function allowAdminUser(): bool
    {
        if ($this->isAuthenticationProvider()) {
            return false;
        }

        return (bool) $this->config->get('oidcAllowAdminUser');
    }
}
