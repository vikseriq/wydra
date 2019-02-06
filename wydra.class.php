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
        add_shortcode(Wydra\SHORTCODE_PREFIX . '-define', ['Wydra', 'define_data']);
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
            $prefix = \Wydra\SHORTCODE_PREFIX;
            $paths = [
                'plugin' => \Wydra\VIEW_PATH,
                'template' => TEMPLATEPATH . Wydra\THEME_PATH
            ];

            foreach ($paths as $path) {
                if (!file_exists($path))
                    continue;
                $directory = scandir($path);
                foreach ($directory as $file) {
                    if (preg_match('/^(.*)\.php$/', $file, $match)) {
                        // direct shortcode, like wydra-list
                        $code = "{$prefix}-{$match[1]}";
                        $templates[$code] = array(
                            'code' => $code,
                            'name' => $match[1],
                            'file' => $path . $file,
                        );
                        // additional shortcode, like wydra-list-view
                        $code = "{$prefix}-{$match[1]}-view";
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
     * @return null
     */
    static function template_file($shortcode)
    {
        if (!isset(self::$_templates[$shortcode]))
            return null;
        return self::$_templates[$shortcode]['file'];
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
     * Return most recent shortcode render instance from stack
     *
     * @return WydraInstance
     */
    static function latest()
    {
        return self::$_instance_stack[count(self::$_instance_stack) - 1];
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
        $start = strpos($raw_content, '<pre>') + 5;
        $end = strrpos($raw_content, '</pre>');
        if ($start < $end) {
            // extract pre-wrapped
            $content = substr($raw_content, $start, $end - $start);
        } else {
            // decode WP entities
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

        include_once \Wydra\PLUGIN_FILE . '/Dipper.php';
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
 * A render instance available in every wydra shortcode
 *
 * Class WydraInstance
 */
class WydraInstance
{

    var $template_file;
    var $result = '';
    var $attrs = [];
    var $content = '';

    function __construct($template_file, $attrs, $content)
    {
        $this->attrs = $attrs;
        $this->content = $content;
        $this->template_file = $template_file;
    }

    function render()
    {
        ob_start();
        include $this->template_file;
        $this->result = ob_get_clean();
        return $this->result;
    }

    function attr($code, $default = null)
    {
        if (isset($this->attrs[$code]))
            return $this->attrs[$code];
        return $default;
    }

    function content()
    {
        return do_shortcode($this->content);
    }

}