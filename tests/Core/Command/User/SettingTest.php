<?php

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */

namespace Tests\Core\Command\User;

use InvalidArgumentException;
use OC\Core\Command\User\Setting;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Test\TestCase;

class SettingTest extends TestCase {
	protected IUserManager&MockObject $userManager;
	protected IConfig&MockObject $config;
	protected IDBConnection&MockObject $connection;
	protected InputInterface&MockObject $consoleInput;
	protected MockObject&OutputInterface $consoleOutput;

	protected function setUp(): void {
		parent::setUp();

		$this->userManager = $this->createMock(IUserManager::class);
		$this->config = $this->createMock(IConfig::class);
		$this->connection = $this->createMock(IDBConnection::class);
		$this->consoleInput = $this->createMock(InputInterface::class);
		$this->consoleOutput = $this->createMock(OutputInterface::class);
	}

	public function getCommand(array $methods = []) {
		if (empty($methods)) {
			return new Setting($this->userManager, $this->config);
		} else {
			$mock = $this->getMockBuilder(Setting::class)
				->setConstructorArgs([
					$this->userManager,
					$this->config,
				])
				->onlyMethods($methods)
				->getMock();
			return $mock;
		}
	}

	public static function dataCheckInput(): array {
		return [
			[
				[['uid', 'username']],
				[['ignore-missing-user', true]],
				[],
				false,
				false,
			],
			[
				[['uid', 'username']],
				[['ignore-missing-user', false]],
				[],
				null,
				'The user "username" does not exist.',
			],

			[
				[['uid', 'username'], ['key', 'configkey']],
				[['ignore-missing-user', true]],
				[['--default-value', false, true]],
				false,
				false,
			],
			[
				[['uid', 'username'], ['key', '']],
				[['ignore-missing-user', true]],
				[['--default-value', false, true]],
				false,
				'The "default-value" option can only be used when specifying a key.',
			],

			[
				[['uid', 'username'], ['key', 'configkey'], ['value', '']],
				[['ignore-missing-user', true]],
				[],
				false,
				false,
			],
			[
				[['uid', 'username'], ['key', ''], ['value', '']],
				[['ignore-missing-user', true]],
				[],
				false,
				'The value argument can only be used when specifying a key.',
			],
			[
				[['uid', 'username'], ['key', 'configkey'], ['value', '']],
				[['ignore-missing-user', true]],
				[['--default-value', false, true]],
				false,
				'The value argument can not be used together with "default-value".',
			],
			[
				[['uid', 'username'], ['key', 'configkey'], ['value', '']],
				[['ignore-missing-user', true], ['update-only', true]],
				[],
				false,
				false,
			],
			[
				[['uid', 'username'], ['key', 'configkey'], ['value', null]],
				[['ignore-missing-user', true], ['update-only', true]],
				[],
				false,
				'The "update-only" option can only be used together with "value".',
			],

			[
				[['uid', 'username'], ['key', 'configkey']],
				[['ignore-missing-user', true], ['delete', true]],
				[],
				false,
				false,
			],
			[
				[['uid', 'username'], ['key', '']],
				[['ignore-missing-user', true], ['delete', true]],
				[],
				false,
				'The "delete" option can only be used when specifying a key.',
			],
			[
				[['uid', 'username'], ['key', 'configkey']],
				[['ignore-missing-user', true], ['delete', true]],
				[['--default-value', false, true]],
				false,
				'The "delete" option can not be used together with "default-value".',
			],
			[
				[['uid', 'username'], ['key', 'configkey'], ['value', '']],
				[['ignore-missing-user', true], ['delete', true]],
				[],
				false,
				'The "delete" option can not be used together with "value".',
			],
			[
				[['uid', 'username'], ['key', 'configkey']],
				[['ignore-missing-user', true], ['delete', true], ['error-if-not-exists', true]],
				[],
				false,
				false,
			],
			[
				[['uid', 'username'], ['key', 'configkey']],
				[['ignore-missing-user', true], ['delete', false], ['error-if-not-exists', true]],
				[],
				false,
				'The "error-if-not-exists" option can only be used together with "delete".',
			],
		];
	}

