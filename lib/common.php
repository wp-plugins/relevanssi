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
	global $relevanssi_post_array, $relevanssi_post_types, $wpdb;
	
	$ids = array();
	foreach ($matches as $match) {
		array_push($ids, $match->doc);
	}
	
	$ids = implode(',', $ids);
	$posts = $wpdb->get_results("SELECT * FROM $wpdb->posts WHERE id IN ($ids)");
	foreach ($posts as $post) {
		$relevanssi_post_array[$post->ID] = $post;
		$relevanssi_post_types[$post->ID] = $post->post_type;
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

function relevanssi_create_database_tables($relevanssi_db_version) {
	global $wpdb;
	
	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

	$charset_collate_bin_column = '';
	$charset_collate = '';

	if (!empty($wpdb->charset)) {
        $charset_collate_bin_column = "CHARACTER SET $wpdb->charset";
		$charset_collate = "DEFAULT $charset_collate_bin_column";
	}
	if (strpos($wpdb->collate, "_") > 0) {
        $charset_collate_bin_column .= " COLLATE " . substr($wpdb->collate, 0, strpos($wpdb->collate, '_')) . "_bin";
        $charset_collate .= " COLLATE $wpdb->collate";
    } else {
    	if ($wpdb->collate == '' && $wpdb->charset == "utf8") {
	        $charset_collate_bin_column .= " COLLATE utf8_bin";
	    }
    }
    
	$relevanssi_table = $wpdb->prefix . "relevanssi";	
	$relevanssi_stopword_table = $wpdb->prefix . "relevanssi_stopwords";
	$relevanssi_log_table = $wpdb->prefix . "relevanssi_log";
	$relevanssi_cache = $wpdb->prefix . 'relevanssi_cache';
	$relevanssi_excerpt_cache = $wpdb->prefix . 'relevanssi_excerpt_cache';

	if(get_option('relevanssi_db_version') != $relevanssi_db_version) {
		if ($relevanssi_db_version == 1) {
			$sql = "DROP TABLE $relevanssi_table";
			$wpdb->query($sql);
			delete_option('relevanssi_indexed');
		}
	
		$sql = "CREATE TABLE " . $relevanssi_table . " (doc bigint(20) NOT NULL DEFAULT '0', 
		term varchar(50) NOT NULL DEFAULT '0', 
		content mediumint(9) NOT NULL DEFAULT '0', 
		title mediumint(9) NOT NULL DEFAULT '0', 
		comment mediumint(9) NOT NULL DEFAULT '0', 
		tag mediumint(9) NOT NULL DEFAULT '0', 
		link mediumint(9) NOT NULL DEFAULT '0', 
		author mediumint(9) NOT NULL DEFAULT '0', 
		category mediumint(9) NOT NULL DEFAULT '0', 
		excerpt mediumint(9) NOT NULL DEFAULT '0', 
		taxonomy mediumint(9) NOT NULL DEFAULT '0', 
		customfield mediumint(9) NOT NULL DEFAULT '0', 
		mysqlcolumn mediumint(9) NOT NULL DEFAULT '0',
		taxonomy_detail longtext NOT NULL,
		customfield_detail longtext NOT NULL,
		mysqlcolumn_detail longtext NOT NULL,
		type varchar(210) NOT NULL DEFAULT 'post', 
		item bigint(20) NOT NULL DEFAULT '0', 
	    UNIQUE KEY doctermitem (doc, term, item)) $charset_collate";
		
		dbDelta($sql);

		$sql = "CREATE INDEX terms ON $relevanssi_table (term(20))";
		$wpdb->query($sql);

		$sql = "CREATE INDEX docs ON $relevanssi_table (doc)";
		$wpdb->query($sql);

		$sql = "CREATE TABLE " . $relevanssi_stopword_table . " (stopword varchar(50) $charset_collate_bin_column NOT NULL, "
	    . "UNIQUE KEY stopword (stopword)) $charset_collate;";

		dbDelta($sql);

		$sql = "CREATE TABLE " . $relevanssi_log_table . " (id bigint(9) NOT NULL AUTO_INCREMENT, "
		. "query varchar(200) NOT NULL, "
		. "hits mediumint(9) NOT NULL DEFAULT '0', "
		. "time timestamp NOT NULL, "
		. "user_id bigint(20) NOT NULL DEFAULT '0', "
		. "ip varchar(40) NOT NULL DEFAULT '', "
	    . "UNIQUE KEY id (id)) $charset_collate;";

		dbDelta($sql);
	
		$sql = "CREATE TABLE " . $relevanssi_cache . " (param varchar(32) $charset_collate_bin_column NOT NULL, "
		. "hits text NOT NULL, "
		. "tstamp timestamp NOT NULL, "
	    . "UNIQUE KEY param (param)) $charset_collate;";

		dbDelta($sql);

		$sql = "CREATE TABLE " . $relevanssi_excerpt_cache . " (query varchar(100) $charset_collate_bin_column NOT NULL, "
		. "post mediumint(9) NOT NULL, "
		. "excerpt text NOT NULL, "
	    . "UNIQUE (query, post)) $charset_collate;";

		dbDelta($sql);

		if (RELEVANSSI_PREMIUM && get_option('relevanssi_db_version') < 12) {
			$charset_collate_bin_column = '';
			$charset_collate = '';
		
			if (!empty($wpdb->charset)) {
				$charset_collate_bin_column = "CHARACTER SET $wpdb->charset";
				$charset_collate = "DEFAULT $charset_collate_bin_column";
			}
			if (strpos($wpdb->collate, "_") > 0) {
				$charset_collate_bin_column .= " COLLATE " . substr($wpdb->collate, 0, strpos($wpdb->collate, '_')) . "_bin";
				$charset_collate .= " COLLATE $wpdb->collate";
			} else {
				if ($wpdb->collate == '' && $wpdb->charset == "utf8") {
					$charset_collate_bin_column .= " COLLATE utf8_bin";
				}
			}
			
			$sql = "ALTER TABLE $relevanssi_stopword_table MODIFY COLUMN stopword varchar(50) $charset_collate_bin_column NOT NULL";
			$wpdb->query($sql);
			$sql = "ALTER TABLE $relevanssi_log_table ADD COLUMN user_id bigint(20) NOT NULL DEFAULT '0'";
			$wpdb->query($sql);
			$sql = "ALTER TABLE $relevanssi_log_table ADD COLUMN ip varchar(40) NOT NULL DEFAULT ''";
			$wpdb->query($sql);
			$sql = "ALTER TABLE $relevanssi_cache MODIFY COLUMN param varchar(32) $charset_collate_bin_column NOT NULL";
			$wpdb->query($sql);
			$sql = "ALTER TABLE $relevanssi_excerpt_cache MODIFY COLUMN query(100) $charset_collate_bin_column NOT NULL";
			$wpdb->query($sql);
		}
		
		update_option('relevanssi_db_version', $relevanssi_db_version);
	}
	
	if ($wpdb->get_var("SELECT COUNT(*) FROM $relevanssi_stopword_table WHERE 1") < 1) {
		relevanssi_populate_stopwords();
	}
}

