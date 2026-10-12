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
        } else if (c === 'auto-heading') {
          var n = autoFormatHeadings(rte);
          sync(); scoreSoon();
          showToast('✨ Đã tự động nhận diện và cập nhật ' + n + ' tiêu đề H2, H3!');
        } else document.execCommand(c);
        sync(); scoreSoon();
      });
    });

    // Thông báo nhanh góc màn hình
    function showToast(msg) {
      var t = document.getElementById('rte-toast');
      if (!t) {
        t = document.createElement('div');
        t.id = 'rte-toast';
        document.body.appendChild(t);
      }
      t.innerHTML = msg;
      t.className = 'show';
      clearTimeout(t._timer);
      t._timer = setTimeout(function () { t.className = ''; }, 3500);
    }

    // Nhận diện cấu trúc tiêu đề (H2, H3) từ văn bản tiếng Việt & Markdown
    function detectHeading(text, isBold) {
      text = (text || '').trim();
      if (!text || text.length > 140) return null;

      // 1. Markdown heading
      if (/^#{4,6}\s+/.test(text)) return { tag: 'h3', text: text.replace(/^#{4,6}\s+/, '') };
      if (/^###\s+/.test(text)) return { tag: 'h3', text: text.replace(/^###\s+/, '') };
      if (/^##\s+/.test(text)) return { tag: 'h2', text: text.replace(/^##\s+/, '') };
      if (/^#\s+/.test(text)) return { tag: 'h2', text: text.replace(/^#\s+/, '') };

      // 2. Mục số phân cấp: 1.1, 1.2, 2.1.3...
      if (/^[0-9]+\.[0-9]+(?:\.[0-9]+)?[\.\:\-\s]\s*(.+)$/.test(text)) return { tag: 'h3', text: text };

      // 3. Bước, Giai đoạn, Lưu ý, Phương pháp, Kỹ thuật, Tiêu chuẩn, Dấu hiệu
      if (/^(?:Bước|Giai đoạn|Lưu ý|Cách|Phương pháp|Kỹ thuật|Tiêu chuẩn|Dấu hiệu)\s+[0-9IVXa-z]+[\.\:\-\s]\s*(.+)$/i.test(text)) return { tag: 'h3', text: text };

      // 4. Mục chữ cái: a), b), c)
      if (/^[a-zA-Z]\)[\.\:\-\s]?\s*(.+)$/.test(text) && !/[\,\;]$/.test(text)) return { tag: 'h3', text: text };

      // 5. Số La Mã: I., II., III., IV., V., VI., VII., VIII., IX., X.
      if (/^(?:I|II|III|IV|V|VI|VII|VIII|IX|X|XI|XII)[\.\:\-\/\s]\s*(.+)$/i.test(text)) return { tag: 'h2', text: text };

      // 6. Phần 1, Chương 1, Mục 1
      if (/^(?:Phần|Chương|Mục|Bài)\s+(?:[0-9]+|[IVX]+)[\.\:\-\s]\s*(.+)$/i.test(text)) return { tag: 'h2', text: text };

      // 7. Câu hỏi tiêu đề (chuẩn SEO Nha Khoa Trẻ dạng câu hỏi)
      if (/\?$/.test(text) && text.length <= 110 && !/\.\s/.test(text) && !/^[a-zà-ỹ]/.test(text)) return { tag: 'h2', text: text };

      // 8. Tiêu đề in HOA toàn bộ (ALL CAPS)
      var words = text.split(/\s+/);
      if (words.length >= 2 && words.length <= 16 && text.length <= 100 && !/\.$/.test(text)) {
        if (text === text.toUpperCase() && /[A-ZÀÁẢÃẠĂẰẮẲẴẶÂẦẤẨẪẬĐÈÉẺẼẸÊỀẾỂỄỆÌÍỈĨỊÒÓỎÕỌÔỒỐỔỖỘƠỜỚỞỠỢÙÚỦŨỤƯỪỨỬỮỰỲÝỶỸỴ]/.test(text)) {
          return { tag: 'h2', text: text };
        }
      }

      // 9. Mục số lớn: 1. , 2. , 3.
      if (/^[0-9]+[\.\:\-]\s*(.+)$/.test(text) && text.length <= 110) {
        var hasColonOrQ = /[\:\?]$/.test(text);
        var hasDot = /\.$/.test(text);
        if (isBold || hasColonOrQ || !hasDot) return { tag: 'h2', text: text };
      }

      // 10. Đoạn văn ngắn in đậm toàn bộ
      if (isBold && text.length <= 95 && !/\.$/.test(text) && words.length >= 2) {
        return { tag: 'h2', text: text };
      }

      return null;
    }

    // Quét và tự động chuyển các đoạn văn bản đủ điều kiện thành H2 / H3
    function autoFormatHeadings(root) {
      var count = 0;
      // 1. Chuyển H1 thành H2 (H1 đã là tiêu đề bài viết)
      root.querySelectorAll('h1').forEach(function (el) {
        var h2 = document.createElement('h2');
        h2.innerHTML = el.innerHTML;
        el.replaceWith(h2);
        count++;
      });
      // 2. Chuyển H4, H5, H6 thành H3
      root.querySelectorAll('h4, h5, h6').forEach(function (el) {
        var h3 = document.createElement('h3');
        h3.innerHTML = el.innerHTML;
        el.replaceWith(h3);
        count++;
      });
      // 3. Quét các thẻ p
      root.querySelectorAll('p').forEach(function (p) {
        var raw = p.textContent.trim();
        if (!raw) return;
        var isAllBold = false;
        var b = p.querySelector('b, strong');
        if (b && b.textContent.trim() === raw) isAllBold = true;
        var h = detectHeading(raw, isAllBold);
        if (h) {
          var el = document.createElement(h.tag);
          el.textContent = h.text;
          p.replaceWith(el);
          count++;
        }
      });
      // 4. Xóa thẻ b/strong thừa nằm bên trong H2, H3
      root.querySelectorAll('h2 b, h3 b, h2 strong, h3 strong').forEach(function (b) {
        while (b.firstChild) b.parentNode.insertBefore(b.firstChild, b);
        b.remove();
      });
      return count;
    }

    // Phân tích văn bản thô (Plain text hoặc Markdown) thành các khối HTML chuẩn
    function parsePlainText(txt, onFirstHeading) {
      var lines = txt.replace(/\r\n/g, '\n').split('\n');
      var html = [];
      var listType = null;
      var listItems = [];
      var quoteLines = [];
      var pBuffer = [];

      var flushList = function () {
        if (listItems.length && listType) {
          html.push('<' + listType + '>' + listItems.map(function (li) { return '<li>' + li + '</li>'; }).join('') + '</' + listType + '>');
          listItems = [];
          listType = null;
        }
      };
      var flushQuote = function () {
        if (quoteLines.length) {
          html.push('<blockquote>' + quoteLines.join('<br>') + '</blockquote>');
          quoteLines = [];
        }
      };
      var flushP = function () {
        if (pBuffer.length) {
          html.push('<p>' + pBuffer.map(inline).join('<br>') + '</p>');
          pBuffer = [];
        }
      };

      var inline = function (s) {
        return s
          .replace(/[&<>]/g, function (m) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[m]; })
          .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
          .replace(/__([^_]+)__/g, '<strong>$1</strong>')
          .replace(/\*([^*]+)\*/g, '<em>$1</em>')
          .replace(/\[([^\]]+)\]\((https?:\/\/[^\s\)]+|\/[^\s\)]+)\)/g, '<a href="$2">$1</a>');
      };

      var isFirstHeadingSeen = false;

      for (var i = 0; i < lines.length; i++) {
        var raw = lines[i];
        var line = raw.trim();
        if (!line) {
          flushList(); flushQuote(); flushP();
          continue;
        }

        // Khối trích dẫn
        if (/^>\s*(.*)$/.test(line)) {
          flushList(); flushP();
          quoteLines.push(inline(line.replace(/^>\s*/, '')));
          continue;
        } else {
          flushQuote();
        }

        // Danh sách gạch đầu dòng
        if (/^[-*•+–]\s+(.+)$/.test(line)) {
          flushP();
          if (listType !== 'ul') { flushList(); listType = 'ul'; }
          listItems.push(inline(line.replace(/^[-*•+–]\s+/, '')));
          continue;
        }

        // Nhận diện Heading
        var h = detectHeading(line, false);
        if (h) {
          flushList(); flushP();
          if (!isFirstHeadingSeen && onFirstHeading && onFirstHeading(h.text)) {
            isFirstHeadingSeen = true;
            continue;
          }
          isFirstHeadingSeen = true;
          html.push('<' + h.tag + '>' + inline(h.text) + '</' + h.tag + '>');
          continue;
        }

        // Danh sách số (1. , 2. )
        if (/^[0-9]+[\.\)]\s+(.+)$/.test(line)) {
          flushP();
          if (listType !== 'ol') { flushList(); listType = 'ol'; }
          listItems.push(inline(line.replace(/^[0-9]+[\.\)]\s+/, '')));
          continue;
        }

        flushList();
        pBuffer.push(line);
      }
      flushList(); flushQuote(); flushP();
      return html.join('');
    }

    // Xử lý thông minh khi dán nội dung (HTML từ Word/Docs hoặc Plain/Markdown)
    function parseSmartContent(html, txt) {
      var clean = '';
      var detectedTitle = '';
      var isEditorEmpty = !rte.textContent.trim();

      // Nếu có HTML (từ Word, Docs, Web) và không phải là markdown thô
      if (html && !/^#{1,3}\s+/.test((txt || '').trim())) {
        var d = new DOMParser().parseFromString(html, 'text/html');
        d.querySelectorAll('script,style,meta,link,title,xml').forEach(function (x) { x.remove(); });

        // Nhận diện style heading từ Word / Docs trước khi xóa class
        d.body.querySelectorAll('.MsoTitle, .MsoHeading1, [class*="heading-1"], [style*="mso-outline-level:1"]').forEach(function (x) {
          var h2 = d.createElement('h2'); h2.innerHTML = x.innerHTML; x.replaceWith(h2);
        });
        d.body.querySelectorAll('.MsoHeading2, [class*="heading-2"], [style*="mso-outline-level:2"]').forEach(function (x) {
          var h2 = d.createElement('h2'); h2.innerHTML = x.innerHTML; x.replaceWith(h2);
        });
        d.body.querySelectorAll('.MsoHeading3, .MsoHeading4, .MsoHeading5, [class*="heading-3"], [style*="mso-outline-level:3"]').forEach(function (x) {
          var h3 = d.createElement('h3'); h3.innerHTML = x.innerHTML; x.replaceWith(h3);
        });

        // Xóa thuộc tính định dạng rác
        d.body.querySelectorAll('*').forEach(function (x) {
          ['style', 'class', 'id', 'dir', 'lang', 'face', 'size', 'color'].forEach(function (a) { x.removeAttribute(a); });
        });
        d.body.querySelectorAll('span,font').forEach(function (x) {
          while (x.firstChild) x.parentNode.insertBefore(x.firstChild, x);
          x.remove();
        });
        d.body.querySelectorAll('div').forEach(function (x) {
          if (x.querySelector('p,h1,h2,h3,h4,ul,ol,table,blockquote,div')) {
            while (x.firstChild) x.parentNode.insertBefore(x.firstChild, x);
            x.remove();
          } else {
            var p = d.createElement('p');
            while (x.firstChild) p.appendChild(x.firstChild);
            x.replaceWith(p);
          }
        });

        // Tự động phân chia Heading cho các đoạn văn trong DOM
        autoFormatHeadings(d.body);

        // Tự động điền Tiêu đề bài nếu đang để trống và editor trống
        if (title && !title.value.trim() && isEditorEmpty) {
          var firstHead = d.body.querySelector('h1, h2');
          if (firstHead && firstHead === d.body.firstElementChild) {
            detectedTitle = firstHead.textContent.trim();
            firstHead.remove();
          }
        }

        clean = d.body.innerHTML.replace(/<!--[\s\S]*?-->/g, '').trim();
      }

      // Nếu không có HTML hoặc kết quả HTML rỗng, phân tích plain text/markdown
      if (!clean && txt) {
        clean = parsePlainText(txt, function (firstTitle) {
          if (title && !title.value.trim() && isEditorEmpty) {
            detectedTitle = firstTitle;
            return true;
          }
          return false;
        });
      }

      // Tự động điền Title & slug nếu phát hiện
      if (detectedTitle && title && !title.value.trim()) {
        title.value = detectedTitle;
        if (slug && !slug.readOnly && (!slug.value || !slugTouched)) {
          slug.value = slugify(detectedTitle);
        }
      }

      return clean;
    }

    // Bấm vào ảnh để sửa mô tả
    rte.addEventListener('click', function (e) {
      if (e.target.tagName === 'IMG') { var a = prompt('Mô tả ảnh (alt):', e.target.getAttribute('alt') || ''); if (a !== null) { e.target.setAttribute('alt', a); sync(); scoreSoon(); } }
    });

    // Dán nội dung: Tự động phân chia tiêu đề H2, H3, danh sách và chuẩn hóa
    rte.addEventListener('paste', function (e) {
      // Dán ảnh trực tiếp từ clipboard
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

      e.preventDefault();
      var clean = parseSmartContent(html, txt);
      if (!clean) return;

      insertClean(clean);
      sync(); scoreSoon();
      showToast('✨ Đã tự động nhận diện và phân chia cấu trúc bài viết!');
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
