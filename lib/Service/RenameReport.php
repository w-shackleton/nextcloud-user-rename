<?php

declare(strict_types=1);

namespace OCA\UserRename\Service;

final class RenameReport {
	/** @var list<array{handler: string, column: string, rows: int}> rows to change / changed */
	public array $changes = [];

	/** @var list<array{handler: string, column: string, rows: int}> rows already referencing the new uid */
	public array $conflicts = [];

	/** @var list<array{table: string, column: string, match: string, rows: int}> */
	public array $unknownReferences = [];

	/** @var list<string> */
	public array $warnings = [];

	public function __construct(
		public readonly string $old,
		public readonly string $new,
	) {
	}
}
