<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

$user = currentUser();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>DT Manhunt</title>
  <link href="https://cdn.jsdelivr.net/gh/ayroui/bootstrap-ui-components@96a9f7dc00bd6d2bcaedf11c163fec2ededfbb2c/assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/gh/ayroui/bootstrap-ui-components@96a9f7dc00bd6d2bcaedf11c163fec2ededfbb2c/assets/scss/starter.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="/style.css" rel="stylesheet">
</head>
<body class="dark-ui">
  <main class="container py-3 py-lg-4 app-container">
    <header class="d-flex justify-content-between align-items-center page-header mb-3 mb-lg-4">
      <div>
        <span class="eyebrow">SUNY Purchase</span>
        <h1 class="h4 m-0">Manhunt Registration</h1>
      </div>
      <button id="logoutBtn" class="btn btn-outline-light btn-sm d-none">Logout</button>
    </header>

    <div id="toastContainer" class="toast-container position-fixed bottom-0 end-0 p-3"></div>

    <section id="authSection" class="card shell-card border-0 shadow-sm">
      <div class="card-body p-3 p-lg-4">
        <div class="auth-grid">
          <aside class="brand-panel d-none d-lg-flex">
            <div>
              <span class="badge text-bg-primary mb-3">Ayro UI + Dark Mode</span>
              <h2 class="h4">Join the campus game</h2>
              <p class="text-muted mb-0">Desktop shows side-by-side panels. Mobile keeps a stacked, touch-first flow.</p>
            </div>
          </aside>

          <section>
            <ul class="nav nav-pills nav-fill mb-3" role="tablist">
              <li class="nav-item"><button class="nav-link active" data-auth-tab="login" type="button">Login</button></li>
              <li class="nav-item"><button class="nav-link" data-auth-tab="register" type="button">Register</button></li>
            </ul>

            <div id="loginPanel">
              <h2 class="h6 mb-2">Welcome back</h2>
              <p class="small text-muted mb-3">Use your phone and PIN to continue from your saved step.</p>
              <form id="loginForm" class="vstack gap-2">
                <input class="form-control form-control-lg" name="phone" placeholder="Phone number" required>
                <input class="form-control form-control-lg" name="pin" placeholder="PIN" inputmode="numeric" type="password" required>
                <button class="btn btn-primary btn-lg" type="submit">Sign In</button>
              </form>
            </div>

            <div id="registerPanel" class="d-none">
              <h2 class="h6 mb-2">Create account</h2>
              <p class="small text-muted mb-3">Use your real first and last name for matching.</p>
              <form id="registerForm" class="vstack gap-2">
                <div class="row g-2">
                  <div class="col-6"><input class="form-control form-control-lg" name="first_name" placeholder="First name" required></div>
                  <div class="col-6"><input class="form-control form-control-lg" name="last_name" placeholder="Last name" required></div>
                </div>
                <input class="form-control form-control-lg" name="phone" placeholder="Phone number" required>
                <input class="form-control form-control-lg" name="pin" placeholder="PIN (4-8 digits)" inputmode="numeric" type="password" required>
                <div class="row g-2">
                  <div class="col-6"><input class="form-control form-control-lg" name="graduation_year" placeholder="Graduation year" inputmode="numeric" required></div>
                  <div class="col-6"><input class="form-control form-control-lg" name="concentration" placeholder="Concentration" required></div>
                </div>
                <button class="btn btn-success btn-lg" type="submit">Create Account</button>
              </form>
            </div>
          </section>
        </div>
      </div>
    </section>

    <section id="appSection" class="d-none">
      <div class="app-grid">
        <aside class="left-rail">
          <div id="stepperCard" class="card shell-card border-0 shadow-sm mb-3">
            <div class="card-body">
              <div id="stepper" class="stepper"></div>
            </div>
          </div>
          <div id="resumeCard" class="alert alert-info d-none mb-3"></div>
        </aside>

        <section class="flow-area">
          <article id="profileStep" class="card shell-card border-0 shadow-sm d-none step-pane">
            <div class="card-body">
              <h2 class="h5">Profile complete</h2>
              <p id="profileSummary" class="small text-muted mb-3"></p>
              <button id="continueToMode" class="btn btn-primary btn-lg w-100">Continue to mode</button>
            </div>
          </article>

          <article id="modeStep" class="card shell-card border-0 shadow-sm d-none step-pane">
            <div class="card-body">
              <h2 class="h5">Choose your mode</h2>
              <p class="small text-muted">Choose once to continue.</p>
              <div class="row g-2">
                <div class="col-12 col-md-6">
                  <button class="btn btn-outline-light mode-btn w-100" data-mode="solo" type="button">
                    <i class="bi bi-person-fill d-block fs-2"></i>
                    <span>Solo</span>
                  </button>
                </div>
                <div class="col-12 col-md-6">
                  <button class="btn btn-outline-info mode-btn w-100" data-mode="duo" type="button">
                    <i class="bi bi-people-fill d-block fs-2"></i>
                    <span>Duo</span>
                  </button>
                </div>
              </div>
            </div>
          </article>

          <article id="matchmakingStep" class="card shell-card border-0 shadow-sm d-none step-pane">
            <div class="card-body">
              <h2 class="h5">Find teammate</h2>
              <p class="small text-muted">Search by first and last name.</p>
              <div class="match-grid">
                <div>
                  <div class="input-group mb-2">
                    <input id="searchInput" class="form-control form-control-lg" placeholder="Search full name">
                    <button id="searchBtn" class="btn btn-primary" type="button">Search</button>
                  </div>
                  <div id="searchResults" class="list-group mb-3"></div>
                  <h3 class="h6">Your invite</h3>
                  <div id="outgoingInvite" class="small text-muted">No outgoing invite.</div>
                </div>
                <div>
                  <h3 class="h6">Incoming invites</h3>
                  <div id="incomingInvites" class="vstack gap-2 mb-3"></div>
                  <div id="teammateCard" class="alert alert-success d-none"></div>
                </div>
              </div>
            </div>
          </article>

          <article id="paymentStep" class="card shell-card border-0 shadow-sm d-none step-pane">
            <div class="card-body">
              <h2 class="h5">Payment</h2>
              <p class="small text-muted">Complete payment to finish registration.</p>
              <div id="paymentInfo" class="mb-3"></div>
              <button id="completePaymentBtn" class="btn btn-success btn-lg w-100" type="button">Mark Payment Complete</button>
            </div>
          </article>

          <article id="completeStep" class="card shell-card border-0 shadow-sm d-none step-pane complete-pane">
            <div class="card-body">
              <h2 class="h5 text-success">You are registered</h2>
              <p class="mb-0">You can log in and out anytime. Your progress stays saved.</p>
            </div>
          </article>
        </section>
      </div>
    </section>
  </main>

  <script>
    window.__BOOT_USER__ = <?php echo json_encode(
      $user ? userPublic($user) : null,
      JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ); ?>;
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="/app.js"></script>
</body>
</html>
