<?php
/**
 * Full-page template for the app.
 *
 * Deliberately bare, and deliberately without wp_head() or wp_footer() (#193).
 * The app owns the whole viewport and its own styling: the theme's header,
 * footer and sidebars are not loaded, and neither is the theme's CSS, the block
 * library's presets, the admin bar or any other plugin's global assets. Almost
 * all of that arrives as inline <style> rather than as a linked file, which is
 * why a bare-looking template was still inheriting the whole of the active
 * theme until this was fixed.
 *
 * Frontend::print_styles() and Frontend::print_scripts() print this plugin's
 * own handles and nothing else. tests/e2e/style-isolation.spec.js fails if
 * anything else reaches the page.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bwx_forge_frontend = \Blueworx\Forge\Frontend::instance();

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( wp_get_document_title() ); ?></title>
	<?php $bwx_forge_frontend->print_styles(); ?>
</head>
<?php // A fixed class, not body_class(): that adds the theme's own classes, which is the hook every theme stylesheet hangs off. ?>
<body class="bwx-forge-page">
<div id="bwx-forge-app"></div>
<?php $bwx_forge_frontend->print_scripts(); ?>
</body>
</html>
