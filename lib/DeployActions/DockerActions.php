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

namespace OCA\AppEcosystemV2\DeployActions;


use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCP\ICertificateManager;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

use OCA\AppEcosystemV2\Db\DaemonConfig;
use OCA\AppEcosystemV2\Deploy\DeployActions;

class DockerActions extends DeployActions {
	public const DOCKER_API_VERSION = 'v1.41';
	public const AE_REQUIRED_ENVS = [
		'AE_VERSION',
		'APP_SECRET',
		'APP_ID',
		'APP_DISPLAY_NAME',
		'APP_VERSION',
		'APP_PROTOCOL',
		'APP_HOST',
		'APP_PORT',
		'IS_SYSTEM_APP',
		'NEXTCLOUD_URL',
	];
	private LoggerInterface $logger;
	private Client $guzzleClient;
	private ICertificateManager $certificateManager;
	private IConfig $config;

	public function __construct(
		LoggerInterface $logger,
		IConfig $config,
		ICertificateManager $certificateManager
	) {
		$this->logger = $logger;
		$this->certificateManager = $certificateManager;
		$this->config = $config;
	}

	public function getAcceptsDeployId(): string {
		return 'docker-install';
	}

	/**
	 * Pull image, create and start container
	 *
	 * @param DaemonConfig $daemonConfig
	 * @param array $params
	 *
	 * @return array
	 */
	public function deployExApp(DaemonConfig $daemonConfig, array $params = []): array {
		if ($daemonConfig->getAcceptsDeployId() !== 'docker-install') {
			return [['error' => 'Only docker-install is supported for now.'], null, null];
		}

		if (isset($params['image_params'])) {
			$imageParams = $params['image_params'];
		} else {
			return [['error' => 'Missing image_params.'], null, null];
		}

		if (isset($params['container_params'])) {
			$containerParams = $params['container_params'];
		} else {
			return [['error' => 'Missing container_params.'], null, null];
		}

		$dockerUrl = $this->buildDockerUrl($daemonConfig);
		$this->initGuzzleClient($daemonConfig);

		$pullResult = $this->pullContainer($dockerUrl, $imageParams);
		if (isset($pullResult['error'])) {
			return [$pullResult, null, null];
		}

		$createResult = $this->createContainer($dockerUrl, $imageParams, $containerParams);
		if (isset($createResult['error'])) {
			return [null, $createResult, null];
		}

		$startResult = $this->startContainer($dockerUrl, $createResult['Id']);
		return [$pullResult, $createResult, $startResult];
	}

	public function buildApiUrl(string $dockerUrl, string $route): string {
		return sprintf('%s/%s/%s', $dockerUrl, self::DOCKER_API_VERSION, $route);
	}

	public function buildImageName(array $imageParams): string {
		return $imageParams['image_src'] . '/' . $imageParams['image_name'] . ':' . $imageParams['image_tag'];
	}

	public function createContainer(string $dockerUrl, array $imageParams, array $params = []): array {
		$containerParams = [
			'Image' => $this->buildImageName($imageParams),
			'Hostname' => $params['hostname'],
			'HostConfig' => [
				'NetworkMode' => $params['net'],
			],
			'Env' => $params['env'],
		];

		if (!in_array($params['net'], ['host', 'bridge'])) {
			$networkingConfig = [
				'EndpointsConfig' => [
					$params['net'] => [
						'Aliases' => [
							$params['hostname']
						],
					],
				],
			];
			$containerParams['NetworkingConfig'] = $networkingConfig;
		}

		$url = $this->buildApiUrl($dockerUrl, sprintf('containers/create?name=%s', urlencode($params['name'])));
		try {
			$options['json'] = $containerParams;
			$response = $this->guzzleClient->post($url, $options);
			return json_decode((string) $response->getBody(), true);
		} catch (GuzzleException $e) {
			$this->logger->error('Failed to create container', ['exception' => $e]);
			error_log($e->getMessage());
			return ['error' => 'Failed to create container'];
		}
	}

	public function startContainer(string $dockerUrl, string $containerId): array {
		$url = $this->buildApiUrl($dockerUrl, sprintf('containers/%s/start', $containerId));
		try {
			$response = $this->guzzleClient->post($url);
			return ['success' => $response->getStatusCode() === 204];
		} catch (GuzzleException $e) {
			$this->logger->error('Failed to start container', ['exception' => $e]);
			error_log($e->getMessage());
			return ['error' => 'Failed to start container'];
		}
	}

	public function pullContainer(string $dockerUrl, array $params): array {
		$url = $this->buildApiUrl($dockerUrl, sprintf('images/create?fromImage=%s', $this->buildImageName($params)));
		try {
			$xRegistryAuth = json_encode([
				'https://' . $params['image_src'] => []
			], JSON_UNESCAPED_SLASHES);
			$response = $this->guzzleClient->post($url, [
				'headers' => [
					'X-Registry-Auth' => base64_encode($xRegistryAuth),
				],
			]);
			return ['success' => $response->getStatusCode() === 200];
		} catch (GuzzleException $e) {
			$this->logger->error('Failed to pull image', ['exception' => $e]);
			error_log($e->getMessage());
			return ['error' => 'Failed to pull image.'];
		}
	}

