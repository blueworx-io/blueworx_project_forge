<?php
/**
 * Full-page template for the client workspace.
 *
 * Bare on purpose, with no wp_head() or wp_footer(): the app owns the whole
 * viewport and its own styling, and the theme, the block library and every
 * other plugin's global assets stay out (#193). Frontend::print_styles() and
 * Frontend::print_scripts() print this plugin's own handles and nothing else.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bwx_forge_client_frontend = \Blueworx\Forge\Client\Frontend::instance();

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( wp_get_document_title() ); ?></title>
	<?php $bwx_forge_client_frontend->print_styles(); ?>
</head>
<?php // A fixed class, not body_class(): the theme's classes are what its stylesheet hangs off. ?>
<body class="bwx-forge-client-page">
<div id="bwx-forge-client-app"></div>
<?php $bwx_forge_client_frontend->print_scripts(); ?>
</body>
</html>
