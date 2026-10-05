<?php

declare(strict_types=1);

namespace OCA\UserRename\Handler;

use OCA\UserRename\Service\RenameOptions;

/**
 * A group of related uid-bearing columns, usually one app's tables.
 */
interface IRenameHandler {
	public function getName(): string;

	/**
	 * @return list<ColumnRule>
	 */
	public function getRules(RenameOptions $options): array;
}
