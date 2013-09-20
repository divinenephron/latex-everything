=== Plugin Name ===
Contributors: divinenephron
Tags: latex, pdf, attachment
Requires at least: 3.0
Tested up to: 3.4.1
Version: 1.3
Stable tag: 1.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Produce PDF documents of everything on your site with Latex.

== Description ==

Latex Everything can produce PDF documents of everything on your site with
Latex. Or at least everything worth putting into a PDF.

Latex Everything can make PDF documents from individual posts and groups of
posts like categories, tags and custom taxonomy terms. The plugins contains
everything a theme needs to define its own Latex templates, and link to the PDFs
produced.

== Installation ==

1. This plugin requires `pdflatex` and `pdftk` installed. You can check for
these by executing `which pdflatex` and `which pdftk` on your host.
2. This plugin also requires you to know at least enough about Wordpress to
cut-and-paste code into your theme.
2. Upload this plugin to the /wp-content/plugins/ directory.
3. Activate the plugin Latex for WordPress through the 'Plugins' menu in
WordPress. Behind the scenes it will generate PDFs for your posts using a
default Latex template.
4. Link to the PDF version of a post by putting the following into [The Loop][the-loop]:  
        `<a href="<?php the_latex_url( 'single_post', get_the_ID() ) ?>">PDF Version</a>`
5. For more advanced usage (user-defined templates and generating Latex
documents for pages, categories and others) see the [Frequently Asked Questions][faq].

[the-loop]: http://codex.wordpress.org/The_Loop
	"Documentation for The Loop in WordPress."
[faq]: http://wordpress.org/extend/plugins/latex-everything/faq/
	"FAQ for Latex Everything."

== Frequently Asked Questions ==

= Why isn't this working? =

If something isn't working, check this FAQ for a relevant answer. If there isn't one,
create a new topic in the [plugin forum][forum] and the author will try to get back to you.

[forum]: http://wordpress.org/tags/latex-everything?forum_id=10#postform
	"The forum where questions can be asked about Latex Everything."

= How do I typeset things besides posts? =

Go to `Settings->Reading`. There is a "Latex Everything" section where you can
choose which documents Latex Everything creates. By default only "Single Posts"
option is selected, but you can typeset other post types (including custom ones),
documents containing every post in a category (or other taxonomies), and
documents containing every post of a specific post type. 

There are in fact three broad types of document Latex Everything produces:

* `single_post` -- Each document contains a single post (this can be a page or a
custom post type).
* `post_type` -- Each document contains every post of a particular type (e.g. a
post, a page, or a custom post type).
* `term` -- Each post contains every post belonging to a specific term (e.g. a
category, tag, or term in a custom taxonomy).

You need to know what sort of document you're looking for when getting its url.

= When are the PDF files generated? =

PDF files that contain a post are updated when it is saved. PDF files are also
generated in bulk after the plugin is activated (this uses WP-Cron, so it takes
a while). If you have a large number of posts and want to generate PDF files for
all of them, deactivate and reactivate the plugin, then wait.

= How do I link to the generated documents? =

Functions have been provided to link to the generated PDFs.

* `the_latex_url( $type, $arg1, [$arg2])` -- Prints a direct link to the PDF.
* `get_latex_url( $type, $arg1, [$arg2])` -- Returns a direct link to the PDF.
* `the_latex_permalink( $type, $arg1, [$arg2])` -- Prints a link to the
attachment page.
* `get_latex_permalink( $type, $arg1, [$arg2])` -- Returns a link to the
attachment page.
* `get_latex_attachment_id( $type, $arg1, [$arg])` -- Returns the id of the
attachment.

The arguments you give depend on the type of PDF document you're requesting:

For a `single_post` PDF:

    the_latex_url( 'single_post', (int) $post_id )

For a `post_type` PDF:  
    
	the_latex_url( 'post_type', (string) $post_type )

For a `term` PDF:  
    
	the_latex_url( 'term', (int) $term_id, (string) $taxonomy)

Here's how you would use them in The Loop.

    <a href="<?php the_latex_url( 'single_post', get_the_ID() ) ?>">PDF of this post</a>
    <a href="<?php the_latex_url( 'post_type', get_post_type() ) ?>">PDF of all posts</a>
    <?php foreach( get_the_category() as $category ) : ?>
    <a href="<?php the_latex_url( 'term', $category->cat_ID, 'category' ) ?>">PDF of a category</a>
    <?php endforeach; ?>

NB: These don't automatically figure out which post you're on while in The Loop,
you must always give all of the arguments

If you are going to use these functions in a theme, check they exist and produce
a url first:

    <?php if( function_exists('get_latex_url')
              && $latex_url = get_latex_url( 'single_post', get_the_ID() ) ): ?>
    <a href="<?php echo $latex_url ?>">PDF</a>
    <?php endif; // get_latex_permalink ?>

= How do I use my own Latex Templates? =

Latex Everything has a default template inside the plugin directory, but it only
falls back on that if it doesn't find templates in the theme directory. The
plugin searches for templates in the same way that Wordpress does.

For a `single_post` PDF:  

    latex-single-<post_type>-<post id>.php 
    latex-single-<post_type>-<post slug>.php 
    latex-single-<post_type>.php 
    latex-single.php 
    latex.php 


For a `post_type` PDF:

    latex-post-type-<post type name>.pdf
    latex-post-type.pdf
    latex.pdf

For a `term` PDF:

    latex-term-<taxonomy>-<term id>.pdf
    latex-term-<taxonomy>-<term slug>.pdf
    latex-term-<taxonomy>.pdf
    latex-term.pdf
    latex.pdf

Look at `default-latex-template.php` in the plugin directory for guidance as to
how to make your own.

= How can I extend this? =

The plugin has been built with the intention of being extensible. The internals
have documentation in comments, and if you want to know how to do something that
isn't obvious, create a new topic in the [plugin forum][forum]
and the author will try to get back to you.

[forum]: http://wordpress.org/tags/latex-everything?forum_id=10#postform
	"The forum where questions can be asked about Latex Everything."

== Screenshots ==

1. The options page.
2. The default generated output.

== Changelog ==

= 1.2 =
* Changed the URL at which the PDFs for new posts and post types are saved. This
  prevents the PDF for a post entitled "Post" being overwritten by the PDF for
  the post type "Post". Existing PDFs and updates to them will stay at their
  current URL.

= 1.1 =
* Fixed a bug where PDFs for custom post types would not be generated.

= 1.0 =
* First released version.

== Upgrade Notice ==

= 1.2 =
This version changes the URL at which new PDFs are written. This will not affect
existing PDFs or updates to them, which will remain at their current URL.

= 1.1 =
This version fixes a bug where PDFs for custom post types would not be generated.

= 1.0 =
First stable version.
