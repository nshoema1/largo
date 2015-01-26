<?php
/**
 * Contains functions and tools for transitioning between Largo 0.3 and Largo 0.4
 */

/**
 * Returns current version of largo
 */
function largo_version() {
	$theme = wp_get_theme();
	$parent = $theme->parent();
	if (!empty($parent))
		return $parent->get('Version');
	return $theme->get('Version');
}

/**
 * Checks if widgets need to be placed by checking old theme settings
 */
function largo_need_updates() {
	// try to figure out which versions of the options are stored. Implemented in 0.3
	if (of_get_option('largo_version')) {
		$compare = version_compare(largo_version(), of_get_option('largo_version'));
		if ($compare == 1)
			return true;
		else
			return false;
	}

	// if 'largo_version' isn't present, the settings are old!
	return true;
}

/**
 * Performs various database updates upon Largo version change. Fairly primitive as of 0.3
 *
 * @since 0.3
 */
function largo_perform_update() {
	if (largo_need_updates()) {
		largo_update_widgets();
		largo_home_transition();
		largo_transition_nav_menus();
		largo_update_custom_less_variables();
		largo_update_prominence_term_descriptions();
		of_set_option('single_template', 'classic');
		of_set_option('largo_version', largo_version());
	}

	if ( is_admin() ) {
		largo_check_deprecated_widgets();
	}

	return true;
}

/**
 * Upgrades for moving from 0.3 to 0.4
 * In which many theme options became widgets
 * And homepage templates are implemented
 */

/**
 * Convert old theme option of 'homepage_top' to new option of 'home_template'
 */
function largo_home_transition() {
	$old_regime = of_get_option('homepage_top', 0);
	$new_regime = of_get_option('home_template', 0);

	// we're using the old system and the new one isn't in place, act accordingly
	// this should ALWAYS happen when this function is called, as there's a separate version check before this is invoked
	// however, it will not run if the new system has already been set up, so largo-dev to 0.4 will not overwrite details.
	// the home template sidebars have same names as old regime so that *shouldn't* be an issue
	if (of_get_option('homepage_layout') == '3col') {
		of_set_option('home_template', 'LegacyThreeColumn');
	} else if ($old_regime && !$new_regime) {
		if ($old_regime == 'topstories')
			$home_template = 'TopStories';
		if ($old_regime == 'slider')
			$home_template = 'HomepageBlog';
		if ($old_regime == 'blog')
			$home_template = 'HomepageBlog';
		of_set_option('home_template', $home_template);
	} else if (!$new_regime) {
		of_set_option('home_template', 'HomepageBlog');
	}
}

/**
 * Puts new widgets into sidebars as appropriate based on old theme options
 */
function largo_update_widgets() {

	/* checks and adds if necessary:
		social_icons_display ('btm' or 'both')
		add series widget
		show_tags
		show_author_box
		show_related_content
		show_next_prev_nav_single
	*/
	$checks = array();

	$checks['social_icons_display'] = array(
		'values' => array('btm', 'both'),
		'widget' => 'largo-follow',
		'settings' => array( 'title' => '' ),
	);

	//this is a dummy check
	$checks['in_series'] = array(
		'values' => NULL,
		'widget' => 'largo-post-series-links',
		'settings' => array( 'title' => __( 'Related Series', 'largo' ) ),
	);

	$checks['show_tags'] = array(
		'values' => array(1),
		'widget' => 'largo-tag-list',
		'settings' => array('title' => __( 'Filed Under:', 'largo' ), 'tag_limit' => 20),
	);

	$checks['show_author_box'] = array(
		'values' => array('1'),
		'widget' => 'largo-author-bio',
		'settings' => array('title' => __( 'Author', 'largo' ) ),
	);

	$checks['show_related_content'] = array(
		'values' => array('1'),
		'widget' => 'largo-explore-related',
		'settings' => array('title' => __( 'More About', 'largo' ), 'topics' => 6, 'posts' => 3),
	);

	$checks['show_next_prev_nav_single'] = array(
		'values' => array('1'),
		'widget' => 'largo-prev-next-post-links',
		'settings' => array(),
	);

	//loop thru, see if value is present, then see if widget exists, if not, create one
	foreach( $checks as $option => $i ) {
		$opt = of_get_option( $option );
		if ( $i['values'] === NULL || in_array($opt, $i['values']) ) {
			//we found an option that suggests we need to add a widget.
			//if there's not aleady one present, add it
			if ( !largo_widget_in_region( $i['widget'] ) ) {
				largo_instantiate_widget( $i['widget'], $i['settings'], 'article-bottom');
			}
		}
	}
}

