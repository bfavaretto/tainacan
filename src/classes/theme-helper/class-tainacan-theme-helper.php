<?php

namespace Tainacan;
use Tainacan\Entities;


class Theme_Helper {

	private static $instance = null;
	
	public $visiting_collection_cover = false;

	/**
	 * Stores view modes available to be used by the theme
	 */
	private $registered_view_modes = [];

	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {

		add_filter( 'the_content', [$this, 'the_content_filter'] );
		

		// Replace collections permalink to post type archive if cover not enabled
		add_filter('post_type_link', array($this, 'permalink_filter'), 10, 3);

		// Replace single query to the page content set as cover for the colllection
		// Redirect to post type archive if no cover page is set
		add_action('wp', array($this, 'collection_single_redirect'));
		
		add_action('wp_print_scripts', array($this, 'enqueue_scripts'), 90);
		
		// make archive for terms work with items
		add_action('pre_get_posts', array($this, 'tax_archive_pre_get_posts'));
		
		add_action('archive_template_hierarchy', array($this, 'items_template_hierachy'));
		add_action('taxonomy_template_hierarchy', array($this, 'tax_template_hierachy'));
		add_action('single_template_hierarchy', array($this, 'items_template_hierachy'));
		
		add_filter('theme_mod_header_image', array($this, 'header_image'));

		add_filter('get_the_archive_title', array($this, 'filter_archive_title'));

		add_shortcode( 'tainacan-search', array($this, 'search_shortcode'));
		add_shortcode( 'tainacan-item-submission', array($this, 'item_submission_shortcode'));

		add_action( 'generate_rewrite_rules', array( &$this, 'rewrite_rules' ), 10, 1 );
		add_filter( 'query_vars', array( &$this, 'rewrite_rules_query_vars' ) );
		add_filter( 'template_include', array( &$this, 'rewrite_rule_template_include' ) );
		add_action( 'pre_get_posts', array($this, 'archive_repository_pre_get_posts'));
		// TODO: fix the WP Title 
		// add_filter( 'wp_title', array($this, 'archive_repository_wp_title'), 10, 3);
		
		add_action( 'wp_head', array($this, 'add_social_meta'), 5 );
		
		$this->register_view_mode('table', [
			'label' => __('Table', 'tainacan'),
			'description' => 'The classic table display.',
			'dynamic_metadata' => true,
			'icon' => '<span class="icon"><i class="tainacan-icon tainacan-icon-viewtable tainacan-icon-1-25em"></i></span>',
			'type' => 'component',
			'implements_skeleton' => true,
			'requires_thumbnail' => false
		]);
		$this->register_view_mode('cards', [
			'label' => __('Cards', 'tainacan'),
			'dynamic_metadata' => false,
			'description' => 'A cards view, displaying cropped thumbnails, title and description.',
			'icon' => '<span class="icon"><i class="tainacan-icon tainacan-icon-viewcards tainacan-icon-1-25em"></i></span>',
			'type' => 'component',
			'implements_skeleton' => true,
			'requires_thumbnail' => false
		]);
		$this->register_view_mode('records', [
			'label' => __('Records', 'tainacan'),
			'dynamic_metadata' => true,
			'description' => 'A records view, similiar to cards, but flexible for metadata.',
			'icon' => '<span class="icon"><i class="tainacan-icon tainacan-icon-viewrecords tainacan-icon-1-25em"></i></span>',
			'type' => 'component',
			'implements_skeleton' => true,
			'requires_thumbnail' => false
		]);
		$this->register_view_mode('masonry', [
			'label' => __('Masonry', 'tainacan'),
			'dynamic_metadata' => false,
			'description' => 'A masonry view, similar to pinterest, which will display images without cropping.',
			'icon' => '<span class="icon"><i class="tainacan-icon tainacan-icon-viewmasonry tainacan-icon-1-25em"></i></span>',
			'type' => 'component',
			'implements_skeleton' => true
		]);
		$this->register_view_mode('slideshow', [
			'label' => __('Slides', 'tainacan'),
			'dynamic_metadata' => false,
			'description' => 'A fullscreen slideshow view, that shows the item document instead of just thumbnails.',
			'icon' => '<span class="icon"><i class="tainacan-icon tainacan-icon-viewgallery tainacan-icon-1-25em"></i></span>',
			'type' => 'component',
			'show_pagination' => false,
			'full_screen' => true
		]);
		$this->register_view_mode('list', [
			'label' => __('List', 'tainacan'),
			'dynamic_metadata' => true,
			'description' => 'A list view, similiar to the records, but full width.',
			'icon' => '<span class="icon"><i class="tainacan-icon tainacan-icon-viewlist tainacan-icon-1-25em"></i></span>',
			'type' => 'component',
			'implements_skeleton' => true,
			'requires_thumbnail' => false
		]);
	}
	
