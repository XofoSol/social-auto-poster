<?php
namespace SocialAutoPoster\Platforms;

/**
 * Integración con Facebook.
 * Usa la Facebook Graph API para publicar en perfiles y páginas.
 * Soporta selección de páginas de Facebook.
 */
class Facebook implements PlatformInterface {

    /**
     * Versión de la Graph API de Facebook.
     */
    const API_VERSION = 'v19.0';

    public function get_slug(): string {
        return 'facebook';
    }

    public function get_name(): string {
        return __('Facebook', 'social-auto-poster');
    }

    public function get_icon_url(): string {
        return 'dashicons-facebook';
    }

    public function get_settings_fields(): array {
        return [
            'access_token' => [
                'id'          => 'access_token',
                'label'       => __('Access Token (Facebook)', 'social-auto-poster'),
                'type'        => 'password',
                'description' => __('Token de acceso de Facebook con permisos: pages_manage_posts, pages_read_engagement, publish_to_groups (si aplica).', 'social-auto-poster'),
                'required'    => true,
            ],
            'publish_type' => [
                'id'          => 'publish_type',
                'label'       => __('Tipo de destino', 'social-auto-poster'),
                'type'        => 'select',
                'options'     => [
                    'profile' => __('Perfil personal', 'social-auto-poster'),
                    'page'    => __('Página de Facebook', 'social-auto-poster'),
                ],
                'description' => __('¿Publicar en tu perfil personal o en una página?', 'social-auto-poster'),
                'required'    => false,
                'default'     => 'profile',
            ],
            'page_id' => [
                'id'          => 'page_id',
                'label'       => __('ID de la Página de Facebook', 'social-auto-poster'),
                'type'        => 'text',
                'description' => __('ID de la página donde publicar (solo si seleccionaste "Página" arriba). Se puede obtener desde el botón "Cargar páginas".', 'social-auto-poster'),
                'required'    => false,
            ],
            'page_access_token' => [
                'id'          => 'page_access_token',
                'label'       => __('Page Access Token', 'social-auto-poster'),
                'type'        => 'password',
                'description' => __('Token de acceso específico de la página. Se genera automáticamente al seleccionar una página.', 'social-auto-poster'),
                'required'    => false,
            ],
            'link_placement' => [
                'id'          => 'link_placement',
                'label'       => __('Ubicación del enlace', 'social-auto-poster'),
                'type'        => 'select',
                'options'     => [
                    'comment' => __('En el primer comentario (recomendado por Meta)', 'social-auto-poster'),
                    'body'    => __('En el cuerpo del post', 'social-auto-poster'),
                ],
                'description' => __('Meta recomienda poner el enlace en el primer comentario para mejorar el alcance orgánico. El 97.3% de los posts más vistos no tienen enlaces en el cuerpo.', 'social-auto-poster'),
                'required'    => false,
                'default'     => 'comment',
            ],
        ];
    }

    public function is_configured(array $settings): bool {
        $base = !empty($settings['access_token']);

        if (!$base) {
            return false;
        }

        // Si es página, necesita page_id y page_access_token.
        if (!empty($settings['publish_type']) && $settings['publish_type'] === 'page') {
            return !empty($settings['page_id']) && !empty($settings['page_access_token']);
        }

        return true;
    }

