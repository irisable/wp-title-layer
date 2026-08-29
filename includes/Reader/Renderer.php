<?php
/**
 * Ordered Series navigation renderer.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Reader;

defined( 'ABSPATH' ) || exit;

final class Renderer {
	/** @var Navigation */
	private $navigation;

	public function __construct( ?Navigation $navigation = null ) {
		$this->navigation = $navigation ?: new Navigation();
	}

	public function render( int $post_id = 0 ): string {
		$post_id = 0 < $post_id ? $post_id : (int) get_the_ID();
		$context = $this->navigation->context( $post_id );
		if ( empty( $context['enabled'] ) || 1 > (int) $context['total'] ) {
			return '';
		}

		Assets::enqueue();
		$template = $this->templatePath();
		if ( '' === $template ) {
			return '';
		}

		$wptl_navigation = $context;
		ob_start();
		include $template;
		$html = (string) ob_get_clean();

		/**
		 * @param string              $html    Rendered navigation HTML.
		 * @param array<string,mixed> $context Navigation context.
		 */
		$html = (string) apply_filters( 'wptl_reader_navigation_html', $html, $context );

		return wp_kses( $html, self::allowedHtml() );
	}

	public function templatePath(): string {
		$default = trailingslashit( dirname( __DIR__, 2 ) ) . 'templates/series-navigation.php';

		/** @param string $default Default navigation template path. */
		$template = (string) apply_filters( 'wptl_reader_navigation_template', $default );

		return TemplatePaths::resolve( $template, $default );
	}

	/** @return array<string,array<string,bool>> */
	public static function allowedHtml(): array {
		return array(
			'nav'  => array( 'class' => true, 'aria-label' => true ),
			'div'  => array( 'class' => true ),
			'span' => array( 'class' => true, 'aria-current' => true ),
			'a'    => array( 'class' => true, 'href' => true, 'rel' => true ),
			'p'    => array( 'class' => true ),
		);
	}
}
