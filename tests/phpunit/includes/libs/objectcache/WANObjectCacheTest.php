<?php /** @noinspection PhpStaticAsDynamicMethodCallInspection */

use Wikimedia\TestingAccessWrapper;

/**
 * @covers WANObjectCache::wrap
 * @covers WANObjectCache::unwrap
 * @covers WANObjectCache::worthRefreshExpiring
 * @covers WANObjectCache::worthRefreshPopular
 * @covers WANObjectCache::isValid
 * @covers WANObjectCache::getWarmupKeyMisses
 * @covers WANObjectCache::makeSisterKey
 * @covers WANObjectCache::makeSisterKeys
 * @covers WANObjectCache::extractBaseKey
 * @covers WANObjectCache::getProcessCache
 * @covers WANObjectCache::getNonProcessCachedMultiKeys
 * @covers WANObjectCache::getRawKeysForWarmup
 * @covers WANObjectCache::getInterimValue
 * @covers WANObjectCache::setInterimValue
 */
class WANObjectCacheTest extends PHPUnit\Framework\TestCase {

	use MediaWikiCoversValidator;

	/**
	 * @param array $params [optional]
	 * @return WANObjectCache[]|BagOStuff[] (WANObjectCache, BagOStuff)
	 */
	private function newWanCache( array $params = [] ) {
		if ( !empty( $params['mcrouterAware'] ) ) {
			// Convert mcrouter broadcast keys to regular keys in HashBagOStuff::delete() calls
			$bag = new McrouterHashBagOStuff();
		} else {
			$bag = new HashBagOStuff();
		}

		$cache = new WANObjectCache( [ 'cache' => $bag ] + $params );

		return [ $cache, $bag ];
	}

	/**
	 * @dataProvider provideSetAndGet
	 * @covers WANObjectCache::set()
	 * @covers WANObjectCache::get()
	 * @covers WANObjectCache::makeKey()
	 * @param mixed $value
	 * @param int $ttl
	 */
	public function testSetAndGet( $value, $ttl ) {
		list( $cache ) = $this->newWanCache();

		$curTTL = null;
		$asOf = null;
		$key = $cache->makeKey( 'x', wfRandomString() );

		$cache->get( $key, $curTTL, [], $asOf );
		$this->assertSame( null, $curTTL, "Current TTL (absent)" );
		$this->assertSame( null, $asOf, "Current as-of-time (absent)" );

		$t = microtime( true );

		$cache->set( $key, $value, $cache::TTL_UNCACHEABLE );
		$cache->get( $key, $curTTL, [], $asOf );
		$this->assertSame( null, $curTTL, "Current TTL (TTL_UNCACHEABLE)" );
		$this->assertSame( null, $asOf, "Current as-of-time (TTL_UNCACHEABLE)" );

		$cache->set( $key, $value, $ttl );

		$this->assertSame( $value, $cache->get( $key, $curTTL, [], $asOf ) );
		if ( $ttl === INF ) {
			$this->assertSame( INF, $curTTL, "Current TTL" );
		} else {
			$this->assertGreaterThan( 0, $curTTL, "Current TTL" );
			$this->assertLessThanOrEqual( $ttl, $curTTL, "Current TTL < nominal TTL" );
		}
		$this->assertGreaterThanOrEqual( $t - 1, $asOf, "As-of-time in range of set() time" );
		$this->assertLessThanOrEqual( $t + 1, $asOf, "As-of-time in range of set() time" );
	}

	public static function provideSetAndGet() {
		return [
			// value, ttl
			[ 14141, 3 ],
			[ 3535.666, 3 ],
			[ [], 3 ],
			[ '0', 3 ],
			[ (object)[ 'meow' ], 3 ],
			[ INF, 3 ],
			[ '', 3 ],
			[ 'pizzacat', INF ],
			[ null, 80 ]
		];
	}

	/**
	 * @covers WANObjectCache::get()
	 * @covers WANObjectCache::makeGlobalKey()
	 */
	public function testGetNotExists() {
		list( $cache ) = $this->newWanCache();

		$key = $cache->makeGlobalKey( 'y', wfRandomString(), 'p' );
		$curTTL = null;
		$value = $cache->get( $key, $curTTL );

		$this->assertSame( false, $value, "Return value" );
		$this->assertSame( null, $curTTL, "current TTL" );
	}

	/**
	 * @covers WANObjectCache::set()
	 */
	public function testSetOver() {
		list( $cache ) = $this->newWanCache();

		$key = wfRandomString();
		for ( $i = 0; $i < 3; ++$i ) {
			$value = wfRandomString();
			$cache->set( $key, $value, 3 );

			$this->assertSame( $cache->get( $key ), $value );
		}
	}

	/**
	 * @covers WANObjectCache::set()
	 */
	public function testStaleSet() {
		list( $cache ) = $this->newWanCache();

		$key = wfRandomString();
		$value = wfRandomString();
		$cache->set( $key, $value, 3, [ 'since' => microtime( true ) - 30 ] );

		$this->assertSame( false, $cache->get( $key ), "Stale set() value ignored" );
	}

	/**
	 * @covers WANObjectCache::getWithSetCallback
	 */
	public function testProcessCacheTTL() {
		list( $cache ) = $this->newWanCache();
		$mockWallClock = 1549343530.2053;
		$cache->setMockTime( $mockWallClock );

		$key = "mykey-" . wfRandomString();

		$hits = 0;
		$callback = function ( $oldValue, &$ttl, &$setOpts ) use ( &$hits ) {
			++$hits;
			return 42;
		};

		$cache->getWithSetCallback( $key, 100, $callback, [ 'pcTTL' => 5 ] );
		$cache->delete( $key, $cache::HOLDOFF_NONE ); // clear persistent cache
		$cache->getWithSetCallback( $key, 100, $callback, [ 'pcTTL' => 5 ] );
		$this->assertSame( 1, $hits, "Value process cached" );

		$mockWallClock += 6;
		$cache->getWithSetCallback( $key, 100, $callback, [ 'pcTTL' => 5 ] );
		$this->assertSame( 2, $hits, "Value expired in process cache" );
	}

	/**
	 * @covers WANObjectCache::getWithSetCallback
	 */
	public function testProcessCacheLruAndDelete() {
		list( $cache ) = $this->newWanCache();
		$mockWallClock = 1549343530.2053;
		$cache->setMockTime( $mockWallClock );

		$hit = 0;
		$fn = function () use ( &$hit ) {
			++$hit;
			return 42;
		};
		$keysA = [ wfRandomString(), wfRandomString(), wfRandomString() ];
		$keysB = [ wfRandomString(), wfRandomString(), wfRandomString() ];
		$pcg = [ 'thiscache:1', 'thatcache:1', 'somecache:1' ];

		foreach ( $keysA as $i => $key ) {
			$cache->getWithSetCallback( $key, 100, $fn, [ 'pcTTL' => 5, 'pcGroup' => $pcg[$i] ] );
		}
		$this->assertSame( 3, $hit, "Values not cached yet" );

		foreach ( $keysA as $i => $key ) {
			// Should not evict from process cache
			$cache->delete( $key );
			$cache->getWithSetCallback( $key, 100, $fn, [ 'pcTTL' => 5, 'pcGroup' => $pcg[$i] ] );
		}
		$this->assertSame( 3, $hit, "Values cached; not cleared by delete()" );

		foreach ( $keysB as $i => $key ) {
			$cache->getWithSetCallback( $key, 100, $fn, [ 'pcTTL' => 5, 'pcGroup' => $pcg[$i] ] );
		}
		$this->assertSame( 6, $hit, "New values not cached yet" );

		foreach ( $keysB as $i => $key ) {
			$cache->getWithSetCallback( $key, 100, $fn, [ 'pcTTL' => 5, 'pcGroup' => $pcg[$i] ] );
		}
		$this->assertSame( 6, $hit, "New values cached" );

		foreach ( $keysA as $i => $key ) {
			$cache->getWithSetCallback( $key, 100, $fn, [ 'pcTTL' => 5, 'pcGroup' => $pcg[$i] ] );
		}
		$this->assertSame( 9, $hit, "Prior values evicted by new values" );
	}

	/**
	 * @covers WANObjectCache::getWithSetCallback
	 */
	public function testProcessCacheInterimKeys() {
		list( $cache ) = $this->newWanCache();
		$mockWallClock = 1549343530.2053;
		$cache->setMockTime( $mockWallClock );

		$hit = 0;
		$fn = function () use ( &$hit ) {
			++$hit;
			return 42;
		};
		$keysA = [ wfRandomString(), wfRandomString(), wfRandomString() ];
		$pcg = [ 'thiscache:1', 'thatcache:1', 'somecache:1' ];

		foreach ( $keysA as $i => $key ) {
			$cache->delete( $key ); // tombstone key
			$mockWallClock += 0.001; // cached values will be newer than tombstone
			// Get into process cache (specific group) and interim cache
			$cache->getWithSetCallback( $key, 100, $fn, [ 'pcTTL' => 5, 'pcGroup' => $pcg[$i] ] );
		}
		$this->assertSame( 3, $hit );

		// Get into process cache (default group)
		$key = reset( $keysA );
		$cache->getWithSetCallback( $key, 100, $fn, [ 'pcTTL' => 5 ] );
		$this->assertSame( 3, $hit, "Value recently interim-cached" );

		$mockWallClock += 0.2; // interim key not brand new
		$cache->clearProcessCache();
		$cache->getWithSetCallback( $key, 100, $fn, [ 'pcTTL' => 5 ] );
		$this->assertSame( 4, $hit, "Value calculated (interim key not recent and reset)" );
		$cache->getWithSetCallback( $key, 100, $fn, [ 'pcTTL' => 5 ] );
		$this->assertSame( 4, $hit, "Value process cached" );
	}

	/**
	 * @covers WANObjectCache::getWithSetCallback
	 */
	public function testProcessCacheNesting() {
		list( $cache ) = $this->newWanCache();
		$mockWallClock = 1549343530.2053;
		$cache->setMockTime( $mockWallClock );

		$keyOuter = "outer-" . wfRandomString();
		$keyInner = "inner-" . wfRandomString();

		$innerHit = 0;
		$innerFn = function () use ( &$innerHit ) {
			++$innerHit;
			return 42;
		};

		$outerHit = 0;
		$outerFn = function () use ( $keyInner, $innerFn, $cache, &$outerHit ) {
			++$outerHit;
			$v = $cache->getWithSetCallback( $keyInner, 100, $innerFn, [ 'pcTTL' => 5 ] );

			return 43 + $v;
		};

		$cache->getWithSetCallback( $keyInner, 100, $innerFn, [ 'pcTTL' => 5 ] );
		$cache->getWithSetCallback( $keyInner, 100, $innerFn, [ 'pcTTL' => 5 ] );

		$this->assertSame( 1, $innerHit, "Inner callback value cached" );
		$cache->delete( $keyInner, $cache::HOLDOFF_NONE );
		$mockWallClock += 1;

		$cache->getWithSetCallback( $keyInner, 100, $innerFn, [ 'pcTTL' => 5 ] );
		$this->assertSame( 1, $innerHit, "Inner callback process cached" );

		// Outer key misses and inner key process cache value is refused
		$cache->getWithSetCallback( $keyOuter, 100, $outerFn );

		$this->assertSame( 1, $outerHit, "Outer callback value not yet cached" );
		$this->assertSame( 2, $innerHit, "Inner callback value process cache skipped" );

		$cache->getWithSetCallback( $keyOuter, 100, $outerFn );

		$this->assertSame( 1, $outerHit, "Outer callback value cached" );

		$cache->delete( $keyInner, $cache::HOLDOFF_NONE );
		$cache->delete( $keyOuter, $cache::HOLDOFF_NONE );
		$mockWallClock += 1;
		$cache->clearProcessCache();
		$cache->getWithSetCallback( $keyOuter, 100, $outerFn );

		$this->assertSame( 2, $outerHit, "Outer callback value not yet cached" );
		$this->assertSame( 3, $innerHit, "Inner callback value not yet cached" );

		$cache->delete( $keyInner, $cache::HOLDOFF_NONE );
		$mockWallClock += 1;
		$cache->getWithSetCallback( $keyInner, 100, $innerFn, [ 'pcTTL' => 5 ] );

		$this->assertSame( 3, $innerHit, "Inner callback value process cached" );
	}

