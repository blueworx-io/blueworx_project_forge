<?php
/**
 * Keeping Forge's picture of SureCart current.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Commerce\SureCart;

use Blueworx\Forge\Recurring\Sources;
use Blueworx\Forge\Tenancy\Studio;
use DateTimeImmutable;

/**
 * A refresh reads a store's active subscriptions, keeps a copy, and makes
 * sure each one has a recurring source (PR 3) whose next due date is the
 * renewal date. The recurring engine then does what it does for any
 * source: on renewal day, a task on the studio's board, seats from the
 * connection. A subscription that stops being active has its source ended.
 *
 * No cron. The Subscriptions screen refreshes when it opens, and the
 * recurring engine asks for a refresh at most once an hour before it runs,
 * so renewals are never more than an hour behind and never depend on a
 * timer having fired.
 */
final class Sync {

	/**
	 * How long a refresh holds before the next one may run on its own.
	 */
	private const EVERY = HOUR_IN_SECONDS;

	/**
	 * The transient that says a refresh happened lately.
	 */
	private const RAN = 'bwx_forge_surecart_ran';

	/**
	 * Refreshes every connection, at most once an hour.
	 */
	public static function maybe(): void {
		if ( false !== get_transient( self::RAN ) ) {
			return;
		}

		set_transient( self::RAN, 1, self::EVERY );

		foreach ( Connections::all() as $connection ) {
			if ( Connections::ACTIVE === (string) $connection['status'] ) {
				self::refresh( $connection );
			}
		}
	}

	/**
	 * Refreshes one connection: reads, keeps, reconciles the sources.
	 *
	 * @param array<string, mixed> $connection The connection.
	 * @return array{ok: bool, count: int, error: string}
	 */
	public static function refresh( array $connection ): array {
		$token = Connections::token( (string) $connection['id'] );

		if ( null === $token ) {
			$error = __( 'The token could not be read — enter it again.', 'blueworx-forge' );
			Connections::mark( (string) $connection['id'], false, $error, 0 );

			return array(
				'ok'    => false,
				'count' => 0,
				'error' => $error,
			);
		}

		$answer = Client::subscriptions( $token );

		if ( is_wp_error( $answer ) ) {
			Connections::mark( (string) $connection['id'], false, $answer->get_error_message(), 0 );

			return array(
				'ok'    => false,
				'count' => 0,
				'error' => $answer->get_error_message(),
			);
		}

		$rows = array();

		foreach ( $answer as $subscription ) {
			$row = self::normalise( $subscription );

			// Active only. The call asks for active ones, and a store that hands
			// back more is still shown as what it is: what renews.
			if ( '' !== $row['id'] && 'active' === $row['status'] ) {
				$rows[ $row['id'] ] = $row;
			}
		}

		Subscriptions::replace( (string) $connection['id'], array_values( $rows ) );
		self::reconcile( $connection, array_values( $rows ) );
		Connections::mark( (string) $connection['id'], true, '', count( $rows ) );

		return array(
			'ok'    => true,
			'count' => count( $rows ),
			'error' => '',
		);
	}

	/**
	 * One source per active subscription, due on its renewal date.
	 *
	 * @param array<string, mixed>             $connection The connection.
	 * @param array<int, array<string, mixed>> $rows       Normalised subscriptions.
	 */
	private static function reconcile( array $connection, array $rows ): void {
		$site_id   = Studio::site_id();
		$client_id = Studio::client_id();

		if ( '' === $site_id ) {
			return;
		}

		$settings = (array) $connection['settings'];
		$seen     = array();

		foreach ( $rows as $row ) {
			$ref = self::ref( (string) $connection['id'], (string) $row['id'] );
			$due = (string) $row['renews_on'];

			if ( 'active' !== (string) $row['status'] || '' === $due ) {
				continue;
			}

			$seen[ $ref ] = true;
			$source       = Sources::by_source_ref( $ref );
			$values       = array(
				'title'           => self::title( (string) $row['customer_name'], (int) $row['amount'], (string) $row['currency'] ),
				'description'     => sprintf(
					/* translators: 1: product name, 2: billing interval, 3: store name, 4: customer email */
					__( '%1$s, %2$s, from %3$s. Check that the payment went through. %4$s', 'blueworx-forge' ),
					(string) $row['product_name'],
					(string) $row['interval'],
					(string) $connection['name'],
					(string) $row['customer_email']
				),
				'work_type'       => 'task',
				'primary_user_id' => (string) ( $settings['primary_user_id'] ?? '' ),
				'reviewer_id'     => (string) ( $settings['reviewer_id'] ?? '' ),
				'deliverer_id'    => (string) ( $settings['deliverer_id'] ?? '' ),
				'hours_primary'   => (string) ( $settings['hours_primary'] ?? '0.25' ),
				'hours_review'    => (string) ( $settings['hours_review'] ?? '0' ),
				'hours_delivery'  => (string) ( $settings['hours_delivery'] ?? '0' ),
				'source_ref'      => $ref,
				'status'          => Sources::ACTIVE,
			);

			if ( null === $source || Sources::ENDED === (string) $source['status'] ) {
				$made = Sources::create( $site_id, $client_id, Sources::SUBSCRIPTION, array_merge( $values, array( 'starts_on' => $due ) ), 0 );

				if ( null !== $made ) {
					Sources::pin( (string) $made['id'], $due );
				}

				continue;
			}

			Sources::update( (string) $source['id'], $values, (int) $source['record_version'] );
			Sources::pin( (string) $source['id'], $due );
		}

		// Anything this store used to produce that it no longer lists as
		// active is finished with — the customer cancelled, or paused.
		foreach ( Sources::for_site( $site_id, Sources::SUBSCRIPTION ) as $source ) {
			$ref = (string) $source['source_ref'];

			if ( 0 === strpos( $ref, (string) $connection['id'] . ':' ) && ! isset( $seen[ $ref ] ) ) {
				Sources::end( (string) $source['id'] );
			}
		}
	}

