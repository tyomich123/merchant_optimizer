<?php
if (!defined('ABSPATH')) exit;

$dates = GMCO_Logger::get_available_dates();
$selected_date = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : date('Y-m-d');
$logs = GMCO_Logger::get_logs($selected_date);
?>

<div class="wrap">
<h1>📋 Logs</h1>

<p>
<select id="log-date">
<?php foreach ($dates as $date): ?>
<option value="<?php echo esc_attr($date); ?>" <?php selected($date, $selected_date); ?>><?php echo esc_html($date); ?></option>
<?php endforeach; ?>
</select>
<button id="refresh-btn" class="button">Refresh</button>
</p>

<pre style="background:#000;color:#0f0;padding:20px;max-height:600px;overflow:auto;font-family:monospace;"><?php 
echo empty($logs) ? 'No logs for this date' : esc_html($logs); 
?></pre>
</div>

<script>
jQuery(function($){
$('#log-date').change(function(){
location.href = '?page=gmco-logs&date=' + $(this).val();
});
$('#refresh-btn').click(function(){
location.reload();
});
});
</script>
