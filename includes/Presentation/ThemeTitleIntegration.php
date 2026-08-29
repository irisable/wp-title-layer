<?php
/**
 * Safe, opt-in replacement of a theme's singular title slot.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Presentation;

defined( 'ABSPATH' ) || exit;

/**
 * Integrates with title slots whose exact rendering contract is known.
 *
 * Block themes expose core/post-title directly. Classic themes do not have a
 * universal title-region hook, so the only bundled classic adapter is Kadence.
 */
final class ThemeTitleIntegration {
	public const MODE_REPLACE = 'replace-theme-title';
	private const QUERY_LOOP_MARKER = '__wptl_query_loop';

	/** @var Renderer */
	private $renderer;

	/** @var bool */
	private $rendering = false;

	/** @var int */
	private $kadence_post_id = 0;

	/** @var array<string,string> */
	private $kadence_fragments = array();

	/** @var bool */
	private $kadence_replaced = false;

	/** @var array<string,bool> */
	private static $theme_slots_rendered = array();

	public function __construct( ?Renderer $renderer = null ) {
		$this->renderer = $renderer ?: new Renderer();
	}

	public function register(): void {
		add_filter( 'render_block_data', array( $this, 'markQueryLoopDescendant' ), 20, 3 );
		add_filter( 'render_block_core/post-title', array( $this, 'replaceBlockTitle' ), 20, 3 );

		// Kadence 1.x calls these actions immediately around its singular H1.
		// The the_title filter remains inert unless the exact before action arms it.
		add_action( 'kadence_single_before_entry_title', array( $this, 'beginKadenceTitle' ), PHP_INT_MAX );
		add_filter( 'the_title', array( $this, 'filterKadenceTitle' ), 99, 2 );
		add_action( 'kadence_single_after_entry_title', array( $this, 'finishKadenceTitle' ), 1 );
	}

	/**
	 * Propagate the core/post-template boundary through its rebuilt core/null
	 * block tree. WordPress can drop queryId from descendant context, but this
	 * parent relationship remains explicit and does not require request state.
	 */
	public function markQueryLoopDescendant( $parsed_block, $source_block = array(), $parent_block = null ) {
		if ( ! is_array( $parsed_block ) || ! $parent_block instanceof \WP_Block ) {
			return $parsed_block;
		}

		$parent_marked = ! empty( $parent_block->parsed_block['attrs'][ self::QUERY_LOOP_MARKER ] );
		if ( 'core/null' === $parent_block->name || $parent_marked ) {
			$parsed_block['attrs'] = isset( $parsed_block['attrs'] ) && is_array( $parsed_block['attrs'] )
				? $parsed_block['attrs']
				: array();
			$parsed_block['attrs'][ self::QUERY_LOOP_MARKER ] = true;
		}

		return $parsed_block;
	}

	/**
	 * Replace the queried singular core/post-title block in place.
	 *
	 * @param mixed          $block_content Rendered Post Title block.
	 * @param array<mixed>   $parsed_block  Parsed block.
	 * @param \WP_Block|null $instance      Runtime block instance.
	 */
	public function replaceBlockTitle( $block_content, $parsed_block, $instance = null ): string {
		$original = is_string( $block_content ) ? $block_content : (string) $block_content;
		if (
			$this->rendering
			|| doing_filter( 'the_content' )
			|| doing_filter( 'get_the_excerpt' )
			|| doing_filter( 'the_excerpt' )
			|| ! $instance instanceof \WP_Block
			|| ! self::isBlockTheme()
		) {
			return $original;
		}

		$context = is_array( $instance->context ) ? $instance->context : array();
		$post_id = absint( $context['postId'] ?? 0 );
		$query_id = isset( $context['queryId'] ) ? (string) $context['queryId'] : '';
		if ( ( '' !== $query_id && '0' !== $query_id ) || ! $this->isEligible( $post_id ) ) {
			return $original;
		}
		$post = get_post( $post_id );
		if ( isset( $context['postType'] ) && $post instanceof \WP_Post && sanitize_key( (string) $context['postType'] ) !== $post->post_type ) {
			return $original;
		}

		$attrs = is_array( $parsed_block ) && isset( $parsed_block['attrs'] ) && is_array( $parsed_block['attrs'] )
			? $parsed_block['attrs']
			: array();
		if ( ! empty( $attrs[ self::QUERY_LOOP_MARKER ] ) || ! empty( $attrs['isLink'] ) ) {
			return $original;
		}
		// A manually rendered full layer placed before this slot owns the title.
		if ( AutoDisplay::hasFullLayerPlacement( $post_id ) ) {
			// Do not remove a verified theme slot merely because another complete
			// layer rendered earlier. Manual authors must remove that placement when
			// switching to takeover; preserving the native slot is fail-safe.
			return $original;
		}

		$root = $this->blockRootAttributes( $original );
		if ( empty( $root['heading_tag'] ) ) {
			return $original;
		}

		$this->rendering = true;
		try {
			$replacement = $this->renderer->render(
				$post_id,
				array(
					'heading_tag'    => $root['heading_tag'],
					'class_name'      => 'wptl-theme-title-replacement',
					'title_class_name'=> (string) ( $root['class'] ?? '' ),
					'title_id'        => (string) ( $root['id'] ?? '' ),
					'title_style'     => (string) ( $root['style'] ?? '' ),
					'title_aria_label'=> (string) ( $root['aria-label'] ?? '' ),
				)
			);
		} catch ( \Throwable $error ) {
			$replacement = '';
		} finally {
			$this->rendering = false;
		}

		if ( '' === trim( $replacement ) || ! $this->isValidBlockReplacement( $replacement, $root, $post_id ) ) {
			return $original;
		}

		self::markThemeSlotRendered( $post_id );
		return $replacement;
	}

