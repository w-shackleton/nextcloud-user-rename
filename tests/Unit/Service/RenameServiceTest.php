<?php

declare(strict_types=1);

namespace OCA\UserRename\Tests\Unit\Service;

use OCA\UserRename\Exception\PreflightException;
use OCA\UserRename\Exception\RenameFailedException;
use OCA\UserRename\Handler\Apps;
use OCA\UserRename\Handler\Core;
use OCA\UserRename\Handler\HandlerRegistry;
use OCA\UserRename\Service\FilesystemMover;
use OCA\UserRename\Service\Preflight;
use OCA\UserRename\Service\RenameOptions;
use OCA\UserRename\Service\RenameService;
use OCA\UserRename\Service\RuleExecutor;
use OCA\UserRename\Service\Scanner;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RenameServiceTest extends TestCase {
	private IDBConnection&MockObject $db;
	private RuleExecutor&MockObject $executor;
	private Scanner&MockObject $scanner;
	private Preflight&MockObject $preflight;
	private FilesystemMover&MockObject $filesystem;
	private IEventDispatcher&MockObject $dispatcher;
	private RenameService $service;

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->executor = $this->createMock(RuleExecutor::class);
		$this->scanner = $this->createMock(Scanner::class);
		$this->preflight = $this->createMock(Preflight::class);
		$this->filesystem = $this->createMock(FilesystemMover::class);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

		$this->executor->method('isAvailable')->willReturn(true);
		$this->executor->method('count')->willReturnCallback(static fn ($rule, string $uid) => $uid === 'alice' ? 1 : 0);
		$this->executor->method('apply')->willReturn(1);
		$this->scanner->method('scan')->willReturn([]);
		$this->preflight->method('check')->willReturn($this->createMock(IUser::class));

		$this->service = new RenameService(
			$this->db,
			self::registry(),
			$this->executor,
			$this->scanner,
			$this->preflight,
			$this->filesystem,
			$cacheFactory,
			$this->dispatcher,
			$this->createMock(LoggerInterface::class),
		);
	}

	private static function registry(): HandlerRegistry {
		return new HandlerRegistry(
			new Core\UsersHandler(), new Core\GroupsHandler(), new Core\PreferencesHandler(),
			new Core\AccountsHandler(), new Core\AuthTokensHandler(), new Core\SharesHandler(),
			new Core\StoragesHandler(), new Core\MountsHandler(), new Core\PropertiesHandler(),
			new Core\CommentsHandler(), new Core\TwoFactorHandler(), new Core\MiscHandler(),
			new Apps\DavHandler(), new Apps\FilesHandler(), new Apps\SharingHandler(),
			new Apps\MiscAppsHandler(), new Apps\ActivityHandler(), new Apps\NotificationsHandler(),
			new Apps\CirclesHandler(),
		);
	}

	public function testSuccessfulRenameCommitsAfterMovingDirectory(): void {
		$calls = [];
		$this->db->method('beginTransaction')->willReturnCallback(function () use (&$calls) { $calls[] = 'begin'; });
		$this->filesystem->method('renameDataDirectory')->willReturnCallback(function () use (&$calls) { $calls[] = 'move'; });
		$this->db->method('commit')->willReturnCallback(function () use (&$calls) { $calls[] = 'commit'; });
		$this->db->expects($this->never())->method('rollBack');
		$this->filesystem->expects($this->once())->method('moveAvatar')->with('alice', 'alicia');
		$this->dispatcher->expects($this->once())->method('dispatchTyped');

		$report = $this->service->rename('alice', 'alicia', new RenameOptions());

		$this->assertSame(['begin', 'move', 'commit'], $calls);
		$this->assertNotEmpty($report->changes);
	}

	public function testFailedDirectoryMoveRollsBackDatabase(): void {
		$this->db->expects($this->once())->method('beginTransaction');
		$this->filesystem->method('renameDataDirectory')->willThrowException(new RenameFailedException('disk says no'));
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->filesystem->expects($this->never())->method('moveAvatar');

		$this->expectException(RenameFailedException::class);
		$this->expectExceptionMessage('database rolled back');
		$this->service->rename('alice', 'alicia', new RenameOptions());
	}

	public function testFailedHandlerRollsBackWithoutMovingDirectory(): void {
		$failing = $this->createMock(RuleExecutor::class);
		$failing->method('isAvailable')->willReturn(true);
		$failing->method('count')->willReturn(0);
		$failing->method('apply')->willThrowException(new \RuntimeException('constraint violation'));
		$service = $this->serviceWith($failing);

		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->filesystem->expects($this->never())->method('renameDataDirectory');

		$this->expectException(RenameFailedException::class);
		$service->rename('alice', 'alicia', new RenameOptions());
	}

	public function testFailedCommitMovesDirectoryBack(): void {
		$this->db->method('commit')->willThrowException(new \RuntimeException('deadlock'));
		$this->filesystem->expects($this->once())->method('revertDataDirectory')->with('alice', 'alicia')->willReturn(true);

		$this->expectException(RenameFailedException::class);
		$this->expectExceptionMessage('data directory moved back');
		$this->service->rename('alice', 'alicia', new RenameOptions());
	}

	public function testConflictsAbortWithoutForce(): void {
		$conflicting = $this->createMock(RuleExecutor::class);
		$conflicting->method('isAvailable')->willReturn(true);
		$conflicting->method('count')->willReturn(1); // both old and new have rows
		$service = $this->serviceWith($conflicting);

		$this->db->expects($this->never())->method('beginTransaction');
		$this->expectException(PreflightException::class);
		$service->rename('alice', 'alicia', new RenameOptions());
	}

	public function testDryRunChangesNothing(): void {
		$this->db->expects($this->never())->method('beginTransaction');
		$this->filesystem->expects($this->never())->method('renameDataDirectory');
		$this->executor->expects($this->never())->method('apply');

		$report = $this->service->rename('alice', 'alicia', new RenameOptions(dryRun: true));
		$this->assertNotEmpty($report->changes);
	}

	private function serviceWith(RuleExecutor $executor): RenameService {
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));
		return new RenameService(
			$this->db, self::registry(), $executor, $this->scanner, $this->preflight,
			$this->filesystem, $cacheFactory, $this->dispatcher, $this->createMock(LoggerInterface::class),
		);
	}
}
