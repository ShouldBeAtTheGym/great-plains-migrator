<ol class="progress-steps">
    <li class="{{ request()->routeIs('setup.gp') ? 'is-current' : ($company->gp_tested_at ? 'is-done' : '') }}">1. Great Plains</li>
    <li class="{{ request()->routeIs('setup.odoo') ? 'is-current' : ($company->odoo_tested_at ? 'is-done' : '') }}">2. Odoo</li>
    <li class="{{ request()->routeIs('setup.preflight') ? 'is-current' : ($company->preflight_passed_at ? 'is-done' : '') }}">3. Preflight</li>
    <li class="{{ request()->routeIs('dashboard') ? 'is-current' : '' }}">4. Checklist</li>
</ol>
