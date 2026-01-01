<?php
if (!defined('ABSPATH')) exit;

class GMCO_Database {
    
    public static function create_tables() {
        global $wpdb;
        
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}gmco_optimizations (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            product_id bigint(20) NOT NULL,
            original_title text,
            optimized_title text,
            original_description longtext,
            optimized_description longtext,
            status varchar(20) DEFAULT 'pending',
            error_message text,
            optimization_date datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY status (status)
        ) $charset;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public static function add_optimization_record($data) {
        global $wpdb;
        
        $wpdb->insert(
            $wpdb->prefix . 'gmco_optimizations',
            $data,
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        return $wpdb->insert_id;
    }
    
    public static function get_recent_optimizations($limit = 10) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}gmco_optimizations 
             ORDER BY optimization_date DESC 
             LIMIT %d",
            $limit
        ));
    }
    
    public static function get_stats() {
        global $wpdb;
        
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as success,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as errors
            FROM {$wpdb->prefix}gmco_optimizations
        ", ARRAY_A);
        
        return $stats ?: array('total' => 0, 'success' => 0, 'errors' => 0);
    }
}
