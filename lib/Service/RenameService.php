<?php

declare(strict_types=1);

namespace OCA\UserRename\Service;

use OCA\UserRename\Event\UserRenamedEvent;
use OCA\UserRename\Exception\PreflightException;
use OCA\UserRename\Exception\RenameFailedException;
use OCA\UserRename\Handler\ColumnRule;
use OCA\UserRename\Handler\HandlerRegistry;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class RenameService {
	public function __construct(
		private IDBConnection $db,
		private HandlerRegistry $registry,
		private RuleExecutor $executor,
		private Scanner $scanner,
		private Preflight $preflight,
		private FilesystemMover $filesystem,
		private ICacheFactory $cacheFactory,
		private IEventDispatcher $dispatcher,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Every rule whose table and columns exist in this installation.
	 *
	 * @return list<array{handler: string, rule: ColumnRule}>
	 */
	public function availableRules(RenameOptions $options): array {
		$rules = [];
		foreach ($this->registry->getHandlers() as $handler) {
			foreach ($handler->getRules($options) as $rule) {
				if ($this->executor->isAvailable($rule)) {
					$rules[] = ['handler' => $handler->getName(), 'rule' => $rule];
				}
			}
		}
		return $rules;
	}

	/**
	 * @return array<string, true>
	 */
	public function coveredColumns(RenameOptions $options): array {
		$covered = [];
		foreach ($this->availableRules($options) as ['rule' => $rule]) {
			$covered[$rule->table . '.' . $rule->column] = true;
		}
		// Rewritten implicitly through extraSet / always regenerated
		$covered['users.uid_lower'] = true;
		$covered['mounts.mount_point_hash'] = true;
		// Rewritten with --keep-tokens, deleted otherwise
		$covered['authtoken.login_name'] = true;
		return $covered;
	}

	/**
	 * Row counts per rule for $uid.
	 *
	 * @return list<array{handler: string, column: string, rows: int}>
	 */
	public function countReferences(string $uid, RenameOptions $options): array {
		$counts = [];
		foreach ($this->availableRules($options) as ['handler' => $handler, 'rule' => $rule]) {
			$rows = $this->executor->count($rule, $uid);
			if ($rows > 0) {
				$counts[] = ['handler' => $handler, 'column' => $rule->label(), 'rows' => $rows];
			}
		}
		return $counts;
	}

	/**
	 * Runs all checks without changing anything.
	 *
	 * @throws PreflightException
	 */
	public function plan(string $old, string $new, RenameOptions $options): RenameReport {
		$this->preflight->check($old, $new);
		$report = new RenameReport($old, $new);
		$report->changes = $this->countReferences($old, $options);
		$report->unknownReferences = $this->scanner->scan($old, $this->coveredColumns($options));

		// Leftovers from a deleted user that had the new name would be merged into this account.
		if (mb_strtolower($old) !== mb_strtolower($new)) {
			$report->conflicts = array_merge(
				$this->countReferences($new, $options),
				array_map(static fn (array $hit) => [
					'handler' => 'scan',
					'column' => $hit['table'] . '.' . $hit['column'] . ' (' . $hit['match'] . ')',
					'rows' => $hit['rows'],
				], $this->scanner->scan($new, $this->coveredColumns($options))),
			);
		}
		return $report;
	}

	/**
	 * The caller must have put the instance into maintenance mode.
	 *
	 * @throws PreflightException|RenameFailedException
	 */
	public function rename(string $old, string $new, RenameOptions $options): RenameReport {
		$report = $this->plan($old, $new, $options);
		if ($report->conflicts !== [] && !$options->force) {
			throw new PreflightException("Rows already reference \"$new\" (see the conflicts above); clean them up or pass --force");
		}
		if ($report->unknownReferences !== [] && $options->strict) {
			throw new PreflightException("Uncovered references to \"$old\" found and --strict is set");
		}
		if ($options->dryRun) {
			return $report;
		}

		$report->changes = [];
		$this->db->beginTransaction();
		try {
			foreach ($this->availableRules($options) as ['handler' => $handler, 'rule' => $rule]) {
				$rows = $this->executor->apply($rule, $old, $new);
				if ($rows > 0) {
					$report->changes[] = ['handler' => $handler, 'column' => $rule->label(), 'rows' => $rows];
				}
			}
			// Inside the transaction: if the move fails, the database rolls back.
			$this->filesystem->renameDataDirectory($old, $new);
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw new RenameFailedException('Rename failed, database rolled back: ' . $e->getMessage(), 0, $e);
		}

		try {
			$this->db->commit();
		} catch (\Throwable $e) {
			try {
				$this->db->rollBack();
			} catch (\Throwable) {
			}
			$reverted = $this->filesystem->revertDataDirectory($old, $new);
			throw new RenameFailedException('Commit failed: ' . $e->getMessage()
				. ($reverted ? '; data directory moved back' : '; COULD NOT move the data directory back, do it by hand'), 0, $e);
		}

		$this->afterCommit($old, $new, $report);
		return $report;
	}

	private function afterCommit(string $old, string $new, RenameReport $report): void {
		$warning = $this->filesystem->moveAvatar($old, $new);
		if ($warning !== null) {
			$report->warnings[] = $warning;
		}

		try {
			// Keys as used by OC\User\Manager and OC\User\DisplayNameCache
			$backendMap = $this->cacheFactory->createDistributed('user_backend_map');
			$backendMap->remove(sha1($old));
			$backendMap->remove(sha1($new));
			$displayNames = $this->cacheFactory->createDistributed('displayNameMappingCache');
			$displayNames->remove($old);
			$displayNames->remove($new);
		} catch (\Throwable $e) {
			$report->warnings[] = 'Could not clear caches: ' . $e->getMessage();
		}

		try {
			$this->dispatcher->dispatchTyped(new UserRenamedEvent($old, $new));
		} catch (\Throwable $e) {
			$this->logger->error('UserRenamedEvent listener failed', ['exception' => $e]);
			$report->warnings[] = 'A UserRenamedEvent listener failed: ' . $e->getMessage();
		}

		$report->unknownReferences = $this->scanner->scan($old, $this->coveredColumns(new RenameOptions()));
		$this->logger->warning("User \"$old\" was renamed to \"$new\" by occ user:rename", ['app' => 'user_rename']);
	}
}
