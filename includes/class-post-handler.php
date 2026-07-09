<?php
namespace SocialAutoPoster;

/**
 * Maneja los hooks de publicación de posts para disparar el auto-posting.
 * Se engancha a transition_post_status para detectar cuando un post se publica.
 */
class Post_Handler {

    /**
     * Referencia al plugin principal.
     *
     * @var Main
     */
    private $plugin;

    /**
     * Meta key para guardar si un post ya fue procesado.
     */
    const META_PROCESSED = '_sap_auto_posted';

    /**
     * Meta key para deshabilitar auto-posting en un post específico.
     */
    const META_DISABLED = '_sap_disable_auto_post';

    /**
     * Constructor.
     *
     * @param Main $plugin
     */
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    /**
     * Inicializar hooks.
     */
    public function init() {
        // Detectar cuando un post se publica.
        add_action('transition_post_status', [$this, 'on_post_transition'], 10, 3);

        // Agregar meta box en el editor de posts.
        add_action('add_meta_boxes', [$this, 'add_meta_box']);

        // Guardar la preferencia del meta box.
        add_action('save_post', [$this, 'save_meta_box']);

        // Registrar metadatos para el editor de bloques (REST API).
        add_action('init', [$this, 'register_meta_fields']);
    }

    /**
     * Registrar metadatos para el block editor (REST API).
     */
    public function register_meta_fields() {
        $meta_fields = [
            self::META_DISABLED => [
                'type'        => 'string',
                'description' => 'Deshabilitar auto-publicación en redes sociales',
                'default'     => '0',
            ],
            '_sap_use_ai' => [
                'type'        => 'string',
                'description' => 'Usar IA para generar texto en redes sociales',
                'default'     => '0',
            ],
        ];

        foreach ($meta_fields as $key => $args) {
            register_post_meta('post', $key, [
                'show_in_rest' => true,
                'single'       => true,
                'type'         => $args['type'],
                'description'  => $args['description'],
                'default'      => $args['default'],
                'auth_callback' => function () {
                    return current_user_can('edit_posts');
                },
            ]);
        }
    }

    /**
     * Detectar cuando un post cambia de estado.
     *
     * @param string   $new_status Nuevo estado.
     * @param string   $old_status Estado anterior.
     * @param \WP_Post $post       El post.
     */
    public function on_post_transition(string $new_status, string $old_status, \WP_Post $post) {
        // Solo para posts (no páginas, CPT, etc).
        if ($post->post_type !== 'post') {
            return;
        }

        // Evitar disparos durante bulk edit o quick edit.
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }

        // Solo cuando se publica (de cualquier estado no-público a 'publish').
        if ($new_status !== 'publish' || $old_status === 'publish') {
            return;
        }

        // Si es un post programado publicado automáticamente por WP Cron,
        // verificar la preferencia del usuario.
        if ($old_status === 'future' && wp_doing_cron()) {
            $settings = Main::get_options();
            $publish_scheduled = !empty($settings['publish_scheduled']) && $settings['publish_scheduled'] === '1';
            if (!$publish_scheduled) {
                return;
            }
        }

        // Evitar duplicados: si ya fue procesado, salir.
        if (get_post_meta($post->ID, self::META_PROCESSED, true)) {
            return;
        }

        // Verificar si el auto-posting está deshabilitado para este post.
        if (get_post_meta($post->ID, self::META_DISABLED, true)) {
            return;
        }

        // Verificar categorías permitidas.
        if (!$this->is_category_allowed($post->ID)) {
            return;
        }

