<?php

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
use Behat\Behat\Context\Context;
use Behat\Behat\Context\SnippetAcceptingContext;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * Features context.
 */
class FeatureContext implements Context, SnippetAcceptingContext {
	use AppConfiguration;
	use ContactsMenu;
	use ExternalStorage;
	use Search;
	use WebDav;
	use Trashbin;

	protected function resetAppConfigs(): void {
		$this->deleteServerConfig('bruteForce', 'whitelist_0');
		$this->deleteServerConfig('bruteForce', 'whitelist_1');
		$this->deleteServerConfig('bruteforcesettings', 'apply_allowlist_to_ratelimit');
	}
}
