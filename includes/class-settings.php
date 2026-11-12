<?php
/**
 * Per-site settings.
 *
 * @package Acato\RedirectInspector
 */

namespace Acato\RedirectInspector\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * Small, non-autoloaded settings array.
 */
final class Settings {

	/**
	 * Option name.
	 */
	const OPTION = 'redirect_inspector_settings';

	/**
	 * Retention as agreed: 90 days (3 months).
	 */
	const DEFAULT_RETENTION_DAYS = 90;

	/**
	 * Lower bound for retention.
	 */
	const MIN_RETENTION_DAYS = 7;

	/**
	 * Upper bound for retention.
	 */
	const MAX_RETENTION_DAYS = 365;

	/**
	 * Default scan schedule: off; when switched on, weekdays at 03:00.
	 */
	const DEFAULT_SCHEDULE = [
		'enabled' => false,
		'days'    => [ 1, 2, 3, 4, 5 ],
		'hours'   => [ 3 ],
	];

	/**
	 * All settings with defaults.
	 *
	 * @return array{retention_days:int, schedule:array{enabled:bool, days:int[], hours:int[]}}
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		return [
			'retention_days' => self::clamp_retention( $stored['retention_days'] ?? self::DEFAULT_RETENTION_DAYS ),
			'schedule'       => self::sanitize_schedule( $stored['schedule'] ?? self::DEFAULT_SCHEDULE ),
		];
	}

	/**
	 * The scan schedule.
	 *
	 * @return array{enabled:bool, days:int[], hours:int[]}
	 */
	public static function schedule(): array {
		return self::all()['schedule'];
	}

	/**
	 * Normalise a schedule: weekdays 0 (Sunday)–6, hours 0–23, unique and sorted. A schedule
	 * without days or hours can't be on.
	 *
	 * @param mixed $raw Raw value.
	 *
	 * @return array{enabled:bool, days:int[], hours:int[]}
	 */
	public static function sanitize_schedule( $raw ): array {
		$raw   = is_array( $raw ) ? $raw : [];
		$pick  = static function ( $values, int $max ): array {
			$values = array_unique( array_filter( array_map( 'intval', (array) $values ), static fn( $value ) => $value >= 0 && $value <= $max ) );
			sort( $values );
			return $values;
		};
		$days  = $pick( $raw['days'] ?? [], 6 );
		$hours = $pick( $raw['hours'] ?? [], 23 );

		return [
			'enabled' => ! empty( $raw['enabled'] ) && $days && $hours,
			'days'    => $days,
			'hours'   => $hours,
		];
	}

	/**
	 * Retention in days.
	 *
	 * @return int
	 */
	public static function retention_days(): int {
		return self::all()['retention_days'];
	}

	/**
	 * Save settings.
	 *
	 * @param array $values New values.
	 *
	 * @return array{retention_days:int, schedule:array} Saved settings.
	 */
	public static function update( array $values ): array {
		$settings = self::all();

		if ( isset( $values['retention_days'] ) ) {
			$settings['retention_days'] = self::clamp_retention( $values['retention_days'] );
		}

		if ( isset( $values['schedule'] ) ) {
			$settings['schedule'] = self::sanitize_schedule( $values['schedule'] );
		}

		update_option( self::OPTION, $settings, false );

		return $settings;
	}

	/**
	 * Clamp a retention value.
	 *
	 * @param mixed $days Raw value.
	 *
	 * @return int
	 */
	public static function clamp_retention( $days ): int {
		return max( self::MIN_RETENTION_DAYS, min( self::MAX_RETENTION_DAYS, absint( $days ) ) );
	}
}
