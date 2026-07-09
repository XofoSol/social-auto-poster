<?php
namespace SocialAutoPoster\Platforms;

/**
 * Integración con LinkedIn.
 * Usa la LinkedIn API para publicar en el feed con OAuth 2.0 integrado.
 * Requiere: Client ID, Client Secret (para OAuth), Access Token (se obtiene automáticamente).
 */
class LinkedIn implements PlatformInterface {

    /**
     * Versión de la LinkedIn API.
     */
    const API_VERSION = 'v2';

    const OAUTH_AUTHORIZE_URL = 'https://www.linkedin.com/oauth/v2/authorization';
    const OAUTH_TOKEN_URL     = 'https://www.linkedin.com/oauth/v2/accessToken';
    const OAUTH_SCOPES        = 'w_member_social,openid,profile,email';

    /**
     * Registrar hooks OAuth (callback y desconexión).
     */
    public static function register_oauth_hooks() {
        add_action('admin_post_sap_linkedin_oauth_callback', [self::class, 'handle_oauth_callback']);
        add_action('admin_post_sap_linkedin_oauth_disconnect', [self::class, 'handle_oauth_disconnect']);
    }

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
            'client_id' => [
                'id'          => 'client_id',
                'label'       => __('Client ID', 'social-auto-poster'),
                'type'        => 'text',
                'description' => __('ID de tu App de LinkedIn Developer. Necesario para el flujo OAuth.', 'social-auto-poster'),
                'required'    => true,
            ],
            'client_secret' => [
                'id'          => 'client_secret',
                'label'       => __('Client Secret', 'social-auto-poster'),
                'type'        => 'password',
                'description' => __('Secret de tu App de LinkedIn Developer. Necesario para el flujo OAuth.', 'social-auto-poster'),
                'required'    => true,
            ],
            'access_token' => [
                'id'          => 'access_token',
                'label'       => __('Access Token (LinkedIn)', 'social-auto-poster'),
                'type'        => 'password',
                'description' => __('Se obtiene automáticamente al conectar con LinkedIn. Token de acceso con permisos: w_member_social, openid, profile, email.', 'social-auto-poster'),
                'required'    => true,
            ],
            'author_id' => [
                'id'          => 'author_id',
                'label'       => __('LinkedIn Person/Organization ID', 'social-auto-poster'),
                'type'        => 'text',
                'description' => __('Se obtiene automáticamente al conectar con LinkedIn. URN: "urn:li:person:xxx" o "urn:li:organization:xxx".', 'social-auto-poster'),
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
        return !empty($settings['client_id'])
            && !empty($settings['client_secret'])
            && !empty($settings['access_token'])
            && !empty($settings['author_id']);
    }

    public function get_extra_settings_html(array $settings): string {
        $client_id     = $settings['client_id'] ?? '';
        $client_secret = $settings['client_secret'] ?? '';
        $has_token     = !empty($settings['access_token']);

        $html = '<hr style="margin: 20px 0;">';
        $html .= '<h4>' . esc_html__('Conexión OAuth con LinkedIn', 'social-auto-poster') . '</h4>';

        if ($has_token) {
            $html .= '<p style="color: #46b450;">&#10003; ' . esc_html__('Conectado a LinkedIn.', 'social-auto-poster') . '</p>';
            $html .= '<p><a href="' . esc_url(admin_url('admin-post.php?action=sap_linkedin_oauth_disconnect')) . '" class="button" style="color: #dc3232;">'
                . esc_html__('Desconectar LinkedIn', 'social-auto-poster') . '</a></p>';
        } elseif (!empty($client_id) && !empty($client_secret)) {
            $authorize_url = $this->build_authorize_url($client_id);
            $html .= '<p>' . esc_html__('Guarda primero los cambios con Client ID y Client Secret, luego haz clic en:', 'social-auto-poster') . '</p>';
            $html .= '<p><a href="' . esc_url($authorize_url) . '" class="button button-primary">'
                . esc_html__('Conectar con LinkedIn', 'social-auto-poster') . '</a></p>';
        } else {
            $html .= '<p class="description">' . esc_html__('Completa el Client ID y Client Secret, guarda los cambios, y luego podrás conectar con LinkedIn.', 'social-auto-poster') . '</p>';
        }

        return $html;
    }

    /**
     * Construir la URL de autorización de LinkedIn.
     */
    private function build_authorize_url(string $client_id): string {
        $redirect_uri = admin_url('admin-post.php?action=sap_linkedin_oauth_callback');
        $state = wp_create_nonce('sap_linkedin_oauth_state');

        // Guardar el state en sesión o transiente para verificarlo después.
        set_transient('sap_linkedin_oauth_state_' . get_current_user_id(), $state, 600);

        $params = [
            'response_type' => 'code',
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'state'         => $state,
            'scope'         => self::OAUTH_SCOPES,
        ];

        return self::OAUTH_AUTHORIZE_URL . '?' . http_build_query($params);
    }

