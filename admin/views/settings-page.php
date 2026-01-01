<?php
if (!defined('ABSPATH')) exit;
$settings = get_option('gmco_settings');
?>

<div class="wrap">
<h1>⚙️ Settings</h1>

<form id="settings-form">
<table class="form-table">
<tr>
<th>OpenAI API Key</th>
<td>
<input type="password" name="openai_api_key" value="<?php echo esc_attr($settings['openai_api_key']); ?>" class="regular-text">
<button type="button" id="test-btn" class="button">Test</button>
</td>
</tr>
<tr>
<th>Model</th>
<td>
<select name="openai_model">
<option value="gpt-5-nano" <?php selected($settings['openai_model'], 'gpt-5-nano'); ?>>GPT-5 Nano (Fastest & Cheapest - $0.05/$0.40)</option>
<option value="gpt-5-mini" <?php selected($settings['openai_model'], 'gpt-5-mini'); ?>>GPT-5 Mini (Fast - $0.25)</option>
<option value="gpt-5" <?php selected($settings['openai_model'], 'gpt-5'); ?>>GPT-5 (Most Capable - $1.25)</option>
<option value="gpt-4o-mini" <?php selected($settings['openai_model'], 'gpt-4o-mini'); ?>>gpt-4o-mini (Legacy - Cheap)</option>
<option value="gpt-4o" <?php selected($settings['openai_model'], 'gpt-4o'); ?>>gpt-4o (Legacy - Better)</option>
</select>
<p class="description">
<strong>Рекомендовано: GPT-5 Nano</strong> - найкраще співвідношення ціни та якості для Shopping-safe описів.<br>
Контекст: 400,000 токенів | Вихід: 128,000 токенів | Знання до: 31 травня 2024
</p>
</td>
</tr>
<tr>
<th>Batch Size</th>
<td><input type="number" name="batch_size" value="<?php echo $settings['batch_size']; ?>" min="1" max="10"></td>
</tr>
<tr>
<th>Delay (seconds)</th>
<td><input type="number" name="delay" value="<?php echo $settings['delay']; ?>" min="1" max="10"></td>
</tr>
<tr>
<th>Skip Optimized</th>
<td><input type="checkbox" name="skip_optimized" <?php checked($settings['skip_optimized']); ?>></td>
</tr>
<tr>
<th>Auto-Optimize New Products</th>
<td>
<input type="checkbox" name="auto_optimize_new" <?php checked($settings['auto_optimize_new'] ?? false); ?>>
<p class="description">Автоматично оптимізувати нові товари при створенні (потрібен ActionScheduler/WooCommerce)</p>
</td>
</tr>
<tr>
<th>Re-Optimize Updated Products</th>
<td>
<input type="checkbox" name="auto_reoptimize_updated" <?php checked($settings['auto_reoptimize_updated'] ?? false); ?>>
<p class="description">Автоматично реоптимізувати товари при оновленні</p>
</td>
</tr>
</table>

<p><button type="submit" class="button button-primary">Save</button></p>
</form>

<hr>

<h2>🔧 Utilities</h2>
<p>
<button type="button" id="flush-permalinks-btn" class="button">Flush Permalinks</button>
<span class="description">Оновлює WordPress permalink структуру після масової оптимізації. Використовуйте якщо товари показують 404 помилку.</span>
</p>

</div>

<script>
jQuery(function($){
$('#settings-form').submit(function(e){
e.preventDefault();
$.post(gmcoData.ajax_url, {
action: 'gmco_save_settings',
nonce: gmcoData.nonce,
openai_api_key: $('[name=openai_api_key]').val(),
openai_model: $('[name=openai_model]').val(),
batch_size: $('[name=batch_size]').val(),
delay: $('[name=delay]').val(),
skip_optimized: $('[name=skip_optimized]').is(':checked'),
auto_optimize_new: $('[name=auto_optimize_new]').is(':checked'),
auto_reoptimize_updated: $('[name=auto_reoptimize_updated]').is(':checked')
}, function(r){
alert(r.success ? '✅ Saved!' : '❌ Error');
});
});

$('#test-btn').click(function(){
$(this).prop('disabled',1).text('Testing...');
$.post(gmcoData.ajax_url, {
action: 'gmco_test_openai',
nonce: gmcoData.nonce,
api_key: $('[name=openai_api_key]').val()
}, function(r){
alert(r.success ? '✅ Connection OK!' : '❌ '+r.data.error);
$('#test-btn').prop('disabled',0).text('Test');
});
});

$('#flush-permalinks-btn').click(function(){
if (!confirm('Оновити permalink структуру? Це безпечна операція.')) return;
$(this).prop('disabled',1).text('Flushing...');
$.post(gmcoData.ajax_url, {
action: 'gmco_flush_permalinks',
nonce: gmcoData.nonce
}, function(r){
alert(r.success ? '✅ Permalinks оновлено!' : '❌ Помилка');
$('#flush-permalinks-btn').prop('disabled',0).text('Flush Permalinks');
});
});
});
</script>
