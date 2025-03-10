<?php

define( 'NS_UNITTEST', 5600 );
define( 'NS_UNITTEST_TALK', 5601 );

use MediaWiki\Block\CompositeBlock;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Block\Restriction\NamespaceRestriction;
use MediaWiki\Block\Restriction\PageRestriction;
use MediaWiki\Block\SystemBlock;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentityValue;
use Wikimedia\TestingAccessWrapper;

/**
 * @group Database
 */
class UserTest extends MediaWikiTestCase {

	/** Constant for self::testIsBlockedFrom */
	const USER_TALK_PAGE = '<user talk page>';

	/**
	 * @var User
	 */
	protected $user;

	protected function setUp() : void {
		parent::setUp();

		$this->setMwGlobals( [
			'wgGroupPermissions' => [],
			'wgRevokePermissions' => [],
			'wgUseRCPatrol' => true,
		] );

		$this->setUpPermissionGlobals();

		$this->user = $this->getTestUser( [ 'unittesters' ] )->getUser();

		TestingAccessWrapper::newFromClass( User::class )->reservedUsernames = false;
	}

	protected function tearDown() : void {
		parent::tearDown();
		TestingAccessWrapper::newFromClass( User::class )->reservedUsernames = false;
	}

	private function setUpPermissionGlobals() {
		global $wgGroupPermissions, $wgRevokePermissions;

		# Data for regular $wgGroupPermissions test
		$wgGroupPermissions['unittesters'] = [
			'test' => true,
			'runtest' => true,
			'writetest' => false,
			'nukeworld' => false,
			'autoconfirmed' => false,
		];
		$wgGroupPermissions['testwriters'] = [
			'test' => true,
			'writetest' => true,
			'modifytest' => true,
			'autoconfirmed' => true,
		];

		# Data for regular $wgRevokePermissions test
		$wgRevokePermissions['formertesters'] = [
			'runtest' => true,
		];

		# For the options and watchlist tests
		$wgGroupPermissions['*'] = [
			'editmyoptions' => true,
			'editmywatchlist' => true,
			'viewmywatchlist' => true,
		];

		# For patrol tests
		$wgGroupPermissions['patroller'] = [
			'patrol' => true,
		];

		# For account creation when blocked test
		$wgGroupPermissions['accountcreator'] = [
			'createaccount' => true,
			'ipblock-exempt' => true
		];

		# For bot and ratelimit tests
		$wgGroupPermissions['bot'] = [
			'bot' => true,
			'noratelimit' => true,
		];
	}

	private function setSessionUser( User $user, WebRequest $request ) {
		$this->setMwGlobals( 'wgUser', $user );
		RequestContext::getMain()->setUser( $user );
		RequestContext::getMain()->setRequest( $request );
		TestingAccessWrapper::newFromObject( $user )->mRequest = $request;
		$request->getSession()->setUser( $user );
	}

	/**
	 * @covers User::getGroupPermissions
	 */
	public function testGroupPermissions() {
		$rights = User::getGroupPermissions( [ 'unittesters' ] );
		$this->assertContains( 'runtest', $rights );
		$this->assertNotContains( 'writetest', $rights );
		$this->assertNotContains( 'modifytest', $rights );
		$this->assertNotContains( 'nukeworld', $rights );

		$rights = User::getGroupPermissions( [ 'unittesters', 'testwriters' ] );
		$this->assertContains( 'runtest', $rights );
		$this->assertContains( 'writetest', $rights );
		$this->assertContains( 'modifytest', $rights );
		$this->assertNotContains( 'nukeworld', $rights );
	}

	/**
	 * @covers User::getGroupPermissions
	 */
	public function testRevokePermissions() {
		$rights = User::getGroupPermissions( [ 'unittesters', 'formertesters' ] );
		$this->assertNotContains( 'runtest', $rights );
		$this->assertNotContains( 'writetest', $rights );
		$this->assertNotContains( 'modifytest', $rights );
		$this->assertNotContains( 'nukeworld', $rights );
	}

	/**
	 * TODO: Remove. This is the same as PermissionManagerTest::testGetUserPermissions
	 * @covers User::getRights
	 */
	public function testUserPermissions() {
		$rights = $this->user->getRights();
		$this->assertContains( 'runtest', $rights );
		$this->assertNotContains( 'writetest', $rights );
		$this->assertNotContains( 'modifytest', $rights );
		$this->assertNotContains( 'nukeworld', $rights );
	}

	/**
	 * TODO: Remove. This is the same as PermissionManagerTest::testGetUserPermissionsHooks
	 * @covers User::getRights
	 */
	public function testUserGetRightsHooks() {
		$user = $this->getTestUser( [ 'unittesters', 'testwriters' ] )->getUser();
		$userWrapper = TestingAccessWrapper::newFromObject( $user );

		$rights = $user->getRights();
		$this->assertContains( 'test', $rights, 'sanity check' );
		$this->assertContains( 'runtest', $rights, 'sanity check' );
		$this->assertContains( 'writetest', $rights, 'sanity check' );
		$this->assertNotContains( 'nukeworld', $rights, 'sanity check' );

		// Add a hook manipluating the rights
		$this->setTemporaryHook( 'UserGetRights', function ( $user, &$rights ) {
			$rights[] = 'nukeworld';
			$rights = array_diff( $rights, [ 'writetest' ] );
		} );

		$rights = $user->getRights();
		$this->assertContains( 'test', $rights );
		$this->assertContains( 'runtest', $rights );
		$this->assertNotContains( 'writetest', $rights );
		$this->assertContains( 'nukeworld', $rights );

		// Add a Session that limits rights
		$mock = $this->getMockBuilder( stdClass::class )
			->setMethods( [ 'getAllowedUserRights', 'deregisterSession', 'getSessionId' ] )
			->getMock();
		$mock->method( 'getAllowedUserRights' )->willReturn( [ 'test', 'writetest' ] );
		$mock->method( 'getSessionId' )->willReturn(
			new MediaWiki\Session\SessionId( str_repeat( 'X', 32 ) )
		);
		$session = MediaWiki\Session\TestUtils::getDummySession( $mock );
		$mockRequest = $this->getMockBuilder( FauxRequest::class )
			->setMethods( [ 'getSession' ] )
			->getMock();
		$mockRequest->method( 'getSession' )->willReturn( $session );
		$userWrapper->mRequest = $mockRequest;

		$this->resetServices();
		$rights = $user->getRights();
		$this->assertContains( 'test', $rights );
		$this->assertNotContains( 'runtest', $rights );
		$this->assertNotContains( 'writetest', $rights );
		$this->assertNotContains( 'nukeworld', $rights );
	}

	/**
	 * @dataProvider provideGetGroupsWithPermission
	 * @covers User::getGroupsWithPermission
	 */
	public function testGetGroupsWithPermission( $expected, $right ) {
		$result = User::getGroupsWithPermission( $right );
		sort( $result );
		sort( $expected );

		$this->assertEquals( $expected, $result, "Groups with permission $right" );
	}

	public static function provideGetGroupsWithPermission() {
		return [
			[
				[ 'unittesters', 'testwriters' ],
				'test'
			],
			[
				[ 'unittesters' ],
				'runtest'
			],
			[
				[ 'testwriters' ],
				'writetest'
			],
			[
				[ 'testwriters' ],
				'modifytest'
			],
		];
	}

	/**
	 * @covers User::isAllowedAny
	 * @covers User::isAllowedAll
	 * @covers User::isAllowed
	 * @covers User::isNewbie
	 */
	public function testIsAllowed() {
		$user = $this->getTestUser( 'unittesters' )->getUser();
		$this->assertFalse(
			$user->isAllowed( 'writetest' ),
			'Basic isAllowed works with a group not granted a right'
		);
		$this->assertTrue(
			$user->isAllowedAny( 'test', 'writetest' ),
			'A user with only one of the rights can pass isAllowedAll'
		);
		$this->assertTrue(
			$user->isAllowedAll( 'test', 'runtest' ),
			'A user with multiple rights can pass isAllowedAll'
		);
		$this->assertFalse(
			$user->isAllowedAll( 'test', 'runtest', 'writetest' ),
			'A user needs all rights specified to pass isAllowedAll'
		);
		$this->assertTrue(
			$user->isNewbie(),
			'Unit testers are not autoconfirmed yet'
		);

		$user = $this->getTestUser( 'testwriters' )->getUser();
		$this->assertTrue(
			$user->isAllowed( 'test' ),
			'Basic isAllowed works with a group granted a right'
		);
		$this->assertTrue(
			$user->isAllowed( 'writetest' ),
			'Testwriters pass isAllowed with `writetest`'
		);
		$this->assertFalse(
			$user->isNewbie(),
			'Test writers are autoconfirmed'
		);
	}

	/**
	 * @covers User::useRCPatrol
	 * @covers User::useNPPatrol
	 * @covers User::useFilePatrol
	 */
	public function testPatrolling() {
		$user = $this->getTestUser( 'patroller' )->getUser();

		$this->assertTrue( $user->useRCPatrol() );
		$this->assertTrue( $user->useNPPatrol() );
		$this->assertTrue( $user->useFilePatrol() );

		$user = $this->getTestUser()->getUser();
		$this->assertFalse( $user->useRCPatrol() );
		$this->assertFalse( $user->useNPPatrol() );
		$this->assertFalse( $user->useFilePatrol() );
	}

	/**
	 * @covers User::getGroups
	 * @covers User::getGroupMemberships
	 * @covers User::isBot
	 */
	public function testBot() {
		$user = $this->getTestUser( 'bot' )->getUser();

		$this->assertSame( $user->getGroups(), [ 'bot' ] );
		$this->assertArrayHasKey( 'bot', $user->getGroupMemberships() );
		$this->assertTrue( $user->isBot() );

		$user = $this->getTestUser()->getUser();
		$this->assertArrayNotHasKey( 'bot', $user->getGroupMemberships() );
		$this->assertFalse( $user->isBot() );
	}

	/**
	 * @dataProvider provideIPs
	 * @covers User::isIP
	 */
	public function testIsIP( $value, $result, $message ) {
		$this->assertEquals( $this->user->isIP( $value ), $result, $message );
	}

	public static function provideIPs() {
		return [
			[ '', false, 'Empty string' ],
			[ ' ', false, 'Blank space' ],
			[ '10.0.0.0', true, 'IPv4 private 10/8' ],
			[ '10.255.255.255', true, 'IPv4 private 10/8' ],
			[ '192.168.1.1', true, 'IPv4 private 192.168/16' ],
			[ '203.0.113.0', true, 'IPv4 example' ],
			[ '2002:ffff:ffff:ffff:ffff:ffff:ffff:ffff', true, 'IPv6 example' ],
			// Not valid IPs but classified as such by MediaWiki for negated asserting
			// of whether this might be the identifier of a logged-out user or whether
			// to allow usernames like it.
			[ '300.300.300.300', true, 'Looks too much like an IPv4 address' ],
			[ '203.0.113.xxx', true, 'Assigned by UseMod to cloaked logged-out users' ],
		];
	}

	/**
	 * @dataProvider provideUserNames
	 * @covers User::isValidUserName
	 */
	public function testIsValidUserName( $username, $result, $message ) {
		$this->assertEquals( $this->user->isValidUserName( $username ), $result, $message );
	}

	public static function provideUserNames() {
		return [
			[ '', false, 'Empty string' ],
			[ ' ', false, 'Blank space' ],
			[ 'abcd', false, 'Starts with small letter' ],
			[ 'Ab/cd', false, 'Contains slash' ],
			[ 'Ab cd', true, 'Whitespace' ],
			[ '192.168.1.1', false, 'IP' ],
			[ '116.17.184.5/32', false, 'IP range' ],
			[ '::e:f:2001/96', false, 'IPv6 range' ],
			[ 'User:Abcd', false, 'Reserved Namespace' ],
			[ '12abcd232', true, 'Starts with Numbers' ],
			[ '?abcd', true, 'Start with ? mark' ],
			[ '#abcd', false, 'Start with #' ],
			[ 'Abcdകഖഗഘ', true, ' Mixed scripts' ],
			[ 'ജോസ്‌തോമസ്', false, 'ZWNJ- Format control character' ],
			[ 'Ab　cd', false, ' Ideographic space' ],
			[ '300.300.300.300', false, 'Looks too much like an IPv4 address' ],
			[ '302.113.311.900', false, 'Looks too much like an IPv4 address' ],
			[ '203.0.113.xxx', false, 'Reserved for usage by UseMod for cloaked logged-out users' ],
		];
	}