function relevanssi_clear_database_tables() {
	wp_clear_scheduled_hook('relevanssi_truncate_cache');

	$relevanssi_table = $wpdb->prefix . "relevanssi";	
	$stopword_table = $wpdb->prefix . "relevanssi_stopwords";
	$log_table = $wpdb->prefix . "relevanssi_log";
	$relevanssi_cache = $wpdb->prefix . 'relevanssi_cache';
	$relevanssi_excerpt_cache = $wpdb->prefix . 'relevanssi_excerpt_cache';
	
	if($wpdb->get_var("SHOW TABLES LIKE '$stopword_table'") == $stopword_table) {
		$sql = "DROP TABLE $stopword_table";
		$wpdb->query($sql);
	}

	if($wpdb->get_var("SHOW TABLES LIKE '$relevanssi_table'") == $relevanssi_table) {
		$sql = "DROP TABLE $relevanssi_table";
		$wpdb->query($sql);
	}

	if($wpdb->get_var("SHOW TABLES LIKE '$log_table'") == $log_table) {
		$sql = "DROP TABLE $log_table";
		$wpdb->query($sql);
	}

	if($wpdb->get_var("SHOW TABLES LIKE '$relevanssi_cache'") == $relevanssi_cache) {
		$sql = "DROP TABLE $relevanssi_cache";
		$wpdb->query($sql);
	}

	if($wpdb->get_var("SHOW TABLES LIKE '$relevanssi_excerpt_cache'") == $relevanssi_excerpt_cache) {
		$sql = "DROP TABLE $relevanssi_excerpt_cache";
		$wpdb->query($sql);
	}
	
	echo '<div id="message" class="updated fade"><p>' . __("Data wiped clean, you can now delete the plugin.", "relevanssi") . '</p></div>';
}

add_filter('query_vars', 'relevanssi_query_vars');
function relevanssi_query_vars($qv) {
	$qv[] = 'cats';
	$qv[] = 'tags';
	$qv[] = 'post_types';

	return $qv;
}

function relevanssi_build_index($extend = false) {
	global $wpdb, $relevanssi_table, $relevanssi_variables;
	if (isset($relevanssi_variables['relevanssi_table'])) $relevanssi_table = $relevanssi_variables['relevanssi_table'];

	set_time_limit(0);
	
	$post_types = array();
	$types = get_option("relevanssi_index_post_types");
	if (!is_array($types)) $types = array();
	foreach ($types as $type) {
		array_push($post_types, "'$type'");
	}
	
	if (count($post_types) > 0) {
		$restriction = " AND post.post_type IN (" . implode(', ', $post_types) . ') ';
	}
	else {
		$restriction = "";
	}

	$n = 0;
	$size = 0;
	
	if (!$extend) {
		// truncate table first
		$wpdb->query("TRUNCATE TABLE $relevanssi_table");

		if (function_exists('relevanssi_index_taxonomies')) {
			if (get_option('relevanssi_index_taxonomies') == 'on') {
				relevanssi_index_taxonomies();
			}
		}

		if (function_exists('relevanssi_index_users')) {
			if (get_option('relevanssi_index_users') == 'on') {
				relevanssi_index_users();
			}
		}

        $q = "SELECT post.ID
		FROM $wpdb->posts parent, $wpdb->posts post WHERE
        (parent.post_status IN ('publish', 'draft', 'private', 'pending', 'future'))
        AND (
            (post.post_status='inherit'
            AND post.post_parent=parent.ID)
            OR
            (parent.ID=post.ID)
        )
		$restriction";
		update_option('relevanssi_index', '');
	}
	else {
		// extending, so no truncate and skip the posts already in the index
		$limit = get_option('relevanssi_index_limit', 200);
		if ($limit > 0) {
			$size = $limit;
			$limit = " LIMIT $limit";
		}
        $q = "SELECT post.ID
		FROM $wpdb->posts parent, $wpdb->posts post WHERE
        (parent.post_status IN ('publish', 'draft', 'private', 'pending', 'future'))
        AND (
            (post.post_status='inherit'
            AND post.post_parent=parent.ID)
            OR
            (parent.ID=post.ID)
        )
		AND post.ID NOT IN (SELECT DISTINCT(doc) FROM $relevanssi_table) $restriction $limit";
	}

	$custom_fields = relevanssi_get_custom_fields();

	$content = $wpdb->get_results($q);
	
	foreach ($content as $post) {
		$n += relevanssi_index_doc($post->ID, false, $custom_fields);
		// n calculates the number of insert queries
	}
	
    echo '<div id="message" class="updated fade"><p>'
		. __((($size == 0) || (count($content) < $size)) ? "Indexing complete!" : "More to index...", "relevanssi")
		. '</p></div>';
	update_option('relevanssi_indexed', 'done');
}

