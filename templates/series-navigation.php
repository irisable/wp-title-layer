<?php
/**
 * Ordered Series navigation partial.
 *
 * Available variable: $wptl_navigation.
 *
 * @package WPTitleLayer
 */

defined( 'ABSPATH' ) || exit;

if ( ! isset( $wptl_navigation ) || ! is_array( $wptl_navigation ) || empty( $wptl_navigation['enabled'] ) ) {
	return;
}

$wptl_series   = isset( $wptl_navigation['series'] ) && is_array( $wptl_navigation['series'] ) ? $wptl_navigation['series'] : array();
$wptl_previous = isset( $wptl_navigation['previous'] ) && is_array( $wptl_navigation['previous'] ) ? $wptl_navigation['previous'] : null;
$wptl_next     = isset( $wptl_navigation['next'] ) && is_array( $wptl_navigation['next'] ) ? $wptl_navigation['next'] : null;
$wptl_first    = isset( $wptl_navigation['first'] ) && is_array( $wptl_navigation['first'] ) ? $wptl_navigation['first'] : null;
$wptl_scope    = isset( $wptl_navigation['scope'] ) ? (string) $wptl_navigation['scope'] : 'series';
$wptl_season   = isset( $wptl_navigation['season'] ) && is_array( $wptl_navigation['season'] ) ? $wptl_navigation['season'] : null;
$wptl_position = max( 1, (int) ( $wptl_navigation['position'] ?? 1 ) );
$wptl_total    = max( 1, (int) ( $wptl_navigation['total'] ?? 1 ) );
$wptl_is_season_scope = 'season' === $wptl_scope && is_array( $wptl_season ) && ! empty( $wptl_season['label'] );
?>
<nav class="wptl-series-navigation" aria-label="<?php esc_attr_e( 'Series reading navigation', 'wp-title-layer' ); ?>">
	<div class="wptl-series-navigation__context">
		<span class="wptl-series-navigation__context-name">
			<?php if ( ! empty( $wptl_series['url'] ) && ! empty( $wptl_series['name'] ) ) : ?>
				<a class="wptl-series-navigation__series" href="<?php echo esc_url( (string) $wptl_series['url'] ); ?>"><?php echo esc_html( (string) $wptl_series['name'] ); ?></a>
			<?php elseif ( ! empty( $wptl_series['name'] ) ) : ?>
				<span class="wptl-series-navigation__series"><?php echo esc_html( (string) $wptl_series['name'] ); ?></span>
			<?php endif; ?>
			<?php if ( $wptl_is_season_scope ) : ?>
				<span class="wptl-series-navigation__separator" aria-hidden="true">·</span>
				<?php if ( ! empty( $wptl_season['url'] ) ) : ?>
					<a class="wptl-series-navigation__scope" href="<?php echo esc_url( (string) $wptl_season['url'] ); ?>"><?php echo esc_html( (string) $wptl_season['label'] ); ?></a>
				<?php else : ?>
					<span class="wptl-series-navigation__scope"><?php echo esc_html( (string) $wptl_season['label'] ); ?></span>
				<?php endif; ?>
			<?php endif; ?>
		</span>
		<span class="wptl-series-navigation__position" aria-current="step">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: current article position, 2: total article count. */
					__( '%1$d of %2$d', 'wp-title-layer' ),
					$wptl_position,
					$wptl_total
				)
			);
			?>
		</span>
	</div>

	<div class="wptl-series-navigation__links">
		<?php if ( $wptl_previous ) : ?>
			<a class="wptl-series-navigation__link wptl-series-navigation__link--previous" rel="prev" href="<?php echo esc_url( (string) $wptl_previous['url'] ); ?>">
				<span class="wptl-series-navigation__eyebrow"><?php esc_html_e( 'Previous', 'wp-title-layer' ); ?></span>
				<span class="wptl-series-navigation__title"><?php echo esc_html( (string) $wptl_previous['title'] ); ?></span>
			</a>
		<?php else : ?>
			<span class="wptl-series-navigation__spacer"></span>
		<?php endif; ?>

		<?php if ( $wptl_first ) : ?>
			<a class="wptl-series-navigation__start" href="<?php echo esc_url( (string) $wptl_first['url'] ); ?>">
				<?php if ( $wptl_is_season_scope ) : ?>
					<?php esc_html_e( 'Read this season from the beginning', 'wp-title-layer' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Read from the beginning', 'wp-title-layer' ); ?>
				<?php endif; ?>
			</a>
		<?php endif; ?>

		<?php if ( $wptl_next ) : ?>
			<a class="wptl-series-navigation__link wptl-series-navigation__link--next" rel="next" href="<?php echo esc_url( (string) $wptl_next['url'] ); ?>">
				<span class="wptl-series-navigation__eyebrow"><?php esc_html_e( 'Next', 'wp-title-layer' ); ?></span>
				<span class="wptl-series-navigation__title"><?php echo esc_html( (string) $wptl_next['title'] ); ?></span>
			</a>
		<?php else : ?>
			<span class="wptl-series-navigation__spacer"></span>
		<?php endif; ?>
	</div>
</nav>
