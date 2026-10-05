<?php

declare(strict_types=1);

namespace OCA\UserRename\Handler\Apps;

use OCA\UserRename\Handler\ColumnRule;
use OCA\UserRename\Handler\IRenameHandler;
use OCA\UserRename\Service\RenameOptions;

class NotificationsHandler implements IRenameHandler {
	public function getName(): string {
		return 'notifications';
	}

	public function getRules(RenameOptions $options): array {
		return [
			new ColumnRule('notifications', 'user', ['notification_id']),
			new ColumnRule('notifications_pushhash', 'uid'),
			new ColumnRule('notifications_settings', 'user_id'),
			new ColumnRule('notifications_webpush', 'uid'),
		];
	}
}