	/**
	 * Test User::editCount
	 * @group medium
	 * @covers User::getEditCount
	 * @covers User::setEditCountInternal
	 */
	public function testGetEditCount() {
		$user = $this->getMutableTestUser()->getUser();

		// let the user have a few (3) edits
		$page = WikiPage::factory( Title::newFromText( 'Help:UserTest_EditCount' ) );
		for ( $i = 0; $i < 3; $i++ ) {
			$page->doEditContent(
				ContentHandler::makeContent( (string)$i, $page->getTitle() ),
				'test',
				0,
				false,
				$user
			);
		}

		$this->assertEquals(
			3,
			$user->getEditCount(),
			'After three edits, the user edit count should be 3'
		);

		// increase the edit count
		$user->incEditCount();
		$user->clearInstanceCache();

		$this->assertEquals(
			4,
			$user->getEditCount(),
			'After increasing the edit count manually, the user edit count should be 4'
		);

		// Update the edit count
		$user->setEditCountInternal( 42 );
		$this->assertEquals(
			42,
			$user->getEditCount(),
			'After setting the edit count manually, the user edit count should be 42'
		);
	}

	/**
	 * Test User::editCount
	 * @group medium
	 * @covers User::getEditCount
	 * @covers User::incEditCount
	 */
	public function testGetEditCountForAnons() {
		$user = User::newFromName( 'Anonymous' );

		$this->assertNull(
			$user->getEditCount(),
			'Edit count starts null for anonymous users.'
		);

		$this->assertNull(
			$user->incEditCount(),
			'Edit count cannot be increased for anonymous users'
		);

		$this->assertNull(
			$user->getEditCount(),
			'Edit count remains null for anonymous users despite calls to increase it.'
		);
	}

	/**
	 * Test User::editCount
	 * @group medium
	 * @covers User::incEditCount
	 */
	public function testIncEditCount() {
		$user = $this->getMutableTestUser()->getUser();
		$user->incEditCount();

		$reloadedUser = User::newFromId( $user->getId() );
		$reloadedUser->incEditCount();

		$this->assertEquals(
			2,
			$reloadedUser->getEditCount(),
			'Increasing the edit count after a fresh load leaves the object up to date.'
		);
	}

	/**
	 * Test changing user options.
	 * @covers User::setOption
	 * @covers User::getOption
	 * @covers User::getBoolOption
	 * @covers User::getIntOption
	 * @covers User::getStubThreshold
	 */
	public function testOptions() {
		$this->setMwGlobals( [
			'wgMaxArticleSize' => 2,
		] );
		$user = $this->getMutableTestUser()->getUser();

		$user->setOption( 'userjs-someoption', 'test' );
		$user->setOption( 'userjs-someintoption', '42' );
		$user->setOption( 'rclimit', 200 );
		$user->setOption( 'wpwatchlistdays', '0' );
		$user->setOption( 'stubthreshold', 1024 );
		$user->setOption( 'userjs-usedefaultoverride', '' );
		$user->saveSettings();

		$user = User::newFromName( $user->getName() );
		$user->load( User::READ_LATEST );
		$this->assertEquals( 'test', $user->getOption( 'userjs-someoption' ) );
		$this->assertTrue( $user->getBoolOption( 'userjs-someoption' ) );
		$this->assertEquals( 200, $user->getOption( 'rclimit' ) );
		$this->assertSame( 42, $user->getIntOption( 'userjs-someintoption' ) );
		$this->assertSame(
			123,
			$user->getIntOption( 'userjs-usedefaultoverride', 123 ),
			'Int options that are empty string can have a default returned'
		);
		$this->assertSame(
			1024,
			$user->getStubThreshold(),
			'Valid stub threshold preferences are respected'
		);

		$user = User::newFromName( $user->getName() );
		MediaWikiServices::getInstance()->getMainWANObjectCache()->clearProcessCache();
		$this->assertEquals( 'test', $user->getOption( 'userjs-someoption' ) );
		$this->assertTrue( $user->getBoolOption( 'userjs-someoption' ) );
		$this->assertEquals( 200, $user->getOption( 'rclimit' ) );
		$this->assertSame( 42, $user->getIntOption( 'userjs-someintoption' ) );
		$this->assertSame(
			0,
			$user->getIntOption( 'userjs-usedefaultoverride' ),
			'Int options that are empty string and have no default specified default to 0'
		);
		$this->assertSame(
			1024,
			$user->getStubThreshold(),
			'Valid stub threshold preferences are respected after cache is cleared'
		);

		// Check that an option saved as a string '0' is returned as an integer.
		$user = User::newFromName( $user->getName() );
		$user->load( User::READ_LATEST );
		$this->assertSame( 0, $user->getOption( 'wpwatchlistdays' ) );
		$this->assertFalse( $user->getBoolOption( 'wpwatchlistdays' ) );

		// Check that getStubThreashold resorts to 0 if invalid
		$user->setOption( 'stubthreshold', 4096 );
		$user->saveSettings();
		$this->assertSame(
			0,
			$user->getStubThreshold(),
			'If a stub threashold is impossible, it defaults to 0'
		);
	}

	/**
	 * T39963
	 * Make sure defaults are loaded when setOption is called.
	 * @covers User::loadOptions
	 */
	public function testAnonOptions() {
		global $wgDefaultUserOptions;
		$this->user->setOption( 'userjs-someoption', 'test' );
		$this->assertEquals( $wgDefaultUserOptions['rclimit'], $this->user->getOption( 'rclimit' ) );
		$this->assertEquals( 'test', $this->user->getOption( 'userjs-someoption' ) );
	}

	/**
	 * Test password validity checks. There are 3 checks in core,
	 *	- ensure the password meets the minimal length
	 *	- ensure the password is not the same as the username
	 *	- ensure the username/password combo isn't forbidden
	 * @covers User::checkPasswordValidity()
	 * @covers User::isValidPassword()
	 */
	public function testCheckPasswordValidity() {
		$this->setMwGlobals( [
			'wgPasswordPolicy' => [
				'policies' => [
					'sysop' => [
						'MinimalPasswordLength' => 8,
						'MinimumPasswordLengthToLogin' => 1,
						'PasswordCannotMatchUsername' => 1,
						'PasswordCannotBeSubstringInUsername' => 1,
					],
					'default' => [
						'MinimalPasswordLength' => 6,
						'PasswordCannotMatchUsername' => true,
						'PasswordCannotBeSubstringInUsername' => true,
						'PasswordCannotMatchBlacklist' => true,
						'MaximalPasswordLength' => 40,
					],
				],
				'checks' => [
					'MinimalPasswordLength' => 'PasswordPolicyChecks::checkMinimalPasswordLength',
					'MinimumPasswordLengthToLogin' => 'PasswordPolicyChecks::checkMinimumPasswordLengthToLogin',
					'PasswordCannotMatchUsername' => 'PasswordPolicyChecks::checkPasswordCannotMatchUsername',
					'PasswordCannotBeSubstringInUsername' =>
						'PasswordPolicyChecks::checkPasswordCannotBeSubstringInUsername',
					'PasswordCannotMatchBlacklist' => 'PasswordPolicyChecks::checkPasswordCannotMatchBlacklist',
					'MaximalPasswordLength' => 'PasswordPolicyChecks::checkMaximalPasswordLength',
				],
			],
		] );

		$user = static::getTestUser()->getUser();

		// Sanity
		$this->assertTrue( $user->isValidPassword( 'Password1234' ) );

		// Minimum length
		$this->assertFalse( $user->isValidPassword( 'a' ) );
		$this->assertFalse( $user->checkPasswordValidity( 'a' )->isGood() );
		$this->assertTrue( $user->checkPasswordValidity( 'a' )->isOK() );

		// Maximum length
		$longPass = str_repeat( 'a', 41 );
		$this->assertFalse( $user->isValidPassword( $longPass ) );
		$this->assertFalse( $user->checkPasswordValidity( $longPass )->isGood() );
		$this->assertFalse( $user->checkPasswordValidity( $longPass )->isOK() );

		// Matches username
		$this->assertFalse( $user->checkPasswordValidity( $user->getName() )->isGood() );
		$this->assertTrue( $user->checkPasswordValidity( $user->getName() )->isOK() );

		$this->setTemporaryHook( 'isValidPassword', function ( $password, &$result, $user ) {
			$result = 'isValidPassword returned false';
			return false;
		} );
		$status = $user->checkPasswordValidity( 'Password1234' );
		$this->assertTrue( $status->isOK() );
		$this->assertFalse( $status->isGood() );
		$this->assertSame( $status->getErrors()[0]['message'], 'isValidPassword returned false' );

		// Unregister
		$this->mergeMwGlobalArrayValue( 'wgHooks', [
			'isValidPassword' => []
		] );

		$this->setTemporaryHook( 'isValidPassword', function ( $password, &$result, $user ) {
			$result = true;
			return true;
		} );
		$status = $user->checkPasswordValidity( 'Password1234' );
		$this->assertTrue( $status->isOK() );
		$this->assertTrue( $status->isGood() );
		$this->assertArrayEquals( $status->getErrors(), [] );

		// Unregister
		$this->mergeMwGlobalArrayValue( 'wgHooks', [
			'isValidPassword' => []
		] );

		$this->setTemporaryHook( 'isValidPassword', function ( $password, &$result, $user ) {
			$result = 'isValidPassword returned true';
			return true;
		} );
		$status = $user->checkPasswordValidity( 'Password1234' );
		$this->assertTrue( $status->isOK() );
		$this->assertFalse( $status->isGood() );
		$this->assertSame( $status->getErrors()[0]['message'], 'isValidPassword returned true' );

		// Unregister
		$this->mergeMwGlobalArrayValue( 'wgHooks', [
			'isValidPassword' => []
		] );

		// On the forbidden list
		$user = User::newFromName( 'Useruser' );
		$this->assertFalse( $user->checkPasswordValidity( 'Passpass' )->isGood() );
	}

	/**
	 * @covers User::getCanonicalName()
	 * @dataProvider provideGetCanonicalName
	 */
	public function testGetCanonicalName( $name, $expectedArray ) {
		// fake interwiki map for the 'Interwiki prefix' testcase
		$this->mergeMwGlobalArrayValue( 'wgHooks', [
			'InterwikiLoadPrefix' => [
				function ( $prefix, &$iwdata ) {
					if ( $prefix === 'interwiki' ) {
						$iwdata = [
							'iw_url' => 'http://example.com/',
							'iw_local' => 0,
							'iw_trans' => 0,
						];
						return false;
					}
				},
			],
		] );

		foreach ( $expectedArray as $validate => $expected ) {
			$this->assertEquals(
				$expected,
				User::getCanonicalName( $name, $validate === 'false' ? false : $validate ), $validate );
		}
	}

	public static function provideGetCanonicalName() {
		return [
			'Leading space' => [ ' Leading space', [ 'creatable' => 'Leading space' ] ],
			'Trailing space ' => [ 'Trailing space ', [ 'creatable' => 'Trailing space' ] ],
			'Namespace prefix' => [ 'Talk:Username', [ 'creatable' => false, 'usable' => false,
				'valid' => false, 'false' => 'Talk:Username' ] ],
			'Interwiki prefix' => [ 'interwiki:Username', [ 'creatable' => false, 'usable' => false,
				'valid' => false, 'false' => 'Interwiki:Username' ] ],
			'With hash' => [ 'name with # hash', [ 'creatable' => false, 'usable' => false ] ],
			'Multi spaces' => [ 'Multi  spaces', [ 'creatable' => 'Multi spaces',
				'usable' => 'Multi spaces' ] ],
			'Lowercase' => [ 'lowercase', [ 'creatable' => 'Lowercase' ] ],
			'Invalid character' => [ 'in[]valid', [ 'creatable' => false, 'usable' => false,
				'valid' => false, 'false' => 'In[]valid' ] ],
			'With slash' => [ 'with / slash', [ 'creatable' => false, 'usable' => false, 'valid' => false,
				'false' => 'With / slash' ] ],
		];
	}

