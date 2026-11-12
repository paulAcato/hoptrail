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
	 * Retention by age ('days') or by number of scans ('count'), as the site admin chooses.
	 */
	const RETENTION_MODES = [ 'days', 'count' ];

	const DEFAULT_RETENTION_COUNT = 100;
	const MIN_RETENTION_COUNT     = 5;
	const MAX_RETENTION_COUNT     = 1000;

	/**
	 * Default scan schedule: off; when switched on, weekdays at 03:00.
	 */
	const DEFAULT_SCHEDULE = [
		'enabled'   => false,
		'frequency' => 'weekly',
		'days'      => [ 1, 2, 3, 4, 5 ],
		'month_day' => 1,
		'hours'     => [ 3 ],
	];

	/**
	 * Schedule frequencies: every day, chosen weekdays, or one day of the month.
	 */
	const FREQUENCIES = [ 'daily', 'weekly', 'monthly' ];

	/**
	 * All settings with defaults.
	 *
	 * @return array{retention_mode:string, retention_days:int, retention_count:int, schedule:array{enabled:bool, frequency:string, days:int[], month_day:int, hours:int[]}}
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		return [
			'retention_mode'  => in_array( $stored['retention_mode'] ?? '', self::RETENTION_MODES, true ) ? $stored['retention_mode'] : 'days',
			'retention_days'  => self::clamp_retention( $stored['retention_days'] ?? self::DEFAULT_RETENTION_DAYS ),
			'retention_count' => self::clamp_count( $stored['retention_count'] ?? self::DEFAULT_RETENTION_COUNT ),
			'schedule'        => self::sanitize_schedule( $stored['schedule'] ?? self::DEFAULT_SCHEDULE ),
		];
	}

	/**
	 * The scan schedule.
	 *
	 * @return array{enabled:bool, frequency:string, days:int[], month_day:int, hours:int[]}
	 */
	public static function schedule(): array {
		return self::all()['schedule'];
	}

	/**
	 * Normalise a schedule: frequency daily|weekly|monthly; weekdays 0 (Sunday)–6 (weekly);
	 * month_day 1–28, or 0 for the last day of the month (monthly); hours 0–23. A schedule without
	 * hours, or a weekly one without days, can't be on.
	 *
	 * @param mixed $raw Raw value.
	 *
	 * @return array{enabled:bool, frequency:string, days:int[], month_day:int, hours:int[]}
	 */
	public static function sanitize_schedule( $raw ): array {
		$raw       = is_array( $raw ) ? $raw : [];
		$pick      = static function ( $values, int $max ): array {
			$values = array_unique( array_filter( array_map( 'intval', (array) $values ), static fn( $value ) => $value >= 0 && $value <= $max ) );
			sort( $values );
			return $values;
		};
		$days      = $pick( $raw['days'] ?? [], 6 );
		$hours     = $pick( $raw['hours'] ?? [], 23 );
		$frequency = in_array( $raw['frequency'] ?? '', self::FREQUENCIES, true ) ? $raw['frequency'] : 'weekly';
		$month_day = max( 0, min( 28, (int) ( $raw['month_day'] ?? 1 ) ) );

		return [
			'enabled'   => ! empty( $raw['enabled'] ) && $hours && ( 'weekly' !== $frequency || $days ),
			'frequency' => $frequency,
			'days'      => $days,
			'month_day' => $month_day,
			'hours'     => $hours,
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

		if ( isset( $values['retention_mode'] ) && in_array( $values['retention_mode'], self::RETENTION_MODES, true ) ) {
			$settings['retention_mode'] = $values['retention_mode'];
		}

		if ( isset( $values['retention_count'] ) ) {
			$settings['retention_count'] = self::clamp_count( $values['retention_count'] );
		}

		if ( isset( $values['schedule'] ) ) {
			$settings['schedule'] = self::sanitize_schedule( $values['schedule'] );
		}

		update_option( self::OPTION, $settings, false );

		return $settings;
	}

	/**
	 * Clamp a scan count.
	 *
	 * @param mixed $count Raw value.
	 *
	 * @return int
	 */
	public static function clamp_count( $count ): int {
		return max( self::MIN_RETENTION_COUNT, min( self::MAX_RETENTION_COUNT, absint( $count ) ) );
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
