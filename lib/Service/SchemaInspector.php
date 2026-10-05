<?php

declare(strict_types=1);

namespace OCA\UserRename\Service;

use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TextType;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Cached view of the live database schema, keyed by unprefixed table name.
 */
class SchemaInspector {
	public const KIND_STRING = 'string';
	public const KIND_TEXT = 'text';
	public const KIND_JSON = 'json';
	public const KIND_OTHER = 'other';

	/** @var array<string, array<string, string>>|null table => column => kind */
	private ?array $tables = null;

	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
	) {
	}

	public function hasTable(string $table): bool {
		return isset($this->getTables()[$table]);
	}

	public function hasColumn(string $table, string $column): bool {
		return isset($this->getTables()[$table][$column]);
	}

	public function columnKind(string $table, string $column): ?string {
		return $this->getTables()[$table][$column] ?? null;
	}

	/**
	 * @return array<string, array<string, string>>
	 */
	public function getTables(): array {
		if ($this->tables !== null) {
			return $this->tables;
		}
		$prefix = $this->config->getSystemValueString('dbtableprefix', 'oc_');
		$this->tables = [];
		foreach ($this->db->createSchema()->getTables() as $table) {
			$name = $table->getName();
			if ($prefix !== '') {
				if (!str_starts_with($name, $prefix)) {
					continue;
				}
				$name = substr($name, strlen($prefix));
			}
			$columns = [];
			foreach ($table->getColumns() as $column) {
				$type = $column->getType();
				$columns[strtolower($column->getName())] = match (true) {
					$type instanceof JsonType => self::KIND_JSON,
					$type instanceof TextType => self::KIND_TEXT,
					$type instanceof StringType => self::KIND_STRING,
					default => self::KIND_OTHER,
				};
			}
			$this->tables[strtolower($name)] = $columns;
		}
		return $this->tables;
	}
}
