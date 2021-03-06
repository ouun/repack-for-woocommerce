<?php

/**
 * The telemetry-specific functionality of the plugin:
 * We respect your privacy, you decide if and which data you share with us.
 * We use the collected data to measure the success of the WeRePack initiative & to improve the code.
 * Websites which opt-in for our WeRePack Directory are listed and linked as supporter shops.
 *
 * @link       https://WeRePack.org
 * @since      1.1.0
 *
 * @package    Repack
 * @subpackage Repack/admin
 */

/**
 * The telemetry-specific functionality of the plugin.
 * This is based on the Telemetry solution of
 *
 * @package    Repack
 * @subpackage Repack/admin
 * @author     Philipp Wellmer <philipp@ouun.io>
 */
class Repack_Telemetry {

	/**
	 * Constructor.
	 *
	 * @access public
	 * @since 1.1.0
	 */
	public function __construct() {
		// Early exit if telemetry is disabled.
		if ( ! apply_filters( 'repack_telemetry', true ) ) {
			return;
		}

		add_action( 'repack_field_init', array( $this, 'field_init' ), 10, 2 );
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
	}

	/**
	 * Additional actions that run on init.
	 *
	 * @access public
	 * @since 1.1.0
	 * @return void
	 */
	public function init() {
		$this->run_action();

		// Scheduled event to trigger telemetry
		add_action( 'repack_telemetry', array( $this, 'maybe_send_data' ) );
	}

	/**
	 * Maybe send data.
	 *
	 * @access public
	 * @since 1.1.0
	 * @return void
	 */
	public function maybe_send_data() {
		// Check if the user has consented to the data sending.
		if ( ! get_option( 'repack_telemetry_optin' ) ) {
			return;
		}

		// Send data & update sent value
		if ( ! is_wp_error( $this->send_data() ) ) {
			update_option( 'repack_telemetry_sent', time() );
		}
	}

	/**
	 * Sends data.
	 *
	 * @access private
	 * @since 1.1.0
	 * @return array|WP_Error
	 */
	private function send_data() {
		// Ping remote server.
		return wp_remote_post(
			'https://werepack.org/?action=repack-stats',
			array(
				'method'   => 'POST',
				'blocking' => false,
				'body'     => array_merge(
					array(
						'action' => 'repack-stats',
					),
					$this->get_data(
						array(
							'repackLastSent' => time(),
						)
					)
				),
			)
		);
	}

