<?php

declare(strict_types=1);

namespace OCA\UserRename\Handler;

/**
 * All handlers, in the order they run. users comes first so a failure there
 * aborts before anything else is touched.
 */
class HandlerRegistry {
	/** @var list<IRenameHandler> */
	private array $handlers;

	public function __construct(
		Core\UsersHandler $users,
		Core\GroupsHandler $groups,
		Core\PreferencesHandler $preferences,
		Core\AccountsHandler $accounts,
		Core\AuthTokensHandler $authTokens,
		Core\SharesHandler $shares,
		Core\StoragesHandler $storages,
		Core\MountsHandler $mounts,
		Core\PropertiesHandler $properties,
		Core\CommentsHandler $comments,
		Core\TwoFactorHandler $twoFactor,
		Core\MiscHandler $misc,
		Apps\DavHandler $dav,
		Apps\FilesHandler $files,
		Apps\SharingHandler $sharing,
		Apps\MiscAppsHandler $miscApps,
		Apps\ActivityHandler $activity,
		Apps\NotificationsHandler $notifications,
		Apps\CirclesHandler $circles,
	) {
		$this->handlers = func_get_args();
	}

	/**
	 * @return list<IRenameHandler>
	 */
	public function getHandlers(): array {
		return $this->handlers;
	}
}
