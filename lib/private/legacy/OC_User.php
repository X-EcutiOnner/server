<?php

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
use OC\Authentication\Token\IProvider;
use OC\User\DisabledUserException;
use OCP\Authentication\Exceptions\InvalidTokenException;
use OCP\Authentication\Exceptions\WipeTokenException;
use OCP\Authentication\Token\IToken;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use OCP\Session\Exceptions\SessionNotAvailableException;
use OCP\User\Events\BeforeUserLoggedInEvent;
use OCP\User\Events\UserLoggedInEvent;
use Psr\Log\LoggerInterface;

/**
 * This class provides wrapper methods for user management. Multiple backends are
 * supported. User management operations are delegated to the configured backend for
 * execution.
 *
 * Note that &run is deprecated and won't work anymore.
 *
 * Hooks provided:
 *   pre_createUser(&run, uid, password)
 *   post_createUser(uid, password)
 *   pre_deleteUser(&run, uid)
 *   post_deleteUser(uid)
 *   pre_setPassword(&run, uid, password, recoveryPassword)
 *   post_setPassword(uid, password, recoveryPassword)
 *   pre_login(&run, uid, password)
 *   post_login(uid)
 *   logout()
 */
class OC_User {
	private static $_setupedBackends = [];

	// bool, stores if a user want to access a resource anonymously, e.g if they open a public link
	private static $incognitoMode = false;

	/**
	 * Adds the backend to the list of used backends
	 *
	 * @param string|\OCP\UserInterface $backend default: database The backend to use for user management
	 * @return bool
	 * @deprecated 32.0.0 Use IUserManager::registerBackend instead
	 *
	 * Set the User Authentication Module
	 */
	public static function useBackend($backend = 'database') {
		if ($backend instanceof \OCP\UserInterface) {
			Server::get(IUserManager::class)->registerBackend($backend);
		} else {
			// You'll never know what happens
			if ($backend === null or !is_string($backend)) {
				$backend = 'database';
			}

			// Load backend
			switch ($backend) {
				case 'database':
				case 'mysql':
				case 'sqlite':
					Server::get(LoggerInterface::class)->debug('Adding user backend ' . $backend . '.', ['app' => 'core']);
					Server::get(IUserManager::class)->registerBackend(new \OC\User\Database());
					break;
				case 'dummy':
					Server::get(IUserManager::class)->registerBackend(new \Test\Util\User\Dummy());
					break;
				default:
					Server::get(LoggerInterface::class)->debug('Adding default user backend ' . $backend . '.', ['app' => 'core']);
					$className = 'OC_USER_' . strtoupper($backend);
					Server::get(IUserManager::class)->registerBackend(new $className());
					break;
			}
		}
		return true;
	}

	/**
	 * remove all used backends
	 * @deprecated 32.0.0 Use IUserManager::clearBackends instead
	 */
	public static function clearBackends() {
		Server::get(IUserManager::class)->clearBackends();
	}

	/**
	 * setup the configured backends in config.php
	 * @suppress PhanDeprecatedFunction
	 */
	public static function setupBackends() {
		OC_App::loadApps(['prelogin']);
		$backends = \OC::$server->getSystemConfig()->getValue('user_backends', []);
		if (isset($backends['default']) && !$backends['default']) {
			// clear default backends
			self::clearBackends();
		}
		foreach ($backends as $i => $config) {
			if (!is_array($config)) {
				continue;
			}
			$class = $config['class'];
			$arguments = $config['arguments'];
			if (class_exists($class)) {
				if (!in_array($i, self::$_setupedBackends)) {
					// make a reflection object
					$reflectionObj = new ReflectionClass($class);

					// use Reflection to create a new instance, using the $args
					$backend = $reflectionObj->newInstanceArgs($arguments);
					self::useBackend($backend);
					self::$_setupedBackends[] = $i;
				} else {
					Server::get(LoggerInterface::class)->debug('User backend ' . $class . ' already initialized.', ['app' => 'core']);
				}
			} else {
				Server::get(LoggerInterface::class)->error('User backend ' . $class . ' not found.', ['app' => 'core']);
			}
		}
	}