	public function enqueue_scripts($force = false) {
		global $TAINACAN_BASE_URL;
		if ( $force || is_post_type_archive( \Tainacan\Repositories\Repository::get_collections_db_identifiers() ) || tainacan_get_term() || get_query_var('tainacan_repository_archive') == 1 ) {
			wp_register_script('tainacan-search', $TAINACAN_BASE_URL . '/assets/js/theme_search.js' , ['underscore'] , TAINACAN_VERSION);
			wp_localize_script('tainacan-search', 'tainacan_plugin', \Tainacan\Admin::get_instance()->get_admin_js_localization_params());
		}
	}
	
	public function is_post_an_item(\WP_Post $post) {
		$post_type = $post->post_type;
		$prefix = substr( $post_type, 0, strlen( Entities\Collection::$db_identifier_prefix ) );
		return $prefix == Entities\Collection::$db_identifier_prefix;
	}
	
	public function is_taxonomy_a_tainacan_tax($tax_slug) {
		$prefix = substr( $tax_slug, 0, strlen( Entities\Taxonomy::$db_identifier_prefix ) );
		return $prefix == Entities\Taxonomy::$db_identifier_prefix;
	}
	
	public function is_term_a_tainacan_term( \WP_Term $term ) {
		return $this->is_taxonomy_a_tainacan_tax($term->taxonomy);
	}
	
	public function filter_archive_title($title) {
		if (is_post_type_archive()) {
			
			$collections_post_types = \Tainacan\Repositories\Repository::get_collections_db_identifiers();
			$current_post_type = get_post_type();
			
			if (in_array($current_post_type, $collections_post_types)) {
				$title = sprintf( __( 'Collection: %s' ), post_type_archive_title( '', false ) );
			}
		} elseif (is_archive()) {
			if (get_query_var('tainacan_repository_archive') == 1) {
				$title = __( 'All items in repository', 'tainacan' );
			}
		}
		
		return $title;
	}

	public function the_content_filter($content) {
		
		if (!is_single())
			return $content;

		$post = get_queried_object();
		
		// Is it a collection Item?
		if ( !$this->is_post_an_item($post) ) {
			return $content;
		}
		
		$item = new Entities\Item($post);
		$content = '';
		
		// document
		$content .= $item->get_document_as_html();
		
		// metadata
		$content .= $item->get_metadata_as_html();
		
		// attachments
		
		return $content;
		
	}
	
	/**
	 * Filters the permalink for posts to:
	 *
	 * * Replace Collection single permalink with the link to the post type archive for items of that collection
	 * 
	 * @return string new permalink
	 */
	function permalink_filter($permalink, $post, $leavename) {
		
		$collection_post_type = \Tainacan\Entities\Collection::get_post_type();
		
		if (!is_admin() && $post->post_type == $collection_post_type) {
			
			$collection = new \Tainacan\Entities\Collection($post);
			
			if ( $collection->is_cover_page_enabled() ) {
				return $permalink;
			}
			
			$items_post_type = $collection->get_db_identifier();
			
			$post_type_object = get_post_type_object($items_post_type);
			
			if (isset($post_type_object->rewrite) && is_array($post_type_object->rewrite) && isset($post_type_object->rewrite['slug']))
				return get_post_type_archive_link($items_post_type);
				
		}
		
		return $permalink;
		
	}
	
