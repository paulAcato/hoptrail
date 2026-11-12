<?php
/**
 * Abilities for the WordPress Abilities API (WordPress 7.1+).
 *
 * @package Acato\RedirectInspector
 */

declare( strict_types=1 );

namespace Acato\RedirectInspector\Includes;

use Acato\RedirectInspector\Includes\Analysis\Analyzer;
use Acato\RedirectInspector\Includes\Sources\Registry;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every ability of this plugin: one register_*() method per ability; the shared steps
 * (category, capability check, schemas, annotations, personal-data guard) live in helpers.
 *
 * Decided with the user: capability `manage_options` (same as the dashboard), per site on
 * multisite; `public` (discoverable by AI clients via the MCP adapter); not on the REST API.
 */
final class Abilities extends Singleton {

	/**
	 * Ability namespace (`redirect-inspector/{ability}`) and category slug. Equal to the plugin slug.
	 */
	private const ABILITY_NAMESPACE = 'redirect-inspector';
	private const CATEGORY          = 'redirect-inspector';

	/**
	 * Capability for every ability (as agreed).
	 */
	private const CAPABILITY = Caps::MANAGE;

	/**
	 * Most issues an ability returns.
	 */
	private const MAX_ISSUES = 50;

	/**
	 * Keys that hold personal data (GDPR / AVG). Input or output of a guarded ability containing one
	 * of these keys — or an e-mail address anywhere — is refused before it reaches an AI client.
	 */
	private const PERSONAL_DATA_KEYS = [
		'email',
		'user_email',
		'author_email',
		'billing_email',
		'user_login',
		'login',
		'username',
		'display_name',
		'nickname',
		'first_name',
		'last_name',
		'full_name',
		'phone',
		'telephone',
		'mobile',
		'address',
		'street',
		'postcode',
		'zipcode',
		'ip',
		'ip_address',
		'author_ip',
		'birthdate',
		'date_of_birth',
		'bsn',
		'iban',
		'user',
	];

	/**
	 * Abilities allowed to handle personal data anyway (none: these abilities never return any).
	 *
	 * @var string[]
	 */
	private static array $personal_data_approved = [];

	/**
	 * Core abilities the personal-data guard also covers.
	 *
	 * @var string[]
	 */
	private static array $guarded_core_abilities = [];

