<?php
/**
 * Safe title-layer renderer.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Presentation;

defined( 'ABSPATH' ) || exit;

final class Renderer {
	/** @var TemplateResolver */
	private $resolver;

	public function __construct( ?TemplateResolver $resolver = null ) {
		$this->resolver = $resolver ?: new TemplateResolver();
	}

	/**
	 * Render a complete title layer.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $args    Rendering overrides.
	 * @return string
	 */
	public function render( int $post_id = 0, array $args = array() ): string {
		$post_id = $post_id > 0 ? $post_id : (int) get_the_ID();
		if ( ! $this->canReadPost( $post_id ) ) {
			return '';
		}

		$resolved  = $this->resolver->resolve( $post_id );
		if ( ! empty( $resolved['disabled'] ) ) {
			return '';
		}
		$preset_id = sanitize_key( (string) ( $args['preset'] ?? '' ) );
		$presets   = Presets::all();
		if ( '' === $preset_id || ! isset( $presets[ $preset_id ] ) ) {
			$preset_id = (string) $resolved['preset'];
		}
		$preset   = Presets::get( $preset_id );
		$recipe   = Presets::recipe( $preset_id );
		$context  = $this->context( $post_id, $resolved );
		$template = $this->templateForRecipe( $preset_id, (string) $preset['template'], $recipe );
		$html     = $this->interpolate( $template, $context, $recipe );
		$html     = $this->replaceHeadingTag( $html, (string) ( $args['heading_tag'] ?? 'h1' ) );

		$class_name = $this->sanitizeClassList( (string) ( $args['class_name'] ?? '' ) );
		if ( '' !== $class_name ) {
			$html = preg_replace_callback(
				'/^(\s*<([a-z][a-z0-9]*)\b[^>]*\bclass=)(["\'])([^"\']*)(\3)/i',
				static function ( array $matches ) use ( $class_name ): string {
					return $matches[1] . $matches[3] . trim( $matches[4] . ' ' . $class_name ) . $matches[5];
				},
				$html,
				1
			) ?: $html;
		}

		$html = $this->applyRootAttributes(
			$html,
			array(
				'id'         => (string) ( $args['root_id'] ?? '' ),
				'style'      => (string) ( $args['root_style'] ?? '' ),
				'aria-label' => (string) ( $args['root_aria_label'] ?? '' ),
			)
		);
		$html = $this->applyTitleAttributes(
			$html,
			array(
				'class'      => (string) ( $args['title_class_name'] ?? '' ),
				'id'         => (string) ( $args['title_id'] ?? '' ),
				'style'      => (string) ( $args['title_style'] ?? '' ),
				'aria-label' => (string) ( $args['title_aria_label'] ?? '' ),
			)
		);

		/**
		 * Filter rendered title-layer HTML before the final allow-list pass.
		 *
		 * @param string              $html       Rendered HTML.
		 * @param int                 $post_id    Post ID.
		 * @param array<string,mixed> $context    Semantic context.
		 * @param array<string,mixed> $resolution Template resolution.
		 */
		$html = (string) apply_filters( 'wptl_rendered_title_layer', $html, $post_id, $context, $resolved );

		return wp_kses( $html, self::allowedHtml() );
	}

	/**
	 * Build phrasing-safe fragments for an exact classic-theme title adapter.
	 *
	 * The theme keeps its own heading wrapper. WP Title Layer contributes only
	 * a context line, safe inline title content, and an optional subtitle line.
	 *
	 * @return array{status:string,before:string,title:string,after:string,preset:string}
	 */
	public function themeTitleFragments( int $post_id ): array {
		$empty = array(
			'status' => 'unreadable',
			'before' => '',
			'title'  => '',
			'after'  => '',
			'preset' => '',
		);
		if ( ! $this->canReadPost( $post_id ) ) {
			return $empty;
		}

		$resolved = $this->resolver->resolve( $post_id );
		if ( ! empty( $resolved['disabled'] ) ) {
			$empty['status'] = 'disabled';
			return $empty;
		}

		$presets   = Presets::all();
		$preset_id = sanitize_key( (string) ( $resolved['preset'] ?? '' ) );
		if ( ! isset( $presets[ $preset_id ] ) ) {
			$preset_id = Presets::DEFAULT_ID;
		}
		$recipe   = Presets::recipe( $preset_id );
		$context  = $this->context( $post_id, $resolved );
		$title    = trim( (string) ( $context['title'] ?? '' ) );
		$subtitle = trim( (string) ( $context['subtitle'] ?? '' ) );
		$eyebrow  = trim( (string) ( $context['eyebrow'] ?? '' ) );
		if ( '' === $title ) {
			return $empty;
		}

		$show_series = null === $recipe
			? in_array( $preset_id, array( 'standard', 'editorial' ), true )
			: 'above' === $recipe['series_position'];
		$subtitle_position = null === $recipe
			? ( 'inline' === $preset_id ? 'inline' : 'stacked' )
			: (string) $recipe['subtitle_position'];

		$before       = '';
		$after        = '';
		$preset_class = 'wptl-preset--' . sanitize_html_class( $preset_id );
		if ( $show_series && '' !== $eyebrow ) {
			$before = '<p class="wptl-theme-title-context wptl-eyebrow ' . $preset_class . '">' . $this->seriesContextHtml( $context, true ) . '</p>';
		}

		$title_class = 'inline' === $subtitle_position && '' !== $subtitle
			? 'wptl-title wptl-subtitle-layout--inline'
			: 'wptl-title';
		$title_html = '<span class="' . $title_class . ' ' . $preset_class . '">' . esc_html( $title ) . '</span>';
		if ( 'inline' === $subtitle_position && '' !== $subtitle ) {
			$separator = null === $recipe
				? Presets::separatorText( Presets::defaultRecipe( 'inline' ) )
				: Presets::separatorText( $recipe );
			$after = '<span class="wptl-theme-title-inline ' . $preset_class . '"><span class="wptl-title-separator">' . esc_html( $separator ) . '</span><span class="wptl-subtitle">' . esc_html( $subtitle ) . '</span></span>';
		} elseif ( 'stacked' === $subtitle_position && '' !== $subtitle ) {
			$after = '<p class="wptl-theme-title-subtitle wptl-subtitle ' . $preset_class . '">' . esc_html( $subtitle ) . '</p>';
		}

		$fragments = array(
			'status' => 'rendered',
			'before' => $before,
			'title'  => $title_html,
			'after'  => $after,
			'preset' => $preset_id,
		);

		/**
		 * Filter classic-theme title fragments before the final allow-list pass.
		 *
		 * @param array<string,string> $fragments Safe title-slot fragments.
		 * @param int                  $post_id   Post ID.
		 * @param array<string,mixed>  $context   Semantic title context.
		 */
		$filtered = apply_filters( 'wptl_theme_title_fragments', $fragments, $post_id, $context );
		if ( is_array( $filtered ) ) {
			foreach ( array( 'status', 'before', 'title', 'after', 'preset' ) as $key ) {
				if ( isset( $filtered[ $key ] ) && is_scalar( $filtered[ $key ] ) ) {
					$fragments[ $key ] = (string) $filtered[ $key ];
				}
			}
		}

		$fragments['status'] = 'rendered' === sanitize_key( $fragments['status'] ) ? 'rendered' : 'unreadable';
		$fragments['preset'] = sanitize_key( $fragments['preset'] );
		$fragments['before'] = wp_kses( $fragments['before'], self::classicContextHtml() );
		$fragments['title']  = wp_kses( $fragments['title'], self::classicTitleHtml() );
		$fragments['after']  = wp_kses( $fragments['after'], self::classicContextHtml() );

		return $fragments;
	}

	/**
	 * Get one semantic value for compatibility helpers and shortcodes.
	 *
	 * @param string $field   Field name.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public function value( string $field, int $post_id = 0 ): string {
		$post_id = $post_id > 0 ? $post_id : (int) get_the_ID();
		if ( ! $this->canReadPost( $post_id ) ) {
			return '';
		}

		$context = $this->context( $post_id, $this->resolver->resolve( $post_id ) );
		$field   = sanitize_key( $field );

		return isset( $context[ $field ] ) && is_scalar( $context[ $field ] )
			? (string) $context[ $field ]
			: '';
	}

	/**
	 * Build the semantic context used by all output channels.
	 *
	 * Canonical subtitle metadata is authoritative even when deliberately
	 * empty. Legacy `_secondary_title` is read only when the canonical key has
	 * never existed, making pre-copy migration safe without creating two owners.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $resolved Template resolution.
	 * @return array<string,mixed>
	 */
	public function context( int $post_id, array $resolved = array() ): array {
		$title = (string) get_post_field( 'post_title', $post_id, 'display' );
		$title = (string) apply_filters( 'wptl_primary_title', $title, $post_id );

		if ( metadata_exists( 'post', $post_id, Schema::subtitleMeta() ) ) {
			$subtitle = (string) get_post_meta( $post_id, Schema::subtitleMeta(), true );
		} else {
			$subtitle = Schema::legacySubtitle( $post_id );
		}

		$series_term  = isset( $resolved['series_term'] ) && $resolved['series_term'] instanceof \WP_Term
			? $resolved['series_term']
			: $this->resolver->selectedSeries( $post_id );
		$series       = $series_term instanceof \WP_Term ? $series_term->name : '';
		$series_url   = $this->seriesArchiveUrl( $series_term );
		$series_short = $series_term instanceof \WP_Term
			? (string) get_term_meta( $series_term->term_id, Schema::shortLabelMeta(), true )
			: '';
		if ( '' === trim( $series_short ) ) {
			$series_short = $series;
		}
		$series_icon_id = $this->seriesIconId( $series_term );

		$mode      = $series_term instanceof \WP_Term
			? (string) get_term_meta( $series_term->term_id, Schema::seriesModeMeta(), true )
			: 'unordered';
		$structure = $series_term instanceof \WP_Term
			? (string) get_term_meta( $series_term->term_id, Schema::seriesStructureMeta(), true )
			: 'flat';
		$mode      = 'ordered' === $mode ? 'ordered' : 'unordered';
		$structure = 'seasoned' === $structure ? 'seasoned' : 'flat';

		$role          = $series_term instanceof \WP_Term ? (string) get_post_meta( $post_id, Schema::seriesRoleMeta(), true ) : '';
		$season_key    = 'seasoned' === $structure ? (string) get_post_meta( $post_id, Schema::seasonKeyMeta(), true ) : '';
		if ( $series_term instanceof \WP_Term && class_exists( '\\WPTitleLayer\\Core\\BookStructure' ) ) {
			$book_context = \WPTitleLayer\Core\BookStructure::context( $post_id, $series_term );
			if ( ! empty( $book_context['valid'] ) && \WPTitleLayer\Core\Schema::SCOPE_SERIES === (string) $book_context['scope'] ) {
				$season_key = '';
			}
		}
		$season        = $this->seasonLabel( $series_term, $season_key );
		$season_url    = $this->seasonArchiveUrl( $series_term, $season_key );
		$position      = 'ordered' === $mode ? (string) get_post_meta( $post_id, Schema::sequencePositionMeta(), true ) : '';
		if (
			'ordered' === $mode
			&& $series_term instanceof \WP_Term
			&& class_exists( '\\WPTitleLayer\\Core\\Sequence' )
			&& \WPTitleLayer\Core\Sequence::is_managed( $series_term )
		) {
			$ordinal  = \WPTitleLayer\Core\Sequence::automatic_ordinal( $post_id, $series_term );
			$position = 0 < $ordinal ? (string) $ordinal : '';
		}
		$sequence      = $series_term instanceof \WP_Term ? (string) get_post_meta( $post_id, Schema::sequenceLabelMeta(), true ) : '';
		$sequence_item = $this->sequenceItemDisplay( $position, $sequence, $role );
		$derived_label = $this->sequenceDisplay( $season, $sequence_item );

		$kicker_override = metadata_exists( 'post', $post_id, Schema::kickerOverrideMeta() )
			? (string) get_post_meta( $post_id, Schema::kickerOverrideMeta(), true )
			: '';
		$has_kicker_override = '' !== trim( $kicker_override );
		$kicker              = $has_kicker_override ? $kicker_override : $series_short;

		$eyebrow_parts = array_values(
			array_filter(
				array( $kicker, $derived_label ),
				static function ( string $value ): bool {
					return '' !== trim( $value );
				}
			)
		);

		$context = array(
			'title'           => $this->plainText( $title ),
			'subtitle'        => $this->plainText( $subtitle ),
			'series'          => $this->plainText( $series ),
			'series_short'    => $this->plainText( $series_short ),
			'series_url'      => $series_url,
			'series_icon_id'  => $series_icon_id,
			'season'          => $this->plainText( $season ),
			'season_key'      => $this->plainText( $season_key ),
			'season_url'      => $season_url,
			'position'        => $this->plainText( $position ),
			'sequence_label'  => $this->plainText( $sequence ),
			'sequence_item'   => $this->plainText( $sequence_item ),
			'sequence_display'=> $this->plainText( $derived_label ),
			'role'            => $this->plainText( $role ),
			'kicker'          => $this->plainText( $kicker ),
			'kicker_source'   => $has_kicker_override ? 'override' : ( $series_term instanceof \WP_Term ? 'series' : 'none' ),
			'eyebrow'         => $this->plainText( implode( ' · ', $eyebrow_parts ) ),
			'series_mode'     => $mode,
			'series_structure'=> $structure,
		);

		/**
		 * Filter semantic title context before escaping and interpolation.
		 *
		 * @param array<string,mixed> $context Context values.
		 * @param int                 $post_id Post ID.
		 */
		$filtered = apply_filters( 'wptl_title_context', $context, $post_id );
		if ( is_array( $filtered ) ) {
			foreach ( $context as $key => $value ) {
				if ( isset( $filtered[ $key ] ) && is_scalar( $filtered[ $key ] ) ) {
					$context[ $key ] = in_array( $key, array( 'series_url', 'season_url' ), true )
						? esc_url_raw( (string) $filtered[ $key ], array( 'http', 'https' ) )
						: $this->plainText( (string) $filtered[ $key ] );
				}
			}
		}

		return $context;
	}

	/**
	 * @param string                   $template Preset markup.
	 * @param array<string,mixed>      $context Semantic values.
	 * @param array<string,string>|null $recipe Built-in structured recipe.
	 * @return string
	 */
	private function interpolate( string $template, array $context, ?array $recipe = null ): string {
		$escaped = array();
		foreach ( $context as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$escaped[ '{{' . $key . '}}' ] = esc_html( (string) $value );
			}
		}

		$title         = (string) ( $context['title'] ?? '' );
		$subtitle      = (string) ( $context['subtitle'] ?? '' );
		$kicker        = (string) ( $context['kicker'] ?? '' );
		$eyebrow       = (string) ( $context['eyebrow'] ?? '' );
		$kicker_html   = $this->seriesContextHtml( $context, false );
		$eyebrow_html  = $this->seriesContextHtml( $context, true );
		$title_content = '<span class="wptl-title">' . esc_html( $title ) . '</span>';

		if ( null === $recipe ) {
			// Preserve the original token contract for code-owned presets.
			$subtitle_inline = '' !== $subtitle
				? '<span class="wptl-title-separator">' . esc_html( Presets::separatorText( Presets::defaultRecipe( 'inline' ) ) ) . '</span><span class="wptl-subtitle">' . esc_html( $subtitle ) . '</span>'
				: '';
			$blocks = array(
				'{{title_content}}'   => $title_content,
				'{{subtitle_block}}'  => '' !== $subtitle ? '<p class="wptl-subtitle">' . esc_html( $subtitle ) . '</p>' : '',
				'{{subtitle_inline}}' => $subtitle_inline,
				'{{kicker_block}}'   => '' !== $kicker ? '<p class="wptl-kicker">' . $kicker_html . '</p>' : '',
				'{{eyebrow_block}}'  => '' !== $eyebrow ? '<p class="wptl-eyebrow">' . $eyebrow_html . '</p>' : '',
			);
		} else {
			$subtitle_position = (string) $recipe['subtitle_position'];
			$subtitle_inline   = '';
			if ( 'inline' === $subtitle_position && '' !== $subtitle ) {
				$subtitle_inline = '<span class="wptl-title-separator">' . esc_html( Presets::separatorText( $recipe ) ) . '</span><span class="wptl-subtitle">' . esc_html( $subtitle ) . '</span>';
			}
			$blocks = array(
				'{{title_content}}'   => $title_content,
				'{{subtitle_block}}'  => 'stacked' === $subtitle_position && '' !== $subtitle ? '<p class="wptl-subtitle">' . esc_html( $subtitle ) . '</p>' : '',
				'{{subtitle_inline}}' => $subtitle_inline,
				'{{kicker_block}}'   => '' !== $kicker ? '<p class="wptl-kicker">' . $kicker_html . '</p>' : '',
				'{{eyebrow_block}}'  => 'above' === $recipe['series_position'] && '' !== $eyebrow ? '<p class="wptl-eyebrow">' . $eyebrow_html . '</p>' : '',
			);
		}

		$html = strtr( $template, array_merge( $escaped, $blocks ) );
		// Unknown tokens are removed rather than leaked into the page.
		return (string) preg_replace( '/\{\{[a-z0-9_]+\}\}/i', '', $html );
	}

	/**
	 * Select the smallest semantic wrapper needed by a structured recipe.
	 * Standard, Editorial, and Minimal keep their 0.2.1 markup when possible.
	 */
	private function templateForRecipe( string $preset_id, string $template, ?array $recipe ): string {
		if ( null === $recipe ) {
			return $template;
		}

		$needs_row = 'inline' === $recipe['subtitle_position'] || 'inline' === $preset_id;
		$minimal_changed = 'minimal' === $preset_id
			&& ( 'above' === $recipe['series_position'] || 'hidden' !== $recipe['subtitle_position'] );
		if ( ! $needs_row && ! $minimal_changed ) {
			return $template;
		}

		$preset_class = sanitize_html_class( $preset_id );
		$layout_class = 'inline' === $recipe['subtitle_position'] ? ' wptl-subtitle-layout--inline' : '';
		return '<header class="wptl-title-layer wptl-title-layer--' . $preset_class . $layout_class . '">{{eyebrow_block}}<div class="wptl-title-row"><h1 class="wptl-title-heading">{{title_content}}</h1>{{subtitle_inline}}</div>{{subtitle_block}}</header>';
	}

	/**
	 * Render the visible Series context without ever placing sequence text in
	 * the archive link. A filtered eyebrow is treated as one custom plain-text
	 * value unless it still matches the canonical kicker/sequence composition.
	 *
	 * @param array<string,mixed> $context          Semantic title context.
	 * @param bool                $include_sequence Whether to render the eyebrow rather than only its kicker.
	 */
	private function seriesContextHtml( array $context, bool $include_sequence ): string {
		$kicker          = trim( (string) ( $context['kicker'] ?? '' ) );
		$series_short    = trim( (string) ( $context['series_short'] ?? '' ) );
		$kicker_source   = sanitize_key( (string) ( $context['kicker_source'] ?? '' ) );
		$season          = trim( (string) ( $context['season'] ?? '' ) );
		$season_url      = esc_url( (string) ( $context['season_url'] ?? '' ) );
		$sequence_item   = trim( (string) ( $context['sequence_item'] ?? '' ) );
		$eyebrow         = trim( (string) ( $context['eyebrow'] ?? '' ) );
		$series_icon_id  = absint( $context['series_icon_id'] ?? 0 );
		$canonical_parts = array_values(
			array_filter(
				array( $kicker, $season, $sequence_item ),
				static function ( string $value ): bool {
					return '' !== trim( $value );
				}
			)
		);
		$canonical = $this->plainText( implode( ' · ', $canonical_parts ) );

		if ( $include_sequence && $eyebrow !== $canonical ) {
			return esc_html( $eyebrow );
		}

		$label_html = esc_html( $kicker );
		$series_url = esc_url( (string) ( $context['series_url'] ?? '' ) );
		if ( 'series' === $kicker_source && $kicker === $series_short && '' !== $label_html ) {
			$label_html = $this->seriesIconHtml( $series_icon_id ) . $label_html;
			if ( '' !== $series_url ) {
				$label_html = '<a class="wptl-series-link" href="' . $series_url . '" rel="tag">' . $label_html . '</a>';
			} elseif ( 0 < $series_icon_id ) {
				$label_html = '<span class="wptl-series-label">' . $label_html . '</span>';
			}
		}

		if ( ! $include_sequence ) {
			return $label_html;
		}

		$parts = array();
		if ( '' !== $kicker ) {
			$parts[] = $label_html;
		}
		if ( '' !== $season ) {
			$season_html = esc_html( $season );
			if ( '' !== $season_url ) {
				$season_html = '<a class="wptl-season-link" href="' . $season_url . '" rel="tag">' . $season_html . '</a>';
			}
			$parts[] = $season_html;
		}
		if ( '' !== $sequence_item ) {
			$parts[] = esc_html( $sequence_item );
		}

		return implode( ' · ', $parts );
	}

	private function seriesIconId( ?\WP_Term $series_term ): int {
		if ( ! $series_term instanceof \WP_Term || ! SettingsPage::seriesIconEnabled( $series_term ) ) {
			return 0;
		}

		$core_series   = '\\WPTitleLayer\\Core\\Series';
		$attachment_id = class_exists( $core_series ) && is_callable( array( $core_series, 'icon_id' ) )
			? absint( call_user_func( array( $core_series, 'icon_id' ), $series_term ) )
			: absint( get_term_meta( $series_term->term_id, Schema::iconIdMeta(), true ) );

		return 0 < $attachment_id && 'attachment' === get_post_type( $attachment_id ) && wp_attachment_is_image( $attachment_id )
			? $attachment_id
			: 0;
	}

	private function seriesIconHtml( int $attachment_id ): string {
		if ( 1 > $attachment_id ) {
			return '';
		}

		$image = wp_get_attachment_image_src( $attachment_id, 'thumbnail' );
		if ( ! is_array( $image ) || empty( $image[0] ) ) {
			return '';
		}
		$image_url = esc_url_raw( (string) $image[0], array( 'http', 'https' ) );
		if ( '' === $image_url ) {
			return '';
		}

		$width  = isset( $image[1] ) ? max( 1, (int) $image[1] ) : 1;
		$height = isset( $image[2] ) ? max( 1, (int) $image[2] ) : 1;
		return '<span class="wptl-series-icon" aria-hidden="true"><img class="wptl-series-icon__image" src="' . esc_url( $image_url ) . '" alt="" width="' . esc_attr( (string) $width ) . '" height="' . esc_attr( (string) $height ) . '" decoding="async"></span>';
	}

	/** Return a Series archive URL only for a valid public taxonomy term. */
	private function seriesArchiveUrl( ?\WP_Term $series_term ): string {
		if ( ! $series_term instanceof \WP_Term ) {
			return '';
		}
		$core_series = '\\WPTitleLayer\\Core\\Series';
		if ( class_exists( $core_series ) && is_callable( array( $core_series, 'public_archive_url' ) ) ) {
			return (string) call_user_func( array( $core_series, 'public_archive_url' ), $series_term );
		}
		if ( '' === Schema::seriesTaxonomy() || Schema::seriesTaxonomy() !== $series_term->taxonomy ) {
			return '';
		}

		$taxonomy = get_taxonomy( $series_term->taxonomy );
		if ( ! $taxonomy instanceof \WP_Taxonomy || empty( $taxonomy->publicly_queryable ) ) {
			return '';
		}
		if ( function_exists( 'is_term_publicly_viewable' ) && ! is_term_publicly_viewable( $series_term ) ) {
			return '';
		}

		$url = get_term_link( $series_term );
		return is_wp_error( $url ) || ! is_string( $url ) ? '' : esc_url_raw( $url, array( 'http', 'https' ) );
	}

	/** Return a filtered archive URL only for a defined season. */
	private function seasonArchiveUrl( ?\WP_Term $series_term, string $season_key ): string {
		if ( ! $series_term instanceof \WP_Term || '' === sanitize_key( $season_key ) ) {
			return '';
		}

		$core_series = '\\WPTitleLayer\\Core\\Series';
		if ( class_exists( $core_series ) && is_callable( array( $core_series, 'public_season_archive_url' ) ) ) {
			return (string) call_user_func( array( $core_series, 'public_season_archive_url' ), $series_term, $season_key );
		}

		$defined = false;
		foreach ( (array) get_term_meta( $series_term->term_id, Schema::seasonsMeta(), true ) as $season ) {
			if ( is_array( $season ) && sanitize_key( (string) ( $season['key'] ?? '' ) ) === sanitize_key( $season_key ) ) {
				$defined = true;
				break;
			}
		}
		$series_url = $defined ? $this->seriesArchiveUrl( $series_term ) : '';
		return '' === $series_url
			? ''
			: esc_url_raw( add_query_arg( 'wptl_season', sanitize_key( $season_key ), $series_url ), array( 'http', 'https' ) );
	}

	private function sequenceItemDisplay( string $position, string $label, string $role ): string {
		if ( '' !== trim( $label ) ) {
			return $label;
		}

		if ( 'intro' === $role ) {
			return __( 'Introduction / preface', 'wp-title-layer' );
		}
		if ( 'epilogue' === $role ) {
			return __( 'Epilogue / afterword', 'wp-title-layer' );
		}
		if ( 'appendix' === $role ) {
			return __( 'Appendix', 'wp-title-layer' );
		}

		$position = trim( $position );
		if ( '' !== $position && ctype_digit( $position ) ) {
			$position = str_pad( $position, 2, '0', STR_PAD_LEFT );
		}

		return $position;
	}

	private function sequenceDisplay( string $season, string $sequence_item ): string {
		$parts = array_values(
			array_filter(
				array( trim( $season ), trim( $sequence_item ) ),
				static function ( string $value ): bool {
					return '' !== $value;
				}
			)
		);

		return implode( ' · ', $parts );
	}

	private function seasonLabel( ?\WP_Term $series_term, string $season_key ): string {
		if ( '' === trim( $season_key ) || ! $series_term instanceof \WP_Term ) {
			return $season_key;
		}

		$definitions = get_term_meta( $series_term->term_id, Schema::seasonsMeta(), true );
		foreach ( is_array( $definitions ) ? $definitions : array() as $season ) {
			if ( is_array( $season ) && $season_key === (string) ( $season['key'] ?? '' ) ) {
				$label = sanitize_text_field( (string) ( $season['label'] ?? '' ) );
				return '' !== $label ? $label : $season_key;
			}
		}

		return $season_key;
	}

	private function plainText( string $value ): string {
		return trim( wp_strip_all_tags( $value, true ) );
	}

	/**
	 * Prevent blocks and shortcodes from exposing non-public posts by ID.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function canReadPost( int $post_id ): bool {
		if ( $post_id < 1 ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		if ( post_password_required( $post ) ) {
			return false;
		}

		if ( function_exists( 'is_post_publicly_viewable' ) && is_post_publicly_viewable( $post ) ) {
			return true;
		}

		return current_user_can( 'read_post', $post_id );
	}

	private function replaceHeadingTag( string $html, string $tag ): string {
		$tag = strtolower( $tag );
		if ( ! in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'p' ), true ) || 'h1' === $tag ) {
			return $html;
		}

		$html = preg_replace( '/<h1\b/i', '<' . $tag, $html );
		$html = preg_replace( '/<\/h1>/i', '</' . $tag . '>', (string) $html );

		return (string) $html;
	}

	private function sanitizeClassList( string $classes ): string {
		$valid = array();
		foreach ( preg_split( '/\s+/', $classes ) ?: array() as $class_name ) {
			$class_name = sanitize_html_class( $class_name );
			if ( '' !== $class_name ) {
				$valid[] = $class_name;
			}
		}

		return implode( ' ', array_unique( $valid ) );
	}

	/**
	 * Apply a narrow set of sanitized attributes to the rendered root element.
	 *
	 * @param array<string,string> $attributes Root attributes.
	 */
	private function applyRootAttributes( string $html, array $attributes ): string {
		if ( ! class_exists( '\\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );
		if ( ! $processor->next_tag() ) {
			return $html;
		}

		$id = sanitize_html_class( (string) ( $attributes['id'] ?? '' ) );
		if ( '' !== $id ) {
			$processor->set_attribute( 'id', $id );
		}
		$style = safecss_filter_attr( (string) ( $attributes['style'] ?? '' ) );
		if ( '' !== $style ) {
			$processor->set_attribute( 'style', $style );
		}
		$aria_label = sanitize_text_field( (string) ( $attributes['aria-label'] ?? '' ) );
		if ( '' !== $aria_label ) {
			$processor->set_attribute( 'aria-label', $aria_label );
		}

		return $processor->get_updated_html();
	}

	/**
	 * Preserve a theme Post Title block's attributes on the actual heading,
	 * rather than moving its Global Styles contract onto the layer wrapper.
	 *
	 * @param array<string,string> $attributes Heading attributes.
	 */
	private function applyTitleAttributes( string $html, array $attributes ): string {
		if ( ! class_exists( '\\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );
		if ( ! $processor->next_tag( array( 'class_name' => 'wptl-title-heading' ) ) ) {
			return $html;
		}
		$tag = strtolower( (string) $processor->get_tag() );
		if ( ! in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p' ), true ) ) {
			return $html;
		}

		$classes = $this->sanitizeClassList(
			trim( (string) $processor->get_attribute( 'class' ) . ' ' . (string) ( $attributes['class'] ?? '' ) )
		);
		if ( '' !== $classes ) {
			$processor->set_attribute( 'class', $classes );
		}

		$id = sanitize_html_class( (string) ( $attributes['id'] ?? '' ) );
		if ( '' !== $id ) {
			$processor->set_attribute( 'id', $id );
		}
		$style = safecss_filter_attr( (string) ( $attributes['style'] ?? '' ) );
		if ( '' !== $style ) {
			$processor->set_attribute( 'style', $style );
		}
		$aria_label = sanitize_text_field( (string) ( $attributes['aria-label'] ?? '' ) );
		if ( '' !== $aria_label ) {
			$processor->set_attribute( 'aria-label', $aria_label );
		}

		return $processor->get_updated_html();
	}

	/**
	 * Strict allow-list for rendered templates and extension output.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function allowedHtml(): array {
		$attributes = array(
			'class'       => true,
			'id'          => true,
			'style'       => true,
			'aria-label'  => true,
			'aria-hidden' => true,
			'role'        => true,
		);
		$allowed = array(
			'header' => $attributes,
			'div'    => $attributes,
			'h1'     => $attributes,
			'h2'     => $attributes,
			'h3'     => $attributes,
			'h4'     => $attributes,
			'h5'     => $attributes,
			'h6'     => $attributes,
			'p'      => $attributes,
			'span'   => $attributes,
			'small'  => $attributes,
			'strong' => $attributes,
			'em'     => $attributes,
			'br'     => array(),
			'a'      => array_merge(
				$attributes,
				array(
					'href'   => true,
					'rel'    => true,
					'target' => true,
				)
			),
			'img'    => array(
				'class'    => true,
				'src'      => true,
				'alt'      => true,
				'width'    => true,
				'height'   => true,
				'decoding' => true,
			),
		);

		/** @param array<string,array<string,bool>> $allowed Allowed HTML. */
		$filtered = apply_filters( 'wptl_allowed_presentation_html', $allowed );

		return is_array( $filtered ) ? $filtered : $allowed;
	}

	/** Phrasing-only allow-list for content placed inside a theme heading. */
	private static function classicTitleHtml(): array {
		return array(
			'span'   => array( 'class' => true, 'aria-hidden' => true ),
			'small'  => array( 'class' => true ),
			'strong' => array( 'class' => true ),
			'em'     => array( 'class' => true ),
			'br'     => array(),
		);
	}

	/** Narrow block-level allow-list for context adjacent to a theme heading. */
	private static function classicContextHtml(): array {
		return array_merge(
			self::classicTitleHtml(),
			array(
				'p' => array( 'class' => true, 'aria-label' => true ),
				'a' => array(
					'class'      => true,
					'href'       => true,
					'rel'        => true,
					'aria-label' => true,
				),
				'img' => array(
					'class'    => true,
					'src'      => true,
					'alt'      => true,
					'width'    => true,
					'height'   => true,
					'decoding' => true,
				),
			)
		);
	}
}
