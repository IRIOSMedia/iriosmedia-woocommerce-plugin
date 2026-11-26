<?php

namespace Irios\WooCommerce\Tests;

use Irios\WooCommerce\Languages;
use Irios\WooCommerce\Translations;

/** Without a multi-language plugin the ids stay as they are and the sync service is told so. */
final class LanguagesTest extends WordPressTestCase {

	public function test_without_a_multi_language_plugin_nothing_changes(): void {
		$this->assertNull( Languages::plugin() );
		$this->assertNull( Languages::default_language() );
		$this->assertSame( array(), Languages::languages() );
		$this->assertSame( 9120, Languages::original_id( 9120 ) );
		$this->assertSame( 9120, Languages::local_id( 9120 ) );
		$this->assertTrue( Languages::is_default_language_term( 15 ) );
	}

	public function test_the_sync_service_is_told_there_are_no_languages(): void {
		$data = Translations::response( new \WP_REST_Request( array( 'ids' => '7237' ) ) )->get_data();

		$this->assertNull( $data['plugin'] );
		$this->assertNull( $data['default_language'] );
		$this->assertEquals( new \stdClass(), $data['products'] );
	}
}
