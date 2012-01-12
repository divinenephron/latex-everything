<?php
if( !defined( 'ABSPATH') && !defined('WP_UNINSTALL_PLUGIN') )
    exit();

// Remove every generated pdf
$args = array( 'post_type' => 'attachment',
           'numberposts' => -1,
           'meta_key' => '_a2l_is_latex',
           'meta_value' => 1,
           ); 
$attachments = get_posts($args);
foreach ($attachments as $attachment)
    wp_delete_attachment( $attachment->ID );

/* Remove the options
 */
global $wpdb;
$options = $wpdb->get_results( "SELECT * FROM $wpdb->options ORDER BY option_name" );
foreach ( (array) $options as $option )
        if ( strncmp( 'le_', $option->option_name, 3 ) == 0 ) // option_name has le_ prefix
            delete_option( $option->option_name );
?>

