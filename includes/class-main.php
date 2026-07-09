<?php
namespace SocialAutoPoster;

use SocialAutoPoster\Platforms\PlatformInterface;

/**
 * Clase principal del plugin Social Auto Poster.
 * Orquesta la carga de módulos y la inicialización.
 */
class Main {

    /**
     * Instancias de plataformas registradas.
     *
     * @var PlatformInterface[]
     */
    private $platforms = [];

    /**
     * Instancia del administrador.
     *
     * @var Admin
     */
    private $admin;

    /**
     * Instancia del manejador de posts.
     *
     * @var Post_Handler
     */
    private $post_handler;

    /**
     * Ejecutar el plugin.
     */
    public function run() {
        $this->load_dependencies();
        $this->register_platforms();
        $this->init_hooks();
    }

    /**
     * Cargar archivos de dependencias.
     */
    private function load_dependencies() {
        require_once SAP_PLUGIN_DIR . 'includes/platforms/interface-platform.php';
        require_once SAP_PLUGIN_DIR . 'includes/platforms/class-x.php';
        require_once SAP_PLUGIN_DIR . 'includes/platforms/class-threads.php';
        require_once SAP_PLUGIN_DIR . 'includes/platforms/class-instagram.php';
        require_once SAP_PLUGIN_DIR . 'includes/platforms/class-facebook.php';
        require_once SAP_PLUGIN_DIR . 'includes/platforms/class-linkedin.php';
        require_once SAP_PLUGIN_DIR . 'includes/class-ai.php';
        require_once SAP_PLUGIN_DIR . 'includes/class-media-helper.php';
        require_once SAP_PLUGIN_DIR . 'includes/class-post-handler.php';
        require_once SAP_PLUGIN_DIR . 'includes/class-publisher.php';
        require_once SAP_PLUGIN_DIR . 'includes/class-admin.php';
    }

    /**
     * Registrar todas las plataformas soportadas.
     */
    private function register_platforms() {
        $this->register_platform(new Platforms\X());
        $this->register_platform(new Platforms\Threads());
        $this->register_platform(new Platforms\Instagram());
        $this->register_platform(new Platforms\Facebook());
        $this->register_platform(new Platforms\LinkedIn());

        /**
         * Permite que otros plugins registren plataformas adicionales.
         *
         * @param Main $plugin Instancia del plugin principal.
         */
        do_action('sap_register_platforms', $this);
    }

    /**
     * Registrar una plataforma individual.
     *
     * @param PlatformInterface $platform
     */
    public function register_platform(PlatformInterface $platform) {
        $this->platforms[$platform->get_slug()] = $platform;
    }

    /**
     * Obtener una plataforma por su slug.
     *
     * @param string $slug
     * @return PlatformInterface|null
     */
    public function get_platform(string $slug): ?PlatformInterface {
        return $this->platforms[$slug] ?? null;
    }

    /**
     * Obtener todas las plataformas registradas.
     *
     * @return PlatformInterface[]
     */
    public function get_platforms(): array {
        return $this->platforms;
    }

    /**
     * Inicializar los hooks de WordPress.
     */
    private function init_hooks() {
        // Cargar traducciones.
        add_action('init', [$this, 'load_textdomain']);

        // Inicializar administración.
        $this->admin = new Admin($this);
        $this->admin->init();

        // Inicializar manejador de publicaciones.
        $this->post_handler = new Post_Handler($this);
        $this->post_handler->init();

        // Inicializar publicador (se engancha a sap_publish_post).
        $publisher = new Publisher($this);
        $publisher->init();

        // Inicializar hooks OAuth de LinkedIn.
        Platforms\LinkedIn::register_oauth_hooks();
    }

    /**
     * Cargar dominio de texto para traducciones.
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'social-auto-poster',
            false,
            dirname(SAP_PLUGIN_BASENAME) . '/languages'
        );
    }

    /**
     * Obtener la instancia del administrador.
     *
     * @return Admin
     */
    public function get_admin(): Admin {
        return $this->admin;
    }

    /**
     * Obtener la instancia del manejador de posts.
     *
     * @return Post_Handler
     */
    public function get_post_handler(): Post_Handler {
        return $this->post_handler;
    }

    /**
     * Obtener las opciones globales del plugin.
     *
     * @return array
     */
    public static function get_options(): array {
        return get_option('sap_settings', []);
    }

    /**
     * Obtener las opciones de categorías habilitadas.
     *
     * @return array
     */
    public static function get_category_options(): array {
        return get_option('sap_category_settings', []);
    }
}
