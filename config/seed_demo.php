<?php
// SmartClass - demo data (command line only).
//
//   php config/seed_demo.php              add demo data (skipped if already added)
//   php config/seed_demo.php --resources  add the demo resource files (needs a writable uploads/)
//   php config/seed_demo.php --progress   add "opened / done" lecture progress + assignment links
//                                         (also part of the full seed; for demo data added before them)
//   php config/seed_demo.php --remove     delete ALL demo data (and nothing else)
//
// Everything added is easy to find and remove:
//   * demo accounts use emails ending in @smartclass.demo (password: password)
//   * demo classes use class codes starting with DEMO — all their lectures, resources,
//     assignments, quizzes, posts, attendance and notifications live inside them
//   * teacher@test.com teaches most demo classes and student@test.com is enrolled in
//     several, so the existing demo logins show the data
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/config/db.php';

mt_srand(20261020);   // same data every run
$args = array_slice($argv, 1);
$now = time();
$day = 86400;
$at = fn($ts) => date('Y-m-d H:i:s', $ts);
$pick = fn(array $a) => $a[mt_rand(0, count($a) - 1)];

// ---------------------------------------------------------------- remove
if (in_array('--remove', $args, true)) {
    $files = array_column($db->fetchAll(
        "SELECT r.file_path FROM resources r INNER JOIN classes c ON r.class_id = c.id
         WHERE c.class_code LIKE 'DEMO%' AND r.file_path IS NOT NULL"), 'file_path');
    $classes = $db->delete('classes', "class_code LIKE 'DEMO%'");
    $users = $db->delete('users', "email LIKE '%@smartclass.demo'");
    $logs = $db->delete('activity_log', "details LIKE '%[demo]%'");
    foreach ($files as $f) {
        if ($abs = uploadAbsolutePath($f)) @unlink($abs);
    }
    echo "Removed {$classes} demo classes (with all their content), {$users} demo accounts, {$logs} demo log entries, " . count($files) . " files.\n";
    exit;
}

$teacher_main = $db->fetchOne("SELECT id, name FROM users WHERE email = 'teacher@test.com'");
$student_main = $db->fetchOne("SELECT id, name FROM users WHERE email = 'student@test.com'");
if (!$teacher_main || !$student_main) {
    exit("The demo logins teacher@test.com / student@test.com must exist first.\n");
}

// ---------------------------------------------------------------- resources only
if (in_array('--resources', $args, true)) {
    seedResources($db, $teacher_main, $now, $day, $at);
    exit;
}
if (in_array('--progress', $args, true)) {
    seedProgress($db, $now, $day, $at);
    exit;
}

if ($db->fetchOne("SELECT id FROM classes WHERE class_code LIKE 'DEMO%' LIMIT 1")) {
    exit("Demo data is already there. Run with --remove first to start over.\n");
}

$hash = password_hash('password', PASSWORD_DEFAULT);
$count = [];
$add = function ($table, $row) use ($db, &$count) {
    $count[$table] = ($count[$table] ?? 0) + 1;
    return (int) $db->insert($table, $row);
};

// ---------------------------------------------------------------- people
$teacher2 = $add('users', ['name' => 'Ms. Fatima Noori', 'email' => 'fatima.noori@smartclass.demo', 'password' => $hash,
    'role' => 'teacher', 'is_approved' => 1, 'created_at' => $at($now - 40 * $day), 'last_login' => $at($now - $day)]);
$teacher3 = $add('users', ['name' => 'Mr. Bilal Ahmadzai', 'email' => 'bilal.ahmadzai@smartclass.demo', 'password' => $hash,
    'role' => 'teacher', 'is_approved' => 1, 'created_at' => $at($now - 38 * $day), 'last_login' => $at($now - 2 * $day)]);

$student_names = ['Aisha Rahimi', 'Omar Siddiqui', 'Zainab Karimi', 'Yusuf Haidari', 'Maryam Sultani', 'Hamza Wardak',
    'Sana Popal', 'Idris Mohammadi', 'Laila Azizi', 'Tariq Stanekzai', 'Nadia Hakimi', 'Farhad Yousafzai',
    'Mina Saberi', 'Kamran Durrani', 'Hira Nazari'];
$students = [];   // id => skill (0..1): drives grades, quiz answers and attendance
foreach ($student_names as $i => $name) {
    $email = strtolower(str_replace(' ', '.', $name)) . '@smartclass.demo';
    $id = $add('users', ['name' => $name, 'email' => $email, 'password' => $hash, 'role' => 'student', 'is_approved' => 1,
        'created_at' => $at($now - (35 - $i) * $day), 'last_login' => $at($now - mt_rand(0, 6) * $day - mt_rand(0, 80000))]);
    $students[$id] = 0.45 + mt_rand(0, 50) / 100;
}
$students[(int) $student_main['id']] = 0.8;

// Waiting for approval (Students › Approve Students)
foreach (['Rahim Shinwari', 'Gulalai Jalali', 'Sohail Barakzai', 'Parwana Amiri', 'Jawid Safi', 'Shabnam Ghani',
          'Wahid Mangal', 'Roya Habibi', 'Naveed Kakar', 'Farzana Rasuli'] as $i => $name) {
    $add('users', ['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '@smartclass.demo', 'password' => $hash,
        'role' => 'student', 'is_approved' => 0, 'created_at' => $at($now - $i * 7000 - 3600)]);
}

// ---------------------------------------------------------------- classes
$class_defs = [
    ['DEMOMATH', 'Mathematics — Grade 10', 'Algebra, geometry and statistics for grade 10.', 'Math,Grade 10', 'royal', $teacher_main['id']],
    ['DEMOPHYS', 'Physics Fundamentals', 'Motion, forces, energy and waves with simple experiments.', 'Science,Physics', 'teal', $teacher_main['id']],
    ['DEMOENGL', 'English Literature', 'Reading, analysing and writing about short stories and poems.', 'Languages', 'berry', $teacher_main['id']],
    ['DEMOCHEM', 'Chemistry Basics', 'Atoms, the periodic table, reactions and lab safety.', 'Science,Chemistry', 'emerald', $teacher_main['id']],
    ['DEMOBIOL', 'Biology: Cells & Life', 'Cells, genetics, ecosystems and the human body.', 'Science,Biology', 'sunset', $teacher_main['id']],
    ['DEMOHIST', 'World History', 'Ancient civilisations to the modern world.', 'Humanities', 'slate', $teacher_main['id']],
    ['DEMONETW', 'Computer Networks', 'How the internet works: TCP/IP, routing, DNS and HTTP.', 'IT,Networking', 'indigo', $teacher_main['id']],
    ['DEMOJAVA', 'JavaScript for Beginners', 'Variables, functions, the DOM and small projects.', 'Programming,Web', 'crimson', $teacher_main['id']],
    ['DEMOPASH', 'Pashto Language & Writing', 'Grammar, reading comprehension and essay writing in Pashto.', 'Languages,Pashto', 'teal', $teacher2],
    ['DEMOACCT', 'Introduction to Accounting', 'Bookkeeping, journals, ledgers and financial statements.', 'Business', 'sunset', $teacher3],
];
$classes = [];   // id => [def, members]
foreach ($class_defs as $i => [$code, $name, $desc, $tags, $theme, $tid]) {
    $cid = $add('classes', ['teacher_id' => $tid, 'class_name' => $name, 'class_code' => $code, 'description' => $desc,
        'tags' => $tags, 'theme' => $theme, 'stream_permission' => $i % 3 === 0 ? 'post' : 'comment',
        'require_approval' => 1, 'created_at' => $at($now - (30 - $i) * $day)]);
    addClassOwner($cid, $tid);
    $classes[$cid] = ['code' => $code, 'name' => $name, 'teacher' => (int) $tid, 'members' => []];
}

// Enrolment: 8–12 students per class; student@test.com is in five classes
$all_students = array_keys($students);
$demo_only = array_values(array_diff($all_students, [(int) $student_main['id']]));
$ci = 0;
foreach ($classes as $cid => &$c) {
    $pool = $demo_only;
    shuffle($pool);
    $members = array_slice($pool, 0, mt_rand(8, 12));
    if ($ci < 5) $members[] = (int) $student_main['id'];
    foreach ($members as $sid) {
        $add('class_members', ['class_id' => $cid, 'student_id' => $sid, 'joined_at' => $at($now - mt_rand(20, 28) * $day)]);
    }
    $c['members'] = $members;
    $ci++;
}
unset($c);

// Join requests waiting for the teacher (People › Join requests)
// one or two per class (at least 12), from approved demo students not yet in that class
$req = 0;
foreach ($classes as $cid => $c) {
    $outside = array_values(array_diff($demo_only, $c['members']));
    shuffle($outside);
    foreach (array_slice($outside, 0, $req < 12 ? 2 : 1) as $sid) {
        $add('class_join_requests', ['class_id' => $cid, 'student_id' => $sid, 'created_at' => $at($now - mt_rand(1, 40) * 3600)]);
        $req++;
    }
}

// ---------------------------------------------------------------- topics + lectures
$lecture_bank = [
    'DEMOMATH' => [['Unit 1: Algebra', [['Linear equations', "A linear equation has the form ax + b = c.\n\nTo solve it, isolate x: subtract b, then divide by a.\n\n```\n3x + 5 = 20\n3x = 15\nx = 5\n```\n\nAlways check your answer by putting it back into the equation."],
        ['Quadratic equations', "A quadratic equation looks like ax² + bx + c = 0.\n\nWe solve it by factorising, completing the square, or with the quadratic formula.\n\n```\nx = (-b ± √(b² − 4ac)) / 2a\n```"]]],
        ['Unit 2: Geometry', [['Angles and triangles', "The angles in a triangle always add up to 180°.\n\nAn exterior angle equals the sum of the two opposite interior angles."]]]],
    'DEMOPHYS' => [['Motion', [['Speed, velocity and acceleration', "Speed = distance ÷ time.\n\nVelocity is speed in a given direction. Acceleration is how fast velocity changes:\n\n```\na = (v − u) / t\n```"],
        ["Newton's laws", "1. An object stays at rest or keeps moving unless a force acts on it.\n2. F = m × a.\n3. Every action has an equal and opposite reaction."]]],
        ['Energy', [['Kinetic and potential energy', "Kinetic energy: KE = ½ m v².\n\nGravitational potential energy: PE = m g h."]]]],
    'DEMOENGL' => [['Short stories', [['Elements of a short story', "Every short story has characters, a setting, a plot, a conflict and a theme.\n\nWhile you read, note how the conflict changes the main character."],
        ['Writing a summary', "A good summary is short, in your own words, and keeps only the main events."]]],
        ['Poetry', [['Imagery and metaphor', "Imagery appeals to the senses. A metaphor says one thing *is* another: \"Time is a thief\"."]]]],
    'DEMOCHEM' => [['Atoms', [['Inside the atom', "Atoms are made of protons, neutrons and electrons.\n\nThe atomic number is the number of protons."], ['The periodic table', "Elements are arranged by atomic number. Columns are groups; rows are periods."]]],
        ['Reactions', [['Balancing equations', "Atoms are never created or destroyed in a reaction.\n\n```\n2H2 + O2 -> 2H2O\n```"]]]],
    'DEMOBIOL' => [['Cells', [['Plant and animal cells', "Both have a nucleus, cytoplasm and a cell membrane. Plant cells also have a cell wall and chloroplasts."], ['Cell division', "Mitosis makes two identical cells; meiosis makes sex cells with half the chromosomes."]]],
        ['Ecosystems', [['Food chains', "Energy flows from producers to consumers. Only about 10% passes to the next level."]]]],
    'DEMOHIST' => [['Ancient world', [['Mesopotamia', "Between the Tigris and Euphrates rivers, the first cities and writing (cuneiform) appeared."], ['The Silk Road', "Trade routes linked China to the Mediterranean, carrying silk, spices and ideas."]]],
        ['Modern world', [['The Industrial Revolution', "From about 1760, machines, factories and steam power changed how people worked and lived."]]]],
    'DEMONETW' => [['Basics', [['What is a network?', "A network connects devices so they can share data. LAN = local, WAN = wide area."], ['The TCP/IP model', "Four layers: Link, Internet, Transport, Application.\n\n```\nping 8.8.8.8\ntraceroute example.com\n```"]]],
        ['The web', [['How DNS works', "DNS turns names like example.com into IP addresses, using a hierarchy of servers."]]]],
    'DEMOJAVA' => [['Getting started', [['Variables and types', "Use let and const to declare variables.\n\n```\nconst name = \"Aisha\";\nlet score = 10;\nscore += 5;\n```"], ['Functions', "```\nfunction greet(name) {\n  return \"Hello, \" + name + \"!\";\n}\n```"]]],
        ['The DOM', [['Changing a page with JavaScript', "```\ndocument.getElementById(\"title\").textContent = \"Hi!\";\n```\n\nAlways run DOM code after the page has loaded."]]]],
    'DEMOPASH' => [['ګرامر', [['نوم او صفت', "په پښتو کې نوم د کس، ځای یا شي نوم دی.\n\nصفت د نوم ځانګړتیا ښيي، لکه: ښکلی ګل."], ['فعل', "فعل د کار یا حالت ښکارندوی دی، لکه: لیکل، لوستل."]]],
        ['لیکنه', [['د مقالې جوړښت', "هره مقاله سریزه، اصلي برخه او پایله لري."]]]],
    'DEMOACCT' => [['Bookkeeping', [['Debits and credits', "Every transaction has two sides. Total debits always equal total credits."], ['The journal', "Record each transaction in date order before posting it to the ledger."]]],
        ['Statements', [['The balance sheet', "Assets = Liabilities + Equity."]]]],
];
$videos = ['https://www.youtube.com/watch?v=NybHckSEQBI', 'https://www.youtube.com/watch?v=kKKM8Y-u7ds', null, null];
$topics = [];   // class id => [topic ids]
foreach ($classes as $cid => $c) {
    $pos = 0;
    foreach ($lecture_bank[$c['code']] as [$topic, $lectures]) {
        $tid = $add('class_topics', ['class_id' => $cid, 'name' => $topic, 'position' => $pos++, 'created_at' => $at($now - 26 * $day)]);
        $topics[$cid][] = $tid;
        foreach ($lectures as $li => [$title, $content]) {
            $add('lectures', ['class_id' => $cid, 'topic_id' => $tid, 'teacher_id' => $c['teacher'], 'title' => $title,
                'content' => $content, 'notes' => $li === 0 ? 'Read this before the next class and bring one question.' : null,
                'video_url' => $pick($videos), 'created_at' => $at($now - (25 - $pos * 4 - $li) * $day)]);
        }
    }
}

// ---------------------------------------------------------------- assignments + submissions + private comments
$assignment_titles = ['Worksheet 1', 'Short report', 'Practice problems', 'Research task', 'Weekly reflection'];
$answers = ['I solved all the questions and showed my working.', 'Here is my report — I used two sources from the library.',
    'Attached are my answers. Question 4 was difficult.', 'My summary of the chapter is below.', 'I worked with my notes from the lecture.'];
$feedback = ['Great work, clear explanations.', 'Good effort — check question 3 again.', 'Well organised. Add more detail next time.',
    'Excellent!', 'Some mistakes in the calculations; see the lecture notes.'];
$assignments = [];
foreach ($classes as $cid => $c) {
    foreach ([[-9, 'past'], [3, 'open']] as $k => [$due_in, $kind]) {
        if ($k === 1 && mt_rand(0, 2) === 0) continue;   // not every class has an open one
        $max = $pick([10, 20, 50, 100]);
        $aid = $add('assignments', ['class_id' => $cid, 'topic_id' => $topics[$cid][$k] ?? null, 'teacher_id' => $c['teacher'],
            'title' => $assignment_titles[($cid + $k) % 5] . ': ' . explode(':', $lecture_bank[$c['code']][$k][1][0][0])[0],
            'description' => "Complete the task for this unit and hand it in before the due date.\n\n1. Read the lecture again.\n2. Answer every question.\n3. Show your working.",
            'due_date' => $at($now + $due_in * $day), 'max_marks' => $max, 'created_at' => $at($now - (12 - $k * 6) * $day)]);
        $assignments[] = $aid;
        foreach ($c['members'] as $sid) {
            $skill = $students[$sid];
            if (mt_rand(0, 100) / 100 > $skill + 0.15) continue;   // weaker students sometimes don't hand in
            $sub_at = $kind === 'past' ? $now - (9 + mt_rand(-2, 1)) * $day : $now - mt_rand(0, 2) * $day;
            $late = $kind === 'past' && $sub_at > $now - 9 * $day;
            $graded = $kind === 'past' && mt_rand(0, 9) < 8;
            $marks = round(min($max, $max * ($skill + mt_rand(-10, 15) / 100)), $max <= 20 ? 1 : 0);
            $subid = $add('submissions', ['assignment_id' => $aid, 'student_id' => $sid, 'content' => $pick($answers),
                'submitted_at' => $at($sub_at), 'status' => $graded ? 'graded' : ($late ? 'late' : 'submitted'),
                'marks_obtained' => $graded ? max(0, $marks) : null, 'feedback' => $graded ? $pick($feedback) : null,
                'graded_by' => $graded ? $c['teacher'] : null, 'graded_at' => $graded ? $at($sub_at + mt_rand(1, 3) * $day) : null]);
            if (mt_rand(0, 6) === 0) {
                $add('private_messages', ['class_id' => $cid, 'student_id' => $sid, 'user_id' => $sid, 'context_type' => 'assignment',
                    'context_id' => $aid, 'body' => 'Can I hand in a corrected version?', 'is_read' => 1, 'created_at' => $at($sub_at + 3600)]);
                $add('private_messages', ['class_id' => $cid, 'student_id' => $sid, 'user_id' => $c['teacher'], 'context_type' => 'assignment',
                    'context_id' => $aid, 'body' => 'Yes — resubmit before the due date and I will look again.', 'is_read' => mt_rand(0, 1), 'created_at' => $at($sub_at + 9000)]);
            }
        }
    }
}

// ---------------------------------------------------------------- quizzes + attempts
$quiz_bank = [
    ['What is 7 × 8?', ['54', '56', '64', '58'], 1], ['Which is a prime number?', ['21', '27', '29', '33'], 2],
    ['What is the capital of Afghanistan?', ['Kandahar', 'Herat', 'Kabul', 'Mazar-i-Sharif'], 2],
    ['Water boils at (sea level):', ['90 °C', '100 °C', '110 °C', '120 °C'], 1], ['Which gas do plants take in?', ['Oxygen', 'Nitrogen', 'Carbon dioxide', 'Helium'], 2],
    ['HTTP stands for:', ['HyperText Transfer Protocol', 'High Transfer Text Process', 'Hosted Text Protocol', 'Hyper Tool Transfer Program'], 0],
    ['Which keyword declares a constant in JavaScript?', ['var', 'let', 'const', 'static'], 2], ['F = m × a is Newton\'s:', ['First law', 'Second law', 'Third law', 'Law of gravity'], 1],
    ['The powerhouse of the cell is the:', ['Nucleus', 'Ribosome', 'Mitochondrion', 'Cell wall'], 2], ['Assets = Liabilities + ?', ['Revenue', 'Equity', 'Expenses', 'Cash'], 1],
    ['A metaphor is:', ['A comparison using like or as', 'Saying one thing is another', 'A rhyme', 'A long poem'], 1], ['DNS converts names into:', ['MAC addresses', 'IP addresses', 'Ports', 'Passwords'], 1],
];
foreach ($classes as $cid => $c) {
    $qid = $add('quizzes', ['class_id' => $cid, 'topic_id' => $topics[$cid][0] ?? null, 'teacher_id' => $c['teacher'],
        'title' => 'Quiz 1 — ' . $lecture_bank[$c['code']][0][0], 'description' => 'Answer every question. You have one attempt.',
        'time_limit' => $pick([null, 10, 15]), 'due_date' => $at($now + mt_rand(-5, 6) * $day), 'created_at' => $at($now - 8 * $day)]);
    $qs = [];
    $set = $quiz_bank;
    shuffle($set);
    foreach (array_slice($set, 0, mt_rand(4, 5)) as $pos => [$text, $opts, $right]) {
        $qqid = $add('quiz_questions', ['quiz_id' => $qid, 'question' => $text, 'marks' => $pick([1, 2, 2]), 'position' => $pos]);
        $oids = [];
        foreach ($opts as $op => $otext) {
            $oids[$op] = $add('quiz_options', ['question_id' => $qqid, 'option_text' => $otext, 'is_correct' => $op === $right ? 1 : 0, 'position' => $op]);
        }
        $marks = (int) $db->fetchOne("SELECT marks FROM quiz_questions WHERE id = ?", [$qqid])['marks'];
        $qs[] = [$qqid, $oids, $right, $marks];
    }
    $max = array_sum(array_column($qs, 3));
    foreach ($c['members'] as $sid) {
        if (mt_rand(0, 100) / 100 > $students[$sid] + 0.2) continue;
        $start = $now - mt_rand(1, 7) * $day - mt_rand(0, 30000);
        $score = 0;
        $rows = [];
        foreach ($qs as [$qqid, $oids, $right, $marks]) {
            $correct = mt_rand(0, 100) / 100 < $students[$sid];
            $chosen = $correct ? $oids[$right] : $oids[($right + mt_rand(1, 3)) % 4];
            if ($correct) $score += $marks;
            $rows[] = ['question_id' => $qqid, 'option_id' => $chosen, 'is_correct' => $correct ? 1 : 0];
        }
        $att = $add('quiz_attempts', ['quiz_id' => $qid, 'student_id' => $sid, 'started_at' => $at($start),
            'submitted_at' => $at($start + mt_rand(240, 600)), 'score' => $score, 'max_score' => $max]);
        foreach ($rows as $r) {
            $add('quiz_answers', $r + ['attempt_id' => $att]);
        }
    }
}

// ---------------------------------------------------------------- private "Message teacher" conversations
$questions = ['I was sick yesterday — what did I miss?', 'Could you explain question 3 of the worksheet again?',
    'Is the quiz open-book?', 'Can I sit with my group for the project?', 'I could not open the lecture video.'];
foreach ($classes as $cid => $c) {
    foreach (array_slice($c['members'], 0, 2) as $k => $sid) {
        $t0 = $now - mt_rand(1, 6) * $day;
        $add('private_messages', ['class_id' => $cid, 'student_id' => $sid, 'user_id' => $sid, 'context_type' => 'class',
            'body' => $questions[($cid + $k) % 5], 'is_read' => $k, 'created_at' => $at($t0)]);
        if ($k === 1) {
            $add('private_messages', ['class_id' => $cid, 'student_id' => $sid, 'user_id' => $c['teacher'], 'context_type' => 'class',
                'body' => 'Thanks for asking — see me after class and we will go through it.', 'is_read' => 0, 'created_at' => $at($t0 + 5400)]);
        }
    }
}

// ---------------------------------------------------------------- stream: announcements + comments
$posts = ['Welcome to the class! Please read the first lecture before Monday.', 'Reminder: the worksheet is due on Thursday. Ask here if anything is unclear.',
    'Great results in the quiz — well done everyone!', 'Tomorrow we will have a short practical session. Bring your notebook.',
    'Office hours this week: Wednesday 2–3 PM.'];
$comments = ['Thank you, teacher!', 'Will the quiz include unit 2?', 'Can we get extra practice questions?', 'Got it 👍', 'I will be a bit late tomorrow.'];
foreach ($classes as $cid => $c) {
    foreach (array_slice($posts, 0, mt_rand(1, 3)) as $pi => $body) {
        $pid = $add('announcements', ['class_id' => $cid, 'user_id' => $c['teacher'], 'body' => $body, 'pinned' => $pi === 0 ? 1 : 0,
            'created_at' => $at($now - (20 - $pi * 6) * $day)]);
        foreach (array_slice($c['members'], 0, mt_rand(0, 3)) as $k => $sid) {
            $add('announcement_comments', ['announcement_id' => $pid, 'user_id' => $sid, 'body' => $comments[($pid + $k) % 5],
                'created_at' => $at($now - (19 - $pi * 6) * $day + $k * 3600)]);
        }
    }
}

// ---------------------------------------------------------------- attendance: last 10 school days
$days = [];
for ($d = 1; count($days) < 10; $d++) {
    $ts = $now - $d * $day;
    if (date('N', $ts) <= 5) $days[] = date('Y-m-d', $ts);   // Mon–Fri
}
foreach (array_slice(array_keys($classes), 0, 6) as $cid) {
    foreach ($days as $date) {
        foreach ($classes[$cid]['members'] as $sid) {
            $r = mt_rand(0, 100) / 100;
            $skill = $students[$sid];
            $status = $r < $skill + 0.1 ? 'present' : ($r < $skill + 0.2 ? 'late' : ($r < $skill + 0.25 ? 'official' : 'absent'));
            $add('attendance', ['class_id' => $cid, 'student_id' => $sid, 'date' => $date, 'status' => $status,
                'note' => $status === 'official' ? 'School sports event' : null, 'marked_by' => $classes[$cid]['teacher'],
                'created_at' => $date . ' 09:' . sprintf('%02d', mt_rand(0, 30)) . ':00']);
        }
    }
}

// ---------------------------------------------------------------- notifications (in-app inbox)
$main_class = array_key_first($classes);
$note = function ($user, $type, $title, $msg, $link, $sender, $cid, $ago) use ($add, $now, $at) {
    $add('notifications', ['user_id' => $user, 'sender_id' => $sender, 'class_id' => $cid, 'type' => $type, 'title' => $title,
        'message' => $msg, 'link' => $link, 'is_read' => $ago > 3 * 86400 ? 1 : 0, 'created_at' => $at($now - $ago)]);
};
foreach (array_slice(array_keys($classes), 0, 5) as $i => $cid) {
    $c = $classes[$cid];
    $note($student_main['id'], 'lecture_added', 'New lecture: ' . $lecture_bank[$c['code']][0][1][0][0], 'A new lecture was added to ' . $c['name'] . '.',
        'pages/classes/classwork.php?id=' . $cid, $c['teacher'], $cid, ($i + 1) * 20000);
    $note($student_main['id'], 'submission_graded', 'Graded: ' . $assignment_titles[$cid % 5], 'Your work in ' . $c['name'] . ' was graded.',
        'pages/classes/grades.php?id=' . $cid, $c['teacher'], $cid, ($i + 2) * 50000);
    $s = $c['members'][0];
    $note($c['teacher'], 'submission_received', 'Submitted: ' . $assignment_titles[$cid % 5], $student_names[$i] . ' handed in work in ' . $c['name'] . '.',
        'pages/classes/grades.php?id=' . $cid, $s, $cid, ($i + 1) * 15000);
    $note($c['teacher'], 'join_requested', 'Request to join ' . $c['name'], 'A student asked to join ' . $c['name'] . '.',
        'pages/classes/people.php?id=' . $cid . '#requests', $s, $cid, ($i + 1) * 9000);
}

$db->insert('activity_log', ['user_id' => $teacher_main['id'], 'action' => 'seed_demo', 'module' => 'system',
    'details' => '[demo] Demo data added: ' . count($classes) . ' classes, ' . count($students) . ' students', 'ip_address' => 'cli']);

seedResources($db, $teacher_main, $now, $day, $at);
seedProgress($db, $now, $day, $at);

ksort($count);
echo "Demo data added:\n";
foreach ($count as $table => $n) {
    printf("  %-22s %4d\n", $table, $n);
}
echo "\nLog in as teacher@test.com or student@test.com (password: password),\n"
   . "or any demo account, e.g. aisha.rahimi@smartclass.demo / fatima.noori@smartclass.demo (password: password).\n";

// ---------------------------------------------------------------- lecture progress + assignment links
/**
 * Which demo students opened / finished each demo lecture, and a reading link on each demo assignment.
 * Skipped when demo progress already exists. Deterministic (own seed).
 */
function seedProgress($db, $now, $day, $at) {
    if ($db->fetchOne("SELECT p.id FROM content_progress p INNER JOIN classes c ON p.class_id = c.id WHERE c.class_code LIKE 'DEMO%' LIMIT 1")) {
        echo "Demo progress is already there.\n";
        return;
    }
    mt_srand(7020);
    $rows = 0;
    foreach ($db->fetchAll("SELECT l.id, l.class_id, l.created_at FROM lectures l INNER JOIN classes c ON l.class_id = c.id WHERE c.class_code LIKE 'DEMO%'") as $l) {
        $posted = strtotime($l['created_at']);
        foreach ($db->fetchAll("SELECT student_id, joined_at FROM class_members WHERE class_id = ?", [$l['class_id']]) as $m) {
            if (mt_rand(0, 9) < 3) continue;   // about 3 in 10 have not opened it
            $first = max($posted, strtotime($m['joined_at'])) + mt_rand(1, 48) * 3600;   // never before they joined
            if ($first > $now - 3600) continue;
            $last = min($now - 600, $first + mt_rand(0, 5) * $day);
            $done = mt_rand(0, 9) < 6 ? $at(min($now - 300, $last + 1800)) : null;
            $db->insert('content_progress', ['class_id' => $l['class_id'], 'student_id' => $m['student_id'], 'item_type' => 'lecture',
                'item_id' => $l['id'], 'first_opened_at' => $at($first), 'last_opened_at' => $at($last),
                'open_count' => mt_rand(1, 4), 'done_at' => $done]);
            $rows++;
        }
    }
    $links = [['https://www.khanacademy.org/', 'Khan Academy — practice exercises'], ['https://en.wikipedia.org/wiki/Main_Page', 'Background reading'],
              ['https://www.w3schools.com/', 'W3Schools reference']];
    $n_links = 0;
    foreach ($db->fetchAll("SELECT a.id FROM assignments a INNER JOIN classes c ON a.class_id = c.id WHERE c.class_code LIKE 'DEMO%'") as $i => $a) {
        [$url, $title] = $links[$i % count($links)];
        $db->insert('assignment_materials', ['assignment_id' => $a['id'], 'kind' => 'link', 'url' => $url, 'title' => $title, 'position' => 0]);
        $n_links++;
    }
    echo "Demo progress added: {$rows} lecture opens, {$n_links} assignment links.\n";
}

// ---------------------------------------------------------------- resources (real files)
function seedResources($db, $teacher_main, $now, $day, $at) {
    if ($db->fetchOne("SELECT r.id FROM resources r INNER JOIN classes c ON r.class_id = c.id WHERE c.class_code LIKE 'DEMO%' LIMIT 1")) {
        echo "Demo resources are already there.\n";
        return;
    }
    $dir = ensureProtectedUploadDir('resources');
    if ($dir === false) {
        echo "\nResources skipped: uploads/resources is not writable by this user.\n"
           . "Make uploads/ writable (see README › Local Apache), then run:  php config/seed_demo.php --resources\n";
        return;
    }
    $classes = $db->fetchAll("SELECT id, class_name, teacher_id FROM classes WHERE class_code LIKE 'DEMO%' ORDER BY id");
    $kinds = [['Course outline', 'other', 'txt'], ['Unit 1 summary notes', 'presentation', 'pdf'], ['Practice worksheet', 'template', 'pdf']];
    $n = 0;
    foreach ($classes as $i => $c) {
        foreach ($kinds as $k => [$title, $category, $ext]) {
            if ($k === 2 && $i % 2) continue;
            $text = "{$c['class_name']}\n{$title}\n\nThis is a demo file created by SmartClass for testing downloads.";
            $body = $ext === 'txt' ? $text : demoPdf($c['class_name'] . ' - ' . $title);
            $name = bin2hex(random_bytes(16)) . '.' . $ext;
            if (file_put_contents($dir . '/' . $name, $body) === false) continue;
            $db->insert('resources', ['class_id' => $c['id'], 'uploaded_by' => $c['teacher_id'], 'title' => $title,
                'description' => 'Download and keep this for the unit.', 'category' => $category,
                'file_path' => 'uploads/resources/' . $name, 'original_name' => strtolower(str_replace(' ', '-', $title)) . '.' . $ext,
                'file_type' => $ext, 'file_size' => strlen($body), 'download_count' => mt_rand(0, 12),
                'created_at' => $at($now - mt_rand(3, 20) * $day)]);
            $n++;
        }
    }
    echo "Demo resources added: {$n}\n";
}

/** A tiny valid one-page PDF with a line of text. */
function demoPdf($line) {
    $line = preg_replace('/[^\x20-\x7E]/', '', $line);
    $stream = "BT /F1 18 Tf 72 720 Td (" . addcslashes($line, '()\\') . ") Tj ET";
    $objs = ["<< /Type /Catalog /Pages 2 0 R >>", "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>",
        "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}\nendstream", "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>"];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objs as $i => $o) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n{$o}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $o) $pdf .= sprintf("%010d 00000 n \n", $o);
    return $pdf . "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
}