    /**
     * Manejar el callback OAuth de LinkedIn (intercambia code por token).
     */
    public static function handle_oauth_callback() {
        if (empty($_GET['code']) || empty($_GET['state'])) {
            wp_die(__('Parámetros inválidos en el callback de LinkedIn.', 'social-auto-poster'));
        }

        // Verificar state (CSRF).
        $saved_state = get_transient('sap_linkedin_oauth_state_' . get_current_user_id());
        if (!$saved_state || $_GET['state'] !== $saved_state) {
            wp_die(__('State inválido. Posible ataque CSRF.', 'social-auto-poster'));
        }
        delete_transient('sap_linkedin_oauth_state_' . get_current_user_id());

        if (!current_user_can('manage_options')) {
            wp_die(__('Permiso denegado.', 'social-auto-poster'));
        }

        $code = sanitize_text_field($_GET['code']);

        // Obtener Client ID y Secret de las opciones guardadas.
        $settings = \SocialAutoPoster\Main::get_options();
        $li_settings = $settings['linkedin'] ?? [];

        if (empty($li_settings['client_id']) || empty($li_settings['client_secret'])) {
            wp_die(__('LinkedIn no está configurado. Guarda Client ID y Client Secret primero.', 'social-auto-poster'));
        }

        $redirect_uri = admin_url('admin-post.php?action=sap_linkedin_oauth_callback');

        // Intercambiar code por access token.
        $token_response = wp_remote_post(self::OAUTH_TOKEN_URL, [
            'body' => [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'client_id'     => $li_settings['client_id'],
                'client_secret' => $li_settings['client_secret'],
                'redirect_uri'  => $redirect_uri,
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($token_response)) {
            wp_die(__('Error al obtener token de LinkedIn: ', 'social-auto-poster') . $token_response->get_error_message());
        }

        $token_data = json_decode(wp_remote_retrieve_body($token_response), true);

        if (empty($token_data['access_token'])) {
            $error_msg = !empty($token_data['error_description'])
                ? $token_data['error_description']
                : __('Error desconocido al obtener token.', 'social-auto-poster');
            wp_die(esc_html($error_msg));
        }

        $access_token = $token_data['access_token'];

        // Obtener información del usuario (author_id).
        $user_response = wp_remote_get('https://api.linkedin.com/v2/userinfo', [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
            ],
            'timeout' => 15,
        ]);

        $author_id = '';
        if (!is_wp_error($user_response)) {
            $user_data = json_decode(wp_remote_retrieve_body($user_response), true);
            if (!empty($user_data['sub'])) {
                $author_id = 'urn:li:person:' . $user_data['sub'];
            }
        }

        // Guardar token y author_id en las opciones.
        if (empty($author_id)) {
            // Si no se pudo obtener el author_id, al menos guardar el token.
            $li_settings['access_token'] = $access_token;
        } else {
            $li_settings['access_token'] = $access_token;
            $li_settings['author_id']    = $author_id;
        }

        $settings['linkedin'] = $li_settings;
        update_option(\SocialAutoPoster\Admin::OPTION_KEY, $settings);

        // Redirigir al panel de LinkedIn.
        wp_redirect(admin_url('admin.php?page=social-auto-poster&tab=linkedin&sap_linkedin=connected'));
        exit;
    }

    /**
     * Desconectar LinkedIn (eliminar token).
     */
    public static function handle_oauth_disconnect() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Permiso denegado.', 'social-auto-poster'));
        }

        $settings = \SocialAutoPoster\Main::get_options();
        if (isset($settings['linkedin'])) {
            $settings['linkedin']['access_token'] = '';
            $settings['linkedin']['author_id'] = '';
            update_option(\SocialAutoPoster\Admin::OPTION_KEY, $settings);
        }

        wp_redirect(admin_url('admin.php?page=social-auto-poster&tab=linkedin&sap_linkedin=disconnected'));
        exit;
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

    /**
     * Construir el texto del commentary para LinkedIn.
     */
    private function build_commentary(array $post_data): string {
        $commentary = $post_data['title'] . "\n\n";

        if (!empty($post_data['excerpt'])) {
            $commentary .= wp_trim_words($post_data['excerpt'], 50) . "\n\n";
        }

        $commentary .= $post_data['url'];

        if (mb_strlen($commentary) > 3000) {
            $commentary = mb_substr($commentary, 0, 2997) . '…';
        }

        return $commentary;
    }

    /**
     * Subir una imagen a LinkedIn y obtener su URN.
     */
    private function upload_media(string $image_url, string $token, string $author): ?string {
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
