<?php

define( 'PLUGIN_DIR', plugin_dir_path( __FILE__ ) );            // Plugin directory with trailing slash

include_once('html-to-latex.php'); // Include functions to convert html to latex.

/* LE_Latex_Document
 * - - - - - - - - -
 *
 * The base class representing generated Latex attachments.
 *
 * This class isn't of much use on its own, but its subclasses define specific
 * behaviour that allow you to create and locate different generated Latex 
 * attachments.
 * 
 * Important methods for using LE_Latex_Document subclasses
 * - - - - - - - - - - - - - - - - - - - - - - - - - - - - 
 * 
 * new LE_Latex_Document_Subclass( [...] )
 *      Create a new document representing some Wordpress object.
 *      Args: whatever is needed to identify the object, depending on the subclass.
 *      Return: 0 on success, or a WP_Error object.
 *
 * generate()
 *      Creates the Latex document attachment.
 *      Return: 0 on succes, or a WP_Error object.
 * 
 * get_permalink()
 *      Return the permalink of the attachment.
 *      Return: String containing the permalink
 * 
 * get_url()
 *      Returns a direct link to the Latex pdf.
 *      Return: String containing the pdf url.
 *
 * Methods that must be defined by LE_Latex_Document subclasses
 * - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
 *
 * get_posts()
 *      Return an array of post objects to be typset for the document.
 *
 * typeset_all_files()
 *      Produce a single pdf file from the contents of $this->latex_files.
 *      Return a string containing its path.
 *
 * get_template()
 *      Return a string containing the path to the template to be used by
 *      the document.
 *
 * get_title()
 *      Return the title for the attachment (show on the attachment page and
 *      on the admin screen).
 *
 * get_name()
 *      Return the name of the attachment. This should be a slug. Used as the
 *      PDF file's name.
 *
 * get_parent_post_id()
 *      (optional) Return the id of the parent of the attachment, if this
 *      attachment has one.
 */

class LE_Latex_Document {

    var $pdflatex_path;

    var $latex_files;
    var $tmp_files;

    var $attachment_id;

    var $unwanted_filter_functions = Array( 'wptexturize', 'convert_chars', 'esc_html', 'ent2ncr', '_wp_specialchars', 'sanitize_text_field', 'capital_P_dangit' );
    var $removed_filters;

    function __construct () {

        $this->latex_files = array();
        $this->tmp_files = array();

        return 0;
    }

    /* Unlink temporary files when destroyed.
     */
    function __destruct () {
        // Unlink temporary files
        if ( ! WP_DEBUG ) {
            array_map( 'unlink', $this->latex_files );
            array_map( 'unlink', $this->tmp_files );
        }
    }

    /* This method is redefined by subclasses to generate and attach the pdf file.
     */
    function generate() {
        // Find pdflatex
        if ( !$this->pdflatex_path = exec( 'which pdflatex' ) )
            return new WP_Error( 'LE_Latex_Document::__construct', 'pdflatex not found' );

        // Generate single latex files for each of a term's posts, because Latex
        // wasn't designed to generate multi-article documents.
        $posts = $this->get_posts();

        // Generate latex files.
        foreach ( $posts as $post ) {
            $latex_result = $this->make_latex_file( $post->ID, $post->post_type );
            if ( is_wp_error( $latex_result ) )
                return $latex_result;
            $this->latex_files[] = $latex_result;
        }

        // Typeset latex files.
        $pdf_file = $this->typeset_all_files();
        if ( is_wp_error( $pdf_file ) )
            return $pdf_file;

        // Attach pdf file
        $attach_id = $this->attach_pdf_file( $pdf_file );
        if ( is_wp_error( $attach_id ) )
            return $attach_id;
        return 0; // Return no error
    }

