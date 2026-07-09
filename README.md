# Social Auto Poster

**Plugin de WordPress para publicación automática en redes sociales.**

## Descripción

Social Auto Poster publica automáticamente tus posts de WordPress en las redes sociales más populares cuando son publicados. Permite seleccionar qué categorías de posts se publican automáticamente y cuáles no.

## Redes Sociales Soportadas

| Plataforma | API | Características |
|-----------|-----|-----------------|
| **X (Twitter)** | API v2 + OAuth 1.0a | Texto, imagen destacada |
| **Threads** | Threads Graph API v1.0 | Texto, imagen |
| **Instagram** | Instagram Graph API v19.0 | Imagen con caption (requiere imagen destacada) |
| **Facebook** | Facebook Graph API v19.0 | Perfil personal o **Página de Facebook** |
| **LinkedIn** | LinkedIn API v2 (UGC Posts) | Texto, imagen (Persona/Organización) |

## Características

- ✅ Publicación automática al publicar un post
- ✅ Selección por categorías (incluir o excluir)
- ✅ Soporte para Facebook Pages (selector visual de páginas)
- ✅ Meta box en el editor de posts para deshabilitar por post individual
- ✅ Vista de estado en el listado de posts
- ✅ Botón de republicación manual
- ✅ Prueba de conexión desde el panel de ajustes
- ✅ Generación de texto por IA (DeepSeek u OpenAI)
- ✅ Registro de actividad (logs) filtrable por fecha
- ✅ Soporte multilingüe (i18n listo)
- ✅ Diseño extensible (interfaz PlatformInterface)

## Requisitos

- WordPress 5.8+
- PHP 7.4+
- Una cuenta de desarrollador en cada red social que quieras usar

## Instalación

1. Descarga el plugin y súbelo a `/wp-content/plugins/social-auto-poster/`
2. Activa el plugin desde el menú "Plugins" de WordPress
3. Ve a **Social Auto Poster** en el menú de administración
4. Configura cada red social con tus credenciales
5. Activa las redes que deseas usar en la pestaña "General"

## Configuración por Red Social

### X (Twitter)
1. Ve a [Twitter Developer Portal](https://developer.twitter.com/) y crea un proyecto
2. Genera API Key, API Secret, Access Token y Access Token Secret
3. Introduce las credenciales en la pestaña "X (Twitter)"

### Facebook
1. Ve a [Meta Developer](https://developers.facebook.com/) y crea una app
2. Configura el producto "Facebook Login" y "Pages API"
3. Genera un Access Token con permisos: `pages_manage_posts`, `pages_read_engagement`, `pages_show_list`
4. Introduce las credenciales en la pestaña "Facebook"
5. Usa el botón "Cargar mis páginas" para seleccionar una página

### Instagram
1. Necesitas una cuenta de Instagram Business o Creator conectada a una página de Facebook
2. Configura una app en Meta Developer con el producto "Instagram Graph API"
3. Obtén el Instagram Business User ID y un Access Token con permisos `instagram_content_publish`

### Threads
1. Configura una app en Meta Developer con el producto "Threads API"
2. Obtén el Threads User ID y Access Token

### LinkedIn
1. Ve a [LinkedIn Developer](https://developer.linkedin.com/) y crea una app
2. Solicita los permisos: `w_member_social`, `openid`, `profile`, `email`
3. Genera un Access Token y obtén tu Person URN (formato: `urn:li:person:xxx`)

## Generación por Inteligencia Artificial

El plugin puede generar automáticamente el texto de cada publicación usando IA, adaptado al tono y límites de cada red social.

### Proveedores Soportados

| Proveedor | Modelo por defecto | Costo |
|-----------|-------------------|-------|
| **DeepSeek** | `deepseek-v4-flash` | Bajo (más barato que OpenAI) |
| **OpenAI** | `gpt-5.5-mini` | Moderado |

### Configuración

1. Ve a la pestaña **IA** en el panel de Social Auto Poster
2. Selecciona el proveedor (DeepSeek recomendado)
3. Ingresa tu API Key
4. Marca las plataformas donde quieres que se use la IA
5. Opcional: añade instrucciones personalizadas (ej: "Usa un tono divertido con emojis")

### Comportamiento

- La IA genera un texto distinto para cada red social, respetando su límite de caracteres
- Si la generación por IA falla (API caída, límite excedido), se usa el texto normal del post como fallback
- Se puede habilitar/deshabilitar por post individual desde el meta box en el editor
- El modelo se puede cambiar según el proveedor seleccionado

## Desarrollo

### Extensibilidad

Puedes agregar nuevas plataformas implementando la interfaz `SocialAutoPoster\Platforms\PlatformInterface`:

```php
class MiRed implements \SocialAutoPoster\Platforms\PlatformInterface {
    // Implementar métodos requeridos
}

add_action('sap_register_platforms', function($plugin) {
    $plugin->register_platform(new MiRed());
});
```

### Hooks Disponibles

```php
// Antes de publicar en todas las plataformas
do_action('sap_before_publish_all', $post_id);

// Después de publicar en una plataforma individual
do_action('sap_after_platform_publish', $post_id, $slug, $result);

// Después de publicar en todas las plataformas
do_action('sap_after_publish_all', $post_id, $log_result);

// Registrar plataformas adicionales
do_action('sap_register_platforms', $plugin_instance);
```

## Changelog

### 1.0.0
- Versión inicial
- Soporte para X, Threads, Instagram, Facebook y LinkedIn
- Selección por categorías (incluir/excluir)
- Facebook Pages support
- Generación de texto por IA (DeepSeek / OpenAI)
- Meta box en editor de posts
- Logs de actividad
- Prueba de conexión

## Licencia

GPL v2 o posterior.
