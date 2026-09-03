<?php
/**
 * Every client who needs a commercial conversation, on one screen.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Commerce\Assignments;
use Blueworx\Forge\Commerce\Attention;
use Blueworx\Forge\Commerce\Ledger;
use Blueworx\Forge\Commerce\Support;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Clients;

/**
 * #157, COMM-1 and COMM-4. The one screen in the studio that spans clients on
 * purpose.
 *
 * Everywhere else a read is scoped to a client, and that is the safe answer.
 * Here it is the wrong one: the whole value is seeing at a glance which clients
 * are about to run out of hours, run out of term, or have never been sold
 * anything at all. Per-client, each of those facts is one screen somebody has
 * to think to open, and it gets opened the day after the client was told no.
 *
 * **Only the sites that want something appear.** A list that showed every site
 * with a column saying "fine" would need reading; this one is a to-do list, and
 * an empty one means there is nothing to do.
 *
 * The judgement is Commerce\Attention's, not this screen's. What is here is the
 * reading and the wording.
 */
final class SalesScreen {

	/**
	 * The submenu page slug.
	 */
	public const SLUG = 'blueworx-forge-sales';

	/**
	 * Adds the menu entry, under the Forge menu.
	 */
	public static function register(): void {
		add_submenu_page(
			SitesScreen::SLUG,
			__( 'Sales', 'blueworx-forge' ),
			__( 'Sales', 'blueworx-forge' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Renders the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Forge — sales', 'blueworx-forge' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Clients who need something doing about their package. A site drops off this list when it no longer does.', 'blueworx-forge' ) . '</p>';

		$wanted = self::wanted();

		if ( array() === $wanted ) {
			echo '<p data-bwx-attention="0">' . esc_html__( 'Nothing needs attention. Every site on a package has hours and time left.', 'blueworx-forge' ) . '</p>';
			echo '</div>';

			return;
		}

		echo '<table class="widefat striped" data-bwx-attention="' . esc_attr( (string) count( $wanted ) ) . '"><thead><tr>';
		echo '<th>' . esc_html__( 'Client', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Site', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Position', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Hours left', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'What needs doing', 'blueworx-forge' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $wanted as $row ) {
			echo '<tr data-bwx-site="' . esc_attr( $row['site_id'] ) . '">';
			echo '<td>' . esc_html( $row['client'] ) . '</td>';
			echo '<td>' . esc_html( $row['site'] ) . '</td>';
			echo '<td>' . esc_html( $row['state'] ) . '</td>';
			echo '<td data-bwx-balance="' . esc_attr( (string) $row['balance'] ) . '">' . esc_html( number_format( $row['balance'], 2 ) ) . '</td>';
			echo '<td>';

			foreach ( $row['reasons'] as $reason ) {
				printf(
					'<span class="bwx-reason" data-bwx-reason="%1$s">%2$s</span><br>',
					esc_attr( $reason ),
					esc_html( Attention::label( $reason ) )
				);
			}

			echo '</td><td>';
			printf(
				'<a class="button button-small" href="%1$s">%2$s</a>',
				esc_url( SupportScreen::url( $row['site_id'] ) ),
				esc_html__( 'Open', 'blueworx-forge' )
			);
			echo '</td></tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Every site that wants something, with why.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function wanted(): array {
		$today   = gmdate( 'Y-m-d', bwx_forge_now() );
		$clients = array_column( Clients::all( null ), null, 'id' );
		$wanted  = array();

		foreach ( ClientSites::all( null ) as $site ) {
			$id       = (string) $site['id'];
			$position = Assignments::entitlement_on( $id, $today );
			$balance  = Ledger::balance( $id );
			$reasons  = Attention::of( $position, $balance, $today );

			if ( array() === $reasons ) {
				continue;
			}

			$client = $clients[ (string) $site['client_id'] ] ?? array();

			$wanted[] = array(
				'site_id' => $id,
				'site'    => (string) $site['name'],
				'client'  => (string) ( $client['display_name'] ?? '—' ),
				'state'   => Support::label( (string) $position['state'] ),
				'balance' => $balance,
				'reasons' => $reasons,
			);
		}

		return $wanted;
	}
}
