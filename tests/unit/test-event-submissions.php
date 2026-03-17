<?php
/**
 * Unit tests for Event Submission endpoint.
 *
 * Tests field extraction, validation, workflow building,
 * and routing logic for the event submission REST endpoint.
 *
 * @package ExtraChill\API\Tests
 */

use PHPUnit\Framework\TestCase;

class Test_Event_Submissions extends TestCase {

	/**
	 * Build a valid submission request for testing.
	 *
	 * @param array $overrides Optional field overrides.
	 * @return WP_REST_Request Mock request with valid submission fields.
	 */
	private function get_valid_submission_request( array $overrides = array() ) {
		$defaults = array(
			'contact_name'  => 'Test Submitter',
			'contact_email' => 'test@example.com',
			'event_title'   => 'Live at The Royal American',
			'event_date'    => '2026-04-15',
			'event_time'    => '9:00 PM',
			'venue_name'    => 'The Royal American',
			'event_city'    => 'Charleston',
			'event_lineup'  => 'Band A, Band B, Band C',
			'event_link'    => 'https://tickets.example.com/event/123',
			'notes'         => 'Doors at 8pm, 21+',
		);

		$params  = array_merge( $defaults, $overrides );
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/event-submissions' );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $request;
	}

	// -------------------------------------------------------------------------
	// Field extraction tests
	// -------------------------------------------------------------------------

	/**
	 * Test extract returns all fields from a complete submission.
	 */
	public function test_extract_fields_complete_submission() {
		$request = $this->get_valid_submission_request();
		$result  = extrachill_api_extract_submission_fields( $request );

		$this->assertIsArray( $result );
		$this->assertEquals( 'Test Submitter', $result['contact_name'] );
		$this->assertEquals( 'test@example.com', $result['contact_email'] );
		$this->assertEquals( 'Live at The Royal American', $result['event_title'] );
		$this->assertEquals( '2026-04-15', $result['event_date'] );
		$this->assertEquals( '9:00 PM', $result['event_time'] );
		$this->assertEquals( 'The Royal American', $result['venue_name'] );
		$this->assertEquals( 'Charleston', $result['event_city'] );
		$this->assertEquals( 'Band A, Band B, Band C', $result['event_lineup'] );
		$this->assertEquals( 'https://tickets.example.com/event/123', $result['event_link'] );
		$this->assertEquals( 'Doors at 8pm, 21+', $result['notes'] );
	}

	/**
	 * Test extract returns error when event title is missing.
	 */
	public function test_extract_fields_missing_title() {
		$request = $this->get_valid_submission_request( array( 'event_title' => '' ) );
		$result  = extrachill_api_extract_submission_fields( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'missing_fields', $result->get_error_code() );
	}

	/**
	 * Test extract returns error when event date is missing.
	 */
	public function test_extract_fields_missing_date() {
		$request = $this->get_valid_submission_request( array( 'event_date' => '' ) );
		$result  = extrachill_api_extract_submission_fields( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'missing_fields', $result->get_error_code() );
	}

	/**
	 * Test extract returns error when contact name is missing (logged-out user).
	 */
	public function test_extract_fields_missing_contact_name() {
		$request = $this->get_valid_submission_request( array( 'contact_name' => '' ) );
		$result  = extrachill_api_extract_submission_fields( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'missing_fields', $result->get_error_code() );
	}

	/**
	 * Test extract returns error when contact email is missing (logged-out user).
	 */
	public function test_extract_fields_missing_contact_email() {
		$request = $this->get_valid_submission_request( array( 'contact_email' => '' ) );
		$result  = extrachill_api_extract_submission_fields( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'missing_fields', $result->get_error_code() );
	}

	/**
	 * Test extract returns error when email is invalid format.
	 */
	public function test_extract_fields_invalid_email() {
		$request = $this->get_valid_submission_request( array( 'contact_email' => 'not-an-email' ) );
		$result  = extrachill_api_extract_submission_fields( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'invalid_email', $result->get_error_code() );
	}

	/**
	 * Test extract handles optional fields being empty.
	 */
	public function test_extract_fields_optional_fields_empty() {
		$request = $this->get_valid_submission_request( array(
			'event_time'   => '',
			'venue_name'   => '',
			'event_city'   => '',
			'event_lineup' => '',
			'event_link'   => '',
			'notes'        => '',
		) );
		$result  = extrachill_api_extract_submission_fields( $request );

		$this->assertIsArray( $result );
		$this->assertEquals( 'Live at The Royal American', $result['event_title'] );
		$this->assertEquals( '2026-04-15', $result['event_date'] );
		$this->assertEmpty( $result['event_time'] );
		$this->assertEmpty( $result['venue_name'] );
		$this->assertEmpty( $result['event_city'] );
		$this->assertEmpty( $result['event_lineup'] );
		$this->assertEmpty( $result['notes'] );
	}