/**
 * Checks to see if a given widget is in a given region already
 */
function largo_widget_in_region( $widget_name, $region = 'article-bottom' ) {

	$widgets = get_option( 'sidebars_widgets ');

	if ( !isset( $widgets[$region] ) ) {
		return new WP_Error( 'region-missing', __('Invalid region specified.', 'largo' ) );
	}

	foreach( $widgets[$region] as $key => $widget ) {
		if ( stripos( $widget, $widget_name ) === 0 ) return true;	//we found a copy of this widget! Note this may return a false positive if the widget we're checking is the same name (but shorter) as another kind of widget
	}
	return false;	// the widget wasn't there

}

/**
 * Inserts a widget programmatically.
 * This is slightly dangerous as it makes some assumptions about existing plugins
 * if $instance_settings are wrong, bad things might happen
 */
function largo_instantiate_widget( $kind, $instance_settings, $region ) {

	$defaults = array(
		'widget_class' => 'default',
		'hidden_desktop' => 0,
		'hidden_tablet' => 0,
		'hidden_phone' => 0,
		'title_link' => ''
	);

	$instance_id = 2; 	// default, not sure why it always seems to start at 2
	$full_kind = 'widget_' . str_replace("_", "-", $kind) . '-widget';

	// step 1: add the widget instance to the database and get the ID
	$widget_instances = get_option( $kind );

	// no instances of this exist, yay
	if ( !$widget_instances ) {
		update_option( $full_kind,
			array(
				2 => wp_parse_args( $instance_settings, $defaults ),
				'_multiwidget' => 1,
			)
		);

	} else {
		//figure out what ID we're creating. Don't just use count() as things might get deleted or something...
		//there's probably a smarter way to do this...
		while ( array_key_exists( $instance_id, $widget_instances) ) {
			$instance_id++;
		}

		//pop off _multiwidget, add our element to the end, then add _multiwidget back
		$new_instances = array_pop( $widget_instances );
		$new_instances[ $instance_id ] = wp_parse_args( $instance_settings, $defaults );
		$new_instances[ '_multiwidget' ] = 1;
		update_option( $full_kind, $new_instances );
	}

	// step 2: add the widget instance we just created to the region; this isn't so bad
	$region_widgets = get_option( 'sidebars_widgets' );
	$region_widgets[ $region ][] = $kind . '-widget-' . $instance_id;
	update_option( 'sidebars_widgets', $region_widgets );

}

/**
 * Checks for use of deprecated widgets and posts an alert
 */
function largo_check_deprecated_widgets() {

	$deprecated = array(
		'largo-footer-featured' => 'largo_deprecated_footer_widget',
		'largo-sidebar-featured' => 'largo_deprecated_sidebar_widget'
	);

	$widgets = get_option( 'sidebars_widgets ');
	foreach ( $widgets as $region => $widgets ) {
		if ( $region != 'wp_inactive_widgets' && $region != 'array_version' && is_array($widgets) ) {
			foreach ( $widgets as $widget_instance ) {
				foreach ( $deprecated as $widget_name => $callback ) {
					if (strpos($widget_instance, $widget_name) === 0) {
						add_action( 'admin_notices', $callback );
						unset( $deprecated[$widget_name] ); //no need to flag the same widget multiple times
					}
				}
			}
		}
	}
}

/**
 * Admin notices of older widgets
 */
function largo_deprecated_footer_widget() { ?>
	<div class="update-nag"><p>
	<?php printf( __('You are using the <strong>Largo Footer Featured Posts</strong> widget, which is deprecated and will be removed from future versions of Largo. Please <a href="%s">change your widget settings</a> to use its replacement, <strong>Largo Featured Posts</strong>.', 'largo' ), admin_url( 'widgets.php' ) ); ?>
	</p></div>
	<?php
}