	public function inspectContainer(string $dockerUrl, string $containerId): array {
		$url = $this->buildApiUrl($dockerUrl, sprintf('containers/%s/json', $containerId));
		try {
			$response = $this->guzzleClient->get($url);
			return json_decode((string) $response->getBody(), true);
		} catch (GuzzleException $e) {
			$this->logger->error('Failed to inspect container', ['exception' => $e]);
			error_log($e->getMessage());
			return ['error' => 'Failed to inspect container'];
		}
	}

	/**
	 * @param string $appId
	 * @param DaemonConfig $daemonConfig
	 * @param array $params
	 *
	 * @return array
	 */
	public function loadExAppInfo(string $appId, DaemonConfig $daemonConfig, array $params = []): array {
		$this->initGuzzleClient($daemonConfig);
		$containerInfo = $this->inspectContainer($this->buildDockerUrl($daemonConfig), $appId);
		if (isset($containerInfo['error'])) {
			return ['error' => sprintf('Failed to inspect ExApp %s container: %s', $appId, $containerInfo['error'])];
		}

		$containerEnvs = (array) $containerInfo['Config']['Env'];
		$aeEnvs = [];
		foreach ($containerEnvs as $env) {
			$envParts = explode('=', $env, 2);
			if (in_array($envParts[0], self::AE_REQUIRED_ENVS)) {
				$aeEnvs[$envParts[0]] = $envParts[1];
			}
		}

		if ($appId !== $aeEnvs['APP_ID']) {
			return ['error' => sprintf('ExApp appid %s does not match to deployed APP_ID %s.', $appId, $aeEnvs['APP_ID'])];
		}

		return [
			'appid' => $aeEnvs['APP_ID'],
			'name' => $aeEnvs['APP_DISPLAY_NAME'],
			'version' => $aeEnvs['APP_VERSION'],
			'secret' => $aeEnvs['APP_SECRET'],
			'host' => $this->resolveDeployExAppHost($appId, $daemonConfig),
			'port' => $aeEnvs['APP_PORT'],
			'protocol' => $aeEnvs['APP_PROTOCOL'],
			'system_app' => $aeEnvs['IS_SYSTEM_APP'] ?? false,
		];
	}

	public function resolveDeployExAppHost(string $appId, DaemonConfig $daemonConfig, array $params = []): string {
		$deployConfig = $daemonConfig->getDeployConfig();
		if (isset($deployConfig['net']) && $deployConfig['net'] === 'host') {
			$host = $deployConfig['host'] ?? 'localhost';
		} else {
			$host = $appId;
		}
		return $host;
	}

	public function buildDockerUrl(DaemonConfig $daemonConfig): string {
		$dockerUrl = 'http://localhost';
		if (in_array($daemonConfig->getProtocol(), ['http', 'https'])) {
			$dockerUrl = $daemonConfig->getProtocol() . '://' . $daemonConfig->getHost();
		}
		return $dockerUrl;
	}

	public function initGuzzleClient(DaemonConfig $daemonConfig): void {
		$guzzleParams = [];
		if ($daemonConfig->getProtocol() === 'unix-socket') {
			$guzzleParams = [
				'curl' => [
					CURLOPT_UNIX_SOCKET_PATH => $daemonConfig->getHost(),
				],
			];
		} else if (in_array($daemonConfig->getProtocol(), ['http', 'https'])) {
			$guzzleParams = $this->setupCerts($guzzleParams, $daemonConfig->getDeployConfig());
		}
		$this->guzzleClient = new Client($guzzleParams);
	}

	/**
	 * @param array $guzzleParams
	 * @param array $deployConfig
	 *
	 * @return array
	 */
	private function setupCerts(array $guzzleParams, array $deployConfig): array {
		if (!$this->config->getSystemValueBool('installed', false)) {
			$certs =  \OC::$SERVERROOT . '/resources/config/ca-bundle.crt';
		} else {
			$certs = $this->certificateManager->getAbsoluteBundlePath();
		}

		$guzzleParams['verify'] = $certs;
		if (isset($deployConfig['ssl_key'])) {
			$guzzleParams['ssl_key'] = !isset($deployConfig['ssl_key_password'])
				? $deployConfig['ssl_key']
				: [$deployConfig['ssl_key'], $deployConfig['ssl_key_password']];
		}
		if (isset($deployConfig['ssl_cert'])) {
			$guzzleParams['cert'] = !isset($deployConfig['ssl_cert_password'])
				? $deployConfig['ssl_cert']
				: [$deployConfig['ssl_cert'], $deployConfig['ssl_cert_password']];
		}
		return $guzzleParams;
	}
}