	/** Arm the exact next Kadence singular title call. */
	public function beginKadenceTitle(): void {
		$this->resetKadence();
		if ( $this->rendering || ! self::isKadenceTheme() ) {
			return;
		}

		$post_id = (int) get_queried_object_id();
		if ( ! $this->isEligible( $post_id ) ) {
			return;
		}
		if ( AutoDisplay::hasFullLayerPlacement( $post_id ) ) {
			return;
		}

		try {
			$fragments = $this->renderer->themeTitleFragments( $post_id );
		} catch ( \Throwable $error ) {
			$this->resetKadence();
			return;
		}
		if ( 'rendered' !== ( $fragments['status'] ?? '' ) || empty( $fragments['title'] ) ) {
			return;
		}

		$this->kadence_post_id  = $post_id;
		$this->kadence_fragments = $fragments;
	}

	/**
	 * Replace only the title text call armed by Kadence's exact before hook.
	 * No complete Title Layer HTML is ever returned through the global hook.
	 *
	 * @param mixed $title   Normal title text.
	 * @param mixed $post_id Post ID.
	 */
	public function filterKadenceTitle( $title, $post_id = 0 ): string {
		$original = is_string( $title ) ? $title : (string) $title;
		$post_id  = absint( $post_id );
		if ( doing_action( 'kadence_single_before_entry_title' ) ) {
			return $original;
		}
		if (
			$this->rendering
			|| $post_id < 1
			|| $post_id !== $this->kadence_post_id
			|| empty( $this->kadence_fragments['title'] )
		) {
			return $original;
		}

		$this->rendering = true;
		try {
			$before = (string) ( $this->kadence_fragments['before'] ?? '' );
			if ( '' !== $before ) {
				echo $before; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer applied the final allow-list.
			}
			$this->kadence_replaced = true;
			self::markThemeSlotRendered( $post_id );
			// Consume the global title arm immediately. The exact Kadence after hook
			// may still output the already-sanitized subtitle fragment.
			$this->kadence_post_id = 0;
			return (string) $this->kadence_fragments['title'];
		} catch ( \Throwable $error ) {
			$this->kadence_replaced = false;
			return $original;
		} finally {
			$this->rendering = false;
		}
	}

	/** Output the safe after-title fragment only after the exact title matched. */
	public function finishKadenceTitle(): void {
		if ( $this->kadence_replaced && ! empty( $this->kadence_fragments['after'] ) ) {
			echo $this->kadence_fragments['after']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer applied the final allow-list.
		}
		$this->resetKadence();
	}

	/**
	 * Whether a front-end full layer should yield to an already replaced title.
	 */
	public static function wasReplaced( int $post_id ): bool {
		return isset( self::$theme_slots_rendered[ self::requestKey( $post_id ) ] );
	}

	/**
	 * Whether a complete manual layer should yield to the verified theme slot.
	 * This makes placement order irrelevant: the title slot owns takeover mode.
	 */
	public static function ownsFullLayerPlacement( int $post_id ): bool {
		return ( new self() )->isEligible( $post_id );
	}

