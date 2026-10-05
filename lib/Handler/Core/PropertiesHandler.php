<?php

declare(strict_types=1);

namespace OCA\UserRename\Handler\Core;

use OCA\UserRename\Handler\ColumnRule;
use OCA\UserRename\Handler\IRenameHandler;
use OCA\UserRename\Service\RenameOptions;

class PropertiesHandler implements IRenameHandler {
	public function getName(): string {
		return 'dav properties';
	}

	public function getRules(RenameOptions $options): array {
		return [
			new ColumnRule('properties', 'userid'),
			new ColumnRule('properties', 'propertypath', mode: ColumnRule::PREFIX, prefixes: [
				'files/',
				'calendars/',
				'addressbooks/users/',
				'principals/users/',
			]),
		];
	}
}
