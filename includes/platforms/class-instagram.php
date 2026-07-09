<?php
namespace SocialAutoPoster\Platforms;

/**
 * Integración con Instagram.
 * Usa la Instagram Graph API para cuentas de creador/empresa.
 * Requiere: Page Access Token (de una página de Facebook conectada a Instagram).
 */
class Instagram implements PlatformInterface {

    /**
     * Versión de la Instagram Graph API.
     */
    const API_VERSION = 'v19.0';

    public function get_slug(): string {
        return 'instagram';
    }

    public function get_name(): string {
        return __('Instagram', 'social-auto-poster');
    }

    public function get_icon_url(): string {
        return 'dashicons-camera';
    }

    public function get_settings_fields(): array {
        return [
            'access_token' => [
                'id'          => 'access_token',
                'label'       => __('Access Token (Instagram)', 'social-auto-poster'),
                'type'        => 'password',
                'description' => __('Token de acceso de Instagram (de una cuenta de creador/empresa). Debe tener permisos instagram_basic, instagram_content_publish, pages_show_list.', 'social-auto-poster'),
                'required'    => true,
            ],
            'instagram_user_id' => [
                'id'          => 'instagram_user_id',
                'label'       => __('Instagram Business/Creator User ID', 'social-auto-poster'),
                'type'        => 'text',
                'description' => __('ID de la cuenta de Instagram Business o Creator (se obtiene de la API de Meta).', 'social-auto-poster'),
                'required'    => true,
            ],
        ];
    }

    public function is_configured(array $settings): bool {
        return !empty($settings['access_token'])
            && !empty($settings['instagram_user_id']);
    }

    public function publish(array $post_data, array $settings): array {
        if (!$this->is_configured($settings)) {
            return [
                'success' => false,
                'message' => __('Instagram no está configurado.', 'social-auto-poster'),
            ];
        }

        $user_id = $settings['instagram_user_id'];
        $token   = $settings['access_token'];
        $caption = $this->build_caption($post_data);

        // Instagram requiere imagen. Si no hay imagen destacada, no se puede publicar.
        if (empty($post_data['featured_image'])) {
            return [
                'success' => false,
                'message' => __('Instagram requiere una imagen destacada en el post.', 'social-auto-poster'),
            ];
        }

        // Paso 1: Crear contenedor de medios (IMAGE).
        $container_url = "https://graph.facebook.com/" . self::API_VERSION . "/{$user_id}/media";
        $container_body = [
            'image_url'    => $post_data['featured_image'],
            'caption'      => $caption,
            'access_token' => $token,
        ];

        $response = wp_remote_post($container_url, [
            'body'    => $container_body,
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => __('Error de conexión con Instagram: ', 'social-auto-poster') . $response->get_error_message(),
            ];
        }

        $result = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($result['id'])) {
            $msg = __('Error al crear el contenedor en Instagram.', 'social-auto-poster');
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
        $publish_url = "https://graph.facebook.com/" . self::API_VERSION . "/{$user_id}/media_publish";
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
                'message' => __('Error al publicar en Instagram: ', 'social-auto-poster') . $publish_response->get_error_message(),
            ];
        }

        $publish_result = json_decode(wp_remote_retrieve_body($publish_response), true);

        if (!empty($publish_result['id'])) {
            return [
                'success' => true,
                'message' => __('Publicado en Instagram exitosamente.', 'social-auto-poster'),
                'post_id' => $publish_result['id'],
            ];
        }

        $msg = __('Error al publicar el contenedor en Instagram.', 'social-auto-poster');
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
     * Construir caption para Instagram.
     * Instagram permite hasta 2200 caracteres.
     */
    private function build_caption(array $post_data): string {
        $caption = $post_data['title'] . "\n\n";

        if (!empty($post_data['excerpt'])) {
            $caption .= wp_trim_words($post_data['excerpt'], 40) . "\n\n";
        }

        $caption .= $post_data['url'];

        // Añadir hashtags de categorías.
        if (!empty($post_data['categories'])) {
            $tags = array_map(function($cat) {
                return '#' . str_replace(' ', '', $cat);
            }, $post_data['categories']);
            $caption .= "\n\n" . implode(' ', $tags);
        }

        if (mb_strlen($caption) > 2200) {
            $caption = mb_substr($caption, 0, 2197) . '…';
        }

        return $caption;
    }
}
