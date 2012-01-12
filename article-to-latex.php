<?php
/*
Plugin Name: Article to Latex
Plugin URI: 
Version: 0.1
Author: Divinenephron (Devon Buchanan)
Author URI: http://divinenephron.co.uk
Description: 
License: GPL
*/

// TODO: Make documentation of API and install process.
// TODO: Extend generation to object other than posts - pages? categories? You'd just need to extend the
//       templating and the "get latex stuff" API. You might also have to use WP-Cron more (more PDF 
//       being written at once), and hence improve the error handling to actually save error messages.
         

define( 'PLUGIN_DIR', plugin_dir_path( __FILE__ ) );            // Plugin directory with trailing slash
define( 'PDFLATEX', '/usr/texbin/pdflatex' );

include('html-to-latex.php'); // Include functions to convert html to latex.


class A2l_Article_To_Latex {

    var $post;

    var $latex_file;
    var $pdf_file;
    var $uploaded_file;

    var $error;

    var $unwanted_filter_functions = Array( 'wptexturize', 'convert_chars', 'esc_html', 'ent2ncr', '_wp_specialchars', 'sanitize_text_field', 'capital_P_dangit' );
    var $removed_filters;

    function __construct ( $post_id ) {
        $this->post = get_post( $post_id );
    }

    function __destruct () {
        // Unlink temporary files
        /*
        unlink( $this->latex_file );
        unlink( $this->pdf_file );
        unlink( $this->latex_file . '.aux' );
        unlink( $this->latex_file . '.log' );
        */
    }

    function activate () {
        // Create cron-jobs to re-create the pdf for every post.
        $args = Array( 'post-type' => 'post',
                      'numberposts' => -1,
                      'orderby' => 'post_date',
                      'order' => 'DESC',
                      'post_status' => null,
                    );
        $all_posts = get_posts( $args );
        foreach ( $all_posts as $post )
            wp_schedule_single_event( time(), 'a2l_activation_update_post', Array( $post->ID ) );
    }

    function deactivate () {
        // Remove all cron jobs.
        wp_clear_scheduled_hook('a2l_activation_update_post');
    }

    function create_pdf() {
    
        $latex_result = $this->make_latex_file();
        if ( is_wp_error( $latex_result ) ) {
            $this->record_error( $latex_result );
            return;
        }
    
        $pdf_result = $this->latex_file_to_pdf_file();
        if ( is_wp_error( $pdf_result ) ) {
            $this->record_error( $pdf_result );
            return;
        }
    
        $attach_id = $this->attach_pdf_file();
        if ( is_wp_error( $attach_id ) ) {
            $this->record_error( $pdf_result );
            return;
        }
        return;
    }

    function make_latex_file () {

        $template = $this->_get_latex_template();

        // Render the template        
        query_posts( 'p=' . $this->post->ID );
        $this->_set_up_latex_filters();
        ob_start();
        include( $template );
        $latex = ob_get_clean();
        $this->_undo_latex_filters();
        wp_reset_query();

        // Get the name of a temporary file.

        if ( !$this->latex_file = tempnam( sys_get_temp_dir(), 'a2l-' ) ) // Should fall back on system's temp dir if /tmp does not exist
	    return new WP_Error( 'tempnam', 'Could not create temporary file.' );
        $dir = dirname( $this->latex_file );

        // Open and write the latex the temporary file.
        if ( !WP_DEBUG )
            $f = @fopen( $this->latex_file, 'w' );
        else
            $f = fopen( $this->latex_file, 'w' );
	if ( !$f )
		return new WP_Error( 'fopen', 'Could not open temporary file for writing' );
        if ( ! WP_DEBUG )
            $w = @fwrite($f, $latex);
        else
            $w = fwrite($f, $latex);
	if ( !$w )
		return new WP_Error( 'fwrite', 'Could not write to temporary file' );
	fclose($f);

        return $this->latex_file;
    }

    function _get_latex_template () {
        $template = get_query_template('latex');
        if ( empty( $template) ) {
            $template = PLUGIN_DIR . 'default-template.php';
        }
        return $template;
    }

    /* Removes filters that interfere with Latex while processing Latex templates.
     * Records which filters were removed so that they can be added again later.
     */
    function _set_up_latex_filters() {
        global $wp_filter;
        foreach(         $wp_filter  as $tag      => $priorities ) {
            foreach(     $priorities as $priority => $filters ) {
                foreach( $filters    as $name     => $filter ) {
                    if ( in_array( $filter['function'], $this->unwanted_filter_functions ) ) {
                        $this->removed_filters[$tag][$priority][$name] = $filter;
                        unset( $wp_filter[$tag][$priority][$name] );
                    }
                }
            }
        }
        return;
    }
    
    /* Add the filters back in again once Latex template processing has finished.
     */
    function _undo_latex_filters() {
        global $wp_filter;
        // (Doesn't quite get priorities right)
        $wp_filter = array_merge_recursive( $wp_filter, $this->removed_filters );
        return;
    }
    
