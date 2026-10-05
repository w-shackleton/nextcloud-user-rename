<?php

declare(strict_types=1);

namespace OCA\UserRename\Handler\Core;

use OCA\UserRename\Handler\ColumnRule;
use OCA\UserRename\Handler\IRenameHandler;
use OCA\UserRename\Service\RenameOptions;

class UsersHandler implements IRenameHandler {
	public function getName(): string {
		return 'users';
	}

	public function getRules(RenameOptions $options): array {
		return [
			new ColumnRule('users', 'uid', ['uid'],
				extraSet: static fn (string $new): array => ['uid_lower' => mb_strtolower($new)]),
		];
	}
}
