<?php

declare(strict_types=1);

namespace OCA\UserRename\Handler\Core;

use OCA\UserRename\Handler\ColumnRule;
use OCA\UserRename\Handler\IRenameHandler;
use OCA\UserRename\Service\RenameOptions;

class GroupsHandler implements IRenameHandler {
	public function getName(): string {
		return 'groups';
	}

	public function getRules(RenameOptions $options): array {
		return [
			new ColumnRule('group_user', 'uid', ['gid', 'uid']),
			new ColumnRule('group_admin', 'uid', ['gid', 'uid']),
		];
	}
}