	/**
	 * Try to login a user, assuming authentication
	 * has already happened (e.g. via Single Sign On).
	 *
	 * Log in a user and regenerate a new session.
	 *
	 * @param \OCP\Authentication\IApacheBackend $backend
	 * @return bool
	 */
	public static function loginWithApache(\OCP\Authentication\IApacheBackend $backend) {
		$uid = $backend->getCurrentUserId();
		$run = true;
		OC_Hook::emit('OC_User', 'pre_login', ['run' => &$run, 'uid' => $uid, 'backend' => $backend]);

		if ($uid) {
			if (self::getUser() !== $uid) {
				self::setUserId($uid);
				$userSession = \OC::$server->getUserSession();

				/** @var IEventDispatcher $dispatcher */
				$dispatcher = \OC::$server->get(IEventDispatcher::class);

				if ($userSession->getUser() && !$userSession->getUser()->isEnabled()) {
					$message = \OC::$server->getL10N('lib')->t('Account disabled');
					throw new DisabledUserException($message);
				}
				$userSession->setLoginName($uid);
				$request = OC::$server->getRequest();
				$password = null;
				if ($backend instanceof \OCP\Authentication\IProvideUserSecretBackend) {
					$password = $backend->getCurrentUserSecret();
				}

				/** @var IEventDispatcher $dispatcher */
				$dispatcher->dispatchTyped(new BeforeUserLoggedInEvent($uid, $password, $backend));

				$userSession->createSessionToken($request, $uid, $uid, $password);
				$userSession->createRememberMeToken($userSession->getUser());

				if (empty($password)) {
					$tokenProvider = \OC::$server->get(IProvider::class);
					try {
						$token = $tokenProvider->getToken($userSession->getSession()->getId());
						$token->setScope([
							IToken::SCOPE_SKIP_PASSWORD_VALIDATION => true,
							IToken::SCOPE_FILESYSTEM => true,
						]);
						$tokenProvider->updateToken($token);
					} catch (InvalidTokenException|WipeTokenException|SessionNotAvailableException) {
						// swallow the exceptions as we do not deal with them here
						// simply skip updating the token when is it missing
					}
				}

				// setup the filesystem
				OC_Util::setupFS($uid);
				// first call the post_login hooks, the login-process needs to be
				// completed before we can safely create the users folder.
				// For example encryption needs to initialize the users keys first
				// before we can create the user folder with the skeleton files
				OC_Hook::emit(
					'OC_User',
					'post_login',
					[
						'uid' => $uid,
						'password' => $password,
						'isTokenLogin' => false,
					]
				);
				$dispatcher->dispatchTyped(new UserLoggedInEvent(
					\OC::$server->get(IUserManager::class)->get($uid),
					$uid,
					null,
					false)
				);

				//trigger creation of user home and /files folder
				\OC::$server->getUserFolder($uid);
			}
			return true;
		}
		return false;
	}

	/**
	 * Verify with Apache whether user is authenticated.
	 *
	 * @return boolean|null
	 *                      true: authenticated
	 *                      false: not authenticated
	 *                      null: not handled / no backend available
	 */
	public static function handleApacheAuth() {
		$backend = self::findFirstActiveUsedBackend();
		if ($backend) {
			OC_App::loadApps();

			//setup extra user backends
			self::setupBackends();
			\OC::$server->getUserSession()->unsetMagicInCookie();

			return self::loginWithApache($backend);
		}

		return null;
	}


	/**
	 * Sets user id for session and triggers emit
	 *
	 * @param string $uid
	 */
	public static function setUserId($uid) {
		$userSession = \OC::$server->getUserSession();
		$userManager = Server::get(IUserManager::class);
		if ($user = $userManager->get($uid)) {
			$userSession->setUser($user);
		} else {
			\OC::$server->getSession()->set('user_id', $uid);
		}
	}

	/**
	 * Check if the user is logged in, considers also the HTTP basic credentials
	 *
	 * @deprecated 12.0.0 use \OC::$server->getUserSession()->isLoggedIn()
	 * @return bool
	 */
	public static function isLoggedIn() {
		return \OC::$server->getUserSession()->isLoggedIn();
	}

