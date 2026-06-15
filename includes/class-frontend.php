<?php
/**
 * Frontend: dialog-based DOB verification, asset loading, DOB field markup, and the site-wide 18+ badge.
 *
 * @package Nera_DCMS_Age_Gate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_DCMS_Frontend
 */
class Nera_DCMS_Frontend {

	const STYLE_HANDLE  = 'nera-dcms-age-gate';
	const SCRIPT_HANDLE = 'nera-dcms-age-gate';

	/**
	 * Init.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_badge' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_dialog' ) );
	}

	/**
	 * Enqueue styles and the age-gate dialog script sitewide.
	 */
	public static function enqueue_assets() {
		wp_enqueue_style(
			self::STYLE_HANDLE,
			NERA_DCMS_PLUGIN_URL . 'assets/age-gate.css',
			array(),
			NERA_DCMS_VERSION
		);

		// Load after Alpine.js so Alpine.$data() is available when the script runs.
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			NERA_DCMS_PLUGIN_URL . 'assets/age-gate.js',
			array( 'alpinejs', 'jquery' ),
			NERA_DCMS_VERSION,
			true
		);

		$needs_verification = is_user_logged_in()
			&& ! Nera_DCMS_Enforcement::current_user_can_participate();

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'neraAgeGate',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( Nera_DCMS_Ajax::ACTION ),
				'needsVerification'=> $needs_verification,
				'minAge'           => (int) Nera_DCMS_Age_Validator::min_age(),
				'i18n'             => array(
					'title'      => __( 'Age Verification', 'nera-dcms-age-gate' ),
					'subtitle'   => sprintf(
						/* translators: %d: minimum age */
						__( 'This site and its prize draws are only available to users aged %d and over. Please confirm your date of birth to continue.', 'nera-dcms-age-gate' ),
						(int) Nera_DCMS_Age_Validator::min_age()
					),
					'labelDob'   => __( 'Date of birth', 'nera-dcms-age-gate' ),
					'btnContinue'=> __( 'Continue', 'nera-dcms-age-gate' ),
					'btnClose'   => __( 'Close', 'nera-dcms-age-gate' ),
					'btnVerifying'=> __( 'Verifying…', 'nera-dcms-age-gate' ),
					'errInvalid' => __( 'Please select a valid day, month and year.', 'nera-dcms-age-gate' ),
					'errUnderage'=> sprintf(
						/* translators: %d: minimum age */
						__( 'Sorry, you must be %d or over to take part in prize draws on this site.', 'nera-dcms-age-gate' ),
						(int) Nera_DCMS_Age_Validator::min_age()
					),
					'errGeneral' => __( 'Something went wrong. Please try again.', 'nera-dcms-age-gate' ),
				),
			)
		);
	}

	/**
	 * Output the three date-of-birth dropdowns (Day / Month / Year), shared by the
	 * registration form and the age verification dialog.
	 *
	 * @param array $selected Optional selected values: day, month, year.
	 */
	public static function dob_fields_html( $selected = array() ) {
		$sel_day   = isset( $selected['day'] ) ? (int) $selected['day'] : 0;
		$sel_month = isset( $selected['month'] ) ? (int) $selected['month'] : 0;
		$sel_year  = isset( $selected['year'] ) ? (int) $selected['year'] : 0;

		$months = array(
			1  => __( 'January', 'nera-dcms-age-gate' ),
			2  => __( 'February', 'nera-dcms-age-gate' ),
			3  => __( 'March', 'nera-dcms-age-gate' ),
			4  => __( 'April', 'nera-dcms-age-gate' ),
			5  => __( 'May', 'nera-dcms-age-gate' ),
			6  => __( 'June', 'nera-dcms-age-gate' ),
			7  => __( 'July', 'nera-dcms-age-gate' ),
			8  => __( 'August', 'nera-dcms-age-gate' ),
			9  => __( 'September', 'nera-dcms-age-gate' ),
			10 => __( 'October', 'nera-dcms-age-gate' ),
			11 => __( 'November', 'nera-dcms-age-gate' ),
			12 => __( 'December', 'nera-dcms-age-gate' ),
		);

		$current_year = (int) gmdate( 'Y' );
		$oldest_year  = $current_year - (int) Nera_DCMS_Age_Validator::MAX_AGE;

		echo '<div class="nera-dob-fields" data-nera-dob role="group" aria-label="' . esc_attr__( 'Date of birth', 'nera-dcms-age-gate' ) . '">';

		// Day.
		echo '<div class="nera-dob-field">';
		echo '<label class="nera-dob-field-label" for="nera_dob_day">' . esc_html__( 'Day', 'nera-dcms-age-gate' ) . '</label>';
		echo '<select id="nera_dob_day" name="nera_dob_day" class="nera-dob-select" required>';
		echo '<option value="">' . esc_html__( 'Day', 'nera-dcms-age-gate' ) . '</option>';
		for ( $d = 1; $d <= 31; $d++ ) {
			printf(
				'<option value="%1$d"%2$s>%1$d</option>',
				$d,
				selected( $sel_day, $d, false )
			);
		}
		echo '</select>';
		echo '</div>';

		// Month.
		echo '<div class="nera-dob-field">';
		echo '<label class="nera-dob-field-label" for="nera_dob_month">' . esc_html__( 'Month', 'nera-dcms-age-gate' ) . '</label>';
		echo '<select id="nera_dob_month" name="nera_dob_month" class="nera-dob-select" required>';
		echo '<option value="">' . esc_html__( 'Month', 'nera-dcms-age-gate' ) . '</option>';
		foreach ( $months as $num => $label ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $num,
				selected( $sel_month, $num, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '</div>';

		// Year.
		echo '<div class="nera-dob-field">';
		echo '<label class="nera-dob-field-label" for="nera_dob_year">' . esc_html__( 'Year', 'nera-dcms-age-gate' ) . '</label>';
		echo '<select id="nera_dob_year" name="nera_dob_year" class="nera-dob-select" required>';
		echo '<option value="">' . esc_html__( 'Year', 'nera-dcms-age-gate' ) . '</option>';
		for ( $y = $current_year; $y >= $oldest_year; $y-- ) {
			printf(
				'<option value="%1$d"%2$s>%1$d</option>',
				$y,
				selected( $sel_year, $y, false )
			);
		}
		echo '</select>';
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Render the age verification dialog in the footer.
	 *
	 * Only output for logged-in users who still need to verify their DOB.
	 * The dialog is hidden by default; age-gate.js opens it when needed.
	 */
	public static function render_dialog() {
		if ( ! is_user_logged_in() || Nera_DCMS_Enforcement::current_user_can_participate() ) {
			return;
		}

		$min = (int) Nera_DCMS_Age_Validator::min_age();
		?>
		<div
			id="nera-age-dialog"
			class="nera-age-overlay"
			role="dialog"
			aria-modal="true"
			aria-hidden="true"
			aria-labelledby="nera-age-dialog-title"
			style="display:none">

			<div class="nera-age-dialog-card bg-surface rounded-2xl shadow-xl p-6 sm:p-8 max-w-md w-full mx-4">

				<button
					type="button"
					id="nera-age-dialog-close"
					class="nera-age-dialog-close"
					aria-label="<?php esc_attr_e( 'Close', 'nera-dcms-age-gate' ); ?>">
					<span class="material-symbols-outlined" aria-hidden="true">close</span>
				</button>

				<div class="text-center mb-6">
					<div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-primary/10 mb-4">
						<span class="material-symbols-outlined text-primary text-3xl" aria-hidden="true">cake</span>
					</div>
					<h2 id="nera-age-dialog-title" class="text-2xl font-bold text-text-primary mb-2">
						<?php esc_html_e( 'Age Verification', 'nera-dcms-age-gate' ); ?>
					</h2>
					<p class="text-sm text-text-secondary">
						<?php
						printf(
							/* translators: %d: minimum age */
							esc_html__( 'This site and its prize draws are only available to users aged %d and over. Please confirm your date of birth to continue.', 'nera-dcms-age-gate' ),
							$min
						);
						?>
					</p>
				</div>

				<div class="nera-age-dialog-actions">
					<?php self::dob_fields_html(); ?>

					<p
						id="nera-age-dialog-error"
						class="nera-age-dialog-error hidden"
						role="alert"
						aria-live="polite">
					</p>

					<button
						id="nera-age-dialog-submit"
						type="button"
						class="nera-age-dialog-submit w-full inline-flex items-center justify-center gap-2 px-8 py-4 bg-primary text-white font-semibold rounded-xl hover:opacity-90 transition-all shadow-sm hover:shadow-md tracking-wide uppercase disabled:opacity-50 disabled:cursor-not-allowed"
						aria-busy="false">
						<span class="nera-age-dialog-submit-icon material-symbols-outlined text-xl" aria-hidden="true">check_circle</span>
						<span id="nera-age-dialog-submit-label">
							<?php esc_html_e( 'Continue', 'nera-dcms-age-gate' ); ?>
						</span>
					</button>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * Site-wide persistent 18+ badge.
	 */
	public static function render_badge() {
		$min = (int) Nera_DCMS_Age_Validator::min_age();
		printf(
			'<div class="nera-age-badge" aria-label="%1$s" title="%1$s">%2$d+</div>',
			esc_attr(
				sprintf(
					/* translators: %d: minimum age */
					__( 'This site is for users aged %d and over', 'nera-dcms-age-gate' ),
					$min
				)
			),
			$min
		);
	}
}
