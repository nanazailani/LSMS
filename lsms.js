/* LSMS shared helpers: station locations, the logged-in user, and printable documents.
   Load in <head> of every page:  <script src="../lsms.js"></script>
   Front-end demo only: the session lives in sessionStorage (set by login.html). */
(function () {
  var LOCATIONS = [
    { id: 'PHG', short: 'Kuantan', name: 'Pejabat IPTB Sdn Bhd Cawangan Pahang',
      addr: 'B34-G, Jalan IM 7/5, Bandar Indera Mahkota, 25200 Kuantan, Pahang' },
    { id: 'KTN', short: 'Kota Bharu', name: 'Pejabat IPTB Sdn Bhd Wilayah Pantai Timur',
      addr: 'Lot PT 1023, Ground Floor, Jalan Sri Cemerlang 1/27, 15300 Kota Bharu, Kelantan' }
  ];
  var script = document.currentScript;
  var root = new URL('./', script ? script.src : location.href).href;

  function esc(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function loc(id) { return LOCATIONS.filter(function (l) { return l.id === id || l.name === id; })[0] || null; }

  var user = '', role = '', myLoc = null;
  try {
    user = sessionStorage.getItem('lsms_user') || '';
    role = sessionStorage.getItem('lsms_role') || '';
    myLoc = loc(sessionStorage.getItem('lsms_loc') || '');
  } catch (e) {}
  var isStaff = role === 'staff' && !!myLoc;

  // ---------- printable documents (invoices, stock lists) ----------
  var DOC_CSS = '\
.doc{font-family:"Segoe UI",system-ui,-apple-system,Roboto,Arial,sans-serif;color:#17202a;font-size:14px;line-height:1.45}\
.doc-h{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;flex-wrap:wrap;padding-bottom:14px;border-bottom:4px solid #d4161c;margin-bottom:18px}\
.doc-from{display:flex;gap:12px;align-items:flex-start;max-width:360px}\
.doc-from img{height:48px;width:auto;display:block}\
.doc-from b{display:block;font-size:15px}.doc-from span{display:block;color:#5c6670;font-size:12.5px}\
.doc-t{text-align:right;margin-left:auto}\
.doc-t h1{margin:0 0 6px;font-size:22px;letter-spacing:1px;text-transform:uppercase;color:#d4161c}\
.doc table.doc-meta{margin-left:auto;border-collapse:collapse;width:auto;min-width:0}\
.doc .doc-meta td{padding:1px 0 1px 14px;font-size:13px;border:0;background:none}\
.doc .doc-meta td:first-child{color:#5c6670}\
.doc-to{margin:0 0 16px;font-size:13.5px}.doc-to small{display:block;color:#5c6670;font-size:11.5px;text-transform:uppercase;letter-spacing:.5px}\
.doc table.doc-lines{width:100%;border-collapse:collapse;min-width:0}\
.doc .doc-lines th{background:#f6f7f9;text-align:left;font-size:12px;color:#5c6670;padding:8px 10px;border-bottom:1px solid #dfe3e8}\
.doc .doc-lines td{padding:8px 10px;border-bottom:1px solid #dfe3e8;font-size:13.5px;vertical-align:top}\
.doc .doc-lines .n{text-align:right}\
.doc .doc-lines .sub{display:block;color:#5c6670;font-size:12px}\
.doc .doc-lines tfoot td{font-weight:700;border-bottom:0;border-top:2px solid #17202a}\
.doc-sec{margin:22px 0 8px;font-size:16px}\
.doc-note{margin-top:18px;color:#5c6670;font-size:12px}\
.doc-pb{break-before:page;page-break-before:always}';

  // inject once so in-page previews (e.g. an invoice inside a dialog) use the same look as the printout
  var st = document.createElement('style');
  st.textContent = DOC_CSS;
  document.head.appendChild(st);

  // Header of a printable document: the station's name and address, then the title and reference rows
  function docHead(locId, title, meta) {
    var l = loc(locId) || { name: 'IPTB Sdn Bhd', addr: '' };
    return '<div class="doc-h"><div class="doc-from"><img src="' + root + 'images/buraqoil-logo.png" alt="Buraqoil">' +
      '<div><b>IPTB Sdn Bhd</b><span>' + esc(l.name) + '</span><span>' + esc(l.addr) + '</span></div></div>' +
      '<div class="doc-t"><h1>' + esc(title) + '</h1><table class="doc-meta">' +
      (meta || []).map(function (m) { return '<tr><td>' + esc(m[0]) + '</td><td><b>' + esc(m[1]) + '</b></td></tr>'; }).join('') +
      '</table></div></div>';
  }

  // Print a document without leaving the page: render it in a hidden frame and open the print dialog
  function printDoc(title, html) {
    var f = document.createElement('iframe');
    f.setAttribute('aria-hidden', 'true');
    f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
    document.body.appendChild(f);
    var w = f.contentWindow, d = w.document;
    d.open();
    d.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + esc(title) + '</title><style>' + DOC_CSS +
      '@page{size:A4;margin:14mm}body{margin:0}</style></head><body><div class="doc">' + html + '</div></body></html>');
    d.close();
    var done = false;
    function go() {
      if (done) return; done = true;
      w.addEventListener('afterprint', function () { setTimeout(function () { f.remove(); }, 100); });
      w.focus(); w.print();
      setTimeout(function () { if (f.isConnected) f.remove(); }, 60000);
    }
    var imgs = [].slice.call(d.images), left = imgs.length;
    if (!left) return go();
    imgs.forEach(function (im) {
      if (im.complete) { if (!--left) go(); }
      else im.onload = im.onerror = function () { if (!--left) go(); };
    });
    setTimeout(go, 2000);
  }

  window.LSMS = {
    LOCATIONS: LOCATIONS,
    loc: loc,
    locName: function (id) { var l = loc(id); return l ? l.name : ''; },
    locShort: function (id) { var l = loc(id); return l ? l.short : ''; },
    user: user,
    role: role,
    isAdmin: !isStaff,                              // admin (or page opened without logging in): sees every location
    myLoc: isStaff ? myLoc.id : null,               // a staff member's station; null for admin
    locs: function () { return isStaff ? [myLoc] : LOCATIONS; },   // locations this user may see
    docHead: docHead,
    print: printDoc
  };
})();
