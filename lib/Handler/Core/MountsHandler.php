<?php

declare(strict_types=1);

namespace OCA\UserRename\Handler\Core;

use OCA\UserRename\Handler\ColumnRule;
use OCA\UserRename\Handler\IRenameHandler;
use OCA\UserRename\Service\RenameOptions;

class MountsHandler implements IRenameHandler {
	public function getName(): string {
		return 'mounts';
	}

	public function getRules(RenameOptions $options): array {
		return [
			new ColumnRule('mounts', 'user_id'),
			// "/<uid>/" and "/<uid>/files/..." mount points, plus their lookup hash
			new ColumnRule('mounts', 'mount_point', mode: ColumnRule::PREFIX, prefixes: ['/'],
				extraSet: static fn (string $new): array => ['mount_point_hash' => hash('xxh128', $new)]),
		];
	}
}
