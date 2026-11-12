<?php
/**
 * REST API for the dashboard.
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes;

use Acato\RedirectInspector\Includes\Analysis\Analyzer;
use Acato\RedirectInspector\Includes\Sources\Registry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Namespace redirect-inspector/v1. Every route requires a capability (S6); the wp_rest nonce is
 * verified by core for cookie-authenticated requests.
 */
final class Rest_Controller extends Singleton {

	/**
	 * Namespace.
	 */
	const NAMESPACE = 'redirect-inspector/v1';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$manage = [ Caps::class, 'can_manage' ];

		register_rest_route(
			self::NAMESPACE,
			'/summary',
			[
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $manage,
				'callback'            => [ $this, 'summary' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/issues',
			[
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $manage,
				'callback'            => [ $this, 'issues' ],
				'args'                => [
					'scan'     => [
						'type'              => 'integer',
						'minimum'           => 0,
						'default'           => 0,
						'sanitize_callback' => 'absint',
					],
					'types'    => [
						'type'    => 'array',
						'items'   => [
							'type' => 'string',
							'enum' => Analyzer::TYPES,
						],
						'default' => [],
					],
					'plugin'   => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					],
					'search'   => [
						'type'              => 'string',
						'default'           => '',
						'maxLength'         => 200,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'page'     => [
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					],
					'per_page' => [
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/scans',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => $manage,
					'callback'            => [ $this, 'scans' ],
					'args'                => [
						'page'     => [
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						],
						'per_page' => [
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
							'default' => 10,
						],
						'date'     => [
							'type'    => 'string',
							'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
						],
						'orderby'  => [
							'type'    => 'string',
							'enum'    => array_keys( Log_Repository::SCAN_ORDER ),
							'default' => 'date',
						],
						'order'    => [
							'type'    => 'string',
							'enum'    => [ 'asc', 'desc' ],
							'default' => 'desc',
						],
					],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => $manage,
					'callback'            => [ $this, 'start_scan' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/issues/(?P<id>\\d+)/fix',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $manage,
				'callback'            => [ $this, 'apply_fix' ],
				'args'                => [
					'id' => [
						'type'              => 'integer',
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/migrate',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $manage,
				'callback'            => [ $this, 'migrate' ],
				'args'                => [
					'from'    => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					],
					'to'      => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					],
					'dry_run' => [
						'type'    => 'boolean',
						'default' => true,
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/live-check',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $manage,
				'callback'            => [ $this, 'live_check' ],
				'args'                => [
					'issue_id' => [
						'type'              => 'integer',
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					],
					'urls'     => [
						'type'     => 'array',
						'maxItems' => Live_Checker::MAX_URLS,
						'items'    => [
							'type'      => 'string',
							'maxLength' => 2048,
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => $manage,
					'callback'            => [ $this, 'settings' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'permission_callback' => $manage,
					'callback'            => [ $this, 'save_settings' ],
					'args'                => [
						'retention_days' => [
							'type'    => 'integer',
							'minimum' => Settings::MIN_RETENTION_DAYS,
							'maximum' => Settings::MAX_RETENTION_DAYS,
						],
						'schedule'       => [
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => [
								'enabled' => [ 'type' => 'boolean' ],
								'days'    => [
									'type'        => 'array',
									'maxItems'    => 7,
									'uniqueItems' => true,
									'items'       => [
										'type'    => 'integer',
										'minimum' => 0,
										'maximum' => 6,
									],
								],
								'hours'   => [
									'type'        => 'array',
									'maxItems'    => 24,
									'uniqueItems' => true,
									'items'       => [
										'type'    => 'integer',
										'minimum' => 0,
										'maximum' => 23,
									],
								],
							],
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/logs',
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'permission_callback' => $manage,
				'callback'            => [ $this, 'purge_logs' ],
			]
		);

		if ( is_multisite() && is_main_site() ) {
			register_rest_route(
				self::NAMESPACE,
				'/network/sites',
				[
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => [ Caps::class, 'can_view_network' ],
					'callback'            => [ $this, 'network_sites' ],
					'args'                => [
						'page'   => [
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						],
						'search' => [
							'type'              => 'string',
							'default'           => '',
							'maxLength'         => 100,
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				]
			);
		}
	}

	/**
	 * Fail when the tables are missing.
	 *
	 * @return WP_Error|null
	 */
	private function not_installed(): ?WP_Error {
		if ( Installer::is_installed() || Installer::install() ) {
			return null;
		}

		return new WP_Error( 'redirect_inspector_not_installed', __( 'The Redirect Inspector tables are missing and could not be created.', 'redirect-inspector' ), [ 'status' => 500 ] );
	}

	/**
	 * Dashboard summary: latest scan, trend, sources, settings.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function summary() {
		$error = $this->not_installed();
		if ( $error ) {
			return $error;
		}

		$history = Log_Repository::scans( 20 );
		$trend   = array_reverse(
			array_values(
				array_filter( $history['items'], static fn( $scan ) => in_array( $scan['status'], [ 'done', 'partial' ], true ) )
			)
		);

		$sources = [];
		foreach ( Registry::all() as $slug => $source ) {
			$available = $source->is_available();
			$sources[] = [
				'slug'      => $slug,
				'label'     => $source->label(),
				'available' => $available,
				'rules'     => $available ? $source->count() : 0,
			];
		}

		return new WP_REST_Response(
			[
				'latest'    => Log_Repository::latest_scan(),
				'trend'     => array_map(
					static fn( $scan ) => [
						'id'          => $scan['id'],
						'finished_at' => $scan['finished_at'],
						'score'       => $scan['score'],
						'issue_count' => $scan['issue_count'],
						'types'       => $scan['totals']['types'] ?? (object) [],
						'severities'  => $scan['totals']['severities'] ?? (object) [],
					],
					$trend
				),
				'sources'   => $sources,
				'running'   => Scanner::is_running(),
				'settings'  => Settings::all(),
				'retention' => self::retention(),
				'next_scan' => self::next_scan(),
				'leftovers' => Migrator::leftovers(),
				'targets'   => Migrator::targets(),
			]
		);
	}

	/**
	 * Retention text and next cleanup time.
	 *
	 * @return array{text:string, next_cleanup:string|null}
	 */
	private static function retention(): array {
		$next = wp_next_scheduled( Retention::HOOK );

		return [
			'text'         => Retention::describe(),
			'next_cleanup' => $next ? gmdate( 'c', $next ) : null,
		];
	}

	/**
	 * Issues of a scan (latest by default).
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function issues( WP_REST_Request $request ) {
		$error = $this->not_installed();
		if ( $error ) {
			return $error;
		}

		$scan_id = (int) $request['scan'];
		if ( ! $scan_id ) {
			$latest  = Log_Repository::latest_scan();
			$scan_id = $latest ? $latest['id'] : 0;
		}

		if ( ! $scan_id ) {
			return new WP_REST_Response(
				[
					'items' => [],
					'total' => 0,
				]
			);
		}

		$result = Log_Repository::issues(
			$scan_id,
			array_map( 'strval', (array) $request['types'] ),
			(string) $request['plugin'],
			(string) $request['search'],
			(int) $request['page'],
			(int) $request['per_page']
		);

		$response = new WP_REST_Response( $result );
		$response->header( 'X-WP-Total', (string) $result['total'] );

		return $response;
	}

	/**
	 * Scan history.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function scans( WP_REST_Request $request ) {
		$error = $this->not_installed();
		if ( $error ) {
			return $error;
		}

		$per_page = (int) $request['per_page'];
		$result   = Log_Repository::scans(
			$per_page,
			( (int) $request['page'] - 1 ) * $per_page,
			(string) $request['date'],
			(string) $request['orderby'],
			(string) $request['order']
		);

		$result['days'] = Log_Repository::scan_days();

		return new WP_REST_Response( $result );
	}

	/**
	 * Run a scan now.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_scan() {
		try {
			return new WP_REST_Response( Scanner::run( 'manual' ), 201 );
		} catch ( \RuntimeException $error ) {
			return new WP_Error( 'redirect_inspector_scan_failed', $error->getMessage(), [ 'status' => 409 === $error->getCode() ? 409 : 500 ] );
		}
	}

	/**
	 * Apply an issue's automatic fix.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function apply_fix( WP_REST_Request $request ) {
		try {
			return new WP_REST_Response( Fixer::apply( (int) $request['id'] ) );
		} catch ( \RuntimeException $error ) {
			return new WP_Error( 'redirect_inspector_fix_failed', $error->getMessage(), [ 'status' => in_array( $error->getCode(), [ 400, 409 ], true ) ? $error->getCode() : 500 ] );
		}
	}

	/**
	 * Migrate leftover rules (dry run by default).
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function migrate( WP_REST_Request $request ) {
		try {
			return new WP_REST_Response( Migrator::run( (string) $request['from'], (string) $request['to'], (bool) $request['dry_run'] ) );
		} catch ( \RuntimeException $error ) {
			return new WP_Error( 'redirect_inspector_migrate_failed', $error->getMessage(), [ 'status' => 400 ] );
		}
	}

	/**
	 * Live check an issue's source, or a list of URLs on this site.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function live_check( WP_REST_Request $request ) {
		$issue_id = (int) $request['issue_id'];
		$issue    = $issue_id ? Log_Repository::issue( $issue_id ) : null;

		if ( $issue_id && ! $issue ) {
			return new WP_Error( 'redirect_inspector_no_issue', __( 'Issue not found.', 'redirect-inspector' ), [ 'status' => 404 ] );
		}

		$urls = $issue ? [ $issue['hops'][0]['from'] ?? $issue['source_url'] ] : array_map( 'strval', (array) $request['urls'] );
		$urls = array_values( array_filter( array_map( 'trim', $urls ) ) );

		if ( ! $urls ) {
			return new WP_Error( 'redirect_inspector_no_urls', __( 'Nothing to check.', 'redirect-inspector' ), [ 'status' => 400 ] );
		}

		try {
			$results = ( new Live_Checker() )->check_many( $urls );
		} catch ( \RuntimeException $error ) {
			return new WP_Error( 'redirect_inspector_busy', $error->getMessage(), [ 'status' => 409 ] );
		}

		if ( $issue ) {
			Log_Repository::save_live_result( $issue_id, $results[0] );
		}

		return new WP_REST_Response( $results );
	}

	/**
	 * Settings.
	 *
	 * @return WP_REST_Response
	 */
	public function settings() {
		return new WP_REST_Response( Settings::all() );
	}

	/**
	 * Save settings.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_settings( WP_REST_Request $request ) {
		$schedule = $request['schedule'];
		if ( is_array( $schedule ) && ! empty( $schedule['enabled'] ) && ( empty( $schedule['days'] ) || empty( $schedule['hours'] ) ) ) {
			return new WP_Error( 'redirect_inspector_schedule', __( 'Pick at least one day and one hour for scheduled scans.', 'redirect-inspector' ), [ 'status' => 400 ] );
		}

		$values = [];
		if ( null !== $request['retention_days'] ) {
			$values['retention_days'] = (int) $request['retention_days'];
		}
		if ( is_array( $schedule ) ) {
			$values['schedule'] = $schedule;
		}

		// Saving triggers Scheduler::reschedule() through the update_option hook.
		$settings = Settings::update( $values );

		return new WP_REST_Response(
			[
				'settings'  => $settings,
				'retention' => self::retention(),
				'next_scan' => self::next_scan(),
			]
		);
	}

	/**
	 * Next scheduled scan as ISO 8601, or null.
	 *
	 * @return string|null
	 */
	private static function next_scan(): ?string {
		$next = wp_next_scheduled( Scheduler::HOOK );

		return $next ? gmdate( 'c', $next ) : null;
	}

	/**
	 * Delete all scan history.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function purge_logs() {
		$error = $this->not_installed();
		if ( $error ) {
			return $error;
		}

		if ( Scanner::is_running() ) {
			return new WP_Error( 'redirect_inspector_busy', __( 'A scan is running. Try again in a moment.', 'redirect-inspector' ), [ 'status' => 409 ] );
		}

		return new WP_REST_Response( [ 'deleted' => Retention::cleanup( 0 ) ] );
	}

	/**
	 * Network overview: latest scan per site, 20 sites per page. Only ids, names, URLs and totals.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function network_sites( WP_REST_Request $request ) {
		$per_page = 20;
		$args     = [
			'number'   => $per_page,
			'offset'   => ( (int) $request['page'] - 1 ) * $per_page,
			'deleted'  => 0,
			'archived' => 0,
			'spam'     => 0,
		];
		if ( '' !== $request['search'] ) {
			$args['search'] = (string) $request['search'];
		}

		$total = (int) get_sites(
			array_merge(
				$args,
				[
					'count'  => true,
					'number' => 0,
					'offset' => 0,
				]
			)
		);
		$items = [];

		foreach ( get_sites( $args ) as $site ) {
			switch_to_blog( (int) $site->blog_id );

			$latest  = Installer::is_installed() ? Log_Repository::latest_scan() : null;
			$items[] = [
				'id'        => (int) $site->blog_id,
				'name'      => (string) get_bloginfo( 'name' ),
				'url'       => home_url( '/' ),
				'dashboard' => admin_url( 'tools.php?page=redirect-inspector' ),
				'active'    => Installer::is_installed(),
				'latest'    => $latest ? [
					'finished_at' => $latest['finished_at'],
					'score'       => $latest['score'],
					'rule_count'  => $latest['rule_count'],
					'issue_count' => $latest['issue_count'],
					'types'       => $latest['totals']['types'] ?? (object) [],
					'status'      => $latest['status'],
				] : null,
			];

			restore_current_blog();
		}

		$response = new WP_REST_Response(
			[
				'items' => $items,
				'total' => $total,
			]
		);
		$response->header( 'X-WP-Total', (string) $total );

		return $response;
	}
}
