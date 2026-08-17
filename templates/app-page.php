<?php
/**
 * Full-page template for the app. Deliberately bare: the app owns the whole
 * viewport, so the theme's header, footer and sidebars are not loaded.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'bwx-forge-page' ); ?>>
<?php wp_body_open(); ?>
<div id="bwx-forge-app"></div>
<?php wp_footer(); ?>
</body>
</html>
