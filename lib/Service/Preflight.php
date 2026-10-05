<?php

declare(strict_types=1);

namespace OCA\UserRename\Service;

use OCA\UserRename\Exception\PreflightException;
use OCP\Encryption\IManager as IEncryptionManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserBackend;
use OCP\IUserManager;

/**
 * Checks that must all pass before anything is changed.
 */
class Preflight {
	public function __construct(
		private IUserManager $userManager,
		private IConfig $config,
		private IDBConnection $db,
		private IEncryptionManager $encryptionManager,
		private FilesystemMover $filesystem,
	) {
	}

	/**
	 * @throws PreflightException
	 */
	public function check(string $old, string $new): IUser {
		if ($old === $new) {
			throw new PreflightException('Old and new uid are identical');
		}

		$user = $this->userManager->get($old);
		if ($user === null || $user->getUID() !== $old) {
			throw new PreflightException("User \"$old\" does not exist (the uid is case-sensitive here)");
		}
		if ($user->getBackendClassName() !== 'Database') {
			throw new PreflightException("User \"$old\" belongs to the {$user->getBackendClassName()} backend; only local (Database) users can be renamed");
		}
		// IUserManager::userExists()'s $excludeBackends does not match backend
		// names in all versions, so ask the other backends directly.
		foreach ($this->userManager->getBackends() as $backend) {
			if ($backend instanceof IUserBackend && $backend->getBackendName() === 'Database') {
				continue;
			}
			if ($backend->userExists($old)) {
				throw new PreflightException("Another user backend (" . $backend::class . ") also knows \"$old\"; refusing to rename");
			}
		}

		$caseOnly = mb_strtolower($old) === mb_strtolower($new);
		$existing = $this->userManager->get($new);
		if ($existing !== null && !($caseOnly && $existing->getUID() === $old)) {
			throw new PreflightException("User \"{$existing->getUID()}\" already exists (uids are unique case-insensitively)");
		}

		try {
			// Case-only renames would trip the data directory check on case-insensitive filesystems.
			$this->userManager->validateUserId($new, !$caseOnly);
		} catch (\InvalidArgumentException $e) {
			throw new PreflightException('Invalid new uid: ' . $e->getMessage(), 0, $e);
		}

		if ($this->config->getSystemValue('objectstore', null) !== null
			|| $this->config->getSystemValue('objectstore_multibucket', null) !== null) {
			throw new PreflightException('Object storage as primary storage is not supported');
		}
		if ($this->encryptionManager->isEnabled()) {
			throw new PreflightException('Server-side encryption is enabled; encryption keys are stored per uid and are not supported');
		}
		if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_ORACLE) {
			throw new PreflightException('Oracle databases are not supported');
		}

		$this->filesystem->checkCanRename($user, $old, $new);

		return $user;
	}
}
