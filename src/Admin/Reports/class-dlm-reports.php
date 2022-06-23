<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DLM_Reports' ) ) {

	/**
	 * DLM_Reports
	 *
	 * @since 4.6.0
	 */
	class DLM_Reports {

		/**
		 * Holds the class object.
		 *
		 * @since 4.6.0
		 *
		 * @var object
		 */
		public static $instance;

		/**
		 * DLM_Reports constructor.
		 *
		 * @since 4.6.0
		 */
		public function __construct() {
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'create_global_variable' ) );
			add_action( 'wp_ajax_dlm_update_report_setting', array( $this, 'save_reports_settings' ) );
		}

		/**
		 * Returns the singleton instance of the class.
		 *
		 * @return object The DLM_Reports object.
		 *
		 * @since 4.6.0
		 */
		public static function get_instance() {

			if ( ! isset( self::$instance ) && ! ( self::$instance instanceof DLM_Reports ) ) {
				self::$instance = new DLM_Reports();
			}

			return self::$instance;

		}

		/**
		 * Set our global variable dlmReportsStats so we can manipulate given data
		 *
		 * @since 4.6.0
		 */
		public function create_global_variable() {

			$rest_route_download_reports = rest_url() . 'download-monitor/v1/download_reports';
			$rest_route_user_reports     = rest_url() . 'download-monitor/v1/user_reports';
			$rest_route_user_data        = rest_url() . 'download-monitor/v1/user_data';
			// Let's add the global variable that will hold our reporst class and the routes
			wp_add_inline_script( 'dlm_reports', 'let dlmReportsInstance = {}; dlm_admin_url = "' . admin_url() . '" ; const dlmDownloadReportsAPI ="' . $rest_route_download_reports . '"; const dlmUserReportsAPI ="' . $rest_route_user_reports . '"; const dlmUserDataAPI ="' . $rest_route_user_data . '"; ', 'before' );
		}

		/**
		 * Register DLM Logs Routes
		 *
		 * @since 4.6.0
		 */
		public function register_routes() {

			// The REST route for downloads reports.
			register_rest_route(
				'download-monitor/v1',
				'/download_reports',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'rest_stats' ),
					'permission_callback' => '__return_true',
				)
			);

			// The REST route for user reports.
			register_rest_route(
				'download-monitor/v1',
				'/user_reports',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'user_reports_stats' ),
					'permission_callback' => '__return_true',
				)
			);

			// The REST route for users data.
			register_rest_route(
				'download-monitor/v1',
				'/user_data',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'user_data_stats' ),
					'permission_callback' => '__return_true',
				)
			);
		}

		/**
		 * Get our stats for the chart
		 *
		 * @return WP_REST_Response
		 * @throws Exception
		 * @since 4.6.0
		 */
		public function rest_stats() {

			return $this->respond( $this->report_stats() );
		}

		/**
		 * Get our stats for the user reports
		 *
		 * @return WP_REST_Response
		 * @since 4.6.0
		 */
		public function user_reports_stats() {

			return $this->respond( $this->get_user_reports() );
		}


		/**
		 * Get our user data
		 *
		 * @return WP_REST_Response
		 * @since 4.6.0
		 */
		public function user_data_stats() {

			return $this->respond( $this->get_user_data() );
		}

		/**
		 * Send our data
		 *
		 * @param $data JSON data received from report_stats.
		 *
		 * @return WP_REST_Response
		 * @since 4.6.0
		 */
		public function respond( $data ) {

			$result = new \WP_REST_Response( $data, 200 );

			if ( $this->clear_cache_maybe() ) {
				$result->set_headers(
					array(
						'Content-Type' => 'application/json',
					)
				);
			} else {
				$result->set_headers(
					array(
						//'Cache-Control' => 'max-age=3600, s-max-age=3600',
						'Content-Type'  => 'application/json',
					)
				);
			}

			return $result;
		}

		/**
		 * Return stats
		 *
		 * @retun array
		 * @since 4.6.0
		 */
		public function report_stats() {

			global $wpdb;

			if ( ! DLM_Logging::is_logging_enabled() || ! DLM_Utils::table_checker( $wpdb->dlm_reports ) ) {
				return array();
			}

			$cache_key = 'dlm_insights';
			$stats     = wp_cache_get( $cache_key, 'dlm_reports_page' );

			if ( ! $stats ) {
				$stats = $wpdb->get_results( "SELECT  * FROM {$wpdb->dlm_reports};", ARRAY_A );
				wp_cache_set( $cache_key, $stats, 'dlm_reports_page', 12 * HOUR_IN_SECONDS );
			}

			return $stats;
		}

		/**
		 * Return user reports stats
		 *
		 * @retun array
		 * @since 4.6.0
		 */
		public function get_user_reports() {

			global $wpdb;

			if ( ! DLM_Logging::is_logging_enabled() || ! DLM_Utils::table_checker( $wpdb->dlm_reports ) ) {
				return array();
			}

			$cache_key           = 'dlm_insights_users';
			$user_reports_option = get_option( 'dlm_toggle_user_reports' );
			$user_reports        = array();
			$offset              = isset( $_REQUEST['offset'] ) ? absint( sanitize_text_field( wp_unslash( $_REQUEST['offset'] ) ) ) : 0;
			$count               = isset( $_REQUEST['limit'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['limit'] ) ) : 10000;
			$offset_limit        = $offset * 10000;

			// If user toggled off user reports we should clear the cache.
			if ( $this->clear_cache_maybe() ) {
				wp_cache_delete( $cache_key, 'dlm_user_reports' );
				update_option( 'dlm_first_user_reports_toggle', $user_reports_option );
			}
			wp_cache_delete( $cache_key, 'dlm_user_reports' );
			$stats = wp_cache_get( $cache_key, 'dlm_user_reports' );
			if ( ! $stats ) {
				if ( 'on' === $user_reports_option ) {
					$downloads = $wpdb->get_results( 'SELECT user_id, user_ip, download_id, download_date, download_status FROM ' . $wpdb->download_log . " ORDER BY ID desc LIMIT {$offset_limit}, {$count};", ARRAY_A );
					$user_reports = array(
						'logs'   => $downloads,
						'offset' => ( 10000 === count( $downloads ) ) ? $offset + 1 : '',
						'done'   => ( 10000 > count( $downloads ) ) ? true : false
					);
				}
				wp_cache_set( $cache_key, $user_reports, 'dlm_reports_page', 12 * HOUR_IN_SECONDS );
			}

			return $user_reports;
		}

		/**
		 * Return user data
		 *
		 * @retun array
		 * @since 4.6.0
		 */
		public function get_user_data() {

			global $wpdb;

			if ( ! DLM_Logging::is_logging_enabled() || ! DLM_Utils::table_checker( $wpdb->dlm_reports ) ) {
				return array();
			}

			$cache_key           = 'dlm_insights_users';
			$user_reports_option = get_option( 'dlm_toggle_user_reports' );
			$user_data           = array();

			$stats = wp_cache_get( $cache_key, 'dlm_user_data' );
			if ( ! $stats ) {
				if ( 'on' === $user_reports_option ) {
					$users      = get_users();
					foreach ( $users as $user ) {
						$user_data                    = $user->data;
						$users_data[ $user_data->ID ] = array(
							'id'           => $user_data->ID,
							'nicename'     => $user_data->user_nicename,
							'url'          => $user_data->user_url,
							'registered'   => $user_data->user_registered,
							'display_name' => $user_data->display_name,
							'role'         => ( ( ! in_array( 'administrator', $user->roles, true ) ) ? $user->roles : '' ),
						);
					}
				}
				wp_cache_set( $cache_key, $user_data, 'dlm_user_data', 12 * HOUR_IN_SECONDS );
			}

			return $user_data;
		}

		/**
		 * Save reports settings
		 *
		 * @return void
		 * @since 4.6.0
		 */
		public function save_reports_settings() {

			if ( ! isset( $_POST['_ajax_nonce'] ) ) {
				wp_send_json_error( 'No nonce' );
			}

			check_ajax_referer( 'dlm_reports_nonce' );
			$option = ( isset( $_POST['name'] ) ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

			if ( 'dlm_clear_api_cache' === $option ) {
				wp_cache_delete( 'dlm_insights', 'dlm_reports_page' );
				die();
			}

			if ( isset( $_POST['checked'] ) && 'true' === $_POST['checked'] ) {
				$value = 'on';
			} else {
				$value = 'off';
			}

			update_option( $option, $value );
			die();
		}

		/**
		 * Check if we need to clear the cache
		 *
		 * @return bool
		 * @since 4.6.0
		 */
		public function clear_cache_maybe() {
			$toggled_reports     = get_option( 'dlm_first_user_reports_toggle' );
			$user_reports_option = get_option( 'dlm_toggle_user_reports' );
			return ( ! $toggled_reports || $toggled_reports !== $user_reports_option );
		}
	}
}
