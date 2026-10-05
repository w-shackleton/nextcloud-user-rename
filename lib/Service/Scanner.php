<?php

declare(strict_types=1);

namespace OCA\UserRename\Service;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Looks for uid references in columns that no handler covers, so unknown
 * apps' tables are at least reported.
 */
class Scanner {
	private const NAME_PATTERN = '/(^|_)(uid|user|owner|actor|principal|login|author|known|creator|initiator|recipient|member|account)|_by$/';

	/** Columns that look like uids but are not (iCal/vCard UIDs, remote ids, ...) */
	private const EXCLUDED = [
		'calendarobjects.uid',
		'calendar_invitations.uid',
		'calendar_reminders.uid',
		'cards.uid',
		'recent_contact.uid',
		'share_external.owner',
		'share_external.remote',
		'calendars_federated.shared_by',
		'calendars_federated.shared_by_display_name',
		'users.uid_lower',
		// remote users / instances
		'federated_invites.recipient_user_id',
		'federated_invites.recipient_name',
		'federated_invites.recipient_email',
		'sec_signatory.account',
		'circles_remote.uid',
		// circles single ids, not uids
		'circles_member.member_id',
		'circles_member.invited_by',
		'circles_token.member_id',
		// display names
		'files_lock.owner',
	];

	public function __construct(
		private IDBConnection $db,
		private SchemaInspector $schema,
		private RuleExecutor $executor,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<string, true> $covered "table.column" => true
	 * @return list<array{table: string, column: string, match: string, rows: int}>
	 */
	public function scan(string $uid, array $covered): array {
		$hits = [];
		foreach ($this->schema->getTables() as $table => $columns) {
			if (str_starts_with($table, 'ldap_')) {
				continue;
			}
			foreach ($columns as $column => $kind) {
				$key = $table . '.' . $column;
				if ($kind === SchemaInspector::KIND_OTHER
					|| isset($covered[$key])
					|| in_array($key, self::EXCLUDED, true)
					|| str_contains($column, 'display_name')
					|| !preg_match(self::NAME_PATTERN, $column)) {
					continue;
				}
				try {
					$hit = $kind === SchemaInspector::KIND_STRING
						? $this->countExact($table, $column, $uid)
						: $this->countContains($table, $column, $uid);
				} catch (\Throwable $e) {
					$this->logger->warning('user_rename scan failed for ' . $key, ['exception' => $e]);
					continue;
				}
				if ($hit !== null) {
					$hits[] = ['table' => $table, 'column' => $column] + $hit;
				}
			}
		}
		return $hits;
	}

	/**
	 * @return array{match: string, rows: int}|null
	 */
	private function countExact(string $table, string $column, string $uid): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select($column)
			->from($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($uid)));
		$result = $qb->executeQuery();
		$rows = 0;
		while (($value = $result->fetchOne()) !== false) {
			// strict check in PHP, the column may use a _ci collation
			if ((string)$value === $uid) {
				$rows++;
			}
		}
		$result->closeCursor();
		return $rows > 0 ? ['match' => 'exact', 'rows' => $rows] : null;
	}

	/**
	 * @return array{match: string, rows: int}|null
	 */
	private function countContains(string $table, string $column, string $uid): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($table)
			->where($qb->expr()->like($this->executor->textExpression($qb, $column), $qb->createNamedParameter(
				'%' . $this->db->escapeLikeParameter($uid) . '%'
			)));
		$result = $qb->executeQuery();
		$rows = (int)$result->fetchOne();
		$result->closeCursor();
		return $rows > 0 ? ['match' => 'contains', 'rows' => $rows] : null;
	}
}
