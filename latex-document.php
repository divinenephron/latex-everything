<?php

define( 'PLUGIN_DIR', plugin_dir_path( __FILE__ ) );            // Plugin directory with trailing slash
define( 'PDFLATEX', '/usr/texbin/pdflatex' );

define( 'LE_POST_TYPE', 1 );
define( 'LE_TERM_TYPE', 2 );

include('html-to-latex.php'); // Include functions to convert html to latex.

class LE_Latex_Document {

    var $source;
    var $doc_type;

    var $latex_file;
    var $pdf_file;
    var $uploaded_file;

    var $unwanted_filter_functions = Array( 'wptexturize', 'convert_chars', 'esc_html', 'ent2ncr', '_wp_specialchars', 'sanitize_text_field', 'capital_P_dangit' );
    var $removed_filters;

    function __construct ( $id, $taxonomy='' ) {
        if ( !$taxonomy ) { // Have post
            $this->doc_type = LE_POST_TYPE;
            $source = get_post( $id );
        } else {            // Have term
            $this->doc_type = LE_TERM_TYPE;
            $source = get_term( $id, $taxonomy );
        }
        if ( is_wp_error( $source ) )
            return $source;
        $this->source = $source;
        return 0;
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
    
        error_log( "Before latex_file_to_pdf_file()" );
        $pdf_result = $this->latex_file_to_pdf_file();
        if ( is_wp_error( $pdf_result ) ) {
            return $pdf_result;
        }
    
        $attach_id = $this->attach_pdf_file();
        if ( is_wp_error( $attach_id ) ) {
            return $attach_id;
        }
        error_log( "After attach_pdf_file()" );
        return 0; // Return no error
    }

    function make_latex_file () {
        $template = $this->_get_latex_template();

        // Render the template        
        if ( $this->doc_type == LE_POST_TYPE ) {
            $args = array(  'orderby'        => 'date',
                            'order'          => 'DESC',
                            'posts_per_pate' => -1,
                            );
            if ( $this->source->post_type == 'page' )
                $args['page_id'] = $this->source->ID;
            else
                $args['p'] = $this->source->ID;
        } else {
            $args = array( 'tax_query'      => array( array(
                                                'taxonomy' => $this->source->taxonomy,
                                                'field' => 'id',
                                                'terms' => $this->source->term_id, )),
                           'orderby'        => 'date',
                           'order'          => 'DESC',
                           'posts_per_page' => -1,
                           );
        }
        query_posts( $args );
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
        error_log( "latex_file_to_pdf_file" );
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
        error_log( "attach_pdf_file()" );
        if ( $this->doc_type == LE_POST_TYPE ) {
            $filename = $this->source->post_name . '.pdf';
            $title = $this->source->post_title;
            $parent = $this->source->ID;
            error_log( "Filename: {$filename}" );
            error_log( "Title: {$title}" );
            error_log( "Parent: {$parent}" );
        } else {
            $filename = $this->source->slug . '.pdf';
            $title = "{$this->source->taxonomy} {$this->source->name}";
            $parent = 0;
        }

        $wp_filetype = wp_check_filetype( '.pdf' );
        $attachment_data = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title' => $title,
            'post_name' => 'pdf',
            'post_content' => '',
            'post_status' => 'inherit',
        );

        // Check whether this post has an older file and attachment
        // object we can update
        if ( $this->doc_type == LE_POST_TYPE ) {
            $args = array( 'post_type' => 'attachment',
                           'numberposts' => -1,
                           'post_status' => null,
                           'post_parent' => $this->source->ID );
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
        }

        // If it doesn't, find a location for a new file
        if ( empty( $this->uploaded_file ) ) {
            $upload_dir = wp_upload_dir();
            $this->uploaded_file = $upload_dir['path'] . '/' . $filename;
        }
        
        // Create the attachment
        copy( $this->pdf_file, $this->uploaded_file );
        $attach_id = wp_insert_attachment( $attachment_data, $this->uploaded_file, $parent );
        if ( $attach_id == 0 ) { // Attachment error
            return WP_Error( 'wp_insert_attachment', 'Could not attach generated pdf' );
        }
        add_post_meta( $attach_id, '_le_is_latex', 1, true );
        return $attach_id;
    }
}
?>
