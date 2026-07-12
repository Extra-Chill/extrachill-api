<?php
/**
 * Tests for docs-info post type support metadata.
 *
 * @package ExtraChill\API\Tests
 */

/**
 * Tests docs-info post type support metadata.
 */
class Docs_InfoTest extends WP_UnitTestCase { // phpcs:ignore Generic.Classes.OpeningBraceSameLine -- WP test convention.

	/**
	 * Removes post types registered by tests.
	 */
	public function tear_down() {
		unregister_post_type( 'api_doc_supported' );
		unregister_post_type( 'api_doc_none' );

		parent::tear_down();
	}

	/**
	 * Feature-keyed core supports are normalized to feature names.
	 */
	public function test_post_type_supports_are_returned_as_feature_names() {
		register_post_type(
			'api_doc_supported',
			array(
				'public'   => true,
				'supports' => array( 'title', 'editor' ),
			)
		);

		$this->assertTrue( get_all_post_type_supports( 'api_doc_supported' )['title'] );
		$this->assertTrue( get_all_post_type_supports( 'api_doc_supported' )['editor'] );
		$this->assertSame(
			array_keys( get_all_post_type_supports( 'api_doc_supported' ) ),
			extrachill_api_docs_info_get_post_type_supports( 'api_doc_supported' )
		);
	}

	/**
	 * Post types without supports return an empty feature list.
	 */
	public function test_post_type_without_supports_returns_empty_list() {
		register_post_type(
			'api_doc_none',
			array(
				'public'   => true,
				'supports' => false,
			)
		);

		$this->assertSame( array(), get_all_post_type_supports( 'api_doc_none' ) );
		$this->assertSame( array(), extrachill_api_docs_info_get_post_type_supports( 'api_doc_none' ) );
	}
}
