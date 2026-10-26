(function () {
  'use strict';
  function openSettingsFromHash() {
    if (location.hash === '#sn-config') {
      var panel = document.getElementById('sn-config');
      if (panel) panel.open = true;
    }
  }
  window.addEventListener('hashchange', openSettingsFromHash);
  openSettingsFromHash();
  var search = document.getElementById('sn-plugin-search');
  var list = document.getElementById('sn-plugin-list');
  if (search && list) {
    var groups = Array.prototype.slice.call(list.querySelectorAll('[data-sn-group]'));
    var empty = document.getElementById('sn-no-results');
    function filterEntries() {
      var term = search.value.trim().toLocaleLowerCase();
      var visible = 0;
      groups.forEach(function (group) {
        var shown = 0;
        Array.prototype.slice.call(group.querySelectorAll('.sn-plugin-entry')).forEach(function (entry) {
          entry.hidden = (entry.getAttribute('data-sn-name') || '').indexOf(term) === -1;
          if (!entry.hidden) shown += 1;
        });
        group.hidden = shown === 0;
        if (term && shown) group.open = true;
        visible += shown;
      });
      if (empty) empty.hidden = visible > 0;
    }
    search.addEventListener('input', filterEntries);
    function revealLinkedComponent() {
      if (!location.hash || location.hash.indexOf('#sn-plugin-') !== 0) return;
      var entry = document.getElementById(location.hash.slice(1));
      if (!entry) return;
      var technicalPanel = entry.closest('.sn-extra');
      if (technicalPanel && technicalPanel.tagName.toLowerCase() === 'details') technicalPanel.open = true;
      if (entry.classList.contains('sn-plugin-entry') && entry.hidden) {
        search.value = '';
        filterEntries();
      }
      var group = entry.closest('[data-sn-group]');
      if (group) group.open = true;
      if (entry.tagName.toLowerCase() === 'details') entry.open = true;
      entry.scrollIntoView({block:'start', behavior:'auto'});
    }
    window.addEventListener('hashchange', revealLinkedComponent);
    revealLinkedComponent();
  }
  var scan = document.getElementById('sn-scan-now');
  var status = document.getElementById('sn-scan-progress');
  if (scan && status) {
    scan.addEventListener('click', function () {
      scan.disabled = true;
      var first = true;
      function step() {
        var data = new FormData();
        data.append('action', 'integra_sentinel_scan_site');
        data.append('nonce', scan.getAttribute('data-nonce'));
        data.append('site', scan.getAttribute('data-site'));
        if (first) data.append('reset', '1');
        first = false;
        fetch(window.ajaxurl, {method:'POST',credentials:'same-origin',body:data})
          .then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
          })
          .then(function (result) {
            if (!result.success) throw new Error(typeof result.data === 'string' ? result.data : 'Risposta non valida');
            var info = result.data;
            status.textContent = info.done + '/' + info.total + ' controllati · ' + info.vulnerabilities + ' advisory · ' + info.errors + ' verifiche senza esito';
            if (info.complete) window.location.reload();
            else step();
          })
          .catch(function (error) {
            status.textContent = 'Analisi interrotta: ' + error.message + '. Riprova.';
            scan.disabled = false;
          });
      }
      status.textContent = 'Analisi in corso…';
      step();
    });
  }
})();

