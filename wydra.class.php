<?php

class Wydra
{
    // shortcode-to-template map
    static $_templates = null;

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
        // register shortcodes from template files
        $available_shortcodes = self::scan_templates();
        foreach ($available_shortcodes as $shortcode) {
            add_shortcode($shortcode['code'], ['Wydra', 'do_shortcode']);
        }

        // register data-related shortcodes
        add_shortcode(Wydra\SHORTCODE_PREFIX_CORE . '-define', ['Wydra', 'define_data']);
        add_shortcode(Wydra\SHORTCODE_PREFIX . '-define', ['Wydra', 'define_data']);

        // register html-like shortcodes
        foreach (self::$HTML_TAGS as $tag) {
            add_shortcode(Wydra\SHORTCODE_PREFIX_CORE . '-' . $tag, ['Wydra', 'do_tag']);
            add_shortcode(Wydra\SHORTCODE_PREFIX . '-' . $tag, ['Wydra', 'do_tag']);
            // register depth, like wydra-div-0, w-tag-3
            $depth = self::$HTML_TAGS_DEPTH;
            do {
                add_shortcode(Wydra\SHORTCODE_PREFIX_CORE . '-' . $tag . '-' . $depth, ['Wydra', 'do_tag']);
                add_shortcode(Wydra\SHORTCODE_PREFIX . '-' . $tag . '-' . $depth, ['Wydra', 'do_tag']);
            } while ($depth--);
        }
    }

    /**
     * Template scanner
     *
     * @return array|null
     */
    static function scan_templates()
    {
        if (!self::$_templates) {

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
                    if (preg_match('/^(.*)\.php$/', $file, $match)) {
                        $prefix = \Wydra\SHORTCODE_PREFIX_CORE;
                        // direct shortcode, like wydra-list
                        $code = "{$prefix}-{$match[1]}";
                        $templates[$code] = array(
                            'code' => $code,
                            'name' => $match[1],
                            'file' => $path . $file,
                        );

                        // alternative shorten shortcode, like w-list
                        $prefix = \Wydra\SHORTCODE_PREFIX;
                        $code = "{$prefix}-{$match[1]}";
                        $templates[$code] = array(
                            'code' => $code,
                            'name' => $match[1],
                            'file' => $path . $file,
                        );
                    }
                }
            }
            self::$_templates = $templates;
        }

        return self::$_templates;
    }

    /**
     * Returns template file path based on shortcode name
     *
     * @param $shortcode
     * @return string|null
     */
    static function template_file($shortcode)
    {
        if (!isset(self::$_templates[$shortcode]))
            return null;
        return self::$_templates[$shortcode]['file'];
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
            if (preg_match('/^(' . \Wydra\SHORTCODE_PREFIX_CORE . '|' . \Wydra\SHORTCODE_PREFIX . ')-'
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
     * Processing raw post content and extracts YAML data.
     * The Dipper parser used to process.
     *
     * @param $raw_content
     * @return array
     */
    static function parse_yaml($raw_content)
    {
        $yaml = [];
        $content = self::unwrap($raw_content);

        if (strlen($content) === strlen($raw_content)) {
            // decode WP entities if no pre-wrap was found
            $content = html_entity_decode(
                str_replace(['&#8212;', '<br />'], ['-', ''], ($raw_content))
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

        include_once __DIR__ . '/Dipper.php';
        try {
            $yaml = \secondparty\Dipper\Dipper::parse($content);
            if (is_array($yaml) && !empty($yaml[$list_wrap])) {
                $yaml = $yaml[$list_wrap];
            }
        } catch (Exception $e) {
            if (WP_DEBUG) {
                echo 'Wydra debug: YAML parse error /EOF' . PHP_EOL;
                echo $content . PHP_EOL . '/EOF;' . PHP_EOL;
                echo $e->getMessage();
            }
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
            'data' => self::parse_yaml($content)
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
        if (isset($this->attrs[$code]))
            return $this->attrs[$code];
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