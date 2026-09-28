<?php
// claiming.php
//
// Ready for Claiming: patients whose results from a prior visit are ready to
// pick up. This used to be a panel at the bottom of the encoder console; it
// has its own screen now so the Front Desk only shows today's queue. The list
// is the same one display.php scrolls on the waiting-area board.
session_start();
require 'config.php';
require 'queue_functions.php';
require 'app_header.php';

// Same staff name as the Front Desk (shared session), so added_by/claimed_by
// stay attributed to a person.
$staff_name = '';
if (isset($_GET['encoder_name'])) {
    $staff_name = trim($_GET['encoder_name']);
    $_SESSION['encoder_name'] = $staff_name;
} elseif (isset($_SESSION['encoder_name'])) {
    $staff_name = $_SESSION['encoder_name'];
}

// ---------- AJAX: add a name / mark claimed (X-Requested-With + JSON) ----------
// Inline AJAX so adding or claiming never reloads the page and loses the
// search box or scroll position.

if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && isset($_POST['add_claimable'])) {
    header('Content-Type: application/json');
    $post_encoder = trim($_POST['encoder_name'] ?? '');
    $surname = trim($_POST['surname'] ?? '');
    $first_name_initials = trim($_POST['first_name_initials'] ?? '');
    if ($surname === '' || $first_name_initials === '') {
        echo json_encode(['ok' => false, 'error' => 'Surname and first name initials are required.']);
        exit();
    }
    $id = add_claimable_result($conn, $surname, $first_name_initials, $post_encoder);
    echo json_encode([
        'ok' => true,
        'row' => ['id' => $id, 'surname' => $surname, 'first_name_initials' => $first_name_initials],
    ]);
    exit();
}

if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && isset($_POST['mark_claimed'])) {
    header('Content-Type: application/json');
    $id = (int) ($_POST['id'] ?? 0);
    $post_encoder = trim($_POST['encoder_name'] ?? '');
    $ok = mark_result_claimed($conn, $id, $post_encoder);
    echo json_encode(['ok' => $ok]);
    exit();
}

// ---------- render ----------

$show_name_card = $staff_name === '' || isset($_GET['change']);
if ($show_name_card) {
    $recent_names = get_recent_staff_names($conn);
} else {
    $claimable_results = get_claimable_results($conn);
}

function added_label($created_at) {
    if (!$created_at) return '';
    $day = date('Y-m-d', strtotime($created_at));
    return $day === date('Y-m-d') ? 'Added today' : 'Added ' . date('M j', strtotime($created_at));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Laboratory Queueing — Claiming</title>
<link rel="stylesheet" href="assets/theme.css?v=<?= filemtime(__DIR__ . '/assets/theme.css') ?>">
<style>
    .claim-toolbar { display: flex; gap: 12px; max-width: 760px; margin: 0 auto; }
    .claim-toolbar .field { flex: 1; min-width: 0; font-size: 1.05rem; padding: 12px 14px; }
    .claim-count { text-align: center; color: var(--text-muted); font-weight: 600; margin: 12px 0 18px; }
    #claimStatus { max-width: 760px; margin: 0 auto 14px; }
    .claim-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(270px, 1fr)); gap: 12px; }
    .claim-card { display: flex; align-items: center; justify-content: space-between; gap: 12px; background: var(--surface-alt); border: 1.5px solid var(--border); border-radius: var(--radius-md); padding: 12px 14px; }
    .claim-card .claim-name { font-weight: 700; font-size: 1.05rem; word-break: break-word; }
    .claim-card .claim-added { font-size: 0.78rem; color: var(--text-muted); margin-top: 2px; }
    .claim-dialog-name { font-size: 1.3rem; font-weight: 800; margin: 8px 0 10px; word-break: break-word; }
    .no-match { text-align: center; color: var(--text-muted); padding: 18px 0; }
