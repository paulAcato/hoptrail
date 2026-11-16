<?php
/**
 * Asks for a rating once the plugin has had time to prove itself.
 *
 * @package Onehop\RedirectChecker
 */

namespace Onehop\RedirectChecker\Admin;

use Onehop\RedirectChecker\Includes\Caps;
use Onehop\RedirectChecker\Includes\Singleton;

defined( 'ABSPATH' ) || exit;

/**
 * A notice a month after activation (or after the update that added it), asking for a rating.
 *
 * A month is long enough for some scans to have run, so the question comes from somebody who has
 * seen what the plugin does. It only appears on the Dashboard and our own screen, never on screens
 * people come to do other work on, and only to administrators. The answer is kept per user, so one
 * administrator saying no does not speak for another; on multisite it holds on every site.
 */
final class Rating_Notice extends Singleton {

	/**
	 * Network-wide option: when the plugin was first activated (or updated to 1.1.0).
	 */
	const INSTALLED_OPTION = 'onehop_redirect_checker_installed';

	/**
	 * User meta: the answer, `done` or when to ask again.
	 */
	const USER_META = 'onehop_redirect_checker_rating_notice';

	/**
	 * The review form itself, rather than the plugin page a click away from it.
	 */
	const RATE_URL = 'https://wordpress.org/support/plugin/onehop-redirect-checker/reviews/#new-post';

	/**
	 * Action the notice's links post to.
	 */
	const ACTION = 'onehop_redirect_checker_rating';

	/**
	 * How long after activation, and after "Maybe later", before asking.
	 */
	const WAIT = MONTH_IN_SECONDS;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		// Installs from before 1.1.0 never ran the activation hook that notes the time: start their month now.
		add_action( 'admin_init', [ self::class, 'remember_install' ] );
		add_action( 'admin_notices', [ $this, 'render' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
	}

	/**
	 * Note when the plugin was activated, unless that is already known.
	 *
	 * @return void
	 */
	public static function remember_install(): void {
		if ( false === get_site_option( self::INSTALLED_OPTION ) ) {
			update_site_option( self::INSTALLED_OPTION, time() );
		}
	}

	/**
	 * Show the notice, when this is the place and the time for it.
	 *
	 * @return void
	 */
	public function render(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! in_array( $screen->id, [ 'dashboard', 'tools_page_' . Admin_Page::SLUG ], true ) || ! self::is_due() ) {
			return;
		}

		$link = static fn( string $choice ): string => wp_nonce_url(
			add_query_arg(
				[
					'action' => self::ACTION,
					'choice' => $choice,
				],
				admin_url( 'admin-post.php' )
			),
			self::ACTION
		);

		// After "Maybe later" it has been a while longer than a month, so the second ask does not repeat the first.
		$text = is_numeric( get_user_meta( get_current_user_id(), self::USER_META, true ) )
			/* translators: %s: plugin name. */
			? _x( '%s has been checking your redirects for a while now. If it does the job, would you rate it on WordPress.org? A rating helps other sites find it, and takes a minute.', 'rating notice', 'onehop-redirect-checker' )
			/* translators: %s: plugin name. */
			: _x( 'You have been using %s for a month now. If it keeps your redirects in one hop, would you rate it on WordPress.org? A rating helps other sites find it, and takes a minute.', 'rating notice', 'onehop-redirect-checker' );
		?>
		<div class="notice notice-info rdi-rating">
			<p><?php echo esc_html( sprintf( $text, ONEHOP_REDIRECT_CHECKER_NAME ) ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $link( 'rate' ) ); ?>" target="_blank" rel="noopener">
					<?php
					/* translators: %s: plugin name. */
					echo esc_html( sprintf( _x( 'Rate %s', 'rating notice', 'onehop-redirect-checker' ), ONEHOP_REDIRECT_CHECKER_NAME ) );
					?>
					<span class="screen-reader-text"><?php echo esc_html_x( '(opens in a new tab)', 'accessibility label', 'onehop-redirect-checker' ); ?></span>
				</a>
				<a class="button" href="<?php echo esc_url( $link( 'later' ) ); ?>">
					<?php echo esc_html_x( 'Maybe later', 'rating notice', 'onehop-redirect-checker' ); ?>
				</a>
				<?php // WordPress's own red link style, so it shows on the Dashboard too, where our stylesheet is not loaded. ?>
				<a class="button-link button-link-delete" href="<?php echo esc_url( $link( 'never' ) ); ?>">
					<?php echo esc_html_x( 'Don’t ask again', 'rating notice', 'onehop-redirect-checker' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Store the answer, then go where it leads: rating goes on to WordPress.org, the other two come
	 * back to the screen they were given on, without the notice.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! Caps::can_manage() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'onehop-redirect-checker' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::ACTION );

		$choice  = isset( $_GET['choice'] ) ? sanitize_key( wp_unslash( $_GET['choice'] ) ) : '';
		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::USER_META, 'later' === $choice ? (string) ( time() + self::WAIT ) : 'done' );
		}

		if ( 'rate' === $choice ) {
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- A fixed address of ours on WordPress.org, never one taken from the request.
			wp_redirect( self::RATE_URL );
			exit;
		}

		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}

	/**
	 * Whether the current user should be asked now.
	 *
	 * @return bool
	 */
	public static function is_due(): bool {
		if ( ! Caps::can_manage() ) {
			return false;
		}

		$installed = (int) get_site_option( self::INSTALLED_OPTION, 0 );
		if ( $installed < 1 || time() < $installed + self::WAIT ) {
			return false;
		}

		$answer = (string) get_user_meta( get_current_user_id(), self::USER_META, true );

		return '' === $answer || ( 'done' !== $answer && time() >= (int) $answer );
	}

	/**
	 * Forget the install time and every user's answer.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		delete_site_option( self::INSTALLED_OPTION );
		delete_metadata( 'user', 0, self::USER_META, '', true );
	}
}