    public function publish(array $post_data, array $settings): array {
        if (!$this->is_configured($settings)) {
            return [
                'success' => false,
                'message' => __('Facebook no está configurado.', 'social-auto-poster'),
            ];
        }

        // Construir mensaje: sin URL si va en comentario.
        $link_placement = $settings['link_placement'] ?? 'comment';
        $include_url_in_body = ($link_placement !== 'comment');
        $message = $this->build_message($post_data, $include_url_in_body);
        $publish_type = $settings['publish_type'] ?? 'profile';

        // Determinar el token y el endpoint según el tipo de publicación.
        if ($publish_type === 'page') {
            $token = $settings['page_access_token'];
            $node_id = $settings['page_id'];
        } else {
            $token = $settings['access_token'];
            // Para perfil: obtener el user_id primero.
            $user_id = $this->get_user_id($token);
            if (!$user_id) {
                return [
                    'success' => false,
                    'message' => __('No se pudo obtener el ID de usuario de Facebook.', 'social-auto-poster'),
                ];
            }
            $node_id = $user_id;
        }

        // Construir payload.
        $payload = [
            'message'     => $message,
            'access_token' => $token,
        ];

        // Si hay imagen destacada, añadirla.
        if (!empty($post_data['featured_image'])) {
            $payload['picture']       = $post_data['featured_image'];
            $payload['name']          = $post_data['title'];
            if (!empty($post_data['excerpt'])) {
                $payload['description'] = wp_trim_words($post_data['excerpt'], 30);
            }
        }

        // Colocar el enlace según la configuración.
        if ($link_placement === 'body') {
            // Modo tradicional: el enlace va en el cuerpo (como link attachment).
            $payload['link'] = $post_data['url'];
            if (empty($post_data['featured_image'])) {
                $payload['name'] = $post_data['title'];
            }
        }
        // Si es 'comment', NO incluimos 'link' en el payload — va como comentario después.

        // Llamar a la Graph API.
        $url = "https://graph.facebook.com/" . self::API_VERSION . "/{$node_id}/feed";

        $response = wp_remote_post($url, [
            'body'    => $payload,
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => __('Error de conexión con Facebook: ', 'social-auto-poster') . $response->get_error_message(),
            ];
        }

        $result = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($result['id'])) {
            $post_id = $result['id'];
            $messages = [sprintf(
                __('Publicado en Facebook: %s', 'social-auto-poster'),
                'https://facebook.com/' . $post_id
            )];

            // Si la configuración es 'comment', agregar el enlace como primer comentario.
            if ($link_placement === 'comment') {
                $comment_result = $this->add_comment($post_id, $post_data['url'], $token);
                if ($comment_result['success']) {
                    $messages[] = __('Enlace añadido en el primer comentario.', 'social-auto-poster');
                } else {
                    $messages[] = $comment_result['message'];
                }
            }

            return [
                'success' => true,
                'message' => implode(' ', $messages),
                'post_id' => $post_id,
            ];
        }

        $msg = __('Error al publicar en Facebook.', 'social-auto-poster');
        if (!empty($result['error']['message'])) {
            $msg = $result['error']['message'];
        }

