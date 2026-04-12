<?php
/**
 * Plugin Name: WebPrinter Engine
 * Description: Smart template engine — deploys contractor demo sites from n8n with variable-length content.
 * Version:     5.1
 * Author:      Team Platypus
 *
 * REQUIRES in wp-config.php:
 *   define( 'WP_TEMPLATE_BASE', 'https://raw.githubusercontent.com/SiteHypeInc/WebPrinterBoltjsonPHP/main' );
 *
 * v5 UPGRADE NOTES:
 *   - Accepts structured JSON payload (nested objects, arrays)
 *   - _wp_repeat: clones elements per array item (services, testimonials, etc.)
 *   - _wp_repeat inside widget settings: handles icon-list, price-table, slides, etc.
 *   - _wp_if: conditionally includes/removes sections
 *   - {{dot.notation}} tokens resolve from nested payload
 *   - Backward compatible with [BRACKET TOKEN] format
 *   - Expanded design token support (full palette, fonts)
 *   - Elementor element IDs auto-regenerated on clones
 *   - Graceful pass-through: unknown markers are ignored, never errors
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// PHP 8.1 polyfill for older hosts
if ( ! function_exists( 'array_is_list' ) ) {
    function array_is_list( array $array ): bool {
        if ( $array === [] ) return true;
        return array_keys( $array ) === range( 0, count( $array ) - 1 );
    }
}

class WebPrinter_Engine {

    const VERSION = '5.1';

    /**
     * Image slot keys -> payload paths.
     * Supports dot notation for nested payload: "images.hero.url"
     */
    const IMAGE_SLOTS = [
        'hero'    => 'images.hero',
        'about'   => 'images.about',
        'service' => 'images.service',
        'logo'    => 'images.logo',
        'gallery' => 'images.gallery',
    ];

    /** Flattened payload data — built once per deploy. */
    private array $payload = [];

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        register_rest_route( 'webprinter/v1', '/deploy', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_deploy' ],
            'permission_callback' => [ $this, 'check_auth' ],
        ] );
        register_rest_route( 'webprinter/v1', '/setup-breeze', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_setup_breeze' ],
            'permission_callback' => [ $this, 'check_auth' ],
        ] );
        register_rest_route( 'webprinter/v1', '/health', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_health' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function handle_health( WP_REST_Request $request ) {
        return new WP_REST_Response( [
            'status'  => 'ok',
            'version' => self::VERSION,
            'ts'      => time(),
        ], 200 );
    }

    /**
     * Breeze cache exclusion — unchanged from v4.7.
     */
    public function handle_setup_breeze( WP_REST_Request $request ) {
        if ( ! is_multisite() ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Not multisite' ], 400 );
        }

        $params   = $request->get_json_params() ?: $request->get_params();
        $blog_ids = array_map( 'intval', $params['blog_ids'] ?? [] );
        $paths    = array_map( 'sanitize_text_field', $params['paths'] ?? [] );

        if ( empty( $blog_ids ) || empty( $paths ) ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'blog_ids and paths required' ], 400 );
        }

        $results = [];
        foreach ( $blog_ids as $blog_id ) {
            switch_to_blog( $blog_id );
            $settings = get_option( 'breeze_basic_settings', [] );
            if ( ! is_array( $settings ) ) $settings = [];
            $existing   = isset( $settings['breeze-exclude-urls'] ) ? (array) $settings['breeze-exclude-urls'] : [];
            $merged     = array_values( array_unique( array_merge( $existing, $paths ) ) );
            $settings['breeze-exclude-urls'] = $merged;
            update_option( 'breeze_basic_settings', $settings );
            do_action( 'breeze_clear_all_cache' );
            if ( class_exists( 'Breeze_PurgeCache' ) ) {
                Breeze_PurgeCache::breeze_cache_flush();
            }
            restore_current_blog();
            $results[ $blog_id ] = [ 'excluded_paths' => $merged ];
        }

        return new WP_REST_Response( [ 'success' => true, 'results' => $results ], 200 );
    }

    public function check_auth( WP_REST_Request $request ) {
        $key = defined( 'WP_WEBPRINTER_KEY' ) ? WP_WEBPRINTER_KEY : '';
        if ( empty( $key ) ) return true;
        return $request->get_header( 'X-Webprinter-Key' ) === $key;
    }

    // =================================================================
    // MAIN DEPLOY HANDLER
    // =================================================================

    public function handle_deploy( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        if ( empty( $params ) ) $params = $request->get_params();

        // Store full payload for dot-notation resolution
        $this->payload = $params;

        // -----------------------------------------------------------
        // 1. VALIDATE
        // -----------------------------------------------------------
        $required = [ 'blog_id', 'business_name', 'industry' ];
        foreach ( $required as $field ) {
            $val = $this->resolve( $field );
            if ( empty( $val ) ) {
                // Backward compat: check v4 field names
                $compat = [ 'business_name' => 'company_name', 'industry' => 'trade' ];
                $alt = $compat[$field] ?? null;
                if ( ! $alt || empty( $this->resolve( $alt ) ) ) {
                    return new WP_REST_Response([
                        'success' => false,
                        'error'   => "Missing required field: {$field}",
                    ], 400 );
                }
            }
        }

        $blog_id  = intval( $params['blog_id'] );
        $template = sanitize_text_field( $params['template'] ?? 'bold-v2' );

        // Normalize v4 field names to v5 if needed
        $this->normalize_payload_compat();

        // -----------------------------------------------------------
        // 2. TEMPLATE BASE URL
        // -----------------------------------------------------------
        if ( ! defined( 'WP_TEMPLATE_BASE' ) ) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'WP_TEMPLATE_BASE is not defined in wp-config.php',
            ], 500 );
        }
        $template_base = rtrim( WP_TEMPLATE_BASE, '/' ) . '/' . $template . '/';

        // -----------------------------------------------------------
        // 3. SWITCH TO TARGET SUBSITE
        // -----------------------------------------------------------
        if ( is_multisite() ) {
            if ( ! get_blog_details( $blog_id ) ) {
                return new WP_REST_Response([
                    'success' => false,
                    'error'   => "Blog ID {$blog_id} not found on this network.",
                ], 404 );
            }
            switch_to_blog( $blog_id );
        }

        // -----------------------------------------------------------
        // 3a. BLOG NAME + FRONT PAGE
        // -----------------------------------------------------------
        // Unhook Elementor Kit Manager — it fires on updated_option and
        // throws "Access denied" in REST context (no nonce/ajax caps).
        global $wp_filter;
        $saved_updated_option = null;
        if ( isset( $wp_filter['updated_option'] ) ) {
            $saved_updated_option = clone $wp_filter['updated_option'];
            remove_all_filters( 'updated_option' );
        }

        $business_name = sanitize_text_field( $this->resolve( 'business_name' ) );
        update_option( 'blogname', $business_name );
        wp_cache_delete( 'blogname', 'options' );
        update_option( 'show_on_front', 'page' );
        update_option( 'page_on_front', 6 );

        // Restore hooks.
        if ( $saved_updated_option ) {
            $wp_filter['updated_option'] = $saved_updated_option;
        }

        // -----------------------------------------------------------
        // 3b. NAVIGATION MENU
        // -----------------------------------------------------------
        $this->setup_navigation();

        // -----------------------------------------------------------
        // 3c. DESIGN TOKENS → ELEMENTOR KIT
        // -----------------------------------------------------------
        $this->apply_design_tokens( $template );

        // -----------------------------------------------------------
        // 3d. CLEAR ALL ELEMENTOR CSS ONCE
        // -----------------------------------------------------------
        if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }

        // -----------------------------------------------------------
        // 4. SIDELOAD IMAGES
        // -----------------------------------------------------------
        $images = $this->process_images();

        // -----------------------------------------------------------
        // 5. BUILD TOKEN MAP (backward-compat bracket tokens)
        // -----------------------------------------------------------
        $replacements = $this->build_bracket_replacements();

        // 5b. Add image URL tokens for custom_css hero/about/service/logo
        $replacements['[HERO_IMAGE_URL]']    = ! empty( $images['hero']['url'] )    ? $images['hero']['url']    : '';
        $replacements['[ABOUT_IMAGE_URL]']   = ! empty( $images['about']['url'] )   ? $images['about']['url']   : '';
        $replacements['[SERVICE_IMAGE_URL]'] = ! empty( $images['service']['url'] ) ? $images['service']['url'] : '';
        $replacements['[LOGO_URL]']          = ! empty( $images['logo']['url'] )    ? $images['logo']['url']    : '';

        // -----------------------------------------------------------
        // 6. DEPLOY PAGES
        // -----------------------------------------------------------
        $pages = [
            'home'     => 'home.json',
            'about'    => 'about.json',
            'services' => 'services.json',
            'quote'    => 'quote.json',
            'contact'  => 'contact.json',
        ];

        $results = [];
        $errors  = [];

        foreach ( $pages as $slug => $file ) {
            $result = $this->deploy_page( $slug, $template_base . $file, $replacements, $images );
            if ( is_wp_error( $result ) ) {
                $errors[$slug] = $result->get_error_message();
            } else {
                $results[$slug] = $result;
            }
        }

        // -----------------------------------------------------------
        // 7. HEADER + FOOTER
        // -----------------------------------------------------------
        foreach ( [ 'header' => 'header.json', 'footer' => 'footer.json' ] as $label => $file ) {
            $result = $this->deploy_library_template( $label, $template_base . $file, $replacements, $images );
            if ( ! is_wp_error( $result ) ) {
                $results[$label] = 'updated';
            } else {
                $errors[$label] = $result->get_error_message();
            }
        }

        // -----------------------------------------------------------
        // 8. FLUSH CACHES + WARM
        // -----------------------------------------------------------
        do_action( 'breeze_clear_all_cache' );
        if ( class_exists( 'Breeze_PurgeCache' ) ) {
            Breeze_PurgeCache::breeze_cache_flush();
        }

        $deployed_site_url = is_multisite() ? get_blog_option( $blog_id, 'siteurl' ) : get_option( 'siteurl' );
        if ( is_multisite() ) restore_current_blog();

        if ( ! empty( $deployed_site_url ) ) {
            wp_remote_get( trailingslashit( $deployed_site_url ), [
                'timeout'   => 15,
                'headers'   => [ 'Cache-Control' => 'no-cache, no-store, must-revalidate' ],
                'sslverify' => false,
                'blocking'  => false,
            ] );
        }

        $success = empty( $errors );

        return new WP_REST_Response([
            'success'  => $success,
            'blog_id'  => $blog_id,
            'template' => $template,
            'company'  => $business_name,
            'updated'  => $results,
            'errors'   => $errors,
            'image_ids'=> array_map( fn( $img ) => $img['id'] ?? 0, $images ),
            'version'  => self::VERSION,
        ], $success ? 200 : 207 );
    }

    // =================================================================
    // v5: PAYLOAD RESOLUTION (dot notation)
    // =================================================================

    /**
     * Resolve a dot-notation path from the payload.
     * "contact.phone" → $payload['contact']['phone']
     * "services.0.name" → $payload['services'][0]['name']
     */
    private function resolve( string $path, $default = '' ) {
        $keys = explode( '.', $path );
        $value = $this->payload;
        foreach ( $keys as $key ) {
            if ( is_array( $value ) && array_key_exists( $key, $value ) ) {
                $value = $value[$key];
            } elseif ( is_array( $value ) && is_numeric( $key ) && array_key_exists( (int) $key, $value ) ) {
                $value = $value[(int) $key];
            } else {
                return $default;
            }
        }
        return $value;
    }

    /**
     * Map v4 flat field names to v5 nested structure so old n8n
     * workflows keep working without changes.
     */
    private function normalize_payload_compat() {
        $p = &$this->payload;

        // business_name ← company_name
        if ( empty( $p['business_name'] ) && ! empty( $p['company_name'] ) ) {
            $p['business_name'] = $p['company_name'];
        }

        // industry ← trade
        if ( empty( $p['industry'] ) && ! empty( $p['trade'] ) ) {
            $p['industry'] = $p['trade'];
        }

        // contact object ← flat fields
        if ( ! isset( $p['contact'] ) ) {
            $p['contact'] = [];
        }
        if ( empty( $p['contact']['phone'] ) && ! empty( $p['phone'] ) ) {
            $p['contact']['phone'] = $p['phone'];
        }
        if ( empty( $p['contact']['email'] ) && ! empty( $p['email'] ) ) {
            $p['contact']['email'] = $p['email'];
        }
        if ( empty( $p['contact']['address'] ) && ! empty( $p['address'] ) ) {
            $p['contact']['address'] = [ 'street' => $p['address'] ];
        }
        if ( ! isset( $p['contact']['address']['city'] ) && ! empty( $p['city'] ) ) {
            $p['contact']['address']['city']  = $p['city'];
            $p['contact']['address']['state'] = $p['state'] ?? '';
        }

        // services array ← flat service_N_name / service_N_desc
        if ( empty( $p['services'] ) ) {
            $services = [];
            for ( $i = 1; $i <= 12; $i++ ) {
                $name = $p["service_{$i}_name"] ?? '';
                if ( ! empty( $name ) ) {
                    $services[] = [
                        'name'        => $name,
                        'description' => $p["service_{$i}_desc"] ?? '',
                    ];
                }
            }
            if ( ! empty( $services ) ) {
                $p['services'] = $services;
            }
        }

        // testimonials array ← flat testimonial_N_text / name / title
        if ( empty( $p['testimonials'] ) ) {
            $testimonials = [];
            for ( $i = 1; $i <= 6; $i++ ) {
                $text = $p["testimonial_{$i}_text"] ?? '';
                if ( ! empty( $text ) ) {
                    $testimonials[] = [
                        'quote'  => $text,
                        'author' => $p["testimonial_{$i}_name"]  ?? 'Satisfied Customer',
                        'role'   => $p["testimonial_{$i}_title"] ?? '',
                    ];
                }
            }
            if ( ! empty( $testimonials ) ) {
                $p['testimonials'] = $testimonials;
            }
        }

        // process steps array ← flat process_N_title / desc
        if ( empty( $p['process_steps'] ) ) {
            $steps = [];
            for ( $i = 1; $i <= 6; $i++ ) {
                $title = $p["process_{$i}_title"] ?? '';
                if ( ! empty( $title ) ) {
                    $steps[] = [
                        'title'       => $title,
                        'description' => $p["process_{$i}_desc"] ?? '',
                    ];
                }
            }
            if ( ! empty( $steps ) ) {
                $p['process_steps'] = $steps;
            }
        }

        // images ← flat image URLs
        if ( ! isset( $p['images'] ) ) $p['images'] = [];
        $img_compat = [
            'hero_image_url'    => 'hero',
            'about_image_url'   => 'about',
            'service_image_url' => 'service',
            'logo_url'          => 'logo',
        ];
        foreach ( $img_compat as $old_key => $new_slot ) {
            if ( ! empty( $p[$old_key] ) && empty( $p['images'][$new_slot] ) ) {
                $p['images'][$new_slot] = [ 'url' => $p[$old_key] ];
            }
        }
    }

    // =================================================================
    // v5: SMART TEMPLATE PROCESSING
    // =================================================================

    /**
     * Build Elementor JSON data from template + payload.
     *
     * Processing order:
     *   1. Parse template JSON
     *   2. Process _wp_if (conditional sections)
     *   3. Process _wp_repeat (clone elements per array)
     *   4. Process _wp_img (image injection — from v4)
     *   5. Inject bracket tokens [LIKE THIS] (backward compat)
     *   6. Inject dot tokens {{like.this}} (v5)
     */
    private function build_elementor_data( string $body, string $source_url, array $replacements, array $images ) {
        $template_data = json_decode( $body, true );
        if ( ! isset( $template_data['content'] ) ) {
            return new WP_Error( 'invalid_template', "Template has no 'content' key: {$source_url}" );
        }

        $elements = $template_data['content'];

        // Step 1: Conditional sections — remove elements where data is missing
        $elements = $this->process_conditionals( $elements );

        // Step 2a: Repeatable sections — clone elements per array item
        $elements = $this->process_repeats( $elements );

        // Step 2b: Widget-internal array repeats (icon_list, price_table, etc.)
        $elements = $this->process_settings_repeats( $elements );

        // Step 3: Image injection (unchanged from v4, works recursively)
        $elements = $this->apply_image_overrides( $elements, $images );

        // Step 4: Token replacement (both formats)
        $json = wp_json_encode( $elements, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

        // v4 bracket tokens: [COMPANY NAME] etc.
        $json = $this->inject_tokens( $json, $replacements );

        // v5 dot tokens: {{business_name}}, {{contact.phone}}, etc.
        $json = $this->inject_dot_tokens( $json );

        return $json;
    }

    /**
     * _wp_if: Conditionally include/exclude elements.
     *
     * Template element has: "settings": { "_wp_if": "testimonials" }
     * → Element is KEPT if $payload['testimonials'] is non-empty.
     * → Element is REMOVED (returns null) if empty/missing.
     *
     * Supports negation: "_wp_if": "!team" → keep if team is empty.
     */
    private function process_conditionals( array $elements ): array {
        $filtered = [];

        foreach ( $elements as $element ) {
            if ( ! is_array( $element ) ) {
                $filtered[] = $element;
                continue;
            }

            $condition = $element['settings']['_wp_if'] ?? null;

            if ( $condition !== null ) {
                $negate = false;
                if ( str_starts_with( $condition, '!' ) ) {
                    $negate = true;
                    $condition = substr( $condition, 1 );
                }

                $value   = $this->resolve( $condition );
                $present = ! empty( $value );
                $keep    = $negate ? ! $present : $present;

                if ( ! $keep ) {
                    continue; // Remove this element entirely
                }

                // Clean up the marker so Elementor doesn't choke
                unset( $element['settings']['_wp_if'] );
            }

            // Recurse into children
            if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $element['elements'] = $this->process_conditionals( $element['elements'] );
            }

            $filtered[] = $element;
        }

        return $filtered;
    }

    /**
     * _wp_repeat: Clone an element once per array item.
     *
     * Template element has:
     *   "settings": {
     *     "_wp_repeat": "services",
     *     "_wp_repeat_min": 3,    // optional: pad with defaults if fewer
     *     "_wp_repeat_max": 8     // optional: cap at this many
     *   }
     *
     * Inside the element, use tokens like {{services._item.name}}
     * which resolve to the current array item's 'name' field.
     *
     * The element is cloned N times. Each clone gets:
     *   - A unique Elementor element ID
     *   - Its own token context (the current array item)
     *   - Column widths recalculated if inside a container
     */
    private function process_repeats( array $elements ): array {
        $result = [];

        foreach ( $elements as $element ) {
            if ( ! is_array( $element ) ) {
                $result[] = $element;
                continue;
            }

            $repeat_key = $element['settings']['_wp_repeat'] ?? null;

            if ( $repeat_key !== null ) {
                $items     = $this->resolve( $repeat_key, [] );
                $min       = intval( $element['settings']['_wp_repeat_min'] ?? 0 );
                $max       = intval( $element['settings']['_wp_repeat_max'] ?? 12 );

                if ( ! is_array( $items ) ) $items = [];

                // Pad to minimum with empty items if needed
                while ( count( $items ) < $min ) {
                    $items[] = [];
                }

                // Cap at maximum
                $items = array_slice( $items, 0, $max );

                // Clean up markers
                unset(
                    $element['settings']['_wp_repeat'],
                    $element['settings']['_wp_repeat_min'],
                    $element['settings']['_wp_repeat_max']
                );

                // Clone for each item
                foreach ( $items as $index => $item ) {
                    $clone = $this->deep_clone_element( $element );
                    $clone = $this->inject_repeat_context( $clone, $repeat_key, $item, $index );
                    $result[] = $clone;
                }

                continue;
            }

            // Recurse into children
            if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $element['elements'] = $this->process_repeats( $element['elements'] );
            }

            $result[] = $element;
        }

        return $result;
    }

    /**
     * v5.1: Process _wp_repeat markers found INSIDE widget settings arrays.
     *
     * Elementor widgets like icon-list, price-table, social-icons, slides,
     * testimonial-carousel, etc. store their items as arrays inside settings.
     * Example: settings.icon_list = [ { text: "...", _wp_repeat: "credentials" } ]
     *
     * This method walks every element's settings. When it finds an array
     * where any item has "_wp_repeat", it:
     *   1. Uses that item as the template
     *   2. Clones it once per payload array item
     *   3. Resolves {{key._item.field}} tokens in each clone
     *   4. Generates unique _id values for Elementor
     *   5. Removes items without _wp_repeat (they're static placeholders)
     *      OR keeps them if no _wp_repeat items exist (pass-through)
     *
     * If no _wp_repeat markers are found anywhere, the element passes
     * through completely untouched. No errors, no side effects.
     */
    private function process_settings_repeats( array $elements ): array {
        foreach ( $elements as &$element ) {
            if ( ! is_array( $element ) ) continue;

            if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
                $element['settings'] = $this->scan_settings_for_repeats( $element['settings'] );
            }

            if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $element['elements'] = $this->process_settings_repeats( $element['elements'] );
            }
        }

        return $elements;
    }

    /**
     * Recursively scan a settings array for repeatable items.
     * Works on any depth — handles nested settings structures.
     */
    private function scan_settings_for_repeats( array $settings ): array {
        foreach ( $settings as $key => &$value ) {
            if ( ! is_array( $value ) ) continue;

            // Check if this is a sequential array (list of items)
            if ( ! array_is_list( $value ) ) {
                // Associative array — recurse into it
                $value = $this->scan_settings_for_repeats( $value );
                continue;
            }

            // Sequential array — check if any item has _wp_repeat
            $repeat_template = null;
            $repeat_key      = null;

            foreach ( $value as $item ) {
                if ( is_array( $item ) && isset( $item['_wp_repeat'] ) ) {
                    $repeat_template = $item;
                    $repeat_key      = $item['_wp_repeat'];
                    break;
                }
            }

            if ( $repeat_template === null ) {
                // No _wp_repeat found — pass through untouched
                continue;
            }

            // Found a repeat marker — clone the template item per data item
            $data_items = $this->resolve( $repeat_key, [] );
            if ( ! is_array( $data_items ) || empty( $data_items ) ) {
                // No data — leave the original items (or clear them)
                continue;
            }

            // Remove the marker from the template
            unset( $repeat_template['_wp_repeat'] );

            $new_items = [];
            foreach ( $data_items as $index => $data_item ) {
                $clone = $repeat_template;

                // Generate unique Elementor _id for this list item
                $clone['_id'] = substr( bin2hex( random_bytes( 4 ) ), 0, 7 );

                // Walk all string values in the clone and resolve tokens
                $clone = $this->resolve_item_tokens( $clone, $repeat_key, $data_item, $index );

                $new_items[] = $clone;
            }

            $value = $new_items;
        }

        return $settings;
    }

    /**
     * Recursively resolve {{key._item.field}} tokens in a settings item.
     * Works on strings, arrays, and nested structures.
     */
    private function resolve_item_tokens( $data, string $repeat_key, $item, int $index ) {
        if ( is_string( $data ) ) {
            // Replace {{key._item.field}} with the item's field value
            $data = preg_replace_callback(
                '/\{\{' . preg_quote( $repeat_key, '/' ) . '\._item\.([a-zA-Z0-9_.]+)\}\}/',
                function ( $matches ) use ( $item ) {
                    $field = $matches[1];
                    if ( is_array( $item ) ) {
                        $keys = explode( '.', $field );
                        $val  = $item;
                        foreach ( $keys as $k ) {
                            if ( is_array( $val ) && isset( $val[$k] ) ) {
                                $val = $val[$k];
                            } else {
                                return '';
                            }
                        }
                        return is_string( $val ) ? $val : strval( $val );
                    }
                    return '';
                },
                $data
            );

            // Replace {{_index}} and {{_position}}
            $data = str_replace( '{{_index}}', strval( $index ), $data );
            $data = str_replace( '{{_position}}', strval( $index + 1 ), $data );

            return $data;
        }

        if ( is_array( $data ) ) {
            foreach ( $data as $k => &$v ) {
                $v = $this->resolve_item_tokens( $v, $repeat_key, $item, $index );
            }
        }

        return $data;
    }

    /**
     * Deep-clone an element tree with fresh Elementor IDs.
     * Elementor requires unique 7-char hex IDs on every element.
     */
    private function deep_clone_element( array $element ): array {
        // Generate new unique ID
        $element['id'] = substr( bin2hex( random_bytes( 4 ) ), 0, 7 );

        if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
            foreach ( $element['elements'] as &$child ) {
                $child = $this->deep_clone_element( $child );
            }
        }

        return $element;
    }

    /**
     * Replace {{repeat_key._item.field}} tokens inside a cloned element
     * with values from the current array item.
     *
     * Also replaces {{_index}} with the 0-based index and
     * {{_position}} with the 1-based position.
     */
    private function inject_repeat_context( array $element, string $repeat_key, $item, int $index ): array {
        $json = wp_json_encode( $element, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

        // Replace {{services._item.name}} → item['name']
        $json = preg_replace_callback(
            '/\{\{' . preg_quote( $repeat_key, '/' ) . '\._item\.([a-zA-Z0-9_.]+)\}\}/',
            function ( $matches ) use ( $item ) {
                $field = $matches[1];
                if ( is_array( $item ) ) {
                    // Support nested fields: {{services._item.nested.field}}
                    $keys = explode( '.', $field );
                    $val  = $item;
                    foreach ( $keys as $k ) {
                        if ( is_array( $val ) && isset( $val[$k] ) ) {
                            $val = $val[$k];
                        } else {
                            return '';
                        }
                    }
                    return is_string( $val ) ? $this->escape_json_value( $val ) : strval( $val );
                }
                return '';
            },
            $json
        );

        // Replace {{_index}} and {{_position}}
        $json = str_replace( '{{_index}}', strval( $index ), $json );
        $json = str_replace( '{{_position}}', strval( $index + 1 ), $json );

        return json_decode( $json, true ) ?: $element;
    }

    /**
     * Replace {{dot.notation}} tokens with values from payload.
     */
    private function inject_dot_tokens( string $json ): string {
        return preg_replace_callback(
            '/\{\{([a-zA-Z0-9_.]+)\}\}/',
            function ( $matches ) {
                $path  = $matches[1];
                $value = $this->resolve( $path, '' );

                // Don't inject arrays/objects as raw values
                if ( is_array( $value ) || is_object( $value ) ) return '';

                return $this->escape_json_value( strval( $value ) );
            },
            $json
        );
    }

    /**
     * Escape a string for safe embedding inside a JSON-encoded string.
     * The value will appear inside "..." so we need to escape quotes,
     * backslashes, and control characters.
     */
    private function escape_json_value( string $value ): string {
        // json_encode wraps in quotes — strip them to get the inner escaped value
        $encoded = json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        return substr( $encoded, 1, -1 ); // Strip surrounding quotes
    }

    // =================================================================
    // BRACKET TOKEN REPLACEMENT (backward compat with v4)
    // =================================================================

    private function build_bracket_replacements(): array {
        $city  = sanitize_text_field( $this->resolve( 'contact.address.city', $this->resolve( 'city', '' ) ) );
        $state = sanitize_text_field( $this->resolve( 'contact.address.state', $this->resolve( 'state', '' ) ) );
        $city_state = trim( "{$city}, {$state}", ', ' );

        $company = sanitize_text_field( $this->resolve( 'business_name' ) );
        $trade   = sanitize_text_field( $this->resolve( 'industry', $this->resolve( 'trade', '' ) ) );
        $phone   = sanitize_text_field( $this->resolve( 'contact.phone', $this->resolve( 'phone', '' ) ) );
        $email   = sanitize_email( $this->resolve( 'contact.email', $this->resolve( 'email', '' ) ) );

        $replacements = [
            '[COMPANY NAME]'       => $company,
            '[TAGLINE]'            => sanitize_text_field( $this->resolve( 'tagline', "Professional {$trade} Services in {$city_state}" ) ),
            '[HERO HEADLINE]'      => sanitize_text_field( $this->resolve( 'hero_headline', $company ) ),
            '[HERO SUB]'           => sanitize_text_field( $this->resolve( 'about_short', "Serving {$city_state}" ) ),
            '[TRADE]'              => strtoupper( $trade ),
            '[CITY, STATE]'        => $city_state,
            '[CITY]'               => $city,
            '[STATE]'              => $state,
            '[PHONE]'              => $phone,
            '[PHONE NUMBER]'       => $phone,
            '[EMAIL]'              => $email,
            '[EMAIL ADDRESS]'      => $email,
            '[ADDRESS]'            => sanitize_text_field( $this->resolve( 'contact.address.street', $this->resolve( 'address', '' ) ) ),
            '[ABOUT]'              => wp_kses_post( $this->resolve( 'about_long', $this->resolve( 'about', '' ) ) ),
            '[YEARS IN BUSINESS]'  => $this->resolve( 'years_in_business', $this->calculate_years() ),
            '[INSTABID EMBED PLACEHOLDER]' => '',
        ];

        // Generate flat service tokens for backward compat
        $services = $this->resolve( 'services', [] );
        for ( $i = 0; $i < 12; $i++ ) {
            $n = $i + 1;
            $svc = $services[$i] ?? [];
            $replacements["[SERVICE {$n} NAME]"]        = sanitize_text_field( $svc['name'] ?? '' );
            $replacements["[SERVICE {$n} description]"] = wp_kses_post( $svc['description'] ?? '' );
        }

        // Generate flat process tokens for backward compat
        $defaults = [
            [ 'Free Consultation', 'We start with a thorough assessment and provide a free estimate.' ],
            [ 'Custom Plan', 'We create a plan tailored to your needs and timeline.' ],
            [ 'Expert Execution', 'Our certified team executes with precision and professionalism.' ],
            [ 'Quality Guarantee', 'We stand behind every job with a satisfaction guarantee.' ],
        ];
        $steps = $this->resolve( 'process_steps', [] );
        for ( $i = 0; $i < 6; $i++ ) {
            $n    = $i + 1;
            $step = $steps[$i] ?? [];
            $def  = $defaults[$i] ?? [ '', '' ];
            $replacements["[PROCESS {$n} TITLE]"] = sanitize_text_field( $step['title'] ?? $def[0] );
            $replacements["[PROCESS {$n} DESC]"]  = wp_kses_post( $step['description'] ?? $def[1] );
        }

        // Generate flat testimonial tokens for backward compat
        $testimonials = $this->resolve( 'testimonials', [] );
        for ( $i = 0; $i < 6; $i++ ) {
            $n   = $i + 1;
            $t   = $testimonials[$i] ?? [];
            $replacements["[TESTIMONIAL {$n} TEXT]"]  = wp_kses_post( $t['quote'] ?? '' );
            $replacements["[TESTIMONIAL {$n} NAME]"]  = sanitize_text_field( $t['author'] ?? '' );
            $replacements["[TESTIMONIAL {$n} TITLE]"] = sanitize_text_field( $t['role'] ?? '' );
        }

        return $replacements;
    }

    /**
     * Auto-calculate years in business from year_founded.
     */
    private function calculate_years(): string {
        $founded = intval( $this->resolve( 'year_founded', 0 ) );
        if ( $founded > 1900 && $founded <= (int) date( 'Y' ) ) {
            return strval( (int) date( 'Y' ) - $founded );
        }
        return '10';
    }

    // =================================================================
    // IMAGE PROCESSING
    // =================================================================

    private function process_images(): array {
        $images = [];

        foreach ( self::IMAGE_SLOTS as $slot => $payload_path ) {
            if ( $slot === 'gallery' ) {
                // Gallery is an array of images
                $gallery = $this->resolve( $payload_path, [] );
                if ( is_array( $gallery ) ) {
                    foreach ( $gallery as $idx => $img ) {
                        $url = is_array( $img ) ? ( $img['url'] ?? '' ) : $img;
                        if ( ! empty( $url ) ) {
                            $id  = $this->sideload_image( $url );
                            $images["gallery_{$idx}"] = [
                                'url' => $id ? wp_get_attachment_url( $id ) : esc_url( $url ),
                                'id'  => $id,
                            ];
                        }
                    }
                }
                continue;
            }

            $img_data = $this->resolve( $payload_path );
            $src_url  = '';

            if ( is_array( $img_data ) ) {
                $src_url = $img_data['url'] ?? '';
            } elseif ( is_string( $img_data ) ) {
                $src_url = $img_data;
            }

            if ( ! empty( $src_url ) ) {
                $id        = $this->sideload_image( $src_url );
                $local_url = $id ? wp_get_attachment_url( $id ) : esc_url( $src_url );
                $images[$slot] = [ 'url' => $local_url, 'id' => $id ];
            } else {
                $images[$slot] = [ 'url' => '', 'id' => 0 ];
            }
        }

        return $images;
    }

    // =================================================================
    // DESIGN TOKENS
    // =================================================================

    /**
     * Apply design tokens to Elementor's kit settings.
     *
     * v5 supports full system colors + typography via payload:
     *   "design_tokens": {
     *     "colors": {
     *       "primary": "#1A3A5C",
     *       "secondary": "#C9A84C",
     *       "text": "#2C2C2A",
     *       "accent": "#D85A30"
     *     },
     *     "fonts": {
     *       "primary": "Montserrat",
     *       "secondary": "Open Sans"
     *     }
     *   }
     *
     * Falls back to per-template accent colors if no tokens provided.
     */
    private function apply_design_tokens( string $template ) {
        $tokens = $this->resolve( 'design_tokens', [] );

        // --- COLORS ---
        $template_defaults = [
            'authority-v2' => [ 'primary' => '#1A3A5C', 'accent' => '#C9A84C' ],
            'green-v2'     => [ 'primary' => '#2E7D32', 'accent' => '#4CAF50' ],
            'premium-v2'   => [ 'primary' => '#1A3A5C', 'accent' => '#C9A84C' ],
            'bold-v2'      => [ 'primary' => '#1B1B1B', 'accent' => '#D85A30' ],
        ];
        $defaults = $template_defaults[ strtolower( $template ) ] ?? [ 'primary' => '#1A3A5C', 'accent' => '#C9A84C' ];

        $colors = is_array( $tokens['colors'] ?? null ) ? $tokens['colors'] : [];

        $system_colors = [
            [ '_id' => 'primary',   'title' => 'Primary',   'color' => $colors['primary']   ?? $defaults['primary'] ],
            [ '_id' => 'secondary', 'title' => 'Secondary', 'color' => $colors['secondary'] ?? '#54595F' ],
            [ '_id' => 'text',      'title' => 'Text',      'color' => $colors['text']      ?? '#2C2C2A' ],
            [ '_id' => 'accent',    'title' => 'Accent',    'color' => $colors['accent']     ?? $defaults['accent'] ],
        ];

        $kit_id = (int) get_option( 'elementor_active_kit' );
        if ( ! $kit_id ) return;

        $settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
        if ( ! is_array( $settings ) ) $settings = [];

        $settings['system_colors'] = $system_colors;

        // --- TYPOGRAPHY ---
        $fonts = is_array( $tokens['fonts'] ?? null ) ? $tokens['fonts'] : [];
        if ( ! empty( $fonts['primary'] ) || ! empty( $fonts['secondary'] ) ) {
            $settings['system_typography'] = [
                [
                    '_id'                       => 'primary',
                    'title'                     => 'Primary',
                    'typography_font_family'    => $fonts['primary'] ?? 'Montserrat',
                    'typography_font_weight'    => '600',
                ],
                [
                    '_id'                       => 'secondary',
                    'title'                     => 'Secondary',
                    'typography_font_family'    => $fonts['secondary'] ?? 'Open Sans',
                    'typography_font_weight'    => '400',
                ],
                [
                    '_id'                       => 'text',
                    'title'                     => 'Text',
                    'typography_font_family'    => $fonts['secondary'] ?? 'Open Sans',
                    'typography_font_weight'    => '400',
                ],
                [
                    '_id'                       => 'accent',
                    'title'                     => 'Accent',
                    'typography_font_family'    => $fonts['primary'] ?? 'Montserrat',
                    'typography_font_weight'    => '700',
                ],
            ];
        }

        update_post_meta( $kit_id, '_elementor_page_settings', $settings );

        // Regenerate kit CSS
        delete_post_meta( $kit_id, '_elementor_css' );
        if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
            \Elementor\Core\Files\CSS\Post::create( $kit_id )->update();
        }
    }

    // =================================================================
    // NAVIGATION (extracted from v4 inline code)
    // =================================================================

    private function setup_navigation() {
        $menu_name = 'Primary';
        $menu_id   = 0;

        foreach ( wp_get_nav_menus() as $m ) {
            if ( $m->name === $menu_name ) {
                $menu_id = $m->term_id;
                $old_items = wp_get_nav_menu_items( $menu_id, [ 'update_post_term_cache' => false ] );
                if ( $old_items ) {
                    foreach ( $old_items as $oi ) { wp_delete_post( $oi->ID, true ); }
                }
                break;
            }
        }

        if ( ! $menu_id ) {
            $r = wp_create_nav_menu( $menu_name );
            $menu_id = is_wp_error( $r ) ? 0 : (int) $r;
        }

        if ( $menu_id ) {
            // Use CTA text from payload if available
            $cta_text = sanitize_text_field(
                $this->resolve( 'cta.primary_text',
                $this->resolve( 'cta_text', 'Get a Quote' ) )
            );

            $nav_links = [
                'Home'     => home_url( '/' ),
                'About'    => home_url( '/about/' ),
                'Services' => home_url( '/services/' ),
                'Contact'  => home_url( '/contact/' ),
                $cta_text  => home_url( '/quote/' ),
            ];

            foreach ( $nav_links as $title => $url ) {
                wp_update_nav_menu_item( $menu_id, 0, [
                    'menu-item-type'   => 'custom',
                    'menu-item-title'  => $title,
                    'menu-item-url'    => $url,
                    'menu-item-status' => 'publish',
                ] );
            }

            $locs = get_theme_mod( 'nav_menu_locations' ) ?: [];
            $locs['primary'] = $menu_id;
            set_theme_mod( 'nav_menu_locations', $locs );
        }
    }

    // =================================================================
    // UNCHANGED FROM v4 (deploy, fetch, image, cache methods)
    // =================================================================

    private function deploy_page( string $slug, string $json_url, array $replacements, array $images ) {
        $body = $this->fetch_template( $json_url );
        if ( is_wp_error( $body ) ) return $body;

        $elementor_data = $this->build_elementor_data( $body, $json_url, $replacements, $images );
        if ( is_wp_error( $elementor_data ) ) return $elementor_data;

        $page = get_page_by_path( $slug, OBJECT, 'page' );
        if ( ! $page ) {
            $posts = get_posts([
                'name'           => $slug,
                'post_type'      => 'page',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
            ]);
            $page = $posts[0] ?? null;
        }
        if ( ! $page ) return new WP_Error( 'not_found', "Page '{$slug}' not found on this blog." );

        $post_id = $page->ID;
        update_post_meta( $post_id, '_elementor_data', wp_slash( $elementor_data ) );
        update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
        $this->clear_elementor_cache( $post_id );
        $this->set_page_template_for_hfe();

        return get_permalink( $post_id );
    }

    private function deploy_library_template( string $template_slug, string $json_url, array $replacements, array $images ) {
        $body = $this->fetch_template( $json_url );
        if ( is_wp_error( $body ) ) return $body;

        $elementor_data = $this->build_elementor_data( $body, $json_url, $replacements, $images );
        if ( is_wp_error( $elementor_data ) ) return $elementor_data;

        $hfe_type = ( $template_slug === 'header' ) ? 'type_header' : 'type_footer';
        $templates = get_posts([
            'post_type'      => 'elementor-hf',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_key'       => 'ehf_template_type',
            'meta_value'     => $hfe_type,
        ]);

        if ( empty( $templates ) ) {
            $new_id = wp_insert_post([
                'post_title'  => ucfirst( $template_slug ),
                'post_status' => 'publish',
                'post_type'   => 'elementor-hf',
            ]);
            if ( ! $new_id || is_wp_error( $new_id ) ) {
                return new WP_Error( 'create_failed', "Could not create HFE {$template_slug} template." );
            }
            update_post_meta( $new_id, 'ehf_template_type', $hfe_type );
            update_post_meta( $new_id, '_elementor_edit_mode', 'builder' );
            update_post_meta( $new_id, 'ehf_target_include_locations', [ 'rule' => [ 'basic-global' ], 'specific' => [] ] );
            $post_id = $new_id;
        } else {
            $post_id = $templates[0]->ID;
        }

        $lib_templates = get_posts([
            'name'           => $template_slug,
            'post_type'      => 'elementor_library',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
        ]);
        if ( ! empty( $lib_templates ) ) {
            update_post_meta( $lib_templates[0]->ID, '_elementor_data', wp_slash( $elementor_data ) );
            $this->clear_elementor_cache( $lib_templates[0]->ID );
        }

        update_post_meta( $post_id, '_elementor_data', wp_slash( $elementor_data ) );
        update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
        $this->clear_elementor_cache( $post_id );
        $this->set_page_template_for_hfe();

        return true;
    }

    private function set_page_template_for_hfe() {
        $pages = get_posts([
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_key'       => '_wp_page_template',
            'meta_value'     => 'elementor_canvas',
        ]);
        foreach ( $pages as $page ) {
            update_post_meta( $page->ID, '_wp_page_template', 'elementor_header_footer' );
        }
        if ( get_option( 'template' ) !== 'hello-elementor' ) {
            switch_theme( 'hello-elementor' );
        }
    }

    private function fetch_template( string $url ) {
        $response = wp_remote_get( $url, [ 'timeout' => 15 ] );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'fetch_failed', "Could not fetch template: {$url}" );
        }
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return new WP_Error( 'fetch_failed', "Template returned HTTP {$code}: {$url}" );
        }
        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) {
            return new WP_Error( 'empty_template', "Empty template body: {$url}" );
        }
        return $body;
    }

    /**
     * v4 bracket token injection — unchanged.
     */
    private function inject_tokens( string $json, array $replacements ): string {
        return str_replace(
            array_keys( $replacements ),
            array_map( 'strval', array_values( $replacements ) ),
            $json
        );
    }

    /**
     * v4 image override — unchanged, recursive.
     */
    private function apply_image_overrides( array $elements, array $images ): array {
        foreach ( $elements as &$element ) {
            if ( ! is_array( $element ) ) continue;

            if ( isset( $element['settings']['_wp_img'] ) ) {
                $slot = $element['settings']['_wp_img'];

                if ( isset( $images[$slot] ) && ! empty( $images[$slot]['url'] ) ) {
                    $img = $images[$slot];
                    $is_image_widget = ( ( $element['elType'] ?? '' ) === 'widget' &&
                                         ( $element['widgetType'] ?? '' ) === 'image' );

                    if ( $is_image_widget ) {
                        $element['settings']['image'] = $img;
                    } else {
                        $element['settings']['background_image']      = $img;
                        $element['settings']['background_background'] = 'classic';
                    }
                }
            }

            if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $element['elements'] = $this->apply_image_overrides( $element['elements'], $images );
            }
        }
        return $elements;
    }

    private function sideload_image( string $url ): int {
        if ( empty( $url ) ) return 0;

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $existing = get_posts([
            'post_type'      => 'attachment',
            'meta_key'       => '_source_url',
            'meta_value'     => $url,
            'posts_per_page' => 1,
        ]);
        if ( ! empty( $existing ) ) return $existing[0]->ID;

        $id = media_sideload_image( $url, 0, '', 'id' );
        if ( is_wp_error( $id ) ) return 0;

        update_post_meta( $id, '_source_url', $url );
        return intval( $id );
    }

    private function clear_elementor_cache( int $post_id ) {
        delete_post_meta( $post_id, '_elementor_css' );
        clean_post_cache( $post_id );
        wp_cache_delete( $post_id, 'posts' );
        wp_cache_delete( $post_id, 'post_meta' );

        if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
            \Elementor\Core\Files\CSS\Post::create( $post_id )->update();
        }
    }
}

new WebPrinter_Engine();
