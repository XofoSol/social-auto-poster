<?php
namespace SocialAutoPoster;

/**
 * Servicio de IA para generación de textos para redes sociales.
 * Soporta DeepSeek (primario) y OpenAI (alternativo).
 */
class AI {

    /**
     * Proveedores disponibles.
     */
    const PROVIDERS = [
        'deepseek' => [
            'name'       => 'DeepSeek',
            'api_url'    => 'https://api.deepseek.com/v1/chat/completions',
            'models'     => ['deepseek-v4-flash'],
            'default_model' => 'deepseek-v4-flash',
        ],
        'openai' => [
            'name'       => 'OpenAI',
            'api_url'    => 'https://api.openai.com/v1/chat/completions',
            'models'     => ['gpt-5.5-nano', 'gpt-5.5-mini'],
            'default_model' => 'gpt-5.5-mini',
        ],
    ];

    /**
     * Obtener la configuración de IA guardada.
     *
     * @return array
     */
    public static function get_ai_settings(): array {
        $settings = Main::get_options();
        return $settings['ai'] ?? [];
    }

    /**
     * Verificar si la IA está configurada.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        $ai_settings = self::get_ai_settings();
        return !empty($ai_settings['api_key']) && !empty($ai_settings['provider']);
    }

    /**
     * Verificar si la IA está habilitada para una plataforma específica.
     *
     * @param string $platform_slug
     * @return bool
     */
    public static function is_enabled_for(string $platform_slug): bool {
        $ai_settings = self::get_ai_settings();
        $enabled_for = $ai_settings['enabled_for'] ?? [];
        return !empty($enabled_for[$platform_slug]) && $enabled_for[$platform_slug] === '1';
    }

    /**
     * Generar texto para una red social específica usando IA.
     *
     * @param array  $post_data      Datos del post (title, excerpt, categories, tags, url)
     * @param string $platform_slug  Slug de la plataforma destino
     * @param string $platform_name  Nombre mostrable de la plataforma
     * @return string Texto generado, o cadena vacía si falla
     */
    public function generate(array $post_data, string $platform_slug, string $platform_name): string {
        $ai_settings = self::get_ai_settings();

        if (empty($ai_settings['api_key']) || empty($ai_settings['provider'])) {
            return '';
        }

        $provider = $ai_settings['provider'];
        $model    = $ai_settings['model'] ?? self::PROVIDERS[$provider]['default_model'];
        $api_key  = $ai_settings['api_key'];
        $api_url  = self::PROVIDERS[$provider]['api_url'];
        $prompt   = $this->build_prompt($post_data, $platform_slug, $platform_name, $ai_settings);

        $max_tokens = $this->get_max_tokens_for_platform($platform_slug);

        $body = [
            'model'       => $model,
            'messages'    => [
                [
                    'role'    => 'system',
                    'content' => 'Eres un copywriter experto en redes sociales. Generas contenido atractivo y optimizado para cada plataforma. Respondes SOLO con el texto de la publicación, sin explicaciones, sin comillas, sin formato adicional.',
                ],
                [
                    'role'    => 'user',
                    'content' => $prompt,
                ],
            ],
            'max_tokens'  => $max_tokens,
            'temperature' => 0.8,
        ];

        $response = wp_remote_post($api_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => json_encode($body),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return '';
        }

        $result = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($result['choices'][0]['message']['content'])) {
            $text = trim($result['choices'][0]['message']['content']);

            // Verificar si el modelo se detuvo por longitud máxima (finish_reason = 'length').
            $finish_reason = $result['choices'][0]['finish_reason'] ?? '';
            if ($finish_reason === 'length') {
                // El texto puede estar truncado; registrar para diagnóstico.
                error_log(
                    sprintf(
                        '[Social Auto Poster] AI response truncated for %s (%s): finish_reason=length, received %d chars',
                        $platform_slug,
                        $platform_name,
                        mb_strlen($text)
                    )
                );
            }

            // Limitar al máximo de caracteres de la plataforma.
            return $this->truncate_for_platform($text, $platform_slug);
        }