	function tax_archive_pre_get_posts($wp_query) {
		
		if (!$wp_query->is_tax() || !$wp_query->is_main_query())
			return;
		
		$term = get_queried_object();
		
		if ($term instanceof \WP_Term && $this->is_term_a_tainacan_term($term)) {
			
			$tax_id = \Tainacan\Repositories\Taxonomies::get_instance()->get_id_by_db_identifier($term->taxonomy);
			$tax = \Tainacan\Repositories\Taxonomies::get_instance()->fetch($tax_id);
			
			if ( $tax && $tax->can_read() ) {
				$post_types = $tax->get_enabled_post_types();

				// TODO: Why post_type = any does not work? 
				// ANSWER because post types are registered with exclude_from_search. Should we change it?
				// TODO adding all post types to the list is something we need to discuss 
				$post_types = array_merge($post_types, \Tainacan\Repositories\Repository::get_collections_db_identifiers());
				$wp_query->set( 'post_type',  $post_types);
				
			} else {
				$wp_query->set_404();
				status_header( 404 );
			}
			
			
		}
		
	}
	
	function collection_single_redirect() {
		
		if (is_single() && get_post_type() == \Tainacan\Entities\Collection::$post_type) {
			
			$post = get_post();
			
			$collection = new \Tainacan\Entities\Collection($post);
			
			if ( ! $collection->is_cover_page_enabled() ) {
				
				wp_redirect(get_post_type_archive_link( $collection->get_db_identifier() ));
				
			} else {
				
				$cover_page_id = $collection->get_cover_page_id();
				
				if ($cover_page_id) {
					
					// TODO: it would be better to do this via pre_get_posts. But have to find out how to do it
					// Without generating a redirect.
					// Another question is that, doing this way, hooking in wp, assures that the template loader 
					// still looks for tainacan-collection-single, and not for page.
					
					global $wp_query;
					$wp_query = new \WP_Query('page_id=' . $cover_page_id);
					
					$this->visiting_collection_cover = $collection->get_id();
				}
				
			}
			
		}
		
		
	}
	
	function items_template_hierachy($templates) {
		
		if (is_post_type_archive() || is_single()) {
			
			$collections_post_types = \Tainacan\Repositories\Repository::get_collections_db_identifiers();
			$current_post_type = get_post_type();
			
			if (in_array($current_post_type, $collections_post_types)) {
				
				$last_template = array_pop($templates);
				
				if (is_post_type_archive()) {
					array_push($templates, 'tainacan/archive-items.php');
				} elseif (is_single()) {
					array_push($templates, 'tainacan/single-items.php');
				}
				
				array_push($templates, $last_template);
				
			}
			
		}
		
		return $templates;
		
	}
	
	function tax_template_hierachy($templates) {
		
		if (is_tax()) {
			
			$term = get_queried_object();
			
			if ( isset($term->taxonomy) && $this->is_taxonomy_a_tainacan_tax($term->taxonomy)) {
				$tax_id = \Tainacan\Repositories\Taxonomies::get_instance()->get_id_by_db_identifier($term->taxonomy);
				$tax = \Tainacan\Repositories\Taxonomies::get_instance()->fetch($tax_id);
				
				if ( $tax ) {
					$post_types = $tax->get_enabled_post_types();
					if (sizeof($post_types)) {
						// if taxonomy is enabled for other post types, we disable 
						// custom template ans use default list
						// TODO: This needs discussion
						return $templates;
					}
				}
				
				$last_template = array_pop($templates);
				
				array_push($templates, 'tainacan/archive-taxonomy.php');
				
				array_push($templates, $last_template);
				
			}
			
		}
		
		return $templates;
		
	}
	
