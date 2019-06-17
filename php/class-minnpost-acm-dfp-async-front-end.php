<?php
/**
 * Class file for the MinnPost_ACM_DFP_Async_Front_End class.
 *
 * @file
 */

class MinnPost_ACM_DFP_Async_Front_End {

	public $option_prefix;
	public $version;
	public $slug;
	public $capability;
	public $ad_code_manager;

	/**
	* Constructor which sets up ad panel
	*/
	public function __construct() {

		$this->option_prefix   = minnpost_acm_dfp_async()->option_prefix;
		$this->version         = minnpost_acm_dfp_async()->version;
		$this->slug            = minnpost_acm_dfp_async()->slug;
		$this->capability      = minnpost_acm_dfp_async()->capability;
		$this->ad_code_manager = minnpost_acm_dfp_async()->ad_code_manager;

		$this->paragraph_end = array(
			false => '</p>',
			true  => "\n",
		);

		$this->add_actions();

	}

	private function add_actions() {
		add_filter( 'acm_output_tokens', array( $this, 'acm_output_tokens' ), 15, 3 );
		add_filter( 'acm_output_html', array( $this, 'filter_output_html' ), -1, 2 );
		//add_filter( 'acm_display_ad_codes_without_conditionals', array( $this, 'check_conditionals' ) ); this is maybe not necessary
		add_filter( 'acm_conditional_args', array( $this, 'conditional_args' ), 10, 2 );

		// disperse shortcodes in the editor if the settings say to
		$show_in_editor = filter_var( get_option( $this->option_prefix . 'show_in_editor', false ), FILTER_VALIDATE_BOOLEAN );
		if ( true === $show_in_editor ) {
			//add_filter( 'content_edit_pre', array( $this, 'insert_inline_ad_in_editor' ), 10, 2 );
		}

		// always either replace the shortcodes with ads, or if they are absent disperse ad codes throughout the content
		add_shortcode( 'cms_ad', array( $this, 'render_shortcode' ) );
		add_filter( 'the_content', array( $this, 'insert_and_render_inline_ads' ), 2000 );
		add_filter( 'the_content_feed', array( $this, 'insert_and_render_inline_ads' ), 2000 );
		//add_action( 'wp_head', array( $this, 'action_wp_head' ) );

		// add javascript
		//add_action( 'wp_enqueue_scripts', array( $this, 'add_scripts' ), 20 );
	}

	public function acm_output_tokens( $output_tokens, $tag_id, $code_to_display ) {
		global $dfp_tile;
		global $dfp_ord;
		global $dfp_pos;
		global $dfp_dcopt;
		global $wp_query;

		//error_log( 'tile is ' . $dfp_tile . ' and ord is ' . $dfp_ord . ' and pos is ' . $dfp_pos . ' and dcopt is ' . $dfp_dcopt . ' and wp query is ' . print_r( $wp_query, true ) );

		//error_log( 'tag id is ' . $tag_id . ' and code to display is ' . print_r( $code_to_display, true ) );

		//if ( false === $dfp_pos[ $code_to_display['url_vars']['sz'] ] ) {
		if ( isset( $code_to_display['url_vars']['pos'] ) ) {
			$output_tokens['%pos%'] = $code_to_display['url_vars']['pos'];
		}
		//} else {
			//$output_tokens['%pos%'] = 'bottom';
		//}
		//$output_tokens['%test%'] = isset( $_GET['test'] ) && $_GET['test'] == 'on' ? 'on' : '';

		if ( isset( $output_tokens['%pos%'] ) ) {
			//error_log( 'output tokens is ' . print_r( $output_tokens, true ) );
		}

		return $output_tokens;
	}

