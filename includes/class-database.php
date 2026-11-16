<?php
/**
 * Batched database dumper for Lumos Backup.
 *
 * @package LumosBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Lumos_Backup_Database
 */
class Lumos_Backup_Database {

	/**
	 * Number of rows to read per database query batch.
	 */
	const BATCH_SIZE = 500;

	/**
	 * Maximum rows per multi-row INSERT statement.
	 */
	const INSERT_CHUNK = 50;

	/**
	 * Dump all WordPress database tables to a target SQL file.
	 *
	 * Writes data in batches directly to disk to minimize memory usage.
	 *
	 * @param string $output_file Path to destination .sql file.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function dump_to_file( $output_file ) {
		global $wpdb;

		$handle = @fopen( $output_file, 'wb' );
		if ( ! $handle ) {
			return new WP_Error(
				'file_open_failed',
				sprintf(
					/* translators: %s: target file path */
					__( 'Could not open file for writing database dump: %s', 'lumos-backup' ),
					$output_file
				)
			);
		}

		// 1. Write header
		$site_url = function_exists( 'site_url' ) ? site_url() : 'unknown';
		$header   = "-- ============================================================\n" .
			"-- Lumos Backup Database Dump\n" .
			"-- Site: " . $site_url . "\n" .
			"-- Created (UTC): " . gmdate( 'Y-m-d H:i:s' ) . "\n" .
			"-- Table Prefix: " . $wpdb->prefix . "\n" .
			"-- PHP Version: " . PHP_VERSION . "\n" .
			"-- ============================================================\n\n" .
			"SET FOREIGN_KEY_CHECKS=0;\n" .
			"SET SQL_MODE=\"NO_AUTO_VALUE_ON_ZERO\";\n" .
			"SET time_zone = \"+00:00\";\n" .
			"SET NAMES utf8mb4;\n\n";

		fwrite( $handle, $header );

		// 2. Retrieve base tables
		$tables = $this->get_tables();
		if ( empty( $tables ) ) {
			fclose( $handle );
			return new WP_Error( 'no_tables', __( 'No tables found in database to dump.', 'lumos-backup' ) );
		}

		// 3. Process each table
		foreach ( $tables as $table ) {
			$this->dump_table( $table, $handle );
		}

		// 4. Write footer
		$footer = "\n-- ============================================================\n" .
			"-- End of Lumos Backup Dump\n" .
			"-- ============================================================\n" .
			"SET FOREIGN_KEY_CHECKS=1;\n";

		fwrite( $handle, $footer );
		fclose( $handle );

		return true;
	}

	/**
	 * Get list of tables to dump.
	 *
	 * Prioritizes BASE TABLEs to prevent issues with views.
	 *
	 * @return array List of table names.
	 */
	private function get_tables() {
		global $wpdb;

		$output_type = defined( 'ARRAY_N' ) ? ARRAY_N : 'ARRAY_N';

		// Suppress errors if MySQL version doesn't support Table_type filter
		$suppress = $wpdb->suppress_errors( true );
		$results  = $wpdb->get_results( "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'", $output_type );
		$wpdb->suppress_errors( $suppress );

		$tables = [];
		if ( ! empty( $results ) ) {
			foreach ( $results as $row ) {
				$tables[] = $row[0];
			}
		} else {
			// Fallback to standard SHOW TABLES
			$results = $wpdb->get_col( 'SHOW TABLES' );
			if ( ! empty( $results ) ) {
				$tables = $results;
			}
		}

		return $tables;
	}

	/**
	 * Dump schema and rows for a single table.
	 *
	 * @param string   $table  Table name.
	 * @param resource $handle Open file handle.
	 */
	private function dump_table( $table, $handle ) {
		global $wpdb;

		$output_n = defined( 'ARRAY_N' ) ? ARRAY_N : 'ARRAY_N';
		$output_a = defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A';

		// Table DDL header
		fwrite( $handle, "\n-- ------------------------------------------------------------\n" );
		fwrite( $handle, "-- Table structure for `{$table}`\n" );
		fwrite( $handle, "-- ------------------------------------------------------------\n" );
		fwrite( $handle, "DROP TABLE IF EXISTS `{$table}`;\n" );

		// Get CREATE TABLE statement
		$create_table = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", $output_n );
		if ( ! empty( $create_table[1] ) ) {
			fwrite( $handle, $create_table[1] . ";\n\n" );
		}

		// Count rows
		$total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		if ( 0 === $total_rows ) {
			return;
		}

		// Table Data header
		fwrite( $handle, "-- Dumping data for `{$table}`\n" );
		fwrite( $handle, "LOCK TABLES `{$table}` WRITE;\n" );
		fwrite( $handle, "ALTER TABLE `{$table}` DISABLE KEYS;\n" );

		// Read and dump in batches
		$batch_size = self::BATCH_SIZE;
		$offset     = 0;

		while ( $offset < $total_rows ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $batch_size, $offset ),
				$output_a
			);

			if ( empty( $rows ) ) {
				break;
			}

			// Group into multi-row INSERTs
			$this->write_insert_statements( $table, $rows, $handle );

			$offset += count( $rows );

			// Free memory
			unset( $rows );
		}

		fwrite( $handle, "ALTER TABLE `{$table}` ENABLE KEYS;\n" );
		fwrite( $handle, "UNLOCK TABLES;\n" );
	}

	/**
	 * Write multi-row INSERT statements for an array of rows.
	 *
	 * @param string   $table  Table name.
	 * @param array    $rows   Array of row associative arrays.
	 * @param resource $handle Open file handle.
	 */
	private function write_insert_statements( $table, array $rows, $handle ) {
		if ( empty( $rows ) ) {
			return;
		}

		$columns = array_keys( $rows[0] );
		$column_clause = '`' . implode( '`, `', $columns ) . '`';

		$chunks = array_chunk( $rows, self::INSERT_CHUNK );

		foreach ( $chunks as $chunk ) {
			$values_sql = [];

			foreach ( $chunk as $row ) {
				$escaped_vals = [];
				foreach ( $row as $val ) {
					$escaped_vals[] = $this->escape_sql_value( $val );
				}
				$values_sql[] = '(' . implode( ', ', $escaped_vals ) . ')';
			}

			$statement = "INSERT INTO `{$table}` ({$column_clause}) VALUES\n" .
				implode( ",\n", $values_sql ) . ";\n";

			fwrite( $handle, $statement );
		}
	}

	/**
	 * Escape a single database value for SQL.
	 *
	 * @param mixed $val Value from database row.
	 * @return string Escaped SQL value.
	 */
	private function escape_sql_value( $val ) {
		global $wpdb;

		if ( null === $val ) {
			return 'NULL';
		}

		// Numeric types can still be safely quoted in MySQL, or unquoted if strict int/float
		if ( is_int( $val ) || is_float( $val ) ) {
			return (string) $val;
		}

		// Escape string value
		if ( isset( $wpdb->dbh ) && $wpdb->dbh instanceof mysqli ) {
			$escaped = mysqli_real_escape_string( $wpdb->dbh, $val );
		} else {
			$escaped = addcslashes( (string) $val, "\x00\n\r\\'\"\x1a" );
		}

		return "'" . $escaped . "'";
	}
}
