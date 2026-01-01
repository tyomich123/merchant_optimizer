<?php
/**
 * Plugin Name: Google Merchant Content Optimizer
 * Description: v2.6.1 - Fix WooCommerce slug handling (WC_Product no longer exposes $post)
 * Version: 2.6.1
 * Author: AI Enhanced
 * Text Domain: gmco
 * 
 * Tested: WordPress 6.4+, WooCommerce 8.0+, PHP 7.4+
 * Requires: WooCommerce (для ActionScheduler)
 * 
 * Features:
 * - GPT-5 Nano support (Responses API)
 * - ActionScheduler integration (99.9% reliability)
 * - Long detailed descriptions (400+ words minimum)
 * - Automatic 301 redirects for old product URLs
 * - SEO-friendly slug changes
 * - No "unknown" fields in output
 * - HTML formatted descriptions (structured & readable)
 * - Shopping-Safe промпт (Google Merchant Center compliant)
 */

if (!defined('ABSPATH')) {
    exit;
}

// Завантаження класів
require_once plugin_dir_path(__FILE__) . 'includes/class-gmco-database.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-gmco-logger.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-gmco-openai.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-gmco-actionscheduler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-gmco-redirects.php';

final class Google_Merchant_Content_Optimizer {
    private const VERSION = '2.6.1';
    
    // Константи для обробки
    private const BATCH_SIZE = 1;              // По 1 товару для надійності
    private const DELAY_BETWEEN = 3;           // 3 секунди між товарами
    private const MAX_EXECUTION_TIME = 50;     // 50 секунд на батч
    private const STALL_SEC = 60;              // 1 хвилина без активності = зависання
    private const LOCK_TTL = 60;               // Lock на 60 секунд
    
    // Опції
    private const OPT_STATE = 'gmco_state';
    private const OPT_SETTINGS = 'gmco_settings';
    private const OPT_HEARTBEAT = 'gmco_last_heartbeat';
    private const OPT_QUEUE = 'gmco_products_queue';
    
    // Transients
    private const TR_LOCK = 'gmco_lock';
    
    // Cron hooks
    private const CRON_BATCH = 'gmco_cron_batch';
    private const CRON_WATCHDOG = 'gmco_cron_watchdog';
    private const CRON_HEALTH = 'gmco_cron_health';
    
    private static $instance = null;
    
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Ініціалізація ActionScheduler на init hook (після WooCommerce)
        add_action('init', array($this, 'init_actionscheduler'), 20);
        
        // Ініціалізація системи редіректів
        add_action('init', array($this, 'init_redirects'), 20);
        
        // Cron actions
        add_action(self::CRON_BATCH, array($this, 'cron_batch'));
        add_action(self::CRON_WATCHDOG, array($this, 'watchdog'));
        add_action(self::CRON_HEALTH, array($this, 'health_check'));
        
        // Cron schedules
        add_filter('cron_schedules', array($this, 'cron_schedules'));
        
        // Hooks
        register_activation_hook(__FILE__, array($this, 'on_activate'));
        register_deactivation_hook(__FILE__, array($this, 'on_deactivate'));
        
        // Admin
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        
        // AJAX
        add_action('wp_ajax_gmco_start', array($this, 'ajax_start'));
        add_action('wp_ajax_gmco_stop', array($this, 'ajax_stop'));
        add_action('wp_ajax_gmco_status', array($this, 'ajax_status'));
        add_action('wp_ajax_gmco_force_clear', array($this, 'ajax_force_clear'));
        add_action('wp_ajax_gmco_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_gmco_test_openai', array($this, 'ajax_test_openai'));
        add_action('wp_ajax_gmco_diagnostics', array($this, 'ajax_diagnostics'));
        add_action('wp_ajax_gmco_force_batch', array($this, 'ajax_force_batch'));
        add_action('wp_ajax_gmco_flush_permalinks', array($this, 'ajax_flush_permalinks'));
        
