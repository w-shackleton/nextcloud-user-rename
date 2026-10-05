<?php

declare(strict_types=1);

namespace OCA\UserRename\Handler\Core;

use OCA\UserRename\Handler\ColumnRule;
use OCA\UserRename\Handler\IRenameHandler;
use OCA\UserRename\Service\RenameOptions;

class TwoFactorHandler implements IRenameHandler {
	public function getName(): string {
		return 'two-factor';
	}

	public function getRules(RenameOptions $options): array {
		return [
			new ColumnRule('twofactor_providers', 'uid', ['provider_id', 'uid']),
			new ColumnRule('twofactor_backupcodes', 'user_id'),
			new ColumnRule('webauthn', 'uid'),
		];
	}
}
