<?php

class Wydra
{
    // registered shortcodes list with handlers
    static $_shortcodes = [];

    // shortcode alias map
    static $_shortcode_alias = [];

    // controls either wydra-define returns data or not
    static $DEFINE_DUMP_INSTANCE = false;

    // data storage
    static $_data = [];

    // shortcode render instances
    static $_instance_stack = [];

    // html shortcode variants
    static $HTML_TAGS = ['pre', 'tag', 'div', 'span', 'p'];
    // maximum depth of html shortcodes nesting
    static $HTML_TAGS_DEPTH = 5;

    /**
     * Scan directories and define shortcodes based on template names
     */
    static function init()
    {
        // define html-like shortcodes
        foreach (self::$HTML_TAGS as $tag) {
            self::$_shortcodes[$tag] = [
                'handler' => ['Wydra', 'do_tag'],
                'depth' => self::$HTML_TAGS_DEPTH,
            ];
        }

        // define data-related shortcodes
        self::$_shortcodes['define'] = [
            'handler' => ['Wydra', 'define_data'],
        ];

        // define shortcodes from template files
        $available_shortcodes = self::scan_templates();
        foreach ($available_shortcodes as $shortcode) {
            self::$_shortcodes[$shortcode['name']] = [
                'handler' => ['Wydra', 'do_shortcode'],
                'template' => $shortcode['file'],
            ];
        }

        $prefix_list = [
            Wydra\SHORTCODE_PREFIX_CORE,
            Wydra\SHORTCODE_PREFIX,
        ];
        // register shortcodes and aliases
        foreach (self::$_shortcodes as $code => $params) {
            foreach ($prefix_list as $prefix) {
                $shortcode_name = $prefix . $code;
                self::$_shortcode_alias[$shortcode_name] = $code;
                add_shortcode($shortcode_name, $params['handler']);
            }

            if (!empty($params['depth'])) {
                $depth = min(max((int)$params['depth'], 1), self::$HTML_TAGS_DEPTH);
                // register depth suffixes, like wydra-div-0, w-tag-3
                for ($i = 0; $i <= $depth; $i++) {
                    foreach ($prefix_list as $prefix) {
                        $shortcode_name = $prefix . $code . '-' . $i;
                        self::$_shortcode_alias[$shortcode_name] = $code;
                        add_shortcode($shortcode_name, $params['handler']);
                    }
                };
            }
        }

        // register post type
        self::register_yaml_post_type();
        add_action('admin_menu', function () {
            // add management page with list of registered wydra shortcodes
            add_submenu_page('tools.php',
                __('Wydra shortcodes', 'wydra'),
                __('Wydra shortcodes', 'wydra'),
                'edit_posts',
                'wydra_shortcodes',
                'wydra_wpadmin_shortcodes'
            );
        });
    }

    /**
     * Template scanner
     *
     * @return array|null
     */
    static function scan_templates()
    {
        $templates = [];
        $paths = [
            'theme' => TEMPLATEPATH . Wydra\THEME_PATH,
            'templates' => Wydra\TEMPLATES_PATH
        ];

        foreach ($paths as $path) {
            if (!is_dir($path))
                continue;
            $directory = scandir($path);
            foreach ($directory as $file) {
                if (in_array(substr($file, 0, 1), ['.', '!', '~', '-'])) {
                    // exclude files with prefix
                    continue;
                }
                if (preg_match('/^(.*)\.php$/', $file, $match)) {
                    $code = $match[1];
                    $templates[$code] = array(
                        'name' => $code,
                        'file' => $path . $file,
                    );
                }
            }
        }
        return $templates;
    }

    /**
     * Returns template file path based on shortcode name
     *
     * @param $shortcode
     * @return string|null
     */
    static function template_file($shortcode)
    {
        if (!isset(self::$_shortcode_alias[$shortcode]))
            return null;
        return self::$_shortcodes[self::$_shortcode_alias[$shortcode]]['template'];
    }

    /**
     * Returns allowed tag name based on shortcode name
     *
     * @param $shortcode
     * @return string|null
     */
    static function tag_name($shortcode)
    {
        foreach (self::$HTML_TAGS as $tag) {
            if (preg_match('/^(' . \Wydra\SHORTCODE_PREFIX_CORE . '|' . \Wydra\SHORTCODE_PREFIX . ')'
                . $tag . '(-\d)?$/', $shortcode)) {
                return $tag;
            }
        }
        return null;
    }