/* Mappa delle dipendenze a nuclei: SVG puro, nessuna libreria esterna, dati solo via textContent. */
(function () {
  'use strict';
  var dataEl = document.getElementById('sn-graph-data');
  var svg = document.getElementById('sn-graph');
  var stage = document.getElementById('sn-graph-stage');
  var inspector = document.getElementById('sn-inspector');
  if (!dataEl || !svg || !stage || !inspector) return;
  var data;
  try { data = JSON.parse(dataEl.textContent); } catch (e) { return; }
  if (!data || !data.nodes) return;

  var NS = 'http://www.w3.org/2000/svg';
  var RANK = {ok: 0, update: 1, blind: 2, manual: 2, warn: 3, abandoned: 3, held: 4, license: 5, bottleneck: 5, blocked: 6, missing: 6};
  var EDGE_LABEL = {ok: 'Compatibile', coord: 'Da aggiornare insieme', unknown: 'Compatibilità non dichiarata', untested: 'Oltre la versione testata', order: 'Prima il nucleo', hold: 'Trattiene il nucleo', block: 'Bloccante'};
  var ROLE_RADIUS = {hub: 24, subhub: 16, addon: 10, env: 21, missing: 12, solo: 7};
  var byId = {};
  var children = {};
  data.nodes.forEach(function (n) { byId[n.id] = n; });
  data.nodes.forEach(function (n) { if (n.parent) (children[n.parent] = children[n.parent] || []).push(n.id); });
  var outEdges = {}, inEdges = {};
  data.edges.forEach(function (e) {
    (outEdges[e.s] = outEdges[e.s] || []).push(e);
    (inEdges[e.t] = inEdges[e.t] || []).push(e);
  });

  var opts = {issues: false, solo: true, inactive: true};
  var pos = {}, rendered = {}, nodeEls = {}, edgeEls = [];
  var bounds = {x: 0, y: 0, w: 1000, h: 600};
  var view = {x: 0, y: 0, w: 1000, h: 600};
  var selected = null;

  function el(name, attrs, parent) {
    var node = document.createElementNS(NS, name);
    if (attrs) Object.keys(attrs).forEach(function (k) { node.setAttribute(k, attrs[k]); });
    if (parent) parent.appendChild(node);
    return node;
  }
  function html(tag, cls, text, parent) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined && text !== null) node.textContent = text;
    if (parent) parent.appendChild(node);
    return node;
  }
  function truncate(s, max) { s = String(s || ''); return s.length > max ? s.slice(0, max - 1) + '…' : s; }
  function initials(name) {
    var words = String(name || '?').replace(/[^A-Za-z0-9À-ÿ ]+/g, ' ').trim().split(/\s+/);
    return ((words[0] || '?').charAt(0) + (words[1] ? words[1].charAt(0) : '')).toUpperCase();
  }
  function isOffLibrary(n) { return ['wporg', 'internal', 'env', 'missing'].indexOf(n.origin) === -1; }
  function versionText(n) {
    var target = n['new'] || n.latest || '';
    return (n.ver || '—') + (target ? ' → ' + target : '');
  }

  function nodeVisible(n) {
    if (!opts.inactive && !n.active && (n.role === 'addon' || n.role === 'solo')) return false;
    if (n.role === 'solo') {
      if (!opts.solo) return false;
      if (opts.issues && (RANK[n.status] || 0) < 1 && !(n.vuln && n.vuln.count)) return false;
    }
    return true;
  }
  function kids(id) { return (children[id] || []).filter(function (k) { return nodeVisible(byId[k]); }); }

  function layout() {
    pos = {};
    var clusterBoxes = [];
    (data.clusters || []).forEach(function (c) {
      if (opts.issues && (RANK[c.worst] || 0) < 3 && !c.vulns) return;
      var root = c.root;
      var leafCount = {};
      function leaves(id) {
        var k = kids(id);
        var total = k.length ? k.reduce(function (s, c2) { return s + leaves(c2); }, 0) : 1;
        leafCount[id] = total;
        return total;
      }
      var total = leaves(root);
      var r1 = Math.max(105, 66 * total / (2 * Math.PI));
      var gap = 84, maxR = 0;
      var local = {};
      local[root] = {x: 0, y: 0, a: -Math.PI / 2, d: 0};
      (function place(id, a0, a1, depth) {
        var k = kids(id);
        var tot = leafCount[id] || 1;
        k.sort(function (x, y) { return (RANK[byId[y].status] || 0) - (RANK[byId[x].status] || 0) || byId[x].name.localeCompare(byId[y].name); });
        if (depth === 0 && k.length) { // il primo addon (il piu' critico) in alto
          var shift = (a1 - a0) * (leafCount[k[0]] || 1) / tot / 2;
          a0 -= shift; a1 -= shift;
        }
        var a = a0;
        k.forEach(function (child) {
          var span = (a1 - a0) * (leafCount[child] || 1) / tot;
          var mid = a + span / 2;
          var r = r1 + (depth) * gap;
          local[child] = {x: r * Math.cos(mid), y: r * Math.sin(mid), a: mid, d: depth + 1};
          maxR = Math.max(maxR, r);
          place(child, a, a + span, depth + 1);
          a += span;
        });
      })(root, -Math.PI / 2, 3 * Math.PI / 2, 0);
      var radius = (maxR ? maxR : 30) + 66;
      clusterBoxes.push({c: c, local: local, radius: radius});
    });

    var envNodes = data.nodes.filter(function (n) { return n.role === 'env'; });
    if (envNodes.length) {
      var envLocal = {};
      envNodes.forEach(function (n, i) { envLocal[n.id] = {x: (i - (envNodes.length - 1) / 2) * 120, y: 0, a: Math.PI / 2, d: 0}; });
      clusterBoxes.unshift({c: {id: 'env', name: 'Ambiente server', verdict: 'Requisiti che bloccano aggiornamenti', verdictTone: 'danger', root: null}, local: envLocal, radius: 70 + envNodes.length * 50});
    }

    // Impaginazione a ripiani: larghezza scelta sull'area totale e sulla proporzione del riquadro.
    var gapX = 36, rowY = 0, x = 0, rowH = 0, boxes = [], area = 0, widest = 0;
    var rect = stage.getBoundingClientRect();
    var aspect = rect.width > 0 && rect.height > 0 ? Math.min(Math.max(rect.width / rect.height, 1), 2.4) : 1.65;
    clusterBoxes.sort(function (a, b) { return b.radius - a.radius; });
    clusterBoxes.forEach(function (b) { var d = b.radius * 2 + gapX; area += d * (d + 30); widest = Math.max(widest, b.radius * 2); });
    var maxWidth = Math.max(widest, 900, Math.sqrt(area * aspect) * 1.08);
    clusterBoxes.forEach(function (b) {
      var d = b.radius * 2;
      if (x > 0 && x + d > maxWidth) { rowY += rowH + gapX + 30; x = 0; rowH = 0; }
      var cx = x + b.radius, cy = rowY + b.radius + 30;
      Object.keys(b.local).forEach(function (id) {
        pos[id] = {x: cx + b.local[id].x, y: cy + b.local[id].y, a: b.local[id].a, d: b.local[id].d};
      });
      boxes.push({c: b.c, cx: cx, cy: cy, r: b.radius});
      x += d + gapX;
      rowH = Math.max(rowH, d);
    });
    var bottom = boxes.length ? rowY + rowH + 30 : 0;

    var solos = data.nodes.filter(function (n) { return n.role === 'solo' && nodeVisible(n); });
    solos.sort(function (a, b) { return (RANK[b.status] || 0) - (RANK[a.status] || 0) || a.name.localeCompare(b.name); });
    var soloBox = null;
    if (solos.length) {
      var cellW = 168, cellH = 30, cols = Math.max(1, Math.floor(maxWidth / cellW));
      var top = bottom + (boxes.length ? 50 : 10);
      solos.forEach(function (n, i) {
        pos[n.id] = {x: 14 + (i % cols) * cellW, y: top + 34 + Math.floor(i / cols) * cellH, a: 0, d: 0, solo: true};
      });
      soloBox = {x: 0, y: top, w: Math.min(solos.length, cols) * cellW, h: 34 + Math.ceil(solos.length / cols) * cellH, count: solos.length};
      bottom = top + soloBox.h;
    }
    bounds = {x: -40, y: -20, w: maxWidth + 80, h: Math.max(bottom, 200) + 80};
    return {boxes: boxes, soloBox: soloBox};
  }

  function draw() {
    while (svg.firstChild) svg.removeChild(svg.firstChild);
    nodeEls = {}; edgeEls = []; rendered = {};
    var scene = layout();
    var gClusters = el('g', {'class': 'sn-g-clusters'}, svg);
    var gEdges = el('g', {'class': 'sn-g-edges'}, svg);
    var gNodes = el('g', {'class': 'sn-g-nodes'}, svg);

    scene.boxes.forEach(function (b) {
      el('circle', {cx: b.cx, cy: b.cy, r: b.r - 22, 'class': 'sn-nucleus sn-tone-' + (b.c.verdictTone || 'ok')}, gClusters);
      var t = el('text', {x: b.cx, y: b.cy - b.r + 6, 'class': 'sn-nucleus-title', 'text-anchor': 'middle'}, gClusters);
      t.textContent = truncate(b.c.name, 34);
      var v = el('text', {x: b.cx, y: b.cy - b.r + 22, 'class': 'sn-nucleus-verdict sn-tone-' + (b.c.verdictTone || 'ok'), 'text-anchor': 'middle'}, gClusters);
      v.textContent = truncate(b.c.verdict, 58);
    });
    if (scene.soloBox) {
      var st = el('text', {x: 0, y: scene.soloBox.y + 14, 'class': 'sn-nucleus-title'}, gClusters);
      st.textContent = 'Plugin indipendenti (' + scene.soloBox.count + ')';
      el('line', {x1: 0, y1: scene.soloBox.y + 22, x2: scene.soloBox.w, y2: scene.soloBox.y + 22, 'class': 'sn-solo-rule'}, gClusters);
    }

    data.edges.forEach(function (e) {
      if (!pos[e.s] || !pos[e.t]) return;
      var a = pos[e.s], b = pos[e.t];
      var primary = byId[e.s] && byId[e.s].parent === e.t;
      var d;
      if (primary) {
        d = 'M' + a.x.toFixed(1) + ' ' + a.y.toFixed(1) + 'L' + b.x.toFixed(1) + ' ' + b.y.toFixed(1);
      } else {
        var mx = (a.x + b.x) / 2, my = (a.y + b.y) / 2, dx = b.x - a.x, dy = b.y - a.y;
        var len = Math.sqrt(dx * dx + dy * dy) || 1, bend = Math.min(90, len * 0.25);
        d = 'M' + a.x.toFixed(1) + ' ' + a.y.toFixed(1) + 'Q' + (mx - dy / len * bend).toFixed(1) + ' ' + (my + dx / len * bend).toFixed(1) + ' ' + b.x.toFixed(1) + ' ' + b.y.toFixed(1);
      }
      var path = el('path', {d: d, 'class': 'sn-edge sn-e-' + e.state + (e.conf <= 1 ? ' is-weak' : '') + (primary ? '' : ' is-secondary') + (byId[e.t] && byId[e.t].role === 'env' ? ' is-env' : '')}, gEdges);
      var title = el('title', null, path);
      title.textContent = (byId[e.s] ? byId[e.s].name : e.s) + ' → ' + (byId[e.t] ? byId[e.t].name : e.t) + ': ' + (EDGE_LABEL[e.state] || e.state) + ' (' + e.confLabel + ')';
      edgeEls.push({e: e, el: path});
    });

    data.nodes.forEach(function (n) {
      var p = pos[n.id];
      if (!p) return;
      rendered[n.id] = true;
      var r = ROLE_RADIUS[n.role] || 9;
      var g = el('g', {
        'class': 'sn-node sn-st-' + n.status + ' sn-role-' + n.role + (n.active ? '' : ' is-inactive'),
        transform: 'translate(' + p.x.toFixed(1) + ' ' + p.y.toFixed(1) + ')',
        tabindex: '0', role: 'button',
        'aria-label': n.name + ', ' + n.statusLabel + ', ' + versionText(n) + (isOffLibrary(n) ? ', ' + n.originLabel : '')
      }, gNodes);
      g.setAttribute('data-id', n.id);
      if (n.vuln && n.vuln.count) el('circle', {r: r + (isOffLibrary(n) ? 9 : 5), 'class': 'sn-ring-vuln'}, g);
      if (isOffLibrary(n)) el('circle', {r: r + 4, 'class': 'sn-ring-premium' + (n.envato ? ' is-envato' : '')}, g);
      el('circle', {r: r, 'class': 'sn-n'}, g);
      if (r >= 16) {
        var ini = el('text', {'class': 'sn-n-ini', 'text-anchor': 'middle', dy: '0.35em'}, g);
        ini.textContent = n.role === 'env' ? (n.id === 'env:php' ? 'PHP' : 'WP') : initials(n.name);
      }
      if (n['new']) {
        var bx = r * 0.78, by = -r * 0.78;
        el('circle', {cx: bx, cy: by, r: 6.5, 'class': 'sn-n-badge'}, g);
        var arrow = el('text', {x: bx, y: by, 'class': 'sn-n-badge-t', 'text-anchor': 'middle', dy: '0.33em'}, g);
        arrow.textContent = '↑';
      }
      var label = el('text', {'class': 'sn-n-label' + (n.role === 'hub' || n.role === 'env' ? ' is-hub' : '')}, g);
      if (p.solo) {
        label.setAttribute('x', r + 7); label.setAttribute('dy', '0.35em');
        label.textContent = truncate(n.name, 21);
      } else if (n.role === 'hub' || n.role === 'env' || p.d === 0) {
        label.setAttribute('y', r + 16); label.setAttribute('text-anchor', 'middle');
        label.textContent = truncate(n.name, 26);
      } else {
        var c = Math.cos(p.a), s = Math.sin(p.a);
        label.setAttribute('x', (c * (r + 7)).toFixed(1));
        label.setAttribute('y', (s * (r + 7)).toFixed(1));
        label.setAttribute('dy', s > 0.5 ? '0.9em' : (s < -0.5 ? '-0.25em' : '0.35em'));
        label.setAttribute('text-anchor', c > 0.25 ? 'start' : (c < -0.25 ? 'end' : 'middle'));
        label.textContent = truncate(n.name, 22);
      }
      var tip = el('title', null, g);
      tip.textContent = n.name + ' · ' + n.statusLabel + ' · ' + versionText(n);
      g.addEventListener('click', function (ev) { ev.stopPropagation(); select(n.id); });
      g.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); select(n.id); }
      });
      g.addEventListener('mouseenter', function () { highlight(n.id, true); });
      g.addEventListener('mouseleave', function () { highlight(selected, false); });
      g.addEventListener('focus', function () { highlight(n.id, true); });
      g.addEventListener('blur', function () { highlight(selected, false); });
      nodeEls[n.id] = g;
    });
    if (!Object.keys(rendered).length) {
      var none = el('text', {x: bounds.x + bounds.w / 2, y: 80, 'text-anchor': 'middle', 'class': 'sn-nucleus-verdict'}, svg);
      none.textContent = 'Nessun elemento con i filtri attuali.';
    }
    if (selected && !rendered[selected]) selected = null;
    highlight(selected, false);
  }

  function neighbours(id) {
    var set = {};
    set[id] = true;
    (outEdges[id] || []).forEach(function (e) { set[e.t] = true; });
    (inEdges[id] || []).forEach(function (e) { set[e.s] = true; });
    return set;
  }
  function highlight(id, transient) {
    svg.classList.toggle('has-focus', !!id);
    var near = id ? neighbours(id) : {};
    Object.keys(nodeEls).forEach(function (k) {
      nodeEls[k].classList.toggle('is-near', !!near[k]);
      nodeEls[k].classList.toggle('is-selected', k === selected);
    });
    edgeEls.forEach(function (x) { x.el.classList.toggle('is-near', !!id && (x.e.s === id || x.e.t === id)); });
    if (!transient && !id) svg.classList.remove('has-focus');
  }

  /* --- Ispettore --- */
  function chip(text, cls, parent) { return html('span', 'sn-badge sn-' + cls, text, parent); }
  var STATUS_TONE = {ok: 'ok', update: 'brand', manual: 'premium', warn: 'warn', held: 'risk', bottleneck: 'danger', blocked: 'danger', missing: 'danger', license: 'license', blind: 'muted', abandoned: 'warn'};
  function linkTo(id, parent) {
    var n = byId[id];
    var b = html('button', 'sn-linkbtn', n ? n.name : id, parent);
    b.type = 'button';
    b.addEventListener('click', function () { focusNode(id, false); });
    return b;
  }
  function renderInspector(n) {
    while (inspector.firstChild) inspector.removeChild(inspector.firstChild);
    if (!n) {
      if (inspector._empty) inspector.appendChild(inspector._empty);
      return;
    }
    var head = html('div', 'sn-insp-head', null, inspector);
    html('h4', null, n.name, head);
    var close = html('button', 'sn-insp-close', '×', head);
    close.type = 'button';
    close.setAttribute('aria-label', 'Chiudi dettaglio');
    close.addEventListener('click', function () { select(null); });
    var chips = html('div', 'sn-insp-chips', null, inspector);
    chip(n.statusLabel, STATUS_TONE[n.status] || 'muted', chips);
    if (n.envato) chip('Envato', 'premium', chips);
    if (!n.active && n.role !== 'env' && n.role !== 'missing') chip('Inattivo', 'muted', chips);
    if (n.vuln && n.vuln.count) chip(n.vuln.count + ' vulnerabilità', n.vuln.tier || 'danger', chips);

    var dl = html('dl', 'sn-insp-facts', null, inspector);
    function fact(k, v) { if (!v) return; html('dt', null, k, dl); html('dd', null, v, dl); }
    fact('Installata', n.ver || '—');
    if (n['new']) fact('Aggiornamento', n['new']);
    else if (n.latest) fact('Ultima nota', n.latest + ' (' + n.latestSrc + ')');
    fact('Origine', n.originLabel + (n.originHint ? ' — ' + n.originHint : ''));
    var role = {hub: 'Nucleo principale', subhub: 'Addon e nucleo secondario', addon: 'Addon', solo: 'Indipendente', env: 'Requisito di ambiente', missing: 'Dipendenza non installata'}[n.role];
    fact('Ruolo', role + (children[n.id] ? ' · ' + children[n.id].length + ' collegati' : ''));

    if (n.reasons && n.reasons.length) {
      html('h5', null, 'Perché', inspector);
      var ul = html('ul', 'sn-insp-reasons', null, inspector);
      n.reasons.forEach(function (r) { html('li', null, r, ul); });
    }
    function edgeList(title, edges, other) {
      if (!edges || !edges.length) return;
      html('h5', null, title + ' (' + edges.length + ')', inspector);
      var ul2 = html('ul', 'sn-insp-edges', null, inspector);
      edges.slice().sort(function (a, b) { return (b.state === 'ok' ? 0 : 1) - (a.state === 'ok' ? 0 : 1); }).forEach(function (e) {
        var li = html('li', 'sn-e-li-' + e.state, null, ul2);
        linkTo(e[other], li);
        html('span', 'sn-insp-edge-state', (EDGE_LABEL[e.state] || e.state) + ' · ' + e.confLabel, li);
        if (e.notes && e.notes.length) html('small', null, e.notes[0], li);
      });
    }
    edgeList('Dipende da', outEdges[n.id], 't');
    edgeList('Usato da', inEdges[n.id], 's');
    if (n.role !== 'env' && n.role !== 'missing') {
      var a = html('a', 'sn-insp-more', 'Apri la scheda del plugin ↓', inspector);
      a.href = '#sn-plugin-' + String(n.id).replace(/[^A-Za-z0-9_-]/g, '');
    }
  }

  function select(id) {
    selected = id && byId[id] ? id : null;
    highlight(selected, false);
    renderInspector(selected ? byId[selected] : null);
  }

  /* --- Vista, pan e zoom --- */
  function applyView() {
    svg.setAttribute('viewBox', view.x.toFixed(1) + ' ' + view.y.toFixed(1) + ' ' + view.w.toFixed(1) + ' ' + view.h.toFixed(1));
  }
  function fit() {
    var rect = stage.getBoundingClientRect();
    var ratio = rect.width > 0 && rect.height > 0 ? rect.height / rect.width : 0.55;
    view.w = bounds.w; view.h = bounds.w * ratio;
    if (view.h < bounds.h) { view.h = bounds.h; view.w = bounds.h / ratio; }
    view.x = bounds.x - (view.w - bounds.w) / 2;
    view.y = bounds.y;
    applyView();
  }
  function zoom(factor, cx, cy) {
    var nw = Math.min(Math.max(view.w * factor, 220), Math.max(bounds.w, bounds.h) * 3);
    var f = nw / view.w;
    if (cx === undefined) { cx = view.x + view.w / 2; cy = view.y + view.h / 2; }
    view.x = cx - (cx - view.x) * f;
    view.y = cy - (cy - view.y) * f;
    view.w = nw; view.h = view.h * f;
    applyView();
  }
  function center(id) {
    var p = pos[id];
    if (!p) return;
    if (view.w > 900) zoom(900 / view.w);
    view.x = p.x - view.w / 2; view.y = p.y - view.h / 2;
    applyView();
  }
  function toSvg(clientX, clientY) {
    var ctm = svg.getScreenCTM();
    if (!ctm) return {x: view.x, y: view.y};
    var pt = svg.createSVGPoint();
    pt.x = clientX; pt.y = clientY;
    var r = pt.matrixTransform(ctm.inverse());
    return {x: r.x, y: r.y};
  }
  svg.addEventListener('wheel', function (ev) {
    if (!ev.ctrlKey && !ev.metaKey) return; // la rotella semplice scorre la pagina
    ev.preventDefault();
    var p = toSvg(ev.clientX, ev.clientY);
    zoom(ev.deltaY > 0 ? 1.15 : 1 / 1.15, p.x, p.y);
  }, {passive: false});
  var drag = null;
  svg.addEventListener('pointerdown', function (ev) {
    if (ev.button !== 0 || (ev.target.closest && ev.target.closest('.sn-node'))) return;
    drag = {x: ev.clientX, y: ev.clientY, vx: view.x, vy: view.y, moved: false};
    svg.setPointerCapture(ev.pointerId);
  });
  svg.addEventListener('pointermove', function (ev) {
    if (!drag) return;
    var ctm = svg.getScreenCTM();
    var scale = ctm ? ctm.a : 1;
    var dx = (ev.clientX - drag.x) / scale, dy = (ev.clientY - drag.y) / scale;
    if (Math.abs(dx) + Math.abs(dy) > 3) { drag.moved = true; svg.classList.add('is-dragging'); }
    view.x = drag.vx - dx; view.y = drag.vy - dy;
    applyView();
  });
  function endDrag(ev) {
    if (!drag) return;
    if (!drag.moved && ev.type === 'pointerup') select(null);
    drag = null;
    svg.classList.remove('is-dragging');
  }
  svg.addEventListener('pointerup', endDrag);
  svg.addEventListener('pointercancel', endDrag);
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && selected) select(null);
  });
  Array.prototype.forEach.call(document.querySelectorAll('[data-sn-zoom]'), function (b) {
    b.addEventListener('click', function () {
      var k = b.getAttribute('data-sn-zoom');
      if (k === 'fit') fit(); else zoom(k === 'in' ? 1 / 1.3 : 1.3);
    });
  });

  /* --- Filtri, ricerca, schede --- */
  function bindToggle(id, key) {
    var input = document.getElementById(id);
    if (!input) return;
    opts[key] = input.checked;
    input.addEventListener('change', function () { opts[key] = input.checked; draw(); fit(); });
  }
  bindToggle('sn-graph-issues', 'issues');
  bindToggle('sn-graph-solo', 'solo');
  bindToggle('sn-graph-inactive', 'inactive');

  var find = document.getElementById('sn-graph-find');
  var names = document.getElementById('sn-graph-names');
  if (names) {
    data.nodes.slice().sort(function (a, b) { return a.name.localeCompare(b.name); }).forEach(function (n) {
      var o = document.createElement('option');
      o.value = n.name;
      names.appendChild(o);
    });
  }
  if (find) {
    var lookup = function () {
      var term = find.value.trim().toLocaleLowerCase();
      if (term.length < 2) return;
      var hit = null;
      data.nodes.forEach(function (n) {
        var name = n.name.toLocaleLowerCase();
        if (!hit && name === term) hit = n;
      });
      if (!hit) data.nodes.forEach(function (n) { if (!hit && (n.name.toLocaleLowerCase().indexOf(term) !== -1 || n.id.indexOf(term) !== -1)) hit = n; });
      if (hit) focusNode(hit.id, false);
    };
    find.addEventListener('change', lookup);
    find.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); lookup(); } });
  }

  var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-sn-tab]'));
  function showTab(key, focusTab) {
    tabs.forEach(function (t) {
      var on = t.getAttribute('data-sn-tab') === key;
      t.classList.toggle('is-selected', on);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.tabIndex = on ? 0 : -1;
      var panel = document.getElementById(t.getAttribute('aria-controls'));
      if (panel) panel.hidden = !on;
      if (on && focusTab) t.focus();
    });
    if (key === 'graph') fit();
  }
  tabs.forEach(function (t, i) {
    t.addEventListener('click', function () { showTab(t.getAttribute('data-sn-tab'), false); });
    t.addEventListener('keydown', function (ev) {
      var dir = ev.key === 'ArrowRight' ? 1 : (ev.key === 'ArrowLeft' ? -1 : 0);
      if (!dir) return;
      ev.preventDefault();
      showTab(tabs[(i + dir + tabs.length) % tabs.length].getAttribute('data-sn-tab'), true);
    });
  });

  // La mappa vive nei "Dettagli tecnici", chiusi all'apertura della scheda: si adatta quando vengono aperti.
  var techPanel = stage.closest('details');
  if (techPanel) techPanel.addEventListener('toggle', function () { if (techPanel.open) fit(); });
  function focusNode(id, scroll) {
    if (techPanel && !techPanel.open) { techPanel.open = true; fit(); }
    if (!byId[id]) return;
    showTab('graph', false);
    if (!rendered[id]) {
      ['sn-graph-issues', 'sn-graph-solo', 'sn-graph-inactive'].forEach(function (inputId) {
        var input = document.getElementById(inputId);
        if (input) input.checked = inputId !== 'sn-graph-issues';
      });
      opts = {issues: false, solo: true, inactive: true};
      draw(); fit();
    }
    select(id);
    center(id);
    if (scroll) document.getElementById('sn-deps').scrollIntoView({block: 'start', behavior: 'smooth'});
    if (nodeEls[id]) nodeEls[id].focus({preventScroll: true});
  }
  Array.prototype.forEach.call(document.querySelectorAll('[data-sn-focus]'), function (b) {
    b.addEventListener('click', function () { focusNode(b.getAttribute('data-sn-focus'), true); });
  });

  inspector._empty = inspector.firstElementChild;
  stage.classList.add('is-ready');
  draw();
  fit();
  window.addEventListener('resize', function () { if (!stage.closest('[hidden]')) fit(); });
})();

