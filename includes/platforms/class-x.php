<?php
namespace SocialAutoPoster\Platforms;

/**
 * Integración con X (Twitter).
 * Usa la API v2 de X para publicar tweets.
 */
class X implements PlatformInterface {

    /**
     * Versión de la API de X para tweets.
     */
    const API_VERSION = '2';

    /**
     * Versión de la API de subida de medios de X.
     */
    const MEDIA_API_VERSION = '1.1';

    public function get_slug(): string {
        return 'x';
    }

    public function get_name(): string {
        return __('X (Twitter)', 'social-auto-poster');
    }

    public function get_icon_url(): string {
        return 'dashicons-twitter'; // WordPress no tiene icono nativo de X, usamos placeholder
    }

    public function get_settings_fields(): array {
        return [
            'api_key' => [
                'id'          => 'api_key',
                'label'       => __('API Key (Consumer Key)', 'social-auto-poster'),
                'type'        => 'password',
                'description' => __('Tu API Key de X Developer Portal.', 'social-auto-poster'),
                'required'    => true,
            ],
            'api_secret' => [
                'id'          => 'api_secret',
                'label'       => __('API Secret (Consumer Secret)', 'social-auto-poster'),
                'type'        => 'password',
                'description' => __('Tu API Secret de X Developer Portal.', 'social-auto-poster'),
                'required'    => true,
            ],
            'access_token' => [
                'id'          => 'access_token',
                'label'       => __('Access Token', 'social-auto-poster'),
                'type'        => 'password',
                'description' => __('Access Token de la cuenta de X.', 'social-auto-poster'),
                'required'    => true,
            ],
            'access_token_secret' => [
                'id'          => 'access_token_secret',
                'label'       => __('Access Token Secret', 'social-auto-poster'),
                'type'        => 'password',
                'description' => __('Access Token Secret de la cuenta de X.', 'social-auto-poster'),
                'required'    => true,
            ],
            'post_type' => [
                'id'          => 'post_type',
                'label'       => __('Tipo de publicación', 'social-auto-poster'),
                'type'        => 'select',
                'options'     => [
                    'text'       => __('Solo texto', 'social-auto-poster'),
                    'text_image' => __('Texto + imagen destacada', 'social-auto-poster'),
                ],
                'description' => __('¿Qué incluir en el tweet?', 'social-auto-poster'),
                'required'    => false,
                'default'     => 'text_image',
            ],
        ];
    }

    public function is_configured(array $settings): bool {
        return !empty($settings['api_key'])
            && !empty($settings['api_secret'])
            && !empty($settings['access_token'])
            && !empty($settings['access_token_secret']);
    }

    public function publish(array $post_data, array $settings): array {
        if (!$this->is_configured($settings)) {
            return [
                'success' => false,
                'message' => __('X no está configurado.', 'social-auto-poster'),
            ];
        }

        $text = $this->build_tweet_text($post_data, $settings);

        // Construir payload.
        $payload = ['text' => $text];

        // Si hay imagen destacada y está configurado para incluirla.
        $media_id = null;
        if (!empty($post_data['featured_image'])
            && (!isset($settings['post_type']) || $settings['post_type'] === 'text_image')
        ) {
            $media_id = $this->upload_media($post_data['featured_image'], $settings);
            if ($media_id) {
                $payload['media'] = ['media_ids' => [$media_id]];
            }
        }

        // Llamar a la API v2 de X.
        $result = $this->call_x_api('https://api.twitter.com/' . self::API_VERSION . '/tweets', $payload, 'POST', $settings);

        if ($result && !empty($result['data']['id'])) {
            return [
                'success' => true,
                'message' => sprintf(
                    __('Publicado en X: %s', 'social-auto-poster'),
                    'https://twitter.com/i/web/status/' . $result['data']['id']
                ),
                'post_id' => $result['data']['id'],
            ];
        }

        $error_msg = __('Error al publicar en X.', 'social-auto-poster');
        if ($result && !empty($result['errors'])) {
            $error_msg = $result['errors'][0]['message'] ?? $error_msg;
        }

        return [
            'success' => false,
            'message' => $error_msg,
        ];
    }

    public function get_extra_settings_html(array $settings): string {
        return ''; // Sin HTML adicional para X.
    }

