(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const fmt = n => Math.round(n).toLocaleString('fr-FR').replace(/ | /g, ' ') + ' F';
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  // Horloge
  const clock = $('#clock');
  if (clock) { const t = () => clock.textContent = new Date().toLocaleString('fr-FR', { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }); t(); setInterval(t, 30000); }

  // Remplir un formulaire d'édition (paramètres)
  window.fillForm = (id, data) => {
    const f = document.getElementById(id);
    Object.entries(data).forEach(([k, v]) => {
      const el = f.elements[k];
      if (!el) return;
      if (el.type === 'checkbox') el.checked = String(v) === '1'; else el.value = v ?? '';
    });
    f.scrollIntoView({ behavior: 'smooth', block: 'center' });
    f.elements.nom && f.elements.nom.focus();
  };

  // Recherche produit réutilisable
  function productSearch(input, box, onPick, opts = {}) {
    let timer, items = [], act = -1;
    const close = () => { box.classList.remove('open'); act = -1; };
    const draw = () => {
      box.innerHTML = items.length ? items.map((p, i) => {
        const blocked = opts.checkStock && (p.dispo <= 0 || (p.stupefiant && !opts.canStup));
        return `<div class="it ${i === act ? 'act' : ''} ${blocked ? 'no' : ''}" data-i="${i}">
          <div><strong>${esc(p.nom)}</strong> ${esc(p.dosage || '')}
            ${p.ordonnance ? '<span class="badge">Ord.</span>' : ''}${p.stupefiant ? '<span class="badge err">Stup.</span>' : ''}
            <small>${esc(p.dci || '')} ${p.forme ? '· ' + esc(p.forme) : ''}</small></div>
          <div style="text-align:right"><strong>${fmt(opts.price === 'achat' ? p.prix_achat : p.prix_vente)}</strong>
            <small>${opts.checkStock ? (p.dispo > 0 ? p.dispo + ' dispo' : 'Rupture') : ''}${p.stupefiant && opts.checkStock && !opts.canStup ? ' · pharmacien' : ''}</small></div></div>`;
      }).join('') : '<div class="it no">Aucun résultat</div>';
      box.classList.add('open');
    };
    const pick = i => {
      const p = items[i];
      if (!p) return;
      if (opts.checkStock && (p.dispo <= 0 || (p.stupefiant && !opts.canStup))) return;
      onPick(p); input.value = ''; close(); input.focus();
    };
    input.addEventListener('input', () => {
      clearTimeout(timer);
      const v = input.value.trim();
      if (!v) return close();
      timer = setTimeout(async () => {
        const r = await fetch('index.php?p=api&a=search&q=' + encodeURIComponent(v));
        if (r.status === 401) return location.reload();
        items = await r.json(); act = items.length ? 0 : -1; draw();
        // Lecteur code-barres : correspondance exacte -> ajout direct
        if (items.length && items[0].code_barre && items[0].code_barre === v) pick(0);
      }, 180);
    });
    input.addEventListener('keydown', e => {
      if (e.key === 'ArrowDown') { act = Math.min(items.length - 1, act + 1); draw(); e.preventDefault(); }
      else if (e.key === 'ArrowUp') { act = Math.max(0, act - 1); draw(); e.preventDefault(); }
      else if (e.key === 'Enter') { e.preventDefault(); if (box.classList.contains('open')) pick(act < 0 ? 0 : act); }
      else if (e.key === 'Escape') close();
    });
    box.addEventListener('mousedown', e => { const it = e.target.closest('.it[data-i]'); if (it) { e.preventDefault(); pick(+it.dataset.i); } });
    input.addEventListener('blur', () => setTimeout(close, 150));
  }

  /* ---------------- Comptoir ---------------- */
  const comptoir = $('#comptoir');
  if (comptoir) {
    const field = $('#cart-field');
    let cart = [];
    try { cart = JSON.parse(field.value || '[]').filter(c => c && c.nom); } catch (e) { cart = []; }
    const tbody = $('#cart tbody');
    const ins = $('#insurer');

    let rendering = false;
    const render = () => {
      if (rendering) return;
      rendering = true;
      if (tbody.contains(document.activeElement)) document.activeElement.blur();
      rendering = false;
      if (!cart.length) tbody.innerHTML = '<tr class="empty"><td colspan="5">Aucun produit. Recherchez ou scannez un médicament.</td></tr>';
      else tbody.innerHTML = cart.map((c, i) => `<tr>
          <td><strong>${esc(c.nom)}</strong> <small class="muted">${esc(c.dosage || '')}</small>
            ${c.ordonnance ? '<span class="badge">Ord.</span>' : ''}${c.stupefiant ? '<span class="badge err">Stup.</span>' : ''}
            <br><small class="muted">${c.dispo} disponible(s)</small></td>
          <td class="r">${fmt(c.prix_vente)}</td>
          <td class="c"><span class="qty"><button type="button" data-d="-1" data-i="${i}">−</button><input data-i="${i}" value="${c.qty}" inputmode="numeric"><button type="button" data-d="1" data-i="${i}">+</button></span></td>
          <td class="r"><strong>${fmt(c.prix_vente * c.qty)}</strong></td>
          <td><button type="button" class="rm" data-rm="${i}" title="Retirer">×</button></td></tr>`).join('');
      const total = cart.reduce((s, c) => s + c.prix_vente * c.qty, 0);
      const taux = +(ins.selectedOptions[0]?.dataset.taux || 0);
      const ass = Math.round(total * taux / 100);
      $('#t-total').textContent = fmt(total);
      $('#t-ass').textContent = fmt(ass);
      $('#t-ass-row').hidden = !taux;
      $('#t-pat').textContent = fmt(total - ass);
      $('#assure-wrap').hidden = !taux;
      const need = cart.some(c => c.ordonnance);
      $('#ord').classList.toggle('need', need);
      $('#ord-req').hidden = !need;
      ['medecin', 'patient_nom', 'ordonnance_date'].forEach(n => comptoir.elements[n].required = need);
      comptoir.elements.num_assure.required = !!taux;
      field.value = JSON.stringify(cart);
    };

    productSearch($('#psearch'), $('#presults'), p => {
      const ex = cart.find(c => c.id === p.id);
      if (ex) ex.qty = Math.min(ex.qty + 1, p.dispo); else cart.push({ ...p, qty: 1 });
      render();
    }, { checkStock: true, canStup: comptoir.dataset.canStup === '1' });

    tbody.addEventListener('click', e => {
      const b = e.target.closest('button'); if (!b) return;
      if (b.dataset.rm !== undefined) cart.splice(+b.dataset.rm, 1);
      else if (b.dataset.d) { const c = cart[+b.dataset.i]; c.qty = Math.max(1, Math.min(c.dispo, c.qty + +b.dataset.d)); }
      render();
    });
    tbody.addEventListener('change', e => {
      if (!e.target.matches('input[data-i]')) return;
      const c = cart[+e.target.dataset.i]; c.qty = Math.max(1, Math.min(c.dispo, parseInt(e.target.value, 10) || 1)); render();
    });
    ins.addEventListener('change', render);
    comptoir.addEventListener('submit', e => {
      if (!cart.length) { e.preventDefault(); alert('Ajoutez au moins un médicament.'); return; }
      field.value = JSON.stringify(cart);
      comptoir.querySelectorAll('button').forEach(b => setTimeout(() => b.disabled = true, 0));
    });
    render();
  }

  /* ---------------- Caisse ---------------- */
  const pending = $('#pending');
  if (pending) {
    const sel = +pending.dataset.selected;
    const filter = $('#bon-filter');
    let known = null, data = [];
    const draw = () => {
      const f = filter.value.trim().toUpperCase();
      const list = data.filter(b => !f || b.numero.toUpperCase().includes(f));
      pending.innerHTML = list.length ? list.map(b => `<a href="index.php?p=caisse&id=${b.id}" class="${b.id == sel ? 'sel' : ''} ${known && !known.has(b.id) ? 'fresh' : ''}">
          <div class="n">${esc(b.numero)}</div>
          <small>${esc(b.client_nom || b.patient_nom || 'Client')} · ${b.nb} article(s) · ${esc(b.vendeur)}</small>
          <small>${new Date(b.created_at.replace(' ', 'T')).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}${b.assurance ? ' · ' + esc(b.assurance) : ''}</small>
          <div class="m">${fmt(b.part_patient)}</div></a>`).join('')
        : '<p class="muted">Aucun bon en attente.</p>';
      known = new Set(data.map(b => b.id));
    };
    const load = async () => {
      try {
        const r = await fetch('index.php?p=api&a=pending');
        if (r.status === 401) return location.reload();
        data = await r.json(); draw();
      } catch (e) { /* réseau momentanément indisponible */ }
    };
    filter.addEventListener('input', draw);
    filter.addEventListener('keydown', e => {
      if (e.key !== 'Enter') return;
      const f = filter.value.trim().toUpperCase();
      const m = data.filter(b => b.numero.toUpperCase().includes(f));
      if (m.length === 1) location.href = 'index.php?p=caisse&id=' + m[0].id;
    });
    load(); setInterval(load, 4000);
  }
  const pay = $('#payform');
  if (pay) {
    const due = +$('#due').dataset.due, recu = $('#recu'), wrap = $('#recu-wrap'), out = $('#monnaie');
    const upd = () => {
      const esp = pay.elements.mode.value === 'especes';
      wrap.hidden = !esp; out.parentElement.hidden = !esp;
      const m = (parseInt(String(recu.value).replace(/\D/g, ''), 10) || 0) - due;
      out.textContent = fmt(Math.max(0, m));
      out.style.color = m < 0 ? 'var(--err)' : '';
    };
    pay.addEventListener('change', upd); recu.addEventListener('input', upd); upd();
    recu.addEventListener('focus', () => recu.select());
    pay.addEventListener('submit', e => {
      if (pay.elements.mode.value === 'especes' && (parseInt(recu.value.replace(/\D/g, ''), 10) || 0) < due) { e.preventDefault(); alert('Montant reçu insuffisant.'); return; }
      pay.querySelector('button').disabled = true;
    });
  }

  /* ---------------- Réception ---------------- */
  const rec = $('#reception');
  if (rec) {
    const tb = $('#rlines tbody');
    const today = new Date().toISOString().slice(0, 10);
    productSearch($('#rsearch'), $('#rresults'), p => {
      tb.querySelector('.empty')?.remove();
      const tr = document.createElement('tr');
      tr.innerHTML = `<td><strong>${esc(p.nom)}</strong> ${esc(p.dosage || '')}<input type="hidden" name="product_id[]" value="${p.id}"></td>
        <td><input name="numero_lot[]" required placeholder="N° lot"></td>
        <td><input type="date" name="date_peremption[]" required min="${today}"></td>
        <td><input name="quantite[]" inputmode="numeric" required style="width:90px"></td>
        <td><input name="prix_achat[]" inputmode="numeric" value="${p.prix_achat || ''}" style="width:110px"></td>
        <td><button type="button" class="rm" title="Retirer">×</button></td>`;
      tb.appendChild(tr);
      tr.querySelector('input[name="numero_lot[]"]').focus();
    }, { price: 'achat' });
    tb.addEventListener('click', e => { if (e.target.closest('.rm')) e.target.closest('tr').remove(); });
    rec.addEventListener('submit', e => { if (!tb.querySelector('input[name="product_id[]"]')) { e.preventDefault(); alert('Ajoutez au moins un médicament.'); } });
  }
})();