	function header_image($image) {
		
		$object = false;
		
		if ($collection_id = tainacan_get_collection_id()) {
			$object = \Tainacan\Repositories\Collections::get_instance()->fetch($collection_id);
		} elseif ($term = tainacan_get_term()) {
			$object = \Tainacan\Repositories\Terms::get_instance()->fetch($term->term_id, $term->taxonomy);
		}
		
		if (!$object)
			return $image;
		
		$header_image = $object->get_header_image_id();
		
		if (is_numeric($header_image)) {
			$src = wp_get_attachment_image_src($header_image, 'full');
			if (is_array($src)) {
				$image = $src[0];
			}
		}
		
		return $image;
	}

	public function item_submission_shortcode($args) {
		global $TAINACAN_BASE_URL;

		$props = ' ';

		// Passes arguments to custom props
		if ($args) {
			foreach ($args as $key => $value) {
				if ($value == true || $value == 'true') {
					$props .= str_replace('_', '-', $key) . '="' . $value . '" ';
				}
			}
		}

		wp_enqueue_media();
		wp_enqueue_script('jcrop');
		wp_enqueue_script('tainacan-item-submission', $TAINACAN_BASE_URL . '/assets/js/item_submission.js' , ['underscore', 'jcrop', 'media-editor', 'media-views', 'customize-controls'] , TAINACAN_VERSION);
		wp_localize_script('tainacan-item-submission', 'tainacan_plugin', \Tainacan\Admin::get_instance()->get_admin_js_localization_params());

		return "<div id='tainacan-item-submission-form' $props ></div>";
	}

	/**
	 * Returns the div used by Vue to render the Items List with a powerful faceted search
	 *
	 * The items list bellong to a collection, to the whole repository or a taxonomy term, according to where
	 * it is used on the loop, or to given params
	 * 
	 * The following params are all optional for customizing the rendered vue component
	 *
	 * @param array $args {
		 *     Optional. Array of arguments.
		 *     @type string $collection_id								Collection ID for a collection items list
		 *     @type string $term_id									Term ID for a taxonomy term items list
		 *     @type bool 	$hide_filters								Completely hide filter sidebar or modal
		 *     @type bool 	$hide_hide_filters_button					Hides the button resonsible for collpasing filters sidebar on desktop
		 *     @type bool 	$hide_search								Hides the complete search bar, including advanced search link
		 *     @type bool 	$hide_advanced_search						Hides only the advanced search link
		 *     @type bool	$hide_displayed_metadata_dropdown			Hides the "Displayed metadata" dropdown even if the current view modes allows it	
		 *     @type bool	$hide_sorting_area							Completely hides all sorting controls	
		 *     @type bool 	$hide_sort_by_button						Hides the button where user can select the metadata to sort by items (keeps the sort direction)
		 *     @type bool 	$hide_items_thumbnail						Forces the thumbnail to be hiden on every listing. This setting also disables view modes that contain the 'requires-thumbnail' attr. By default is false or inherited from collection setting
		 *     @type bool	$hide_exposers_button						Hides the "View as..." button, a.k.a. Exposers modal
		 *     @type bool 	$hide_items_per_page_button					Hides the button for selecting amount of items loaded per page
		 *     @type bool 	$hide_go_to_page_button						Hides the button for skiping to a specific page
		 *     @type bool	$hide_pagination_area						Completely hides pagination controls
		 *     @type int	$default_items_per_page						Default number of items per page loaded
		 *     @type bool 	$show_filters_button_inside_search_control	Display the "hide filters" button inside of the search control instead of floating
		 *     @type bool 	$start_with_filters_hidden					Loads the filters list hidden from start
		 *     @type bool 	$filters_as_modal							Display the filters as a modal instead of a collapsable region on desktop
		 *     @type bool 	$show_inline_view_mode_options				Display view modes as inline icon buttons instead of the dropdown
		 *     @type bool 	$show_fullscreen_with_view_modes			Lists fullscreen viewmodes alongside with other view modes istead of separatelly
		 *     @type string $default_view_mode							The default view mode
		 *     @type bool	$is_forced_view_mode						Ignores user prefs to always render the choosen default view mode
		 *     @type string[] $enabled_view_modes						The list os enable view modes to display
	 * @return string  The HTML div to be used for rendering the items list vue component
	 */
	public function search_shortcode($args) {
		return $this->get_tainacan_items_list($args, true);
	}

