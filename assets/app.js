/* Smart Image Optimizer v4.0 */
(function($){'use strict';

const App = {
  page:1, totalPages:1,
  selected: new Set(),
  visible:  [],
  catSelected: new Set(),   // separate selection for categories tab

  init() {
    this.bindTabs(); this.bindVideos();
    this.bindBrowse();
    this.bindBulk();
    this.bindUnused();
    this.bindCats();
    this.bindAudit();
    this.bindSettings();
    this.bindModal();
    this.loadStats();
    this.loadImages();
  },

  /* ── Tabs ── */
  bindTabs() {
    $('.tab').on('click', function(){
      $('.tab').removeClass('active'); $('.pane').removeClass('active');
      $(this).addClass('active'); $('#tab-'+$(this).data('tab')).addClass('active');
      const t=$(this).data('tab');
      if (t==='bulk')   App.updateBulkCount();
      if (t==='cats')   App.updateCatCount();
      if (t==='videos') App.loadVideos(1);
    });
  },

  /* ── Stats ── */
  loadStats() {
    $.post(SIO.ajax, {action:'sio_get_stats',nonce:SIO.nonce}, r=>{
      if (!r.success) return;
      const d=r.data;
      $('#sio-stats').html(`<span class="stat">📸 Total: <strong>${d.total.toLocaleString()}</strong></span><span class="stat">⚠️ Bad names: <strong>${d.bad}</strong>/${d.sample}</span>`);
    });
  },

  /* ── Browse ── */
  bindBrowse() {
    let t;
    $('#search').on('input', ()=>{ clearTimeout(t); t=setTimeout(()=>this.loadImages(1),400); });
    $('#filter,#per-page').on('change', ()=>this.loadImages(1));
    $('#load-btn').on('click', ()=>this.loadImages(1));
    $('#sel-all').on('change', function(){
      App.visible.forEach(id=>$(this).is(':checked')?App.selected.add(id):App.selected.delete(id));
      App.refreshSel(); App.updateBulkCount(); App.updateCatCount();
      $('#sel-count').text(App.selected.size?`${App.selected.size} selected`:'');
    });
    $(document).on('click','.pg-btn', function(){ App.loadImages(+$(this).data('p')); });
    $(document).on('click','.card', function(e){
      if ($(e.target).closest('.card-actions').length) return;
      const id=+$(this).data('id');
      App.selected.has(id)?App.selected.delete(id):App.selected.add(id);
      $(this).toggleClass('selected',App.selected.has(id));
      App.updateBulkCount(); App.updateCatCount();
      $('#sel-count').text(App.selected.size?`${App.selected.size} selected`:'');
    });
    $(document).on('click','.btn-ai',  e=>{ e.stopPropagation(); App.aiRenameCard(+$(e.currentTarget).data('id')); });
    $(document).on('click','.btn-ren', e=>{ e.stopPropagation(); App.openManualModal(+$(e.currentTarget).data('id')); });
    $(document).on('click','.btn-res', e=>{ e.stopPropagation(); App.openResizeModal(+$(e.currentTarget).data('id')); });
  },

  loadImages(page=1) {
    this.page=page;
    $('#grid').html('<div class="loading"><div class="spinner"></div>Loading...</div>');
    $.post(SIO.ajax, {action:'sio_get_images',nonce:SIO.nonce,page,per_page:$('#per-page').val()||24,search:$('#search').val()||'',filter:$('#filter').val()||'all'}, r=>{
      if (!r.success) { $('#grid').html('<div class="loading">Error loading images</div>'); return; }
      const {images,total,total_pages}=r.data;
      this.totalPages=total_pages; this.visible=images.map(i=>i.id);
      if (!images.length) { $('#grid').html('<div class="loading">No images found</div>'); $('#pagination').empty(); return; }
      $('#grid').html(images.map(i=>this.cardHTML(i)).join(''));
      this.refreshSel(); this.renderPagination(page,total_pages,total);
    });
  },

  cardHTML(img) {
    return `<div class="card${img.bad_name?' bad':''}" data-id="${img.id}">
      <div class="thumb-wrap"><img src="${img.thumb||''}" loading="lazy" alt="">
        <div class="sel-dot">✓</div>${img.bad_name?'<span class="bad-tag">⚠ name</span>':''}
      </div>
      <div class="card-info">
        <div class="card-fn">${this.esc(img.filename)}</div>
        <div class="card-meta"><span>${img.width}×${img.height}</span><span>${img.size_kb}KB</span></div>
      </div>
      <div class="card-actions">
        <button class="btn ai-btn btn-ai" data-id="${img.id}" title="AI rename">🤖</button>
        <button class="btn secondary btn-ren" data-id="${img.id}" title="Manual rename">✏</button>
        <button class="btn secondary btn-res" data-id="${img.id}" title="Resize">📐</button>
      </div>
    </div>`;
  },

  refreshSel() {
    $('.card').each(function(){ $(this).toggleClass('selected', App.selected.has(+$(this).data('id'))); });
  },

  renderPagination(page,total_pages,total) {
    const $p=$('#pagination').empty();
    if (total_pages<=1) { $p.html(`<span style="color:var(--muted);font-size:12px;">${total} images</span>`); return; }
    if (page>1) $p.append(`<button class="pg-btn" data-p="${page-1}">‹</button>`);
    for(let i=Math.max(1,page-2);i<=Math.min(total_pages,page+2);i++)
      $p.append(`<button class="pg-btn ${i===page?'active':''}" data-p="${i}">${i}</button>`);
    if (page<total_pages) $p.append(`<button class="pg-btn" data-p="${page+1}">›</button>`);
    $p.append(`<span style="color:var(--muted);font-size:12px;align-self:center;">${total} images</span>`);
  },

  updateBulkCount() {
    const n=this.selected.size;
    if (!n) { $('#bulk-count').attr('class','notice info').text('No images selected — go to Images tab first'); $('#bulk-run').prop('disabled',true); return; }
    $('#bulk-count').attr('class','notice success').text(`✅ ${n} image${n>1?'s':''} selected`);
    $('#bulk-run').prop('disabled', !SIO.has_key);
  },

  /* ── AI rename single card ── */
  async aiRenameCard(id) {
    if (!SIO.has_key) { alert('Add an API key in Settings first'); return; }
    const $c=$(`.card[data-id="${id}"]`);
    $c.find('.thumb-wrap').append('<div class="card-ai-overlay"><div class="ai-spin"></div><span>AI analyzing...</span></div>');
    $c.find('.btn').prop('disabled',true);
    const r=await this.post({action:'sio_ai_name',nonce:SIO.nonce,id});
    $c.find('.card-ai-overlay').remove(); $c.find('.btn').prop('disabled',false);
    if (!r.success) { alert('AI error: '+r.data); return; }
    this.openAIConfirmModal(id, r.data.suggested, $c.find('img').attr('src'), $c.find('.card-fn').text());
  },

  openAIConfirmModal(id,suggested,thumb,oldFn) {
    $('#mbody').html(`
      <h2 style="margin:0 0 14px;font-size:17px;">🤖 AI Rename</h2>
      <div class="modal-img-row"><img src="${thumb}" class="modal-thumb"><div><strong>${this.esc(oldFn)}</strong><p style="margin:4px 0 0;font-size:12px;color:var(--muted)">Current filename</p></div></div>
      <p style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;margin:0 0 5px">AI suggested name</p>
      <div class="ai-preview"><span class="ai-lbl">🤖</span><span class="ai-nm">${this.esc(suggested)}</span></div>
      <p style="font-size:12px;color:var(--muted);margin:4px 0 10px">You can edit before saving:</p>
      <input type="text" id="m-new-name" value="${this.esc(suggested)}" style="width:100%;padding:9px;border:1px solid var(--border);border-radius:7px;font-size:13px;font-family:monospace;margin-bottom:4px">
      <p style="font-size:11px;color:var(--muted);margin:0 0 12px">Extension added automatically · All links updated sitewide</p>
      <div class="modal-actions">
        <button class="btn ai-btn" id="m-save-rename" data-id="${id}">✅ Save &amp; Update Links</button>
        <button class="btn secondary mclose">Cancel</button>
      </div><div id="m-result" style="margin-top:10px"></div>`);
    this.openModal();
  },

  openManualModal(id) {
    const $c=$(`.card[data-id="${id}"]`), fn=$c.find('.card-fn').text(), thumb=$c.find('img').attr('src');
    $('#mbody').html(`
      <h2 style="margin:0 0 14px;font-size:17px;">✏ Manual Rename</h2>
      <div class="modal-img-row"><img src="${thumb}" class="modal-thumb"><div><strong>${this.esc(fn)}</strong></div></div>
      <div class="fg" style="margin-bottom:12px"><label>New name (without extension)</label>
        <input type="text" id="m-new-name" placeholder="e.g. red-leather-wallet" style="padding:9px;border:1px solid var(--border);border-radius:7px;font-size:13px;"></div>
      <div class="modal-actions">
        <button class="btn success" id="m-save-rename" data-id="${id}">💾 Save</button>
        <button class="btn secondary mclose">Cancel</button>
      </div><div id="m-result" style="margin-top:10px"></div>`);
    this.openModal();
  },

  openResizeModal(id) {
    const $c=$(`.card[data-id="${id}"]`), fn=$c.find('.card-fn').text(), thumb=$c.find('img').attr('src');
    const dims=$c.find('.card-meta span:first').text().split('×');
    $('#mbody').html(`
      <h2 style="margin:0 0 14px;font-size:17px;">📐 Resize Image</h2>
      <div class="modal-img-row"><img src="${thumb}" class="modal-thumb"><div><strong>${this.esc(fn)}</strong><p style="margin:3px 0 0;font-size:12px;color:var(--muted)">${dims[0]?.trim()||'?'} × ${dims[1]?.trim()||'?'} px</p></div></div>
      <div class="form-row" style="margin-bottom:14px">
        <div class="fg"><label>Mode</label><select id="m-mode" onchange="App.toggleResizeFields(this.value)"><option value="fixed_height">Fixed height (uniform) ✅</option><option value="max_width">Max width (keep ratio)</option><option value="crop">Crop to exact size</option><option value="resize">Fixed width &amp; height</option></select></div>
        <div class="fg" id="m-h-wrap"><label>Target height (px)</label><input type="number" id="m-h" value="800"></div>
        <div class="fg" id="m-w-wrap" style="display:none"><label>Width (px)</label><input type="number" id="m-w" value="1200"></div>
      </div>
      <div class="modal-actions">
        <button class="btn success" id="m-save-resize" data-id="${id}">📐 Apply</button>
        <button class="btn secondary mclose">Cancel</button>
      </div><div id="m-result" style="margin-top:10px"></div>`);
    this.openModal();
  },

  /* ── Bulk ── */
  bindBulk() {
    $('input[name=op]').on('change', function(){
      const v=$(this).val();
      $('.op-card').removeClass('active'); $(this).closest('.op-card').addClass('active');
      $('#ren-opts').toggle(v!=='resize');
      $('#res-opts').toggle(v!=='rename');
    });
    $('#bulk-run').on('click', ()=>this.runBulk());
    $('#b-rmode').on('change', function(){ App.toggleResizeFields($(this).val()); });
  },

  async runBulk() {
    const ids=[...this.selected];
    if (!ids.length||!SIO.has_key) return;
    const op=$('input[name=op]:checked').val()||'rename';
    const label={'rename':'Rename','resize':'Resize','both':'Rename + Resize'}[op];
    if (!confirm(`${label} ${ids.length} image${ids.length>1?'s':''}?\n\n⚠ This cannot be undone. Make sure you have a backup.`)) return;

    const BATCH=5;
    let done=0,ok=0,fail=0,links=0;
    $('#bulk-prog').show(); $('#bulk-run').prop('disabled',true).text('⏳ Running...');
    $('#bulk-log').empty(); $('#bulk-sum').hide();

    for (let i=0;i<ids.length;i+=BATCH) {
      const batch=ids.slice(i,i+BATCH);
      const r=await this.post({
        action:'sio_bulk',nonce:SIO.nonce,ids:batch,operation:op,
        prefix:$('#b-prefix').val().trim(),
        max_width:parseInt($('#b-maxw').val())||0,
        max_height:parseInt($('#b-maxh').val())||0,
        resize_mode:$('#b-rmode').val()||'fixed_height',
      });
      done+=batch.length;
      const pct=Math.round(done/ids.length*100);
      $('#prog-fill').css('width',pct+'%'); $('#prog-pct').text(pct+'%');
      $('#prog-lbl').text(`Processing ${done} / ${ids.length}...`);
      if (r.success) {
        ok+=r.data.success; fail+=r.data.failed; links+=r.data.replaced||0;
        r.data.details.forEach(l=>{
          const cls=l.startsWith('✅')?'ok':l.startsWith('⏭')?'skip':'fail';
          $('#bulk-log').append(`<div class="${cls}">${l}</div>`);
        });
        $('#bulk-log').scrollTop(1e9);
      }
      await this.sleep(op==='rename'||op==='both'?1200:200);
    }

    $('#bulk-run').prop('disabled',false).text('▶ Run');
    $('#bulk-sum').show().html(`🏁 Done — ✅${ok} succeeded &nbsp;|&nbsp; ❌${fail} failed &nbsp;|&nbsp; <span class="lnk-badge">🔗${links} links updated</span>`);
    this.loadImages(this.page); this.loadStats(); this.selected.clear(); this.updateBulkCount();
    $('#sel-count').text('');
  },

  /* ── Unused ── */
  bindUnused() {
    $('#scan-unused').on('click', async ()=>{
      $('#scan-unused').prop('disabled',true).text('⏳ Scanning...');
      $('#unused-out').html('<div class="loading"><div class="spinner"></div>Scanning all images — this may take a moment...</div>');
      const r=await this.post({action:'sio_scan_unused',nonce:SIO.nonce});
      $('#scan-unused').prop('disabled',false).text('🔍 Scan for unused images');
      if (!r.success) { $('#unused-out').html('<div class="notice warn">Error: '+r.data+'</div>'); return; }
      const {unused,count,total_mb}=r.data;
      if (!count) { $('#unused-out').html('<div class="notice success">✅ No unused images found! All images are referenced somewhere on your site.</div>'); return; }
      const ids=unused.map(u=>u.id);
      let html=`<div class="notice warn">⚠ Found <strong>${count}</strong> unused images totaling <strong>${total_mb} MB</strong> — review carefully before deleting!</div>
        <div style="display:flex;gap:10px;align-items:center;margin-bottom:14px;flex-wrap:wrap;">
          <button class="btn danger" id="del-unused" data-ids='${JSON.stringify(ids)}' style="background:var(--danger);color:#fff">🗑 Delete all ${count} images</button>
          <span style="font-size:12px;color:var(--muted)">⚠ This permanently deletes files and database records</span>
        </div><div class="unused-grid">`;
      unused.forEach(u=>{ html+=`<div class="unused-card"><div class="thumb-wrap"><img src="${u.thumb||''}" loading="lazy" alt=""></div><div class="card-info"><div class="card-fn">${this.esc(u.filename)}</div><div class="card-meta"><span>${u.size_kb}KB</span></div></div></div>`; });
      html+='</div>';
      $('#unused-out').html(html);
      $('#del-unused').on('click', async function(){
        if (!confirm(`Permanently delete ${count} unused images?\n\nThis CANNOT be undone!`)) return;
        $(this).prop('disabled',true).text('⏳ Deleting...');
        const r2=await App.post({action:'sio_delete_unused',nonce:SIO.nonce,ids:JSON.parse($(this).data('ids'))});
        if (r2.success) { $('#unused-out').html(`<div class="notice success">✅ Deleted <strong>${r2.data.deleted}</strong> images (${r2.data.failed} failed)</div>`); App.loadStats(); }
        else { $(this).prop('disabled',false).text('🗑 Delete all'); }
      });
    });
  },

  /* ── Categories ── */
  updateCatCount() {
    const n=this.selected.size;
    if (!n) { $('#cat-count').attr('class','notice info').text('No images selected — go to Images tab and select images first'); $('#run-cats').prop('disabled',true); return; }
    $('#cat-count').attr('class','notice success').text(`✅ ${n} image${n>1?'s':''} selected — ready to categorize`);
    $('#run-cats').prop('disabled',!SIO.has_key);
  },

  bindCats() {
    $('#run-cats').on('click', ()=>this.runCategorize());
  },

  async runCategorize() {
    const ids=[...this.selected];
    if (!ids.length||!SIO.has_key) return;
    if (!confirm(`Analyze ${ids.length} image${ids.length>1?'s':''} and auto-categorize them?\n\nThe AI will group similar images into categories (saved as WordPress taxonomy).`)) return;

    const BATCH=5; let done=0;
    let allCats={};
    $('#cat-prog').show(); $('#run-cats').prop('disabled',true).text('⏳ Analyzing...');
    $('#cat-log').empty(); $('#cat-out').empty();

    for (let i=0;i<ids.length;i+=BATCH){
      const batch=ids.slice(i,i+BATCH);
      const r=await this.post({action:'sio_scan_cats',nonce:SIO.nonce,ids:batch});
      done+=batch.length;
      const pct=Math.round(done/ids.length*100);
      $('#cat-fill').css('width',pct+'%'); $('#cat-pct').text(pct+'%');
      if (r.success){
        r.data.details.forEach(l=>$('#cat-log').append(`<div class="${l.startsWith('❌')?'fail':'ok'}">${l}</div>`));
        Object.entries(r.data.categories).forEach(([cat,cids])=>{
          if (!allCats[cat]) allCats[cat]=[];
          allCats[cat]=[...allCats[cat],...cids];
        });
      }
      await this.sleep(1200);
    }

    // Show category groups
    let html=`<div style="margin-bottom:14px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
      <strong>${Object.keys(allCats).length} categories found</strong>
      <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
        <input type="checkbox" id="move-files-chk" checked>
        <span>📂 Move images into real folders <code style="background:#f1f5f9;padding:1px 5px;border-radius:4px">uploads/categories/{name}/</code></span>
      </label>
      <button class="btn ai-btn" id="apply-cats">📁 Apply</button>
    </div><div class="cat-groups">`;
    Object.entries(allCats).forEach(([cat,cids])=>{
      const thumbs=cids.slice(0,8).map(id=>{
        const $c=$(`.card[data-id="${id}"] img`);
        return $c.length?`<img src="${$c.attr('src')}" alt=""`+' loading="lazy">':'';
      }).join('');
      html+=`<div class="cat-group"><div class="cat-group-head"><span>📁 ${cat.replace(/-/g,' ')}</span><span class="cnt">${cids.length}</span></div><div class="cat-thumbs">${thumbs}</div></div>`;
    });
    html+='</div>';
    $('#cat-out').html(html);

    $('#apply-cats').on('click', async ()=>{
      const movFiles=$('#move-files-chk').is(':checked');
      if (movFiles && !confirm(`Move images into category subfolders inside uploads/categories/?\n\nThis updates all database references automatically.\n\n⚠ Make sure you have a backup first.`)) return;
      $('#apply-cats').prop('disabled',true).text('⏳ Applying...');
      const r=await this.post({action:'sio_apply_cats',nonce:SIO.nonce,categories:JSON.stringify(allCats),move_files:movFiles?1:0});
      if (r.success) {
        const d=r.data;
        const msg=movFiles
          ? `✅ ${d.cats} folders created · ${d.moved} images moved · ${d.applied} tagged${d.failed?' · ⚠'+d.failed+' failed':''}`
          : `✅ ${d.applied} images tagged across ${d.cats} categories`;
        $('#apply-cats').replaceWith(`<span class="notice success" style="display:inline-block;padding:6px 12px;">${msg}</span>`);
      } else { $('#apply-cats').prop('disabled',false).text('📁 Apply'); }
    });

    $('#run-cats').prop('disabled',false).text('🤖 Analyze & Categorize');
  },

  /* ── Audit ── */
  bindAudit() {
    $('#a-scan').on('click', async ()=>{
      const fn=$('#a-old').val().trim();
      if (!fn) { alert('Enter the old filename'); return; }
      $('#a-scan').prop('disabled',true).text('⏳');
      $('#audit-out').empty(); $('#a-fix').prop('disabled',true);
      const r=await this.post({action:'sio_scan_refs',nonce:SIO.nonce,filename:fn});
      $('#a-scan').prop('disabled',false).text('🔍 Scan');
      if (!r.success) { $('#audit-out').html('<div class="notice warn">'+r.data+'</div>'); return; }
      const {refs,total}=r.data;
      if (!total) { $('#audit-out').html('<div class="notice success">✅ No references found — nothing to fix</div>'); return; }
      let html=`<div class="notice warn">⚠ Found <strong>${total}</strong> reference${total>1?'s':''} in ${refs.length} table${refs.length>1?'s':''}</div>`;
      refs.forEach(ref=>{ html+=`<div class="ref-card"><div class="ref-head"><span>${ref.table}</span><span>${ref.count} rows</span></div><div class="ref-rows">${ref.rows.map(r=>`<div class="ref-row">${this.esc(r)}</div>`).join('')}</div></div>`; });
      $('#audit-out').html(html); $('#a-fix').prop('disabled',false);
    });
    $('#a-fix').on('click', async ()=>{
      const o=$('#a-old').val().trim(), n=$('#a-new').val().trim();
      if (!o||!n) { alert('Enter both filenames'); return; }
      if (!confirm(`Replace all references to "${o}" with "${n}"?`)) return;
      $('#a-fix').prop('disabled',true).text('⏳ Fixing...');
      const r=await this.post({action:'sio_fix_refs',nonce:SIO.nonce,old:o,new:n});
      $('#a-fix').prop('disabled',false).text('🔧 Fix all');
      $('#audit-out').html(r.success?`<div class="notice success">✅ Fixed — <span class="lnk-badge">🔗${r.data.replaced} rows updated</span></div>`:`<div class="notice warn">❌${r.data}</div>`);
    });
  },

  /* ── Settings ── */
  bindSettings() {
    $('input[name=prov]').on('change', function(){
      const v=$(this).val();
      $('.prov-card').removeClass('active'); $(this).closest('.prov-card').addClass('active');
      $('.sf').hide(); $(`.p-${v}`).show();
    });
    $(document).on('click','.tpw', function(){
      const $i=$(this).prev('input'); const show=$i.attr('type')==='password';
      $i.attr('type',show?'text':'password'); $(this).text(show?'🙈':'👁');
    });
    $('#save-set').on('click', async ()=>{
      const prov=$('input[name=prov]:checked').val()||'groq';
      const r=await this.post({action:'sio_save_settings',nonce:SIO.nonce,
        ai_provider:prov, groq_key:$('#s-groq').val().trim(),
        gemini_key:$('#s-gemini').val().trim(), api_key:$('#s-claude').val().trim(),
        ai_model:$('#s-model').val()||'claude-haiku-4-5-20251001',
        ai_language:$('#s-lang').val(), ai_context:$('#s-ctx').val().trim()});
      const $m=$('#set-msg');
      if (r.success){
        $m.css('color','var(--success)').text('✅ Saved!');
        const key=prov==='groq'?$('#s-groq').val():prov==='gemini'?$('#s-gemini').val():$('#s-claude').val();
        SIO.has_key=!!key.trim(); this.updateBulkCount(); this.updateCatCount();
      } else $m.css('color','var(--danger)').text('❌ Save failed');
      setTimeout(()=>$m.text(''),3000);
    });
    $('#test-con').on('click', async ()=>{
      const prov=$('input[name=prov]:checked').val()||'groq';
      const $m=$('#set-msg'); $m.css('color','var(--muted)').text('Testing...');
      $('#test-con').prop('disabled',true);
      try {
        if (prov==='groq'){
          const key=$('#s-groq').val().trim();
          if (!key){$m.css('color','var(--danger)').text('❌ Enter Groq key first');return;}
          const r=await fetch('https://api.groq.com/openai/v1/models',{headers:{Authorization:`Bearer ${key}`}});
          const d=await r.json(); r.ok?$m.css('color','var(--success)').text('✅ Groq connected!'):$m.css('color','var(--danger)').text('❌ '+(d.error?.message||r.status));
        } else if (prov==='gemini'){
          const key=$('#s-gemini').val().trim();
          if (!key){$m.css('color','var(--danger)').text('❌ Enter Gemini key first');return;}
          const r=await fetch(`https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-8b:generateContent?key=${key}`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({contents:[{parts:[{text:'hi'}]}],generationConfig:{maxOutputTokens:5}})});
          const d=await r.json(); r.ok?$m.css('color','var(--success)').text('✅ Gemini connected!'):$m.css('color','var(--danger)').text('❌ '+(d.error?.message||r.status));
        } else {
          const key=$('#s-claude').val().trim();
          if (!key){$m.css('color','var(--danger)').text('❌ Enter Anthropic key first');return;}
          const r=await fetch('https://api.anthropic.com/v1/messages',{method:'POST',headers:{'Content-Type':'application/json','x-api-key':key,'anthropic-version':'2023-06-01'},body:JSON.stringify({model:'claude-haiku-4-5-20251001',max_tokens:5,messages:[{role:'user',content:'hi'}]})});
          const d=await r.json(); r.ok?$m.css('color','var(--success)').text('✅ Claude connected!'):$m.css('color','var(--danger)').text('❌ '+(d.error?.message||r.status));
        }
      } catch(e){$m.css('color','var(--danger)').text('❌ '+e.message);}
      $('#test-con').prop('disabled',false);
    });
  },

  /* ── Modal ── */
  bindModal() {
    // Video SEO apply
    $(document).on('click','#v-apply', async function(){
      const id=+$(this).data('id');
      const slug=$('#v-slug').val().trim();
      const title=$('#v-title').val().trim();
      const desc=$('#v-desc').val().trim();
      if (!slug||!title) { alert('Slug and title are required'); return; }
      $(this).prop('disabled',true).text('⏳ Applying...');
      const r=await App.post({action:'sio_video_seo',nonce:SIO.nonce,id,apply:1,
        slug,title,description:desc});
      $(this).prop('disabled',false).text('✅ Apply SEO + Rename File');
      if (r.success){
        const fn=r.data.new_filename||'updated';
        $('#v-result').html(`<div class="notice success">✅ SEO applied! New file: <strong>${fn}</strong><br>Alt text, title, description &amp; caption updated.</div>`);
        App.loadVideos(App.vidPage);
      } else {
        $('#v-result').html(`<div class="notice warn">❌ ${r.data}</div>`);
      }
    });
    // Screenshot
    $(document).on('click','#shot-run', async function(){
      const id=+$(this).data('id'); const at=$('#shot-at').val()||1;
      $(this).prop('disabled',true).text('⏳ Extracting frame...');
      $('#shot-result').html('<div class="loading"><div class="spinner"></div>Extracting...</div>');
      const r=await App.post({action:'sio_video_screenshot',nonce:SIO.nonce,id,at});
      $(this).prop('disabled',false).text('📸 Extract Frame');
      if (r.success){
        const d=r.data;
        $('#shot-result').html(`
          <div class="notice success">✅ Screenshot saved! (via ${d.method})</div>
          ${d.thumb_url?`<img src="${d.thumb_url}" class="screenshot-preview" style="margin-top:8px;">`:''}
          <p style="font-size:11px;color:var(--muted);margin-top:6px;">
            ${d.width}×${d.height}px · Set as video thumbnail in Media Library
          </p>`);
        App.loadVideos(App.vidPage);
      } else {
        $('#shot-result').html(`<div class="notice warn">❌ ${r.data}</div>`);
      }
    });

    $(document).on('click','#m-save-rename', function(){
      const id=+$(this).data('id'), nm=$('#m-new-name').val().trim();
      if (!nm){alert('Enter a name');return;}
      $(this).prop('disabled',true).text('⏳ Saving...');
      $.post(SIO.ajax,{action:'sio_rename_single',nonce:SIO.nonce,id,new_name:nm},r=>{
        $(this).prop('disabled',false).text('✅ Save & Update Links');
        if (r.success){
          const seo = r.data.seo_title ? `<br><small style="color:var(--success)">🏷 Alt text set: <em>${r.data.seo_title}</em></small>` : '';
          $('#m-result').html(`<div class="notice success">✅ <strong>${r.data.new_filename}</strong> <span class="lnk-badge">🔗${r.data.replaced} links</span>${seo}</div>`);
          App.updateCard(id,r.data.new_filename); App.loadStats();
        } else $('#m-result').html(`<div class="notice warn">❌ ${r.data}</div>`);
      });
    });
    $(document).on('click','#m-save-resize', function(){
      const id=+$(this).data('id');
      $(this).prop('disabled',true).text('⏳');
      $.post(SIO.ajax,{action:'sio_resize_single',nonce:SIO.nonce,id,width:+$('#m-w').val()||0,height:+$('#m-h').val()||0,mode:$('#m-mode').val()||'fixed_height'},r=>{
        $(this).prop('disabled',false).text('📐 Apply');
        const d=r.data||{};
        $('#m-result').html(r.success?(d.skipped?`<div class="notice info">ℹ ${d.reason}</div>`:`<div class="notice success">✅ ${d.old_w}×${d.old_h} → ${d.new_w}×${d.new_h} · saved ${d.saved_kb}KB</div>`):`<div class="notice warn">❌${r.data}</div>`);
      });
    });
    $(document).on('click','.mclose', ()=>this.closeModal());
    $(document).on('click','#modal', e=>{ if ($(e.target).is('#modal')) this.closeModal(); });
  },

  openModal()  { $('#modal').show(); },
  closeModal() { $('#modal').hide(); },

  updateCard(id,newFn){
    const $c=$(`.card[data-id="${id}"]`);
    $c.find('.card-fn').text(newFn);
    $c.find('.thumb-wrap').append('<div class="card-done">✓</div>');
    setTimeout(()=>$c.find('.card-done').fadeOut(400,function(){$(this).remove();}),1400);
    if (!/^(whatsapp|img[-_]?\d|dsc|\d{4}[-_]\d{2})/i.test(newFn)){$c.removeClass('bad');$c.find('.bad-tag').remove();}
  },

  /* ══════════════════════════════════════════════════════
     VIDEO MODULE
  ══════════════════════════════════════════════════════ */
  vidSelected: new Set(),
  vidPage: 1,

  bindVideos() {
    $('#vid-load-btn').on('click', ()=>this.loadVideos(1));
    let t;
    $('#vid-search').on('input', ()=>{ clearTimeout(t); t=setTimeout(()=>this.loadVideos(1),400); });
    $('#vid-per-page').on('change', ()=>this.loadVideos(1));
    $('#vid-sel-all').on('change', function(){
      App.vidVisible.forEach(id=>$(this).is(':checked')?App.vidSelected.add(id):App.vidSelected.delete(id));
      App.refreshVidSel();
      $('#vid-sel-count').text(App.vidSelected.size?`${App.vidSelected.size} selected`:'');
    });
    $(document).on('click','.vcard', function(e){
      if ($(e.target).closest('.vactions').length) return;
      const id=+$(this).data('id');
      App.vidSelected.has(id)?App.vidSelected.delete(id):App.vidSelected.add(id);
      $(this).toggleClass('selected',App.vidSelected.has(id));
      $('#vid-sel-count').text(App.vidSelected.size?`${App.vidSelected.size} selected`:'');
    });
    $(document).on('click','.vbtn-seo',  e=>{ e.stopPropagation(); App.openVideoSeoModal(+$(e.currentTarget).data('id')); });
    $(document).on('click','.vbtn-shot', e=>{ e.stopPropagation(); App.openScreenshotModal(+$(e.currentTarget).data('id')); });
    $(document).on('click','.vbtn-ren',  e=>{ e.stopPropagation(); App.openManualModal(+$(e.currentTarget).data('id')); });
    $(document).on('click','.vpg-btn',   function(){ App.loadVideos(+$(this).data('p')); });
  },

  vidVisible: [],

  async loadVideos(page=1) {
    this.vidPage=page;
    $('#vid-grid').html('<div class="loading"><div class="spinner"></div>Loading videos...</div>');
    const r=await this.post({action:'sio_get_videos',nonce:SIO.nonce,page,per_page:$('#vid-per-page').val()||24,search:$('#vid-search').val()||''});
    if (!r.success) { $('#vid-grid').html('<div class="loading">Error loading videos</div>'); return; }
    const {videos,total,total_pages}=r.data;
    this.vidVisible=videos.map(v=>v.id);
    if (!videos.length) { $('#vid-grid').html('<div class="loading">No videos found in Media Library</div>'); $('#vid-pagination').empty(); return; }
    $('#vid-grid').html(videos.map(v=>this.videoCardHTML(v)).join(''));
    this.refreshVidSel();
    this.renderVidPagination(page,total_pages,total);
  },

  videoCardHTML(v) {
    const dur = v.duration ? `<span class="vdur">${this.esc(v.duration)}</span>` : '';
    const thumb = v.thumb
      ? `<img src="${v.thumb}" loading="lazy" alt="">${dur}`
      : `<div class="no-thumb"><span>🎬</span><small>${this.esc(v.mime||'video')}</small></div>${dur}`;
    const seoTag = v.seo_title ? `<span style="font-size:9px;color:var(--success);display:block;margin-top:2px">✅ SEO set</span>` : '';
    return `<div class="vcard${v.bad_name?' bad':''}" data-id="${v.id}">
      <div class="vthumb">${thumb}<div class="vplay"><div class="vplay-btn">▶</div></div><div class="vsel-dot">✓</div></div>
      <div class="vinfo">
        <div class="vfn">${this.esc(v.filename)}</div>
        <div class="vmeta">
          <span>${v.width&&v.height?v.width+'×'+v.height:'?'}</span>
          <span>${v.size_mb}MB</span>
          ${v.has_thumb?'<span style="color:var(--success)">📸 thumb</span>':'<span style="color:var(--muted)">no thumb</span>'}
        </div>
        ${seoTag}
      </div>
      <div class="vactions">
        <button class="btn ai-btn vbtn-seo"  data-id="${v.id}" title="AI SEO">🤖 SEO</button>
        <button class="btn secondary vbtn-shot" data-id="${v.id}" title="Screenshot">📸</button>
        <button class="btn secondary vbtn-ren"  data-id="${v.id}" title="Rename">✏</button>
      </div>
    </div>`;
  },

  refreshVidSel() {
    $('.vcard').each(function(){ $(this).toggleClass('selected',App.vidSelected.has(+$(this).data('id'))); });
  },

  renderVidPagination(page,total_pages,total) {
    const $p=$('#vid-pagination').empty();
    if (total_pages<=1) { $p.html(`<span style="color:var(--muted);font-size:12px;">${total} videos</span>`); return; }
    if (page>1) $p.append(`<button class="pg-btn vpg-btn" data-p="${page-1}">‹</button>`);
    for(let i=Math.max(1,page-2);i<=Math.min(total_pages,page+2);i++)
      $p.append(`<button class="pg-btn vpg-btn ${i===page?'active':''}" data-p="${i}">${i}</button>`);
    if (page<total_pages) $p.append(`<button class="pg-btn vpg-btn" data-p="${page+1}">›</button>`);
    $p.append(`<span style="color:var(--muted);font-size:12px;">${total} videos</span>`);
  },

  /* ── Video SEO modal ── */
  async openVideoSeoModal(id) {
    if (!SIO.has_key) { alert('Add an API key in Settings first'); return; }
    $('#mbody').html(`
      <h2 style="margin:0 0 14px;font-size:17px;">🤖 AI Video SEO</h2>
      <div class="loading"><div class="spinner"></div>AI analyzing video metadata...</div>`);
    this.openModal();
    const r=await this.post({action:'sio_video_seo',nonce:SIO.nonce,id,apply:0});
    if (!r.success) { $('#mbody').html(`<h2>🤖 AI Video SEO</h2><div class="notice warn">❌ ${r.data}</div>`); return; }
    const d=r.data;
    const tagsHtml=(d.tags||[]).map(t=>`<span class="vtag">${this.esc(t)}</span>`).join('');
    $('#mbody').html(`
      <h2 style="margin:0 0 14px;font-size:17px;">🤖 AI Video SEO</h2>
      <div class="vseo-panel">
        <h4>✨ AI Generated SEO — Review &amp; Edit</h4>
        <div class="vseo-row"><label>SEO Filename slug</label>
          <input type="text" id="v-slug" value="${this.esc(d.slug)}" style="font-family:monospace"></div>
        <div class="vseo-row"><label>Title (used as Alt &amp; Media Title)</label>
          <input type="text" id="v-title" value="${this.esc(d.title)}"></div>
        <div class="vseo-row"><label>Description (Caption &amp; Post Content)</label>
          <textarea id="v-desc">${this.esc(d.description)}</textarea></div>
        <div class="vseo-row"><label>Tags (for reference)</label>
          <div>${tagsHtml}</div></div>
      </div>
      <div class="modal-actions">
        <button class="btn ai-btn" id="v-apply" data-id="${id}">✅ Apply SEO + Rename File</button>
        <button class="btn secondary mclose">Cancel</button>
      </div>
      <div id="v-result" style="margin-top:10px;"></div>`);
  },

  /* ── Screenshot modal ── */
  async openScreenshotModal(id) {
    $('#mbody').html(`
      <h2 style="margin:0 0 14px;font-size:17px;">📸 Extract Video Screenshot</h2>
      <p class="hint">Captures a frame from the video and sets it as the thumbnail.</p>
      <div class="fg" style="margin-bottom:12px;">
        <label>Capture at (seconds into video)</label>
        <input type="number" id="shot-at" value="1" min="0" step="0.5" style="width:100px;">
      </div>
      <div class="modal-actions">
        <button class="btn ai-btn" id="shot-run" data-id="${id}">📸 Extract Frame</button>
        <button class="btn secondary mclose">Cancel</button>
      </div>
      <div id="shot-result" style="margin-top:12px;"></div>`);
    this.openModal();
  },

  /* ── Toggle resize field visibility ── */
  toggleResizeFields(mode) {
    const needH = ['fixed_height','crop','resize'].includes(mode);
    const needW = ['max_width','crop','resize'].includes(mode);
    $('#m-h-wrap, #b-h-wrap').toggle(needH);
    $('#m-w-wrap, #b-w-wrap').toggle(needW);
  },

  /* ── Helpers ── */
  post(data){return new Promise(res=>$.post(SIO.ajax,data,res).fail(e=>res({success:false,data:e.statusText})));},
  sleep(ms){return new Promise(r=>setTimeout(r,ms));},
  esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');},
};

$(()=>App.init());
})(jQuery);
