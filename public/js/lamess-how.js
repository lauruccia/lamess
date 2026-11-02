(function () {
  // ---- simulazione «Prova tu» ----
  var sim = document.getElementById('sim');
  if (sim) {
    var cfg;
    try { cfg = JSON.parse(sim.getAttribute('data-plan')); } catch (e) { cfg = null; }
    if (cfg && cfg.tiers && cfg.tiers.length) {
      var MAX_ME = 100, MAX_TEAM = 6, MAX_MEMBER = 12;
      var me = 0, team = [];
      var $ = function (id) { return document.getElementById(id); };
      var rng = $('meRange'), meN = $('meN'), tree = $('tree');
      var pearl = null;
      cfg.ranks.forEach(function (r) { if (r.code === cfg.pearl) { pearl = r; } });
      var pearlClients = pearl ? Math.max(1, pearl.clients) : 3;
      var pearlColor = pearl ? pearl.color : '#C8B6A6', grey = '#BBB4AF';

      var fmt = function (n) { return String(n).replace('.', ','); };
      var eur = function (n) { return n.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
      var esc = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
      var art = function (n) { var s = String(n); return (s.charAt(0) === '8' || s.indexOf('11') === 0) ? 'l’' : 'il '; };
      var plural = function (n, one, many) { return n + ' ' + (n === 1 ? one : many); };

      function tierFor(n) {
        var t = cfg.tiers[0];
        cfg.tiers.forEach(function (x) { if (n >= x.clients) { t = x; } });
        return t;
      }
      function nextTier(n, cur) {
        for (var i = 0; i < cfg.tiers.length; i++) {
          if (cfg.tiers[i].clients > n && cfg.tiers[i].pct > cur.pct) { return cfg.tiers[i]; }
        }
        return null;
      }
      function myRank(pearls) {
        var got = null;
        for (var i = 0; i < cfg.ranks.length; i++) {
          var r = cfg.ranks[i];
          if (!r.simple) { break; }
          if (me >= r.clients && pearls >= r.pearl) { got = r; } else { break; }
        }
        return got;
      }

      function render() {
        var pearls = team.filter(function (c) { return c >= pearlClients; }).length;
        var teamClients = team.reduce(function (a, b) { return a + b; }, 0);
        var tier = tierFor(me), nxt = nextTier(me, tier), rk = myRank(pearls);
        var col = rk ? rk.color : grey;

        meN.textContent = me; rng.value = me;
        $('teamHint').textContent = team.length === 0 ? 'Nessun incaricato nel tuo team, per ora.' : plural(team.length, 'incaricato', 'incaricati') + ' nel tuo team, ' + plural(pearls, 'con qualifica ' + (pearl ? pearl.name : 'Pearl'), 'con qualifica ' + (pearl ? pearl.name : 'Pearl')) + '.';
        $('addMember').disabled = team.length >= MAX_TEAM;

        // albero
        var dots = '', shown = Math.min(me, 30);
        for (var i = 0; i < shown; i++) { dots += '<i class="cl" aria-hidden="true"></i>'; }
        if (me > shown) { dots += '<em>+' + (me - shown) + '</em>'; }
        var h = '<div class="tr-me"><i class="dot big" style="--c:' + col + '"></i><div><b>Tu</b><span>' + esc(rk ? rk.name : 'Incaricato') + ' · ' + plural(me, 'cliente', 'clienti') + '</span></div></div>';
        h += '<div class="tr-cl" aria-hidden="true">' + (dots || '<small>Ancora nessun cliente</small>') + '</div>';
        if (team.length) {
          h += '<ul class="tr-team">';
          team.forEach(function (c, i) {
            var isP = c >= pearlClients;
            h += '<li><i class="dot" style="--c:' + (isP ? pearlColor : grey) + '"></i><div><b>Incaricato ' + (i + 1) + '</b><span>' + (isP ? (pearl ? pearl.name : 'Pearl') + ' · ' : '') + plural(c, 'cliente', 'clienti') + '</span></div>' +
              '<div class="stp sm" role="group" aria-label="Clienti dell’incaricato ' + (i + 1) + '"><button type="button" data-m="' + i + '" data-d="-1" aria-label="Un cliente in meno">−</button><button type="button" data-m="' + i + '" data-d="1" aria-label="Un cliente in più">+</button></div>' +
              '<button type="button" class="rm" data-rm="' + i + '" aria-label="Togli l’incaricato ' + (i + 1) + '">×</button></li>';
          });
          h += '</ul>';
        }
        tree.innerHTML = h;

        // risultati
        $('outRank').innerHTML = '<i class="dot" style="--c:' + col + '"></i> ' + esc(rk ? rk.name : 'Incaricato');
        $('outPct').textContent = fmt(tier.pct) + '%';
        $('outNext').textContent = nxt ? 'Ancora ' + plural(nxt.clients - me, 'cliente', 'clienti') + ' per passare al ' + fmt(nxt.pct) + '%.' : 'Hai raggiunto lo scaglione più alto.';
        var e = $('outEur');
        if (cfg.euro && me > 0 && cfg.box > 0) {
          e.hidden = false;
          e.textContent = 'Esempio: su una box da ' + eur(cfg.box) + ' € la percentuale vale circa ' + eur(cfg.box * cfg.vap * tier.pct / 100) + ' € per ogni box di un tuo cliente.';
        } else { e.hidden = true; }
        var l1 = cfg.levels[0], ind = $('outInd');
        if (!l1) { ind.textContent = ''; }
        else if (team.length === 0) { ind.textContent = 'Aggiungi un incaricato: i clienti del tuo team contano anche per te, fino a ' + cfg.levels.length + ' livelli.'; }
        else if (me >= l1.clients) { ind.textContent = 'Sui ' + plural(teamClients, 'cliente', 'clienti') + ' del tuo team ricevi anche ' + art(l1.pct) + fmt(l1.pct) + '% (1° livello).'; }
        else { ind.textContent = 'Per ricevere ' + art(l1.pct) + fmt(l1.pct) + '% sul 1° livello ti serve almeno ' + plural(l1.clients, 'cliente tuo', 'clienti tuoi') + '.'; }
      }

      function setMe(n) { me = Math.max(0, Math.min(MAX_ME, n)); render(); }
      sim.addEventListener('click', function (ev) {
        var b = ev.target.closest('button'); if (!b || !sim.contains(b)) { return; }
        if (b.hasAttribute('data-me')) { setMe(me + parseInt(b.getAttribute('data-me'), 10)); }
        else if (b.id === 'addMember') { if (team.length < MAX_TEAM) { team.push(0); render(); } }
        else if (b.id === 'simReset') { me = 0; team = []; render(); }
        else if (b.hasAttribute('data-m')) {
          var i = parseInt(b.getAttribute('data-m'), 10), d = parseInt(b.getAttribute('data-d'), 10);
          team[i] = Math.max(0, Math.min(MAX_MEMBER, team[i] + d)); render();
          var again = tree.querySelector('button[data-m="' + i + '"][data-d="' + d + '"]'); if (again) { again.focus(); }
        } else if (b.hasAttribute('data-rm')) { team.splice(parseInt(b.getAttribute('data-rm'), 10), 1); render(); }
      });
      rng.addEventListener('input', function () { setMe(parseInt(rng.value, 10) || 0); });
      render();
    }
  }

  // ---- anteprima area riservata (schede) ----
  var tour = document.getElementById('tour');
  if (tour) {
    var tabs = [].slice.call(tour.querySelectorAll('[role=tab]'));
    var show = function (tab, focus) {
      tabs.forEach(function (t) {
        var on = t === tab;
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        t.tabIndex = on ? 0 : -1;
        document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
      });
      if (focus) { tab.focus(); }
    };
    tabs.forEach(function (t, i) {
      t.addEventListener('click', function () { show(t, false); });
      t.addEventListener('keydown', function (e) {
        var k = e.key, n = null;
        if (k === 'ArrowRight') { n = tabs[(i + 1) % tabs.length]; }
        else if (k === 'ArrowLeft') { n = tabs[(i - 1 + tabs.length) % tabs.length]; }
        else if (k === 'Home') { n = tabs[0]; }
        else if (k === 'End') { n = tabs[tabs.length - 1]; }
        if (n) { e.preventDefault(); show(n, true); }
      });
    });
  }
})();