	/**
	 * set incognito mode, e.g. if a user wants to open a public link
	 *
	 * @param bool $status
	 */
	public static function setIncognitoMode($status) {
		self::$incognitoMode = $status;
	}

	/**
	 * get incognito mode status
	 *
	 * @return bool
	 */
	public static function isIncognitoMode() {
		return self::$incognitoMode;
	}

	/**
	 * Returns the current logout URL valid for the currently logged-in user
	 *
	 * @param \OCP\IURLGenerator $urlGenerator
	 * @return string
	 */
	public static function getLogoutUrl(\OCP\IURLGenerator $urlGenerator) {
		$backend = self::findFirstActiveUsedBackend();
		if ($backend) {
			return $backend->getLogoutUrl();
		}

		$user = \OC::$server->getUserSession()->getUser();
		if ($user instanceof IUser) {
			$backend = $user->getBackend();
			if ($backend instanceof \OCP\User\Backend\ICustomLogout) {
				return $backend->getLogoutUrl();
			}
		}

		$logoutUrl = $urlGenerator->linkToRoute('core.login.logout');
		$logoutUrl .= '?requesttoken=' . urlencode(\OCP\Util::callRegister());

		return $logoutUrl;
	}

	/**
	 * Check if the user is an admin user
	 *
	 * @param string $uid uid of the admin
	 * @return bool
	 */
	public static function isAdminUser($uid) {
		$user = Server::get(IUserManager::class)->get($uid);
		$isAdmin = $user && Server::get(IGroupManager::class)->isAdmin($user->getUID());
		return $isAdmin && self::$incognitoMode === false;
	}


	/**
	 * get the user id of the user currently logged in.
	 *
	 * @return string|false uid or false
	 */
	public static function getUser() {
		$uid = Server::get(ISession::class)?->get('user_id');
		if (!is_null($uid) && self::$incognitoMode === false) {
			return $uid;
		} else {
			return false;
		}
	}

	/**
	 * Set password
	 *
	 * @param string $uid The username
	 * @param string $password The new password
	 * @param string $recoveryPassword for the encryption app to reset encryption keys
	 * @return bool
	 *
	 * Change the password of a user
	 */
	public static function setPassword($uid, $password, $recoveryPassword = null) {
		$user = Server::get(IUserManager::class)->get($uid);
		if ($user) {
			return $user->setPassword($password, $recoveryPassword);
		} else {
			return false;
		}
	}

	/**
	 * @param string $uid The username
	 * @return string
	 *
	 * returns the path to the users home directory
	 * @deprecated 12.0.0 Use \OC::$server->getUserManager->getHome()
	 */
	public static function getHome($uid) {
		$user = Server::get(IUserManager::class)->get($uid);
		if ($user) {
			return $user->getHome();
		} else {
			return \OC::$server->getSystemConfig()->getValue('datadirectory', OC::$SERVERROOT . '/data') . '/' . $uid;
		}
	}

	/**
	 * Get a list of all users display name
	 *
	 * @param string $search
	 * @param int $limit
	 * @param int $offset
	 * @return array associative array with all display names (value) and corresponding uids (key)
	 *
	 * Get a list of all display names and user ids.
	 * @deprecated 12.0.0 Use \OC::$server->getUserManager->searchDisplayName($search, $limit, $offset) instead.
	 */
	public static function getDisplayNames($search = '', $limit = null, $offset = null) {
		$displayNames = [];
		$users = Server::get(IUserManager::class)->searchDisplayName($search, $limit, $offset);
		foreach ($users as $user) {
			$displayNames[$user->getUID()] = $user->getDisplayName();
		}
		return $displayNames;
	}

	/**
	 * Returns the first active backend from self::$_usedBackends.
	 *
	 * @return OCP\Authentication\IApacheBackend|null if no backend active, otherwise OCP\Authentication\IApacheBackend
	 */
	private static function findFirstActiveUsedBackend() {
		foreach (Server::get(IUserManager::class)->getBackends() as $backend) {
			if ($backend instanceof OCP\Authentication\IApacheBackend) {
				if ($backend->isSessionActive()) {
					return $backend;
				}
			}
		}

		return null;
	}
}
