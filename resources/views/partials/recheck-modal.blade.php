<div id="recheck-modal" class="office-modal" hidden>
    <div class="office-modal-backdrop" data-close-recheck></div>
    <div class="office-modal-card" role="dialog" aria-modal="true" aria-labelledby="recheck-heading">
        <h2 id="recheck-heading">Restart <span id="recheck-title">this step</span>?</h2>
        <p>This starts the whole step over. The checklist lights the Start button for this file so you can download it, import it, and confirm it again.</p>
        <h3>Remove the records already in Odoo</h3>
        <ol class="steps">
            <li><span>Open <strong id="recheck-screen">the Odoo screen for this file</strong>.</span></li>
            <li><span>Select the records this file created.</span></li>
            <li><span>Open the Action menu and choose <strong>Delete</strong>.</span></li>
            <li><span>If Odoo will not delete a record, archive it. Select the records and use Action → <strong>Archive</strong>, or open one record and choose Archive. Posted invoices cannot be archived until they are cancelled. A category that still has products cannot be deleted until those products are moved to another category. Product categories cannot be archived.</span></li>
        </ol>
        <p>You can also have this app delete them. It asks Odoo to delete every record from this file. When Odoo refuses a delete and the record can be archived, the app archives it instead.</p>
        <form id="recheck-form" method="post" action="">
            @csrf
            <button class="btn btn-primary" type="submit">Delete in Odoo and restart</button>
            <button class="btn btn-ghost" type="button" data-close-recheck>Cancel</button>
        </form>
    </div>
</div>
<script>
    (function () {
        var modal = document.getElementById('recheck-modal');
        if (!modal || modal.dataset.bound === '1') {
            return;
        }
        modal.dataset.bound = '1';
        var form = document.getElementById('recheck-form');
        var title = document.getElementById('recheck-title');
        var screen = document.getElementById('recheck-screen');

        function openRecheck(button) {
            title.textContent = button.getAttribute('data-title') || 'this step';
            screen.textContent = button.getAttribute('data-screen') || 'the Odoo screen for this file';
            form.action = button.getAttribute('data-action');
            modal.hidden = false;
        }

        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-recheck]');
            if (button) {
                event.preventDefault();
                openRecheck(button);
                return;
            }
            if (event.target.closest('[data-close-recheck]')) {
                modal.hidden = true;
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                modal.hidden = true;
            }
        });
    })();
</script>
