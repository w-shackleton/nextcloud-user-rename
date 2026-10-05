<?php

declare(strict_types=1);

namespace OCA\UserRename\Handler\Core;

use OCA\UserRename\Handler\ColumnRule;
use OCA\UserRename\Handler\IRenameHandler;
use OCA\UserRename\Service\RenameOptions;

class PreferencesHandler implements IRenameHandler {
	public function getName(): string {
		return 'preferences';
	}

	public function getRules(RenameOptions $options): array {
		return [
			new ColumnRule('preferences', 'userid', ['userid', 'appid', 'configkey']),
		];
	}
}
