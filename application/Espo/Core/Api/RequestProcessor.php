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

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Authentication\AuthenticationFactory;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Core\ApplicationUser;

use Exception;
use Psr\Http\Message\ResponseInterface as Psr7Response;
use Psr\Http\Message\ServerRequestInterface as Psr7Request;
use Slim\MiddlewareDispatcher;
use Throwable;
use LogicException;

/**
 * Processes requests. Handles authentication. Obtains a controller name, action, body from a request.
 * Then passes them to the action processor.
 */
class RequestProcessor
{
    public function __construct(
        private AuthenticationFactory $authenticationFactory,
        private AuthBuilderFactory $authBuilderFactory,
        private ErrorOutput $errorOutput,
        private Config $config,
        private Log $log,
        private ApplicationUser $applicationUser,
        private ActionProcessor $actionProcessor,
        private MiddlewareProvider $middlewareProvider
    ) {}

    public function process(
        ProcessData $processData,
        Psr7Request $request,
        Psr7Response $response
    ): Psr7Response {

        $requestWrapped = new RequestWrapper($request, $processData->getBasePath(), $processData->getRouteParams());
        $responseWrapped = new ResponseWrapper($response);

        try {
            return $this->processInternal(
                $processData,
                $request,
                $requestWrapped,
                $responseWrapped
            );
        }
        catch (Exception $exception) {
            $this->handleException(
                $exception,
                $requestWrapped,
                $responseWrapped,
                $processData->getRoute()->getAdjustedRoute()
            );

            return $responseWrapped->getResponse();
        }
    }

    /**
     * @throws BadRequest
     */
    private function processInternal(
        ProcessData $processData,
        Psr7Request $psrRequest,
        RequestWrapper $request,
        ResponseWrapper $response
    ): Psr7Response {

        $authRequired = !$processData->getRoute()->noAuth();

        $apiAuth = $this->authBuilderFactory
            ->create()
            ->setAuthentication($this->authenticationFactory->create())
            ->setAuthRequired($authRequired)
            ->build();

        $authResult = $apiAuth->process($request, $response);

        if (!$authResult->isResolved()) {
            return $response->getResponse();
        }

        if ($authResult->isResolvedUseNoAuth()) {
            $this->applicationUser->setupSystemUser();
        }

        ob_start();

        $response = $this->proceed($processData, $psrRequest, $request, $response);

        ob_clean();

        return $response;
    }

    /**
     * @throws BadRequest
     */
    private function proceed(
        ProcessData $processData,
        Psr7Request $psrRequest,
        Request $request,
        ResponseWrapper $response
    ): Psr7Response {

        $controllerName = $this->getControllerName($request);
        $actionName = $request->getRouteParam('action');
        $requestMethod = $request->getMethod();

        if (!$actionName) {
            $method = strtolower($requestMethod);

            $crudList = $this->config->get('crud') ?? [];

            $actionName = $crudList[$method] ?? null;

            if (!$actionName) {
                throw new BadRequest("No action for method {$method}.");
            }
        }

        $handler = new ControllerActionHandler(
            controllerName: $controllerName,
            actionName: $actionName,
            processData: $processData,
            responseWrapped: $response,
            actionProcessor: $this->actionProcessor,
            config: $this->config,
        );

        $dispatcher = new MiddlewareDispatcher($handler);

        $this->addControllerMiddlewares($dispatcher, $requestMethod, $controllerName, $actionName);

        return $dispatcher->handle($psrRequest);
    }

    private function getControllerName(Request $request): string
    {
        $controllerName = $request->getRouteParam('controller');

        if (!$controllerName) {
            throw new LogicException("Route doesn't have specified controller.");
        }

        return ucfirst($controllerName);
    }


    private function handleException(
        Exception $exception,
        Request $request,
        Response $response,
        string $route
    ): void {

        try {
            $this->errorOutput->process($request, $response, $exception, $route);
        }
        catch (Throwable $exceptionAnother) {
            $this->log->error($exceptionAnother->getMessage());

            $response->setStatus(500);
        }
    }

    private function addControllerMiddlewares(
        MiddlewareDispatcher $dispatcher,
        string $method,
        string $controller,
        string $action
    ): void {

        $controllerActionMiddlewareList = $this->middlewareProvider
            ->getControllerActionMiddlewareList($method, $controller, $action);

        foreach ($controllerActionMiddlewareList as $middleware) {
            $dispatcher->addMiddleware($middleware);
        }

        $controllerMiddlewareList = $this->middlewareProvider
            ->getControllerMiddlewareList($controller);

        foreach ($controllerMiddlewareList as $middleware) {
            $dispatcher->addMiddleware($middleware);
        }
    }
}
