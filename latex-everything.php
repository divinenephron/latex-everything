<?php
/*
   Plugin Name: Latex Everything
   Plugin URI: http://wordpress.org/extend/plugins/latex-everything
   Version: 1.3
   Author: Divinenephron (Devon Buchanan)
   Author URI: http://divinenephron.co.uk
   Description: Produce PDF documents of everything on your site with Latex.
   License: GPLv3
 */

global $latex_everything;
$latex_everything = new Latex_Everything;

include('latex-single-posts.php');
include('latex-post-types.php');
include('latex-terms.php');

/* Latex_Everything
 * - - - - - - - - 
 *
 * This class decides when to make documents and tells us where they are.
 *
 * LE_Latex_<type>_Controller
 * - - - - - - - - - - - -
 * These classes are used by Latex_Everything to decide which documents
 * need to be created. There is one for each document type (post_type,
 * term, single_post).
 * 
 * They are added to Latex_Everything with a function call, e.g.
 *
 *      global $latex_everything;
 *      $latex_post_type_controller = new LE_Latex_Post_Type_Controller();
 *      $latex_everything->add_controller( 'post_type', $latex_post_type_controller );
 *
 * These controllers need to define certain methods to work
 * 
 * documents_for_post( $post_id )
 *      Return an array of new documents. Latex_Everything will call generate() with them.
 * 
 * get_document( arg1, [$arg2] )
 *      Return a single document object.
 *
 * get_settings()
 *      Return an array containing the settings this controller cares about. It is in the form:
 *      array( [0] => array( 'name' => "le_post_type_{$post_type}",
 *                           'title' => "All {$post_type_obj->labels->name}" ),
 *             [1] => ... );
 *      Each 'name' index corresponds to a document the controller might generate (e.g. a document
 *      containing a single post, all posts with a specific tag, or all posts of a custom type).
 *      The user is able to activate or dactivate their generation with this setting, which should
 *      be checked with in documents_for_post(). The 'title' index is a human-readable description.
 */

/* When the plugin is activated, create cron jobs to create the desired pdf.
 */
class Latex_Everything {
    
    var $controllers;

    function __construct () {
        register_activation_hook( __FILE__, array( &$this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( &$this, 'deactivate' ) );
        
        add_action('save_post', array( &$this, 'update_post' ) );
        add_action('le_activation', array( &$this, 'update_post' ) );

        add_action('admin_init', array( &$this, 'settings_api_init' ) );

        $this->controllers = array();
    }

    function add_controller( $name, &$controller ) {
        $this->controllers[$name] = $controller;
    }

    function remove_controller( $name ) {
        unset( $this->controllers[$name] );
    }

    /* Create cron-jobs to re-create the pdf for every post.
     */
    function activate () {
        // Set the post_type option to 1 if it doesn't already exist
        $option = get_option( 'le_single_post_post', "doesn't exist" );
        if ( $option == "doesn't exist" ) {
            update_option( 'le_single_post_post', 1 );
        }

        // Schedule the creation of pdfs for every post.
        $args = Array( 'post-type' => null,
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

        foreach ( $this->controllers as $controller )
            $needed_settings = array_merge( $needed_settings, $controller->get_settings() );
       
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

        foreach( $this->controllers as $controller ) {
            $docs = array_merge( $docs, $controller->documents_for_post( $post_id ) );
        }

        //error_log( var_export( $docs, true ) );
        foreach ( $docs as $doc ) {
            if ( is_wp_error( $doc ) ) {
                $this->handle_error( $doc );
                continue;
            }
            $error = $doc->generate();
            if ( $error ) {
                $this->handle_error( $error );
                continue;
            }
        }
    }

    function handle_error( $error ) {
        error_log( "{$error->get_error_code()}: {$error->get_error_message()}" );
    }

    function get_document( $type /*, [...] */ ) {
        $args = array_slice( func_get_args(), 1 );
        $controller = $this->controllers[$type];
        $doc = call_user_func_array( array( &$controller, 'get_document' ), $args );
        if ( is_wp_error( $doc ) ) {
            $this->handle_error( $doc );
            return 0;
        }
        return $doc;

    }

    function get_latex_permalink( $type /*, [...] */ ) {
        $doc = call_user_func_array( array( &$this, 'get_document' ), func_get_args() );
        if ( $doc )
            return $doc->get_permalink();
        return '';
    }

    function get_latex_url( $type /*, [...] */ ) {
        $doc = call_user_func_array( array( &$this, 'get_document' ), func_get_args() );
        if ( $doc ) {
            return $doc->get_url();
        }
        return '';
    }   
    
    function get_latex_attachment_id( $type /*, [...] */ ) {
        $doc = call_user_func_array( array( &$this, 'get_document' ), func_get_args() );
        if ( $doc )
            return $doc->get_attachment_id();
        return '';
    }
}

/* API */

/* Echos the permalink to the attachment page for the given thing.
 * (Not a direct link to the pdf, use get_latex_url() for that).
 */
function the_latex_permalink($type /*, [...] */ ) {
    echo call_user_func_array( 'get_latex_permalink', func_get_args() );
}


/* Returns the permalink for the given thing (not a direct link
 * to the pdf, use get_latex_url() for that).
 */
function get_latex_permalink($type /*, [...] */ ) {
    global $latex_everything;
    return call_user_func_array( array( &$latex_everything, 'get_latex_permalink' ),
                                 func_get_args() );
}

/* Echos a direct link to the pdf for the given thing.
 */
function the_latex_url($type /*, [...] */ ) {
    echo call_user_func_array( 'get_latex_url', func_get_args() );
}

/* Returns a direct link to the pdf for the given thing.
 * ID.
 */
function get_latex_url( $type /*, [...] */ ) {
    global $latex_everything;
    return call_user_func_array( array( &$latex_everything, 'get_latex_url' ),
                                 func_get_args() );
}

/* Returns the id of the latex pdf attachment for the given thing.
 *
 */
function get_latex_attachment_id( $type /*, [...] */ ) {
    global $latex_everything;
    return call_user_func_array( array( &$latex_everything, 'get_latex_attachment_id' ),
                                 func_get_args() );
}
