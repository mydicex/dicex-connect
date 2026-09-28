<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dicex_Connect_Logs_Page {

	public function __construct() {
		add_action( 'admin_post_dicex_connect_clear_logs', array( $this, 'handle_clear_logs' ) );
	}

	public function render() {
		$logs = Dicex_Connect_Logger::get_logs();
		require DICEX_CONNECT_DIR . 'includes/admin/views/logs-page.php';
	}

	public function handle_clear_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'dicex-connect' ) );
		}
		check_admin_referer( 'dicex_connect_clear_logs' );

		Dicex_Connect_Logger::clear();

		wp_safe_redirect( admin_url( 'admin.php?page=dicex-connect-logs' ) );
		exit;
	}
}