	/**
	 * @dataProvider getWithSetCallback_provider
	 * @covers WANObjectCache::getWithSetCallback()
	 * @covers WANObjectCache::fetchOrRegenerate()
	 * @param array $extOpts
	 */
	public function testGetWithSetCallback( array $extOpts ) {
		list( $cache ) = $this->newWanCache();

		$key = wfRandomString();
		$value = wfRandomString();
		$cKey1 = wfRandomString();
		$cKey2 = wfRandomString();

		$priorValue = null;
		$priorAsOf = null;
		$wasSet = 0;
		$func = function ( $old, &$ttl, &$opts, $asOf )
		use ( &$wasSet, &$priorValue, &$priorAsOf, $value ) {
			++$wasSet;
			$priorValue = $old;
			$priorAsOf = $asOf;
			$ttl = 20; // override with another value
			return $value;
		};

		$mockWallClock = 1549343530.2053;
		$priorTime = $mockWallClock; // reference time
		$cache->setMockTime( $mockWallClock );

		$wasSet = 0;
		$v = $cache->getWithSetCallback( $key, 30, $func, [ 'lockTSE' => 5 ] + $extOpts );
		$this->assertSame( $value, $v, "Value returned" );
		$this->assertSame( 1, $wasSet, "Value regenerated" );
		$this->assertSame( false, $priorValue, "No prior value" );
		$this->assertSame( null, $priorAsOf, "No prior value" );

		$curTTL = null;
		$cache->get( $key, $curTTL );
		$this->assertLessThanOrEqual( 20, $curTTL, 'Current TTL between 19-20 (overriden)' );
		$this->assertGreaterThanOrEqual( 19, $curTTL, 'Current TTL between 19-20 (overriden)' );

		$wasSet = 0;
		$v = $cache->getWithSetCallback(
			$key, 30, $func, [ 'lowTTL' => 0, 'lockTSE' => 5 ] + $extOpts );
		$this->assertSame( $value, $v, "Value returned" );
		$this->assertSame( 0, $wasSet, "Value not regenerated" );

		$mockWallClock += 1;

		$wasSet = 0;
		$v = $cache->getWithSetCallback(
			$key, 30, $func, [ 'checkKeys' => [ $cKey1, $cKey2 ] ] + $extOpts
		);
		$this->assertSame( $value, $v, "Value returned" );
		$this->assertSame( 1, $wasSet, "Value regenerated due to check keys" );
		$this->assertSame( $value, $priorValue, "Has prior value" );
		$this->assertIsFloat( $priorAsOf, "Has prior value" );
		$t1 = $cache->getCheckKeyTime( $cKey1 );
		$this->assertGreaterThanOrEqual( $priorTime, $t1, 'Check keys generated on miss' );
		$t2 = $cache->getCheckKeyTime( $cKey2 );
		$this->assertGreaterThanOrEqual( $priorTime, $t2, 'Check keys generated on miss' );

		$mockWallClock += 0.2; // interim key is not brand new and check keys have past values
		$priorTime = $mockWallClock; // reference time
		$wasSet = 0;
		$v = $cache->getWithSetCallback(
			$key, 30, $func, [ 'checkKeys' => [ $cKey1, $cKey2 ] ] + $extOpts
		);
		$this->assertSame( $value, $v, "Value returned" );
		$this->assertSame( 1, $wasSet, "Value regenerated due to still-recent check keys" );
		$t1 = $cache->getCheckKeyTime( $cKey1 );
		$this->assertLessThanOrEqual( $priorTime, $t1, 'Check keys did not change again' );
		$t2 = $cache->getCheckKeyTime( $cKey2 );
		$this->assertLessThanOrEqual( $priorTime, $t2, 'Check keys did not change again' );

		$curTTL = null;
		$v = $cache->get( $key, $curTTL, [ $cKey1, $cKey2 ] );
		$this->assertSame( $value, $v, "Value returned" );
		$this->assertLessThanOrEqual( 0, $curTTL, "Value has current TTL < 0 due to check keys" );

		$wasSet = 0;
		$key = wfRandomString();
		$v = $cache->getWithSetCallback( $key, 30, $func, [ 'pcTTL' => 5 ] + $extOpts );
		$this->assertSame( $value, $v, "Value returned" );
		$cache->delete( $key );
		$v = $cache->getWithSetCallback( $key, 30, $func, [ 'pcTTL' => 5 ] + $extOpts );
		$this->assertSame( $value, $v, "Value still returned after deleted" );
		$this->assertSame( 1, $wasSet, "Value process cached while deleted" );

		$oldValReceived = -1;
		$oldAsOfReceived = -1;
		$checkFunc = function ( $oldVal, &$ttl, array $setOpts, $oldAsOf )
		use ( &$oldValReceived, &$oldAsOfReceived, &$wasSet ) {
			++$wasSet;
			$oldValReceived = $oldVal;
			$oldAsOfReceived = $oldAsOf;

			return 'xxx' . $wasSet;
		};

		$mockWallClock = 1549343530.2053;
		$priorTime = $mockWallClock; // reference time

		$wasSet = 0;
		$key = wfRandomString();
		$v = $cache->getWithSetCallback(
			$key, 30, $checkFunc, [ 'staleTTL' => 50 ] + $extOpts );
		$this->assertSame( 'xxx1', $v, "Value returned" );
		$this->assertSame( false, $oldValReceived, "Callback got no stale value" );
		$this->assertSame( null, $oldAsOfReceived, "Callback got no stale value" );

		$mockWallClock += 40;
		$v = $cache->getWithSetCallback(
			$key, 30, $checkFunc, [ 'staleTTL' => 50 ] + $extOpts );
		$this->assertSame( 'xxx2', $v, "Value still returned after expired" );
		$this->assertSame( 2, $wasSet, "Value recalculated while expired" );
		$this->assertSame( 'xxx1', $oldValReceived, "Callback got stale value" );
		$this->assertNotEquals( null, $oldAsOfReceived, "Callback got stale value" );

		$mockWallClock += 260;
		$v = $cache->getWithSetCallback(
			$key, 30, $checkFunc, [ 'staleTTL' => 50 ] + $extOpts );
		$this->assertSame( 'xxx3', $v, "Value still returned after expired" );
		$this->assertSame( 3, $wasSet, "Value recalculated while expired" );
		$this->assertSame( false, $oldValReceived, "Callback got no stale value" );
		$this->assertSame( null, $oldAsOfReceived, "Callback got no stale value" );

		$mockWallClock = ( $priorTime - $cache::HOLDOFF_TTL - 1 );
		$wasSet = 0;
		$key = wfRandomString();
		$checkKey = $cache->makeKey( 'template', 'X' );
		$cache->touchCheckKey( $checkKey ); // init check key
		$mockWallClock = $priorTime;
		$v = $cache->getWithSetCallback(
			$key,
			$cache::TTL_INDEFINITE,
			$checkFunc,
			[ 'graceTTL' => $cache::TTL_WEEK, 'checkKeys' => [ $checkKey ] ] + $extOpts
		);
		$this->assertSame( 'xxx1', $v, "Value returned" );
		$this->assertSame( 1, $wasSet, "Value computed" );
		$this->assertSame( false, $oldValReceived, "Callback got no stale value" );
		$this->assertSame( null, $oldAsOfReceived, "Callback got no stale value" );

		$mockWallClock += $cache::TTL_HOUR; // some time passes
		$v = $cache->getWithSetCallback(
			$key,
			$cache::TTL_INDEFINITE,
			$checkFunc,
			[
				'graceTTL' => $cache::TTL_WEEK,
				'checkKeys' => [ $checkKey ],
				'ageNew' => -1
			] + $extOpts
		);
		$this->assertSame( 'xxx1', $v, "Cached value returned" );
		$this->assertSame( 1, $wasSet, "Cached value returned" );

		$cache->touchCheckKey( $checkKey ); // make key stale
		$mockWallClock += 0.01; // ~1 week left of grace (barely stale to avoid refreshes)

		$v = $cache->getWithSetCallback(
			$key,
			$cache::TTL_INDEFINITE,
			$checkFunc,
			[
				'graceTTL' => $cache::TTL_WEEK,
				'checkKeys' => [ $checkKey ],
				'ageNew' => -1,
			] + $extOpts
		);
		$this->assertSame( 'xxx1', $v, "Value still returned after expired (in grace)" );
		$this->assertSame( 1, $wasSet, "Value still returned after expired (in grace)" );

		// Chance of refresh increase to unity as staleness approaches graceTTL
		$mockWallClock += $cache::TTL_WEEK; // 8 days of being stale
		$v = $cache->getWithSetCallback(
			$key,
			$cache::TTL_INDEFINITE,
			$checkFunc,
			[ 'graceTTL' => $cache::TTL_WEEK, 'checkKeys' => [ $checkKey ] ] + $extOpts
		);
		$this->assertSame( 'xxx2', $v, "Value was recomputed (past grace)" );
		$this->assertSame( 2, $wasSet, "Value was recomputed (past grace)" );
		$this->assertSame( 'xxx1', $oldValReceived, "Callback got post-grace stale value" );
		$this->assertNotEquals( null, $oldAsOfReceived, "Callback got post-grace stale value" );
	}

	/**
	 * @dataProvider getWithSetCallback_provider
	 * @covers WANObjectCache::getWithSetCallback()
	 * @covers WANObjectCache::fetchOrRegenerate()
	 * @param array $extOpts
	 */
	public function testGetWithSetCallback_touched( array $extOpts ) {
		list( $cache ) = $this->newWanCache();

		$mockWallClock = 1549343530.2053;
		$cache->setMockTime( $mockWallClock );

		$checkFunc = function ( $oldVal, &$ttl, array $setOpts, $oldAsOf )
		use ( &$wasSet ) {
			++$wasSet;

			return 'xxx' . $wasSet;
		};

		$key = wfRandomString();
		$wasSet = 0;
		$touched = null;
		$touchedCallback = function () use ( &$touched ) {
			return $touched;
		};
		$v = $cache->getWithSetCallback(
			$key,
			$cache::TTL_INDEFINITE,
			$checkFunc,
			[ 'touchedCallback' => $touchedCallback ] + $extOpts
		);
		$mockWallClock += 60;
		$v = $cache->getWithSetCallback(
			$key,
			$cache::TTL_INDEFINITE,
			$checkFunc,
			[ 'touchedCallback' => $touchedCallback ] + $extOpts
		);
		$this->assertSame( 'xxx1', $v, "Value was computed once" );
		$this->assertSame( 1, $wasSet, "Value was computed once" );

		$touched = $mockWallClock - 10;
		$v = $cache->getWithSetCallback(
			$key,
			$cache::TTL_INDEFINITE,
			$checkFunc,
			[ 'touchedCallback' => $touchedCallback ] + $extOpts
		);
		$v = $cache->getWithSetCallback(
			$key,
			$cache::TTL_INDEFINITE,
			$checkFunc,
			[ 'touchedCallback' => $touchedCallback ] + $extOpts
		);
		$this->assertSame( 'xxx2', $v, "Value was recomputed once" );
		$this->assertSame( 2, $wasSet, "Value was recomputed once" );
	}

	public static function getWithSetCallback_provider() {
		return [
			[ [], false ],
			[ [ 'version' => 1 ], true ]
		];
	}

