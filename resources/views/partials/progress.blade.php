<ol class="progress">
    <li class="{{ request()->routeIs('welcome') ? 'is-current' : '' }}"><a href="{{ route('welcome') }}">1. Welcome</a></li>
    <li class="{{ request()->routeIs('start.ready') ? 'is-current' : '' }}"><a href="{{ route('start.ready') }}">2. Preflight</a></li>
    <li class="{{ request()->routeIs('start.overview') ? 'is-current' : '' }}"><a href="{{ route('start.overview') }}">3. Overview</a></li>
    <li class="{{ request()->routeIs('setup.gp') ? 'is-current' : '' }}"><a href="{{ route('setup.gp') }}">4. Great Plains</a></li>
    <li class="{{ request()->routeIs('setup.odoo') ? 'is-current' : '' }}"><a href="{{ route('setup.odoo') }}">5. Odoo</a></li>
    <li class="{{ request()->routeIs('setup.great-plains') ? 'is-current' : '' }}"><a href="{{ route('setup.great-plains') }}">6. Great Plains fields</a></li>
</ol>
