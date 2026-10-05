<?php

declare(strict_types=1);

namespace OCA\UserRename\Service;

final class RenameOptions {
	public function __construct(
		public readonly bool $dryRun = false,
		/** Rewrite app passwords / sessions instead of deleting them */
		public readonly bool $keepTokens = false,
		/** Continue even if rows already reference the new uid */
		public readonly bool $force = false,
		/** Abort if the scanner finds uid references no handler covers */
		public readonly bool $strict = false,
	) {
	}
}
