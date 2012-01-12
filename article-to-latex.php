<?php
/*
Plugin Name: Article to Latex
Plugin URI: 
Version: 0.1
Author: Devon Buchanan
Author URI: 
Description: 
License: GPL
*/

/* Latex documents redered by html2latex.pl.
 * Documents saved using attachment API (see "Attachments" header)
 * http://codex.wordpress.org/Function_Reference#Post.2C_Custom_Post_Type.2C_Page.2C_Attachment_and_Bookmarks_Functions
 * Probably want to create a rewrite rule so that the pdf attachment is at 
 * "/pdf" under the post itself. Use rewrite API 
 * (http://codex.wordpress.org/Rewrite_API)
 */

/* function make_pdf_from_post ($ID) {
    
} */

class Divinenephron_Article_To_Latex {

    var $plugin_dir;
    var $html_to_latex_script;

    var $post;

    var $html_file;
    var $latex_file;
    var $pdf_file;
    var $uploaded_file;

    var $error;


    function __construct ( $post_id ) {
        $this->plugin_dir = plugin_dir_path(__FILE__);
        $this->html_to_latex_script = $this->plugin_dir . 'html2latex.pl';

        $this->post = get_post( $post_id );
    }

    function __destruct () {
        // Unlink temporary files
        unlink( $this->html_file );
        unlink( $this->latex_file );
        unlink( $this->pdf_file );
        unlink( $this->html_file . '.aux' );
        unlink( $this->html_file . '.log' );
    }

    function create_pdf() {
        $html_result = $this->make_html_file();
        if ( is_wp_error( $html_result ) ) {
            $this->record_error( $html_result );
            return;
        }
    
        $latex_result = $this->html_file_to_latex_file();
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
    
    function make_html_file () {
        $html = $this->post->post_content;
        $html = apply_filters('the_content', $html);
        $html = str_replace(']]>', ']]&gt;', $html);

        if ( !$this->html_file = tempnam( sys_get_temp_dir(), 'a2l-' ) ) // Should fall back on system's temp dir if /tmp does not exist
	    return new WP_Error( 'tempnam', 'Could not create temporary file.' );

	if ( !$f = @fopen( $this->html_file, 'w' ) )
		return new WP_Error( 'fopen', 'Could not open TEX file for writing' );
	if ( false === @fwrite($f, $html) )
		return new WP_Error( 'fwrite', 'Could not write to TEX file' );
	fclose($f);

	return $this->html_file;
    }
    
    function html_file_to_latex_file () {
    
        $dir = dirname( $this->html_file );
        $cmd = sprintf( 'cd %s; perl %s %s 2>&1', $dir, $this->html_to_latex_script, $this->html_file );

        exec( $cmd, $h2l_output, $v );

        if ( $v != 0 ) { // There was an error
            $h2l_output = join("\n", $h2l_output);
            return new WP_Error('html2latex.pl', $h2l_output);
        }

        $this->latex_file = $dir . '/' . basename($this->html_file) . '.tex';
        return $this->latex_file;
    }
    
    function latex_file_to_pdf_file () {
        $tmp_file = tempnam( '/tmp', 'atl'); // Falls back on system temp dir
        $dir = dirname( $this->latex_file );
        $cmd = sprintf( 'cd %s; /usr/texbin/pdflatex --interaction=nonstopmode %s 2>&1', $dir, $this->latex_file);

        exec( $cmd, $latex_output, $v );

        if ( $v != 0 ) { // There was an error
            $latex_output = join( "\n", $latex_output );
            return new WP_Error( 'pdflatex', $latex_output );
        }

        $this->pdf_file = $dir . '/' . basename($this->latex_file, '.tex') . '.pdf';
        return $this->pdf_file;
    
    }

    function attach_pdf_file () {
        $filename = 'a2l-postie-' . $this->post->ID . '.pdf';

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
        printf( "<div class=\"error\"><p>Article to Latex error</p><p>%s</p><p>%s</p></div>\n", $this->error->get_error_code(), $this->error->get_error_message() );
    }
}

/* Every time a post is saved run the converter.
 */
function a2l_save_post ( $post_id ) {
    if ( get_post_type( $post_id ) == 'post' && !wp_is_post_revision( $post_id ) ) {
        $a2l = new Divinenephron_Article_To_Latex( $post_id );
        $a2l->create_pdf();
    }
}
add_action('save_post', 'a2l_save_post');

/* If a2l_error is in the query, a previous post save created an error
 * for a post (the post ID is its value). Thus we need to re-run the
 * converter to figure out what the error was and display it.
 */
function a2l_show_errors () {
    global $wp_query;
    if ( isset( $_GET['a2l_error'] ) ) {
        $post_id = $_GET[ 'a2l_error' ];
        $a2l = new Divinenephron_Article_To_Latex( $post_id );
        $a2l->create_pdf();
    }
}
add_action( 'admin_head', 'a2l_show_errors' );

