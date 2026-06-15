<?php
/**
 * Plugin Update Checker bootstrap for GitHub releases.
 *
 * @package Nera_DCMS_Age_Gate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register GitHub updates (PUC). Safe to call once per request; idempotent.
 *
 * @param string $plugin_file Absolute path to `nera-dcms-age-gate.php`.
 * @return void
 */
function nera_dcms_bootstrap_plugin_update_checker( $plugin_file ) {
	if ( defined( 'NERA_DCMS_UPDATE_CHECKER_BOOTSTRAPPED' ) ) {
		return;
	}

	if ( ! is_string( $plugin_file ) || ! is_readable( $plugin_file ) ) {
		return;
	}

	if ( defined( 'NERA_DCMS_DISABLE_GITHUB_UPDATES' ) && NERA_DCMS_DISABLE_GITHUB_UPDATES ) {
		define( 'NERA_DCMS_UPDATE_CHECKER_BOOTSTRAPPED', true );
		return;
	}

	$plugin_dir          = plugin_dir_path( $plugin_file );
	$github_repo_default = 'https://github.com/Nera-Marketing/nera-age-shield-plugin/';
	if ( defined( 'NERA_DCMS_GITHUB_REPO_URL' ) && is_string( NERA_DCMS_GITHUB_REPO_URL ) && NERA_DCMS_GITHUB_REPO_URL !== '' ) {
		$github_repo_default = NERA_DCMS_GITHUB_REPO_URL;
	}
	$github_repo = apply_filters( 'nera_dcms_github_repo_url', $github_repo_default );

	$puc_loader = $plugin_dir . 'lib/plugin-update-checker/load-v5p5.php';
	if ( ! is_readable( $puc_loader ) ) {
		return;
	}

	require_once $puc_loader;

	$update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		$github_repo,
		$plugin_file,
		'nera-dcms-age-gate',
		6
	);
	$update_checker->setBranch( 'main' );

	if ( defined( 'NERA_DCMS_GITHUB_TOKEN' ) && is_string( NERA_DCMS_GITHUB_TOKEN ) && NERA_DCMS_GITHUB_TOKEN !== '' ) {
		$update_checker->setAuthentication( NERA_DCMS_GITHUB_TOKEN );
	}

	$puc_vcs = $update_checker->getVcsApi();
	if ( $puc_vcs instanceof YahnisElsts\PluginUpdateChecker\v5p5\Vcs\GitHubApi ) {
		$puc_vcs->setReleaseFilter(
			static function ( $version_number, $release_object ) {
				unset( $version_number, $release_object );
				return true;
			},
			YahnisElsts\PluginUpdateChecker\v5p5\Vcs\Api::RELEASE_FILTER_SKIP_PRERELEASE,
			20
		);
		$puc_vcs->enableReleaseAssets();
	}

	add_filter(
		$update_checker->getUniqueName( 'vcs_update_detection_strategies' ),
		static function ( $strategies ) {
			if ( ! isset( $strategies['latest_tag'], $strategies['latest_release'] ) ) {
				return $strategies;
			}

			$ordered = array(
				'latest_tag'     => $strategies['latest_tag'],
				'latest_release' => $strategies['latest_release'],
			);
			foreach ( $strategies as $key => $callback ) {
				if ( isset( $ordered[ $key ] ) ) {
					continue;
				}
				$ordered[ $key ] = $callback;
			}
			return $ordered;
		},
		10,
		1
	);

	add_filter(
		$update_checker->getUniqueName( 'request_info_result' ),
		static function ( $info ) use ( $github_repo ) {
			if ( ! is_object( $info ) || empty( $info->version ) ) {
				return $info;
			}

			$version = preg_replace( '/^v/i', '', (string) $info->version );
			$tag     = 'v' . $version;
			$path    = (string) wp_parse_url( $github_repo, PHP_URL_PATH );
			$parts   = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) );
			if ( count( $parts ) >= 2 ) {
				$info->download_url = sprintf(
					'https://github.com/%s/%s/releases/download/%s/nera-dcms-age-gate-%s.zip',
					$parts[0],
					$parts[1],
					$tag,
					$version
				);
			}

			return $info;
		},
		10,
		1
	);

	define( 'NERA_DCMS_UPDATE_CHECKER_BOOTSTRAPPED', true );
}