	/**
	 * The admin-notice.
	 *
	 * @access private
	 * @since 1.1.0
	 * @return void
	 */
	public function admin_notice() {

		// Early exit if the user has dismissed the consent, or if they have opted-in.
		if ( get_option( 'repack_telemetry_consent_dismissed' ) || get_option( 'repack_telemetry_optin' ) ) {
			return;
		}

		$template_loader = new Repack_Template_Loader();
		$data            = $this->get_data();
		?>
		<div class="notice notice-info repack-telemetry">
			<h3><strong><?php esc_html_e( 'Help us reducing packaging waste. Join the WeRePack Community.', 'repack-for-woocommerce' ); ?></strong></h3>
			<p style="max-width: 76em;">
				<?php _e( 'We want to win you as a supporter and measure our joint success. To do this, you can share certain data with us in order to be listed in the supporter directory on WeRePack.org. This way, we can measure our positive impact on e-commerce and give you a platform that recognises your commitment to the environment. <br><strong>No sensitive user data is transferred.</strong>', 'repack-for-woocommerce' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</p>
			<div class="toggle-hidden hidden">
				<?php
					ob_start();
					$template_loader
						->set_template_data( $data )
						->get_template_part( 'telemetry-data' );

					echo ob_get_clean();
				?>
			</div>
			<p class="actions">
				<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'repack-action', 'telemetry' ) ) ); ?>" class="button button-primary consent"><?php esc_html_e( 'I agree', 'repack-for-woocommerce' ); ?></a>
				<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'repack-action', 'hide-notice' ) ) ); ?>" class="button button-secondary dismiss"><?php esc_html_e( 'No thanks', 'repack-for-woocommerce' ); ?></a>
				<a class="button button-link details details-show"><?php esc_html_e( 'Show me the data', 'repack-for-woocommerce' ); ?></a>
				<a class="button button-link details details-hide hidden"><?php esc_html_e( 'Collapse data', 'repack-for-woocommerce' ); ?></a>
			</p>
			<script>
				jQuery( '.repack-telemetry a.details' ).on( 'click', function() {
					jQuery( '.repack-telemetry .toggle-hidden' ).toggleClass( 'hidden' );
					jQuery( '.repack-telemetry a.details-show' ).toggleClass( 'hidden' );
					jQuery( '.repack-telemetry a.details-hide' ).toggleClass( 'hidden' );
				});
			</script>
		</div>
		<?php
	}

	/**
	 * Builds and returns the data or uses cached if data already exists.
	 *
	 * @access private
	 * @param array $data
	 * @return array
	 * @since 1.1.0
	 */
	public function get_data( $data = array() ) {
		// Build data and return the array.
		return wp_parse_args(
			$data,
			array(
				'siteURL'          => home_url( '/' ),
				'siteLang'         => get_locale(),
				'repackStart'      => get_option( 'repack_start' ),
				'repackCounter'    => get_option( 'repack_counter' ),
				'repackRatio'      => $this->get_repack_ratio( get_option( 'repack_counter' ) ),
				'repackCoupon'     => Repack_Public::repack_coupon_exists(),
				'repackCouponCode' => Repack_Public::get_repack_coupon_name(),
				'repackLastSent'   => get_option( 'repack_telemetry_sent' ),
			)
		);
	}

	/**
	 * Calculate the ratio: orders / consents
	 *
	 * @param $consents
	 * @return float|string
	 */
	private function get_repack_ratio( $consents ) {
		// Orders since starting WeRePack support
		$orders = new WP_Query(
			array(
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'post_type'      => 'shop_order',
				'post_status'    => array_keys( wc_get_order_statuses() ),
				'date_query'     => array(
					array(
						'column' => 'post_date_gmt',
						'after'  => wp_date( 'Y-m-d', strtotime( '-1 day', get_option( 'repack_start' ) ) ),
					),
				),
			)
		);

		if ( $consents === 0 || $orders->found_posts === 0 ) {
			return '0';
		}

		$ratio = round( $consents * 100 / $orders->found_posts );

		return (string) $ratio > 0 ? $ratio : '0';
	}

	/**
	 * Run action by URL.
	 *
	 * @access private
	 * @since 1.2.0
	 * @return void
	 */
	private function run_action() {

		// Check if this is the request we want.
		if ( isset( $_GET['_wpnonce'] ) && isset( $_GET['repack-action'] ) ) {

			// Hide Notice
			if ( 'hide-notice' === sanitize_text_field( wp_unslash( $_GET['repack-action'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				// Check the wp-nonce.
				if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) ) ) {
					// All good, we can save the option to dismiss this notice.
					update_option( 'repack_telemetry_consent_dismissed', true );
				}
			}

			// Telemetry Consent
			if ( 'telemetry' === sanitize_text_field( wp_unslash( $_GET['repack-action'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				// Check the wp-nonce.
				if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) ) ) {
					// Initially send the data in a minute
					if ( wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'repack_telemetry' ) ) {
						// All good, we can save the option to dismiss this notice.
						update_option( 'repack_telemetry_optin', true );
					}
				}
			}

			// Revoke Telemetry Consent
			if ( 'revoke-telemetry' === sanitize_text_field( wp_unslash( $_GET['repack-action'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				// Check the wp-nonce.
				if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) ) ) {
					// Remove scheduled events
					wp_clear_scheduled_hook( 'repack_telemetry' );
					// Remove consent
					update_option( 'repack_telemetry_optin', false );
				}
			}

			// Sync with WeRePack.org
			if ( 'sync' === sanitize_text_field( wp_unslash( $_GET['repack-action'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				// Check the wp-nonce.
				if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) ) ) {
					$this->maybe_send_data();
				}
			}
		}
	}
}