	public function testPreemtiveRefresh() {
		$value = 'KatCafe';
		$wasSet = 0;
		$func = function ( $old, &$ttl, &$opts, $asOf ) use ( &$wasSet, &$value )
		{
			++$wasSet;
			return $value;
		};

		$cache = new NearExpiringWANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$mockWallClock = 1549343530.2053;
		$cache->setMockTime( $mockWallClock );

		$wasSet = 0;
		$key = wfRandomString();
		$opts = [ 'lowTTL' => 30 ];
		$v = $cache->getWithSetCallback( $key, 20, $func, $opts );
		$this->assertSame( $value, $v, "Value returned" );
		$this->assertSame( 1, $wasSet, "Value calculated" );

		$mockWallClock += 0.2; // interim key is not brand new
		$v = $cache->getWithSetCallback( $key, 20, $func, $opts );
		$this->assertSame( 2, $wasSet, "Value re-calculated" );

		$wasSet = 0;
		$key = wfRandomString();
		$opts = [ 'lowTTL' => 1 ];
		$v = $cache->getWithSetCallback( $key, 30, $func, $opts );
		$this->assertSame( $value, $v, "Value returned" );
		$this->assertSame( 1, $wasSet, "Value calculated" );
		$v = $cache->getWithSetCallback( $key, 30, $func, $opts );
		$this->assertSame( 1, $wasSet, "Value cached" );

		$asycList = [];
		$asyncHandler = function ( $callback ) use ( &$asycList ) {
			$asycList[] = $callback;
		};
		$cache = new NearExpiringWANObjectCache( [
			'cache'        => new HashBagOStuff(),
			'asyncHandler' => $asyncHandler
		] );

		$mockWallClock = 1549343530.2053;
		$priorTime = $mockWallClock; // reference time
		$cache->setMockTime( $mockWallClock );

		$wasSet = 0;
		$key = wfRandomString();
		$opts = [ 'lowTTL' => 100 ];
		$v = $cache->getWithSetCallback( $key, 300, $func, $opts );
		$this->assertSame( $value, $v, "Value returned" );
		$this->assertSame( 1, $wasSet, "Value calculated" );
		$v = $cache->getWithSetCallback( $key, 300, $func, $opts );
		$this->assertSame( 1, $wasSet, "Cached value used" );
		$this->assertSame( $v, $value, "Value cached" );

		$mockWallClock += 250;
		$v = $cache->getWithSetCallback( $key, 300, $func, $opts );
		$this->assertSame( $value, $v, "Value returned" );
		$this->assertSame( 1, $wasSet, "Stale value used" );
		$this->assertSame( 1, count( $asycList ), "Refresh deferred." );
		$value = 'NewCatsInTown'; // change callback return value
		$asycList[0](); // run the refresh callback
		$asycList = [];
		$this->assertSame( 2, $wasSet, "Value calculated at later time" );
		$this->assertSame( [], $asycList, "No deferred refreshes added." );
		$v = $cache->getWithSetCallback( $key, 300, $func, $opts );
		$this->assertSame( $value, $v, "New value stored" );

		$cache = new PopularityRefreshingWANObjectCache( [
			'cache'   => new HashBagOStuff()
		] );

		$mockWallClock = $priorTime;
		$cache->setMockTime( $mockWallClock );

		$wasSet = 0;
		$key = wfRandomString();
		$opts = [ 'hotTTR' => 900 ];
		$v = $cache->getWithSetCallback( $key, 60, $func, $opts );
		$this->assertSame( $value, $v, "Value returned" );
		$this->assertSame( 1, $wasSet, "Value calculated" );

		$mockWallClock += 30;

		$v = $cache->getWithSetCallback( $key, 60, $func, $opts );
		$this->assertSame( 1, $wasSet, "Value cached" );

		$mockWallClock = $priorTime;
		$wasSet = 0;
		$key = wfRandomString();
		$opts = [ 'hotTTR' => 10 ];
		$v = $cache->getWithSetCallback( $key, 60, $func, $opts );
		$this->assertSame( $value, $v, "Value returned" );
		$this->assertSame( 1, $wasSet, "Value calculated" );

		$mockWallClock += 30;

		$v = $cache->getWithSetCallback( $key, 60, $func, $opts );
		$this->assertSame( $value, $v, "Value returned" );
		$this->assertSame( 2, $wasSet, "Value re-calculated" );
	}

	/**
	 * @dataProvider getMultiWithSetCallback_provider
	 * @covers WANObjectCache::getMultiWithSetCallback
	 * @covers WANObjectCache::makeMultiKeys
	 * @covers WANObjectCache::getMulti
	 * @param array $extOpts
	 */
	public function testGetMultiWithSetCallback( array $extOpts ) {
		list( $cache ) = $this->newWanCache();

		$keyA = wfRandomString();
		$keyB = wfRandomString();
		$keyC = wfRandomString();
		$cKey1 = wfRandomString();
		$cKey2 = wfRandomString();

		$priorValue = null;
		$priorAsOf = null;
		$wasSet = 0;
		$genFunc = function ( $id, $old, &$ttl, &$opts, $asOf ) use (
			&$wasSet, &$priorValue, &$priorAsOf
		) {
			++$wasSet;
			$priorValue = $old;
			$priorAsOf = $asOf;
			$ttl = 20; // override with another value
			return "@$id$";
		};

		$mockWallClock = 1549343530.2053;
		$priorTime = $mockWallClock; // reference time
		$cache->setMockTime( $mockWallClock );

		$wasSet = 0;
		$keyedIds = new ArrayIterator( [ $keyA => 3353 ] );
		$value = "@3353$";
		$v = $cache->getMultiWithSetCallback(
			$keyedIds, 30, $genFunc, [ 'lockTSE' => 5 ] + $extOpts );
		$this->assertSame( $value, $v[$keyA], "Value returned" );
		$this->assertSame( 1, $wasSet, "Value regenerated" );
		$this->assertSame( false, $priorValue, "No prior value" );
		$this->assertSame( null, $priorAsOf, "No prior value" );

		$curTTL = null;
		$cache->get( $keyA, $curTTL );
		$this->assertLessThanOrEqual( 20, $curTTL, 'Current TTL between 19-20 (overriden)' );
		$this->assertGreaterThanOrEqual( 19, $curTTL, 'Current TTL between 19-20 (overriden)' );

		$wasSet = 0;
		$value = "@efef$";
		$keyedIds = new ArrayIterator( [ $keyB => 'efef' ] );
		$v = $cache->getMultiWithSetCallback(
			$keyedIds, 30, $genFunc, [ 'lowTTL' => 0, 'lockTSE' => 5 ] + $extOpts );
		$this->assertSame( $value, $v[$keyB], "Value returned" );
		$this->assertSame( 1, $wasSet, "Value regenerated" );
		$this->assertSame( 0, $cache->getWarmupKeyMisses(), "Keys warmed in warmup cache" );

		$v = $cache->getMultiWithSetCallback(
			$keyedIds, 30, $genFunc, [ 'lowTTL' => 0, 'lockTSE' => 5 ] + $extOpts );
		$this->assertSame( $value, $v[$keyB], "Value returned" );
		$this->assertSame( 1, $wasSet, "Value not regenerated" );
		$this->assertSame( 0, $cache->getWarmupKeyMisses(), "Keys warmed in warmup cache" );

		$mockWallClock += 1;

		$wasSet = 0;
		$keyedIds = new ArrayIterator( [ $keyB => 'efef' ] );
		$v = $cache->getMultiWithSetCallback(
			$keyedIds, 30, $genFunc, [ 'checkKeys' => [ $cKey1, $cKey2 ] ] + $extOpts
		);
		$this->assertSame( $value, $v[$keyB], "Value returned" );
		$this->assertSame( 1, $wasSet, "Value regenerated due to check keys" );
		$this->assertSame( $value, $priorValue, "Has prior value" );
		$this->assertIsFloat( $priorAsOf, "Has prior value" );
		$t1 = $cache->getCheckKeyTime( $cKey1 );
		$this->assertGreaterThanOrEqual( $priorTime, $t1, 'Check keys generated on miss' );
		$t2 = $cache->getCheckKeyTime( $cKey2 );
		$this->assertGreaterThanOrEqual( $priorTime, $t2, 'Check keys generated on miss' );

		$mockWallClock += 0.01;
		$priorTime = $mockWallClock;
		$value = "@43636$";
		$wasSet = 0;
		$keyedIds = new ArrayIterator( [ $keyC => 43636 ] );
		$v = $cache->getMultiWithSetCallback(
			$keyedIds, 30, $genFunc, [ 'checkKeys' => [ $cKey1, $cKey2 ] ] + $extOpts
		);
		$this->assertSame( $value, $v[$keyC], "Value returned" );
		$this->assertSame( 1, $wasSet, "Value regenerated due to still-recent check keys" );
		$t1 = $cache->getCheckKeyTime( $cKey1 );
		$this->assertLessThanOrEqual( $priorTime, $t1, 'Check keys did not change again' );
		$t2 = $cache->getCheckKeyTime( $cKey2 );
		$this->assertLessThanOrEqual( $priorTime, $t2, 'Check keys did not change again' );

		$curTTL = null;
		$v = $cache->get( $keyC, $curTTL, [ $cKey1, $cKey2 ] );
		$this->assertSame( $value, $v, "Value returned" );
		$this->assertLessThanOrEqual( 0, $curTTL, "Value has current TTL < 0 due to check keys" );

		$wasSet = 0;
		$key = wfRandomString();
		$keyedIds = new ArrayIterator( [ $key => 242424 ] );
		$v = $cache->getMultiWithSetCallback(
			$keyedIds, 30, $genFunc, [ 'pcTTL' => 5 ] + $extOpts );
		$this->assertSame( "@{$keyedIds[$key]}$", $v[$key], "Value returned" );
		$cache->delete( $key );
		$keyedIds = new ArrayIterator( [ $key => 242424 ] );
		$v = $cache->getMultiWithSetCallback(
			$keyedIds, 30, $genFunc, [ 'pcTTL' => 5 ] + $extOpts );
		$this->assertSame( "@{$keyedIds[$key]}$", $v[$key], "Value still returned after deleted" );
		$this->assertSame( 1, $wasSet, "Value process cached while deleted" );

		$calls = 0;
		$ids = [ 1, 2, 3, 4, 5, 6 ];
		$keyFunc = function ( $id, WANObjectCache $wanCache ) {
			return $wanCache->makeKey( 'test', $id );
		};
		$keyedIds = $cache->makeMultiKeys( $ids, $keyFunc );
		$genFunc = function ( $id, $oldValue, &$ttl, array &$setops ) use ( &$calls ) {
			++$calls;

			return "val-{$id}";
		};
		$values = $cache->getMultiWithSetCallback( $keyedIds, 10, $genFunc );

		$this->assertSame(
			[ "val-1", "val-2", "val-3", "val-4", "val-5", "val-6" ],
			array_values( $values ),
			"Correct values in correct order"
		);
		$this->assertSame(
			array_map( $keyFunc, $ids, array_fill( 0, count( $ids ), $cache ) ),
			array_keys( $values ),
			"Correct keys in correct order"
		);
		$this->assertSame( count( $ids ), $calls );

		$cache->getMultiWithSetCallback( $keyedIds, 10, $genFunc );
		$this->assertSame( count( $ids ), $calls, "Values cached" );

		// Mock the BagOStuff to assure only one getMulti() call given process caching
		$localBag = $this->getMockBuilder( HashBagOStuff::class )
			->setMethods( [ 'getMulti' ] )->getMock();
		$localBag->expects( $this->exactly( 1 ) )->method( 'getMulti' )->willReturn( [
			'WANCache:v:' . 'k1' => 'val-id1',
			'WANCache:v:' . 'k2' => 'val-id2'
		] );
		$wanCache = new WANObjectCache( [ 'cache' => $localBag ] );

		// Warm the process cache
		$keyedIds = new ArrayIterator( [ 'k1' => 'id1', 'k2' => 'id2' ] );
		$this->assertSame(
			[ 'k1' => 'val-id1', 'k2' => 'val-id2' ],
			$wanCache->getMultiWithSetCallback( $keyedIds, 10, $genFunc, [ 'pcTTL' => 5 ] )
		);
		// Use the process cache
		$this->assertSame(
			[ 'k1' => 'val-id1', 'k2' => 'val-id2' ],
			$wanCache->getMultiWithSetCallback( $keyedIds, 10, $genFunc, [ 'pcTTL' => 5 ] )
		);
	}

	public static function getMultiWithSetCallback_provider() {
		return [
			[ [], false ],
			[ [ 'version' => 1 ], true ]
		];
	}

	/**
	 * @dataProvider getMultiWithSetCallbackRefresh_provider
	 * @param bool $expiring
	 * @param bool $popular
	 * @param array $idsByKey
	 */
	public function testGetMultiWithSetCallbackRefresh( $expiring, $popular, array $idsByKey ) {
		$deferredCbs = [];
		$bag = new HashBagOStuff();
		$cache = $this->getMockBuilder( WANObjectCache::class )
			->setMethods( [ 'worthRefreshExpiring', 'worthRefreshPopular' ] )
			->setConstructorArgs( [
				[
					'cache' => $bag,
					'asyncHandler' => function ( $callback ) use ( &$deferredCbs ) {
						$deferredCbs[] = $callback;
					}
				]
			] )
			->getMock();

		$cache->method( 'worthRefreshExpiring' )->willReturn( $expiring );
		$cache->method( 'worthRefreshPopular' )->willReturn( $popular );

		$wasSet = 0;
		$keyedIds = new ArrayIterator( $idsByKey );
		$genFunc = function ( $id, $old, &$ttl, &$opts, $asOf ) use ( &$wasSet ) {
			++$wasSet;
			$ttl = 20; // override with another value
			return "@$id$";
		};

		$v = $cache->getMultiWithSetCallback( $keyedIds, 30, $genFunc );
		$this->assertSame( count( $idsByKey ), $wasSet, "Initial sets" );
		$this->assertSame( 0, count( $deferredCbs ), "No deferred callbacks yet" );
		foreach ( $idsByKey as $key => $id ) {
			$this->assertSame( "@$id$", $v[$key], "Initial cache value generation" );
		}

		$wasSet = 0;
		$preemptiveRefresh = ( $expiring || $popular );
		$v = $cache->getMultiWithSetCallback( $keyedIds, 30, $genFunc );
		$this->assertSame( 0, $wasSet, "No values generated" );
		$this->assertSame(
			$preemptiveRefresh ? count( $idsByKey ) : 0,
			count( $deferredCbs ),
			"Deferred callbacks queued"
		);
		foreach ( $idsByKey as $key => $id ) {
			$this->assertSame( "@$id$", $v[$key], "Cached value reused; refresh scheduled" );
		}

		// Run the deferred callbacks...
		$deferredCbsReady = $deferredCbs;
		$deferredCbs = []; // empty by-reference queue
		foreach ( $deferredCbsReady as $deferredCb ) {
			$deferredCb();
		}

		$this->assertSame(
			( $preemptiveRefresh ? count( $idsByKey ) : 0 ),
			$wasSet,
			"Deferred callback regenerations"
		);
		$this->assertSame( 0, count( $deferredCbs ), "Deferred callbacks queue empty" );

		$wasSet = 0;
		$v = $cache->getMultiWithSetCallback( $keyedIds, 30, $genFunc );
		$this->assertSame(
			0,
			$wasSet,
			"Deferred callbacks did not run again"
		);
		foreach ( $idsByKey as $key => $id ) {
			$this->assertSame( "@$id$", $v[$key], "Cached value OK after deferred refresh run" );
		}
	}