	public function get_tainacan_items_list($args, $force_enqueue = false) {
		$props = ' ';

		// Loads info related to view modes
		$view_modes = tainacan_get_the_view_modes();
		$default_view_mode = $view_modes['default_view_mode'];
		$enabled_view_modes = $view_modes['enabled_view_modes'];

		if( isset($args['default_view_mode']) ) {
			$default_view_mode = $args['default_view_mode'];
			unset($args['default_view_mode']);
		}

		if( isset($args['enabled_view_modes']) ) {
			$enabled_view_modes = $args['enabled_view_modes'];
			if ( !in_array($default_view_mode, $enabled_view_modes) ) {
				$default_view_mode = $enabled_view_modes[0];
			}
			unset($args['enabled_view_modes']);
		}

		// If in a collection page
		$collection = tainacan_get_collection($args);
		if ($collection) {
			$props .= 'collection-id="' . $collection->get_id() . '" ';
			$default_view_mode = $collection->get_default_view_mode();
			$enabled_view_modes = $collection->get_enabled_view_modes();
					
			// Gets hideItemsThumbnail info from collection setting
			$args['hide-items-thumbnail'] = $collection->get_hide_items_thumbnail_on_lists() == 'yes' ? true : false;
		}

		// If in a tainacan taxonomy
		$term = tainacan_get_term($args);
		if ($term) {
			$props .= 'term-id="' . $term->term_id . '" ';
			$props .= 'taxonomy="' . $term->taxonomy . '" ';
		}

		$props .= 'default-view-mode="' . $default_view_mode . '" ';
		$props .= 'enabled-view-modes="' . implode(',', $enabled_view_modes) . '" ';

		// Passes arguments to custom props
		foreach ($args as $key => $value) {
			if ($value == true || $value == 'true') {
				$props .= str_replace('_', '-', $key) . '="' . $value . '" ';
			}
		}

		$this->enqueue_scripts($force_enqueue);

		return "<div id='tainacan-items-page' $props ></div>";
	}
	
	function get_items_list_slug() {
		/* Translators: The Items slug - will be the URL for the repository archive */
		return sanitize_title(_x('items', 'Slug: the string that will be used to build the URL to list all items of the repository', 'tainacan'));
	}
	
	function rewrite_rules( &$wp_rewrite ) {
		
		$items_base = $this->get_items_list_slug();
		
		$new_rules = array(
			$items_base . "/?$"               => "index.php?tainacan_repository_archive=1",
			$items_base . "/page/([0-9]+)/?$" => 'index.php?tainacan_repository_archive=1&paged=$matches[1]'
		);

		$wp_rewrite->rules = $new_rules + $wp_rewrite->rules;
	}

	function rewrite_rules_query_vars( $public_query_vars ) {
		$public_query_vars[] = "tainacan_repository_archive";
		return $public_query_vars;
	}

	function rewrite_rule_template_include( $template ) {
		global $wp_query;
		if ( $wp_query->get( 'tainacan_repository_archive' ) == 1 ) {

			$templates = apply_filters('tainacan_repository_archive_template_hierarchy', ['tainacan/archive-repository.php', 'index.php']);
			
			return locate_template($templates, false);
			
		}
		return $template;
	}
	
