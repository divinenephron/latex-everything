<?php
include_once('class-latex-document.php');

global $latex_everything;
$latex_term_controller = new LE_Latex_Term_Controller();

$latex_everything->add_controller( 'term', $latex_term_controller );

class LE_Latex_Term_Controller {

    function documents_for_post( $post_id ) {
        $docs = array();
        foreach( get_taxonomies() as $taxonomy ) {
            if( get_option( "le_term_{$taxonomy}" ) && $terms = get_the_terms( $post_id, $taxonomy ) ) {
                if( is_wp_error( $terms ) ) {
                    $docs[] = $terms;
                    continue;
                }
                foreach( $terms as $term )
                    $docs[] = $this->get_document( $term->term_id, $taxonomy );
            }
        }
        return $docs;

    }

    function get_document( $term_id, $taxonomy ) {
        return new LE_Latex_Term_Document( $term_id, $taxonomy );
    }

    function get_settings() {
        $needed_settings = array();

        $taxonomies = get_taxonomies( '', 'names' );
        $taxonomies = array_diff( $taxonomies, array( 'nav_menu', 'link_category', 'post_format' ) );
        foreach ( $taxonomies as $taxonomy ) {
            $taxonomy_obj = get_taxonomy( $taxonomy );
            if ( $taxonomy_obj ) {
                $needed_settings[] = array( 'name' => "le_term_{$taxonomy}",
                                            'title' => "Single {$taxonomy_obj->labels->name}" );
            }
        }

        return $needed_settings;
    }

}

class LE_Latex_Term_Document extends LE_Latex_Multiple_Document {

    var $source;

    function __construct( $id, $taxonomy ) {
        $this->source = get_term( $id, $taxonomy );
        parent::__construct();
    }

    function get_posts() {
        $args = array( 'tax_query'      => array( array(
                                            'taxonomy' => $this->source->taxonomy,
                                            'field' => 'id',
                                            'terms' => $this->source->term_id, )),
                       'orderby'        => 'date',
                       'order'          => 'DESC',
                       'posts_per_page' => -1,
                       'post_type'      => null, );
        return get_posts( $args );
    }

    function get_template() {
        $templates = array();
        $templates[] = 'latex';
        $templates[] = "latex-term";
        $templates[] = "latex-term-{$this->source->taxonomy}";
        $templates[] = "latex-term-{$this->source->taxonomy}-{$this->source->slug}";
        $templates[] = "latex-term-{$this->source->taxonomy}-{$this->source->term_id}";
        $template = get_query_template('latex-term', $templates);
        if ( empty( $template) )
            $template = parent::get_template();
        return $template;
    }

    function get_title() {
        $tax_name = ucwords( $this->source->taxonomy );
        return "{$tax_name} {$this->source->name}";
    }

    function get_name() {
        return "{$this->source->taxonomy}-{$this->source->slug}";
    }
}
