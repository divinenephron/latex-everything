<?php
/*
   Plugin Name: Latex Everything
   Plugin URI: 
   Version: 0.1
   Author: Divinenephron (Devon Buchanan)
   Author URI: http://divinenephron.co.uk
   Description: 
   License: GPL
 */

// TODO: Make documentation of API and install process.
// TODO: Oh fsck, Latex wasn't supposed to typeset multiple papers. Find a way around it. Probably just typset the buggers seperately and glueing them together with combine.

global $latex_everything;
$latex_everything = new Latex_Everything;

/* When the plugin is activated, create cron jobs to create the desired pdf.
 */
class Latex_Everything {

    var $taxonomies;
    var $post_types;

    function __construct () {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
        
        add_action('save_post', array( $this, 'update_post' ) );
        add_action('le_activation', array( $this, 'update_post' ) );
        add_action('init', array( $this, 'get_everything' ) );

        //add_action( 'admin_head', array( &$this, 'show_errors' ) );
    }

    function get_everything () {
        // Find all entities you can generate stuff for
        $this->taxonomies = get_taxonomies( '', 'names' );
        $this->taxonomies = array_diff( $this->taxonomies, array( 'nav_menu', 'link_category', 'post_format' ) );
        $this->post_types = get_post_types( '', 'names' );
        $this->post_types = array_diff( $this->post_types, array( 'mediapage', 'attachment', 'revision', 'nav_menu_item' ) );
    }
    
    /* Create cron-jobs to re-create the pdf for every post.
     */
    function activate () {
        $args = Array( 'post-type' => 'post',
                'numberposts' => -1,
                'orderby' => 'post_date',
                'order' => 'DESC',
                'post_status' => null,
                );
        $all_posts = get_posts( $args );
        foreach ( $all_posts as $post )
            wp_schedule_single_event( time(), 'le_activation', Array( $post->ID ) );
    }

    /* Remove any remaining cron jobs on deactivation
     */
    function deactivate () {
        // Remove all cron jobs.
        wp_clear_scheduled_hook('le_activation');
    }

    /*  Create a latex document for a post/taxonomy.
     */
    function create_document ( $id, $taxonomy='' ) {
        // TODO: Re-do the error handling.
        include_once('latex-document.php');
        $doc = new LE_Latex_Document( $id, $taxonomy );
        if ( is_wp_error( $doc ) ) {
            error_log( "{$doc->get_error_code()}: {$doc->get_error_message()}" );
            return;
        }
        $error = $doc->generate();
        if (is_wp_error( $error ) ) {
            error_log( "{$error->get_error_code()}: {$error->get_error_message()}" );
            return;
        }
    }

    function update_post ( $post_id ) {
        // Find out which entities are affected
        if( in_array( get_post_type( $post_id ), $this->post_types ) ) {
            $this->create_document( $post_id );
        }
        foreach( $this->taxonomies as $taxonomy ) {
            if( $terms = get_the_terms( $post_id, $taxonomy ) ) {
                if( is_wp_error( $terms ) )
                    error_log( "{$terms->get_error_code()}: {$terms->get_error_message()}" );
                else
                    foreach( $terms as $term )
                        $this->create_document( $term->term_id, $taxonomy );
            }
        }
    }
        /* If le_error is in the query, a previous post save created an error
         * for a post (the post ID is its value). Thus we need to re-run the
         * converter to figure out what the error was and display it.
         */
    /*
        function show_errors () {
            global $wp_query;
            if ( isset( $_GET['le_error'] ) ) {
                $post_id = $_GET[ 'le_error' ];
                global $latex_everything;
                $le = new LE_Latex_Document( $post_id );
                $le->create_pdf();
            }
        }
    */
    /* Adds le_error to the redirect query when the error is first encounterd.
     * After the redirect it prints the errors to the top of the admin page.
     */
    /*
    function record_error ( $error ) {
        if ( isset( $_GET['le_error'] ) ) {
            add_action( 'admin_notices', array( $this, 'display_error' ) );
        } else { // First time the error has been encountered.
            add_action('redirect_post_location', array( $this, 'change_redirect_query' ), 99 );
        }
        $this->error = $error;
    }
    */
    /* Add a query to the redirect after the post has been saved to remind the post edit
     * screen to re-run the pdf create so that the error can be displayed.
     */
    /*
    function change_redirect_query ($location) {
        return add_query_arg('le_error', $this->post->ID, $location);
    }
     */
    /* Displays the error on the admin screen.
     */
    /*
    function display_error () {
        printf( "<div class=\"error\"><p>Article to Latex error: %s</p><pre>%s</pre></div>\n", $this->error->get_error_code(), $this->error->get_error_message() );
    }
    */
}

/* API */

/* Display the permalink to the Latex attachment page for the current post.
 * (not a direct link to the pdf, use get_latex_url() for that).
 */
function the_latex_permalink() {
    echo apply_filters('the_latex_permalink', get_latex_permalink());
}


/* Get the permalink for the current post or a post ID (not a direct link
 * to the pdf, use get_latex_url() for that).
 */
function get_latex_permalink($id = 0) {
    $attachment = get_latex_attachment( $post->ID );

    if( !$attachment )
        return '';

    return get_attachment_link($attachment->ID);
}

/* Returns a direct link to the Latex pdf for the current post or a post
 * ID.
 */
function get_latex_url( $id = 0 ) {
    $attachment = get_latex_attachment( $id );

    if( !$attachment )
        return false;

    return wp_get_attachment_url( $attachment->ID );
}

/* Returns the latex attachment for the current post or a 
 * post ID. Return false if this doesn't have one.
 */
function get_latex_attachment( $id=0 ) {
    if ( is_object($id) ) {
        $post = $id;
    } else {
        $post = &get_post($id);
    }
    if ( empty($post->ID) )
        return false;

    $args = array( 'post_type' => 'attachment',
            'numberposts' => 1,
            'post_parent' => $post->ID,
            'meta_key' => '_le_is_latex',
            'meta_value' => 1,
            ); 
    $attachments = get_posts($args);

    if (!$attachments)
        return false;

    return $attachments[0];
}
?>
