<?php

declare(strict_types=1);

namespace OCA\UserRename\Handler\Apps;

use OCA\UserRename\Handler\ColumnRule;
use OCA\UserRename\Handler\IRenameHandler;
use OCA\UserRename\Service\RenameOptions;

/**
 * calendarobjects.uid, cards.uid etc. are iCal/vCard UIDs and are not touched.
 * The system address book is refreshed after the rename.
 */
class DavHandler implements IRenameHandler {
	private const PRINCIPAL_PREFIX = 'principals/users/';

	public function getName(): string {
		return 'dav';
	}

	public function getRules(RenameOptions $options): array {
		$rules = [];
		foreach (['calendars', 'addressbooks', 'calendarsubscriptions', 'schedulingobjects', 'dav_shares', 'calendars_federated'] as $table) {
			$rules[] = new ColumnRule($table, 'principaluri', mode: ColumnRule::PREFIX, prefixes: [self::PRINCIPAL_PREFIX]);
		}
		$rules[] = new ColumnRule('dav_cal_proxy', 'owner_id', mode: ColumnRule::PREFIX, prefixes: [self::PRINCIPAL_PREFIX]);
		$rules[] = new ColumnRule('dav_cal_proxy', 'proxy_id', mode: ColumnRule::PREFIX, prefixes: [self::PRINCIPAL_PREFIX]);
		$rules[] = new ColumnRule('directlink', 'user_id');
		$rules[] = new ColumnRule('dav_absence', 'user_id');
		$rules[] = new ColumnRule('dav_absence', 'replacement_user_id');
		return $rules;
	}
}
