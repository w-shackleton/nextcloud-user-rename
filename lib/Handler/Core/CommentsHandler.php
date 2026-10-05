<?php

declare(strict_types=1);

namespace OCA\UserRename\Handler\Core;

use OCA\UserRename\Handler\ColumnRule;
use OCA\UserRename\Handler\IRenameHandler;
use OCA\UserRename\Service\RenameOptions;

class CommentsHandler implements IRenameHandler {
	public function getName(): string {
		return 'comments';
	}

	public function getRules(RenameOptions $options): array {
		return [
			new ColumnRule('comments', 'actor_id', where: ['actor_type' => 'users']),
			new ColumnRule('comments_read_markers', 'user_id', ['user_id', 'object_type', 'object_id']),
			new ColumnRule('reactions', 'actor_id', where: ['actor_type' => 'users']),
		];
	}
}