	/**
	 * @covers User::getCanonicalName()
	 */
	public function testGetCanonicalName_bad() {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage(
			'Invalid parameter value for $validate in User::getCanonicalName'
		);
		User::getCanonicalName( 'ValidName', 'InvalidValidationValue' );
	}

	/**
	 * @covers User::equals
	 */
	public function testEquals() {
		$first = $this->getMutableTestUser()->getUser();
		$second = User::newFromName( $first->getName() );

		$this->assertTrue( $first->equals( $first ) );
		$this->assertTrue( $first->equals( $second ) );
		$this->assertTrue( $second->equals( $first ) );

		$third = $this->getMutableTestUser()->getUser();
		$fourth = $this->getMutableTestUser()->getUser();

		$this->assertFalse( $third->equals( $fourth ) );
		$this->assertFalse( $fourth->equals( $third ) );

		// Test users loaded from db with id
		$user = $this->getMutableTestUser()->getUser();
		$fifth = User::newFromId( $user->getId() );
		$sixth = User::newFromName( $user->getName() );
		$this->assertTrue( $fifth->equals( $sixth ) );
	}

	/**
	 * @covers User::getId
	 * @covers User::setId
	 */
	public function testUserId() {
		$user = $this->getTestUser()->getUser();
		$this->assertGreaterThan( 0, $user->getId() );

		$user = User::newFromName( 'UserWithNoId' );
		$this->assertSame( $user->getId(), 0 );

		$user->setId( 7 );
		$this->assertSame(
			7,
			$user->getId(),
			'Manually setting a user id via ::setId is reflected in ::getId'
		);

		$user = new User;
		$user->setName( '1.2.3.4' );
		$this->assertSame(
			0,
			$user->getId(),
			'IPs have an id of 0'
		);
	}

	/**
	 * @covers User::isRegistered
	 * @covers User::isLoggedIn
	 * @covers User::isAnon
	 */
	public function testLoggedIn() {
		$user = $this->getMutableTestUser()->getUser();
		$this->assertTrue( $user->isRegistered() );
		$this->assertTrue( $user->isLoggedIn() );
		$this->assertFalse( $user->isAnon() );

		// Non-existent users are perceived as anonymous
		$user = User::newFromName( 'UTNonexistent' );
		$this->assertFalse( $user->isRegistered() );
		$this->assertFalse( $user->isLoggedIn() );
		$this->assertTrue( $user->isAnon() );

		$user = new User;
		$this->assertFalse( $user->isRegistered() );
		$this->assertFalse( $user->isLoggedIn() );
		$this->assertTrue( $user->isAnon() );
	}

	/**
	 * @covers User::setRealName
	 * @covers User::getRealName
	 */
	public function testRealName() {
		$user = $this->getMutableTestUser()->getUser();
		$realName = 'John Doe';

		$user->setRealName( $realName );
		$this->assertSame(
			$realName,
			$user->getRealName(),
			'Real name retrieved from cache'
		);

		$id = $user->getId();
		$user->saveSettings();

		$otherUser = User::newFromId( $id );
		$this->assertSame(
			$realName,
			$user->getRealName(),
			'Real name retrieved from database'
		);
	}

	/**
	 * @covers User::checkAndSetTouched
	 * @covers User::getDBTouched()
	 */
	public function testCheckAndSetTouched() {
		$user = $this->getMutableTestUser()->getUser();
		$user = TestingAccessWrapper::newFromObject( $user );
		$this->assertTrue( $user->isLoggedIn() );

		$touched = $user->getDBTouched();
		$this->assertTrue(
			$user->checkAndSetTouched(), "checkAndSetTouched() succedeed" );
		$this->assertGreaterThan(
			$touched, $user->getDBTouched(), "user_touched increased with casOnTouched()" );

		$touched = $user->getDBTouched();
		$this->assertTrue(
			$user->checkAndSetTouched(), "checkAndSetTouched() succedeed #2" );
		$this->assertGreaterThan(
			$touched, $user->getDBTouched(), "user_touched increased with casOnTouched() #2" );
	}

	/**
	 * @covers User::findUsersByGroup
	 */
	public function testFindUsersByGroup() {
		// FIXME: fails under postgres
		$this->markTestSkippedIfDbType( 'postgres' );

		$users = User::findUsersByGroup( [] );
		$this->assertSame( 0, iterator_count( $users ) );

		$users = User::findUsersByGroup( 'foo', 1, 1 );
		$this->assertSame( 0, iterator_count( $users ) );

		$user = $this->getMutableTestUser( [ 'foo' ] )->getUser();
		$users = User::findUsersByGroup( 'foo' );
		$this->assertEquals( 1, iterator_count( $users ) );
		$users->rewind();
		$this->assertTrue( $user->equals( $users->current() ) );

		// arguments have OR relationship
		$user2 = $this->getMutableTestUser( [ 'bar' ] )->getUser();
		$users = User::findUsersByGroup( [ 'foo', 'bar' ] );
		$this->assertEquals( 2, iterator_count( $users ) );
		$users->rewind();
		$this->assertTrue( $user->equals( $users->current() ) );
		$users->next();
		$this->assertTrue( $user2->equals( $users->current() ) );

		// users are not duplicated
		$user = $this->getMutableTestUser( [ 'baz', 'boom' ] )->getUser();
		$users = User::findUsersByGroup( [ 'baz', 'boom' ] );
		$this->assertEquals( 1, iterator_count( $users ) );
		$users->rewind();
		$this->assertTrue( $user->equals( $users->current() ) );
	}

	/**
	 * When a user is autoblocked a cookie is set with which to track them
	 * in case they log out and change IP addresses.
	 * @link https://phabricator.wikimedia.org/T5233
	 * @covers User::trackBlockWithCookie
	 */
	public function testAutoblockCookies() {
		// Set up the bits of global configuration that we use.
		$this->setMwGlobals( [
			'wgCookieSetOnAutoblock' => true,
			'wgCookiePrefix' => 'wmsitetitle',
			'wgSecretKey' => MWCryptRand::generateHex( 64, true ),
		] );

		// Unregister the hooks for proper unit testing
		$this->mergeMwGlobalArrayValue( 'wgHooks', [
			'PerformRetroactiveAutoblock' => []
		] );

		$blockManager = MediaWikiServices::getInstance()->getBlockManager();

		// 1. Log in a test user, and block them.
		$user1tmp = $this->getTestUser()->getUser();
		$request1 = new FauxRequest();
		$request1->getSession()->setUser( $user1tmp );
		$expiryFiveHours = wfTimestamp() + ( 5 * 60 * 60 );
		$block = new DatabaseBlock( [
			'enableAutoblock' => true,
			'expiry' => wfTimestamp( TS_MW, $expiryFiveHours ),
		] );
		$block->setBlocker( $this->getTestSysop()->getUser() );
		$block->setTarget( $user1tmp );
		$res = $block->insert();
		$this->assertTrue( (bool)$res['id'], 'Failed to insert block' );
		$user1 = User::newFromSession( $request1 );
		$user1->load();
		$blockManager->trackBlockWithCookie( $user1, $request1->response() );

		// Confirm that the block has been applied as required.
		$this->assertTrue( $user1->isLoggedIn() );
		$this->assertInstanceOf( DatabaseBlock::class, $user1->getBlock() );
		$this->assertEquals( DatabaseBlock::TYPE_USER, $block->getType() );
		$this->assertTrue( $block->isAutoblocking() );
		$this->assertGreaterThanOrEqual( 1, $block->getId() );

		// Test for the desired cookie name, value, and expiry.
		$cookies = $request1->response()->getCookies();
		$this->assertArrayHasKey( 'wmsitetitleBlockID', $cookies );
		$this->assertEquals( $expiryFiveHours, $cookies['wmsitetitleBlockID']['expire'] );
		$cookieId = $blockManager->getIdFromCookieValue(
			$cookies['wmsitetitleBlockID']['value']
		);
		$this->assertEquals( $block->getId(), $cookieId );

		// 2. Create a new request, set the cookies, and see if the (anon) user is blocked.
		$request2 = new FauxRequest();
		$request2->setCookie( 'BlockID', $blockManager->getCookieValue( $block ) );
		$user2 = User::newFromSession( $request2 );
		$user2->load();
		$blockManager->trackBlockWithCookie( $user2, $request2->response() );
		$this->assertNotEquals( $user1->getId(), $user2->getId() );
		$this->assertNotEquals( $user1->getToken(), $user2->getToken() );
		$this->assertTrue( $user2->isAnon() );
		$this->assertFalse( $user2->isLoggedIn() );
		$this->assertInstanceOf( DatabaseBlock::class, $user2->getBlock() );
		$this->assertTrue( (bool)$user2->getBlock()->isAutoblocking(), 'Autoblock does not work' );
		// Can't directly compare the objects because of member type differences.
		// One day this will work: $this->assertEquals( $block, $user2->getBlock() );
		$this->assertEquals( $block->getId(), $user2->getBlock()->getId() );
		$this->assertEquals( $block->getExpiry(), $user2->getBlock()->getExpiry() );

		// 3. Finally, set up a request as a new user, and the block should still be applied.
		$user3tmp = $this->getTestUser()->getUser();
		$request3 = new FauxRequest();
		$request3->getSession()->setUser( $user3tmp );
		$request3->setCookie( 'BlockID', $block->getId() );
		$user3 = User::newFromSession( $request3 );
		$user3->load();
		$blockManager->trackBlockWithCookie( $user3, $request3->response() );
		$this->assertTrue( $user3->isLoggedIn() );
		$this->assertInstanceOf( DatabaseBlock::class, $user3->getBlock() );
		$this->assertTrue( (bool)$user3->getBlock()->isAutoblocking() );

		// Clean up.
		$block->delete();
	}

	/**
	 * Make sure that no cookie is set to track autoblocked users
	 * when $wgCookieSetOnAutoblock is false.
	 * @covers User::trackBlockWithCookie
	 */
	public function testAutoblockCookiesDisabled() {
		// Set up the bits of global configuration that we use.
		$this->setMwGlobals( [
			'wgCookieSetOnAutoblock' => false,
			'wgCookiePrefix' => 'wm_no_cookies',
			'wgSecretKey' => MWCryptRand::generateHex( 64, true ),
		] );

		// Unregister the hooks for proper unit testing
		$this->mergeMwGlobalArrayValue( 'wgHooks', [
			'PerformRetroactiveAutoblock' => []
		] );

		// 1. Log in a test user, and block them.
		$testUser = $this->getTestUser()->getUser();
		$request1 = new FauxRequest();
		$request1->getSession()->setUser( $testUser );
		$block = new DatabaseBlock( [ 'enableAutoblock' => true ] );
		$block->setBlocker( $this->getTestSysop()->getUser() );
		$block->setTarget( $testUser );
		$res = $block->insert();
		$this->assertTrue( (bool)$res['id'], 'Failed to insert block' );
		$user = User::newFromSession( $request1 );
		$user->load();
		MediaWikiServices::getInstance()->getBlockManager()
			->trackBlockWithCookie( $user, $request1->response() );

		// 2. Test that the cookie IS NOT present.
		$this->assertTrue( $user->isLoggedIn() );
		$this->assertInstanceOf( DatabaseBlock::class, $user->getBlock() );
		$this->assertEquals( DatabaseBlock::TYPE_USER, $block->getType() );
		$this->assertTrue( $block->isAutoblocking() );
		$this->assertGreaterThanOrEqual( 1, $user->getBlockId() );
		$this->assertGreaterThanOrEqual( $block->getId(), $user->getBlockId() );
		$cookies = $request1->response()->getCookies();
		$this->assertArrayNotHasKey( 'wm_no_cookiesBlockID', $cookies );

		// Clean up.
		$block->delete();
	}

