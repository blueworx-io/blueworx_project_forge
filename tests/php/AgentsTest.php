<?php
/**
 * The agent as a member of staff.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\Agents;
use PHPUnit\Framework\TestCase;

/**
 * #689. The agent holds seats, its hours are its own, and the deliverer looks
 * after the task while it is the agent's turn.
 */
final class AgentsTest extends TestCase {

	public function test_default_hours_are_twelve_on_weekdays_and_none_at_weekends(): void {
		$hours = Agents::default_hours();

		$this->assertCount( 7, $hours );

		foreach ( array( 'hours_mon', 'hours_tue', 'hours_wed', 'hours_thu', 'hours_fri' ) as $day ) {
			$this->assertSame( 12.0, $hours[ $day ], $day );
		}

		$this->assertSame( 0.0, $hours['hours_sat'] );
		$this->assertSame( 0.0, $hours['hours_sun'] );
	}
}