	/**
	 * Filter the output HTML for each ad tag to produce the code we need
	 * @param string $output_html
	 * @param string $tag_id
	 *
	 * @return $output_html
	 * return filtered html for the ad code
	 */
	public function filter_output_html( $output_html, $tag_id ) {
		global $ad_code_manager;

		switch ( $tag_id ) {
			case 'dfp_head':
				$ad_tags = $ad_code_manager->ad_tag_ids;
				ob_start();
				?>
<!-- Include google_services.js -->
<script type='text/javascript'>
var googletag = googletag || {};
googletag.cmd = googletag.cmd || [];
(function() {
var gads = document.createElement('script');
gads.async = true;
gads.type = 'text/javascript';
var useSSL = 'https:' == document.location.protocol;
gads.src = (useSSL ? 'https:' : 'http:') +
'//www.googletagservices.com/tag/js/gpt.js';
var node = document.getElementsByTagName('script')[0];
node.parentNode.insertBefore(gads, node);
})();
</script>
<script type='text/javascript'>
googletag.cmd.push(function() {
				<?php
				foreach ( (array) $ad_tags as $tag ) :
					if ( 'dfp_head' === $tag['tag'] ) {
						continue;
					}

					$tt               = $tag['url_vars'];
					$matching_ad_code = $ad_code_manager->get_matching_ad_code( $tag['tag'] );
					if ( ! empty( $matching_ad_code ) ) {
						// @todo There might be a case when there are two tags registered with the same dimensions
						// and the same tag id ( which is just a div id ). This confuses DFP Async, so we need to make sure
						// that tags are unique

						// Parse ad tags to output flexible unit dimensions
						$unit_sizes = $this->parse_ad_tag_sizes( $tt );
						$pos        = '';
						if ( isset( $matching_ad_code['url_vars']['pos'] ) ) {
							$pos = ".setTargeting('pos', ['" . esc_attr( $matching_ad_code['url_vars']['pos'] ) . "'])";
						}

						?>
googletag.defineSlot('/<?php echo esc_attr( $matching_ad_code['url_vars']['dfp_id'] ); ?>/<?php echo esc_attr( $matching_ad_code['url_vars']['tag_name'] ); ?>', <?php echo json_encode( $unit_sizes ); ?>, "acm-ad-tag-<?php echo esc_attr( $matching_ad_code['url_vars']['tag_id'] ); ?>")<?php echo $pos; ?>.addService(googletag.pubads());
						<?php
					}
			endforeach;
				?>
googletag.pubads().enableSingleRequest();
googletag.pubads().collapseEmptyDivs();
googletag.enableServices();
});
</script>
				<?php

				$output_script = ob_get_clean();
				break;
			default:
				$matching_ad_code = $ad_code_manager->get_matching_ad_code( $tag_id );
				if ( ! empty( $matching_ad_code ) ) {
					$output_html = $this->get_code_to_insert( $tag_id );
				}
				return $output_html;
		}
		return $output_script;

	}

	/**
	 * Whether to show ads that don't have any conditionals
	 *
	 * @return bool
	 *
	 */
	public function check_conditionals() {
		$show_without_conditionals = filter_var( get_option( $this->option_prefix . 'show_ads_without_conditionals', false ), FILTER_VALIDATE_BOOLEAN );
		return $show_without_conditionals;
	}

	/**
	 * Additional arguments for conditionals
	 *
	 * @param array $args
	 * @param string $function
	 * @return array $args
	 *
	 */
	public function conditional_args( $args, $function ) {
		global $wp_query;
		// has_category and has_tag use has_term
		// we should pass queried object id for it to produce correct result

		if ( in_array( $function, array( 'has_category', 'has_tag' ) ) ) {
			if ( true === $wp_query->is_single ) {
				$args[] = $wp_query->queried_object->ID;
			}
			$args['is_singular'] = true;
		}
		return $args;
	}

	/**
	 * Make [cms_ad] a recognized shortcode
	 *
	 * @param array $atts
	 *
	 *
	 */
	public function render_shortcode( $atts ) {
		return;
	}

	/**
	 * Use one or more inline ads, depending on the settings. This does not place them into the post editor, but into the post when it renders.
	 *
	 * @param string $content
	 *
	 * @return $content
	 * return the post content with code for ads inside it at the proper places
	 *
	 */
	public function insert_and_render_inline_ads( $content = '' ) {
		if ( is_feed() ) {
			global $post;
			$current_object = $post;
		} else {
			$current_object = get_queried_object();
		}
		if ( is_object( $current_object ) ) {
			$post_type = isset( $current_object->post_type ) ? $current_object->post_type : '';
			$post_id   = isset( $current_object->ID ) ? $current_object->ID : '';
		} else {
			return $content;
		}
		$in_editor = false; // we are not in the editor right now

		// Should we skip rendering ads?
		$should_we_skip = $this->should_we_skip_ads( $content, $post_type, $post_id, $in_editor );
		$should_we_skip = false;
		if ( true === $should_we_skip ) {
			return $content;
		}

		// Render any `[cms_ad` shortcodes, whether they were manually added or added by this plugin
		// this should also be used to render the shortcodes added in the editor
		$shortcode = 'cms_ad';
		$pattern   = $this->get_single_shortcode_regex( $shortcode );
		if ( preg_match_all( $pattern, $content, $matches ) && array_key_exists( 2, $matches ) && in_array( $shortcode, $matches[2] ) ) {

			/*
			[0] => Array (
				[0] => [cms_ad:Middle]
			)

			[1] => Array(
				[0] =>
			)

			[2] => Array(
				[0] => cms_ad
			)

			[3] => Array(
				[0] => :Middle
			)
			*/

			foreach ( $matches[0] as $key => $value ) {
				$position  = ( isset( $matches[3][ $key ] ) && '' !== ltrim( $matches[3][ $key ], ':' ) ) ? ltrim( $matches[3][ $key ], ':' ) : get_option( $this->option_prefix . 'auto_embed_position', 'Middle' );
				$rewrite[] = $this->get_code_to_insert( $position );
				$matched[] = $matches[0][ $key ];
			}
			return str_replace( $matched, $rewrite, $content );
		}

		$ad_code_manager = $this->ad_code_manager;

		$content = $this->insert_ads_into_content( $content, false );
		return $content;

	}