	function archive_repository_pre_get_posts($wp_query) {
		if (!$wp_query->is_main_query() || $wp_query->get( 'tainacan_repository_archive' ) != 1)
			return;
		
		$wp_query->set( 'is_archive', true );
		$wp_query->set( 'is_post_type_archive', false );
		$wp_query->set( 'is_home', false );
		$wp_query->is_home = false;
		$wp_query->is_post_type_archive = false;
		$wp_query->is_archive = true;
		$wp_query->set( 'post_type', \Tainacan\Repositories\Repository::get_collections_db_identifiers() );
		
	}

	/**
	 * Register a new View Mode
	 * 
	 * View Modes are used to display items in the faceted search when browsing a collection using 
	 * the current active theme. It can be a php/html template or a web component.
	 * 
	 * Collection managers can choose from registered view modes which will be the default mode and what others modes will be available 
	 * for the visitors to choose from for each collection
	 * 
	 * @param string $slug a unique slug for the view mode
	 * @param array|string $args {
	 * 		Optional. Array of arguments
	 * 
	 * 		@type string 		$label				 Label, visible to users. Default to $slug
	 * 		@type string		$description		 Description, visible only to editors in the admin. Default none.
	 * 		@type string		$type 				 Type. Accepted values are 'template' or 'component'. Default 'template'
	 * 		@type string		$template			 Full path  to the template file to be used. Required if $type is set to template.
	 * 												 Default: theme-path/tainacan/view-mode-{$slug}.php
	 * 		@type string		$component			 Component tag name. The web component js must be included and must accept two props:
	 * 												 	* items - the list of items to be rendered
	 * 												 	* displayed-metadata - list of metadata to be displayed
	 * 												 Default view-mode-{$slug}
	 * 		@type string		$thumbnail			 Full URL to an thumbnail that represents the view mode. Displayed in admin.
	 * 		@type string		$icon 				 HTML that outputs an icon that represents the view mode. Displayed in front end.
	 * 		@type bool			$show_pagination	 Wether to display or not pagination controls. Default true.
	 * 		@type bool			$full_screen		 Wether the view mode will display full screen or not. Default false.
	 * 		@type bool			$dynamic_metadata	 Wether to display or not (and use or not) the "displayed metadata" selector. Default false.
	 * 		@type bool			$implements_skeleton Wether the view mode has its own strategy for disaplying loading state.
	 * 		@type string		$skeleton_template	 If the view mode is a template, this is the html of its loading state.
	 * 		@type bool			$required_thumbnail	 Wether the view mode considers essential that the item thumbnail is available, even if it is a placeholder.
	 * }
	 * 
	 * @return void
	 */
	public function register_view_mode($slug, $args = []) {

		$defaults = array(
			'label' => $slug,
			'description' => '',
			'type' => 'template',
			'template' => get_stylesheet_directory() . '/tainacan/view-mode-' . $slug . '.php',
			'component' => 'view-mode-' . $slug,
			'thumbnail' => '', // get_stylesheet_directory() . '/tainacan/view-mode-' . $slug . '.png',
			'icon' => '', //
			'show_pagination' => true,
			'full_screen' => false,
			'dynamic_metadata' => false,
			'implements_skeleton' => false,
			'skeleton_template' => '',
			'requires_thumbnail' => true
		);
		$args = wp_parse_args($args, $defaults);

		$this->registered_view_modes[$slug] = $args;

	}

	/**
	 * Get a list of all registered view modes
	 * 
	 * @return array The list of registered view modes
	 */
	public function get_registered_view_modes() {
		return $this->registered_view_modes;
	}

	/**
	 * Get one specific view mode by its slug
	 * 
	 * @param string $slug The view mode slug
	 * 
	 * @return array|false The view mode definition or false if it is not found
	 */
	public function get_view_mode($slug) {
		return isset($this->registered_view_modes[$slug]) ? $this->registered_view_modes[$slug] : false;
	}
	

