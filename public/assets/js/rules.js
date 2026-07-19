/* P3A rules.js (Phase 11) — routing-rule editor.
 *
 * §18 lesson (a named exit criterion): the editor MUST rebuild the action-VALUE
 * control whenever the action TYPE changes, so a stale value from the previous type
 * can never be silently submitted. rebuildValueControl() destroys the old control
 * and builds the right one (priority/category/agent dropdown, or a free-text tag). */
(function () {
    'use strict';
    var P3A = window.P3A;
    if (!P3A || !document.querySelector('[data-view="rules"]')) { return; }

    var categories = [];
    var agents = [];

    function option(value, label) {
        var o = document.createElement('option');
        o.value = value;
        o.textContent = label;
        return o;
    }
    function selectFrom(pairs) {
        var s = document.createElement('select');
        pairs.forEach(function (p) { s.appendChild(option(p[0], p[1])); });
        return s;
    }

    /** Build the value control appropriate to the chosen action type. */
    function buildValueControl(type) {
        var el;
        if (type === 'set_priority') {
            el = selectFrom([['urgent', 'Urgent'], ['high', 'High'], ['normal', 'Normal'], ['low', 'Low']]);
        } else if (type === 'set_category') {
            el = selectFrom(categories.map(function (c) { return [c.category_id, c.name]; }));
        } else if (type === 'assign_agent') {
            el = selectFrom(agents.map(function (a) { return [a.email, a.name]; }));
        } else { // add_tag
            el = document.createElement('input');
            el.type = 'text';
            el.placeholder = 'tag';
        }
        el.name = 'action_value';
        el.setAttribute('data-bind', 'action-value');
        return el;
    }

    /** Destroy the stale control and rebuild for the new type (the §18 fix). */
    function rebuildValueControl(type) {
        var slot = document.querySelector('[data-slot="action-value"]');
        if (!slot) { return; }
        slot.textContent = ''; // remove the previous control entirely — no stale value survives
        slot.appendChild(buildValueControl(type));
    }

    // The type control is a <select>; rebuild on its 'change' event.
    document.addEventListener('change', function (e) {
        if (e.target && e.target.getAttribute('data-action') === 'rule-action-type') {
            rebuildValueControl(e.target.value);
        }
    });

    function val(name) {
        var el = document.querySelector('[data-view="rules"] [name="' + name + '"]');
        return el ? el.value : '';
    }

    async function saveRule(form) {
        var payload = {
            name: val('rule_name'),
            enabled: true,
            conditions: [{ field: val('cond_field'), operator: val('cond_operator'), value: val('cond_value') }],
            actions: [{ type: val('action_type'), value: val('action_value') }]
        };
        var res = await P3A.call('createRule', payload);
        var msg = document.querySelector('[data-bind="rule-msg"]');
        if (msg) { msg.textContent = res && res.ok ? 'Rule saved.' : (res && res.error) || 'Could not save.'; }
        if (res && res.ok) { loadRules(); }
    }

    async function loadRules() {
        var res = await P3A.call('listRules');
        var list = document.querySelector('[data-region="rules-list"]');
        if (!list || !res || !res.ok) { return; }
        list.textContent = '';
        res.data.rules.forEach(function (r) {
            var row = document.createElement('div');
            row.className = 'p3a-rule-row';
            row.textContent = r.name + (parseInt(r.enabled, 10) ? ' (enabled)' : ' (disabled)');
            list.appendChild(row);
        });
    }

    document.addEventListener('submit', function (e) {
        if (e.target && e.target.getAttribute('data-action') === 'save-rule') {
            e.preventDefault();
            saveRule(e.target);
        }
    });

    async function init() {
        var cats = await P3A.call('listCategories');
        if (cats && cats.ok) { categories = cats.data.categories; }
        var users = await P3A.call('listUsers');
        if (users && users.ok) { agents = users.data.users.filter(function (u) { return u.role === 'agent' || u.role === 'admin'; }); }
        rebuildValueControl(val('action_type') || 'set_priority'); // initial control
        loadRules();
    }
    init();
})();
