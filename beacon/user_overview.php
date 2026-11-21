<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_page('user_overview');

const MERIT_CATEGORIES = ['conduct' => 'Good conduct', 'academic' => 'Academic', 'leadership' => 'Leadership',
                          'service' => 'Service', 'kindness' => 'Kindness', 'other' => 'Other'];

$students   = visible_students($user);
$studentIds = array_map('intval', array_column($students, 'id'));
$openModal  = null;
$old        = [];

if (is_post()) {
    verify_csrf();
    $action    = post('action');
    $studentId = (int)post('student_id', 0);
    $old       = $_POST;
    $student   = in_array($studentId, $studentIds, true) ? find_student($user, $studentId) : null;

    if ($action === 'issue_merit') {
        require_permission('commendation.issue');
        $category = (string)post('category', '');
        $title    = trim((string)post('title', ''));
        $reason   = trim((string)post('description', ''));
        $date     = (string)post('awarded_on', date('Y-m-d'));

        $error = !$student ? 'Please choose a student from your class.'
            : (!isset(MERIT_CATEGORIES[$category]) ? 'Please choose a category.'
            : ($title === '' ? 'Please give the commendation a title.'
            : ($reason === '' ? 'Please write the reason for this commendation.'
            : (!DateTime::createFromFormat('Y-m-d', $date) || $date > date('Y-m-d') ? 'Please choose a date that is today or earlier.' : null))));

        if ($error) {
            flash('error', $error);
            $openModal = 'modalMerit';
        } else {
            db_run('INSERT INTO commendations (student_id, issued_by, category, title, description, awarded_on, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$studentId, $user['id'], $category, mb_substr($title, 0, 120), $reason, $date, now()]);
            audit('create', 'commendations', db()->lastInsertId(), $studentId, ['title', 'description', 'category'], $user);
            flash('success', 'Commendation issued to ' . student_name($student) . '. Their family can see it in the Family Portal.');
            redirect('user_overview.php');
        }
    }
}

$section    = advisory_section((int)$user['id']);
$attendance = attendance_today($studentIds);
$welfare    = welfare_indicators($studentIds);
$presentToday = count(array_filter($attendance, fn($s) => in_array($s, ['present', 'late'], true)));

$in = $studentIds ? implode(',', array_fill(0, count($studentIds), '?')) : '0';

$pendingReports = (int)db_value("SELECT COUNT(*) FROM incident_reports WHERE reported_by = ? AND status = 'new'", [$user['id']]);
$urgentPending  = (int)db_value("SELECT COUNT(*) FROM incident_reports WHERE reported_by = ? AND status = 'new' AND severity IN ('high','urgent')", [$user['id']]);

$commendations = db_all(
    "SELECT c.title, c.description, c.category, c.awarded_on, s.first_name, s.last_name,
            u.first_name AS by_first, u.last_name AS by_last
       FROM commendations c
       JOIN students s ON s.id = c.student_id
       JOIN users u    ON u.id = c.issued_by
      WHERE c.student_id IN ($in)
      ORDER BY c.awarded_on DESC, c.id DESC",
    $studentIds
);
$monthStart     = date('Y-m-01');
$commThisMonth  = count(array_filter($commendations, fn($c) => $c['awarded_on'] >= $monthStart));

$tracker = teacher_pass_tracker($user, 7);
$openPasses = count(array_filter($tracker, fn($p) => in_array($p['status'], ['issued', 'arrived'], true) && !$p['departed_at']));

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$tz = config('timezone') ?: 'Asia/Manila';

layout_start($user, 'overview', "$greeting, {$user['first_name']}",
    $section ? "Here is your Grade {$section['grade_level']} advisory welfare overview." : 'Here is the welfare overview for your classes.');

function student_options(array $students, $selected): void {
    echo '<option value="">Choose a student…</option>';
    foreach ($students as $s) {
        $sel = (string)$selected === (string)$s['id'] ? ' selected' : '';
        echo '<option value="' . (int)$s['id'] . '"' . $sel . '>' . e($s['last_name'] . ', ' . $s['first_name']) . ' · ' . e($s['student_no']) . '</option>';
    }
}
?>

<div class="hero-card">
    <div>
        <div class="hero-date">
            <span data-clock="date" data-server-time="<?= time() ?>" data-tz="<?= e($tz) ?>"><?= e(date('l · F j, Y')) ?></span>
            · <span class="live-clock" data-clock="time" data-server-time="<?= time() ?>" data-tz="<?= e($tz) ?>"><?= e(date('g:i:s A')) ?></span>
        </div>
        <div class="hero-title"><?= $section ? e("Grade {$section['grade_level']} – {$section['name']}") : 'My classes' ?></div>
        <div class="hero-desc">
            <?= count($students) ?> learner<?= count($students) === 1 ? '' : 's' ?>
            · <?= $attendance ? "$presentToday present today" : 'Attendance not yet recorded today' ?>
        </div>
    </div>
    <div class="hero-btns">
        <a class="hero-btn" href="log_conduct.php"><?= icon('clipboard') ?> Log incident</a>
        <button type="button" class="hero-btn" data-open="modalMerit"><?= icon('heart') ?> Issue merit</button>
        <a class="hero-btn" href="#pass-tracker"><?= icon('shield') ?> Clinic passes<?= $openPasses ? ' (' . $openPasses . ')' : '' ?></a>
    </div>
</div>

<div class="stat-grid-3">
    <a class="stat-card-box" href="student_search.php">
        <div class="stat-icon-wrap ic-violet"><?= icon('users') ?></div>
        <div class="stat-info">
            <span class="stat-lbl">Assigned students</span>
            <div class="stat-val"><?= count($students) ?></div>
            <span class="stat-note">Open the student lookup</span>
        </div>
    </a>

    <a class="stat-card-box" href="log_conduct.php#my-reports">
        <div class="stat-icon-wrap ic-amber"><?= icon('clipboard') ?></div>
        <div class="stat-info">
            <span class="stat-lbl">Reports awaiting review</span>
            <div class="stat-val"><?= $pendingReports ?></div>
            <span class="stat-note <?= $urgentPending ? 'note-amber' : '' ?>"><?= $urgentPending ? "$urgentPending marked high or urgent" : 'Sent to Guidance or Clinic' ?></span>
        </div>
    </a>

    <button type="button" class="stat-card-box" data-open="modalCommendations" aria-haspopup="dialog">
        <div class="stat-icon-wrap ic-green"><?= icon('heart') ?></div>
        <div class="stat-info">
            <span class="stat-lbl">Commendations</span>
            <div class="stat-val"><?= count($commendations) ?></div>
            <span class="stat-note note-green"><?= $commThisMonth ?> this month · view list</span>
        </div>
    </button>
</div>

<div class="card">
    <div class="card-head-flex">
        <div>
            <div class="card-title">Class conduct &amp; health</div>
            <div class="card-sub">Today's attendance and student welfare indicators</div>
        </div>
        <a class="card-link" href="student_search.php">View all students &gt;</a>
    </div>

    <?php if (!$students): ?>
        <div class="empty">No students are assigned to your sections yet. Ask the administrator to set your advisory class.</div>
    <?php else: ?>
    <table class="table">
        <thead>
            <tr><th>Student</th><th>Student ID</th><th>Attendance</th><th>Welfare indicator</th><th style="width: 40px;"></th></tr>
        </thead>
        <tbody>
        <?php foreach ($students as $s):
            $sid = (int)$s['id'];
            $att = $attendance[$sid] ?? null;
            $attTone = ['present' => 'green', 'late' => 'amber', 'absent' => 'red', 'excused' => 'blue'][$att] ?? 'gray';
        ?>
            <tr>
                <td>
                    <div class="stu-cell">
                        <div class="stu-avatar"><?= e(student_initials($s)) ?></div>
                        <a class="stu-name" href="student_search.php?id=<?= $sid ?>"><?= e(student_name($s)) ?></a>
                    </div>
                </td>
                <td class="mono" style="color: #475569;"><?= e($s['student_no']) ?></td>
                <td><span class="badge badge-<?= $attTone ?>"><?= e($att ? ucfirst($att) : 'Not taken') ?></span></td>
                <td>
                    <div class="tags">
                    <?php foreach ($welfare[$sid] ?? [] as $tag): ?>
                        <span class="badge badge-<?= e($tag['tone']) ?>" title="<?= e($tag['hint']) ?>"><?= e($tag['label']) ?></span>
                    <?php endforeach; ?>
                    <?php if (empty($welfare[$sid])): ?><span class="badge badge-gray">No active flags</span><?php endif; ?>
                    </div>
                </td>
                <td style="text-align: right;"><a href="student_search.php?id=<?= $sid ?>" aria-label="Open <?= e(student_name($s)) ?>" style="color: #94a3b8; text-decoration: none; font-weight: bold;">›</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div class="card" id="pass-tracker">
    <div class="card-head-flex">
        <div>
            <div class="card-title">Clinic pass tracker</div>
            <div class="card-sub">Passes you issued in the last 7 days and what happened at the clinic. To send a student, log their physical state.</div>
        </div>
        <a class="btn btn-primary btn-sm" href="log_conduct.php?type=physical"><?= icon('clipboard') ?> Log physical state</a>
    </div>
    <?php if (!$tracker): ?>
        <div class="empty">No clinic passes in the last 7 days. When you log a student's physical state in <a href="log_conduct.php?type=physical">Log conduct</a>, you can issue a pass there and follow it here.</div>
    <?php else: ?>
    <div style="overflow-x: auto;">
    <table class="table">
        <thead><tr><th>Student</th><th>Pass code</th><th>Reason</th><th>Urgency</th><th>Where they are</th><th>Time in</th><th>Time out</th><th>Clinic outcome</th></tr></thead>
        <tbody>
        <?php foreach ($tracker as $p): ?>
            <tr>
                <td style="white-space: nowrap;"><strong><?= e($p['first_name'] . ' ' . $p['last_name']) ?></strong>
                    <div class="muted" style="font-size: 0.72rem;">Issued <?= e(date('M j, g:i A', strtotime($p['issued_at']))) ?></div></td>
                <td class="mono"><strong><?= e($p['pass_code']) ?></strong></td>
                <td><?= e(PASS_REASONS[$p['reason_type']] ?? label($p['reason_type'])) ?></td>
                <td><span class="badge badge-<?= $p['urgency'] === 'routine' ? 'gray' : 'red' ?>"><?= e(PASS_URGENCY[$p['urgency']] ?? ucfirst($p['urgency'])) ?></span></td>
                <td><span class="badge badge-<?= e($p['stage'][1]) ?>"><?= e($p['stage'][0]) ?></span></td>
                <td class="muted" style="white-space: nowrap;"><?= $p['arrived_at'] ? e(date('g:i A', strtotime($p['arrived_at']))) : '—' ?></td>
                <td class="muted" style="white-space: nowrap;"><?= $p['departed_at'] ? e(date('g:i A', strtotime($p['departed_at']))) : '—' ?></td>
                <td><?= $p['outcome'] ? '<span class="badge badge-' . e($p['outcome'][1]) . '">' . e($p['outcome'][0]) . '</span>' : '<span class="muted">—</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<div id="modalMerit" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="meritTitle" aria-hidden="true" <?= $openModal === 'modalMerit' ? 'data-autoopen' : '' ?>>
    <form class="modal-card" method="post" action="user_overview.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="issue_merit">
        <div class="modal-head">
            <div class="modal-icon ic-green"><?= icon('heart') ?></div>
            <div>
                <h3 id="meritTitle">Issue a commendation</h3>
                <p>Recognize good conduct. Families see commendations in the Family Portal.</p>
            </div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body">
            <div class="form-grid">
                <div class="field span-2">
                    <label for="merit_student">Student</label>
                    <select id="merit_student" name="student_id" required><?php student_options($students, $old['student_id'] ?? ''); ?></select>
                </div>
                <div class="field">
                    <label for="merit_category">Category</label>
                    <select id="merit_category" name="category" required>
                        <?php foreach (MERIT_CATEGORIES as $k => $v): ?>
                            <option value="<?= $k ?>" <?= ($old['category'] ?? 'conduct') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="merit_date">Date</label>
                    <input id="merit_date" type="date" name="awarded_on" max="<?= date('Y-m-d') ?>" value="<?= e($old['awarded_on'] ?? date('Y-m-d')) ?>" required>
                </div>
                <div class="field span-2">
                    <label for="merit_title">Title</label>
                    <input id="merit_title" name="title" maxlength="120" placeholder="e.g. Peer Helper of the Week" value="<?= e($old['title'] ?? '') ?>" required>
                </div>
                <div class="field span-2">
                    <label for="merit_reason">Reason</label>
                    <textarea id="merit_reason" name="description" rows="3" placeholder="What did the student do? Be specific — this is what the family will read." required><?= e($old['description'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-soft" data-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Issue commendation</button>
        </div>
    </form>
</div>

<div id="modalCommendations" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="commTitle" aria-hidden="true">
    <div class="modal-card wide">
        <div class="modal-head">
            <div class="modal-icon ic-green"><?= icon('heart') ?></div>
            <div>
                <h3 id="commTitle">Commendations</h3>
                <p><?= count($commendations) ?> awarded to your students · <?= $commThisMonth ?> this month</p>
            </div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body" style="padding-top: 8px;">
            <?php if (!$commendations): ?>
                <div class="empty">No commendations yet. Use “Issue merit” to recognize a student.</div>
            <?php else: ?>
            <table class="table">
                <thead><tr><th>Student</th><th>Commendation</th><th>Date</th></tr></thead>
                <tbody>
                <?php foreach ($commendations as $c): ?>
                    <tr>
                        <td style="white-space: nowrap;"><strong><?= e($c['first_name'] . ' ' . $c['last_name']) ?></strong></td>
                        <td>
                            <strong><?= e($c['title']) ?></strong>
                            <span class="badge badge-green" style="margin-left: 6px; font-size: 0.68rem;"><?= e(MERIT_CATEGORIES[$c['category']] ?? label($c['category'])) ?></span>
                            <div class="muted" style="font-size: 0.8rem; margin-top: 3px;"><?= e($c['description']) ?></div>
                            <div class="muted" style="font-size: 0.72rem; margin-top: 3px;">by <?= e($c['by_first'] . ' ' . $c['by_last']) ?></div>
                        </td>
                        <td class="muted" style="white-space: nowrap;"><?= e(date('M j, Y', strtotime($c['awarded_on']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-primary" data-open="modalMerit" data-close>Issue a new commendation</button>
        </div>
    </div>
</div>

<?php layout_end(); ?>
