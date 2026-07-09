<?php
/**
 * Desinstalación de Social Auto Poster.
 *
 * Limpia todas las opciones y metadatos del plugin.
 * WordPress ejecuta este archivo automáticamente al desinstalar el plugin.
 *
 * @package SocialAutoPoster
 */

// Si no es una desinstalación de WordPress, abortar.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Eliminar opciones del plugin.
delete_option('sap_settings');
delete_option('sap_category_settings');

// Eliminar todos los metadatos de posts asociados al plugin.
$meta_keys = [
    '_sap_auto_posted',
    '_sap_disable_auto_post',
    '_sap_publish_log',
    '_sap_publish_time',
    '_sap_use_ai',
];

foreach ($meta_keys as $key) {
    delete_post_meta_by_key($key);
}