	/**
	 * When visiting a collection archive or single, returns the current collection id
	 *
	 * @uses get_post_type() WordPress function, which looks for the global $wp_query variable
	 */
	function tainacan_get_collection_id() {
		if ( is_post_type_archive() || is_single() ) {
			return \Tainacan\Repositories\Collections::get_instance()->get_id_by_db_identifier(get_post_type());
		} elseif ( false !== $this->visiting_collection_cover ) {
			return $this->visiting_collection_cover;
		}
		return false;
	}

	/**
	 * When visiting a collection archive or single, returns the current collection object
	 *
	 * @uses tainacan_get_collection_id()
	 * @return \Tainacan\Entities\Collection | false
	 */
	function tainacan_get_collection($args = []) {
		$collection_id = isset($args['collection_id']) ? $args['collection_id'] : $this->tainacan_get_collection_id();
		if ( $collection_id ) {
			$TainacanCollections = \Tainacan\Repositories\Collections::get_instance();
			$collection = $TainacanCollections->fetch($collection_id);
			if ( $collection instanceof \Tainacan\Entities\Collection ) {
				return $collection;
			}
		}
		return false;
	}


	/**
	 * Gets the Tainacan Item Entity object
	 *
	 * If used inside the Loop of items, will get the Item object for the current post
	 */
	function tainacan_get_item($post_id = 0) {
		$post = get_post( $post_id );

		if (!$post)
			return null;

		if (!$this->is_post_an_item($post))
			return null;

		$item = new \Tainacan\Entities\Item($post);

		return $item;

	}

	/**
	 * Adds meta tags to the header to improve social sharing 
	 */
	public function add_social_meta() {

		if ( is_single() || is_tax() || is_archive() ) {

			$logo = get_template_directory_uri() . '/assets/images/social-logo.png';
			$excerpt = get_bloginfo( 'description' );
			$url_src = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
			global $wp;
				
			if ( is_post_type_archive() ) {
				$collection_id = tainacan_get_collection_id();
				if ($collection_id) {
					$title = tainacan_get_the_collection_name();
					$img_info = ( has_post_thumbnail( tainacan_get_collection_id() ) ) ? wp_get_attachment_image_src( get_post_thumbnail_id( tainacan_get_collection_id() ), 'full' ) : $logo;
					$url_src = home_url( $wp->request );
					$excerpt = strip_tags(tainacan_get_the_collection_description());
				} elseif ( is_post_type_archive('tainacan-collection') ) {
					$title = __('Collections', 'tainacan');
				}
			} elseif ( is_singular() ) {
				global $post;

				if ( !is_object($post) ) { return; }

				$title = get_the_title();
				$img_info = ( has_post_thumbnail( $post->ID ) ) ? wp_get_attachment_image_src( get_post_thumbnail_id( $post->ID ), 'full' ) : $logo;
				$url_src = get_permalink();
				$content = wp_trim_words( $post->post_content, 28, '[...]' );
				if ( $content ) {
					$excerpt = strip_tags( $content );
					$excerpt = str_replace( '', "'", $excerpt );
				} 
			} elseif ( is_tax() ) {
				$term = get_queried_object();
				$tainacan_term = tainacan_get_term();
				
				$title = $term->name;
				$excerpt = strip_tags($term->description);
				
				$url_src = get_term_link($term->term_id, $term->taxonomy);

				if ($tainacan_term) {
					$_term = new \Tainacan\Entities\Term( $tainacan_term );
					$img_id = $_term->get_header_image_id();
					if ($img_id) {
						$img_info = wp_get_attachment_image_src( $img_id, 'full' );
					}
				}
				
			} else {
				
				if ( is_day() ) :
					$title =  sprintf( __( 'Daily Archives: %s', 'tainacan-interface' ), get_the_date() );
				elseif ( is_month() ) :
					$title =  sprintf( __( 'Monthly Archives: %s', 'tainacan-interface' ), get_the_date( _x( 'F Y', 'monthly archives date format', 'tainacan-interface' ) ) );
				elseif ( is_year() ) :
					$title =  sprintf( __( 'Yearly Archives: %s', 'tainacan-interface' ), get_the_date( _x( 'Y', 'yearly archives date format', 'tainacan-interface' ) ) );
				elseif ( is_author() ) :
					$title = get_the_author();
				else :
					$title = get_the_archive_title();
				endif;
				
			}

			$image = array(
			   'url' => ( ! empty( $img_info[0] ) && $img_info[1] >= 200 && $img_info[2] >= 200 ) ? $img_info[0] : $logo,
			   'width' => ( ! empty( $img_info[1] ) && $img_info[1] >= 200 && $img_info[2] >= 200 ) ? $img_info[1] : 200,
			   'height' => ( ! empty( $img_info[2] ) && $img_info[1] >= 200 && $img_info[2] >= 200 ) ? $img_info[2] : 200,
			);

			?>
			<meta property="og:type" content="article"/>
			<meta property="og:title" content="<?php echo $title; ?>"/>
			<meta property="og:site_name" content="<?php echo get_bloginfo(); ?>"/>
			<meta property="og:description" content="<?php echo $excerpt; ?>"/>
			<meta property="og:url" content="<?php echo $url_src; ?>"/>
			<meta property="og:image" content="<?php echo $image['url']; ?>"/>
			<meta property="og:image:width" content="<?php echo $image['width']; ?>"/>
			<meta property="og:image:height" content="<?php echo $image['height']; ?>"/>


		<?php } else { return; } // End if().
	}