	public static function getMultiWithSetCallbackRefresh_provider() {
		return [
			[ true, true, [ 'a' => 1, 'b' => 2, 'c' => 3, 'd' => 4 ] ],
			[ true, false, [ 'a' => 'x', 'b' => 'y', 'c' => 'z', 'd' => 'w' ] ],
			[ false, true, [ 'a' => 'p', 'b' => 'q', 'c' => 'r', 'd' => 's' ] ],
			[ false, false, [ 'a' => '%', 'b' => '^', 'c' => '&', 'd' => 'ç' ] ]
		];
	}

	/**
	 * @dataProvider getMultiWithUnionSetCallback_provider
	 * @covers WANObjectCache::getMultiWithUnionSetCallback()
	 * @covers WANObjectCache::makeMultiKeys()
	 * @param array $extOpts
	 */
	public function testGetMultiWithUnionSetCallback( array $extOpts ) {
		list( $cache ) = $this->newWanCache();

		$keyA = wfRandomString();
		$keyB = wfRandomString();
		$keyC = wfRandomString();
		$cKey1 = wfRandomString();
		$cKey2 = wfRandomString();

		$wasSet = 0;
		$genFunc = function ( array $ids, array &$ttls, array &$setOpts ) use (
			&$wasSet, &$priorValue, &$priorAsOf
		) {
			$newValues = [];
			foreach ( $ids as $id ) {
				++$wasSet;
				$newValues[$id] = "@$id$";
				$ttls[$id] = 20; // override with another value
			}

			return $newValues;
		};

		$mockWallClock = 1549343530.2053;
		$priorTime = $mockWallClock; // reference time
		$cache->setMockTime( $mockWallClock );

		$wasSet = 0;
		$keyedIds = new ArrayIterator( [ $keyA => 3353 ] );
		$value = "@3353$";
		$v = $cache->getMultiWithUnionSetCallback(
			$keyedIds, 30, $genFunc, $extOpts );
		$this->assertSame( $value, $v[$keyA], "Value returned" );
		$this->assertSame( 1, $wasSet, "Value regenerated" );

		$curTTL = null;
		$cache->get( $keyA, $curTTL );
		$this->assertLessThanOrEqual( 20, $curTTL, 'Current TTL between 19-20 (overriden)' );
		$this->assertGreaterThanOrEqual( 19, $curTTL, 'Current TTL between 19-20 (overriden)' );

		$wasSet = 0;
		$value = "@efef$";
		$keyedIds = new ArrayIterator( [ $keyB => 'efef' ] );
		$v = $cache->getMultiWithUnionSetCallback(
			$keyedIds, 30, $genFunc, [ 'lowTTL' => 0 ] + $extOpts );
		$this->assertSame( $value, $v[$keyB], "Value returned" );
		$this->assertSame( 1, $wasSet, "Value regenerated" );
		$this->assertSame( 0, $cache->getWarmupKeyMisses(), "Keys warmed in warmup cache" );

		$v = $cache->getMultiWithUnionSetCallback(
			$keyedIds, 30, $genFunc, [ 'lowTTL' => 0 ] + $extOpts );
		$this->assertSame( $value, $v[$keyB], "Value returned" );
		$this->assertSame( 1, $wasSet, "Value not regenerated" );
		$this->assertSame( 0, $cache->getWarmupKeyMisses(), "Keys warmed in warmup cache" );

		$mockWallClock += 1;

		$wasSet = 0;
		$keyedIds = new ArrayIterator( [ $keyB => 'efef' ] );
		$v = $cache->getMultiWithUnionSetCallback(
			$keyedIds, 30, $genFunc, [ 'checkKeys' => [ $cKey1, $cKey2 ] ] + $extOpts
		);
		$this->assertSame( $value, $v[$keyB], "Value returned" );
		$this->assertSame( 1, $wasSet, "Value regenerated due to check keys" );
		$t1 = $cache->getCheckKeyTime( $cKey1 );
		$this->assertGreaterThanOrEqual( $priorTime, $t1, 'Check keys generated on miss' );
		$t2 = $cache->getCheckKeyTime( $cKey2 );
		$this->assertGreaterThanOrEqual( $priorTime, $t2, 'Check keys generated on miss' );

		$mockWallClock += 0.01;
		$priorTime = $mockWallClock;
		$value = "@43636$";
		$wasSet = 0;
		$keyedIds = new ArrayIterator( [ $keyC => 43636 ] );
		$v = $cache->getMultiWithUnionSetCallback(
			$keyedIds, 30, $genFunc, [ 'checkKeys' => [ $cKey1, $cKey2 ] ] + $extOpts
		);
		$this->assertSame( $value, $v[$keyC], "Value returned" );
		$this->assertSame( 1, $wasSet, "Value regenerated due to still-recent check keys" );
		$t1 = $cache->getCheckKeyTime( $cKey1 );
		$this->assertLessThanOrEqual( $priorTime, $t1, 'Check keys did not change again' );
		$t2 = $cache->getCheckKeyTime( $cKey2 );
		$this->assertLessThanOrEqual( $priorTime, $t2, 'Check keys did not change again' );

		$curTTL = null;
		$v = $cache->get( $keyC, $curTTL, [ $cKey1, $cKey2 ] );
		$this->assertSame( $value, $v, "Value returned" );
		$this->assertLessThanOrEqual( 0, $curTTL, "Value has current TTL < 0 due to check keys" );

		$wasSet = 0;
		$key = wfRandomString();
		$keyedIds = new ArrayIterator( [ $key => 242424 ] );
		$v = $cache->getMultiWithUnionSetCallback(
			$keyedIds, 30, $genFunc, [ 'pcTTL' => 5 ] + $extOpts );
		$this->assertSame( "@{$keyedIds[$key]}$", $v[$key], "Value returned" );
		$cache->delete( $key );
		$keyedIds = new ArrayIterator( [ $key => 242424 ] );
		$v = $cache->getMultiWithUnionSetCallback(
			$keyedIds, 30, $genFunc, [ 'pcTTL' => 5 ] + $extOpts );
		$this->assertSame( "@{$keyedIds[$key]}$", $v[$key], "Value still returned after deleted" );
		$this->assertSame( 1, $wasSet, "Value process cached while deleted" );

		$calls = 0;
		$ids = [ 1, 2, 3, 4, 5, 6 ];
		$keyFunc = function ( $id, WANObjectCache $wanCache ) {
			return $wanCache->makeKey( 'test', $id );
		};
		$keyedIds = $cache->makeMultiKeys( $ids, $keyFunc );
		$genFunc = function ( array $ids, array &$ttls, array &$setOpts ) use ( &$calls ) {
			$newValues = [];
			foreach ( $ids as $id ) {
				++$calls;
				$newValues[$id] = "val-{$id}";
			}

			return $newValues;
		};
		$values = $cache->getMultiWithUnionSetCallback( $keyedIds, 10, $genFunc );

		$this->assertSame(
			[ "val-1", "val-2", "val-3", "val-4", "val-5", "val-6" ],
			array_values( $values ),
			"Correct values in correct order"
		);
		$this->assertSame(
			array_map( $keyFunc, $ids, array_fill( 0, count( $ids ), $cache ) ),
			array_keys( $values ),
			"Correct keys in correct order"
		);
		$this->assertSame( count( $ids ), $calls );

		$cache->getMultiWithUnionSetCallback( $keyedIds, 10, $genFunc );
		$this->assertSame( count( $ids ), $calls, "Values cached" );
	}

	public static function getMultiWithUnionSetCallback_provider() {
		return [
			[ [], false ],
			[ [ 'version' => 1 ], true ]
		];
	}

	public static function provideCoalesceAndMcrouterSettings() {
		return [
			[ [ 'mcrouterAware' => false, 'coalesceKeys' => false ] ],
			[ [ 'mcrouterAware' => false, 'coalesceKeys' => true ] ],
			[ [ 'mcrouterAware' => true, 'cluster' => 'test', 'coalesceKeys' => false ] ],
			[ [ 'mcrouterAware' => true, 'cluster' => 'test', 'coalesceKeys' => true ] ]
		];
	}

	/**
	 * @dataProvider getMultiWithUnionSetCallbackRefresh_provider
	 * @param bool $expiring
	 * @param bool $popular
	 * @param array $idsByKey
	 */
	public function testGetMultiWithUnionSetCallbackRefresh( $expiring, $popular, array $idsByKey ) {
		$deferredCbs = [];
		$bag = new HashBagOStuff();
		$cache = $this->getMockBuilder( WANObjectCache::class )
			->setMethods( [ 'worthRefreshExpiring', 'worthRefreshPopular' ] )
			->setConstructorArgs( [
				[
					'cache' => $bag,
					'asyncHandler' => function ( $callback ) use ( &$deferredCbs ) {
						$deferredCbs[] = $callback;
					}
				]
			] )
			->getMock();

		$cache->expects( $this->any() )->method( 'worthRefreshExpiring' )->willReturn( $expiring );
		$cache->expects( $this->any() )->method( 'worthRefreshPopular' )->willReturn( $popular );

		$wasSet = 0;
		$keyedIds = new ArrayIterator( $idsByKey );
		$genFunc = function ( array $ids, array &$ttls, array &$setOpts ) use ( &$wasSet ) {
			$newValues = [];
			foreach ( $ids as $id ) {
				++$wasSet;
				$newValues[$id] = "@$id$";
				$ttls[$id] = 20; // override with another value
			}

			return $newValues;
		};

		$v = $cache->getMultiWithUnionSetCallback( $keyedIds, 30, $genFunc );
		$this->assertSame( count( $idsByKey ), $wasSet, "Initial sets" );
		$this->assertSame( 0, count( $deferredCbs ), "No deferred callbacks yet" );
		foreach ( $idsByKey as $key => $id ) {
			$this->assertSame( "@$id$", $v[$key], "Initial cache value generation" );
		}

		$preemptiveRefresh = ( $expiring || $popular );
		$v = $cache->getMultiWithUnionSetCallback( $keyedIds, 30, $genFunc );
		$this->assertSame( count( $idsByKey ), $wasSet, "Deferred callbacks did not run yet" );
		$this->assertSame(
			$preemptiveRefresh ? count( $idsByKey ) : 0,
			count( $deferredCbs ),
			"Deferred callbacks queued"
		);
		foreach ( $idsByKey as $key => $id ) {
			$this->assertSame( "@$id$", $v[$key], "Cached value reused; refresh scheduled" );
		}

		// Run the deferred callbacks...
		$deferredCbsReady = $deferredCbs;
		$deferredCbs = []; // empty by-reference queue
		foreach ( $deferredCbsReady as $deferredCb ) {
			$deferredCb();
		}

		$this->assertSame(
			count( $idsByKey ) * ( $preemptiveRefresh ? 2 : 1 ),
			$wasSet,
			"Deferred callback regenerations"
		);
		$this->assertSame( 0, count( $deferredCbs ), "Deferred callbacks queue empty" );

		$v = $cache->getMultiWithUnionSetCallback( $keyedIds, 30, $genFunc );
		$this->assertSame(
			count( $idsByKey ) * ( $preemptiveRefresh ? 2 : 1 ),
			$wasSet,
			"Deferred callbacks did not run again yet"
		);
		foreach ( $idsByKey as $key => $id ) {
			$this->assertSame( "@$id$", $v[$key], "Cached value OK after deferred refresh run" );
		}
	}

