<?php
namespace SocialAutoPoster\Platforms;

/**
 * Integración con LinkedIn.
 * Usa la LinkedIn API v2 para publicar en el feed.
 * Requiere: Client ID, Client Secret, Access Token.
 */
class LinkedIn implements PlatformInterface {

    /**
     * Versión de la LinkedIn API.
     */
    const API_VERSION = 'v2';

    public function get_slug(): string {
        return 'linkedin';
    }

    public function get_name(): string {
        return __('LinkedIn', 'social-auto-poster');
    }

    public function get_icon_url(): string {
        return 'dashicons-id';
    }

    public function get_settings_fields(): array {
        return [
            'access_token' => [
                'id'          => 'access_token',
                'label'       => __('Access Token (LinkedIn)', 'social-auto-poster'),
                'type'        => 'password',
                'description' => __('Token de acceso de LinkedIn. Debe tener permisos: w_member_social, openid, profile, email.', 'social-auto-poster'),
                'required'    => true,
            ],
            'author_id' => [
                'id'          => 'author_id',
                'label'       => __('LinkedIn Person/Organization ID', 'social-auto-poster'),
                'type'        => 'text',
                'description' => __('Tu LinkedIn Person URN o Organization URN. Ej: "urn:li:person:abc123" o "urn:li:organization:xyz456". Se puede obtener llamando a /me.', 'social-auto-poster'),
                'required'    => true,
            ],
            'visibility' => [
                'id'          => 'visibility',
                'label'       => __('Visibilidad', 'social-auto-poster'),
                'type'        => 'select',
                'options'     => [
                    'PUBLIC'  => __('Público', 'social-auto-poster'),
                    'CONNECTIONS' => __('Solo conexiones', 'social-auto-poster'),
                ],
                'description' => __('¿Quién puede ver la publicación?', 'social-auto-poster'),
                'required'    => false,
                'default'     => 'PUBLIC',
            ],
        ];
    }

    public function is_configured(array $settings): bool {
        return !empty($settings['access_token'])
            && !empty($settings['author_id']);
    }

    public function publish(array $post_data, array $settings): array {
        if (!$this->is_configured($settings)) {
            return [
                'success' => false,
                'message' => __('LinkedIn no está configurado.', 'social-auto-poster'),
            ];
        }

        $author       = $settings['author_id'];
        $token        = $settings['access_token'];
        $visibility   = $settings['visibility'] ?? 'PUBLIC';
        $commentary   = $this->build_commentary($post_data);

        // LinkedIn requiere que el author_id comience con "urn:li:".
        if (strpos($author, 'urn:li:') !== 0) {
            // Intentar auto-corregir: podría ser solo un ID numérico.
            $author = 'urn:li:person:' . $author;
        }

        // Construir payload para LinkedIn API v2.
        $payload = [
            'author' => $author,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary' => [
                        'text' => $commentary,
                    ],
                    'shareMediaCategory' => 'NONE',
                ],
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => $visibility,
            ],
        ];

        // Si hay imagen destacada, añadirla como media.
        if (!empty($post_data['featured_image'])) {
            // Subir la imagen a LinkedIn primero (o usar URL).
            $media_urn = $this->upload_media($post_data['featured_image'], $token, $author);
            if ($media_urn) {
                $payload['specificContent']['com.linkedin.ugc.ShareContent']['shareMediaCategory'] = 'IMAGE';
                $payload['specificContent']['com.linkedin.ugc.ShareContent']['media'] = [
                    [
                        'status' => 'READY',
                        'description' => [
                            'text' => wp_trim_words($post_data['title'], 10),
                        ],
                        'media' => $media_urn,
                        'title' => [
                            'text' => $post_data['title'],
                        ],
                    ],
                ];
            }
        }

        $response = wp_remote_post('https://api.linkedin.com/' . self::API_VERSION . '/ugcPosts', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'X-Restli-Protocol-Version' => '2.0.0',
            ],
            'body'    => json_encode($payload),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => __('Error de conexión con LinkedIn: ', 'social-auto-poster') . $response->get_error_message(),
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        // LinkedIn devuelve 201 Created con un header "x-restli-id" en éxito.
        if ($status_code === 201) {
            $post_id = wp_remote_retrieve_header($response, 'x-restli-id');
            return [
                'success' => true,
                'message' => __('Publicado en LinkedIn exitosamente.', 'social-auto-poster'),
                'post_id' => $post_id,
            ];
        }

        $msg = __('Error al publicar en LinkedIn.', 'social-auto-poster');
        if (!empty($result['message'])) {
            $msg = $result['message'];
        } elseif (!empty($result['error']['message'])) {
            $msg = $result['error']['message'];
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
     * Construir el texto del commentary para LinkedIn.
     */
    private function build_commentary(array $post_data): string {
        $commentary = $post_data['title'] . "\n\n";

        if (!empty($post_data['excerpt'])) {
            $commentary .= wp_trim_words($post_data['excerpt'], 50) . "\n\n";
        }

        $commentary .= $post_data['url'];

        // LinkedIn permite hasta 3000 caracteres.
        if (mb_strlen($commentary) > 3000) {
            $commentary = mb_substr($commentary, 0, 2997) . '…';
        }

        return $commentary;
    }

    /**
     * Subir una imagen a LinkedIn y obtener su URN.
     * LinkedIn requiere un proceso de 2 pasos: registerUpload luego upload.
     */
    private function upload_media(string $image_url, string $token, string $author): ?string {
        // Paso 1: Registrar la carga.
        $register_payload = [
            'registerUploadRequest' => [
                'recipes' => [
                    ['firstPublishedElement' => 'urn:li:digitalmediaRecipe:feedshare-image'],
                ],
                'owner' => $author,
                'serviceRelationships' => [
                    [
                        'relationshipType' => 'OWNER',
                        'identifier' => 'urn:li:userGeneratedContent',
                    ],
                ],
                'supportedUploadMechanism' => ['SYNCHRONOUS_UPLOAD'],
            ],
        ];

        $register_response = wp_remote_post('https://api.linkedin.com/' . self::API_VERSION . '/assets?action=registerUpload', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'X-Restli-Protocol-Version' => '2.0.0',
            ],
            'body'    => json_encode($register_payload),
            'timeout' => 20,
        ]);

        if (is_wp_error($register_response)) {
            return null;
        }

        $register_result = json_decode(wp_remote_retrieve_body($register_response), true);

        $upload_url = $register_result['value']['uploadMechanism']['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest']['uploadUrl'] ?? null;
        $asset_urn  = $register_result['value']['asset'] ?? null;

        if (!$upload_url || !$asset_urn) {
            return null;
        }

        // Paso 2: Descargar y subir la imagen.
        $image = \SocialAutoPoster\Media_Helper::download_image($image_url);
        if (!$image) {
            return null;
        }

        $upload_response = wp_remote_request($upload_url, [
            'method'  => 'PUT',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => $image['mime_type'],
            ],
            'body'    => $image['data'],
            'timeout' => 30,
        ]);

        if (is_wp_error($upload_response)) {
            return null;
        }

        $upload_status = wp_remote_retrieve_response_code($upload_response);
        if ($upload_status === 201 || $upload_status === 200) {
            return $asset_urn;
        }

        return null;
    }
}