// BEGIN modified by renaissancehack
//  recieve $post argument as $indexpost, so we can make it the $post global.  This will allow shortcodes
//  that need to know what post is calling them to access $post->ID
/*
	Different cases:

	- 	Build index:
		global $post is NULL, $indexpost is a post object.
		
	-	Update post:
		global $post has the original $post, $indexpost is the ID of revision.
		
	-	Quick edit:
		global $post is an array, $indexpost is the ID of current revision.
*/
function relevanssi_index_doc($indexpost, $remove_first = false, $custom_fields = false, $bypassglobalpost = false) {
	global $wpdb, $relevanssi_table, $post, $relevanssi_variables;
	if (isset($relevanssi_variables['relevanssi_table'])) $relevanssi_table = $relevanssi_variables['relevanssi_table'];
	$post_was_null = false;
	$previous_post = NULL;

	if ($bypassglobalpost) {
		// if $bypassglobalpost is set, relevanssi_index_doc() will index the post object or post
		// ID as specified in $indexpost
		isset($post) ?
			$previous_post = $post : $post_was_null = true;
		is_object($indexpost) ?
			$post = $indexpost : $post = get_post($indexpost);
	}
	else {
		// Quick edit has an array in the global $post, so fetch the post ID for the post to edit.
		if (is_array($post)) {
			$post = get_post($post['ID']);
		}
		
		if (!isset($post)) {
			// No $post set, so we need to use $indexpost, if it's a post object
			$post_was_null = true;
			if (is_object($indexpost)) {
				$post = $indexpost;
			}
			else {
				$post = get_post($indexpost);
			}
		}
		else {
			// $post was set, let's grab the previous value in case we need it
			$previous_post = $post;
		}
	}
	
	if ($post == NULL) {
		// At this point we should have something in $post; if not, quit.
		if ($post_was_null) $post = null;
		if ($previous_post) $post = $previous_post;
		return;
	}
	
	// Finally fetch the post again by ID. Complicated, yes, but unless we do this, we might end
	// up indexing the post before the updates come in.
	$post = get_post($post->ID);

	if (function_exists('relevanssi_hide_post')) {
		if (relevanssi_hide_post($post->ID)) {
			if ($post_was_null) $post = null;
			if ($previous_post) $post = $previous_post;
			return;
		}
	}

	if (true == apply_filters('relevanssi_do_not_index', false, $post->ID)) {
		// filter says no
		if ($post_was_null) $post = null;
		if ($previous_post) $post = $previous_post;
		return;
	}

	$post->indexing_content = true;
	$index_types = get_option('relevanssi_index_post_types');
	if (!is_array($index_types)) $index_types = array();
	if (in_array($post->post_type, $index_types)) $index_this_post = true;

	if ($remove_first) {
		// we are updating a post, so remove the old stuff first
		relevanssi_remove_doc($post->ID, true);
		if (function_exists('relevanssi_remove_item')) {
			relevanssi_remove_item($post->ID, 'post');
		}
		relevanssi_purge_excerpt_cache($post->ID);
	}

	// This needs to be here, after the call to relevanssi_remove_doc(), because otherwise
	// a post that's in the index but shouldn't be there won't get removed. A remote chance,
	// I mean who ever flips exclude_from_search between true and false once it's set, but
	// I'd like to cover all bases.
	if (!$index_this_post) {
		if ($post_was_null) $post = null;
		if ($previous_post) $post = $previous_post;
		return;
	}

	$n = 0;	

	$min_word_length = get_option('relevanssi_min_word_length', 3);
	$insert_data = array();

	//Added by OdditY - INDEX COMMENTS of the POST ->
	if ("none" != get_option("relevanssi_index_comments")) {
		$pcoms = relevanssi_get_comments($post->ID);
		if ($pcoms != "") {
			$pcoms = relevanssi_strip_invisibles($pcoms);
			$pcoms = preg_replace('/<[a-zA-Z\/][^>]*>/', ' ', $pcoms);
			$pcoms = strip_tags($pcoms);
			$pcoms = relevanssi_tokenize($pcoms, true, $min_word_length);		
			if (count($pcoms) > 0) {
				foreach ($pcoms as $pcom => $count) {
					$n++;
					$insert_data[$pcom]['comment'] = $count;
				}
			}				
		}
	} //Added by OdditY END <-


	$taxonomies = array();
	//Added by OdditY - INDEX TAGs of the POST ->
	if ("on" == get_option("relevanssi_include_tags")) {
		array_push($taxonomies, "post_tag");
	} // Added by OdditY END <- 

	$custom_taxos = get_option("relevanssi_custom_taxonomies");
	if ("" != $custom_taxos) {
		$cts = explode(",", $custom_taxos);
		foreach ($cts as $taxon) {
			$taxon = trim($taxon);
			array_push($taxonomies, $taxon);
		}
	}

	// index categories
	if ("on" == get_option("relevanssi_include_cats")) {
		array_push($taxonomies, 'category');
	}

	// Then process all taxonomies, if any.
	foreach ($taxonomies as $taxonomy) {
		$insert_data = relevanssi_index_taxonomy_terms($post, $taxonomy, $insert_data);
	}
	
	// index author
	if ("on" == get_option("relevanssi_index_author")) {
		$auth = $post->post_author;
		$display_name = $wpdb->get_var("SELECT display_name FROM $wpdb->users WHERE ID=$auth");
		$names = relevanssi_tokenize($display_name, false, $min_word_length);
		foreach($names as $name => $count) {
			isset($insert_data[$name]['author']) ? $insert_data[$name]['author'] += $count : $insert_data[$name]['author'] = $count;
		}
	}

	if ($custom_fields) {
		$remove_underscore_fields = false;
		if ($custom_fields == 'all') 
			$custom_fields = get_post_custom_keys($post->ID);
		if ($custom_fields == 'visible') {
			$custom_fields = get_post_custom_keys($post->ID);
			$remove_underscore_fields = true;
		}
		foreach ($custom_fields as $field) {
			if ($remove_underscore_fields) {
				if (substr($field, 0, 1) == '_') continue;
			}
			$values = get_post_meta($post->ID, $field, false);
			if ("" == $values) continue;
			foreach ($values as $value) {
				$value_tokens = relevanssi_tokenize($value, true, $min_word_length);
				foreach ($value_tokens as $token => $count) {
					isset($insert_data[$token]['customfield']) ? $insert_data[$token]['customfield'] += $count : $insert_data[$token]['customfield'] = $count;
					if (function_exists('relevanssi_customfield_detail')) {
						$insert_data = relevanssi_customfield_detail($insert_data, $token, $count, $field);
					}
				}
			}
		}
	}

	if (isset($post->post_excerpt) && ("on" == get_option("relevanssi_index_excerpt") || "attachment" == $post->post_type)) { // include excerpt for attachments which use post_excerpt for captions - modified by renaissancehack
		$excerpt_tokens = relevanssi_tokenize($post->post_excerpt, true, $min_word_length);
		foreach ($excerpt_tokens as $token => $count) {
			isset($insert_data[$token]['excerpt']) ? $insert_data[$token]['excerpt'] += $count : $insert_data[$token]['excerpt'] = $count;
		}
	}

	if (function_exists('relevanssi_index_mysqL_columns')) {
		$insert_data = relevanssi_index_mysql_columns($insert_data, $post->ID);
	}

	$index_titles = true;
	if (apply_filters('relevanssi_index_titles', $index_titles)) {
		$titles = relevanssi_tokenize($post->post_title);

		if (count($titles) > 0) {
			foreach ($titles as $title => $count) {
				if (strlen($title) < 2) continue;
				$n++;
				isset($insert_data[$title]['title']) ? $insert_data[$title]['title'] += $count : $insert_data[$title]['title'] = $count;
			}
		}
	}
	
	$index_content = true;
	if (apply_filters('relevanssi_index_content', $index_content)) {
		remove_shortcode('noindex');
		add_shortcode('noindex', 'relevanssi_noindex_shortcode_indexing');

		$contents = $post->post_content;
		
		// Allow user to add extra content for Relevanssi to index
		// Thanks to Alexander Gieg
		$additional_content = trim(apply_filters('relevanssi_content_to_index', '', $post));
		if ('' != $additional_content)
			$contents .= ' '.$additional_content;		
			
		if ('on' == get_option('relevanssi_expand_shortcodes')) {
			if (function_exists("do_shortcode")) {
				$contents = do_shortcode($contents);
			}
		}
		else {
			if (function_exists("strip_shortcodes")) {
				// WP 2.5 doesn't have the function
				$contents = strip_shortcodes($contents);
			}
		}
		
		remove_shortcode('noindex');
		add_shortcode('noindex', 'relevanssi_noindex_shortcode');

		$contents = relevanssi_strip_invisibles($contents);
	
		if (function_exists('relevanssi_process_internal_links')) {
			$contents = relevanssi_process_internal_links($contents, $post->ID);
		}

		$contents = preg_replace('/<[a-zA-Z\/][^>]*>/', ' ', $contents);
		$contents = strip_tags($contents);
		$contents = relevanssi_tokenize($contents, true, $min_word_length);
	
		if (count($contents) > 0) {
			foreach ($contents as $content => $count) {
		 		$n++;
				isset($insert_data[$content]['content']) ? $insert_data[$content]['content'] += $count : $insert_data[$content]['content'] = $count;
			}
		}
	}
	
	$type = 'post';
	if ($post->post_type == 'attachment') $type = 'attachment';
	
	$values = array();
	foreach ($insert_data as $term => $data) {
		$content = 0;
		$title = 0;
		$comment = 0;
		$tag = 0;
		$link = 0;
		$author = 0;
		$category = 0;
		$excerpt = 0;
		$taxonomy = 0;
		$customfield = 0;
		$taxonomy_detail = '';
		$customfield_detail = '';
		$mysqlcolumn = 0;
		extract($data);

		$value = $wpdb->prepare("(%d, %s, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %s, %s, %s, %d)",
			$post->ID, $term, $content, $title, $comment, $tag, $link, $author, $category, $excerpt, $taxonomy, $customfield, $type, $taxonomy_detail, $customfield_detail, $mysqlcolumn);

		array_push($values, $value);
	}
	
	if (!empty($values)) {
		$values = implode(', ', $values);
		$query = "INSERT IGNORE INTO $relevanssi_table (doc, term, content, title, comment, tag, link, author, category, excerpt, taxonomy, customfield, type, taxonomy_detail, customfield_detail, mysqlcolumn)
			VALUES $values";
		$wpdb->query($query);
	}

	if ($post_was_null) $post = null;
	if ($previous_post) $post = $previous_post;

	return $n;
}