	public static function getMultiWithUnionSetCallbackRefresh_provider() {
		return [
			[ true, true, [ 'a' => 1, 'b' => 2, 'c' => 3, 'd' => 4 ] ],
			[ true, false, [ 'a' => 'x', 'b' => 'y', 'c' => 'z', 'd' => 'w' ] ],
			[ false, true, [ 'a' => 'p', 'b' => 'q', 'c' => 'r', 'd' => 's' ] ],
			[ false, false, [ 'a' => '%', 'b' => '^', 'c' => '&', 'd' => 'ç' ] ]
		];
	}

	/**
	 * @covers WANObjectCache::getWithSetCallback()
	 * @covers WANObjectCache::fetchOrRegenerate()
	 * @dataProvider provideCoalesceAndMcrouterSettings
	 * @param array $params
	 */
	public function testLockTSE( array $params ) {
		list( $cache, $bag ) = $this->newWanCache( $params );
		$key = wfRandomString();
		$value = wfRandomString();

		$mockWallClock = 1549343530.2053;
		$cache->setMockTime( $mockWallClock );

		$calls = 0;
		$func = function () use ( &$calls, $value, $cache, $key ) {
			++$calls;
			return $value;
		};

		$ret = $cache->getWithSetCallback( $key, 30, $func, [ 'lockTSE' => 5 ] );
		$this->assertSame( $value, $ret );
		$this->assertSame( 1, $calls, 'Value was populated' );

		// Acquire the mutex to verify that getWithSetCallback uses lockTSE properly
		$this->setMutexKey( $bag, $key );

		$checkKeys = [ wfRandomString() ]; // new check keys => force misses
		$ret = $cache->getWithSetCallback( $key, 30, $func,
			[ 'lockTSE' => 5, 'checkKeys' => $checkKeys ] );
		$this->assertSame( $value, $ret, 'Old value used' );
		$this->assertSame( 1, $calls, 'Callback was not used' );

		$cache->setMcRouterAware( false ); // broadcast keys don't work with HashBagOStuff
		$cache->delete( $key ); // no value at all anymore and still locked
		$cache->setMcRouterAware( $params['mcrouterAware'] );

		$mockWallClock += 0.001; // cached values will be newer than tombstone
		$ret = $cache->getWithSetCallback( $key, 30, $func,
			[ 'lockTSE' => 5, 'checkKeys' => $checkKeys ] );
		$this->assertSame( $value, $ret, 'Callback was used; interim saved' );
		$this->assertSame( 2, $calls, 'Callback was used; interim saved' );

		$ret = $cache->getWithSetCallback( $key, 30, $func,
			[ 'lockTSE' => 5, 'checkKeys' => $checkKeys ] );
		$this->assertSame( $value, $ret, 'Callback was not used; used interim (mutex failed)' );
		$this->assertSame( 2, $calls, 'Callback was not used; used interim (mutex failed)' );
	}

	private function setMutexKey( BagOStuff $bag, $key ) {
		$bag->add( "{$key}:m", 1, 0 );
		$bag->add( "{$key}|#|m", 1, 0 );
		$bag->add( "WANCache:m:$key", 1, 0 );
	}

	private function clearMutexKey( BagOStuff $bag, $key ) {
		$bag->delete( "{$key}:m" );
		$bag->delete( "{$key}|#|m" );
		$bag->delete( "WANCache:m:$key" );
	}

	/**
	 * @covers WANObjectCache::getWithSetCallback()
	 * @covers WANObjectCache::fetchOrRegenerate()
	 * @covers WANObjectCache::set()
	 * @dataProvider provideCoalesceAndMcrouterSettings
	 * @param array $params
	 */
	public function testLockTSESlow( array $params ) {
		list( $cache, $bag ) = $this->newWanCache( $params );
		$key = wfRandomString();
		$key2 = wfRandomString();
		$value = wfRandomString();

		$mockWallClock = 1549343530.2053;
		$cache->setMockTime( $mockWallClock );

		$calls = 0;
		$func = function ( $oldValue, &$ttl, &$setOpts ) use ( &$calls, $value, &$mockWallClock ) {
			++$calls;
			$setOpts['since'] = $mockWallClock - 10;
			return $value;
		};

		// Value should be given a low logical TTL due to snapshot lag
		$curTTL = null;
		$ret = $cache->getWithSetCallback( $key, 300, $func, [ 'lockTSE' => 5 ] );
		$this->assertSame( $value, $ret );
		$this->assertSame( $value, $cache->get( $key, $curTTL ), 'Value was populated' );
		$this->assertEqualsWithDelta( 1.0, $curTTL, 0.01, 'Value has reduced logical TTL' );
		$this->assertSame( 1, $calls, 'Value was generated' );

		$mockWallClock += 2; // low logical TTL expired

		$ret = $cache->getWithSetCallback( $key, 300, $func, [ 'lockTSE' => 5 ] );
		$this->assertSame( $value, $ret );
		$this->assertSame( 2, $calls, 'Callback used (mutex acquired)' );

		$ret = $cache->getWithSetCallback( $key, 300, $func, [ 'lockTSE' => 5, 'lowTTL' => -1 ] );
		$this->assertSame( $value, $ret );
		$this->assertSame( 2, $calls, 'Callback was not used (interim value used)' );

		$mockWallClock += 2; // low logical TTL expired
		// Acquire a lock to verify that getWithSetCallback uses lockTSE properly
		$this->setMutexKey( $bag, $key );

		$ret = $cache->getWithSetCallback( $key, 300, $func, [ 'lockTSE' => 5 ] );
		$this->assertSame( $value, $ret );
		$this->assertSame( 2, $calls, 'Callback was not used (mutex not acquired)' );

		$mockWallClock += 301; // physical TTL expired
		// Acquire a lock to verify that getWithSetCallback uses lockTSE properly
		$this->setMutexKey( $bag, $key );

		$ret = $cache->getWithSetCallback( $key, 300, $func, [ 'lockTSE' => 5 ] );
		$this->assertSame( $value, $ret );
		$this->assertSame( 3, $calls, 'Callback was used (mutex not acquired, not in cache)' );

		$calls = 0;
		$func2 = function ( $oldValue, &$ttl, &$setOpts ) use ( &$calls, $value ) {
			++$calls;
			$setOpts['lag'] = 15;
			return $value;
		};

		// Value should be given a low logical TTL due to replication lag
		$curTTL = null;
		$ret = $cache->getWithSetCallback( $key2, 300, $func2, [ 'lockTSE' => 5 ] );
		$this->assertSame( $value, $ret );
		$this->assertSame( $value, $cache->get( $key2, $curTTL ), 'Value was populated' );
		$this->assertSame( 30.0, $curTTL, 'Value has reduced logical TTL', 0.01 );
		$this->assertSame( 1, $calls, 'Value was generated' );

		$ret = $cache->getWithSetCallback( $key2, 300, $func2, [ 'lockTSE' => 5 ] );
		$this->assertSame( $value, $ret );
		$this->assertSame( 1, $calls, 'Callback was used (not expired)' );

		$mockWallClock += 31;

		$ret = $cache->getWithSetCallback( $key2, 300, $func2, [ 'lockTSE' => 5 ] );
		$this->assertSame( $value, $ret );
		$this->assertSame( 2, $calls, 'Callback was used (mutex acquired)' );
	}

	/**
	 * @covers WANObjectCache::getWithSetCallback()
	 * @covers WANObjectCache::fetchOrRegenerate()
	 * @dataProvider provideCoalesceAndMcrouterSettings
	 * @param array $params
	 */
	public function testBusyValueBasic( array $params ) {
		list( $cache, $bag ) = $this->newWanCache( $params );
		$key = wfRandomString();
		$value = wfRandomString();
		$busyValue = wfRandomString();

		$mockWallClock = 1549343530.2053;
		$cache->setMockTime( $mockWallClock );

		$calls = 0;
		$func = function () use ( &$calls, $value ) {
			++$calls;
			return $value;
		};

		$ret = $cache->getWithSetCallback( $key, 30, $func, [ 'busyValue' => $busyValue ] );
		$this->assertSame( $value, $ret );
		$this->assertSame( 1, $calls, 'Value was populated' );

		$mockWallClock += 0.2; // interim keys not brand new

		// Acquire a lock to verify that getWithSetCallback uses busyValue properly
		$this->setMutexKey( $bag, $key );

		$checkKeys = [ wfRandomString() ]; // new check keys => force misses
		$ret = $cache->getWithSetCallback( $key, 30, $func,
			[ 'busyValue' => $busyValue, 'checkKeys' => $checkKeys ] );
		$this->assertSame( $value, $ret, 'Callback used' );
		$this->assertSame( 2, $calls, 'Callback used' );

		$ret = $cache->getWithSetCallback( $key, 30, $func,
			[ 'lockTSE' => 30, 'busyValue' => $busyValue, 'checkKeys' => $checkKeys ] );
		$this->assertSame( $value, $ret, 'Old value used' );
		$this->assertSame( 2, $calls, 'Callback was not used' );

		$cache->setMcRouterAware( false ); // broadcast keys don't work with HashBagOStuff
		$cache->delete( $key ); // no value at all anymore and still locked
		$cache->setMcRouterAware( $params['mcrouterAware'] );

		$ret = $cache->getWithSetCallback( $key, 30, $func,
			[ 'busyValue' => $busyValue, 'checkKeys' => $checkKeys ] );
		$this->assertSame( $busyValue, $ret, 'Callback was not used; used busy value' );
		$this->assertSame( 2, $calls, 'Callback was not used; used busy value' );

		$this->clearMutexKey( $bag, $key );
		$mockWallClock += 0.001; // cached values will be newer than tombstone
		$ret = $cache->getWithSetCallback( $key, 30, $func,
			[ 'lockTSE' => 30, 'busyValue' => $busyValue, 'checkKeys' => $checkKeys ] );
		$this->assertSame( $value, $ret, 'Callback was used; saved interim' );
		$this->assertSame( 3, $calls, 'Callback was used; saved interim' );

		$this->setMutexKey( $bag, $key );
		$ret = $cache->getWithSetCallback( $key, 30, $func,
			[ 'busyValue' => $busyValue, 'checkKeys' => $checkKeys ] );
		$this->assertSame( $value, $ret, 'Callback was not used; used interim' );
		$this->assertSame( 3, $calls, 'Callback was not used; used interim' );
	}

	public function getBusyValues_Provider() {
		$hash = new HashBagOStuff( [] );

		return [
			[
				function () {
					return "Saint Oliver Plunckett";
				},
				'Saint Oliver Plunckett'
			],
			[ 'strlen', 'strlen' ],
			[ 'WANObjectCache::newEmpty', 'WANObjectCache::newEmpty' ],
			[ [ 'WANObjectCache', 'newEmpty' ], [ 'WANObjectCache', 'newEmpty' ] ],
			[ [ $hash, 'getLastError' ], [ $hash, 'getLastError' ] ],
			[ [ 1, 2, 3 ], [ 1, 2, 3 ] ]
		];
	}

	/**
	 * @covers WANObjectCache::getWithSetCallback()
	 * @covers WANObjectCache::fetchOrRegenerate()
	 * @dataProvider getBusyValues_Provider
	 * @param mixed $busyValue
	 * @param mixed $expected
	 */
	public function testBusyValueTypes( $busyValue, $expected ) {
		list( $cache, $bag ) = $this->newWanCache();
		$key = wfRandomString();

		$mockWallClock = 1549343530.2053;
		$cache->setMockTime( $mockWallClock );

		$calls = 0;
		$func = function () use ( &$calls ) {
			++$calls;
			return 418;
		};

		// Acquire a lock to verify that getWithSetCallback uses busyValue properly
		$this->setMutexKey( $bag, $key );

		$ret = $cache->getWithSetCallback( $key, 30, $func, [ 'busyValue' => $busyValue ] );
		$this->assertSame( $expected, $ret, 'busyValue used as expected' );
		$this->assertSame( 0, $calls, 'busyValue was used' );
	}