    /* Writes a latex file for a single post.
     */
    function make_latex_file ($id, $post_type ) {
        $template = $this->get_template( );

        // Query the post
        $args = array(  'orderby'        => 'date',
                        'order'          => 'DESC',
                        );
        if ( $post_type == 'page' ) {
            $args['page_id'] = $id;
        } else {
            $args['p'] = $id;
            $args['post_type'] = $post_type;
        }
        query_posts( $args );
        
        global $wp_query;
        if ( $wp_query->post_count == 0 ) {
            return new WP_Error( 'query_posts', 'No posts were found for the query "'.http_build_query($args).'", so no Latex files were produced');
        }
        
        // Render the template
        $this->set_up_latex_filters();
        ob_start();
        include( $template );
        $latex = ob_get_clean();
        $this->undo_latex_filters();

        wp_reset_query();

        // Get the name of a temporary file.
        if ( !$latex_file = tempnam( sys_get_temp_dir(), 'le-' ) )
	    return new WP_Error( 'tempnam', 'Could not create temporary file.' );
        $dir = dirname( $latex_file );

        // Open and write the latex the temporary file.
        if ( !WP_DEBUG )
            $f = @fopen( $latex_file, 'w' );
        else
            $f = fopen( $latex_file, 'w' );
	if ( !$f )
		return new WP_Error( 'fopen', 'Could not open temporary file for writing' );
        if ( ! WP_DEBUG )
            $w = @fwrite($f, $latex);
        else
            $w = fwrite($f, $latex);
	if ( !$w )
		return new WP_Error( 'fwrite', 'Could not write to temporary file' );
	fclose($f);

        return $latex_file;
    }

    function get_template() {
        return PLUGIN_DIR . 'default-latex-template.php';
    }

    /* Removes filters that interfere with Latex while processing Latex templates.
     * Records which filters were removed so that they can be added again later.
     */
    function set_up_latex_filters() {
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
    function undo_latex_filters() {
        global $wp_filter;
        // (Doesn't quite get priorities right)
        $wp_filter = array_merge_recursive( $wp_filter, $this->removed_filters );
        return;
    }
    
    /* Calls pdflatex once on $latex_file, returning the path to the generated pdf, or a WP_Error.
     * The optional $latex_cmd argument can be used when a command other than the default
     * "[path/to/pdflatex] --interaction=nonstopmode [filename]" is needed.
     */
    function typeset_file ( $latex_file, $latex_cmd='' ) {
        $dir = dirname( $latex_file );

        // Tell Latex to search in the theme directory for class/style files.
        putenv('TEXINPUTS=.:' . get_stylesheet_directory() . ':' . get_template_directory() . ':');
        if ( !$latex_cmd )
            $latex_cmd = sprintf( "%s --interaction=nonstopmode %s", $this->pdflatex_path, $latex_file );
        $cmd = sprintf( 'cd %s; %s 2>&1', $dir, $latex_cmd );

        exec( $cmd, $latex_output, $v );

        if ( $v != 0 ) { // There was an error
            $latex_output = implode( "\n", $latex_output );
            return new WP_Error( 'pdflatex', $latex_output );
        }

        $pdf_file = $dir . '/' . basename($latex_file, '.tex') . '.pdf';

        $this->tmp_files[] = "{$latex_file}.log";
        $this->tmp_files[] = "{$latex_file}.aux";
        $this->tmp_files[] = $pdf_file;

        return $pdf_file;
    }

    function attach_pdf_file( $pdf_file ) {
        $wp_filetype = wp_check_filetype( '.pdf' );
        $attachment_data = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title' => $this->get_title(),
            'post_name' => 'pdf',
            'post_content' => '',
            'post_status' => 'inherit',
        );
        
        // Find out where to put the attachment
        $attachment_id = $this->get_attachment_id();
        if ( $attachment_id ) {
            $attachment_data['ID'] = $attachment_id;
            $uploaded_file = get_attached_file( $attachment_id );
        } else {
            $upload_dir = wp_upload_dir();
	    if ($upload_dir['error']) {
	      return new WP_Error( 'wp_upload_dir', $upload_dir['error'] );
            }
            $uploaded_file = trailingslashit($upload_dir['path']).$this->get_name().'.pdf';
        }
        
        // Create the attachment
        if ( !copy( $pdf_file, $uploaded_file )) {
            return new WP_Error( 'copy', 'Failed to copy '.$pdf_file.' to '.$uploaded_file.'.');
        }
        $attachment_id = wp_insert_attachment( $attachment_data, $uploaded_file, $this->get_parent_post_id() );
        if ( $attachment_id == 0 ) { // Attachment error
            return new WP_Error( 'wp_insert_attachment', 'Could not attach generated pdf' );
        }
        
        return $attachment_id;
    }

