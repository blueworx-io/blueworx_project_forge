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
use Blueworx\Forge\Tenancy\Health;
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

		Page::open(
			__( 'Sync health', 'blueworx-forge' ),
			__( 'Forge', 'blueworx-forge' ),
			__( 'A broken client site is noticed by us, not by the client.', 'blueworx-forge' )
		);

		self::queue( Sync::queue( $named ) );
		self::everything( $named );

		Page::close();
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
		Page::panel_open( __( 'Needs somebody', 'blueworx-forge' ), 'sync-queue' );

		if ( array() === $queue ) {
			Page::notice(
				'success',
				__( 'Every connected site is reporting in, and nothing is waiting to be collected.', 'blueworx-forge' ),
				array( 'data-bwx-sync-queue' => 'empty' )
			);
			Page::panel_close();

			return;
		}

		echo '<div class="bw-tablescroll">';
		echo '<table class="bw-table" data-bwx-sync-queue="full"><thead><tr>';
		echo '<th>' . esc_html__( 'Site', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'What is wrong', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'What to try', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Last heard from', 'blueworx-forge' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $queue as $row ) {
			self::queue_row( $row );
		}

		echo '</tbody></table>';
		echo '</div>';
		Page::panel_close();
	}

	/**
	 * One entry in the queue.
	 *
	 * @param array<string, mixed> $row The row.
	 */
	private static function queue_row( array $row ): void {
		echo '<tr data-bwx-sync-site="' . esc_attr( (string) $row['client_site_id'] ) . '"';
		echo ' data-bwx-sync-reasons="' . esc_attr( implode( ' ', (array) $row['reasons'] ) ) . '">';

		echo '<td class="bw-table__primary">' . esc_html( (string) $row['site_name'] );

		if ( '' !== (string) $row['client_name'] ) {
			echo '<span class="bw-table__sub">' . esc_html( (string) $row['client_name'] ) . '</span>';
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
			echo '<p class="bw-fieldnote bw-input--mono">' . esc_html( (string) $row['last_error_code'] ) . '</p>';
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
		Page::panel_open( __( 'Every site', 'blueworx-forge' ), 'sync-all' );

		if ( array() === $rows ) {
			echo '<div class="bw-empty">';
			echo '<i class="bw-icon bw-empty__icon" data-lucide="server-off"></i>';
			echo '<p class="bw-empty__title">' . esc_html__( 'No client site has been set up yet', 'blueworx-forge' ) . '</p>';
			echo '</div>';
			Page::panel_close();

			return;
		}

		echo '<div class="bw-tablescroll">';
		echo '<table class="bw-table" data-bwx-sync-all="1"><thead><tr>';
		echo '<th>' . esc_html__( 'Site', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Connection', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Last heard from', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Waiting to be collected', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Plugin', 'blueworx-forge' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr data-bwx-sync-row="' . esc_attr( (string) $row['client_site_id'] ) . '"';
			echo ' data-bwx-sync-state="' . esc_attr( (string) $row['state'] ) . '">';
			echo '<td class="bw-table__primary">' . esc_html( (string) $row['site_name'] ) . '</td>';
			echo '<td>' . self::state_badge( (string) $row['state'], (string) $row['state_label'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- state_badge escapes both of its arguments.
			echo '<td>' . esc_html( self::heard( $row ) ) . '</td>';
			echo '<td class="bw-table__num">' . esc_html( 0 < (int) $row['waiting'] ? (string) (int) $row['waiting'] : '—' ) . '</td>';
			echo '<td>' . esc_html( '' !== (string) $row['plugin_version'] ? (string) $row['plugin_version'] : '—' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
		Page::panel_close();
	}

	/**
	 * A site's connection state, toned by what the state means.
	 *
	 * A site that has stopped talking or had its key revoked is the thing
	 * somebody has to act on, so those are the danger tones. A site nobody has
	 * finished setting up yet is not a fault — it is neutral. A connected site
	 * needs no colour at all. Whole class names, so the admin UI check can
	 * read them.
	 *
	 * @param string $state One of Tenancy\Health's constants.
	 * @param string $label What to show.
	 * @return string
	 */
	private static function state_badge( string $state, string $label ): string {
		$classes = array(
			Health::CONNECTED       => 'bw-badge',
			Health::UNCONFIGURED    => 'bw-badge bw-badge--neutral',
			Health::NEVER_CONNECTED => 'bw-badge bw-badge--neutral',
			Health::IDLE            => 'bw-badge bw-badge--neutral',
			Health::BROKEN          => 'bw-badge bw-badge--danger',
			Health::REVOKED         => 'bw-badge bw-badge--danger',
		);

		return sprintf(
			'<span class="%1$s">%2$s</span>',
			esc_attr( $classes[ $state ] ?? 'bw-badge bw-badge--neutral' ),
			esc_html( $label )
		);
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