    /**
     * Construir el texto del tweet.
     */
    private function build_tweet_text(array $post_data, array $settings): string {
        $title   = $post_data['title'];
        $url     = $post_data['url'];
        $excerpt = !empty($post_data['excerpt'])
            ? wp_trim_words($post_data['excerpt'], 20)
            : '';

        // X acorta URLs a 23 caracteres (t.co).
        $url_shortened = 23;
        $text = $title;

        if (!empty($excerpt)) {
            $text .= "\n\n" . $excerpt;
        }

        $text .= "\n\n" . $url;

        // Acortar si excede 280 caracteres (considerando t.co).
        $url_display_len = $url_shortened;
        if (mb_strlen($text) > 280) {
            $max_content_len = 280 - $url_display_len - 4; // 4 por "\n\n"
            // Intentar mantener title + excerpt dentro del límite.
            $title_excerpt = $title;
            if (!empty($excerpt)) {
                $title_excerpt .= "\n\n" . $excerpt;
            }
            if (mb_strlen($title_excerpt) > $max_content_len) {
                $title_excerpt = mb_substr($title_excerpt, 0, $max_content_len - 1) . '…';
            }
            $text = $title_excerpt . "\n\n" . $url;
        }

        return $text;
    }

    /**
     * Subir una imagen a X y obtener el media_id.
     */
    private function upload_media(string $image_url, array $settings): ?string {
        // Descargar la imagen usando el helper centralizado.
        $image = \SocialAutoPoster\Media_Helper::download_image($image_url);
        if (!$image) {
            return null;
        }

        $file_data = $image['data'];
        $mime_type = $image['mime_type'];

        // La API de medios de X usa OAuth 1.0a con multipart/form-data.
        $boundary = wp_generate_password(24, false);
        $body = '';

        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="media"; filename="media.' . $this->get_extension($mime_type) . "\"\r\n";
        $body .= 'Content-Type: ' . $mime_type . "\r\n\r\n";
        $body .= $file_data . "\r\n";
        $body .= '--' . $boundary . "--\r\n";

        $oauth_params = $this->build_oauth_params('POST', 'https://upload.twitter.com/' . self::MEDIA_API_VERSION . '/media/upload.json', $settings);
        $auth_header = $this->build_oauth_header($oauth_params);

        $response = wp_remote_post('https://upload.twitter.com/' . self::MEDIA_API_VERSION . '/media/upload.json', [
            'headers' => [
                'Authorization' => $auth_header,
                'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
            ],
            'body'    => $body,
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body_resp = json_decode(wp_remote_retrieve_body($response), true);
        return $body_resp['media_id_string'] ?? null;
    }

    /**
     * Llamar a la API de X (Twitter) v2 con OAuth 1.0a.
     */
    private function call_x_api(string $url, array $payload, string $method, array $settings) {
        $oauth_params = $this->build_oauth_params($method, $url, $settings);
        $auth_header  = $this->build_oauth_header($oauth_params);

        $args = [
            'headers' => [
                'Authorization' => $auth_header,
                'Content-Type'  => 'application/json',
            ],
            'body'    => json_encode($payload),
            'timeout' => 20,
        ];

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            return ['errors' => [['message' => $response->get_error_message()]]];
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /**
     * Construir parámetros OAuth 1.0a.
     */
    private function build_oauth_params(string $method, string $url, array $settings): array {
        $oauth = [
            'oauth_consumer_key'     => $settings['api_key'],
            'oauth_nonce'            => wp_generate_password(32, false),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => time(),
            'oauth_token'            => $settings['access_token'],
            'oauth_version'          => '1.0',
        ];

        // Para POST con body JSON, no hay parámetros de consulta adicionales.
        $base_string = $method . '&' . rawurlencode($url) . '&';
        $params = [];
        foreach ($oauth as $key => $value) {
            $params[rawurlencode($key)] = rawurlencode($value);
        }
        ksort($params);
        $param_string = '';
        foreach ($params as $key => $value) {
            $param_string .= $key . '=' . $value . '&';
        }
        $param_string = rtrim($param_string, '&');
        $base_string .= rawurlencode($param_string);

        $signing_key = rawurlencode($settings['api_secret']) . '&' . rawurlencode($settings['access_token_secret']);
        $oauth['oauth_signature'] = base64_encode(hash_hmac('sha1', $base_string, $signing_key, true));

        return $oauth;
    }

    /**
     * Construir el header Authorization para OAuth 1.0a.
     */
    private function build_oauth_header(array $oauth_params): string {
        $parts = [];
        foreach ($oauth_params as $key => $value) {
            $parts[] = $key . '="' . rawurlencode($value) . '"';
        }
        return 'OAuth ' . implode(', ', $parts);
    }

    /**
     * Obtener extensión de archivo según MIME type.
     */
    private function get_extension(string $mime_type): string {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        ];
        return $map[$mime_type] ?? 'jpg';
    }
}