        return [
            'success' => false,
            'message' => $msg,
        ];
    }

    /**
     * Añadir un comentario (con el enlace) a un post de Facebook.
     * Si es una página, intenta anclar el comentario.
     *
     * @param string $post_id ID del post en Facebook.
     * @param string $url     URL a incluir en el comentario.
     * @param string $token   Access token.
     * @return array Con 'success' (bool) y 'message' (string).
     */
    private function add_comment(string $post_id, string $url, string $token): array {
        $comment_url = "https://graph.facebook.com/" . self::API_VERSION . "/{$post_id}/comments";

        $response = wp_remote_post($comment_url, [
            'body' => [
                'message'      => $url,
                'access_token' => $token,
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => __('Error al añadir comentario con enlace.', 'social-auto-poster'),
            ];
        }

        $result = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($result['id'])) {
            return [
                'success' => true,
                'message' => '',
            ];
        }

        $msg = __('Error al añadir el comentario.', 'social-auto-poster');
        if (!empty($result['error']['message'])) {
            $msg = $result['error']['message'];
        }

        return [
            'success' => false,
            'message' => $msg,
        ];
    }

    /**
     * Renderizar HTML extra para Facebook: selector de páginas.
     */
    public function get_extra_settings_html(array $settings): string {
        $html = '';
        $token = $settings['access_token'] ?? '';

        if (!empty($token)) {
            $html .= '<div class="sap-fb-pages-section" style="margin-top: 15px; padding: 15px; background: #f0f6fc; border-left: 4px solid #1877f2;">';
            $html .= '<h4>' . esc_html__('Páginas de Facebook', 'social-auto-poster') . '</h4>';
            $html .= '<p>' . esc_html__('Selecciona una página para obtener su Access Token.', 'social-auto-poster') . '</p>';
            $html .= '<button type="button" class="button sap-load-pages" data-nonce="' . wp_create_nonce('sap_fb_pages') . '">'
                . esc_html__('Cargar mis páginas', 'social-auto-poster') . '</button>';
            $html .= '<div class="sap-fb-pages-list" style="margin-top: 10px;"></div>';
            $html .= '<p class="description">' . esc_html__('Usa este botón después de guardar tu Access Token. Selecciona una página y copia su ID y Token en los campos de arriba.', 'social-auto-poster') . '</p>';
            $html .= '</div>';

            // Traducciones para el script inline.
            $i18n = [
                'loading'     => esc_js(__('Cargando…', 'social-auto-poster')),
                'pages'       => esc_js(__('Cargando páginas…', 'social-auto-poster')),
                'name'        => esc_js(__('Nombre', 'social-auto-poster')),
                'id'          => esc_js(__('ID', 'social-auto-poster')),
                'action'      => esc_js(__('Acción', 'social-auto-poster')),
                'usePage'     => esc_js(__('Usar esta página', 'social-auto-poster')),
                'noPages'     => esc_js(__('No se encontraron páginas. Asegúrate de que tu token tenga permisos pages_show_list.', 'social-auto-poster')),
                'error'       => esc_js(__('Error al cargar páginas.', 'social-auto-poster')),
                'connError'   => esc_js(__('Error de conexión.', 'social-auto-poster')),
                'loadPages'   => esc_js(__('Cargar mis páginas', 'social-auto-poster')),
                'selected'    => esc_js(__('Página seleccionada: ', 'social-auto-poster')),
                'saveMsg'     => esc_js(__('Los campos se han actualizado. Guarda los cambios.', 'social-auto-poster')),
            ];

            $html .= '<script>
            (function($) {
                var i = ' . wp_json_encode($i18n) . ';
                $(document).on("click", ".sap-load-pages", function(e) {
                    e.preventDefault();
                    var btn = $(this);
                    var list = $(".sap-fb-pages-list");
                    var nonce = btn.data("nonce");
                    btn.prop("disabled", true).text(i.loading);
                    list.html("<p>" + i.pages + "</p>");
                    $.post(ajaxurl, { action: "sap_load_fb_pages", nonce: nonce }, function(resp) {
                        if (resp.success && resp.data.pages.length > 0) {
                            window._sapFbPages = {};
                            var html = "<table class=\"widefat\"><thead><tr><th>" + i.name + "</th><th>" + i.id + "</th><th>" + i.action + "</th></tr></thead><tbody>";
                            $.each(resp.data.pages, function(i, page) {
                                window._sapFbPages[page.id] = page.access_token;
                                html += "<tr><td><strong>" + page.name + "</strong></td><td><code>" + page.id + "</code></td>";
                                html += "<td><button type=\"button\" class=\"button button-small sap-select-page\" data-page-id=\"" + page.id + "\" data-page-name=\"" + page.name + "\">" + i.usePage + "</button></td></tr>";
                            });
                            html += "</tbody></table>";
                            list.html(html);
                        } else if (resp.success && resp.data.pages.length === 0) {
                            list.html("<p>" + i.noPages + "</p>");
                        } else {
                            list.html("<p class=\"error\">" + (resp.data?.message || i.error) + "</p>");
                        }
                        btn.prop("disabled", false).text(i.loadPages);
                    }).fail(function() {
                        list.html("<p class=\"error\">" + i.connError + "</p>");
                        btn.prop("disabled", false).text(i.loadPages);
                    });
                });
                $(document).on("click", ".sap-select-page", function() {
                    var pageId = $(this).data("page-id");
                    var pageName = $(this).data("page-name");
                    var pageToken = window._sapFbPages && window._sapFbPages[pageId] ? window._sapFbPages[pageId] : "";
                    $("input[name=\"sap_settings[facebook][page_id]\"]").val(pageId);
                    $("input[name=\"sap_settings[facebook][page_access_token]\"]").val(pageToken);
                    $("select[name=\"sap_settings[facebook][publish_type]\"]").val("page");
                    alert(i.selected + pageName + ". " + i.saveMsg);
                });
            })(jQuery);
            </script>';
        }

        return $html;
    }

    /**
     * Construir el mensaje para Facebook.
     *
     * @param array $post_data Datos del post.
     * @param bool  $include_url Si debe incluir la URL en el cuerpo.
     * @return string
     */
    private function build_message(array $post_data, bool $include_url = true): string {
        $message = $post_data['title'];

        if (!empty($post_data['excerpt'])) {
            $message .= "\n\n" . wp_trim_words($post_data['excerpt'], 40);
        }

        if ($include_url) {
            $message .= "\n\n" . $post_data['url'];
        }

        return $message;
    }

    /**
     * Obtener el ID de usuario de Facebook desde el token.
     */
    private function get_user_id(string $token): ?string {
        $response = wp_remote_get('https://graph.facebook.com/' . self::API_VERSION . '/me?fields=id&access_token=' . $token, [
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data['id'] ?? null;
    }

    /**
     * Obtener las páginas del usuario (método público para el handler AJAX).
     *
     * @param string $token Access Token de Facebook
     * @return array
     */
    public function get_user_pages(string $token): array {
        $response = wp_remote_get(
            'https://graph.facebook.com/' . self::API_VERSION . '/me/accounts?fields=id,name,access_token,picture&access_token=' . $token,
            ['timeout' => 15]
        );

        if (is_wp_error($response)) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data['data'] ?? [];
    }
}
