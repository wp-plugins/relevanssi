<?php
/*
Plugin Name: Relevanssi
Plugin URI: http://www.relevanssi.com/
Description: This plugin replaces WordPress search with a relevance-sorting search.
Version: 3.0
Author: Mikko Saari
Author URI: http://www.mikkosaari.fi/
*/

/*  Copyright 2012 Mikko Saari  (email: mikko@mikkosaari.fi)

    This file is part of Relevanssi, a search plugin for WordPress.

    Relevanssi is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    Relevanssi is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with Relevanssi.  If not, see <http://www.gnu.org/licenses/>.
*/

// For debugging purposes
//error_reporting(E_ALL);
//ini_set("display_errors", 1); 
//define('WP-DEBUG', true);
global $wpdb;
//$wpdb->show_errors();

global $relevanssi_variables;

$relevanssi_variables['relevanssi_table'] = $wpdb->prefix . "relevanssi";
$relevanssi_variables['stopword_table'] = $wpdb->prefix . "relevanssi_stopwords";
$relevanssi_variables['log_table'] = $wpdb->prefix . "relevanssi_log";
$relevanssi_variables['relevanssi_cache'] = $wpdb->prefix . "relevanssi_cache";
$relevanssi_variables['relevanssi_excerpt_cache'] = $wpdb->prefix . "relevanssi_excerpt_cache";
$relevanssi_variables['title_boost_default'] = 5;
$relevanssi_variables['comment_boost_default'] = 0.75;
$relevanssi_variables['database_version'] = 1;

require_once('lib/common.php');

function relevanssi_didyoumean($query, $pre, $post, $n = 5) {
	global $wpdb, $relevanssi_variables, $wp_query;
	
	$total_results = $wp_query->found_posts;	
	
	if ($total_results > $n) return;

	$q = "SELECT query, count(query) as c, AVG(hits) as a FROM " . $relevanssi_variables['log_table'] . " WHERE hits > 1 GROUP BY query ORDER BY count(query) DESC";
	$q = apply_filters('relevanssi_didyoumean_query', $q);

	$data = $wpdb->get_results($q);
		
	$distance = -1;
	$closest = "";
	
	foreach ($data as $row) {
		if ($row->c < 2) break;
		$lev = levenshtein($query, $row->query);
		
		if ($lev < $distance || $distance < 0) {
			if ($row->a > 0) {
				$distance = $lev;
				$closest = $row->query;
				if ($lev == 1) break; // get the first with distance of 1 and go
			}
		}
	}
	
	if ($distance > 0) {

 		$url = get_bloginfo('url');
		$url = esc_attr(add_query_arg(array(
			's' => urlencode($closest)

			), $url ));
		echo "$pre<a href='$url'>$closest</a>$post";
 	}
 

}

function relevanssi_check_old_data() {
	if (is_admin()) {
		global $wpdb;

		// Version 3.0 removed relevanssi_tag_boost
		$tag_boost = get_option('relevanssi_tag_boost', 'nothing');
		if ($tag_boost != 'nothing') {
			$post_type_weights = get_option('relevanssi_post_type_weights');
			if (!is_array($post_type_weights)) {
				$post_type_weights = array();
			}
			$post_type_weights['post_tag'] = $tag_boost;
			delete_option('relevanssi_tag_boost');
			update_option('relevanssi_post_type_weights', $post_type_weights);
		}
	
		$index_type = get_option('relevanssi_index_type', 'nothing');
		if ($index_type != 'nothing') {
			// Delete unused options from versions < 3
			$post_types = get_option('relevanssi_index_post_types');
			
			if (!is_array($post_types)) $post_types = array();
			
			switch ($index_type) {
				case "posts":
					array_push($post_types, 'post');
					break;
				case "pages":
					array_push($post_types, 'page');
					break;
				case 'public':
					if (function_exists('get_post_types')) {
						$pt_1 = get_post_types(array('exclude_from_search' => '0'));
						$pt_2 = get_post_types(array('exclude_from_search' => false));
						foreach (array_merge($pt_1, $pt_2) as $type) {
							array_push($post_types, $type);				
						}
					}
					break;
				case "both": 								// really should be "everything"
					$pt = get_post_types();
					foreach ($pt as $type) {
						array_push($post_types, $type);				
					}
					break;
			}
			
			$attachments = get_option('relevanssi_index_attachments');
			if ('on' == $attachments) array_push($post_types, 'attachment');
			
			$custom_types = get_option('relevanssi_custom_types');
			$custom_types = explode(',', $custom_types);
			if (is_array($custom_types)) {
				foreach ($custom_types as $type) {
					$type = trim($type);
					if (substr($type, 0, 1) != '-') {
						array_push($post_types, $type);
					}
				}
			}
			
			update_option('relevanssi_index_post_types', $post_types);
			
			delete_option('relevanssi_index_type');
			delete_option('relevanssi_index_attachments');
			delete_option('relevanssi_custom_types');
		}
	}
}

