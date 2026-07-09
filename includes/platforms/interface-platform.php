<?php
namespace SocialAutoPoster\Platforms;

/**
 * Interfaz que todas las plataformas de redes sociales deben implementar.
 */
interface PlatformInterface {

    /**
     * Obtener el nombre único de la plataforma (slug).
     *
     * @return string Ej: 'x', 'facebook', 'linkedin'
     */
    public function get_slug(): string;

    /**
     * Obtener el nombre mostrable de la plataforma.
     *
     * @return string Ej: 'X (Twitter)', 'Facebook'
     */
    public function get_name(): string;

    /**
     * Obtener la URL del ícono/logo de la plataforma (opcional).
     *
     * @return string
     */
    public function get_icon_url(): string;

    /**
     * Publicar un post en la plataforma.
     *
     * @param array $post_data Datos del post preparados: 'title', 'content', 'excerpt', 'url', 'featured_image'
     * @param array $settings  Ajustes guardados de la plataforma (tokens, preferencias)
     * @return array Resultado con 'success' (bool) y 'message' (string)
     */
    public function publish(array $post_data, array $settings): array;

    /**
     * Validar que la configuración de la plataforma es suficiente para publicar.
     *
     * @param array $settings Ajustes guardados
     * @return bool
     */
    public function is_configured(array $settings): bool;

    /**
     * Obtener los campos del formulario de configuración para esta plataforma.
     *
     * @return array Array de campos con 'id', 'label', 'type', 'description', 'required'
     */
    public function get_settings_fields(): array;

    /**
     * Obtener opciones adicionales para mostrar en la página de ajustes (ej: selector de páginas de Facebook).
     *
     * @param array $settings Ajustes guardados
     * @return string HTML adicional para mostrar en los ajustes
     */
    public function get_extra_settings_html(array $settings): string;
}
