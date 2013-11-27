<?php
/**
 * Functions to scour for homepage templates and build an array of them.
 * The array is then fed into options_framework settings for homepage layout
 * And that setting is fetched to display the proper homepage
 */

/**
 * Scans theme (and parent theme) for homepage templates.
 *
 * @todo Also enqueue CSS files and JS files specific to the template (like the screenshot, look for matching filename)
 *
 * @return array An array of templates, with friendly names as keys and arrays with 'path' and 'thumb' as values
 */
if( !function_exists( 'get_homepage_templates' ) ) {

	function largo_get_home_templates() {

		$theme = wp_get_theme();
		$php_files = $theme->get_files( 'php', 1, true );
		$home_templates = array();

		$base = array(trailingslashit(get_template_directory()), trailingslashit(get_stylesheet_directory()));

		foreach ( (array)$php_files as $template ) {
			$template = WP_CONTENT_DIR . str_replace(WP_CONTENT_DIR, '', $template);
			$basename = str_replace($base, '', $template);

			$template_data = implode('', file( $template ));

			$name = $desc = '';
			if ( basename($template) != basename(__FILE__) && preg_match( '|Home Template:(.*)$|mi', $template_data, $name) ) {
				$name = _cleanup_header_comment($name[1]);
				preg_match( '|Description:(.*)$|mi', $template_data, $desc);
				$home_templates[ trim($name) ] = array(
					'path' => $basename,	//eg 'homepages/my-homepage.php'
					'thumb' => largo_get_home_thumb( $theme, $basename ),
					'desc' => _cleanup_header_comment($desc[1])
				);
			}
		}

		return $home_templates;

	}
}

/**
 * @return string The public url of the image file to use for the given template's screenshot
 */
function largo_get_home_thumb( $theme, $file ) {
	$pngs = $theme->get_files( 'png', 1, true );
	$our_filename = basename( $file, '.php' ) . '.png';
	foreach ( (array)$pngs as $filename => $server_path ) {
		if ( basename($filename) == $our_filename ) {
			return str_replace( WP_CONTENT_DIR, content_url(), $server_path);
		}
	}

	//still here? Use a default
	return get_template_directory_uri() . '/homepages/no-thumb.png';
}

/**
 * Get the server path of the current homepage template
 */
function largo_home_template_path() {
	$tpl = of_get_option( 'home_template', 'homepages/blog.php' );
	if ( ! $tpl ) return false;
	if ( file_exists( get_stylesheet_directory() . "/$tpl") ) {
		return get_stylesheet_directory() . "/$tpl";
	} else if ( file_exists( get_template_directory() . "/$tpl") ) {
		return get_template_directory() . "/$tpl";
	}
	return false;
}

/**
 * Register the sidebars specified in the Home Template
 */
function largo_register_home_sidebars() {
	$path = largo_home_template_path();
	$sidebar_string = '';
	$template_data = implode('', file( $path ));
	preg_match( '|Sidebars:(.*)$|mi', $template_data, $sidebar_string );
	if ( count( $sidebar_string ) > 1 ) {
		$sidebars = explode("|", _cleanup_header_comment($sidebar_string[1]));
		foreach ( $sidebars as $sidebar ) {
			preg_match( '|^(.*?)(\((.*)\))?$|', trim( $sidebar ), $sb );
			register_sidebar( array(
				'name' => trim($sb[1]),
				'id' => largo_make_slug( trim($sb[1]) ),
				'description' => (isset( $sb[3] ) ) ? trim($sb[3]) : __('Auto-generated by current homepage template'),
				'before_widget' => '<aside id="%1$s" class="%2$s clearfix">',
				'after_widget' 	=> "</aside>",
				'before_title' 	=> '<h3 class="widgettitle">',
				'after_title' 	=> '</h3>',
			) );
		}
	}

	// while we're at it, set the $largo['hide_home_rail'] value
	global $largo;
	$rail = $largo['home_rail'] = TRUE;
	preg_match( '|Right Rail:(.*)$|mi', $template_data, $rail );
	if ( count($rail) > 1 ) {
		$rail_val = _cleanup_header_comment($rail[1]);
		if ( stripos($rail_val, 'hidden') !== FALSE || stripos($rail_val, 'none') !== FALSE ) {
			$largo['home_rail'] = FALSE;
		}
	}
}
add_action( 'widgets_init', 'largo_register_home_sidebars' );


/**
 * Enqueue scripts/styles
 */
function largo_enqueue_home_assets() {

	if ( is_admin() ) return;

	$path = largo_home_template_path();
	$pathinfo = pathinfo( $path );
	//determine if child or parent
	if ( strpos( $pathinfo['dirname'] , get_stylesheet_directory() ) === FALSE ) {
		$uri = get_template_directory_uri() . "/";
	} else {
		$uri = get_stylesheet_directory_uri() . "/";
	}

	if ( is_file( str_replace('.php', '.js', $path )) ) {
		wp_enqueue_script( 'largo-home-tpl-js', $uri . str_replace('.php', '.js', of_get_option('home_template') ), array('jquery'), TRUE );	//in footer for now
	}
	if ( is_file( str_replace('.php', '.css', $path )) ) {
		wp_enqueue_style( 'largo-home-tpl-css', $uri. str_replace('.php', '.css', of_get_option('home_template') ) );
	}

}
add_action( 'wp_enqueue_scripts', 'largo_enqueue_home_assets' );

/**
 * Backwards-compatibility with older versions of Largo
 */
function largo_home_transition() {
	$old_regime = of_get_option('homepage_top', 0);
	$new_regime = of_get_option('home_template', 0);

	// we're using the old system and the new one isn't in place, act accordingly
	// the home template sidebars have same names as old regime so that *shouldn't* be an issue
	if ( $old_regime && ! $new_regime ) {
		//minor name change
		if ( $old_regime == 'topstories' ) $old_regime = 'top-stories';
		of_set_option( 'home_template', 'homepages/'.$old_regime.".php" );
	}
}
add_action('init', 'largo_home_transition');