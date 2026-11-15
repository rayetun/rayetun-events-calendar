<?php
/**
 * The event archive, for classic themes.
 *
 * Block themes get a block template instead; see Templates::register().
 *
 * @package RayEtun\EventsCalendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main id="primary" class="site-main rayec-archive">
	<header class="rayec-archive__head">
		<h1 class="rayec-archive__title"><?php post_type_archive_title(); ?></h1>
		<?php
		$rayec_description = get_the_post_type_description();
		if ( $rayec_description ) {
			echo '<div class="rayec-archive__intro">' . wp_kses_post( wpautop( $rayec_description ) ) . '</div>';
		}
		?>
	</header>
	<?php echo do_shortcode( '[rayec_calendar]' ); ?>
</main>
<?php
get_footer();
