<?php

declare(strict_types=1);

namespace OCA\UserRename\Handler\Core;

use OCA\UserRename\Handler\ColumnRule;
use OCA\UserRename\Handler\IRenameHandler;
use OCA\UserRename\Service\RenameOptions;

/**
 * Sessions and app passwords. Deleted by default: sync clients embed the uid
 * in their WebDAV URL and must re-add the account anyway.
 */
class AuthTokensHandler implements IRenameHandler {
	public function getName(): string {
		return 'auth tokens';
	}

	public function getRules(RenameOptions $options): array {
		$rules = [
			new ColumnRule('login_flow_v2', 'login_name', mode: ColumnRule::DELETE),
		];
		if ($options->keepTokens) {
			// The token's private key is encrypted with token + secret, not the
			// uid, so app passwords stay valid after rewriting these columns.
			$rules[] = new ColumnRule('authtoken', 'uid');
			$rules[] = new ColumnRule('authtoken', 'login_name');
		} else {
			$rules[] = new ColumnRule('authtoken', 'uid', mode: ColumnRule::DELETE);
		}
		return $rules;
	}
}
