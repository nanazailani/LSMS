/* LSMS shared sidebar: hamburger button + slide-out menu for Modules 1-4.
   Letak fail ini di root folder LSMS (sebelah folder "module 1" dan "module_2").
   Untuk aktifkan Module 3 / 4 nanti, isi `path` dalam senarai MODULES di bawah. */
   (function () {
    var MODULES = [
      { n: 1, name: 'Product Management',  path: 'module 1/products.php' },
      { n: 2, name: 'Stock Receiving',     path: 'module_2/stock-receiving.php' },
      { n: 3, name: 'Sales & Adjustment',  path: 'module_3/sales-adjustment.html' },
      { n: 4, name: 'Reports & Alerts',   path: 'module_4/index.html', sub: [
        { name: 'Overview', path: 'module_4/index.html' },
        { name: 'Low-stock alerts', path: 'module_4/alerts.html' },
        { name: 'Generate report', path: 'module_4/generate.html' },
        { name: 'Saved reports', path: 'module_4/reports.html' },
        { name: 'Alert settings', path: 'module_4/settings.html' }
      ]},
      { n: 'A', name: 'Staff & Locations', path: 'admin/staff.php', admin: true }
    ];
  
    var script = document.currentScript;
    var root = new URL('./', script ? script.src : location.href);
  
    var css = '\
  .sb-btn{width:42px;height:42px;min-height:0;flex:0 0 auto;display:inline-flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;padding:0;border:1px solid #d9e0e5;border-radius:6px;background:#fff;cursor:pointer}\
  .sb-btn:hover{background:#f3f5f6}\
  .sb-btn:focus-visible,.sb-x:focus-visible,.sb-link:focus-visible{outline:3px solid #1a5fd0;outline-offset:2px}\
  .sb-btn i{display:block;width:20px;height:2px;background:#16212b;border-radius:2px;transition:transform .2s,opacity .2s}\
  .sb-btn[aria-expanded=true] i:nth-child(1){transform:translateY(7px) rotate(45deg)}\
  .sb-btn[aria-expanded=true] i:nth-child(2){opacity:0}\
  .sb-btn[aria-expanded=true] i:nth-child(3){transform:translateY(-7px) rotate(-45deg)}\
  .sb-ov{position:fixed;inset:0;background:rgba(23,32,42,.5);opacity:0;visibility:hidden;transition:opacity .2s,visibility .2s;z-index:900}\
  .sb-ov.on{opacity:1;visibility:visible}\
  .sb{position:fixed;top:0;left:0;bottom:0;width:min(280px,86vw);background:#fff;z-index:901;transform:translateX(-100%);visibility:hidden;transition:transform .25s ease,visibility .25s;display:flex;flex-direction:column;box-shadow:4px 0 24px rgba(0,0,0,.25);border-right:4px solid #d4161c;font-family:"Segoe UI",system-ui,-apple-system,Roboto,Arial,sans-serif;color:#16212b}\
  .sb.on{transform:none;visibility:visible}\
  .sb-h{display:flex;align-items:center;justify-content:space-between;padding:16px 16px 14px;border-bottom:4px solid #ffe500}\
  .sb-h img{height:44px;width:auto;display:block;margin-bottom:6px}.sb-h b{font-size:16px;line-height:1.25}.sb-h small{display:block;font-weight:400;color:#5b6875;font-size:12px}\
  .sb-x{width:36px;height:36px;border:0;background:none;font-size:26px;line-height:1;cursor:pointer;border-radius:6px;color:#16212b;padding:0;min-height:0}\
  .sb-x:hover{background:#f3f5f6}\
  .sb nav{padding:10px 0;overflow-y:auto}\
  .sb-link{display:flex;align-items:center;gap:12px;padding:13px 18px;color:#16212b;text-decoration:none;font-size:15px;border-left:4px solid transparent}\
  .sb-link:hover{background:#f3f5f6}\
  .sb-link .no{flex:0 0 28px;height:28px;border-radius:50%;background:#f3f5f6;border:1px solid #d9e0e5;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600}\
  .sb-link.cur{background:#fdecec;border-left-color:#d4161c;font-weight:600}\
  .sb-link.cur .no{background:#d4161c;border-color:#d4161c;color:#fff}\
  .sb-link.off{color:#8a949e;cursor:not-allowed;pointer-events:none}\
  .sb-link.off em{margin-left:auto;font-size:11px;font-style:normal;background:#f3f5f6;border:1px solid #d9e0e5;border-radius:99px;padding:1px 8px}\
  .sb-sub{padding:0 0 4px}\
  .sb-sub a{display:block;padding:7px 18px 7px 58px;color:#3f4856;text-decoration:none;font-size:13.5px;border-left:4px solid transparent}\
  .sb-sub a:hover{background:#f3f5f6}\
  .sb-sub a.cur{color:#d4161c;font-weight:600;background:#fdecec;border-left-color:#d4161c}\
  .sb-f{margin-top:auto;padding:12px 16px 16px;border-top:1px solid #d9e0e5}\
  .sb-u{font-size:12.5px;color:#5b6875;margin:0 0 8px}.sb-u b{color:#16212b}\
  .sb-out{width:100%;display:flex;align-items:center;justify-content:center;gap:8px;padding:10px 14px;min-height:0;border:1px solid #d4161c;border-radius:6px;background:#fff;color:#d4161c;font:inherit;font-size:14.5px;font-weight:600;cursor:pointer}\
  .sb-out:hover{background:#d4161c;color:#fff}\
  .sb-out:focus-visible{outline:3px solid #1a5fd0;outline-offset:2px}\
  @media (prefers-reduced-motion:reduce){.sb,.sb-ov,.sb-btn i{transition:none}}';
  
    var style = document.createElement('style');
    style.textContent = css;
    document.head.appendChild(style);
  
    var USER_KEY = 'lsms_user';
    var user = '', role = '', loc = '';
    try {
      user = sessionStorage.getItem(USER_KEY) || '';
      role = sessionStorage.getItem('lsms_role') || '';
      loc = sessionStorage.getItem('lsms_loc') || '';
    } catch (e) {}
    if (loc && window.LSMS) loc = LSMS.locName(loc) || loc;   // stored as a code such as PHG
    function esc(t) { return String(t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    var here = decodeURIComponent(location.pathname);
    var links = MODULES.filter(function (m) { return !m.admin || role === 'admin'; }).map(function (m) {
      var on = !!m.path;
      var href = on ? new URL(m.path.split('/').map(encodeURIComponent).join('/'), root).href : '';
      var folder = m.path.substring(0, m.path.indexOf('/'));
      var cur = on && here.indexOf('/' + folder + '/') >= 0;
      var html = '<a class="sb-link' + (cur ? ' cur' : '') + (on ? '' : ' off') + '"' +
        (on ? ' href="' + href + '"' : ' aria-disabled="true" tabindex="-1"') +
        (cur ? ' aria-current="page"' : '') + '>' +
        '<span class="no">' + m.n + '</span><span>' + m.name + '</span>' +
        (on ? '' : '<em>Akan datang</em>') + '</a>';
      if (m.sub && cur) {
        html += '<div class="sb-sub">';
        m.sub.forEach(function (s) {
          var sh = new URL(s.path.split('/').map(encodeURIComponent).join('/'), root).href;
          var sc = here.length >= s.path.length && here.slice(-s.path.length) === s.path;
          html += '<a' + (sc ? ' class="cur"' : '') + ' href="' + sh + '">' + s.name + '</a>';
        });
        html += '</div>';
      }
      return html;
    }).join('');
  
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'sb-btn';
    btn.setAttribute('aria-label', 'Buka menu');
    btn.setAttribute('aria-expanded', 'false');
    btn.setAttribute('aria-controls', 'lsms-sidebar');
    btn.innerHTML = '<i></i><i></i><i></i>';
  
    var ov = document.createElement('div');
    ov.className = 'sb-ov';
  
    var sb = document.createElement('aside');
    sb.id = 'lsms-sidebar';
    sb.className = 'sb';
    sb.setAttribute('aria-label', 'Menu modul');
    sb.innerHTML = '<div class="sb-h"><div><img src="' + new URL('images/buraqoil-logo.png', root).href + '" alt="Buraqoil" onerror="this.hidden=true"><b>IPTB-LSMS</b><small>Lubricant Stock Management</small></div>' +
      '<button type="button" class="sb-x" aria-label="Tutup menu">&times;</button></div><nav>' + links + '</nav>' +
      '<div class="sb-f">' + (user ? '<p class="sb-u">Log masuk sebagai <b>' + esc(user) + '</b>' +
        (loc ? '<br>Lokasi: <b>' + esc(loc) + '</b>' : '') + '</p>' : '') +
      '<button type="button" class="sb-out"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>Log out</button></div>';
  
    var header = document.querySelector('header');
    if (header) {
      var row = header.querySelector('.in, .hin') || header;
      row.insertBefore(btn, row.firstChild);
    } else {
      btn.style.cssText = 'position:fixed;top:10px;left:10px;z-index:850';
      document.body.appendChild(btn);
    }
    document.body.appendChild(ov);
    document.body.appendChild(sb);
  
    function set(open) {
      sb.classList.toggle('on', open);
      ov.classList.toggle('on', open);
      btn.setAttribute('aria-expanded', String(open));
      btn.setAttribute('aria-label', open ? 'Tutup menu' : 'Buka menu');
      if (open) { sb.querySelector('.sb-x').focus(); } else if (document.activeElement && sb.contains(document.activeElement)) { btn.focus(); }
    }
    btn.addEventListener('click', function () { set(!sb.classList.contains('on')); });
    ov.addEventListener('click', function () { set(false); });
    sb.querySelector('.sb-x').addEventListener('click', function () { set(false); });
    sb.querySelector('.sb-out').addEventListener('click', function () {
      try { ['lsms_user', 'lsms_role', 'lsms_loc'].forEach(function (k) { sessionStorage.removeItem(k); }); } catch (e) {}
      location.href = new URL('logout.php', root).href;
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sb.classList.contains('on')) set(false); });
  })();