        return '';
    }

    /**
     * Construir el prompt para la IA según la plataforma.
     */
    private function build_prompt(array $post_data, string $platform_slug, string $platform_name, array $ai_settings): string {
        $title      = $post_data['title'] ?? '';
        $excerpt    = $post_data['excerpt'] ?? '';
        $url        = $post_data['url'] ?? '';
        $categories = !empty($post_data['categories']) ? implode(', ', $post_data['categories']) : '';
        $tags       = !empty($post_data['tags']) ? implode(', ', $post_data['tags']) : '';

        // Instrucciones personalizadas del usuario.
        $custom_instructions = $ai_settings['instructions'] ?? '';

        // Características de la plataforma.
        $platform_guidelines = $this->get_platform_guidelines($platform_slug);

        // Reglas de longitud.
        $max_chars = self::get_max_chars_for_platform($platform_slug);

        $prompt = <<<PROMPT
Genera un texto atractivo para publicar en {$platform_name} ({$platform_slug}).

DATOS DEL ARTÍCULO:
- Título: {$title}
- Extracto: {$excerpt}
- URL: {$url}
- Categorías: {$categories}
- Etiquetas: {$tags}

REGLAS PARA {$platform_name}:
{$platform_guidelines}

RESTRICCIONES:
- Máximo {$max_chars} caracteres.
- Incluye la URL al final.
- NO uses comillas dobles al inicio o final.
- NO incluyas explicaciones, solo el texto de la publicación.
PROMPT;

        if (!empty($custom_instructions)) {
            $prompt .= "\n\nINSTRUCCIONES ADICIONALES DEL USUARIO:\n{$custom_instructions}";
        }

        return $prompt;
    }

    /**
     * Obtener directrices de tono y estilo para cada plataforma.
     */
    private function get_platform_guidelines(string $slug): string {
        $guidelines = [
            'x' => implode("\n", [
                '- Tono directo y conversacional.',
                '- Máximo 280 caracteres (incluyendo URL).',
                '- idealmente un gancho + URL.',
                '- Puedes usar hashtags relevantes (máximo 2).',
            ]),
            'threads' => implode("\n", [
                '- Tono cercano, auténtico y conversacional.',
                '- Máximo 500 caracteres.',
                '- Ideal para compartir ideas o reflexiones breves.',
                '- Puedes usar hashtags (máximo 3).',
            ]),
            'instagram' => implode("\n", [
                '- Tono visual e inspirador.',
                '- Máximo 2200 caracteres.',
                '- Incluye 3-5 hashtags relevantes al final.',
                '- El texto debe complementar la imagen del post.',
            ]),
            'facebook' => implode("\n", [
                '- Tono cercano y atractivo.',
                '- Máximo 2000 caracteres.',
                '- Puedes incluir una pregunta para fomentar engagement.',
                '- El tono puede ser más informal y extenso que en otras redes.',
            ]),
            'linkedin' => implode("\n", [
                '- Tono profesional pero accesible.',
                '- Máximo 3000 caracteres.',
                '- Enfocado en valor, aprendizaje o industria.',
                '- Evita hashtags excesivos (máximo 3 relevantes).',
                '- Puedes incluir un llamado a la acción profesional.',
            ]),
        ];

        return $guidelines[$slug] ?? '- Genera un texto atractivo para redes sociales.';
    }

    /**
     * Límites de caracteres por plataforma (fuente única de verdad).
     *
     * @return array<string, int>
     */
    public static function get_char_limits(): array {
        return [
            'x'         => 280,
            'threads'   => 500,
            'instagram' => 2200,
            'facebook'  => 2000,
            'linkedin'  => 3000,
        ];
    }

    /**
     * Obtener el máximo de caracteres permitidos por plataforma.
     *
     * @param string $slug
     * @return int
     */
    public static function get_max_chars_for_platform(string $slug): int {
        $limits = self::get_char_limits();
        return $limits[$slug] ?? 1000;
    }

    /**
     * Obtener max_tokens para la API según plataforma.
     */
    private function get_max_tokens_for_platform(string $slug): int {
        $limits = [
            'x'         => 150,
            'threads'   => 200,
            'instagram' => 500,
            'facebook'  => 500,
            'linkedin'  => 600,
        ];
        return $limits[$slug] ?? 300;
    }

    /**
     * Truncar el texto generado al máximo de la plataforma.
     */
    private function truncate_for_platform(string $text, string $slug): string {
        $max = self::get_max_chars_for_platform($slug);
        if (mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max - 1) . '…';
        }
        return $text;
    }
}
