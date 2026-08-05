<div class="modal-overlay" id="user-create-overlay">
    <div class="modal modal--plain">
        <button class="modal-close"><i data-lucide="x"></i></button>
        <div class="modal-body">
            <div class="modal-content">
                <h2>New User</h2>
                <div data-msg="general" style="display:none;"></div>
                <form data-panel-form="general">
                    <div class="modal-form-row">
                        <label class="modal-form-label required" for="uc-first-name">First Name</label>
                        <div class="modal-form-field"><input id="uc-first-name" data-field="first_name" type="text" required></div>
                    </div>
                    <div class="modal-form-row">
                        <label class="modal-form-label required" for="uc-last-name">Last Name</label>
                        <div class="modal-form-field"><input id="uc-last-name" data-field="last_name" type="text" required></div>
                    </div>
                    <div class="modal-form-row">
                        <label class="modal-form-label required" for="uc-email">Email</label>
                        <div class="modal-form-field"><input id="uc-email" data-field="email" type="email" required></div>
                    </div>
                    <div class="modal-form-row">
                        <label class="modal-form-label" for="uc-password">Password</label>
                        <div class="modal-form-field"><input id="uc-password" data-field="password" type="password"></div>
                    </div>
                    <div class="modal-form-actions">
                        <button type="submit" class="btn-primary"><i data-lucide="plus"></i> Create User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
const userCreateModal = new AjaxModal('user-create-overlay', {
    mode: 'create',
    createUrl: '/api/users',
    onCreated(data) {
        window.location = '/users/' + data.uid;
    },
});
ModalLoader.register('user-create', userCreateModal);
</script>