	/**
	 * When a user is autoblocked and a cookie is set to track them, the expiry time of the cookie
	 * should match the block's expiry, to a maximum of 24 hours. If the expiry time is changed,
	 * the cookie's should change with it.
	 * @covers User::trackBlockWithCookie
	 */
	public function testAutoblockCookieInfiniteExpiry() {
		$this->setMwGlobals( [
			'wgCookieSetOnAutoblock' => true,
			'wgCookiePrefix' => 'wm_infinite_block',
			'wgSecretKey' => MWCryptRand::generateHex( 64, true ),
		] );

		// Unregister the hooks for proper unit testing
		$this->mergeMwGlobalArrayValue( 'wgHooks', [
			'PerformRetroactiveAutoblock' => []
		] );

		// 1. Log in a test user, and block them indefinitely.
		$user1Tmp = $this->getTestUser()->getUser();
		$request1 = new FauxRequest();
		$request1->getSession()->setUser( $user1Tmp );
		$block = new DatabaseBlock( [ 'enableAutoblock' => true, 'expiry' => 'infinity' ] );
		$block->setBlocker( $this->getTestSysop()->getUser() );
		$block->setTarget( $user1Tmp );
		$res = $block->insert();
		$this->assertTrue( (bool)$res['id'], 'Failed to insert block' );
		$user1 = User::newFromSession( $request1 );
		$user1->load();
		MediaWikiServices::getInstance()->getBlockManager()
			->trackBlockWithCookie( $user1, $request1->response() );

		// 2. Test the cookie's expiry timestamp.
		$this->assertTrue( $user1->isLoggedIn() );
		$this->assertInstanceOf( DatabaseBlock::class, $user1->getBlock() );
		$this->assertEquals( DatabaseBlock::TYPE_USER, $block->getType() );
		$this->assertTrue( $block->isAutoblocking() );
		$this->assertGreaterThanOrEqual( 1, $user1->getBlockId() );
		$cookies = $request1->response()->getCookies();
		// Test the cookie's expiry to the nearest minute.
		$this->assertArrayHasKey( 'wm_infinite_blockBlockID', $cookies );
		$expOneDay = wfTimestamp() + ( 24 * 60 * 60 );
		// Check for expiry dates in a 10-second window, to account for slow testing.
		$this->assertEqualsWithDelta(
			$expOneDay,
			$cookies['wm_infinite_blockBlockID']['expire'],
			5.0,
			'Expiry date'
		);

		// 3. Change the block's expiry (to 2 hours), and the cookie's should be changed also.
		$newExpiry = wfTimestamp() + 2 * 60 * 60;
		$block->setExpiry( wfTimestamp( TS_MW, $newExpiry ) );
		$block->update();
		$user2tmp = $this->getTestUser()->getUser();
		$request2 = new FauxRequest();
		$request2->getSession()->setUser( $user2tmp );
		$user2 = User::newFromSession( $request2 );
		$user2->load();
		MediaWikiServices::getInstance()->getBlockManager()
			->trackBlockWithCookie( $user2, $request2->response() );
		$cookies = $request2->response()->getCookies();
		$this->assertEquals( wfTimestamp( TS_MW, $newExpiry ), $block->getExpiry() );
		$this->assertEquals( $newExpiry, $cookies['wm_infinite_blockBlockID']['expire'] );

		// Clean up.
		$block->delete();
	}

	/**
	 * @covers User::getBlockedStatus
	 */
	public function testSoftBlockRanges() {
		$this->setMwGlobals( 'wgSoftBlockRanges', [ '10.0.0.0/8' ] );

		// IP isn't in $wgSoftBlockRanges
		$wgUser = new User();
		$request = new FauxRequest();
		$request->setIP( '192.168.0.1' );
		$this->setSessionUser( $wgUser, $request );
		$this->assertNull( $wgUser->getBlock() );

		// IP is in $wgSoftBlockRanges
		$wgUser = new User();
		$request = new FauxRequest();
		$request->setIP( '10.20.30.40' );
		$this->setSessionUser( $wgUser, $request );
		$block = $wgUser->getBlock();
		$this->assertInstanceOf( SystemBlock::class, $block );
		$this->assertSame( 'wgSoftBlockRanges', $block->getSystemBlockType() );

		// Make sure the block is really soft
		$wgUser = $this->getTestUser()->getUser();
		$request = new FauxRequest();
		$request->setIP( '10.20.30.40' );
		$this->setSessionUser( $wgUser, $request );
		$this->assertFalse( $wgUser->isAnon(), 'sanity check' );
		$this->assertNull( $wgUser->getBlock() );
	}

	/**
	 * Test that a modified BlockID cookie doesn't actually load the relevant block (T152951).
	 * @covers User::trackBlockWithCookie
	 */
	public function testAutoblockCookieInauthentic() {
		// Set up the bits of global configuration that we use.
		$this->setMwGlobals( [
			'wgCookieSetOnAutoblock' => true,
			'wgCookiePrefix' => 'wmsitetitle',
			'wgSecretKey' => MWCryptRand::generateHex( 64, true ),
		] );

		// Unregister the hooks for proper unit testing
		$this->mergeMwGlobalArrayValue( 'wgHooks', [
			'PerformRetroactiveAutoblock' => []
		] );

		// 1. Log in a blocked test user.
		$user1tmp = $this->getTestUser()->getUser();
		$request1 = new FauxRequest();
		$request1->getSession()->setUser( $user1tmp );
		$block = new DatabaseBlock( [ 'enableAutoblock' => true ] );
		$block->setBlocker( $this->getTestSysop()->getUser() );
		$block->setTarget( $user1tmp );
		$res = $block->insert();
		$this->assertTrue( (bool)$res['id'], 'Failed to insert block' );

		// 2. Create a new request, set the cookie to an invalid value, and make sure the (anon)
		// user not blocked.
		$request2 = new FauxRequest();
		$request2->setCookie( 'BlockID', $block->getId() . '!zzzzzzz' );
		$user2 = User::newFromSession( $request2 );
		$user2->load();
		$this->assertTrue( $user2->isAnon() );
		$this->assertFalse( $user2->isLoggedIn() );
		$this->assertNull( $user2->getBlock() );

		// Clean up.
		$block->delete();
	}

	/**
	 * The BlockID cookie is normally verified with a HMAC, but not if wgSecretKey is not set.
	 * This checks that a non-authenticated cookie still works.
	 * @covers User::trackBlockWithCookie
	 */
	public function testAutoblockCookieNoSecretKey() {
		// Set up the bits of global configuration that we use.
		$this->setMwGlobals( [
			'wgCookieSetOnAutoblock' => true,
			'wgCookiePrefix' => 'wmsitetitle',
			'wgSecretKey' => null,
		] );

		// Unregister the hooks for proper unit testing
		$this->mergeMwGlobalArrayValue( 'wgHooks', [
			'PerformRetroactiveAutoblock' => []
		] );

		// 1. Log in a blocked test user.
		$user1tmp = $this->getTestUser()->getUser();
		$request1 = new FauxRequest();
		$request1->getSession()->setUser( $user1tmp );
		$block = new DatabaseBlock( [ 'enableAutoblock' => true ] );
		$block->setBlocker( $this->getTestSysop()->getUser() );
		$block->setTarget( $user1tmp );
		$res = $block->insert();
		$this->assertTrue( (bool)$res['id'], 'Failed to insert block' );
		$user1 = User::newFromSession( $request1 );
		$user1->load();
		$this->assertInstanceOf( DatabaseBlock::class, $user1->getBlock() );

		// 2. Create a new request, set the cookie to just the block ID, and the user should
		// still get blocked when they log in again.
		$request2 = new FauxRequest();
		$request2->setCookie( 'BlockID', $block->getId() );
		$user2 = User::newFromSession( $request2 );
		$user2->load();
		$this->assertNotEquals( $user1->getId(), $user2->getId() );
		$this->assertNotEquals( $user1->getToken(), $user2->getToken() );
		$this->assertTrue( $user2->isAnon() );
		$this->assertFalse( $user2->isLoggedIn() );
		$this->assertInstanceOf( DatabaseBlock::class, $user2->getBlock() );
		$this->assertTrue( (bool)$user2->getBlock()->isAutoblocking() );

		// Clean up.
		$block->delete();
	}

	/**
	 * @covers User::isPingLimitable
	 */
	public function testIsPingLimitable() {
		$request = new FauxRequest();
		$request->setIP( '1.2.3.4' );
		$user = User::newFromSession( $request );

		$this->setMwGlobals( 'wgRateLimitsExcludedIPs', [] );
		$this->assertTrue( $user->isPingLimitable() );

		$this->setMwGlobals( 'wgRateLimitsExcludedIPs', [ '1.2.3.4' ] );
		$this->assertFalse( $user->isPingLimitable() );

		$this->setMwGlobals( 'wgRateLimitsExcludedIPs', [ '1.2.3.0/8' ] );
		$this->assertFalse( $user->isPingLimitable() );

		$this->setMwGlobals( 'wgRateLimitsExcludedIPs', [] );
		$this->overrideUserPermissions( $user, 'noratelimit' );
		$this->assertFalse( $user->isPingLimitable() );
	}

	public function provideExperienceLevel() {
		return [
			[ 2, 2, 'newcomer' ],
			[ 12, 3, 'newcomer' ],
			[ 8, 5, 'newcomer' ],
			[ 15, 10, 'learner' ],
			[ 450, 20, 'learner' ],
			[ 460, 33, 'learner' ],
			[ 525, 28, 'learner' ],
			[ 538, 33, 'experienced' ],
		];
	}

	/**
	 * @covers User::getExperienceLevel
	 * @dataProvider provideExperienceLevel
	 */
	public function testExperienceLevel( $editCount, $memberSince, $expLevel ) {
		$this->setMwGlobals( [
			'wgLearnerEdits' => 10,
			'wgLearnerMemberSince' => 4,
			'wgExperiencedUserEdits' => 500,
			'wgExperiencedUserMemberSince' => 30,
		] );

		$db = wfGetDB( DB_MASTER );
		$userQuery = User::getQueryInfo();
		$row = $db->selectRow(
			$userQuery['tables'],
			$userQuery['fields'],
			[ 'user_id' => $this->getTestUser()->getUser()->getId() ],
			__METHOD__,
			[],
			$userQuery['joins']
		);
		$row->user_editcount = $editCount;
		$row->user_registration = $db->timestamp( time() - $memberSince * 86400 );
		$user = User::newFromRow( $row );

		$this->assertEquals( $expLevel, $user->getExperienceLevel() );
	}

	/**
	 * @covers User::getExperienceLevel
	 */
	public function testExperienceLevelAnon() {
		$user = User::newFromName( '10.11.12.13', false );

		$this->assertFalse( $user->getExperienceLevel() );
	}

	public static function provideIsLocallyBlockedProxy() {
		return [
			[ '1.2.3.4', '1.2.3.4' ],
			[ '1.2.3.4', '1.2.3.0/16' ],
		];
	}

	/**
	 * @dataProvider provideIsLocallyBlockedProxy
	 * @covers User::isLocallyBlockedProxy
	 */
	public function testIsLocallyBlockedProxy( $ip, $blockListEntry ) {
		$this->hideDeprecated( 'User::isLocallyBlockedProxy' );

		$this->setMwGlobals(
			'wgProxyList', []
		);
		$this->assertFalse( User::isLocallyBlockedProxy( $ip ) );

		$this->setMwGlobals(
			'wgProxyList',
			[
				$blockListEntry
			]
		);
		$this->assertTrue( User::isLocallyBlockedProxy( $ip ) );

		$this->setMwGlobals(
			'wgProxyList',
			[
				'test' => $blockListEntry
			]
		);
		$this->assertTrue( User::isLocallyBlockedProxy( $ip ) );
	}

