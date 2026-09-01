<?php
/**
 * The screen that says which client sites have stopped talking to us.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Tenancy\Clients;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Sync;

/**
 * #177. A broken client site is noticed by us, not by the client.
 *
 * Two lists, and the order of them is the whole design. First the sites
 * somebody has to do something about, with what is wrong and what to try.
 * Then every site, so the queue being empty can be read as "all forty are
 * fine" rather than "the check is broken" — an empty queue is exactly as
 * uninformative as a full one until you can see what it was drawn from.
 *
 * A WordPress admin screen rather than a screen in the application, per ARCH-7.
 * This is about the plumbing between us and a site, and it sits next to the
 * keys somebody would rotate to fix it; it is not work anybody does for a
 * client. The one thing that does belong in the application — "this needs
 * somebody today" — already appears in Standup, from this same class, so the
 * two cannot disagree about what broken means.
 */
final class SyncScreen {

	/**
	 * The submenu page slug.
	 */
	public const SLUG = 'blueworx-forge-sync';

	/**
	 * Adds the menu entry, under the Forge menu.
	 */
	public static function register(): void {
		add_submenu_page(
			SitesScreen::SLUG,
			__( 'Sync health', 'blueworx-forge' ),
			__( 'Sync health', 'blueworx-forge' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * This screen's URL.
	 *
	 * @return string
	 */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/**
	 * Renders the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$rows  = Sync::all();
		$named = self::named( $rows );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Forge — sync health', 'blueworx-forge' ) . '</h1>';

		self::queue( Sync::queue( $named ) );
		self::everything( $named );

		echo '</div>';
	}

	/**
	 * The rows with the site and client names filled in.
	 *
	 * Done once here rather than per row, because a table of forty sites that
	 * looked up a name per row would be eighty queries to draw a page that is
	 * mostly about how slow things are.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function named( array $rows ): array {
		$sites   = array_column( ClientSites::all( null ), null, 'id' );
		$clients = array_column( Clients::all( null ), null, 'id' );

		foreach ( $rows as $at => $row ) {
			$site   = $sites[ $row['client_site_id'] ] ?? array();
			$client = $clients[ $row['client_id'] ] ?? array();

			$rows[ $at ]['site_name']   = (string) ( $site['name'] ?? $row['client_site_id'] );
			$rows[ $at ]['site_url']    = (string) ( $site['url'] ?? '' );
			$rows[ $at ]['client_name'] = (string) ( $client['display_name'] ?? $client['name'] ?? '' );
		}

		return $rows;
	}

	/**
	 * The sites somebody has to do something about.
	 *
	 * @param array<int, array<string, mixed>> $queue The queue, worst first.
	 */
	private static function queue( array $queue ): void {
		echo '<h2>' . esc_html__( 'Needs somebody', 'blueworx-forge' ) . '</h2>';

		if ( array() === $queue ) {
			echo '<div class="notice notice-success inline" data-bwx-sync-queue="empty"><p>';
			echo esc_html__( 'Every connected site is reporting in, and nothing is waiting to be collected.', 'blueworx-forge' );
			echo '</p></div>';

			return;
		}

		echo '<table class="widefat striped" data-bwx-sync-queue="full"><thead><tr>';
		echo '<th>' . esc_html__( 'Site', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'What is wrong', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'What to try', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Last heard from', 'blueworx-forge' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $queue as $row ) {
			self::queue_row( $row );
		}

		echo '</tbody></table>';
	}

	/**
	 * One entry in the queue.
	 *
	 * @param array<string, mixed> $row The row.
	 */
	private static function queue_row( array $row ): void {
		echo '<tr data-bwx-sync-site="' . esc_attr( (string) $row['client_site_id'] ) . '"';
		echo ' data-bwx-sync-reasons="' . esc_attr( implode( ' ', (array) $row['reasons'] ) ) . '">';

		echo '<td><strong>' . esc_html( (string) $row['site_name'] ) . '</strong>';

		if ( '' !== (string) $row['client_name'] ) {
			echo '<br><span class="description">' . esc_html( (string) $row['client_name'] ) . '</span>';
		}

		echo '</td>';

		echo '<td>';

		foreach ( (array) $row['reasons'] as $reason ) {
			echo '<p>' . esc_html( Sync::label( (string) $reason ) ) . '</p>';
		}

		/*
		 * The mailer's or the signature's own complaint, and the size of the
		 * backlog. A queue that says "broken" and nothing else sends somebody
		 * off to work out from scratch what this screen already knew.
		 */
		if ( '' !== (string) $row['last_error_code'] ) {
			echo '<p><code>' . esc_html( (string) $row['last_error_code'] ) . '</code></p>';
		}

		if ( 0 < (int) $row['waiting'] ) {
			printf(
				'<p data-bwx-sync-waiting="%1$d">%2$s</p>',
				(int) $row['waiting'],
				esc_html(
					sprintf(
						/* translators: 1: number of emails, 2: how long the oldest has waited, such as "3 hours". */
						_n(
							'%1$d email waiting, the oldest for %2$s.',
							'%1$d emails waiting, the oldest for %2$s.',
							(int) $row['waiting'],
							'blueworx-forge'
						),
						(int) $row['waiting'],
						self::duration( (int) $row['waiting_for'] )
					)
				)
			);
		}

		echo '</td>';

		echo '<td>';

		foreach ( (array) $row['reasons'] as $reason ) {
			echo '<p>' . esc_html( Sync::what_to_do( (string) $reason ) ) . '</p>';
		}

		echo '</td>';

		echo '<td>' . esc_html( self::heard( $row ) ) . '</td>';
		echo '</tr>';
	}

	/**
	 * Every site, so an empty queue means something.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows.
	 */
	private static function everything( array $rows ): void {
		echo '<h2>' . esc_html__( 'Every site', 'blueworx-forge' ) . '</h2>';

		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No client site has been set up yet.', 'blueworx-forge' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped" data-bwx-sync-all="1"><thead><tr>';
		echo '<th>' . esc_html__( 'Site', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Connection', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Last heard from', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Waiting to be collected', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Plugin', 'blueworx-forge' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr data-bwx-sync-row="' . esc_attr( (string) $row['client_site_id'] ) . '"';
			echo ' data-bwx-sync-state="' . esc_attr( (string) $row['state'] ) . '">';
			echo '<td>' . esc_html( (string) $row['site_name'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['state_label'] ) . '</td>';
			echo '<td>' . esc_html( self::heard( $row ) ) . '</td>';
			echo '<td>' . esc_html( 0 < (int) $row['waiting'] ? (string) (int) $row['waiting'] : '—' ) . '</td>';
			echo '<td>' . esc_html( '' !== (string) $row['plugin_version'] ? (string) $row['plugin_version'] : '—' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * When we last heard from a site, said the way somebody would say it.
	 *
	 * @param array<string, mixed> $row The row.
	 * @return string
	 */
	private static function heard( array $row ): string {
		if ( 0 === (int) $row['last_seen_at'] ) {
			return __( 'Never', 'blueworx-forge' );
		}

		return sprintf(
			/* translators: %s: a length of time, such as "3 days". */
			__( '%s ago', 'blueworx-forge' ),
			self::duration( (int) $row['silent_for'] )
		);
	}

	/**
	 * A length of time in the largest unit that still says something.
	 *
	 * WordPress's own human_time_diff would do most of this, and is not used
	 * because it needs a pair of timestamps and this has a duration — passing
	 * it `now` and `now minus the gap` is the same arithmetic done twice, in a
	 * form that reads as though it were doing something else.
	 *
	 * @param int $seconds How long.
	 * @return string
	 */
	private static function duration( int $seconds ): string {
		if ( $seconds < MINUTE_IN_SECONDS ) {
			return __( 'less than a minute', 'blueworx-forge' );
		}

		if ( $seconds < HOUR_IN_SECONDS ) {
			$minutes = (int) floor( $seconds / MINUTE_IN_SECONDS );

			/* translators: %d: a number of minutes. */
			return sprintf( _n( '%d minute', '%d minutes', $minutes, 'blueworx-forge' ), $minutes );
		}

		if ( $seconds < DAY_IN_SECONDS ) {
			$hours = (int) floor( $seconds / HOUR_IN_SECONDS );

			/* translators: %d: a number of hours. */
			return sprintf( _n( '%d hour', '%d hours', $hours, 'blueworx-forge' ), $hours );
		}

		$days = (int) floor( $seconds / DAY_IN_SECONDS );

		/* translators: %d: a number of days. */
		return sprintf( _n( '%d day', '%d days', $days, 'blueworx-forge' ), $days );
	}
}
