<?php
/**
 * Social Auto Poster
 *
 * @package           SocialAutoPoster
 * @author            Social Auto Poster Team
 * @copyright         2024 Social Auto Poster
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Social Auto Poster
 * Plugin URI:        https://github.com/social-auto-poster/social-auto-poster
 * Description:       Publica automáticamente en redes sociales (X, Threads, Instagram, Facebook, LinkedIn) cuando se publica un post, con selección por categorías.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Social Auto Poster
 * Author URI:        https://github.com/social-auto-poster
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       social-auto-poster
 * Domain Path:       /languages
 */

// Si este archivo es llamado directamente, abortar.
if (!defined('ABSPATH')) {
    exit;
}

// Guard de versión PHP.
if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', function () {
        $message = __('Social Auto Poster requiere PHP 7.4 o superior. Tu versión: ', 'social-auto-poster') . PHP_VERSION;
        echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
    });
    return;
}

// Definir constantes del plugin.
define('SAP_VERSION', '1.0.0');
define('SAP_PLUGIN_FILE', __FILE__);
define('SAP_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('SAP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SAP_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autocargar clases.
require_once SAP_PLUGIN_DIR . 'includes/class-main.php';

/**
 * Singleton guard: evitar inicialización duplicada.
 */
$GLOBALS['sap_initialized'] = false;

/**
 * Inicializar el plugin.
 */
function sap_init() {
    if (!empty($GLOBALS['sap_initialized'])) {
        return;
    }
    $GLOBALS['sap_initialized'] = true;

    $plugin = new SocialAutoPoster\Main();
    $plugin->run();
}

add_action('plugins_loaded', 'sap_init');

/**
 * Activar el plugin.
 */
function sap_activate() {
    // Crear opciones por defecto si no existen.
    $defaults = [
        'enabled_platforms' => [
            'x'         => '0',
            'threads'   => '0',
            'instagram' => '0',
            'facebook'  => '0',
            'linkedin'  => '0',
        ],
        'publish_scheduled' => '0',
        'ai'                => [
            'provider'      => 'deepseek',
            'api_key'       => '',
            'model'         => 'deepseek-v4-flash',
            'enabled_for'   => [],
            'instructions'  => '',
        ],
    ];

    if (!get_option('sap_settings')) {
        add_option('sap_settings', $defaults);
    }

    if (!get_option('sap_category_settings')) {
        add_option('sap_category_settings', [
            'allowed_categories' => [],
            'mode'              => 'include',
        ]);
    }
}

/**
 * Desactivar el plugin (limpiar crons, etc.).
 */
function sap_deactivate() {
    // Espacio reservado para futuras tareas de desactivación.
}

register_activation_hook(__FILE__, 'sap_activate');
register_deactivation_hook(__FILE__, 'sap_deactivate');

/**
 * Agregar enlace de Ajustes en la página de Plugins.
 */
function sap_plugin_action_links($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=social-auto-poster') . '">'
        . __('Ajustes', 'social-auto-poster') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'sap_plugin_action_links');