	/**
	 * @covers User::newFromId
	 */
	public function testNewFromId() {
		$user = $this->getTestUser()->getUser();
		$userId = $user->getId();
		$this->assertGreaterThan(
			0,
			$userId,
			'Sanity check: user has a working id'
		);

		$otherUser = User::newFromId( $userId );
		$this->assertTrue(
			$user->equals( $otherUser ),
			'User created by id should match user with that id'
		);
	}

	/**
	 * @covers User::newFromActorId
	 */
	public function testActorId() {
		$domain = MediaWikiServices::getInstance()->getDBLoadBalancer()->getLocalDomainID();
		$this->hideDeprecated( 'User::selectFields' );

		// Newly-created user has an actor ID
		$user = User::createNew( 'UserTestActorId1' );
		$id = $user->getId();
		$this->assertTrue( $user->getActorId() > 0, 'User::createNew sets an actor ID' );

		$user = User::newFromName( 'UserTestActorId2' );
		$user->addToDatabase();
		$this->assertTrue( $user->getActorId() > 0, 'User::addToDatabase sets an actor ID' );

		$user = User::newFromName( 'UserTestActorId1' );
		$this->assertTrue( $user->getActorId() > 0, 'Actor ID can be retrieved for user loaded by name' );

		$user = User::newFromId( $id );
		$this->assertTrue( $user->getActorId() > 0, 'Actor ID can be retrieved for user loaded by ID' );

		$user2 = User::newFromActorId( $user->getActorId() );
		$this->assertEquals( $user->getId(), $user2->getId(),
			'User::newFromActorId works for an existing user' );

		$row = $this->db->selectRow( 'user', User::selectFields(), [ 'user_id' => $id ], __METHOD__ );
		$user = User::newFromRow( $row );
		$this->assertTrue( $user->getActorId() > 0,
			'Actor ID can be retrieved for user loaded with User::selectFields()' );

		$user = User::newFromId( $id );
		$user->setName( 'UserTestActorId4-renamed' );
		$user->saveSettings();
		$this->assertEquals(
			$user->getName(),
			$this->db->selectField(
				'actor', 'actor_name', [ 'actor_id' => $user->getActorId() ], __METHOD__
			),
			'User::saveSettings updates actor table for name change'
		);

		// For sanity
		$ip = '192.168.12.34';
		$this->db->delete( 'actor', [ 'actor_name' => $ip ], __METHOD__ );

		$user = User::newFromName( $ip, false );
		$this->assertFalse( $user->getActorId() > 0, 'Anonymous user has no actor ID by default' );
		$this->assertTrue( $user->getActorId( $this->db ) > 0,
			'Actor ID can be created for an anonymous user' );

		$user = User::newFromName( $ip, false );
		$this->assertTrue( $user->getActorId() > 0, 'Actor ID can be loaded for an anonymous user' );
		$user2 = User::newFromActorId( $user->getActorId() );
		$this->assertEquals( $user->getName(), $user2->getName(),
			'User::newFromActorId works for an anonymous user' );
	}

	/**
	 * @covers User::newFromAnyId
	 */
	public function testNewFromAnyId() {
		// Registered user
		$user = $this->getTestUser()->getUser();
		for ( $i = 1; $i <= 7; $i++ ) {
			$test = User::newFromAnyId(
				( $i & 1 ) ? $user->getId() : null,
				( $i & 2 ) ? $user->getName() : null,
				( $i & 4 ) ? $user->getActorId() : null
			);
			$this->assertSame( $user->getId(), $test->getId() );
			$this->assertSame( $user->getName(), $test->getName() );
			$this->assertSame( $user->getActorId(), $test->getActorId() );
		}

		// Anon user. Can't load by only user ID when that's 0.
		$user = User::newFromName( '192.168.12.34', false );
		$user->getActorId( $this->db ); // Make sure an actor ID exists

		$test = User::newFromAnyId( null, '192.168.12.34', null );
		$this->assertSame( $user->getId(), $test->getId() );
		$this->assertSame( $user->getName(), $test->getName() );
		$this->assertSame( $user->getActorId(), $test->getActorId() );
		$test = User::newFromAnyId( null, null, $user->getActorId() );
		$this->assertSame( $user->getId(), $test->getId() );
		$this->assertSame( $user->getName(), $test->getName() );
		$this->assertSame( $user->getActorId(), $test->getActorId() );

		// Bogus data should still "work" as long as nothing triggers a ->load(),
		// and accessing the specified data shouldn't do that.
		$test = User::newFromAnyId( 123456, 'Bogus', 654321 );
		$this->assertSame( 123456, $test->getId() );
		$this->assertSame( 'Bogus', $test->getName() );
		$this->assertSame( 654321, $test->getActorId() );

		// Loading remote user by name from remote wiki should succeed
		$test = User::newFromAnyId( null, 'Bogus', null, 'foo' );
		$this->assertSame( 0, $test->getId() );
		$this->assertSame( 'Bogus', $test->getName() );
		$this->assertSame( 0, $test->getActorId() );
		$test = User::newFromAnyId( 123456, 'Bogus', 654321, 'foo' );
		$this->assertSame( 0, $test->getId() );
		$this->assertSame( 0, $test->getActorId() );

		// Exceptional cases
		try {
			User::newFromAnyId( null, null, null );
			$this->fail( 'Expected exception not thrown' );
		} catch ( InvalidArgumentException $ex ) {
		}
		try {
			User::newFromAnyId( 0, null, 0 );
			$this->fail( 'Expected exception not thrown' );
		} catch ( InvalidArgumentException $ex ) {
		}

		// Loading remote user by id from remote wiki should fail
		try {
			User::newFromAnyId( 123456, null, 654321, 'foo' );
			$this->fail( 'Expected exception not thrown' );
		} catch ( InvalidArgumentException $ex ) {
		}
	}

	/**
	 * @covers User::newFromIdentity
	 */
	public function testNewFromIdentity() {
		// Registered user
		$user = $this->getTestUser()->getUser();

		$this->assertSame( $user, User::newFromIdentity( $user ) );

		// ID only
		$identity = new UserIdentityValue( $user->getId(), '', 0 );
		$result = User::newFromIdentity( $identity );
		$this->assertInstanceOf( User::class, $result );
		$this->assertSame( $user->getId(), $result->getId(), 'ID' );
		$this->assertSame( $user->getName(), $result->getName(), 'Name' );
		$this->assertSame( $user->getActorId(), $result->getActorId(), 'Actor' );

		// Name only
		$identity = new UserIdentityValue( 0, $user->getName(), 0 );
		$result = User::newFromIdentity( $identity );
		$this->assertInstanceOf( User::class, $result );
		$this->assertSame( $user->getId(), $result->getId(), 'ID' );
		$this->assertSame( $user->getName(), $result->getName(), 'Name' );
		$this->assertSame( $user->getActorId(), $result->getActorId(), 'Actor' );

		// Actor only
		$identity = new UserIdentityValue( 0, '', $user->getActorId() );
		$result = User::newFromIdentity( $identity );
		$this->assertInstanceOf( User::class, $result );
		$this->assertSame( $user->getId(), $result->getId(), 'ID' );
		$this->assertSame( $user->getName(), $result->getName(), 'Name' );
		$this->assertSame( $user->getActorId(), $result->getActorId(), 'Actor' );
	}

	/**
	 * @covers User::newFromConfirmationCode
	 */
	public function testNewFromConfirmationCode() {
		$user = User::newFromConfirmationCode( 'NotARealConfirmationCode' );
		$this->assertNull(
			$user,
			'Invalid confirmation codes result in null users when reading from replicas'
		);

		$user = User::newFromConfirmationCode( 'OtherFakeCode', User::READ_LATEST );
		$this->assertNull(
			$user,
			'Invalid confirmation codes result in null users when reading from master'
		);
	}

	/**
	 * @covers User::newFromName
	 * @covers User::getName
	 * @covers User::getUserPage
	 * @covers User::getTalkPage
	 * @covers User::getTitleKey
	 * @covers User::whoIs
	 * @dataProvider provideNewFromName
	 */
	public function testNewFromName( $name, $titleKey ) {
		$user = User::newFromName( $name );
		$this->assertEquals( $user->getName(), $name );
		$this->assertEquals( $user->getUserPage(), Title::makeTitle( NS_USER, $name ) );
		$this->assertEquals( $user->getTalkPage(), Title::makeTitle( NS_USER_TALK, $name ) );
		$this->assertEquals( $user->getTitleKey(), $titleKey );

		$status = $user->addToDatabase();
		$this->assertTrue( $status->isOK(), 'User can be added to the database' );
		$this->assertSame( $name, User::whoIs( $user->getId() ) );
	}

	public static function provideNewFromName() {
		return [
			[ 'Example1', 'Example1' ],
			[ 'Mediawiki easter egg', 'Mediawiki_easter_egg' ],
			[ 'See T22281 for more', 'See_T22281_for_more' ],
			[ 'DannyS712', 'DannyS712' ],
		];
	}

	/**
	 * @covers User::newFromName
	 */
	public function testNewFromName_extra() {
		$user = User::newFromName( '1.2.3.4' );
		$this->assertFalse( $user, 'IP addresses are not valid user names' );

		$user = User::newFromName( 'DannyS712', true );
		$otherUser = User::newFromName( 'DannyS712', 'valid' );
		$this->assertTrue(
			$user->equals( $otherUser ),
			'true maps to valid for backwards compatibility'
		);
	}

	/**
	 * @covers User::getBlockedStatus
	 * @covers User::getBlockId
	 * @covers User::getBlock
	 * @covers User::blockedBy
	 * @covers User::blockedFor
	 * @covers User::isHidden
	 * @covers User::isBlockedFrom
	 */
	public function testBlockInstanceCache() {
		// First, check the user isn't blocked
		$user = $this->getMutableTestUser()->getUser();
		$ut = Title::makeTitle( NS_USER_TALK, $user->getName() );
		$this->assertNull( $user->getBlock( false ), 'sanity check' );
		$this->assertSame( '', $user->blockedBy(), 'sanity check' );
		$this->assertSame( '', $user->blockedFor(), 'sanity check' );
		$this->assertFalse( $user->isHidden(), 'sanity check' );
		$this->assertFalse( $user->isBlockedFrom( $ut ), 'sanity check' );

		// Block the user
		$blocker = $this->getTestSysop()->getUser();
		$block = new DatabaseBlock( [
			'hideName' => true,
			'allowUsertalk' => false,
			'reason' => 'Because',
		] );
		$block->setTarget( $user );
		$block->setBlocker( $blocker );
		$res = $block->insert();
		$this->assertTrue( (bool)$res['id'], 'sanity check: Failed to insert block' );

		// Clear cache and confirm it loaded the block properly
		$user->clearInstanceCache();
		$this->assertInstanceOf( DatabaseBlock::class, $user->getBlock( false ) );
		$this->assertSame( $blocker->getName(), $user->blockedBy() );
		$this->assertSame( 'Because', $user->blockedFor() );
		$this->assertTrue( $user->isHidden() );
		$this->assertTrue( $user->isBlockedFrom( $ut ) );
		$this->assertEquals( $res['id'], $user->getBlockId() );

		// Unblock
		$block->delete();

		// Clear cache and confirm it loaded the not-blocked properly
		$user->clearInstanceCache();
		$this->assertNull( $user->getBlock( false ) );
		$this->assertSame( '', $user->blockedBy() );
		$this->assertSame( '', $user->blockedFor() );
		$this->assertFalse( $user->isHidden() );
		$this->assertFalse( $user->isBlockedFrom( $ut ) );
		$this->assertFalse( $user->getBlockId() );
	}