    function latex_file_to_pdf_file () {
        $tmp_file = tempnam( '/tmp', 'atl'); // Falls back on system temp dir
        $dir = dirname( $this->latex_file );

        // Tell Latex to search in the theme directory for class/style files.
        putenv('TEXINPUTS=.:' . get_stylesheet_directory() . ':' . get_template_directory() . ':');
        $cmd = sprintf( 'cd %s; %s --interaction=nonstopmode %s 2>&1', $dir, PDFLATEX, $this->latex_file);

        exec( $cmd, $latex_output, $v );

        if ( $v != 0 ) { // There was an error
            $latex_output = join( "\n", $latex_output );
            return new WP_Error( 'pdflatex', $latex_output );
        }

        $this->pdf_file = $dir . '/' . basename($this->latex_file, '.tex') . '.pdf';
        return $this->pdf_file;
    }

    function attach_pdf_file () {
        $filename = 'a2l-post-' . $this->post->ID . '.pdf';

        $wp_filetype = wp_check_filetype( '.pdf' );
        $attachment_data = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title' => $this->post->post_title,
            'post_name' => 'pdf',
            'post_content' => '',
            'post_status' => 'inherit',
        );

        /* Check whether this post has an older file and attachment
         * object we can update
         */
        $args = array( 'post_type' => 'attachment', 'numberposts' => -1, 'post_status' => null, 'post_parent' => $this->post->ID ); 
        $attachments = get_posts($args);
        if ($attachments) {
            foreach ( $attachments as $attachment ) {
                $attached_file = get_attached_file( $attachment->ID );
                if ( basename( $attached_file ) == $filename ) {
                    $this->uploaded_file = $attached_file;
                    $attachment_data['ID'] = $attachment->ID;
                }
            }
        }

        // If it doesn't, find a location for a new file
        if ( empty( $this->uploaded_file ) ) {
            $upload_dir = wp_upload_dir();
            $this->uploaded_file = $upload_dir['path'] . '/' . $filename;
        }

        
        // Create the attachment
        copy( $this->pdf_file, $this->uploaded_file );
        $attach_id = wp_insert_attachment( $attachment_data, $this->uploaded_file, $this->post->ID );
        if ( $attach_id == 0 ) { // Attachment error
            return WP_Error( 'wp_insert_attachment', 'Could not attach generated pdf' );
        }
        add_post_meta( $attach_id, '_a2l_is_latex', 1, true );
        return $attach_id;
    }


    /* Adds a2l_error to the redirect query when the error is first encounterd.
     * After the redirect it prints the errors to the top of the admin page.
     */
    function record_error ( $error ) {
        if ( isset( $_GET['a2l_error'] ) ) {
            add_action( 'admin_notices', array( $this, 'display_error' ) );
        } else { // First time the error has been encountered.
            add_action('redirect_post_location', array( $this, 'change_redirect_query' ), 99 );
        }
        $this->error = $error;
    }
    /* Add a query to the redirect after the post has been saved to remind the post edit
     * screen to re-run the pdf create so that the error can be displayed.
     */
    function change_redirect_query ($location) {
        return add_query_arg('a2l_error', $this->post->ID, $location);
    }
    /* Displays the error on the admin screen.
     */
    function display_error () {
        printf( "<div class=\"error\"><p>Article to Latex error: %s</p><pre>%s</pre></div>\n", $this->error->get_error_code(), $this->error->get_error_message() );
    }
}

/* Update the post. Run when a post is saved, and when the plugin is activated.
 */
function a2l_update_post ( $post_id ) {
    if ( get_post_type( $post_id ) == 'post' && !wp_is_post_revision( $post_id ) ) {
        global $a2l;
        $a2l = new A2l_Article_To_Latex( $post_id );
        $a2l->create_pdf();
    }
}
add_action('save_post', 'a2l_update_post');
add_action('a2l_activation_update_post', 'a2l_update_post');

/* If a2l_error is in the query, a previous post save created an error
 * for a post (the post ID is its value). Thus we need to re-run the
 * converter to figure out what the error was and display it.
 */
function a2l_show_errors () {
    global $wp_query;
    if ( isset( $_GET['a2l_error'] ) ) {
        $post_id = $_GET[ 'a2l_error' ];
        global $a2l;
        $a2l = new A2l_Article_To_Latex( $post_id );
        $a2l->create_pdf();
    }
}
add_action( 'admin_head', 'a2l_show_errors' );

register_activation_hook( __FILE__, Array( 'A2l_Article_To_Latex', 'activate' ) );
register_deactivation_hook( __FILE__, Array( 'A2l_Article_To_Latex', 'deactivate' ) );

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
                   'meta_key' => '_a2l_is_latex',
                   'meta_value' => 1,
                   ); 
    $attachments = get_posts($args);
    
    if (!$attachments)
        return false;

    return $attachments[0];
}
?>
