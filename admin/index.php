<?php

declare(strict_types=1);

require __DIR__ . '/../lib.php';

$config = appConfig();
$appName = (string) ($config['app']['name'] ?? 'DT Manhunt');
$admin = currentAdminUser();
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?> Admin</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@latest/dist/css/tabler.min.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css" />
</head>
<body>
  <div class="page">
    <header class="navbar navbar-expand-md d-print-none">
      <div class="container-xl">
        <div class="navbar-brand">
          <span class="avatar avatar-sm me-2 bg-primary text-primary-fg"><i class="ti ti-settings"></i></span>
          <strong>Admin Console</strong>
        </div>
        <a href="/" class="btn btn-outline-secondary">Back to App</a>
      </div>
    </header>
    <div class="page-wrapper">
      <div class="page-body">
        <div class="container-xl">
          <div id="adminLogin" class="<?php echo $admin ? 'd-none' : ''; ?>">
            <div class="row justify-content-center">
              <div class="col-12 col-md-8 col-lg-5">
                <div class="card">
                  <div class="card-body">
                    <h2 class="h3 mb-3">Admin Login</h2>
                    <form id="adminLoginForm" class="vstack gap-3">
                      <div>
                        <label class="form-label" for="adminPhone">Phone number</label>
                        <input id="adminPhone" class="form-control" name="phone" type="tel" inputmode="numeric" pattern="[0-9]{10,15}" minlength="10" maxlength="15" required>
                      </div>
                      <div>
                        <label class="form-label" for="adminPin">PIN</label>
                        <input id="adminPin" class="form-control" name="pin" type="password" inputmode="numeric" pattern="[0-9]{4,8}" minlength="4" maxlength="8" required>
                      </div>
                      <button class="btn btn-primary" type="submit">Sign In</button>
                    </form>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div id="adminDashboard" class="<?php echo $admin ? '' : 'd-none'; ?>">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h2 class="h3 mb-0">Control Panel</h2>
              <button id="adminLogoutBtn" class="btn btn-outline-secondary" type="button"><i class="ti ti-logout me-1"></i>Logout</button>
            </div>

            <div class="row g-3">
              <div class="col-12 col-xl-5">
                <div class="card">
                  <div class="card-header"><h3 class="card-title">Venmo Settings</h3></div>
                  <div class="card-body">
                    <form id="venmoForm" class="vstack gap-2">
                      <label class="form-label" for="venmoLink">Venmo URL</label>
                      <input id="venmoLink" class="form-control" type="url" name="venmo_link" placeholder="https://venmo.com/u/youraccount">
                      <button class="btn btn-primary" type="submit">Save Venmo Link</button>
                    </form>
                  </div>
                </div>
              </div>

              <div class="col-12 col-xl-7">
                <div class="card">
                  <div class="card-header"><h3 class="card-title">Payment Approvals</h3></div>
                  <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                      <thead>
                        <tr><th>User</th><th>Status</th><th>Step</th><th></th></tr>
                      </thead>
                      <tbody id="paymentsBody"></tbody>
                    </table>
                  </div>
                </div>
              </div>

              <div class="col-12">
                <div class="card">
                  <div class="card-header"><h3 class="card-title">Matched Duos</h3></div>
                  <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                      <thead>
                        <tr><th>Pair</th><th>Payment</th><th>Step</th><th>Actions</th></tr>
                      </thead>
                      <tbody id="matchesBody"></tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div id="adminNotice" class="alert d-none mt-3"></div>
        </div>
      </div>
    </div>
  </div>

  <script>
    window.__BOOT_ADMIN__ = <?php echo json_encode(
      $admin ? adminPublic($admin) : null,
      JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ); ?>;
  </script>
  <script src="https://cdn.jsdelivr.net/npm/@tabler/core@latest/dist/js/tabler.min.js"></script>
  <script src="/admin/admin.js"></script>
</body>
</html>
