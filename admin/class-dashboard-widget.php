<?php
/**
 * Dashboard widget, a compact version of the dashboard banner: the health score, last scan date and issue counts of the latest scan.
 *
 * @package Onehop\RedirectChecker
 */

namespace Onehop\RedirectChecker\Admin;

use Onehop\RedirectChecker\Includes\Caps;
use Onehop\RedirectChecker\Includes\Installer;
use Onehop\RedirectChecker\Includes\Log_Repository;
use Onehop\RedirectChecker\Includes\Singleton;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only summary on each site's Dashboard, for administrators and the roles the active SEO or
 * redirect plugins let manage their settings (Caps::can_view_widget). Rendered in PHP: no app
 * bundle on the Dashboard, and two small queries only when the widget is shown.
 */
final class Dashboard_Widget extends Singleton {

	/**
	 * Widget ID.
	 */
	const ID = 'onehop-redirect-checker';

	/**
	 * Issue groups, as the stat tiles on our screen show them (assets/scripts/lib/meta.ts GROUPS).
	 */
	const GROUPS = [
		'loops'       => [ 'loop' ],
		'chains'      => [ 'chain' ],
		'conflicts'   => [ 'conflict' ],
		'unnecessary' => [ 'noop', 'duplicate', 'redundant', 'shadow' ],
		'broken'      => [ 'broken' ],
	];

	/**
	 * Scans in a sparkline, like the stat tiles on our screen (components/Sparkline.tsx).
	 */
	const TREND = 12;

	/**
	 * Sparkline viewBox, as in components/Sparkline.tsx.
	 */
	const SPARK = [
		'width'  => 120,
		'height' => 36,
		'pad'    => 5,
	];

