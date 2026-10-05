<?php

declare(strict_types=1);

namespace OCA\UserRename\Handler\Apps;

use OCA\UserRename\Handler\ColumnRule;
use OCA\UserRename\Handler\IRenameHandler;
use OCA\UserRename\Service\RenameOptions;

/**
 * The activity app ships separately. If its primary keys ever differ, the
 * rules are skipped and the scanner reports the columns instead.
 */
class ActivityHandler implements IRenameHandler {
	public function getName(): string {
		return 'activity';
	}

	public function getRules(RenameOptions $options): array {
		return [
			new ColumnRule('activity', 'user', ['activity_id']),
			new ColumnRule('activity', 'affecteduser', ['activity_id']),
			new ColumnRule('activity_mq', 'amq_affecteduser', ['mail_id']),
		];
	}
}
