<?php

// Reads automatically the correct stopwords for the current language set in WPLANG.
function relevanssi_populate_stopwords() {
	global $wpdb, $relevanssi_variables;

	if (WPLANG == '') {
		$lang = "en_GB";
	}
	else {
		$lang = WPLANG;
	}
	
	include('stopwords.' . $lang);

	if (is_array($stopwords) && count($stopwords) > 0) {
		foreach ($stopwords as $word) {
			$q = $wpdb->prepare("INSERT IGNORE INTO " . $relevanssi_variables['stopword_table'] . " (stopword) VALUES (%s)", trim($word));
			$wpdb->query($q);
		}
	}
}

function relevanssi_fetch_stopwords() {
	global $wpdb, $relevanssi_variables;
	
	if (!isset($relevanssi_variables['stopword_list'])) $relevanssi_variables['stopword_list'] = array();
	
	if (count($relevanssi_variables['stopword_list']) < 1) {
		$results = $wpdb->get_results("SELECT stopword FROM " . $relevanssi_variables['stopword_table']);
		foreach ($results as $word) {
			$relevanssi_variables['stopword_list'][] = $word->stopword;
		}
	}
	
	return $relevanssi_variables['stopword_list'];
}

?>