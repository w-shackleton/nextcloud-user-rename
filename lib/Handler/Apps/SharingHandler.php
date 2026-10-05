<?php

declare(strict_types=1);

namespace OCA\UserRename\Handler\Apps;

use OCA\UserRename\Handler\ColumnRule;
use OCA\UserRename\Handler\IRenameHandler;
use OCA\UserRename\Service\RenameOptions;

class SharingHandler implements IRenameHandler {
	public function getName(): string {
		return 'sharing';
	}

	public function getRules(RenameOptions $options): array {
		return [
			// owner / remote belong to the remote server and stay as they are
			new ColumnRule('share_external', 'user'),
			// files_external APPLICABLE_TYPE_USER
			new ColumnRule('external_applicable', 'value', ['applicable_id'], where: ['type' => 3]),
			// workflowengine IManager::SCOPE_USER
			new ColumnRule('flow_operations_scope', 'value', where: ['type' => 1]),
		];
	}
}
