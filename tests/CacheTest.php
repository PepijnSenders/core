<?php
/**
 * CacheTest
 */
namespace Sledgehammer;
/**
 * Unittests for the Cache object.
 *
 * Untested: Caching with concurrency (blocking locks)
 * Untested: Max age
 *
 * @package Core
 */
class CacheTest extends TestCase {

	/**
	 * var int Counter which increments when the cache expired.
	 */
	private $counter = 0;

	/**
	 * @var bool true: when apc extension is installed
	 */
	private $apcSupported;

	function test_startup() {
		mkdirs(TMP_DIR.'Cache');
		$cache = Cache::rootNode();
		$this->assertInstanceOf('Sledgehammer\Cache', $cache);
		$this->apcSupported = function_exists('apc_fetch');
		if ($this->apcSupported === false) {
			$this->markTestSkipped('Skipping tests for "apc" backend, the php-extension "apc" is not installed.');
		}
	}

	function test_cache_miss() {
		$this->cache_miss_test('file');
		if ($this->apcSupported) {
			$this->cache_miss_test('apc');
		}
	}

	function test_cache_hit() {
		$this->cache_hit_test('file');
		if ($this->apcSupported) {
			$this->cache_hit_test('apc');
		}
	}

	function test_cache_expires() {
		$this->cache_expires_test('file');
		if ($this->apcSupported) {
			$this->cache_expires_test('apc');
		}
	}

	function test_invalid_option() {
		try {
			cache(__FILE__.__FUNCTION__, array('a invalid option' => 'yes'), array($this, 'incrementCounter'));
			$this->fail('An invalid options should generate a notice');
		} catch (\PHPUnit_Framework_Error_Notice $e) {
			$this->assertEquals('Option: "a invalid option" is invalid', $e->getMessage());
		}
	}

	function test_invalid_max_age() {
		cache(__FILE__.__FUNCTION__, array('expires' => '+1 min'), array($this, 'incrementCounter')); // create a cache entry. (no entry == no max_age validation)
		try {
			cache(__FILE__.__FUNCTION__, array('max_age' => '+1 min'), array($this, 'incrementCounter'));
			$this->fail('An max_age in the future should generate a notice');
		} catch (\PHPUnit_Framework_Error_Notice $e) {
			$this->assertEquals('maxAge is 60 seconds in the future', $e->getMessage());
		}
	}

	private function cache_miss_test($type) {
		$this->counter = 0;
		$cache = new CacheTester(__FILE__.__FUNCTION__, $type);
		$counter = $cache->value('+1sec', array($this, 'incrementCounter')); // miss
		$this->assertEquals(1, $counter, 'The counter should be incremented');
		$cache->clear();
	}

	private function cache_hit_test($type) {
		$this->counter = 0;
		$cache = new CacheTester(__FILE__.__FUNCTION__, $type);
		$counter1 = $cache->value('+1sec', array($this, 'incrementCounter')); // miss/store
		$this->assertEquals(1, $counter1, 'Sanity check');
		$counter2 = $cache->value('+1sec', array($this, 'incrementCounter')); // hit
		$this->assertEquals(1, $counter2, 'The counter should only be incremented once');
		$cache->clear();
	}

	private function cache_expires_test($type) {
		$this->counter = 0;
		$cache = new CacheTester(__FILE__.__FUNCTION__, $type);
		$counter1 = $cache->value('+1sec', array($this, 'incrementCounter')); // miss/store
		$this->assertEquals(1, $counter1, 'Sanity check');
		usleep(100000); // 0.1 sec
		$counter2 = $cache->value('+1sec', array($this, 'incrementCounter')); // hit
		$this->assertEquals(1, $counter2, 'Should not be expired just yet');
		sleep(2); // Wait 2 sec for the cache to expire.
		$counter3 = $cache->value('+1sec', array($this, 'incrementCounter')); // miss (expired)
		$this->assertEquals(2, $counter3, 'Should be expired (and incremented again)');
		$cache->clear();
	}

	/**
	 * Callback to detect if the operation was cached.
	 */
	function incrementCounter() {
		$this->counter++;
		return $this->counter;
	}

}

?>
