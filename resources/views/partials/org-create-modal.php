<div class="modal-overlay" id="org-create-overlay">
    <div class="modal modal--plain">
        <button class="modal-close"><i data-lucide="x"></i></button>
        <div class="modal-body">
            <div class="modal-content">
                <h2>New Organization</h2>
                <div data-msg="general" style="display:none;"></div>
                <form data-panel-form="general">
                    <div class="modal-form-row">
                        <label class="modal-form-label" for="oc-name">Name</label>
                        <div class="modal-form-field"><input id="oc-name" data-field="name" type="text" placeholder="My Workspace"></div>
                    </div>
                    <div class="modal-form-row">
                        <label class="modal-form-label required" for="oc-email">Email</label>
                        <div class="modal-form-field"><input id="oc-email" data-field="email" type="email" required></div>
                    </div>
                    <div class="modal-form-actions">
                        <button type="submit" class="btn-primary"><i data-lucide="plus"></i> Create Organization</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
const orgCreateModal = new AjaxModal('org-create-overlay', {
    mode: 'create',
    createUrl: '/api/organizations',
    onCreated(data) {
        window.location = '/organizations/' + data.uid + '/dashboard';
    },
});
ModalLoader.register('org-create', orgCreateModal);
</script>
