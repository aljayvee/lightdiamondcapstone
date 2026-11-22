<?php

declare(strict_types=1);

use Wbpms\Http\View\Formatter;
/**
 * @var array[]  $rows
 * @var int      $total
 * @var int      $active
 * @var int      $inactive
 * @var int      $archived
 * @var string   $base
 * @var string   $csrf
 * @var array[]  $positions       Active job positions for filter dropdown
 * @var string   $filterPosition  Current position filter value
 * @var string   $filterUsername  Current username filter value
 * @var string   $filterStatus    Current status filter value
 *
 * Flash messages are handled by layout.php via the ViewRenderer toast system.
 */
$positions      ??= [];
$filterPosition ??= '';
$filterUsername ??= '';
$filterStatus   ??= '';
$roleName       ??= '';
?>
<div class="page-head">
    <div><h1>User Management</h1><p>System accounts are created automatically when employees are added.</p></div>
</div>

<div class="cards four">
    <div class="stat"><strong><?= $total ?></strong><span>Total Users</span></div>
    <div class="stat"><strong style="color:var(--ok)"><?= $active ?></strong><span>Active</span></div>
    <div class="stat"><strong style="color:var(--wait)"><?= $inactive ?></strong><span>Inactive</span></div>
    <div class="stat"><strong style="color:var(--bad)"><?= $archived ?></strong><span>Archived</span></div>
</div>

