<?php
namespace SocialAutoPoster\Platforms;

/**
 * Integración con Threads (Meta).
 * Usa la Threads API (Graph API) para publicar hilos.
 * Requiere: App ID, App Secret, Access Token de Threads.
 */
class Threads implements PlatformInterface {

    /**
     * Versión de la Threads API.
     */
    const API_VERSION = 'v1.0';

    public function get_slug(): string {
        return 'threads';
    }

    public function get_name(): string {
        return __('Threads', 'social-auto-poster');
    }

    public function get_icon_url(): string {
        return 'dashicons-format-chat';
    }

    public function get_settings_fields(): array {
        return [
            'access_token' => [
                'id'          => 'access_token',
                'label'       => __('Access Token (Threads)', 'social-auto-poster'),
                'type'        => 'password',
                'description' => __('Token de acceso de la cuenta de Threads.', 'social-auto-poster'),
                'required'    => true,
            ],
            'user_id' => [
                'id'          => 'user_id',
                'label'       => __('Threads User ID', 'social-auto-poster'),
                'type'        => 'text',
                'description' => __('ID de usuario de Threads (se obtiene de la API).', 'social-auto-poster'),
                'required'    => true,
            ],
        ];
    }

    public function is_configured(array $settings): bool {
        return !empty($settings['access_token'])
            && !empty($settings['user_id']);
    }

    public function publish(array $post_data, array $settings): array {
        if (!$this->is_configured($settings)) {
            return [
                'success' => false,
                'message' => __('Threads no está configurado.', 'social-auto-poster'),
            ];
        }

        $user_id  = $settings['user_id'];
        $token    = $settings['access_token'];
        $text     = $this->build_thread_text($post_data);

        // Paso 1: Crear el media container en Threads.
        $container_url = "https://graph.threads.net/" . self::API_VERSION . "/{$user_id}/threads";

        if (!empty($post_data['featured_image'])) {
            // Publicación con imagen.
            $container_body = [
                'media_type' => 'IMAGE',
                'image_url'  => $post_data['featured_image'],
                'text'       => $text,
                'access_token' => $token,
            ];
        } else {
            // Publicación solo texto.
            $container_body = [
                'media_type' => 'TEXT',
                'text'       => $text,
                'access_token' => $token,
            ];
        }

        $response = wp_remote_post($container_url, [
            'body'    => $container_body,
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => __('Error de conexión con Threads: ', 'social-auto-poster') . $response->get_error_message(),
            ];
        }

        $result = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($result['id'])) {
            $msg = __('Error al crear el contenedor en Threads.', 'social-auto-poster');
            if (!empty($result['error']['message'])) {
                $msg = $result['error']['message'];
            }
            return [
                'success' => false,
                'message' => $msg,
            ];
        }

        $container_id = $result['id'];

        // Paso 2: Publicar el contenedor.
        $publish_url = "https://graph.threads.net/" . self::API_VERSION . "/{$user_id}/threads_publish";
        $publish_response = wp_remote_post($publish_url, [
            'body' => [
                'creation_id'  => $container_id,
                'access_token' => $token,
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($publish_response)) {
            return [
                'success' => false,
                'message' => __('Error al publicar en Threads: ', 'social-auto-poster') . $publish_response->get_error_message(),
            ];
        }

        $publish_result = json_decode(wp_remote_retrieve_body($publish_response), true);

        if (!empty($publish_result['id'])) {
            return [
                'success' => true,
                'message' => __('Publicado en Threads exitosamente.', 'social-auto-poster'),
                'post_id' => $publish_result['id'],
            ];
        }

        $msg = __('Error al publicar el contenedor en Threads.', 'social-auto-poster');
        if (!empty($publish_result['error']['message'])) {
            $msg = $publish_result['error']['message'];
        }

        return [
            'success' => false,
            'message' => $msg,
        ];
    }

    public function get_extra_settings_html(array $settings): string {
        return '';
    }

    /**
     * Construir el texto para Threads.
     */
    private function build_thread_text(array $post_data): string {
        $text = $post_data['title'] . "\n\n";

        if (!empty($post_data['excerpt'])) {
            $text .= wp_trim_words($post_data['excerpt'], 30) . "\n\n";
        }

        $text .= $post_data['url'];

        // Threads permite hasta 500 caracteres.
        if (mb_strlen($text) > 500) {
            $text = mb_substr($text, 0, 497) . '…';
        }

        return $text;
    }
}
