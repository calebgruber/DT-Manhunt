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
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <link href="/style.css" rel="stylesheet">
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

            <div id="incidentBanner" class="alert alert-danger d-none mb-3"></div>

            <ul class="nav nav-tabs mb-3" id="adminTabs" role="tablist">
              <li class="nav-item"><button class="nav-link active" data-admin-tab="game">Game</button></li>
              <li class="nav-item"><button class="nav-link" data-admin-tab="messages">Messages</button></li>
              <li class="nav-item"><button class="nav-link" data-admin-tab="map">Map</button></li>
              <li class="nav-item"><button class="nav-link" data-admin-tab="killboard">Kill Board</button></li>
              <li class="nav-item"><button class="nav-link" data-admin-tab="incidents">Incidents</button></li>
              <li class="nav-item"><button class="nav-link" data-admin-tab="ops">Ops</button></li>
            </ul>

            <div class="admin-tab-content">
              <section data-admin-panel="game">
                <div class="card mb-3">
                  <div class="card-header"><h3 class="card-title">Live Game Controls</h3></div>
                  <div class="card-body">
                    <form id="gameStateForm" class="row g-2">
                      <div class="col-12 col-lg-2">
                        <label class="form-label" for="gameStage">Game Stage</label>
                        <select id="gameStage" class="form-select" name="game_stage" required>
                          <option value="pregame">Pre-game</option>
                          <option value="live">Live</option>
                          <option value="paused">Paused</option>
                          <option value="ended">Ended</option>
                        </select>
                      </div>
                      <div class="col-12 col-lg-2">
                        <label class="form-label" for="clockMode">Clock Mode</label>
                        <select id="clockMode" class="form-select" name="clock_mode">
                          <option value="countdown">Count down</option>
                          <option value="countup">Count up</option>
                        </select>
                      </div>
                      <div class="col-6 col-lg-2">
                        <label class="form-label" for="hideDurationSeconds">Hide Sec</label>
                        <input id="hideDurationSeconds" class="form-control" type="number" name="hide_duration_seconds" min="0">
                      </div>
                      <div class="col-6 col-lg-2">
                        <label class="form-label" for="seekDurationSeconds">Seek Sec</label>
                        <input id="seekDurationSeconds" class="form-control" type="number" name="seek_duration_seconds" min="0">
                      </div>
                      <div class="col-12 col-lg-4">
                        <label class="form-label" for="announcementText">Announcement</label>
                        <input id="announcementText" class="form-control" name="announcement" type="text">
                      </div>
                      <div class="col-12">
                        <label class="form-label" for="gameInfoText">Game Info</label>
                        <input id="gameInfoText" class="form-control" name="game_info" type="text">
                      </div>
                      <div class="col-12 d-flex flex-wrap gap-2">
                        <button class="btn btn-primary" type="submit">Save Live State</button>
                        <button id="startGameBtn" class="btn btn-success" type="button">Start Game</button>
                        <button id="resetGameBtn" class="btn btn-warning" type="button">Reset Game</button>
                      </div>
                    </form>
                  </div>
                </div>
              </section>

              <section data-admin-panel="messages" class="d-none">
                <div class="card">
                  <div class="card-header"><h3 class="card-title">Persistent Messages</h3></div>
                  <div class="card-body">
                    <form id="messageForm" class="row g-2 mb-3">
                      <div class="col-12 col-lg-2">
                        <label class="form-label" for="messageTarget">Target</label>
                        <select id="messageTarget" class="form-select" name="target">
                          <option value="all_users">Everyone</option>
                          <option value="active">Still in + seekers</option>
                          <option value="eliminated">Eliminated + withdrawn</option>
                          <option value="user">Single user</option>
                          <option value="duo">Specific duo</option>
                          <option value="group">Custom group</option>
                        </select>
                      </div>
                      <div class="col-12 col-lg-2">
                        <label class="form-label" for="messageUserId">User ID</label>
                        <input id="messageUserId" class="form-control" name="user_id" type="number" min="1">
                      </div>
                      <div class="col-12 col-lg-3">
                        <label class="form-label" for="messageGroupIds">Group IDs</label>
                        <input id="messageGroupIds" class="form-control" name="group_user_ids" type="text" placeholder="2,5,9">
                      </div>
                      <div class="col-12 col-lg-5">
                        <label class="form-label" for="messageText">Message</label>
                        <input id="messageText" class="form-control" name="message" type="text" required>
                      </div>
                      <div class="col-12">
                        <button class="btn btn-primary" type="submit">Send Live Alert</button>
                      </div>
                    </form>
                    <div id="messagesFeed" class="vstack gap-2"></div>
                  </div>
                </div>
              </section>

              <section data-admin-panel="map" class="d-none">
                <div class="card">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0">Live Dark Map</h3>
                    <button id="adminMapFullscreenBtn" class="btn btn-sm btn-outline-secondary" type="button"><i class="ti ti-arrows-maximize"></i></button>
                  </div>
                  <div class="card-body">
                    <div id="liveMap" style="height: 420px;" class="border mb-3"></div>
                    <div class="table-responsive">
                      <table class="table table-vcenter card-table">
                        <thead>
                          <tr><th>Player</th><th>Status</th><th>Coordinates</th><th>Updated</th></tr>
                        </thead>
                        <tbody id="locationsBody"></tbody>
                      </table>
                    </div>
                  </div>
                </div>
              </section>

              <section data-admin-panel="killboard" class="d-none">
                <div class="card">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0">Kill Board</h3>
                    <button id="adminKillboardFullscreenBtn" class="btn btn-sm btn-outline-secondary" type="button"><i class="ti ti-arrows-maximize"></i></button>
                  </div>
                  <div class="card-body">
                    <div id="killboardSummary" class="text-secondary mb-2"></div>
                    <div id="killboardCards" class="killboard-grid-admin mb-3"></div>
                    <div class="table-responsive">
                      <table class="table table-vcenter card-table">
                        <thead>
                          <tr><th>Player</th><th>Mode</th><th>Status</th><th>Actions</th></tr>
                        </thead>
                        <tbody id="killboardBody"></tbody>
                      </table>
                    </div>
                  </div>
                </div>
              </section>

              <section data-admin-panel="incidents" class="d-none">
                <div class="card">
                  <div class="card-header"><h3 class="card-title">Incident Reports</h3></div>
                  <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                      <thead>
                        <tr><th>Reporter</th><th>Type</th><th>Severity</th><th>Details</th><th>Status</th><th></th></tr>
                      </thead>
                      <tbody id="incidentsBody"></tbody>
                    </table>
                  </div>
                </div>
              </section>

              <section data-admin-panel="ops" class="d-none">
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
              </section>
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
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="/admin/admin.js"></script>
</body>
</html>
