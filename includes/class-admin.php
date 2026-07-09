<?php
namespace SocialAutoPoster;

/**
 * Página de administración del plugin Social Auto Poster.
 * Proporciona interfaz con tabs para cada plataforma y configuración global.
 */
class Admin {

    /**
     * Referencia al plugin principal.
     *
     * @var Main
     */
    private $plugin;

    /**
     * Opción para almacenar ajustes generales.
     */
    const OPTION_KEY = 'sap_settings';

    /**
     * Opción para almacenar ajustes de categorías.
     */
    const CATEGORY_OPTION_KEY = 'sap_category_settings';

    /**
     * Constructor.
     *
     * @param Main $plugin
     */
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    /**
     * Inicializar hooks de administración.
     */
    public function init() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_notices', [$this, 'show_admin_notices']);

        // AJAX handlers para configuración.
        add_action('wp_ajax_sap_load_fb_pages', [$this, 'ajax_load_fb_pages']);
        add_action('wp_ajax_sap_test_connection', [$this, 'ajax_test_connection']);
    }

    /**
     * Agregar página al menú de administración.
     */
    public function add_admin_menu() {
        // Menú principal con icono propio.
        add_menu_page(
            __('Social Auto Poster', 'social-auto-poster'),
            __('Social Auto Poster', 'social-auto-poster'),
            'manage_options',
            'social-auto-poster',
            [$this, 'render_admin_page'],
            'dashicons-share',
            80
        );
    }

    /**
     * Cargar assets en la página de administración.
     *
     * @param string $hook
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'social-auto-poster') === false) {
            return;
        }

        wp_enqueue_style(
            'sap-admin',
            SAP_PLUGIN_URL . 'assets/admin.css',
            [],
            SAP_VERSION
        );

        wp_enqueue_script('jquery');
    }

    /**
     * Registrar ajustes.
     */
    public function register_settings() {
        register_setting('sap_settings_group', self::OPTION_KEY, [
            'sanitize_callback' => [$this, 'sanitize_settings'],
            'default'           => [],
        ]);

        register_setting('sap_settings_group', self::CATEGORY_OPTION_KEY, [
            'sanitize_callback' => [$this, 'sanitize_category_settings'],
            'default'           => ['allowed_categories' => []],
        ]);
    }

    /**
     * Sanitizar ajustes generales.
     *
     * @param array $input
     * @return array
     */
    public function sanitize_settings($input) {
        if (!is_array($input)) {
            return [];
        }

        $sanitized = [];
        $platforms = $this->plugin->get_platforms();

        // Sanitizar plataformas habilitadas.
        $sanitized['enabled_platforms'] = [];
        if (isset($input['enabled_platforms']) && is_array($input['enabled_platforms'])) {
            foreach ($input['enabled_platforms'] as $slug => $value) {
                $sanitized['enabled_platforms'][sanitize_key($slug)] = '1';
            }
        }

        // Sanitizar ajustes por plataforma.
        foreach ($platforms as $slug => $platform) {
            $fields = $platform->get_settings_fields();
            $sanitized[$slug] = [];

            if (isset($input[$slug]) && is_array($input[$slug])) {
                foreach ($fields as $field_id => $field) {
                    $value = $input[$slug][$field_id] ?? '';

                    switch ($field['type']) {
                        case 'password':
                            // Mantener el valor anterior si está vacío (no sobrescribir con vacío).
                            if (empty($value)) {
                                $old_settings = get_option(self::OPTION_KEY, []);
                                $old_value = $old_settings[$slug][$field_id] ?? '';
                                $sanitized[$slug][$field_id] = $old_value;
                            } else {
                                $sanitized[$slug][$field_id] = sanitize_text_field($value);
                            }
                            break;

                        case 'select':
                            $allowed_options = $field['options'] ?? [];
                            $sanitized[$slug][$field_id] = isset($allowed_options[$value])
                                ? sanitize_key($value)
                                : ($field['default'] ?? '');
                            break;

                        default:
                            $sanitized[$slug][$field_id] = sanitize_text_field($value);
                            break;
                    }
                }
            }
        }

        // Sanitizar ajustes de IA.
        $sanitized['ai'] = [];
        if (isset($input['ai']) && is_array($input['ai'])) {
            $ai = $input['ai'];

            $sanitized['ai']['provider'] = in_array($ai['provider'] ?? '', ['deepseek', 'openai'])
                ? sanitize_key($ai['provider'])
                : 'deepseek';

            // API key: mantener anterior si está vacío.
            if (empty($ai['api_key'])) {
                $old_settings = get_option(self::OPTION_KEY, []);
                $sanitized['ai']['api_key'] = $old_settings['ai']['api_key'] ?? '';
            } else {
                $sanitized['ai']['api_key'] = sanitize_text_field($ai['api_key']);
            }

            $sanitized['ai']['model'] = !empty($ai['model'])
                ? sanitize_text_field($ai['model'])
                : 'deepseek-v4-flash';

            $sanitized['ai']['instructions'] = !empty($ai['instructions'])
                ? sanitize_textarea_field($ai['instructions'])
                : '';

            // Plataformas habilitadas para IA.
            $sanitized['ai']['enabled_for'] = [];
            if (isset($ai['enabled_for']) && is_array($ai['enabled_for'])) {
                foreach ($ai['enabled_for'] as $slug => $value) {
                    $sanitized['ai']['enabled_for'][sanitize_key($slug)] = '1';
                }
            }
        }

        return $sanitized;
    }

    /**
     * Sanitizar ajustes de categorías.
     *
     * @param array $input
     * @return array
     */
    public function sanitize_category_settings($input) {
        $sanitized = ['allowed_categories' => []];

        if (isset($input['allowed_categories']) && is_array($input['allowed_categories'])) {
            foreach ($input['allowed_categories'] as $cat_id) {
                $cat_id = intval($cat_id);
                if ($cat_id > 0 && term_exists($cat_id, 'category')) {
                    $sanitized['allowed_categories'][] = $cat_id;
                }
            }
        }

        // Preservar el modo (include/exclude), solo permitir valores válidos.
        $mode = $input['mode'] ?? 'include';
        $sanitized['mode'] = in_array($mode, ['include', 'exclude'], true) ? $mode : 'include';

        return $sanitized;
    }

    /**
     * Mostrar notificaciones administrativas.
     */
    public function show_admin_notices() {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'social-auto-poster') === false) {
            return;
        }

        // Mostrar mensaje de republicación exitosa.
        if (isset($_GET['sap_republished'])) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Post republicado en redes sociales.', 'social-auto-poster')
                . '</p></div>';
        }

        // Mostrar mensaje de ajustes guardados.
        if (isset($_GET['settings-updated']) && $_GET['settings-updated']) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Ajustes guardados correctamente.', 'social-auto-poster')
                . '</p></div>';
        }
    }

    /**
     * Renderizar la página de administración.
     */
    public function render_admin_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
        ?>
        <div class="wrap sap-admin-wrap">
            <h1><?php echo esc_html__('Social Auto Poster', 'social-auto-poster'); ?></h1>
            <p><?php echo esc_html__('Configura la publicación automática en redes sociales cuando se publiquen posts.', 'social-auto-poster'); ?></p>

            <h2 class="nav-tab-wrapper sap-tabs">
                <a href="?page=social-auto-poster&tab=general"
                   class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('General', 'social-auto-poster'); ?>
                </a>
                <a href="?page=social-auto-poster&tab=categories"
                   class="nav-tab <?php echo $active_tab === 'categories' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Categorías', 'social-auto-poster'); ?>
                </a>

                <?php foreach ($this->plugin->get_platforms() as $slug => $platform) : ?>
                    <a href="?page=social-auto-poster&tab=<?php echo esc_attr($slug); ?>"
                       class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($platform->get_name()); ?>
                    </a>
                <?php endforeach; ?>

                <a href="?page=social-auto-poster&tab=logs"
                   class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Registro', 'social-auto-poster'); ?>
                </a>
                <a href="?page=social-auto-poster&tab=ai"
                   class="nav-tab <?php echo $active_tab === 'ai' ? 'nav-tab-active' : ''; ?>" style="color: #46b450;">
                    🤖 <?php esc_html_e('IA', 'social-auto-poster'); ?>
                </a>
            </h2>

            <div class="sap-tab-content">
                <?php if ($active_tab === 'logs') : ?>
                    <?php $this->render_logs_tab(); ?>
                <?php else : ?>
                    <form method="post" action="options.php">
                        <?php
                        switch ($active_tab) {
                            case 'general':
                                $this->render_general_tab();
                                break;
                            case 'categories':
                                $this->render_categories_tab();
                                break;
                            case 'ai':
                                $this->render_ai_tab();
                                break;
                            default:
                                $this->render_platform_tab($active_tab);
                                break;
                        }
                        ?>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Renderizar la pestaña general.
     */
    private function render_general_tab() {
        $settings = Main::get_options();
        $enabled_platforms = $settings['enabled_platforms'] ?? [];
        ?>
        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e('Redes sociales activas', 'social-auto-poster'); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text">
                                <span><?php esc_html_e('Redes sociales activas', 'social-auto-poster'); ?></span>
                            </legend>
                            <?php foreach ($this->plugin->get_platforms() as $slug => $platform) : ?>
                                <label for="sap_enabled_<?php echo esc_attr($slug); ?>" style="display: block; margin-bottom: 8px;">
                                    <input type="checkbox"
                                           id="sap_enabled_<?php echo esc_attr($slug); ?>"
                                           name="sap_settings[enabled_platforms][<?php echo esc_attr($slug); ?>]"
                                           value="1"
                                           <?php checked(!empty($enabled_platforms[$slug])); ?> />
                                    <strong><?php echo esc_html($platform->get_name()); ?></strong>
                                    <?php if ($platform->is_configured($settings[$slug] ?? [])) : ?>
                                        <span style="color: #46b450;">&#10003;</span>
                                    <?php else : ?>
                                        <span style="color: #dc3232;">&#10007;</span>
                                        <span class="description">
                                            <?php esc_html_e('No configurado', 'social-auto-poster'); ?>
                                        </span>
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                        <p class="description">
                            <?php esc_html_e('Selecciona las redes sociales donde deseas publicar automáticamente. Cada red requiere configuración adicional en su pestaña correspondiente.', 'social-auto-poster'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Publicar al programar', 'social-auto-poster'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="sap_settings[publish_scheduled]" value="1"
                                <?php checked(!empty($settings['publish_scheduled'])); ?> />
                            <?php esc_html_e('Publicar también cuando un post programado se publique automáticamente.', 'social-auto-poster'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Probar conexión', 'social-auto-poster'); ?></th>
                    <td>
                        <p class="description">
                            <?php esc_html_e('Selecciona una plataforma configurada y haz clic en "Probar conexión" para verificar que las credenciales funcionan. Se publicará un mensaje de prueba.', 'social-auto-poster'); ?>
                        </p>
                        <?php
                        $configured = [];
                        foreach ($this->plugin->get_platforms() as $slug => $platform) {
                            if ($platform->is_configured($settings[$slug] ?? [])) {
                                $configured[$slug] = $platform->get_name();
                            }
                        }
                        if (!empty($configured)) :
                        ?>
                        <p>
                            <select id="sap-test-platform">
                                <?php foreach ($configured as $slug => $name) : ?>
                                    <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="button" id="sap-test-connection">
                                <?php esc_html_e('Probar conexión', 'social-auto-poster'); ?>
                            </button>
                            <span id="sap-test-result" style="margin-left: 10px;"></span>
                        </p>
                        <script>
                        (function($) {
                            $('#sap-test-connection').on('click', function() {
                                var slug = $('#sap-test-platform').val();
                                var result = $('#sap-test-result');
                                result.html('<?php echo esc_js(__('Probando…', 'social-auto-poster')); ?>');

                                $.post(ajaxurl, {
                                    action: 'sap_test_connection',
                                    platform: slug,
                                    nonce: '<?php echo wp_create_nonce('sap_test_connection'); ?>'
                                }, function(resp) {
                                    if (resp.success) {
                                        result.html('<span style="color: #46b450;"><?php echo esc_js(__('✓ Conectado', 'social-auto-poster')); ?></span>');
                                    } else {
                                        result.html('<span style="color: #dc3232;"><?php echo esc_js(__('✗ Error:', 'social-auto-poster')); ?> ' + resp.data.message + '</span>');
                                    }
                                });
                            });
                        })(jQuery);
                        </script>
                        <?php else : ?>
                            <p class="description" style="color: #999;">
                                <?php esc_html_e('No hay plataformas configuradas. Configura al menos una en sus pestañas correspondientes.', 'social-auto-poster'); ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <?php settings_fields('sap_settings_group'); ?>
        <?php submit_button(); ?>
        <?php
    }

    /**
     * Renderizar la pestaña de categorías.
     */
    private function render_categories_tab() {
        $category_settings = Main::get_category_options();
        $allowed_categories = $category_settings['allowed_categories'] ?? [];
        $categories = get_categories(['hide_empty' => false]);
        ?>
        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e('Categorías permitidas', 'social-auto-poster'); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text">
                                <span><?php esc_html_e('Categorías permitidas', 'social-auto-poster'); ?></span>
                            </legend>
                            <p class="description">
                                <?php esc_html_e('Selecciona las categorías cuyos posts se publicarán automáticamente. Si no seleccionas ninguna, se publicarán TODOS los posts.', 'social-auto-poster'); ?>
                            </p>
                            <div style="margin-top: 15px; max-height: 400px; overflow-y: auto; padding: 10px; background: #fff; border: 1px solid #ccd0d4;">
                                <?php foreach ($categories as $category) : ?>
                                    <label style="display: block; margin-bottom: 6px; padding: 4px 0;">
                                        <input type="checkbox"
                                               name="sap_category_settings[allowed_categories][]"
                                               value="<?php echo esc_attr($category->term_id); ?>"
                                               <?php checked(in_array($category->term_id, $allowed_categories)); ?> />
                                        <strong><?php echo esc_html($category->name); ?></strong>
                                        <span class="description">
                                            (<?php echo sprintf(_n('%s post', '%s posts', $category->count, 'social-auto-poster'), $category->count); ?>)
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p style="margin-top: 10px;">
                                <button type="button" class="button" id="sap-select-all-cats">
                                    <?php esc_html_e('Seleccionar todas', 'social-auto-poster'); ?>
                                </button>
                                <button type="button" class="button" id="sap-deselect-all-cats">
                                    <?php esc_html_e('Deseleccionar todas', 'social-auto-poster'); ?>
                                </button>
                            </p>
                            <script>
                            (function($) {
                                $('#sap-select-all-cats').on('click', function() {
                                    $('input[name="sap_category_settings[allowed_categories][]"]').prop('checked', true);
                                });
                                $('#sap-deselect-all-cats').on('click', function() {
                                    $('input[name="sap_category_settings[allowed_categories][]"]').prop('checked', false);
                                });
                            })(jQuery);
                            </script>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Modo de categorías', 'social-auto-poster'); ?></th>
                    <td>
                        <label>
                            <select name="sap_category_settings[mode]">
                                <option value="include" <?php selected($category_settings['mode'] ?? 'include', 'include'); ?>>
                                    <?php esc_html_e('Incluir solo las seleccionadas', 'social-auto-poster'); ?>
                                </option>
                                <option value="exclude" <?php selected($category_settings['mode'] ?? '', 'exclude'); ?>>
                                    <?php esc_html_e('Excluir las seleccionadas (publicar todo excepto estas)', 'social-auto-poster'); ?>
                                </option>
                            </select>
                        </label>
                        <p class="description">
                            <?php esc_html_e('"Incluir" publica solo posts en las categorías marcadas. "Excluir" publica todos los posts EXCEPTO los de las categorías marcadas.', 'social-auto-poster'); ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

        <?php settings_fields('sap_settings_group'); ?>
        <?php submit_button(); ?>
        <?php
    }

    /**
     * Renderizar la pestaña de una plataforma específica.
     *
     * @param string $slug Slug de la plataforma.
     */
    private function render_platform_tab(string $slug) {
        $platform = $this->plugin->get_platform($slug);

        if (!$platform) {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('Plataforma no encontrada.', 'social-auto-poster')
                . '</p></div>';
            return;
        }

        $settings = Main::get_options();
        $platform_settings = $settings[$slug] ?? [];
        $fields = $platform->get_settings_fields();

        $is_configured = $platform->is_configured($platform_settings);
        ?>
        <div class="sap-platform-header">
            <h2><?php echo esc_html($platform->get_name()); ?></h2>
            <?php if ($is_configured) : ?>
                <span class="sap-status-badge sap-status-configured">
                    &#10003; <?php esc_html_e('Configurado', 'social-auto-poster'); ?>
                </span>
            <?php else : ?>
                <span class="sap-status-badge sap-status-not-configured">
                    &#10007; <?php esc_html_e('No configurado', 'social-auto-poster'); ?>
                </span>
            <?php endif; ?>
        </div>

        <p class="description">
            <?php
            printf(
                __('Configura las credenciales para %s. Las credenciales se almacenan en la base de datos de WordPress.', 'social-auto-poster'),
                $platform->get_name()
            );
            ?>
        </p>

        <table class="form-table">
            <tbody>
                <?php foreach ($fields as $field_id => $field) : ?>
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr("sap_{$slug}_{$field_id}"); ?>">
                                <?php echo esc_html($field['label']); ?>
                                <?php if (!empty($field['required'])) : ?>
                                    <span style="color: #dc3232;">*</span>
                                <?php endif; ?>
                            </label>
                        </th>
                        <td>
                            <?php
                            $value = $platform_settings[$field_id] ?? ($field['default'] ?? '');
                            $input_name = "sap_settings[{$slug}][{$field_id}]";
                            $input_id = "sap_{$slug}_{$field_id}";

                            switch ($field['type']) {
                                case 'password':
                                    ?>
                                    <input type="password"
                                           id="<?php echo esc_attr($input_id); ?>"
                                           name="<?php echo esc_attr($input_name); ?>"
                                           value="<?php echo esc_attr($value); ?>"
                                           class="regular-text"
                                           <?php echo !empty($field['required']) ? 'required' : ''; ?> />
                                    <?php if (!empty($value)) : ?>
                                        <span class="description">(<?php esc_attr_e('rellenado', 'social-auto-poster'); ?>)</span>
                                    <?php endif; ?>
                                    <?php
                                    break;

                                case 'select':
                                    ?>
                                    <select id="<?php echo esc_attr($input_id); ?>"
                                            name="<?php echo esc_attr($input_name); ?>">
                                        <?php foreach ($field['options'] as $opt_value => $opt_label) : ?>
                                            <option value="<?php echo esc_attr($opt_value); ?>" <?php selected($value, $opt_value); ?>>
                                                <?php echo esc_html($opt_label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php
                                    break;

                                default:
                                    ?>
                                    <input type="text"
                                           id="<?php echo esc_attr($input_id); ?>"
                                           name="<?php echo esc_attr($input_name); ?>"
                                           value="<?php echo esc_attr($value); ?>"
                                           class="regular-text"
                                           <?php echo !empty($field['required']) ? 'required' : ''; ?> />
                                    <?php
                                    break;
                            }
                            ?>

                            <?php if (!empty($field['description'])) : ?>
                                <p class="description"><?php echo esc_html($field['description']); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        // Renderizar HTML adicional (ej: selector de páginas de Facebook).
        echo $platform->get_extra_settings_html($platform_settings);
        ?>

        <?php settings_fields('sap_settings_group'); ?>
        <?php submit_button(__('Guardar configuración', 'social-auto-poster')); ?>
        <?php
    }

    /**
     * Renderizar la pestaña de registros (logs).
     */
    private function render_logs_tab() {
        // Consultar posts recientes que fueron auto-publicados.
        $args = [
            'post_type'      => 'post',
            'posts_per_page' => 20,
            'meta_query'     => [
                [
                    'key'   => '_sap_auto_posted',
                    'value' => '1',
                ],
            ],
            'orderby'        => 'meta_value',
            'meta_key'       => '_sap_publish_time',
            'meta_type'      => 'DATETIME',
            'order'          => 'DESC',
        ];

        // Si hay filtro de fecha (sobre _sap_publish_time, no post_date).
        $date_from = isset($_GET['log_from']) ? sanitize_text_field($_GET['log_from']) : date('Y-m-d', strtotime('-7 days'));
        $date_to   = isset($_GET['log_to']) ? sanitize_text_field($_GET['log_to']) : date('Y-m-d');

        $args['meta_query'][] = [
            'key'      => '_sap_publish_time',
            'value'    => [$date_from . ' 00:00:00', $date_to . ' 23:59:59'],
            'compare'  => 'BETWEEN',
            'type'     => 'DATETIME',
        ];

        $query = new \WP_Query($args);
        ?>
        <h2><?php esc_html_e('Últimas publicaciones automáticas', 'social-auto-poster'); ?></h2>

        <form method="get" action="" style="margin-bottom: 20px;">
            <input type="hidden" name="page" value="social-auto-poster" />
            <input type="hidden" name="tab" value="logs" />

            <label for="log_from"><?php esc_html_e('Desde:', 'social-auto-poster'); ?></label>
            <input type="date" id="log_from" name="log_from" value="<?php echo esc_attr($date_from); ?>" />

            <label for="log_to"><?php esc_html_e('Hasta:', 'social-auto-poster'); ?></label>
            <input type="date" id="log_to" name="log_to" value="<?php echo esc_attr($date_to); ?>" />

            <button type="submit" class="button"><?php esc_html_e('Filtrar', 'social-auto-poster'); ?></button>
        </form>

        <?php if ($query->have_posts()) : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Post', 'social-auto-poster'); ?></th>
                        <th><?php esc_html_e('Fecha', 'social-auto-poster'); ?></th>
                        <th><?php esc_html_e('Redes', 'social-auto-poster'); ?></th>
                        <th><?php esc_html_e('Resultado', 'social-auto-poster'); ?></th>
                        <th><?php esc_html_e('Acción', 'social-auto-poster'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($query->have_posts()) : $query->the_post(); ?>
                        <?php
                        $post_id = get_the_ID();
                        $log = Post_Handler::get_publish_log($post_id);
                        $publish_time = get_post_meta($post_id, '_sap_publish_time', true);
                        ?>
                        <tr>
                            <td>
                                <a href="<?php echo get_edit_post_link($post_id); ?>">
                                    <?php echo esc_html(get_the_title()); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html($publish_time ? $publish_time : get_the_date()); ?></td>
                            <td>
                                <?php foreach ($log as $entry) : ?>
                                    <span style="display: inline-block; margin: 2px; padding: 2px 6px; border-radius: 3px; font-size: 11px; <?php echo $entry['success'] ? 'background: #ecf7ed; color: #389e0d;' : 'background: #fbe9e9; color: #cf1322;'; ?>">
                                        <?php echo esc_html($entry['platform']); ?>
                                    </span>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <details>
                                    <summary><?php echo sprintf(__('%d resultados', 'social-auto-poster'), count($log)); ?></summary>
                                    <ul style="margin: 5px 0; font-size: 12px;">
                                        <?php foreach ($log as $entry) : ?>
                                            <li>
                                                <strong><?php echo esc_html($entry['platform']); ?>:</strong>
                                                <?php echo esc_html($entry['message']); ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            </td>
                            <td>
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?action=sap_republish&post=' . $post_id), 'sap_republish_' . $post_id); ?>"
                                   class="button button-small"
                                   onclick="return confirm('<?php esc_attr_e('¿Republicar?', 'social-auto-poster'); ?>')">
                                    <?php esc_html_e('Republicar', 'social-auto-poster'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>

            <p class="description">
                <?php printf(__('Mostrando los últimos %d posts con publicación automática.', 'social-auto-poster'), $query->post_count); ?>
            </p>
        <?php else : ?>
            <div class="notice notice-info">
                <p><?php esc_html_e('No se encontraron posts con publicación automática en el rango seleccionado.', 'social-auto-poster'); ?></p>
            </div>
        <?php endif; ?>

        <?php
        wp_reset_postdata();
    }

    /**
     * Renderizar la pestaña de configuración de IA.
     */
    private function render_ai_tab() {
        $ai_settings = AI::get_ai_settings();
        $provider = $ai_settings['provider'] ?? 'deepseek';
        $model    = $ai_settings['model'] ?? 'deepseek-v4-flash';
        $api_key  = $ai_settings['api_key'] ?? '';
        $instructions = $ai_settings['instructions'] ?? '';
        $enabled_for  = $ai_settings['enabled_for'] ?? [];
        ?>
        <div class="sap-platform-header">
            <h2><?php esc_html_e('🤖 Generación con Inteligencia Artificial', 'social-auto-poster'); ?></h2>
            <?php if (AI::is_configured()) : ?>
                <span class="sap-status-badge sap-status-configured">
                    &#10003; <?php esc_html_e('Configurado', 'social-auto-poster'); ?>
                </span>
            <?php else : ?>
                <span class="sap-status-badge sap-status-not-configured">
                    &#10007; <?php esc_html_e('No configurado', 'social-auto-poster'); ?>
                </span>
            <?php endif; ?>
        </div>

        <p class="description">
            <?php esc_html_e('Activa la generación por IA para que un modelo de lenguaje escriba automáticamente el texto de cada publicación, adaptado al tono y estilo de cada red social. Si la IA falla, se usará el título + excerpt normal como fallback.', 'social-auto-poster'); ?>
        </p>

        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="sap_ai_provider"><?php esc_html_e('Proveedor', 'social-auto-poster'); ?> <span style="color: #dc3232;">*</span></label>
                    </th>
                    <td>
                        <select id="sap_ai_provider" name="sap_settings[ai][provider]" class="sap-ai-provider-select">
                            <option value="deepseek" <?php selected($provider, 'deepseek'); ?>><?php esc_html_e('DeepSeek', 'social-auto-poster'); ?></option>
                            <option value="openai" <?php selected($provider, 'openai'); ?>><?php esc_html_e('OpenAI', 'social-auto-poster'); ?></option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('DeepSeek es la opción recomendada. Si eliges OpenAI necesitas una API key de OpenAI.', 'social-auto-poster'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="sap_ai_api_key"><?php esc_html_e('API Key', 'social-auto-poster'); ?> <span style="color: #dc3232;">*</span></label>
                    </th>
                    <td>
                        <input type="password"
                               id="sap_ai_api_key"
                               name="sap_settings[ai][api_key]"
                               value="<?php echo esc_attr($api_key); ?>"
                               class="regular-text"
                               style="width: 400px;"
                               placeholder="sk-..." />
                        <?php if (!empty($api_key)) : ?>
                            <span class="description">(<?php esc_attr_e('rellenada', 'social-auto-poster'); ?>)</span>
                        <?php endif; ?>
                        <p class="description">
                            <?php esc_html_e('Tu API Key de DeepSeek (https://platform.deepseek.com) u OpenAI (https://platform.openai.com).', 'social-auto-poster'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="sap_ai_model"><?php esc_html_e('Modelo', 'social-auto-poster'); ?></label>
                    </th>
                    <td>
                        <select id="sap_ai_model" name="sap_settings[ai][model]">
                            <optgroup label="DeepSeek" class="sap-model-group" data-provider="deepseek">
                                <option value="deepseek-v4-flash" <?php selected($model, 'deepseek-v4-flash'); ?>>DeepSeek V4 Flash</option>
                            </optgroup>
                            <optgroup label="OpenAI" class="sap-model-group" data-provider="openai">
                                <option value="gpt-5.5-nano" <?php selected($model, 'gpt-5.5-nano'); ?>>GPT-5.5 Nano</option>
                                <option value="gpt-5.5-mini" <?php selected($model, 'gpt-5.5-mini'); ?>>GPT-5.5 Mini</option>
                            </optgroup>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Según el proveedor elegido arriba, se mostrarán los modelos disponibles.', 'social-auto-poster'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Habilitar IA para', 'social-auto-poster'); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text">
                                <span><?php esc_html_e('Habilitar IA para', 'social-auto-poster'); ?></span>
                            </legend>
                            <?php foreach ($this->plugin->get_platforms() as $slug => $platform) : ?>
                                <label style="display: block; margin-bottom: 8px;">
                                    <input type="checkbox"
                                           name="sap_settings[ai][enabled_for][<?php echo esc_attr($slug); ?>]"
                                           value="1"
                                           <?php checked(!empty($enabled_for[$slug])); ?> />
                                    <strong><?php echo esc_html($platform->get_name()); ?></strong>
                                    <span class="description">
                                        <?php printf(__('(máx. %d caracteres)', 'social-auto-poster'), $this->get_ai_max_chars($slug)); ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                        <p class="description">
                            <?php esc_html_e('La IA generará un texto distinto para cada red, adaptado a su formato y límite de caracteres. Si la generación falla, se usará el texto normal (título + excerpt + URL).', 'social-auto-poster'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="sap_ai_instructions"><?php esc_html_e('Instrucciones adicionales', 'social-auto-poster'); ?></label>
                    </th>
                    <td>
                        <textarea id="sap_ai_instructions"
                                  name="sap_settings[ai][instructions]"
                                  rows="5"
                                  style="width: 100%; max-width: 600px;"
                                  placeholder="<?php esc_attr_e('Ej: Usa un tono divertido y emojis. Menciona siempre el autor al final.', 'social-auto-poster'); ?>"><?php echo esc_textarea($instructions); ?></textarea>
                        <p class="description">
                            <?php esc_html_e('Instrucciones opcionales que la IA seguirá al generar los textos. Se aplican a todas las redes.', 'social-auto-poster'); ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Script para mostrar/ocultar modelos según proveedor -->
        <script>
        (function($) {
            function updateModels() {
                var provider = $('#sap_ai_provider').val();
                $('#sap_ai_model optgroup').hide();
                $('#sap_ai_model optgroup[data-provider="' + provider + '"]').show();
                var firstVisible = $('#sap_ai_model optgroup[data-provider="' + provider + '"] option:first');
                if (firstVisible.length && !$('#sap_ai_model').val()) {
                    firstVisible.prop('selected', true);
                }
            }
            $('#sap_ai_provider').on('change', updateModels);
            updateModels();
        })(jQuery);
        </script>

        <?php settings_fields('sap_settings_group'); ?>
        <?php submit_button(__('Guardar configuración de IA', 'social-auto-poster')); ?>
        <?php
    }

    /**
     * Obtener el máximo de caracteres para la IA según plataforma.
     */
    private function get_ai_max_chars(string $slug): int {
        return AI::get_max_chars_for_platform($slug);
    }

    /**
     * Manejar AJAX para cargar páginas de Facebook.
     */
    public function ajax_load_fb_pages() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'sap_fb_pages')) {
            wp_send_json_error(['message' => __('Nonce inválido.', 'social-auto-poster')]);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permiso denegado.', 'social-auto-poster')]);
        }

        $settings = Main::get_options();
        $fb_settings = $settings['facebook'] ?? [];

        if (empty($fb_settings['access_token'])) {
            wp_send_json_error(['message' => __('Primero guarda el Access Token de Facebook.', 'social-auto-poster')]);
        }

        $fb_platform = $this->plugin->get_platform('facebook');

        if (!$fb_platform || !method_exists($fb_platform, 'get_user_pages')) {
            wp_send_json_error(['message' => __('Plataforma Facebook no disponible.', 'social-auto-poster')]);
        }

        $pages = $fb_platform->get_user_pages($fb_settings['access_token']);

        if (empty($pages)) {
            wp_send_json_success([
                'pages' => [],
                'message' => __('No se encontraron páginas o el token no tiene permisos pages_show_list.', 'social-auto-poster'),
            ]);
        }

        wp_send_json_success(['pages' => $pages]);
    }

    /**
     * Manejar AJAX para probar conexión con una plataforma.
     */
    public function ajax_test_connection() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'sap_test_connection')) {
            wp_send_json_error(['message' => __('Nonce inválido.', 'social-auto-poster')]);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permiso denegado.', 'social-auto-poster')]);
        }

        $platform_slug = isset($_POST['platform']) ? sanitize_key(wp_unslash($_POST['platform'])) : '';

        $settings = Main::get_options();
        $platform_settings = $settings[$platform_slug] ?? [];

        $publisher = new Publisher($this->plugin);
        $result = $publisher->test_connection($platform_slug, $platform_settings);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
}
