<?php

function relevanssi_options() {
	if (RELEVANSSI_PREMIUM) {
		$options_txt = __('Relevanssi Premium Search Options', 'relevanssi');
	}
	else {
		$options_txt = __('Relevanssi Search Options', 'relevanssi');
	}

	printf("<div class='wrap'><h2>%s</h2>", $options_txt);
	if (!empty($_POST)) {
		if (isset($_REQUEST['submit'])) {
			check_admin_referer(plugin_basename(__FILE__), 'relevanssi_options');
			update_relevanssi_options();
		}
	
		if (isset($_REQUEST['index'])) {
			check_admin_referer(plugin_basename(__FILE__), 'relevanssi_options');
			update_relevanssi_options();
			relevanssi_build_index();
		}
	
		if (isset($_REQUEST['index_extend'])) {
			check_admin_referer(plugin_basename(__FILE__), 'relevanssi_options');
			update_relevanssi_options();
			relevanssi_build_index(true);
		}

		if (isset($_REQUEST['import_options'])) {
			if (function_exists('relevanssi_import_options')) {
				check_admin_referer(plugin_basename(__FILE__), 'relevanssi_options');
				$options = $_REQUEST['relevanssi_settings'];
				relevanssi_import_options($options);
			}
		}
		
		if (isset($_REQUEST['search'])) {
			relevanssi_search($_REQUEST['q']);
		}
		
		if (isset($_REQUEST['dowhat'])) {
			if ("add_stopword" == $_REQUEST['dowhat']) {
				if (isset($_REQUEST['term'])) {
					check_admin_referer(plugin_basename(__FILE__), 'relevanssi_options');
					relevanssi_add_stopword($_REQUEST['term']);
				}
			}
		}
	
		if (isset($_REQUEST['addstopword'])) {
			check_admin_referer(plugin_basename(__FILE__), 'relevanssi_options');
			relevanssi_add_stopword($_REQUEST['addstopword']);
		}
		
		if (isset($_REQUEST['removestopword'])) {
			check_admin_referer(plugin_basename(__FILE__), 'relevanssi_options');
			relevanssi_remove_stopword($_REQUEST['removestopword']);
		}
	
		if (isset($_REQUEST['removeallstopwords'])) {
			check_admin_referer(plugin_basename(__FILE__), 'relevanssi_options');
			relevanssi_remove_all_stopwords();
		}

		if (isset($_REQUEST['truncate'])) {
			check_admin_referer(plugin_basename(__FILE__), 'relevanssi_options');
			$clear_all = true;
			relevanssi_truncate_cache($clear_all);
		}
	}
	relevanssi_options_form();
	
	relevanssi_common_words();
	
	echo "<div style='clear:both'></div>";
	
	echo "</div>";
}

function relevanssi_search_stats() {
	$relevanssi_hide_branding = get_option( 'relevanssi_hide_branding' );

	if ( 'on' == $relevanssi_hide_branding )
		$options_txt = __('User Searches', 'relevanssi');
	else
		$options_txt = __('Relevanssi User Searches', 'relevanssi');

	if (isset($_REQUEST['relevanssi_reset']) and current_user_can('manage_options')) {
		check_admin_referer('relevanssi_reset_logs', '_relresnonce');
		if (isset($_REQUEST['relevanssi_reset_code'])) {
			if ($_REQUEST['relevanssi_reset_code'] == 'reset') {
				relevanssi_truncate_logs();
			}
		}
	}

	wp_enqueue_style('dashboard');
	wp_print_styles('dashboard');
	wp_enqueue_script('dashboard');
	wp_print_scripts('dashboard');

	printf("<div class='wrap'><h2>%s</h2>", $options_txt);

	if ( 'on' == $relevanssi_hide_branding )
		echo '<div class="postbox-container">';
	else
		echo '<div class="postbox-container" style="width:70%;">';


	if ('on' == get_option('relevanssi_log_queries')) {
		relevanssi_query_log();
	}
	else {
		echo "<p>Enable query logging to see stats here.</p>";
	}
	
	echo "</div>";
	
	if ('on' != $relevanssi_hide_branding )
		relevanssi_sidebar();
}

function relevanssi_truncate_logs() {
	global $wpdb, $relevanssi_log_table, $relevanssi_variables;
	if (isset($relevanssi_variables['relevanssi_log_table'])) {
		$relevanssi_log_table = $relevanssi_variables['relevanssi_log_table']);
	}
	
	$query = "TRUNCATE $relevanssi_log_table";
	$wpdb->query($query);
	
	echo "<div id='relevanssi-warning' class='updated fade'>Logs clear!</div>";
}


?>