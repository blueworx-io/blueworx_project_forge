<?php
/**
 * The support packages on offer, and what each of them has been.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Commerce\Packages;
use Blueworx\Forge\Commerce\Terms;

/**
 * The package catalogue (#145).
 *
 * ARCH-7 puts it here rather than in the React application: the catalogue is
 * configuration the studio writes once and rarely touches, not work anybody
 * does daily.
 *
 * The screen exists to make COMM-1 visible. Every package shows its version
 * number and, underneath, every version it has ever had with the hours and
 * price of each. Somebody who has used this screen should come away knowing
 * that editing a package writes a new version rather than changing the old one
 * — because that is the promise the whole commercial record rests on, and a
 * promise nobody can see is a promise somebody eventually breaks.
 */
final class PackagesScreen {

	/**
	 * The submenu page slug.
	 */
	public const SLUG = 'blueworx-forge-packages';

	/**
	 * Adds the menu entry, under the Forge menu.
	 */
	public static function register(): void {
		add_submenu_page(
			SitesScreen::SLUG,
			__( 'Support packages', 'blueworx-forge' ),
			__( 'Support packages', 'blueworx-forge' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * This screen's URL, optionally carrying a result to report.
	 *
	 * @param string $result A result code, or an empty string.
	 * @return string
	 */
	public static function url( string $result = '' ): string {
		$url = admin_url( 'admin.php?page=' . self::SLUG );

		return '' === $result ? $url : add_query_arg( 'bwx-result', $result, $url );
	}

	/**
	 * Renders the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Forge — support packages', 'blueworx-forge' ) . '</h1>';

		self::result_notice();

		echo '<p>';
		echo esc_html__(
			'Editing a package writes a new version and leaves every earlier one exactly as it was, so a client stays on the terms they were given.',
			'blueworx-forge'
		);
		echo '</p>';

		self::catalogue();
		self::add_form();

		echo '</div>';
	}

	/**
	 * The outcome of the last action, if there was one.
	 */
	private static function result_notice(): void {
		// Chosen from the fixed list below, never free text: it comes off the
		// URL, so anything it can say is something anyone can make an
		// administrator's screen say.
		$result = isset( $_GET['bwx-result'] ) ? sanitize_key( wp_unslash( $_GET['bwx-result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reporting the outcome of an action that carried its own nonce.

		$messages = array(
			'added'     => array( 'success', __( 'Package added.', 'blueworx-forge' ) ),
			'revised'   => array( 'success', __( 'Saved as a new version. Every earlier version is untouched.', 'blueworx-forge' ) ),
			'unchanged' => array( 'info', __( 'Nothing changed, so no new version was written.', 'blueworx-forge' ) ),
			'retired'   => array( 'success', __( 'Retired. Nobody new can be put on it; everybody already on it keeps it.', 'blueworx-forge' ) ),
			'restored'  => array( 'success', __( 'Back on the shelf.', 'blueworx-forge' ) ),
			'reordered' => array( 'success', __( 'Order saved.', 'blueworx-forge' ) ),
			'refused'   => array( 'error', __( 'That is not an offer. A package needs a name and some hours in it.', 'blueworx-forge' ) ),
			'unknown'   => array( 'error', __( 'There is no such package.', 'blueworx-forge' ) ),
		);

		if ( ! isset( $messages[ $result ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s" data-bwx-result="%2$s"><p>%3$s</p></div>',
			esc_attr( $messages[ $result ][0] ),
			esc_attr( $result ),
			esc_html( $messages[ $result ][1] )
		);
	}

	/**
	 * Every package, each with its terms and its history.
	 */
	private static function catalogue(): void {
		$packages = Packages::all();

		if ( array() === $packages ) {
			echo '<p data-bwx-packages="empty">' . esc_html__( 'No packages yet. Add the first one below.', 'blueworx-forge' ) . '</p>';

			return;
		}

		$current = Packages::current_versions( array_column( $packages, 'id' ) );

		echo '<div data-bwx-packages="' . esc_attr( (string) count( $packages ) ) . '">';

		foreach ( $packages as $package ) {
			self::one( $package, $current[ (string) $package['id'] ] ?? array() );
		}

		echo '</div>';

		self::order_form( $packages );
	}

	/**
	 * One package: what it offers now, how to change it, and what it has been.
	 *
	 * @param array<string, mixed> $package The catalogue row.
	 * @param array<string, mixed> $version The version in force.
	 */
	private static function one( array $package, array $version ): void {
		$id      = (string) $package['id'];
		$retired = Terms::RETIRED === (string) $package['status'];

		echo '<div class="card" style="max-width:none;margin-block-end:16px" data-bwx-package="' . esc_attr( $id ) . '"';
		echo ' data-bwx-status="' . esc_attr( (string) $package['status'] ) . '"';
		echo ' data-bwx-version="' . esc_attr( (string) ( $version['version'] ?? 0 ) ) . '">';

		echo '<h2 style="margin-block-start:0">' . esc_html( (string) $package['name'] );

		if ( $retired ) {
			echo ' <span class="description">' . esc_html__( '— retired', 'blueworx-forge' ) . '</span>';
		}

		echo '</h2>';

		self::edit_form( $id, $version );
		self::status_form( $id, $retired );
		self::history( $id );

		echo '</div>';
	}

	/**
	 * The form that writes the next version.
	 *
	 * Prefilled with what the package offers today, because the ordinary edit
	 * is a change to one field — and retyping the other five is how the other
	 * five get changed by accident.
	 *
	 * @param string               $id      The package.
	 * @param array<string, mixed> $version The version in force.
	 */
	private static function edit_form( string $id, array $version ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwx_forge_revise_package' );
		echo '<input type="hidden" name="action" value="bwx_forge_revise_package">';
		echo '<input type="hidden" name="package" value="' . esc_attr( $id ) . '">';

		echo '<table class="form-table"><tbody>';
		self::field( 'name', __( 'Name', 'blueworx-forge' ), (string) ( $version['name'] ?? '' ) );
		self::field( 'hours', __( 'Hours', 'blueworx-forge' ), (string) ( $version['hours'] ?? '' ), 'number', '0.25' );
		self::field( 'price', __( 'Price', 'blueworx-forge' ), (string) ( $version['price'] ?? '' ), 'number', '1' );
		self::field( 'currency', __( 'Currency', 'blueworx-forge' ), (string) ( $version['currency'] ?? 'GBP' ) );
		self::field( 'validity_months', __( 'Runs for (months)', 'blueworx-forge' ), (string) ( $version['validity_months'] ?? Terms::DEFAULT_VALIDITY_MONTHS ), 'number', '1' );

		echo '<tr><th scope="row"><label for="bwx-terms-' . esc_attr( $id ) . '">' . esc_html__( 'Terms', 'blueworx-forge' ) . '</label></th>';
		echo '<td><textarea class="large-text" rows="3" id="bwx-terms-' . esc_attr( $id ) . '" name="terms">';
		echo esc_textarea( (string) ( $version['terms'] ?? '' ) );
		echo '</textarea></td></tr>';
		echo '</tbody></table>';

		submit_button( __( 'Save as a new version', 'blueworx-forge' ), 'primary', 'bwx-revise', false );
		echo '</form>';
	}

	/**
	 * Retire, or bring back.
	 *
	 * @param string $id      The package.
	 * @param bool   $retired Whether it is off the shelf now.
	 */
	private static function status_form( string $id, bool $retired ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-block-start:8px">';
		wp_nonce_field( 'bwx_forge_set_package_status' );
		echo '<input type="hidden" name="action" value="bwx_forge_set_package_status">';
		echo '<input type="hidden" name="package" value="' . esc_attr( $id ) . '">';
		echo '<input type="hidden" name="status" value="' . esc_attr( $retired ? Terms::ACTIVE : Terms::RETIRED ) . '">';

		submit_button(
			$retired ? __( 'Put back on the shelf', 'blueworx-forge' ) : __( 'Retire', 'blueworx-forge' ),
			'secondary',
			'bwx-status',
			false
		);

		echo '</form>';
	}

	/**
	 * Every version this package has had.
	 *
	 * Shown rather than tucked away, because it is the evidence for the claim
	 * at the top of the screen. A history nobody can see is a promise nobody
	 * can check.
	 *
	 * @param string $id The package.
	 */
	private static function history( string $id ): void {
		$versions = Packages::versions_for( $id );

		echo '<h3>' . esc_html__( 'Every version', 'blueworx-forge' ) . '</h3>';
		echo '<table class="widefat striped" data-bwx-history="' . esc_attr( $id ) . '"><thead><tr>';
		echo '<th>' . esc_html__( 'Version', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Name', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Hours', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Price', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Runs for', 'blueworx-forge' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $versions as $version ) {
			echo '<tr data-bwx-package-version="' . esc_attr( (string) $version['version'] ) . '">';
			echo '<td>' . esc_html( (string) $version['version'] ) . '</td>';
			echo '<td>' . esc_html( (string) $version['name'] ) . '</td>';
			echo '<td data-bwx-hours="' . esc_attr( (string) $version['hours'] ) . '">' . esc_html( number_format( (float) $version['hours'], 2 ) ) . '</td>';
			echo '<td data-bwx-price="' . esc_attr( (string) $version['price'] ) . '">';
			echo esc_html( (string) $version['currency'] . ' ' . number_format( (float) $version['price'] ) );
			echo '</td>';
			echo '<td>' . esc_html( (string) $version['validity_months'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The form that adds a package.
	 */
	private static function add_form(): void {
		echo '<h2>' . esc_html__( 'Add a package', 'blueworx-forge' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwx_forge_add_package' );
		echo '<input type="hidden" name="action" value="bwx_forge_add_package">';

		echo '<table class="form-table"><tbody>';
		self::field( 'name', __( 'Name', 'blueworx-forge' ), '' );
		self::field( 'hours', __( 'Hours', 'blueworx-forge' ), '', 'number', '0.25' );
		self::field( 'price', __( 'Price', 'blueworx-forge' ), '', 'number', '1' );
		self::field( 'currency', __( 'Currency', 'blueworx-forge' ), 'GBP' );
		self::field( 'validity_months', __( 'Runs for (months)', 'blueworx-forge' ), (string) Terms::DEFAULT_VALIDITY_MONTHS, 'number', '1' );

		echo '<tr><th scope="row"><label for="bwx-new-terms">' . esc_html__( 'Terms', 'blueworx-forge' ) . '</label></th>';
		echo '<td><textarea class="large-text" rows="3" id="bwx-new-terms" name="terms"></textarea></td></tr>';
		echo '</tbody></table>';

		submit_button( __( 'Add package', 'blueworx-forge' ), 'primary', 'bwx-add', false );
		echo '</form>';
	}

	/**
	 * The form that sets the order the catalogue is shown in.
	 *
	 * Numbers rather than dragging. This screen is opened a handful of times a
	 * year, and a number in a box works on every device and needs no script.
	 *
	 * @param array<int, array<string, mixed>> $packages The catalogue.
	 */
	private static function order_form( array $packages ): void {
		echo '<h2>' . esc_html__( 'Order', 'blueworx-forge' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwx_forge_reorder_packages' );
		echo '<input type="hidden" name="action" value="bwx_forge_reorder_packages">';

		echo '<table class="form-table"><tbody>';

		$at = 0;

		foreach ( $packages as $package ) {
			++$at;

			$id = (string) $package['id'];

			echo '<tr><th scope="row"><label for="bwx-order-' . esc_attr( $id ) . '">' . esc_html( (string) $package['name'] ) . '</label></th>';
			echo '<td><input type="number" min="1" class="small-text" id="bwx-order-' . esc_attr( $id ) . '"';
			echo ' name="order[' . esc_attr( $id ) . ']" value="' . esc_attr( (string) $at ) . '"></td></tr>';
		}

		echo '</tbody></table>';

		submit_button( __( 'Save order', 'blueworx-forge' ), 'secondary', 'bwx-reorder', false );
		echo '</form>';
	}

	/**
	 * One labelled input.
	 *
	 * @param string $name  Field name.
	 * @param string $label How it reads.
	 * @param string $value What is in it.
	 * @param string $type  Input type.
	 * @param string $step  Step, for a number.
	 */
	private static function field( string $name, string $label, string $value, string $type = 'text', string $step = '' ): void {
		$id = 'bwx-' . $name . '-' . wp_unique_id();

		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="' . esc_attr( $type ) . '" class="regular-text" id="' . esc_attr( $id ) . '"';
		echo ' name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"';

		if ( '' !== $step ) {
			echo ' step="' . esc_attr( $step ) . '" min="0"';
		}

		echo '></td></tr>';
	}
}