    /**
     * Performing shortcode processing
     *
     * @param $attrs string[] shortcode params
     *          special param 'source-page' used to mark
     *          WP page_id from which YAML data will be extracted
     * @param $content
     * @param $shortcode
     * @return string
     */
    static function do_shortcode($attrs, $content, $shortcode)
    {
        $template_file = self::template_file($shortcode);
        if (empty($template_file))
            return '';

        if (!empty($attrs['source-page'])) {
            // load data from another page
            $content = self::fetch_yaml([
                'page' => $attrs['source-page']
            ]);
        }

        self::$_instance_stack[] = new WydraInstance($template_file, $attrs, $content);
        $content = self::latest()->render();
        array_pop(self::$_instance_stack);

        return $content;
    }

    /**
     * Performing common tag processing
     *
     * @param $attrs
     * @param $content
     * @param $shortcode
     * @return false|string
     */
    static function do_tag($attrs, $content, $shortcode)
    {
        $tag = self::tag_name($shortcode);
        if (!$tag)
            return '';

        self::$_instance_stack[] = new WydraInstance($tag, $attrs, $content);
        $content = self::latest()->render();
        array_pop(self::$_instance_stack);

        return $content;
    }

    /**
     * Return most recent shortcode render instance from stack
     *
     * @return WydraInstance
     */
    static function latest()
    {
        return self::$_instance_stack[count(self::$_instance_stack) - 1];
    }

    /**
     * Extracting inner content from pre tags.
     * Returns original content if no pre pair found.
     *
     * @param $content
     * @return string
     */
    static function unwrap($content)
    {
        $start = strpos($content, '<pre>') + 5;
        $end = strrpos($content, '</pre>');
        if ($start < $end) {
            // extract pre-wrapped
            return substr($content, $start, $end - $start);
        }
        // no pre found
        return $content;
    }

    /**
     * Just parse YAML from string content
     * The Dipper parser used to process.
     *
     * @param $raw_content
     * @return array|null
     */
    static function parse_yaml($raw_content)
    {
        $yaml = null;
        include_once __DIR__ . '/Dipper.php';
        try {
            $yaml = \secondparty\Dipper\Dipper::parse($raw_content);
        } catch (Exception $e) {
            if (WP_DEBUG) {
                echo 'Wydra debug: YAML parse error /EOF' . PHP_EOL;
                echo $raw_content . PHP_EOL . '/EOF;' . PHP_EOL;
                echo $e->getMessage();
            }
        }
        return $yaml;
    }

    /**
     * Processing raw post content and extracts YAML data.
     *
     * @param $post_content
     * @return array
     */
    static function parse_content_yaml($post_content)
    {
        $yaml = [];
        $content = self::unwrap($post_content);

        if (strlen($content) === strlen($post_content)) {
            // decode WP entities if no pre-wrap was found
            $content = html_entity_decode(
                str_replace(['&#8212;', '<br />'], ['-', ''], ($post_content))
            );
        }

        // reduce formatting bugs by decoding special dash and trimming whitespaces
        $content = trim(str_replace('&#8212;', '-', $content));

        // if YAML starts with array list - wrap it with root element and shift
        $list_wrap = false;
        if (substr($content, 0, 2) == '- ') {
            $list_wrap = uniqid();
            $lines = explode(PHP_EOL, $content);
            $content = $list_wrap . ': ' . PHP_EOL;
            foreach ($lines as $line) {
                $content .= '  ' . $line . PHP_EOL;
            }
        }

        $yaml = self::parse_content_yaml($content);
        if (is_array($yaml) && !empty($yaml[$list_wrap])) {
            $yaml = $yaml[$list_wrap];
        }

        return $yaml;
    }

    /**
     * Load YAML from post content
     *
     * @param $attrs
     * @param $content
     * @return string
     */
    static function define_data($attrs, $content)
    {
        $post_name = '';
        if ($post = get_post()) {
            $post_name = $post->post_name;
        }
        $attrs = shortcode_atts([
            'name' => $post_name
        ], $attrs);

        $hash = md5($content . $attrs['name']);
        $code = $attrs['name'];
        if (!empty(self::$_data[$code])) {
            // already defined - resolve collision by adding hash
            $code = $attrs['name'] . '-' . $hash;
        }
        self::$_data[$code] = [
            'name' => $attrs['name'],
            'hash' => $hash,
            'data' => self::parse_content_yaml($content)
        ];

        return self::$DEFINE_DUMP_INSTANCE ? 'wydra-instance-' . $hash . ' ' : '';
    }