	/**
	 *
	 * @param array $arguments
	 * @param array $options
	 * @param array $parameterOptions
	 * @param mixed $user
	 * @param string $expectedException
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('dataCheckInput')]
	public function testCheckInput($arguments, $options, $parameterOptions, $user, $expectedException): void {
		$this->consoleInput->expects($this->any())
			->method('getArgument')
			->willReturnMap($arguments);
		$this->consoleInput->expects($this->any())
			->method('getOption')
			->willReturnMap($options);
		$this->consoleInput->expects($this->any())
			->method('hasParameterOption')
			->willReturnCallback(function (string|array $config, bool $default = false) use ($parameterOptions): bool {
				foreach ($parameterOptions as $parameterOption) {
					if ($config === $parameterOption[0]
						// Check the default value if the maps has 3 entries
						&& (!isset($parameterOption[2]) || $default === $parameterOption[1])) {
						return end($parameterOption);
					}
				}
				return false;
			});

		if ($user !== false) {
			$this->userManager->expects($this->once())
				->method('get')
				->willReturn($user);
		} else {
			$this->userManager->expects($this->never())
				->method('get');
		}

		$command = $this->getCommand();
		try {
			$this->invokePrivate($command, 'checkInput', [$this->consoleInput]);
			$this->assertFalse($expectedException);
		} catch (InvalidArgumentException $e) {
			$this->assertEquals($expectedException, $e->getMessage());
		}
	}

	public function testCheckInputExceptionCatch(): void {
		$command = $this->getCommand(['checkInput']);
		$command->expects($this->once())
			->method('checkInput')
			->willThrowException(new InvalidArgumentException('test'));

		$this->consoleOutput->expects($this->once())
			->method('writeln')
			->with('<error>test</error>');

		$this->assertEquals(1, $this->invokePrivate($command, 'execute', [$this->consoleInput, $this->consoleOutput]));
	}

	public static function dataExecuteDelete(): array {
		return [
			['config', false, null, 0],
			['config', true, null, 0],
			[null, false, null, 0],
			[null, true, '<error>The setting does not exist for user "username".</error>', 1],
		];
	}

	/**
	 *
	 * @param string|null $value
	 * @param bool $errorIfNotExists
	 * @param string $expectedLine
	 * @param int $expectedReturn
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('dataExecuteDelete')]
	public function testExecuteDelete($value, $errorIfNotExists, $expectedLine, $expectedReturn): void {
		$command = $this->getCommand([
			'writeArrayInOutputFormat',
			'checkInput',
			'getUserSettings',
		]);

		$this->consoleInput->expects($this->any())
			->method('getArgument')
			->willReturnMap([
				['uid', 'username'],
				['app', 'appname'],
				['key', 'configkey'],
			]);

		$command->expects($this->once())
			->method('checkInput');

		$this->config->expects($this->once())
			->method('getUserValue')
			->with('username', 'appname', 'configkey', null)
			->willReturn($value);

		$this->consoleInput->expects($this->atLeastOnce())
			->method('hasParameterOption')
			->willReturnMap([
				['--delete', false, true],
				['--error-if-not-exists', false, $errorIfNotExists],
			]);

		if ($expectedLine === null) {
			$this->consoleOutput->expects($this->never())
				->method('writeln');
			$this->config->expects($this->once())
				->method('deleteUserValue')
				->with('username', 'appname', 'configkey');
		} else {
			$this->consoleOutput->expects($this->once())
				->method('writeln')
				->with($expectedLine);
			$this->config->expects($this->never())
				->method('deleteUserValue');
		}

		$this->assertEquals($expectedReturn, $this->invokePrivate($command, 'execute', [$this->consoleInput, $this->consoleOutput]));
	}

	public static function dataExecuteSet(): array {
		return [
			['config', false, null, 0],
			['config', true, null, 0],
			[null, false, null, 0],
			[null, true, '<error>The setting does not exist for user "username".</error>', 1],
		];
	}

	/**
	 *
	 * @param string|null $value
	 * @param bool $updateOnly
	 * @param string $expectedLine
	 * @param int $expectedReturn
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('dataExecuteSet')]
	public function testExecuteSet($value, $updateOnly, $expectedLine, $expectedReturn): void {
		$command = $this->getCommand([
			'writeArrayInOutputFormat',
			'checkInput',
			'getUserSettings',
		]);

		$this->consoleInput->expects($this->atLeast(4))
			->method('getArgument')
			->willReturnMap([
				['uid', 'username'],
				['app', 'appname'],
				['key', 'configkey'],
				['value', 'setValue'],
			]);

		$command->expects($this->once())
			->method('checkInput');

		$this->config->expects($this->once())
			->method('getUserValue')
			->with('username', 'appname', 'configkey', null)
			->willReturn($value);

		$this->consoleInput->expects($this->atLeastOnce())
			->method('hasParameterOption')
			->willReturnMap([
				['--update-only', false, $updateOnly],
			]);

		if ($expectedLine === null) {
			$this->consoleOutput->expects($this->never())
				->method('writeln');

			$this->consoleInput->expects($this->never())
				->method('getOption');

			$this->config->expects($this->once())
				->method('setUserValue')
				->with('username', 'appname', 'configkey', 'setValue');
		} else {
			$this->consoleOutput->expects($this->once())
				->method('writeln')
				->with($expectedLine);
			$this->config->expects($this->never())
				->method('setUserValue');
		}

		$this->assertEquals($expectedReturn, $this->invokePrivate($command, 'execute', [$this->consoleInput, $this->consoleOutput]));
	}

	public static function dataExecuteGet(): array {
		return [
			['config', null, 'config', 0],
			[null, 'config', 'config', 0],
			[null, null, '<error>The setting does not exist for user "username".</error>', 1],
		];
	}

	/**
	 *
	 * @param string|null $value
	 * @param string|null $defaultValue
	 * @param string $expectedLine
	 * @param int $expectedReturn
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('dataExecuteGet')]
	public function testExecuteGet($value, $defaultValue, $expectedLine, $expectedReturn): void {
		$command = $this->getCommand([
			'writeArrayInOutputFormat',
			'checkInput',
			'getUserSettings',
		]);

		$this->consoleInput->expects($this->any())
			->method('getArgument')
			->willReturnMap([
				['uid', 'username'],
				['app', 'appname'],
				['key', 'configkey'],
			]);

		$command->expects($this->once())
			->method('checkInput');

		$this->config->expects($this->once())
			->method('getUserValue')
			->with('username', 'appname', 'configkey', null)
			->willReturn($value);

		if ($value === null) {
			if ($defaultValue === null) {
				$this->consoleInput->expects($this->atLeastOnce())
					->method('hasParameterOption')
					->willReturn(false);
			} else {
				$this->consoleInput->expects($this->atLeastOnce())
					->method('hasParameterOption')
					->willReturnCallback(function (string|array $config, bool $default = false): bool {
						if ($config === '--default-value' && $default === false) {
							return true;
						}
						return false;
					});
				$this->consoleInput->expects($this->once())
					->method('getOption')
					->with('default-value')
					->willReturn($defaultValue);
			}
		}

		$this->consoleOutput->expects($this->once())
			->method('writeln')
			->with($expectedLine);

		$this->assertEquals($expectedReturn, $this->invokePrivate($command, 'execute', [$this->consoleInput, $this->consoleOutput]));
	}

	public function testExecuteList(): void {
		$command = $this->getCommand([
			'writeArrayInOutputFormat',
			'checkInput',
			'getUserSettings',
		]);

		$this->consoleInput->expects($this->any())
			->method('getArgument')
			->willReturnMap([
				['uid', 'username'],
				['app', 'appname'],
				['key', ''],
			]);

		$command->expects($this->once())
			->method('checkInput');
		$command->expects($this->once())
			->method('getUserSettings')
			->willReturn(['settings']);
		$command->expects($this->once())
			->method('writeArrayInOutputFormat')
			->with($this->consoleInput, $this->consoleOutput, ['settings']);


		$this->assertEquals(0, $this->invokePrivate($command, 'execute', [$this->consoleInput, $this->consoleOutput]));
	}
}
