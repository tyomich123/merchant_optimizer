<?php
/**
 * OpenAI API handler with support for both Chat Completions and Responses API
 */
class GMCO_OpenAI {
    
    private $api_key;
    private $model;
    private $chat_api_url = 'https://api.openai.com/v1/chat/completions';
    private $responses_api_url = 'https://api.openai.com/v1/responses';
    
    public function __construct($api_key = null, $model = null) {
        $settings = get_option('gmco_settings');
        
        $this->api_key = $api_key ?: $settings['openai_api_key'];
        $this->model = $model ?: ($settings['openai_model'] ?? 'gpt-5-nano');
    }
    
    /**
     * Визначення чи модель є GPT-5
     */
    private function is_gpt5() {
        return (strpos($this->model, 'gpt-5') !== false);
    }
    
    /**
     * Визначення чи модель є reasoning (o-series)
     */
    private function is_reasoning_model() {
        return (strpos($this->model, 'o1') !== false || strpos($this->model, 'o3') !== false);
    }
    
    /**
     * Визначення чи використовувати Responses API
     */
    private function should_use_responses_api() {
        // GPT-5 та reasoning моделі працюють краще з Responses API
        return $this->is_gpt5() || $this->is_reasoning_model();
    }
    
    /**
     * Тест підключення до OpenAI
     */
    public function test_connection() {
        if ($this->should_use_responses_api()) {
            return $this->test_connection_responses_api();
        } else {
            return $this->test_connection_chat_api();
        }
    }
    
    /**
     * Тест через Responses API
     */
    private function test_connection_responses_api() {
        $response = wp_remote_post($this->responses_api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode(array(
                'model' => $this->model,
                'input' => 'Test'
            )),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return array(
                'success' => false,
                'message' => $body['error']['message'] ?? 'Unknown error'
            );
        }
        
        return array(
            'success' => true,
            'message' => __('Connection successful! Using Responses API', 'gmco')
        );
    }
    
