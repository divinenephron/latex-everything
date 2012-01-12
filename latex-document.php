<?php

define( 'PLUGIN_DIR', plugin_dir_path( __FILE__ ) );            // Plugin directory with trailing slash

include('html-to-latex.php'); // Include functions to convert html to latex.

class LE_Latex_Document {

    var $source;
    var $title;
    var $filename;
    var $parent_post_id;

    var $pdflatex_path;

    var $latex_files;
    var $pdf_file;
    var $uploaded_file;
    var $tmp_files;

    var $unwanted_filter_functions = Array( 'wptexturize', 'convert_chars', 'esc_html', 'ent2ncr', '_wp_specialchars', 'sanitize_text_field', 'capital_P_dangit' );
    var $removed_filters;



    function __construct () {
        $this->tmp_files = array();
        $this->latex_files = array();

        if ( !$this->pdflatex_path = exec( 'which pdflatex' ) )
            return new WP_Error( 'LE_Latex_Document::__construct', 'pdflatex not found' );

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
     * As a minimum the subclass needs to set
     * - $this->source
     * - $this->title
     * - $this->filename
     * - $this->parent_post_id
     */
    function generate() {
        return WP_Error( 'LE_Latex_Document::generate', 'Method needs to be redefined by a subclass' );
    }

    /* Writes a latex file for a single post.
     */
    function make_latex_file ($id, $post_type ) {
        $template = $this->_get_template( );

        // Query the post
        $args = array(  'orderby'        => 'date',
                        'order'          => 'DESC',
                        );
        if ( $post_type == 'page' )
            $args['page_id'] = $id;
        else
            $args['p'] = $id;
        query_posts( $args );

        // Render the template
        $this->_set_up_latex_filters();
        ob_start();
        include( $template );
        $latex = ob_get_clean();
        $this->_undo_latex_filters();

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

    function _get_template() {
        return PLUGIN_DIR . 'default-latex.php';
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


    function attach_pdf_file () {
        $wp_filetype = wp_check_filetype( '.pdf' );
        $attachment_data = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title' => $this->title,
            'post_name' => 'pdf',
            'post_content' => '',
            'post_status' => 'inherit',
        );

        // Check whether this post has an older file and attachment
        // object we can update
        $args = array( 'post_type' => 'attachment',
                       'numberposts' => -1,
                       'post_status' => null,
                       'post_parent' => $this->parent_post_id, );
        $attachments = get_posts($args);
        if ($attachments) {
            foreach ( $attachments as $attachment ) {
                $attached_file = get_attached_file( $attachment->ID );
                if ( basename( $attached_file ) == $this->filename ) {
                    $this->uploaded_file = $attached_file;
                    $attachment_data['ID'] = $attachment->ID;
                }
            }
        }

        // If it doesn't, find a location for a new file
        if ( empty( $this->uploaded_file ) ) {
            $upload_dir = wp_upload_dir();
            $this->uploaded_file = $upload_dir['path'] . '/' . $this->filename;
        }
        
        // Create the attachment
        copy( $this->pdf_file, $this->uploaded_file );
        $attach_id = wp_insert_attachment( $attachment_data, $this->uploaded_file, $this->parent_post_id );
        if ( $attach_id == 0 ) { // Attachment error
            return new WP_Error( 'wp_insert_attachment', 'Could not attach generated pdf' );
        }
        add_post_meta( $attach_id, '_le_is_latex', 1, true );
        return $attach_id;
    }

}

class LE_Latex_Single_Document extends LE_Latex_Document {
    
    function __construct( $id ) {
        $source = get_post( $id );
        if ( is_wp_error( $source ) )
            return $source;
        $this->source = $source;

        $this->filename = "{$this->source->post_name}.pdf";
        $this->title = $this->source->post_title;
        $this->parent_post_id = $this->source->ID;
        
        return parent::__construct();
    }

    function generate() {
        $post = $this->source;

        // Create latex file.
        $latex_file = $this->make_latex_file( $post->ID, $post->post_type );
        if ( is_wp_error( $latex_file ) )
            return $latex_file;
        $this->latex_files[] = $latex_file;
        
        // Typset latex file.
        $pdf_file = $this->typeset_file( $latex_file );
        if ( is_wp_error( $pdf_file ) )
            return $pdf_file;
        else
            $this->pdf_file = $pdf_file;

        // Attach pdf file
        $attach_id = $this->attach_pdf_file();
        if ( is_wp_error( $attach_id ) )
            return $attach_id;
        return 0; // Return no error
    }

    function _get_template() {
        $templates = array();
        $templates[] = 'latex';
        $templates[] = "latex-single";
        $templates[] = "latex-single-{$this->source->post_type}";
        $templates[] = "latex-single-{$this->source->post_type}-{$this->source->post_name}";
        $templates[] = "latex-single-{$this->source->post_type}-{$this->source->ID}";
        $template = get_query_template('latex-single', $templates);
        if ( empty( $template) )
            $template = parent::_get_template();
        return $template;
    }
}

class LE_Latex_Term_Document extends LE_Latex_Document {

    var $combine_latex_files_callback;
    var $pdftk_path;

    function __construct( $id, $taxonomy ) {
        $this->combine_latex_files_callback = array( &$this, 'concatenate_latex_files' );

        if ( !$this->pdftk_path = exec( 'which pdftk' ) )
            return new WP_Error( 'LE_Latex_Term_Document::__construct', 'pdftk not found' );

        $source = get_term( $id, $taxonomy );
        if ( is_wp_error( $source ) )
            return $source;

        $this->source = $source;

        $this->filename = "{$this->source->taxonomy}-{$this->source->slug}.pdf";
        $this->tax_name = ucwords( $this->source->taxonomy );
        $this->title = "{$taxonomy} {$this->source->name}";
        $this->parent_post_id = 0;

        parent::__construct();
    }

    function generate() {
        // Generate single latex files for each of a term's posts, because Latex
        // wasn't designed to generate multi-article documents.
        $args = array( 'tax_query'      => array( array(
                                            'taxonomy' => $this->source->taxonomy,
                                            'field' => 'id',
                                            'terms' => $this->source->term_id, )),
                       'orderby'        => 'date',
                       'order'          => 'DESC',
                       'posts_per_page' => -1,
                       'post_type'      => null,
                       );
        $posts = get_posts( $args );

        // Generate latex files.
        foreach ( $posts as $post ) {
            $latex_result = $this->make_latex_file( $post->ID, $post->post_type );
            if ( is_wp_error( $latex_result ) )
                return $latex_result;
            $this->latex_files[] = $latex_result;
        }

        // Typset latex files.
        if ( is_callable( $this->combine_latex_files_callback ) )
            $pdf_file = call_user_func( $this->combine_latex_files_callback, &$this );
        if ( !$pdf_file )
            return new WP_Error( 'LE_Latex_Term_Document::generate', "Error: No pdf file generated." );
        else if ( is_wp_error( $pdf_file ) )
            return $pdf_file;
        else
            $this->pdf_file = $pdf_file;

        // Attach pdf file
        $attach_id = $this->attach_pdf_file();
        if ( is_wp_error( $attach_id ) )
            return $attach_id;
        return 0; // Return no error
    }

    function _get_template() {
        $templates = array();
        $templates[] = 'latex';
        $templates[] = "latex-term";
        $templates[] = "latex-term-{$this->source->taxonomy}";
        $templates[] = "latex-term-{$this->source->taxonomy}-{$this->source->slug}";
        $templates[] = "latex-term-{$this->source->taxonomy}-{$this->source->term_id}";
        $template = get_query_template('latex-term', $templates);
        if ( empty( $template) )
            $template = parent::_get_template();
        return $template;
    }

    /* Typsets the latex files in $doc seperately then concatenates them with
     * pdftk. Also ensures the page numbering is corret for each file.
     */
    function concatenate_latex_files ( $doc ) {
        // Get a temporary filename for the concatenated pdf.
        if ( !$tmp_file = tempnam( sys_get_temp_dir(), 'le-' ) )
	    return new WP_Error( 'tempnam', 'Could not create temporary file.' );
        $concatenated_pdf = "{$tmp_file}.pdf";
        unlink( $tmp_file );

        // Typset all of the latex files to be concatenated, fixing page numbers.
        $pdf_files = array();
        $current_page = 1;
        foreach ( $doc->latex_files as $latex_file ) {
            $latex_cmd = "{$doc->pdflatex_path} --interaction=nonstopmode \"\AtBeginDocument{\setcounter{page}{{$current_page}}}\input{{$latex_file}}\"";
            $pdf_file = $doc->typeset_file( $latex_file, $latex_cmd );
            if ( is_wp_error( $pdf_file ) )
                return $pdf_file;
            $pdf_files[] = $pdf_file;
            $current_page += $this->_pages_in_pdf( $pdf_file );
        }

        // Concatenate with pdftk
        $cmd = sprintf( '%s %s cat output %s', $this->pdftk_path, implode( ' ', $pdf_files ), $concatenated_pdf );
        exec( $cmd, $pdftk_output, $v );
        if ( $v != 0 ) { // There was an error
            $pdftk_output = implode( "\n", $pdftk_output );
            return new WP_Error( 'pdftk', $pdftk_output );
        }

        $doc->tmp_files[] = $concatenated_pdf;
        return $concatenated_pdf;
    }

    /* Tells you how many pages are in the pdf specified by the string argument.
     */
    function _pages_in_pdf ( $pdf ) {
        $cmd = "{$this->pdftk_path} {$pdf} dump_data";
        exec( $cmd, $pdftk_output, $v );
        $pdftk_output = implode( "\n", $pdftk_output );
        if ( preg_match('/NumberOfPages: (\d+)/', $pdftk_output, $matches ) ){
            return (int) $matches[1];
        }
        return 0;
    }

}

?>
