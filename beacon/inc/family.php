<?php

const NOTICE_TAGS = [
    'important'    => ['Important', 'red'],
    'announcement' => ['Announcement', 'blue'],
    'clinic'       => ['Clinic Update', 'green'],
    'guidance'     => ['Guidance Notice', 'purple'],
    'commendation' => ['Commendation', 'amber'],
];
const POSTABLE_TAGS = ['important', 'announcement', 'clinic', 'guidance'];

function parent_children(array $parent): array {
    return db_all(
        "SELECT s.id, s.first_name, s.last_name, s.student_no, s.section_id, sec.grade_level, sec.name AS section_name,
                adv.first_name AS adviser_first, adv.last_name AS adviser_last, l.relationship
           FROM parent_student_links l
           JOIN students s ON s.id = l.student_id
           LEFT JOIN sections sec ON sec.id = s.section_id
           LEFT JOIN users adv ON adv.id = sec.adviser_id
          WHERE l.parent_id = ? AND l.status = 'approved' ORDER BY s.first_name",
        [$parent['id']]
    );
}

function parent_other_links(array $parent): array {
    return db_all(
        "SELECT l.status, l.review_note, l.created_at, s.first_name, s.last_name
           FROM parent_student_links l JOIN students s ON s.id = l.student_id
          WHERE l.parent_id = ? AND l.status <> 'approved' ORDER BY l.created_at DESC",
        [$parent['id']]
    );
}

function selected_child(array $children): ?array {
    $want = (int)($_GET['child'] ?? 0);
    foreach ($children as $c) if ((int)$c['id'] === $want) return $c;
    return $children[0] ?? null;
}

/** Who a notice reaches. v1.3 adds 'student': one child's family (automatic clinic check-in notices). */
const NOTICE_AUDIENCE_SQL = "(n.audience = 'all' OR (n.audience = 'grade' AND n.grade_level = ?) OR (n.audience = 'section' AND n.section_id = ?) OR (n.audience = 'student' AND n.student_id = ?))";

function notice_audience_params(array $child): array {
    return [(int)$child['grade_level'], (int)$child['section_id'], (int)$child['id']];
}

function notices_for_child(array $child): array {
    return db_all(
        'SELECT n.*, u.first_name AS a_first, u.last_name AS a_last, r.label AS a_role
           FROM notices n JOIN users u ON u.id = n.posted_by JOIN roles r ON r.id = u.role_id
          WHERE ' . NOTICE_AUDIENCE_SQL . ' ORDER BY n.published_at DESC',
        notice_audience_params($child)
    );
}

function parent_can_see_notice(array $parent, int $noticeId): bool {
    foreach (parent_children($parent) as $c) {
        if (db_value('SELECT 1 FROM notices n WHERE n.id = ? AND ' . NOTICE_AUDIENCE_SQL, array_merge([$noticeId], notice_audience_params($c)))) return true;
    }
    return false;
}

function notice_acknowledged(int $noticeId, int $parentId): ?string {
    return db_value('SELECT acknowledged_at FROM notice_acknowledgements WHERE notice_id = ? AND parent_id = ?', [$noticeId, $parentId]);
}

function notice_acknowledge(array $parent, int $noticeId): ?string {
    if ($parent['role'] !== 'parent' || !parent_can_see_notice($parent, $noticeId)) return 'Notice not found.';
    if (notice_acknowledged($noticeId, (int)$parent['id'])) return null;
    db_run('INSERT INTO notice_acknowledgements (notice_id, parent_id, acknowledged_at) VALUES (?, ?, ?)', [$noticeId, $parent['id'], now()]);
    audit('notice_ack', 'notices', $noticeId, null, [], $parent);
    return null;
}

/** Sections this user may post to. v1.3: a teacher may post only to their own advisory section. */
function postable_sections(array $user): array {
    if ($user['role'] === 'teacher') {
        $sec = advisory_section((int)$user['id']);
        return $sec ? [['id' => (int)$sec['id'], 'grade_level' => $sec['grade_level'], 'name' => $sec['name']]] : [];
    }
    return db_all('SELECT id, grade_level, name FROM sections ORDER BY grade_level, name');
}

