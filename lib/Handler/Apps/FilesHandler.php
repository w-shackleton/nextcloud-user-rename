<?php

declare(strict_types=1);

namespace OCA\UserRename\Handler\Apps;

use OCA\UserRename\Handler\ColumnRule;
use OCA\UserRename\Handler\IRenameHandler;
use OCA\UserRename\Service\RenameOptions;

class FilesHandler implements IRenameHandler {
	public function getName(): string {
		return 'files';
	}

	public function getRules(RenameOptions $options): array {
		return [
			new ColumnRule('files_trash', 'user', ['auto_id']),
			new ColumnRule('files_trash', 'deleted_by', ['auto_id']),
			new ColumnRule('files_versions', 'metadata', mode: ColumnRule::JSON, jsonKeys: ['author']),
			new ColumnRule('files_reminders', 'user_id'),
			new ColumnRule('user_transfer_owner', 'source_user'),
			new ColumnRule('user_transfer_owner', 'target_user'),
			new ColumnRule('open_local_editor', 'user_id', mode: ColumnRule::DELETE),
		];
	}
}
