<?php
// app_header.php
//
// Shared green header + top menu for the staff screens (Front Desk,
// Claiming, Extraction, Reports, Printer Setup). A function rather than a
// bare include so its loop variables can't overwrite the calling page's.
//
//   render_app_header('front_desk', $encoder_name, 'index.php?change=1');
//
// $staff_name/$change_url are optional: when given, the right side shows
// "Maria · change".
function render_app_header($active_page, $staff_name = '', $change_url = '') {
    $screens = [
        'front_desk' => ['index.php', 'Front Desk'],
        'claiming' => ['claiming.php', 'Claiming'],
        'extraction' => ['extraction.php', 'Extraction'],
        'reports' => ['admin.php', 'Reports'],
        'printer' => ['printer_setup.php', 'Printer'],
    ];
    ?>
    <div class="app-header">
        <img src="CHO.png" alt="CHO Logo" class="logo-img">
        <div class="title">Laboratory Queueing</div>
        <nav class="app-nav no-print" aria-label="Screens">
            <?php foreach ($screens as $key => [$href, $label]): ?>
            <a href="<?= $href ?>"<?= $key === $active_page ? ' class="active" aria-current="page"' : '' ?>><?= $label ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ($staff_name !== ''): ?>
        <div class="staff-id">
            <?= htmlspecialchars($staff_name) ?>
            <?php if ($change_url !== ''): ?>&middot; <a href="<?= htmlspecialchars($change_url) ?>">change</a><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
}
