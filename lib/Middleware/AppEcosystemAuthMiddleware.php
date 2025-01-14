<?php

declare(strict_types=1);

/**
 *
 * Nextcloud - App Ecosystem V2
 *
 * @copyright Copyright (c) 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @copyright Copyright (c) 2023 Alexander Piskun <bigcat88@icloud.com>
 *
 * @author 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\AppEcosystemV2\Middleware;

use ReflectionMethod;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\Utility\IControllerMethodReflector;

use OCA\AppEcosystemV2\Attribute\AppEcosystemAuth;
use OCA\AppEcosystemV2\Exceptions\AEAuthNotValidException;
use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class AppEcosystemAuthMiddleware extends Middleware {
	private IControllerMethodReflector $reflector;
	private AppEcosystemV2Service $service;
	protected IRequest $request;
	private IL10N $l;
	private LoggerInterface $logger;

	public function __construct(
		IControllerMethodReflector $reflector,
		AppEcosystemV2Service $service,
		IRequest $request,
		IL10N $l,
		LoggerInterface $logger,
	) {
		$this->reflector = $reflector;
		$this->service = $service;
		$this->request = $request;
		$this->l = $l;
		$this->logger = $logger;
	}

	public function beforeController($controller, $methodName) {
		$reflectionMethod = new ReflectionMethod($controller, $methodName);

		$isAppEcosystemAuth = $this->hasAnnotationOrAttribute($reflectionMethod, 'AppEcosystemAuth', AppEcosystemAuth::class);

		if ($isAppEcosystemAuth) {
			if (!$this->service->validateExAppRequestToNC($this->request)) {
				throw new AEAuthNotValidException($this->l->t('AppEcosystemV2 authentication failed'), Http::STATUS_UNAUTHORIZED);
			}
		}
	}

	/**
	 * @template T
	 *
	 * @param ReflectionMethod $reflectionMethod
	 * @param string $annotationName
	 * @param class-string<T> $attributeClass
	 * @return boolean
	 */
	protected function hasAnnotationOrAttribute(ReflectionMethod $reflectionMethod, string $annotationName, string $attributeClass): bool {
		if (!empty($reflectionMethod->getAttributes($attributeClass))) {
			return true;
		}

		if ($this->reflector->hasAnnotation($annotationName)) {
			return true;
		}

		return false;
	}

	/**
	 * If an AEAuthNotValidException is being caught
	 *
	 * @param Controller $controller the controller that is being called
	 * @param string $methodName the name of the method that will be called on
	 *                           the controller
	 * @param \Exception $exception the thrown exception
	 * @return Response a Response object or null in case that the exception could not be handled
	 * @throws \Exception the passed in exception if it can't handle it
	 */
	public function afterException($controller, $methodName, \Exception $exception): Response {
		if ($exception instanceof AEAuthNotValidException) {
			$response = new JSONResponse([
				'message' => $exception->getMessage(),
			]);
			if (stripos($this->request->getHeader('Accept'), 'html') === false) {
				$response = new JSONResponse(
					['message' => $exception->getMessage()],
					$exception->getCode()
				);
			}

			$this->logger->debug($exception->getMessage(), [
				'exception' => $exception,
			]);
			return $response;
		}

		throw $exception;
	}
}
