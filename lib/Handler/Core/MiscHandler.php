<?php

declare(strict_types=1);

namespace OCA\UserRename\Handler\Core;

use OCA\UserRename\Handler\ColumnRule;
use OCA\UserRename\Handler\IRenameHandler;
use OCA\UserRename\Service\RenameOptions;

class MiscHandler implements IRenameHandler {
	public function getName(): string {
		return 'misc core';
	}

	public function getRules(RenameOptions $options): array {
		return [
			// tags and favorites
			new ColumnRule('vcategory', 'uid'),
			new ColumnRule('storages_credentials', 'user'),
			// named arguments, and queued OC\Command jobs (serialized Expire commands)
			new ColumnRule('jobs', 'argument', mode: ColumnRule::JSON,
				jsonKeys: ['uid', 'userId', 'userid', 'user_id', 'user', 'owner']),
			// positional [uid, fileId]
			new ColumnRule('jobs', 'argument', mode: ColumnRule::JSON, jsonKeys: [0],
				where: ['class' => 'OC\FilesMetadata\Job\UpdateSingleMetadata']),
			new ColumnRule('llm_tasks', 'user_id'),
			new ColumnRule('textprocessing_tasks', 'user_id'),
			new ColumnRule('text2image_tasks', 'user_id'),
			new ColumnRule('taskprocessing_tasks', 'user_id'),
			// caches and short-lived rows
			new ColumnRule('collres_accesscache', 'user_id',
				['user_id', 'collection_id', 'resource_type', 'resource_id'], ColumnRule::DELETE),
			new ColumnRule('direct_edit', 'user_id', mode: ColumnRule::DELETE),
		];
	}
}
