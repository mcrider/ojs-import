<?php
/* 
Copyright (c) 2012, Matthew Crider

Code based on SMW Import plugin by Christoph Herbst (http://wordpress.org/extend/plugins/smw-import/).

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as 
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

require_once('ojsimport.php');
// Hook for adding admin menus
add_action('admin_menu', 'ojsimport_add_pages');
add_action('ojsimport_import_all_event', 'ojsimport_import_all' );
register_activation_hook( __FILE__, 'ojsimport_on_activation' );


function ojsimport_on_activation() {
	// check existing data sources
	$datasources = get_option('ojsimport_data_sources',array());
	if ( empty($datasources) ){
		// add default data source
		$datasources[] = dirname(__FILE__) . '/example_data.json';
		update_option( 'ojsimport_data_sources', $datasources );
	}
}

function ojsimport_import_all(){
	ojsimport::import_all();
}

// action function for above hook
function ojsimport_add_pages() {
    $title = 'ojs Import';
    $slug = 'ojsimport';
    // Add a new submenu under Tools:
    add_management_page( __($title,'menu-ojsimport'), __($title,'menu-ojsimport'), 'manage_options',$slug, 'ojsimport_tools_page');

  // Add a new submenu under Settings:
    add_options_page(__($title,'menu-ojsimport'), __($title,'menu-ojsimport'), 'manage_options', $slug, 'ojsimport_settings_page');

}


// mt_tools_page() displays the page content for the Test Tools submenu
function ojsimport_tools_page() {
    //must check that the user has the required capability 
    if (!current_user_can('manage_options'))
    {
      wp_die( __('You do not have sufficient permissions to access this page.') );
    }

    echo "<h2>" . __( 'ojs Import', 'menu-ojsimport' ) . "</h2>";
    $hidden_field_name = 'ojsimport_submit_hidden';

// See if the user has posted us some information
    // If they did, this hidden field will be set to 'Y'
    if( isset($_POST[ $hidden_field_name ]) && $_POST[ $hidden_field_name ] == 'Y' ) {
	$class = "updated";
	if ( $_POST['Import'] ){
		$ret = ojsimport::import_all();

		if ( is_wp_error($ret) ){
			$message = $ret->get_error_message();
			$class = "error";
		}else $message = 'Successfully imported.'."</br>".$ret;
	}else if ( $_POST['Delete'] ){
		$ret = ojsimport::delete_all_imported();
		if ( is_wp_error($ret) ){
			$message = $ret->get_error_message();
			$class = "error";
		}else $message = 'successfully deleted all imported posts.';
	}
        // Put the result  message on the screen
?>
<div id="message" class="<?php echo $class ?>"><p><strong><?php _e($message, 'menu-ojsimport' ); ?></strong></p></div>
<?php

    }


    // tools form
    
    ?>

<form name="form1" method="post" action="">
<input type="hidden" name="<?php echo $hidden_field_name; ?>" value="Y">

<p class="submit">
<input type="submit" name="Import" class="button-primary" value="<?php esc_attr_e('Import from ojs') ?>" />
<input type="submit" name="Delete" class="button-secondary" value="<?php esc_attr_e('Delete all imported') ?>" />
</p>

</form>
</div>

<?php

}


// ojs_import_page() displays the page content for the Test tools submenu
function ojsimport_settings_page() {
    global $events_option_name, $news_option_name, $press_option_name, $images_page_option_name;
    //must check that the user has the required capability 
    if (!current_user_can('manage_options'))
    {
      wp_die( __('You do not have sufficient permissions to access this page.') );
    }

    // variables for the field and option names 
    $hidden_field_name = 'ojsimport_submit_hidden';

    // Read in existing option value from database
    $datasources_opt = get_option('ojsimport_data_sources',array());

    // See if the user has posted us some information
    // If they did, this hidden field will be set to 'Y'
    if( isset($_POST[ $hidden_field_name ]) && $_POST[ $hidden_field_name ] == 'Y' ) {
        // Read their posted value

	if ( $_POST['Submit'] ){
		foreach( $datasources_opt as $key => $opt )
    		    $datasources_opt[$key] = $_POST[ 'ojsimport_data_source'.$key ];

		// Save the posted value in the database
		update_option( 'ojsimport_data_sources', $datasources_opt );

		$message = __('settings saved.', 'menu-ojsimport' );
	}else if ( $_POST['NewSource'] ){
		// add new data source
		$datasources_opt[] = '';
		update_option('ojsimport_data_sources',$datasources_opt);
		$message = __('New data source added.', 'menu-ojsimport' );
	}else if ( $_POST['RemoveSource'] ){
		// remove last data source
		unset($datasources_opt[count($datasources_opt)-1]);
		update_option('ojsimport_data_sources',$datasources_opt);
		$message = __('Data source removed.', 'menu-ojsimport' );
	}
        // Put an settings updated message on the screen

?>
<div class="updated"><p><strong><?php echo($message);  ?></strong></p></div>
<?php

    }

    // Now display the settings editing screen

    echo '<div class="wrap">';

    // header

    echo "<h2>" . __( 'ojs Import Settings', 'menu-ojsimport' ) . "</h2>";

    // settings form
    
    ?>

<form name="form1" method="post" action="">
<input type="hidden" name="<?php echo $hidden_field_name; ?>" value="Y">

<?php foreach ( $datasources_opt as $key => $opt ){ ?>
<p><?php echo(($key+1).'.'); _e("Data source:", 'menu-ojsimport' ); ?> 
<input type="text" name="ojsimport_data_source<?php echo $key; ?>" value="<?php echo $opt; ?>" size="80">
</p>
<?php } ?>
<hr />

<p class="submit">
<input type="submit" name="Submit" class="button-primary" value="<?php esc_attr_e('Save Changes') ?>" />
<input type="submit" name="NewSource" class="button-secondary" value="<?php esc_attr_e('Add new data source') ?>" />
<?php if ( count($datasources_opt) > 0 ){ ?>
<input type="submit" name="RemoveSource" class="button-secondary" value="<?php esc_attr_e('Remove last data source') ?>" />
<?php } ?>
</p>

</form>
</div>

<?php
 
}


?>
