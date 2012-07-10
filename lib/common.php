<?php
/**
Relevanssi common functions library
- Premium 1.8.3
- Free 3.0
*/

function relevanssi_menu() {
	add_options_page(
		'Relevanssi Premium',
		'Relevanssi Premium',
		'manage_options',
		__FILE__,
		'relevanssi_options'
	);
	add_dashboard_page(
		__('User searches', 'relevanssi'),
		__('User searches', 'relevanssi'),
		'edit_pages',
		__FILE__,
		'relevanssi_search_stats'
	);
}

add_filter('relevanssi_query_filter', 'relevanssi_limit_filter');
function relevanssi_limit_filter($query) {
	if (get_option('relevanssi_throttle', 'on') == 'on') {
		return $query . " ORDER BY tf DESC LIMIT 500";
	}
	else {
		return $query;
	}
}

// BEGIN added by renaissancehack
function relevanssi_update_child_posts($new_status, $old_status, $post) {
// called by 'transition_post_status' action hook when a post is edited/published/deleted
//  and calls appropriate indexing function on child posts/attachments
    global $wpdb;

    $index_statuses = array('publish', 'private', 'draft', 'pending', 'future');
    if (($new_status == $old_status)
          || (in_array($new_status, $index_statuses) && in_array($old_status, $index_statuses))
          || (in_array($post->post_type, array('attachment', 'revision')))) {
        return;
    }
    $q = "SELECT * FROM $wpdb->posts WHERE post_parent=$post->ID AND post_type!='revision'";
    $child_posts = $wpdb->get_results($q);
    if ($child_posts) {
        if (!in_array($new_status, $index_statuses)) {
            foreach ($child_posts as $post) {
                relevanssi_delete($post->ID);
            }
        } else {
            foreach ($child_posts as $post) {
                relevanssi_publish($post->ID);
            }
        }
    }
}
// END added by renaissancehack

function relevanssi_edit($post) {
	// Check if the post is public
	global $wpdb;

	$post_status = get_post_status($post);
	if ('auto-draft' == $post_status) return;

// BEGIN added by renaissancehack
    //  if post_status is "inherit", get post_status from parent
    if ($post_status == 'inherit') {
        $post_type = $wpdb->get_var("SELECT post_type FROM $wpdb->posts WHERE ID=$post");
    	$post_status = $wpdb->get_var("SELECT p.post_status FROM $wpdb->posts p, $wpdb->posts c WHERE c.ID=$post AND c.post_parent=p.ID");
    }
// END added by renaissancehack

	$index_statuses = array('publish', 'private', 'draft', 'pending', 'future');
	if (!in_array($post_status, $index_statuses)) {
 		// The post isn't supposed to be indexed anymore, remove it from index
 		relevanssi_remove_doc($post);
	}
	else {
		relevanssi_publish($post);
	}
}

function relevanssi_purge_excerpt_cache($post) {
	global $wpdb, $relevanssi_excerpt_cache;
	
	$wpdb->query("DELETE FROM $relevanssi_excerpt_cache WHERE post = $post");
}

function relevanssi_delete($post) {
	relevanssi_remove_doc($post);
	relevanssi_purge_excerpt_cache($post);
}

function relevanssi_publish($post, $bypassglobalpost = false) {
	global $relevanssi_publish_doc;
	
	$post_status = get_post_status($post);
	if ('auto-draft' == $post_status) return;

	$custom_fields = relevanssi_get_custom_fields();
	relevanssi_index_doc($post, true, $custom_fields, $bypassglobalpost);
}

// added by lumpysimon
// when we're using wp_insert_post to update a post,
// we don't want to use the global $post object
function relevanssi_insert_edit($post_id) {
	global $wpdb;

	$post_status = get_post_status( $post_id );
	if ( 'auto-draft' == $post_status ) return;

    if ( $post_status == 'inherit' ) {
        $post_type = $wpdb->get_var( "SELECT post_type FROM $wpdb->posts WHERE ID=$post_id" );
	    $post_status = $wpdb->get_var( "SELECT p.post_status FROM $wpdb->posts p, $wpdb->posts c WHERE c.ID=$post_id AND c.post_parent=p.ID" );
    }

	$index_statuses = array('publish', 'private', 'draft', 'future', 'pending');
	if ( !in_array( $post_status, $index_statuses ) ) {
		// The post isn't supposed to be indexed anymore, remove it from index
		relevanssi_remove_doc( $post_id );
	}
	else {
		$bypassglobalpost = true;
		relevanssi_publish($post_id, $bypassglobalpost);
	}
}

//Added by OdditY -> 
function relevanssi_comment_edit($comID) {
	relevanssi_comment_index($comID,$action="update");
}

function relevanssi_comment_remove($comID) {
	relevanssi_comment_index($comID,$action="remove");
}

