<?php
/* When uninstalling, remove every generated pdf
 */
$args = array( 'post_type' => 'attachment',
           'numberposts' => -1,
           'meta_key' => '_a2l_is_latex',
           'meta_value' => 1,
           ); 
$attachments = get_posts($args);
foreach ($attachments as $attachment)
    wp_delete_attachment( $attachment->ID );
?>
