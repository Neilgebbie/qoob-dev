<?php

class Search_Replace_Command extends WP_CLI_Command {

	private $dry_run;
	private $export_handle = false;
	private $export_insert_size;
	private $recurse_objects;
	private $regex;
	private $regex_flags;
	private $regex_delimiter;
	private $skip_columns;
	private $include_columns;
	private $format;
	private $report;
	private $report_changed_only;

	/**
	 * Search/replace strings in the database.
	 *
	 * Searches through all rows in a selection of tables and replaces
	 * appearances of the first string with the second string.
	 *
	 * By default, the command uses tables registered to the `$wpdb` object. On
	 * multisite, this will just be the tables for the current site unless
	 * `--network` is specified.
	 *
	 * Search/replace intelligently handles PHP serialized data, and does not
	 * change primary key values.
	 *
	 * ## OPTIONS
	 *
	 * <old>
	 * : A string to search for within the database.
	 *
	 * <new>
	 * : Replace instances of the first string with this new string.
	 *
	 * [<table>...]
	 * : List of database tables to restrict the replacement to. Wildcards are
	 * supported, e.g. `'wp_*options'` or `'wp_post*'`.
	 *
	 * [--dry-run]
	 * : Run the entire search/replace operation and show report, but don't save
	 * changes to the database.
	 *
	 * [--network]
	 * : Search/replace through all the tables registered to $wpdb in a
	 * multisite install.
	 *
	 * [--all-tables-with-prefix]
	 * : Enable replacement on any tables that match the table prefix even if
	 * not registered on $wpdb.
	 *
	 * [--all-tables]
	 * : Enable replacement on ALL tables in the database, regardless of the
	 * prefix, and even if not registered on $wpdb. Overrides --network
	 * and --all-tables-with-prefix.
	 *
	 * [--export[=<file>]]
	 * : Write transformed data as SQL file instead of saving replacements to
	 * the database. If <file> is not supplied, will output to STDOUT.
	 *
	 * [--export_insert_size=<rows>]
	 * : Define number of rows in single INSERT statement when doing SQL export.
	 * You might want to change this depending on your database configuration
	 * (e.g. if you need to do fewer queries). Default: 50
	 *
	 * [--skip-columns=<columns>]
	 * : Do not perform the replacement on specific columns. Use commas to
	 * specify multiple columns.
	 *
	 * [--include-columns=<columns>]
	 * : Perform the replacement on specific columns. Use commas to
	 * specify multiple columns.
	 *
	 * [--precise]
	 * : Force the use of PHP (instead of SQL) which is more thorough,
	 * but slower.
	 *
	 * [--recurse-objects]
	 * : Enable recursing into objects to replace strings. Defaults to true;
	 * pass --no-recurse-objects to disable.
	 *
	 * [--verbose]
	 * : Prints rows to the console as they're updated.
	 *
	 * [--regex]
	 * : Runs the search using a regular expression (without delimiters).
	 * Warning: search-replace will take about 15-20x longer when using --regex.
	 *
	 * [--regex-flags=<regex-flags>]
	 * : Pass PCRE modifiers to regex search-replace (e.g. 'i' for case-insensitivity).
	 *
	 * [--regex-delimiter=<regex-delimiter>]
	 * : The delimiter to use for the regex. It must be escaped if it appears in the search string. The default value is the result of `chr(1)`.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - count
	 * ---
	 *
	 * [--report]
	 * : Produce report. Defaults to true.
	 *
	 * [--report-changed-only]
	 * : Report changed fields only. Defaults to false.
	 *
	 * ## EXAMPLES
	 *
	 *     # Search and replace but skip one column
	 *     $ wp search-replace 'http://example.dev' 'http://example.com' --skip-columns=guid
	 *
	 *     # Run search/replace operation but dont save in database
	 *     $ wp search-replace 'foo' 'bar' wp_posts wp_postmeta wp_terms --dry-run
	 *
	 *     # Run case-insensitive regex search/replace operation (slow)
	 *     $ wp search-replace '\[foo id="([0-9]+)"' '[bar id="\1"' --regex --regex-flags='i'
	 *
	 *     # Turn your production multisite database into a local dev database
	 *     $ wp search-replace --url=example.com example.com example.dev 'wp_*options' wp_blogs
	 *
	 *     # Search/replace to a SQL file without transforming the database
	 *     $ wp search-replace foo bar --export=database.sql
	 *
	 *     # Bash script: Search/replace production to development url (multisite compatible)
	 *     #!/bin/bash
	 *     if $(wp --url=http://example.com core is-installed --network); then
	 *         wp search-replace --url=http://example.com 'http://example.com' 'http://example.dev' --recurse-objects --network --skip-columns=guid
	 *     else
	 *         wp search-replace 'http://example.com' 'http://example.dev' --recurse-objects --skip-columns=guid
	 *     fi
	 */
	public function __invoke( $args, $assoc_args ) {
		global $wpdb;
		$old             = array_shift( $args );
		$new             = array_shift( $args );
		$total           = 0;
		$report          = array();
		$this->dry_run         = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run' );
		$php_only        = \WP_CLI\Utils\get_flag_value( $assoc_args, 'precise' );
		$this->recurse_objects = \WP_CLI\Utils\get_flag_value( $assoc_args, 'recurse-objects', true );
		$this->verbose         =  \WP_CLI\Utils\get_flag_value( $assoc_args, 'verbose' );
		$this->regex           =  \WP_CLI\Utils\get_flag_value( $assoc_args, 'regex' );
		$this->regex_flags     =  \WP_CLI\Utils\get_flag_value( $assoc_args, 'regex-flags' );
		$this->regex_delimiter =  \WP_CLI\Utils\get_flag_value( $assoc_args, 'regex-delimiter', chr( 1 ) );
		$this->format          = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format' );

		if ( ! empty( $this->regex ) ) {
			if ( '' === $this->regex_delimiter ) {
				$this->regex_delimiter = chr( 1 );
			}
			$search_regex = $this->regex_delimiter;
			$search_regex .= $old;
			$search_regex .= $this->regex_delimiter;
			$search_regex .= $this->regex_flags;
			if ( false === @preg_match( $search_regex, '' ) ) {
				\WP_CLI::error( "The regex '$search_regex' fails." );
			}
		}

		$this->skip_columns = explode( ',', \WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-columns' ) );
		$this->include_columns = array_filter( explode( ',', \WP_CLI\Utils\get_flag_value( $assoc_args, 'include-columns' ) ) );

		if ( $old === $new && ! $this->regex ) {
			WP_CLI::warning( "Replacement value '{$old}' is identical to search value '{$new}'. Skipping operation." );
			exit;
		}

		if ( null !== ( $export = \WP_CLI\Utils\get_flag_value( $assoc_args, 'export' ) ) ) {
			if ( $this->dry_run ) {
				WP_CLI::error( 'You cannot supply --dry-run and --export at the same time.' );
			}
			if ( true === $export ) {
				$this->export_handle = STDOUT;
				$this->verbose = false;
			} else {
				$this->export_handle = @fopen( $assoc_args['export'], 'w' );
				if ( false === $this->export_handle ) {
					$error = error_get_last();
					WP_CLI::error( sprintf( 'Unable to open "%s" for writing: %s.', $assoc_args['export'], $error['message'] ) );
				}
			}
			$export_insert_size = WP_CLI\Utils\get_flag_value( $assoc_args, 'export_insert_size', 50 );
			if ( (int) $export_insert_size == $export_insert_size && $export_insert_size > 0 ) {
				$this->export_insert_size = $export_insert_size;
			}
			$php_only = true;
		}

		$this->report = \WP_CLI\Utils\get_flag_value( $assoc_args, 'report', true );
		$this->report_changed_only = \WP_CLI\Utils\get_flag_value( $assoc_args, 'report-changed-only', false );

		if ( $this->regex_flags ) {
			$php_only = true;
		}

		// never mess with hashed passwords
		$this->skip_columns[] = 'user_pass';

		// Get table names based on leftover $args or supplied $assoc_args
		$tables = \WP_CLI\Utils\wp_get_table_names( $args, $assoc_args );
		foreach ( $tables as $table ) {

			$table_sql = self::esc_sql_ident( $table );

			if ( $this->export_handle ) {
				fwrite( $this->export_handle, "\nDROP TABLE IF EXISTS $table_sql;\n" );
				$row = $wpdb->get_row( "SHOW CREATE TABLE $table_sql", ARRAY_N );
				fwrite( $this->export_handle, $row[1] . ";\n" );
				list( $table_report, $total_rows ) = $this->php_export_table( $table, $old, $new );
				if ( $this->report ) {
					$report = array_merge( $report, $table_report );
				}
				$total += $total_rows;
				// Don't perform replacements on the actual database
				continue;
			}

			list( $primary_keys, $columns, $all_columns ) = self::get_columns( $table );

			// since we'll be updating one row at a time,
			// we need a primary key to identify the row
			if ( empty( $primary_keys ) ) {
				if ( $this->report ) {
					$report[] = array( $table, '', 'skipped', '' );
				} else {
					WP_CLI::warning( $all_columns ? "No primary keys for table '$table'." : "No such table '$table'." );
				}
				continue;
			}

			foreach ( $columns as $col ) {
				if ( ! empty( $this->include_columns ) && ! in_array( $col, $this->include_columns ) ) {
					continue;
				}

				if ( in_array( $col, $this->skip_columns ) ) {
					continue;
				}

				if ( $this->verbose && 'count' !== $this->format ) {
					$this->start_time = microtime( true );
					WP_CLI::log( sprintf( 'Checking: %s.%s', $table, $col ) );
				}

				if ( ! $php_only && ! $this->regex ) {
					$col_sql = self::esc_sql_ident( $col );
					$wpdb->last_error = '';
					$serialRow = $wpdb->get_row( "SELECT * FROM $table_sql WHERE $col_sql REGEXP '^[aiO]:[1-9]' LIMIT 1" );
					// When the regex triggers an error, we should fall back to PHP
					if ( false !== strpos( $wpdb->last_error, 'ERROR 1139' ) ) {
						$serialRow = true;
					}
				}

				if ( $php_only || $this->regex || NULL !== $serialRow ) {
					$type = 'PHP';
					$count = $this->php_handle_col( $col, $primary_keys, $table, $old, $new );
				} else {
					$type = 'SQL';
					$count = $this->sql_handle_col( $col, $table, $old, $new );
				}

				if ( $this->report && ( $count || ! $this->report_changed_only ) ) {
					$report[] = array( $table, $col, $count, $type );
				}

				$total += $count;
			}
		}

		if ( $this->export_handle && STDOUT !== $this->export_handle ) {
			fclose( $this->export_handle );
		}

		// Only informational output after this point
		if ( WP_CLI::get_config( 'quiet' ) || STDOUT === $this->export_handle ) {
			return;
		}

		if ( 'count' === $this->format ) {
			WP_CLI::line( $total );
			return;
		}

		if ( $this->report ) {
			$table = new \cli\Table();
			$table->setHeaders( array( 'Table', 'Column', 'Replacements', 'Type' ) );
			$table->setRows( $report );
			$table->display();
		}

		if ( ! $this->dry_run ) {
			if ( ! empty( $assoc_args['export'] ) ) {
				$success_message = 1 === $total ? "Made 1 replacement and exported to {$assoc_args['export']}." : "Made {$total} replacements and exported to {$assoc_args['export']}.";
			} else {
				$success_message = 1 === $total ? "Made 1 replacement." : "Made $total replacements.";
				if ( $total && 'Default' !== WP_CLI\Utils\wp_get_cache_type() ) {
					$success_message .= ' Please remember to flush your persistent object cache with `wp cache flush`.';
					if ( is_multisite() ) {
						$success_message .= ' If you see a "Site not found" error after replacing a domain, try flushing cache against the old domain (which may be the cached lookup value).';
					}
				}
			}
			WP_CLI::success( $success_message );
		}
		else {
			$success_message = ( 1 === $total ) ? '%d replacement to be made.' : '%d replacements to be made.';
			WP_CLI::success( sprintf( $success_message, $total ) );
		}
	}

	private function php_export_table( $table, $old, $new ) {
		list( $primary_keys, $columns, $all_columns ) = self::get_columns( $table );
		$chunk_size = getenv( 'BEHAT_RUN' ) ? 10 : 1000;
		$args = array(
			'table'      => $table,
			'fields'     => $all_columns,
			'chunk_size' => $chunk_size
		);

		$replacer = new \WP_CLI\SearchReplacer( $old, $new, $this->recurse_objects, $this->regex, $this->regex_flags, $this->regex_delimiter );
		$col_counts = array_fill_keys( $all_columns, 0 );
		if ( $this->verbose && 'table' === $this->format ) {
			$this->start_time = microtime( true );
			WP_CLI::log( sprintf( 'Checking: %s', $table ) );
		}

		$rows = array();
		foreach ( new \WP_CLI\Iterators\Table( $args ) as $i => $row ) {
			$row_fields = array();
			foreach( $all_columns as $col ) {
				$value = $row->$col;
				if ( $value && ! in_array( $col, $primary_keys ) && ! in_array( $col, $this->skip_columns ) ) {
					$new_value = $replacer->run( $value );
					if ( $new_value !== $value ) {
						$col_counts[ $col ]++;
						$value = $new_value;
					}
				}
				$row_fields[ $col ] = $value;
			}
			$rows[] = $row_fields;
		}
		$this->write_sql_row_fields( $table, $rows );

		$table_report = array();
		$total_rows = $total_cols = 0;
		foreach ( $col_counts as $col => $col_count ) {
			if ( $this->report && ( $col_count || ! $this->report_changed_only ) ) {
				$table_report[] = array( $table, $col, $col_count, 'PHP' );
			}
			if ( $col_count ) {
				$total_cols++;
				$total_rows += $col_count;
			}
		}

		if ( $this->verbose && 'table' === $this->format ) {
			$time = round( microtime( true ) - $this->start_time, 3 );
			WP_CLI::log( sprintf( '%d columns and %d total rows affected using PHP (in %ss).', $total_cols, $total_rows, $time ) );
		}

		return array( $table_report, $total_rows );
	}

	private function sql_handle_col( $col, $table, $old, $new ) {
		global $wpdb;

		$table_sql = self::esc_sql_ident( $table );
		$col_sql = self::esc_sql_ident( $col );
		if ( $this->dry_run ) {
			$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT($col_sql) FROM $table_sql WHERE $col_sql LIKE BINARY %s;", '%' . self::esc_like( $old ) . '%' ) );
		} else {
			$count = $wpdb->query( $wpdb->prepare( "UPDATE $table_sql SET $col_sql = REPLACE($col_sql, %s, %s);", $old, $new ) );
		}

		if ( $this->verbose && 'table' === $this->format ) {
			$time = round( microtime( true ) - $this->start_time, 3 );
			WP_CLI::log( sprintf( '%d rows affected using SQL (in %ss).', $count, $time ) );
		}
		return $count;
	}

	private function php_handle_col( $col, $primary_keys, $table, $old, $new ) {
		global $wpdb;

		$count = 0;
		$replacer = new \WP_CLI\SearchReplacer( $old, $new, $this->recurse_objects, $this->regex, $this->regex_flags, $this->regex_delimiter );

		$table_sql = self::esc_sql_ident( $table );
		$col_sql = self::esc_sql_ident( $col );
		$where = $this->regex ? '' : " WHERE $col_sql" . $wpdb->prepare( ' LIKE BINARY %s', '%' . self::esc_like( $old ) . '%' );
		$primary_keys_sql = implode( ',', self::esc_sql_ident( $primary_keys ) );
		$rows = $wpdb->get_results( "SELECT {$primary_keys_sql} FROM {$table_sql} {$where}" );
		foreach ( $rows as $keys ) {
			$where_sql = '';
			foreach( (array) $keys as $k => $v ) {
				if ( strlen( $where_sql ) ) {
					$where_sql .= ' AND ';
				}
				$where_sql .= self::esc_sql_ident( $k ) . ' = ' . esc_sql( $v );
			}
			$col_value = $wpdb->get_var( "SELECT {$col_sql} FROM {$table_sql} WHERE {$where_sql}" );
			if ( '' === $col_value )
				continue;

			$value = $replacer->run( $col_value );

			if ( $value === $col_value ) {
				continue;
			}

			if ( $this->dry_run ) {
				if ( $value != $col_value )
					$count++;
			} else {
				$where = array();
				foreach( (array) $keys as $k => $v ) {
					$where[ $k ] = $v;
				}

				$count += $wpdb->update( $table, array( $col => $value ), $where );
			}
		}

		if ( $this->verbose && 'table' === $this->format ) {
			$time = round( microtime( true ) - $this->start_time, 3 );
			WP_CLI::log( sprintf( '%d rows affected using PHP (in %ss).', $count, $time ) );
		}

		return $count;
	}

	private function write_sql_row_fields( $table, $rows ) {
		global $wpdb;

		if(empty($rows)) {
			return;
		}

		$table_sql = self::esc_sql_ident( $table );
		$insert = "INSERT INTO $table_sql (";
		$insert .= join( ', ', self::esc_sql_ident( array_keys( $rows[0] ) ) );
		$insert .= ') VALUES ';
		$insert .= "\n";

		$sql = $insert;
		$values = array();

		$index = 1;
		$count = count( $rows );
		$export_insert_size = $this->export_insert_size;

		foreach($rows as $row_fields) {
			$sql .= '(' . join( ', ', array_fill( 0, count( $row_fields ), '%s' ) ) . ')';
			$values = array_merge( $values, array_values( $row_fields ) );

			// Add new insert statement if needed. Before this we close the previous with semicolon and write statement to sql-file.
			// "Statement break" is needed:
			//		1. When the loop is running every nth time (where n is insert statement size, $export_index_size). Remainder is zero also on first round, so it have to be excluded.
			//			$index % $export_insert_size == 0 && $index > 0
			//		2. Or when the loop is running last time
			//			$index == $count
			if( ( $index % $export_insert_size == 0 && $index > 0 ) || $index == $count ) {
				$sql .= ";\n";

				$sql = $wpdb->prepare( $sql, array_values( $values ) );
				fwrite( $this->export_handle, $sql );

				// If there is still rows to loop, reset $sql and $values variables.
				if( $count > $index ) {
					$sql = $insert;
					$values = array();
				}
			} else { // Otherwise just add comma and new line
				$sql .= ",\n";
			}

			$index++;
		}
	}

	private static function get_columns( $table ) {
		global $wpdb;

		$table_sql = self::esc_sql_ident( $table );
		$primary_keys = $text_columns = $all_columns = array();
		$suppress_errors = $wpdb->suppress_errors();
		if ( ( $results = $wpdb->get_results( "DESCRIBE $table_sql" ) ) ) {
			foreach ( $results as $col ) {
				if ( 'PRI' === $col->Key ) {
					$primary_keys[] = $col->Field;
				}
				if ( self::is_text_col( $col->Type ) ) {
					$text_columns[] = $col->Field;
				}
				$all_columns[] = $col->Field;
			}
		}
		$wpdb->suppress_errors( $suppress_errors );
		return array( $primary_keys, $text_columns, $all_columns );
	}

	private static function is_text_col( $type ) {
		foreach ( array( 'text', 'varchar' ) as $token ) {
			if ( false !== strpos( $type, $token ) )
				return true;
		}

		return false;
	}

	private static function esc_like( $old ) {
		global $wpdb;

		// Remove notices in 4.0 and support backwards compatibility
		if( method_exists( $wpdb, 'esc_like' ) ) {
			// 4.0
			$old = $wpdb->esc_like( $old );
		} else {
			// 3.9 or less
			$old = like_escape( esc_sql( $old ) ); // Note: this double escaping is actually necessary, even though `esc_like()` will be used in a `prepare()`.
		}

		return $old;
	}

	/**
	 * Escapes (backticks) MySQL identifiers (aka schema object names) - i.e. column names, table names, and database/index/alias/view etc names.
	 * See https://dev.mysql.com/doc/refman/5.5/en/identifiers.html
	 *
	 * @param string|array $idents A single identifier or an array of identifiers.
	 * @return string|array An escaped string if given a string, or an array of escaped strings if given an array of strings.
	 */
	private static function esc_sql_ident( $idents ) {
		$backtick = function ( $v ) {
			// Escape any backticks in the identifier by doubling.
			return '`' . str_replace( '`', '``', $v ) . '`';
		};
		if ( is_string( $idents ) ) {
			return $backtick( $idents );
		}
		return array_map( $backtick, $idents );
	}

}