	/**
	 * @covers User::getBlockedStatus
	 */
	public function testCompositeBlocks() {
		$user = $this->getMutableTestUser()->getUser();
		$request = $user->getRequest();
		$this->setSessionUser( $user, $request );

		$ipBlock = new Block( [
			'address' => $user->getRequest()->getIP(),
			'by' => $this->getTestSysop()->getUser()->getId(),
			'createAccount' => true,
		] );
		$ipBlock->insert();

		$userBlock = new Block( [
			'address' => $user,
			'by' => $this->getTestSysop()->getUser()->getId(),
			'createAccount' => false,
		] );
		$userBlock->insert();

		$block = $user->getBlock();
		$this->assertInstanceOf( CompositeBlock::class, $block );
		$this->assertTrue( $block->isCreateAccountBlocked() );
		$this->assertTrue( $block->appliesToPasswordReset() );
		$this->assertTrue( $block->appliesToNamespace( NS_MAIN ) );
	}

	/**
	 * @covers User::isBlockedFrom
	 * @dataProvider provideIsBlockedFrom
	 * @param string|null $title Title to test.
	 * @param bool $expect Expected result from User::isBlockedFrom()
	 * @param array $options Additional test options:
	 *  - 'blockAllowsUTEdit': (bool, default true) Value for $wgBlockAllowsUTEdit
	 *  - 'allowUsertalk': (bool, default false) Passed to DatabaseBlock::__construct()
	 *  - 'pageRestrictions': (array|null) If non-empty, page restriction titles for the block.
	 */
	public function testIsBlockedFrom( $title, $expect, array $options = [] ) {
		$this->setMwGlobals( [
			'wgBlockAllowsUTEdit' => $options['blockAllowsUTEdit'] ?? true,
		] );

		$user = $this->getTestUser()->getUser();

		if ( $title === self::USER_TALK_PAGE ) {
			$title = $user->getTalkPage();
		} else {
			$title = Title::newFromText( $title );
		}

		$restrictions = [];
		foreach ( $options['pageRestrictions'] ?? [] as $pagestr ) {
			$page = $this->getExistingTestPage(
				$pagestr === self::USER_TALK_PAGE ? $user->getTalkPage() : $pagestr
			);
			$restrictions[] = new PageRestriction( 0, $page->getId() );
		}
		foreach ( $options['namespaceRestrictions'] ?? [] as $ns ) {
			$restrictions[] = new NamespaceRestriction( 0, $ns );
		}

		$block = new DatabaseBlock( [
			'expiry' => wfTimestamp( TS_MW, wfTimestamp() + ( 40 * 60 * 60 ) ),
			'allowUsertalk' => $options['allowUsertalk'] ?? false,
			'sitewide' => !$restrictions,
		] );
		$block->setTarget( $user );
		$block->setBlocker( $this->getTestSysop()->getUser() );
		if ( $restrictions ) {
			$block->setRestrictions( $restrictions );
		}
		$block->insert();

		try {
			$this->assertSame( $expect, $user->isBlockedFrom( $title ) );
		} finally {
			$block->delete();
		}
	}

	public static function provideIsBlockedFrom() {
		return [
			'Sitewide block, basic operation' => [ 'Test page', true ],
			'Sitewide block, not allowing user talk' => [
				self::USER_TALK_PAGE, true, [
					'allowUsertalk' => false,
				]
			],
			'Sitewide block, allowing user talk' => [
				self::USER_TALK_PAGE, false, [
					'allowUsertalk' => true,
				]
			],
			'Sitewide block, allowing user talk but $wgBlockAllowsUTEdit is false' => [
				self::USER_TALK_PAGE, true, [
					'allowUsertalk' => true,
					'blockAllowsUTEdit' => false,
				]
			],
			'Partial block, blocking the page' => [
				'Test page', true, [
					'pageRestrictions' => [ 'Test page' ],
				]
			],
			'Partial block, not blocking the page' => [
				'Test page 2', false, [
					'pageRestrictions' => [ 'Test page' ],
				]
			],
			'Partial block, not allowing user talk but user talk page is not blocked' => [
				self::USER_TALK_PAGE, false, [
					'allowUsertalk' => false,
					'pageRestrictions' => [ 'Test page' ],
				]
			],
			'Partial block, allowing user talk but user talk page is blocked' => [
				self::USER_TALK_PAGE, true, [
					'allowUsertalk' => true,
					'pageRestrictions' => [ self::USER_TALK_PAGE ],
				]
			],
			'Partial block, user talk page is not blocked but $wgBlockAllowsUTEdit is false' => [
				self::USER_TALK_PAGE, false, [
					'allowUsertalk' => false,
					'pageRestrictions' => [ 'Test page' ],
					'blockAllowsUTEdit' => false,
				]
			],
			'Partial block, user talk page is blocked and $wgBlockAllowsUTEdit is false' => [
				self::USER_TALK_PAGE, true, [
					'allowUsertalk' => true,
					'pageRestrictions' => [ self::USER_TALK_PAGE ],
					'blockAllowsUTEdit' => false,
				]
			],
			'Partial user talk namespace block, not allowing user talk' => [
				self::USER_TALK_PAGE, true, [
					'allowUsertalk' => false,
					'namespaceRestrictions' => [ NS_USER_TALK ],
				]
			],
			'Partial user talk namespace block, allowing user talk' => [
				self::USER_TALK_PAGE, false, [
					'allowUsertalk' => true,
					'namespaceRestrictions' => [ NS_USER_TALK ],
				]
			],
			'Partial user talk namespace block, where $wgBlockAllowsUTEdit is false' => [
				self::USER_TALK_PAGE, true, [
					'allowUsertalk' => true,
					'namespaceRestrictions' => [ NS_USER_TALK ],
					'blockAllowsUTEdit' => false,
				]
			],
		];
	}

	/**
	 * @covers User::isBlockedFromEmailuser
	 * @covers User::isAllowedToCreateAccount
	 * @dataProvider provideIsBlockedFromAction
	 * @param bool $blockFromEmail Whether to block email access.
	 * @param bool $blockFromAccountCreation Whether to block account creation.
	 */
	public function testIsBlockedFromAction( $blockFromEmail, $blockFromAccountCreation ) {
		$user = $this->getTestUser( 'accountcreator' )->getUser();

		$block = new DatabaseBlock( [
			'expiry' => wfTimestamp( TS_MW, wfTimestamp() + ( 40 * 60 * 60 ) ),
			'sitewide' => true,
			'blockEmail' => $blockFromEmail,
			'createAccount' => $blockFromAccountCreation
		] );
		$block->setTarget( $user );
		$block->setBlocker( $this->getTestSysop()->getUser() );
		$block->insert();

		try {
			$this->assertEquals( $user->isBlockedFromEmailuser(), $blockFromEmail );
			$this->assertEquals( $user->isAllowedToCreateAccount(), !$blockFromAccountCreation );
		} finally {
			$block->delete();
		}
	}

	public static function provideIsBlockedFromAction() {
		return [
			'Block email access and account creation' => [ true, true ],
			'Block only email access' => [ true, false ],
			'Block only account creation' => [ false, true ],
			'Allow email access and account creation' => [ false, false ],
		];
	}

	/**
	 * @covers User::isBlockedFromUpload
	 * @dataProvider provideIsBlockedFromUpload
	 * @param bool $sitewide Whether to block sitewide.
	 * @param bool $expected Whether the user is expected to be blocked from uploads.
	 */
	public function testIsBlockedFromUpload( $sitewide, $expected ) {
		$user = $this->getTestUser()->getUser();

		$block = new DatabaseBlock( [
			'expiry' => wfTimestamp( TS_MW, wfTimestamp() + ( 40 * 60 * 60 ) ),
			'sitewide' => $sitewide,
		] );
		$block->setTarget( $user );
		$block->setBlocker( $this->getTestSysop()->getUser() );
		$block->insert();

		try {
			$this->assertEquals( $user->isBlockedFromUpload(), $expected );
		} finally {
			$block->delete();
		}
	}

	public static function provideIsBlockedFromUpload() {
		return [
			'sitewide blocks block uploads' => [ true, true ],
			'partial blocks allow uploads' => [ false, false ],
		];
	}

	/**
	 * Block cookie should be set for IP Blocks if
	 * wgCookieSetOnIpBlock is set to true
	 * @covers User::trackBlockWithCookie
	 */
	public function testIpBlockCookieSet() {
		$this->setMwGlobals( [
			'wgCookieSetOnIpBlock' => true,
			'wgCookiePrefix' => 'wiki',
			'wgSecretKey' => MWCryptRand::generateHex( 64, true ),
		] );

		// setup block
		$block = new DatabaseBlock( [
			'expiry' => wfTimestamp( TS_MW, wfTimestamp() + ( 5 * 60 * 60 ) ),
		] );
		$block->setTarget( '1.2.3.4' );
		$block->setBlocker( $this->getTestSysop()->getUser() );
		$block->insert();

		// setup request
		$request = new FauxRequest();
		$request->setIP( '1.2.3.4' );

		// get user
		$user = User::newFromSession( $request );
		MediaWikiServices::getInstance()->getBlockManager()
			->trackBlockWithCookie( $user, $request->response() );

		// test cookie was set
		$cookies = $request->response()->getCookies();
		$this->assertArrayHasKey( 'wikiBlockID', $cookies );

		// clean up
		$block->delete();
	}

	/**
	 * Block cookie should NOT be set when wgCookieSetOnIpBlock
	 * is disabled
	 * @covers User::trackBlockWithCookie
	 */
	public function testIpBlockCookieNotSet() {
		$this->setMwGlobals( [
			'wgCookieSetOnIpBlock' => false,
			'wgCookiePrefix' => 'wiki',
			'wgSecretKey' => MWCryptRand::generateHex( 64, true ),
		] );

		// setup block
		$block = new DatabaseBlock( [
			'expiry' => wfTimestamp( TS_MW, wfTimestamp() + ( 5 * 60 * 60 ) ),
		] );
		$block->setTarget( '1.2.3.4' );
		$block->setBlocker( $this->getTestSysop()->getUser() );
		$block->insert();

		// setup request
		$request = new FauxRequest();
		$request->setIP( '1.2.3.4' );

		// get user
		$user = User::newFromSession( $request );
		MediaWikiServices::getInstance()->getBlockManager()
			->trackBlockWithCookie( $user, $request->response() );

		// test cookie was not set
		$cookies = $request->response()->getCookies();
		$this->assertArrayNotHasKey( 'wikiBlockID', $cookies );

		// clean up
		$block->delete();
	}

	/**
	 * When an ip user is blocked and then they log in, cookie block
	 * should be invalid and the cookie removed.
	 * @covers User::trackBlockWithCookie
	 */
	public function testIpBlockCookieIgnoredWhenUserLoggedIn() {
		$this->setMwGlobals( [
			'wgAutoblockExpiry' => 8000,
			'wgCookieSetOnIpBlock' => true,
			'wgCookiePrefix' => 'wiki',
			'wgSecretKey' => MWCryptRand::generateHex( 64, true ),
		] );

		$blockManager = MediaWikiServices::getInstance()->getBlockManager();

		// setup block
		$block = new DatabaseBlock( [
			'expiry' => wfTimestamp( TS_MW, wfTimestamp() + ( 40 * 60 * 60 ) ),
		] );
		$block->setTarget( '1.2.3.4' );
		$block->setBlocker( $this->getTestSysop()->getUser() );
		$block->insert();

		// setup request
		$request = new FauxRequest();
		$request->setIP( '1.2.3.4' );
		$request->getSession()->setUser( $this->getTestUser()->getUser() );
		$request->setCookie( 'BlockID', $blockManager->getCookieValue( $block ) );

		// setup user
		$user = User::newFromSession( $request );

		// logged in users should be inmune to cookie block of type ip/range
		$this->assertNull( $user->getBlock() );

		// clean up
		$block->delete();
	}

