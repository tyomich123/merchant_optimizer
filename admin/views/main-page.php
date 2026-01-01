<?php
if (!defined('ABSPATH')) exit;

$state = get_option('gmco_state', array('status' => 'idle', 'total' => 0, 'processed' => 0, 'success' => 0, 'errors' => 0));
$stats = GMCO_Database::get_stats();
$percentage = $state['total'] > 0 ? round(($state['processed'] / $state['total']) * 100, 1) : 0;
?>

<div class="wrap">
<h1>🛒 Merchant Optimizer v2.0 WATCHDOG</h1>

<style>
.gmco-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;margin:20px 0}
.gmco-card{background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,.1)}
.gmco-card h3{margin-top:0}
.gmco-progress{background:#f0f0f0;height:30px;border-radius:15px;overflow:hidden;margin:10px 0}
.gmco-progress-bar{height:100%;background:linear-gradient(90deg,#4CAF50,#45a049);transition:width .3s}
.status-running{color:#4CAF50;font-weight:bold}
.status-idle{color:#999}
</style>

<div class="gmco-grid">
<div class="gmco-card">
<h3>📊 Статистика</h3>
<p>Всього: <strong><?php echo number_format($stats['total']); ?></strong></p>
<p>Успішно: <strong><?php echo number_format($stats['success']); ?></strong></p>
<p>Помилок: <strong><?php echo number_format($stats['errors']); ?></strong></p>
</div>

<div class="gmco-card">
<h3>⚙️ Процес</h3>
<p>Статус: <strong id="status" class="status-<?php echo $state['status']; ?>"><?php echo $state['status']; ?></strong></p>
<p><span id="processed"><?php echo $state['processed']; ?></span> / <span id="total"><?php echo $state['total']; ?></span></p>
<p>Успішно: <span id="success"><?php echo $state['success']; ?></span></p>
<div class="gmco-progress"><div id="progress-bar" class="gmco-progress-bar" style="width:<?php echo $percentage; ?>%"></div></div>
<p><strong id="percentage"><?php echo $percentage; ?>%</strong></p>
</div>
</div>

<p>
<button id="start-btn" class="button button-primary button-hero">▶️ Start</button>
<button id="stop-btn" class="button">⏹️ Stop</button>
<button id="clear-btn" class="button button-link-delete">🧹 Clear</button>
<button id="diagnostics-btn" class="button">🔍 Diagnostics</button>
<button id="force-batch-btn" class="button button-secondary">⚡ Force Batch</button>
<label><input type="checkbox" id="force-all"> All products</label>
</p>

<div class="gmco-card">
<h3>ℹ️ v2.0 WATCHDOG</h3>
<p>✅ Auto-recovery кожні 30 сек<br>
✅ Health check кожні 5 хв<br>
✅ Працює у фоні - можна закривати вкладку!</p>
</div>

<script>
jQuery(function($){
let poll;

$('#start-btn').click(function(){
if(!confirm('Start?'))return;
$(this).prop('disabled',1);
$.post(gmcoData.ajax_url,{action:'gmco_start',nonce:gmcoData.nonce,force_all:$('#force-all').is(':checked')},function(r){
if(r.success){alert('✅ Started!');startPoll();}else alert('❌ '+r.data.message);
$('#start-btn').prop('disabled',0);
});
});

$('#stop-btn').click(function(){
if(!confirm('Stop?'))return;
$.post(gmcoData.ajax_url,{action:'gmco_stop',nonce:gmcoData.nonce},function(){location.reload()});
});

$('#clear-btn').click(function(){
if(!confirm('Clear all?'))return;
$.post(gmcoData.ajax_url,{action:'gmco_force_clear',nonce:gmcoData.nonce},function(){location.reload()});
});

$('#diagnostics-btn').click(function(){
$(this).prop('disabled',1).text('Checking...');
$.post(gmcoData.ajax_url,{action:'gmco_diagnostics',nonce:gmcoData.nonce},function(r){
if(r.success){
let d=r.data;
let msg='📊 ДІАГНОСТИКА:\n\n';
msg+='WP Cron: '+(d.wp_cron_enabled?'✅ Enabled':'❌ DISABLED!')+'\n';
msg+='Batch scheduled: '+(d.cron_jobs.batch?'✅ Yes ('+new Date(d.cron_jobs.batch*1000)+')':'❌ NO!')+'\n';
msg+='Watchdog: '+(d.cron_jobs.watchdog?'✅ Yes':'❌ NO!')+'\n';
msg+='Health: '+(d.cron_jobs.health?'✅ Yes':'❌ NO!')+'\n';
msg+='Lock: '+(d.lock?'🔒 Active':'✅ Free')+'\n';
msg+='Heartbeat age: '+d.heartbeat_age+' sec\n';
msg+='Queue: '+d.queue_size+' products\n';
msg+='Status: '+d.state.status+'\n';
msg+='spawn_cron: '+(d.spawn_cron_exists?'✅ Yes':'❌ NO!')+'\n\n';
if(!d.cron_jobs.batch){
msg+='⚠️ BATCH NOT SCHEDULED!\n';
msg+='Використайте "Force Batch" для ручного запуску.';
}
alert(msg);
}
$('#diagnostics-btn').prop('disabled',0).text('🔍 Diagnostics');
});
});

$('#force-batch-btn').click(function(){
if(!confirm('Force run batch manually?\n\nThis will process 1 product immediately.'))return;
$(this).prop('disabled',1).text('Running...');
$.post(gmcoData.ajax_url,{action:'gmco_force_batch',nonce:gmcoData.nonce},function(r){
alert(r.success?'✅ Batch executed! Check logs.':'❌ Error');
$('#force-batch-btn').prop('disabled',0).text('⚡ Force Batch');
setTimeout(function(){location.reload()},1000);
});
});

function startPoll(){
poll=setInterval(update,3000);
update();
}

function update(){
$.post(gmcoData.ajax_url,{action:'gmco_status',nonce:gmcoData.nonce},function(r){
if(r.success){
let d=r.data;
$('#status').text(d.status).removeClass().addClass('status-'+d.status);
$('#processed').text(d.processed);
$('#total').text(d.total);
$('#success').text(d.success);
$('#percentage').text(d.percentage+'%');
$('#progress-bar').css('width',d.percentage+'%');
if(d.status!=='running'){clearInterval(poll);if(d.status==='completed')setTimeout(function(){location.reload()},2000);}
}
});
}

if($('#status').text().trim()==='running')startPoll();
});
</script>
</div>
