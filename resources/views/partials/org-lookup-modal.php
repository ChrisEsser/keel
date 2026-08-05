<div class="modal-overlay" id="org-lookup-overlay">
    <div class="modal modal--plain">
        <button class="modal-close"><i data-lucide="x"></i></button>
        <div class="modal-body">
            <div class="modal-content">
                <h2>Add to an organization</h2>
                <div id="ol-msg" style="display:none;"></div>

                <div class="ps-search"><i data-lucide="search"></i><input type="search" id="ol-search" placeholder="Search by organization or owner name…" autocomplete="off"></div>
                <div class="ps-list ps-list--scroll" id="ol-results"></div>

                <div class="modal-form-row" style="margin-top:0.75rem;">
                    <label class="modal-form-label" for="ol-role">Role</label>
                    <div class="modal-form-field">
                        <select id="ol-role">
                            <option value="user">User</option>
                            <option value="admin" selected>Admin</option>
                            <option value="owner">Owner</option>
                        </select>
                    </div>
                </div>

                <div class="modal-form-actions">
                    <button type="button" class="btn-primary" id="ol-add" disabled>Add to organization</button>
                    <button type="button" class="btn-ghost" id="ol-cancel">Cancel</button>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
// Admin-only org picker for the user page: search organizations (by name OR owner name, server-side
// -- see OrganizationModel::searchForAdmin), pick one, choose a role, and add the user. Replaces a
// full-catalog <select> that couldn't scale past a few hundred orgs. On success it hands the created
// membership back to the opener via onAdded so the page can render the new card without a reload.
const orgLookupModal = (() => {
    const overlay = document.getElementById('org-lookup-overlay');
    const modalForm = new ModalForm(overlay, 'ol-msg');
    const searchInput = document.getElementById('ol-search');
    const resultsEl = document.getElementById('ol-results');
    const roleSelect = document.getElementById('ol-role');
    const addBtn = document.getElementById('ol-add');

    let userUid = null;
    let onAdded = null;
    let selected = null;   // { uid, name }
    let debounce = null;
    let reqSeq = 0;        // guards against out-of-order search responses

    overlay.querySelector('.modal-close').addEventListener('click', close);
    document.getElementById('ol-cancel').addEventListener('click', close);
    onBackdropDismiss(overlay, close);
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && overlay.style.display === 'flex') close(); });

    searchInput.addEventListener('input', () => {
        clearTimeout(debounce);
        debounce = setTimeout(search, 200);
    });

    async function search() {
        const q = searchInput.value.trim();
        const seq = ++reqSeq;
        try {
            const res = await fetch('/api/organizations?per_page=25&search=' + encodeURIComponent(q));
            const data = await res.json();
            if (seq !== reqSeq) return;   // a newer search already fired
            renderResults(data.success ? data.data : []);
        } catch (e) {
            if (seq === reqSeq) renderResults(null);
        }
    }

    function renderResults(orgs) {
        if (orgs === null) { resultsEl.innerHTML = '<p class="ps-empty">Could not load organizations.</p>'; return; }
        if (!orgs.length) { resultsEl.innerHTML = '<p class="ps-empty">No organizations match your search.</p>'; return; }

        resultsEl.innerHTML = '';
        orgs.forEach(o => {
            // Show the owner only when it isn't already what the name column is (unnamed orgs already
            // display their owner as the name), so a search-by-owner shows why a named org matched.
            const sub = [(o.owner_name && o.owner_name !== o.name) ? 'Owned by ' + o.owner_name : null, o.email]
                .filter(Boolean).join(' · ');
            const row = document.createElement('label');
            row.className = 'ps-row';
            row.innerHTML = `<input type="radio" name="ol-org" value="${esc(o.uid)}">`
                + `<span class="ps-row-main"><span class="ps-row-name">${esc(o.name)}</span>`
                + (sub ? `<span class="ps-row-sub">${esc(sub)}</span>` : '')
                + `</span>`;
            row.querySelector('input').addEventListener('change', () => {
                selected = { uid: o.uid, name: o.name };
                addBtn.disabled = false;
            });
            resultsEl.appendChild(row);
        });
    }

    addBtn.addEventListener('click', async () => {
        if (!selected) return;
        addBtn.disabled = true;
        modalForm.reset();

        const role = roleSelect.value;
        const res = await fetch('/api/memberships', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_uid: userUid, org_uid: selected.uid, role }),
        });
        const data = await res.json();

        if (!data.success) {
            modalForm.showMsg((data.errors ?? [data.message ?? 'Could not add to organization.']).join(' '), true);
            addBtn.disabled = false;
            return;
        }

        // Capture before close() -- it resets `selected`/`onAdded` to null.
        const payload = { uid: data.data.uid, org_uid: selected.uid, org_name: selected.name, role };
        const cb = onAdded;
        close();
        toast('Added to ' + payload.org_name + '.', 'success');
        if (cb) cb(payload);
    });

    function open(uid, opts) {
        userUid = uid;
        onAdded = (opts || {}).onAdded || null;
        selected = null;
        addBtn.disabled = true;
        roleSelect.value = 'admin';
        searchInput.value = '';
        modalForm.reset();
        resultsEl.innerHTML = '<p class="ps-empty">Loading…</p>';
        overlay.style.display = 'flex';
        if (typeof lucide !== 'undefined') lucide.createIcons();
        search();   // seed with the first page so the list isn't empty
        setTimeout(() => searchInput.focus(), 50);
    }

    function close() {
        overlay.style.display = 'none';
        userUid = null;
        onAdded = null;
        selected = null;
    }

    return { open };
})();
ModalLoader.register('org-lookup', orgLookupModal);
</script>
