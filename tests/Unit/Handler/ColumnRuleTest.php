<?php

declare(strict_types=1);

namespace OCA\UserRename\Tests\Unit\Handler;

use OCA\UserRename\Handler\ColumnRule;
use OCA\UserRename\Handler\Core\StoragesHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ColumnRuleTest extends TestCase {
	public static function eqProvider(): array {
		return [
			'exact' => ['alice', 'alicia'],
			'other case' => ['Alice', null],
			'longer' => ['alice2', null],
			'null' => [null, null],
		];
	}

	#[DataProvider('eqProvider')]
	public function testEq(?string $value, ?string $expected): void {
		$rule = new ColumnRule('t', 'c');
		$this->assertSame($expected, $rule->rewrite($value, 'alice', 'alicia'));
	}

	public function testDeleteMatchesLikeEq(): void {
		$rule = new ColumnRule('t', 'c', mode: ColumnRule::DELETE);
		$this->assertNotNull($rule->rewrite('alice', 'alice', 'alicia'));
		$this->assertNull($rule->rewrite('ALICE', 'alice', 'alicia'));
	}

	public static function prefixProvider(): array {
		return [
			'exact' => ['principals/users/bob', 'principals/users/robert'],
			'child' => ['principals/users/bob/calendar-proxy-read', 'principals/users/robert/calendar-proxy-read'],
			'longer uid' => ['principals/users/bobby', null],
			'longer uid child' => ['principals/users/bobby/x', null],
			'other prefix' => ['principals/groups/bob', null],
			'other case' => ['principals/users/Bob', null],
		];
	}

	#[DataProvider('prefixProvider')]
	public function testPrefix(string $value, ?string $expected): void {
		$rule = new ColumnRule('t', 'c', mode: ColumnRule::PREFIX, prefixes: ['principals/users/']);
		$this->assertSame($expected, $rule->rewrite($value, 'bob', 'robert'));
	}

	public function testPrefixMountPoint(): void {
		$rule = new ColumnRule('mounts', 'mount_point', mode: ColumnRule::PREFIX, prefixes: ['/']);
		$this->assertSame('/robert/', $rule->rewrite('/bob/', 'bob', 'robert'));
		$this->assertSame('/robert/files/Shared/', $rule->rewrite('/bob/files/Shared/', 'bob', 'robert'));
		$this->assertNull($rule->rewrite('/bobby/', 'bob', 'robert'));
	}

	public function testPrefixWithSeparator(): void {
		$rule = new ColumnRule('circles_circle', 'name', mode: ColumnRule::PREFIX, prefixes: ['user:'], separator: ':');
		$this->assertSame('user:robert:abc123', $rule->rewrite('user:bob:abc123', 'bob', 'robert'));
		$this->assertNull($rule->rewrite('user:bobby:abc123', 'bob', 'robert'));
		$this->assertNull($rule->rewrite('group:bob', 'bob', 'robert'));
	}

	public function testEncodedStorageId(): void {
		$rule = new ColumnRule('storages', 'id', ['numeric_id'],
			encode: static fn (string $uid): string => StoragesHandler::adjustStorageId('home::' . $uid));
		$this->assertSame('home::robert', $rule->rewrite('home::bob', 'bob', 'robert'));
		$this->assertNull($rule->rewrite('bob', 'bob', 'robert'));
	}

	public function testLongStorageIdIsHashed(): void {
		$long = str_repeat('a', 60);
		$this->assertSame(md5('home::' . $long), StoragesHandler::adjustStorageId('home::' . $long));
		$this->assertSame('home::bob', StoragesHandler::adjustStorageId('home::bob'));
	}

	public function testJsonNamedKeys(): void {
		$keys = ['uid', 'user'];
		$this->assertSame('{"uid":"robert","n":1}', ColumnRule::rewriteJson('{"uid":"bob","n":1}', 'bob', 'robert', $keys));
		$this->assertSame('{"a":{"user":"robert"}}', ColumnRule::rewriteJson('{"a":{"user":"bob"}}', 'bob', 'robert', $keys));
		$this->assertNull(ColumnRule::rewriteJson('{"other":"bob"}', 'bob', 'robert', $keys));
		$this->assertNull(ColumnRule::rewriteJson('{"uid":"Bob"}', 'bob', 'robert', $keys));
		$this->assertNull(ColumnRule::rewriteJson('not json', 'bob', 'robert', $keys));
	}

	public function testJsonBareString(): void {
		$this->assertSame('"robert"', ColumnRule::rewriteJson('"bob"', 'bob', 'robert', []));
	}

	public function testJsonPositionalOnlyTopLevel(): void {
		$this->assertSame('["robert",91]', ColumnRule::rewriteJson('["bob",91]', 'bob', 'robert', [0]));
		$this->assertNull(ColumnRule::rewriteJson('["x",["bob"]]', 'bob', 'robert', [0]));
		$this->assertNull(ColumnRule::rewriteJson('[91,"bob"]', 'bob', 'robert', [0]));
	}

	public function testJsonSerializedCommand(): void {
		$class = 'OCA\Files_Versions\Command\Expire';
		$serialized = 'O:' . strlen($class) . ':"' . $class . '":2:{'
			. 's:' . strlen("\0$class\0user") . ":\"\0$class\0user\";s:3:\"bob\";"
			. 's:' . strlen("\0$class\0fileName") . ":\"\0$class\0fileName\";s:8:\"/bob.txt\";}";
		$json = json_encode($serialized);

		$result = ColumnRule::rewriteJson($json, 'bob', 'robert', ['user', 'userId']);
		$this->assertNotNull($result);
		$decoded = json_decode($result);
		$this->assertStringContainsString("\0user\";s:6:\"robert\";", $decoded);
		// fileName is not a uid property and keeps its value
		$this->assertStringContainsString('s:8:"/bob.txt";', $decoded);
		// still valid serialized data (checked without instantiating the class)
		$this->assertIsObject(unserialize($decoded, ['allowed_classes' => false]));

		$this->assertNull(ColumnRule::rewriteJson($json, 'bo', 'x', ['user']));
	}

	public function testLabel(): void {
		$rule = new ColumnRule('share', 'share_with', where: ['share_type' => [0, 2]]);
		$this->assertSame('share.share_with [share_type=0,2]', $rule->label());
	}
}