function largo_deprecated_sidebar_widget() { ?>
	<div class="update-nag"><p>
	<?php printf( __( 'You are using the <strong>Largo Sidebar Featured Posts</strong> widget, which is deprecated and will be removed from future versions of Largo. Please <a href="%s">change your widget settings</a> to use its replacement, <strong>Largo Featured Posts</strong>.', 'largo' ), admin_url( 'widgets.php' ) ); ?>
	</p></div>
	<?php
}

function largo_transition_nav_menus() {
	$locations = get_nav_menu_locations();
	$main_nav = wp_get_nav_menu_object('Main Navigation');
	if (!$main_nav) {
		$main_nav_id = wp_create_nav_menu('Main Navigation');
		$locations['main-nav'] = $main_nav_id;
	} else {
		$locations['main-nav'] = $main_nav->term_id;
	}

	// Get the menu items for each menu
	$existing_items = array();
	foreach ($locations as $location => $id)
		$existing_items[$location] = wp_get_nav_menu_items($id);

	// These nav menu locations/menus get folded into main-nav menu
	$transition = array('navbar-categories', 'navbar-supplemental', 'sticky-nav');

	// Move all the category, supplemental items to main-nav.
	// Remove category and supplemental navs.
	foreach ($transition as $location_slug) {
		if (isset($existing_items[$location_slug])) {
			$items = $existing_items[$location_slug];
			if ($items) {
				foreach ($items as $idx => $item) {
					$meta = get_metadata('post', $item->ID);
					$attrs = array(
						'menu-item-type' => $meta['_menu_item_type'][0],
						'menu-item-menu-item-parent' => $meta['_menu_item_menu_item_parent'][0],
						'menu-item-parent-id' => $meta['_menu_item_menu_item_parent'][0],
						'menu-item-object-id' => $meta['_menu_item_object_id'][0],
						'menu-item-object' => $meta['_menu_item_object'][0],
						'menu-item-target' => $meta['_menu_item_target'][0],
						'menu-item-classes' => $meta['_menu_item_classes'][0],
						'menu-item-xfn' => $meta['_menu_item_xfn'][0],
						'menu-item-url' => $meta['_menu_item_url'][0],
						'menu-item-title' => $item->post_title,
						'menu-item-attr-title' => $item->post_excerpt
					);
					wp_update_nav_menu_item($locations['main-nav'], $item->ID, $attrs);
				}
			}
			// Get rid of the menu
			wp_delete_nav_menu($locations[$location_slug]);
			unset($locations[$location_slug]);
		}
	}

	set_theme_mod('nav_menu_locations', $locations);
}

/**
 * Compares an array containing an old and new prominence term description and the appropriate slug and name to an array of current term descriptions. For each term whose current description matches the old description, the function updates the current description to the new description.
 *
 * This function contains commented-out logic that will allow you to from description to olddescription
 *
 * @param array $update The new details for the prominence tax term to be updated
 * @param array $term_descriptions Array of prominence terms, each prominence term as an associative array with keys: name, description, olddescription, slug
 *
 */
function largo_update_prominence_term_description_single($update, $term_descriptions) {	
	$logarray = array();

	// Toggle comment on these two lines to revert to old descriptions.
	if (in_array($update['olddescription'], $term_descriptions)) {
#		if (in_array($update['description'], $term_descriptions)) {
	    $id = get_term_by('slug', $update['slug'], 'prominence', 'ARRAY_A' );
	    // Comment out this function to avoid all prominence term updates.
#		    /*
	    wp_update_term(
	        $id['term_id'], 'prominence',
	        array(
	            'name' => $update['name'],
	            // Toggle comment on these two lines to revert to old descriptions.
	            'description' => $update['description'],
#		            'description' => $update['olddescription'],
	            'slug' => $update['slug']
	        )
	    );
#		    */
	    $logarray[] = 'Updated description of "' . $update['name'] . '" from "'. $update['olddescription'] . '" to "' . $update['description'] . '"';
	    // Clean the entire prominence term cache
	    clean_term_cache( $id['term_id'], 'prominence', true );
	}
	
	// These are here so you can grep your server logs to see if the terms were updated.
#	var_log($logarray);
#	var_log("Done updating prominence terms");

	return $update;
}

