<?php
/**
 * Stand-in for the real wp-admin/includes/upgrade.php, so Schema::maybe_upgrade()'s
 * require_once has something to find. dbDelta() itself is stubbed in bootstrap.php,
 * before this file is ever required, so this file only needs to exist.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );
