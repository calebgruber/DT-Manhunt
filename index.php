<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

$user = currentUser();
$config = appConfig();
$appName = (string) ($config['app']['name'] ?? 'DT Manhunt');
$orgName = (string) ($config['app']['organization'] ?? 'SUNY Purchase');
$registrationOptions = registrationOptions();
$graduationYearOptions = $registrationOptions['graduation_year_options'];
$concentrationOptions = $registrationOptions['concentration_options'];
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?></title>
  <!-- Tabler UI CDN -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@latest/dist/css/tabler.min.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css" />
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <link href="/style.css" rel="stylesheet">
</head>
<body>
  <div id="loadingOverlay" class="loading-overlay">
    <div class="card loading-card">
      <div class="card-body text-center">
        <div class="spinner-border text-primary mb-3" role="status" aria-hidden="true"></div>
        <div id="loadingText" class="fw-semibold">Loading...</div>
      </div>
    </div>
  </div>
  <div class="page">
    <header class="navbar navbar-expand-md d-print-none">
      <div class="container-xl">
        <div class="navbar-brand pe-0">
          <span class="avatar avatar-sm me-2 bg-primary text-primary-fg"><i class="ti ti-target-arrow"></i></span>
          <div>
            <div class="text-uppercase text-secondary small fw-bold"><?php echo htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="fw-semibold">Manhunt Registration</div>
          </div>
        </div>
        <div class="navbar-nav flex-row align-items-center order-md-last">
          <span id="loggedInUserLabel" class="badge bg-secondary-lt text-secondary d-none me-2"></span>
          <a id="adminLink" href="/admin/" class="btn btn-outline-secondary d-none me-2">
            <i class="ti ti-settings me-1"></i>Admin
          </a>
          <button id="logoutBtn" class="btn btn-outline-secondary d-none" type="button">
            <i class="ti ti-logout me-1"></i>Logout
          </button>
        </div>
      </div>
    </header>

    <div class="page-wrapper">
      <div class="page-body">
        <div class="container-xl app-container">
          <div id="toastContainer" class="toast-container position-fixed bottom-0 end-0 p-3"></div>

          <section id="authSection">
            <div class="row g-3">
              <div class="col-lg-5 d-none d-lg-block">
                <div class="card">
                  <div class="card-body">
                    <div class="badge bg-azure-lt text-azure mb-3">Tabler UI</div>
                    <h2 class="h2 mb-2">Join the game</h2>
                    <p class="text-secondary mb-0">Clean desktop layout and touch-friendly mobile flow with one shared registration system.</p>
                  </div>
                </div>
              </div>

              <div class="col-12 col-lg-7">
                <div class="card">
                  <div class="card-body">
                    <ul class="nav nav-tabs mb-4" role="tablist" aria-label="Authentication tabs">
                      <li class="nav-item" role="presentation">
                        <button class="nav-link active" data-auth-tab="login" type="button" aria-controls="loginPanel" aria-selected="true">Login</button>
                      </li>
                      <li class="nav-item" role="presentation">
                        <button class="nav-link" data-auth-tab="register" type="button" aria-controls="registerPanel" aria-selected="false">Register</button>
                      </li>
                    </ul>

                    <div id="loginPanel" role="tabpanel">
                      <h3 class="h4 mb-2">Welcome back</h3>
                      <p class="text-secondary mb-3">Use your phone and PIN to continue your saved progress.</p>
                      <form id="loginForm" class="vstack gap-3">
                        <div>
                          <label class="form-label" for="loginPhone">Phone number</label>
                          <input id="loginPhone" class="form-control form-control-lg" name="phone" placeholder="Phone number" type="tel" inputmode="numeric" pattern="[0-9]{10,15}" minlength="10" maxlength="15" autocomplete="tel-national" required>
                        </div>
                        <div>
                          <label class="form-label" for="loginPin">PIN</label>
                          <input id="loginPin" class="form-control form-control-lg" name="pin" placeholder="4-8 digit PIN" inputmode="numeric" pattern="[0-9]{4,8}" autocomplete="current-password" type="password" required>
                        </div>
                        <button class="btn btn-primary btn-lg w-100" type="submit">Sign In</button>
                      </form>
                    </div>

                    <div id="registerPanel" class="d-none" role="tabpanel">
                      <h3 class="h4 mb-2">Create account</h3>
                      <p class="text-secondary mb-3">Use your real first and last name for teammate matching.</p>
                      <form id="registerForm" class="vstack gap-3">
                        <div class="row g-3">
                          <div class="col-12 col-sm-6">
                            <label class="form-label" for="registerFirstName">First name</label>
                            <input id="registerFirstName" class="form-control form-control-lg" name="first_name" placeholder="First name" autocomplete="given-name" required>
                          </div>
                          <div class="col-12 col-sm-6">
                            <label class="form-label" for="registerLastName">Last name</label>
                            <input id="registerLastName" class="form-control form-control-lg" name="last_name" placeholder="Last name" autocomplete="family-name" required>
                          </div>
                          <div class="col-12">
                            <label class="form-label" for="registerPhone">Phone number</label>
                            <input id="registerPhone" class="form-control form-control-lg" name="phone" placeholder="Phone number" type="tel" inputmode="numeric" pattern="[0-9]{10,15}" minlength="10" maxlength="15" autocomplete="tel-national" required>
                          </div>
                          <div class="col-12 col-sm-6">
                            <label class="form-label" for="registerPin">PIN (4-8 digits)</label>
                            <input id="registerPin" class="form-control form-control-lg" name="pin" placeholder="PIN" inputmode="numeric" pattern="[0-9]{4,8}" minlength="4" maxlength="8" autocomplete="new-password" type="password" required>
                          </div>
                          <div class="col-12 col-sm-6">
                            <label class="form-label" for="registerGradYear">Graduation year</label>
                            <select id="registerGradYear" class="form-select form-select-lg" name="graduation_year" required>
                              <option value="">Select year</option>
                              <?php foreach ($graduationYearOptions as $year): ?>
                                <option value="<?php echo htmlspecialchars($year, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($year, ENT_QUOTES, 'UTF-8'); ?></option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <div class="col-12">
                            <label class="form-label" for="registerConcentration">Concentration</label>
                            <select id="registerConcentration" class="form-select form-select-lg" name="concentration" required>
                              <option value="">Select concentration</option>
                              <?php foreach ($concentrationOptions as $option): ?>
                                <option value="<?php echo htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?></option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                        </div>
                        <button class="btn btn-success btn-lg w-100" type="submit">Create Account</button>
                      </form>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <section id="appSection" class="d-none">
            <div id="userAlertBanner" class="alert alert-warning d-none mb-3">
              <div class="d-flex justify-content-between align-items-start gap-2">
                <div id="userAlertText" class="fw-semibold"></div>
                <button id="acknowledgeAlertBtn" class="btn btn-sm btn-warning" type="button">Acknowledge</button>
              </div>
            </div>
            <div class="row g-3">
              <div class="col-12 col-xl-4">
                <div id="stepperCard" class="card sticky-panel">
                  <div class="card-body">
                    <div id="stepper" class="stepper"></div>
                  </div>
                </div>
              </div>

              <div class="col-12 col-xl-8">
                <article id="profileStep" class="card d-none step-pane">
                  <div class="card-body">
                    <h2 class="h3 mb-2">Profile complete</h2>
                    <p id="profileSummary" class="text-secondary mb-4"></p>
                    <button id="continueToMode" class="btn btn-primary btn-lg w-100">Continue to mode</button>
                  </div>
                </article>

                <article id="modeStep" class="card d-none step-pane">
                  <div class="card-body">
                    <h2 class="h3 mb-2">Choose your mode</h2>
                    <p class="text-secondary mb-3">Pick solo or duo to continue.</p>
                    <div class="row g-3">
                      <div class="col-12 col-md-6">
                        <button class="btn btn-outline-secondary mode-btn w-100" data-mode="solo" type="button">
                          <i class="ti ti-user fs-1"></i>
                          <span>Solo</span>
                        </button>
                      </div>
                      <div class="col-12 col-md-6">
                        <button class="btn btn-outline-primary mode-btn w-100" data-mode="duo" type="button">
                          <i class="ti ti-users fs-1"></i>
                          <span>Duo</span>
                        </button>
                      </div>
                    </div>
                  </div>
                </article>

                <article id="matchmakingStep" class="card d-none step-pane">
                  <div class="card-body">
                    <h2 class="h3 mb-2">Find teammate</h2>
                    <p class="text-secondary mb-3">Search by first and last name, then send an invite.</p>
                    <div class="row g-3">
                      <div class="col-12 col-lg-7">
                        <div class="input-group mb-2">
                          <label class="visually-hidden" for="searchInput">Search full name</label>
                          <input id="searchInput" class="form-control form-control-lg" placeholder="Search full name" autocomplete="off">
                          <button id="searchBtn" class="btn btn-primary" type="button">Search</button>
                        </div>
                        <div id="searchResults" class="list-group"></div>
                        <div class="mt-3">
                          <h3 class="h5 mb-1">Your invite</h3>
                          <p id="outgoingInvite" class="text-secondary mb-0">No outgoing invite.</p>
                        </div>
                      </div>
                      <div class="col-12 col-lg-5">
                        <div class="mb-3">
                          <button id="switchSoloBtn" class="btn btn-outline-warning w-100" type="button">
                            <i class="ti ti-user-x me-1"></i>Switch to Solo
                          </button>
                        </div>
                        <h3 class="h5 mb-2">Incoming invites</h3>
                        <div id="incomingInvites" class="vstack gap-2 mb-3"></div>
                        <div id="teammateCard" class="alert alert-success d-none mb-0"></div>
                      </div>
                    </div>
                  </div>
                </article>

                <article id="paymentStep" class="card d-none step-pane">
                  <div class="card-body">
                    <h2 class="h3 mb-2">Payment</h2>
                    <p class="text-secondary mb-3">Submit payment, then wait for admin approval.</p>
                    <div id="paymentInfo" class="mb-4"></div>
                    <div id="paymentQrWrap" class="mb-4 d-none">
                      <h3 class="h5 mb-2">Pay with Venmo</h3>
                      <img id="paymentQrImage" class="img-thumbnail mb-2 qr-image" alt="Venmo QR code">
                      <div><a id="paymentVenmoLink" href="#" target="_blank" rel="noopener noreferrer">Open Venmo link</a></div>
                    </div>
                    <button id="completePaymentBtn" class="btn btn-success btn-lg w-100" type="button">Mark Payment Complete</button>
                  </div>
                </article>

                <article id="completeStep" class="d-none step-pane">
                  <div id="userGameShell" class="game-shell">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                      <div>
                        <h2 class="h3 mb-1">Game Dashboard</h2>
                        <div id="dashboardRoleLine" class="text-secondary small">You are a: hider</div>
                        <div id="dashboardStatusLine" class="text-secondary small">Loading game status…</div>
                      </div>
                      <div class="d-flex gap-2">
                        <button id="openIncidentModalBtn" class="btn btn-danger btn-sm" type="button"><i class="ti ti-alert-triangle me-1"></i>Report Incident</button>
                        <button id="withdrawBtn" class="btn btn-outline-secondary btn-sm" type="button"><i class="ti ti-user-x me-1"></i>Withdraw</button>
                      </div>
                    </div>

                    <div class="row g-2 mb-3">
                      <div class="col-6 col-md-3">
                        <div class="stat-tile">
                          <div class="stat-label">In</div>
                          <div id="statInCount" class="stat-value">0</div>
                        </div>
                      </div>
                      <div class="col-6 col-md-3">
                        <div class="stat-tile">
                          <div class="stat-label">Seekers</div>
                          <div id="statSeekerCount" class="stat-value">0</div>
                        </div>
                      </div>
                      <div class="col-6 col-md-3">
                        <div class="stat-tile">
                          <div class="stat-label">Eliminated</div>
                          <div id="statEliminatedCount" class="stat-value">0</div>
                        </div>
                      </div>
                      <div class="col-6 col-md-3">
                        <div class="stat-tile">
                          <div class="stat-label">Game Clock</div>
                          <div id="gameClockValue" class="stat-value">00:00</div>
                        </div>
                      </div>
                    </div>

                    <div id="persistentMessageAlerts" class="vstack gap-2 mb-3"></div>

                    <div class="card mb-3">
                      <div class="card-header"><h3 class="card-title mb-0">Current Duo</h3></div>
                      <div id="duoInfoPanel" class="card-body text-secondary">No duo info available.</div>
                    </div>

                    <div class="row g-3">
                      <div class="col-12 col-xl-5">
                        <div class="card">
                          <div class="card-header d-flex justify-content-between align-items-center">
                            <h3 class="card-title mb-0">Your Duo Map</h3>
                            <button id="userMapFullscreenBtn" class="btn btn-sm btn-outline-secondary" type="button"><i class="ti ti-arrows-maximize"></i></button>
                          </div>
                          <div class="card-body">
                            <div id="userMap" class="user-map"></div>
                          </div>
                        </div>
                      </div>
                      <div class="col-12 col-xl-7">
                        <div class="card">
                          <div class="card-header d-flex justify-content-between align-items-center">
                            <h3 class="card-title mb-0">Live Kill Board</h3>
                            <button id="userKillboardFullscreenBtn" class="btn btn-sm btn-outline-secondary" type="button"><i class="ti ti-arrows-maximize"></i></button>
                          </div>
                          <div class="card-body">
                            <div id="killboardSummary" class="text-secondary mb-2"></div>
                            <div id="killboardCards" class="killboard-grid"></div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </article>
              </div>
            </div>
          </section>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="incidentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Report Incident</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form id="incidentForm" class="vstack gap-2">
            <input class="form-control" type="text" name="incident_type" placeholder="Incident type (UPD, medical, etc.)" required>
            <select class="form-select" name="severity" required>
              <option value="low">Low</option>
              <option value="medium">Medium</option>
              <option value="high">High</option>
              <option value="emergency">Emergency</option>
            </select>
            <textarea class="form-control" name="details" rows="3" placeholder="What happened?" required></textarea>
            <button class="btn btn-danger" type="submit">Send Incident Report</button>
          </form>
        </div>
      </div>
    </div>
  </div>
  <script>
    window.__BOOT_USER__ = <?php echo json_encode(
      $user ? userPublic($user) : null,
      JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ); ?>;
  </script>
  <script src="https://cdn.jsdelivr.net/npm/@tabler/core@latest/dist/js/tabler.min.js"></script>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="/app.js"></script>
</body>
</html>
