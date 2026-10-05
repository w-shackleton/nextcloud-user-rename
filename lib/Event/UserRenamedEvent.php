<?php

declare(strict_types=1);

namespace OCA\UserRename\Event;

use OCP\EventDispatcher\Event;

/**
 * Dispatched after a user's uid was renamed and the transaction committed.
 */
class UserRenamedEvent extends Event {
	public function __construct(
		private string $oldUid,
		private string $newUid,
	) {
		parent::__construct();
	}

	public function getOldUid(): string {
		return $this->oldUid;
	}

	public function getNewUid(): string {
		return $this->newUid;
	}
}
