<?php

declare(strict_types=1);

namespace OCA\UserRename\Handler;

/**
 * Declarative description of one column that stores a uid.
 *
 * Modes:
 *  - EQ:     the column value is exactly the uid
 *  - PREFIX: the uid is a segment after one of $prefixes, e.g.
 *            "principals/users/<uid>" or "principals/users/<uid>/..."
 *            ($separator ends the segment, "/" by default)
 *  - JSON:   the column holds JSON; values equal to the uid under $jsonKeys
 *            are rewritten. String keys match at any depth, integer keys
 *            (positional arguments) only at the top level. A bare JSON string
 *            equal to the uid is rewritten, and a JSON string holding a
 *            PHP-serialized object (queued OC\Command jobs) has its
 *            properties named in $jsonKeys rewritten.
 *  - DELETE: rows matching EQ are deleted instead of rewritten
 */
final class ColumnRule {
	public const EQ = 'eq';
	public const PREFIX = 'prefix';
	public const JSON = 'json';
	public const DELETE = 'delete';

	/**
	 * @param string $table table name without prefix
	 * @param list<string> $primaryKey columns that identify a row
	 * @param array<string, int|string|list<int>|list<string>> $where extra equality / IN filters
	 * @param list<string> $prefixes for PREFIX mode
	 * @param list<string|int> $jsonKeys for JSON mode
	 * @param null|\Closure(string $newValue): array<string, string> $extraSet additional columns
	 *        to set when a row is rewritten; columns missing from the schema are skipped
	 * @param null|\Closure(string $uid): string $encode EQ/DELETE only: maps a uid to the stored
	 *        value, e.g. "home::<uid>"
	 * @param string $separator PREFIX only: what may follow the uid segment
	 */
	public function __construct(
		public readonly string $table,
		public readonly string $column,
		public readonly array $primaryKey = ['id'],
		public readonly string $mode = self::EQ,
		public readonly array $where = [],
		public readonly array $prefixes = [],
		public readonly array $jsonKeys = [],
		public readonly ?\Closure $extraSet = null,
		public readonly ?\Closure $encode = null,
		public readonly string $separator = '/',
	) {
	}

	public function label(): string {
		$label = $this->table . '.' . $this->column;
		if ($this->mode !== self::EQ) {
			$label .= ' (' . $this->mode . ')';
		}
		foreach ($this->where as $column => $value) {
			$label .= ' [' . $column . '=' . (is_array($value) ? implode(',', $value) : $value) . ']';
		}
		return $label;
	}

	/**
	 * The stored value an EQ/DELETE rule matches for $uid.
	 */
	public function encode(string $uid): string {
		return $this->encode === null ? $uid : ($this->encode)($uid);
	}

	/**
	 * Compute the rewritten value, or null if $value does not reference $old
	 * strictly (case-sensitive, whole segment).
	 */
	public function rewrite(?string $value, string $old, string $new): ?string {
		if ($value === null) {
			return null;
		}
		switch ($this->mode) {
			case self::EQ:
			case self::DELETE:
				return $value === $this->encode($old) ? $this->encode($new) : null;
			case self::PREFIX:
				foreach ($this->prefixes as $prefix) {
					$base = $prefix . $old;
					if ($value === $base) {
						return $prefix . $new;
					}
					if (str_starts_with($value, $base . $this->separator)) {
						return $prefix . $new . substr($value, strlen($base));
					}
				}
				return null;
			case self::JSON:
				return self::rewriteJson($value, $old, $new, $this->jsonKeys);
		}
		throw new \LogicException('Unknown mode ' . $this->mode);
	}

	/**
	 * @param list<string|int> $keys
	 */
	public static function rewriteJson(string $value, string $old, string $new, array $keys): ?string {
		try {
			$decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}
		if ($decoded === $old) {
			return self::encodeJson($new);
		}
		if (is_string($decoded)) {
			$serialized = self::rewriteSerialized($decoded, $old, $new, $keys);
			return $serialized === null ? null : self::encodeJson($serialized);
		}
		if (!is_array($decoded)) {
			return null;
		}
		$changed = false;
		$walk = function (array $data, bool $topLevel) use (&$walk, &$changed, $old, $new, $keys): array {
			foreach ($data as $key => $item) {
				if (is_array($item)) {
					$data[$key] = $walk($item, false);
				} elseif ($item === $old
					&& (is_string($key) || $topLevel)
					&& in_array($key, $keys, true)) {
					$data[$key] = $new;
					$changed = true;
				}
			}
			return $data;
		};
		$result = $walk($decoded, true);
		return $changed ? self::encodeJson($result) : null;
	}

	/**
	 * Rewrites string properties of a PHP-serialized object without
	 * unserializing it, e.g. s:5:"alice" -> s:6:"alicia" for the private
	 * property "user" of OCA\Files_Versions\Command\Expire.
	 *
	 * @param list<string|int> $keys
	 */
	public static function rewriteSerialized(string $value, string $old, string $new, array $keys): ?string {
		if (!preg_match('/^O:\d+:"/', $value)) {
			return null;
		}
		$names = array_map(static fn ($k) => preg_quote((string)$k, '/'), array_filter($keys, 'is_string'));
		if ($names === []) {
			return null;
		}
		// property names: "name", "\0*\0name" (protected) or "\0Class\0name" (private)
		$pattern = '/(s:\d+:"(?:\x00[^\x00"]*\x00)?(?:' . implode('|', $names) . ')";)s:(\d+):"/';
		// Walk the matches with offsets so each string payload is compared exactly.
		$changed = false;
		$out = '';
		$pos = 0;
		if (preg_match_all($pattern, $value, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
			foreach ($matches as $m) {
				$length = (int)$m[2][0];
				$payloadStart = $m[0][1] + strlen($m[0][0]);
				$payload = substr($value, $payloadStart, $length);
				if ($payload !== $old || substr($value, $payloadStart + $length, 2) !== '";') {
					continue;
				}
				$out .= substr($value, $pos, $m[0][1] - $pos) . $m[1][0] . 's:' . strlen($new) . ':"' . $new;
				$pos = $payloadStart + $length;
				$changed = true;
			}
		}
		if (!$changed) {
			return null;
		}
		return $out . substr($value, $pos);
	}

	private static function encodeJson(mixed $value): string {
		return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	}

	/**
	 * @return list<string>
	 */
	public function columnsUsed(): array {
		return array_values(array_unique(array_merge(
			[$this->column],
			$this->primaryKey,
			array_keys($this->where),
		)));
	}
}