	/**
	 * Get previous and next item according to current search query
	 * 
	 * @param integer $index the position of the item in the current list. This should be added to pagination.
	 * 
	 * @return array containing the next and previous item
	 */
	public function get_adjacent_items() {

		// Array with the link results. If nothing goes well here we just don't have any link :(
		$adjacent_items = [
			'next' => null,
			'previous' => null
		];

		// Adjusts the args to obtain only on one item per request with the correct offset
		$args = $_GET;
		
		// Defines where are we getting items from
		$entity = [];
		if (isset($args['source_list']) && $args['source_list'] == 'collection' && $collection_id = tainacan_get_collection_id()) {
			$entity = \Tainacan\Repositories\Collections::get_instance()->fetch($collection_id);
		}
		unset($args['source_list']);

		if (isset($args['pos'])) {

			// Sets Page based on position
			$args['perpage'] = '1';
			$current_position = (int)$args['pos'] + 1;
			unset($args['pos']);
			$args = (new \Tainacan\API\EndPoints\REST_Items_Controller())->process_request_filters($args);

			// Fetch Previous Item
			if($current_position > 1) {
				$args['paged'] = $current_position - 1;
				$items = \Tainacan\Repositories\Items::get_instance()->fetch($args, $entity, 'WP_Query');
				
				if ($items->have_posts()) {
					$items->the_post();
					$item = new Entities\Item($items->post);

					if (!empty($item)  && $item instanceof \Tainacan\Entities\Item) {
						$adjacent_items['previous'] = [
							'url' => get_permalink( $item->get_id() ) . '?' . http_build_query(array_merge($_GET, ['pos'=> $current_position-2])),
							'title' => $item->get_title(),
							'thumbnail' => $item->get_thumbnail()
						];
					}
					\wp_reset_postdata();
				}
			}

			// Fetch Next Item
			$args['paged'] = $current_position + 1;
			$items = \Tainacan\Repositories\Items::get_instance()->fetch($args, $entity, 'WP_Query');

			if ($items->have_posts()) {
				$items->the_post();
				$item = new Entities\Item($items->post);

				if (!empty($item) && $item instanceof \Tainacan\Entities\Item) {
					$adjacent_items['next'] = [
						'url' => get_permalink( $item->get_id() ) . '?' . http_build_query(array_merge($_GET, ['pos'=> $current_position])),
						'title' => $item->get_title(),
						'thumbnail' => $item->get_thumbnail()
					];
				}
				\wp_reset_postdata();
			}
		}

		return $adjacent_items;
	}
	
}

