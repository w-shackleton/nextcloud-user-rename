<?php

declare(strict_types=1);

namespace OCA\UserRename\Handler\Core;

use OCA\UserRename\Handler\ColumnRule;
use OCA\UserRename\Handler\IRenameHandler;
use OCA\UserRename\Service\RenameOptions;

/**
 * The numeric storage id is unchanged, so oc_filecache needs no update.
 */
class StoragesHandler implements IRenameHandler {
	public function getName(): string {
		return 'home storage';
	}

	public function getRules(RenameOptions $options): array {
		return [
			new ColumnRule('storages', 'id', ['numeric_id'],
				encode: static fn (string $uid): string => self::adjustStorageId('home::' . $uid)),
		];
	}

	/**
	 * Mirrors OC\Files\Cache\Storage::adjustStorageId(): ids longer than
	 * 64 characters are stored as their md5.
	 */
	public static function adjustStorageId(string $storageId): string {
		return strlen($storageId) > 64 ? md5($storageId) : $storageId;
	}
}
