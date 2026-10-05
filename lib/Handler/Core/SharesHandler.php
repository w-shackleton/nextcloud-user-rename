<?php

declare(strict_types=1);

namespace OCA\UserRename\Handler\Core;

use OCA\UserRename\Handler\ColumnRule;
use OCA\UserRename\Handler\IRenameHandler;
use OCA\UserRename\Service\RenameOptions;
use OCP\Share\IShare;

class SharesHandler implements IRenameHandler {
	public function getName(): string {
		return 'shares';
	}

	public function getRules(RenameOptions $options): array {
		return [
			new ColumnRule('share', 'uid_owner'),
			new ColumnRule('share', 'uid_initiator'),
			// share_with holds a uid only for user shares and per-user group share rows
			new ColumnRule('share', 'share_with',
				where: ['share_type' => [IShare::TYPE_USER, IShare::TYPE_USERGROUP]]),
		];
	}
}