/**
 * Index taxonomy terms for given post and given taxonomy.
 *
 * @since 1.8
 * @param object $post Post object.
 * @param string $taxonomy Taxonomy name.
 * @param array $insert_data Insert query data array.
 * @return array Updated insert query data array.
 */
function relevanssi_index_taxonomy_terms($post = null, $taxonomy = "", $insert_data) {
	global $wpdb, $relevanssi_table, $relevanssi_varibles;
	if (isset($relevanssi_variables['relevanssi_table'])) $relevanssi_table = $relevanssi_variables['relevanssi_table'];

	$n = 0;

	if (null == $post) return 0;
	if ("" == $taxonomy) return 0;
	
	$min_word_length = get_option('relevanssi_min_word_length', 3);
	$ptagobj = get_the_terms($post->ID, $taxonomy);
	if ($ptagobj !== FALSE) { 
		$tagstr = "";
		foreach ($ptagobj as $ptag) {
			if (is_object($ptag)) {
				$tagstr .= $ptag->name . ' ';
			}
		}		
		$tagstr = trim($tagstr);
		$ptags = relevanssi_tokenize($tagstr, true, $min_word_length);		
		if (count($ptags) > 0) {
			foreach ($ptags as $ptag => $count) {
				$n++;
				
				if ('post_tags' == $taxonomy) {
					$insert_data[$ptag]['tag'] = $count;
				}
				else if ('category' == $taxonomy) {
					$insert_data[$ptag]['category'] = $count;
				}
				else {
					if (isset($insert_data[$ptag]['taxonomy'])) {
						$insert_data[$ptag]['taxonomy'] += $count;
					}
					else {
						$insert_data[$ptag]['taxonomy'] = $count;
					}
				}
				if (isset($insert_data[$ptag]['taxonomy_detail'])) {
					$tax_detail = unserialize($insert_data[$ptag]['taxonomy_detail']);
				}
				else {
					$tax_detail = array();
				}
				if (isset($tax_detail[$taxonomy])) {
					$tax_detail[$taxonomy] += $count;
				}
				else {
					$tax_detail[$taxonomy] = $count;
				}
				$insert_data[$ptag]['taxonomy_detail'] = serialize($tax_detail);
			}
		}	
	}
	return $insert_data;
}