	/**
	 * @covers WANObjectCache::getMulti()
	 */
	public function testGetMulti() {
		list( $cache ) = $this->newWanCache();

		$value1 = [ 'this' => 'is', 'a' => 'test' ];
		$value2 = [ 'this' => 'is', 'another' => 'test' ];

		$key1 = wfRandomString();
		$key2 = wfRandomString();
		$key3 = wfRandomString();

		$mockWallClock = 1549343530.2053;
		$priorTime = $mockWallClock; // reference time
		$cache->setMockTime( $mockWallClock );

		$cache->set( $key1, $value1, 5 );
		$cache->set( $key2, $value2, 10 );

		$curTTLs = [];
		$this->assertSame(
			[ $key1 => $value1, $key2 => $value2 ],
			$cache->getMulti( [ $key1, $key2, $key3 ], $curTTLs ),
			'Result array populated'
		);

		$this->assertSame( 2, count( $curTTLs ), "Two current TTLs in array" );
		$this->assertGreaterThan( 0, $curTTLs[$key1], "Key 1 has current TTL > 0" );
		$this->assertGreaterThan( 0, $curTTLs[$key2], "Key 2 has current TTL > 0" );

		$cKey1 = wfRandomString();
		$cKey2 = wfRandomString();

		$mockWallClock += 1;

		$curTTLs = [];
		$this->assertSame(
			[ $key1 => $value1, $key2 => $value2 ],
			$cache->getMulti( [ $key1, $key2, $key3 ], $curTTLs, [ $cKey1, $cKey2 ] ),
			"Result array populated even with new check keys"
		);
		$t1 = $cache->getCheckKeyTime( $cKey1 );
		$this->assertGreaterThanOrEqual( $priorTime, $t1, 'Check key 1 generated on miss' );
		$t2 = $cache->getCheckKeyTime( $cKey2 );
		$this->assertGreaterThanOrEqual( $priorTime, $t2, 'Check key 2 generated on miss' );
		$this->assertSame( 2, count( $curTTLs ), "Current TTLs array set" );
		$this->assertLessThanOrEqual( 0, $curTTLs[$key1], 'Key 1 has current TTL <= 0' );
		$this->assertLessThanOrEqual( 0, $curTTLs[$key2], 'Key 2 has current TTL <= 0' );

		$mockWallClock += 1;

		$curTTLs = [];
		$this->assertSame(
			[ $key1 => $value1, $key2 => $value2 ],
			$cache->getMulti( [ $key1, $key2, $key3 ], $curTTLs, [ $cKey1, $cKey2 ] ),
			"Result array still populated even with new check keys"
		);
		$this->assertSame( 2, count( $curTTLs ), "Current TTLs still array set" );
		$this->assertLessThan( 0, $curTTLs[$key1], 'Key 1 has negative current TTL' );
		$this->assertLessThan( 0, $curTTLs[$key2], 'Key 2 has negative current TTL' );
	}

	/**
	 * @covers WANObjectCache::getMulti()
	 * @covers WANObjectCache::processCheckKeys()
	 * @param array $params
	 * @dataProvider provideCoalesceAndMcrouterSettings
	 */
	public function testGetMultiCheckKeys( array $params ) {
		list( $cache ) = $this->newWanCache( $params );

		$checkAll = wfRandomString();
		$check1 = wfRandomString();
		$check2 = wfRandomString();
		$check3 = wfRandomString();
		$value1 = wfRandomString();
		$value2 = wfRandomString();

		$mockWallClock = 1549343530.2053;
		$cache->setMockTime( $mockWallClock );

		// Fake initial check key to be set in the past. Otherwise we'd have to sleep for
		// several seconds during the test to assert the behaviour.
		foreach ( [ $checkAll, $check1, $check2 ] as $checkKey ) {
			$cache->setMcRouterAware( false ); // broadcast keys don't work with HashBagOStuff
			$cache->touchCheckKey( $checkKey, WANObjectCache::HOLDOFF_TTL_NONE );
			$cache->setMcRouterAware( $params['mcrouterAware'] );
		}

		$mockWallClock += 0.100;

		$cache->set( 'key1', $value1, 10 );
		$cache->set( 'key2', $value2, 10 );

		$curTTLs = [];
		$result = $cache->getMulti( [ 'key1', 'key2', 'key3' ], $curTTLs, [
			'key1' => $check1,
			$checkAll,
			'key2' => $check2,
			'key3' => $check3,
		] );
		$this->assertSame(
			[ 'key1' => $value1, 'key2' => $value2 ],
			$result,
			'Initial values'
		);
		$this->assertGreaterThanOrEqual( 9.5, $curTTLs['key1'], 'Initial ttls' );
		$this->assertLessThanOrEqual( 10.5, $curTTLs['key1'], 'Initial ttls' );
		$this->assertGreaterThanOrEqual( 9.5, $curTTLs['key2'], 'Initial ttls' );
		$this->assertLessThanOrEqual( 10.5, $curTTLs['key2'], 'Initial ttls' );

		$mockWallClock += 0.100;
		$cache->setMcRouterAware( false ); // broadcast keys don't work with HashBagOStuff
		$cache->touchCheckKey( $check1 );
		$cache->setMcRouterAware( $params['mcrouterAware'] );

		$curTTLs = [];
		$result = $cache->getMulti( [ 'key1', 'key2', 'key3' ], $curTTLs, [
			'key1' => $check1,
			$checkAll,
			'key2' => $check2,
			'key3' => $check3,
		] );
		$this->assertSame(
			[ 'key1' => $value1, 'key2' => $value2 ],
			$result,
			'key1 expired by check1, but value still provided'
		);
		$this->assertLessThan( 0, $curTTLs['key1'], 'key1 TTL expired' );
		$this->assertGreaterThan( 0, $curTTLs['key2'], 'key2 still valid' );

		$cache->setMcRouterAware( false ); // broadcast keys don't work with HashBagOStuff
		$cache->touchCheckKey( $checkAll );
		$cache->setMcRouterAware( $params['mcrouterAware'] );

		$curTTLs = [];
		$result = $cache->getMulti( [ 'key1', 'key2', 'key3' ], $curTTLs, [
			'key1' => $check1,
			$checkAll,
			'key2' => $check2,
			'key3' => $check3,
		] );
		$this->assertSame(
			[ 'key1' => $value1, 'key2' => $value2 ],
			$result,
			'All keys expired by checkAll, but value still provided'
		);
		$this->assertLessThan( 0, $curTTLs['key1'], 'key1 expired by checkAll' );
		$this->assertLessThan( 0, $curTTLs['key2'], 'key2 expired by checkAll' );
	}

	/**
	 * @covers WANObjectCache::get()
	 * @covers WANObjectCache::processCheckKeys()
	 */
	public function testCheckKeyInitHoldoff() {
		list( $cache ) = $this->newWanCache();

		for ( $i = 0; $i < 500; ++$i ) {
			$key = wfRandomString();
			$checkKey = wfRandomString();
			// miss, set, hit
			$cache->get( $key, $curTTL, [ $checkKey ] );
			$cache->set( $key, 'val', 10 );
			$curTTL = null;
			$v = $cache->get( $key, $curTTL, [ $checkKey ] );

			$this->assertSame( 'val', $v );
			$this->assertLessThan( 0, $curTTL, "Step $i: CTL < 0 (miss/set/hit)" );
		}

		for ( $i = 0; $i < 500; ++$i ) {
			$key = wfRandomString();
			$checkKey = wfRandomString();
			// set, hit
			$cache->set( $key, 'val', 10 );
			$curTTL = null;
			$v = $cache->get( $key, $curTTL, [ $checkKey ] );

			$this->assertSame( 'val', $v );
			$this->assertLessThan( 0, $curTTL, "Step $i: CTL < 0 (set/hit)" );
		}
	}

	/**
	 * @covers WANObjectCache::get()
	 * @covers WANObjectCache::processCheckKeys()
	 */
	public function testCheckKeyHoldoff() {
		list( $cache ) = $this->newWanCache();
		$key = wfRandomString();
		$checkKey = wfRandomString();

		$mockWallClock = 1549343530.2053;
		$cache->setMockTime( $mockWallClock );
		$cache->touchCheckKey( $checkKey, 8 );

		$mockWallClock += 1;
		$cache->set( $key, 1, 60 );
		$this->assertSame( 1, $cache->get( $key, $curTTL, [ $checkKey ] ) );
		$this->assertLessThan( 0, $curTTL, "Key in hold-off due to check key" );

		$mockWallClock += 3;
		$cache->set( $key, 1, 60 );
		$this->assertSame( 1, $cache->get( $key, $curTTL, [ $checkKey ] ) );
		$this->assertLessThan( 0, $curTTL, "Key in hold-off due to check key" );

		$mockWallClock += 10;
		$cache->set( $key, 1, 60 );
		$this->assertSame( 1, $cache->get( $key, $curTTL, [ $checkKey ] ) );
		$this->assertGreaterThan( 0, $curTTL, "Key not in hold-off due to check key" );
	}

	/**
	 * @covers WANObjectCache::delete
	 * @covers WANObjectCache::relayDelete
	 * @covers WANObjectCache::relayPurge
	 */
	public function testDelete() {
		list( $cache ) = $this->newWanCache();
		$key = wfRandomString();
		$value = wfRandomString();
		$cache->set( $key, $value );

		$curTTL = null;
		$v = $cache->get( $key, $curTTL );
		$this->assertSame( $value, $v, "Key was created with value" );
		$this->assertGreaterThan( 0, $curTTL, "Existing key has current TTL > 0" );

		$cache->delete( $key );

		$curTTL = null;
		$v = $cache->get( $key, $curTTL );
		$this->assertSame( false, $v, "Deleted key has false value" );
		$this->assertLessThan( 0, $curTTL, "Deleted key has current TTL < 0" );

		$cache->set( $key, $value . 'more' );
		$v = $cache->get( $key, $curTTL );
		$this->assertSame( false, $v, "Deleted key is tombstoned and has false value" );
		$this->assertLessThan( 0, $curTTL, "Deleted key is tombstoned and has current TTL < 0" );

		$cache->set( $key, $value );
		$cache->delete( $key, WANObjectCache::HOLDOFF_TTL_NONE );

		$curTTL = null;
		$v = $cache->get( $key, $curTTL );
		$this->assertSame( false, $v, "Deleted key has false value" );
		$this->assertSame( null, $curTTL, "Deleted key has null current TTL" );

		$cache->set( $key, $value );
		$v = $cache->get( $key, $curTTL );
		$this->assertSame( $value, $v, "Key was created with value" );
		$this->assertGreaterThan( 0, $curTTL, "Existing key has current TTL > 0" );
	}

	/**
	 * @dataProvider getWithSetCallback_versions_provider
	 * @covers WANObjectCache::getWithSetCallback()
	 * @covers WANObjectCache::fetchOrRegenerate()
	 * @param array $extOpts
	 * @param bool $versioned
	 */
	public function testGetWithSetCallback_versions( array $extOpts, $versioned ) {
		list( $cache ) = $this->newWanCache();

		$key = wfRandomString();
		$valueV1 = wfRandomString();
		$valueV2 = [ wfRandomString() ];

		$wasSet = 0;
		$funcV1 = function () use ( &$wasSet, $valueV1 ) {
			++$wasSet;

			return $valueV1;
		};

		$priorValue = false;
		$priorAsOf = null;
		$funcV2 = function ( $oldValue, &$ttl, $setOpts, $oldAsOf )
		use ( &$wasSet, $valueV2, &$priorValue, &$priorAsOf ) {
			$priorValue = $oldValue;
			$priorAsOf = $oldAsOf;
			++$wasSet;

			return $valueV2; // new array format
		};

		// Set the main key (version N if versioned)
		$wasSet = 0;
		$v = $cache->getWithSetCallback( $key, 30, $funcV1, $extOpts );
		$this->assertSame( $valueV1, $v, "Value returned" );
		$this->assertSame( 1, $wasSet, "Value regenerated" );
		$cache->getWithSetCallback( $key, 30, $funcV1, $extOpts );
		$this->assertSame( 1, $wasSet, "Value not regenerated" );
		$this->assertSame( $valueV1, $v, "Value not regenerated" );

		if ( $versioned ) {
			// Set the key for version N+1 format
			$verOpts = [ 'version' => $extOpts['version'] + 1 ];
		} else {
			// Start versioning now with the unversioned key still there
			$verOpts = [ 'version' => 1 ];
		}

		// Value goes to secondary key since V1 already used $key
		$wasSet = 0;
		$v = $cache->getWithSetCallback( $key, 30, $funcV2, $verOpts + $extOpts );
		$this->assertSame( $valueV2, $v, "Value returned" );
		$this->assertSame( 1, $wasSet, "Value regenerated" );
		$this->assertSame( false, $priorValue, "Old value not given due to old format" );
		$this->assertSame( null, $priorAsOf, "Old value not given due to old format" );

		$wasSet = 0;
		$v = $cache->getWithSetCallback( $key, 30, $funcV2, $verOpts + $extOpts );
		$this->assertSame( $valueV2, $v, "Value not regenerated (secondary key)" );
		$this->assertSame( 0, $wasSet, "Value not regenerated (secondary key)" );

		// Clear out the older or unversioned key
		$cache->delete( $key, 0 );

		// Set the key for next/first versioned format
		$wasSet = 0;
		$v = $cache->getWithSetCallback( $key, 30, $funcV2, $verOpts + $extOpts );
		$this->assertSame( $valueV2, $v, "Value returned" );
		$this->assertSame( 1, $wasSet, "Value regenerated" );

		$v = $cache->getWithSetCallback( $key, 30, $funcV2, $verOpts + $extOpts );
		$this->assertSame( $valueV2, $v, "Value not regenerated (main key)" );
		$this->assertSame( 1, $wasSet, "Value not regenerated (main key)" );
	}