function _relevanssi_install() {
	global $wpdb, $relevanssi_variables;
	
	add_option('relevanssi_title_boost', $relevanssi_variables['title_boost_default']);
	add_option('relevanssi_comment_boost', $relevanssi_variables['comment_boost_default']);
	add_option('relevanssi_admin_search', 'off');
	add_option('relevanssi_highlight', 'strong');
	add_option('relevanssi_txt_col', '#ff0000');
	add_option('relevanssi_bg_col', '#ffaf75');
	add_option('relevanssi_css', 'text-decoration: underline; text-color: #ff0000');
	add_option('relevanssi_class', 'relevanssi-query-term');
	add_option('relevanssi_excerpts', 'on');
	add_option('relevanssi_excerpt_length', '450');
	add_option('relevanssi_excerpt_type', 'chars');
	add_option('relevanssi_log_queries', 'off');
	add_option('relevanssi_cat', '0');
	add_option('relevanssi_excat', '0');
	add_option('relevanssi_index_fields', '');
	add_option('relevanssi_exclude_posts', ''); 		//added by OdditY
	add_option('relevanssi_include_tags', 'on');		//added by OdditY	
	add_option('relevanssi_hilite_title', ''); 			//added by OdditY	
	add_option('relevanssi_highlight_docs', 'off');
	add_option('relevanssi_highlight_comments', 'off');
	add_option('relevanssi_index_comments', 'none');	//added by OdditY
	add_option('relevanssi_include_cats', '');
	add_option('relevanssi_show_matches', '');
	add_option('relevanssi_show_matches_txt', '(Search hits: %body% in body, %title% in title, %tags% in tags, %comments% in comments. Score: %score%)');
	add_option('relevanssi_fuzzy', 'sometimes');
	add_option('relevanssi_indexed', '');
	add_option('relevanssi_expand_shortcodes', 'on');
	add_option('relevanssi_custom_taxonomies', '');
	add_option('relevanssi_index_author', '');
	add_option('relevanssi_implicit_operator', 'OR');
	add_option('relevanssi_omit_from_logs', '');
	add_option('relevanssi_synonyms', '');
	add_option('relevanssi_index_excerpt', '');
	add_option('relevanssi_index_limit', '500');
	add_option('relevanssi_disable_or_fallback', 'off');
	add_option('relevanssi_respect_exclude', 'on');
	add_option('relevanssi_cache_seconds', '172800');
	add_option('relevanssi_enable_cache', 'off');
	add_option('relevanssi_min_word_length', 3);
	add_option('relevanssi_wpml_only_current', 'on');
	add_option('relevanssi_word_boundaries', 'on');
	add_option('relevanssi_default_orderby', 'relevance');
	add_option('relevanssi_db_version', '0');
	add_option('relevanssi_post_type_weights', '');
	
	relevanssi_create_database_tables($relevanssi_variables['relevanssi_db_version']);
}

if (function_exists('register_uninstall_hook')) {
	register_uninstall_hook(__FILE__, 'relevanssi_uninstall');
	// this doesn't seem to work
}

function relevanssi_get_post($id) {
	global $relevanssi_post_array;
	
	if (isset($relevanssi_post_array[$id])) {
		$post = $relevanssi_post_array[$id];
	}
	else {
		$post = get_post($id);
	}
	return $post;
}

function relevanssi_remove_doc($id) {
	global $wpdb, $relevanssi_table;
	
	$q = "DELETE FROM $relevanssi_table WHERE doc=$id";
	$wpdb->query($q);
}

/*****
 * Interface functions
 */
 
function relevanssi_form_tag_weight($post_type_weights) {
	$taxonomies = get_taxonomies('', 'names'); 
	$label = sprintf(__("Tag weight:", 'relevanssi'), $type);
		
	echo <<<EOH
	<tr>
		<td>
			$label 
		</td>
		<td>
			<input type='text' name='relevanssi_weight_$type' size='4' value='$value' />
		</td>
		<td>&nbsp;</td>
	</tr>
EOH;
	}
}

