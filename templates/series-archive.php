<?php
/**
 * Plugin-owned public Series archive template.
 *
 * @package WPTitleLayer
 */

defined( 'ABSPATH' ) || exit;

$wptl_term  = get_queried_object();
$wptl_query = isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof \WP_Query
	? $GLOBALS['wp_query']
	: new \WP_Query();
$wptl_view  = ( new \WPTitleLayer\Reader\Archive() )->context( $wptl_term, $wptl_query );

get_header();
?>
<main class="wptl-series-archive" id="wptl-series-archive">
	<?php if ( ! empty( $wptl_view['valid'] ) ) : ?>
		<header class="wptl-series-archive__header<?php echo ! empty( $wptl_view['cover_id'] ) ? ' wptl-series-archive__header--has-cover' : ''; ?>">
			<?php if ( ! empty( $wptl_view['cover_id'] ) ) : ?>
				<div class="wptl-series-archive__cover">
					<?php
					echo wp_kses_post(
						wp_get_attachment_image(
							(int) $wptl_view['cover_id'],
							'large',
							false,
							array( 'class' => 'wptl-series-archive__cover-image' )
						)
					);
					?>
				</div>
			<?php endif; ?>

			<div class="wptl-series-archive__intro">
				<p class="wptl-series-archive__kicker">
					<?php if ( ! empty( $wptl_view['parent_category']['name'] ) ) : ?>
						<?php if ( ! empty( $wptl_view['parent_category']['url'] ) ) : ?>
							<a class="wptl-series-archive__parent-category" href="<?php echo esc_url( (string) $wptl_view['parent_category']['url'] ); ?>"><?php echo esc_html( (string) $wptl_view['parent_category']['name'] ); ?></a>
						<?php else : ?>
							<span><?php echo esc_html( (string) $wptl_view['parent_category']['name'] ); ?></span>
						<?php endif; ?>
						<span class="wptl-series-archive__kicker-separator" aria-hidden="true"> / </span>
					<?php endif; ?>
					<span><?php esc_html_e( 'Series', 'wp-title-layer' ); ?></span>
				</p>
				<h1 class="wptl-series-archive__title"><?php echo esc_html( (string) $wptl_view['name'] ); ?></h1>
				<?php if ( ! empty( $wptl_view['status_label'] ) ) : ?>
					<p class="wptl-series-archive__status wptl-series-archive__status--<?php echo esc_attr( sanitize_html_class( (string) $wptl_view['status'] ) ); ?>"><?php echo esc_html( (string) $wptl_view['status_label'] ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $wptl_view['active_season']['label'] ) ) : ?>
					<p class="wptl-series-archive__active-season">
						<span><?php echo esc_html( (string) $wptl_view['active_season']['label'] ); ?></span>
						<?php if ( ! empty( $wptl_view['series_url'] ) ) : ?>
							<a class="wptl-series-archive__all-seasons" href="<?php echo esc_url( (string) $wptl_view['series_url'] ); ?>"><?php esc_html_e( 'View all seasons', 'wp-title-layer' ); ?></a>
						<?php endif; ?>
					</p>
				<?php endif; ?>
				<?php if ( '' !== trim( wp_strip_all_tags( (string) $wptl_view['description'] ) ) ) : ?>
					<div class="wptl-series-archive__description"><?php echo wp_kses_post( (string) $wptl_view['description'] ); ?></div>
				<?php endif; ?>
				<p class="wptl-series-archive__count">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of published articles in the Series. */
							_n( '%d published article', '%d published articles', (int) $wptl_view['found_posts'], 'wp-title-layer' ),
							(int) $wptl_view['found_posts']
						)
					);
					?>
				</p>
			</div>
		</header>

		<div class="wptl-series-archive__content wptl-series-archive__content--<?php echo esc_attr( sanitize_html_class( (string) $wptl_view['mode'] ) ); ?>">
			<?php if ( empty( $wptl_view['groups'] ) ) : ?>
				<p class="wptl-series-archive__empty"><?php esc_html_e( 'No published articles are available in this Series.', 'wp-title-layer' ); ?></p>
			<?php else : ?>
				<?php foreach ( (array) $wptl_view['groups'] as $wptl_group ) : ?>
					<section class="wptl-series-group">
						<?php if ( ! empty( $wptl_group['label'] ) ) : ?>
							<h2 class="wptl-series-group__title"><?php echo esc_html( (string) $wptl_group['label'] ); ?></h2>
						<?php endif; ?>

						<?php $wptl_group_ordered = ! empty( $wptl_group['ordered'] ); ?>
						<<?php echo $wptl_group_ordered ? 'ol' : 'ul'; ?> class="wptl-series-list">
							<?php foreach ( (array) $wptl_group['posts'] as $wptl_post ) : ?>
								<?php $wptl_has_image = ! empty( $wptl_view['show_featured_images'] ) && ! empty( $wptl_post['featured_image_id'] ); ?>
								<li class="wptl-series-list__item wptl-series-list__item--<?php echo esc_attr( sanitize_html_class( (string) ( $wptl_post['role'] ?? 'article' ) ) ); ?><?php echo $wptl_has_image ? ' wptl-series-list__item--has-image' : ''; ?>">
									<?php if ( '' !== (string) ( $wptl_post['sequence_label'] ?? '' ) ) : ?>
										<span class="wptl-series-list__sequence"><?php echo esc_html( (string) $wptl_post['sequence_label'] ); ?></span>
									<?php endif; ?>

									<?php if ( $wptl_has_image ) : ?>
										<a class="wptl-series-list__media" href="<?php echo esc_url( (string) $wptl_post['url'] ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Read %s', 'wp-title-layer' ), (string) $wptl_post['title'] ) ); ?>">
											<?php
											echo wp_kses_post(
												wp_get_attachment_image(
													(int) $wptl_post['featured_image_id'],
													'medium',
													false,
													array(
														'class' => 'wptl-series-list__image',
														'alt'   => '',
													)
												)
											);
											?>
										</a>
									<?php endif; ?>

									<div class="wptl-series-list__body">
										<h3 class="wptl-series-list__title"><a href="<?php echo esc_url( (string) $wptl_post['url'] ); ?>"><?php echo esc_html( (string) $wptl_post['title'] ); ?></a></h3>
										<?php if ( ! empty( $wptl_view['show_subtitles'] ) && '' !== trim( (string) $wptl_post['subtitle'] ) ) : ?>
											<p class="wptl-series-list__subtitle"><?php echo esc_html( (string) $wptl_post['subtitle'] ); ?></p>
										<?php endif; ?>
										<?php if ( ! empty( $wptl_view['show_excerpts'] ) && '' !== trim( (string) $wptl_post['excerpt'] ) ) : ?>
											<p class="wptl-series-list__excerpt"><?php echo esc_html( (string) $wptl_post['excerpt'] ); ?></p>
										<?php endif; ?>
										<?php if ( 'unordered' === $wptl_view['mode'] && '' !== (string) $wptl_post['date'] ) : ?>
											<time class="wptl-series-list__date" datetime="<?php echo esc_attr( (string) $wptl_post['date_iso'] ); ?>"><?php echo esc_html( (string) $wptl_post['date'] ); ?></time>
										<?php endif; ?>
									</div>
								</li>
							<?php endforeach; ?>
						</<?php echo $wptl_group_ordered ? 'ol' : 'ul'; ?>>
					</section>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>

		<?php if ( ! empty( $wptl_view['pagination'] ) ) : ?>
			<nav class="wptl-series-archive__pagination" aria-label="<?php esc_attr_e( 'Series archive pages', 'wp-title-layer' ); ?>">
				<?php echo wp_kses_post( (string) $wptl_view['pagination'] ); ?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>
</main>
<?php
get_footer();