	public static function getWithSetCallback_versions_provider() {
		return [
			[ [], false ],
			[ [ 'version' => 1 ], true ]
		];
	}

	/**
	 * @covers WANObjectCache::useInterimHoldOffCaching
	 * @covers WANObjectCache::getInterimValue
	 * @dataProvider provideCoalesceAndMcrouterSettings
	 * @param array $params
	 */
	public function testInterimHoldOffCaching( array $params ) {
		list( $cache, $bag ) = $this->newWanCache( $params );

		$mockWallClock = 1549343530.2053;
		$cache->setMockTime( $mockWallClock );

		$value = 'CRL-40-940';
		$wasCalled = 0;
		$func = function () use ( &$wasCalled, $value ) {
			$wasCalled++;

			return $value;
		};

		$cache->useInterimHoldOffCaching( true );

		$key = wfRandomString( 32 );
		$cache->getWithSetCallback( $key, 60, $func );
		$cache->getWithSetCallback( $key, 60, $func );
		$this->assertSame( 1, $wasCalled, 'Value cached' );

		$cache->setMcRouterAware( false ); // broadcast keys don't work with HashBagOStuff
		$cache->delete( $key ); // no value at all anymore and still locked
		$cache->setMcRouterAware( $params['mcrouterAware'] );

		$mockWallClock += 0.001; // cached values will be newer than tombstone
		$cache->getWithSetCallback( $key, 60, $func );
		$this->assertSame( 2, $wasCalled, 'Value regenerated (got mutex)' ); // sets interim
		$cache->getWithSetCallback( $key, 60, $func );
		$this->assertSame( 2, $wasCalled, 'Value interim cached' ); // reuses interim

		$mockWallClock += 0.2; // interim key not brand new
		$cache->getWithSetCallback( $key, 60, $func );
		$this->assertSame( 3, $wasCalled, 'Value regenerated (got mutex)' ); // sets interim
		// Lock up the mutex so interim cache is used
		$this->setMutexKey( $bag, $key );
		$cache->getWithSetCallback( $key, 60, $func );
		$this->assertSame( 3, $wasCalled, 'Value interim cached (failed mutex)' );
		$this->clearMutexKey( $bag, $key );

		$cache->useInterimHoldOffCaching( false );

		$wasCalled = 0;
		$key = wfRandomString( 32 );
		$cache->getWithSetCallback( $key, 60, $func );
		$cache->getWithSetCallback( $key, 60, $func );
		$this->assertSame( 1, $wasCalled, 'Value cached' );

		$cache->setMcRouterAware( false ); // broadcast keys don't work with HashBagOStuff
		$cache->delete( $key ); // no value at all anymore and still locked
		$cache->setMcRouterAware( $params['mcrouterAware'] );

		$cache->getWithSetCallback( $key, 60, $func );
		$this->assertSame( 2, $wasCalled, 'Value regenerated (got mutex)' );
		$cache->getWithSetCallback( $key, 60, $func );
		$this->assertSame( 3, $wasCalled, 'Value still regenerated (got mutex)' );
		$cache->getWithSetCallback( $key, 60, $func );
		$this->assertSame( 4, $wasCalled, 'Value still regenerated (got mutex)' );
		// Lock up the mutex so interim cache is used
		$this->setMutexKey( $bag, $key );
		$cache->getWithSetCallback( $key, 60, $func );
		$this->assertSame( 5, $wasCalled, 'Value still regenerated (failed mutex)' );
	}

	/**
	 * @covers WANObjectCache::touchCheckKey
	 * @covers WANObjectCache::resetCheckKey
	 * @covers WANObjectCache::getCheckKeyTime
	 * @covers WANObjectCache::getMultiCheckKeyTime
	 * @covers WANObjectCache::makePurgeValue
	 * @covers WANObjectCache::parsePurgeValue
	 */
	public function testTouchKeys() {
		list( $cache ) = $this->newWanCache();
		$key = wfRandomString();

		$mockWallClock = 1549343530.2053;
		$priorTime = $mockWallClock; // reference time
		$cache->setMockTime( $mockWallClock );

		$mockWallClock += 0.100;
		$t0 = $cache->getCheckKeyTime( $key );
		$this->assertGreaterThanOrEqual( $priorTime, $t0, 'Check key auto-created' );

		$priorTime = $mockWallClock;
		$mockWallClock += 0.100;
		$cache->touchCheckKey( $key );
		$t1 = $cache->getCheckKeyTime( $key );
		$this->assertGreaterThanOrEqual( $priorTime, $t1, 'Check key created' );

		$t2 = $cache->getCheckKeyTime( $key );
		$this->assertSame( $t1, $t2, 'Check key time did not change' );

		$mockWallClock += 0.100;
		$cache->touchCheckKey( $key );
		$t3 = $cache->getCheckKeyTime( $key );
		$this->assertGreaterThan( $t2, $t3, 'Check key time increased' );

		$t4 = $cache->getCheckKeyTime( $key );
		$this->assertSame( $t3, $t4, 'Check key time did not change' );

		$mockWallClock += 0.100;
		$cache->resetCheckKey( $key );
		$t5 = $cache->getCheckKeyTime( $key );
		$this->assertGreaterThan( $t4, $t5, 'Check key time increased' );

		$t6 = $cache->getCheckKeyTime( $key );
		$this->assertSame( $t5, $t6, 'Check key time did not change' );
	}

	/**
	 * @covers WANObjectCache::getMulti()
	 * @param array $params
	 * @dataProvider provideCoalesceAndMcrouterSettings
	 */
	public function testGetWithSeveralCheckKeys( array $params ) {
		list( $cache, $bag ) = $this->newWanCache( $params );
		$key = wfRandomString();
		$tKey1 = wfRandomString();
		$tKey2 = wfRandomString();
		$value = 'meow';

		$mockWallClock = 1549343530.2053;
		$priorTime = $mockWallClock; // reference time
		$cache->setMockTime( $mockWallClock );

		// Two check keys are newer (given hold-off) than $key, another is older
		$bag->set(
			'WANCache:t:' . $tKey2,
			'PURGED:' . ( $priorTime - 3 )
		);
		$bag->set(
			'WANCache:t:' . $tKey2,
			'PURGED:' . ( $priorTime - 5 )
		);
		$bag->set(
			'WANCache:t:' . $tKey1,
			'PURGED:' . ( $priorTime - 30 )
		);
		$cache->set( $key, $value, 30 );

		$curTTL = null;
		$v = $cache->get( $key, $curTTL, [ $tKey1, $tKey2 ] );
		$this->assertSame( $value, $v, "Value matches" );
		$this->assertLessThan( -4.9, $curTTL, "Correct CTL" );
		$this->assertGreaterThan( -5.1, $curTTL, "Correct CTL" );
	}

	/**
	 * @covers WANObjectCache::reap()
	 * @covers WANObjectCache::reapCheckKey()
	 */
	public function testReap() {
		list( $cache, $bag ) = $this->newWanCache();
		$vKey1 = wfRandomString();
		$vKey2 = wfRandomString();
		$tKey1 = wfRandomString();
		$tKey2 = wfRandomString();
		$value = 'moo';

		$knownPurge = time() - 60;
		$goodTime = microtime( true ) - 5;
		$badTime = microtime( true ) - 300;

		$bag->set(
			'WANCache:v:' . $vKey1,
			[
				0 => 1,
				1 => $value,
				2 => 3600,
				3 => $goodTime
			]
		);
		$bag->set(
			'WANCache:v:' . $vKey2,
			[
				0 => 1,
				1 => $value,
				2 => 3600,
				3 => $badTime
			]
		);
		$bag->set(
			'WANCache:t:' . $tKey1,
			'PURGED:' . $goodTime
		);
		$bag->set(
			'WANCache:t:' . $tKey2,
			'PURGED:' . $badTime
		);

		$this->assertSame( $value, $cache->get( $vKey1 ) );
		$this->assertSame( $value, $cache->get( $vKey2 ) );
		$cache->reap( $vKey1, $knownPurge, $bad1 );
		$cache->reap( $vKey2, $knownPurge, $bad2 );

		$this->assertSame( false, $bad1 );
		$this->assertTrue( $bad2 );

		$cache->reapCheckKey( $tKey1, $knownPurge, $tBad1 );
		$cache->reapCheckKey( $tKey2, $knownPurge, $tBad2 );
		$this->assertSame( false, $tBad1 );
		$this->assertTrue( $tBad2 );
	}

	/**
	 * @covers WANObjectCache::reap()
	 */
	public function testReap_fail() {
		$backend = $this->getMockBuilder( EmptyBagOStuff::class )
			->setMethods( [ 'get', 'changeTTL' ] )->getMock();
		$backend->expects( $this->once() )->method( 'get' )
			->willReturn( [
				0 => 1,
				1 => 'value',
				2 => 3600,
				3 => 300,
			] );
		$backend->expects( $this->once() )->method( 'changeTTL' )
			->willReturn( false );

		$wanCache = new WANObjectCache( [
			'cache' => $backend
		] );

		$isStale = null;
		$ret = $wanCache->reap( 'key', 360, $isStale );
		$this->assertTrue( $isStale, 'value was stale' );
		$this->assertSame( false, $ret, 'changeTTL failed' );
	}

	/**
	 * @covers WANObjectCache::set()
	 */
	public function testSetWithLag() {
		list( $cache ) = $this->newWanCache();
		$value = 1;

		$key = wfRandomString();
		$opts = [ 'lag' => 300, 'since' => microtime( true ) ];
		$cache->set( $key, $value, 30, $opts );
		$this->assertSame( $value, $cache->get( $key ), "Rep-lagged value written." );

		$key = wfRandomString();
		$opts = [ 'lag' => 0, 'since' => microtime( true ) - 300 ];
		$cache->set( $key, $value, 30, $opts );
		$this->assertSame( false, $cache->get( $key ), "Trx-lagged value not written." );

		$key = wfRandomString();
		$opts = [ 'lag' => 5, 'since' => microtime( true ) - 5 ];
		$cache->set( $key, $value, 30, $opts );
		$this->assertSame( false, $cache->get( $key ), "Lagged value not written." );
	}

	/**
	 * @covers WANObjectCache::set()
	 */
	public function testWritePending() {
		list( $cache ) = $this->newWanCache();
		$value = 1;

		$key = wfRandomString();
		$opts = [ 'pending' => true ];
		$cache->set( $key, $value, 30, $opts );
		$this->assertSame( false, $cache->get( $key ), "Pending value not written." );
	}

	public function testMcRouterSupport() {
		$localBag = $this->getMockBuilder( EmptyBagOStuff::class )
			->setMethods( [ 'set', 'delete' ] )->getMock();
		$localBag->expects( $this->never() )->method( 'set' );
		$localBag->expects( $this->never() )->method( 'delete' );
		$wanCache = new WANObjectCache( [
			'cache' => $localBag,
			'mcrouterAware' => true,
			'region' => 'pmtpa',
			'cluster' => 'mw-wan'
		] );
		$valFunc = function () {
			return 1;
		};

		// None of these should use broadcasting commands (e.g. SET, DELETE)
		$wanCache->get( 'x' );
		$wanCache->get( 'x', $ctl, [ 'check1' ] );
		$wanCache->getMulti( [ 'x', 'y' ] );
		$wanCache->getMulti( [ 'x', 'y' ], $ctls, [ 'check2' ] );
		$wanCache->getWithSetCallback( 'p', 30, $valFunc );
		$wanCache->getCheckKeyTime( 'zzz' );
		$wanCache->reap( 'x', time() - 300 );
		$wanCache->reap( 'zzz', time() - 300 );
	}

	public function testMcRouterSupportBroadcastDelete() {
		$localBag = $this->getMockBuilder( EmptyBagOStuff::class )
			->setMethods( [ 'set' ] )->getMock();
		$wanCache = new WANObjectCache( [
			'cache' => $localBag,
			'mcrouterAware' => true,
			'region' => 'pmtpa',
			'cluster' => 'mw-wan'
		] );

		$localBag->expects( $this->once() )->method( 'set' )
			->with( "/*/mw-wan/" . 'WANCache:v:' . "test" );

		$wanCache->delete( 'test' );
	}

	public function testMcRouterSupportBroadcastTouchCK() {
		$localBag = $this->getMockBuilder( EmptyBagOStuff::class )
			->setMethods( [ 'set' ] )->getMock();
		$wanCache = new WANObjectCache( [
			'cache' => $localBag,
			'mcrouterAware' => true,
			'region' => 'pmtpa',
			'cluster' => 'mw-wan'
		] );

		$localBag->expects( $this->once() )->method( 'set' )
			->with( "/*/mw-wan/" . 'WANCache:t:' . "test" );

		$wanCache->touchCheckKey( 'test' );
	}