/**
 * Updates post prominence term descriptions iff they use the old language
 *
 * This function can be added to the `init` action to force an update of prominence term descriptions:
 *    add_action('init', 'largo_update_prominence_term_descriptions');
 *
 * This function does not touch custom prominence term descriptions, except those that are identical to the descriptions of current or 0.3 prominence term descriptions.
 *
 * @since 0.4
 * @uses largo_update_prominence_term_description_single
 * @uses wp_update_term
 * @uses clean_term_cache
 * @uses var_log
 
 */
function largo_update_prominence_term_descriptions() {
	// see https://github.com/INN/Largo/issues/210

	$terms = get_terms('prominence', array(
			'hide_empty' => false,
			'fields' => 'all'
		));

	// prevent PHP warnings in case no terms returned
	if ( gettype($terms) != "array" )
		return false;

	$term_descriptions = array_map(function($arg) { return $arg->description; }, $terms);
	
	$largoOldProminenceTerms = array(
		array(
			'name' => __('Sidebar Featured Widget', 'largo'),
			'description' => __('If you are using the Featured Posts widget in a sidebar, add this label to posts to determine which to display in the widget.', 'largo'),
			'olddescription' 	=> __('If you are using the Sidebar Featured Posts widget, add this label to posts to determine which to display in the widget.', 'largo'),
			'slug' => 'sidebar-featured'
		),
		array(
			'name' => __('Footer Featured Widget', 'largo'),
			'description' => __('If you are using the Featured Posts widget in the footer, add this label to posts to determine which to display in the widget.', 'largo'),
			'olddescription' => __('If you are using the Footer Featured Posts widget, add this label to posts to determine which to display in the widget.', 'largo'),
			'slug' => 'footer-featured'
		),
		array(
			'name' => __('Featured in Category', 'largo'),
			'description' => __('This will allow you to designate a story to appear more prominently on category archive pages.', 'largo'),
			'olddescription' 	=> __('Not yet implemented, in the future this will allow you to designate a story (or stories) to appear more prominently on category archive pages.', 'largo'),
			'slug' => 'category-featured'
		),
		array(
			'name' => __('Homepage Featured', 'largo'),
			'description' => __('Add this label to posts to display them in the featured area on the homepage.', 'largo'),
			'olddescription' => __('If you are using the Newspaper or Carousel optional homepage layout, add this label to posts to display them in the featured area on the homepage.', 'largo'),
			'slug' => 'homepage-featured'
		),
		array(
			'name' => __('Homepage Top Story', 'largo'),
			'description' => __('If you are using a "Big story" homepage layout, add this label to a post to make it the top story on the homepage', 'largo'),
			'olddescription' => __('If you are using the Newspaper or Carousel optional homepage layout, add this label to label to a post to make it the top story on the homepage.', 'largo'),
			'slug' => 'top-story'
		),
		array(
			'name' => __('Featured in Series', 'largo'),
			// 0.4 description did not change from 0.3
			'description' => __('Select this option to allow this post to float to the top of any/all series landing pages sorting by Featured first.', 'largo'),
			'olddescription' 	=> __('Select this option to allow this post to float to the top of any/all series landing pages sorting by Featured first.', 'largo'),
			'slug' => 'series-featured'
		)
	);
	
	foreach ($largoOldProminenceTerms as $update ) {
		largo_update_prominence_term_description_single($update, $term_descriptions);

	}
}
// Uncomment this line if you would like to force prominence terms to update.
# add_action('init', 'largo_update_prominence_term_descriptions');

// WP admin dashboard update workflow
function largo_update_admin_notice() {
	if (largo_need_updates() && !(isset($_GET['page']) && $_GET['page'] == 'update-largo')) {
?>
	<div class="update-nag" style="display: block;">
		<p>Largo has been updated! Please <a href="<? echo admin_url('index.php?page=update-largo'); ?>">visit the update page</a> to apply a required database update.</p>
	</div>
<?php
	}
}
add_action('admin_notices', 'largo_update_admin_notice');

