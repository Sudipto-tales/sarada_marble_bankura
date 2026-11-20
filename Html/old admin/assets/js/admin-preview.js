/* Browser-local interactions for the supplied Hyper HTML preview. No live API. */
(() => {
    'use strict';
    const productsKey = 'maa-sarada.admin.products.v1';
    const profileKey = 'maa-sarada.admin.profile.v1';
    const read = (key, fallback) => {
        try { return JSON.parse(localStorage.getItem(key)) || fallback; }
        catch (_) { return fallback; }
    };
    const products = () => {
        const value = read(productsKey, []);
        return Array.isArray(value) ? value.filter(p => p && typeof p.id === 'string' && p.fields && typeof p.fields.name === 'string') : [];
    };
    const message = (text, error = false) => {
        const box = document.getElementById('form-message');
        box.className = 'alert ' + (error ? 'alert-danger' : 'alert-success');
        box.textContent = text;
        box.scrollIntoView({block: 'nearest'});
    };
    const applyProfile = profile => {
        if (!profile || typeof profile.displayName !== 'string') return;
        document.querySelectorAll('.nav-user h5, .leftbar-user-name, .profile-dropdown .dropdown-header h6, .card.text-center h4').forEach(node => {
            node.textContent = profile.displayName;
        });
    };
    const start = () => {
        applyProfile(read(profileKey, null));
        const account = document.getElementById('profile-form');
        if (account) {
            const profile = read(profileKey, {});
            account.elements.displayName.value = typeof profile.displayName === 'string' ? profile.displayName : 'Store Admin';
            account.elements.email.value = typeof profile.email === 'string' ? profile.email : '';
            account.addEventListener('submit', event => {
                event.preventDefault();
                const displayName = account.elements.displayName.value.trim();
                if (!displayName) { message('Enter a display name.', true); return; }
                if (!account.reportValidity()) return;
                const updated = {displayName, email: account.elements.email.value.trim()};
                try {
                    localStorage.setItem(profileKey, JSON.stringify(updated));
                    applyProfile(updated);
                    message('Profile saved in this browser.');
                } catch (_) { message('Browser storage is unavailable. Profile was not saved.', true); }
            });
        }
        const form = document.getElementById('product-form');
        if (form) {
            const id = new URLSearchParams(location.search).get('id');
            const existing = products().find(p => p.id === id);
            if (existing) {
                Object.entries(existing.fields).forEach(([name, value]) => {
                    const field = form.elements.namedItem(name);
                    if (field) field.value = value;
                });
                document.querySelector('.page-title').textContent = 'Edit Product';
            } else if (id) {
                message('This browser does not contain that material. You can add a new material.', true);
            }
            const name = form.elements.name;
            const slug = form.elements.slug;
            name.addEventListener('input', () => {
                if (!slug.dataset.manual) slug.value = name.value.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
            });
            slug.addEventListener('input', () => { slug.dataset.manual = '1'; });
            form.addEventListener('submit', event => {
                event.preventDefault();
                if (!form.reportValidity()) return;
                const fields = Object.fromEntries(new FormData(form));
                for (const key of ['name', 'slug', 'variants[0][sku]']) fields[key] = fields[key].trim();
                if (!fields.name || !fields['variants[0][sku]']) { message('Enter a material name and SKU.', true); return; }
                const saved = products();
                if (saved.some(p => p.id !== existing?.id && (p.fields.slug === fields.slug || p.fields['variants[0][sku]'].toLowerCase() === fields['variants[0][sku]'].toLowerCase()))) {
                    message('A local material already uses this slug or SKU. Choose a unique value.', true); return;
                }
                const product = {id: existing?.id || 'local-' + Date.now() + '-' + Math.random().toString(36).slice(2), fields, created: existing?.created || new Date().toISOString()};
                try {
                    localStorage.setItem(productsKey, JSON.stringify([...saved.filter(p => p.id !== product.id), product]));
                    location.href = 'apps-ecommerce-products.html?added=' + encodeURIComponent(product.id);
                } catch (_) { message('Browser storage is unavailable or full. Material was not saved.', true); }
            });
        }
        const table = document.getElementById('products-datatable');
        if (table) {
            const saved = products();
            const dataTable = window.jQuery && jQuery.fn.dataTable.isDataTable(table) ? jQuery(table).DataTable() : null;
            saved.forEach(product => {
                const f = product.fields;
                const row = document.createElement('tr');
                const cell = () => row.appendChild(document.createElement('td'));
                const check = document.createElement('input');
                check.type = 'checkbox'; check.className = 'form-check-input dt-checkboxes'; check.setAttribute('aria-label', 'Select ' + f.name); cell().append(check);
                const link = document.createElement('a');
                link.className = 'text-body'; link.textContent = f.name;
                link.href = 'apps-ecommerce-products-add.html?id=' + encodeURIComponent(product.id); cell().append(link);
                cell().textContent = f.category_id;
                cell().textContent = new Date(product.created).toLocaleDateString('en-IN');
                cell().textContent = new Intl.NumberFormat('en-IN', {style: 'currency', currency: 'INR'}).format(Number(f['variants[0][price]'])) + ' / ' + f['variants[0][sell_unit]'];
                cell().textContent = f.stock || '0';
                const badge = document.createElement('span'); badge.className = 'badge ' + (f.status === 'active' ? 'bg-success' : 'bg-secondary');
                badge.textContent = f.status === 'active' ? 'Published (local)' : f.status === 'archived' ? 'Archived (local)' : 'Draft (local)'; cell().append(badge);
                const actions = cell(); actions.className = 'table-action';
                const edit = document.createElement('a'); edit.href = link.href; edit.className = 'action-icon'; edit.setAttribute('aria-label', 'Edit ' + f.name);
                const icon = document.createElement('i'); icon.className = 'mdi mdi-square-edit-outline'; edit.append(icon); actions.append(edit);
                if (dataTable) dataTable.row.add(row); else table.tBodies[0].append(row);
            });
            const added = saved.find(p => p.id === new URLSearchParams(location.search).get('added'));
            if (dataTable) { if (added) dataTable.search(added.fields.name); dataTable.draw(false); }
            if (added) {
                const note = document.createElement('div'); note.className = 'alert alert-success'; note.role = 'status';
                note.textContent = added.fields.name + ' saved in this browser. Live catalogue is unchanged.';
                table.closest('.card-body').prepend(note);
            }
        }
    };
    if (window.jQuery) jQuery(start);
    else if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