</style>
</head>
<body>
    <?php render_app_header('claiming', $show_name_card ? '' : $staff_name, 'claiming.php?change=1'); ?>
    <div class="page">
        <?php if ($show_name_card): ?>
        <div class="name-card">
            <h2>Who's working?</h2>
            <p>Type or pick your name. It's saved with every name you add or mark as claimed.</p>
            <form method="get">
                <input class="field" type="text" name="encoder_name" list="recentNames" value="<?= htmlspecialchars($staff_name) ?>" placeholder="Your name" autocomplete="off" required autofocus>
                <button type="submit" class="btn">Start</button>
            </form>
            <datalist id="recentNames">
                <?php foreach ($recent_names as $name): ?>
                <option value="<?= htmlspecialchars($name) ?>">
                <?php endforeach; ?>
            </datalist>
        </div>
        <?php else: ?>

        <div class="claim-toolbar">
            <input class="field" type="search" id="claimSearch" placeholder="Search a surname or initials…" autocomplete="off" aria-label="Search names">
            <button type="button" class="btn" id="addNameBtn">+ Add name</button>
        </div>
        <p class="claim-count"><span id="claimCount"><?= count($claimable_results) ?></span> result(s) ready for claiming</p>
        <div id="claimStatus" class="alert alert-success" hidden></div>

        <div class="claim-grid" id="claimGrid">
            <?php foreach ($claimable_results as $row): ?>
            <div class="claim-card" data-claim-id="<?= (int) $row['id'] ?>">
                <div>
                    <div class="claim-name"><?= htmlspecialchars($row['surname']) ?>, <?= htmlspecialchars($row['first_name_initials']) ?></div>
                    <div class="claim-added"><?= htmlspecialchars(added_label($row['created_at'])) ?></div>
                </div>
                <button type="button" class="btn btn-sm claim-btn">Claimed</button>
            </div>
            <?php endforeach; ?>
        </div>
        <div id="claimEmpty" class="empty-note" <?= empty($claimable_results) ? '' : 'hidden' ?>>No results ready for claiming.</div>
        <div id="claimNoMatch" class="no-match" hidden>
            No name matches "<span id="noMatchText"></span>".
            <button type="button" class="link-button" id="noMatchAdd">Add it</button>
        </div>

        <div id="addDialog" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="addDialogTitle">
            <div class="modal-box dialog-box">
                <h3 id="addDialogTitle">Add a name</h3>
                <p>For a patient whose results are ready to pick up.</p>
                <form id="addForm">
                    <input class="field" type="text" name="surname" placeholder="Surname" autocomplete="off" required>
                    <input class="field" type="text" name="first_name_initials" placeholder="First name initials (e.g. J.M.)" autocomplete="off" required>
                    <div class="dialog-actions">
                        <button type="button" class="btn btn-outline" data-close-dialog>Cancel</button>
                        <button type="submit" class="btn">Add</button>
                    </div>
                </form>
                <div id="addError" class="alert alert-error" hidden></div>
            </div>
        </div>

        <div id="claimDialog" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="claimDialogTitle">
            <div class="modal-box dialog-box">
                <h3 id="claimDialogTitle">Mark as claimed?</h3>
                <div class="claim-dialog-name" id="claimDialogName"></div>
                <p>The name will be removed from this list and from the waiting-area screen.</p>
                <div class="dialog-actions">
                    <button type="button" class="btn btn-outline" data-close-dialog>Cancel</button>
                    <button type="button" class="btn" id="claimConfirmBtn">Yes, claimed</button>
                </div>
                <div id="claimError" class="alert alert-error" hidden></div>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <script src="assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>"></script>
    <?php if (!$show_name_card): ?>
    <script>
    (function() {
        const currentStaffName = <?= json_encode($staff_name) ?>;
        const grid = document.getElementById('claimGrid');
        const search = document.getElementById('claimSearch');
        const countEl = document.getElementById('claimCount');
        const emptyEl = document.getElementById('claimEmpty');
        const noMatch = document.getElementById('claimNoMatch');
        const noMatchText = document.getElementById('noMatchText');
        const statusEl = document.getElementById('claimStatus');

        function escapeHtml(s) {
            const d = document.createElement('div');
            d.textContent = s == null ? '' : s;
            return d.innerHTML;
        }

        function post(fields) {
            const body = new URLSearchParams();
            Object.keys(fields).forEach(function(k) { body.set(k, fields[k]); });
            body.set('encoder_name', currentStaffName);
            return fetch('', { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); });
        }

        function showStatus(message) {
            statusEl.textContent = message;
            statusEl.hidden = false;
            clearTimeout(showStatus.timer);
            showStatus.timer = setTimeout(function() { statusEl.hidden = true; }, 5000);
        }

        // Search filters the list in place; the list IS the page.
        function applyFilter() {
            const query = search.value.trim().toLowerCase();
            const cards = grid.querySelectorAll('.claim-card');
            let visible = 0;
            cards.forEach(function(card) {
                const name = card.querySelector('.claim-name').textContent.toLowerCase();
                const show = query === '' || name.indexOf(query) !== -1;
                card.hidden = !show;
                if (show) visible++;
            });
            countEl.textContent = cards.length;
            emptyEl.hidden = cards.length !== 0;
            noMatch.hidden = !(cards.length > 0 && query !== '' && visible === 0);
            noMatchText.textContent = search.value.trim();
        }
        search.addEventListener('input', applyFilter);

        // --- Add a name ---
        const addForm = document.getElementById('addForm');
        const addError = document.getElementById('addError');
        function openAddDialog() {
            addForm.reset();
            addError.hidden = true;
            addForm.querySelector('button[type=submit]').disabled = false;
            const surname = search.value.trim();
            addForm.surname.value = surname;
            LabUI.openDialog('addDialog');
            (surname ? addForm.first_name_initials : addForm.surname).focus();
        }
        document.getElementById('addNameBtn').addEventListener('click', openAddDialog);
        document.getElementById('noMatchAdd').addEventListener('click', openAddDialog);

        addForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = addForm.querySelector('button[type=submit]');
            btn.disabled = true;
            post({
                add_claimable: '1',
                surname: addForm.surname.value.trim(),
                first_name_initials: addForm.first_name_initials.value.trim(),
            }).then(function(res) {
                if (!res.ok) {
                    addError.textContent = res.error || 'Could not add this name.';
                    addError.hidden = false;
                    btn.disabled = false;
                    return;
                }
                const name = res.row.surname + ', ' + res.row.first_name_initials;
                grid.insertAdjacentHTML('beforeend',
                    '<div class="claim-card" data-claim-id="' + res.row.id + '"><div>'
                    + '<div class="claim-name">' + escapeHtml(name) + '</div>'
                    + '<div class="claim-added">Added today</div></div>'
                    + '<button type="button" class="btn btn-sm claim-btn">Claimed</button></div>');
                LabUI.closeDialog(document.getElementById('addDialog'));
                search.value = '';
                applyFilter();
                showStatus('Added ' + name + ' to the list.');
            }).catch(function() {
                addError.textContent = 'Network error. Please try again.';
                addError.hidden = false;
                btn.disabled = false;
            });
        });

        // --- Mark claimed (asks first: there's no undo button) ---
        const claimDialogName = document.getElementById('claimDialogName');
        const claimConfirmBtn = document.getElementById('claimConfirmBtn');
        const claimError = document.getElementById('claimError');
        let pendingCard = null;

        grid.addEventListener('click', function(e) {
            const btn = e.target.closest('.claim-btn');
            if (!btn) return;
            pendingCard = btn.closest('.claim-card');
            claimDialogName.textContent = pendingCard.querySelector('.claim-name').textContent;
            claimError.hidden = true;
            claimConfirmBtn.disabled = false;
            LabUI.openDialog('claimDialog');
            claimConfirmBtn.focus();
        });

        claimConfirmBtn.addEventListener('click', function() {
            if (!pendingCard) return;
            const card = pendingCard;
            const name = card.querySelector('.claim-name').textContent;
            claimConfirmBtn.disabled = true;
            post({ mark_claimed: '1', id: card.dataset.claimId }).then(function(res) {
                // ok:false means it was already claimed (e.g. from another PC):
                // either way it no longer belongs on the list.
                card.remove();
                LabUI.closeDialog(document.getElementById('claimDialog'));
                applyFilter();
                showStatus(res.ok ? name + ' marked as claimed.' : name + ' was already marked as claimed.');
            }).catch(function() {
                claimError.textContent = 'Network error. Please try again.';
                claimError.hidden = false;
                claimConfirmBtn.disabled = false;
            });
        });

        // Names added or claimed on another PC show up within a minute —
        // but never reload over someone who is typing or has a pop-up open.
        setInterval(function() {
            if (!LabUI.isBusy() && search.value.trim() === '') location.reload();
        }, 60000);

        search.focus();
    })();
    </script>
    <?php endif; ?>
</body>
</html>
