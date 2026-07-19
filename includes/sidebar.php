<aside class="sidebar">

    <div class="sidebar-brand">
        <div class="brand-logo">GSN</div>

        <div>
            <h5>GRAND SPEED</h5>
            <small>NETWORK</small>
        </div>
    </div>

    <nav class="sidebar-menu">

        <a href="dashboard.php" class="sidebar-link">
            <i class="bi bi-grid"></i>
            <span>Dashboard</span>
        </a>

        <a href="add-complaint.php" class="sidebar-link">
            <i class="bi bi-plus-circle"></i>
            <span>Add Complaint</span>
        </a>

        <a href="view-complaints.php" class="sidebar-link">
            <i class="bi bi-list-check"></i>
            <span>Complaints</span>
        </a>

        <a href="agents.php" class="sidebar-link">
            <i class="bi bi-people"></i>
            <span>Agents</span>
        </a>

        <a href="vendors.php" class="sidebar-link">
            <i class="bi bi-building"></i>
            <span>Vendors</span>
        </a>

        <a href="jobs.php" class="sidebar-link">
            <i class="bi bi-briefcase"></i>
            <span>Jobs</span>
        </a>

        <a href="reports.php" class="sidebar-link">
            <i class="bi bi-bar-chart"></i>
            <span>Reports</span>
        </a>

        <a href="logout.php" class="sidebar-link logout-link">
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>

</aside>

<main class="main-content">

    <header class="topbar">

        <button class="menu-toggle" type="button" id="menuToggle">
            <i class="bi bi-list"></i>
        </button>

        <div>
            <h5 class="mb-0">
                <?php echo isset($pageHeading) ? htmlspecialchars($pageHeading) : 'GSN Portal'; ?>
            </h5>

            <small class="text-muted">
                Complaint Management System
            </small>
        </div>

        <div class="topbar-user">
            <i class="bi bi-person-circle"></i>

            <span>
                <?php echo htmlspecialchars($_SESSION['admin'] ?? 'Admin'); ?>
            </span>
        </div>

    </header>

    <div class="content-area">