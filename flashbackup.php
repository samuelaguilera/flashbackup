<?php
/**
 * Plugin Name: FlashBackup
 * Description: Cron scheduled database backup using mysqldump for faster backup operation.
 * Author: Samuel Aguilera
 * Version: 1.0
 * Author URI: http://www.samuelaguilera.com
 * License: GPL3
 *
 * @package FlashBackup
 */

/*
Copyright 2022 Samuel Aguilera.

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License version 3 as published by
the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Updates handler.
require_once plugin_dir_path( __FILE__ ) . 'class-flashbackup-updater.php';
$flashbackup_updater = new FlashBackup_Updater( __FILE__ );

/**
 * Main plugin class.
 *
 * @package FlashBackup
 */
class FlashBackup {

	/**
	 * Initialization.
	 */
	public function __construct() {
		register_activation_hook( __FILE__, array( $this, 'activation' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivation' ) );

		// Show warning if required function is not available.
		add_action( 'admin_init', array( $this, 'exec_notice' ) );

		// Add backup creation to the cron job.
		add_action( 'flashbackup_task', array( $this, 'create_backup' ) );
	}

	/**
	 * Backups folder and security files creation, and Cron scheduling.
	 */
	public function activation() {
		$folder_token = get_option( 'flashbackup_folder_token' );

		if ( empty( $folder_token ) ) {
			$folder_token = bin2hex( random_bytes( 8 ) );
			update_option( 'flashbackup_folder_token', $folder_token );
		}

		$full_path = WP_CONTENT_DIR . '/flashbackup_' . $folder_token;

		// Create folder if it doesn't exists.
		if ( ! is_dir( $full_path ) ) {
			wp_mkdir_p( $full_path );
		}

		// Insert Apache 2.4+ rule to block web access to backups. File is created if it doesn't exists.
		insert_with_markers( $full_path . '/.htaccess', 'FlashBackup', 'Require all denied' );
		// Add index.html to prevent directory listing for servers not support .htaccess.
		$noindex_file = touch( $full_path . '/index.html' );

		// Cron event interval. Other WP default values for this are: twicedaily, hourly, weekly .
		$interval = apply_filters( 'flashbackup_interval', 'daily' );

		if ( ! wp_next_scheduled( 'flashbackup_task' ) ) {
			wp_schedule_event( time(), $interval, 'flashbackup_task' );
		}
	}

	/**
	 * Removes cron event on plugin deactivation.
	 */
	public function deactivation() {
		wp_clear_scheduled_hook( 'flashbackup_task' );
	}

	/**
	 * Creates the database backup.
	 */
	public function create_backup() {

		$full_path = WP_CONTENT_DIR . '/flashbackup_' . get_option( 'flashbackup_folder_token' );
		// Possible values, gz, zip, or empty for no compression.
		$compresion = apply_filters( 'flashbackup_compression', 'gz' );

		// Ensure we have a valid path.
		if ( empty( $full_path ) || ! is_dir( $full_path ) ) {
			return;
		}

		// Ensure we can use the exec function.
		if ( ! function_exists( 'exec' ) ) {
			return;
		}

		// Random part of the file name.
		$random = bin2hex( random_bytes( 3 ) );
		// Date and time for the file name.
		$time_now = current_datetime(); // Get local Date and time object.
		$date     = $time_now->format( 'Y.m.d-H.i.s' );

		// Delete old backups.
		$this->rotate_backups( $full_path, $time_now->format( 'U' ) );

		$mysqldump_base = 'mysqldump --complete-insert -u ' . DB_USER . " -p'" . DB_PASSWORD . "' " . DB_NAME . ' -h ' . DB_HOST;
		$db_backup_file = $full_path . '/DB_Backup_' . $date . '_' . $random . '.sql';

		if ( 'gz' === $compresion ) { // gzip compression.
			$db_backup_file = $db_backup_file . '.gz';
			$mysqldump      = $mysqldump_base . ' | gzip > ' . escapeshellarg( $db_backup_file );
		} elseif ( 'zip' === $compresion ) { // zip compression.
			$db_backup_file = $db_backup_file . '.zip';
			$mysqldump      = $mysqldump_base . ' | zip > ' . escapeshellarg( $db_backup_file );
		} else { // No compression.
			$mysqldump = $mysqldump_base . ' > ' . escapeshellarg( $db_backup_file );
		}
		// Run backup.
		exec( $mysqldump ); // phpcs:ignore

	}

	/**
	 * Deletes old backups.
	 *
	 * @param string $full_path Path to backup folder.
	 * @param int    $time_now Unix timestamp in local timezone.
	 */
	public function rotate_backups( $full_path, $time_now ) {

		// Delete backups older than 1 week.
		$rotate_time = apply_filters( 'flashbackup_rotate_time', WEEK_IN_SECONDS );

		$file_system_iterator = new FilesystemIterator( $full_path );
		$files_to_exclude     = array( 'index.html', '.htaccess' );
		foreach ( $file_system_iterator as $file ) {
			if ( ! in_array( $file->getFilename(), $files_to_exclude, true ) && ( $time_now - $file->getCTime() ) >= $rotate_time ) {
				unlink( "$full_path/" . $file->getFilename() );
			}
		}

	}

	/**
	 * Show warning if exec is not available.
	 */
	public function exec_notice() {
		if ( ! function_exists( 'exec' ) ) {
			?>
			<div class="notice notice-warning">
				<p><strong>Your server doesn't allow to use PHP's exec function!</strong> You will want to ask your host support to enable it or disable this plugin.</p>
			</div>
			<?php
		}
	}

}

$flashbackup = new FlashBackup();