	/**
	 * Hooks registration and the personal-data guard. Requires WordPress 7.1.
	 */
	protected function init(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! is_wp_version_compatible( '7.1' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
		add_filter( 'wp_ability_validate_input', [ $this, 'guard_personal_data' ], 10, 3 );
		add_filter( 'wp_ability_validate_output', [ $this, 'guard_personal_data' ], 10, 3 );
	}

	/**
	 * Registers the plugin's ability category.
	 */
	public function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => __( 'Redirect Inspector', 'redirect-inspector' ),
				'description' => __( 'Find redirect loops, chains and redirects you don\'t need across the redirect plugins of this site.', 'redirect-inspector' ),
			]
		);
	}

	/**
	 * Registers every ability. One line per ability.
	 */
	public function register_abilities(): void {
		$this->register_run_scan();
		$this->register_get_scan_results();
	}

	/*
	 * ------------------------------------------------------------------
	 * Abilities: one register_*() method and one callback per ability.
	 * ------------------------------------------------------------------
	 */

	/**
	 * Ability: redirect-inspector/run-scan.
	 */
	private function register_run_scan(): void {
		$this->register(
			'run-scan',
			[
				'label'            => __( 'Run a redirect scan', 'redirect-inspector' ),
				'description'      => __( 'Reads every redirect rule of the active redirect plugins, follows each one to where it ends, and stores the result. Returns the health score (0–100), counts per issue type and severity, and the most severe issues with their redirect path and a suggested fix. Never returns who ran a scan or any other user data, and never changes a redirect rule.', 'redirect-inspector' ),
				'capabilities'     => [ self::CAPABILITY ],
				'input_schema'     => $this->input_schema(
					[
						'issue_limit' => $this->issue_limit_schema(),
					]
				),
				'output_schema'    => $this->results_schema(),
				'execute_callback' => [ $this, 'run_scan' ],
				// Adds a scan to the history; changes no redirect.
				'annotations'      => $this->writes( false, false ),
				'public'           => true,
			]
		);
	}

	/**
	 * Runs a scan and returns its results.
	 *
	 * @param array{issue_limit?: int} $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function run_scan( array $input = [] ): array|WP_Error {
		try {
			$scan = Scanner::run( 'ability' );
		} catch ( \RuntimeException $error ) {
			return new WP_Error( 'redirect_inspector_scan_failed', $error->getMessage(), [ 'status' => 409 === $error->getCode() ? 409 : 500 ] );
		}

		return $this->results( $scan, [], (int) ( $input['issue_limit'] ?? 10 ) );
	}

	/**
	 * Ability: redirect-inspector/get-scan-results.
	 */
	private function register_get_scan_results(): void {
		$this->register(
			'get-scan-results',
			[
				'label'            => __( 'Get redirect scan results', 'redirect-inspector' ),
				'description'      => __( 'Returns the results of the latest redirect scan without running a new one: when it ran, the health score (0–100), counts per issue type and severity, and the most severe issues (optionally of the given types) with their redirect path and a suggested fix. Never returns who ran a scan or any other user data.', 'redirect-inspector' ),
				'capabilities'     => [ self::CAPABILITY ],
				'input_schema'     => $this->input_schema(
					[
						'types'       => $this->list_schema(
							$this->string_schema( __( 'Issue Type', 'redirect-inspector' ), __( 'Issue type.', 'redirect-inspector' ), [ 'enum' => Analyzer::TYPES ] )
						) + [
							'title'       => __( 'Issue Types', 'redirect-inspector' ),
							'description' => __( 'Only issues of these types; all types when empty.', 'redirect-inspector' ),
							'uniqueItems' => true,
							'maxItems'    => count( Analyzer::TYPES ),
						],
						'issue_limit' => $this->issue_limit_schema(),
					]
				),
				'output_schema'    => $this->results_schema(),
				'execute_callback' => [ $this, 'get_scan_results' ],
				'annotations'      => $this->read_only(),
				'public'           => true,
			]
		);
	}

	/**
	 * Returns the latest scan's results.
	 *
	 * @param array{types?: string[], issue_limit?: int} $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_scan_results( array $input = [] ): array|WP_Error {
		$scan = Installer::is_installed() ? Log_Repository::latest_scan() : null;

		if ( ! $scan ) {
			return new WP_Error( 'redirect_inspector_no_scan', __( 'There is no scan yet. Run a scan first.', 'redirect-inspector' ), [ 'status' => 404 ] );
		}

		return $this->results( $scan, array_map( 'strval', (array) ( $input['types'] ?? [] ) ), (int) ( $input['issue_limit'] ?? 10 ) );
	}

	/*
	 * ------------------------------------------------------------------
	 * Shared steps. Extend these instead of repeating them per ability.
	 * ------------------------------------------------------------------
	 */

	/**
	 * A scan as ability output: only the fields an AI client needs, no user data.
	 *
	 * @param array<string, mixed> $scan  Formatted scan (Log_Repository::format_scan()).
	 * @param string[]             $types Issue types to include ([] = all).
	 * @param int                  $limit Most issues to include.
	 * @return array<string, mixed>
	 */
	private function results( array $scan, array $types, int $limit ): array {
		$labels = array_map( static fn( $source ): string => $source->label(), Registry::all() );
		$issues = Log_Repository::issues( (int) $scan['id'], $types, '', '', 1, max( 0, min( self::MAX_ISSUES, $limit ) ) ?: 1 );
		$totals = (array) $scan['totals'];

		return [
			'scanned_at'   => (string) ( $scan['finished_at'] ?? $scan['started_at'] ),
			'status'       => (string) $scan['status'],
			'score'        => (int) $scan['score'],
			'rule_count'   => (int) $scan['rule_count'],
			'issue_count'  => (int) $scan['issue_count'],
			'types'        => array_map( 'intval', (array) ( $totals['types'] ?? [] ) ) ?: (object) [],
			'severities'   => array_map( 'intval', (array) ( $totals['severities'] ?? [] ) ) ?: (object) [],
			'issues_shown' => 0 === $limit ? 0 : count( $issues['items'] ),
			'issues_total' => (int) $issues['total'],
			'issues'       => 0 === $limit ? [] : array_map(
				static fn( array $issue ): array => [
					'type'       => $issue['type'],
					'severity'   => $issue['severity'],
					'source'     => wp_strip_all_tags( $issue['source_url'] ),
					'target'     => wp_strip_all_tags( $issue['target_url'] ),
					'path'       => implode(
						' → ',
						array_merge(
							[ wp_strip_all_tags( (string) ( $issue['hops'][0]['from'] ?? $issue['source_url'] ) ) ],
							array_map( static fn( array $hop ): string => sprintf( '(%d, %s) %s', (int) $hop['code'], $labels[ $hop['rule']['source'] ] ?? $hop['rule']['source'], wp_strip_all_tags( (string) $hop['to'] ) ), $issue['hops'] )
						)
					),
					'plugins'    => array_values( array_map( static fn( string $slug ): string => $labels[ $slug ] ?? $slug, $issue['plugins'] ) ),
					'suggestion' => wp_strip_all_tags( $issue['suggestion'] ),
					'fixable'    => ! empty( $issue['fix'] ) && empty( $issue['fixed'] ),
				],
				$issues['items']
			),
		];
	}

	/**
	 * Output schema shared by both abilities.
	 *
	 * @return array<string, mixed>
	 */
	private function results_schema(): array {
		$count = fn( string $title, string $description ): array => $this->integer_schema( $title, $description, 0 );
		$map   = fn( string $title, string $description ): array => [
			'type'                 => 'object',
			'title'                => $title,
			'description'          => $description,
			'additionalProperties' => [ 'type' => 'integer' ],
		];

		return $this->object_schema(
			[
				'scanned_at'   => $this->string_schema( __( 'Scanned At', 'redirect-inspector' ), __( 'When the scan finished (ISO 8601, UTC).', 'redirect-inspector' ) ),
				'status'       => $this->string_schema( __( 'Status', 'redirect-inspector' ), __( 'done, or partial when a time budget was reached.', 'redirect-inspector' ), [ 'enum' => [ 'done', 'partial' ] ] ),
				'score'        => $this->integer_schema( __( 'Health Score', 'redirect-inspector' ), __( '0–100; below 50 is critical, 80 and up is healthy.', 'redirect-inspector' ), 0, 100 ),
				'rule_count'   => $count( __( 'Rule Count', 'redirect-inspector' ), __( 'Redirect rules read from all active plugins.', 'redirect-inspector' ) ),
				'issue_count'  => $count( __( 'Issue Count', 'redirect-inspector' ), __( 'Issues found.', 'redirect-inspector' ) ),
				'types'        => $map( __( 'Issues Per Type', 'redirect-inspector' ), __( 'loop, chain, conflict, noop, duplicate, redundant, shadow, broken.', 'redirect-inspector' ) ),
				'severities'   => $map( __( 'Issues Per Severity', 'redirect-inspector' ), __( 'critical, high, medium, low.', 'redirect-inspector' ) ),
				'issues_shown' => $count( __( 'Issues Shown', 'redirect-inspector' ), __( 'Issues in this response, most severe first.', 'redirect-inspector' ) ),
				'issues_total' => $count( __( 'Issues Total', 'redirect-inspector' ), __( 'Issues matching the filter.', 'redirect-inspector' ) ),
				'issues'       => $this->list_schema(
					$this->object_schema(
						[
							'type'       => $this->string_schema( __( 'Type', 'redirect-inspector' ), __( 'Issue type.', 'redirect-inspector' ), [ 'enum' => Analyzer::TYPES ] ),
							'severity'   => $this->string_schema( __( 'Severity', 'redirect-inspector' ), __( 'critical, high, medium or low.', 'redirect-inspector' ) ),
							'source'     => $this->string_schema( __( 'Source', 'redirect-inspector' ), __( 'URL or pattern the issue starts at.', 'redirect-inspector' ) ),
							'target'     => $this->string_schema( __( 'Target', 'redirect-inspector' ), __( 'Where the path ends.', 'redirect-inspector' ) ),
							'path'       => $this->string_schema( __( 'Path', 'redirect-inspector' ), __( 'Every hop: URL → (status code, plugin) URL → …', 'redirect-inspector' ) ),
							'plugins'    => $this->list_schema( $this->string_schema( __( 'Plugin', 'redirect-inspector' ), __( 'Redirect plugin involved.', 'redirect-inspector' ) ) ),
							'suggestion' => $this->string_schema( __( 'Suggestion', 'redirect-inspector' ), __( 'What to change, in plain text.', 'redirect-inspector' ) ),
							'fixable'    => [
								'type'        => 'boolean',
								'title'       => __( 'Fixable', 'redirect-inspector' ),
								'description' => __( 'Whether Apply fix is available on the dashboard (Tools → Redirect Inspector).', 'redirect-inspector' ),
							],
						]
					)
				),
			]
		);
	}

	/**
	 * Input property: how many issues to return.
	 *
	 * @return array<string, mixed>
	 */
	private function issue_limit_schema(): array {
		return $this->integer_schema( __( 'Issue Limit', 'redirect-inspector' ), __( 'Most issues to return, most severe first (0 for counts only). Default 10.', 'redirect-inspector' ), 0, self::MAX_ISSUES );
	}

	/**
	 * Registers one ability with the shared defaults: namespace, category, capability check, an
	 * empty-object input schema, annotations and visibility (not public and not in REST unless
	 * asked for).
	 *
	 * @param string               $slug Ability slug (kebab-case, verb first); prefixed with the namespace.
	 * @param array<string, mixed> $args Ability arguments.
	 */
	private function register( string $slug, array $args ): void {
		$capabilities = $args['capabilities'];

		wp_register_ability(
			self::ABILITY_NAMESPACE . '/' . $slug,
			[
				'label'               => $args['label'],
				'description'         => $args['description'],
				'category'            => self::CATEGORY,
				'input_schema'        => $args['input_schema'] ?? $this->input_schema( [] ),
				'output_schema'       => $args['output_schema'],
				'execute_callback'    => $args['execute_callback'],
				'permission_callback' => fn(): bool => $this->current_user_has( $capabilities ),
				'meta'                => [
					'annotations'  => $args['annotations'],
					'public'       => $args['public'] ?? false,
					// Explicit, so a `public` ability is not on REST by inheritance. Only true when asked.
					'show_in_rest' => $args['show_in_rest'] ?? false,
				],
			]
		);
	}

	/**
	 * Whether the current, logged-in user has every given capability.
	 *
	 * @param string[] $capabilities Capabilities, all required.
	 */
	private function current_user_has( array $capabilities ): bool {
		if ( ! is_user_logged_in() || [] === $capabilities ) {
			return false;
		}

		foreach ( $capabilities as $capability ) {
			if ( ! current_user_can( $capability ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Strict input schema: an object, no unknown keys, empty object when no input is given.
	 *
	 * @param array<string, array<string, mixed>> $properties Property schemas.
	 * @param string[]                            $required   Required property names.
	 * @return array<string, mixed>
	 */
	private function input_schema( array $properties, array $required = [] ): array {
		return $this->object_schema( $properties, $required ) + [ 'default' => [] ];
	}

	/**
	 * Object schema without unknown keys. Leaves out empty `properties`/`required`, which would
	 * encode as JSON arrays.
	 *
	 * @param array<string, array<string, mixed>> $properties Property schemas.
	 * @param string[]                            $required   Required property names.
	 * @return array<string, mixed>
	 */
	private function object_schema( array $properties, array $required = [] ): array {
		return array_filter(
			[
				'type'                 => 'object',
				'properties'           => $properties,
				'required'             => $required,
				'additionalProperties' => false,
			],
			fn( $value ): bool => [] !== $value
		);
	}

	/**
	 * List (JSON array) schema.
	 *
	 * @param array<string, mixed> $items Item schema.
	 * @return array<string, mixed>
	 */
	private function list_schema( array $items ): array {
		return [
			'type'  => 'array',
			'items' => $items,
		];
	}

	/**
	 * String schema with title and description; pass `enum`, `maxLength`, `format` via $extra.
	 *
	 * @param string               $title       Title Case, translated.
	 * @param string               $description Translated description.
	 * @param array<string, mixed> $extra       Extra keywords.
	 * @return array<string, mixed>
	 */
	private function string_schema( string $title, string $description, array $extra = [] ): array {
		return [
			'type'        => 'string',
			'title'       => $title,
			'description' => $description,
		] + $extra;
	}

	/**
	 * Bounded integer schema with title and description.
	 *
	 * @param string   $title       Title Case, translated.
	 * @param string   $description Translated description.
	 * @param int      $minimum     Minimum value.
	 * @param int|null $maximum     Maximum value; always set one for input.
	 * @return array<string, mixed>
	 */
	private function integer_schema( string $title, string $description, int $minimum, ?int $maximum = null ): array {
		return array_filter(
			[
				'type'        => 'integer',
				'title'       => $title,
				'description' => $description,
				'minimum'     => $minimum,
				'maximum'     => $maximum,
			],
			fn( $value ): bool => null !== $value
		);
	}

	/**
	 * Annotations for an ability that changes nothing (REST: GET).
	 *
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	private function read_only(): array {
		return [
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		];
	}

	/**
	 * Annotations for an ability that changes data (REST: DELETE when destructive and idempotent,
	 * otherwise POST).
	 *
	 * @param bool $destructive Deletes or overwrites data.
	 * @param bool $idempotent  Repeating the same call has no further effect.
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	private function writes( bool $destructive, bool $idempotent ): array {
		return [
			'readonly'    => false,
			'destructive' => $destructive,
			'idempotent'  => $idempotent,
		];
	}

	/**
	 * Refuses input or output carrying personal data, so it never reaches an AI client, and tells
	 * the person asking why. Hooked to wp_ability_validate_input and wp_ability_validate_output.
	 *
	 * @param bool|WP_Error $is_valid     Result of the schema validation so far.
	 * @param mixed         $value        Ability input or output.
	 * @param string        $ability_name Ability name.
	 * @return bool|WP_Error
	 */
	public function guard_personal_data( bool|WP_Error $is_valid, mixed $value, string $ability_name ): bool|WP_Error {
		if ( true !== $is_valid || ! $this->is_guarded( $ability_name ) || ! $this->contains_personal_data( $value ) ) {
			return $is_valid;
		}

		return new WP_Error(
			'redirect_inspector_personal_data',
			__( 'This request involves personal data, such as names or e-mail addresses. Sending personal data to an AI service can breach the GDPR, so it is not shared. Please do not ask for personal data through AI; look it up in the WordPress admin instead.', 'redirect-inspector' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Whether the guard covers this ability: ours (unless approved) and the chosen core abilities.
	 *
	 * @param string $ability_name Ability name.
	 */
	private function is_guarded( string $ability_name ): bool {
		if ( in_array( $ability_name, self::$personal_data_approved, true ) ) {
			return false;
		}

		return str_starts_with( $ability_name, self::ABILITY_NAMESPACE . '/' )
			|| in_array( $ability_name, self::$guarded_core_abilities, true );
	}

	/**
	 * Whether a value holds a personal-data key, a requested personal-data field (a list item such
	 * as `fields: [ "email" ]`) or an e-mail address, at any depth.
	 *
	 * @param mixed $value Value to inspect.
	 */
	private function contains_personal_data( mixed $value ): bool {
		if ( is_string( $value ) ) {
			return (bool) preg_match( '/[^\s@<>]+@[^\s@<>]+\.[a-z]{2,}/i', $value );
		}

		if ( ! is_array( $value ) && ! is_object( $value ) ) {
			return false;
		}

		foreach ( (array) $value as $key => $item ) {
			$name = is_string( $key ) ? $key : ( is_string( $item ) ? $item : '' );

			if ( in_array( strtolower( $name ), self::PERSONAL_DATA_KEYS, true ) || $this->contains_personal_data( $item ) ) {
				return true;
			}
		}

		return false;
	}
}
