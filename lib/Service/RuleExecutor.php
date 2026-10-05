<?php

declare(strict_types=1);

namespace OCA\UserRename\Service;

use OCA\UserRename\Handler\ColumnRule;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Runs ColumnRules against the database.
 *
 * Rows are always selected first and then filtered in PHP with strict
 * comparison, so case-insensitive collations (e.g. utf8mb4_general_ci on
 * old MySQL installs) never cause "Alice" to be rewritten when renaming
 * "alice". Updates and deletes then target rows by primary key.
 */
class RuleExecutor {
	private const CHUNK = 500;

	public function __construct(
		private IDBConnection $db,
		private SchemaInspector $schema,
	) {
	}

	public function isAvailable(ColumnRule $rule): bool {
		foreach ($rule->columnsUsed() as $column) {
			if (!$this->schema->hasColumn($rule->table, $column)) {
				return false;
			}
		}
		return true;
	}

	public function count(ColumnRule $rule, string $uid): int {
		return count($this->findMatches($rule, $uid));
	}

	/**
	 * @return list<array{pk: array<string, mixed>, value: string}>
	 */
	public function findMatches(ColumnRule $rule, string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(...array_values(array_unique([...$rule->primaryKey, $rule->column])))
			->from($rule->table);

		switch ($rule->mode) {
			case ColumnRule::EQ:
			case ColumnRule::DELETE:
				$qb->where($qb->expr()->eq($rule->column, $qb->createNamedParameter($rule->encode($uid))));
				break;
			case ColumnRule::PREFIX:
				$or = [];
				foreach ($rule->prefixes as $prefix) {
					$or[] = $qb->expr()->eq($rule->column, $qb->createNamedParameter($prefix . $uid));
					$or[] = $qb->expr()->like($rule->column, $qb->createNamedParameter(
						$this->db->escapeLikeParameter($prefix . $uid . $rule->separator) . '%'
					));
				}
				$qb->where($qb->expr()->orX(...$or));
				break;
			case ColumnRule::JSON:
				$qb->where($qb->expr()->like($this->textExpression($qb, $rule->column), $qb->createNamedParameter(
					'%' . $this->db->escapeLikeParameter($uid) . '%'
				)));
				break;
		}
		$this->applyWhere($qb, $rule->where);

		$matches = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$row = array_change_key_case($row, CASE_LOWER);
			$value = $row[strtolower($rule->column)];
			$value = $value === null ? null : (string)$value;
			// Probe with $uid as the "new" value: non-null means a strict match.
			if ($rule->rewrite($value, $uid, $uid) === null) {
				continue;
			}
			$pk = [];
			foreach ($rule->primaryKey as $column) {
				$pk[$column] = $row[strtolower($column)];
			}
			$matches[] = ['pk' => $pk, 'value' => $value];
		}
		$result->closeCursor();
		return $matches;
	}

	/**
	 * Must be called inside a transaction managed by the caller.
	 *
	 * @return int number of rows changed
	 */
	public function apply(ColumnRule $rule, string $old, string $new): int {
		$matches = $this->findMatches($rule, $old);
		if ($matches === []) {
			return 0;
		}

		if ($rule->mode === ColumnRule::DELETE) {
			foreach ($this->chunkByPk($rule, $matches) as $where) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($rule->table);
				$where($qb);
				$qb->executeStatement();
			}
			return count($matches);
		}

		// EQ rewrites set the same value on every row, so update in chunks.
		if ($rule->mode === ColumnRule::EQ) {
			foreach ($this->chunkByPk($rule, $matches) as $where) {
				$qb = $this->db->getQueryBuilder();
				$qb->update($rule->table)
					->set($rule->column, $qb->createNamedParameter($rule->encode($new)));
				$this->applyExtraSet($qb, $rule, $rule->encode($new));
				$where($qb);
				$qb->executeStatement();
			}
			return count($matches);
		}

		foreach ($matches as $match) {
			$newValue = $rule->rewrite($match['value'], $old, $new);
			if ($newValue === null) {
				continue;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->update($rule->table)
				->set($rule->column, $qb->createNamedParameter($newValue));
			$this->applyExtraSet($qb, $rule, $newValue);
			foreach ($match['pk'] as $column => $value) {
				$qb->andWhere($qb->expr()->eq($column, $this->param($qb, $value)));
			}
			$qb->executeStatement();
		}
		return count($matches);
	}

	/**
	 * Yields closures that add a WHERE clause selecting a chunk of the matched rows.
	 *
	 * @param list<array{pk: array<string, mixed>, value: string}> $matches
	 * @return iterable<\Closure(IQueryBuilder): void>
	 */
	private function chunkByPk(ColumnRule $rule, array $matches): iterable {
		if (count($rule->primaryKey) === 1) {
			$column = $rule->primaryKey[0];
			$ids = array_map(static fn (array $m) => $m['pk'][$column], $matches);
			foreach (array_chunk($ids, self::CHUNK) as $chunk) {
				$allInt = array_reduce($chunk, static fn (bool $c, $v) => $c && is_int($v), true);
				yield static function (IQueryBuilder $qb) use ($column, $chunk, $allInt): void {
					$qb->where($qb->expr()->in($column, $qb->createNamedParameter(
						$chunk,
						$allInt ? IQueryBuilder::PARAM_INT_ARRAY : IQueryBuilder::PARAM_STR_ARRAY,
					)));
				};
			}
			return;
		}
		foreach ($matches as $match) {
			yield function (IQueryBuilder $qb) use ($match): void {
				foreach ($match['pk'] as $column => $value) {
					$qb->andWhere($qb->expr()->eq($column, $this->param($qb, $value)));
				}
			};
		}
	}

	private function applyExtraSet(IQueryBuilder $qb, ColumnRule $rule, string $newValue): void {
		if ($rule->extraSet === null) {
			return;
		}
		foreach (($rule->extraSet)($newValue) as $column => $value) {
			if ($this->schema->hasColumn($rule->table, $column)) {
				$qb->set($column, $qb->createNamedParameter($value));
			}
		}
	}

	/**
	 * @param array<string, int|string|list<int>|list<string>> $where
	 */
	private function applyWhere(IQueryBuilder $qb, array $where): void {
		foreach ($where as $column => $value) {
			if (is_array($value)) {
				$allInt = array_reduce($value, static fn (bool $c, $v) => $c && is_int($v), true);
				$qb->andWhere($qb->expr()->in($column, $qb->createNamedParameter(
					$value,
					$allInt ? IQueryBuilder::PARAM_INT_ARRAY : IQueryBuilder::PARAM_STR_ARRAY,
				)));
			} else {
				$qb->andWhere($qb->expr()->eq($column, $this->param($qb, $value)));
			}
		}
	}

	private function param(IQueryBuilder $qb, mixed $value) {
		return is_int($value)
			? $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT)
			: $qb->createNamedParameter((string)$value);
	}

	/**
	 * JSON columns are native json on PostgreSQL, where LIKE needs a cast.
	 */
	public function textExpression(IQueryBuilder $qb, string $column) {
		if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_POSTGRES) {
			return $qb->createFunction('CAST(' . $qb->getColumnName($column) . ' AS TEXT)');
		}
		return $column;
	}
}
