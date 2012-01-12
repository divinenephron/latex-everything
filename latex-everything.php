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
// TODO: Update the API to allow access to the taxonomy and post_type pdfs.


include('latex-document.php');

global $latex_everything;
$latex_everything = new Latex_Everything;

/* When the plugin is activated, create cron jobs to create the desired pdf.
 */
class Latex_Everything {

    function __construct () {
        register_activation_hook( __FILE__, array( &$this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( &$this, 'deactivate' ) );
        
        add_action('save_post', array( &$this, 'update_post' ) );
        add_action('le_activation', array( &$this, 'update_post' ) );

        add_action('admin_init', array( &$this, 'settings_api_init' ) );

    }

    /* Create cron-jobs to re-create the pdf for every post.
     */
    function activate () {
        // Set the post_type option to 1 if it doesn't already exist
        $option = get_option( 'le_post_type_post', "doesn't exist" );
        if ( $option == "doesn't exist" ) {
            update_option( 'le_single_post', 1 );
        }

        // Schedule the creation of pdfs for every post.
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

    function settings_api_init() {

        // Add the section to reading settings so we can add our
 	// fields to it
 	add_settings_section('le_setting_section',
		'Latex Everything',
		array( &$this, 'setting_section' ),
		'reading');
 	
        // Record which taxonomies and post types are defined (excluding certain ones).
        $needed_settings = array();
        $taxonomies = get_taxonomies( '', 'names' );
        $taxonomies = array_diff( $taxonomies, array( 'nav_menu', 'link_category', 'post_format' ) );
        foreach ( $taxonomies as $taxonomy ) {
            $taxonomy_obj = get_taxonomy( $taxonomy );
            if ( $taxonomy_obj ) {
                $needed_settings[] = array( 'name' => "le_taxonomy_{$taxonomy}",
                                            'title' => "Single {$taxonomy_obj->labels->name}" );
            }
        }
        $post_types = get_post_types( '', 'names' );
        $post_types = array_diff( $post_types, array( 'mediapage', 'attachment', 'revision', 'nav_menu_item' ) );
        foreach ( $post_types as $post_type ) {
            $post_type_obj = get_post_type_object( $post_type );
            if ( $post_type_obj ) {
                $needed_settings[] = array( 'name' => "le_post_type_{$post_type}",
                                            'title' => "All {$post_type_obj->labels->name}" );
                $needed_settings[] = array( 'name' => "le_single_{$post_type}",
                                            'title' => "Single {$post_type_obj->labels->name}" );
            }
        }
       
        foreach ( $needed_settings as $setting ) {
            add_settings_field( $setting['name'],
                    $setting['title'],
                    array( &$this, 'setting' ),
                    'reading',
                    'le_setting_section',
                    array( 'name' => $setting['name'] ) );
 	    register_setting('reading', $setting['name'] );
        }
    }

   /* Prints a description at the top of the setting section.
    */
    function setting_section() {
           echo '<p>Generate documents containing:</p>';
    }
    
    /* Creates a checkbox for the option $args['name'].
     */
     function setting( $args ) {
            echo '<input name="' . $args['name'] . '" id="' . $args['name'] . '" type="checkbox" value="1" class="code" ' . checked( 1, get_option( $args['name'], 0 ), false ) . '>';
     }

    function update_post ( $post_id ) {
        $docs = array();

        // Find out which entities are affected, and make a new document for them.
        $post_type = get_post_type( $post_id );
        if( get_option( "le_single_{$post_type}" ) )
            $docs[] = new LE_Latex_Single_Document( $post_id );
        if ( get_option( "le_post_type_{$post_type}" ) )
            $docs[] = new LE_Latex_Post_Type_Document( $post_type );

        foreach( get_taxonomies() as $taxonomy ) {
            if( get_option( "le_taxonomy_{$taxonomy}" ) && $terms = get_the_terms( $post_id, $taxonomy ) ) {
                if( is_wp_error( $terms ) ) {
                    $this->handle_error( $terms );
                    continue;
                }
                foreach( $terms as $term )
                    $docs[] = new LE_Latex_Term_Document( $term->term_id, $taxonomy );
            }
        }

        foreach ( $docs as $doc ) {
            if ( is_wp_error( $doc ) ) {
                $this->handle_error( $doc );
                continue;
            }
            if ( $error = $doc->generate() ) {
                $this->handle_error( $error );
                continue;
            }
        }
    }

    function handle_error( $error ) {
        error_log( "{$error->get_error_code()}: {$error->get_error_message()}" );
    }
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