<!-- Filters -->
<div class="card" style="padding:.9rem 1.25rem;margin-bottom:1.25rem">
    <form method="GET" action="<?= $base ?>/users" style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end">
        <div>
            <label style="font-size:.8rem;font-weight:500;color:#374151;display:block;margin-bottom:.25rem">Username</label>
            <input
                type="text"
                name="username"
                value="<?= Formatter::escape($filterUsername) ?>"
                placeholder="Search username..."
                style="padding:.45rem .7rem;border:1px solid #d1d5db;border-radius:4px;font-size:.875rem;width:180px"
            >
        </div>
        <div>
            <label style="font-size:.8rem;font-weight:500;color:#374151;display:block;margin-bottom:.25rem">Status</label>
            <select name="status" style="padding:.45rem .7rem;border:1px solid #d1d5db;border-radius:4px;font-size:.875rem">
                <option value="">All</option>
                <option value="active"   <?= $filterStatus === 'active'   ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                <option value="archived" <?= $filterStatus === 'archived' ? 'selected' : '' ?>>Archived</option>
            </select>
        </div>
        <div>
            <label style="font-size:.8rem;font-weight:500;color:#374151;display:block;margin-bottom:.25rem">Job Position</label>
            <select name="position" style="padding:.45rem .7rem;border:1px solid #d1d5db;border-radius:4px;font-size:.875rem">
                <option value="">All positions</option>
                <?php foreach ($positions as $pos): ?>
                <option value="<?= Formatter::escape($pos['name']) ?>"
                    <?= ($pos['name'] === $filterPosition) ? 'selected' : '' ?>>
                    <?= Formatter::escape($pos['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-secondary">Apply Filters</button>
        <?php if ($filterUsername !== '' || $filterStatus !== '' || $filterPosition !== ''): ?>
        <a href="<?= $base ?>/users" class="btn btn-secondary">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="panel table-wrap">
    <table id="usersTable">
        <thead>
            <tr>
                <th>Username</th><th>Email</th><th>Role</th>
                <th>Linked Employee</th><th>Status</th><th>Created</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="7" class="muted" style="text-align:center;padding:28px">No users found.</td></tr>
        <?php else: foreach ($rows as $r):
            $badge      = match($r['status']) { 'Active' => 'ok', 'Inactive' => 'off', default => 'bad' };
            $isArchived = $r['status'] === 'Archived';
            $uid        = (int) $r['user_id'];
            $rawUsername = (string) ($r['username'] ?? '');
            // $uname is safe for use in data-* attribute values (no HTML tags)
            $uname      = $rawUsername !== '' ? Formatter::escape($rawUsername) : '(no username)';
            // $unameDisplay is used in the table cell and may include styling
            $unameDisplay = $rawUsername !== '' ? Formatter::escape($rawUsername) : '<span class="muted" style="font-style:italic">(no username)</span>';
        ?>
            <tr>
                <td style="font-weight:700"><?= $unameDisplay ?></td>
                <td>
                    <?= $r['account_email'] !== '' && $r['account_email'] !== null ? Formatter::escape($r['account_email']) : '<span class="muted">—</span>' ?>
                    <?php if (array_key_exists('email_verified_at', $r) && $r['email_verified_at'] === null): ?>
                        <span class="badge warn" style="font-size:10px;margin-left:4px" title="Email address has not been verified yet">Unverified</span>
                    <?php endif; ?>
                </td>
                <td><?= Formatter::escape(Formatter::label($r['role_name'] ?? null)) ?></td>
                <td class="muted"><?= Formatter::escape($r['employee_name'] ?? '—') ?></td>
                <td><span class="badge <?= $badge ?>"><?= Formatter::escape($r['status']) ?></span></td>
                <td><?= Formatter::date($r['created_at']) ?></td>
                <td>
                    <?php if ($isArchived): ?>
                        <?php if ($roleName === 'BusinessOwner'): ?>
                        <div class="action-menu">
                            <button type="button" class="action-menu-toggle" aria-haspopup="true" aria-expanded="false">
                                Actions <span class="chevron"><?= \Wbpms\Http\View\Icon::svg('chevron-down', 14) ?></span>
                            </button>
                            <div class="action-menu-list" role="menu">
                                <!-- Unarchive -->
                                <form id="form-unarchive-<?= $uid ?>" method="POST" action="<?= $base ?>/users/<?= $uid ?>/unarchive">
                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="button" class="item-ok" role="menuitem"
                                            data-confirm
                                            data-form="form-unarchive-<?= $uid ?>"
                                            data-icon="undo"
                                            data-title="Unarchive user"
                                            data-body="Unarchive <?= $uname ?>? The account will be set to Inactive. You can activate it afterwards.">
                                        <?= \Wbpms\Http\View\Icon::svg('undo') ?> Unarchive
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php else: ?>
                        <span class="muted" style="font-size:13px">—</span>
                        <?php endif; ?>

                    <?php else: ?>
                    <?php
                        $canAdmin   = ($roleName === 'BusinessOwner');
                        $canEdit    = ($roleName === 'BusinessOwner' || ($roleName === 'HRHead' && ($r['role_name'] ?? '') === 'Employee'));
                        $hasActions = ($canAdmin || $canEdit);
                    ?>
                    <?php if ($hasActions): ?>
                    <div class="action-menu">
                        <button type="button" class="action-menu-toggle" aria-haspopup="true" aria-expanded="false">
                            Actions <span class="chevron"><?= \Wbpms\Http\View\Icon::svg('chevron-down', 14) ?></span>
                        </button>
                        <div class="action-menu-list" role="menu">

                            <?php if ($canEdit): ?>
                            <a href="<?= $base ?>/users/<?= $uid ?>/edit" role="menuitem"><?= \Wbpms\Http\View\Icon::svg('pencil') ?> Edit</a>
                            <?php endif; ?>

                            <?php if ($canAdmin): ?>
                            <?php $isActive = $r['status'] === 'Active'; ?>
                            <?php if ($uid !== $currentUserId || !$isActive): ?>
                            <div class="menu-divider"></div>

                            <!-- Toggle Active / Inactive -->
                            <form id="form-toggle-<?= $uid ?>" method="POST" action="<?= $base ?>/users/<?= $uid ?>/toggle">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                <button type="button"
                                        class="<?= $isActive ? 'item-warn' : 'item-ok' ?>"
                                        role="menuitem"
                                        data-confirm
                                        data-form="form-toggle-<?= $uid ?>"
                                        data-icon="<?= $isActive ? 'user-x' : 'user-check' ?>"
                                        data-title="<?= $isActive ? 'Deactivate user' : 'Activate user' ?>"
                                        data-body="<?= $isActive
                                            ? "Deactivate {$uname}? They will not be able to log in until reactivated."
                                            : "Activate {$uname}? They will regain access immediately." ?>">
                                    <?= $isActive ? \Wbpms\Http\View\Icon::svg('user-x') . ' Deactivate' : \Wbpms\Http\View\Icon::svg('user-check') . ' Activate' ?>
                                </button>
                            </form>
                            <?php endif; ?>

                            <?php if ($uid !== $currentUserId): ?>
                            <form id="form-archive-<?= $uid ?>" method="POST" action="<?= $base ?>/users/<?= $uid ?>/archive">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                <button type="button"
                                        class="item-danger"
                                        role="menuitem"
                                        data-confirm
                                        data-form="form-archive-<?= $uid ?>"
                                        data-icon="archive"
                                        data-title="Archive user"
                                        data-body="Archive <?= $uname ?>? This will also close and archive their linked employee record. The account can be restored later by the Business Owner.">
                                    <?= \Wbpms\Http\View\Icon::svg('archive') ?> Archive
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php endif; /* canAdmin */ ?>

                            <?php if ($canEdit): ?>
                            <?php if ($canAdmin): ?><div class="menu-divider"></div><?php endif; ?>
                            <!-- Reset Password -->
                            <form id="form-reset-<?= $uid ?>" method="POST" action="<?= $base ?>/users/<?= $uid ?>/reset-password">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                <button type="button"
                                        role="menuitem"
                                        data-confirm
                                        data-form="form-reset-<?= $uid ?>"
                                        data-icon="key"
                                        data-title="Reset password"
                                        data-body="Generate a new temporary password for <?= $uname ?>? The user will be required to change it on next login.">
                                    <?= \Wbpms\Http\View\Icon::svg('key') ?> Reset Password
                                </button>
                            </form>

                            <?php if (array_key_exists('email_verified_at', $r) && $r['email_verified_at'] === null): ?>
                            <!-- Resend Email Verification -->
                            <form id="form-verify-<?= $uid ?>" method="POST" action="<?= $base ?>/users/<?= $uid ?>/resend-verification">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" role="menuitem">
                                    <?= \Wbpms\Http\View\Icon::svg('mail') ?> Resend Verification
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php endif; /* canEdit */ ?>

                        </div>
                    </div>
                    <?php else: ?>
                    <span class="muted" style="font-size:13px">—</span>
                    <?php endif; /* hasActions */ ?>
                    <?php endif; /* isArchived */ ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<!-- ================================================================
     CONFIRM MODAL  (single shared instance — JS populates it)
     ================================================================ -->
<div id="confirmModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="confirmModalTitle">
    <div class="modal-box">
        <span id="confirmModalIcon" class="modal-icon" aria-hidden="true"></span>
        <p id="confirmModalTitle" class="modal-title"></p>
        <p id="confirmModalBody"  class="modal-body"></p>
        <div class="modal-actions">
            <button type="button" class="btn btn-secondary" id="confirmModalCancel">Cancel</button>
            <button type="button" class="btn btn-primary"   id="confirmModalOk">Confirm</button>
        </div>
    </div>
</div>

<script>
(function () {
    /* ---- Action-menu toggle ---- */
    document.addEventListener('click', function (e) {
        var toggle = e.target.closest('.action-menu-toggle');
        if (toggle) {
            var menu   = toggle.closest('.action-menu');
            var isOpen = menu.classList.contains('open');
            closeAllMenus();
            if (!isOpen) {
                menu.classList.add('open');
                toggle.setAttribute('aria-expanded', 'true');
                positionMenuList(toggle, menu.querySelector('.action-menu-list'));
            }
            e.stopPropagation();
            return;
        }
        // Click outside closes all menus (but not modal clicks)
        if (!e.target.closest('.modal-box')) {
            closeAllMenus();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeAllMenus();
            closeModal();
        }
    });

    function closeAllMenus() {
        document.querySelectorAll('.action-menu.open').forEach(function (m) {
            m.classList.remove('open');
            m.querySelector('.action-menu-toggle').setAttribute('aria-expanded', 'false');
            var list = m.querySelector('.action-menu-list');
            if (list) { list.style.top = ''; list.style.left = ''; list.style.right = ''; }
        });
    }

    function positionMenuList(toggle, list) {
        var rect = toggle.getBoundingClientRect();
        // fixed positioning is relative to the viewport — no scrollY offset needed.
        // Align right edge of dropdown to right edge of the toggle button.
        list.style.top   = (rect.bottom + 4) + 'px';
        list.style.right = (window.innerWidth - rect.right) + 'px';
        list.style.left  = '';
        // Bottom rows: open upward when the list would run past the viewport,
        // otherwise Archive / Reset Password were cut off. [QA USER TC1]
        var height = list.offsetHeight;
        if (rect.bottom + 4 + height > window.innerHeight && rect.top - 4 - height >= 0) {
            list.style.top = (rect.top - 4 - height) + 'px';
        }
    }

    /* ---- Confirm modal ---- */
    var modal      = document.getElementById('confirmModal');
    var modalIcon  = document.getElementById('confirmModalIcon');
    var modalTitle = document.getElementById('confirmModalTitle');
    var modalBody  = document.getElementById('confirmModalBody');
    var modalOk    = document.getElementById('confirmModalOk');
    var modalCancelBtn = document.getElementById('confirmModalCancel');
    var pendingFormId  = null;

    // Wire every [data-confirm] button
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-confirm]');
        if (!btn) return;

        e.preventDefault();
        closeAllMenus();

        pendingFormId = btn.getAttribute('data-form');
        modalIcon.innerHTML    = (window.WB_ICONS || {})[btn.getAttribute('data-icon')] || (window.WB_ICONS || {}).help || '';
        modalTitle.textContent = btn.getAttribute('data-title') || 'Are you sure?';
        modalBody.textContent  = btn.getAttribute('data-body')  || '';

        // Colour the confirm button to match the action severity
        modalOk.className = 'btn btn-primary';
        if (btn.classList.contains('item-danger')) {
            modalOk.className = 'btn btn-danger';
        } else if (btn.classList.contains('item-warn')) {
            modalOk.className = 'btn btn-warn';
        }

        modal.classList.add('show');
        modalOk.focus();
    });

    modalOk.addEventListener('click', function () {
        if (pendingFormId) {
            var form = document.getElementById(pendingFormId);
            if (form) form.submit();
        }
        closeModal();
    });

    modalCancelBtn.addEventListener('click', closeModal);

    // Click on backdrop (outside box) cancels
    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });

    function closeModal() {
        modal.classList.remove('show');
        pendingFormId = null;
    }
}());
</script>
