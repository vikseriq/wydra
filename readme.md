Wydra
=====

WordPress YAML Data & Render Assistant

*Use YAML-driven data right inside post content and render shortcodes by using HTML templates*

## Conception

Sometimes backend developers need to integrate frontend markup into 
WordPress site and give ability to edit content by managers,
which may not have any knowledge of html/css and may easy break markup schema by mistake.

With such conditions there are no use of page builders
(markup already done, just integration needed) 
nor plain html inline editor 
(manager may break something, for example by accidentally switching to "Visual" mode in the Classic Editor).
In addition, using page builders requires definition of every repeated piece of content 
again and again on every page, that may be a nightmare for content managers and translators.


This plugin helps to save integration/further support time by utilizing following cases:

- allows definition of custom shortcodes based on filenames of html markup pieces;
- provides easy ways to add editable data:
 via shortcode attributes, via shortcode content, 
 and via reusable inclusion of YAML-based page;
- allows parsing of YAML data from content strings, 
 including multilines and array of items;
- separate YAML data posts by providing custom post type;
- provides a simple way to place plain html tag as a shortcode.


## Usage

### Shortcode prefix

By default, plugin registers shortcodes in two variants:
with core prefix `wydra-` and shorthand prefix `w-`.
You may configure shorthand prefix by defining special
constant placed in `wp-config.php` or theme's `functions.php`:

```php
define('Wydra\SHORTCODE_PREFIX', 'my-custom-prefix-');
```

In the following examples we assume to use a `w-`-prefix,
(like `[w-define]`), but keep in mind that every shortcode also available 
in full form (`[wydra-define]`) and shorthand form (`my-custom-prefix-define`).


### Template-based shortcodes

Plugin iterates through `*.php` files of 
`/wydra-templates/` path of current active theme 
and registers shortcodes based on filename.
Example: `section-list.php` becomes `[w-section-list]` shortcode.

When calling `[w-section-list]` the plugin includes
appropriate file and evaluate it as regular PHP file.
Available in-content methods see on the next section.

To exclude file from shortcode-lookup parsing place `.`, `~` or `!` in the beginning of filename.
Example: `/wydra-templates/~product-list.php` does not produce any shortcode.


### HTML tags

Most common html tags like `div`, `span` and `p` (see \Wydra::$HTML_TAGS)
used for control of content-flow registered as shortcodes.
The shortcode attributes placed as html attributes. 
Shortcode content passing via `the_content` filter and then placed as inner html.
Plugin also provides a tag level suffix (from 0 to 5) 
to resolve WordPress quirk of supporting nested shortcodes.

Special shortcode `w-tag` used to render custom tag, 
where the tag name passed as first shortcode attribute.

Another special shortcode `w-pre` used just only to unwrap `<pre>`-wrapped inner content 
to get rid of WordPress `wpautop` or `nl2br` transformations. 
It’s also used to format indentation-sensitive YAML-data.
 
Example:
```text
[w-pre]<pre>
[w-div-0 class="row"]
    [w-div class="col-md-6" style="font-style:italic;"]
        [w-p]Within cells interlinked.[/w-p]
    [/w-div]
    [w-div class="col-md-6"]
        [w-span class="text-success"]Interlinked.[/w-span]
    [/w-div]
    [w-tag a href="#" class="btn"]Retry[/w-tag]
[/w-div-0]
</pre>[/w-pre]
```

Renders as:

```html
<div class="row">
    <div class="col-md-6" style="font-style:italic;">
        <p>Within cells interlinked.</p>
    </div>
    <div class="col-md-6">
        <span class="text-success">Interlinked.</span>
    </div>
    <a href="#" class="btn">Retry</a>
</div>
```

### In-template methods

| Method | Description |
| --- | --- |
`wydra_attr (attribute code, fallback value)` | Return value of shortcode attribute or fallback if attribute not exists. For non-value attributes (flags) returns true if attribute exists.
`wydra_content ()` | Return inner content of current shortcode.
`wydra-data (container)` | Get yaml data from `wydra-define` defined container.
`wydra-yaml ()` | Parse current shortcode content as yaml data.


### YAML

Parse arbitrary YAML content by using global `wydra_parse_yaml` function.
Parsing performed via [Dipper](https://github.com/secondparty/dipper) library.
See full formatting guide [here](https://github.com/secondparty/dipper/blob/master/README.md#what-it-will-parse).

## License

MIT © 2019 vikseriq