	/**
	 * Test extract sanitizes HTML from text fields.
	 */
	public function test_extract_fields_sanitizes_html() {
		$request = $this->get_valid_submission_request( array(
			'event_title' => '<script>alert("xss")</script>My Event',
			'notes'       => '<b>Bold</b> notes with <script>alert(1)</script>',
		) );
		$result  = extrachill_api_extract_submission_fields( $request );

		$this->assertIsArray( $result );
		$this->assertStringNotContainsString( '<script>', $result['event_title'] );
		$this->assertStringNotContainsString( '<script>', $result['notes'] );
	}

	/**
	 * Test extract sets user_id to 0 for logged-out users.
	 */
	public function test_extract_fields_logged_out_user_id() {
		$request = $this->get_valid_submission_request();
		$result  = extrachill_api_extract_submission_fields( $request );

		$this->assertIsArray( $result );
		$this->assertEquals( 0, $result['user_id'] );
	}

	// -------------------------------------------------------------------------
	// Workflow building tests
	// -------------------------------------------------------------------------

	/**
	 * Test workflow has 3 steps when flyer is present.
	 */
	public function test_workflow_with_flyer_has_three_steps() {
		$submission = array(
			'event_title' => 'Test Event',
			'event_date'  => '2026-04-15',
			'event_time'  => '9:00 PM',
			'venue_name'  => 'Test Venue',
			'event_city'  => 'Charleston',
			'event_lineup' => 'Band A',
			'event_link'  => 'https://example.com',
		);
		$flyer      = array(
			'filename'    => 'flyer.jpg',
			'stored_path' => '/tmp/stored/flyer.jpg',
			'mime_type'   => 'image/jpeg',
		);

		$workflow = extrachill_api_build_event_submission_workflow(
			$submission,
			$flyer,
			'openai',
			'gpt-5-mini'
		);

		$this->assertArrayHasKey( 'steps', $workflow );
		$this->assertCount( 3, $workflow['steps'] );
		$this->assertEquals( 'event_import', $workflow['steps'][0]['type'] );
		$this->assertEquals( 'event_flyer', $workflow['steps'][0]['handler_slug'] );
		$this->assertEquals( 'ai', $workflow['steps'][1]['type'] );
		$this->assertEquals( 'update', $workflow['steps'][2]['type'] );
		$this->assertEquals( 'upsert_event', $workflow['steps'][2]['handler_slug'] );
	}

	/**
	 * Test workflow has 2 steps when no flyer is present.
	 */
	public function test_workflow_without_flyer_has_two_steps() {
		$submission = array(
			'event_title' => 'Test Event',
			'event_date'  => '2026-04-15',
			'event_time'  => '',
			'venue_name'  => '',
			'event_city'  => '',
			'event_lineup' => '',
			'event_link'  => '',
		);

		$workflow = extrachill_api_build_event_submission_workflow(
			$submission,
			null,
			'openai',
			'gpt-5-mini'
		);

		$this->assertCount( 2, $workflow['steps'] );
		$this->assertEquals( 'ai', $workflow['steps'][0]['type'] );
		$this->assertEquals( 'update', $workflow['steps'][1]['type'] );
	}

	/**
	 * Test workflow uses custom system prompt when provided.
	 */
	public function test_workflow_custom_system_prompt() {
		$submission = array(
			'event_title' => 'Test Event',
			'event_date'  => '2026-04-15',
			'event_time'  => '',
			'venue_name'  => '',
			'event_city'  => '',
			'event_lineup' => '',
			'event_link'  => '',
		);

		$custom_prompt = 'Custom instructions for processing this event.';
		$workflow      = extrachill_api_build_event_submission_workflow(
			$submission,
			null,
			'openai',
			'gpt-5-mini',
			$custom_prompt
		);

		$ai_step = $workflow['steps'][0];
		$this->assertEquals( $custom_prompt, $ai_step['system_prompt'] );
	}

	/**
	 * Test workflow uses default prompt when none provided.
	 */
	public function test_workflow_default_system_prompt() {
		$submission = array(
			'event_title' => 'Test Event',
			'event_date'  => '2026-04-15',
			'event_time'  => '',
			'venue_name'  => '',
			'event_city'  => '',
			'event_lineup' => '',
			'event_link'  => '',
		);

		$workflow = extrachill_api_build_event_submission_workflow(
			$submission,
			null,
			'openai',
			'gpt-5-mini',
			''
		);

		$ai_step = $workflow['steps'][0];
		$this->assertStringContainsString( 'event submission', $ai_step['system_prompt'] );
		$this->assertStringContainsString( 'upsert_event', $ai_step['system_prompt'] );
	}

	/**
	 * Test workflow AI step enables upsert_event tool.
	 */
	public function test_workflow_ai_step_enables_upsert_tool() {
		$submission = array(
			'event_title' => 'Test Event',
			'event_date'  => '2026-04-15',
			'event_time'  => '',
			'venue_name'  => '',
			'event_city'  => '',
			'event_lineup' => '',
			'event_link'  => '',
		);

		$workflow = extrachill_api_build_event_submission_workflow(
			$submission,
			null,
			'anthropic',
			'claude-sonnet-4-20250514'
		);

		$ai_step = $workflow['steps'][0];
		$this->assertContains( 'upsert_event', $ai_step['enabled_tools'] );
	}