	/**
	 * A subscription as Forge keeps it. Pure, and forgiving: a field SureCart
	 * did not send is empty rather than an error, because a renewal date
	 * with no product name is still a renewal date.
	 *
	 * @param array<string, mixed> $subscription One subscription, as the API sent it.
	 * @return array<string, mixed>
	 */
	public static function normalise( array $subscription ): array {
		$customer = is_array( $subscription['customer'] ?? null ) ? $subscription['customer'] : array();
		$price    = is_array( $subscription['price'] ?? null ) ? $subscription['price'] : array();
		$product  = is_array( $price['product'] ?? null ) ? $price['product'] : array();

		$name = trim( (string) ( $customer['name'] ?? '' ) );

		if ( '' === $name ) {
			$name = trim( trim( (string) ( $customer['first_name'] ?? '' ) ) . ' ' . trim( (string) ( $customer['last_name'] ?? '' ) ) );
		}

		$ends = (int) ( $subscription['current_period_end_at'] ?? 0 );

		return array(
			'id'             => (string) ( $subscription['id'] ?? '' ),
			'status'         => (string) ( $subscription['status'] ?? '' ),
			'customer_name'  => $name,
			'customer_email' => (string) ( $customer['email'] ?? '' ),
			'product_name'   => (string) ( $product['name'] ?? '' ),
			'amount'         => (int) ( $price['amount'] ?? 0 ),
			'currency'       => strtoupper( (string) ( $price['currency'] ?? '' ) ),
			'interval'       => self::interval( (string) ( $price['recurring_interval'] ?? '' ), (int) ( $price['recurring_interval_count'] ?? 1 ) ),
			'renews_on'      => $ends > 0 ? ( new DateTimeImmutable( '@' . $ends ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' ) : '',
		);
	}

	/**
	 * "Subscription Renewal: Acme Ltd - (£120.00)".
	 *
	 * @param string $customer Customer name.
	 * @param int    $amount   Minor units.
	 * @param string $currency ISO code.
	 * @return string
	 */
	public static function title( string $customer, int $amount, string $currency ): string {
		$symbols = array(
			'GBP' => '£',
			'USD' => '$',
			'EUR' => '€',
		);

		$currency = strtoupper( $currency );
		$figure   = number_format( $amount / 100, 2, '.', ',' );
		$money    = isset( $symbols[ $currency ] ) ? $symbols[ $currency ] . $figure : trim( $currency . ' ' . $figure );

		return sprintf( 'Subscription Renewal: %s - (%s)', '' === trim( $customer ) ? 'Nobody' : trim( $customer ), $money );
	}

	/**
	 * "monthly", "yearly", "every 3 months".
	 *
	 * @param string $unit  day, week, month or year.
	 * @param int    $count How many units.
	 * @return string
	 */
	private static function interval( string $unit, int $count ): string {
		if ( '' === $unit ) {
			return '';
		}

		if ( $count <= 1 ) {
			$single = array(
				'day'   => 'daily',
				'week'  => 'weekly',
				'month' => 'monthly',
				'year'  => 'yearly',
			);

			return $single[ $unit ] ?? $unit;
		}

		return sprintf( 'every %d %ss', $count, $unit );
	}

	/**
	 * The key a subscription's source is found by: store and subscription
	 * together, because two stores could both know a `sub_01`.
	 *
	 * @param string $connection_id The connection.
	 * @param string $subscription  The subscription id.
	 * @return string
	 */
	private static function ref( string $connection_id, string $subscription ): string {
		return $connection_id . ':' . $subscription;
	}
}