	/**
	 * Place the ad code, or cms shortcode for the ad, into the post body as many times, and in the right location.
	 *
	 * @param string $content
	 * @param bool $in_editor
	 *
	 * @return $content
	 * return the post content with shortcodes for ads inside it at the proper places
	 *
	 */
	private function insert_ads_into_content( $content, $in_editor = false ) {
		$multiple_embeds = get_option( $this->option_prefix . 'multiple_embeds', '0' );
		if ( is_array( $multiple_embeds ) ) {
			$multiple_embeds = $multiple_embeds[0];
		}

		$end      = strlen( $content );
		$position = $end;

		$paragraph_end = $this->paragraph_end[ $in_editor ];

		if ( '1' === $multiple_embeds ) {

			$insert_every_paragraphs = intval( get_option( $this->option_prefix . 'insert_every_paragraphs', 4 ) );
			$minimum_paragraph_count = intval( get_option( $this->option_prefix . 'minimum_paragraph_count', 6 ) );

			$embed_prefix      = get_option( $this->option_prefix . 'embed_prefix', 'x' );
			$start_embed_id    = get_option( $this->option_prefix . 'start_tag_id', 'x100' );
			$start_embed_count = intval( str_replace( $embed_prefix, '', $start_embed_id ) ); // ex 100
			$end_embed_id      = get_option( $this->option_prefix . 'end_tag_id', 'x110' );
			$end_embed_count   = intval( str_replace( $embed_prefix, '', $end_embed_id ) ); // ex 110

			$paragraphs = [];
			$split      = explode( $paragraph_end, $content );
			foreach ( $split as $paragraph ) {
				// filter out empty paragraphs
				if ( strlen( $paragraph ) > 3 ) {
					$paragraphs[] = $paragraph . $paragraph_end;
				}
			}

			$paragraph_count = count( $paragraphs );
			$maximum_ads     = floor( ( $paragraph_count - $minimum_paragraph_count ) / $insert_every_paragraphs ) + $minimum_paragraph_count;

			$ad_num      = 0;
			$counter     = $minimum_paragraph_count;
			$embed_count = $start_embed_count;

			for ( $i = 0; $i < $paragraph_count; $i++ ) {
				if ( 0 === $counter && $embed_count <= $end_embed_count ) {
					// make a shortcode using the number of the shorcode that will be added.
					if ( false === $in_editor ) {
						$shortcode = $this->get_code_to_insert( $embed_prefix . (int) $embed_count );
					} elseif ( true === $in_editor ) {
						$shortcode = "\n" . '[cms_ad:' . $embed_prefix . (int) $embed_count . ']' . "\n\n";
					}
					$otherblocks = '(?:div|dd|dt|li|pre|fieldset|legend|figcaption|details|thead|tfoot|tr|td|style|script|link|h1|h2|h3|h4|h5|h6)';
					if ( preg_match( '!(<' . $otherblocks . '[\s/>])!', $paragraphs[ $i ], $m ) ) {
						continue;
					}
					array_splice( $paragraphs, $i + $ad_num, 0, $shortcode );
					$counter = $insert_every_paragraphs;
					$ad_num++;
					if ( $ad_num > $maximum_ads ) {
						break;
					}
					$embed_count++;
				}
				$counter--;
			}

			if ( true === $in_editor ) {
				$content = implode( $paragraph_end, $paragraphs );
			} else {
				$content = implode( '', $paragraphs );
			}
		} else {
			$tag_id        = get_option( $this->option_prefix . 'auto_embed_position', 'Middle' );
			$top_offset    = get_option( $this->option_prefix . 'auto_embed_top_offset', 1000 );
			$bottom_offset = get_option( $this->option_prefix . 'auto_embed_bottom_offset', 400 );

			// if the content is longer than the minimum ad spot find a break.
			// otherwise place the ad at the end
			if ( $position > $top_offset ) {
				// find the break point
				$breakpoints = array(
					'</p>'             => 4,
					'<br />'           => 6,
					'<br/>'            => 5,
					'<br>'             => 4,
					'<!--pagebreak-->' => 0,
					'<p>'              => 0,
					"\n"               => 2,
				);
				// We use strpos on the reversed needle and haystack for speed.
				foreach ( $breakpoints as $point => $offset ) {
					$length = stripos( $content, $point, $top_offset );
					if ( false !== $length ) {
						$position = min( $position, $length + $offset );
					}
				}
			}
			if ( false === $in_editor ) {
				// If the position is at or near the end of the article.
				if ( $position > $end - $bottom_offset ) {
					$position  = $end;
					$shortcode = $this->get_code_to_insert( $tag_id, 'minnpost-ads-ad-article-end' );
				} else {
					$shortcode = $this->get_code_to_insert( $tag_id, 'minnpost-ads-ad-article-middle' );
				}
			} else {
				$shortcode = "\n" . '[cms_ad:' . $tag_id . ']' . "\n\n";
			}

			$content = substr_replace( $content, $shortcode, $position, 0 );
		}

		return $content;
	}