function relevanssi_comment_index($comID,$action="add") {
	global $wpdb;
	$comtype = get_option("relevanssi_index_comments");
	switch ($comtype) {
		case "all": 
			// all (incl. customs, track-&pingbacks)
			break;
		case "normal": 
			// normal (excl. customs, track-&pingbacks)
			$restriction=" AND comment_type='' ";
			break;
		default:
			// none (don't index)
			return ;
	}
	switch ($action) {
		case "update": 
			//(update) comment status changed:
			$cpostID = $wpdb->get_var("SELECT comment_post_ID FROM $wpdb->comments WHERE comment_ID='$comID'".$restriction);
			break;
		case "remove": 
			//(remove) approved comment will be deleted (if not approved, its not in index):
			$cpostID = $wpdb->get_var("SELECT comment_post_ID FROM $wpdb->comments WHERE comment_ID='$comID' AND comment_approved='1'".$restriction);
			if($cpostID!=NULL) {
				//empty comment_content & reindex, then let WP delete the empty comment
				$wpdb->query("UPDATE $wpdb->comments SET comment_content='' WHERE comment_ID='$comID'");
			}				
			break;
		default:
			// (add) new comment:
			$cpostID = $wpdb->get_var("SELECT comment_post_ID FROM $wpdb->comments WHERE comment_ID='$comID' AND comment_approved='1'".$restriction);
			break;
	}
	if($cpostID!=NULL) relevanssi_publish($cpostID);	
}
//Added by OdditY END <-

// Reads automatically the correct stopwords for the current language set in WPLANG.
function relevanssi_populate_stopwords() {
	global $wpdb, $relevanssi_stopword_table;

	if (WPLANG == '') {
		$lang = "en_GB";
	}
	else {
		$lang = WPLANG;
	}
	
	include('stopwords.' . $lang);

	if (is_array($stopwords) && count($stopwords) > 0) {
		foreach ($stopwords as $word) {
			$q = $wpdb->prepare("INSERT IGNORE INTO $relevanssi_stopword_table (stopword) VALUES (%s)", trim($word));
			$wpdb->query($q);
		}
	}
}

function relevanssi_fetch_stopwords() {
	global $wpdb, $stopword_list, $relevanssi_stopword_table;
	
	if (count($stopword_list) < 1) {
		$results = $wpdb->get_results("SELECT stopword FROM $relevanssi_stopword_table");
		foreach ($results as $word) {
			$stopword_list[] = $word->stopword;
		}
	}
	
	return $stopword_list;
}

function relevanssi_query($posts, $query = false) {
	$admin_search = get_option('relevanssi_admin_search');
	($admin_search == 'on') ? $admin_search = true : $admin_search = false;

	global $relevanssi_active;
	global $wp_query;

	$search_ok = true; 							// we will search!
	if (!is_search()) {
		$search_ok = false;						// no, we can't
	}
	
	// Uses $wp_query->is_admin instead of is_admin() to help with Ajax queries that
	// use 'admin_ajax' hook (which sets is_admin() to true whether it's an admin search
	// or not.
	if (is_search() && $wp_query->is_admin) {
		$search_ok = false; 					// but if this is an admin search, reconsider
		if ($admin_search) $search_ok = true; 	// yes, we can search!
	}

	$search_ok = apply_filters('relevanssi_search_ok', $search_ok);
	
	if ($relevanssi_active) {
		$search_ok = false;						// Relevanssi is already in action
	}

	if ($search_ok) {
		$wp_query = apply_filters('relevanssi_modify_wp_query', $wp_query);
		$posts = relevanssi_do_query($wp_query);
	}

	return $posts;
}

function relevanssi_fetch_excerpt($post, $query) {
	global $wpdb, $relevanssi_excerpt_cache;

	$query = mysql_real_escape_string($query);	
	$excerpt = $wpdb->get_var("SELECT excerpt FROM $relevanssi_excerpt_cache WHERE post = $post AND query = '$query'");
	
	if (!$excerpt) return null;
	
	return $excerpt;
}

