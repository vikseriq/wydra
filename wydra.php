<?php
/**
 * Plugin Name: Wydra: WordPress YAML Data Render Assistant
 * Description: Use YAML-driven data right in post content and render by using HTML templates
 * Plugin URI: https://github.com/vikseriq/wydra
 * GitHub Plugin URI: https://github.com/vikseriq/wydra
 * Author: vikseriq
 * Author URI: https://vikseriq.xyz/
 * Version: 0.2.0
 * License: MIT
 * License URI: https://tldrlegal.com/license/mit-license
 */

define('Wydra\SHORTCODE_PREFIX_CORE', 'wydra');

if (!defined('Wydra\SHORTCODE_PREFIX')) {
    // shorthand prefix
    define('Wydra\SHORTCODE_PREFIX', 'w');
}
if (!defined('Wydra\THEME_PATH')) {
    // In-theme templates subfolder name
    define('Wydra\THEME_PATH', '/wydra-templates/');
}
if (!defined('Wydra\TEMPLATES_PATH')) {
    // Path to theme-agnostic templates
    define('Wydra\TEMPLATES_PATH', __DIR__ . '/templates/');
}

add_action('after_setup_theme', 'wydra_boot_plugin');
function wydra_boot_plugin()
{
    include_once __DIR__ . '/wydra.class.php';
    Wydra::init();
}

/**
 * Returns shortcode attribute value with fallback
 * @param $code string attribute code
 * @param null $default fallback value
 * @return string|null
 */
function wydra_attr($code, $default = null)
{
    return Wydra::latest()->attr($code, $default);
}

/**
 * Returns shortcode content
 * @return string
 */
function wydra_content()
{
    return Wydra::latest()->content();
}

/**
 * Returns content from named YAML container, registered with wydra-define
 * @param $name
 * @return array|null
 */
function wydra_data($name)
{
    return Wydra::get_data($name);
}

/**
 * Return PHP array of YAML content
 * @return array
 */
function wydra_yaml()
{
    return Wydra::parse_yaml(Wydra::latest()->content());
}

/**
 * Resolves value based on array path or returns default.
 *
 * Example:
 *
 * $array = [
 *      'foo' => [
 *          'bar' => 'baz',
 *          'kee'
 *      ],
 *      'pass' => 123
 * ]
 *
 * wap($array, 'foo.bar')           // `baz`,       subarray foo contains key bar
 * wap($array, 'bar', 'nothing')    // `nothing`,   path 'bar' not exists
 * wap($array, 'pass', 321)         // `123`,       path exists
 *
 * @param $array array
 * @param $path string comma-separated array path
 * @param null $default fallback value
 * @return mixed
 */
function wydra_array_path($array, $path, $default = null)
{
    $pathway = explode('.', $path);
    $value = $default;
    foreach ($pathway as $chain) {
        if (isset($array[$chain]))
            $value = $array[$chain];
        else break;
    }
    return $value;
}

if (!function_exists('wap')) {
    /**
     * Shorthand call for wydra_array_path
     *
     * @param $array
     * @param $path
     * @param null $default
     */
    function wap($array, $path, $default = null)
    {
        call_user_func('wydra_array_path', func_get_args());
    }
}