	/**
	 * @covers User::getFirstEditTimestamp
	 * @covers User::getLatestEditTimestamp
	 */
	public function testGetFirstLatestEditTimestamp() {
		$clock = MWTimestamp::convert( TS_UNIX, '20100101000000' );
		MWTimestamp::setFakeTime( function () use ( &$clock ) {
			return $clock += 1000;
		} );
		try {
			$user = $this->getTestUser()->getUser();
			$firstRevision = self::makeEdit( $user, 'Help:UserTest_GetEditTimestamp', 'one', 'test' );
			$secondRevision = self::makeEdit( $user, 'Help:UserTest_GetEditTimestamp', 'two', 'test' );
			// Sanity check: revisions timestamp are different
			$this->assertNotEquals( $firstRevision->getTimestamp(), $secondRevision->getTimestamp() );

			$this->assertEquals( $firstRevision->getTimestamp(), $user->getFirstEditTimestamp() );
			$this->assertEquals( $secondRevision->getTimestamp(), $user->getLatestEditTimestamp() );
		} finally {
			MWTimestamp::setFakeTime( false );
		}
	}

	/**
	 * @param User $user
	 * @param string $title
	 * @param string $content
	 * @param string $comment
	 * @return \MediaWiki\Revision\RevisionRecord|null
	 */
	private static function makeEdit( User $user, $title, $content, $comment ) {
		$page = WikiPage::factory( Title::newFromText( $title ) );
		$content = ContentHandler::makeContent( $content, $page->getTitle() );
		$updater = $page->newPageUpdater( $user );
		$updater->setContent( 'main', $content );
		return $updater->saveRevision( CommentStoreComment::newUnsavedComment( $comment ) );
	}

	/**
	 * @covers User::idFromName
	 */
	public function testExistingIdFromName() {
		$this->assertTrue(
			array_key_exists( $this->user->getName(), User::$idCacheByName ),
			'Test user should already be in the id cache.'
		);
		$this->assertSame(
			$this->user->getId(), User::idFromName( $this->user->getName() ),
			'Id is correctly retreived from the cache.'
		);
		$this->assertSame(
			$this->user->getId(), User::idFromName( $this->user->getName(), User::READ_LATEST ),
			'Id is correctly retreived from the database.'
		);
	}

	/**
	 * @covers User::idFromName
	 */
	public function testNonExistingIdFromName() {
		$this->assertFalse(
			array_key_exists( 'NotExisitngUser', User::$idCacheByName ),
			'Non exisitng user should not be in the id cache.'
		);
		$this->assertNull( User::idFromName( 'NotExisitngUser' ) );
		$this->assertTrue(
			array_key_exists( 'NotExisitngUser', User::$idCacheByName ),
			'Username will be cached when requested once.'
		);
		$this->assertNull( User::idFromName( 'NotExistingUser' ) );
		$this->assertNull( User::idFromName( 'Illegal|Name' ) );
	}

	/**
	 * @covers User::isSystemUser
	 */
	public function testIsSystemUser() {
		$user = static::getTestUser()->getUser();
		$this->assertFalse( $user->isSystemUser(), 'Normal users are not system users' );

		$user = User::newSystemUser( __METHOD__ );
		$this->assertTrue( $user->isSystemUser(), 'Users created with newSystemUser() are system users' );
	}

	/**
	 * @covers User::newSystemUser
	 * @dataProvider provideNewSystemUser
	 * @param string $exists How/whether to create the user before calling User::newSystemUser
	 *  - 'missing': Do not create the user
	 *  - 'actor': Create an anonymous actor
	 *  - 'user': Create a non-system user
	 *  - 'system': Create a system user
	 * @param string $options Options to User::newSystemUser
	 * @param array $testOpts Test options
	 * @param string $expect 'user', 'exception', or 'null'
	 */
	public function testNewSystemUser( $exists, $options, $testOpts, $expect ) {
		$origUser = null;
		$actorId = null;

		switch ( $exists ) {
			case 'missing':
				$name = 'TestNewSystemUser ' . TestUserRegistry::getNextId();
				break;

			case 'actor':
				$name = 'TestNewSystemUser ' . TestUserRegistry::getNextId();
				$this->db->insert( 'actor', [ 'actor_name' => $name ] );
				$actorId = (int)$this->db->insertId();
				break;

			case 'user':
				$origUser = $this->getMutableTestUser()->getUser();
				$name = $origUser->getName();
				$actorId = $origUser->getActorId();
				break;

			case 'system':
				$name = 'TestNewSystemUser ' . TestUserRegistry::getNextId();
				$user = User::newSystemUser( $name ); // Heh.
				$actorId = $user->getActorId();
				// Use this hook as a proxy for detecting when a "steal" happens.
				$this->setTemporaryHook( 'InvalidateEmailComplete', function () {
					$this->fail( 'InvalidateEmailComplete hook should not have been called' );
				} );
				break;
		}

		$globals = $testOpts['globals'] ?? [];
		if ( !empty( $testOpts['reserved'] ) ) {
			$globals['wgReservedUsernames'] = [ $name ];
		}
		$this->setMwGlobals( $globals );
		$this->assertTrue( User::isValidUserName( $name ) );
		$this->assertSame( empty( $testOpts['reserved'] ), User::isUsableName( $name ) );

		if ( $expect === 'exception' ) {
			$this->expectException( Exception::class );
		}
		$user = User::newSystemUser( $name, $options );
		if ( $expect === 'null' ) {
			$this->assertNull( $user );
			if ( $origUser ) {
				$this->assertNotSame(
					User::INVALID_TOKEN, TestingAccessWrapper::newFromObject( $origUser )->mToken
				);
				$this->assertNotSame( '', $origUser->getEmail() );
				$this->assertFalse( $origUser->isSystemUser(), 'Normal users should not be system users' );
			}
		} else {
			$this->assertInstanceOf( User::class, $user );
			$this->assertSame( $name, $user->getName() );
			if ( $actorId !== null ) {
				$this->assertSame( $actorId, $user->getActorId() );
			}
			$this->assertSame( User::INVALID_TOKEN, TestingAccessWrapper::newFromObject( $user )->mToken );
			$this->assertSame( '', $user->getEmail() );
			$this->assertTrue( $user->isSystemUser(), 'Newly created system users should be system users' );
		}
	}

	public static function provideNewSystemUser() {
		return [
			'Basic creation' => [ 'missing', [], [], 'user' ],
			'No creation' => [ 'missing', [ 'create' => false ], [], 'null' ],
			'Validation fail' => [
				'missing',
				[ 'validate' => 'usable' ],
				[ 'reserved' => true ],
				'null'
			],
			'No stealing' => [ 'user', [], [], 'null' ],
			'Stealing allowed' => [ 'user', [ 'steal' => true ], [], 'user' ],
			'Stealing an already-system user' => [ 'system', [ 'steal' => true ], [], 'user' ],
			'Anonymous actor (T236444)' => [ 'actor', [], [ 'reserved' => true ], 'user' ],
			'Reserved but no anonymous actor' => [ 'missing', [], [ 'reserved' => true ], 'user' ],
			'Anonymous actor but no creation' => [ 'actor', [ 'create' => false ], [], 'null' ],
			'Anonymous actor but not reserved' => [ 'actor', [], [], 'exception' ],
		];
	}

	/**
	 * @covers User::getDefaultOption
	 * @covers User::getDefaultOptions
	 */
	public function testDefaultOptions() {
		User::resetGetDefaultOptionsForTestsOnly();

		$this->setTemporaryHook( 'UserGetDefaultOptions', function ( &$defaults ) {
			$defaults['extraoption'] = 42;
		} );

		$defaultOptions = User::getDefaultOptions();
		$this->assertArrayHasKey( 'search-match-redirect', $defaultOptions );
		$this->assertArrayHasKey( 'extraoption', $defaultOptions );

		$extraOption = User::getDefaultOption( 'extraoption' );
		$this->assertSame( 42, $extraOption );
	}

	/**
	 * @covers User::getAutomaticGroups
	 */
	public function testGetAutomaticGroups() {
		$user = $this->getTestUser()->getUser();
		$this->assertArrayEquals( [
			'*',
			'user',
			'autoconfirmed'
		], $user->getAutomaticGroups( true ) );
		$user = $this->getTestUser( [ 'bureaucrat', 'test' ] )->getUser();
		$this->assertArrayEquals( [
			'*',
			'user',
			'autoconfirmed'
		], $user->getAutomaticGroups( true ) );
		$user->addGroup( 'something' );
		$this->assertArrayEquals( [
			'*',
			'user',
			'autoconfirmed'
		], $user->getAutomaticGroups( true ) );
		$user = User::newFromName( 'UTUser1' );
		$this->assertArrayEquals( [
			'*',
		], $user->getAutomaticGroups( true ) );
		$this->setMwGlobals( [
			'wgAutopromote' => [
				'dummy' => APCOND_EMAILCONFIRMED
			]
		] );
		$user = $this->getTestUser()->getUser();
		$user->confirmEmail();
		$this->assertArrayEquals( [
			'*',
			'user',
			'dummy'
		], $user->getAutomaticGroups( true ) );
		$user = $this->getTestUser( [ 'dummy' ] )->getUser();
		$user->confirmEmail();
		$this->assertArrayEquals( [
			'*',
			'user',
			'dummy'
		], $user->getAutomaticGroups( true ) );
	}

	/**
	 * @covers User::getEffectiveGroups
	 */
	public function testGetEffectiveGroups() {
		$user = $this->getTestUser()->getUser();
		$this->assertArrayEquals( [
			'*',
			'user',
			'autoconfirmed'
		], $user->getEffectiveGroups( true ) );
		$user = $this->getTestUser( [ 'bureaucrat', 'test' ] )->getUser();
		$this->assertArrayEquals( [
			'*',
			'user',
			'autoconfirmed',
			'bureaucrat',
			'test'
		], $user->getEffectiveGroups( true ) );
		$user = $this->getTestUser( [ 'autoconfirmed', 'test' ] )->getUser();
		$this->assertArrayEquals( [
			'*',
			'user',
			'autoconfirmed',
			'test'
		], $user->getEffectiveGroups( true ) );
	}

	/**
	 * @covers User::getGroups
	 */
	public function testGetGroups() {
		$user = $this->getTestUser()->getUser();
		$reflectionClass = new ReflectionClass( 'User' );
		$reflectionProperty = $reflectionClass->getProperty( 'mLoadedItems' );
		$reflectionProperty->setAccessible( true );
		$reflectionProperty->setValue( $user, true );
		$reflectionProperty = $reflectionClass->getProperty( 'mGroupMemberships' );
		$reflectionProperty->setAccessible( true );
		$reflectionProperty->setValue( $user, [ 'a' => 1, 'b' => 2 ] );
		$this->assertArrayEquals( [ 'a', 'b' ], $user->getGroups() );
	}

	/**
	 * @covers User::getFormerGroups
	 */
	public function testGetFormerGroups() {
		$user = $this->getTestUser()->getUser();
		$reflectionClass = new ReflectionClass( 'User' );
		$reflectionProperty = $reflectionClass->getProperty( 'mFormerGroups' );
		$reflectionProperty->setAccessible( true );
		$reflectionProperty->setValue( $user, [ 1, 2, 3 ] );
		$this->assertArrayEquals( [ 1, 2, 3 ], $user->getFormerGroups() );
		$reflectionProperty->setValue( $user, null );
		$this->assertArrayEquals( [], $user->getFormerGroups() );
		$user->addGroup( 'test' );
		$user->removeGroup( 'test' );
		$reflectionProperty->setValue( $user, null );
		$this->assertArrayEquals( [ 'test' ], $user->getFormerGroups() );
	}

	/**
	 * @covers User::addGroup
	 */
	public function testAddGroup() {
		$user = $this->getTestUser()->getUser();
		$this->assertArrayEquals( [], $user->getGroups() );

		$this->assertTrue( $user->addGroup( 'test', '20010115123456' ) );
		$this->assertArrayEquals( [ 'test' ], $user->getGroups() );

		$this->setTemporaryHook( 'UserAddGroup', function ( $user, &$group, &$expiry ) {
			return false;
		} );
		$this->assertFalse( $user->addGroup( 'test2' ) );
		$this->assertArrayEquals(
			[ 'test' ],
			$user->getGroups(),
			'Hooks can stop addition of a group'
		);
	}