	/**
	 * Describe whether the active theme has a verified takeover adapter.
	 *
	 * @return array{supported:bool,adapter:string,label:string}
	 */
	public static function support(): array {
		if ( self::isBlockTheme() ) {
			$support = array(
				'supported' => true,
				'adapter'   => 'block-theme',
				'label'     => __( 'Block theme Post Title replacement', 'wp-title-layer' ),
			);
		} elseif ( self::isKadenceTheme() && self::isVerifiedKadenceVersion() && self::hasVerifiedKadenceTemplates() ) {
			$support = array(
				'supported' => true,
				'adapter'   => 'kadence',
				'label'     => __( 'Kadence title-area integration', 'wp-title-layer' ),
			);
		} else {
			$support = array(
				'supported' => false,
				'adapter'   => 'manual',
				'label'     => __( 'Manual theme integration required', 'wp-title-layer' ),
			);
		}

		/** @param array{supported:bool,adapter:string,label:string} $support Theme support. */
		$filtered = apply_filters( 'wptl_theme_title_support', $support );
		if ( ! is_array( $filtered ) ) {
			return $support;
		}

		return array(
			'supported' => ! empty( $filtered['supported'] ),
			'adapter'   => sanitize_key( (string) ( $filtered['adapter'] ?? $support['adapter'] ) ),
			'label'     => sanitize_text_field( (string) ( $filtered['label'] ?? $support['label'] ) ),
		);
	}

	/**
	 * Whether takeover is configured and safe for this exact front-end request.
	 */
	public function isEligible( int $post_id ): bool {
		$settings = SettingsPage::presentationSettings();
		if ( self::MODE_REPLACE !== $settings['display_mode'] || empty( self::support()['supported'] ) || $post_id < 1 ) {
			return false;
		}

		if (
			is_admin()
			|| is_feed()
			|| ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
			|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() )
			|| ( function_exists( 'is_embed' ) && is_embed() )
			|| ! is_singular()
			|| (int) get_queried_object_id() !== $post_id
		) {
			return false;
		}

		$post = get_post( $post_id );
		if (
			! $post instanceof \WP_Post
			|| 'publish' !== $post->post_status
			|| '' !== (string) $post->post_password
			|| ! is_post_publicly_viewable( $post )
			|| ! in_array( $post->post_type, $settings['auto_post_types'], true )
		) {
			return false;
		}