function relevanssi_store_excerpt($post, $query, $excerpt) {
	global $wpdb, $relevanssi_excerpt_cache;
	
	$query = mysql_real_escape_string($query);
	$excerpt = mysql_real_escape_string($excerpt);

	$wpdb->query("INSERT INTO $relevanssi_excerpt_cache (post, query, excerpt)
		VALUES ($post, '$query', '$excerpt')
		ON DUPLICATE KEY UPDATE excerpt = '$excerpt'");
}

function relevanssi_fetch_hits($param) {
	global $wpdb, $relevanssi_cache;

	$time = get_option('relevanssi_cache_seconds', 172800);

	$hits = $wpdb->get_var("SELECT hits FROM $relevanssi_cache WHERE param = '$param' AND UNIX_TIMESTAMP() - UNIX_TIMESTAMP(tstamp) < $time");
	
	if ($hits) {
		return unserialize($hits);
	}
	else {
		return null;
	}
}

function relevanssi_store_hits($param, $data) {
	global $wpdb, $relevanssi_cache;

	$param = mysql_real_escape_string($param);
	$data = mysql_real_escape_string($data);
	$wpdb->query("INSERT INTO $relevanssi_cache (param, hits)
		VALUES ('$param', '$data')
		ON DUPLICATE KEY UPDATE hits = '$data'");
}

// thanks to rvencu
function relevanssi_wpml_filter($data) {
	$use_filter = get_option('relevanssi_wpml_only_current');
	if ('on' == $use_filter) {
		//save current blog language
		$lang = get_bloginfo('language');
		$filtered_hits = array();
		foreach ($data[0] as $hit) {
			if (isset($hit->blog_id)) {
				switch_to_blog($hit->blog_id);
			}
			global $sitepress;
			if (function_exists('icl_object_id') && $sitepress->is_translated_post_type($hit->post_type)) {
			    if ($hit->ID == icl_object_id($hit->ID, $hit->post_type,false,ICL_LANGUAGE_CODE))
			        $filtered_hits[] = $hit;
			}
			// if there is no WPML but the target blog has identical language with current blog,
			// we use the hits. Note en-US is not identical to en-GB!
			elseif (get_bloginfo('language') == $lang) {
				$filtered_hits[] = $hit;
			}
			if (isset($hit->blog_id)) {
				restore_current_blog();
			}
		}
		return array($filtered_hits, $data[1]);
	}
	return $data;
}

/**
 * Function by Matthew Hood http://my.php.net/manual/en/function.sort.php#75036
 */
function relevanssi_object_sort(&$data, $key, $dir = 'desc') {
	$dir = strtolower($dir);
    for ($i = count($data) - 1; $i >= 0; $i--) {
		$swapped = false;
      	for ($j = 0; $j < $i; $j++) {
      		if ('asc' == $dir) {
	           	if ($data[$j]->$key > $data[$j + 1]->$key) { 
    		        $tmp = $data[$j];
        	        $data[$j] = $data[$j + 1];
            	    $data[$j + 1] = $tmp;
                	$swapped = true;
	           	}
	        }
			else {
	           	if ($data[$j]->$key < $data[$j + 1]->$key) { 
    		        $tmp = $data[$j];
        	        $data[$j] = $data[$j + 1];
            	    $data[$j + 1] = $tmp;
                	$swapped = true;
	           	}
			}
    	}
	    if (!$swapped) return;
    }
}

function relevanssi_show_matches($data, $hit) {
	isset($data['body_matches'][$hit]) ? $body = $data['body_matches'][$hit] : $body = "";
	isset($data['title_matches'][$hit]) ? $title = $data['title_matches'][$hit] : $title = "";
	isset($data['tag_matches'][$hit]) ? $tag = $data['tag_matches'][$hit] : $tag = "";
	isset($data['comment_matches'][$hit]) ? $comment = $data['comment_matches'][$hit] : $comment = "";
	isset($data['scores'][$hit]) ? $score = round($data['scores'][$hit], 2) : $score = 0;
	isset($data['term_hits'][$hit]) ? $term_hits_a = $data['term_hits'][$hit] : $term_hits_a = array();
	arsort($term_hits_a);
	$term_hits = "";
	$total_hits = 0;
	foreach ($term_hits_a as $term => $hits) {
		$term_hits .= " $term: $hits";
		$total_hits += $hits;
	}
	
	$text = get_option('relevanssi_show_matches_text');
	$replace_these = array("%body%", "%title%", "%tags%", "%comments%", "%score%", "%terms%", "%total%");
	$replacements = array($body, $title, $tag, $comment, $score, $term_hits, $total_hits);
	
	$result = " " . str_replace($replace_these, $replacements, $text);
	
	return apply_filters('relevanssi_show_matches', $result);
}

function relevanssi_update_log($query, $hits) {
	if(isset($_SERVER['HTTP_USER_AGENT']) && $_SERVER['HTTP_USER_AGENT'] == "Mediapartners-Google")
		return;

	global $wpdb, $relevanssi_log_table;
	
	$user = wp_get_current_user();
	if ($user->ID != 0 && get_option('relevanssi_omit_from_logs')) {
		$omit = explode(",", get_option('relevanssi_omit_from_logs'));
		if (in_array($user->ID, $omit)) return;
		if (in_array($user->user_login, $omit)) return;
	}
		
	$q = $wpdb->prepare("INSERT INTO $relevanssi_log_table (query, hits, user_id, ip) VALUES (%s, %d, %d, %s)", $query, intval($hits), $user->ID, $_SERVER['REMOTE_ADDR']);
	$wpdb->query($q);
}

function relevanssi_default_post_ok($post_ok, $doc) {
	$status = relevanssi_get_post_status($doc);

	// if it's not public, don't show
	if ('publish' != $status) {
		$post_ok = false;
	}
	
	// ...unless
	
	if ('private' == $status) {
		$post_ok = false;

		if (function_exists('awp_user_can')) {
			// Role-Scoper, though Role-Scoper actually uses a different function to do this
			// So whatever is in here doesn't actually run.
			$current_user = wp_get_current_user();
			$post_ok = awp_user_can('read_post', $doc, $current_user->ID);
		}
		else {
			// Basic WordPress version
			$type = relevanssi_get_post_type($doc);
			$cap = 'read_private_' . $type . 's';
			if (current_user_can($cap)) {
				$post_ok = true;
			}
		}
	}
	
	// only show drafts, pending and future posts in admin search
	if (in_array($status, array('draft', 'pending', 'future')) && is_admin()) {
		$post_ok = true;
	}
	
	if (relevanssi_s2member_level($doc) == 0) $post_ok = false; // not ok with s2member
	
	return $post_ok;
}

/**
 * Return values:
 *  2: full access to post
 *  1: show title only
 *  0: no access to post
 * -1: s2member not active
 */
function relevanssi_s2member_level($doc) {
	$return = -1;
	if (function_exists('is_permitted_by_s2member')) {
		// s2member
		$alt_view_protect = $GLOBALS["WS_PLUGIN__"]["s2member"]["o"]["filter_wp_query"];
		
		if (version_compare (WS_PLUGIN__S2MEMBER_VERSION, "110912", ">="))
			$completely_hide_protected_search_results = (in_array ("all", $alt_view_protect) || in_array ("searches", $alt_view_protect)) ? true : false;
		else /* Backward compatibility with versions of s2Member, prior to v110912. */
			$completely_hide_protected_search_results = (strpos ($alt_view_protect, "all") !== false || strpos ($alt_view_protect, "searches") !== false) ? true : false;
		
		if (is_permitted_by_s2member($doc)) {
			// Show title and excerpt, even full content if you like.
			$return = 2;
		}
		else if (!is_permitted_by_s2member($doc) && $completely_hide_protected_search_results === false) {
			// Show title and excerpt. Alt View Protection is NOT enabled for search results. However, do NOT show full content body.
			$return = 1;
		}
		else {
			// Hide this search result completely.
			$return = 0;
		}
	}
	
	return $return;
}

function relevanssi_populate_array($matches) {
	global $relevanssi_post_array, $wpdb;
	
	$ids = array();
	foreach ($matches as $match) {
		array_push($ids, $match->doc);
	}
	
	$ids = implode(',', $ids);
	$posts = $wpdb->get_results("SELECT * FROM $wpdb->posts WHERE id IN ($ids)");
	foreach ($posts as $post) {
		$relevanssi_post_array[$post->ID] = $post;
	}
}

function relevanssi_get_term_taxonomy($id) {
	global $wpdb;
	$taxonomy = $wpdb->get_var("SELECT taxonomy FROM $wpdb->term_taxonomy WHERE term_id = $id");
	return $taxonomy;
}

/**
 * Extracts phrases from search query
 * Returns an array of phrases
 */
function relevanssi_extract_phrases($q) {
	if ( function_exists( 'mb_strpos' ) )
		$pos = mb_strpos($q, '"');
	else
		$pos = strpos($q, '"');

	$phrases = array();
	while ($pos !== false) {
		$start = $pos;
		if ( function_exists( 'mb_strpos' ) )
			$end = mb_strpos($q, '"', $start + 1);
		else
			$end = strpos($q, '"', $start + 1);
		
		if ($end === false) {
			// just one " in the query
			$pos = $end;
			continue;
		}
		if ( function_exists( 'mb_substr' ) )
			$phrase = mb_substr($q, $start + 1, $end - $start - 1);
		else
			$phrase = substr($q, $start + 1, $end - $start - 1);
		
		$phrases[] = $phrase;
		$pos = $end;
	}
	return $phrases;
}

/* If no phrase hits are made, this function returns false
 * If phrase matches are found, the function presents a comma-separated list of doc id's.
 * If phrase matches are found, but no matching documents, function returns -1.
 */
function relevanssi_recognize_phrases($q) {
	global $wpdb;
	
	$phrases = relevanssi_extract_phrases($q);
	
	if (count($phrases) > 0) {
		$phrase_matches = array();
		foreach ($phrases as $phrase) {
			$phrase = $wpdb->escape($phrase);
			$query = "SELECT ID,post_content,post_title FROM $wpdb->posts 
				WHERE (post_content LIKE '%$phrase%' OR post_title LIKE '%$phrase%')
				AND post_status = 'publish'";
			
			$docs = $wpdb->get_results($query);

			if (is_array($docs)) {
				foreach ($docs as $doc) {
					if (!isset($phrase_matches[$phrase])) {
						$phrase_matches[$phrase] = array();
					}
					$phrase_matches[$phrase][] = $doc->ID;
				}
			}

			$query = "SELECT ID FROM $wpdb->posts as p, $wpdb->term_relationships as r, $wpdb->term_taxonomy as s, $wpdb->terms as t
				WHERE r.term_taxonomy_id = s.term_taxonomy_id AND s.term_id = t.term_id AND p.ID = r.object_id
				AND t.name LIKE '%$phrase%' AND p.post_status = 'publish'";

			$docs = $wpdb->get_results($query);
			if (is_array($docs)) {
				foreach ($docs as $doc) {
					if (!isset($phrase_matches[$phrase])) {
						$phrase_matches[$phrase] = array();
					}
					$phrase_matches[$phrase][] = $doc->ID;
				}
			}

			$query = "SELECT ID
              FROM $wpdb->posts AS p, $wpdb->postmeta AS m
              WHERE p.ID = m.post_id
              AND m.meta_value LIKE '%$phrase%'
              AND p.post_status = 'publish'";

			$docs = $wpdb->get_results($query);
			if (is_array($docs)) {
				foreach ($docs as $doc) {
					if (!isset($phrase_matches[$phrase])) {
						$phrase_matches[$phrase] = array();
					}
					$phrase_matches[$phrase][] = $doc->ID;
				}
			}
		}
		
		if (count($phrase_matches) < 1) {
			$phrases = "-1";
		}
		else {
			// Complicated mess, but necessary...
			$i = 0;
			$phms = array();
			foreach ($phrase_matches as $phm) {
				$phms[$i++] = $phm;
			}
			
			$phrases = $phms[0];
			if ($i > 1) {
				for ($i = 1; $i < count($phms); $i++) {
					$phrases =  array_intersect($phrases, $phms[$i]);
				}
			}
			
			if (count($phrases) < 1) {
				$phrases = "-1";
			}
			else {
				$phrases = implode(",", $phrases);
			}
		}
	}
	else {
		$phrases = false;
	}
	
	return $phrases;
}

function relevanssi_the_excerpt() {
    global $post;
    if (!post_password_required($post)) {
	    echo "<p>" . $post->post_excerpt . "</p>";
	}
	else {
		echo __('There is no excerpt because this is a protected post.');
	}
}

function relevanssi_do_excerpt($t_post, $query) {
	global $post;
	$old_global_post = NULL;
	if ($post != NULL) $old_global_post = $post;
	$post = $t_post;
	
	$remove_stopwords = false;
	$terms = relevanssi_tokenize($query, $remove_stopwords);
	
	$content = apply_filters('relevanssi_pre_excerpt_content', $post->post_content, $post, $query);
	$content = apply_filters('the_content', $post->post_content);
	$content = apply_filters('relevanssi_excerpt_content', $content, $post, $query);
	
	$content = relevanssi_strip_invisibles($content); // removes <script>, <embed> &c with content
	$content = strip_tags($content); // this removes the tags, but leaves the content
	
	$content = preg_replace("/\n\r|\r\n|\n|\r/", " ", $content);
	$content = trim(preg_replace("/\s\s+/", " ", $content));
	
	$excerpt_data = relevanssi_create_excerpt($content, $terms);
	
	if (get_option("relevanssi_index_comments") != 'none') {
		$comment_content = relevanssi_get_comments($post->ID);
		$comment_excerpts = relevanssi_create_excerpt($comment_content, $terms);
		if ($comment_excerpts[1] > $excerpt_data[1]) {
			$excerpt_data = $comment_excerpts;
		}
	}

	if (get_option("relevanssi_index_excerpt") != 'none') {
		$excerpt_content = $post->post_excerpt;
		$excerpt_excerpts = relevanssi_create_excerpt($excerpt_content, $terms);
		if ($excerpt_excerpts[1] > $excerpt_data[1]) {
			$excerpt_data = $excerpt_excerpts;
		}
	}
	
	$start = $excerpt_data[2];

	$excerpt = $excerpt_data[0];	
	$excerpt = apply_filters('get_the_excerpt', $excerpt);
	$excerpt = trim($excerpt);

	$ellipsis = apply_filters('relevanssi_ellipsis', '...');

	$highlight = get_option('relevanssi_highlight');
	if ("none" != $highlight) {
		if (!is_admin()) {
			$excerpt = relevanssi_highlight_terms($excerpt, $query);
		}
	}
	
	if (!$start) {
		$excerpt = $ellipsis . $excerpt;
		// do not add three dots to the beginning of the post
	}
	
	$excerpt = $excerpt . $ellipsis;

	if (relevanssi_s2member_level($post->ID) == 1) $excerpt = $post->post_excerpt;

	if ($old_global_post != NULL) $post = $old_global_post;

	return $excerpt;
}

/**
 * Creates an excerpt from content.
 *
 * @return array - element 0 is the excerpt, element 1 the number of term hits, element 2 is
 * true, if the excerpt is from the start of the content.
 */
function relevanssi_create_excerpt($content, $terms) {
	// If you need to modify these on the go, use 'pre_option_relevanssi_excerpt_length' filter.
	$excerpt_length = get_option("relevanssi_excerpt_length");
	$type = get_option("relevanssi_excerpt_type");

	$best_excerpt_term_hits = -1;
	$excerpt = "";

	$content = " $content";	
	$start = false;
	if ("chars" == $type) {
		$term_hits = 0;
		foreach (array_keys($terms) as $term) {
			$term = " $term";
			if (function_exists('mb_stripos')) {
				$pos = ("" == $content) ? false : mb_stripos($content, $term);
			}
			else if (function_exists('mb_strpos')) {
				$pos = mb_strpos($content, $term);
				if (false === $pos) {
					$titlecased = mb_strtoupper(mb_substr($term, 0, 1)) . mb_substr($term, 1);
					$pos = mb_strpos($content, $titlecased);
					if (false === $pos) {
						$pos = mb_strpos($content, mb_strtoupper($term));
					}
				}
			}
			else {
				$pos = strpos($content, $term);
				if (false === $pos) {
					$titlecased = strtoupper(substr($term, 0, 1)) . substr($term, 1);
					$pos = strpos($content, $titlecased);
					if (false === $pos) {
						$pos = strpos($content, strtoupper($term));
					}
				}
			}
			
			if (false !== $pos) {
				$term_hits++;
				if ($term_hits > $best_excerpt_term_hits) {
					$best_excerpt_term_hits = $term_hits;
					if ($pos + strlen($term) < $excerpt_length) {
						if (function_exists('mb_substr'))
							$excerpt = mb_substr($content, 0, $excerpt_length);
						else
							$excerpt = substr($content, 0, $excerpt_length);
						$start = true;
					}
					else {
						$half = floor($excerpt_length/2);
						$pos = $pos - $half;
						if (function_exists('mb_substr'))
							$excerpt = mb_substr($content, $pos, $excerpt_length);
						else
							$excerpt = substr($content, $pos, $excerpt_length);
					}
				}
			}
		}
		
		if ("" == $excerpt) {
			if (function_exists('mb_substr'))
				$excerpt = mb_substr($content, 0, $excerpt_length);
			else
				$excerpt = substr($content, 0, $excerpt_length);
			$start = true;
		}
	}
	else {
		$words = explode(' ', $content);
		
		$i = 0;
		
		while ($i < count($words)) {
			if ($i + $excerpt_length > count($words)) {
				$i = count($words) - $excerpt_length;
			}
			$excerpt_slice = array_slice($words, $i, $excerpt_length);
			$excerpt_slice = implode(' ', $excerpt_slice);

			$excerpt_slice = " $excerpt_slice";
			$term_hits = 0;
			foreach (array_keys($terms) as $term) {
				$term = " $term";
				if (function_exists('mb_stripos')) {
					$pos = ("" == $excerpt_slice) ? false : mb_stripos($excerpt_slice, $term);
					// To avoid "empty haystack" warnings
				}
				else if (function_exists('mb_strpos')) {
					$pos = mb_strpos($excerpt_slice, $term);
					if (false === $pos) {
						$titlecased = mb_strtoupper(mb_substr($term, 0, 1)) . mb_substr($term, 1);
						$pos = mb_strpos($excerpt_slice, $titlecased);
						if (false === $pos) {
							$pos = mb_strpos($excerpt_slice, mb_strtoupper($term));
						}
					}
				}
				else {
					$pos = strpos($excerpt_slice, $term);
					if (false === $pos) {
						$titlecased = strtoupper(substr($term, 0, 1)) . substr($term, 1);
						$pos = strpos($excerpt_slice, $titlecased);
						if (false === $pos) {
							$pos = strpos($excerpt_slice, strtoupper($term));
						}
					}
				}
			
				if (false !== $pos) {
					$term_hits++;
					if (0 == $i) $start = true;
					if ($term_hits > $best_excerpt_term_hits) {
						$best_excerpt_term_hits = $term_hits;
						$excerpt = $excerpt_slice;
					}
				}
			}
			
			$i += $excerpt_length;
		}
		
		if ("" == $excerpt) {
			$excerpt = explode(' ', $content, $excerpt_length);
			array_pop($excerpt);
			$excerpt = implode(' ', $excerpt);
			$start = true;
		}
	}
	
	return array($excerpt, $best_excerpt_term_hits, $start);
}

// found here: http://forums.digitalpoint.com/showthread.php?t=1106745
function relevanssi_strip_invisibles($text) {
	$text = preg_replace(
		array(
			'@<style[^>]*?>.*?</style>@siu',
			'@<script[^>]*?.*?</script>@siu',
			'@<object[^>]*?.*?</object>@siu',
			'@<embed[^>]*?.*?</embed>@siu',
			'@<applet[^>]*?.*?</applet>@siu',
			'@<noscript[^>]*?.*?</noscript>@siu',
			'@<noembed[^>]*?.*?</noembed>@siu',
			'@<iframe[^>]*?.*?</iframe>@siu',
			'@<del[^>]*?.*?</del>@siu',
		),
		' ',
		$text );
	return $text;
}

function relevanssi_highlight_terms($excerpt, $query) {
	$type = get_option("relevanssi_highlight");
	if ("none" == $type) {
		return $excerpt;
	}
	
	switch ($type) {
		case "mark":						// thanks to Jeff Byrnes
			$start_emp = "<mark>";
			$end_emp = "</mark>";
			break;
		case "strong":
			$start_emp = "<strong>";
			$end_emp = "</strong>";
			break;
		case "em":
			$start_emp = "<em>";
			$end_emp = "</em>";
			break;
		case "col":
			$col = get_option("relevanssi_txt_col");
			if (!$col) $col = "#ff0000";
			$start_emp = "<span style='color: $col'>";
			$end_emp = "</span>";
			break;
		case "bgcol":
			$col = get_option("relevanssi_bg_col");
			if (!$col) $col = "#ff0000";
			$start_emp = "<span style='background-color: $col'>";
			$end_emp = "</span>";
			break;
		case "css":
			$css = get_option("relevanssi_css");
			if (!$css) $css = "color: #ff0000";
			$start_emp = "<span style='$css'>";
			$end_emp = "</span>";
			break;
		case "class":
			$css = get_option("relevanssi_class");
			if (!$css) $css = "relevanssi-query-term";
			$start_emp = "<span class='$css'>";
			$end_emp = "</span>";
			break;
		default:
			return $excerpt;
	}
	
	$start_emp_token = "*[/";
	$end_emp_token = "\]*";

	if ( function_exists('mb_internal_encoding') )
		mb_internal_encoding("UTF-8");
	
	$terms = array_keys(relevanssi_tokenize($query, $remove_stopwords = true));

	$phrases = relevanssi_extract_phrases(stripslashes($query));
	
	$non_phrase_terms = array();
	foreach ($phrases as $phrase) {
		$phrase_terms = array_keys(relevanssi_tokenize($phrase, false));
		foreach ($terms as $term) {
			if (!in_array($term, $phrase_terms)) {
				$non_phrase_terms[] = $term;
			}
		}
		$terms = $non_phrase_terms;
		$terms[] = $phrase;
	}

	usort($terms, 'relevanssi_strlen_sort');

	get_option('relevanssi_word_boundaries', 'on') == 'on' ? $word_boundaries = true : $word_boundaries = false;
	foreach ($terms as $term) {
		$pr_term = preg_quote($term);
		if ($word_boundaries) {
			$excerpt = preg_replace("/(\b$pr_term|$pr_term\b)(?!([^<]+)?>)/iu", $start_emp_token . '\\1' . $end_emp_token, $excerpt);
		}
		else {
			$excerpt = preg_replace("/($pr_term)(?!([^<]+)?>)/iu", $start_emp_token . '\\1' . $end_emp_token, $excerpt);
		}
		// thanks to http://pureform.wordpress.com/2008/01/04/matching-a-word-characters-outside-of-html-tags/
	}
	
	$excerpt = relevanssi_remove_nested_highlights($excerpt, $start_emp_token, $end_emp_token);
	
	$excerpt = str_replace($start_emp_token, $start_emp, $excerpt);
	$excerpt = str_replace($end_emp_token, $end_emp, $excerpt);
	$excerpt = str_replace($end_emp . $start_emp, "", $excerpt);
	if (function_exists('mb_ereg_replace')) {
		$pattern = $end_emp . '\s*' . $start_emp;
		$excerpt = mb_ereg_replace($pattern, " ", $excerpt);
	}

	return $excerpt;
}

function relevanssi_remove_nested_highlights($s, $a, $b) {
	$offset = 0;
	$string = "";
	$bits = explode($a, $s);	
	$new_bits = array($bits[0]);
	$in = false;
	for ($i = 1; $i < count($bits); $i++) {
		if ($bits[$i] == '') continue;
		
		if (!$in) {
			array_push($new_bits, $a);
			$in = true;
		}
		if (substr_count($bits[$i], $b) > 0) {
			$in = false;
		}
		if (substr_count($bits[$i], $b) > 1) {
			$more_bits = explode($b, $bits[$i]);
			$j = 0;
			$k = count($more_bits) - 2;
			$whole_bit = "";
			foreach ($more_bits as $bit) {
				$whole_bit .= $bit;
				if ($j == $k) $whole_bit .= $b;
				$j++;
			}
			$bits[$i] = $whole_bit;
		}
		array_push($new_bits, $bits[$i]);
	}
	$whole = implode('', $new_bits);
	
	return $whole;
}

function relevanssi_strlen_sort($a, $b) {
	return strlen($b) - strlen($a);
}

function relevanssi_get_comments($postID) {	
	global $wpdb;

	$comtype = get_option("relevanssi_index_comments");
	$restriction = "";
	$comment_string = "";
	switch ($comtype) {
		case "all": 
			// all (incl. customs, track- & pingbacks)
			break;
		case "normal": 
			// normal (excl. customs, track- & pingbacks)
			$restriction=" AND comment_type='' ";
			break;
		default:
			// none (don't index)
			return "";
	}

	$to = 20;
	$from = 0;

	while ( true ) {
		$sql = "SELECT 	comment_content, comment_author
				FROM 	$wpdb->comments
				WHERE 	comment_post_ID = '$postID'
				AND 	comment_approved = '1' 
				".$restriction."
				LIMIT 	$from, $to";		
		$comments = $wpdb->get_results($sql);
		if (sizeof($comments) == 0) break;
		foreach($comments as $comment) {
			$comment_string .= $comment->comment_author . ' ' . $comment->comment_content . ' ';
		}
		$from += $to;
	}
	return $comment_string;
}

function relevanssi_get_custom_fields() {
	$custom_fields = get_option("relevanssi_index_fields");
	if ($custom_fields) {
		if ($custom_fields != 'all') {
			$custom_fields = explode(",", $custom_fields);
			for ($i = 0; $i < count($custom_fields); $i++) {
				$custom_fields[$i] = trim($custom_fields[$i]);
			}
		}
	}
	else {
		$custom_fields = false;
	}
	return $custom_fields;
}

function relevanssi_mb_trim($string) { 
	$string = str_replace(chr(194) . chr(160), '', $string);
    $string = preg_replace( "/(^\s+)|(\s+$)/us", "", $string ); 
    return $string; 
} 

function relevanssi_remove_punct($a) {
		$a = strip_tags($a);
		$a = stripslashes($a);

		$a = str_replace("’", '', $a);
		$a = str_replace("‘", '', $a);
		$a = str_replace("„", '', $a);
		$a = str_replace("·", '', $a);
		$a = str_replace("”", '', $a);
		$a = str_replace("“", '', $a);
		$a = str_replace("…", '', $a);
		$a = str_replace("€", '', $a);
		$a = str_replace("&shy;", '', $a);

		$a = str_replace('&#8217;', ' ', $a);
		$a = str_replace("'", ' ', $a);
		$a = str_replace("´", ' ', $a);
		$a = str_replace("—", ' ', $a);
		$a = str_replace("–", ' ', $a);
		$a = str_replace("×", ' ', $a);
        $a = preg_replace('/[[:punct:]]+/u', ' ', $a);

        $a = preg_replace('/[[:space:]]+/', ' ', $a);
		$a = trim($a);

        return $a;
}

function relevanssi_shortcode($atts, $content, $name) {
	global $wpdb;

	extract(shortcode_atts(array('term' => false, 'phrase' => 'not'), $atts));
	
	if ($term != false) {
		$term = urlencode(strtolower($term));
	}
	else {
		$term = urlencode(strip_tags(strtolower($content)));
	}
	
	if ($phrase != 'not') {
		$term = '%22' . $term . '%22';	
	}
	
	$link = get_bloginfo('url') . "/?s=$term";
	
	$pre  = "<a href='$link'>";
	$post = "</a>";

	return $pre . do_shortcode($content) . $post;
}

add_shortcode('search', 'relevanssi_shortcode');

/**
 * This function will prevent the default search from running, when Relevanssi is
 * active.
 * Thanks to John Blackbourne.
 */
function relevanssi_prevent_default_request( $request, $query ) {
	if ($query->is_search) {
		global $wpdb;
		if (!is_admin())
			$request = "SELECT * FROM $wpdb->posts WHERE 1=2";
		else if ('on' == get_option('relevanssi_admin_search'))
			$request = "SELECT * FROM $wpdb->posts WHERE 1=2";
	}
	return $request;
}
add_filter('posts_request', 'relevanssi_prevent_default_request', 10, 2 );

?>