	/**
	 * @covers User::removeGroup
	 */
	public function testRemoveGroup() {
		$user = $this->getTestUser( [ 'test', 'test3' ] )->getUser();

		$this->assertTrue( $user->removeGroup( 'test' ) );
		$this->assertArrayEquals( [ 'test3' ], $user->getGroups() );

		$this->assertFalse(
			$user->removeGroup( 'test2' ),
			'A group membership that does not exist cannot be removed'
		);

		$this->setTemporaryHook( 'UserRemoveGroup', function ( $user, &$group ) {
			return false;
		} );

		$this->assertFalse( $user->removeGroup( 'test3' ) );
		$this->assertArrayEquals(
			[ 'test3' ],
			$user->getGroups(),
			'Hooks can stop removal of a group'
		);
	}

	/**
	 * @covers User::changeableGroups
	 */
	public function testChangeableGroups() {
		// todo: test changeableByGroup here as well
		$this->setMwGlobals( [
			'wgGroupPermissions' => [
				'doEverything' => [
					'userrights' => true,
				],
			],
			'wgAddGroups' => [
				'sysop' => [ 'rollback' ],
				'bureaucrat' => [ 'sysop', 'bureaucrat' ],
			],
			'wgRemoveGroups' => [
				'sysop' => [ 'rollback' ],
				'bureaucrat' => [ 'sysop' ],
			],
			'wgGroupsAddToSelf' => [
				'sysop' => [ 'flood' ],
			],
			'wgGroupsRemoveFromSelf' => [
				'flood' => [ 'flood' ],
			],
		] );

		$allGroups = User::getAllGroups();

		$user = $this->getTestUser( [ 'doEverything' ] )->getUser();
		$changeableGroups = $user->changeableGroups();
		$this->assertGroupsEquals(
			[
				'add' => $allGroups,
				'remove' => $allGroups,
				'add-self' => [],
				'remove-self' => [],
			],
			$changeableGroups
		);

		$user = $this->getTestUser( [ 'bureaucrat', 'sysop' ] )->getUser();
		$changeableGroups = $user->changeableGroups();
		$this->assertGroupsEquals(
			[
				'add' => [ 'bureaucrat', 'sysop', 'rollback' ],
				'remove' => [ 'sysop', 'rollback' ],
				'add-self' => [ 'flood' ],
				'remove-self' => [],
			],
			$changeableGroups
		);

		$user = $this->getTestUser( [ 'flood' ] )->getUser();
		$changeableGroups = $user->changeableGroups();
		$this->assertGroupsEquals(
			[
				'add' => [],
				'remove' => [],
				'add-self' => [],
				'remove-self' => [ 'flood' ],
			],
			$changeableGroups
		);
	}

	private function assertGroupsEquals( $expected, $actual ) {
		// assertArrayEquals can compare without requiring the same order,
		// but the elements of an array are still required to be in the same order,
		// so just compare each element
		$this->assertArrayEquals( $expected['add'], $actual['add'] );
		$this->assertArrayEquals( $expected['remove'], $actual['remove'] );
		$this->assertArrayEquals( $expected['add-self'], $actual['add-self'] );
		$this->assertArrayEquals( $expected['remove-self'], $actual['remove-self'] );
	}

	/**
	 * @covers User::isWatched
	 * @covers User::addWatch
	 * @covers User::removeWatch
	 */
	public function testWatchlist() {
		$user = $this->getTestUser()->getUser();
		$specialTitle = Title::newFromText( 'Special:Version' );
		$articleTitle = Title::newFromText( 'FooBar' );

		$this->assertFalse( $user->isWatched( $specialTitle ), 'Special pages cannot be watched' );
		$this->assertFalse( $user->isWatched( $articleTitle ), 'The article has not been watched yet' );

		$user->addWatch( $articleTitle );
		$this->assertTrue( $user->isWatched( $articleTitle ), 'The article has been watched' );

		$user->removeWatch( $articleTitle );
		$this->assertFalse( $user->isWatched( $articleTitle ), 'The article has been unwatched' );
	}

	/**
	 * @covers User::getName
	 * @covers User::setName
	 */
	public function testUserName() {
		$user = User::newFromName( 'DannyS712' );
		$this->assertSame(
			'DannyS712',
			$user->getName(),
			'Santiy check: Users created using ::newFromName should return the name used'
		);

		$user->setName( 'FooBarBaz' );
		$this->assertSame(
			'FooBarBaz',
			$user->getName(),
			'Changing a username via ::setName should be reflected in ::getName'
		);
	}

	/**
	 * @covers User::getEmail
	 * @covers User::setEmail
	 * @covers User::invalidateEmail
	 */
	public function testUserEmail() {
		$user = $this->getTestUser()->getUser();

		$user->setEmail( 'TestEmail@mediawiki.org' );
		$this->assertSame(
			'TestEmail@mediawiki.org',
			$user->getEmail(),
			'Setting an email via ::setEmail should be reflected in ::getEmail'
		);

		$this->setTemporaryHook( 'UserSetEmail', function ( $user, &$email ) {
			$this->fail(
				'UserSetEmail hook should not be called when the new email ' .
				'is the same as the old email.'
			);
		} );
		$user->setEmail( 'TestEmail@mediawiki.org' );

		// Unregister failing
		$this->mergeMwGlobalArrayValue( 'wgHooks', [
			'UserSetEmail' => []
		] );

		$this->setTemporaryHook( 'UserSetEmail', function ( $user, &$email ) {
			$email = 'SettingIntercepted@mediawiki.org';
		} );
		$user->setEmail( 'NewEmail@mediawiki.org' );
		$this->assertSame(
			'SettingIntercepted@mediawiki.org',
			$user->getEmail(),
			'Hooks can override setting email addresses'
		);

		$this->setTemporaryHook( 'UserGetEmail', function ( $user, &$email ) {
			$email = 'GettingIntercepted@mediawiki.org';
		} );
		$this->assertSame(
			'GettingIntercepted@mediawiki.org',
			$user->getEmail(),
			'Hooks can override getting email address'
		);

		// Unregister hooks
		$this->mergeMwGlobalArrayValue( 'wgHooks', [
			'UserSetEmail' => [],
			'UserGetEmail' => []
		] );
		$user->invalidateEmail();
		$this->assertSame(
			'',
			$user->getEmail(),
			'After invalidation, a user email should be an empty string'
		);
	}

	/**
	 * @covers User::isItemLoaded
	 * @covers User::setItemLoaded
	 */
	public function testItemLoaded() {
		$user = User::newFromName( 'DannyS712' );
		$this->assertTrue(
			$user->isItemLoaded( 'name', 'only' ),
			'Users created by name have user names loaded'
		);
		$this->assertFalse(
			$user->isItemLoaded( 'all', 'all' ),
			'Not everything is loaded yet'
		);
		$user->load();
		$this->assertTrue(
			$user->isItemLoaded( 'FooBar', 'all' ),
			'All items now loaded'
		);
	}

	/**
	 * @covers User::requiresHTTPS
	 * @dataProvider provideRequiresHTTPS
	 */
	public function testRequiresHTTPS( $preference, $hook1, $hook2, bool $expected ) {
		$this->setMwGlobals( [
			'wgSecureLogin' => true,
		] );

		$user = User::newFromName( 'UserWhoMayRequireHTTPS' );
		$user->setOption( 'prefershttps', $preference );
		$user->saveSettings();

		$this->setTemporaryHook( 'UserRequiresHTTPS', function ( $user, &$https ) use ( $hook1 ) {
			$https = $hook1;
			return false;
		} );
		$this->setTemporaryHook( 'CanIPUseHTTPS', function ( $ip, &$canDo ) use ( $hook2 ) {
			if ( $hook2 === 'notcalled' ) {
				$this->fail( 'CanIPUseHTTPS hook should not have been called' );
			}
			$canDo = $hook2;
			return false;
		} );

		$user = User::newFromName( $user->getName() );
		$this->assertSame( $user->requiresHTTPS(), $expected );
	}

	public static function provideRequiresHTTPS() {
		return [
			'Wants, hook requires, can' => [ true, true, true, true ],
			'Wants, hook requires, cannot' => [ true, true, false, false ],
			'Wants, hook prohibits, not called' => [ true, false, 'notcalled', false ],
			'Does not want, hook requires, can' => [ false, true, true, true ],
			'Does not want, hook requires, cannot' => [ false, true, false, false ],
			'Does not want, hook prohibits, not called' => [ false, false, 'notcalled', false ],
		];
	}

	/**
	 * @covers User::requiresHTTPS
	 */
	public function testRequiresHTTPS_disabled() {
		$this->setMwGlobals( [
			'wgSecureLogin' => false,
		] );

		$user = User::newFromName( 'UserWhoMayRequireHTTP' );
		$user->setOption( 'prefershttps', true );
		$user->saveSettings();

		$user = User::newFromName( $user->getName() );
		$this->assertFalse(
			$user->requiresHTTPS(),
			'User preference ignored if wgSecureLogin  is false'
		);
	}

	/**
	 * @covers User::isCreatableName
	 */
	public function testIsCreatableName() {
		$this->setMwGlobals( [
			'wgInvalidUsernameCharacters' => '@',
		] );

		// phpcs:ignore Generic.Files.LineLength
		$longUserName = 'abcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyz';

		$this->assertFalse(
			User::isCreatableName( $longUserName ),
			'longUserName is too long'
		);
		$this->assertFalse(
			User::isCreatableName( 'Foo@Bar' ),
			'User name contains invalid character'
		);
		$this->assertTrue(
			User::isCreatableName( 'FooBar' ),
			'User names with no issues can be created'
		);
	}

	/**
	 * @covers User::isUsableName
	 */
	public function testIsUsableName() {
		$this->setMwGlobals( [
			'wgReservedUsernames' => [
				'MediaWiki default',
				'msg:reserved-user'
			],
			'wgForceUIMsgAsContentMsg' => [
				'reserved-user'
			],
		] );

		$this->assertFalse(
			User::isUsableName( '' ),
			'Only valid user names are creatable'
		);
		$this->assertFalse(
			User::isUsableName( 'MediaWiki default' ),
			'Reserved names cannot be used'
		);
		$this->assertFalse(
			User::isUsableName( 'reserved-user' ),
			'Names can also be reserved via msg: '
		);
		$this->assertTrue(
			User::isUsableName( 'FooBar' ),
			'User names with no issues can be used'
		);
	}

	/**
	 * @covers User::addToDatabase
	 */
	public function testAddToDatabase_bad() {
		$user = new User();
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage(
			'User name field is not set.'
		);
		$user->addToDatabase();
	}

	/**
	 * @covers User::pingLimiter
	 */
	public function testPingLimiter() {
		$user = $this->getTestUser()->getUser();
		$this->setMwGlobals( [
			'wgRateLimits' => [
				'edit' => [
					'user' => [ 3, 60 ],
				],
			],
		] );

		// Hook leaves $result false
		$this->setTemporaryHook(
			'PingLimiter',
			function ( &$user, $action, &$result, $incrBy ) {
				return false;
			}
		);
		$this->assertFalse(
			$user->pingLimiter(),
			'Hooks that just return false leave $result false'
		);
		$this->mergeMwGlobalArrayValue( 'wgHooks', [
			'PingLimiter' => []
		] );

		// Hook sets $result to true
		$this->setTemporaryHook(
			'PingLimiter',
			function ( &$user, $action, &$result, $incrBy ) {
				$result = true;
				return false;
			}
		);
		$this->assertTrue(
			$user->pingLimiter(),
			'Hooks can set $result to true'
		);
		$this->mergeMwGlobalArrayValue( 'wgHooks', [
			'PingLimiter' => []
		] );

		// Unknown action
		$this->assertFalse(
			$user->pingLimiter( 'FakeActionWithNoRateLimit' ),
			'Actions with no rate limit set do not trip the rate limiter'
		);
	}

}
