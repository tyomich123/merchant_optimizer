<?php
if (!defined('ABSPATH')) exit;

class GMCO_Logger {
    
    private static function get_log_file() {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/gmco-logs';
        
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        
        return $log_dir . '/gmco-' . date('Y-m-d') . '.log';
    }
    
    public static function log($message, $level = 'info') {
        $log_file = self::get_log_file();
        $timestamp = current_time('mysql');
        $level_upper = strtoupper($level);
        
        $log_entry = sprintf("[%s] [%s] %s\n", $timestamp, $level_upper, $message);
        
        error_log($log_entry, 3, $log_file);
    }

    /**
     * Лог з захистом від спаму (одноразово за певний час)
     */
    public static function log_once($key, $message, $level = 'info', $ttl = 300) {
        $transient_key = 'gmco_log_once_' . sanitize_key($key);
        if (get_transient($transient_key)) {
            return;
        }

        set_transient($transient_key, 1, $ttl);
        self::log($message, $level);
    }
    
    public static function get_logs($date = null) {
        if (!$date) {
            $date = date('Y-m-d');
        }
        
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/gmco-logs/gmco-' . $date . '.log';
        
        if (!file_exists($log_file)) {
            return '';
        }
        
        return file_get_contents($log_file);
    }
    
    public static function get_available_dates() {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/gmco-logs';
        
        if (!is_dir($log_dir)) {
            return array();
        }
        
        $files = glob($log_dir . '/gmco-*.log');
        $dates = array();
        
        foreach ($files as $file) {
            if (preg_match('/gmco-(\d{4}-\d{2}-\d{2})\.log$/', basename($file), $matches)) {
                $dates[] = $matches[1];
            }
        }
        
        rsort($dates);
        return $dates;
    }
}