    /* Returns the id of the existing attachment object for this document.
     * If this doesn't exists, returns 0.
     */
    function get_attachment_id() {
        if ( isset( $this->attachment_id ) ) // Check whether we've cached the result.
            return $this->attachment_id;
        global $wpdb;

        // If the attachment already exists, it will have an attached
        // file with the same filename as ours.
        $query = "
            SELECT post_id FROM $wpdb->postmeta
            WHERE meta_key = '_wp_attached_file'
            AND meta_value LIKE '%{$this->get_name()}.pdf'
            ";
        $ids = $wpdb->get_results( $query );
        if ( !$ids )
            return 0;
        $attachment_id = (int) $ids[0]->post_id;

        $this->attachment_id = $attachment_id;
        return $attachment_id;
    }

    /* Get the permalink of the attachment page.
     */
    function get_permalink() {
        return get_attachment_url( $this->get_attachment_id() );
    }

    /* Get a direct link to the pdf.
     */
    function get_url() {
        return wp_get_attachment_url( $this->get_attachment_id() );
    }

    function get_parent_post_id() {
        return 0;
    }
}

class LE_Latex_Multiple_Document extends LE_Latex_Document {

    var $pdftk_path;

    /* Typsets the latex files seperately then concatenates them with
     * pdftk. Also ensures the page numbering is correct for each file.
     */
    function typeset_all_files () {
        // Find pdftk
        if ( !$this->pdftk_path = exec( 'which pdftk' ) )
            return new WP_Error( 'LE_Latex_Term_Document::__construct', 'pdftk not found' );

        // Get a temporary filename for the concatenated pdf.
        if ( !$tmp_file = tempnam( sys_get_temp_dir(), 'le-' ) )
	    return new WP_Error( 'tempnam', 'Could not create temporary file.' );
        $concatenated_pdf = "{$tmp_file}.pdf";
        unlink( $tmp_file );

        // Typset all of the latex files to be concatenated, fixing page numbers.
        $pdf_files = array();
        $current_page = 1;
        foreach ( $this->latex_files as $latex_file ) {
            $latex_cmd = "{$this->pdflatex_path} --interaction=nonstopmode \"\AtBeginDocument{\setcounter{page}{{$current_page}}}\input{{$latex_file}}\"";
            $pdf_file = $this->typeset_file( $latex_file, $latex_cmd );
            if ( is_wp_error( $pdf_file ) )
                return $pdf_file;
            $pdf_files[] = $pdf_file;
            $current_page += $this->pages_in_pdf( $pdf_file );
        }

        // Concatenate with pdftk
        $cmd = sprintf( '%s %s cat output %s', $this->pdftk_path, implode( ' ', $pdf_files ), $concatenated_pdf );
        exec( $cmd, $pdftk_output, $v );
        if ( $v != 0 ) { // There was an error
            $pdftk_output = implode( "\n", $pdftk_output );
            return new WP_Error( 'pdftk', $pdftk_output );
        }

        $this->tmp_files[] = $concatenated_pdf;
        return $concatenated_pdf;
    }

    /* Tells you how many pages are in the pdf specified by the string argument.
     */
    function pages_in_pdf ( $pdf ) {
        $cmd = "{$this->pdftk_path} {$pdf} dump_data";
        exec( $cmd, $pdftk_output, $v );
        $pdftk_output = implode( "\n", $pdftk_output );
        if ( preg_match('/NumberOfPages: (\d+)/', $pdftk_output, $matches ) ){
            return (int) $matches[1];
        }
        return 0;
    }
}