        /**
         * Acción para disparar la publicación.
         * El Publisher se engancha aquí.
         *
         * @param int $post_id ID del post publicado.
         */
        do_action('sap_publish_post', $post->ID);
    }

    /**
     * Verificar si el post pertenece a alguna categoría permitida.
     *
     * @param int $post_id ID del post.
     * @return bool
     */
    private function is_category_allowed(int $post_id): bool {
        $category_settings = Main::get_category_options();
        $allowed_categories = $category_settings['allowed_categories'] ?? [];
        $mode = $category_settings['mode'] ?? 'include';

        // Si no hay categorías configuradas, publicar para todas.
        if (empty($allowed_categories)) {
            return true;
        }

        $post_categories = wp_get_post_categories($post_id, ['fields' => 'ids']);

        if ($mode === 'exclude') {
            // Excluir: publicar si NO pertenece a ninguna de las categorías seleccionadas.
            foreach ($post_categories as $cat_id) {
                if (in_array($cat_id, $allowed_categories)) {
                    return false;
                }
            }
            return true;
        }

        // Include (por defecto): publicar solo si pertenece a alguna categoría seleccionada.
        foreach ($post_categories as $cat_id) {
            if (in_array($cat_id, $allowed_categories)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Agregar meta box en la edición de posts.
     */
    public function add_meta_box() {
        add_meta_box(
            'sap_auto_post_meta',
            __('Publicación Automática en Redes Sociales', 'social-auto-poster'),
            [$this, 'render_meta_box'],
            'post',
            'side',
            'default'
        );
    }

    /**
     * Renderizar el meta box.
     *
     * @param \WP_Post $post
     */
    public function render_meta_box($post) {
        wp_nonce_field('sap_meta_box', 'sap_meta_box_nonce');

        $disabled   = get_post_meta($post->ID, self::META_DISABLED, true);
        $processed  = get_post_meta($post->ID, self::META_PROCESSED, true);
        $log        = get_post_meta($post->ID, '_sap_publish_log', true);

        // Verificar si IA está configurada.
        $ai_available = \SocialAutoPoster\AI::is_configured();

        ?>
        <p>
            <label>
                <input type="checkbox" name="sap_disable_auto_post" value="1" <?php checked($disabled, '1'); ?> />
                <?php esc_html_e('Deshabilitar publicación automática para este post', 'social-auto-poster'); ?>
            </label>
        </p>

        <?php if ($ai_available) : ?>
            <p>
                <label>
                    <input type="checkbox" name="sap_use_ai" value="1" <?php checked(get_post_meta($post->ID, '_sap_use_ai', true), '1'); ?> />
                    <?php esc_html_e('Usar IA para generar el texto de las publicaciones', 'social-auto-poster'); ?>
                </label>
            </p>
        <?php endif; ?>

        <?php if ($processed) : ?>
            <p class="sap-processed-notice">
                <span style="color: #46b450;">&#10003;</span>
                <?php esc_html_e('Post procesado automáticamente.', 'social-auto-poster'); ?>
            </p>
            <?php if (!empty($log) && is_array($log)) : ?>
                <details>
                    <summary><?php esc_html_e('Ver detalles', 'social-auto-poster'); ?></summary>
                    <ul style="font-size: 12px; margin-top: 5px;">
                        <?php foreach ($log as $entry) : ?>
                            <li style="margin-bottom: 3px;">
                                <strong><?php echo esc_html($entry['platform']); ?>:</strong>
                                <?php if ($entry['success']) : ?>
                                    <span style="color: #46b450;">&#10003;</span>
                                <?php else : ?>
                                    <span style="color: #dc3232;">&#10007;</span>
                                <?php endif; ?>
                                <?php echo esc_html($entry['message']); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        <?php else : ?>
            <p class="description">
                <?php esc_html_e('Al publicar este post, se compartirá automáticamente en las redes configuradas.', 'social-auto-poster'); ?>
            </p>
        <?php endif; ?>
        <?php
    }

    /**
     * Guardar la preferencia del meta box.
     *
     * @param int $post_id
     */
    public function save_meta_box($post_id) {
        // Verificar nonce.
        if (!isset($_POST['sap_meta_box_nonce'])
            || !wp_verify_nonce($_POST['sap_meta_box_nonce'], 'sap_meta_box')) {
            return;
        }

        // Verificar autosave.
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Verificar permisos.
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $disabled = isset($_POST['sap_disable_auto_post']) ? '1' : '0';
        update_post_meta($post_id, self::META_DISABLED, $disabled);

        $use_ai = isset($_POST['sap_use_ai']) ? '1' : '0';
        update_post_meta($post_id, '_sap_use_ai', $use_ai);
    }

    /**
     * Marcar un post como procesado y guardar el log.
     *
     * @param int   $post_id
     * @param array $log_result Resultados de cada plataforma.
     */
    public static function mark_as_processed(int $post_id, array $log_result) {
        update_post_meta($post_id, self::META_PROCESSED, '1');
        update_post_meta($post_id, '_sap_publish_log', $log_result);
        update_post_meta($post_id, '_sap_publish_time', current_time('mysql'));
    }

    /**
     * Verificar si el post fue auto-procesado.
     *
     * @param int $post_id
     * @return bool
     */
    public static function is_processed(int $post_id): bool {
        return (bool) get_post_meta($post_id, self::META_PROCESSED, true);
    }

    /**
     * Obtener el log de publicación de un post.
     *
     * @param int $post_id
     * @return array
     */
    public static function get_publish_log(int $post_id): array {
        $log = get_post_meta($post_id, '_sap_publish_log', true);
        return is_array($log) ? $log : [];
    }
}