add_shortcode('noindex', 'relevanssi_noindex_shortcode');
function relevanssi_noindex_shortcode($atts, $content) {
	// When in general use, make the shortcode disappear.
	return $content;
}

function relevanssi_noindex_shortcode_indexing($atts, $content) {
	// When indexing, make the text disappear.
	return '';
}

function relevanssi_tokenize($str, $remove_stops = true, $min_word_length = -1) {
	$tokens = array();
	if (is_array($str)) {
		foreach ($str as $part) {
			$tokens = array_merge($tokens, relevanssi_tokenize($part, $remove_stops, $min_word_length));
		}
	}
	if (is_array($str)) return $tokens;
	
	if ( function_exists('mb_internal_encoding') )
		mb_internal_encoding("UTF-8");

	if ($remove_stops) {
		$stopword_list = relevanssi_fetch_stopwords();
	}

	if (function_exists('relevanssi_thousandsep')) {	
		$str = relevanssi_thousandsep($str);
	}

	$str = apply_filters('relevanssi_remove_punctuation', $str);

	if ( function_exists('mb_strtolower') )
		$str = mb_strtolower($str);
	else
		$str = strtolower($str);
	
	$t = strtok($str, "\n\t ");
	while ($t !== false) {
		$accept = true;
		if (strlen($t) < $min_word_length) {
			$t = strtok("\n\t  ");
			continue;
		}
		if ($remove_stops == false) {
			$accept = true;
		}
		else {
			if (count($stopword_list) > 0) {	//added by OdditY -> got warning when stopwords table was empty
				if (in_array($t, $stopword_list)) {
					$accept = false;
				}
			}
		}

		if (RELEVANSSI_PREMIUM) {
			$t = apply_filters('relevanssi_premium_tokenizer', $t);
		}
		
		if ($accept) {
			$t = relevanssi_mb_trim($t);
			if (!isset($tokens[$t])) {
				$tokens[$t] = 1;
			}
			else {
				$tokens[$t]++;
			}
		}
		
		$t = strtok("\n\t ");
	}

	return $tokens;
}

