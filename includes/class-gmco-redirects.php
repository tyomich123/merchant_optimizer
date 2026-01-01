<?php
/**
 * Клас для обробки 301 редіректів старих URL товарів
 * 
 * @package GMCO
 * @since 2.6.0
 */

if (!defined('ABSPATH')) exit;

class GMCO_Redirects {
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Отримати instance
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Конструктор
     */
    private function __construct() {
        // Перехоплюємо 404 помилки
        add_action('template_redirect', array($this, 'handle_old_product_urls'), 1);
    }
    
    /**
     * Обробка старих URL товарів
     */
    public function handle_old_product_urls() {
        // Перевіряємо чи це 404
        if (!is_404()) {
            return;
        }
        
        // Отримуємо поточний запитуваний URL
        $requested_url = $_SERVER['REQUEST_URI'];
        
        // Парсимо URL для отримання slug
        $path_parts = explode('/', trim($requested_url, '/'));
        
        // Знаходимо slug (останній сегмент URL)
        $old_slug = end($path_parts);
        
        // Очищаємо від GET параметрів
        $old_slug = strtok($old_slug, '?');
        
        if (empty($old_slug)) {
            return;
        }
        
        GMCO_Logger::log("🔍 404 перехоплено, шукаємо редірект для: {$old_slug}");
        
        // Шукаємо товар з таким оригінальним slug
        $product_id = $this->find_product_by_old_slug($old_slug);
        
        if ($product_id) {
            // Знайшли товар - робимо редірект
            $product = wc_get_product($product_id);
            
            if ($product) {
                $new_url = get_permalink($product_id);
                
                GMCO_Logger::log("✅ Знайдено товар #{$product_id}, редірект: {$old_slug} → {$new_url}");
                
                // Оновлюємо статистику
                $this->update_redirect_stats($old_slug);
                
                // 301 Permanent Redirect
                wp_redirect($new_url, 301);
                exit;
            }
        }
        
        // Також перевіряємо базу редіректів (для гнучкості)
        $redirect_url = $this->get_redirect_from_database($old_slug);
        
        if ($redirect_url) {
            GMCO_Logger::log("✅ Знайдено редірект в БД: {$old_slug} → {$redirect_url}");
            
            // Оновлюємо статистику
            $this->update_redirect_stats($old_slug);
            
            wp_redirect($redirect_url, 301);
            exit;
        }
        
        // Редірект не знайдено, WordPress покаже 404
    }
    
    /**
     * Знайти товар за старим slug
     */
    private function find_product_by_old_slug($old_slug) {
        global $wpdb;
        
        // Шукаємо в post_meta де зберігається _gmco_original_slug
        $query = $wpdb->prepare("
            SELECT post_id 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_gmco_original_slug' 
            AND meta_value = %s
            LIMIT 1
        ", $old_slug);
        
        $product_id = $wpdb->get_var($query);
        
        return $product_id ? intval($product_id) : null;
    }
    
    /**
     * Отримати редірект з бази даних
     * (для майбутньої функціональності ручних редіректів)
     */
    private function get_redirect_from_database($old_slug) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'gmco_redirects';
        
        // Перевіряємо чи існує таблиця
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return null;
        }
        
        $query = $wpdb->prepare("
            SELECT new_url 
            FROM {$table_name} 
            WHERE old_slug = %s 
            AND active = 1
            LIMIT 1
        ", $old_slug);
        
        return $wpdb->get_var($query);
    }
    
    /**
     * Додати ручний редірект (для майбутнього)
     */
    public function add_redirect($old_slug, $new_url) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'gmco_redirects';
        
        $wpdb->insert(
            $table_name,
            array(
                'old_slug' => $old_slug,
                'new_url' => $new_url,
                'active' => 1,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%d', '%s')
        );
        
        GMCO_Logger::log("📝 Додано ручний редірект: {$old_slug} → {$new_url}");
    }
    
    /**
     * Створити таблицю для редіректів
     */
    public static function create_redirects_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'gmco_redirects';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            old_slug varchar(200) NOT NULL,
            new_url varchar(500) NOT NULL,
            active tinyint(1) DEFAULT 1,
            hits int(11) DEFAULT 0,
            created_at datetime NOT NULL,
            last_used datetime,
            PRIMARY KEY (id),
            KEY old_slug (old_slug),
            KEY active (active)
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        GMCO_Logger::log("✅ Таблиця редіректів створена/оновлена");
    }
    
    /**
     * Отримати статистику редіректів
     */
    public function get_redirect_stats() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'gmco_redirects';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return array(
                'total' => 0,
                'active' => 0,
                'total_hits' => 0
            );
        }
        
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) as active,
                SUM(hits) as total_hits
            FROM {$table_name}
        ", ARRAY_A);
        
        return $stats;
    }
    
    /**
     * Автоматичне додавання редіректу при зміні slug товару
     */
    public static function add_redirect_on_slug_change($product_id, $old_slug, $new_slug) {
        // Зберігаємо старий slug в meta (вже робиться)
        update_post_meta($product_id, '_gmco_original_slug', $old_slug);
        
        // Також можна додати в таблицю редіректів для статистики
        $old_url = home_url('/product/' . $old_slug . '/');
        $new_url = get_permalink($product_id);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'gmco_redirects';
        
        // Перевіряємо чи існує таблиця
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name) {
            // Перевіряємо чи вже є такий редірект
            $exists = $wpdb->get_var($wpdb->prepare("
                SELECT id FROM {$table_name} 
                WHERE old_slug = %s
            ", $old_slug));
            
            if (!$exists) {
                $wpdb->insert(
                    $table_name,
                    array(
                        'old_slug' => $old_slug,
                        'new_url' => $new_url,
                        'active' => 1,
                        'created_at' => current_time('mysql')
                    ),
                    array('%s', '%s', '%d', '%s')
                );
                
                GMCO_Logger::log("📝 Автоматичний редірект: {$old_slug} → {$new_slug}");
            }
        }
    }
    
    /**
     * Оновити статистику використання редіректу
     */
    private function update_redirect_stats($old_slug) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'gmco_redirects';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return;
        }
        
        $wpdb->query($wpdb->prepare("
            UPDATE {$table_name}
            SET hits = hits + 1,
                last_used = %s
            WHERE old_slug = %s
        ", current_time('mysql'), $old_slug));
    }
}