	/**
	 * Get ad code to insert for a given tag.
	 *
	 * @param string $tag_id
	 * @param string $class
	 *
	 * @return $output_html
	 * return the necessary ad code for the specified tag type
	 *
	 */
	public function get_code_to_insert( $tag_id, $class = '' ) {
		// get the code to insert
		$ad_code_manager  = $this->ad_code_manager;
		$ad_tags          = $ad_code_manager->ad_tag_ids;
		$matching_ad_code = $ad_code_manager->get_matching_ad_code( $tag_id );

		$output_html = '
		<div id="acm-ad-tag-' . $matching_ad_code['url_vars']['tag_id'] . '" style="width:%width%px; height:%height%px;">
			<script>
				googletag.cmd.push(
					function() {
						googletag.display("' . $matching_ad_code['url_vars']['tag_id'] . '");
					}
				);
			</script>
		</div>';

		// use the function we already have for the placeholder ad
		if ( function_exists( 'acm_no_ad_users' ) ) {
			if ( ! isset( $output_html ) ) {
				$output_html = '';
			}
			$output_html = acm_no_ad_users( $output_html, $tag_id );
		}

		return $output_html;
	}

	/**
	* Enqueue JavaScript for front end
	*
	*/
	public function add_scripts() {
		if ( true === $this->lazy_load_all || true === $this->lazy_load_embeds ) {
			// allow individual posts to disable lazyload. this can be useful in the case of unresolvable javascript conflicts.
			if ( is_singular() ) {
				global $post;
				if ( get_post_meta( $post->ID, 'wp_lozad_lazyload_prevent_lozad_lazyload', true ) ) {
					return;
				}
			}
			wp_add_inline_script(
				'lozad',
				"
				if (typeof lozad != 'undefined') {
					window.addEventListener('load', function() {
						var observer = lozad('.lozad', {
							rootMargin: '300px 0px',
						    load: function(el) {
						        postscribe(el, '<script src=' + el.getAttribute('data-src') + '><\/script>');
						    }
						});
						observer.observe();
					});
				}
				"
			);
		}
	}

	/**
	 * Get regular expression for a specific shortcode
	 *
	 * @param string $shortcode
	 * @return string $regex
	 *
	 */
	private function get_single_shortcode_regex( $shortcode ) {
		// The  $shortcode_tags global variable contains all registered shortcodes.
		global $shortcode_tags;

		// Store the shortcode_tags global in a temporary variable.
		$temp_shortcode_tags = $shortcode_tags;

		// Add only one specific shortcode name to the $shortcode_tags global.
		//
		// Replace 'related_posts_by_tax' with the shortcode you want to get the regex for.
		// Don't include the brackets from a shortcode.
		$shortcode_tags = array( $shortcode => '' );

		// Create the regex for your shortcode.
		$regex = '/' . get_shortcode_regex() . '/s';

		// Restore the $shortcode_tags global.
		$shortcode_tags = $temp_shortcode_tags;

		// Print the regex.
		return $regex;
	}

