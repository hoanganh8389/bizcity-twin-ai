<?php
/**
 * PDF Studio — view page
 * Route: /tool-pdf/          → SPA (generate + history)
 * Route: /tool-pdf/print/    → raw printable HTML (handled in main plugin)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$nonce   = wp_create_nonce( 'bztool_pdf' );
$ajax_url = admin_url( 'admin-ajax.php' );

// Viewing an existing document? Show full-screen iframe viewer.
$view_id = intval( $_GET['id'] ?? 0 );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>📄 PDF Studio<?php if ($view_id) echo ' — Xem tài liệu'; ?></title>
<style>
/* ── Reset & Base ─────────────────────────────────────────────── */
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --blue:#1e3a5f;--accent:#2563EB;--text:#1f2937;
  --muted:#6b7280;--border:#e5e7eb;--bg:#f1f5f9;
  --surface:#ffffff;--danger:#dc2626;
  --font:'Inter',system-ui,sans-serif;
}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;display:flex;flex-direction:column}

/* ── Header ──────────────────────────────────────────────────── */
.studio-header{
  background:linear-gradient(135deg,var(--blue) 0%,#1d4ed8 100%);
  color:#fff;padding:14px 24px;display:flex;align-items:center;
  gap:12px;position:sticky;top:0;z-index:50;
  box-shadow:0 2px 12px rgba(0,0,0,.18);
}
.studio-header h1{font-size:1.15rem;font-weight:700;letter-spacing:-.01em}
.studio-header .back-btn{
  background:rgba(255,255,255,.18);border:none;color:#fff;
  padding:6px 14px;border-radius:8px;cursor:pointer;font-size:.85rem;font-weight:600;
  text-decoration:none;transition:background .18s;
}
.studio-header .back-btn:hover{background:rgba(255,255,255,.28)}
.studio-header .spacer{flex:1}

/* ── Main Container ──────────────────────────────────────────── */
.studio-main{flex:1;padding:28px 24px;max-width:860px;margin:0 auto;width:100%}

/* ── Tabs ────────────────────────────────────────────────────── */
.tabs{display:flex;gap:4px;background:var(--surface);border:1px solid var(--border);
       border-radius:12px;padding:4px;margin-bottom:24px}
.tab-btn{flex:1;padding:10px 16px;border:none;border-radius:9px;background:none;
          cursor:pointer;font-size:.9rem;font-weight:600;color:var(--muted);transition:all .18s}
.tab-btn.active{background:var(--accent);color:#fff;box-shadow:0 2px 8px rgba(37,99,235,.3)}
.tab-btn:hover:not(.active){background:#f1f5f9;color:var(--text)}
.tab-panel{display:none}
.tab-panel.active{display:block}

/* ── Form card ───────────────────────────────────────────────── */
.form-card{background:var(--surface);border-radius:16px;padding:28px;
            border:1px solid var(--border);box-shadow:0 1px 6px rgba(0,0,0,.05)}
.form-card label{display:block;font-weight:600;font-size:.88rem;margin-bottom:6px;color:#374151}
.form-card textarea{
  width:100%;min-height:130px;resize:vertical;
  border:1.5px solid var(--border);border-radius:10px;padding:12px 14px;
  font-family:var(--font);font-size:.95rem;color:var(--text);line-height:1.6;
  transition:border-color .18s;
}
.form-card textarea:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(37,99,235,.12)}

/* ── Doc type chips ─────────────────────────────────────────── */
.chips{display:flex;flex-wrap:wrap;gap:8px;margin:14px 0 20px}
.chip{
  padding:6px 16px;border:1.5px solid var(--border);border-radius:50px;
  background:var(--surface);cursor:pointer;font-size:.83rem;font-weight:600;
  color:var(--muted);transition:all .18s;user-select:none;
}
.chip:hover{border-color:var(--accent);color:var(--accent)}
.chip.selected{border-color:var(--accent);background:var(--accent);color:#fff}

/* ── Generate button ─────────────────────────────────────────── */
.btn-generate{
  display:flex;align-items:center;justify-content:center;gap:10px;
  width:100%;padding:14px;border:none;border-radius:12px;
  background:linear-gradient(135deg,var(--blue),var(--accent));
  color:#fff;font-size:1rem;font-weight:700;cursor:pointer;
  box-shadow:0 4px 14px rgba(37,99,235,.35);transition:all .2s;
}
.btn-generate:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(37,99,235,.45)}
.btn-generate:disabled{opacity:.55;cursor:not-allowed;transform:none}

/* ── Progress ────────────────────────────────────────────────── */
.progress-area{display:none;margin-top:24px;background:var(--surface);
                border-radius:14px;padding:20px;border:1px solid var(--border);text-align:center}
.spinner{
  width:36px;height:36px;border:4px solid var(--border);
  border-top-color:var(--accent);border-radius:50%;
  animation:spin .8s linear infinite;margin:0 auto 12px;
}
@keyframes spin{to{transform:rotate(360deg)}}
.progress-msg{color:var(--muted);font-size:.9rem}

/* ── Result area ─────────────────────────────────────────────── */
.result-area{display:none;margin-top:24px}
.result-header{
  display:flex;align-items:center;gap:12px;margin-bottom:14px;
  background:var(--surface);border-radius:14px;padding:16px 20px;
  border:1px solid var(--border);
}
.result-header .doc-title{flex:1;font-weight:700;color:var(--text);font-size:1rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.btn-print{
  display:flex;align-items:center;gap:6px;padding:10px 22px;
  background:linear-gradient(135deg,#065f46,#059669);color:#fff;
  border:none;border-radius:10px;font-weight:700;font-size:.9rem;cursor:pointer;
  box-shadow:0 4px 12px rgba(5,150,105,.35);transition:all .18s;white-space:nowrap;
}
.btn-print:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(5,150,105,.45)}
.btn-new{
  padding:10px 18px;background:var(--bg);border:1.5px solid var(--border);
  border-radius:10px;font-weight:600;font-size:.88rem;cursor:pointer;
  color:var(--text);transition:all .18s;white-space:nowrap;
}
.btn-new:hover{border-color:var(--accent);color:var(--accent)}
/* iframe preview */
.doc-iframe{
  width:100%;height:75vh;min-height:540px;border:none;border-radius:14px;
  box-shadow:0 4px 24px rgba(0,0,0,.12);background:#fff;
}

/* ── Error ───────────────────────────────────────────────────── */
.error-box{
  margin-top:20px;background:#FEF2F2;border:1px solid #FECACA;border-radius:12px;
  padding:16px 20px;color:var(--danger);font-size:.9rem;display:none;
}

/* ── History ─────────────────────────────────────────────────── */
.history-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px}
.history-card{
  background:var(--surface);border:1.5px solid var(--border);border-radius:14px;
  padding:18px;cursor:pointer;transition:all .18s;
}
.history-card:hover{border-color:var(--accent);box-shadow:0 4px 16px rgba(37,99,235,.12)}
.history-card .hcard-icon{font-size:1.5rem;margin-bottom:8px}
.history-card .hcard-title{font-weight:700;font-size:.92rem;color:var(--text);margin-bottom:4px;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.history-card .hcard-meta{font-size:.78rem;color:var(--muted)}
.history-card .hcard-type{
  display:inline-block;margin-top:8px;font-size:.75rem;font-weight:600;
  padding:3px 10px;border-radius:50px;background:#EFF6FF;color:var(--accent)
}
.history-card .hcard-actions{display:flex;gap:8px;margin-top:12px}
.hcard-btn{
  flex:1;padding:7px 0;border:1.5px solid var(--border);border-radius:8px;
  background:none;font-size:.8rem;font-weight:600;cursor:pointer;color:var(--muted);
  transition:all .18s;
}
.hcard-btn:hover{border-color:var(--accent);color:var(--accent)}
.hcard-btn.del:hover{border-color:var(--danger);color:var(--danger)}
.history-empty{text-align:center;padding:60px 20px;color:var(--muted)}
.history-empty .icon{font-size:2.5rem;margin-bottom:12px}
.load-more{
  margin-top:20px;width:100%;padding:11px;border:1.5px dashed var(--border);
  border-radius:10px;background:none;cursor:pointer;color:var(--muted);font-size:.88rem;
  font-weight:600;transition:border-color .18s;
}
.load-more:hover{border-color:var(--accent);color:var(--accent)}

/* ── View mode (when ?id= is passed) ────────────────────────── */
.view-mode .studio-main{max-width:100%;padding:0}
.view-mode .viewer-toolbar{
  display:flex;align-items:center;gap:12px;padding:12px 24px;
  background:var(--surface);border-bottom:1px solid var(--border);
}
.view-mode .viewer-toolbar .v-title{flex:1;font-weight:700;font-size:.95rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.viewer-iframe{width:100%;height:calc(100vh - 120px);border:none;background:#fff}

/* ── Responsive ──────────────────────────────────────────────── */
@media(max-width:600px){
  .studio-main{padding:16px}
  .result-header{flex-wrap:wrap}
  .doc-iframe{height:60vh}
  .chips{gap:6px}
  .chip{font-size:.78rem;padding:5px 12px}
}
</style>
</head>
<body<?php echo $view_id ? ' class="view-mode"' : ''; ?>>

<?php if ( $view_id ) : ?>
<!-- ═══════════════════════════════════════════════
     VIEW MODE: Full-screen document viewer
     ═══════════════════════════════════════════════ -->
<div class="studio-header">
  <a href="<?php echo esc_url( home_url( '/tool-pdf/' ) ); ?>" class="back-btn">← Quay lại</a>
  <h1>📄 PDF Studio</h1>
  <div class="spacer"></div>
</div>
<div class="viewer-toolbar">
  <span class="v-title" id="vDocTitle">Đang tải…</span>
  <button class="btn-print" id="vBtnPrint">🖨 In / Lưu PDF</button>
</div>
<iframe class="viewer-iframe" id="viewerIframe" title="PDF Document Viewer"></iframe>

<script>
(function(){
  var nonce='<?php echo esc_js($nonce); ?>';
  var ajaxUrl='<?php echo esc_js($ajax_url); ?>';
  var postId=<?php echo $view_id; ?>;

  function fetchDoc(){
    var fd=new FormData();
    fd.append('action','bztool_pdf_get');
    fd.append('nonce',nonce);
    fd.append('post_id',postId);
    fetch(ajaxUrl,{method:'POST',body:fd})
      .then(r=>r.json()).then(function(res){
        if(!res.success){document.getElementById('vDocTitle').textContent='Không tìm thấy tài liệu.';return;}
        var d=res.data;
        document.getElementById('vDocTitle').textContent=d.title||'Tài liệu';
        document.title='📄 '+( d.title||'Tài liệu' );
        if(d.html){
          var iframe=document.getElementById('viewerIframe');
          iframe.srcdoc=d.html;
        }
      });
  }

  document.getElementById('vBtnPrint').addEventListener('click',function(){
    window.open('<?php echo esc_url(home_url("/tool-pdf/print/?id=")); ?>'+postId,'_blank');
  });

  fetchDoc();
})();
</script>

<?php else : ?>
<!-- ═══════════════════════════════════════════════
     SPA MODE: Generate + History
     ═══════════════════════════════════════════════ -->
<div class="studio-header">
  <span>📄</span>
  <h1>PDF Studio</h1>
  <div class="spacer"></div>
</div>
<div class="studio-main">

  <!-- Tabs -->
  <div class="tabs">
    <button class="tab-btn active" data-tab="generate">✨ Tạo mới</button>
    <button class="tab-btn"        data-tab="history" >📂 Lịch sử</button>
  </div>

  <!-- Tab: Generate -->
  <div class="tab-panel active" id="tab-generate">
    <div class="form-card">
      <label for="promptInput">Mô tả tài liệu bạn muốn tạo</label>
      <textarea id="promptInput" placeholder="Ví dụ: Báo cáo phân tích thị trường thương mại điện tử Việt Nam 2024 — xu hướng, thị phần và cơ hội…"></textarea>

      <label style="margin-top:18px">Loại tài liệu</label>
      <div class="chips" id="docTypeChips">
        <span class="chip selected" data-val="auto">🤖 Tự động</span>
        <span class="chip" data-val="report">📊 Báo cáo</span>
        <span class="chip" data-val="academic">🎓 Học thuật</span>
        <span class="chip" data-val="technical">⚙️ Kỹ thuật</span>
        <span class="chip" data-val="proposal">📋 Đề xuất</span>
        <span class="chip" data-val="summary">📌 Tóm tắt</span>
        <span class="chip" data-val="guide">📖 Hướng dẫn</span>
      </div>

      <button class="btn-generate" id="btnGenerate">
        <span>✨</span><span id="btnGenerateLbl">Tạo tài liệu PDF</span>
      </button>
    </div>

    <!-- Progress -->
    <div class="progress-area" id="progressArea">
      <div class="spinner"></div>
      <p class="progress-msg" id="progressMsg">Claude Sonnet 4.5 đang soạn tài liệu…<br>Thường mất 30–90 giây.</p>
    </div>

    <!-- Error -->
    <div class="error-box" id="errorBox"></div>

    <!-- Result -->
    <div class="result-area" id="resultArea">
      <div class="result-header">
        <span style="font-size:1.4rem">📄</span>
        <span class="doc-title" id="resultTitle">Tài liệu mới</span>
        <button class="btn-new" id="btnNew">← Tạo mới</button>
        <button class="btn-print" id="btnPrint">🖨 In / Lưu PDF</button>
      </div>
      <iframe class="doc-iframe" id="docIframe" title="Generated PDF Document"></iframe>
    </div>
  </div>

  <!-- Tab: History -->
  <div class="tab-panel" id="tab-history">
    <div class="history-grid" id="historyGrid"></div>
    <button class="load-more" id="loadMoreBtn" style="display:none">Tải thêm…</button>
    <div class="history-empty" id="historyEmpty" style="display:none">
      <div class="icon">📄</div>
      <p>Chưa có tài liệu nào.<br>Hãy tạo tài liệu đầu tiên!</p>
    </div>
  </div>

</div><!-- /.studio-main -->

<script>
(function(){
  /* ── Config ──────────────────────────────────────────────── */
  var nonce='<?php echo esc_js($nonce); ?>';
  var ajaxUrl='<?php echo esc_js($ajax_url); ?>';

  /* ── State ───────────────────────────────────────────────── */
  var selectedDocType='auto';
  var currentPostId=null;
  var currentHtml='';
  var jobPollTimer=null;
  var historyPage=1;
  var historyLoaded=false;

  /* ── Elements ────────────────────────────────────────────── */
  var promptInput=document.getElementById('promptInput');
  var btnGenerate=document.getElementById('btnGenerate');
  var btnGenerateLbl=document.getElementById('btnGenerateLbl');
  var progressArea=document.getElementById('progressArea');
  var progressMsg=document.getElementById('progressMsg');
  var errorBox=document.getElementById('errorBox');
  var resultArea=document.getElementById('resultArea');
  var resultTitle=document.getElementById('resultTitle');
  var docIframe=document.getElementById('docIframe');
  var btnPrint=document.getElementById('btnPrint');
  var btnNew=document.getElementById('btnNew');
  var historyGrid=document.getElementById('historyGrid');
  var historyEmpty=document.getElementById('historyEmpty');
  var loadMoreBtn=document.getElementById('loadMoreBtn');

  /* ── Tabs ────────────────────────────────────────────────── */
  document.querySelectorAll('.tab-btn').forEach(function(btn){
    btn.addEventListener('click',function(){
      document.querySelectorAll('.tab-btn').forEach(function(b){b.classList.remove('active')});
      document.querySelectorAll('.tab-panel').forEach(function(p){p.classList.remove('active')});
      btn.classList.add('active');
      document.getElementById('tab-'+btn.dataset.tab).classList.add('active');
      if(btn.dataset.tab==='history' && !historyLoaded){loadHistory();}
    });
  });

  /* ── Doc type chips ──────────────────────────────────────── */
  document.querySelectorAll('#docTypeChips .chip').forEach(function(chip){
    chip.addEventListener('click',function(){
      document.querySelectorAll('#docTypeChips .chip').forEach(function(c){c.classList.remove('selected')});
      chip.classList.add('selected');
      selectedDocType=chip.dataset.val;
    });
  });

  /* ── Generate ────────────────────────────────────────────── */
  btnGenerate.addEventListener('click',function(){
    var prompt=promptInput.value.trim();
    if(!prompt){promptInput.focus();shakeElement(promptInput);return;}
    startGenerate(prompt,selectedDocType);
  });

  function startGenerate(prompt,docType){
    btnGenerate.disabled=true;
    btnGenerateLbl.textContent='Đang xử lý…';
    progressArea.style.display='block';
    resultArea.style.display='none';
    errorBox.style.display='none';
    progressMsg.innerHTML='Claude Sonnet 4.5 đang soạn tài liệu…<br>Thường mất 30–90 giây.';

    var fd=new FormData();
    fd.append('action','bztool_pdf_generate');
    fd.append('nonce',nonce);
    fd.append('prompt',prompt);
    fd.append('doc_type',docType);

    fetch(ajaxUrl,{method:'POST',body:fd})
      .then(function(r){return r.json();})
      .then(function(res){
        if(!res.success){showError(res.data&&res.data.message?res.data.message:'Lỗi không xác định.');return;}
        pollJob(res.data.job_id,0);
      })
      .catch(function(e){showError('Lỗi kết nối: '+e.message);});
  }

  function pollJob(jobId,attempts){
    if(attempts>120){showError('Quá thời gian chờ. Vui lòng thử lại.');return;}
    var msgs=['Claude đang soạn tài liệu…','Đang xây dựng cấu trúc…','Đang viết nội dung…','Đang tối ưu layout…','Sắp xong…'];
    progressMsg.innerHTML=msgs[Math.min(Math.floor(attempts/8),msgs.length-1)]+'<br><small style="color:#9ca3af">'+Math.round(attempts*2.5)+'s / ~90s</small>';

    var fd=new FormData();
    fd.append('action','bztool_pdf_generate_status');
    fd.append('nonce',nonce);
    fd.append('job_id',jobId);

    jobPollTimer=setTimeout(function(){
      fetch(ajaxUrl,{method:'POST',body:fd})
        .then(function(r){return r.json();})
        .then(function(res){
          if(!res.success){showError('Không lấy được trạng thái job.');return;}
          var job=res.data;
          if(job.status==='completed'){
            showResult(job);
          } else if(job.status==='failed'){
            showError(job.message||'Tạo tài liệu thất bại.');
          } else {
            pollJob(jobId,attempts+1);
          }
        })
        .catch(function(){pollJob(jobId,attempts+1);});
    },2500);
  }

  function showResult(job){
    progressArea.style.display='none';
    btnGenerate.disabled=false;
    btnGenerateLbl.textContent='Tạo tài liệu PDF';
    currentPostId=job.post_id;

    resultTitle.textContent=job.title||'Tài liệu mới';
    resultArea.style.display='block';

    // Load doc in iframe via AJAX (get full HTML)
    var fd=new FormData();
    fd.append('action','bztool_pdf_get');
    fd.append('nonce',nonce);
    fd.append('post_id',job.post_id);
    fetch(ajaxUrl,{method:'POST',body:fd})
      .then(function(r){return r.json();})
      .then(function(res){
        if(res.success&&res.data.html){
          currentHtml=res.data.html;
          docIframe.srcdoc=res.data.html;
        }
      });

    resultArea.scrollIntoView({behavior:'smooth',block:'start'});

    // Refresh history in background
    historyLoaded=false;
  }

  /* ── Print / Save PDF ───────────────────────────────────── */
  btnPrint.addEventListener('click',function(){
    if(!currentPostId){return;}
    window.open('<?php echo esc_js(home_url('/tool-pdf/print/?id=')); ?>'+currentPostId,'_blank');
  });

  btnNew.addEventListener('click',function(){
    resultArea.style.display='none';
    progressArea.style.display='none';
    errorBox.style.display='none';
    promptInput.value='';
    promptInput.focus();
    if(jobPollTimer){clearTimeout(jobPollTimer);}
    btnGenerate.disabled=false;
    btnGenerateLbl.textContent='Tạo tài liệu PDF';
  });

  /* ── History ─────────────────────────────────────────────── */
  function loadHistory(reset){
    if(reset){historyPage=1;historyGrid.innerHTML='';}
    var fd=new FormData();
    fd.append('action','bztool_pdf_list');
    fd.append('nonce',nonce);
    fd.append('page',historyPage);
    fetch(ajaxUrl,{method:'POST',body:fd})
      .then(function(r){return r.json();})
      .then(function(res){
        historyLoaded=true;
        if(!res.success){return;}
        var items=res.data.items||[];
        if(items.length===0 && historyPage===1){
          historyEmpty.style.display='block';
          loadMoreBtn.style.display='none';
          return;
        }
        historyEmpty.style.display='none';
        items.forEach(function(item){
          historyGrid.appendChild(buildHistoryCard(item));
        });
        loadMoreBtn.style.display=(res.data.pages>historyPage)?'block':'none';
      });
  }

  loadMoreBtn.addEventListener('click',function(){
    historyPage++;
    loadHistory(false);
  });

  function buildHistoryCard(item){
    var typeIcons={report:'📊',academic:'🎓',technical:'⚙️',proposal:'📋',summary:'📌',guide:'📖',auto:'📄'};
    var typeLabels={report:'Báo cáo',academic:'Học thuật',technical:'Kỹ thuật',proposal:'Đề xuất',summary:'Tóm tắt',guide:'Hướng dẫn',auto:'Tài liệu'};
    var t=item.doc_type||'auto';
    var card=document.createElement('div');
    card.className='history-card';
    card.innerHTML='<div class="hcard-icon">'+( typeIcons[t]||'📄' )+'</div>'
      +'<div class="hcard-title">'+escHtml(item.title)+'</div>'
      +'<div class="hcard-meta">'+escHtml(item.date)+'</div>'
      +'<span class="hcard-type">'+escHtml(typeLabels[t]||'Tài liệu')+'</span>'
      +'<div class="hcard-actions">'
        +'<button class="hcard-btn view-btn">👁 Xem</button>'
        +'<button class="hcard-btn del del-btn">🗑 Xoá</button>'
      +'</div>';

    card.querySelector('.view-btn').addEventListener('click',function(e){
      e.stopPropagation();
      window.location.href=item.url;
    });
    card.addEventListener('click',function(){window.location.href=item.url;});
    card.querySelector('.del-btn').addEventListener('click',function(e){
      e.stopPropagation();
      if(!confirm('Xoá tài liệu "'+item.title+'"?'))return;
      deleteDoc(item.id,card);
    });
    return card;
  }

  function deleteDoc(postId,cardEl){
    var fd=new FormData();
    fd.append('action','bztool_pdf_delete');
    fd.append('nonce',nonce);
    fd.append('post_id',postId);
    fetch(ajaxUrl,{method:'POST',body:fd})
      .then(function(r){return r.json();})
      .then(function(res){
        if(res.success){
          cardEl.style.opacity='0';cardEl.style.transition='opacity .3s';
          setTimeout(function(){
            cardEl.remove();
            if(!historyGrid.querySelector('.history-card')){
              historyEmpty.style.display='block';
            }
          },300);
        }
      });
  }

  /* ── Helpers ─────────────────────────────────────────────── */
  function showError(msg){
    progressArea.style.display='none';
    btnGenerate.disabled=false;
    btnGenerateLbl.textContent='Tạo tài liệu PDF';
    errorBox.textContent='❌ '+msg;
    errorBox.style.display='block';
  }

  function escHtml(s){
    if(!s)return'';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  function shakeElement(el){
    el.style.animation='none';
    el.offsetHeight; // reflow
    el.style.animation='shake .35s';
  }

  /* ── Keyboard shortcut: Ctrl+Enter ─────────────────────── */
  promptInput.addEventListener('keydown',function(e){
    if(e.key==='Enter'&&(e.ctrlKey||e.metaKey)){btnGenerate.click();}
  });

})();
</script>
<style>
@keyframes shake{
  0%,100%{transform:translateX(0)}
  20%{transform:translateX(-6px)}
  40%{transform:translateX(6px)}
  60%{transform:translateX(-4px)}
  80%{transform:translateX(4px)}
}
</style>
<?php endif; ?>
</body>
</html>
