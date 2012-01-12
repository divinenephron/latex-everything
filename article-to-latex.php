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

    var $html_file;
    var $latex_file;
    var $pdf_file;
    var $uploaded_file;


    function __construct () {
        $this->plugin_dir = plugin_dir_path(__FILE__);
        $this->html_to_latex_script = $this->plugin_dir . 'html2latex.pl';
    }

    function __destruct () {
        // Unlink temporary files
        unlink( $this->html_file );
        unlink( $this->latex_file );
        unlink( $this->pdf_file );
        unlink( $this->html_file . '.aux' );
        unlink( $this->html_file . '.log' );
    }
    
    function make_html_file () {
        $html = '<h1>Header</h1><p>Paragraph.</p>';

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
        $upload_dir = wp_upload_dir();
        $this->uploaded_file = $upload_dir['path'] . '/' . 'test' . '.pdf';
    
        copy( $this->pdf_file, $this->uploaded_file );
    }
}

function random_test() {
    $a2l = new Divinenephron_Article_To_Latex;

    $html_result = $a2l->make_html_file();
    if ( is_wp_error( $html_result ) ) {
        print $html_result->get_error_message();
    }

    $latex_result = $a2l->html_file_to_latex_file();
    if ( is_wp_error( $latex_result ) ) {
        print $latex_result->get_error_message();
    }

    $pdf_result = $a2l->latex_file_to_pdf_file();
    if ( is_wp_error( $pdf_result ) ) {
        printf("<!--\n%s\n-->", $pdf_result->get_error_message());
    }

    $a2l->attach_pdf_file();
}
add_action('wp_head', 'random_test');