    /**
     * Тест через Chat Completions API
     */
    private function test_connection_chat_api() {
        $response = wp_remote_post($this->chat_api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode(array(
                'model' => $this->model,
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => 'Test'
                    )
                ),
                'max_tokens' => 10
            )),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return array(
                'success' => false,
                'message' => $body['error']['message'] ?? 'Unknown error'
            );
        }
        
        return array(
            'success' => true,
            'message' => __('Connection successful! Using Chat Completions API', 'gmco')
        );
    }
    
    /**
     * Оптимізація заголовка та опису товару
     */
    public function optimize_product_content($product_title, $product_description, $brand = '', $volume = '') {
        if ($this->should_use_responses_api()) {
            return $this->optimize_with_responses_api($product_title, $product_description, $brand, $volume);
        } else {
            return $this->optimize_with_chat_api($product_title, $product_description, $brand, $volume);
        }
    }
    
    /**
     * Оптимізація через Responses API (для GPT-5)
     */
    private function optimize_with_responses_api($product_title, $product_description, $brand, $volume) {
        $max_retries = 2;
        $last_error = '';
        
        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            $prompt = $this->build_prompt($product_title, $product_description, $brand, $volume);
            
            // Параметри для Responses API
            $api_params = array(
                'model' => $this->model,
                'input' => array(
                    array(
                        'role' => 'developer',
                        'content' => 'Ти експерт з Google Merchant Center та e-commerce копірайтингу. Створюй ДЕТАЛЬНІ описи (мінімум 400 слів). КРИТИЧНО: 1) НЕ використовуй "секс", "анал", "вагіна", "пеніс", "збудження", "оргазм". 2) НЕ пиши "невідомо", "невказано" - якщо не знаєш, просто пропусти поле. 3) Опис має бути розгорнутий з деталями. Відповідай ТІЛЬКИ валідним JSON: {"title":"...","description":"мінімум 400 слів"}'
                    ),
                    array(
                        'role' => 'user',
                        'content' => $prompt
                    )
                ),
                'text' => array(
                    'format' => array(
                        'type' => 'json_object'
                    )
                )
                // НЕ включаємо temperature для GPT-5 - використовується дефолтне значення 1
                // НЕ включаємо max_completion_tokens - модель сама визначить
            );
            
            $response = wp_remote_post($this->responses_api_url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type' => 'application/json',
                ),
                'body' => json_encode($api_params),
                'timeout' => 60
            ));
            
            if (is_wp_error($response)) {
                $last_error = $response->get_error_message();
                if ($attempt < $max_retries) {
                    sleep(2);
                    continue;
                }
                return array(
                    'success' => false,
                    'error' => $last_error
                );
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (isset($body['error'])) {
                $last_error = $body['error']['message'] ?? 'Unknown error';
                if ($attempt < $max_retries) {
                    sleep(2);
                    continue;
                }
                return array(
                    'success' => false,
                    'error' => $last_error
                );
            }
            
            // Витягуємо текст з Responses API
            $content = $this->extract_text_from_responses_api($body);
            
            if (!$content) {
                $last_error = __('Invalid API response: no output text', 'gmco');
                if ($attempt < $max_retries) {
                    sleep(2);
                    continue;
                }
                return array(
                    'success' => false,
                    'error' => $last_error
                );
            }
            
            // Парсинг відповіді
            $result = $this->parse_response($content);
            
            if ($result['success']) {
                return $result;
            }
            
            $last_error = $result['error'];
            if ($attempt < $max_retries) {
                GMCO_Logger::log(sprintf('Attempt %d failed: %s, retrying...', $attempt, $last_error), 'warning');
                sleep(2);
                continue;
            }
        }
        
        return array(
            'success' => false,
            'error' => 'Failed after ' . $max_retries . ' attempts: ' . $last_error
        );
    }
    
    /**
     * Витягування тексту з відповіді Responses API
     */
    private function extract_text_from_responses_api($body) {
        // Перевіряємо output_text (SDK property)
        if (isset($body['output_text'])) {
            return $body['output_text'];
        }
        
        // Перевіряємо output array
        if (!isset($body['output']) || !is_array($body['output'])) {
            return null;
        }
        
        // Збираємо всі text outputs
        $text_parts = array();
        
        foreach ($body['output'] as $item) {
            if ($item['type'] === 'message' && isset($item['content'])) {
                foreach ($item['content'] as $content_item) {
                    if ($content_item['type'] === 'output_text' && isset($content_item['text'])) {
                        $text_parts[] = $content_item['text'];
                    }
                }
            }
        }
        
        return !empty($text_parts) ? implode("\n", $text_parts) : null;
    }
    
    /**
     * Оптимізація через Chat Completions API (для legacy моделей)
     */
    private function optimize_with_chat_api($product_title, $product_description, $brand, $volume) {
        $max_retries = 2;
        $last_error = '';
        
        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            $prompt = $this->build_prompt($product_title, $product_description, $brand, $volume);
            
            $api_params = array(
                'model' => $this->model,
                'messages' => array(
                    array(
                        'role' => 'system',
                        'content' => 'Ти експерт з Google Merchant Center та e-commerce копірайтингу. Створюй ДЕТАЛЬНІ описи (мінімум 400 слів). КРИТИЧНО: 1) НЕ використовуй "секс", "анал", "вагіна", "пеніс", "збудження", "оргазм". 2) НЕ пиши "невідомо", "невказано" - якщо не знаєш, просто пропусти поле. 3) Опис має бути розгорнутий з деталями. Відповідай ТІЛЬКИ валідним JSON: {"title":"...","description":"мінімум 400 слів"}'
                    ),
                    array(
                        'role' => 'user',
                        'content' => $prompt
                    )
                ),
                'temperature' => 0.7,
                'max_tokens' => 4000,  // Збільшено для довших описів
                'response_format' => array('type' => 'json_object')
            );
            
            $response = wp_remote_post($this->chat_api_url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type' => 'application/json',
                ),
                'body' => json_encode($api_params),
                'timeout' => 60
            ));
            
            if (is_wp_error($response)) {
                $last_error = $response->get_error_message();
                if ($attempt < $max_retries) {
                    sleep(2);
                    continue;
                }
                return array(
                    'success' => false,
                    'error' => $last_error
                );
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (isset($body['error'])) {
                $last_error = $body['error']['message'] ?? 'Unknown error';
                if ($attempt < $max_retries) {
                    sleep(2);
                    continue;
                }
                return array(
                    'success' => false,
                    'error' => $last_error
                );
            }
            
            if (!isset($body['choices'][0]['message']['content'])) {
                $last_error = __('Invalid API response', 'gmco');
                if ($attempt < $max_retries) {
                    sleep(2);
                    continue;
                }
                return array(
                    'success' => false,
                    'error' => $last_error
                );
            }
            
            $content = $body['choices'][0]['message']['content'];
            
            // Парсинг відповіді
            $result = $this->parse_response($content);
            
            if ($result['success']) {
                return $result;
            }
            
            $last_error = $result['error'];
            if ($attempt < $max_retries) {
                GMCO_Logger::log(sprintf('Attempt %d failed: %s, retrying...', $attempt, $last_error), 'warning');
                sleep(2);
                continue;
            }
        }
        
        return array(
            'success' => false,
            'error' => 'Failed after ' . $max_retries . ' attempts: ' . $last_error
        );
    }
    
    /**
     * Створення промпту для OpenAI
     */
    private function build_prompt($title, $description, $brand, $volume) {
        // Якщо опису немає, використовуємо заголовок
        $input_description = !empty($description) ? $description : "Опис відсутній. Створіть Shopping-safe опис на основі заголовку.";
        
        $prompt = <<<PROMPT
Ти — e-commerce копірайтер та спеціаліст з Google Merchant Center. Твоя задача — створити ДЕТАЛЬНИЙ та РОЗГОРНУТИЙ Shopping-safe опис товару для інтернет-магазину інтимних товарів.

КРИТИЧНО ВАЖЛИВО:
– Опис має бути МІНІМУМ 400 слів (це дуже важливо!)
– Текст має бути інформативним, детальним та корисним
– 100% сумісність з політиками Google Shopping
– НЕ пиши "невідомо", "невказано", "інформація відсутня" - якщо чогось не знаєш, просто НЕ згадуй це поле
– Якщо бракує інформації - розпиши детальніше наявні характеристики та загальні переваги категорії товару

ЗАБОРОНЕНО (абсолютно):
«секс», «статевий акт», «член», «пісюн», «пеніс», «вагіна», «піхва», «анал», «попа», «оральний», «ерекція», «збудження», «оргазм», «проникнення», «феляція», «кунiлiнгус» та будь-які сексуальні дії або обіцянки результатів.

ДОЗВОЛЕНА ЛЕКСИКА:
«інтимний», «лубрикант», «аксесуари», «інтимні іграшки», «білизна», «одяг для особливих моментів», «ковзання», «зволоження», «комфорт», «тілесний догляд», «чутлива шкіра», «латексні вироби», «зовнішнє використання», «естетичний вигляд», «елегантний дизайн»

СТРУКТУРА ОПИСУ (ОБОВ'ЯЗКОВО, мінімум 400 слів):

1. **Вступний розділ** (3-5 абзаців, 150-200 слів):
   - Загальний опис товару та його призначення
   - Детальний опис зовнішнього вигляду, матеріалів, кольору
   - Особливості дизайну та конструкції
   - Для кого підходить цей товар
   - Основна цінність продукту

2. **Основні переваги** (маркований список, 5-8 пунктів):
   - Кожен пункт має бути детальним (10-15 слів)
   - Опиши КОНКРЕТНІ переваги товару
   - Матеріали, якість, комфорт, естетика
   - Практичні аспекти використання

3. **Детальний опис** (2-3 абзаци, 100-150 слів):
   - Розгорнутий опис особливостей товару
   - Деталі конструкції, якості виготовлення
   - Як товар виглядає та відчувається
   - Унікальні особливості та характеристики

4. **Характеристики** (маркований список):
   - ВКЛЮЧАЙ тільки ТІ характеристики що ВІДОМІ
   - Бренд (якщо є)
   - Об'єм (тільки якщо є і це доречно для товару)
   - Матеріал/Основа (якщо відомо)
   - Розмір (якщо відомо)
   - Колір (якщо є в заголовку або описі)
   - Країна виробництва (якщо відомо)
   - НЕ пиши "невідомо" або "невказано" - просто пропусти ці поля!

5. **Догляд та використання** (1-2 абзаци, 50-100 слів):
   - Рекомендації по догляду за товаром
   - Умови зберігання
   - Особливості використання
   - Важлива інформація для покупця

ПРАВИЛА НАПИСАННЯ:

**Обсяг тексту:**
- Мінімум 400 слів загалом (дуже важливо!)
- Детальні описи, а не короткі фрази
- Розгорнуті пояснення переваг
- Багато корисної інформації

**Якщо інформації бракує:**
- НЕ пиши "невідомо" або "невказано"
- Розпиши детальніше те що відомо
- Додай загальну інформацію про категорію товару
- Опиши типові характеристики таких товарів
- Розкажи про переваги матеріалів (якщо вони згадані)

**Стиль:**
- Інформативний та нейтральний
- Професійний тон
- Як у великих європейських магазинів
- Без емоційних закликів
- Без перебільшень

**Форматування:**
- Використовуй <strong> для заголовків розділів
- Використовуй • (маркер) для списків
- Залишай порожні рядки між розділами (\n\n)
- Абзаци для зручного читання

ПРИКЛАД СТРУКТУРИ (400+ слів):

```
[Вступний абзац 1 про загальний опис товару, його призначення та головні особливості - 3-4 речення]

[Вступний абзац 2 про матеріали, дизайн та зовнішній вигляд - 3-4 речення]

[Вступний абзац 3 про для кого підходить та основну цінність - 2-3 речення]

<strong>Основні переваги:</strong>
• [Детальна перевага 1 з поясненням чому це важливо - 10-15 слів]
• [Детальна перевага 2 з конкретними характеристиками - 10-15 слів]
• [Детальна перевага 3 про матеріали та якість - 10-15 слів]
• [Детальна перевага 4 про комфорт використання - 10-15 слів]
• [Детальна перевага 5 про зовнішній вигляд - 10-15 слів]

<strong>Детальний опис:</strong>
[Абзац 1 з розгорнутим описом особливостей конструкції, деталей виготовлення - 4-5 речень]

[Абзац 2 про унікальні характеристики та що відрізняє цей товар - 3-4 речення]

<strong>Характеристики:</strong>
• Бренд: [назва]
• Колір: [якщо відомо]
• Матеріал: [якщо відомо]
• Розмір: [якщо відомо]
[ТІЛЬКИ ті поля що відомі, БЕЗ "невідомо"]

<strong>Догляд та використання:</strong>
[Абзац з рекомендаціями по догляду, зберіганню та використанню - 3-4 речення]
```

# ВХІДНІ ДАНІ
Поточний заголовок: {$title}

Поточний опис:
{$input_description}

Бренд: {$brand}
Об'єм: {$volume}

# ФОРМАТ ВІДПОВІДІ

Відповідай ТІЛЬКИ у форматі JSON. НІЧОГО більше!

{
  "title": "SEO-оптимізований Shopping-safe заголовок (50-70 символів)",
  "description": "ДЕТАЛЬНИЙ структурований опис МІНІМУМ 400 слів з розділами та списками"
}

ФІНАЛЬНА ПЕРЕВІРКА перед відповіддю:
✓ Опис >= 400 слів?
✓ Немає слів "невідомо", "невказано"?
✓ Всі заборонені слова замінені?
✓ Структура з розділами присутня?
✓ Переваги детальні (10-15 слів кожна)?
✓ Є вступні абзаци (3-5)?
✓ Є детальний опис (2-3 абзаци)?
✓ Характеристики тільки відомі?
✓ Є розділ про догляд?
✓ Використано \n\n між розділами?
✓ Використано <strong> для заголовків?
✓ Використано • для списків?
✓ 100% Shopping-safe?

Якщо хоч один пункт "НІ" - переписуй опис!
PROMPT;
        
        return $prompt;
    }
    
    /**
     * Парсинг відповіді від OpenAI
     */
    private function parse_response($content) {
        // Очищуємо від markdown блоків
        if (preg_match('/```json\s*(.*?)\s*```/s', $content, $matches)) {
            $json_str = $matches[1];
        } elseif (preg_match('/```\s*(.*?)\s*```/s', $content, $matches)) {
            $json_str = $matches[1];
        } else {
            $json_str = $content;
        }
        
        // Очищуємо від зайвих пробілів та контрольних символів
        $json_str = trim($json_str);
        $json_str = preg_replace('/[\x00-\x1F\x7F]/u', '', $json_str); // Видаляємо контрольні символи
        
        // Видаляємо BOM якщо є
        $json_str = preg_replace('/^\xEF\xBB\xBF/', '', $json_str);
        
        // Спроба парсингу
        $data = json_decode($json_str, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Логуємо для діагностики
            $error_msg = json_last_error_msg();
            $preview = mb_substr($json_str, 0, 200);
            
            return array(
                'success' => false,
                'error' => 'Failed to parse JSON response: ' . $error_msg,
                'preview' => $preview,
                'raw_length' => strlen($json_str)
            );
        }
        
        if (!isset($data['title']) || !isset($data['description'])) {
            return array(
                'success' => false,
                'error' => 'Missing title or description in response',
                'data' => $data
            );
        }
        
        // Очищуємо title від контрольних символів
        $data['title'] = preg_replace('/[\x00-\x1F\x7F]/u', '', $data['title']);
        
        // Форматуємо description в HTML
        $data['description'] = $this->format_description_html($data['description']);
        
        return array(
            'success' => true,
            'title' => $data['title'],
            'description' => $data['description']
        );
    }
    
    /**
     * Форматування опису в HTML для зручного читання
     */
    private function format_description_html($description) {
        // Конвертуємо \n в реальні переноси рядків
        $description = str_replace('\n', "\n", $description);
        
        // Розбиваємо на абзаци по подвійним переносам
        $paragraphs = explode("\n\n", $description);
        
        $html = '';
        
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            
            if (empty($paragraph)) {
                continue;
            }
            
            // Розбиваємо параграф на рядки
            $lines = explode("\n", $paragraph);
            
            // Перевіряємо чи це список (більшість рядків починаються з маркера)
            $list_count = 0;
            foreach ($lines as $line) {
                if (preg_match('/^[•\-\*]\s/', trim($line))) {
                    $list_count++;
                }
            }
            
            $is_list = ($list_count > 0);
            
            if ($is_list) {
                // Формуємо список
                $html .= '<ul>' . "\n";
                
                foreach ($lines as $item) {
                    $item = trim($item);
                    if (empty($item)) continue;
                    
                    // Видаляємо маркери
                    $item = preg_replace('/^[•\-\*]\s+/', '', $item);
                    
                    if (!empty($item)) {
                        $html .= '<li>' . wp_kses($item, array()) . '</li>' . "\n";
                    }
                }
                
                $html .= '</ul>' . "\n";
            }
            else {
                // Це абзац - дозволяємо тільки <strong>
                $safe_paragraph = wp_kses($paragraph, array('strong' => array()));
                $html .= '<p>' . nl2br($safe_paragraph) . '</p>' . "\n";
            }
        }
        
        return trim($html);
    }
}