    /**
     * Load and parse YAML data from external source
     *
     * @param $attrs
     * @return mixed|null
     */
    static function fetch_yaml($attrs)
    {
        $attrs = shortcode_atts([
            'page' => false,
            'name' => false
        ], $attrs);

        if ($attrs['page']) {
            // load specified page
            $post = get_post($attrs['page']);
            return apply_filters('the_content', $post->post_content);
        }

        return null;
    }

    /**
     * Returns content from named YAML container, registered with wydra-define
     *
     * @param $name
     * @return array|null
     */
    static function get_data($name)
    {
        foreach (self::$_data as $item) {
            if ($item['name'] === $name || $item['hash'] === $name)
                return $item['data'];
        }
        return null;
    }

    /**
     * Register post type for yaml-only content
     */
    static function register_yaml_post_type()
    {
        $args = array(
            'label' => __('YAML'),
            'description' => __('YAML data'),
            'labels' => array(
                'name' => _x('YAML', 'Post Type General Name'),
                'singular_name' => _x('YAML', 'Post Type Singular Name'),
            ),
            'supports' => array('title', 'editor', 'revisions'),
            'hierarchical' => false,
            'public' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_position' => 28,
            'menu_icon' => 'dashicons-media-code',
            'show_in_admin_bar' => true,
            'show_in_nav_menus' => false,
            'can_export' => false,
            'has_archive' => false,
            'exclude_from_search' => true,
            'publicly_queryable' => false,
            'rewrite' => false,
            'capability_type' => 'page',
            'show_in_rest' => false,
        );
        register_post_type(Wydra\YAML_POST_TYPE, $args);

        // disable wysiwyg
        add_filter('user_can_richedit', function ($value) {
            global $post;
            if (get_post_type($post) === Wydra\YAML_POST_TYPE) {
                $value = false;
            }
            return $value;
        });
    }
}


function wydra_wpadmin_shortcodes()
{
    if (!class_exists('WP_List_Table')) {
        require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
    }

    class ShortcodesList extends WP_List_Table
    {

        /**
         * Extract `@var` definitions from file
         *
         * @param $filename
         * @return array
         */
        function extract_template_definition($filename)
        {
            // TODO: implement
            return [];
        }

        /**
         * Prepare shortcodes
         */
        public function prepare_items()
        {
            $items = [];
            foreach (Wydra::$_shortcodes as $code => $props) {
                $aliases = [];
                // lookup aliases
                foreach (Wydra::$_shortcode_alias as $alias => $_code) {
                    if ($code === $_code) {
                        $aliases[] = $alias;
                    }
                }
                // fill row
                $item = [
                    'code' => $code,
                    'aliases' => $aliases,
                    'handler' => implode('\\', $props['handler']),
                    'source' => isset($props['template']) ? $props['template'] : null,
                    'attributes' => null,
                ];

                if ($item['source']) {
                    // get attributes
                    $item['attributes'] = $this->extract_template_definition($item['source']);
                }

                $items[] = $item;
            }

            // sorting
            usort($items, function ($a, $b) {
                // move built-in templates down
                if (empty($a['source']) && !empty($b['source'])) {
                    return 1;
                } else if (!empty($a['source']) && empty($b['source'])) {
                    return -1;
                }
                // sort by code asc
                return strcmp($a['code'], $b['code']);
            });

            // setup wp table
            $n = count($items);
            $this->_column_headers = [
                $this->get_columns(),
                [], // hidden columns
                $this->get_sortable_columns(),
                $this->get_primary_column_name(),
            ];
            $this->set_pagination_args([
                'total_items' => $n,
                'per_page' => $n,
            ]);
            $this->items = $items;
        }

        /**
         * Table columns
         * @return array
         */
        public function get_columns()
        {
            return [
                'code' => 'Base code',
                'aliases' => 'Shortcodes',
                'attributes' => 'Available attributes',
                'source' => 'Template file',
            ];
        }

        /**
         * Table cells renderer
         *
         * @param object $item
         * @param string $column_name
         * @return string|string[]|void
         */
        public function column_default($item, $column_name)
        {
            switch ($column_name) {
                case 'aliases':
                    return implode(' ', array_map(function ($i) {
                        return '<code style="white-space:nowrap;">' . $i . '</code> ';
                    }, $item[$column_name]));
                case 'attributes':
                    return '<i>none</i>';
                case 'source':
                    $v = $item[$column_name];
                    if (empty($v)) {
                        return '<i>built-in</i>';
                    }
                    $file_path = str_replace(ABSPATH, '', $v);
                    if (preg_match('|^wp-content/([^/]+)/([^/]+)/(.*)$|', $file_path, $matches)) {
                        $link = '';
                        // build a link to editor screen
                        switch ($matches[1]) {
                            case 'themes':
                                $link = admin_url(sprintf('theme-editor.php?theme=%1$s&file=%2$s',
                                    $matches[2], $matches[3]
                                ));
                                break;
                            case'plugins':
                                $link = admin_url(sprintf('plugin-editor.php??plugin=%1$s/%1$s.php&file=%1$s/%2$s',
                                    $matches[2], $matches[3]
                                ));
                                break;
                        }
                        if ($link) {
                            return sprintf('<a target="_blank" href="%s">%s</a>',
                                $link, $file_path
                            );
                        }
                    }
                    return $file_path;
                default:
                    return $item[$column_name];
                    break;
            }
        }
    }

    // construct and display
    $wp_list_table = new ShortcodesList();
    $wp_list_table->prepare_items();

    echo '<div class="wrap">';
    echo '<h1 class="wp-heading-inline">' . __('Available Wydra shortcodes', 'wydra') . '</h1>';
    echo '<hr class="wp-header-end" />';
    $wp_list_table->display();
    echo '</div>';
}

