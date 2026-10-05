<?php

declare(strict_types=1);

namespace OCA\UserRename\Service;

use OCA\UserRename\Exception\PreflightException;
use OCA\UserRename\Exception\RenameFailedException;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IUser;

/**
 * Moves <datadirectory>/<uid> and the avatar folder in appdata.
 */
class FilesystemMover {
	public function __construct(
		private IConfig $config,
		private IAppDataFactory $appDataFactory,
	) {
	}

	public function dataDirectory(): string {
		return rtrim($this->config->getSystemValueString('datadirectory', \OC::$SERVERROOT . '/data'), '/');
	}

	/**
	 * @throws PreflightException
	 */
	public function checkCanRename(IUser $user, string $old, string $new): void {
		$data = $this->dataDirectory();
		$from = $data . '/' . $old;
		$to = $data . '/' . $new;

		if (rtrim($user->getHome(), '/') !== $from) {
			throw new PreflightException("Home of \"$old\" is {$user->getHome()}, expected $from; custom home locations are not supported");
		}
		if (!is_dir($data) || !is_writable($data)) {
			throw new PreflightException("Data directory $data is not writable by this process (run occ as the web server user)");
		}
		// A user who never logged in may have no home folder yet; that's fine.
		if (file_exists($to) && !$this->isSameEntry($from, $to)) {
			throw new PreflightException("$to already exists");
		}
	}

	/**
	 * @throws RenameFailedException
	 */
	public function renameDataDirectory(string $old, string $new): void {
		$data = $this->dataDirectory();
		$from = $data . '/' . $old;
		if (!file_exists($from)) {
			return;
		}
		$to = $data . '/' . $new;
		// Two steps so case-only renames also work on case-insensitive filesystems.
		$tmp = $data . '/.user_rename_' . bin2hex(random_bytes(6));
		if (!@rename($from, $tmp)) {
			throw new RenameFailedException("Could not rename $from");
		}
		if (!@rename($tmp, $to)) {
			@rename($tmp, $from);
			throw new RenameFailedException("Could not rename $from to $to");
		}
	}

	/**
	 * Best effort undo after a failed commit.
	 */
	public function revertDataDirectory(string $old, string $new): bool {
		$data = $this->dataDirectory();
		if (!file_exists($data . '/' . $new)) {
			return true;
		}
		try {
			$this->renameDataDirectory($new, $old);
			return true;
		} catch (RenameFailedException) {
			return false;
		}
	}

	/**
	 * Avatars live in appdata_<instanceid>/avatar/<uid>/. Going through
	 * IAppData keeps the root storage's filecache consistent.
	 *
	 * @return string|null warning, if any
	 */
	public function moveAvatar(string $old, string $new): ?string {
		$appData = $this->appDataFactory->get('avatar');
		try {
			$source = $appData->getFolder($old);
		} catch (NotFoundException) {
			return null;
		}
		try {
			try {
				$target = $appData->getFolder($new);
				// Stale folder from a previously deleted user, or a case-only rename
				// on a case-insensitive filesystem.
				if ($target->getName() === $source->getName()) {
					return 'Avatar folder name differs only in case; left as is';
				}
				$target->delete();
			} catch (NotFoundException) {
			}
			$target = $appData->newFolder($new);
			foreach ($source->getDirectoryListing() as $file) {
				$target->newFile($file->getName(), $file->getContent());
			}
			$source->delete();
		} catch (\Throwable $e) {
			return 'Could not move avatar (it will be regenerated): ' . $e->getMessage();
		}
		return null;
	}

	private function isSameEntry(string $a, string $b): bool {
		return file_exists($a) && file_exists($b) && fileinode($a) === fileinode($b);
	}
}
