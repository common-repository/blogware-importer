<?php
/*
Plugin Name: Blogware Importer
Plugin URI: http://wordpress.org/extend/plugins/blogware-importer/
Description: Import posts from Blogware.
Author: wordpressdotorg
Author URI: http://wordpress.org/
Version: 0.2
Stable tag: 0.2
License: GPL v2 - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

if ( !defined('WP_LOAD_IMPORTERS') )
	return;

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( !class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require_once $class_wp_importer;
}

/**
 * Blogware Importer
 *
 * @package WordPress
 * @subpackage Importer
 */
if ( class_exists( 'WP_Importer' ) ) {
class BW_Import extends WP_Importer {

	var $file;

	function header() {
		echo '<div class="wrap">';
		screen_icon();
		echo '<h2>'.__('Import Blogware', 'blogware-importer').'</h2>';
	}

	function footer() {
		echo '</div>';
	}

	function greet() {
		echo '<div class="narrow">';
		echo '<p>'.__('Howdy! This importer allows you to extract posts from Blogware XML export file into your site. Pick a Blogware file to upload and click Import.', 'blogware-importer').'</p>';
		wp_import_upload_form("admin.php?import=blogware&amp;step=1");
		echo '</div>';
	}

	function _normalize_tag( $matches ) {
		return '<' . strtolower( $matches[1] );
	}

	function import_posts() {
		global $wpdb, $current_user;

		set_magic_quotes_runtime(0);
		$importdata = file($this->file); // Read the file into an array
		$importdata = implode('', $importdata); // squish it
		$importdata = str_replace(array ("\r\n", "\r"), "\n", $importdata);

		preg_match_all('|(<item[^>]+>(.*?)</item>)|is', $importdata, $posts);
		$posts = $posts[1];
		unset($importdata);
		echo '<ol>';
		foreach ($posts as $post) {
			flush();
			preg_match('|<item type=\"(.*?)\">|is', $post, $post_type);
			$post_type = $post_type[1];
			if ($post_type == "photo") {
				preg_match('|<photoFilename>(.*?)</photoFilename>|is', $post, $post_title);
			} else {
				preg_match('|<title>(.*?)</title>|is', $post, $post_title);
			}
			$post_title = $wpdb->escape(trim($post_title[1]));

			preg_match('|<pubDate>(.*?)</pubDate>|is', $post, $post_date);
			$post_date = strtotime($post_date[1]);
			$post_date = gmdate('Y-m-d H:i:s', $post_date);

			preg_match_all('|<category>(.*?)</category>|is', $post, $categories);
			$categories = $categories[1];

			$cat_index = 0;
			foreach ($categories as $category) {
				$categories[$cat_index] = $wpdb->escape( html_entity_decode($category) );
				$cat_index++;
			}

			if ( strcasecmp($post_type, "photo") === 0 ) {
				preg_match('|<sizedPhotoUrl>(.*?)</sizedPhotoUrl>|is', $post, $post_content);
				$post_content = '<img src="'.trim($post_content[1]).'" />';
				$post_content = html_entity_decode( $post_content );
			} else {
				preg_match('|<body>(.*?)</body>|is', $post, $post_content);
				$post_content = str_replace(array ('<![CDATA[', ']]>'), '', trim($post_content[1]));
				$post_content = html_entity_decode( $post_content );
			}

			// Clean up content
			$post_content = preg_replace_callback('|<(/?[A-Z]+)|', array( &$this, '_normalize_tag' ), $post_content);
			$post_content = str_replace('<br>', '<br />', $post_content);
			$post_content = str_replace('<hr>', '<hr />', $post_content);
			$post_content = $wpdb->escape($post_content);

			$post_author = $current_user->ID;
			preg_match('|<postStatus>(.*?)</postStatus>|is', $post, $post_status);
			$post_status = trim($post_status[1]);

			echo '<li>';
			if ($post_id = post_exists($post_title, $post_content, $post_date)) {
				printf(__('Post <em>%s</em> already exists.', 'blogware-importer'), stripslashes($post_title));
			} else {
				printf(__('Importing post <em>%s</em>...', 'blogware-importer'), stripslashes($post_title));
				$postdata = compact('post_author', 'post_date', 'post_content', 'post_title', 'post_status');
				$post_id = wp_insert_post($postdata);
				if ( is_wp_error( $post_id ) ) {
					return $post_id;
				}
				if (!$post_id) {
					_e('Couldn&#8217;t get post ID', 'blogware-importer');
					echo '</li>';
					break;
				}
				if ( 0 != count($categories) )
					wp_create_categories($categories, $post_id);
			}

			preg_match_all('|<comment>(.*?)</comment>|is', $post, $comments);
			$comments = $comments[1];

			if ( $comments ) {
				$comment_post_ID = (int) $post_id;
				$num_comments = 0;
				foreach ($comments as $comment) {
					preg_match('|<body>(.*?)</body>|is', $comment, $comment_content);
					$comment_content = str_replace(array ('<![CDATA[', ']]>'), '', trim($comment_content[1]));
					$comment_content = html_entity_decode( $comment_content );

					// Clean up content
					$comment_content = preg_replace_callback('|<(/?[A-Z]+)|', array( &$this, '_normalize_tag' ), $comment_content);
					$comment_content = str_replace('<br>', '<br />', $comment_content);
					$comment_content = str_replace('<hr>', '<hr />', $comment_content);
					$comment_content = $wpdb->escape($comment_content);

					preg_match('|<pubDate>(.*?)</pubDate>|is', $comment, $comment_date);
					$comment_date = trim($comment_date[1]);
					$comment_date = date('Y-m-d H:i:s', strtotime($comment_date));

					preg_match('|<author>(.*?)</author>|is', $comment, $comment_author);
					$comment_author = $wpdb->escape(trim($comment_author[1]));

					$comment_author_email = NULL;

					$comment_approved = 1;
					// Check if it's already there
					if (!comment_exists($comment_author, $comment_date)) {
						$commentdata = compact('comment_post_ID', 'comment_author', 'comment_author_email', 'comment_date', 'comment_content', 'comment_approved');
						$commentdata = wp_filter_comment($commentdata);
						wp_insert_comment($commentdata);
						$num_comments++;
					}
				}
			}
			if ( $num_comments ) {
				echo ' ';
				printf( _n('%s comment', '%s comments', $num_comments, 'blogware-importer'), $num_comments );
			}
			echo '</li>';
			flush();
			ob_flush();
		}
		echo '</ol>';
	}

	function import() {
		$file = wp_import_handle_upload();
		if ( isset($file['error']) ) {
			echo $file['error'];
			return;
		}

		$this->file = $file['file'];
		$result = $this->import_posts();
		if ( is_wp_error( $result ) )
			return $result;
		wp_import_cleanup($file['id']);
		do_action('import_done', 'blogware');
		echo '<h3>';
		printf(__('All done. <a href="%s">Have fun!</a>', 'blogware-importer'), get_option('home'));
		echo '</h3>';
	}

	function dispatch() {
		if (empty ($_GET['step']))
			$step = 0;
		else
			$step = (int) $_GET['step'];

		$this->header();

		switch ($step) {
			case 0 :
				$this->greet();
				break;
			case 1 :
				$result = $this->import();
				if ( is_wp_error( $result ) )
					$result->get_error_message();
				break;
		}

		$this->footer();
	}

	function BW_Import() {
		// Nothing.
	}
}

$blogware_import = new BW_Import();

register_importer('blogware', __('Blogware', 'blogware-importer'), __('Import posts from Blogware.', 'blogware-importer'), array ($blogware_import, 'dispatch'));

} // class_exists( 'WP_Importer' )

function blogware_importer_init() {
    load_plugin_textdomain( 'blogware-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'blogware_importer_init' );
