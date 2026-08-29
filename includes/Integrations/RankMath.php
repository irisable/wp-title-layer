<?php
/**
 * Rank Math variables for independently composed SEO and social titles.
 *
 * WP Title Layer deliberately does not overwrite Rank Math post metadata or
 * filter its final titles. Administrators opt in by placing these variables in
 * Rank Math's SEO and social title fields, where existing global and per-post
 * overrides remain authoritative.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Integrations;

use WPTitleLayer\Core\Series;
use WPTitleLayer\Presentation\Renderer;
use WPTitleLayer\Presentation\SettingsPage;

defined( 'ABSPATH' ) || exit;

final class RankMath {
	/** @var bool */
	private static $registered = false;

	/** @var Renderer|null */
	private static $renderer;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'rank_math/vars/register_extra_replacements', array( __CLASS__, 'registerVariables' ) );
		add_action( 'admin_init', array( __CLASS__, 'addSettingsSection' ), 30 );
	}

	public static function addSettingsSection(): void {
		add_settings_section(
			'wptl_rank_math_section',
			__( 'Search and sharing titles', 'wp-title-layer' ),
			array( __CLASS__, 'renderSectionIntro' ),
			SettingsPage::PAGE_SLUG
		);

		add_settings_field(
			'wptl_rank_math_variables',
			__( 'Rank Math variables', 'wp-title-layer' ),
			array( __CLASS__, 'renderVariables' ),
			SettingsPage::PAGE_SLUG,
			'wptl_rank_math_section'
		);
	}

	public static function renderSectionIntro(): void {
		echo '<p>' . esc_html__( 'The visible title, SEO title, and social-sharing title are separate channels. WP Title Layer never changes Rank Math metadata automatically.', 'wp-title-layer' ) . '</p>';
	}

	public static function renderVariables(): void {
		$detected = defined( 'RANK_MATH_VERSION' ) || function_exists( 'rank_math' ) || class_exists( '\\RankMath\\Helper' );
		?>
		<p>
			<strong><?php echo esc_html( $detected ? __( 'Rank Math detected.', 'wp-title-layer' ) : __( 'Rank Math is not currently detected.', 'wp-title-layer' ) ); ?></strong>
			<?php esc_html_e( 'When Rank Math is active, WP Title Layer registers these optional variables:', 'wp-title-layer' ); ?>
		</p>
		<ul class="ul-disc">
			<li><code>%wptl_subtitle%</code> — <?php esc_html_e( 'subtitle only', 'wp-title-layer' ); ?></li>
			<li><code>%wptl_series%</code> — <?php esc_html_e( 'full Series name', 'wp-title-layer' ); ?></li>
			<li><code>%wptl_title_with_subtitle%</code> — <?php esc_html_e( 'WordPress title plus a locale-appropriate separator and the subtitle only when one exists', 'wp-title-layer' ); ?></li>
		</ul>
		<p class="description">
			<?php esc_html_e( 'Use the combined variable in Rank Math’s SEO title, Facebook title, or Twitter title fields only where you want the subtitle included. For example: %wptl_title_with_subtitle% %sep% %sitename%. Leaving Rank Math unchanged keeps its current titles.', 'wp-title-layer' ); ?>
		</p>
		<?php
	}

	public static function registerVariables(): void {
		if ( ! function_exists( 'rank_math_register_var_replacement' ) ) {
			return;
		}

		\rank_math_register_var_replacement(
			'wptl_subtitle',
			array(
				'name'        => __( 'WP Title Layer subtitle', 'wp-title-layer' ),
				'description' => __( 'The canonical or legacy-compatible article subtitle.', 'wp-title-layer' ),
				'variable'    => 'wptl_subtitle',
				'example'     => __( 'Example subtitle', 'wp-title-layer' ),
				'nocache'     => true,
			),
			array( __CLASS__, 'subtitle' )
		);

		\rank_math_register_var_replacement(
			'wptl_series',
			array(
				'name'        => __( 'WP Title Layer Series', 'wp-title-layer' ),
				'description' => __( 'The full Series name for an article or Series archive.', 'wp-title-layer' ),
				'variable'    => 'wptl_series',
				'example'     => __( 'Example Series', 'wp-title-layer' ),
				'nocache'     => true,
			),
			array( __CLASS__, 'series' )
		);

		\rank_math_register_var_replacement(
			'wptl_title_with_subtitle',
			array(
				'name'        => __( 'WP Title Layer title with subtitle', 'wp-title-layer' ),
				'description' => __( 'The WordPress title plus a conditional subtitle, without a dangling separator.', 'wp-title-layer' ),
				'variable'    => 'wptl_title_with_subtitle',
				'example'     => __( 'Example title', 'wp-title-layer' ) . self::titleSubtitleSeparator() . __( 'Example subtitle', 'wp-title-layer' ),
				'nocache'     => true,
			),
			array( __CLASS__, 'titleWithSubtitle' )
		);
	}

	/**
	 * @param mixed $args Optional Rank Math callback arguments.
	 * @param mixed $post Optional post object.
	 */
	public static function subtitle( $args = array(), $post = null ): string {
		if ( self::queriedSeriesTerm() instanceof \WP_Term ) {
			return '';
		}

		$post_id = self::postId( $post );
		return $post_id > 0 ? self::renderer()->value( 'subtitle', $post_id ) : '';
	}

	/**
	 * @param mixed $args Optional Rank Math callback arguments.
	 * @param mixed $post Optional post object.
	 */
	public static function series( $args = array(), $post = null ): string {
		$term = self::queriedSeriesTerm();
		if ( $term instanceof \WP_Term ) {
			return trim( wp_strip_all_tags( (string) $term->name, true ) );
		}

		$post_id = self::candidatePostId( $post );
		if ( $post_id > 0 ) {
			return self::renderer()->value( 'series', $post_id );
		}

		$post_id = self::postId();
		return $post_id > 0 ? self::renderer()->value( 'series', $post_id ) : '';
	}

	/**
	 * @param mixed $args Optional Rank Math callback arguments.
	 * @param mixed $post Optional post object.
	 */
	public static function titleWithSubtitle( $args = array(), $post = null ): string {
		$term = self::queriedSeriesTerm();
		if ( $term instanceof \WP_Term ) {
			return trim( wp_strip_all_tags( (string) $term->name, true ) );
		}

		$post_id = self::postId( $post );
		if ( $post_id < 1 ) {
			return '';
		}

		$title    = trim( self::renderer()->value( 'title', $post_id ) );
		$subtitle = trim( self::renderer()->value( 'subtitle', $post_id ) );
		if ( '' === $subtitle ) {
			return $title;
		}
		if ( '' === $title ) {
			return $subtitle;
		}

		/**
		 * @param string $separator Plain-text separator between title and subtitle.
		 * @param int    $post_id   Current post ID.
		 */
		$separator = wp_kses( (string) apply_filters( 'wptl_rank_math_title_separator', self::titleSubtitleSeparator(), $post_id ), array() );
		$separator = (string) preg_replace( '/[\r\n\t]+/u', ' ', $separator );
		if ( '' === trim( $separator ) ) {
			$separator = self::titleSubtitleSeparator();
		}

		return $title . $separator . $subtitle;
	}

	/** Locale-aware plain-text separator for SEO and social title variables. */
	public static function titleSubtitleSeparator(): string {
		return _x( ': ', 'SEO title and subtitle separator', 'wp-title-layer' );
	}

	/** @param mixed $candidate Optional post object supplied by an integration. */
	private static function postId( $candidate = null ): int {
		$post_id = self::candidatePostId( $candidate );
		if ( $post_id > 0 ) {
			return $post_id;
		}

		$queried = get_queried_object();
		if ( $queried instanceof \WP_Post ) {
			return (int) $queried->ID;
		}

		global $post;
		return $post instanceof \WP_Post ? (int) $post->ID : absint( get_the_ID() );
	}

	/** @param mixed $candidate Optional explicit post supplied by an integration. */
	private static function candidatePostId( $candidate = null ): int {
		if ( $candidate instanceof \WP_Post ) {
			return (int) $candidate->ID;
		}
		if ( is_object( $candidate ) && isset( $candidate->ID ) ) {
			return absint( $candidate->ID );
		}
		if ( is_numeric( $candidate ) ) {
			return absint( $candidate );
		}
		return 0;
	}

	private static function queriedSeriesTerm(): ?\WP_Term {
		$term     = get_queried_object();
		$taxonomy = Series::claimed_taxonomy();
		if ( $term instanceof \WP_Term && '' !== $taxonomy && $taxonomy === $term->taxonomy ) {
			return $term;
		}

		return null;
	}

	private static function renderer(): Renderer {
		if ( ! self::$renderer instanceof Renderer ) {
			self::$renderer = new Renderer();
		}

		return self::$renderer;
	}
}
