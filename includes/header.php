<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="SmartClass Management System">
    <title><?php echo isset($page_title) ? $page_title . ' - ' . APP_NAME : APP_NAME; ?></title>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?php echo APP_URL; ?>/assets/media/favicon.ico">

    <!-- CSS Framework - Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">

    <!-- Custom CSS -->
    <link href="<?php echo APP_URL; ?>/assets/css/styles.css" rel="stylesheet">

    <!-- Page specific CSS -->
    <?php if (isset($page_css)): ?>
    <?php foreach ($page_css as $css): ?>
    <link href="<?php echo APP_URL; ?>/assets/css/<?php echo $css; ?>" rel="stylesheet">
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Custom CSS for colored header -->
    <style>
    :root {
        --primary-color: #667eea;
        --secondary-color: #764ba2;
        --gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    }

    body {
        font-family: 'Poppins', sans-serif;
        background-color: #f8f9fa;
    }

    .main-header {
        background: var(--gradient-primary) !important;
        color: white;
    }

    .sidebar {
        background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%);
    }

    .sidebar .nav-link {
        color: rgba(255, 255, 255, 0.8);
        padding: 12px 20px;
        border-radius: 8px;
        margin: 2px 10px;
        transition: all 0.3s ease;
    }

    .sidebar .nav-link:hover,
    .sidebar .nav-link.active {
        background: rgba(255, 255, 255, 0.1);
        color: white;
    }

    .sidebar .nav-link i {
        width: 24px;
        text-align: center;
    }

    .btn-primary {
        background: var(--gradient-primary);
        border: none;
    }

    .btn-primary:hover {
        background: linear-gradient(135deg, #5568d3 0%, #6a3fa0 100%);
    }

    .card {
        border: none;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }

    .stat-card {
        background: var(--gradient-primary);
        color: white;
        border-radius: 12px;
    }

    .table thead th {
        background: var(--gradient-primary);
        color: white;
        border: none;
    }
    </style>

    <!-- HTML5 shim and Respond.js for IE8 support -->
    <!--[if lt IE 9]>
    <script src="https://cdn.jsdelivr.net/npm/html5shiv@3.7.3/dist/html5shiv.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/respond.js@1.4.2/dest/respond.min.js"></script>
    <![endif]-->
</head>

<body
    <?php echo isset($body_attrs) ? implode(' ', array_map(function($k, $v){ return "$k=\"$v\""; }, array_keys($body_attrs), $body_attrs)) : ''; ?>>

    <?php if (!isset($hide_all)): ?>
    <!-- Mobile Overlay -->
    <div class="offcanvas-overlay"></div>

    <!-- Loading Spinner -->
    <div id="loading-spinner" class="loading-spinner">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>

    <!-- Toast Container -->
    <div id="toast-container" class="toast-container position-fixed top-0 end-0 p-3"></div>

    <div class="d-flex">
        <!-- Sidebar -->
        <nav id="sidebar" class="sidebar">
            <div class="sidebar-header">
                <a href="<?php echo APP_URL; ?>/pages/dashboard.php" class="logo d-flex align-items-center">
                    <i class="fas fa-graduation-cap fs-4"></i>
                    <span class="ms-2 fw-bold">SmartClass</span>
                </a>
                <button type="button" class="btn-close-sidebar d-lg-none">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="sidebar-body">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $page === 'dashboard' ? 'active' : ''; ?>"
                            href="<?php echo APP_URL; ?>/pages/dashboard.php">
                            <i class="fas fa-home me-2"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>

                    <?php if (isTeacher() || isAdmin()): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo in_array($page, ['classes', 'myClasses']) ? 'active' : ''; ?>"
                            href="<?php echo APP_URL; ?>/pages/classes/myClasses.php">
                            <i class="fas fa-chalkboard-teacher me-2"></i>
                            <span>My Classes</span>
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (isAdmin()): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo in_array($page, ['addClass', 'viewClasses']) ? 'active' : ''; ?>"
                            href="<?php echo APP_URL; ?>/pages/classes/viewClasses.php">
                            <i class="fas fa-building me-2"></i>
                            <span>Manage Classes</span>
                        </a>
                    </li>
                    <?php endif; ?>

                    <li class="nav-item">
                        <a class="nav-link <?php echo in_array($page, ['viewStudents']) ? 'active' : ''; ?>"
                            href="<?php echo APP_URL; ?>/pages/students/viewStudents.php">
                            <i class="fas fa-user-graduate me-2"></i>
                            <span>Students</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link <?php echo in_array($page, ['attendance', 'markAttendance', 'viewAttendance']) ? 'active' : ''; ?>"
                            href="<?php echo APP_URL; ?>/pages/attendance/markAttendance.php">
                            <i class="fas fa-clipboard-check me-2"></i>
                            <span>Attendance</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link <?php echo in_array($page, ['quizzes', 'createQuiz', 'viewQuizzes']) ? 'active' : ''; ?>"
                            href="<?php echo APP_URL; ?>/pages/quizzes/viewQuizzes.php">
                            <i class="fas fa-question-circle me-2"></i>
                            <span>Quizzes</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link <?php echo in_array($page, ['assignments', 'createAssignment', 'viewAssignments']) ? 'active' : ''; ?>"
                            href="<?php echo APP_URL; ?>/pages/assignments/viewAssignments.php">
                            <i class="fas fa-tasks me-2"></i>
                            <span>Assignments</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link <?php echo in_array($page, ['resources', 'uploadResource', 'viewResources']) ? 'active' : ''; ?>"
                            href="<?php echo APP_URL; ?>/pages/resources/viewResources.php">
                            <i class="fas fa-folder-open me-2"></i>
                            <span>Resources</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link <?php echo in_array($page, ['timetable', 'schedule']) ? 'active' : ''; ?>"
                            href="<?php echo APP_URL; ?>/pages/timetable.php">
                            <i class="fas fa-calendar-alt me-2"></i>
                            <span>Timetable</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link <?php echo in_array($page, ['reports', 'attendanceReports']) ? 'active' : ''; ?>"
                            href="<?php echo APP_URL; ?>/pages/reports/attendanceReports.php">
                            <i class="fas fa-chart-bar me-2"></i>
                            <span>Reports</span>
                        </a>
                    </li>

                    <?php if (isAdmin()): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $page === 'users' ? 'active' : ''; ?>"
                            href="<?php echo APP_URL; ?>/pages/users.php">
                            <i class="fas fa-users-cog me-2"></i>
                            <span>Manage Users</span>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </nav>

        <!-- Main Content -->
        <div class="main-content flex-grow-1">
            <!-- Top Header -->
            <header class="main-header d-flex align-items-center justify-content-between px-4 py-3">
                <div class="d-flex align-items-center">
                    <button class="btn btn-link text-white me-3 d-lg-none" id="sidebarToggle">
                        <i class="fas fa-bars fs-4"></i>
                    </button>
                    <h5 class="mb-0 d-none d-md-block"><?php echo $page_title ?? 'Dashboard'; ?></h5>
                </div>

                <div class="d-flex align-items-center gap-3">
                    <!-- Notifications -->
                    <div class="dropdown">
                        <button class="btn btn-link text-white position-relative" type="button"
                            id="notificationDropdown" data-bs-toggle="dropdown">
                            <i class="fas fa-bell fs-5"></i>
                            <?php $notif_count = getUnreadNotificationsCount(); ?>
                            <?php if ($notif_count > 0): ?>
                            <span
                                class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                <?php echo $notif_count; ?>
                            </span>
                            <?php endif; ?>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end notification-dropdown">
                            <div class="p-3">
                                <h6>Notifications</h6>
                            </div>
                            <div class="notification-list" style="max-height: 300px; overflow-y: auto;">
                                <div class="text-center text-muted p-3">
                                    <i class="fas fa-bell-slash mb-2"></i>
                                    <p class="mb-0">No new notifications</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Messages -->
                    <div class="dropdown">
                        <button class="btn btn-link text-white position-relative" type="button" id="messageDropdown"
                            data-bs-toggle="dropdown">
                            <i class="fas fa-envelope fs-5"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            <div class="p-3">
                                <h6>Messages</h6>
                            </div>
                            <div class="text-center text-muted p-3">
                                <i class="fas fa-inbox mb-2"></i>
                                <p class="mb-0">No messages</p>
                            </div>
                        </div>
                    </div>

                    <!-- User Profile -->
                    <div class="dropdown">
                        <button class="btn btn-link text-white d-flex align-items-center" type="button"
                            id="profileDropdown" data-bs-toggle="dropdown">
                            <div class="avatar me-2">
                                <i class="fas fa-user-circle fs-4"></i>
                            </div>
                            <span
                                class="d-none d-md-block"><?php echo htmlspecialchars($current_user_name ?? 'User'); ?></span>
                            <i class="fas fa-chevron-down ms-2 small"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            <a class="dropdown-item" href="<?php echo APP_URL; ?>/pages/profile/profile.php">
                                <i class="fas fa-user me-2"></i> My Profile
                            </a>
                            <a class="dropdown-item" href="<?php echo APP_URL; ?>/pages/profile/settings.php">
                                <i class="fas fa-cog me-2"></i> Settings
                            </a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item" href="<?php echo APP_URL; ?>/pages/auth/logout.php">
                                <i class="fas fa-sign-out-alt me-2"></i> Logout
                            </a>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page Content -->
            <main class="page-content p-4">
                <?php endif; ?>