// This is my own magic working.
function relevanssi_search($q, $cat = NULL, $excat = NULL, $tag = NULL, $expost = NULL, $post_type = NULL, $taxonomy = NULL, $taxonomy_term = NULL, $operator = "AND", $search_blogs = NULL, $customfield_key = NULL, $customfield_value = NULL, $author = NULL) {
	global $relevanssi_table, $wpdb, $relevanssi_variables;
	if (isset($relevanssi_variables['relevanssi_table'])) $relevanssi_table = $relevanssi_variables['relevanssi_table'];

	$values_to_filter = array(
		'q' => $q,
		'cat' => $cat,
		'excat' => $excat,
		'tag' => $tag,
		'expost' => $expost,
		'post_type' => $post_type,
		'taxonomy' => $taxonomy,
		'taxonomy_term' => $taxonomy_term,
		'operator' => $operator,
		'search_blogs' => $search_blogs,
		'customfield_key' => $customfield_key,
		'customfield_value' => $customfield_value,
		'author' => $author,
		);
	$filtered_values = apply_filters( 'relevanssi_search_filters', $values_to_filter );
	$q               = $filtered_values['q'];
	$cat             = $filtered_values['cat'];
	$tag             = $filtered_values['tag'];
	$excat           = $filtered_values['excat'];
	$expost          = $filtered_values['expost'];
	$post_type       = $filtered_values['post_type'];
	$taxonomy        = $filtered_values['taxonomy'];
	$taxonomy_term   = $filtered_values['taxonomy_term'];
	$operator        = $filtered_values['operator'];
	$search_blogs    = $filtered_values['search_blogs'];
	$customfield_key = $filtered_values['customfield_key'];
	$customfield_value = $filtered_values['customfield_value'];
	$author	  	     = $filtered_values['author'];

	$hits = array();

	$o_cat = $cat;
	$o_excat = $excat;
	$o_tag = $tag;
	$o_expost = $expost;
	$o_post_type = $post_type;
	$o_taxonomy = $taxonomy;
	$o_taxonomy_term = $taxonomy_term;
	$o_customfield_key = $customfield_key;
	$o_customfield_value = $customfield_value;
	$o_author = $author;

	if (function_exists('relevanssi_process_customfield')) {
		$customfield = relevanssi_process_customfield($customfield_key, $customfield_value);
	}
	else {
		$customfield = false;
	}
	
	if ($cat) {
		$cats = explode(",", $cat);
		$inc_term_tax_ids = array();
		$ex_term_tax_ids = array();
		foreach ($cats as $t_cat) {
			$exclude = false;
			if ($t_cat < 0) {
				// Negative category, ie. exclusion
				$exclude = true;
				$t_cat = substr($t_cat, 1); // strip the - sign.
			}
			$t_cat = $wpdb->escape($t_cat);
			$term_tax_id = $wpdb->get_var("SELECT term_taxonomy_id FROM $wpdb->term_taxonomy
				WHERE term_id=$t_cat");
			if ($term_tax_id) {
				$exclude ? $ex_term_tax_ids[] = $term_tax_id : $inc_term_tax_ids[] = $term_tax_id;
				$children = get_term_children($term_tax_id, 'category');
				if (is_array($children)) {
					foreach ($children as $child) {
						$exclude ? $ex_term_tax_ids[] = $child : $inc_term_tax_ids[] = $child;
					}
				}
			}
		}
		
		$cat = implode(",", $inc_term_tax_ids);
		$excat_temp = implode(",", $ex_term_tax_ids);
	}

	if ($excat) {
		$excats = explode(",", $excat);
		$term_tax_ids = array();
		foreach ($excats as $t_cat) {
			$t_cat = $wpdb->escape(trim($t_cat, ' -'));
			$term_tax_id = $wpdb->get_var("SELECT term_taxonomy_id FROM $wpdb->term_taxonomy
				WHERE term_id=$t_cat");
			if ($term_tax_id) {
				$term_tax_ids[] = $term_tax_id;
			}
		}
		
		$excat = implode(",", $term_tax_ids);
	}

	if (isset($excat_temp)) {
		$excat .= $excat_temp;
	}

	if ($tag) {
		$tags = explode(",", $tag);
		$inc_term_tax_ids = array();
		$ex_term_tax_ids = array();
		foreach ($tags as $t_tag) {
			$t_tag = $wpdb->escape($t_tag);
			$term_tax_id = $wpdb->get_var("
				SELECT term_taxonomy_id
					FROM $wpdb->term_taxonomy as a, $wpdb->terms as b
					WHERE a.term_id = b.term_id AND
						(a.term_id='$t_tag' OR b.name LIKE '$t_tag')");

			if ($term_tax_id) {
				$inc_term_tax_ids[] = $term_tax_id;
			}
		}
		
		$tag = implode(",", $inc_term_tax_ids);
	}
	
	if ($author) {
		$author = esc_sql($author);
	}

	if (!empty($taxonomy)) {
		if (function_exists('relevanssi_process_taxonomies')) {
			$taxonomy = relevanssi_process_taxonomies($taxonomy, $taxonomy_term);
		}
		else {
			$term_tax_id = null;
			$term_tax_id = $wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM $wpdb->terms
				JOIN $wpdb->term_taxonomy USING(`term_id`)
					WHERE `slug` LIKE %s AND `taxonomy` LIKE %s", "%$taxonomy_term%", $taxonomy));
			if ($term_tax_id) {
				$taxonomy = $term_tax_id;
			} else {
				$taxonomy = null;
			}
		}
	}

	if (!$post_type && get_option('relevanssi_respect_exclude') == 'on') {
		if (function_exists('get_post_types')) {
			$pt_1 = get_post_types(array('exclude_from_search' => '0'));
			$pt_2 = get_post_types(array('exclude_from_search' => false));
			$post_type = implode(',', array_merge($pt_1, $pt_2));
		}
	}
	
	if ($post_type) {
		if (!is_array($post_type)) {
			$post_types = explode(',', $post_type);
		}
		else {
			$post_types = $post_type;
		}
		$pt_array = array();
		foreach ($post_types as $pt) {
			$pt = "'" . trim(mysql_real_escape_string($pt)) . "'";
			array_push($pt_array, $pt);
		}
		$post_type = implode(",", $pt_array);
	}

	//Added by OdditY:
	//Exclude Post_IDs (Pages) for non-admin search ->
	if ($expost) {
		if ($expost != "") {
			$aexpids = explode(",",$expost);
			foreach ($aexpids as $exid){
				$exid = $wpdb->escape(trim($exid, ' -'));
				$postex .= " AND doc !='$exid'";
			}
		}	
	}
	// <- OdditY End

	$remove_stopwords = false;
	$phrases = relevanssi_recognize_phrases($q);

	if (function_exists('relevanssi_recognize_negatives')) {
		$negative_terms = relevanssi_recognize_negatives($q);
	}
	else {
		$negative_terms = false;
	}
	
	if (function_exists('relevanssi_recognize_positives')) {
		$positive_terms = relevanssi_recognize_positives($q);
	}
	else {
		$positive_terms = false;
	}

	$terms = relevanssi_tokenize($q, $remove_stopwords);
	if (count($terms) < 1) {
		// Tokenizer killed all the search terms.
		return $hits;
	}
	$terms = array_keys($terms); // don't care about tf in query

	if ($negative_terms) {	
		$terms = array_diff($terms, $negative_terms);
		if (count($terms) < 1) {
			return $hits;
		}
	}
	
	$D = $wpdb->get_var("SELECT COUNT(DISTINCT(doc)) FROM $relevanssi_table");
	
	$total_hits = 0;
		
	$title_matches = array();
	$tag_matches = array();
	$comment_matches = array();
	$link_matches = array();
	$body_matches = array();
	$scores = array();
	$term_hits = array();

	$fuzzy = get_option('relevanssi_fuzzy');

	$query_restrictions = "";
	if ($expost) { //added by OdditY
		$query_restrictions .= $postex;
	}

	if (function_exists('relevanssi_negatives_positives')) {	
		$query_restrictions .= relevanssi_negatives_positives($negative_terms, $positive_terms, $relevanssi_table);
	}
	
	if ($cat) {
		$query_restrictions .= " AND doc IN (SELECT DISTINCT(object_id) FROM $wpdb->term_relationships
		    WHERE term_taxonomy_id IN ($cat))";
	}
	if ($excat) {
		$query_restrictions .= " AND doc NOT IN (SELECT DISTINCT(object_id) FROM $wpdb->term_relationships
		    WHERE term_taxonomy_id IN ($excat))";
	}
	if ($tag) {
		$query_restrictions .= " AND doc IN (SELECT DISTINCT(object_id) FROM $wpdb->term_relationships
		    WHERE term_taxonomy_id IN ($tag))";
	}
	if ($author) {
		$query_restrictions .= " AND doc IN (SELECT DISTINCT(ID) FROM $wpdb->posts
		    WHERE post_author IN ($author))";
	}
	if ($post_type) {
		// the -1 is there to get user profiles and category pages
		$query_restrictions .= " AND ((doc IN (SELECT DISTINCT(ID) FROM $wpdb->posts
			WHERE post_type IN ($post_type))) OR (doc = -1))";
	}
	if ($phrases) {
		$query_restrictions .= " AND doc IN ($phrases)";
	}
	if ($customfield) {
		$query_restrictions .= " AND doc IN ($customfield)";
	}
	if (is_array($taxonomy)) {
		foreach ($taxonomy as $tax) {
			$taxonomy_in = implode(',',$tax);
			$query_restrictions .= " AND doc IN (SELECT DISTINCT(object_id) FROM $wpdb->term_relationships
				WHERE term_taxonomy_id IN ($taxonomy_in))";
		}
	}

	if (isset($_REQUEST['by_date'])) {
		$n = $_REQUEST['by_date'];

		$u = substr($n, -1, 1);
		switch ($u) {
			case 'h':
				$unit = "HOUR";
				break;
			case 'd':
				$unit = "DAY";
				break;
			case 'm':
				$unit = "MONTH";
				break;
			case 'y':
				$unit = "YEAR";
				break;
			case 'w':
				$unit = "WEEK";
				break;
			default:
				$unit = "DAY";
		}

		$n = preg_replace('/[hdmyw]/', '', $n);

		if (is_numeric($n)) {
			$query_restrictions .= " AND doc IN (SELECT DISTINCT(ID) FROM $wpdb->posts
				WHERE post_date > DATE_SUB(NOW(), INTERVAL $n $unit))";
		}
	}

	$query_restrictions = apply_filters('relevanssi_where', $query_restrictions); // Charles St-Pierre

	$no_matches = true;
	if ("always" == $fuzzy) {
		$o_term_cond = apply_filters('relevanssi_fuzzy_query', "(term LIKE '%#term#' OR term LIKE '#term#%') ");
	}
	else {
		$o_term_cond = " term = '#term#' ";
	}

	$post_type_weights = get_option('relevanssi_post_type_weights');
	if (function_exists('relevanssi_get_recency_bonus')) {
		list($recency_bonus, $recency_cutoff_date) = relevanssi_get_recency_bonus();
	}
	else {
		$recency_bonus = false;
		$recency_cutoff_date = false;
	}
	$min_length = get_option('relevanssi_min_word_length');
	
	$search_again = false;
	do {
		foreach ($terms as $term) {
			if (strlen($term) < $min_length) continue;
			$term = $wpdb->escape(like_escape($term));
			$term_cond = str_replace('#term#', $term, $o_term_cond);		
			
			$query = "SELECT *, title + content + comment + tag + link + author + category + excerpt + taxonomy + customfield + mysqlcolumn AS tf 
					  FROM $relevanssi_table WHERE $term_cond $query_restrictions";
			$query = apply_filters('relevanssi_query_filter', $query);

			$matches = $wpdb->get_results($query);
			if (count($matches) < 1) {
				continue;
			}
			else {
				$no_matches = false;
			}
			
			relevanssi_populate_array($matches);
			global $relevanssi_post_types;

			$total_hits += count($matches);
	
			$query = "SELECT COUNT(DISTINCT(doc)) FROM $relevanssi_table WHERE $term_cond $query_restrictions";
			$query = apply_filters('relevanssi_df_query_filter', $query);
	
			$df = $wpdb->get_var($query);
	
			if ($df < 1 && "sometimes" == $fuzzy) {
				$query = "SELECT COUNT(DISTINCT(doc)) FROM $relevanssi_table
					WHERE (term LIKE '%$term' OR term LIKE '$term%') $query_restrictions";
				$query = apply_filters('relevanssi_df_query_filter', $query);
				$df = $wpdb->get_var($query);
			}
			
			$title_boost = floatval(get_option('relevanssi_title_boost'));
			$link_boost = floatval(get_option('relevanssi_link_boost'));
			$comment_boost = floatval(get_option('relevanssi_comment_boost'));
			
			$idf = log($D / (1 + $df));
			$idf = $idf * $idf;
			foreach ($matches as $match) {
				if ('user' == $match->type) {
					$match->doc = 'u_' . $match->item;
				}

				if ('taxonomy' == $match->type) {
					$match->doc = 't_' . $match->item;
				}

				$match = relevanssi_match_taxonomy_detail($match, $post_type_weights);				
				
				$match->tf =
					$match->title * $title_boost +
					$match->content +
					$match->comment * $comment_boost +
					$match->link * $link_boost +
					$match->author +
					$match->excerpt +
					$match->taxonomy_score +
					$match->customfield +
					$match->mysqlcolumn;

				$term_hits[$match->doc][$term] =
					$match->title +
					$match->content +
					$match->comment +
					$match->tag +
					$match->link +
					$match->author +
					$match->category +
					$match->excerpt +
					$match->taxonomy +
					$match->customfield +
					$match->mysqlcolumn;

				$match->weight = $match->tf * $idf;
				
				if ($recency_bonus) {
					$post = relevanssi_get_post($match->doc);
					if (strtotime($post->post_date) > $recency_cutoff_date)
						$match->weight = $match->weight * $recency_bonus['bonus'];
				}

				$body_matches[$match->doc] = $match->content;
				$title_matches[$match->doc] = $match->title;
				$tag_matches[$match->doc] = $match->tag;
				$comment_matches[$match->doc] = $match->comment;
	
				$type = $relevanssi_post_types[$match->doc];
				if (isset($post_type_weights[$type])) {
					$match->weight = $match->weight * $post_type_weights[$type];
				}

				$match = apply_filters('relevanssi_match', $match, $idf);

				if ($match->weight == 0) continue; // the filters killed the match

				$post_ok = true;
				$post_ok = apply_filters('relevanssi_post_ok', $post_ok, $match->doc);
				
				if ($post_ok) {
					$doc_terms[$match->doc][$term] = true; // count how many terms are matched to a doc
					isset($doc_weight[$match->doc]) ? $doc_weight[$match->doc] += $match->weight : $doc_weight[$match->doc] = $match->weight;
					isset($scores[$match->doc]) ? $scores[$match->doc] += $match->weight : $scores[$match->doc] = $match->weight;
				}
			}
		}

		if (!isset($doc_weight)) $no_matches = true;

		if ($no_matches) {
			if ($search_again) {
				// no hits even with fuzzy search!
				$search_again = false;
			}
			else {
				if ("sometimes" == $fuzzy) {
					$search_again = true;
					$o_term_cond = "(term LIKE '%#term#' OR term LIKE '#term#%') ";
				}
			}
		}
		else {
			$search_again = false;
		}
	} while ($search_again);
	
	$strip_stops = true;
	$temp_terms_without_stops = array_keys(relevanssi_tokenize(implode(' ', $terms), $strip_stops));
	$terms_without_stops = array();
	foreach ($temp_terms_without_stops as $temp_term) {
		if (strlen($temp_term) >= $min_length)
			array_push($terms_without_stops, $temp_term);
	}
	$total_terms = count($terms_without_stops);

	if (isset($doc_weight))
		$doc_weight = apply_filters('relevanssi_results', $doc_weight);

	if (isset($doc_weight) && count($doc_weight) > 0) {
		arsort($doc_weight);
		$i = 0;
		foreach ($doc_weight as $doc => $weight) {
			if (count($doc_terms[$doc]) < $total_terms && $operator == "AND") {
				// AND operator in action:
				// doc didn't match all terms, so it's discarded
				continue;
			}
			
			$hits[intval($i++)] = relevanssi_get_post($doc);
		}
	}

	if (count($hits) < 1) {
		if ($operator == "AND" AND get_option('relevanssi_disable_or_fallback') != 'on') {
			$return = relevanssi_search($q, $o_cat, $o_excat, $o_tag, $o_expost, $o_post_type, $o_taxonomy, $o_taxonomy_term, "OR", $search_blogs, $o_customfield_key, $o_customfield_value);
			extract($return);
		}
	}

	global $wp;	
	$default_order = get_option('relevanssi_default_orderby', 'relevance');
	isset($wp->query_vars["orderby"]) ? $orderby = $wp->query_vars["orderby"] : $orderby = $default_order;
	isset($wp->query_vars["order"]) ? $order = $wp->query_vars["order"] : $order = 'desc';
	if ($orderby != 'relevance')
		relevanssi_object_sort($hits, $orderby, $order);

	$return = array('hits' => $hits, 'body_matches' => $body_matches, 'title_matches' => $title_matches,
		'tag_matches' => $tag_matches, 'comment_matches' => $comment_matches, 'scores' => $scores,
		'term_hits' => $term_hits, 'query' => $q);

	return $return;
}
?>