<?php

declare(strict_types=1);

namespace OCA\UserRename\Handler\Core;

use OCA\UserRename\Handler\ColumnRule;
use OCA\UserRename\Handler\IRenameHandler;
use OCA\UserRename\Service\RenameOptions;

class AccountsHandler implements IRenameHandler {
	public function getName(): string {
		return 'accounts';
	}

	public function getRules(RenameOptions $options): array {
		return [
			new ColumnRule('accounts', 'uid', ['uid']),
			new ColumnRule('accounts_data', 'uid'),
			new ColumnRule('profile_config', 'user_id'),
			new ColumnRule('known_users', 'known_to'),
			new ColumnRule('known_users', 'known_user'),
		];
	}
}
