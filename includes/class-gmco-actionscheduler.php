<?php
/**
 * ActionScheduler Integration for GMCO
 * 
 * Забезпечує надійну обробку товарів через ActionScheduler
 */

class GMCO_ActionScheduler {
    
    private static $instance = null;
    
    // Hooks для ActionScheduler
    private const HOOK_PROCESS_PRODUCT = 'gmco_process_single_product';
    private const HOOK_BATCH = 'gmco_batch_process';
    
    // Groups
    private const GROUP_AUTO = 'gmco_auto';
    private const GROUP_MANUAL = 'gmco_manual';
    
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Перевіряємо чи доступний ActionScheduler
        if (!$this->is_available()) {
            GMCO_Logger::log('⚠️ ActionScheduler недоступний. Використовується fallback на WP-Cron', 'warning');
            return;
        }
        
        // Реєструємо workers (з кількістю параметрів)
        add_action(self::HOOK_PROCESS_PRODUCT, array($this, 'process_product_worker'), 10, 2);
        add_action(self::HOOK_BATCH, array($this, 'batch_worker'), 10, 2);
        
        // Автообробка нових товарів
        add_action('woocommerce_new_product', array($this, 'auto_optimize_new_product'), 10, 1);
        add_action('woocommerce_update_product', array($this, 'auto_optimize_updated_product'), 10, 1);
        
        // Retry logic
        add_action('action_scheduler_failed_execution', array($this, 'handle_failed_action'), 10, 2);
        
