<?php

define( 'PLUGIN_DIR', plugin_dir_path( __FILE__ ) );            // Plugin directory with trailing slash
define( 'PDFLATEX', '/usr/texbin/pdflatex' );

include('html-to-latex.php'); // Include functions to convert html to latex.

class LE_Latex_Document {

    var $post;

    var $latex_file;
    var $pdf_file;
    var $uploaded_file;

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


    function generate() {
    
        $latex_result = $this->make_latex_file();
        if ( is_wp_error( $latex_result ) ) {
            return $latex_result;
        }
    
        $pdf_result = $this->latex_file_to_pdf_file();
        if ( is_wp_error( $pdf_result ) ) {
            return $pdf_result ;
        }
    
        $attach_id = $this->attach_pdf_file();
        if ( is_wp_error( $attach_id ) ) {
            return $pdf_result ;
        }
        return 0; // Return no error
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

        if ( !$this->latex_file = tempnam( sys_get_temp_dir(), 'le-' ) ) // Should fall back on system's temp dir if /tmp does not exist
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
        $filename = 'le-post-' . $this->post->ID . '.pdf';

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
        add_post_meta( $attach_id, '_le_is_latex', 1, true );
        return $attach_id;
    }
}
?>