	/**
	 * Gauge ring radius in its 100×100 viewBox, as in components/Banner.tsx.
	 */
	const RADIUS = 44;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		add_action( 'wp_dashboard_setup', [ $this, 'register' ] );
	}

	/**
	 * Add the widget for who may see it.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! Caps::can_view_widget() ) {
			return;
		}

		wp_add_dashboard_widget( self::ID, esc_html( ONEHOP_REDIRECT_CHECKER_NAME ), [ $this, 'render' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Widget styles (plain CSS, Dashboard only).
	 *
	 * @return void
	 */
	public function enqueue(): void {
		wp_enqueue_style( 'onehop-redirect-checker-widget', ONEHOP_REDIRECT_CHECKER_URL . 'assets/dashboard-widget.css', [], ONEHOP_REDIRECT_CHECKER_VERSION );
	}

	/**
	 * Group labels.
	 *
	 * @return array<string, string>
	 */
	private static function labels(): array {
		return [
			'loops'       => __( 'Loops', 'onehop-redirect-checker' ),
			'chains'      => __( 'Chains', 'onehop-redirect-checker' ),
			'conflicts'   => __( 'Conflicts', 'onehop-redirect-checker' ),
			'unnecessary' => _x( 'Unnecessary', 'issue group', 'onehop-redirect-checker' ),
			'broken'      => _x( 'Broken', 'issue group', 'onehop-redirect-checker' ),
		];
	}

	/**
	 * Issues of one group in a scan's type counts.
	 *
	 * @param string $group  Key of GROUPS.
	 * @param array  $counts Type counts.
	 *
	 * @return int
	 */
	private static function count( string $group, array $counts ): int {
		return (int) array_sum( array_map( static fn( $type ) => (int) ( $counts[ $type ] ?? 0 ), self::GROUPS[ $group ] ) );
	}

	/**
	 * Health band of a score (lib/meta.ts healthBand) and its label.
	 *
	 * @param int $score Score 0–100.
	 *
	 * @return array{0:string, 1:string}
	 */
	private static function band( int $score ): array {
		if ( $score >= 80 ) {
			return [ 'good', _x( 'Healthy', 'health status of the redirects', 'onehop-redirect-checker' ) ];
		}

		return $score >= 50
			? [ 'warning', _x( 'Needs attention', 'health status of the redirects', 'onehop-redirect-checker' ) ]
			: [ 'critical', _x( 'Critical', 'health status of the redirects', 'onehop-redirect-checker' ) ];
	}

	/**
	 * Signed change, or "No change".
	 *
	 * @param int|null $delta            Change, null without a previous scan.
	 * @param bool     $higher_is_better Whether a rise is good (the score) or bad (issue counts).
	 *
	 * @return array{0:string, 1:string} Tone (good|bad|flat) and text.
	 */
	private static function delta( ?int $delta, bool $higher_is_better = false ): array {
		if ( ! $delta ) {
			return [ 'flat', __( 'No change', 'onehop-redirect-checker' ) ];
		}

		$better = $higher_is_better ? $delta > 0 : $delta < 0;

		return [
			$better ? 'good' : 'bad',
			/* translators: %s: signed change, e.g. +2. */
			sprintf( __( '%s since last scan', 'onehop-redirect-checker' ), ( $delta > 0 ? '+' : '−' ) . number_format_i18n( abs( $delta ) ) ),
		];
	}

	/**
	 * Widget body.
	 *
	 * @return void
	 */
	public function render(): void {
		$scans    = Installer::is_installed() ? Log_Repository::latest_scans( self::TREND ) : [];
		$latest   = $scans[0] ?? null;
		$previous = $scans[1] ?? null;
		$link     = Caps::can_manage() ? admin_url( 'tools.php?page=' . Admin_Page::SLUG ) : '';

		echo '<div class="rdi-widget">';

		if ( ! $latest ) {
			echo '<p class="rdi-widget__empty">' . esc_html__( 'No scan yet.', 'onehop-redirect-checker' ) . '</p>';
			$this->footer( $link, __( 'Run the first scan', 'onehop-redirect-checker' ) );
			echo '</div>';
			return;
		}

		$score             = (int) $latest['score'];
		[ $band, $health ] = self::band( $score );
		[ $tone, $change ] = self::delta( $previous ? $score - (int) $previous['score'] : null, true );
		$finished          = $latest['finished_at'] ? (int) strtotime( $latest['finished_at'] ) : 0;
		$current_types     = (array) ( $latest['totals']['types'] ?? [] );
		$previous_types    = $previous ? (array) ( $previous['totals']['types'] ?? [] ) : null;
		$date_format       = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$history           = array_reverse( $scans ); // Oldest first, for the sparklines.
		$circumference     = 2 * M_PI * self::RADIUS;
		?>
		<div class="rdi-widget__head">
			<div class="rdi-widget__gauge is-<?php echo esc_attr( $band ); ?>">
				<svg viewBox="0 0 100 100" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: 1: score 0–100, 2: health status. */ __( 'Health score: %1$d/100, %2$s', 'onehop-redirect-checker' ), $score, $health ) ); ?>">
					<circle cx="50" cy="50" r="<?php echo esc_attr( (string) self::RADIUS ); ?>" class="rdi-widget__gauge-track"/>
					<circle cx="50" cy="50" r="<?php echo esc_attr( (string) self::RADIUS ); ?>" class="rdi-widget__gauge-fill" stroke-dasharray="<?php echo esc_attr( sprintf( '%.2f', $circumference ) ); ?>" stroke-dashoffset="<?php echo esc_attr( sprintf( '%.2f', $circumference * ( 1 - $score / 100 ) ) ); ?>" transform="rotate(-90 50 50)"/>
				</svg>
				<span class="rdi-widget__gauge-text" aria-hidden="true">
					<span class="rdi-widget__value"><?php echo esc_html( number_format_i18n( $score ) ); ?></span>
					<span class="rdi-widget__label"><?php echo esc_html( $health ); ?></span>
				</span>
			</div>
			<div class="rdi-widget__meta">
				<p class="rdi-widget__delta is-<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( $change ); ?></p>
				<p>
					<?php esc_html_e( 'Last scan', 'onehop-redirect-checker' ); ?><br>
					<?php if ( $finished ) : ?>
						<time datetime="<?php echo esc_attr( $latest['finished_at'] ); ?>"><?php echo esc_html( wp_date( $date_format, $finished ) ); ?></time>
						<span class="rdi-widget__ago">
							<?php
							/* translators: %s: human time difference, e.g. 2 hours. */
							echo esc_html( sprintf( __( '%s ago', 'onehop-redirect-checker' ), human_time_diff( $finished ) ) );
							?>
						</span>
					<?php endif; ?>
				</p>
			</div>
			<img class="rdi-widget__wapuu" src="<?php echo esc_url( ONEHOP_REDIRECT_CHECKER_URL . 'assets/images/wapuu.svg' ); ?>" alt="" width="64" height="70" decoding="async">
		</div>
		<?php

		// Only the groups with issues now, or that had some last time (so a drop to 0 still shows).
		$tiles = [];
		foreach ( self::labels() as $group => $label ) {
			$value = self::count( $group, $current_types );
			$delta = null === $previous_types ? null : $value - self::count( $group, $previous_types );
			if ( $value || $delta ) {
				$tiles[ $group ] = [ $label, $value, $delta ];
			}
		}

		if ( $tiles ) {
			echo '<ul class="rdi-widget__tiles">';
			foreach ( $tiles as $group => [ $label, $value, $delta ] ) {
				[ $tone, $change ] = self::delta( $delta );
				// The whole tile opens that group's tab in the issue list, for who may open our screen.
				$tag = $link ? 'a' : 'div';
				printf(
					'<li><%7$s class="rdi-widget__tile rdi-widget__tile--%1$s"%8$s><span class="rdi-widget__tile-label">%2$s</span><span class="rdi-widget__tile-value">%3$s</span>%6$s<span class="rdi-widget__delta is-%4$s">%5$s</span></%7$s></li>',
					esc_attr( $group ),
					esc_html( $label ),
					esc_html( number_format_i18n( $value ) ),
					esc_attr( $tone ),
					esc_html( $change ),
					self::sparkline( $group, $history, $date_format ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
					tag_escape( $tag ),
					$link ? ' href="' . esc_url( $link . '#issues-' . $group ) . '"' : ''
				);
			}
			echo '</ul>';
		} else {
			echo '<p class="rdi-widget__empty">' . esc_html__( 'No issues found in the last scan.', 'onehop-redirect-checker' ) . '</p>';
		}

		$this->footer( $link, __( 'View all issues', 'onehop-redirect-checker' ) );
		echo '</div>';
	}

	/**
	 * Trend of one group over the last scans: a quiet line with the latest point marked. Each point
	 * names its count and date on hover (an SVG title); screen readers get the delta text instead.
	 *
	 * @param string $group       Key of GROUPS.
	 * @param array  $history     Scans, oldest first.
	 * @param string $date_format Date format for the point titles.
	 *
	 * @return string SVG markup, or an empty placeholder with fewer than two scans.
	 */
	private static function sparkline( string $group, array $history, string $date_format ): string {
		if ( count( $history ) < 2 ) {
			return '<span class="rdi-widget__spark" aria-hidden="true"></span>';
		}

		[ 'width' => $width, 'height' => $height, 'pad' => $pad ] = self::SPARK;

		$values = array_map( static fn( $scan ) => self::count( $group, (array) ( $scan['totals']['types'] ?? [] ) ), $history );
		$max    = max( 1, ...$values );
		$step   = ( $width - $pad * 2 ) / ( count( $values ) - 1 );
		$coords = [];
		foreach ( $values as $index => $value ) {
			$coords[] = [ $pad + $index * $step, $height - $pad - ( $value / $max ) * ( $height - $pad * 2 ) ];
		}

		$path   = '';
		$points = '';
		foreach ( $coords as $index => [ $x, $y ] ) {
			$path    .= sprintf( '%s%.1f,%.1f ', $index ? 'L' : 'M', $x, $y );
			$finished = $history[ $index ]['finished_at'] ? (int) strtotime( $history[ $index ]['finished_at'] ) : 0;
			$points  .= sprintf(
				'<circle cx="%1$.1f" cy="%2$.1f" r="%3$.1f" class="rdi-widget__spark-hit"><title>%4$s</title></circle>',
				$x,
				$y,
				$step / 2,
				esc_html( number_format_i18n( $values[ $index ] ) . ( $finished ? ' · ' . wp_date( $date_format, $finished ) : '' ) )
			);
		}

		[ $last_x, $last_y ] = end( $coords );

		return sprintf(
			'<span class="rdi-widget__spark" aria-hidden="true"><svg viewBox="0 0 %1$d %2$d" focusable="false"><path d="%3$s" class="rdi-widget__spark-line"/><circle cx="%4$.1f" cy="%5$.1f" r="4" class="rdi-widget__spark-dot"/>%6$s</svg></span>',
			$width,
			$height,
			esc_attr( trim( $path ) ),
			$last_x,
			$last_y,
			$points
		);
	}

	/**
	 * Link to our screen, for who may open it.
	 *
	 * @param string $link URL, '' to leave out.
	 * @param string $text Link text.
	 *
	 * @return void
	 */
	private function footer( string $link, string $text ): void {
		if ( $link ) {
			printf( '<p class="rdi-widget__footer"><a class="rdi-widget__button" href="%1$s">%2$s<span aria-hidden="true">%3$s</span></a></p>', esc_url( $link ), esc_html( $text ), is_rtl() ? '←' : '→' );
		}
	}
}
