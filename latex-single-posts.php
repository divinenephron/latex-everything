<?php
include_once('class-latex-document.php');

global $latex_everything;
$latex_single_post_controller = new LE_Latex_Single_Post_Controller();

$latex_everything->add_controller( 'single_post', $latex_single_post_controller );

class LE_Latex_Single_Post_Controller {
    function documents_for_post( $post_id ) {
        $docs = array();
        $post_type = get_post_type( $post_id );
        if( get_option( "le_single_post_{$post_type}" ) )
            $docs[] = $this->get_document( $post_id );
        return $docs;
    }

    function get_document( $post_id ) {
        return new LE_Latex_Single_Document( $post_id );
    }

    function get_settings() {
        $needed_settings = array();

        $post_types = get_post_types( '', 'names' );
        $post_types = array_diff( $post_types, array( 'mediapage', 'attachment', 'revision', 'nav_menu_item' ) );
        foreach ( $post_types as $post_type ) {
            $post_type_obj = get_post_type_object( $post_type );
            if ( $post_type_obj ) {
                $needed_settings[] = array( 'name' => "le_single_post_{$post_type}",
                                            'title' => "Single {$post_type_obj->labels->name}" );
            }
        }

        return $needed_settings;
    }
}

class LE_Latex_Single_Document extends LE_Latex_Document {

    var $source;

    function __construct( $id ) {
        $source =  get_post( $id );
        if ( is_wp_error( $source ) )
            return $source;
        $this->source = $source;

        parent::__construct();
    }
    
    function get_source( $id ) {
    }

    function get_posts() {
        return array( $post = $this->source );
    }

    function typeset_all_files() {
        return $this->typeset_file( $this->latex_files[0] );
    }
        
    function get_template() {
        $templates = array();
        $templates[] = 'latex';
        $templates[] = "latex-single";
        $templates[] = "latex-single-{$this->source->post_type}";
        $templates[] = "latex-single-{$this->source->post_type}-{$this->source->post_name}";
        $templates[] = "latex-single-{$this->source->post_type}-{$this->source->ID}";
        $template = get_query_template('latex-single', $templates);
        if ( empty( $template) )
            $template = parent::get_template();
        return $template;
    }

    function get_name() {
        return "{$this->source->post_type}-{$this->source->post_name}";
    }

    function get_title() {
        return $this->source->post_title;
    }

    function get_parent_post_id() {
        return $this->source->ID;
    }
}
