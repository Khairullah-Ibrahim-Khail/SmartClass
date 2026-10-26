<?php
/**
 * AJAX Request Handler
 * Google Classroom Clone - Teacher & Student System
 */

// Include required files (defines APP_URL and starts the session)
require_once dirname(__DIR__) . '/config/db.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if request is AJAX
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// Get action from POST data
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Check if user is logged in for protected actions
$public_actions = ['login', 'register', 'check_class_code', 'get_class_info'];
if (!in_array($action, $public_actions) && !isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please login first']);
    exit;
}

// Every state-changing (POST) request must carry the session CSRF token
// (X-CSRF-Token header from <meta name="csrf-token">, or a csrf_token field)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
    echo json_encode(['success' => false, 'message' => 'Your session expired. Please reload the page and try again.']);
    exit;
}

// Process request based on action
switch ($action) {
    // ================== AUTHENTICATION ==================
    case 'login':
        handleLogin();
        break;
        
    case 'register':
        handleRegister();
        break;
        
    case 'logout':
        handleLogout();
        break;
        
    // ================== CLASSES ==================
    case 'getMyClasses':
        handleGetMyClasses();
        break;
        
    case 'createClass':
        handleCreateClass();
        break;
        
    case 'getClassDetails':
        handleGetClassDetails();
        break;
        
    case 'joinClass':
        handleJoinClass();
        break;
        
    case 'checkClassCode':
        handleCheckClassCode();
        break;
        
    case 'getClassMembers':
        handleGetClassMembers();
        break;
        

    case 'removeStudentFromClass':
        handleRemoveStudentFromClass();
        break;
        
    // Lectures / Resources / Assignments / Student approval are handled by the
    // server-rendered pages in pages/lectures, pages/resources, pages/assignments
    // and pages/students/approve.php (CSRF + role + class-membership checks there).

    // ================== DASHBOARD ==================
    case 'getDashboardStats':
        handleGetDashboardStats();
        break;

    case 'uploadProfilePhoto':
        handleUploadProfilePhoto();
        break;

    case 'removeProfilePhoto':
        handleRemoveProfilePhoto();
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
}

// ================== AUTHENTICATION HANDLERS ==================

function handleLogin() {
    global $db;
    
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Please enter email and password']);
        return;
    }
    
    $user = $db->fetchOne("SELECT * FROM users WHERE email = ? AND is_active = 1", [$email]);
    
    if ($user && password_verify($password, $user['password'])) {
        // Check if student is approved
        if ($user['role'] === 'student' && $user['is_approved'] == 0) {
            echo json_encode(['success' => false, 'message' => 'Your account is pending approval']);
            return;
        }
        
        // Fresh session ID on login (fixation defense + replaces stale cookies)
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_photo'] = $user['photo'] ?? null;
        
        // Update last login
        $db->update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);
        
        // Audit trail
        logActivity('login', 'auth', null, $user['id']);
        
        echo json_encode([
            'success' => true,
            'message' => 'Login successful',
            'redirect' => APP_URL . '/pages/dashboard.php',
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'role' => $user['role']
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
    }
}