        // Логуємо тільки один раз при першій ініціалізації
    }
    
    /**
     * Перевірка доступності ActionScheduler
     */
    public function is_available() {
        return function_exists('as_schedule_single_action');
    }
    
    /**
     * Автоматична оптимізація нового товару
     */
    public function auto_optimize_new_product($product_id) {
        $settings = get_option('gmco_settings', array());
        
        // Якщо автообробка вимкнена
        if (empty($settings['auto_optimize_new'])) {
            return;
        }
        
        // Перевіряємо чи товар вже має оптимізований контент
        if ($settings['skip_optimized'] ?? true) {
            $meta = get_post_meta($product_id, '_gmco_optimized', true);
            if ($meta === 'yes') {
                GMCO_Logger::log("⏭️ Товар #{$product_id} вже оптимізовано (skip)");
                return;
            }
        }
        
        GMCO_Logger::log("📝 Новий товар #{$product_id} додано до черги автообробки");
        
        // Плануємо обробку через 60 секунд (даємо час товару збережитись)
        as_schedule_single_action(
            time() + 60,
            self::HOOK_PROCESS_PRODUCT,
            array('product_id' => $product_id),
            self::GROUP_AUTO
        );
    }
    
    /**
     * Автоматична обробка оновленого товару
     */
    public function auto_optimize_updated_product($product_id) {
        $settings = get_option('gmco_settings', array());
        
        // Якщо авто-реоптимізація вимкнена
        if (empty($settings['auto_reoptimize_updated'])) {
            return;
        }
        
        // Перевіряємо чи товар редагувався адміном (не автоматично)
        if (!did_action('save_post')) {
            return;
        }
        
        GMCO_Logger::log("🔄 Товар #{$product_id} оновлено, додано до черги реоптимізації");
        
        // Плануємо обробку через 90 секунд
        as_schedule_single_action(
            time() + 90,
            self::HOOK_PROCESS_PRODUCT,
            array('product_id' => $product_id, 'reoptimize' => true),
            self::GROUP_AUTO
        );
    }
    
    /**
     * Запуск масової обробки
     */
    public function start_batch($product_ids) {
        if (!$this->is_available()) {
            return false;
        }
        
        // Очищаємо стару чергу manual обробки
        as_unschedule_all_actions(self::HOOK_BATCH, array(), self::GROUP_MANUAL);
        as_unschedule_all_actions(self::HOOK_PROCESS_PRODUCT, array(), self::GROUP_MANUAL);
        
        GMCO_Logger::log(sprintf('🚀 Запуск масової обробки %d товарів', count($product_ids)));
        
        $settings = get_option('gmco_settings', array());
        $batch_size = max(1, intval($settings['batch_size'] ?? 5));
        $delay_between = max(1, intval($settings['delay'] ?? 3));

        // Розбиваємо на батчі для parallel processing
        $batches = array_chunk($product_ids, $batch_size);
        
        $delay = 0;
        foreach ($batches as $batch_index => $batch) {
            as_schedule_single_action(
                time() + $delay,
                self::HOOK_BATCH,
                array('product_ids' => $batch, 'batch_index' => $batch_index),
                self::GROUP_MANUAL
            );
            
            $delay += max(5, $delay_between * count($batch)); // швидший крок між батчами
        }
        
        GMCO_Logger::log(sprintf('✅ Заплановано %d батчів по %d товарів', count($batches), $batch_size));
        
        return true;
    }
    
    /**
     * Worker для обробки батчу
     * 
     * ВАЖЛИВО: ActionScheduler викликає action з аргументами як окремі параметри,
     * а не як один array. Тому приймаємо $product_ids та $batch_index окремо.
     */
    public function batch_worker($product_ids, $batch_index = 0) {
        GMCO_Logger::log(sprintf('🎯 BATCH WORKER викликано: батч #%d', $batch_index));
        
        // Якщо прийшов array з ключами (для сумісності)
        if (is_array($product_ids) && isset($product_ids['product_ids'])) {
            $args = $product_ids;
            $product_ids = $args['product_ids'] ?? array();
            $batch_index = $args['batch_index'] ?? 0;
        }
        
        GMCO_Logger::log(sprintf('📊 Батч #%d, товарів: %d', $batch_index, is_array($product_ids) ? count($product_ids) : 0));
        
        if (empty($product_ids) || !is_array($product_ids)) {
            GMCO_Logger::log('⚠️ Порожній або невалідний батч, пропускаємо', 'warning');
            return;
        }
        
        GMCO_Logger::log(sprintf('⚙️ Обробка батчу #%d (%d товарів)', $batch_index, count($product_ids)));
        
        $settings = get_option('gmco_settings', array());
        $delay_between = $settings['delay'] ?? 3;
        
        foreach ($product_ids as $index => $product_id) {
            // Плануємо окремо кожен товар з затримкою
            $delay = $index * $delay_between;
            
            GMCO_Logger::log(sprintf('📝 Планування товару #%d з затримкою %d сек', $product_id, $delay));
            
            as_schedule_single_action(
                time() + $delay,
                self::HOOK_PROCESS_PRODUCT,
                array('product_id' => $product_id),
                self::GROUP_MANUAL
            );
        }
        
        GMCO_Logger::log(sprintf('✅ Батч #%d завершено, заплановано %d товарів', $batch_index, count($product_ids)));
    }
    
    /**
     * Worker для обробки одного товару
     * 
     * ВАЖЛИВО: ActionScheduler викликає action з аргументами як окремі параметри
     */
    public function process_product_worker($product_id, $reoptimize = false) {
        GMCO_Logger::log(sprintf('🎯 PRODUCT WORKER викликано для товару #%d', $product_id));
        
        // Якщо прийшов array з ключами (для сумісності)
        if (is_array($product_id) && isset($product_id['product_id'])) {
            $args = $product_id;
            $product_id = $args['product_id'] ?? 0;
            $reoptimize = $args['reoptimize'] ?? false;
        }
        
        if (!$product_id) {
            GMCO_Logger::log('❌ Невалідний product_id', 'error');
            return;
        }
        
        // Lock механізм (щоб запобігти подвійній обробці)
        $lock_key = 'gmco_processing_' . $product_id;
        if (get_transient($lock_key)) {
            GMCO_Logger::log("⏭️ Товар #{$product_id} вже обробляється", 'warning');
            return;
        }
        
        set_transient($lock_key, true, 300); // 5 хв lock
        
        try {
            GMCO_Logger::log("▶️ Початок обробки товару #{$product_id}");
            
            $product = wc_get_product($product_id);
            
            if (!$product) {
                throw new Exception('Товар не знайдено');
            }
            
            // Перевіряємо чи потрібно пропустити
            $settings = get_option('gmco_settings', array());
            if (!$reoptimize && ($settings['skip_optimized'] ?? true)) {
                $meta = get_post_meta($product_id, '_gmco_optimized', true);
                if ($meta === 'yes') {
                    GMCO_Logger::log("⏭️ Товар #{$product_id} вже оптимізовано");
                    delete_transient($lock_key);
                    return;
                }
            }
            
            // Отримуємо дані товару
            $title = $product->get_name();
            $description = $product->get_description();
            
            // Brand та volume можуть бути в атрибутах або мета
            $brand = '';
            $volume = '';
            
            $attributes = $product->get_attributes();
            if (isset($attributes['pa_brand'])) {
                $brand = $product->get_attribute('pa_brand');
            } elseif (isset($attributes['brand'])) {
                $brand = $product->get_attribute('brand');
            }
            
            if (isset($attributes['pa_volume'])) {
                $volume = $product->get_attribute('pa_volume');
            } elseif (isset($attributes['volume'])) {
                $volume = $product->get_attribute('volume');
            }
            
            // Якщо не знайдено в атрибутах, шукаємо в мета
            if (empty($brand)) {
                $brand = get_post_meta($product_id, '_brand', true);
            }
            if (empty($volume)) {
                $volume = get_post_meta($product_id, '_volume', true);
            }
            
            GMCO_Logger::log("📊 Товар #{$product_id}: '{$title}'");
            
            // Викликаємо OpenAI
            $api_key = $settings['openai_api_key'] ?? '';
            $model = $settings['openai_model'] ?? 'gpt-5-nano';
            
            if (empty($api_key)) {
                throw new Exception('OpenAI API ключ не налаштовано');
            }
            
            $openai = new GMCO_OpenAI($api_key, $model);
            $result = $openai->optimize_product_content($title, $description, $brand, $volume);
            
            if (!$result['success']) {
                throw new Exception($result['error']);
            }
            
            // Оновлюємо товар
            $new_title = $result['title'];
            $new_description = $result['description'];
            
            // Генеруємо новий slug на основі нового заголовка
            $new_slug = sanitize_title($new_title);
            
            // Перевіряємо унікальність slug
            $original_slug = $new_slug;
            $suffix = 1;
            
            while (true) {
                $check = get_page_by_path($new_slug, OBJECT, 'product');
                if (!$check || $check->ID == $product_id) {
                    // Slug унікальний або належить поточному товару
                    break;
                }
                // Slug зайнятий, додаємо суфікс
                $new_slug = $original_slug . '-' . $suffix;
                $suffix++;
            }
            
            GMCO_Logger::log("🔗 Новий slug: '{$new_slug}'");
            
            // Зберігаємо старий slug для редіректу.
            // У нових версіях WooCommerce об'єкт WC_Product не гарантує доступ до $product->post.
            // Беремо slug напряму з WP-поста, з запасним варіантом через WC_Product.
            $old_slug = (string) get_post_field('post_name', $product_id);
            if ($old_slug === '') {
                $old_slug = (string) $product->get_slug();
            }
            
            // Оновлюємо товар з новим slug
            wp_update_post(array(
                'ID' => $product_id,
                'post_title' => $new_title,
                'post_content' => $new_description,
                'post_name' => $new_slug  // ВАЖЛИВО: оновлюємо slug
            ));
            
            // Створюємо редірект зі старого URL на новий
            if ($old_slug !== $new_slug && class_exists('GMCO_Redirects')) {
                add_post_meta($product_id, '_wp_old_slug', $old_slug, false);
                GMCO_Redirects::add_redirect_on_slug_change($product_id, $old_slug, $new_slug);
            }
            
            // Очищаємо кеш permalink
            clean_post_cache($product_id);
            delete_transient('wc_product_' . $product_id);
            
            // Зберігаємо оригінали
            update_post_meta($product_id, '_gmco_original_title', $title);
            update_post_meta($product_id, '_gmco_original_description', $description);
            // Зберігаємо саме ОРИГІНАЛЬНИЙ slug (до оновлення), а не новий.
            update_post_meta($product_id, '_gmco_original_slug', $old_slug);
            update_post_meta($product_id, '_gmco_optimized', 'yes');
            update_post_meta($product_id, '_gmco_optimized_date', current_time('mysql'));
            
            GMCO_Logger::log("✅ Товар #{$product_id} успішно оптимізовано");
            
            // Оновлюємо статистику
            $this->update_stats('success');
            
            // Очищаємо лічильник спроб
            delete_transient('gmco_attempts_' . $product_id);
            
        } catch (Exception $e) {
            GMCO_Logger::log("❌ Помилка обробки товару #{$product_id}: " . $e->getMessage(), 'error');
            
            // Оновлюємо статистику
            $this->update_stats('error');
            
            // ActionScheduler автоматично зробить retry
            throw $e;
            
        } finally {
            delete_transient($lock_key);
        }
    }
    
    /**
     * Обробка провалених завдань (retry logic)
     */
    public function handle_failed_action($action_id, $exception) {
        if (!function_exists('ActionScheduler')) {
            return;
        }
        
        try {
            $action = ActionScheduler::store()->fetch_action($action_id);
            
            // Тільки наші action
            $group = $action->get_group();
            if (strpos($group, 'gmco_') !== 0) {
                return;
            }
            
            $args = $action->get_args();
            $product_id = $args['product_id'] ?? 0;
            
            if (!$product_id) {
                return;
            }
            
            // Лічильник спроб
            $attempts_key = 'gmco_attempts_' . $product_id;
            $attempts = get_transient($attempts_key) ?: 0;
            
            // Максимум 3 спроби
            if ($attempts >= 3) {
                GMCO_Logger::log("❌ Товар #{$product_id} провалено після 3 спроб: " . $exception->getMessage(), 'error');
                delete_transient($attempts_key);
                
                // Зберігаємо помилку в мета
                update_post_meta($product_id, '_gmco_last_error', $exception->getMessage());
                update_post_meta($product_id, '_gmco_failed_attempts', 3);
                
                return;
            }
            
            // Збільшуємо лічильник
            $attempts++;
            set_transient($attempts_key, $attempts, 3600);
            
            // Retry з експоненційною затримкою: 2, 4, 8 хвилин
            $delay = pow(2, $attempts) * 60;
            $args['reoptimize'] = true;
            
            as_schedule_single_action(
                time() + $delay,
                $action->get_hook(),
                $args,
                $group
            );
            
            GMCO_Logger::log("🔄 Retry #{$attempts}/3 для товару #{$product_id} через " . ($delay / 60) . " хв", 'warning');
            
        } catch (Exception $e) {
            GMCO_Logger::log('❌ Помилка в handle_failed_action: ' . $e->getMessage(), 'error');
        }
    }
    
    /**
     * Оновлення статистики
     */
    private function update_stats($type) {
        $state = get_option('gmco_state', array(
            'status' => 'idle',
            'total' => 0,
            'processed' => 0,
            'success' => 0,
            'errors' => 0
        ));
        
        $state['processed']++;
        
        if ($type === 'success') {
            $state['success']++;
        } elseif ($type === 'error') {
            $state['errors']++;
        }
        
        update_option('gmco_state', $state);
    }
    
    /**
     * Отримання статистики черги
     */
    public function get_queue_stats() {
        if (!$this->is_available()) {
            return array(
                'pending' => 0,
                'running' => 0,
                'failed' => 0,
                'completed' => 0
            );
        }
        
        $stats = array();
        
        // Pending (заплановані)
        $pending = as_get_scheduled_actions(array(
            'group' => self::GROUP_MANUAL,
            'status' => ActionScheduler_Store::STATUS_PENDING,
            'per_page' => -1
        ), 'ids');
        $stats['pending'] = count($pending);
        
        // Running (виконуються)
        $running = as_get_scheduled_actions(array(
            'group' => self::GROUP_MANUAL,
            'status' => ActionScheduler_Store::STATUS_RUNNING,
            'per_page' => -1
        ), 'ids');
        $stats['running'] = count($running);
        
        // Failed (провалені)
        $failed = as_get_scheduled_actions(array(
            'group' => self::GROUP_MANUAL,
            'status' => ActionScheduler_Store::STATUS_FAILED,
            'per_page' => -1
        ), 'ids');
        $stats['failed'] = count($failed);
        
        // Auto queue stats
        $auto_pending = as_get_scheduled_actions(array(
            'group' => self::GROUP_AUTO,
            'status' => ActionScheduler_Store::STATUS_PENDING,
            'per_page' => -1
        ), 'ids');
        $stats['auto_pending'] = count($auto_pending);
        
        return $stats;
    }
    
    /**
     * Зупинка всіх завдань
     */
    public function stop_all() {
        if (!$this->is_available()) {
            return false;
        }
        
        GMCO_Logger::log('⏹️ Зупинка всіх завдань ActionScheduler');
        
        // Скасовуємо всі заплановані завдання
        as_unschedule_all_actions(self::HOOK_PROCESS_PRODUCT, array(), self::GROUP_MANUAL);
        as_unschedule_all_actions(self::HOOK_BATCH, array(), self::GROUP_MANUAL);
        
        return true;
    }
}