	public function testMcRouterSupportBroadcastResetCK() {
		$localBag = $this->getMockBuilder( EmptyBagOStuff::class )
			->setMethods( [ 'delete' ] )->getMock();
		$wanCache = new WANObjectCache( [
			'cache' => $localBag,
			'mcrouterAware' => true,
			'region' => 'pmtpa',
			'cluster' => 'mw-wan'
		] );

		$localBag->expects( $this->once() )->method( 'delete' )
			->with( "/*/mw-wan/" . 'WANCache:t:' . "test" );

		$wanCache->resetCheckKey( 'test' );
	}

	public function testEpoch() {
		$bag = new HashBagOStuff();
		$cache = new WANObjectCache( [ 'cache' => $bag ] );
		$key = $cache->makeGlobalKey( 'The whole of the Law' );

		$now = microtime( true );
		$cache->setMockTime( $now );

		$cache->set( $key, 'Do what thou Wilt' );
		$cache->touchCheckKey( $key );

		$then = $now;
		$now += 30;
		$this->assertSame( 'Do what thou Wilt', $cache->get( $key ) );
		$this->assertEqualsWithDelta( $then, $cache->getCheckKeyTime( $key ), 0.01, 'Check key init' );

		$cache = new WANObjectCache( [
			'cache' => $bag,
			'epoch' => $now - 3600
		] );
		$cache->setMockTime( $now );

		$this->assertSame( 'Do what thou Wilt', $cache->get( $key ) );
		$this->assertEqualsWithDelta( $then, $cache->getCheckKeyTime( $key ), 0.01, 'Check key kept' );

		$now += 30;
		$cache = new WANObjectCache( [
			'cache' => $bag,
			'epoch' => $now + 3600
		] );
		$cache->setMockTime( $now );

		$this->assertSame( false, $cache->get( $key ), 'Key rejected due to epoch' );
		$this->assertEqualsWithDelta( $now, $cache->getCheckKeyTime( $key ), 0.01, 'Check key reset' );
	}

	/**
	 * @dataProvider provideAdaptiveTTL
	 * @covers WANObjectCache::adaptiveTTL()
	 * @param float|int $ago
	 * @param int $maxTTL
	 * @param int $minTTL
	 * @param float $factor
	 * @param int $adaptiveTTL
	 */
	public function testAdaptiveTTL( $ago, $maxTTL, $minTTL, $factor, $adaptiveTTL ) {
		list( $cache ) = $this->newWanCache();
		$mtime = $ago ? time() - $ago : $ago;
		$margin = 5;
		$ttl = $cache->adaptiveTTL( $mtime, $maxTTL, $minTTL, $factor );

		$this->assertGreaterThanOrEqual( $adaptiveTTL - $margin, $ttl );
		$this->assertLessThanOrEqual( $adaptiveTTL + $margin, $ttl );

		$ttl = $cache->adaptiveTTL( (string)$mtime, $maxTTL, $minTTL, $factor );

		$this->assertGreaterThanOrEqual( $adaptiveTTL - $margin, $ttl );
		$this->assertLessThanOrEqual( $adaptiveTTL + $margin, $ttl );
	}

	public static function provideAdaptiveTTL() {
		return [
			[ 3600, 900, 30, 0.2, 720 ],
			[ 3600, 500, 30, 0.2, 500 ],
			[ 3600, 86400, 800, 0.2, 800 ],
			[ false, 86400, 800, 0.2, 800 ],
			[ null, 86400, 800, 0.2, 800 ]
		];
	}

	/**
	 * @covers WANObjectCache::__construct
	 * @covers WANObjectCache::newEmpty
	 */
	public function testNewEmpty() {
		$this->assertInstanceOf(
			WANObjectCache::class,
			WANObjectCache::newEmpty()
		);
	}

	/**
	 * @covers WANObjectCache::setLogger
	 */
	public function testSetLogger() {
		list( $cache ) = $this->newWanCache();
		$this->assertSame( null, $cache->setLogger( new Psr\Log\NullLogger ) );
	}

	/**
	 * @covers WANObjectCache::getQoS
	 */
	public function testGetQoS() {
		$backend = $this->getMockBuilder( HashBagOStuff::class )
			->setMethods( [ 'getQoS' ] )->getMock();
		$backend->expects( $this->once() )->method( 'getQoS' )
			->willReturn( BagOStuff::QOS_UNKNOWN );
		$wanCache = new WANObjectCache( [ 'cache' => $backend ] );

		$this->assertSame(
			$wanCache::QOS_UNKNOWN,
			$wanCache->getQoS( $wanCache::ATTR_EMULATION )
		);
	}

	/**
	 * @covers WANObjectCache::makeKey
	 */
	public function testMakeKey() {
		$backend = $this->getMockBuilder( HashBagOStuff::class )
			->setMethods( [ 'makeKey' ] )->getMock();
		$backend->expects( $this->once() )->method( 'makeKey' )
			->willReturn( 'special' );

		$wanCache = new WANObjectCache( [
			'cache' => $backend
		] );

		$this->assertSame( 'special', $wanCache->makeKey( 'a', 'b' ) );
	}

	/**
	 * @covers WANObjectCache::makeGlobalKey
	 */
	public function testMakeGlobalKey() {
		$backend = $this->getMockBuilder( HashBagOStuff::class )
			->setMethods( [ 'makeGlobalKey' ] )->getMock();
		$backend->expects( $this->once() )->method( 'makeGlobalKey' )
			->willReturn( 'special' );

		$wanCache = new WANObjectCache( [
			'cache' => $backend
		] );

		$this->assertSame( 'special', $wanCache->makeGlobalKey( 'a', 'b' ) );
	}

	public static function statsKeyProvider() {
		return [
			[ 'domain:page:5', 'page' ],
			[ 'domain:main-key', 'main-key' ],
			[ 'domain:page:history', 'page' ],
			// Regression test for T232907
			[ 'domain:foo-bar-1.2:abc:v2', 'foo-bar-1_2' ],
			[ 'missingdomainkey', 'missingdomainkey' ]
		];
	}

	/**
	 * @dataProvider statsKeyProvider
	 * @covers WANObjectCache::determineKeyClassForStats
	 * @param string $key
	 * @param string $class
	 */
	public function testStatsKeyClass( $key, $class ) {
		/** @var WANObjectCache $wanCache */
		$wanCache = TestingAccessWrapper::newFromObject( new WANObjectCache( [
			'cache' => new HashBagOStuff
		] ) );

		$this->assertSame( $class, $wanCache->determineKeyClassForStats( $key ) );
	}

	/**
	 * @covers WANObjectCache::makeMultiKeys
	 */
	public function testMakeMultiKeys() {
		list( $cache ) = $this->newWanCache();

		$ids = [ 1, 2, 3, 4, 4, 5, 6, 6, 7, 7 ];
		$keyCallback = function ( $id, WANObjectCache $cache ) {
			return $cache->makeKey( 'key', $id );
		};
		$keyedIds = $cache->makeMultiKeys( $ids, $keyCallback );

		$expected = [
			"local:key:1" => 1,
			"local:key:2" => 2,
			"local:key:3" => 3,
			"local:key:4" => 4,
			"local:key:5" => 5,
			"local:key:6" => 6,
			"local:key:7" => 7
		];
		$this->assertSame( $expected, iterator_to_array( $keyedIds ) );

		$ids = [ '1', '2', '3', '4', '4', '5', '6', '6', '7', '7' ];
		$keyCallback = function ( $id, WANObjectCache $cache ) {
			return $cache->makeGlobalKey( 'key', $id, 'a', $id, 'b' );
		};
		$keyedIds = $cache->makeMultiKeys( $ids, $keyCallback );

		$expected = [
			"global:key:1:a:1:b" => '1',
			"global:key:2:a:2:b" => '2',
			"global:key:3:a:3:b" => '3',
			"global:key:4:a:4:b" => '4',
			"global:key:5:a:5:b" => '5',
			"global:key:6:a:6:b" => '6',
			"global:key:7:a:7:b" => '7'
		];
		$this->assertSame( $expected, iterator_to_array( $keyedIds ) );
	}

	/**
	 * @covers WANObjectCache::makeMultiKeys
	 */
	public function testMakeMultiKeysIntString() {
		list( $cache ) = $this->newWanCache();
		$ids = [ 1, 2, 3, 4, '4', 5, 6, 6, 7, '7' ];
		$keyCallback = function ( $id, WANObjectCache $cache ) {
			return $cache->makeGlobalKey( 'key', $id, 'a', $id, 'b' );
		};

		$keyedIds = $cache->makeMultiKeys( $ids, $keyCallback );

		$expected = [
			"global:key:1:a:1:b" => 1,
			"global:key:2:a:2:b" => 2,
			"global:key:3:a:3:b" => 3,
			"global:key:4:a:4:b" => 4,
			"global:key:5:a:5:b" => 5,
			"global:key:6:a:6:b" => 6,
			"global:key:7:a:7:b" => 7
		];
		$this->assertSame( $expected, iterator_to_array( $keyedIds ) );
	}

	/**
	 * @covers WANObjectCache::makeMultiKeys
	 */
	public function testMakeMultiKeysCollision() {
		list( $cache ) = $this->newWanCache();
		$ids = [ 1, 2, 3, 4, '4', 5, 6, 6, 7 ];

		$this->expectException( UnexpectedValueException::class );
		$cache->makeMultiKeys(
			$ids,
			function ( $id ) {
				return "keymod:" . $id % 3;
			}
		);
	}

	/**
	 * @covers WANObjectCache::multiRemap
	 */
	public function testMultiRemap() {
		list( $cache ) = $this->newWanCache();
		$a = [ 'a', 'b', 'c' ];
		$res = [ 'keyA' => 1, 'keyB' => 2, 'keyC' => 3 ];

		$this->assertSame(
			[ 'a' => 1, 'b' => 2, 'c' => 3 ],
			$cache->multiRemap( $a, $res )
		);

		$a = [ 'a', 'b', 'c', 'c', 'd' ];
		$res = [ 'keyA' => 1, 'keyB' => 2, 'keyC' => 3, 'keyD' => 4 ];

		$this->assertSame(
			[ 'a' => 1, 'b' => 2, 'c' => 3, 'd' => 4 ],
			$cache->multiRemap( $a, $res )
		);
	}

	/**
	 * @covers WANObjectCache::hash256
	 */
	public function testHash256() {
		list( $cache ) = $this->newWanCache( [ 'epoch' => 5 ] );
		$this->assertEquals(
			'f402bce76bfa1136adc705d8d5719911ce1fe61f0ad82ddf79a15f3c4de6ec4c',
			$cache->hash256( 'x' )
		);

		list( $cache ) = $this->newWanCache( [ 'epoch' => 50 ] );
		$this->assertSame(
			'f79a126722f0a682c4c500509f1b61e836e56c4803f92edc89fc281da5caa54e',
			$cache->hash256( 'x' )
		);

		list( $cache ) = $this->newWanCache( [ 'secret' => 'garden' ] );
		$this->assertSame(
			'48cd57016ffe29981a1114c45e5daef327d30fc6206cb73edc3cb94b4d8fe093',
			$cache->hash256( 'x' )
		);

		list( $cache ) = $this->newWanCache( [ 'secret' => 'garden', 'epoch' => 3 ] );
		$this->assertSame(
			'48cd57016ffe29981a1114c45e5daef327d30fc6206cb73edc3cb94b4d8fe093',
			$cache->hash256( 'x' )
		);
	}
}

class McrouterHashBagOStuff extends HashBagOStuff {
	public function delete( $key, $flags = 0 ) {
		// Convert mcrouter broadcast keys to regular keys in HashBagOStuff::delete() calls
		// https://github.com/facebook/mcrouter/wiki/Multi-cluster-broadcast-setup
		if ( preg_match( '#^/\*/[^/]+/(.*)$#', $key, $m ) ) {
			$key = $m[1];
		}

		return parent::delete( $key, $flags );
	}
}

class NearExpiringWANObjectCache extends WANObjectCache {
	const CLOCK_SKEW = 1;

	protected function worthRefreshExpiring( $curTTL, $lowTTL ) {
		return ( $curTTL > 0 && ( $curTTL + self::CLOCK_SKEW ) < $lowTTL );
	}
}

class PopularityRefreshingWANObjectCache extends WANObjectCache {
	protected function worthRefreshPopular( $asOf, $ageNew, $timeTillRefresh, $now ) {
		return ( ( $now - $asOf ) > $timeTillRefresh );
	}
}
