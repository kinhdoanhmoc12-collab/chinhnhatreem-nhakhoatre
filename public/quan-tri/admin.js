(function () {
  var csrf = (document.querySelector('input[name=csrf]') || {}).value || '';

  // Hỏi xác nhận trước các thao tác quan trọng
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-confirm]');
    if (b && !confirm(b.getAttribute('data-confirm'))) e.preventDefault();
  });

  // Đếm ký tự tiêu đề / mô tả
  function counters() {
    document.querySelectorAll('.count').forEach(function (c) {
      var f = document.getElementById(c.dataset.for); if (!f) return;
      var n = f.value.trim().length, ok = n >= +c.dataset.min && n <= +c.dataset.max;
      c.textContent = n + ' ký tự'; c.className = 'count ' + (ok ? 'ok' : 'bad');
    });
  }

  // Xem trước kết quả Google
  function gprev() {
    var t = document.getElementById('gp-title'); if (!t) return;
    var st = document.getElementById('f-seot'), ti = document.getElementById('f-title'), de = document.getElementById('f-desc');
    var title = (st && st.value.trim()) || (ti && ti.value.trim() ? ti.value.trim() + ' | Nha Khoa Trẻ' : 'Tiêu đề bài viết');
    t.textContent = title.length > 62 ? title.slice(0, 60) + '…' : title;
    var d = (de && de.value.trim()) || 'Mô tả sẽ hiện ở đây. Viết 120–160 ký tự, có từ khóa chính.';
    document.getElementById('gp-desc').textContent = d.length > 162 ? d.slice(0, 158) + '…' : d;
    var sl = document.getElementById('gp-slug'), fs = document.getElementById('f-slug');
    if (sl && fs) sl.textContent = fs.value;
  }

  function slugify(s) {
    return s.toLowerCase().replace(/đ/g, 'd').normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').split('-').slice(0, 12).join('-');
  }

  function upload(file, name, cb) {
    var fd = new FormData(); fd.append('file', file); fd.append('name', name || file.name.replace(/\.[^.]+$/, '')); fd.append('csrf', csrf);
    fetch('?p=upload', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); })
      .then(function (d) { if (d.url) cb(d.url); else alert(d.err || 'Không tải được ảnh.'); })
      .catch(function () { alert('Không tải được ảnh. Kiểm tra kết nối mạng.'); });
  }

  // Ảnh đại diện / ảnh chia sẻ
  var cf = document.getElementById('cover-file');
  if (cf) cf.addEventListener('change', function () {
    if (!cf.files[0]) return;
    var ti = document.getElementById('f-title');
    upload(cf.files[0], (ti && ti.value) || 'anh-dai-dien', function (url) {
      document.getElementById('f-cover').value = url;
      document.getElementById('cover-box').innerHTML = '<img src="' + url + '" alt="">';
      score();
    });
  });

  // Quản lý liên hệ Lead (AJAX đổi trạng thái & ghi chú)
  document.querySelectorAll('.quick-status').forEach(function (sel) {
    var prevVal = sel.value;
    sel.addEventListener('change', function () {
      var id = sel.dataset.id, st = sel.value;
      var fd = new FormData();
      fd.append('action', 'status');
      fd.append('id', id);
      fd.append('status', st);
      fd.append('csrf', csrf || sel.dataset.csrf || '');
      fd.append('ajax', '1');
      fetch('?p=leads', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.ok) {
            prevVal = st;
            var badge = document.querySelector('.lead-badge-' + id);
            if (badge) {
              badge.className = 'badge ' + res.badge + ' lead-badge-' + id;
              badge.textContent = res.label;
            }
            var noteBtn = document.querySelector('.btn-add-note[data-id="' + id + '"]');
            if (noteBtn) noteBtn.dataset.status = st;
            sel.style.borderColor = '#087A3A';
            setTimeout(function () { sel.style.borderColor = ''; }, 1200);
          } else {
            alert(res.err || 'Không thể cập nhật trạng thái.');
            sel.value = prevVal;
          }
        })
        .catch(function () {
          alert('Lỗi kết nối khi cập nhật trạng thái.');
          sel.value = prevVal;
        });
    });
  });

  // Modal ghi chú CSKH
  var modal = document.getElementById('lead-note-modal');
  var mClose = document.getElementById('lead-modal-close');
  var mCancel = document.getElementById('lead-modal-cancel');
  var mForm = document.getElementById('lead-note-form');
  var mId = document.getElementById('modal-lead-id');
  var mTitle = document.getElementById('modal-lead-title');
  var mStatus = document.getElementById('modal-lead-status');
  var mNote = document.getElementById('modal-lead-note');

  function closeModal() { if (modal) modal.classList.remove('open'); }
  if (mClose) mClose.addEventListener('click', closeModal);
  if (mCancel) mCancel.addEventListener('click', closeModal);
  if (modal) {
    modal.addEventListener('click', function (e) {
      if (e.target === modal) closeModal();
    });
  }

  document.querySelectorAll('.btn-add-note').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!modal) return;
      var id = btn.dataset.id;
      mId.value = id;
      mTitle.textContent = (btn.dataset.name || 'Khách') + ' (' + (btn.dataset.phone || '') + ')';
      mStatus.value = btn.dataset.status || 'chua_lien_he';
      mNote.value = btn.dataset.note || '';
      modal.classList.add('open');
      mNote.focus();
    });
  });

  if (mForm) {
    mForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var id = mId.value;
      var fd = new FormData(mForm);
      fd.append('ajax', '1');
      var subBtn = mForm.querySelector('button[type=submit]');
      if (subBtn) subBtn.disabled = true;

      fetch('?p=leads', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (subBtn) subBtn.disabled = false;
          if (res.ok) {
            var noteBox = document.querySelector('.lead-note-box-' + id);
            if (noteBox) {
              var staff = res.staff ? res.staff + ' · ' : '';
              var textHtml = res.note ? '<p class="note-text">' + res.note.replace(/\n/g, '<br>') + '</p>' : '';
              var metaHtml = '<div class="note-meta">' + staff + res.contacted_at + '</div>';
              noteBox.innerHTML = textHtml + metaHtml;
            }
            var btn = document.querySelector('.btn-add-note[data-id="' + id + '"]');
            if (btn) {
              btn.dataset.note = res.note;
              btn.dataset.status = res.status;
              btn.textContent = res.note ? '✏ Sửa ghi chú' : '+ Thêm ghi chú';
            }
            var sel = document.querySelector('.quick-status[data-id="' + id + '"]');
            if (sel && res.status) sel.value = res.status;
            var badge = document.querySelector('.lead-badge-' + id);
            if (badge && res.label) {
              badge.className = 'badge ' + res.badge + ' lead-badge-' + id;
              badge.textContent = res.label;
            }
            closeModal();
          } else {
            alert(res.err || 'Không lưu được ghi chú.');
          }
        })
        .catch(function () {
          if (subBtn) subBtn.disabled = false;
          alert('Lỗi kết nối khi lưu ghi chú.');
        });
    });
  }

  // Chọn tất cả / Thao tác xóa hàng loạt leads
  var chkAll = document.getElementById('chk-all-leads');
  var bulkBar = document.getElementById('lead-bulk-bar');
  var bulkCount = document.getElementById('lead-bulk-count');
  var bulkIds = document.getElementById('lead-bulk-ids');

  function updateBulkBar() {
    var checked = document.querySelectorAll('.lead-chk:checked');
    if (!bulkBar) return;
    if (checked.length > 0) {
      bulkBar.style.display = 'flex';
      bulkCount.textContent = checked.length;
      var ids = [];
      checked.forEach(function (c) { ids.push(c.value); });
      bulkIds.value = ids.join(',');
    } else {
      bulkBar.style.display = 'none';
      bulkIds.value = '';
    }
  }

  if (chkAll) {
    chkAll.addEventListener('change', function () {
      document.querySelectorAll('.lead-chk').forEach(function (c) { c.checked = chkAll.checked; });
      updateBulkBar();
    });
  }

  document.querySelectorAll('.lead-chk').forEach(function (c) {
    c.addEventListener('change', function () {
      if (!c.checked && chkAll) chkAll.checked = false;
      updateBulkBar();
    });
  });

  // AJAX Xóa từng dòng lead
  document.querySelectorAll('.del-lead-form').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      var btn = f.querySelector('.btn-del-lead');
      var name = btn ? btn.dataset.name : '';
      if (!confirm('Xóa thông tin của ' + (name || 'khách này') + '? Thao tác này sẽ ẩn khỏi hệ thống.')) {
        e.preventDefault();
        return;
      }
      e.preventDefault();
      var id = btn.dataset.id;
      var fd = new FormData(f);
      fd.append('ajax', '1');
      fetch('?p=leads', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.ok) {
            var row = document.getElementById('lead-row-' + id);
            if (row) {
              row.style.transition = 'opacity .3s, transform .3s';
              row.style.opacity = '0';
              row.style.transform = 'translateX(20px)';
              setTimeout(function () { row.remove(); updateBulkBar(); }, 300);
            }
          } else {
            f.submit();
          }
        })
        .catch(function () {
          f.submit();
        });
    });
  });

  var form = document.getElementById('ed') || document.getElementById('pg');
  if (!form) return;
  var rte = document.getElementById('rte'), hidden = document.getElementById('f-content');
  var title = document.getElementById('f-title'), slug = document.getElementById('f-slug');
  var slugTouched = slug && slug.value !== '';
  if (slug) slug.addEventListener('input', function () { slugTouched = true; });
  if (title && slug) title.addEventListener('input', function () { if (!slugTouched && !slug.readOnly) slug.value = slugify(title.value); });

  function sync() { if (rte && hidden) hidden.value = rte.innerHTML; }

  // Trình soạn thảo
  if (rte && rte.isContentEditable) {
    document.execCommand('defaultParagraphSeparator', false, 'p');
    document.querySelectorAll('.toolbar button').forEach(function (b) {
      b.addEventListener('mousedown', function (e) { e.preventDefault(); });
      b.addEventListener('click', function () {
        var c = b.dataset.cmd; rte.focus();
        if (c === 'p') {
          // Đoạn: đổi khối hiện tại thành đoạn văn thường và xóa sạch định dạng thừa (cỡ chữ, in đậm do dán)
          document.execCommand('formatBlock', false, 'p');
          var s = window.getSelection();
          if (s.rangeCount) {
            var n = s.anchorNode; while (n && n !== rte && !/^(P|H2|H3|H4|LI|BLOCKQUOTE)$/.test(n.nodeName)) n = n.parentNode;
            if (n && n !== rte) {
              var keep = s.getRangeAt(0).cloneRange(), all = document.createRange();
              if (s.isCollapsed) { all.selectNodeContents(n); s.removeAllRanges(); s.addRange(all); }
              document.execCommand('removeFormat');
              n.querySelectorAll('[style],[class]').forEach(function (x) { x.removeAttribute('style'); x.removeAttribute('class'); });
              n.querySelectorAll('span,font').forEach(function (x) { while (x.firstChild) x.parentNode.insertBefore(x.firstChild, x); x.remove(); });
              s.removeAllRanges(); try { s.addRange(keep); } catch (err) {}
            }
          }
        }
        else if (c === 'h2' || c === 'h3') document.execCommand('formatBlock', false, c);
        else if (c === 'quote') document.execCommand('formatBlock', false, 'blockquote');
        else if (c === 'ul') document.execCommand('insertUnorderedList');
        else if (c === 'ol') document.execCommand('insertOrderedList');
        else if (c === 'link') {
          var u = prompt('Dán đường dẫn (vd: /hoi-dap hoặc https://www.aaoinfo.org/…):', '/'); if (u) document.execCommand('createLink', false, u);
        } else if (c === 'unlink') document.execCommand('unlink');
        else if (c === 'image') {
          var inp = document.createElement('input'); inp.type = 'file'; inp.accept = 'image/jpeg,image/png,image/webp';
          var sel = window.getSelection(), range = sel.rangeCount ? sel.getRangeAt(0).cloneRange() : null;
          inp.onchange = function () {
            if (!inp.files[0]) return;
            var alt = prompt('Mô tả ảnh (alt), vd: "Bé 8 tuổi đeo khí cụ tháo lắp":', '') || '';
            upload(inp.files[0], alt || (title && title.value), function (url) {
              rte.focus(); if (range) { sel.removeAllRanges(); sel.addRange(range); }
              var safe = alt.replace(/[&<>"]/g, function (m) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]; });
              document.execCommand('insertHTML', false, '<p><img src="' + url + '" alt="' + safe + '"></p>');
              sync(); score();
            });
          };
          inp.click();
        } else document.execCommand(c);
        sync(); scoreSoon();
      });
    });
    // Bấm vào ảnh để sửa mô tả
    rte.addEventListener('click', function (e) {
      if (e.target.tagName === 'IMG') { var a = prompt('Mô tả ảnh (alt):', e.target.getAttribute('alt') || ''); if (a !== null) { e.target.setAttribute('alt', a); sync(); scoreSoon(); } }
    });
    // Dán nội dung: chỉ giữ chữ thuần để tránh định dạng rác từ Word/web
    rte.addEventListener('paste', function (e) {
      // Dán ảnh (ảnh chụp màn hình, ảnh sao chép): tải lên máy chủ rồi chèn vào bài
      var imgs = Array.prototype.filter.call(e.clipboardData.files || [], function (f) { return /^image\/(png|jpeg|webp)$/.test(f.type); });
      if (imgs.length) {
        e.preventDefault();
        var sel = window.getSelection(), range = sel.rangeCount ? sel.getRangeAt(0).cloneRange() : null;
        imgs.forEach(function (f) {
          var alt = prompt('Mô tả ảnh vừa dán (alt), vd: "Bé 8 tuổi đeo khí cụ tháo lắp":', '') || '';
          upload(f, alt || (title && title.value) || 'anh', function (url) {
            rte.focus(); if (range) { sel.removeAllRanges(); sel.addRange(range); }
            var safe = alt.replace(/[&<>"]/g, function (m) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]; });
            document.execCommand('insertHTML', false, '<p><img src="' + url + '" alt="' + safe + '"></p>');
            sync(); score();
          });
        });
        return;
      }
      var html = e.clipboardData.getData('text/html'), txt = e.clipboardData.getData('text/plain');
      if (/<img[^>]+src=["']?data:/i.test(html)) alert('Ảnh trong nội dung vừa dán sẽ không được giữ lại. Hãy chèn ảnh bằng nút "Ảnh" hoặc dán riêng từng ảnh.');
      var esc = function (t) { return t.replace(/[&<>]/g, function (m) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[m]; }); };
      e.preventDefault();
      var clean;
      if (html) {
        // Giữ cấu trúc (tiêu đề, danh sách, bảng, liên kết) nhưng bỏ cỡ chữ, màu, font… mang theo khi dán
        var d = new DOMParser().parseFromString(html, 'text/html');
        d.querySelectorAll('script,style,meta,link,title').forEach(function (x) { x.remove(); });
        d.body.querySelectorAll('*').forEach(function (x) { ['style', 'class', 'id', 'dir', 'lang', 'face', 'size', 'color'].forEach(function (a) { x.removeAttribute(a); }); });
        d.body.querySelectorAll('span,font').forEach(function (x) { while (x.firstChild) x.parentNode.insertBefore(x.firstChild, x); x.remove(); });
        d.body.querySelectorAll('div').forEach(function (x) {
          if (x.querySelector('p,h1,h2,h3,h4,ul,ol,table,blockquote,div')) { while (x.firstChild) x.parentNode.insertBefore(x.firstChild, x); x.remove(); }
          else { var p = d.createElement('p'); while (x.firstChild) p.appendChild(x.firstChild); x.replaceWith(p); }
        });
        d.body.querySelectorAll('h2 b,h3 b,h2 strong,h3 strong,th b').forEach(function (x) { while (x.firstChild) x.parentNode.insertBefore(x.firstChild, x); x.remove(); });
        clean = d.body.innerHTML.replace(/<!--[\s\S]*?-->/g, '').trim();
      } else {
        clean = txt.split(/\n{2,}/).map(function (p) { return '<p>' + esc(p).replace(/\n/g, '<br>') + '</p>'; }).join('');
      }
      if (!clean) return;
      insertClean(clean);
      sync(); scoreSoon();
    });

    // Chèn nội dung đã lọc: nội dung nhiều đoạn được đặt CẠNH khối hiện tại (không lọt vào trong tiêu đề)
    function insertClean(clean) {
      var sel = window.getSelection(); if (!sel.rangeCount) { rte.insertAdjacentHTML('beforeend', clean); return; }
      var r = sel.getRangeAt(0); if (!rte.contains(r.startContainer)) { rte.insertAdjacentHTML('beforeend', clean); return; }
      r.deleteContents();
      var frag = r.createContextualFragment(clean);
      var isBlock = function (n) { return n.nodeType === 1 && /^(P|H[1-6]|UL|OL|TABLE|BLOCKQUOTE|FIGURE|HR)$/.test(n.nodeName); };
      var hasBlock = Array.prototype.some.call(frag.childNodes, isBlock);
      var blk = r.startContainer; while (blk && blk !== rte && !(blk.nodeType === 1 && /^(P|H2|H3|H4|LI|BLOCKQUOTE|DIV)$/.test(blk.nodeName))) blk = blk.parentNode;
      var last;
      if (!hasBlock || !blk || blk === rte || blk.nodeName === 'LI') {
        if (hasBlock && blk && blk.nodeName === 'LI') frag = r.createContextualFragment(esc(frag.textContent));
        last = frag.lastChild; r.insertNode(frag);
      } else {
        // gom chữ rời thành đoạn văn
        Array.prototype.slice.call(frag.childNodes).forEach(function (n) { if (!isBlock(n)) { if (n.textContent.trim()) { var p = document.createElement('p'); n.replaceWith(p); p.appendChild(n); } else n.remove(); } });
        last = frag.lastChild;
        var pre = document.createRange(); pre.selectNodeContents(blk); pre.setEnd(r.startContainer, r.startOffset);
        if (!blk.textContent.trim()) blk.replaceWith(frag);
        else if (!pre.toString().trim()) blk.parentNode.insertBefore(frag, blk);
        else {
          var post = document.createRange(); post.selectNodeContents(blk); post.setStart(r.startContainer, r.startOffset);
          var tail = document.createElement(blk.nodeName); tail.appendChild(post.extractContents());
          blk.after(tail); blk.after(frag);
          if (!tail.textContent.trim()) tail.remove();
        }
      }
      if (last && last.parentNode) { var nr = document.createRange(); nr.selectNodeContents(last); nr.collapse(false); sel.removeAllRanges(); sel.addRange(nr); }
    }

    rte.addEventListener('input', function () { sync(); scoreSoon(); });
  }

  // Chấm điểm SEO trực tiếp
  var t = null;
  function scoreSoon() { clearTimeout(t); t = setTimeout(score, 700); }
  function score() {
    var box = document.getElementById('checks'); if (!box) return;
    sync();
    var fd = new FormData(form); fd.delete('action'); fd.append('id', form.dataset.id || '0');
    fetch('?p=seo', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      var sc = document.getElementById('sc'); sc.textContent = d.score;
      sc.className = 'big-score ' + (d.score >= 80 ? 'green' : d.score >= 55 ? 'orange' : 'red');
      document.getElementById('sc-words').textContent = d.words + ' từ · nên đạt cả hai từ 80 điểm';
      var gc = document.getElementById('gc'); gc.textContent = d.geo;
      gc.className = 'big-score ' + (d.geo >= 80 ? 'green' : d.geo >= 55 ? 'orange' : 'red');
      box.innerHTML = d.html;
    }).catch(function () {});
  }

  form.addEventListener('input', function (e) { counters(); gprev(); if (e.target !== rte) scoreSoon(); });
  form.addEventListener('submit', sync);
  counters(); gprev();

  // Cảnh báo khi rời trang chưa lưu
  var dirty = false;
  form.addEventListener('input', function () { dirty = true; });
  form.addEventListener('submit', function () { dirty = false; });
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
})();
