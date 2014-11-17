<?php
/*
Plugin Name: Etsy Importer
Plugin URI: http://www.webdevstudios.com
Description: Import your Etsy store's products as posts in a custom post type.
Author: WebDevStudios
Author URI: http://www.webdevstudios.com
Version: 1.1.0
License: GPLv2
*/


/**
 * All of the required global functions should be placed here.
 *
 * @package WordPress
 * @subpackage Etsy Importer
 */
Class Etsy_Importer {

	// A single instance of this class.
	public static $instance = null;

	// An array of our registered taxonomies
	public static $taxonomies = array();

	/**
	 * Creates or returns an instance of this class.
	 * @since  0.1.0
	 * @return Etsy_Importer A single instance of this class.
	 */
	public static function engage() {
		if ( self::$instance === null )
			self::$instance = new self();

		return self::$instance;
	}

	/**
	 * Build our class and run the functions
	 */
	public function __construct() {

		// Setup our cron job
		add_action( 'wp', array( $this, 'setup_cron_schedule' ) );

		// Run our cron job to import new products
		add_action( 'etsy_importer_daily_cron_job', array( $this, 'cron_import_posts' ) );

		// Add our menu items
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_admin_settings' ) );
		add_action( 'admin_init', array( $this, 'process_settings_save') );

		// Load translations
		load_plugin_textdomain( 'etsy_importer', false, 'etsy-importer/languages' );

		// Define our constants
		add_action( 'after_setup_theme', array( $this, 'constants' ), 1 );

		// Register our post types
		add_action( 'init', array( $this, 'post_types' ) );

		// Register our taxonomies
		add_action( 'init', array( $this, 'taxonomies' ) );

		// Don't load in WP Dashboard
		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_styles' ), 21 );
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ), 21 );
		} else {
			add_action( 'wp_enqueue_scripts', array( $this, 'styles' ), 21 );
			add_action( 'wp_enqueue_scripts', array( $this, 'scripts' ), 21 );
		}

		// Add shortcodes
		add_shortcode( 'product_link', array( $this, 'product_link_shortcode' ) );
		add_shortcode( 'product_content', array( $this, 'product_content_shortcode' ) );
		add_shortcode( 'product_images', array( $this, 'product_images_shortcode' ) );

		// Include CMB
		require_once( 'cmb/etsy-fields.php' );
		require_once( 'cmb/init.php' );

		// Get the shop ID and our API key
		$this->options 	= get_option( 'etsy_store_settings' );
		$this->api_key 	= ( isset( $this->options['settings_etsy_api_key'] ) ) ? esc_html( $this->options['settings_etsy_api_key'] ) : '';
		$this->store_id = ( isset( $this->options['settings_etsy_store_id'] ) ) ? esc_html( $this->options['settings_etsy_store_id'] ) : '';

		// Grab the shop name
		$url 			= 'https://openapi.etsy.com/v2/private/shops/' . $this->store_id . '?api_key=' . $this->api_key;
		$ch 			= curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		$response_body 	= curl_exec( $ch );
		$status 		= curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		$this->response = json_decode( $response_body );

	}


	// add once 10 minute interval to wp schedules
	public function new_interval($interval) {

	    $interval['minutes_1'] = array('interval' => 1*60, 'display' => 'Once every minute');

	    return $interval;
	}


	/**
	 * Defines the constant paths for use within the theme.
	 */
	public function constants() {

		/* Sets the path to the child theme directory. */
		$this->define_const( 'ETSY_DIR', plugins_url( '/', __FILE__ ) );
		$ETSY_DIR = ETSY_DIR;

		/* Sets the path to the css directory. */
		$this->define_const( 'ETSY_CSS', trailingslashit( $ETSY_DIR . 'css' ) );

		/* Sets the path to the javascript directory. */
		$this->define_const( 'ETSY_JS', trailingslashit( $ETSY_DIR . 'js' ) );

		/* Sets the path to the images directory. */
		$this->define_const( 'ETSY_IMG', trailingslashit( $ETSY_DIR . 'images' ) );

		/* Sets the path to the languages directory. */
		$this->define_const( 'ETSY_LANG', trailingslashit( $ETSY_DIR . 'languages' ) );

	}


	/**
	 * Define a constant if it hasn't been already (this allows them to be overridden)
	 * @since  1.0.0
	 * @param  string  $constant Constant name
	 * @param  string  $value    Constant value
	 */
	public function define_const( $constant, $value ) {
		// (can be overridden via wp-config, etc)
		if ( ! defined( $constant ) )
			define( $constant, $value );
	}


	/**
	 * Load global styles
	 */
	public function admin_styles() {
		global $post;

		// Main stylesheet
		wp_enqueue_style( 'etsy-importer', ETSY_CSS . 'style.css', null, 1 );

		// Thickbox
		wp_enqueue_style( 'thickbox' );

	}


	/**
	 * Load global scripts
	 */
	public function admin_scripts() {
		global $post;

		// Thickbox script
		wp_enqueue_script( 'thickbox' );

	}


	/**
	 * Load global styles
	 */
	public function styles() {
		global $post;

		// Enqueue thickbox if the product images shortcode is used
		if ( has_shortcode( $post->post_content, 'product_images' ) )
			wp_enqueue_style( 'thickbox' );

	}


	/**
	 * Load global scripts
	 */
	public function scripts() {
		global $post;

		// Enqueue thickbox if the product images shortcode is used
		if ( has_shortcode( $post->post_content, 'product_images' ) )
			wp_enqueue_script( 'thickbox', array( 'jquery' ) );

	}


	/**
	 * Register Custom Post Types
	 *
	 * Name (Singular), Name (Plural), Post Type Key (lowercase, use underscore for space),
	 * URL Slug (lowercase, use dash for space), Search, Link To Taxonomies, Hierachical, Menu Position, Supports
	 */
	public function post_types() {

		$this->post_type( array( 'Product', 'Products', 'etsy_products', 'products' ), array( 'menu_position' => '4' ) );
	}

	// public function post_type( $type, $types, $key, $url_slug, $search, $cpt_tax, $hierarchical, $menupos, $supports, $showui = true ) {
	public function post_type( $type, $args = array() ) {

		if ( is_array( $type ) ) {
			$types 	= isset( $type[1] ) ? $type[1] : $type . 's';
			$key 	= isset( $type[2] ) ? $type[2] : strtolower( str_ireplace( ' ', '_', $type[1] ) );
			$slug 	= isset( $type[3] ) ? $type[3] : str_ireplace( '_', '-', $key );
			$type 	= $type[0];
		} else {
			$types 	= $type . 's';
			$key 	= strtolower( str_ireplace( ' ', '_', $type ) );
			$slug 	= str_ireplace( '_', '-', $key );
		}

		// Setup our labels
		$labels = array(
			'name'                => $type,
			'singular_name'       => $type,
			'add_new'             => 'Add New',
			'add_new_item'        => 'Add New ' . $type,
			'edit_item'           => 'Edit '. $type,
			'new_item'            => 'New '. $type,
			'view_item'           => 'View '. $type,
			'search_items'        => 'Search '. $types,
			'not_found'           => 'No '. $types .' found',
			'not_found_in_trash'  => 'No '. $types .' found in Trash',
			'parent_item_colon'   => '',
			'menu_name'           => $types
		);

		$args = wp_parse_args( $args, array(
			'labels'              => $labels,
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'query_var'           => true,
			'rewrite'             => array( 'slug' => $slug ),
			'capability_type'     => 'post',
			'hierarchical'        => false,
			'menu_position'       => '8',
			'has_archive'         => true,
			'exclude_from_search' => true,
			'supports'            => array( 'title', 'editor', 'revisions', 'thumbnail' ),
			'taxonomies'          => array()
		) );

		// Register our post types
		register_post_type( $key, $args );

	}


	/**
	 * Register Taxonomies
	 *
	 * Name (Singular), Name (Plural), Taxonomy Key (lowercase, use underscore for space), URL Slug (lowercase, use dash for space), Parent Post Type Key
	 */
	public function taxonomies() {

		$this->taxonomy( 'Category', 'Categories', 'etsy_category', 'category', array( 'etsy_products' ), true );
		$this->taxonomy( 'Tag', 'Tags', 'etsy_tag', 'tag', array( 'etsy_products' ), true );

	}

	public function taxonomy( $type, $types, $key, $url_slug, $post_type_keys, $public ) {

		// Setup our labels
		$labels = array(
			'name'                       => $types,
			'singular_name'              => $type,
			'search_items'               => 'Search '.$types,
			'popular_items'              => 'Common '.$types,
			'all_items'                  => 'All '.$types,
			'parent_item'                => null,
			'parent_item_colon'          => null,
			'edit_item'                  => 'Edit '.$type,
			'update_item'                => 'Update '.$type,
			'add_new_item'               => 'Add New '.$type,
			'new_item_name'              => 'New '. $type .' Name',
			'separate_items_with_commas' => 'Separate '. $types. ' with commas',
			'add_or_remove_items'        => 'Add or remove '.$types,
			'choose_from_most_used'      => 'Choose from the most used '.$types
		);

		// Permalink
		$rewrite = array(
			'slug'                       => $url_slug,
			'with_front'                 => true,
			'hierarchical'               => true,
		);

		// Default arguments
		$args = array(
			'labels'                     => $labels,
			'hierarchical'               => true,
			'public'                     => $public,
			'show_ui'                    => true,
			'show_admin_column'          => true,
			'show_in_nav_menus'          => true,
			'show_tagcloud'              => true,
			'query_var'                  => true,
			'rewrite'                    => $rewrite
		);

		self::$taxonomies[ $key ] = array( 'post_types' => $post_type_keys, 'taxonomy_args' => $args );

		// Register our taxonomies
		register_taxonomy( $key, $post_type_keys, $args );

	}


	/**
	 * Add our menu items
	 */
	public function admin_menu() {

		// Only let admins see this, but I believe only admins see the settings menu anyway so this is just kind of a backup
		if( is_admin() )
			add_options_page( __( 'Etsy Importer', 'etsy_importer' ), __( 'Etsy Importer', 'etsy_importer' ), 'administrator', __FILE__, array( $this, 'admin_page' ) );
	}

	/**
	 * Register settings and fields
	 */
	public function register_admin_settings() {

		// Add our settings
		register_setting( 'etsy_store_settings', 'etsy_store_settings', array( $this, 'validate_settings' ) );
		add_settings_section( 'etsy_store_main_options', '', '', __FILE__ );
		add_settings_field( 'etsy_settings_api_key', __( 'API Key:', 'etsy_importer' ), array( $this, 'settings_etsy_api_key' ), __FILE__, 'etsy_store_main_options' );
		add_settings_field( 'etsy_settings_store_id', __( 'Store ID:', 'etsy_importer' ), array( $this, 'settings_etsy_store_id' ), __FILE__, 'etsy_store_main_options' );
	}

	/**
	 * Build the form fields
	 */
	public function settings_etsy_api_key() {

		echo "<div class='input-wrap'><div class='left'><input id='api-key' name='etsy_store_settings[settings_etsy_api_key]' type='text' value='{$this->options['settings_etsy_api_key']}' /></div>";
		?>

		<p><?php printf( __( 'Need help? <a href="%s" class="thickbox">Click here</a> for a walkthrough on how to setup your Etsy Application.', 'etsy_importer' ), '#TB_inline?width=1200&height=600&inlineId=etsy-api-instructions' ); ?></p>

		<div id="etsy-api-instructions" style="display: none;">
			<p><?php printf( __( 'In order to import your products, you first need to register an application with Etsy.  <a href="%s" target="_blank">Click here</a> to begin registering your application.  You should see a screen similar to the image below:', 'etsy_importer' ), 'https://www.etsy.com/developers/register' ); ?><br />
			<img src="<?php echo ETSY_DIR . 'screenshot-1.jpg'; ?>" /></p>

			<p><?php _e( 'Once you have created your app, click "Apps You\'ve Made" in the sidebar and select your new app.  On the app detail page, copy the value in the Keystring input field.  This is your API Key.', 'etsy_importer' ); ?><br />
			<img src="<?php echo ETSY_DIR . 'screenshot-2.jpg'; ?>" /></p>

		</div>

		<?php
	}

	/**
	 * Build the form fields
	 */
	public function settings_etsy_store_id() {

		// Get the total post count
		$count_posts = wp_count_posts( 'etsy_products' );
		$total_posts = $count_posts->publish + $count_posts->future + $count_posts->draft + $count_posts->pending + $count_posts->private;

		// Grab our shop name if we have a response
		isset( $this->response ) ? $shop_name = $this->response->results[0]->title : $shop_name = null;

		echo "<div class='input-wrap'>
			<div class='left'>
				<input id='store-id' name='etsy_store_settings[settings_etsy_store_id]' type='text' value='{$this->options['settings_etsy_store_id']}' />
			</div>";

	?>

		<p><?php printf( __( 'Need help? <a href="%s" class="thickbox">Click here</a> for a walkthrough on how to find your Etsy store ID.', 'etsy_importer' ), '#TB_inline?width=1200&height=600&inlineId=etsy-store-id-instructions' ); ?></p>

		<div id="etsy-store-id-instructions" style="display: none;">
			<p><?php _e( 'Visit your Etsy store\'s front page.  View the page source:', 'etsy_importer' ); ?><br />
			<img src="<?php echo ETSY_DIR . 'screenshot-3.jpg'; ?>" /></p>

			<p><?php _e( 'We want one specific line, whose meta name is "apple-itunes-app".  The number you see below following "etsy://shop/" is your store ID:', 'etsy_importer' ); ?><br />
			<img src="<?php echo ETSY_DIR . 'screenshot-4.jpg'; ?>" /></p>

		</div>

		<p class="import-count">
			<span>
				<?php
				echo $total_posts >= 1 ? sprintf( __( 'You have imported <strong>%s products</strong>.', 'etsy_importer' ), $total_posts ) . '<br />' : null;
				echo $shop_name !== null ? sprintf( __( 'You are connected to <strong>%s</strong>.', 'etsy_importer' ), $shop_name ) : null;
				?>
			</span>
		</p>

	<?php
	}

	/**
	 * Sanitize the value
	 */
	public function validate_settings( $etsy_store_settings ) {
		return $etsy_store_settings;
	}

	/**
	 * Import our products and add our new taxonomy terms on settings save
	 */
	public function process_settings_save() {

		// Save our API Key
		if ( isset( $_POST['etsy_store_settings']['settings_etsy_api_key'] ) && ! empty ( $_POST['etsy_store_settings']['settings_etsy_api_key'] ) ) {

			// Update our class variables
			$this->settings_etsy_api_key = $_POST['etsy_store_settings']['settings_etsy_api_key'];
		}

		// Save our Store ID
		if ( isset( $_POST['etsy_store_settings']['settings_etsy_store_id'] ) && ! empty ( $_POST['etsy_store_settings']['settings_etsy_store_id'] ) ) {

			// Update our class variables
			$this->settings_etsy_store_id = $_POST['etsy_store_settings']['settings_etsy_store_id'];
		}


		// If both our API Key and Store ID are saved, import our products
		if ( isset( $_POST['etsy_import_nonce'] ) && isset( $_POST['submit-import'] ) && ! empty ( $_POST['etsy_store_settings']['settings_etsy_api_key'] ) && ! empty ( $_POST['etsy_store_settings']['settings_etsy_store_id'] ) ) {

			// Import our products
			$this->import_posts();
		}
	}

	/**
	 * On an early action hook, check if the hook is scheduled - if not, schedule it.
	 * This runs once daily
	 */
	public function setup_cron_schedule() {

		if ( ! wp_next_scheduled( 'etsy_importer_daily_cron_job' ) ) {
			wp_schedule_event( time(), 'daily', 'etsy_importer_daily_cron_job');
		}
	}

	/**
	 * Add the function to run our import script for our cron job
	 */
	public function cron_import_posts() {

		$this->import_posts();
	}

	/**
	 * Build the admin page
	 */
	public function admin_page() {

		// Get the total post count
		$count_posts = wp_count_posts( 'etsy_products' );
		$total_posts = $count_posts->publish + $count_posts->future + $count_posts->draft + $count_posts->pending + $count_posts->private;

		// If there is no response, disable the import button
		! ( $this->response ) ? $disabled = 'disabled' : $disabled = 'enabled';

		?>
		<div id="theme-options-wrap" class="metabox-holder">
			<h2><?php _e( 'Etsy Importer', 'etsy_importer' ); ?></h2>
			<form id="options-form" method="post" action="options.php" enctype="multipart/form-data">
				<div class="postbox ui-tabs etsy-wrapper">
					<h3 class="hndle"><?php _e( 'Import your Etsy store\'s products as posts in the Product custom post type.', 'etsy_importer' ); ?></h3>
					<?php settings_fields( 'etsy_store_settings' ); ?>
					<input type="hidden" name="etsy_import_nonce" value="<?php echo wp_create_nonce( basename( __FILE__ ) ); ?>" />
					<?php do_settings_sections( __FILE__ ); ?>
					<div class="submit">
						<input name="submit-save" type="submit" class="button-primary" value="<?php esc_attr_e( 'Save Changes', 'etsy_importer' ); ?>" />
						<span class="save-notes"><em><?php _e( 'You must save changes before importing products. If you need to change your API Key or Store ID, hit the Save Changes button before hitting the Import Products button.', 'etsy_importer' ); ?></em></span>
					</div>
					<div class="submit">
						<input name="submit-import" type="submit" class="button-primary button-import" value="<?php esc_attr_e( 'Import Products', 'etsy_importer' ); ?>" <?php echo $disabled; ?> />
						<div class="save-notes"><em><?php _e( 'Your import could take a while if you have a large number of products or images attached to each product.', 'etsy_importer' ); ?></em>
						<p><em><?php _e( 'After your initial import, your products will import automatically once daily.  If you need to manually import your products ahead of schedule, clicking the Import Products button will begin a manual import of new products.', 'etsy_importer' ); ?></em></p></div>
					</div>
				</div>
			</form>
		</div>
	<?php }


	/**
	 * Add a shortcode to display the product title
	 *
	 * @since 1.0
	 */
	public function product_link_shortcode( $atts, $content = null ) {

		// Get our shortcode attributes
		extract( shortcode_atts( array(
			'id'		=> '',
			'external'	=> '',
			'title'		=> ''
		), $atts ) );

		// Get our post content
		$product = get_post( $id );

		// If there is no product found, stop
		if ( ! $product )
			return;

		// Get our post or external link
		if ( $external == 'yes' || $external == 'true' ) {

			$link 	= esc_url( get_post_meta( $id, '_etsy_product_url', true ) );
			$target	= '_blank';

		} else {

			$link 	= get_permalink( $id );
			$target	= '_self';
		}

		// Get our link title
		$title = $title ? : get_the_title( $id );

		// Assume zer is nussing
		$output = '';

		// If our title and link return something, display the link
		if ( $title && $link )
			$output .= '<p><a href="' . $link . '" title="' . $title . '" target="' . $target . '">' . $title . '</a></p>';

		return $output;
	}


	/**
	 * Add a shortcode to display the product content
	 *
	 * @since 1.0
	 */
	public function product_content_shortcode( $atts, $content = null ) {

		// Get our shortcode attributes
		extract( shortcode_atts( array(
			'id'		=> '',
			'length'	=> ''
		), $atts ) );

		// Get our post content
		$product = get_post( $id );

		// If there is no product found, stop
		if ( ! $product )
			return;

		$content = wpautop( $product->post_content );

		// Assume zer is nussing
		$output = '';

		// If our content returns something, display it
		if ( $content ) {

			// If we have a length set, apply it
			if ( '' !== $length ) {

				$excerpt_length = $length;
			    $excerpt_more   = '&hellip;';
			    $output        .= '<p>' . wp_trim_words( $content, $excerpt_length, $excerpt_more ) . ' <a href="' . get_permalink( $id ) . '" class="more-link">' . __( 'Continue reading', 'etsy_importer' ) . ' <span class="screen-reader-text">' . $product->post_title . '</span></a></p>';

			} else {

				$output .= $content;

			}
		}

		return $output;
	}


	/**
	 * Add a shortcode to display the product content
	 *
	 * @since 1.0
	 */
	public function product_images_shortcode( $atts, $content = null ) {

		// Get our shortcode attributes
		extract( shortcode_atts( array(
			'id'	=> '',
			'size'	=> ''
		), $atts ) );

		// Get our post content
		$product = get_post( $id );

		// If there is no product found, stop
		if ( ! $product )
			return;

		// Get our post images
		$images = get_posts( array(
				'post_type'			=> 'attachment',
				'posts_per_page'	=> -1,
				'post_parent'		=> $id
			)
		);

		// Assume zer is nussing
		$output = '';

		// If our content returns something, display it
		if ( $images ) {
			foreach ( $images as $image ) {

				// Set the image ID
				$image_id = $image->ID;

				// Grab the image based on the size passed in the shortcode
				$image_thumb 	= wp_get_attachment_image( $image_id, 'thumbnail' );
				$image_full 	= wp_get_attachment_image_src( $image_id, 'full' );

				// Display the image
				$output .= '<a href="' . $image_full[0]. '" class="thickbox" rel="gallery-' . $id . '">' . $image_thumb . '</a>';

			}
		}

		return $output;
	}

	/**
	 * Grab the image ID from its URL
	 */
	public function get_attachment_id_from_src( $image_src ){
		global $wpdb;

		$query = "SELECT ID FROM {$wpdb->posts} WHERE guid='$image_src'";
		$id = $wpdb->get_var( $query );
		return $id;

	}

	/**
	 * Register the function that imports our posts
	 */
	private function import_posts() {
		global $wpdb;

		// Make sure you define API_KEY to be your unique, registered key
		$url 			= "https://openapi.etsy.com/v2/private/shops/" . $this->store_id . "/listings/active?sort_on=created&sort_order=down&api_key=" . $this->api_key;
		$ch 			= curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		$response_body 	= curl_exec( $ch );
		$status 		= curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		$response = json_decode( $response_body );

		// Get each listing
		if ( $response ) {

			// Increase our time limit
			set_time_limit( 120 );

			// Loop through each product
			foreach( $response->results as $product ) {

				// If the post doesn't exist, add it!
				if ( ! get_page_by_title( esc_html( $product->title ), OBJECT, 'etsy_products' ) ) {

					// Set up our post args
					$post = array(
						'post_type'		=> 'etsy_products',
						'post_title'	=> esc_html( $product->title ),
						'post_content'	=> wp_kses_post( $product->description ),
						'post_status'	=> 'publish'
					);

					// Create our post
					$post_id = wp_insert_post( $post );

					// Update our post meta with the group ID
					update_post_meta( $post_id, '_etsy_product_price', esc_html( $product->price ) );
					update_post_meta( $post_id, '_etsy_product_currency', esc_html( $product->currency_code ) );
					update_post_meta( $post_id, '_etsy_product_url', esc_url( $product->url ) );
					update_post_meta( $post_id, '_etsy_product_made', str_replace( '_', '-', $product->when_made ) );
					update_post_meta( $post_id, '_etsy_product_made_for', esc_html( $product->recipient ) );

					// Set our categories
					wp_set_object_terms( $post_id, $product->category_path, 'etsy_category', true );

					// Set our tags
					wp_set_object_terms( $post_id, $product->tags, 'etsy_tag', true );

					// Get each listing's images
					$images 			= 'https://openapi.etsy.com/v2/private/listings/' . $product->listing_id . '/images?api_key=' . $this->api_key;
					$ch 				= curl_init( $images );
					curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
					$returned_images 	= curl_exec( $ch );
					$status 			= curl_getinfo( $ch, CURLINFO_HTTP_CODE );
					$response	= json_decode( $returned_images );

					// Loop through each listing's images and upload them
					foreach( $response->results as $image ) {

						// Get our image URL and basename
						$image_url 	= $image->url_fullxfull;
						$filename 	= basename( $image->url_fullxfull );

						// Upload our image and attach it to our post
						$uploaded_image = media_sideload_image( $image_url, $post_id, $filename );

						// Grab the src URL from our image tag
						$uploaded_image = preg_replace( "/.*(?<=src=[''])([^'']*)(?=['']).*/", '$1', $uploaded_image );

						// Set post thumbnail to the image with rank 1
						if ( $image->rank == 1 )
							set_post_thumbnail( $post_id, $this->get_attachment_id_from_src( $uploaded_image ) );
					}
				}
			}
		}
	}
}

// Instantiate the class
Etsy_Importer::engage();