	/**
	 * Test workflow sets post_status to pending.
	 */
	public function test_workflow_upsert_step_status_pending() {
		$submission = array(
			'event_title' => 'Test Event',
			'event_date'  => '2026-04-15',
			'event_time'  => '',
			'venue_name'  => '',
			'event_city'  => '',
			'event_lineup' => '',
			'event_link'  => '',
		);

		$workflow = extrachill_api_build_event_submission_workflow(
			$submission,
			null,
			'openai',
			'gpt-5-mini'
		);

		$upsert_step = end( $workflow['steps'] );
		$this->assertEquals( 'pending', $upsert_step['handler_config']['post_status'] );
	}

	/**
	 * Test workflow passes provider and model to AI step.
	 */
	public function test_workflow_passes_provider_and_model() {
		$submission = array(
			'event_title' => 'Test Event',
			'event_date'  => '2026-04-15',
			'event_time'  => '',
			'venue_name'  => '',
			'event_city'  => '',
			'event_lineup' => '',
			'event_link'  => '',
		);

		$workflow = extrachill_api_build_event_submission_workflow(
			$submission,
			null,
			'anthropic',
			'claude-sonnet-4-20250514'
		);

		$ai_step = $workflow['steps'][0];
		$this->assertEquals( 'anthropic', $ai_step['provider'] );
		$this->assertEquals( 'claude-sonnet-4-20250514', $ai_step['model'] );
	}

	/**
	 * Test workflow passes submission data to handler config.
	 */
	public function test_workflow_handler_config_contains_submission_data() {
		$submission = array(
			'event_title' => 'Blues Night',
			'event_date'  => '2026-05-01',
			'event_time'  => '8:00 PM',
			'venue_name'  => 'C-Boys Heart & Soul',
			'event_city'  => 'Austin',
			'event_lineup' => 'Blues Band',
			'event_link'  => 'https://tickets.example.com/blues',
		);

		$flyer    = array(
			'filename'    => 'blues-flyer.png',
			'stored_path' => '/tmp/stored/blues-flyer.png',
			'mime_type'   => 'image/png',
		);

		$workflow = extrachill_api_build_event_submission_workflow(
			$submission,
			$flyer,
			'openai',
			'gpt-5-mini'
		);

		$flyer_step_config = $workflow['steps'][0]['handler_config'];
		$this->assertEquals( 'Blues Night', $flyer_step_config['title'] );
		$this->assertEquals( '2026-05-01', $flyer_step_config['startDate'] );
		$this->assertEquals( '8:00 PM', $flyer_step_config['startTime'] );
		$this->assertEquals( 'C-Boys Heart & Soul', $flyer_step_config['venue_name'] );
		$this->assertEquals( 'Austin', $flyer_step_config['venue_city'] );
		$this->assertEquals( 'Blues Band', $flyer_step_config['performer'] );
		$this->assertEquals( 'https://tickets.example.com/blues', $flyer_step_config['ticketUrl'] );
	}

	/**
	 * Test workflow include_images is true when flyer present.
	 */
	public function test_workflow_include_images_with_flyer() {
		$submission = array(
			'event_title' => 'Test',
			'event_date'  => '2026-04-15',
			'event_time'  => '',
			'venue_name'  => '',
			'event_city'  => '',
			'event_lineup' => '',
			'event_link'  => '',
		);

		$flyer = array(
			'filename'    => 'poster.jpg',
			'stored_path' => '/tmp/stored/poster.jpg',
			'mime_type'   => 'image/jpeg',
		);

		$workflow = extrachill_api_build_event_submission_workflow(
			$submission,
			$flyer,
			'openai',
			'gpt-5-mini'
		);

		$upsert_step = end( $workflow['steps'] );
		$this->assertTrue( $upsert_step['handler_config']['include_images'] );
	}

	/**
	 * Test workflow include_images is false when no flyer.
	 */
	public function test_workflow_include_images_without_flyer() {
		$submission = array(
			'event_title' => 'Test',
			'event_date'  => '2026-04-15',
			'event_time'  => '',
			'venue_name'  => '',
			'event_city'  => '',
			'event_lineup' => '',
			'event_link'  => '',
		);

		$workflow = extrachill_api_build_event_submission_workflow(
			$submission,
			null,
			'openai',
			'gpt-5-mini'
		);

		$upsert_step = end( $workflow['steps'] );
		$this->assertFalse( $upsert_step['handler_config']['include_images'] );
	}

	// -------------------------------------------------------------------------
	// Main handler routing tests
	// -------------------------------------------------------------------------

	/**
	 * Test handler returns error when turnstile function is missing.
	 */
	public function test_handler_missing_turnstile_function() {
		// This test verifies the guard clause — ec_verify_turnstile_response
		// must exist. In test env it won't unless we define it.
		// The function check is the first thing in the handler.
		if ( function_exists( 'ec_verify_turnstile_response' ) ) {
			$this->markTestSkipped( 'ec_verify_turnstile_response is already defined in test environment.' );
		}

		$request = $this->get_valid_submission_request();
		$request->set_param( 'turnstile_response', 'test-token' );

		$result = extrachill_api_handle_event_submission( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'turnstile_missing', $result->get_error_code() );
	}
}
