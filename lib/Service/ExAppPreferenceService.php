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

namespace OCA\AppEcosystemV2\Service;

use OCA\AppEcosystemV2\Db\ExAppPreference;
use OCA\AppEcosystemV2\Db\ExAppPreferenceMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use Psr\Log\LoggerInterface;

/**
 * App per-user preferences (preferences_ex)
 */
class ExAppPreferenceService {
	private ExAppPreferenceMapper $mapper;
	private LoggerInterface $logger;

	public function __construct(
		ExAppPreferenceMapper $mapper,
		LoggerInterface $logger
	) {
		$this->mapper = $mapper;
		$this->logger = $logger;
	}

	public function setUserConfigValue(string $userId, string $appId, string $configKey, mixed $configValue) {
		try {
			$exAppPreference = $this->mapper->findByUserIdAppIdKey($userId, $appId, $configKey);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			$exAppPreference = null;
		}
		if ($exAppPreference === null) {
			try {
				return $this->mapper->insert(new ExAppPreference([
					'userid' => $userId,
					'appid' => $appId,
					'configkey' => $configKey,
					'value' => $configValue,
				]));
			} catch (Exception $e) {
				$this->logger->error('Error while inserting new config value: ' . $e->getMessage());
				return null;
			}
		} else {
			$exAppPreference->setValue($configValue);
			try {
				if ($this->mapper->updateUserConfigValue($exAppPreference) !== 1) {
					$this->logger->error('Error while updating preferences_ex config value');
					return null;
				}
				return $exAppPreference;
			} catch (Exception $e) {
				$this->logger->error('Error while updating config value: ' . $e->getMessage());
				return null;
			}
		}
	}

	/**
	 * @param string $userId
	 * @param string $appId
	 * @param array $configKeys
	 * @return ExAppPreference[]|null
	 */
	public function getUserConfigValue(string $userId, string $appId, array $configKeys): ?array {
		try {
			return $this->mapper->findByUserIdAppIdKeys($userId, $appId, $configKeys);
		} catch (Exception) {
			return null;
		}
	}

	public function getUserConfigKeys(string $userId, string $appId): ?array {
		try {
			return $this->mapper->findUserConfigKeys($userId, $appId);
		} catch (Exception $e) {
			$this->logger->error('Error while getting config keys: ' . $e->getMessage());
			return null;
		}
	}

	public function deleteUserConfigValue(string $userId, string $appId, string $configKey): bool {
		try {
			return $this->mapper->deleteUserConfigValue($userId, $appId, $configKey) === 1;
		} catch (Exception $e) {
			$this->logger->error('Error while deleting config value: ' . $e->getMessage());
		}
		return false;
	}

	public function deleteUserConfigValues(array $configKeys, string $userId, string $appId): bool {
		try {
			return $this->mapper->deleteUserConfigValues($configKeys, $userId, $appId) === count($configKeys);
		} catch (Exception $e) {
			$this->logger->error('Error while deleting config values: ' . $e->getMessage());
		}
		return false;
	}
}
