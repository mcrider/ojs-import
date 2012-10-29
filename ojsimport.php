<?php
/* Plugin name: OJS Import */
/*

Copyright (c) 2012, Matthew Crider

Code based on SMW Import plugin by Christoph Herbst (http://wordpress.org/extend/plugins/smw-import/).

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

require_once(ABSPATH . "wp-admin" . '/includes/bookmark.php');
require_once(ABSPATH . "wp-admin" . '/includes/taxonomy.php');
// for the function wp_generate_attachment_metadata()
require_once(ABSPATH . "wp-admin" . '/includes/image.php');
require_once(dirname(__FILE__) . '/ojsaccess.php');
require_once(dirname(__FILE__) . '/ojsimport-mapping.php');
require_once(dirname(__FILE__) . '/favicon.inc.php');

class ojsimport
{
  // time measure variables
  static $start_time;
  static $fetch_time;
  // post time for creating posts
  static $posttime;
  // array to remember post dates for uniqueness
  static $global_post_dates;
  // array to hold all imported posts
  // is used to delete posts which are not available in the sources
  static $global_imported_posts;
  // array to hold all imported links
  // is used to delete links which are not available in the sources
  static $global_imported_links;

  /* returns an array of the ids of all imported subcategories 
  */
  private static function get_imported_sub_categories($mappings){
	$subcats = array();
	foreach($mappings as $mapping){
		if ( $mapping['type'] != 'post' ) continue;
		if ( !isset($mapping['category']) ) continue;
		// top level category
		$topcat = get_category_by_slug($mapping['category']);
		if ( !$topcat ) continue;
		foreach( $mapping['attributes'] as $attr => $type ){
			if ( is_array($type) ){
				if ( ! in_array('category',$type) ) continue;
			}else if ( $type != 'category' ) continue;
			// get parent cat
			$parentcat = self::get_category_by_slug_and_parent($attr,$topcat->term_id);
			if ( $parentcat == -1 ) continue;
			// get sub categories
			$cats = get_categories( "hide_empty=0&parent=".$parentcat );
			foreach( $cats as $cat ){
				$subcats[] = (int)$cat->term_id;
			}
			$subcats[] = $parentcat;
		}
		$subcats[] = $topcat->term_id;
	}
	return $subcats;
  }

  /*  returns array of all defined data sources together with function to retrieve the data
      returns: array( 'url' => function ) or array( WP_Error )
  */
  private static function get_data_sources(){
	$data_sources = get_option( 'ojsimport_data_sources', array() );
	if (empty($data_sources)) 
		return array(new WP_Error('no_data_sources', __("No data sources defined.")));

	foreach( $data_sources as $source )
		$sources[$source] = array('self','get_data_from_source');

	return $sources;
  }

  /*  returns array of ojs items from datasource or a WP_Error
      $url: url of data source
  */
  private static function get_data_from_source($url){

	$ret = true;
	$start = time(); 
	$content = ojsaccess::get_content($url);
	self::$fetch_time += time()-$start;
	if ($content === false)
		return new WP_Error('data_source_error', __("Could not retrieve data source:").$url);
	//XXX: assume that empty lines only occur in strings (may break import)
	$content = str_replace(array("\n\n"),'<p></p>',$content);
	$content = str_replace(array("\r", "\r\n", "\n"),' ',$content);
	$data = json_decode($content,true);

	if ( !$data ){
		// try more robust JSON library
		require_once( dirname(__FILE__) . '/lib/JSON.php');
		$value = new Services_JSON(SERVICES_JSON_LOOSE_TYPE); 
		$data = $value->decode($content);
		if ( !$data )
			return new WP_Error('data_source_error', __("Could not decode source into json:").$url);
		$data['items'][] = new WP_Error('data_source_error', __("Could not fully decode source into json:").$url."\n" .
				__("Imported data might be incomplete!"));
	}
	return $data['items'];
  }

  /* returns the id of the wordpess category if it exists, otherwise -1
     $slug: slug of the category
     $parent: $parent of the category
  */ 
  private static function get_category_by_slug_and_parent($slug,$parent = null){
	$cat_id = -1;
	if ( $parent == null ){
		$cat = get_category_by_slug($slug);
		if ( $cat )
			$cat_id = $cat->term_id;
	}else{
		//XXX: again needed because of a bug in wordpress term cache
		wp_cache_flush();
		//XXX: same bug, needed for wp_cron support
		delete_option('category_children');
		$cats = get_categories( "hide_empty=0&parent=".$parent );
		$parentcat = get_category($parent);
		foreach( $cats as $cat ){
			if ( $cat->slug == $slug || $cat->slug == $slug.'-'.$parentcat->slug )
				$cat_id = (int)$cat->term_id;
		}
	}
	return $cat_id;
  }

  /* 	create a category if it does not exist 
  */
  private static function create_category($category){
	$cat_id = self::get_category_by_slug_and_parent(
		$category['category_nicename'],
		$category['category_parent']);

	if ( $cat_id == -1 )
		$cat_id = wp_insert_category($category, true);

	if ( is_wp_error( $cat_id ) ) {
		if ( 'term_exists' == $cat_id->get_error_code() )
			return (int) $cat_id->get_error_data();
	} elseif ( ! $cat_id ) {
		return(new WP_Error('category_failed', __("Sorry, the new category failed.")));
	}

	return($cat_id);
  }

  /* 	delete imported subcategories that are no longer used 
	( have no posts attached )
  */
  private static function delete_empty_subcategories($mapping){

	foreach( self::get_imported_sub_categories($mapping) as $category ){
		// XXX: the following should work, but does not!
		//if ($child->category_count == 0){
		$objects = get_objects_in_term($category,'category');
		if ( empty($objects) ){
			$cat = get_category($category);
			wp_delete_category( $category );
		}
	}
	return true;
  }

  /*  converts an ojs file attribute value of the form
      '<prefix>:filename' into an array which can be used by
      self::import_attachment_for_post
  */
  private static function convert_ojs_attachment_to_array($attachment){
	$array = explode(':',$attachment);
	if ( count($array) < 2 ) return false;
	$file = $array[count($array)-1];
	$attachment_url = get_option('ojsimport_attachment_url');
	$attach_array = array( 'title' => $file,
			       'file' => $file,
			       'url' => $attachment_url.urlencode($file));
	return $attach_array;
  }

  /* returns a unique post date based on $date for that category */
  private static function get_unique_post_date($date,$cat){
	$time = strtotime($date);
	while ( isset(self::$global_post_dates[$cat][$time] ) )
		$time++;

	self::$global_post_dates[$cat][$time] = 1;	
	return date("Y-m-d H:i:s",$time);
  }

  /*  imports $data into a wordpress post according to $mapping
  */
  private static function import_post_type($mapping,$data){
	$attribute_mapping = $mapping['attributes'];
	$attachments = array();
	$calendar = null;
	$metas = array();
	$categories = null;
	$g_ret = true;

	// init some post properties
	$postarr = array( 
		'post_title' => '',
		'post_content' => '',	
		'post_excerpt' => '');	
	$globalattachment = null;
	$favicon = null;

	// create top level category
	$cat = self::create_category(array(
			'cat_name' => $mapping['category'],
			'category_nicename' => $mapping['category']));

	if ( is_wp_error($cat) )
		return $cat;

	foreach( $data as $key => $value ){
		if ( is_array($attribute_mapping[$key]) )
			$key_mapping = $attribute_mapping[$key];
		else
			$key_mapping = array($attribute_mapping[$key]);
		foreach( $key_mapping as $key_map ){
			switch($key_map){
				case 'post_title':
				case 'post_excerpt':
				case 'post_content':
				case 'post_date':
					if ( $key_map == 'post_date' )
						$value = self::get_unique_post_date($value,$cat);
					$postarr[$key_map] = $value;
					break;
				case 'globalattachment':
					$globalattachment = $key;
				case 'attachment':
					$attachments[] = $key;
					break;
				case 'calendar_start':
					$calendar['start'] = $value;
					break;
				case 'calendar_end':
					$calendar['end'] = $value;
					break;
				case 'meta':
					$metas[] = $key;
					break;
				case 'favicon':
					$favicon = $value;
					break;
				case 'category':
					$categories[$key] = $value;
					break;
				default:
					// ignore some keys
					if ( $key != 'uri' && $key != 'type' && $key != $mapping['primary_key'] &&
						substr($key,-5) != '_name' )
						error_log('ojsimport: no mapping defined for:'.$key);
			}
		}
	}
	$prim_key = $data[$mapping['primary_key']];

	// if title is empty, use primary key
	if ( $postarr['post_title'] == '' )
		$postarr['post_title'] = $prim_key;

	// create the post
	$ID = self::import_post($prim_key,$postarr,$cat);
	if ( is_wp_error($ID) ){
		error_log('Could not import:'.$prim_key);
		return $ID;
	}

	// import attachments
	foreach( $attachments as $attachment ){
		$attach_arr = $data[$attachment];
		$attach_arrs = array();
		if ( !is_array($attach_arr) )
		       	// the attach_arr should be an ojs file URI
			$attach_arr = array($attach_arr);
		if (isset($attach_arr['url'])) {
			// the attach_arr is already in the right format
			$attach_arrs[] = $attach_arr;
		}else{
		       	// the attach_arr is an array of attachments
			foreach( $attach_arr as $arr ){
				if (is_array($arr))
					$attach_arrs[] = $arr;
				else
					$attach_arrs[] = self::convert_ojs_attachment_to_array($arr);
			}
		}

		$ids = array();
		foreach ( $attach_arrs as $key => $attach_arr ){
			if ( $attach_arr === false ){
				$g_ret = new WP_Error('attach_err', 
					__("Could not get ojs attachment:").$attachment);
				error_log($g_ret->get_error_message());
				continue;
			}

			$attachmentname = $attachment.'_name';
			if ( isset($data[$attachmentname]) ){
				if ( !is_array($data[$attachmentname]) )
					$data[$attachmentname] = array($data[$attachmentname]);
				$attach_arr['title'] = $data[$attachmentname][$key];
			}

			$ret = self::import_attachment_for_post($prim_key.$attachment.$key,$attach_arr,$ID);
			if ( is_wp_error($ret) ){
				$g_ret = $ret;
				continue;
			}
			$ids[] = $ret;
		}

		if ( count($ids) == 1 ) $ids = $ids[0];
		if ( $attachment == $globalattachment ){
			$val = get_option($globalattachment,array());
			if ( !is_array($val) )
				$val = array($val);
			$val[] = $ids;
			update_option($globalattachment,$val);
		}
		// store attachment ID as post meta
		update_post_meta($ID,$attachment,$ids);
	}

	// import dates
	if ( is_array($calendar) ){
		$action = 'create';
		if ( isset($postarr['ID']) )
			$action = 'update';
		self::import_post_dates($ID,$action,$calendar['start'], $calendar['end']);
	}

	// import meta data
	foreach( $metas as $meta )
		update_post_meta($ID,$meta,$data[$meta]);

	// import favicon url
	if ( $favicon != null )
		self::import_favicon_url($ID,$favicon);

	// create categories
	if ( $categories != null ){
		$ret = self::import_post_categories($ID,$categories,$cat);
		if ( is_wp_error($ret) ) $g_ret = $ret;
	}
	return $g_ret;
  }

  /* get the favicon url and cache it in an option */
  private function get_favicon_url($url){
	$favicon = new favicon($url, 0);
	$favicon_url = get_option('favicon-'.$favicon->get_site_url());
	if ( $favicon_url == null ){
		if ( $favicon->is_ico_exists() ){
			$favicon_url = $favicon->get_ico_url();
			update_option('favicon-'.$favicon->get_site_url(),$favicon_url);
		}
	}
	return $favicon_url;
  }

  /*  saves the favicon url of a given url into a post meta
  */
  private function import_favicon_url($post_id,$url){
	$favicon = self::get_favicon_url($url);

	if ( $favicon != null )
		update_post_meta($post_id,'favicon',$favicon);
  }

  /* creates a page with the given name, or returns the id of
     the page if it exists */
  private static function create_page($page){
	$postarr['post_type']  = 'page';
	$postarr['post_title']  = $page;
	return self::import_post('page_'.$page,&$postarr);
  }

  /*  imports $data into a wordpress attachment according to $mapping
  */
  private static function import_attachment_type($mapping,$data){
	$prim_key = $data[$mapping['primary_key']];
	$attribute_mapping = $mapping['attributes'];

	$page = self::create_page( $mapping['page'] ); 
	
	if ( is_wp_error($page) )
		return $page;

	foreach( $data as $key => $value ){
		switch($attribute_mapping[$key]){
			case 'title':
			case 'url':
			case 'file':
				$attachment[$attribute_mapping[$key]] = $value;
				break;
		}
	}

	return self::import_attachment_for_post($prim_key,$attachment,$page);
  }

  /*  creates a wordpress post together with attachments
      for all files contained in a public directory
  */
  private static function import_gallery_type($mapping,$data){
	$prim_key = $data[$mapping['primary_key']];
	$attribute_mapping = $mapping['attributes'];

	// create top level category
	$cat = self::create_category(array(
			'cat_name' => $mapping['category'],
			'category_nicename' => $mapping['category']));

	if ( is_wp_error($cat) )
		return $cat;

	// init some post properties
	$postarr = array(
		'post_title' => '',
		'post_content' => '',
		'post_excerpt' => '');
	$featured_image = null;

	foreach( $data as $key => $value ){
		if ( !isset($attribute_mapping[$key]) ) continue;
		switch($attribute_mapping[$key]){
			case 'description':
				$postarr['post_content'] = $value;
				break;
			case 'name':
				$postarr['post_title'] = $value;
				break;
			case 'gallery_folder':
				$gallery_folder = $value;
				break;
			case 'featured_image':
				$featured_image = $value;
				break;
		}
	}

	if (!is_dir($gallery_folder))
		return new WP_Error('no_directory', __("The given gallery folder is not a directory:").$gallery_folder);
	if (!($dh = opendir($gallery_folder)))
		return new WP_Error('open_error', __("Could not open the given gallery folder:").$gallery_folder);

	$ID = self::import_post($prim_key,&$postarr,$cat);
	if ( is_wp_error($ID) ) return $ID;

	// set gallery format
	set_post_format($ID,'gallery');

	$uploads = wp_upload_dir();
	if (substr($gallery_folder,-1) != '/' ) $gallery_folder .= '/';

	$g_ret = $ID; 
	while (($file = readdir($dh)) !== false) {
		if ( filetype($gallery_folder . $file) != 'file' )
			continue;

		// check if file is an image
		$wp_filetype = wp_check_filetype($gallery_folder . $file, null );
		if ( strpos($wp_filetype['type'],'image') === false )
			continue;

		if ( $featured_image == null )
			$featured_image = $file;

		$data['title'] = $file;

		$attachment = self::get_post($prim_key.$file);
		if ( $attachment == null ){
			// attachment is new
			$data['url'] = $uploads['path'] . '/' . wp_unique_filename($uploads['path'],$file);
			// create a symlink in the upload folder
			if ( !symlink( $gallery_folder . $file, $data['url'] ) ){
				$g_ret = new WP_Error('symlink_error', __('Could not create symlink for image:').$file);
				error_log($g_ret->get_error_message());
				continue;
			}
			$attach_id = self::import_attachment_for_post($prim_key.$file,$data,$ID,false);
			if ( is_wp_error($attach_id) ) {
				$g_ret = $attach_id;
				error_log('Importing image failed:'.$attach_id->get_error_message());
				continue;
			}
		}else{
			// attachment already exists
			$attach_id = $attachment->ID;
			// mark the attachment as imported
			unset(self::$global_imported_posts[$attach_id]);
		}

		if ( $file == $featured_image )
			update_post_meta( $ID, '_thumbnail_id', $attach_id );
	}
	closedir($dh);

	return $g_ret;
  }

  /*  imports $data into a wordpress link according to $mapping
  */
  private static function import_link_type($mapping,$data){
	$attribute_mapping = $mapping['attributes'];
	foreach( $data as $key => $value ){
		switch($attribute_mapping[$key]){
			case 'link_name':
			case 'link_url':
			case 'link_description':
				$link[$attribute_mapping[$key]] = $value;
				break;
			case 'category':
				$categories[] = $value;
				break;
		}
	}
	$favicon = self::get_favicon_url($link['link_url']);
	if ( $favicon != null )
		$link['link_image'] = $favicon;

	if ( $categories == null ){
		if ( isset($mapping['default_category']) )
			$categories[] = $mapping['default_category'];
	}

	foreach( $categories as $cat_name ){
		if (! is_array($cat_name) ) $cat_name = array($cat_name);
		foreach( $cat_name as $cat ){
			$cat_id = self::create_link_category($cat);
			if ( is_wp_error($cat_id) )
				error_log('Could not create link category:'.$cat.':'.$cat_id->get_error_message());
			else
				$link['link_category'][] = $cat_id;
		}
	}
	return self::import_link($link);
  }

  /*
	Check for wordpress type of data and call the right import function 
	returns WP_Error on error or boolean true on success
  */
  private static function import_data($data){

	global $ojs_mapping;
	if ( !isset($data['type']) )
		return new WP_Error('no_type', __("No ojs type set, cannot continue"));

	$mapping = $ojs_mapping[$data['type']];
	if ( $mapping != null && !is_array($mapping) ) // copy mapping
		$mapping = $ojs_mapping[$mapping];

	if ( $mapping == null )
		return new WP_Error('no_mapping', __("No mapping defined for:").$data['type']);

	$importer = array( 'post' => 'import_post_type',
			   'attachment' => 'import_attachment_type',
			   'link' => 'import_link_type',
			   'gallery' => 'import_gallery_type');

	if ( !isset( $importer[$mapping['type']]) )
		return new WP_Error('undefined_type',__('ojsimport: Undefined wordpress import type:').$mapping['type']);

	return self::$importer[$mapping['type']]($mapping,$data);
  }

  /* load ec3 plugin if it exists
  */
  private static function load_ec3(){
	// check if ec3 plugin is activated
	$plugins = get_option('active_plugins');
	$ec3plugin = 'eventcalendar3.php';
	foreach( $plugins as $plugin ){
		if ( strpos($plugin,$ec3plugin) === false ) continue;
		$admin_php = str_replace($ec3plugin,'admin.php',$plugin);
		require_once(ABSPATH . "wp-content" . '/plugins/'.$admin_php);
		break;
	}
  }

  /* delete all posts in $posts and leftover empty subcategories */
  private static function delete_posts($posts){
	global $ojs_mapping;

	self::load_ec3();
	foreach($posts as $post){
		self::delete_post_dates($post->ID);
		wp_delete_post($post->ID,true);
	}
	return self::delete_empty_subcategories($ojs_mapping);
  }

  /* public function
     Deletes all imported data ( posts, attachments, links, categories )
  */
  public static function delete_all_imported(){
	$links = self::get_ojsimport_links();
	self::delete_links($links);

	$posts = self::get_ojsimport_posts();
	self::delete_posts($posts);
  }

  /* public function
     Imports data from all defined data sources
  */
  public static function import_all() {
	global $wp_rewrite;
	global $ojs_mapping;
	self::$start_time = time();
	self::$fetch_time = 0;
	self::$posttime = time();

	self::$global_imported_links = self::get_ojsimport_links();
	self::$global_imported_posts = self::get_ojsimport_posts();

	$sources = array();
    	$import_tests = (boolean)get_option('ojsimport_import_tests');
	if ( $import_tests ){
		require_once(dirname(__FILE__) . '/ojsimport-test.php');
		$sources = ojsimport_test::get_sources();
	}
	
	$sources = array_merge( $sources, self::get_data_sources());

	// login to data source server if auth details are given
	$login_url = get_option('ojsimport_login_url');
	$username = get_option('ojsimport_username');
	$password = get_option('ojsimport_password');
	if ( $username != "" ){
		if ( $login_url == "" ) $login_url = null;
		$ret = ojsaccess::login($login_url,$username,$password);
		if ( $ret === false )
			return new WP_Error('curl_not_installed',
				__("You have to install php5_curl to use authentication!"));
	}

	self::load_ec3();
	$g_ret = true;
	foreach( $sources as $key => $source ){
		if ( is_wp_error($source) ){
			$g_ret = $source;
			continue;
		}
		$items = call_user_func($source,$key);
		if ( is_wp_error($items) ){
			if ( is_array($source) ) 
				// source is a class function
				$source = $source[0].'::'.$source[1];
			$source .= '('.$key.')';
			error_log("ojsimport: could not import from:".$source);
			error_log($items->get_error_message());
			$g_ret = $items;
			continue;
		}
		foreach( $items as $item ){
			if ( is_wp_error($item) ){
				error_log($item->get_error_message());
				$g_ret = $item;
				continue;
			}
			$ret = self::import_data($item);
			if ( is_wp_error($ret) ){
				error_log($ret->get_error_message());
				$g_ret = $ret;
			}
		}
	}
	// XXX: this is needed due to a bug in wordpress category cache
	wp_cache_flush();
	delete_option('category_children');
	// XXX: needed to make permalinks work (not documented)
	$wp_rewrite->flush_rules();

	// delete leftover posts that are not available in the sources anymore 
	// only if import was successful
	$ret = true;
	if ( is_wp_error($g_ret) )
		error_log('Import was not successful. Not deleting leftover posts!');
	else{
		if (!empty(self::$global_imported_posts))
			$ret = self::delete_posts(self::$global_imported_posts);
		if ( is_wp_error($ret) ) $g_ret = $ret;
		if (!empty(self::$global_imported_links))
			$ret = self::delete_links(self::$global_imported_links);
	}

	if ( is_wp_error($ret) ) $g_ret = $ret;
	if ( !is_wp_error($g_ret) ){
		$g_ret  = 'The import took '.(time() - self::$start_time).' seconds.'."\n";
		$g_ret .= 'Fetching data took '.self::$fetch_time.' seconds.';
	}
	return $g_ret;
  }

  /*  return the id of a link category, create it if it does not exist
  */
  private static function create_link_category($cat_name){
	if ( !($cat = term_exists( $cat_name, 'link_category' )) ) {
		$cat = wp_insert_term( $cat_name, 'link_category' );
	}

	if ( is_wp_error($cat) )
		return $cat;
	else
		return $cat['term_id'];
  }

  /*  deletes imported links given in $links
  */
  private static function delete_links($links) {
	$saved_links = self::get_ojsimport_links();
	foreach($links as $url => $id){
		wp_delete_link($id);
		unset($saved_links[$url]);
	}
	update_option('_ojsimportlinks',$saved_links);
  }

  /* 
	updates the imported links option
  */
  private static function save_link_id($id,$url){
	$val = self::get_ojsimport_links();
	if ( !is_array($val) )
		$val = array($val);
	$val[$url] = $id;
	update_option('_ojsimportlinks',$val);
	unset(self::$global_imported_links[$url]);
  }

  /*
	gets the id of the imported link according to the url
	if it exists
  */
  private static function get_link_id($url){
	$val = self::get_ojsimport_links();
	if ( isset($val[$url]) ) return $val[$url];
	return -1;
  }

  /* gets the array of all imported links */
  private static function get_ojsimport_links(){
	return get_option('_ojsimportlinks',array());
  }

  /*  imports $link
      $link must be an array expected by wp_insert_link
  */
  private static function import_link($link) {
	$ID = self::get_link_id($link['link_url']);
	if ( $ID != -1 ) $link['link_id'] = $ID;

	$ID = wp_insert_link($link,true);

	if ( is_wp_error($ID) )
		return $ID;
	self::save_link_id($ID,$link['link_url']);
	return $ID;
  }

  /*  returns an array of all imported posts + attachments
  *   the array keys are the post IDs 
  */
  private static function get_ojsimport_posts(){
	$args = array(
		'meta_query' => array(
			array('key' => '_post_type',
			      'value' => 'ojsimport'
			)
		),
		'numberposts' => -1,
		'post_type'  => 'any',
		'post_status' => 'any'
	);
	$posts = get_posts($args);
	foreach ( $posts as $post )
		$ret[$post->ID] = $post;
	return $ret;
  }

  /*  return a post with the specified $prim_key inside $category_id
      $category_id can be null
  */
  private static function get_post($prim_key, $category_id = null ){
	$args = array(
		'category' => $category_id,
		'post_type' => 'any',
		'post_status' => 'any',
		'numberposts' => 1,
		'meta_key' => '_prim_key',
		'meta_value' => $prim_key
	);
	$posts = get_posts($args);
	return $posts[0];
  }

  /*  import a post
      $postarr must be an array expected by wp_insert_post
  */
  private static function import_post($prim_key,&$postarr, $category_id = null ) {
	if ( $category_id != null )
		$postarr['post_category'] = array( $category_id );
	$postarr['post_status'] = 'publish';
	$post = self::get_post($prim_key,$category_id);
	if ( $post != null ){
		$ID = $post->ID;
		$postarr['ID'] = $ID;
		$post = get_post($ID,'ARRAY_A');
		if ( $category_id != null )
			$post['post_category'] = $postarr['post_category'];
		$diff = array_diff_assoc($postarr,$post);
		// the post did not change, so just return the ID
		if ( empty($diff) ){
			// mark the post as imported
			unset(self::$global_imported_posts[$ID]);
			return $ID;
		}
	}
	// make sure each post has a different publish date
	// otherwise the 'next_post', 'previous_post' queries get confused
	self::$posttime -= 1;
	if ( !isset($postarr['post_date']) )
		$postarr['post_date'] = date("Y-m-d H:i:s",self::$posttime);

	$ID = wp_insert_post($postarr,true);
	if ( is_wp_error($ID) ) return $ID;
	// mark the post as imported
	unset(self::$global_imported_posts[$ID]);
	add_post_meta($ID,"_prim_key",$prim_key,true);
	add_post_meta($ID,"_post_type",'ojsimport',true);
	return $ID;
  }

  /*  deletes all dates attached to $post_id
  */
  private static function delete_post_dates($post_id){
	// this requires the ec3 plugin
	if ( !class_exists(ec3_admin) ) return;
	$sched_entry = array(
		'action' => 'delete',
		'start'  => 'dummy',
		'end'  => 'dummy'
	);

	$ec3_admin=new ec3_Admin();
	$schedule = $ec3_admin->get_schedule($post_id);
	foreach( $schedule as $entry )
		$sched_entries[$entry->sched_id] = $sched_entry;
	if ( !empty($sched_entries) )
		$ec3_admin->ec3_save_schedule($post_id,$sched_entries);
  }

  /*  creates or updates a date for $post_id
  */
  private static function import_ec3_post_dates($post_id,$action,$start,$end){
	if ( $start == null )
		$start = date("Y-m-d H:i");
	if ( $end == null )
		$end = $start;
	$sched_entry = array(
		'action' => $action,
		'start'  => $start,
		'end'  => $end,
		'allday' => 0
	);

	$ec3_admin=new ec3_Admin();
	if ( $action == 'update' ){
		$schedule = $ec3_admin->get_schedule($post_id);
		if ( empty($schedule) ) { 
			// no previous schedule there
			$sched_entry['action'] = 'create';
			$sched_entries = array( $sched_entry );
		}else
			$sched_entries = array( $schedule[0]->sched_id => $sched_entry );
	}else{
		$sched_entries = array( $sched_entry );
	}
	$ec3_admin->ec3_save_schedule($post_id,$sched_entries);
  }

  /*  creates or updates a date for $post_id
  */
  private static function import_post_dates($post_id,$action,$start,$end){
	$ret = true;
	// this requires the ec3 plugin
	if ( class_exists('ec3_admin') ) 
		$ret = self::import_ec3_post_dates($post_id,$action,$start,$end);

	// set meta data for the-events-calender
	update_post_meta($post_id,"_isEvent","yes");
	update_post_meta($post_id,"_EventStartDate",$start);
	$end = ( $end == null?$start:$end );
	update_post_meta($post_id,"_EventEndDate",$end);
	return $ret;
  }

  /*  Attaches a post to categories. The categories are created if they do not
      exist.
      $post_id: id of the post
      $data: array with elements of the form:
	<parent_slug> => <category slug>
      $top_cat: id of the top category under which all categories will be created
  */
  private static function import_post_categories($post_ID,$data,$top_cat){
        $ret = 0;
	$categories[] = $top_cat;
	foreach( $data as $parent_slug => $cat_slug ){
		// create parent category
		$parent_id = self::create_category(array(
			'cat_name' => $parent_slug,
			'category_nicename' => $parent_slug,
			'category_parent' => $top_cat));
		if ( is_wp_error($parent_id) ){
			error_log('ojsimport: could not create parent category:'.$parent_slug);
			error_log($parent_id->get_error_message());
			continue;
		}

		if ( is_array($cat_slug) )
			$subcats = $cat_slug;
		else
			$subcats = array( $cat_slug );
		foreach($subcats as $subcat){ 
			// XXX: make category names case-insensitive
			$category['cat_name'] = strtolower($subcat);
			$category['category_nicename'] = sanitize_title($subcat);
			$category['category_parent'] = $parent_id;
			$category['category_description'] = $subcat;
			$cat_id = self::create_category($category);
			if ( is_wp_error($cat_id) ){
				error_log('ojsimport: could not create sub category:'.$subcat);
				error_log($cat_id->get_error_message());
				continue;
			}
			$categories[] = $cat_id;
		}
	}
	return wp_set_post_terms($post_ID,$categories,'category');
  }

  /*  import an attachment for $post_id
      The attachment is downloaded if $download is true and if it does not exist
  */
  private static function import_attachment_for_post($prim_key,$data,$post_id,$download = true) {
	$remotefile = $data['url'];
	$title = $data['title'];
	$localfile = (isset($data['file'])?$data['file']:basename($remotefile));

	$post = self::get_post($prim_key);
	if ( $post == null ){
		if ( $download ){
			$contents = ojsaccess::get_content($remotefile);
			if ( $contents == FALSE )
				return new WP_Error('download_failed', __("Could not get file:").$remotefile.' for post:'.$prim_key);
			$upload = wp_upload_bits($localfile,null,$contents);
			if ( $upload['error'] != false )
				return new WP_Error('upload_failed',
					__("Could not upload file:").$remotefile.' for post:'.$prim_key.':'. $upload['error']);
			$filename = $upload['file'];
		}else $filename = $remotefile;

		$wp_filetype = wp_check_filetype(basename($filename), null );
		$attachment = array(
			'post_mime_type' => $wp_filetype['type'],
			'post_title' => $title,
			'post_excerpt' => $title,
			'guid'	=> $remotefile,
			'post_content' => '',
			'post_status' => 'publish'
		);
		$attach_id = wp_insert_attachment( $attachment, $filename, $post_id );
		$attach_data = wp_generate_attachment_metadata( $attach_id, $filename );
		wp_update_attachment_metadata( $attach_id,  $attach_data );
		add_post_meta($attach_id,"_prim_key",$prim_key,true);
		add_post_meta($attach_id,"_post_type",'ojsimport',true);
	}else{
		if ( $post->guid != $remotefile ){
			// filename changed, delete this attachment and create a new one
			wp_delete_post($post->ID,true);
			$attach_id = self::import_attachment_for_post($prim_key,$data,$post_id,$download);
		}else{
			//XXX: update the attachment? then we need a hash or something
			// only update title
			$post->post_title = $title;
			$post->post_excerpt = $title;
			$attach_id = wp_update_post($post);
		}
	}
	if ( !is_wp_error($attach_id) ){
		// mark the attachment as imported
		unset(self::$global_imported_posts[$attach_id]);
	}
	return $attach_id;
  }

}