function largo_register_update_page() {
	$parent_slug = null;
	$page_title = "Update Largo";
	$menu_title = "Update Largo";
	$capability = "edit_theme_options";
	$menu_slug = "update-largo";
	$function = "largo_update_page_view";

	if (largo_need_updates()) {
		add_submenu_page(
			$parent_slug, $page_title, $menu_title,
			$capability, $menu_slug, $function
		);
	}
}
add_action('admin_menu', 'largo_register_update_page');

function largo_update_page_view() { ?>
	<style type="text/css">
		.update-message {
			max-width: 700px;
		}
		.update-message,
		.update-message p {
			font-size: 16px;
		}
		.update-message ul li {
			list-style-type: disc;
			list-style-position: inside;
		}
		.update-message .submit-container {
			max-width: 178px;
		}
		.update-message .spinner {
			background: url(../wp-includes/images/spinner.gif) 0 0/20px 20px no-repeat;
			-webkit-background-size: 20px 20px;
			display: none;
			opacity: .7;
			filter: alpha(opacity=70);
			width: 20px;
			height: 20px;
			margin: 0;
			position: relative;
			top: 4px;
		}
		.update-message .updated,
		.update-message .error {
			padding-top: 16px;
			padding-bottom: 16px;
		}
	</style>
	<div class="wrap">
		<div id="icon-tools" class="icon32"></div>
		<h2>Largo Database Update</h2>
		<div class="update-message">
			<p><?php _e('This version of Largo includes a variety of updates, enhancements and changes.'); ?></p>
			<p><?php _e('These changes affect'); ?>:
				<ul>
					<li><?php _e('Theme options'); ?></li>
					<li><?php _e('Configured menus'); ?></li>
					<li><?php _e('Site navigation'); ?></li>
					<li><?php _e('Sidebars and widgets'); ?></li>
				</ul>
			<p><?php _e('The database update you are about to apply will take steps to migrate existing site settings.'); ?></p>
			<p><?php _e('In the event that a site setting can not be migrated, the update will do its best to preserve it instead.'); ?></p>
			<p><?php _e('For example, menus that existed in previous versions of Largo have been removed. If your site has been using one of these now-deprecated menus, the update process will merge it with the nearest related menu.'); ?></p>
			<p><?php _e('Please be sure to review your site settings after applying the update to ensure all is well.'); ?></p>

			<p class="submit-container">
				<input type="submit" class="button-primary" id="update" name="update" value="<?php _e('Update the database!'); ?>">
				<span class="spinner"></span>
			<p>
		</div>
	</div>
<?php
}

function largo_update_page_enqueue_js() {
	if (isset($_GET['page']) && $_GET['page'] == 'update-largo') {
		wp_enqueue_script(
			'largo_update_page', get_template_directory_uri() . '/js/update-page.js',
			array('jquery'), false, 1);
	}
}
add_action('admin_enqueue_scripts', 'largo_update_page_enqueue_js');

function largo_ajax_update_database() {
	if (!current_user_can('activate_plugins')) {
		print json_encode(array(
			"status" => __("An error occurred."),
			"success" => false
		));
		wp_die();
	}

	if (!largo_need_updates()) {
		print json_encode(array(
			"status" => __("Finished. No update was required."),
			"success" => false
		));
		wp_die();
	}

	$ret = largo_perform_update();
	if (!empty($ret)) {
		print json_encode(array(
			"status" => __("Thank you -- the update is complete. Don't forget to check your site settings!"),
			"success" => true
		));
		wp_die();
	} else {
		print json_encode(array(
			"status" => __("There was a problem applying the update. Please try again."),
			"success" => false
		));
		wp_die();
	}
}
add_action('wp_ajax_largo_ajax_update_database', 'largo_ajax_update_database');

/**
 * Make sure custom CSS is regenerated if we're using custom LESS variables
 */
function largo_update_custom_less_variables() {
	if (Largo::is_less_enabled()) {
		$variables = Largo_Custom_Less_Variables::get_custom_values();
		$escaped = array();

		foreach ($variables['variables'] as $key => $var)
			$escaped[$key] = addslashes($var);

		Largo_Custom_Less_Variables::update_custom_values($escaped);
	}
}