		return $this->hasLayerData( $post_id );
	}

	private function hasLayerData( int $post_id ): bool {
		if ( metadata_exists( 'post', $post_id, Schema::subtitleMeta() ) ) {
			if ( '' !== trim( (string) get_post_meta( $post_id, Schema::subtitleMeta(), true ) ) ) {
				return true;
			}
		} elseif ( '' !== trim( Schema::legacySubtitle( $post_id ) ) ) {
			return true;
		}

		if ( '' !== trim( (string) get_post_meta( $post_id, Schema::kickerOverrideMeta(), true ) ) ) {
			return true;
		}

		$terms = wp_get_object_terms( $post_id, Schema::seriesTaxonomy(), array( 'fields' => 'ids' ) );
		return ! is_wp_error( $terms ) && ! empty( $terms );
	}

	/** @return array<string,string> */
	private function blockRootAttributes( string $html ): array {
		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return array();
		}

		$processor = new \WP_HTML_Tag_Processor( $html );
		if ( ! $processor->next_tag() ) {
			return array();
		}
		$tag = strtolower( (string) $processor->get_tag() );
		if ( ! in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p' ), true ) ) {
			return array();
		}

		return array(
			'heading_tag' => $tag,
			'class'       => $this->sanitizeClasses( (string) $processor->get_attribute( 'class' ) ),
			'id'          => sanitize_html_class( (string) $processor->get_attribute( 'id' ) ),
			'style'       => safecss_filter_attr( (string) $processor->get_attribute( 'style' ) ),
			'aria-label'  => sanitize_text_field( (string) $processor->get_attribute( 'aria-label' ) ),
		);
	}

	private function sanitizeClasses( string $classes ): string {
		$clean = array();
		foreach ( preg_split( '/\s+/', $classes ) ?: array() as $class_name ) {
			$class_name = sanitize_html_class( $class_name );
			if ( '' !== $class_name ) {
				$clean[] = $class_name;
			}
		}
		return implode( ' ', array_unique( $clean ) );
	}

	/**
	 * Require an extension-filtered replacement to preserve the verified title
	 * slot contract. Non-empty HTML alone is not enough to remove a theme title.
	 *
	 * @param array<string,string> $root Sanitized attributes of the native slot.
	 */
	private function isValidBlockReplacement( string $html, array $root, int $post_id ): bool {
		if ( ! class_exists( '\\WP_HTML_Tag_Processor' ) ) {
			return false;
		}

		$expected_tag = strtolower( (string) ( $root['heading_tag'] ?? '' ) );
		$processor    = new \WP_HTML_Tag_Processor( $html );
		if ( ! $processor->next_tag( array( 'class_name' => 'wptl-title-heading' ) ) ) {
			return false;
		}
		if ( $expected_tag !== strtolower( (string) $processor->get_tag() ) ) {
			return false;
		}

		$expected_id = (string) ( $root['id'] ?? '' );
		if ( '' !== $expected_id && $expected_id !== (string) $processor->get_attribute( 'id' ) ) {
			return false;
		}
		foreach ( preg_split( '/\s+/', (string) ( $root['class'] ?? '' ) ) ?: array() as $class_name ) {
			if ( '' !== $class_name && ! $processor->has_class( $class_name ) ) {
				return false;
			}
		}
		if ( $processor->next_tag( array( 'class_name' => 'wptl-title-heading' ) ) ) {
			return false;
		}

		$heading_count = 0;
		$structure     = new \WP_HTML_Tag_Processor( $html );
		while ( $structure->next_tag() ) {
			if ( in_array( strtolower( (string) $structure->get_tag() ), array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
				++$heading_count;
			}
		}
		if ( ( 'p' === $expected_tag && 0 !== $heading_count ) || ( 'p' !== $expected_tag && 1 !== $heading_count ) ) {
			return false;
		}

		$expected_title = $this->normalizedText( $this->renderer->value( 'title', $post_id ) );
		return '' === $expected_title || false !== strpos( $this->normalizedText( $html ), $expected_title );
	}

	private function normalizedText( string $value ): string {
		$value = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
	}

	private function resetKadence(): void {
		$this->kadence_post_id  = 0;
		$this->kadence_fragments = array();
		$this->kadence_replaced = false;
	}

	private static function markThemeSlotRendered( int $post_id ): void {
		if ( $post_id > 0 ) {
			self::$theme_slots_rendered[ self::requestKey( $post_id ) ] = true;
		}
	}

	private static function requestKey( int $post_id ): string {
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		return $blog_id . ':' . max( 0, $post_id );
	}

	private static function isBlockTheme(): bool {
		return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
	}

	private static function isKadenceTheme(): bool {
		return function_exists( 'get_template' ) && 'kadence' === get_template();
	}

	private static function isVerifiedKadenceVersion(): bool {
		$version = defined( 'KADENCE_VERSION' ) ? (string) KADENCE_VERSION : '';
		return '' !== $version && version_compare( $version, '1.5.0', '>=' ) && version_compare( $version, '1.6.0', '<' );
	}

	private static function hasVerifiedKadenceTemplates(): bool {
		if ( function_exists( 'get_stylesheet' ) && get_stylesheet() !== get_template() ) {
			foreach ( array( 'template-parts/content/entry_title.php', 'template-parts/title/title.php' ) as $relative ) {
				$child_file = trailingslashit( get_stylesheet_directory() ) . $relative;
				if ( is_readable( $child_file ) ) {
					return false;
				}
			}
		}

		$base = function_exists( 'get_template_directory' ) ? trailingslashit( get_template_directory() ) : '';
		if ( '' === $base ) {
			return false;
		}
		foreach ( array( 'template-parts/content/entry_title.php', 'template-parts/title/title.php' ) as $relative ) {
			$file = $base . $relative;
			if ( ! is_readable( $file ) ) {
				return false;
			}
			$source = file_get_contents( $file );
			if (
				! is_string( $source )
				|| false === strpos( $source, "do_action( 'kadence_single_before_entry_title' );" )
				|| false === strpos( $source, "the_title( '<h1 class=\"entry-title\">', '</h1>' );" )
				|| false === strpos( $source, "do_action( 'kadence_single_after_entry_title' );" )
			) {
				return false;
			}
		}

		return true;
	}
}
