<?php

declare(strict_types=1);

namespace OCA\UserRename\Handler\Apps;

use OCA\UserRename\Handler\ColumnRule;
use OCA\UserRename\Handler\IRenameHandler;
use OCA\UserRename\Service\RenameOptions;

/**
 * Teams (circles). Members reference local users by uid, and every user has
 * a personal "single" circle named "user:<uid>:<singleId>".
 */
class CirclesHandler implements IRenameHandler {
	private const TYPE_USER = 1;
	private const SOURCE_USER = 1;

	public function getName(): string {
		return 'teams';
	}

	public function getRules(RenameOptions $options): array {
		return [
			// local members only; remote members carry their own instance
			new ColumnRule('circles_member', 'user_id', where: ['user_type' => self::TYPE_USER, 'instance' => '']),
			new ColumnRule('circles_circle', 'name', mode: ColumnRule::PREFIX, prefixes: ['user:'],
				where: ['source' => self::SOURCE_USER, 'instance' => ''], separator: ':'),
		];
	}
}
