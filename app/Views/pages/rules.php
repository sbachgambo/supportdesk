<?php
declare(strict_types=1);
/**
 * Routing-rule editor (Phase 11, admin). rules.js rebuilds the action-value control
 * when the action type changes (§18). All data-* driven — no inline handlers.
 */
?>
<section class="p3a-rules" data-view="rules">
    <h1>Routing rules</h1>
    <div class="p3a-rules-list" data-region="rules-list"></div>

    <h2>Add a rule</h2>
    <p class="p3a-form-msg" data-bind="rule-msg" role="status"></p>
    <form data-action="save-rule" class="p3a-rule-form">
        <label>Name<input type="text" name="rule_name" required maxlength="100"></label>

        <fieldset>
            <legend>When</legend>
            <select name="cond_field">
                <option value="subject">Subject</option>
                <option value="description">Description</option>
                <option value="customer_email">Customer email</option>
                <option value="channel">Channel</option>
                <option value="tags">Tags</option>
            </select>
            <select name="cond_operator">
                <option value="contains">contains</option>
                <option value="is">is</option>
                <option value="starts_with">starts with</option>
                <option value="not_contains">does not contain</option>
            </select>
            <input type="text" name="cond_value" placeholder="value">
        </fieldset>

        <fieldset>
            <legend>Then</legend>
            <?php // Changing this select rebuilds the value control below (rules.js). ?>
            <select name="action_type" data-action="rule-action-type">
                <option value="set_priority">Set priority</option>
                <option value="set_category">Set category</option>
                <option value="assign_agent">Assign agent</option>
                <option value="add_tag">Add tag</option>
            </select>
            <span class="p3a-value-slot" data-slot="action-value"></span>
        </fieldset>

        <button type="submit">Save rule</button>
    </form>
</section>