/**
 * A render instance available in every wydra shortcode.
 * Used to transform shortcode and it's data to html code.
 *
 * Class WydraInstance
 */
class WydraInstance
{
    var $tag_value;
    var $result = '';
    var $attrs = [];
    var $content = '';

    /**
     * WydraInstance constructor.
     *
     * @param $tag_value    string      Path to template file or html tag name
     * @param $attrs        string[]    Shortcode attributes
     * @param $content      string      Inner content
     */
    function __construct($tag_value, $attrs, $content)
    {
        $this->attrs = $attrs;
        $this->content = $content;
        $this->tag_value = $tag_value;
    }

    /**
     * Generating result HTML.
     *
     * @return string
     */
    function render()
    {
        if (is_file($this->tag_value)) {
            // render template file
            ob_start();
            include $this->tag_value;
            $this->result = ob_get_clean();
        } else {
            // render as tag
            $this->result = $this->render_tag();
        }
        return $this->result;
    }

    /**
     * Get the named attribute value.
     *
     * @param $code
     * @param null $default fallback value
     * @return string|null
     */
    function attr($code, $default = null)
    {
        // no attributes at all
        if (empty($this->attrs)) {
            return $default;
        }
        // code attribute
        if (isset($this->attrs[$code]))
            return $this->attrs[$code];
        // flag-like attribute
        foreach ($this->attrs as $index => $value) {
            if (is_numeric($index) && $value === $code) {
                return true;
            }
        }
        return $default;
    }

    /**
     * Return processed inner content.
     *
     * @return string
     */
    function content()
    {
        return do_shortcode($this->content);
    }

    /**
     * Rendering shortcode as plain HTML tag
     *
     * @return string
     */
    protected function render_tag()
    {
        $tag = $this->tag_value;

        // special case for w-pre: just unwrapping content and that's all
        if ('pre' === $tag) {
            $this->content = Wydra::unwrap($this->content);
            return $this->content();
        }

        // another case: w-tag, process first argument as tag name
        if ('tag' === $tag) {
            if (empty($this->attrs))
                return $this->content();
            $tag = $this->attrs[0];
            unset($this->attrs[0]);
        }

        // cast all attributes as HTML element attributes
        $attributes = [];
        if (!empty($this->attrs)) {
            foreach ($this->attrs as $k => $v) {
                $attributes[] = esc_attr($k) . '="' . esc_attr($v) . '"';
            }
        }

        // format data as a tag
        return '<' . $tag
            . ($attributes ? ' ' . implode(' ', $attributes) : '')
            . '>' . $this->content() . '</' . $tag . '>';
    }

}