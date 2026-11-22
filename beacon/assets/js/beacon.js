(function () {
  document.querySelectorAll('.modal-overlay').forEach(function (m) { document.body.appendChild(m); });

  function openModal(id) {
    var m = document.getElementById(id);
    if (!m) return;
    m.classList.add('open');
    m.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    var first = m.querySelector('select, input:not([type=hidden]), textarea, button.modal-close');
    if (first) setTimeout(function () { first.focus(); }, 30);
  }
  function closeModal(m) {
    m.classList.remove('open');
    m.setAttribute('aria-hidden', 'true');
    if (!document.querySelector('.modal-overlay.open')) document.body.style.overflow = '';
  }

    // v1.4: wide tables scroll sideways inside their card on small screens
  document.querySelectorAll('table.table').forEach(function (t) {
    if (t.parentElement && t.parentElement.classList.contains('table-scroll')) return;
    var w = document.createElement('div');
    w.className = 'table-scroll';
    t.parentNode.insertBefore(w, t);
    w.appendChild(t);
  });
  
  // v1.4: filter forms marked data-auto-filter apply as soon as a dropdown changes,
  // and a short pause after typing in a search box (no Filter button needed).
  document.querySelectorAll('form[data-auto-filter]').forEach(function (f) {
    var timer, key = 'beacon.autofilter.' + location.pathname;
    function go(field) {
      try { if (field && field.name) sessionStorage.setItem(key, field.name); } catch (err) {}
      if (f.requestSubmit) f.requestSubmit(); else f.submit();
    }
    f.querySelectorAll('select').forEach(function (s) { s.addEventListener('change', function () { go(s); }); });
    f.querySelectorAll('input[type=search], input[type=text]').forEach(function (inp) {
      var last = inp.value;
      inp.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { if (inp.value !== last) go(inp); }, 600);
      });
      inp.addEventListener('search', function () { if (inp.value !== last) go(inp); });   // the little (x) clear button
    });
    f.querySelectorAll('button[type=submit]').forEach(function (b) { b.hidden = true; });
    // Put the cursor back where it was after the page reloads
    try {
      var name = sessionStorage.getItem(key);
      sessionStorage.removeItem(key);
      var el = name && f.elements[name];
      if (el && el.focus) {
        el.focus();
        if (el.setSelectionRange && /^(search|text)$/.test(el.type)) { var n = el.value.length; el.setSelectionRange(n, n); }
      }
    } catch (err) {}
  });

  // Sidebar: hide/show on desktop, slide-in drawer on tablets and phones (v1.4)
  var drawerQuery = window.matchMedia('(max-width: 1024px)');
  function setDrawer(open) {
    document.documentElement.classList.toggle('sidebar-open', open);
    document.body.style.overflow = open ? 'hidden' : '';
  }
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-sidebar-close]')) { setDrawer(false); return; }
    if (drawerQuery.matches && e.target.closest('.shell2 .nav-link')) { setDrawer(false); return; }
    if (!e.target.closest('[data-sidebar-toggle]')) return;
    if (drawerQuery.matches) { setDrawer(!document.documentElement.classList.contains('sidebar-open')); return; }
    var closed = document.documentElement.classList.toggle('sidebar-closed');
    try { localStorage.setItem('beacon.sidebar', closed ? 'closed' : 'open'); } catch (err) {}
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && document.documentElement.classList.contains('sidebar-open')) setDrawer(false);
  });
  (drawerQuery.addEventListener ? drawerQuery.addEventListener.bind(drawerQuery, 'change') : drawerQuery.addListener.bind(drawerQuery))(function (q) {
    if (!q.matches) setDrawer(false);
  });

  document.addEventListener('click', function (e) {
    var opener = e.target.closest('[data-open]');
    if (opener) {
      e.preventDefault();
      if (opener.hasAttribute('data-close')) closeModal(opener.closest('.modal-overlay'));
      openModal(opener.getAttribute('data-open'));
      return;
    }
    var closer = e.target.closest('[data-close]');
    if (closer) { closeModal(closer.closest('.modal-overlay')); return; }
    if (e.target.classList && e.target.classList.contains('modal-overlay')) closeModal(e.target);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.open').forEach(closeModal);
  });
  document.querySelectorAll('.modal-overlay[data-autoopen]').forEach(function (m) { openModal(m.id); });

  document.querySelectorAll('[data-student-picker]').forEach(function (p) {
    var g = p.querySelector('[data-pick=grade]'), sec = p.querySelector('[data-pick=section]'), st = p.querySelector('[data-pick=student]');
    var secOpts = [].slice.call(sec.options, 1), stOpts = [].slice.call(st.options, 1);
    function fill(sel, opts, attr, val) {
      var keep = sel.value;
      sel.length = 1;
      opts.forEach(function (o) { if (val && o.getAttribute(attr) === val) sel.appendChild(o); });
      sel.value = keep;
      if (sel.value !== keep) sel.value = '';
      sel.disabled = !val;
    }
    function sync() {
      fill(sec, secOpts, 'data-grade', g.value);
      var before = st.value;
      fill(st, stOpts, 'data-section', sec.value);
      st.disabled = false;
      st.options[0].text = sec.value ? 'Choose a student…' : 'Pick a grade and section first';
      if (st.value !== before) st.dispatchEvent(new Event('change'));
    }
    g.addEventListener('change', sync);
    sec.addEventListener('change', sync);
    sync();
  });

  // Show / hide password (eye button). Works in every browser: it only swaps input.type.
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-toggle-password]');
    if (!b) return;
    var input = document.getElementById(b.getAttribute('data-toggle-password'));
    if (!input) return;
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    var label = show ? 'Hide password' : 'Show password';
    b.setAttribute('aria-pressed', show ? 'true' : 'false');
    b.setAttribute('aria-label', label);
    b.title = label;
    b.classList.toggle('is-on', show);
    input.focus();
    try { var n = input.value.length; input.setSelectionRange(n, n); } catch (err) {}
  });
  // Put revealed passwords back to hidden before a form is sent, so the browser treats them as passwords.
  document.addEventListener('submit', function (e) {
    e.target.querySelectorAll('[data-toggle-password]').forEach(function (b) {
      var input = document.getElementById(b.getAttribute('data-toggle-password'));
      if (input && input.type === 'text') { input.type = 'password'; b.classList.remove('is-on'); b.setAttribute('aria-pressed', 'false'); }
    });
  }, true);

  // Log out: once confirmed, lock the button so a double click can't send it twice.
  document.addEventListener('submit', function (e) {
    if (!e.target.closest('#modalLogout')) return;
    var btn = e.target.querySelector('button[type=submit]');
    if (btn) { btn.disabled = true; btn.textContent = 'Logging out…'; }
  });

  document.addEventListener('submit', function (e) {
    var f = e.target, msg = f.getAttribute('data-confirm');
    if (!msg || f.getAttribute('data-confirmed')) return;
    e.preventDefault();
    var submitter = e.submitter;
    var d = document.createElement('dialog');
    d.className = 'confirm-dialog';
    d.innerHTML = '<div class="confirm-body"><h3></h3><p></p><div class="confirm-actions">'
      + '<button type="button" class="confirm-cancel">Cancel</button><button type="button" class="confirm-ok"></button></div></div>';
    d.querySelector('h3').textContent = f.getAttribute('data-confirm-title') || 'Are you sure?';
    d.querySelector('p').textContent = msg;
    d.querySelector('.confirm-ok').textContent = f.getAttribute('data-confirm-ok') || 'Continue';
    document.body.appendChild(d);
    d.addEventListener('close', function () { d.remove(); });
    d.addEventListener('click', function (ev) { if (ev.target === d) d.close(); });
    d.querySelector('.confirm-cancel').addEventListener('click', function () { d.close(); });
    d.querySelector('.confirm-ok').addEventListener('click', function () {
      f.setAttribute('data-confirmed', '1');
      d.close();
      if (f.requestSubmit) f.requestSubmit(submitter && submitter.form === f ? submitter : undefined); else f.submit();
    });
    d.showModal();
    d.querySelector('.confirm-ok').focus();
  });
  window.addEventListener('pageshow', function () {
    document.querySelectorAll('form[data-confirmed]').forEach(function (f) { f.removeAttribute('data-confirmed'); });
  });

  var clocks = document.querySelectorAll('[data-clock]');
  if (clocks.length) {
    var serverMs = parseInt(clocks[0].getAttribute('data-server-time'), 10) * 1000;
    var offset = serverMs - Date.now();
    var tz = clocks[0].getAttribute('data-tz') || undefined;
    var fmtDate = new Intl.DateTimeFormat('en-US', { timeZone: tz, weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
    var fmtTime = new Intl.DateTimeFormat('en-US', { timeZone: tz, hour: 'numeric', minute: '2-digit', second: '2-digit' });
    function tick() {
      var now = new Date(Date.now() + offset);
      clocks.forEach(function (c) {
        c.textContent = c.getAttribute('data-clock') === 'time' ? fmtTime.format(now) : fmtDate.format(now).replace(',', ' ·');
      });
    }
    tick();
    setInterval(tick, 1000);
  }
})();
