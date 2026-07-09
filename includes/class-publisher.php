<?php
namespace SocialAutoPoster;

use SocialAutoPoster\Platforms\PlatformInterface;

/**
 * Orquesta la publicación en múltiples plataformas.
 * Se engancha a sap_publish_post para procesar un post.
 */
class Publisher {

    /**
     * Referencia al plugin principal.
     *
     * @var Main
     */
    private $plugin;

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
        add_action('sap_publish_post', [$this, 'publish_to_all_platforms'], 10, 1);

        // Permitir republicación manual desde el listado de posts.
        add_action('admin_action_sap_republish', [$this, 'handle_republish']);

        // Agregar columna en el listado de posts.
        add_filter('manage_posts_columns', [$this, 'add_post_list_column']);
        add_action('manage_posts_custom_column', [$this, 'render_post_list_column'], 10, 2);
    }

    /**
     * Publicar un post en todas las plataformas configuradas y habilitadas.
     *
     * @param int $post_id ID del post a publicar.
     */
    public function publish_to_all_platforms(int $post_id) {
        // Evitar bucles infinitos.
        if (defined('SAP_PUBLISHING') && SAP_PUBLISHING) {
            return;
        }

        define('SAP_PUBLISHING', true);

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'post') {
            return;
        }

        $settings       = Main::get_options();
        $platforms      = $this->plugin->get_platforms();
        $post_data      = $this->prepare_post_data($post);
        $log_result     = [];

        /**
         * Acción antes de publicar en todas las plataformas.
         *
         * @param int $post_id
         */
        do_action('sap_before_publish_all', $post_id);

        // Obtener el estado de cada plataforma (habilitada/deshabilitada).
        $enabled_platforms = $settings['enabled_platforms'] ?? [];

        foreach ($platforms as $slug => $platform) {
            // Verificar si la plataforma está habilitada.
            $is_enabled = !empty($enabled_platforms[$slug]) && $enabled_platforms[$slug] === '1';

            if (!$is_enabled) {
                $log_result[$slug] = [
                    'platform' => $platform->get_name(),
                    'success'  => false,
                    'message'  => __('Plataforma deshabilitada.', 'social-auto-poster'),
                ];
                continue;
            }

            $platform_settings = $settings[$slug] ?? [];

            if (!$platform->is_configured($platform_settings)) {
                $log_result[$slug] = [
                    'platform' => $platform->get_name(),
                    'success'  => false,
                    'message'  => __('Plataforma no configurada.', 'social-auto-poster'),
                ];
                continue;
            }

            // Si la IA está habilitada para esta plataforma, generar texto por IA.
            // También se puede habilitar por post individual con el meta box.
            $use_ai_for_platform = AI::is_configured()
                && (AI::is_enabled_for($slug)
                    || get_post_meta($post_id, '_sap_use_ai', true) === '1');

            $platform_post_data = $post_data;
            if ($use_ai_for_platform) {
                $ai_service = new AI();
                $ai_text = $ai_service->generate($post_data, $slug, $platform->get_name());

                if (!empty($ai_text)) {
                    // El texto IA reemplaza tanto título como excerpt.
                    $platform_post_data['title']   = $ai_text;
                    $platform_post_data['excerpt'] = '';
                }
                // Si falla la IA, se usa el texto normal como fallback.
            }

            // Publicar.
            $result = $platform->publish($platform_post_data, $platform_settings);

            $log_result[$slug] = [
                'platform' => $platform->get_name(),
                'success'  => $result['success'],
                'message'  => $result['message'],
            ];

            if (!$result['success']) {
                error_log(
                    sprintf(
                        '[Social Auto Poster] Fallo al publicar en %s (%s): %s',
                        $platform->get_name(),
                        $slug,
                        $result['message']
                    )
                );
            }

            /**
             * Acción después de publicar en una plataforma individual.
             *
             * @param int    $post_id
             * @param string $slug    Plataforma slug.
             * @param array  $result  Resultado de la publicación.
             */
            do_action('sap_after_platform_publish', $post_id, $slug, $result);
        }

        // Marcar como procesado.
        Post_Handler::mark_as_processed($post_id, $log_result);

        /**
         * Acción después de publicar en todas las plataformas.
         *
         * @param int   $post_id
         * @param array $log_result
         */
        do_action('sap_after_publish_all', $post_id, $log_result);
    }

    /**
     * Preparar los datos del post para enviar a las plataformas.
     *
     * @param \WP_Post $post
     * @return array
     */
    private function prepare_post_data(\WP_Post $post): array {
        $title   = get_the_title($post);
        $excerpt = !empty($post->post_excerpt)
            ? $post->post_excerpt
            : wp_trim_words(strip_shortcodes($post->post_content), 55);
        $url     = get_permalink($post);
        $image   = '';

        // Obtener la URL de la imagen destacada.
        if (has_post_thumbnail($post)) {
            $image_id = get_post_thumbnail_id($post);
            $image_url = wp_get_attachment_image_url($image_id, 'full');
            if ($image_url) {
                $image = $image_url;
            }
        }

        return [
            'post_id'        => $post->ID,
            'title'          => $title,
            'content'        => $post->post_content,
            'excerpt'        => $excerpt,
            'url'            => $url,
            'featured_image' => $image,
            'author'         => $post->post_author,
            'post_date'      => $post->post_date,
            'categories'     => wp_get_post_categories($post->ID, ['fields' => 'names']),
            'tags'           => wp_get_post_tags($post->ID, ['fields' => 'names']),
        ];
    }

    /**
     * Manejar la republicación manual desde el listado de posts.
     */
    public function handle_republish() {
        if (!isset($_GET['post']) || !isset($_GET['_wpnonce'])) {
            return;
        }

        $post_id = intval($_GET['post']);

        if (!wp_verify_nonce($_GET['_wpnonce'], 'sap_republish_' . $post_id)) {
            wp_die(__('Enlace inválido.', 'social-auto-poster'));
        }

        if (!current_user_can('edit_post', $post_id)) {
            wp_die(__('Permiso denegado.', 'social-auto-poster'));
        }

        // Limpiar meta para republicar.
        delete_post_meta($post_id, Post_Handler::META_PROCESSED);
        delete_post_meta($post_id, '_sap_publish_log');
        delete_post_meta($post_id, '_sap_publish_time');

        $this->publish_to_all_platforms($post_id);

        wp_redirect(add_query_arg('sap_republished', '1', admin_url('edit.php')));
        exit;
    }

    /**
     * Agregar columna en el listado de posts de administración.
     *
     * @param array $columns
     * @return array
     */
    public function add_post_list_column(array $columns): array {
        $columns['sap_status'] = __('Redes Sociales', 'social-auto-poster');
        return $columns;
    }

    /**
     * Renderizar la columna de estado en el listado de posts.
     *
     * @param string $column_name
     * @param int    $post_id
     */
    public function render_post_list_column(string $column_name, int $post_id) {
        if ($column_name !== 'sap_status') {
            return;
        }

        $processed = Post_Handler::is_processed($post_id);

        if (!$processed) {
            echo '<span style="color: #999;">—</span>';
            return;
        }

        $log = Post_Handler::get_publish_log($post_id);
        $success_count = 0;
        $total_count = count($log);

        foreach ($log as $entry) {
            if (!empty($entry['success'])) {
                $success_count++;
            }
        }

        echo '<span style="color: #46b450;">&#10003;</span> ';
        printf(
            __('%d de %d', 'social-auto-poster'),
            $success_count,
            $total_count
        );

        // Botón de republicar.
        $republish_url = wp_nonce_url(
            admin_url('admin.php?action=sap_republish&post=' . $post_id),
            'sap_republish_' . $post_id
        );

        echo ' <a href="' . esc_url($republish_url) . '" class="button button-small" onclick="return confirm(\''
            . esc_js(__('¿Republicar en redes sociales?', 'social-auto-poster')) . '\')">'
            . esc_html__('Republicar', 'social-auto-poster') . '</a>';

        // Mostrar tooltip con detalles.
        if (!empty($log)) {
            echo '<div style="font-size: 11px; margin-top: 4px; color: #666;">';
            foreach ($log as $entry) {
                echo '<div>';
                echo esc_html($entry['platform']) . ': ';
                if (!empty($entry['success'])) {
                    echo '<span style="color: #46b450;">&#10003;</span>';
                } else {
                    echo '<span style="color: #dc3232;">&#10007;</span>';
                }
                echo '</div>';
            }
            echo '</div>';
        }
    }

    /**
     * Probar conexión con una plataforma.
     *
     * @param string $platform_slug
     * @param array  $settings
     * @return array
     */
    public function test_connection(string $platform_slug, array $settings): array {
        $platform = $this->plugin->get_platform($platform_slug);

        if (!$platform) {
            return [
                'success' => false,
                'message' => __('Plataforma no encontrada.', 'social-auto-poster'),
            ];
        }

        if (!$platform->is_configured($settings)) {
            return [
                'success' => false,
                'message' => __('Configuración incompleta.', 'social-auto-poster'),
            ];
        }

        // Publicar un mensaje de prueba simple.
        $post_data = [
            'post_id'        => 0,
            'title'          => __('Prueba de conexión - Social Auto Poster', 'social-auto-poster'),
            'content'        => __('Este es un mensaje de prueba para verificar la conexión.', 'social-auto-poster'),
            'excerpt'        => __('Prueba de conexión del plugin Social Auto Poster.', 'social-auto-poster'),
            'url'            => home_url(),
            'featured_image' => '',
            'author'         => 0,
            'post_date'      => current_time('mysql'),
            'categories'     => [],
            'tags'           => [],
        ];

        return $platform->publish($post_data, $settings);
    }
}
