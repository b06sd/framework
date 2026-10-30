<layout name="app">
    <fill slot="title">API tokens</fill>

    <style>
        .tokens{display:grid;gap:20px;margin:28px 0}
        .tokens > *{min-width:0}
        .tokens h1{margin:0 0 6px;font-size:1.6rem}
        .tokens h2{margin:0 0 14px;font-size:1.1rem}
        .tokens .muted{color:var(--text-muted)}
        .tokens form.create{display:grid;gap:14px;max-width:560px}
        .tokens label{display:grid;gap:6px;font-weight:500}
        .tokens input[type=text],.tokens input[type=password],.tokens select{font:inherit;padding:9px 11px;border-radius:9px;border:1px solid var(--border-strong);background:var(--surface-2);color:var(--text)}
        .tokens fieldset{border:1px solid var(--border);border-radius:10px;padding:10px 14px;display:flex;flex-wrap:wrap;gap:8px 18px}
        .tokens fieldset label{display:flex;gap:6px;align-items:center;font-weight:400}
        .tokens button{font:inherit;font-weight:600;padding:9px 16px;border-radius:9px;border:0;background:var(--accent);color:#fff;cursor:pointer;justify-self:start}
        .tokens button.quiet{background:transparent;color:var(--red);border:1px solid var(--border-strong);padding:5px 10px;font-weight:500}
        .tokens .error{color:var(--red);font-size:.92rem;margin:0}
        .tokens .notice{background:var(--green-soft);color:var(--green);padding:10px 14px;border-radius:10px;margin:0}
        .tokens .created{border:1px solid var(--green)}
        .tokens .created code.path{display:block;word-break:break-all;margin:12px 0;font-size:.95rem}
        .tokens .table{overflow-x:auto}
        .tokens table{width:100%;border-collapse:collapse;font-size:.94rem}
        .tokens th,.tokens td{text-align:left;padding:10px 8px;border-bottom:1px solid var(--border);white-space:nowrap}
        .tokens th{color:var(--text-muted);font-weight:500}
        .tokens td.name{white-space:normal;font-weight:500}
        .tokens .state-active{color:var(--green)}
        .tokens .state-revoked,.tokens .state-expired{color:var(--text-faint)}
    </style>

    <div class="tokens">
        <section class="card">
            <h1>API tokens</h1>
            <p class="muted">A token lets a script or another service call this application's API as you: send it as <code>Authorization: Bearer &lt;token&gt;</code>. Treat it like a password.</p>
        </section>

        <if test="notice != ''">
            <p class="notice" role="status">{{ notice }}</p>
        </if>

        <if test="created">
            <section class="card created" aria-live="polite">
                <h2>Your new token: {{ created.name }}</h2>
                <p>Copy it now. It is shown only this once; Trunk keeps just a fingerprint of it.</p>
                <code class="path" id="path" tabindex="0" title="Click to copy">{{ created.token }}</code>
                <p class="muted">Expires {{ created.expires }}.</p>
            </section>
        </if>

        <section class="card">
            <h2>Create a token</h2>
            <form class="create" method="post" action="{{ path }}">
                <input type="hidden" name="_csrf" value="{{ csrf }}">
                <input type="hidden" name="form" value="{{ form }}">
                <if test="errors.form|default('')"><p class="error" role="alert">{{ errors.form }}</p></if>

                <label>Name
                    <input type="text" name="name" maxlength="100" required value="{{ old.name }}" placeholder="CI, reporting, a partner...">
                </label>
                <if test="errors.name|default('')"><p class="error">{{ errors.name }}</p></if>

                <if test="fullAccess">
                    <p class="muted">The token can do everything you can.</p>
                <else>
                    <fieldset>
                        <legend>It may</legend>
                        <for each="abilities" as="ability">
                            <label>
                                <if test="ability.checked">
                                    <input type="checkbox" name="abilities[]" value="{{ ability.value }}" checked>
                                <else>
                                    <input type="checkbox" name="abilities[]" value="{{ ability.value }}">
                                </if>
                                {{ ability.value }}
                            </label>
                        </for>
                    </fieldset>
                    <if test="errors.abilities|default('')"><p class="error">{{ errors.abilities }}</p></if>
                </if>

                <label>Expires after
                    <select name="days">
                        <for each="lifetimes" as="lifetime">
                            <if test="lifetime.selected">
                                <option value="{{ lifetime.days }}" selected>{{ lifetime.label }}</option>
                            <else>
                                <option value="{{ lifetime.days }}">{{ lifetime.label }}</option>
                            </if>
                        </for>
                    </select>
                </label>
                <if test="errors.days|default('')"><p class="error">{{ errors.days }}</p></if>

                <label>Your password
                    <input type="password" name="password" required autocomplete="current-password">
                </label>
                <if test="errors.password|default('')"><p class="error">{{ errors.password }}</p></if>

                <button type="submit">Create token</button>
            </form>
        </section>

        <section class="card">
            <h2>Your tokens</h2>
            <div class="table">
                <table>
                    <thead>
                        <tr><th>Name</th><th>May</th><th>Created</th><th>Last used</th><th>Expires</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                        <for each="tokens" as="token">
                            <tr>
                                <td class="name">{{ token.name }}</td>
                                <td>{{ token.abilities }}</td>
                                <td>{{ token.created }}</td>
                                <td>{{ token.lastUsed }}</td>
                                <td>{{ token.expires }}</td>
                                <td class="state-{{ token.status|lower }}">{{ token.status }}</td>
                                <td>
                                    <if test="token.active">
                                        <form method="post" action="{{ path }}/{{ token.id }}/revoke">
                                            <input type="hidden" name="_csrf" value="{{ csrf }}">
                                            <button class="quiet" type="submit" aria-label="Revoke {{ token.name }}">Revoke</button>
                                        </form>
                                    </if>
                                </td>
                            </tr>
                        <else>
                            <tr><td colspan="7" class="muted">No tokens yet.</td></tr>
                        </for>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</layout>