function handleRegister() {
    global $db;
    
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($name) || empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Please fill in all required fields']);
        return;
    }
    
    if ($password !== $confirm_password) {
        echo json_encode(['success' => false, 'message' => 'Passwords do not match']);
        return;
    }
    
    if (strlen($password) < 6) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
        return;
    }
    
    if ($db->exists('users', 'email', $email)) {
        echo json_encode(['success' => false, 'message' => 'Email already registered']);
        return;
    }
    
    try {
        $user_id = $db->insert('users', [
            'name' => $name,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'student',
            'is_approved' => 0, // Pending
            'is_active' => 1
        ]);

        // Audit trail + tell the super admin a student without a class awaits approval
        logActivity('register', 'auth', "New student registered: {$name} ({$email})", $user_id);
        notifyAdmins(
            'student_registered',
            'New student awaiting approval',
            "{$name} ({$email}) registered and is waiting for approval.",
            'pages/students/approve.php',
            $user_id
        );

        echo json_encode([
            'success' => true,
            'message' => 'Registration successful! Please wait for teacher approval.'
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Registration failed: ' . $e->getMessage()]);
    }
}

function handleLogout() {
    if (isset($_SESSION['user_id'])) {
        logActivity('logout', 'auth');
    }
    
    session_destroy();
    echo json_encode([
        'success' => true,
        'message' => 'Logged out successfully',
        'redirect' => APP_URL . '/pages/auth/login.php'
    ]);
}

// ================== CLASSES HANDLERS ==================

function handleGetMyClasses() {
    global $db;
    
    $user_id = $_SESSION['user_id'];
    $role = $_SESSION['user_role'];
    
    if ($role === 'teacher') {
        // Teacher gets their own classes
        $classes = $db->fetchAll(
            "SELECT c.*, 
                    (SELECT COUNT(*) FROM class_members WHERE class_id = c.id) as student_count
             FROM classes c 
             WHERE EXISTS (SELECT 1 FROM class_teachers ct WHERE ct.class_id = c.id AND ct.teacher_id = ?) AND c.is_active = 1 
             ORDER BY c.created_at DESC",
            [$user_id]
        );
    } else {
        // Student gets enrolled classes
        $classes = $db->fetchAll(
            "SELECT c.*, 
                    u.name as teacher_name,
                    (SELECT COUNT(*) FROM class_members WHERE class_id = c.id) as student_count,
                    cm.joined_at
             FROM classes c 
             INNER JOIN class_members cm ON c.id = cm.class_id
             INNER JOIN users u ON c.teacher_id = u.id
             WHERE cm.student_id = ? AND c.is_active = 1 
             ORDER BY cm.joined_at DESC",
            [$user_id]
        );
    }
    
    echo json_encode(['success' => true, 'data' => $classes]);
}

function handleCreateClass() {
    global $db;
    
    if (!isTeacher()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }
    
    $class_name = trim($_POST['class_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    if (empty($class_name)) {
        echo json_encode(['success' => false, 'message' => 'Please enter class name']);
        return;
    }
    
    // Generate unique class code
    do {
        $class_code = generateClassCode(8);
    } while ($db->exists('classes', 'class_code', $class_code));
    
    try {
        $class_id = $db->insert('classes', [
            'teacher_id' => $_SESSION['user_id'],
            'class_name' => $class_name,
            'class_code' => $class_code,
            'description' => $description
        ]);
        addClassOwner($class_id, $_SESSION['user_id']);
        
        echo json_encode([
            'success' => true,
            'message' => 'Class created successfully!',
            'class_code' => $class_code,
            'class_id' => $class_id
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

function handleGetClassDetails() {
    global $db;
    
    $class_id = intval($_GET['class_id'] ?? 0);
    
    if ($class_id == 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid class ID']);
        return;
    }
    
    // Only the owning teacher or an enrolled student may read a class (incl. its join code)
    $class = getAccessibleClass($class_id, $_SESSION['user_id'], $_SESSION['user_role'] ?? '');
    
    if (!$class) {
        echo json_encode(['success' => false, 'message' => 'Class not found']);
        return;
    }
    
    echo json_encode(['success' => true, 'data' => $class]);
}

function handleJoinClass() {
    global $db;
    
    if (!isStudent()) {
        echo json_encode(['success' => false, 'message' => 'Only students can join classes']);
        return;
    }
    
    $class_code = strtoupper(trim($_POST['class_code'] ?? ''));
    
    if (empty($class_code)) {
        echo json_encode(['success' => false, 'message' => 'Please enter class code']);
        return;
    }
    
    // Find class by code
    $class = $db->fetchOne("SELECT * FROM classes WHERE class_code = ? AND is_active = 1", [$class_code]);
    
    if (!$class) {
        echo json_encode(['success' => false, 'message' => 'Invalid class code']);
        return;
    }
    
    // Same rules as the Join Class page (teacher approval when the class requires it)
    $result = requestToJoinClass($class, $_SESSION['user_id'], $_SESSION['user_name'] ?? 'A student');
    $messages = [
        'member'    => [false, 'You have already joined this class'],
        'pending'   => [false, 'Your request to join this class is already waiting for the teacher'],
        'requested' => [true, 'Request sent — your teacher will approve you for ' . $class['class_name']],
        'joined'    => [true, 'Successfully joined ' . $class['class_name'] . '!'],
    ];
    echo json_encode([
        'success' => $messages[$result][0],
        'status'  => $result,
        'message' => $messages[$result][1],
        'class'   => ['id' => $class['id'], 'name' => $class['class_name']],
    ]);
}

function handleCheckClassCode() {
    global $db;
    
    $class_code = strtoupper(trim($_GET['code'] ?? ''));
    
    if (empty($class_code)) {
        echo json_encode(['success' => false, 'message' => 'Please enter class code']);
        return;
    }
    
    // Before approval a student only learns the class name (no teacher, image, description or id)
    $class = $db->fetchOne(
        "SELECT c.class_name FROM classes c WHERE c.class_code = ? AND c.is_active = 1",
        [$class_code]
    );
    
    if ($class) {
        echo json_encode(['success' => true, 'data' => $class]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Class not found']);
    }
}

function handleGetClassMembers() {
    global $db;
    
    $class_id = intval($_GET['class_id'] ?? 0);
    
    if ($class_id == 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid class ID']);
        return;
    }
    
    if (!getAccessibleClass($class_id, $_SESSION['user_id'], $_SESSION['user_role'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Class not found']);
        return;
    }
    
    $members = $db->fetchAll(
        "SELECT u.id, u.name, u.email, u.photo, cm.joined_at
         FROM users u
         INNER JOIN class_members cm ON u.id = cm.student_id
         WHERE cm.class_id = ?
         ORDER BY cm.joined_at DESC",
        [$class_id]
    );
    
    echo json_encode(['success' => true, 'data' => $members]);
}

function handleRemoveStudentFromClass() {
    global $db;

    if (!isTeacher()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }

    $class_id = intval($_POST['class_id'] ?? 0);
    $student_id = intval($_POST['student_id'] ?? 0);

    if ($class_id <= 0 || $student_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid class or student ID']);
        return;
    }

    $class = $db->fetchOne(
        "SELECT id FROM classes WHERE id = ? AND EXISTS (SELECT 1 FROM class_teachers ct WHERE ct.class_id = classes.id AND ct.teacher_id = ?) LIMIT 1",
        [$class_id, $_SESSION['user_id']]
    );
    if (!$class) {
        echo json_encode(['success' => false, 'message' => 'Class not found or unauthorized']);
        return;
    }

    $removed = $db->delete('class_members', 'class_id = ? AND student_id = ?', [$class_id, $student_id]);
    if ($removed > 0) {
        $class_row = $db->fetchOne("SELECT class_name FROM classes WHERE id = ?", [$class_id]);
        $class_name = $class_row['class_name'] ?? '';
        logActivity('remove_student', 'classes', "Removed student #{$student_id} from {$class_name}");
        notify(
            $student_id,
            'removed_from_class',
            'Removed from class',
            "You were removed from {$class_name}.",
            null,
            $_SESSION['user_id'],
            null
        );
        echo json_encode(['success' => true, 'message' => 'Student removed from class']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Student was not enrolled in this class']);
    }
}

// ================== DASHBOARD HANDLERS ==================

function handleGetDashboardStats() {
    global $db;
    
    $user_id = $_SESSION['user_id'];
    $role = $_SESSION['user_role'];
    
    $stats = [];
    
    if ($role === 'teacher') {
        $stats['total_classes'] = $db->count('classes', 'EXISTS (SELECT 1 FROM class_teachers ct WHERE ct.class_id = classes.id AND ct.teacher_id = ?) AND is_active = 1', [$user_id]);
        $stats['total_students'] = $db->fetchOne(
            "SELECT COUNT(DISTINCT student_id) as count FROM class_members cm
             INNER JOIN classes c ON cm.class_id = c.id
             WHERE EXISTS (SELECT 1 FROM class_teachers ct WHERE ct.class_id = c.id AND ct.teacher_id = ?)",
            [$user_id]
        )['count'] ?? 0;
        $stats['total_lectures'] = $db->fetchOne(
            "SELECT COUNT(*) as count FROM lectures l
             INNER JOIN classes c ON l.class_id = c.id
             WHERE EXISTS (SELECT 1 FROM class_teachers ct WHERE ct.class_id = c.id AND ct.teacher_id = ?)",
            [$user_id]
        )['count'] ?? 0;
        $stats['total_assignments'] = $db->fetchOne(
            "SELECT COUNT(*) as count FROM assignments a
             INNER JOIN classes c ON a.class_id = c.id
             WHERE EXISTS (SELECT 1 FROM class_teachers ct WHERE ct.class_id = c.id AND ct.teacher_id = ?)",
            [$user_id]
        )['count'] ?? 0;
    } else {
        // Student stats
        $stats['enrolled_classes'] = $db->count('class_members', 'student_id = ?', [$user_id]);
        $stats['pending_assignments'] = $db->fetchOne(
            "SELECT COUNT(*) as count FROM assignments a
             INNER JOIN class_members cm ON a.class_id = cm.class_id
             WHERE cm.student_id = ? AND a.due_date > NOW()",
            [$user_id]
        )['count'] ?? 0;
        $stats['submitted_assignments'] = $db->fetchOne(
            "SELECT COUNT(*) as count FROM submissions WHERE student_id = ?",
            [$user_id]
        )['count'] ?? 0;
    }
    
    echo json_encode(['success' => true, 'data' => $stats]);
}

function handleUploadProfilePhoto() {
    global $db;

    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Please select a valid image']);
        return;
    }

    $file = $_FILES['photo'];
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Image must be smaller than 2MB']);
        return;
    }

    $mime = mime_content_type($file['tmp_name']) ?: '';
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];
    if (!isset($allowed[$mime])) {
        echo json_encode(['success' => false, 'message' => 'Only JPG, PNG, WEBP are allowed']);
        return;
    }

    $upload_dir = dirname(__DIR__) . '/uploads/profile/';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
        echo json_encode(['success' => false, 'message' => 'Failed to create upload folder']);
        return;
    }

    $ext = $allowed[$mime];
    $filename = 'user_' . $_SESSION['user_id'] . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $filepath = $upload_dir . $filename;
    $relative = 'uploads/profile/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        echo json_encode(['success' => false, 'message' => 'Failed to upload image']);
        return;
    }

    $old = $db->fetchOne("SELECT photo FROM users WHERE id = ?", [$_SESSION['user_id']]);
    if (!empty($old['photo']) && strpos($old['photo'], 'uploads/profile/') === 0) {
        $oldPath = dirname(__DIR__) . '/' . $old['photo'];
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    $db->update('users', ['photo' => $relative], 'id = ?', [$_SESSION['user_id']]);
    $_SESSION['user_photo'] = $relative;

    echo json_encode([
        'success' => true,
        'message' => 'Profile photo updated',
        'photo' => APP_URL . '/' . $relative
    ]);
}

function handleRemoveProfilePhoto() {
    global $db;

    $user = $db->fetchOne("SELECT photo FROM users WHERE id = ?", [$_SESSION['user_id']]);
    if (!empty($user['photo']) && strpos($user['photo'], 'uploads/profile/') === 0) {
        $oldPath = dirname(__DIR__) . '/' . $user['photo'];
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    $db->update('users', ['photo' => null], 'id = ?', [$_SESSION['user_id']]);
    $_SESSION['user_photo'] = null;

    echo json_encode(['success' => true, 'message' => 'Profile photo removed']);
}

// ================== HELPER FUNCTIONS ==================

function isTeacher() {
    return ($_SESSION['user_role'] ?? '') === 'teacher';
}

function isStudent() {
    return ($_SESSION['user_role'] ?? '') === 'student';
}

// sanitize() comes from config/db.php

function generateClassCode($length = 8) {
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[random_int(0, strlen($characters) - 1)];
    }
    return $code;
}
