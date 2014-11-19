<?php
/**
 * Plugin Name: Content Boost by FindTheBest
 * Description: The FindTheBest plugin allows you to embed widgets from FindTheBest into your WordPress site's blog posts.
 * Version: 2.2.1
 * Author: FindTheBest
 * Author URI: http://findthebest.com
 * License: GPLv2
 */

define( 'FTB_SETTINGS_PREFS', 'ftb_widget_designer_prefs' );
define( 'FTB_REMOTE_ROOT', '//www.findthebest.com' );

class FindTheBest_ContentBoost {

	/**
	 * Singleton
	 */
	static function init() {
		static $instance = false;

		if ( !$instance ) {
			load_plugin_textdomain( 'findthebest', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
			$instance = new FindTheBest_ContentBoost;
		}

		return $instance;
	}


	/**
	 * Constructor
	 */
	function __construct() {

		add_action( 'add_meta_boxes',         array( &$this, 'add_meta_box') );
		add_action( 'admin_enqueue_scripts',  array( &$this, 'admin_menu'  ) );
		add_action( 'wp_ajax_ftb_save_prefs', array( &$this, 'save_prefs'  ) );

		add_shortcode( 'findthebest', array(&$this, 'shortcode_handler') );
	}

	function FindTheBest_ContentBoost() {
		$this->__construct();
	}

	function add_meta_box() {
		// Add optional support for custom post types. Always add to post_type post.
		if( post_type_supports( get_post_type(), 'findthebest' ) || get_post_type() == 'post' ) {
			add_meta_box(
				'ftb',
				__( 'FindTheBest Suggestions' ),
				array( &$this, 'meta_box_shim' ),
				get_post_type(),
				'side',
				'high'
			);
		}
	}

	function admin_menu( $hook ) {
		if ( 'post.php' != $hook && 'post-new.php' != $hook ) {
			return;
		}

		wp_enqueue_script(
			'ftb_box',
			$this->file_path( '/js/box.js' ),
			array( 'jquery' )
		);

		wp_enqueue_script(
			'textarea_helper',
			$this->file_path( '/js/jquery.textarea-helper.js' ),
			array( 'jquery' )
		);

		wp_enqueue_script(
			'postmessage',
			$this->file_path( '/js/jquery.ba-postmessage.min.js' ),
			array( 'jquery' )
		);

		$dependencies = array(
			'jquery',
			'ftb_box',
			'textarea_helper',
			'postmessage'
		);

		wp_enqueue_script(
			'ftb_script',
			$this->file_path( '/js/ftb.js' ),
			$dependencies
		);

		$preferences = get_option( FTB_SETTINGS_PREFS, null );
		if (null === $preferences) {
			$preferences = '{}';
		} else {
			$preferences = json_encode( $preferences );
		}

		$no_content_search_message =  __(
			'Sorry, we did not find any content matching your article.',
			'findthebest'
		);

		$variables = array(
			'ajaxPath' => admin_url( 'admin-ajax.php' ),
			'editWidgetMessage' => __( 'Edit Widget: ', 'findthebest' ),
			'loadingImagePath' => plugins_url( 'images/', __FILE__ ) . 'load.gif',
			'loadingMessage' => __( 'Loading widget designer', 'findthebest' ),
			'noContentSearchMessage' => $no_content_search_message,
			'remoteRoot' => FTB_REMOTE_ROOT,
			'widgetDesignerPrefs' => $preferences
		);

		wp_localize_script( 'ftb_script', 'ftbData', $variables );

		wp_enqueue_style( 'box_style', $this->file_path( '/css/box.css' ) );
		wp_enqueue_style( 'ftb_style', $this->file_path( '/css/ftb.css' ) );

		wp_enqueue_script(
			'ftb_wordpress_analytics_js',
			FTB_REMOTE_ROOT . '/ajax_get_core_script?type=js&name=wordpress_analytics',
			array( 'ftb_script' )
		);

		add_filter( 'tiny_mce_before_init', array( &$this, 'tiny_mce_init' ) );
		add_filter( 'mce_external_plugins', array( &$this, 'tiny_mce_plugin' ) );
	}

	function file_path( $path, $relativity = 'remote' ) {
		switch ( $relativity ) {
		case 'remote':
			return plugins_url( $path, __FILE__ );

		case 'local':
			return untrailingslashit( dirname( __FILE__ ) ) . $path;
		}

		return '';
	}

	function meta_box_shim() {
		echo $this->render( 'meta-box' );
	}

	/**
	 * The HTML generated from rendering a plugin view with the specified arguments.
	 *
	 * @param string $view The PHP file name without the extension.
	 * @param array $arguments An associative array of variables made available.
	 * @return string The generated HTML.
	 */
	function render( $view, $arguments = array() ) {
		$path = $this->file_path( "/views/{$view}.php", 'local' );
		$arguments[ 'image_dir' ] = plugins_url( 'images/', __FILE__ );

		ob_start();
		require $path;

		return ob_get_clean();
	}

	/**
	 * Called from AJAX to update plugin preferences. These preferences are passed
	 * in as a JSON string.
	 */
	function save_prefs() {
		$preferences = sanitize_text_field( $_POST[ 'prefs' ] );
		if ( ! is_string( $preferences ) ) {
			die;
		}

		$preferences = strip_slashes( $preferences );

		// Disallow abnormally large JSON input.
		if ( strlen( $preferences ) >= 0x100000  ) {
			die;
		}

		$preferences = json_decode( $preferences );
		if ( null === $preferences ) {
			die;
		}

		update_option( FTB_SETTINGS_PREFS, $preferences );

		die;
	}

	/**
	 * Converts the FindTheBest shortcode into an HTML embed code.
	 *
	 * @param array $attributes An associative array of shortcode arguments.
	 * @return string The HTML embed code.
	 */
	function shortcode_handler( $attributes ) {
		if ( empty( $attributes ) ) {
			return null;
		}

		$defaults = array(
			'height' => '',
			'id' => '',
			'link' => '',
			'name' => '',
			'url' => '',
			'width' => ''
		);

		$arguments = wp_parse_args( $attributes, $defaults );

		if ( empty( $arguments['id'] ) || empty( $arguments['link'] ) ||
			empty( $arguments['name'] ) || empty( $arguments['url'] ) ) {
			return null;
		}

		$arguments['width'] = intval( $arguments['width'] );
		$arguments['height'] = intval( $arguments['height'] );

		if ( $arguments['width'] <= 0 || $arguments['height'] <= 0 ) {
			return null;
		}

		$arguments = array(
			'height' => $arguments['height'],
			'id' => $arguments['id'],
			'link' => $arguments['link'],
			'name' => $arguments['name'],
			'url' => $arguments['url'],
			'width' => $arguments['width'],
		);

		return $this->render( 'embed-code', $arguments );
	}

	function tiny_mce_init( $init_options ) {
		$css_path = plugins_url( 'css/tiny-mce-plugin.css', __FILE__ );
		$init_options[ 'content_css' ] .= ',' . $css_path;

		return $init_options;
	}

	function tiny_mce_plugin( $plugins ) {
		$plugins[ 'findthebest' ] = plugins_url( 'js/tiny-mce-plugin.js', __FILE__ );

		return $plugins;
	}
}

FindTheBest_ContentBoost::init();