	/**
	 * Insert one or more inline ads into the post editor, depending on the settings. Editors can then rearrange them as desired.
	 *
	 * @param string $content
	 * @param int $post_id
	 *
	 * @return $content
	 * return the post content into the editor with shortcodes for ads inside it at the proper places
	 *
	 */
	public function insert_inline_ad_in_editor( $content = '', $post_id ) {

		/*
		// todo: i think this would be nice, but i think it won't work like this
		$user_id = get_current_user_id();
		if ( ! user_can( $user_id, $this->capability ) ) {
			return $content;
		}*/

		$post_type = get_post_type( $post_id );
		$in_editor = true;

		// should we skip rendering ads?
		$should_we_skip = $this->should_we_skip_ads( $content, $post_type, $post_id, $in_editor );
		if ( true === $should_we_skip ) {
			return $content;
		}

		$ad_code_manager = $this->ad_code_manager;

		$content = $this->insert_ads_into_content( $content, true );
		return $content;

	}

	/**
	 * Determine whether the current post should get automatic ad insertion.
	 *
	 * @param string $content
	 * @param string $post_type
	 * @param int $post_id
	 * @param bool $in_editor
	 *
	 * @return bool
	 * return true to skip rendering ads, false otherwise
	 *
	 */
	private function should_we_skip_ads( $content, $post_type, $post_id, $in_editor ) {

		// This is on the story, so we can access the loop
		if ( false === $in_editor ) {
			// Stop if this is not being called In The Loop.
			if ( ! in_the_loop() || ! is_main_query() ) {
				return true;
			}
			if ( ! is_single() && ! is_feed() ) {
				return true;
			}
		} else {
			// Check that there isn't a line starting with `[cms_ad` already.
			// If there is, stop adding automatic short code(s). Assume the user is doing it manually.
			if ( false !== stripos( $content, '[cms_ad' ) || false !== stripos( $content, '<img class="mceItem mceAdShortcode' ) ) {
				return true;
			}
		}

		// Don't add ads if this post is not a supported type
		$post_types = get_option( $this->option_prefix . 'post_types', array( 'post' ) );
		if ( ! in_array( $post_type, $post_types ) ) {
			return true;
		}

		// If this post has the option set to not add automatic ads, do not add them to the editor view. If we're not in the editor, ignore this value because they would be manually added at this point.
		// This field name is stored in the plugin options.
		$field_automatic_name  = get_option( $this->option_prefix . 'prevent_automatic_ads_field', '_post_prevent_appnexus_ads' );
		$field_automatic_value = get_option( $this->option_prefix . 'prevent_automatic_ads_field_value', 'on' );
		if ( true === $in_editor && get_post_meta( $post_id, $field_automatic_name, true ) === $field_automatic_value ) {
			return true;
		}

		// If this post has that option set to not add automatic ads, skip them in the front end view unless they have been manually added.
		if ( false === $in_editor && get_post_meta( $post_id, $field_automatic_name, true ) === $field_automatic_value && false === stripos( $content, '[cms_ad' ) && false === stripos( $content, '<img class="mceItem mceAdShortcode' ) ) {
			return true;
		}

		// allow developers to prevent automatic ads
		$prevent_automatic_ads = apply_filters( $this->option_prefix . 'prevent_automatic_ads', false, $post_id );
		if ( true === $prevent_automatic_ads ) {
			return true;
		}

		// Stop if this post has the option set to not add any ads.
		// This field name is stored in the plugin options.
		$field_name  = get_option( $this->option_prefix . 'prevent_ads_field', '_post_prevent_appnexus_ads' );
		$field_value = get_option( $this->option_prefix . 'prevent_ads_field_value', 'on' );
		if ( get_post_meta( $post_id, $field_name, true ) === $field_value ) {
			return true;
		}

		// allow developers to prevent ads
		$prevent_ads = apply_filters( $this->option_prefix . 'prevent_ads', false, $post_id );
		if ( true === $prevent_ads ) {
			return true;
		}

		// If we don't have any paragraphs, let's skip the ads for this post
		if ( ! stripos( $content, $this->paragraph_end[ $in_editor ] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Allow ad sizes to be defined as arrays or as basic width x height.
	 * The purpose of this is to solve for flex units, where multiple ad
	 * sizes may be required to load in the same ad unit.
	 */
	public function parse_ad_tag_sizes( $url_vars ) {
		if ( empty( $url_vars ) ) 
			return;

		$unit_sizes_output = '';
		if ( ! empty( $url_vars['sizes'] ) ) {
			$unit_sizes_output = array();
			foreach( $url_vars['sizes'] as $unit_size ) {
				$unit_sizes_output[] = array(
					(int) $unit_size['width'],
					(int) $unit_size['height'],
				);
			}			
		} else { // fallback for old style width x height
			$unit_sizes_output = array(
				(int) $url_vars['width'],
				(int) $url_vars['height'],
			);
		}
		return $unit_sizes_output;
	}

}