function relevanssi_options_form() {
	global $relevanssi_variables;
	
	$relevanssi_table = $relevanssi_variables['relevanssi_table'];
	$relevanssi_cache = $relevanssi_variables['relevanssi_cache'];
	
	wp_enqueue_style('dashboard');
	wp_print_styles('dashboard');
	wp_enqueue_script('dashboard');
	wp_print_scripts('dashboard');

	$docs_count = $wpdb->get_var("SELECT COUNT(DISTINCT doc) FROM $relevanssi_table");
	$biggest_doc = $wpdb->get_var("SELECT doc FROM $relevanssi_table ORDER BY doc DESC LIMIT 1");
	$cache_count = $wpdb->get_var("SELECT COUNT(tstamp) FROM $relevanssi_cache");
	
	$title_boost = get_option('relevanssi_title_boost');
	$tag_boost = get_option('relevanssi_tag_boost');
	$comment_boost = get_option('relevanssi_comment_boost');
	$admin_search = get_option('relevanssi_admin_search');
	if ('on' == $admin_search) {
		$admin_search = 'checked="checked"';
	}
	else {
		$admin_search = '';
	}

	$index_limit = get_option('relevanssi_index_limit');

	$excerpts = get_option('relevanssi_excerpts');
	if ('on' == $excerpts) {
		$excerpts = 'checked="checked"';
	}
	else {
		$excerpts = '';
	}
	
	$excerpt_length = get_option('relevanssi_excerpt_length');
	$excerpt_type = get_option('relevanssi_excerpt_type');
	$excerpt_chars = "";
	$excerpt_words = "";
	switch ($excerpt_type) {
		case "chars":
			$excerpt_chars = 'selected="selected"';
			break;
		case "words":
			$excerpt_words = 'selected="selected"';
			break;
	}

	$log_queries = ('on' == get_option('relevanssi_log_queries') ? 'checked="checked"' : '');
	
	$highlight = get_option('relevanssi_highlight');
	$highlight_none = "";
	$highlight_mark = "";
	$highlight_em = "";
	$highlight_strong = "";
	$highlight_col = "";
	$highlight_bgcol = "";
	$highlight_style = "";
	$highlight_class = "";
	switch ($highlight) {
		case "no":
			$highlight_none = 'selected="selected"';
			break;
		case "mark":
			$highlight_mark = 'selected="selected"';
			break;
		case "em":
			$highlight_em = 'selected="selected"';
			break;
		case "strong":
			$highlight_strong = 'selected="selected"';
			break;
		case "col":
			$highlight_col = 'selected="selected"';
			break;
		case "bgcol":
			$highlight_bgcol = 'selected="selected"';
			break;
		case "css":
			$highlight_style = 'selected="selected"';
			break;
		case "class":
			$highlight_class = 'selected="selected"';
			break;
	}

	$custom_taxonomies = get_option('relevanssi_custom_taxonomies');
	$index_fields = get_option('relevanssi_index_fields');
	
	$txt_col = get_option('relevanssi_txt_col');
	$bg_col = get_option('relevanssi_bg_col');
	$css = get_option('relevanssi_css');
	$class = get_option('relevanssi_class');
	
	$cat = get_option('relevanssi_cat');
	$excat = get_option('relevanssi_excat');

	$orderby = get_option('relevanssi_default_orderby');
	$orderby_relevance = ('relevance' == $orderby ? 'selected="selected"' : '');
	$orderby_date = ('post_date' == $orderby ? 'selected="selected"' : '');
	
	$fuzzy_sometimes = ('sometimes' == get_option('relevanssi_fuzzy') ? 'selected="selected"' : '');
	$fuzzy_always = ('always' == get_option('relevanssi_fuzzy') ? 'selected="selected"' : '');
	$fuzzy_never = ('never' == get_option('relevanssi_fuzzy') ? 'selected="selected"' : '');

	$implicit_and = ('AND' == get_option('relevanssi_implicit_operator') ? 'selected="selected"' : '');
	$implicit_or = ('OR' == get_option('relevanssi_implicit_operator') ? 'selected="selected"' : '');

	$expand_shortcodes = ('on' == get_option('relevanssi_expand_shortcodes') ? 'checked="checked"' : '');
	$disablefallback = ('on' == get_option('relevanssi_disable_or_fallback') ? 'checked="checked"' : '');

	$omit_from_logs	= get_option('relevanssi_omit_from_logs');
	
	$synonyms = get_option('relevanssi_synonyms');
	isset($synonyms) ? $synonyms = str_replace(';', "\n", $synonyms) : $synonyms = "";
	
	//Added by OdditY ->
	$expst = get_option('relevanssi_exclude_posts'); 
	$inctags = ('on' == get_option('relevanssi_include_tags') ? 'checked="checked"' : ''); 
	$hititle = ('on' == get_option('relevanssi_hilite_title') ? 'checked="checked"' : ''); 
	$incom_type = get_option('relevanssi_index_comments');
	$incom_type_all = "";
	$incom_type_normal = "";
	$incom_type_none = "";
	switch ($incom_type) {
		case "all":
			$incom_type_all = 'selected="selected"';
			break;
		case "normal":
			$incom_type_normal = 'selected="selected"';
			break;
		case "none":
			$incom_type_none = 'selected="selected"';
			break;
	}//added by OdditY END <-

	$highlight_docs = ('on' == get_option('relevanssi_highlight_docs') ? 'checked="checked"' : ''); 
	$highlight_coms = ('on' == get_option('relevanssi_highlight_comments') ? 'checked="checked"' : ''); 

	$respect_exclude = ('on' == get_option('relevanssi_respect_exclude') ? 'checked="checked"' : ''); 

	$enable_cache = ('on' == get_option('relevanssi_enable_cache') ? 'checked="checked"' : ''); 
	$cache_seconds = get_option('relevanssi_cache_seconds');

	$min_word_length = get_option('relevanssi_min_word_length');

	$inccats = ('on' == get_option('relevanssi_include_cats') ? 'checked="checked"' : ''); 
	$index_author = ('on' == get_option('relevanssi_index_author') ? 'checked="checked"' : ''); 
	$index_excerpt = ('on' == get_option('relevanssi_index_excerpt') ? 'checked="checked"' : ''); 
	
	$show_matches = ('on' == get_option('relevanssi_show_matches') ? 'checked="checked"' : '');
	$show_matches_text = stripslashes(get_option('relevanssi_show_matches_text'));
	
	$wpml_only_current = ('on' == get_option('relevanssi_wpml_only_current') ? 'checked="checked"' : ''); 

	$word_boundaries = ('on' == get_option('relevanssi_word_boundaries') ? 'checked="checked"' : ''); 

?>
	
<div class='postbox-container' style='width:70%;'>
	<form method='post'>
	
    <p><a href="#basic"><?php _e("Basic options", "relevanssi"); ?></a> |
	<a href="#logs"><?php _e("Logs", "relevanssi"); ?></a> |
    <a href="#exclusions"><?php _e("Exclusions and restrictions", "relevanssi"); ?></a> |
    <a href="#excerpts"><?php _e("Custom excerpts", "relevanssi"); ?></a> |
    <a href="#highlighting"><?php _e("Highlighting search results", "relevanssi"); ?></a> |
    <a href="#indexing"><?php _e("Indexing options", "relevanssi"); ?></a> |
    <a href="#caching"><?php _e("Caching", "relevanssi"); ?></a> |
    <a href="#synonyms"><?php _e("Synonyms", "relevanssi"); ?></a> |
    <a href="#stopwords"><?php _e("Stopwords", "relevanssi"); ?></a> |
    <a href="#uninstall"><?php _e("Uninstalling", "relevanssi"); ?></a> |
    <strong><a href="http://www.relevanssi.com/buy-premium/?utm_source=plugin&utm_medium=link&utm_campaign=buy"><?php _e('Buy Relevanssi Premium', 'relevanssi'); ?></a></strong>
    </p>

	<h3><?php _e('Quick tools', 'relevanssi') ?></h3>
	<p>
	<input type='submit' name='submit' value='<?php _e('Save options', 'relevanssi'); ?>' style="background-color:#007f00; border-color:#5fbf00; border-style:solid; border-width:thick; padding: 5px; color: #fff;" />
	<input type="submit" name="index" value="<?php _e('Build the index', 'relevanssi') ?>" style="background-color:#007f00; border-color:#5fbf00; border-style:solid; border-width:thick; padding: 5px; color: #fff;" />
	<input type="submit" name="index_extend" value="<?php _e('Continue indexing', 'relevanssi') ?>"  style="background-color:#e87000; border-color:#ffbb00; border-style:solid; border-width:thick; padding: 5px; color: #fff;" />, <?php _e('add', 'relevanssi'); ?> <input type="text" size="4" name="relevanssi_index_limit" value="<?php echo $index_limit ?>" /> <?php _e('documents.', 'relevanssi'); ?></p>

	<p><?php _e("Use 'Build the index' to build the index with current <a href='#indexing'>indexing options</a>. If you can't finish indexing with one go, use 'Continue indexing' to finish the job. You can change the number of documents to add until you find the largest amount you can add with one go. See 'State of the Index' below to find out how many documents actually go into the index.", 'relevanssi') ?></p>
	
	<h3><?php _e("State of the Index", "relevanssi"); ?></h3>
	<p>
	<?php _e("Documents in the index", "relevanssi"); ?>: <strong><?php echo $docs_count ?></strong><br />
	<?php _e("Highest post ID indexed", "relevanssi"); ?>: <strong><?php echo $biggest_doc ?></strong>
	</p>
	
	<h3 id="basic"><?php _e("Basic options", "relevanssi"); ?></h3>
	
	<p><?php _e('These values affect the weights of the documents. These are all multipliers, so 1 means no change in weight, less than 1 means less weight, and more than 1 means more weight. Setting something to zero makes that worthless. For example, if title weight is more than 1, words in titles are more significant than words elsewhere. If title weight is 0, words in titles won\'t make any difference to the search results.', 'relevanssi'); ?></p>
	
	<label for='relevanssi_title_boost'><?php _e('Title weight:', 'relevanssi'); ?> 
	<input type='text' name='relevanssi_title_boost' size='4' value='<?php echo $title_boost ?>' /></label>
	<small><?php printf(__('Default: %s', 'relevanssi'), $relevanssi_variables['title_boost_default']); ?></small>
	<br />
	<label for='relevanssi_tag_boost'><?php _e('Tag weight:', 'relevanssi'); ?> 
	<input type='text' name='relevanssi_tag_boost' size='4' value='<?php echo $tag_boost ?>' /></label>
	<small><?php printf(__('Default: %s', 'relevanssi'), $relevanssi_variables['tag_boost_default']); ?></small>
	<br />
	<label for='relevanssi_comment_boost'><?php _e('Comment weight:', 'relevanssi'); ?> 
	<input type='text' name='relevanssi_comment_boost' size='4' value='<?php echo $comment_boost ?>' /></label>
	<small><?php printf(__('Default: %s', 'relevanssi'), $relevanssi_variables['comment_boost_default']); ?></small>

	<br /><br />
	<label for='relevanssi_admin_search'><?php _e('Use search for admin:', 'relevanssi'); ?>
	<input type='checkbox' name='relevanssi_admin_search' <?php echo $admin_search ?> /></label>
	<small><?php _e('If checked, Relevanssi will be used for searches in the admin interface', 'relevanssi'); ?></small>

	<br /><br />

	<label for='relevanssi_implicit_operator'><?php _e("Default operator for the search?", "relevanssi"); ?>
	<select name='relevanssi_implicit_operator'>
	<option value='AND' <?php echo $implicit_and ?>><?php _e("AND - require all terms", "relevanssi"); ?></option>
	<option value='OR' <?php echo $implicit_or ?>><?php _e("OR - any term present is enough", "relevanssi"); ?></option>
	</select></label><br />
	<small><?php _e("If you choose AND and the search finds no matches, it will automatically do an OR search.", "relevanssi"); ?></small>
	
	<br /><br />

	<label for='relevanssi_disable_or_fallback'><?php _e("Disable OR fallback:", "relevanssi"); ?>
	<input type='checkbox' name='relevanssi_disable_or_fallback' <?php echo $disablefallback ?> /></label>
	<small><?php _e("If you don't want Relevanssi to fall back to OR search when AND search gets no hits, check this option. For most cases, leave this one unchecked.", 'relevanssi'); ?></small>

	<br /><br />

	<label for='relevanssi_default_orderby'><?php _e('Default order for results:', 'relevanssi'); ?>
	<select name='relevanssi_default_orderby'>
	<option value='relevance' <?php echo $orderby_relevance ?>><?php _e("Relevance (highly recommended)", "relevanssi"); ?></option>
	<option value='post_date' <?php echo $orderby_date ?>><?php _e("Post date", "relevanssi"); ?></option>
	</select></label><br />

	<br /><br />

	<label for='relevanssi_fuzzy'><?php _e('When to use fuzzy matching?', 'relevanssi'); ?>
	<select name='relevanssi_fuzzy'>
	<option value='sometimes' <?php echo $fuzzy_sometimes ?>><?php _e("When straight search gets no hits", "relevanssi"); ?></option>
	<option value='always' <?php echo $fuzzy_always ?>><?php _e("Always", "relevanssi"); ?></option>
	<option value='never' <?php echo $fuzzy_never ?>><?php _e("Don't use fuzzy search", "relevanssi"); ?></option>
	</select></label><br />
	<small><?php _e("Straight search matches just the term. Fuzzy search matches everything that begins or ends with the search term.", "relevanssi"); ?></small>

	<?php if (function_exists('icl_object_id')) : ?>
	<h3 id="wpml"><?php _e('WPML compatibility', 'relevanssi'); ?></h3>
	
	<label for='relevanssi_wpml_only_current'><?php _e("Limit results to current language:", "relevanssi"); ?>
	<input type='checkbox' name='relevanssi_wpml_only_current' <?php echo $wpml_only_current ?> /></label>
	<small><?php _e("If this option is checked, Relevanssi will only return results in the current active language. Otherwise results will include posts in every language.", "relevanssi");?></small>
	
	<?php endif; ?>

	<h3 id="logs"><?php _e('Logs', 'relevanssi'); ?></h3>
	
	<label for='relevanssi_log_queries'><?php _e("Keep a log of user queries:", "relevanssi"); ?>
	<input type='checkbox' name='relevanssi_log_queries' <?php echo $log_queries ?> /></label>
	<small><?php _e("If checked, Relevanssi will log user queries. The log appears in 'User searches' on the Dashboard admin menu.", 'relevanssi'); ?></small>

	<br /><br />

	<label for='relevanssi_omit_from_logs'><?php _e("Don't log queries from these users:", "relevanssi"); ?>
	<input type='text' name='relevanssi_omit_from_logs' size='20' value='<?php echo $omit_from_logs ?>' /></label>
	<small><?php _e("Comma-separated list of user ids that will not be logged.", "relevanssi"); ?></small>

	<p><?php _e("If you enable logs, you can see what your users are searching for. Logs are also needed to use the 'Did you mean?' feature. You can prevent your own searches from getting in the logs with the omit feature.", "relevanssi"); ?></p>

	<h3 id="exclusions"><?php _e("Exclusions and restrictions", "relevanssi"); ?></h3>
	
	<label for='relevanssi_cat'><?php _e('Restrict search to these categories and tags:', 'relevanssi'); ?>
	<input type='text' name='relevanssi_cat' size='20' value='<?php echo $cat ?>' /></label><br />
	<small><?php _e("Enter a comma-separated list of category and tag IDs to restrict search to those categories or tags. You can also use <code>&lt;input type='hidden' name='cat' value='list of cats and tags' /&gt;</code> in your search form. The input field will 	overrun this setting.", 'relevanssi'); ?></small>

	<br /><br />

	<label for='relevanssi_excat'><?php _e('Exclude these categories and tags from search:', 'relevanssi'); ?>
	<input type='text' name='relevanssi_excat' size='20' value='<?php echo $excat ?>' /></label><br />
	<small><?php _e("Enter a comma-separated list of category and tag IDs that are excluded from search results. This only works here, you can't use the input field option (WordPress doesn't pass custom parameters there).", 'relevanssi'); ?></small>

	<br /><br />

	<label for='relevanssi_excat'><?php _e('Exclude these posts/pages from search:', 'relevanssi'); ?>
	<input type='text' name='relevanssi_expst' size='20' value='<?php echo $expst ?>' /></label><br />
	<small><?php _e("Enter a comma-separated list of post/page IDs that are excluded from search results. This only works here, you can't use the input field option (WordPress doesn't pass custom parameters there).", 'relevanssi'); ?></small>

	<br /><br />

	<label for='relevanssi_respect_exclude'><?php _e('Respect exclude_from_search for custom post types:', 'relevanssi'); ?>
	<input type='checkbox' name='relevanssi_respect_exclude' <?php echo $respect_exclude ?> /></label><br />
	<small><?php _e("If checked, Relevanssi won't display posts of custom post types that have 'exclude_from_search' set to true. If not checked, Relevanssi will display anything that is indexed.", 'relevanssi'); ?></small>

	<h3 id="excerpts"><?php _e("Custom excerpts/snippets", "relevanssi"); ?></h3>
	
	<label for='relevanssi_excerpts'><?php _e("Create custom search result snippets:", "relevanssi"); ?>
	<input type='checkbox' name='relevanssi_excerpts' <?php echo $excerpts ?> /></label><br />
	<small><?php _e("If checked, Relevanssi will create excerpts that contain the search term hits. To make them work, make sure your search result template uses the_excerpt() to display post excerpts.", 'relevanssi'); ?></small>
	
	<br /><br />
	
	<label for='relevanssi_excerpt_length'><?php _e("Length of the snippet:", "relevanssi"); ?>
	<input type='text' name='relevanssi_excerpt_length' size='4' value='<?php echo $excerpt_length ?>' /></label>
	<select name='relevanssi_excerpt_type'>
	<option value='chars' <?php echo $excerpt_chars ?>><?php _e("characters", "relevanssi"); ?></option>
	<option value='words' <?php echo $excerpt_words ?>><?php _e("words", "relevanssi"); ?></option>
	</select><br />
	<small><?php _e("This must be an integer.", "relevanssi"); ?></small>

	<br /><br />

	<label for='relevanssi_show_matches'><?php _e("Show breakdown of search hits in excerpts:", "relevanssi"); ?>
	<input type='checkbox' name='relevanssi_show_matches' <?php echo $show_matches ?> /></label>
	<small><?php _e("Check this to show more information on where the search hits were made. Requires custom snippets to work.", "relevanssi"); ?></small>

	<br /><br />

	<label for='relevanssi_show_matches_text'><?php _e("The breakdown format:", "relevanssi"); ?>
	<input type='text' name='relevanssi_show_matches_text' value="<?php echo $show_matches_text ?>" size='20' /></label>
	<small><?php _e("Use %body%, %title%, %tags% and %comments% to display the number of hits (in different parts of the post), %total% for total hits, %score% to display the document weight and %terms% to show how many hits each search term got. No double quotes (\") allowed!", "relevanssi"); ?></small>

	<h3 id="highlighting"><?php _e("Search hit highlighting", "relevanssi"); ?></h3>

	<?php _e("First, choose the type of highlighting used:", "relevanssi"); ?><br />

	<div style='margin-left: 2em'>
	<label for='relevanssi_highlight'><?php _e("Highlight query terms in search results:", 'relevanssi'); ?>
	<select name='relevanssi_highlight'>
	<option value='no' <?php echo $highlight_none ?>><?php _e('No highlighting', 'relevanssi'); ?></option>
	<option value='mark' <?php echo $highlight_mark ?>>&lt;mark&gt;</option>
	<option value='em' <?php echo $highlight_em ?>>&lt;em&gt;</option>
	<option value='strong' <?php echo $highlight_strong ?>>&lt;strong&gt;</option>
	<option value='col' <?php echo $highlight_col ?>><?php _e('Text color', 'relevanssi'); ?></option>
	<option value='bgcol' <?php echo $highlight_bgcol ?>><?php _e('Background color', 'relevanssi'); ?></option>
	<option value='css' <?php echo $highlight_style ?>><?php _e("CSS Style", 'relevanssi'); ?></option>
	<option value='class' <?php echo $highlight_class ?>><?php _e("CSS Class", 'relevanssi'); ?></option>
	</select></label>
	<small><?php _e("Highlighting isn't available unless you use custom snippets", 'relevanssi'); ?></small>
	
	<br />

	<label for='relevanssi_hilite_title'><?php _e("Highlight query terms in result titles too:", 'relevanssi'); ?>
	<input type='checkbox' name='relevanssi_hilite_title' <?php echo $hititle ?> /></label>
	<small><?php _e("", 'relevanssi'); ?></small>

	<br />

	<label for='relevanssi_highlight_docs'><?php _e("Highlight query terms in documents:", 'relevanssi'); ?>
	<input type='checkbox' name='relevanssi_highlight_docs' <?php echo $highlight_docs ?> /></label>
	<small><?php _e("Highlights hits when user opens the post from search results. This is based on HTTP referrer, so if that's blocked, there'll be no highlights.", "relevanssi"); ?></small>

	<br />
	
	<label for='relevanssi_highlight_comments'><?php _e("Highlight query terms in comments:", 'relevanssi'); ?>
	<input type='checkbox' name='relevanssi_highlight_comments' <?php echo $highlight_coms ?> /></label>
	<small><?php _e("Highlights hits in comments when user opens the post from search results.", "relevanssi"); ?></small>

	<br />
	
	<label for='relevanssi_word_boundaries'><?php _e("Uncheck this if you use non-ASCII characters:", 'relevanssi'); ?>
	<input type='checkbox' name='relevanssi_word_boundaries' <?php echo $word_boundaries ?> /></label>
	<small><?php _e("If you use non-ASCII characters (like Cyrillic alphabet) and the highlights don't work, uncheck this option to make highlights work.", "relevanssi"); ?></small>

	<br /><br />
	</div>
	
	<?php _e("Then adjust the settings for your chosen type:", "relevanssi"); ?><br />

	<div style='margin-left: 2em'>
	
	<label for='relevanssi_txt_col'><?php _e("Text color for highlights:", "relevanssi"); ?>
	<input type='text' name='relevanssi_txt_col' size='7' value='<?php echo $txt_col ?>' /></label>
	<small><?php _e("Use HTML color codes (#rgb or #rrggbb)", "relevanssi"); ?></small>

	<br />
	
	<label for='relevanssi_bg_col'><?php _e("Background color for highlights:", "relevanssi"); ?>
	<input type='text' name='relevanssi_bg_col' size='7' value='<?php echo $bg_col ?>' /></label>
	<small><?php _e("Use HTML color codes (#rgb or #rrggbb)", "relevanssi"); ?></small>

	<br />
	
	<label for='relevanssi_css'><?php _e("CSS style for highlights:", "relevanssi"); ?>
	<input type='text' name='relevanssi_css' size='30' value='<?php echo $css ?>' /></label>
	<small><?php _e("You can use any CSS styling here, style will be inserted with a &lt;span&gt;", "relevanssi"); ?></small>

	<br />
	
	<label for='relevanssi_css'><?php _e("CSS class for highlights:", "relevanssi"); ?>
	<input type='text' name='relevanssi_class' size='10' value='<?php echo $class ?>' /></label>
	<small><?php _e("Name a class here, search results will be wrapped in a &lt;span&gt; with the class", "relevanssi"); ?></small>

	</div>
	
	<br />
	<br />
	
	<input type='submit' name='submit' value='<?php _e('Save the options', 'relevanssi'); ?>' class="button button-primary"  />

	<h3 id="indexing"><?php _e('Indexing options', 'relevanssi'); ?></h3>
	
	<small><?php _e("This determines which post types are included in the index. Choosing 'everything'
	will include posts, pages and all custom post types. 'All public post types' includes all
	registered post types that don't have the 'exclude_from_search' set to true. This includes post,
	page, and possible custom types. 'All public types' requires at least WP 2.9, otherwise it's the
	same as 'everything'. If you choose 'Custom', only the post types listed below are indexed.
	Note: attachments are covered with a separate option below.", "relevanssi"); ?></small>

	<br /><br />
	
	<label for='relevanssi_min_word_length'><?php _e("Minimum word length to index", "relevanssi"); ?>:
	<input type='text' name='relevanssi_min_word_length' size='30' value='<?php echo $min_word_length ?>' /></label><br />
	<small><?php _e("Words shorter than this number will not be indexed.", "relevanssi"); ?></small>

	<br /><br />

	<label for='relevanssi_expand_shortcodes'><?php _e("Expand shortcodes in post content:", "relevanssi"); ?>
	<input type='checkbox' name='relevanssi_expand_shortcodes' <?php echo $expand_shortcodes ?> /></label><br />
	<small><?php _e("If checked, Relevanssi will expand shortcodes in post content before indexing. Otherwise shortcodes will be stripped. If you use shortcodes to include dynamic content, Relevanssi will not keep the index updated, the index will reflect the status of the shortcode content at the moment of indexing.", "relevanssi"); ?></small>

	<br /><br />

	<label for='relevanssi_inctags'><?php _e('Index and search your posts\' tags:', 'relevanssi'); ?>
	<input type='checkbox' name='relevanssi_inctags' <?php echo $inctags ?> /></label><br />
	<small><?php _e("If checked, Relevanssi will also index and search the tags of your posts. Remember to rebuild the index if you change this option!", 'relevanssi'); ?></small>

	<br /><br />

	<label for='relevanssi_inccats'><?php _e('Index and search your posts\' categories:', 'relevanssi'); ?>
	<input type='checkbox' name='relevanssi_inccats' <?php echo $inccats ?> /></label><br />
	<small><?php _e("If checked, Relevanssi will also index and search the categories of your posts. Category titles will pass through 'single_cat_title' filter. Remember to rebuild the index if you change this option!", 'relevanssi'); ?></small>

	<br /><br />

	<label for='relevanssi_index_author'><?php _e('Index and search your posts\' authors:', 'relevanssi'); ?>
	<input type='checkbox' name='relevanssi_index_author' <?php echo $index_author ?> /></label><br />
	<small><?php _e("If checked, Relevanssi will also index and search the authors of your posts. Author display name will be indexed. Remember to rebuild the index if you change this option!", 'relevanssi'); ?></small>

	<br /><br />

	<label for='relevanssi_index_excerpt'><?php _e('Index and search post excerpts:', 'relevanssi'); ?>
	<input type='checkbox' name='relevanssi_index_excerpt' <?php echo $index_excerpt ?> /></label><br />
	<small><?php _e("If checked, Relevanssi will also index and search the excerpts of your posts.Remember to rebuild the index if you change this option!", 'relevanssi'); ?></small>

	<br /><br />
	
	<label for='relevanssi_index_comments'><?php _e("Index and search these comments:", "relevanssi"); ?>
	<select name='relevanssi_index_comments'>
	<option value='none' <?php echo $incom_type_none ?>><?php _e("none", "relevanssi"); ?></option>
	<option value='normal' <?php echo $incom_type_normal ?>><?php _e("normal", "relevanssi"); ?></option>
	<option value='all' <?php echo $incom_type_all ?>><?php _e("all", "relevanssi"); ?></option>
	</select></label><br />
	<small><?php _e("Relevanssi will index and search ALL (all comments including track- &amp; pingbacks and custom comment types), NONE (no comments) or NORMAL (manually posted comments on your blog).<br />Remember to rebuild the index if you change this option!", 'relevanssi'); ?></small>

	<br /><br />

	<label for='relevanssi_index_fields'><?php _e("Custom fields to index:", "relevanssi"); ?>
	<input type='text' name='relevanssi_index_fields' size='30' value='<?php echo $index_fields ?>' /></label><br />
	<small><?php _e("A comma-separated list of custom field names to include in the index.", "relevanssi"); ?></small>

	<br /><br />

	<label for='relevanssi_custom_taxonomies'><?php _e("Custom taxonomies to index:", "relevanssi"); ?>
	<input type='text' name='relevanssi_custom_taxonomies' size='30' value='<?php echo $custom_taxonomies ?>' /></label><br />
	<small><?php _e("A comma-separated list of custom taxonomies to include in the index.", "relevanssi"); ?></small>

	<br /><br />

	<input type='submit' name='index' value='<?php _e("Save indexing options and build the index", 'relevanssi'); ?>' class="button button-primary"  />

	<input type='submit' name='index_extend' value='<?php _e("Continue indexing", 'relevanssi'); ?>'  class="button" />

	<h3 id="caching"><?php _e("Caching", "relevanssi"); ?></h3>

	<p><?php _e("Warning: In many cases caching is not useful, and in some cases can be even harmful. Do not
	activate cache unless you have a good reason to do so.", 'relevanssi'); ?></p>
	
	<label for='relevanssi_enable_cache'><?php _e('Enable result and excerpt caching:', 'relevanssi'); ?>
	<input type='checkbox' name='relevanssi_enable_cache' <?php echo $enable_cache ?> /></label><br />
	<small><?php _e("If checked, Relevanssi will cache search results and post excerpts.", 'relevanssi'); ?></small>

	<br /><br />
	
	<label for='relevanssi_cache_seconds'><?php _e("Cache expire (in seconds):", "relevanssi"); ?>
	<input type='text' name='relevanssi_cache_seconds' size='30' value='<?php echo $cache_seconds ?>' /></label><br />
	<small><?php _e("86400 = day", "relevanssi"); ?></small>

	<br /><br />
	
	<?php _e("Entries in the cache", 'relevanssi'); ?>: <?php echo $cache_count; ?>

	<br /><br />
	
	<input type='submit' name='truncate' value='<?php _e('Clear all caches', 'relevanssi'); ?>' class="button" />

	<h3 id="synonyms"><?php _e("Synonyms", "relevanssi"); ?></h3>
	
	<p><textarea name='relevanssi_synonyms' rows='9' cols='60'><?php echo $synonyms ?></textarea></p>

	<p><small><?php _e("Add synonyms here in 'key = value' format. When searching with the OR operator, any search of 'key' will be expanded to include 'value' as well. Using phrases is possible. The key-value pairs work in one direction only, but you can of course repeat the same pair reversed.", "relevanssi"); ?></small></p>

	<input type='submit' name='submit' value='<?php _e('Save the options', 'relevanssi'); ?>'  class="button"  />

	<h3 id="stopwords"><?php _e("Stopwords", "relevanssi"); ?></h3>
	
	<?php relevanssi_show_stopwords(); ?>
	
	<h3 id="uninstall"><?php _e("Uninstalling the plugin", "relevanssi"); ?></h3>
	
	<p><?php _e("If you want to uninstall the plugin, start by clicking the button below to wipe clean the options and tables created by the plugin, then remove it from the plugins list.", "relevanssi");	 ?></p>
	
	<input type='submit' name='uninstall' value='<?php _e("Remove plugin data", "relevanssi"); ?>'  class="button" />

	</form>
</div>

	<?php

	relevanssi_sidebar();
}

function relevanssi_show_stopwords() {
	global $wpdb, $stopword_table, $wp_version;

	_e("<p>Enter a word here to add it to the list of stopwords. The word will automatically be removed from the index, so re-indexing is not necessary. You can enter many words at the same time, separate words with commas.</p>", 'relevanssi');

?><label for="addstopword"><p><?php _e("Stopword(s) to add: ", 'relevanssi'); ?><textarea name="addstopword" rows="2" cols="40"></textarea>
<input type="submit" value="<?php _e("Add", 'relevanssi'); ?>" class='button' /></p></label>
<?php

	_e("<p>Here's a list of stopwords in the database. Click a word to remove it from stopwords. Removing stopwords won't automatically return them to index, so you need to re-index all posts after removing stopwords to get those words back to index.", 'relevanssi');

	if (function_exists("plugins_url")) {
		if (version_compare($wp_version, '2.8dev', '>' )) {
			$src = plugins_url('delete.png', __FILE__);
		}
		else {
			$src = plugins_url('relevanssi/delete.png');
		}
	}
	else {
		// We can't check, so let's assume something sensible
		$src = '/wp-content/plugins/relevanssi/delete.png';
	}
	
	echo "<ul>";
	$results = $wpdb->get_results("SELECT * FROM $stopword_table");
	$exportlist = array();
	foreach ($results as $stopword) {
		$sw = $stopword->stopword; 
		printf('<li style="display: inline;"><input type="submit" name="removestopword" value="%s"/></li>', $sw, $src, $sw);
		array_push($exportlist, $sw);
	}
	echo "</ul>";
	
?>
<p><input type="submit" name="removeallstopwords" value="<?php _e('Remove all stopwords', 'relevanssi'); ?>" class='button' /></p>
<?php

	$exportlist = implode(", ", $exportlist);
	
?>
<p><?php _e("Here's a list of stopwords you can use to export the stopwords to another blog.", "relevanssi"); ?></p>

<textarea name="stopwords" rows="2" cols="40"><?php echo $exportlist; ?></textarea>
<?php

}

function relevanssi_sidebar() {
	$tweet = 'http://twitter.com/home?status=' . urlencode("I'm using Relevanssi, a better search for WordPress. http://wordpress.org/extend/plugins/relevanssi/ #relevanssi #wordpress");
	if (function_exists("plugins_url")) {
		global $wp_version;
		if (version_compare($wp_version, '2.8dev', '>' )) {
			$facebooklogo = plugins_url('facebooklogo.jpg', __FILE__);
		}
		else {
			$facebooklogo = plugins_url('relevanssi/facebooklogo.jpg');
		}
	}
	else {
		// We can't check, so let's assume something sensible
		$facebooklogo = '/wp-content/plugins/relevanssi/facebooklogo.jpg';
	}

	echo <<<EOH
<div class="postbox-container" style="width:20%; margin-top: 35px; margin-left: 15px;">
	<div class="metabox-holder">	
		<div class="meta-box-sortables" style="min-height: 0">
			<div id="relevanssi_donate" class="postbox">
			<h3 class="hndle"><span>Buy Relevanssi Premium!</span></h3>
			<div class="inside">
<p>Do you want more features? Support Relevanssi development? Get a
better search experience for your users?</p>

<p><strong>Go Premium!</strong> Buy Relevanssi Premium. See <a href="http://www.relevanssi.com/features/?utm_source=plugin&utm_medium=link&utm_campaign=features">feature
comparison</a> and <a href="http://www.relevanssi.com/buy-premium/?utm_source=plugin&utm_medium=link&utm_campaign=license">license prices</a>.</p>
			</div>
		</div>
	</div>
		
		<div class="meta-box-sortables" style="min-height: 0">
			<div id="relevanssi_donate" class="postbox">
			<h3 class="hndle"><span>Relevanssi in Facebook</span></h3>
			<div class="inside">
			<div style="float: left; margin-right: 5px"><img src="$facebooklogo" width="45" height="43" alt="Facebook" /></div>
			<p><a href="http://www.facebook.com/relevanssi">Check
			out the Relevanssi page in Facebook</a> for news and updates about your favourite plugin.</p>
			</div>
		</div>
	</div>

		<div class="meta-box-sortables" style="min-height: 0">
			<div id="relevanssi_donate" class="postbox">
			<h3 class="hndle"><span>Help and support</span></h3>
			<div class="inside">
			<p>For Relevanssi support, see:</p>
			
			<p>- <a href="http://wordpress.org/tags/relevanssi?forum_id=10">WordPress.org forum</a><br />
			- <a href="http://www.relevanssi.com/category/knowledge-base/?utm_source=plugin&utm_medium=link&utm_campaign=kb">Knowledge base</a></p>
			</div>
		</div>
	</div>
	
</div>
</div>
EOH;
}

/**
 * Wrapper function for Premium compatibility.
 */
function relevanssi_install() {
	_relevanssi_install();
}

?>