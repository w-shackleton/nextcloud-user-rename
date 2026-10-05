<?php

declare(strict_types=1);

namespace OCA\UserRename\Handler\Apps;

use OCA\UserRename\Handler\ColumnRule;
use OCA\UserRename\Handler\IRenameHandler;
use OCA\UserRename\Service\RenameOptions;

class MiscAppsHandler implements IRenameHandler {
	public function getName(): string {
		return 'misc apps';
	}

	public function getRules(RenameOptions $options): array {
		return [
			new ColumnRule('user_status', 'user_id'),
			new ColumnRule('recent_contact', 'actor_uid'),
			new ColumnRule('webhook_listeners', 'user_id'),
			new ColumnRule('webhook_listeners', 'user_id_filter'),
			new ColumnRule('webhook_tokens', 'user_id'),
			new ColumnRule('federated_invites', 'user_id'),
			// twofactor_totp: without this the user silently loses TOTP
			new ColumnRule('twofactor_totp_secrets', 'user_id'),
			new ColumnRule('photos_albums', 'user', ['album_id']),
			new ColumnRule('photos_albums_files', 'owner', ['album_file_id']),
			// files_lock: user_id is the uid for ILock::TYPE_USER locks; owner is a display name
			new ColumnRule('files_lock', 'user_id', where: ['type' => 0]),
			// text: collaborative editing sessions are recreated on demand
			new ColumnRule('text_sessions', 'user_id', mode: ColumnRule::DELETE),
		];
	}
}