function notice_create(array $user, array $in): ?string {
    if (!can('notices.manage', $user)) return 'Not allowed to post notices.';
    $title = trim($in['title'] ?? ''); $body = trim($in['body'] ?? '');
    $tag = $in['tag'] ?? ''; $aud = $in['audience'] ?? '';
    if ($title === '')                               return 'Please add a title.';
    if ($body === '')                                return 'Please write the notice.';
    if (!in_array($tag, POSTABLE_TAGS, true))        return 'Please choose a tag.';
    $grade = null; $section = null;
    if ($user['role'] === 'teacher') {
        // Audience is fixed to the adviser's own section, whatever the form sent.
        $sec = advisory_section((int)$user['id']);
        if (!$sec) return 'No advisory section is assigned to you yet, so you cannot post notices.';
        $aud = 'section';
        $in['section_id'] = (int)$sec['id'];
    }
    if (!in_array($aud, ['all', 'grade', 'section'], true)) return 'Please choose who should see it.';
    if ($aud === 'grade') {
        $grade = (int)($in['grade_level'] ?? 0);
        if (!db_value('SELECT 1 FROM sections WHERE grade_level = ?', [$grade])) return 'Please choose a grade level.';
    }
    if ($aud === 'section') {
        $section = (int)($in['section_id'] ?? 0);
        if (!in_array($section, array_map('intval', array_column(postable_sections($user), 'id')), true)) return 'Please choose one of your sections.';
    }
    db_run('INSERT INTO notices (title, body, tag, audience, grade_level, section_id, requires_ack, posted_by, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [mb_substr($title, 0, 150), $body, $tag, $aud, $grade, $section, !empty($in['requires_ack']) ? 1 : 0, $user['id'], now()]);
    audit('create', 'notices', db()->lastInsertId(), null, ['title', 'tag', 'audience'], $user);
    return null;
}

function notice_delete(array $user, int $id): ?string {
    if (!can('notices.manage', $user)) return 'Not allowed.';
    $n = db_one('SELECT posted_by FROM notices WHERE id = ?', [$id]);
    if (!$n) return 'Notice not found.';
    if ((int)$n['posted_by'] !== (int)$user['id'] && $user['role'] !== 'super_admin') return 'You can only remove notices you posted.';
    db_run('DELETE FROM notices WHERE id = ?', [$id]);
    audit('delete', 'notices', $id, null, [], $user);
    return null;
}

function notice_reach(array $n): array {
    $where = ['all' => '1=1', 'grade' => 'sec.grade_level = ?', 'section' => 's.section_id = ?', 'student' => 's.id = ?'][$n['audience']] ?? '1=0';
    $params = ['grade' => [$n['grade_level']], 'section' => [$n['section_id']], 'student' => [$n['student_id'] ?? 0]][$n['audience']] ?? [];
    $families = (int)db_value("SELECT COUNT(DISTINCT l.parent_id) FROM parent_student_links l JOIN students s ON s.id = l.student_id
                                 LEFT JOIN sections sec ON sec.id = s.section_id WHERE l.status = 'approved' AND $where", $params);
    $acks = (int)db_value('SELECT COUNT(*) FROM notice_acknowledgements WHERE notice_id = ?', [$n['id']]);
    return [$families, $acks];
}

function notice_audience_label(array $n): string {
    if ($n['audience'] === 'grade') return 'Grade ' . $n['grade_level'] . ' families';
    if ($n['audience'] === 'section') {
        $s = db_one('SELECT grade_level, name FROM sections WHERE id = ?', [$n['section_id']]);
        return $s ? 'Grade ' . $s['grade_level'] . ' – ' . $s['name'] . ' families' : 'One section';
    }
    if ($n['audience'] === 'student') {
        $st = db_one('SELECT first_name, last_name FROM students WHERE id = ?', [$n['student_id']]);
        return $st ? $st['first_name'] . ' ' . $st['last_name'] . "'s family" : "One student's family";
    }
    return 'All families';
}

function family_feed(array $parent, array $child): array {
    $sid = (int)$child['id'];
    $name = $child['first_name'];
    $items = [];

    $noticedVisits = [];
    foreach (notices_for_child($child) as $n) {
        $auto = $n['audience'] === 'student' && $n['clinic_visit_id'];
        if ($auto) $noticedVisits[(int)$n['clinic_visit_id']] = true;
        $items[] = ['tag' => $n['tag'], 'at' => $n['published_at'], 'title' => $n['title'], 'body' => $n['body'],
                    'meta' => $auto ? 'School clinic · ' . $n['a_first'] . ' ' . $n['a_last']
                                    : 'Posted by ' . $n['a_first'] . ' ' . $n['a_last'] . ' · ' . notice_audience_label($n),
                    'link' => $auto ? 'parent_records.php?child=' . $sid . '#clinic' : 'notice_detail.php?id=' . $n['id'] . '&child=' . $sid,
                    'ack' => $n['requires_ack'] ? (bool)notice_acknowledged((int)$n['id'], (int)$parent['id']) : null];
    }

    foreach (db_all('SELECT c.*, u.first_name AS by_first, u.last_name AS by_last FROM commendations c JOIN users u ON u.id = c.issued_by WHERE c.student_id = ? ORDER BY c.awarded_on DESC', [$sid]) as $c) {
        $items[] = ['tag' => 'commendation', 'at' => $c['awarded_on'] . ' 12:00:00', 'title' => "$name received a commendation: " . $c['title'],
                    'body' => (string)$c['description'], 'meta' => 'From ' . $c['by_first'] . ' ' . $c['by_last'], 'link' => null, 'ack' => null];
    }

    foreach (parent_clinic_view($parent, $sid) as $v) {
        if (isset($noticedVisits[(int)$v['id']])) continue;   // already shown through its automatic notice
        $when = date('g:i A', strtotime($v['arrived_at'])) . ($v['departed_at'] ? '–' . date('g:i A', strtotime($v['departed_at'])) : '');
        if ($v['sensitive']) {
            $body = "$name visited the school clinic. Details are kept private; you can contact the clinic or Guidance Office if you have questions.";
        } else {
            $parts = [$v['complaint']];
            if ($v['case_status']) $parts[] = 'Outcome: ' . (CLINIC_STATUS[$v['case_status']][0] ?? '');
            if ($v['medication_notes']) $parts[] = 'Medication given: ' . str_replace("\n", '; ', $v['medication_notes']);
            $body = implode(' · ', $parts);
        }
        $items[] = ['tag' => 'clinic', 'at' => $v['arrived_at'], 'title' => "$name visited the school clinic ($when)", 'body' => $body,
                    'meta' => $v['departed_at'] ? 'School clinic' : 'School clinic · still in the clinic', 'link' => 'parent_records.php?child=' . $sid . '#clinic', 'ack' => null];
    }

    $g = parent_guidance_view($parent, $sid);
    foreach ($g['cases'] as $c) {
        $items[] = ['tag' => 'guidance', 'at' => $c['opened_at'], 'title' => 'Guidance support: ' . $c['title'],
                    'body' => (string)($c['parent_summary'] ?: 'The Guidance Office is supporting ' . $name . '.'), 'meta' => 'Guidance Office · ' . (CASE_STATUS[$c['status']] ?? $c['status']),
                    'link' => 'parent_records.php?child=' . $sid . '#guidance', 'ack' => null];
        foreach ($c['notes'] as $n) {
            $items[] = ['tag' => 'guidance', 'at' => $n['created_at'], 'title' => 'Update from the Guidance Office', 'body' => $n['body'],
                        'meta' => 'Guidance Office · ' . $c['title'], 'link' => 'parent_records.php?child=' . $sid . '#guidance', 'ack' => null];
        }
    }

    usort($items, fn($a, $b) => strcmp($b['at'], $a['at']));
    return ['items' => $items, 'locked' => $g['locked_count']];
}
