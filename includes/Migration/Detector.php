<?php
/**
 * Detect legacy plugin state, orphaned metadata and ACF subtitle fields.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Migration;

final class Detector {
	/**
	 * Return a read-only environment report.
	 */
	public function detect(): array {
		$plugins = $this->detect_secondary_title_plugins();
		$legacy  = $this->detect_legacy_data();
		$acf     = $this->detect_acf();

		$active = false;
		foreach ( $plugins as $plugin ) {
			if ( ! empty( $plugin['active'] ) || ! empty( $plugin['network_active'] ) ) {
				$active = true;
				break;
			}
		}

		return [
			'secondary_title' => [
				'installed' => ! empty( $plugins ),
				'active'    => $active,
				'plugins'   => $plugins,
				'options'   => $this->detect_legacy_options(),
			],
			'legacy_data' => $legacy,
			'acf'         => $acf,
			'orphaned_legacy_data' => ! $active && $legacy['candidate_posts'] > 0,
		];
	}

	private function detect_secondary_title_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) ) {
			$plugin_file = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( is_readable( $plugin_file ) ) {
				require_once $plugin_file;
			}
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			return [];
		}

		$matches = [];
		foreach ( get_plugins() as $basename => $headers ) {
			$text_domain = isset( $headers['TextDomain'] ) ? (string) $headers['TextDomain'] : '';
			$name        = isset( $headers['Name'] ) ? (string) $headers['Name'] : '';
			$uri         = isset( $headers['PluginURI'] ) ? (string) $headers['PluginURI'] : '';
			$is_match    = 'secondary-title/secondary-title.php' === $basename
				|| 'secondary-title' === $text_domain
				|| ( 'Secondary Title' === $name && false !== stripos( $uri, 'secondary-title' ) );

			if ( ! $is_match ) {
				continue;
			}

			$matches[] = [
				'basename'       => $basename,
				'name'           => $name,
				'version'        => isset( $headers['Version'] ) ? (string) $headers['Version'] : '',
				'text_domain'    => $text_domain,
				'active'         => function_exists( 'is_plugin_active' ) && is_plugin_active( $basename ),
				'network_active' => is_multisite() && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $basename ),
			];
		}

		return $matches;
	}

	private function detect_legacy_options(): array {
		$found    = [];
		$sentinel = '__wptl_missing_' . Config::new_run_id();

		foreach ( Config::LEGACY_OPTIONS as $option ) {
			$value = get_option( $option, $sentinel );
			if ( $sentinel !== $value ) {
				$found[ $option ] = [
					'type'  => gettype( $value ),
					'empty' => empty( $value ),
				];
			}
		}

		return [
			'count' => count( $found ),
			'keys'  => $found,
		];
	}

	private function detect_legacy_data(): array {
		global $wpdb;

		$key = Config::LEGACY_META;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total_rows,
					COUNT(DISTINCT post_id) AS total_posts,
					COUNT(DISTINCT CASE WHEN meta_value <> '' AND LEFT(meta_value, 6) <> 'field_' THEN post_id END) AS candidate_posts,
					SUM(CASE WHEN LEFT(meta_value, 6) = 'field_' THEN 1 ELSE 0 END) AS acf_reference_rows
				FROM {$wpdb->postmeta}
				WHERE meta_key = %s",
				$key
			),
			ARRAY_A
		);

		$orphan_rows = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->postmeta} pm
				LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND p.ID IS NULL",
				$key
			)
		);

		return [
			'total_rows'        => isset( $row['total_rows'] ) ? (int) $row['total_rows'] : 0,
			'total_posts'       => isset( $row['total_posts'] ) ? (int) $row['total_posts'] : 0,
			'candidate_posts'   => isset( $row['candidate_posts'] ) ? (int) $row['candidate_posts'] : 0,
			'acf_reference_rows'=> isset( $row['acf_reference_rows'] ) ? (int) $row['acf_reference_rows'] : 0,
			'orphan_meta_rows'  => $orphan_rows,
		];
	}

	private function detect_acf(): array {
		global $wpdb;

		$watched_names = array_merge( [ Config::ACF_META, 'secondary_title' ], Config::ACF_SERIES_FIELDS );
		$definitions = $this->acf_api_definitions();
		$placeholders = implode( ', ', array_fill( 0, count( $watched_names ), '%s' ) );
		$db_fields   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title AS label, post_excerpt AS name, post_name AS field_key
			FROM {$wpdb->posts}
			WHERE post_type = 'acf-field'
				AND post_status NOT IN ('trash', 'auto-draft')
				AND post_excerpt IN ({$placeholders})",
				$watched_names
			),
			ARRAY_A
		);

		foreach ( (array) $db_fields as $field ) {
			$definitions[ $field['field_key'] ?: 'post_' . $field['ID'] ] = [
				'key'    => (string) $field['field_key'],
				'name'   => (string) $field['name'],
				'label'  => (string) $field['label'],
				'origin' => 'database',
			];
		}

		$acf_subtitle_posts = $this->count_verified_acf_values( Config::ACF_META, $definitions );
		$secondary_title_collision_posts = $this->count_verified_acf_values( 'secondary_title', $definitions );
		$series_fields = [];
		foreach ( Config::ACF_SERIES_FIELDS as $field_name ) {
			$field_definitions = array_filter(
				$definitions,
				static function ( array $definition ) use ( $field_name ): bool {
					return $field_name === $definition['name'];
				}
			);
			$series_fields[ $field_name ] = [
				'definition_count'    => count( $field_definitions ),
				'value_posts'         => $this->count_raw_meta_values( $field_name ),
				'verified_value_posts'=> $this->count_verified_acf_values( $field_name, $definitions ),
			];
		}

		return [
			'active' => function_exists( 'acf_get_field_groups' ) || class_exists( 'ACF' ),
			'field_definitions' => array_values( $definitions ),
			'acf_subtitle_posts' => $acf_subtitle_posts,
			'secondary_title_key_collision_posts' => $secondary_title_collision_posts,
			'series_value_posts' => $this->count_series_value_posts(),
			'series_fields' => $series_fields,
		];
	}

	private function acf_api_definitions(): array {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return [];
		}

		$found = [];
		foreach ( (array) acf_get_field_groups() as $group ) {
			$fields = acf_get_fields( $group );
			$this->collect_acf_fields( (array) $fields, $found );
		}

		return $found;
	}

	private function collect_acf_fields( array $fields, array &$found ): void {
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$name = isset( $field['name'] ) ? (string) $field['name'] : '';
			$key  = isset( $field['key'] ) ? (string) $field['key'] : '';
			if ( in_array( $name, array_merge( [ Config::ACF_META, 'secondary_title' ], Config::ACF_SERIES_FIELDS ), true ) ) {
				$found[ $key ?: md5( serialize( $field ) ) ] = [
					'key'    => $key,
					'name'   => $name,
					'label'  => isset( $field['label'] ) ? (string) $field['label'] : '',
					'origin' => 'api',
				];
			}

			if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
				$this->collect_acf_fields( $field['sub_fields'], $found );
			}

			if ( ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
				foreach ( $field['layouts'] as $layout ) {
					if ( ! empty( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
						$this->collect_acf_fields( $layout['sub_fields'], $found );
					}
				}
			}
		}
	}

	private function count_raw_meta_values( string $field_name ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''",
				$field_name
			)
		);
	}

	/** Count unique posts with any stored non-empty value in the five fields. */
	private function count_series_value_posts(): int {
		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( Config::ACF_SERIES_FIELDS ), '%s' ) );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id)
				FROM {$wpdb->postmeta}
				WHERE meta_key IN ({$placeholders}) AND meta_value <> ''",
				Config::ACF_SERIES_FIELDS
			)
		);
	}

	private function count_verified_acf_values( string $field_name, array $definitions ): int {
		global $wpdb;

		$field_keys = [];
		foreach ( $definitions as $definition ) {
			if ( $field_name === (string) ( $definition['name'] ?? '' ) && Config::is_acf_field_key( $definition['key'] ?? '' ) ) {
				$field_keys[] = (string) $definition['key'];
			}
		}
		$field_keys = array_values( array_unique( $field_keys ) );
		if ( empty( $field_keys ) ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $field_keys ), '%s' ) );
		$sql = "SELECT COUNT(DISTINCT value_meta.post_id)
			FROM {$wpdb->postmeta} value_meta
			INNER JOIN {$wpdb->postmeta} ref_meta
				ON ref_meta.post_id = value_meta.post_id
				AND ref_meta.meta_key = %s
				AND ref_meta.meta_value IN ({$placeholders})
			WHERE value_meta.meta_key = %s AND value_meta.meta_value <> ''";
		$args = array_merge( [ '_' . $field_name ], $field_keys, [ $field_name ] );

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}
}