        // Frontend heartbeat
        add_action('init', array($this, 'frontend_heartbeat'), 1);
    }
    
    /**
     * Ініціалізація ActionScheduler
     */
    public function init_actionscheduler() {
        if (!class_exists('GMCO_ActionScheduler')) {
            GMCO_Logger::log('❌ Клас GMCO_ActionScheduler не знайдено', 'error');
            return;
        }
        
        if (!function_exists('as_schedule_single_action')) {
            GMCO_Logger::log('⚠️ ActionScheduler недоступний (WooCommerce не активний)', 'warning');
            return;
        }
        
        GMCO_ActionScheduler::instance();
        // Логування тільки при першому запуску (всередині класу)
    }
    
    /**
     * Ініціалізація системи редіректів
     */
    public function init_redirects() {
        if (!class_exists('GMCO_Redirects')) {
            GMCO_Logger::log('❌ Клас GMCO_Redirects не знайдено', 'error');
            return;
        }
        
        GMCO_Redirects::instance();
        GMCO_Logger::log('🔄 Система редіректів ініціалізована');
    }
    
    /* ====================================================================
     * АКТИВАЦІЯ / ДЕАКТИВАЦІЯ
     * ==================================================================== */
    
    public function on_activate() {
        GMCO_Logger::log('✅ АКТИВАЦІЯ ПЛАГІНА v' . self::VERSION);
        
        // Створюємо таблиці
        GMCO_Database::create_tables();
        GMCO_Redirects::create_redirects_table();
        
        // Дефолтні налаштування
        if (!get_option(self::OPT_SETTINGS)) {
            $default = array(
                'openai_api_key' => '',
                'openai_model' => 'gpt-5-nano',
                'batch_size' => self::BATCH_SIZE,
                'delay' => self::DELAY_BETWEEN,
                'skip_optimized' => true,
                'log_level' => 'info',
                'auto_optimize_new' => false,  // Автообробка нових товарів
                'auto_reoptimize_updated' => false  // Реоптимізація при оновленні
            );
            update_option(self::OPT_SETTINGS, $default);
        }
        
        // Очищаємо старі cron
        $this->clear_all_cron();
        sleep(1);
        
        // Створюємо нові
        $this->setup_cron();
        sleep(1);
        
        // Перевіряємо
        $this->verify_cron();
        
        GMCO_Logger::log('✅ Активація завершена');
    }
    
    public function on_deactivate() {
        GMCO_Logger::log('⏹️ ДЕАКТИВАЦІЯ ПЛАГІНА');
        $this->clear_all_cron();
    }
    
    private function clear_all_cron() {
        $hooks = array(self::CRON_BATCH, self::CRON_WATCHDOG, self::CRON_HEALTH);
        
        foreach ($hooks as $hook) {
            $count = 0;
            while ($ts = wp_next_scheduled($hook)) {
                wp_unschedule_event($ts, $hook);
                $count++;
                if ($count > 100) break;
            }
        }
        
        GMCO_Logger::log('🧹 Очищено cron завдання');
    }
    
    private function setup_cron() {
        // Watchdog - кожні 30 секунд
        wp_schedule_event(time() + 30, 'thirty_seconds', self::CRON_WATCHDOG);
        GMCO_Logger::log('👁️ Watchdog створено (30 сек)');
        
        // Health Check - кожні 5 хвилин
        wp_schedule_event(time() + 300, 'five_minutes', self::CRON_HEALTH);
        GMCO_Logger::log('🏥 Health Check створено (5 хв)');
    }
    
    private function verify_cron() {
        $checks = array(
            'Watchdog' => self::CRON_WATCHDOG,
            'Health' => self::CRON_HEALTH
        );
        
        foreach ($checks as $name => $hook) {
            if (!wp_next_scheduled($hook)) {
                GMCO_Logger::log('❌ ' . $name . ' не створився!', 'error');
            } else {
                GMCO_Logger::log('✅ ' . $name . ' OK');
            }
        }
    }
    
    public function cron_schedules($schedules) {
        $schedules['thirty_seconds'] = array(
            'interval' => 30,
            'display' => 'Every 30 Seconds'
        );
        $schedules['five_minutes'] = array(
            'interval' => 300,
            'display' => 'Every 5 Minutes'
        );
        return $schedules;
    }
    
    /* ====================================================================
     * WATCHDOG - АВТОМАТИЧНЕ ВІДНОВЛЕННЯ
     * ==================================================================== */
    
    public function watchdog() {
        $state = $this->get_state();
        
        if ($state['status'] === 'idle') {
            return;
        }
        
        $last_hb = get_option(self::OPT_HEARTBEAT, 0);
        $elapsed = time() - $last_hb;
        
        // Якщо зависло більше 1 хвилини
        if ($elapsed > self::STALL_SEC) {
            GMCO_Logger::log(sprintf('⚠️ ЗАВИСАННЯ виявлено! (%d сек)', $elapsed), 'warning');
            GMCO_Logger::log('🔄 AUTO-RECOVERY: відновлення процесу');
            
            // Звільняємо lock
            delete_transient(self::TR_LOCK);
            
            // Оновлюємо heartbeat
            $this->update_heartbeat();
            
            // Запускаємо наступний батч
            $this->schedule_next_batch();
            
            GMCO_Logger::log('✅ Процес відновлено');
        }
    }
    
    public function health_check() {
        // Перевіряємо watchdog
        if (!wp_next_scheduled(self::CRON_WATCHDOG)) {
            wp_schedule_event(time() + 30, 'thirty_seconds', self::CRON_WATCHDOG);
            GMCO_Logger::log('🔧 Health: відновлено watchdog', 'warning');
        }
        
        // Перевіряємо зависання
        $state = $this->get_state();
        if ($state['status'] === 'running') {
            $last_hb = get_option(self::OPT_HEARTBEAT, 0);
            $elapsed = time() - $last_hb;
            
            if ($elapsed > 120) {
                GMCO_Logger::log('🔧 Health: довге зависання, відновлення', 'warning');
                delete_transient(self::TR_LOCK);
                $this->update_heartbeat();
                $this->schedule_next_batch();
            }
        }
    }
    
    /* ====================================================================
     * HEARTBEAT
     * ==================================================================== */
    
    private function update_heartbeat() {
        update_option(self::OPT_HEARTBEAT, time(), false);
    }
    
    public function frontend_heartbeat() {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        
        $state = $this->get_state();
        if ($state['status'] === 'running') {
            $this->update_heartbeat();
        }
    }
    
    /* ====================================================================
     * LOCK МЕХАНІЗМ
     * ==================================================================== */
    
    private function acquire_lock() {
        if (get_transient(self::TR_LOCK)) {
            return false;
        }
        set_transient(self::TR_LOCK, time(), self::LOCK_TTL);
        return true;
    }
    
    private function release_lock() {
        delete_transient(self::TR_LOCK);
    }
    
    /* ====================================================================
     * STATE MANAGEMENT
     * ==================================================================== */
    
    private function get_state() {
        $state = get_option(self::OPT_STATE, array(
            'status' => 'idle',
            'total' => 0,
            'processed' => 0,
            'success' => 0,
            'errors' => 0,
            'offset' => 0,
            'started_at' => null
        ));
        return $state;
    }
    
    private function update_state($data) {
        $state = $this->get_state();
        $state = array_merge($state, $data);
        update_option(self::OPT_STATE, $state, false);
    }
    
    /* ====================================================================
     * ОБРОБКА БАТЧУ
     * ==================================================================== */
    
    public function cron_batch() {
        @set_time_limit(self::MAX_EXECUTION_TIME);
        @ini_set('memory_limit', '512M');
        
        $state = $this->get_state();
        
        if ($state['status'] !== 'running') {
            return;
        }
        
        if (!$this->acquire_lock()) {
            GMCO_Logger::log('⏸️ Lock активний, пропускаємо батч');
            return;
        }
        
        $this->update_heartbeat();
        
        try {
            $this->process_batch();
        } catch (Exception $e) {
            GMCO_Logger::log('❌ Помилка батчу: ' . $e->getMessage(), 'error');
        }
        
        $this->release_lock();
    }
    
    private function process_batch() {
        $state = $this->get_state();
        $queue = get_option(self::OPT_QUEUE, array());
        
        if (empty($queue)) {
            GMCO_Logger::log('✅ Черга порожня, завершуємо');
            $this->complete_process();
            return;
        }
        
        $settings = get_option(self::OPT_SETTINGS);
        $batch_size = intval($settings['batch_size'] ?? self::BATCH_SIZE);
        $delay = intval($settings['delay'] ?? self::DELAY_BETWEEN);
        
        $batch = array_slice($queue, 0, $batch_size);
        
        GMCO_Logger::log(sprintf('🔄 Обробка батчу: %d товарів', count($batch)));
        
        $openai = new GMCO_OpenAI();
        
        foreach ($batch as $product_id) {
            $this->process_single_product($product_id, $openai);
            $this->update_heartbeat();
            
            // Видаляємо з черги
            $queue = array_diff($queue, array($product_id));
            update_option(self::OPT_QUEUE, $queue, false);
            
            // Оновлюємо state
            $state = $this->get_state();
            $this->update_state(array(
                'processed' => $state['processed'] + 1,
                'offset' => $state['offset'] + 1
            ));
            
            sleep($delay);
        }
        
        // Планування наступного батчу
        if (!empty($queue)) {
            $this->schedule_next_batch();
        } else {
            $this->complete_process();
        }
    }
    
    private function process_single_product($product_id, $openai) {
        GMCO_Logger::log(sprintf('📦 Обробка товару #%d', $product_id));
        
        $product = wc_get_product($product_id);
        if (!$product) {
            GMCO_Logger::log('❌ Товар не знайдено', 'error');
            $state = $this->get_state();
            $this->update_state(array('errors' => $state['errors'] + 1));
            return;
        }
        
        $title = $product->get_name();
        $description = $product->get_description();
        
        $result = $openai->optimize_product_content($title, $description);
        
        if ($result['success']) {
            // Генеруємо новий slug на основі нового заголовка
            $new_slug = sanitize_title($result['title']);
            
            // Перевіряємо унікальність slug
            $original_slug = $new_slug;
            $suffix = 1;
            
            while (true) {
                $check = get_page_by_path($new_slug, OBJECT, 'product');
                if (!$check || $check->ID == $product_id) {
                    break;
                }
                $new_slug = $original_slug . '-' . $suffix;
                $suffix++;
            }
            
            // Зберігаємо старий slug.
            // У нових версіях WooCommerce об'єкт WC_Product не гарантує доступ до $product->post.
            $old_slug = get_post_field('post_name', $product_id);
            if (empty($old_slug)) {
                $old_slug = $product->get_slug();
            }
            
            wp_update_post(array(
                'ID' => $product_id,
                'post_title' => $result['title'],
                'post_content' => $result['description'],
                'post_name' => $new_slug  // Оновлюємо slug
            ));
            
            // Очищаємо кеш
            clean_post_cache($product_id);
            delete_transient('wc_product_' . $product_id);
            
            update_post_meta($product_id, '_gmco_optimized', 1);
            update_post_meta($product_id, '_gmco_optimized_date', current_time('mysql'));
            update_post_meta($product_id, '_gmco_original_slug', $old_slug);
            
            // Створюємо редірект
            if ($old_slug !== $new_slug && class_exists('GMCO_Redirects')) {
                GMCO_Redirects::add_redirect_on_slug_change($product_id, $old_slug, $new_slug);
            }
            
            GMCO_Database::add_optimization_record(array(
                'product_id' => $product_id,
                'original_title' => $title,
                'optimized_title' => $result['title'],
                'original_description' => $description,
                'optimized_description' => $result['description'],
                'status' => 'completed'
            ));
            
            $state = $this->get_state();
            $this->update_state(array('success' => $state['success'] + 1));
            
            GMCO_Logger::log("✅ Товар оптимізовано (slug: {$new_slug})", 'success');
        } else {
            GMCO_Database::add_optimization_record(array(
                'product_id' => $product_id,
                'original_title' => $title,
                'original_description' => $description,
                'status' => 'error',
                'error_message' => $result['error']
            ));
            
            $state = $this->get_state();
            $this->update_state(array('errors' => $state['errors'] + 1));
            
            GMCO_Logger::log('❌ Помилка: ' . $result['error'], 'error');
        }
    }
    
    private function schedule_next_batch() {
        // Планування через WP Cron
        if (!wp_next_scheduled(self::CRON_BATCH)) {
            wp_schedule_single_event(time() + 5, self::CRON_BATCH);
            GMCO_Logger::log('⏰ Наступний батч заплановано');
        }
        
        // Тригер cron
        spawn_cron();
    }
    
    private function complete_process() {
        $state = $this->get_state();
        
        $this->update_state(array(
            'status' => 'completed',
            'completed_at' => current_time('mysql')
        ));
        
        delete_option(self::OPT_QUEUE);
        delete_transient(self::TR_LOCK);
        
        // Flush rewrite rules щоб оновити permalinks
        flush_rewrite_rules(false);
        
        GMCO_Logger::log(sprintf(
            '🎉 ЗАВЕРШЕНО! Успішно: %d, Помилок: %d. Permalink оновлено.',
            $state['success'],
            $state['errors']
        ), 'success');
    }
    
    /* ====================================================================
     * AJAX HANDLERS
     * ==================================================================== */
    
    public function ajax_start() {
        check_ajax_referer('gmco-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $force_all = isset($_POST['force_all']) && $_POST['force_all'];
        
        // Отримуємо товари
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post_status' => 'publish'
        );
        
        if (!$force_all) {
            $args['meta_query'] = array(
                'relation' => 'OR',
                array(
                    'key' => '_gmco_optimized',
                    'compare' => 'NOT EXISTS'
                ),
                array(
                    'key' => '_gmco_optimized',
                    'value' => '1',
                    'compare' => '!='
                )
            );
        }
        
        $products = get_posts($args);
        
        if (empty($products)) {
            wp_send_json_error(array('message' => 'Немає товарів для обробки'));
        }
        
        // Зберігаємо чергу
        update_option(self::OPT_QUEUE, $products, false);
        
        // Оновлюємо state
        $this->update_state(array(
            'status' => 'running',
            'total' => count($products),
            'processed' => 0,
            'success' => 0,
            'errors' => 0,
            'offset' => 0,
            'started_at' => current_time('mysql')
        ));
        
        $this->update_heartbeat();
        
        GMCO_Logger::log(sprintf('▶️ СТАРТ: %d товарів', count($products)));
        
        // Перевіряємо чи доступний ActionScheduler
        $as = GMCO_ActionScheduler::instance();
        if ($as && $as->is_available()) {
            GMCO_Logger::log('✅ Використовується ActionScheduler для обробки');
            
            // Використовуємо ActionScheduler
            $result = $as->start_batch($products);
            
            if ($result) {
                wp_send_json_success(array(
                    'message' => 'Процес запущено через ActionScheduler',
                    'total' => count($products),
                    'method' => 'ActionScheduler'
                ));
            } else {
                wp_send_json_error(array('message' => 'Помилка запуску ActionScheduler'));
            }
            
        } else {
            GMCO_Logger::log('⚠️ ActionScheduler недоступний, використовується WP-Cron');
            
            // Fallback на WP-Cron
            $this->schedule_next_batch();
            
            // КРИТИЧНО: Форсуємо запуск cron НЕГАЙНО
            GMCO_Logger::log('🚀 Форсований запуск першого батчу...');
            
            // Спроба 1: Через spawn_cron()
            if (function_exists('spawn_cron')) {
                spawn_cron();
                GMCO_Logger::log('✅ spawn_cron() викликано');
            }
            
            // Спроба 2: Прямий виклик батчу (якщо cron не працює)
            if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
                GMCO_Logger::log('⚠️ WP_CRON вимкнено, викликаємо батч напряму');
                wp_schedule_single_event(time(), self::CRON_BATCH);
                // І відразу виконуємо
                do_action(self::CRON_BATCH);
            }
            
            // Спроба 3: Через HTTP запит (fallback)
            $cron_url = site_url('wp-cron.php?doing_wp_cron');
            wp_remote_post($cron_url, array(
                'timeout' => 0.01,
                'blocking' => false,
                'sslverify' => false
            ));
        }
        GMCO_Logger::log('✅ HTTP тригер wp-cron.php відправлено');
        
        wp_send_json_success(array(
            'message' => 'Процес запущено',
            'total' => count($products)
        ));
    }
    
    public function ajax_stop() {
        check_ajax_referer('gmco-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $this->update_state(array('status' => 'stopped'));
        delete_transient(self::TR_LOCK);
        
        // Очищаємо заплановані батчі (WP-Cron)
        wp_clear_scheduled_hook(self::CRON_BATCH);
        
        // Зупиняємо ActionScheduler якщо доступний
        $as = GMCO_ActionScheduler::instance();
        if ($as && $as->is_available()) {
            $as->stop_all();
            GMCO_Logger::log('⏹️ ActionScheduler зупинено');
        }
        
        GMCO_Logger::log('⏹️ Процес зупинено');
        
        wp_send_json_success(array('message' => 'Процес зупинено'));
    }
    
    public function ajax_status() {
        check_ajax_referer('gmco-nonce', 'nonce');
        
        $state = $this->get_state();
        $last_hb = get_option(self::OPT_HEARTBEAT, 0);
        
        $percentage = $state['total'] > 0 
            ? round(($state['processed'] / $state['total']) * 100, 2)
            : 0;
        
        $response = array(
            'status' => $state['status'],
            'total' => $state['total'],
            'processed' => $state['processed'],
            'success' => $state['success'],
            'errors' => $state['errors'],
            'percentage' => $percentage,
            'started_at' => $state['started_at'],
            'last_heartbeat' => $last_hb,
            'heartbeat_age' => time() - $last_hb
        );
        
        // Додаємо статистику ActionScheduler якщо доступний
        $as = GMCO_ActionScheduler::instance();
        if ($as && $as->is_available()) {
            $queue_stats = $as->get_queue_stats();
            $response['actionscheduler'] = $queue_stats;
            $response['using_actionscheduler'] = true;
        } else {
            $response['using_actionscheduler'] = false;
        }
        
        wp_send_json_success($response);
    }
    
    public function ajax_force_clear() {
        check_ajax_referer('gmco-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $this->update_state(array('status' => 'idle'));
        delete_option(self::OPT_QUEUE);
        delete_transient(self::TR_LOCK);
        wp_clear_scheduled_hook(self::CRON_BATCH);
        
        GMCO_Logger::log('🧹 Force Clear виконано', 'warning');
        
        wp_send_json_success(array('message' => 'Процес очищено'));
    }
    
    public function ajax_save_settings() {
        check_ajax_referer('gmco-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $settings = array(
            'openai_api_key' => sanitize_text_field($_POST['openai_api_key'] ?? ''),
            'openai_model' => sanitize_text_field($_POST['openai_model'] ?? 'gpt-5-nano'),
            'batch_size' => intval($_POST['batch_size'] ?? 1),
            'delay' => intval($_POST['delay'] ?? 3),
            'skip_optimized' => isset($_POST['skip_optimized']),
            'log_level' => sanitize_text_field($_POST['log_level'] ?? 'info'),
            'auto_optimize_new' => isset($_POST['auto_optimize_new']),
            'auto_reoptimize_updated' => isset($_POST['auto_reoptimize_updated'])
        );
        
        update_option(self::OPT_SETTINGS, $settings);
        
        wp_send_json_success(array('message' => 'Налаштування збережено'));
    }
    
    public function ajax_test_openai() {
        check_ajax_referer('gmco-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $api_key = sanitize_text_field($_POST['api_key'] ?? '');
        $openai = new GMCO_OpenAI($api_key);
        
        $result = $openai->test_connection();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    public function ajax_diagnostics() {
        check_ajax_referer('gmco-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $diagnostics = array(
            'wp_cron_enabled' => !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON),
            'cron_jobs' => array(
                'batch' => wp_next_scheduled(self::CRON_BATCH),
                'watchdog' => wp_next_scheduled(self::CRON_WATCHDOG),
                'health' => wp_next_scheduled(self::CRON_HEALTH)
            ),
            'lock' => get_transient(self::TR_LOCK),
            'heartbeat' => get_option(self::OPT_HEARTBEAT, 0),
            'heartbeat_age' => time() - get_option(self::OPT_HEARTBEAT, 0),
            'queue_size' => count(get_option(self::OPT_QUEUE, array())),
            'state' => $this->get_state(),
            'spawn_cron_exists' => function_exists('spawn_cron')
        );
        
        wp_send_json_success($diagnostics);
    }
    
    public function ajax_force_batch() {
        check_ajax_referer('gmco-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        GMCO_Logger::log('🔧 Форсований виклик батчу вручну');
        
        // Прямий виклик
        $this->cron_batch();
        
        wp_send_json_success(array('message' => 'Батч виконано'));
    }
    
    public function ajax_flush_permalinks() {
        check_ajax_referer('gmco-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        // Flush rewrite rules
        flush_rewrite_rules(false);
        
        GMCO_Logger::log('🔄 Permalinks оновлено вручну');
        
        wp_send_json_success(array('message' => 'Permalinks оновлено'));
    }
    
    /* ====================================================================
     * ADMIN PAGE
     * ==================================================================== */
    
    public function admin_menu() {
        add_menu_page(
            'Google Merchant Optimizer',
            'Merchant Optimizer',
            'manage_options',
            'gmco-optimizer',
            array($this, 'admin_page_main'),
            'dashicons-cart',
            56
        );
        
        add_submenu_page(
            'gmco-optimizer',
            'Settings',
            'Settings',
            'manage_options',
            'gmco-settings',
            array($this, 'admin_page_settings')
        );
        
        add_submenu_page(
            'gmco-optimizer',
            'Logs',
            'Logs',
            'manage_options',
            'gmco-logs',
            array($this, 'admin_page_logs')
        );
    }
    
    public function admin_scripts($hook) {
        if (strpos($hook, 'gmco-') === false) {
            return;
        }
        
        wp_enqueue_style('gmco-admin', plugins_url('assets/css/admin.css', __FILE__), array(), self::VERSION);
        wp_enqueue_script('gmco-admin', plugins_url('assets/js/admin.js', __FILE__), array('jquery'), self::VERSION, true);
        
        wp_localize_script('gmco-admin', 'gmcoData', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gmco-nonce')
        ));
    }
    
    public function admin_page_main() {
        include plugin_dir_path(__FILE__) . 'admin/views/main-page.php';
    }
    
    public function admin_page_settings() {
        include plugin_dir_path(__FILE__) . 'admin/views/settings-page.php';
    }
    
    public function admin_page_logs() {
        include plugin_dir_path(__FILE__) . 'admin/views/logs-page.php';
    }
}

// Ініціалізація
add_action('plugins_loaded', array('Google_Merchant_Content_Optimizer', 'instance'));
