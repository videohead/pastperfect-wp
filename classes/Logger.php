<?php

namespace PastPerfect\Archive;

class Sync_Logger
{
    const LOG_FOLDER = 'ppwp-sync-logs';

    public static function write($level, $message, array $context = array())
    {
        $upload_dir = wp_upload_dir();
        $base_dir = trailingslashit($upload_dir['basedir']) . self::LOG_FOLDER;

        if (! file_exists($base_dir)) {
            wp_mkdir_p($base_dir);
        }

        $log_file = trailingslashit($base_dir) . 'sync-' . gmdate('Y-m-d') . '.log';
        $entry = array(
            'timestamp' => gmdate('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents($log_file, wp_json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
    }

    public static function get_recent_logs($limit = 10)
    {
        $upload_dir = wp_upload_dir();
        $base_dir = trailingslashit($upload_dir['basedir']) . self::LOG_FOLDER;

        if (! is_dir($base_dir)) {
            return array();
        }

        $files = glob($base_dir . '/sync-*.log');

        if (! is_array($files)) {
            return array();
        }

        rsort($files);
        $files = array_slice($files, 0, max(1, absint($limit)));

        $download_base = trailingslashit($upload_dir['baseurl']) . self::LOG_FOLDER;

        return array_map(
            static function ($file_path) use ($download_base) {
                return array(
                    'name' => basename($file_path),
                    'path' => $file_path,
                    'url' => trailingslashit($download_base) . basename($file_path),
                );
            },
            $files
        );
    }
}
