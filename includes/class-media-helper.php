<?php
namespace SocialAutoPoster;

/**
 * Helper para descarga y manipulación de medios.
 */
class Media_Helper {

    /**
     * Descargar una imagen desde una URL y devolver sus datos y tipo MIME.
     *
     * @param string $image_url URL de la imagen.
     * @return array{data: string, mime_type: string}|null Array con 'data' y 'mime_type', o null si falla.
     */
    public static function download_image(string $image_url): ?array {
        $temp_file = download_url($image_url);
        if (is_wp_error($temp_file)) {
            return null;
        }

        // Limitar tamaño máximo a 10 MB para evitar agotar memoria.
        $file_size = @filesize($temp_file);
        if ($file_size === false || $file_size > 10 * MB_IN_BYTES) {
            @unlink($temp_file);
            return null;
        }

        $file_data = file_get_contents($temp_file);
        $mime_type = wp_get_image_mime($temp_file);

        @unlink($temp_file);

        if (empty($file_data) || empty($mime_type)) {
            return null;
        }

        return [
            'data'      => $file_data,
            'mime_type' => $mime_type,
        ];
    }
}