/* 1.8: filtri del centro colli di bottiglia, ricerca nelle tabelle, filtri dei siti, sparkline, copia testi. */
(function () {
  'use strict';
  function all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function lower(s) { return String(s || '').toLocaleLowerCase(); }

  // Centro colli di bottiglia: ricerca per sito, componente o problema.
  all('[data-sn-center]').forEach(function (center) {
    var search = center.querySelector('[data-sn-center-search]');
    var empty = center.querySelector('[data-sn-center-empty]');
    function apply() {
      var term = search ? lower(search.value.trim()) : '';
      var visible = 0;
      all('.sn-kind', center).forEach(function (kind) {
        var shown = 0;
        all('.sn-group', kind).forEach(function (g) {
          var ok = !term || lower(g.getAttribute('data-sn-text')).indexOf(term) !== -1;
          g.hidden = !ok;
          if (ok) shown += 1;
        });
        kind.hidden = shown === 0;
        if (shown && term) kind.open = true;
        visible += shown;
      });
      if (empty) empty.hidden = visible > 0;
    }
    if (search) search.addEventListener('input', apply);
    // Apertura della riga indicata nell'URL (#sn-i-...), arrivando dalla scheda di un sito.
    function openFromHash() {
      if (!location.hash || location.hash.indexOf('#sn-i-') !== 0) return;
      var g = document.getElementById(location.hash.slice(1));
      if (!g || !center.contains(g)) return;
      var kind = g.closest('.sn-kind');
      if (kind) { kind.hidden = false; kind.open = true; }
      g.hidden = false;
      g.classList.add('is-target');
      g.scrollIntoView({block: 'center'});
    }
    window.addEventListener('hashchange', openFromHash);
    openFromHash();
  });

  // Plugin e temi del parco: schede Plugin/Temi, siti accesi o spenti e ricerca. Le colonne si ricalcolano sui siti accesi.
  all('[data-sn-fleet]').forEach(function (root) {
    var chips = all('[data-sn-fleet-site]', root);
    var tabs = all('[data-sn-ftab]', root);
    var search = root.querySelector('[data-sn-fleet-search]');
    var counter = root.querySelector('[data-sn-fleet-count]');
    var STORE = 'snFleetOff';
    var off = {};
    try { (JSON.parse(localStorage.getItem(STORE) || '[]') || []).forEach(function (id) { off[id] = true; }); } catch (e) {}
    function cmp(a, b) {
      var x = String(a).replace(/^v/i, '').split(/[.\-+]/), y = String(b).replace(/^v/i, '').split(/[.\-+]/);
      for (var i = 0; i < Math.max(x.length, y.length); i++) {
        var p = parseInt(x[i] || '0', 10), q = parseInt(y[i] || '0', 10);
        if (isNaN(p) || isNaN(q)) { var c = String(x[i] || '').localeCompare(String(y[i] || '')); if (c) return c; continue; }
        if (p !== q) return p < q ? -1 : 1;
      }
      return 0;
    }
    function badge(text, tone) { var s = document.createElement('span'); s.className = 'sn-badge sn-' + tone; s.textContent = text; return s; }
    function recompute(row) {
      var data; try { data = JSON.parse(row.getAttribute('data-sn-sites') || '{}'); } catch (e) { data = {}; }
      var n = 0, names = [], versions = {}, behind = 0, vuln = 0, blocked = 0, lic = 0, blind = 0;
      Object.keys(data).forEach(function (id) {
        if (off[id]) return;
        var s = data[id]; n += 1; names.push(s.l);
        if (s.v) versions[s.v] = true;
        behind += s.b; vuln += s.x; blocked += s.k; lic += s.c; blind += s.u;
      });
      row.setAttribute('data-sn-n', n);
      if (!n) return;
      names.sort(function (a, b) { return a.localeCompare(b); });
      var vl = Object.keys(versions).sort(cmp);
      row.querySelector('[data-sn-col="n"]').textContent = n;
      row.querySelector('[data-sn-col="names"]').textContent = names.slice(0, 4).join(', ') + (names.length > 4 ? ' +' + (names.length - 4) : '');
      row.querySelector('[data-sn-col="range"]').textContent = vl.length ? (vl.length > 1 ? vl[0] + ' – ' + vl[vl.length - 1] + ' (' + vl.length + ' versioni)' : vl[0]) : '—';
      var bc = row.querySelector('[data-sn-col="behind"]'); bc.textContent = '';
      if (behind) { var st = document.createElement('strong'); st.textContent = behind; bc.appendChild(st); } else { bc.textContent = '0'; }
      var ic = row.querySelector('[data-sn-col="issues"]'); ic.textContent = '';
      [[vuln, ' vulnerabili', 'danger'], [blocked, ' bloccati', 'high'], [lic, ' licenza', 'premium'], [blind, ' versione ignota', 'muted']].forEach(function (b) {
        if (b[0]) { ic.appendChild(badge(b[0] + b[1], b[2])); ic.appendChild(document.createTextNode(' ')); }
      });
    }
    function apply() {
      var term = search ? lower(search.value.trim()) : '';
      var active = chips.filter(function (c) { return !off[c.getAttribute('data-sn-fleet-site')]; }).length;
      if (counter) counter.textContent = active + ' di ' + chips.length + ' attivi';
      chips.forEach(function (c) { var on = !off[c.getAttribute('data-sn-fleet-site')]; c.classList.toggle('is-selected', on); c.setAttribute('aria-pressed', on ? 'true' : 'false'); });
      tabs.forEach(function (t) {
        var type = t.getAttribute('data-sn-ftab');
        var panel = document.getElementById('sn-fpanel-' + type);
        var visible = 0;
        all('tbody tr', panel).forEach(function (row) {
          recompute(row);
          var ok = row.getAttribute('data-sn-n') !== '0' && (!term || lower(row.getAttribute('data-sn-name')).indexOf(term) !== -1);
          row.hidden = !ok;
          if (ok) visible += 1;
        });
        var cnt = root.querySelector('[data-sn-ftab-count="' + type + '"]'); if (cnt) cnt.textContent = visible;
        var empty = panel.querySelector('[data-sn-fleet-empty]'); if (empty) empty.hidden = visible > 0;
      });
      try { localStorage.setItem(STORE, JSON.stringify(Object.keys(off))); } catch (e) {}
    }
    chips.forEach(function (c) {
      c.addEventListener('click', function () { var id = c.getAttribute('data-sn-fleet-site'); if (off[id]) delete off[id]; else off[id] = true; apply(); });
    });
    all('[data-sn-fleet-all]', root).forEach(function (b) {
      b.addEventListener('click', function () {
        off = {};
        if (b.getAttribute('data-sn-fleet-all') === '0') chips.forEach(function (c) { off[c.getAttribute('data-sn-fleet-site')] = true; });
        apply();
      });
    });
    tabs.forEach(function (t) {
      t.addEventListener('click', function () {
        tabs.forEach(function (o) {
          var on = o === t;
          o.classList.toggle('is-selected', on); o.setAttribute('aria-selected', on ? 'true' : 'false');
          document.getElementById('sn-fpanel-' + o.getAttribute('data-sn-ftab')).hidden = !on;
        });
      });
    });
    if (search) search.addEventListener('input', apply);
    // Siti rimossi dal Master: non restano spenti per sempre.
    var known = {}; chips.forEach(function (c) { known[c.getAttribute('data-sn-fleet-site')] = true; });
    Object.keys(off).forEach(function (id) { if (!known[id]) delete off[id]; });
    apply();
  });

  // Filtri delle schede dei siti in panoramica.
  var grid = document.getElementById('sn-site-grid');
  if (grid) {
    var siteSearch = document.querySelector('[data-sn-site-search]');
    var tagSelect = document.querySelector('[data-sn-site-tag]');
    var buttons = all('[data-sn-site-filter]');
    var current = 'all';
    var emptySites = document.getElementById('sn-sites-empty');
    function applySites() {
      var term = siteSearch ? lower(siteSearch.value.trim()) : '';
      var tag = tagSelect ? tagSelect.value : '';
      var visible = 0;
      all('.sn-site-card', grid).forEach(function (card) {
        var states = ' ' + (card.getAttribute('data-sn-state') || '') + ' ';
        var tags = ' ' + (card.getAttribute('data-sn-tags') || '') + ' ';
        var ok = (current === 'all' || states.indexOf(' ' + current + ' ') !== -1)
          && (!tag || tags.indexOf(' ' + tag + ' ') !== -1)
          && (!term || lower(card.getAttribute('data-sn-name')).indexOf(term) !== -1);
        card.hidden = !ok;
        if (ok) visible += 1;
      });
      if (emptySites) emptySites.hidden = visible > 0;
    }
    buttons.forEach(function (b) {
      b.addEventListener('click', function () {
        current = b.getAttribute('data-sn-site-filter');
        buttons.forEach(function (o) { var on = o === b; o.classList.toggle('is-selected', on); o.setAttribute('aria-pressed', on ? 'true' : 'false'); });
        applySites();
      });
    });
    if (siteSearch) siteSearch.addEventListener('input', applySites);
    if (tagSelect) tagSelect.addEventListener('change', applySites);
  }

  // Sparkline: mirino che aggancia il giorno piu' vicino, tooltip con valore e data (anche da tastiera).
  all('[data-sn-spark]').forEach(function (wrap) {
    var data;
    try { data = JSON.parse(wrap.getAttribute('data-sn-spark')); } catch (e) { return; }
    if (!data || data.length < 2) return;
    var svg = wrap.querySelector('svg');
    var cross = wrap.querySelector('.sn-spark-cross');
    var dot = wrap.querySelector('.sn-spark-hover');
    var tip = wrap.querySelector('.sn-spark-tip');
    var index = data.length - 1;
    function show(i) {
      index = Math.max(0, Math.min(data.length - 1, i));
      var p = data[index];
      cross.setAttribute('x1', p.x); cross.setAttribute('x2', p.x); cross.hidden = false;
      dot.setAttribute('cx', p.x); dot.setAttribute('cy', p.y); dot.hidden = false;
      while (tip.firstChild) tip.removeChild(tip.firstChild);
      var strong = document.createElement('strong');
      strong.textContent = String(p.v);
      var span = document.createElement('span');
      span.textContent = p.d;
      tip.appendChild(strong); tip.appendChild(span);
      tip.hidden = false;
      var box = svg.getBoundingClientRect();
      var vb = svg.viewBox.baseVal;
      var left = vb && vb.width ? (p.x / vb.width) * box.width : 0;
      tip.style.left = Math.max(0, Math.min(box.width - 70, left - 30)) + 'px';
    }
    function hide() { cross.hidden = true; dot.hidden = true; tip.hidden = true; }
    svg.addEventListener('pointermove', function (ev) {
      var box = svg.getBoundingClientRect();
      var vb = svg.viewBox.baseVal;
      var x = (ev.clientX - box.left) / box.width * (vb && vb.width ? vb.width : box.width);
      var best = 0, dist = Infinity;
      data.forEach(function (p, i) { var d = Math.abs(p.x - x); if (d < dist) { dist = d; best = i; } });
      show(best);
    });
    svg.addEventListener('pointerleave', hide);
    svg.addEventListener('focus', function () { show(index); });
    svg.addEventListener('blur', hide);
    svg.addEventListener('keydown', function (ev) {
      if (ev.key === 'ArrowLeft') { ev.preventDefault(); show(index - 1); }
      if (ev.key === 'ArrowRight') { ev.preventDefault(); show(index + 1); }
    });
  });

  // Modifiche non salvate: avviso prima di lasciare la pagina o di inviare un altro modulo (che ricaricherebbe la pagina).
  var dirtyForms = [];
  all('.sn-app form').forEach(function (form) {
    form.addEventListener('input', function (ev) {
      if (ev.target.type === 'search') return;
      if (dirtyForms.indexOf(form) === -1) dirtyForms.push(form);
    });
    form.addEventListener('submit', function (ev) {
      var others = dirtyForms.filter(function (f) { return f !== form; });
      if (others.length && !window.confirm('Ci sono modifiche non salvate in un’altra sezione della pagina: andranno perse. Continuare?')) {
        ev.preventDefault();
        ev.stopImmediatePropagation();
        return;
      }
      dirtyForms = [];
      window.snSubmitting = true;
    });
  });
  window.addEventListener('beforeunload', function (ev) {
    if (window.snSubmitting || !dirtyForms.length) return;
    ev.preventDefault();
    ev.returnValue = '';
  });

  // Pulsanti che avviano operazioni lunghe (bozza scritta dall'IA): disattivati con un messaggio d'attesa.
  all('[data-sn-busy]').forEach(function (button) {
    var form = button.form;
    if (!form) return;
    form.addEventListener('submit', function () {
      setTimeout(function () { button.disabled = true; button.textContent = button.getAttribute('data-sn-busy'); }, 0);
    });
  });
})();
