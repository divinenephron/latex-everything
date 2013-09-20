<?php

include_once('class-latex-document.php');

global $latex_everything;
$latex_post_type_controller = new LE_Latex_Post_Type_Controller();

$latex_everything->add_controller( 'post_type', $latex_post_type_controller );

class LE_Latex_Post_Type_Controller {
    function documents_for_post( $post_id ) {
        $docs = array();
        $post_type = get_post_type( $post_id );
        if ( get_option( "le_post_type_{$post_type}" ) )
            $docs[] = $this->get_document( $post_type );
        return $docs;

    }

    function get_document( $post_type ) {
        return new LE_Latex_Post_Type_Document( $post_type );
    }

    function get_settings() {
        $needed_settings = array();

        $post_types = get_post_types( '', 'names' );
        $post_types = array_diff( $post_types, array( 'mediapage', 'attachment', 'revision', 'nav_menu_item' ) );
        foreach ( $post_types as $post_type ) {
            $post_type_obj = get_post_type_object( $post_type );
            if ( $post_type_obj ) {
                $needed_settings[] = array( 'name' => "le_post_type_{$post_type}",
                                            'title' => "All {$post_type_obj->labels->name}" );
            }
        }

        return $needed_settings;
    }

}

class LE_Latex_Post_Type_Document extends LE_Latex_Multiple_Document {

    var $source;

    function __construct( $post_type ) {
        $source = get_post_type_object( $post_type );
        if ( !$source )
            return new WP_Error( 'LE_Latex_Term_Document', 'Could not find post type' );
        $this->source = $source;

        parent::__construct();
    }

    function get_posts() {
        $args = array( 'orderby'        => 'date',
                       'order'          => 'DESC',
                       'posts_per_page' => -1,
                       'post_type'      => $this->source->name, );
        return get_posts( $args );
    }

    function get_template() {
        $templates = array();
        $templates[] = 'latex';
        $templates[] = "latex-post-type";
        $templates[] = "latex-post-type-{$this->source->name}";
        $template = get_query_template('latex-term', $templates);
        if ( empty( $template) )
            $template = parent::get_template();
        return $template;
    }
    
    function get_title() {
        return $this->source->labels->name;
    }

    function get_name() {
        return sanitize_title($this->source->labels->all_items);
    }
}
